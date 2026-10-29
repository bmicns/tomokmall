<?php
declare(strict_types=1);
/**
 * 장바구니 API
 *
 * POST /api/cart.php
 *
 * action 값: add | update | remove | get | clear
 * 인증: PHP 세션 기반 (쿠키 자동 전송)
 *
 * 서버 검증:
 *   - action whitelist
 *   - product_id: 양의 정수 + DB 존재 + is_active = 1
 *   - quantity: 1~999 (update 는 0 허용 — 삭제 처리)
 */

require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/cart.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

if (!in_array($action, ['add', 'update', 'remove', 'get', 'clear'], true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '유효하지 않은 action 입니다.']);
    exit;
}

$cart = cart_session_read();

// ─── get ────────────────────────────────────────────────────────────────────
if ($action === 'get') {
    $items = cart_fetch_items($cart);
    [$count, $total] = cart_summary_from_items($items);
    echo json_encode(['ok' => true, 'cart' => compact('items', 'count', 'total')]);
    exit;
}

// ─── clear ──────────────────────────────────────────────────────────────────
if ($action === 'clear') {
    cart_session_write([]);
    echo json_encode(['ok' => true, 'cart' => ['items' => [], 'count' => 0, 'total' => 0]]);
    exit;
}

// ─── remove ─────────────────────────────────────────────────────────────────
if ($action === 'remove') {
    $productId = (int) ($body['product_id'] ?? 0);
    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'product_id 가 유효하지 않습니다.']);
        exit;
    }
    unset($cart[$productId]);
    cart_session_write($cart);
    $items = cart_fetch_items($cart);
    [$count, $total] = cart_summary_from_items($items);
    echo json_encode(['ok' => true, 'cart' => compact('items', 'count', 'total')]);
    exit;
}

// ─── add / update ────────────────────────────────────────────────────────────
$productId = (int) ($body['product_id'] ?? 0);
$quantity  = (int) ($body['quantity']   ?? 1);

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'product_id 가 유효하지 않습니다.']);
    exit;
}

$minQty = ($action === 'update') ? 0 : 1;
// 상한 9999 = AI 상담 add_to_cart·즉시견적과 같음(면적 기준 권장 수량이 수천 장인 블록도 담을 수 있게)
if ($quantity < $minQty || $quantity > 9999) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "수량은 {$minQty}~9999 범위여야 합니다."]);
    exit;
}

// DB 존재 + is_active 확인
$stmt = db()->prepare('SELECT id FROM products WHERE id = :id AND is_active = 1');
$stmt->execute(['id' => $productId]);
if (!$stmt->fetch()) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => '상품을 찾을 수 없습니다.']);
    exit;
}

if ($action === 'add') {
    $cart[$productId] = min(9999, ($cart[$productId] ?? 0) + $quantity);
} else {
    // update: quantity = 0 이면 삭제
    if ($quantity === 0) {
        unset($cart[$productId]);
    } else {
        $cart[$productId] = $quantity;
    }
}

cart_session_write($cart);
$items = cart_fetch_items($cart);
[$count, $total] = cart_summary_from_items($items);
echo json_encode(['ok' => true, 'cart' => compact('items', 'count', 'total')]);
