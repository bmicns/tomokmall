<?php
declare(strict_types=1);
/**
 * GET|POST|DELETE /api/estimate/favorites.php
 * 관심상품(자주찾는 품목·단골품목) 조회·추가·삭제
 *
 * 인증: 로그인 회원 공통(개인·기업) (require_login) — 본인(user_id=세션) 데이터만 조회·변경
 * 검증: product_id 양의 정수+DB 존재, quantity 1~9999, 최대 FAVORITES_MAX(50)개 제한
 * 성능: GET — user_id 기준 단일 쿼리 + JOIN products
 *
 * POST 형식 2가지(기존 호출자 호환):
 *  - 단건: {product_id, quantity?} → 201 {ok, favorite_id}  (이미 있으면 수량 갱신 — 기존 동작)
 *  - 다중: {product_ids:[...]}     → 200 {ok, added[], already[], failed[{product_id,reason}], total}
 *          이미 등록된 상품은 기존 저장 내역(수량 포함) 유지, 신규만 기본 수량 1로 저장.
 *          reason: invalid(판매 중 아님) | limit(최대 개수 초과)
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/favorites.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$user   = require_login();
$isCorp = in_array($user['role'], ['corporate', 'staff', 'admin'], true);

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// ── GET — 단골품목 목록 조회 ─────────────────────────────────────────────────
if ($method === 'GET') {
    // 마이페이지 목록 표시용 image_url·업체명 추가(기존 필드는 그대로 — 즉시견적 호출자 호환)
    $stmt = $pdo->prepare(
        'SELECT f.id, f.product_id, p.name, p.spec, p.price_b2c, p.price_b2b, p.cost_price, p.category_id, f.quantity,
                p.image_url, ' . vendor_names_subquery() . ' AS vendor_names
         FROM favorite_products f
         JOIN products p ON p.id = f.product_id AND p.is_active = 1
         WHERE f.user_id = :uid
         ORDER BY f.sort_order, f.created_at'
    );
    $stmt->execute(['uid' => $user['id']]);
    $rows = $stmt->fetchAll();

    $favorites = array_map(function ($row) use ($isCorp) {
        $b2b = (int) $row['price_b2b'];
        // 기업회원: 기존 그대로 price_b2b(없으면 정가). 개인회원: 기업가를 노출하지 않고 본인 등급 단가(개인회원가).
        // 필드명은 기존 호출자(마이페이지·즉시견적) 호환을 위해 price_b2b 유지.
        $price = $isCorp ? ($b2b > 0 ? $b2b : (int) $row['price_b2c']) : effective_unit_price($row);
        return [
            'id'         => (int)   $row['id'],
            'product_id' => (int)   $row['product_id'],
            'name'       =>         $row['name'],
            'spec'       =>         $row['spec'],
            'price_b2b'  => $price,
            'quantity'   => (int)   $row['quantity'],
            'image_url'  => (string) ($row['image_url'] ?? ''),
            'vendors'    => array_values(array_filter(array_map('trim', explode('|', (string) ($row['vendor_names'] ?? ''))), 'strlen')),
            // 규격 치수 표시: 숫자 사이 곱셈 기호만 × 로 통일(400*400*2000 → 400×400×2000)
            'size'       => (string) preg_replace('/(?<=\d)\s*[*xX]\s*(?=\d)/u', '×', parse_spec_field((string) $row['spec'])['spec']),
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'favorites' => $favorites], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST|DELETE — Request Body 파싱 ─────────────────────────────────────────
$body      = json_decode(file_get_contents('php://input'), true) ?? [];
if (!is_array($body)) {
    $body = [];
}

// ── POST(다중) — 상품 목록에서 여러 상품 한 번에 추가 ───────────────────────
if ($method === 'POST' && array_key_exists('product_ids', $body)) {
    $ids = favorite_parse_ids($body['product_ids']);
    if ($ids === null) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '선택한 상품 정보가 올바르지 않습니다. (1~' . FAVORITES_BULK_MAX . '개)'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uid = (int) $user['id'];
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $vStmt = $pdo->prepare("SELECT id FROM products WHERE is_active = 1 AND id IN ($ph)");
    $vStmt->execute($ids);
    $validIds = array_map('intval', $vStmt->fetchAll(PDO::FETCH_COLUMN));

    $eStmt = $pdo->prepare("SELECT product_id FROM favorite_products WHERE user_id = ? AND product_id IN ($ph)");
    $eStmt->execute(array_merge([$uid], $ids));
    $existingIds = array_map('intval', $eStmt->fetchAll(PDO::FETCH_COLUMN));

    $cStmt = $pdo->prepare('SELECT COUNT(*) FROM favorite_products WHERE user_id = :uid');
    $cStmt->execute(['uid' => $uid]);
    $plan = favorite_bulk_plan($ids, $validIds, $existingIds, (int) $cStmt->fetchColumn());

    // 신규만 저장. INSERT IGNORE: 동시 요청으로 그 사이 등록된 경우에도 기존 행(수량)을 건드리지 않음
    $added   = [];
    $already = $plan['already'];
    $iStmt   = $pdo->prepare('INSERT IGNORE INTO favorite_products (user_id, product_id, quantity) VALUES (:uid, :pid, 1)');
    foreach ($plan['add'] as $pid) {
        $iStmt->execute(['uid' => $uid, 'pid' => $pid]);
        if ($iStmt->rowCount() > 0) { $added[] = $pid; } else { $already[] = $pid; }
    }

    $cStmt->execute(['uid' => $uid]);
    echo json_encode([
        'ok'      => true,
        'added'   => $added,
        'already' => $already,
        'failed'  => $plan['failed'],
        'total'   => (int) $cStmt->fetchColumn(),
        'max'     => FAVORITES_MAX,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$productId = (int) ($body['product_id'] ?? 0);

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'product_id가 유효하지 않습니다.']);
    exit;
}

// 상품 존재 확인
$pStmt = $pdo->prepare('SELECT id FROM products WHERE id = :id AND is_active = 1');
$pStmt->execute(['id' => $productId]);
if (!$pStmt->fetch()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '존재하지 않거나 판매 중단된 상품입니다.']);
    exit;
}

// ── DELETE — 단골품목 삭제 ───────────────────────────────────────────────────
if ($method === 'DELETE') {
    $delStmt = $pdo->prepare(
        'DELETE FROM favorite_products WHERE user_id = :uid AND product_id = :pid'
    );
    $delStmt->execute(['uid' => $user['id'], 'pid' => $productId]);

    echo json_encode(['ok' => true]);
    exit;
}

// ── POST — 단골품목 추가 ─────────────────────────────────────────────────────
$quantity = (int) ($body['quantity'] ?? 1);
if ($quantity < 1 || $quantity > 9999) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'quantity는 1~9999 범위여야 합니다.']);
    exit;
}

// 최대 50개 제한
$countStmt = $pdo->prepare(
    'SELECT COUNT(*) FROM favorite_products WHERE user_id = :uid'
);
$countStmt->execute(['uid' => $user['id']]);
if ((int) $countStmt->fetchColumn() >= FAVORITES_MAX) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '단골품목은 최대 50개까지 등록할 수 있습니다.']);
    exit;
}

// INSERT OR REPLACE (중복 시 수량 갱신)
$insStmt = $pdo->prepare(
    'INSERT INTO favorite_products (user_id, product_id, quantity)
     VALUES (:uid, :pid, :qty)
     ON DUPLICATE KEY UPDATE quantity = VALUES(quantity)'
);
$insStmt->execute(['uid' => $user['id'], 'pid' => $productId, 'qty' => $quantity]);

$favoriteId = (int) $pdo->lastInsertId();
// ON DUPLICATE KEY UPDATE 시 lastInsertId()는 0 — 기존 id 조회
if ($favoriteId === 0) {
    $selStmt = $pdo->prepare(
        'SELECT id FROM favorite_products WHERE user_id = :uid AND product_id = :pid'
    );
    $selStmt->execute(['uid' => $user['id'], 'pid' => $productId]);
    $favoriteId = (int) $selStmt->fetchColumn();
}

http_response_code(201);
echo json_encode(['ok' => true, 'favorite_id' => $favoriteId]);
