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
 *
 * 오류 응답: 검증 실패(action·product_id·수량·상품 없음)도 HTTP 200 + {"ok":false,"error":…}
 *           (닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 JSON 문구가 사라짐 — quote.php 와 같은 방식)
 *           405(메서드)·503(add/update 의 DB 연결 실패)은 상태 코드 유지
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
    echo json_encode(['ok' => false, 'error' => '유효하지 않은 action 입니다.']);
    exit;
}

$cart = cart_session_read();

// ─── get ────────────────────────────────────────────────────────────────────
if ($action === 'get') {
    $items = items_with_display_prices(cart_fetch_items($cart));   // 회원 등급별 단가(견적 요청 화면·저장과 같은 금액)
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
        echo json_encode(['ok' => false, 'error' => 'product_id 가 유효하지 않습니다.']);
        exit;
    }
    unset($cart[$productId]);
    cart_session_write($cart);
    $items = items_with_display_prices(cart_fetch_items($cart));   // 회원 등급별 단가(견적 요청 화면·저장과 같은 금액)
    [$count, $total] = cart_summary_from_items($items);
    echo json_encode(['ok' => true, 'cart' => compact('items', 'count', 'total')]);
    exit;
}

// ─── add / update ────────────────────────────────────────────────────────────
$productId = (int) ($body['product_id'] ?? 0);
$quantity  = (int) ($body['quantity']   ?? 1);

if ($productId <= 0) {
    echo json_encode(['ok' => false, 'error' => 'product_id 가 유효하지 않습니다.']);
    exit;
}

$minQty = ($action === 'update') ? 0 : 1;
if ($quantity < $minQty || $quantity > 999) {
    echo json_encode(['ok' => false, 'error' => "수량은 {$minQty}~999 범위여야 합니다."]);
    exit;
}

// DB 연결 실패 시 빈 500 대신 JSON 오류(다른 API 와 같은 503 응답)
$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// DB 존재 + is_active 확인
$stmt = $pdo->prepare('SELECT id FROM products WHERE id = :id AND is_active = 1');
$stmt->execute(['id' => $productId]);
if (!$stmt->fetch()) {
    echo json_encode(['ok' => false, 'error' => '상품을 찾을 수 없습니다.']);
    exit;
}

if ($action === 'add') {
    if (($cart[$productId] ?? 0) + $quantity > 999) {   // 이미 담긴 수량과 합쳐도 999 이하
        echo json_encode(['ok' => false, 'error' => '수량은 1~999 범위여야 합니다.']);
        exit;
    }
    $cart[$productId] = ($cart[$productId] ?? 0) + $quantity;
} else {
    // update: quantity = 0 이면 삭제
    if ($quantity === 0) {
        unset($cart[$productId]);
    } else {
        $cart[$productId] = $quantity;
    }
}

cart_session_write($cart);
$items = items_with_display_prices(cart_fetch_items($cart));   // 회원 등급별 단가(견적 요청 화면·저장과 같은 금액)
[$count, $total] = cart_summary_from_items($items);
echo json_encode(['ok' => true, 'cart' => compact('items', 'count', 'total')]);
