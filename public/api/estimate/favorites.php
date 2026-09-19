<?php
declare(strict_types=1);
/**
 * GET|POST|DELETE /api/estimate/favorites.php
 * 단골품목 조회·추가·삭제
 *
 * 인증: 기업회원 로그인 필요 (require_corporate)
 * 검증: product_id 양의 정수+DB 존재, quantity 1~9999, 최대 50개 제한
 * 성능: GET — user_id 기준 단일 쿼리 + JOIN products
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST', 'DELETE'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$user = require_corporate();

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// ── GET — 단골품목 목록 조회 ─────────────────────────────────────────────────
if ($method === 'GET') {
    $stmt = $pdo->prepare(
        'SELECT f.id, f.product_id, p.name, p.spec, p.price_b2c, p.price_b2b, f.quantity
         FROM favorite_products f
         JOIN products p ON p.id = f.product_id AND p.is_active = 1
         WHERE f.user_id = :uid
         ORDER BY f.sort_order, f.created_at'
    );
    $stmt->execute(['uid' => $user['id']]);
    $rows = $stmt->fetchAll();

    $favorites = array_map(function ($row) {
        $b2b = (int) $row['price_b2b'];
        return [
            'id'         => (int)   $row['id'],
            'product_id' => (int)   $row['product_id'],
            'name'       =>         $row['name'],
            'spec'       =>         $row['spec'],
            'price_b2b'  => $b2b > 0 ? $b2b : (int) $row['price_b2c'],
            'quantity'   => (int)   $row['quantity'],
        ];
    }, $rows);

    echo json_encode(['ok' => true, 'favorites' => $favorites], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST|DELETE — Request Body 파싱 ─────────────────────────────────────────
$body      = json_decode(file_get_contents('php://input'), true) ?? [];
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
if ((int) $countStmt->fetchColumn() >= 50) {
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
