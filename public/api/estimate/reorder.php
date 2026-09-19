<?php
declare(strict_types=1);
/**
 * POST /api/estimate/reorder.php
 * 이전 견적서 품목을 즉시견적 세션 장바구니로 적재 (재구매 원클릭)
 *
 * 인증: 로그인 필요 (require_login)
 * 보안: estimates.user_id = 현재 user_id 확인 — 타인 견적서 접근 차단
 * 검증: estimate_id 양의 정수, 각 품목 is_active=1 재확인 (단종 상품 제외)
 * 성능: estimate_items JOIN products 단일 쿼리 — N+1 방지
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$user = require_login();

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$estimateId = (int) ($body['estimate_id'] ?? 0);

if ($estimateId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'estimate_id가 유효하지 않습니다.']);
    exit;
}

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// 본인 소유 견적서 확인
$estStmt = $pdo->prepare(
    'SELECT id FROM estimates WHERE id = :eid AND user_id = :uid LIMIT 1'
);
$estStmt->execute(['eid' => $estimateId, 'uid' => $user['id']]);
if (!$estStmt->fetch()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '접근 권한이 없습니다.']);
    exit;
}

// 이전 견적 품목 + 상품 정보 단일 쿼리 조회
// is_active = 1인 상품만 포함 (단종 제외)
$itemStmt = $pdo->prepare(
    'SELECT ei.product_id, ei.quantity,
            p.name, p.spec, p.price_b2c, p.price_b2b
     FROM estimate_items ei
     JOIN products p ON p.id = ei.product_id AND p.is_active = 1
     WHERE ei.estimate_id = :eid'
);
$itemStmt->execute(['eid' => $estimateId]);
$rows = $itemStmt->fetchAll();

if (empty($rows)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '재구매 가능한 품목이 없습니다.']);
    exit;
}

// 즉시견적 세션에 적재
$quickItems = [];
$responseItems = [];
$total = 0;

foreach ($rows as $row) {
    $pid       = (int) $row['product_id'];
    $qty       = (int) $row['quantity'];
    $b2b       = (int) $row['price_b2b'];
    $unitPrice = $b2b > 0 ? $b2b : (int) $row['price_b2c'];
    $subtotal  = $unitPrice * $qty;
    $total    += $subtotal;

    $quickItems[$pid] = $qty;
    $responseItems[]  = [
        'product_id' => $pid,
        'name'       => $row['name'],
        'spec'       => $row['spec'],
        'quantity'   => $qty,
        'unit_price' => $unitPrice,
        'subtotal'   => $subtotal,
    ];
}

$_SESSION['quick_items'] = $quickItems;

echo json_encode([
    'ok'           => true,
    'loaded_count' => count($responseItems),
    'items'        => $responseItems,
    'total'        => $total,
], JSON_UNESCAPED_UNICODE);
