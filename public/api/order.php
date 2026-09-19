<?php
declare(strict_types=1);
/**
 * 주문 생성 API (P2 — 결제 시스템)
 *
 * POST /api/order.php
 *
 * 흐름:
 *  1. 주문자 정보 + 배송지 검증
 *  2. 장바구니 확인
 *  3. SELECT FOR UPDATE 트랜잭션으로 재고 확인 + 차감
 *  4. orders / order_items / shipping_info / payments(READY) 레코드 생성
 *  5. PG 결제 URL 반환 (TODO: PG 선택 후 실제 연동)
 *
 * 인증: PHP 세션 기반 (향후 회원 연동 시 확장)
 */

require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/cart.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];
$sid  = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($body['sid'] ?? ''));

// ─── 주문자 정보 검증 ─────────────────────────────────────────────────────────
$customerName = htmlspecialchars(substr(trim((string) ($body['customer_name'] ?? '')), 0, 80), ENT_QUOTES, 'UTF-8');
$phone        = trim((string) ($body['phone'] ?? ''));
$email        = trim((string) ($body['email'] ?? ''));

if ($customerName === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '주문자 성함을 입력해주세요.', 'field' => 'customer_name']);
    exit;
}
if ($phone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '주문자 연락처를 입력해주세요.', 'field' => 'phone']);
    exit;
}
if (!preg_match('/^0\d{1,2}-?\d{3,4}-?\d{4}$/', $phone)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '올바른 연락처 형식이 아닙니다. (예: 010-1234-5678)', 'field' => 'phone']);
    exit;
}
if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '올바른 이메일 형식이 아닙니다.', 'field' => 'email']);
    exit;
}
if (strlen($email) > 160) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '이메일 주소가 너무 깁니다.', 'field' => 'email']);
    exit;
}
$email = htmlspecialchars($email, ENT_QUOTES, 'UTF-8');

// ─── 배송지 검증 ─────────────────────────────────────────────────────────────
$shipping      = $body['shipping'] ?? [];
$shipRecipient = htmlspecialchars(substr(trim((string) ($shipping['recipient_name'] ?? '')), 0, 80),  ENT_QUOTES, 'UTF-8');
$shipPhone     = trim((string) ($shipping['phone'] ?? ''));
$shipAddr1     = htmlspecialchars(substr(trim((string) ($shipping['address1']       ?? '')), 0, 255), ENT_QUOTES, 'UTF-8');
$shipAddr2     = htmlspecialchars(substr(trim((string) ($shipping['address2']       ?? '')), 0, 100), ENT_QUOTES, 'UTF-8');
$shipZipcode   = htmlspecialchars(substr(trim((string) ($shipping['zipcode']        ?? '')), 0,  10), ENT_QUOTES, 'UTF-8');
$shipMemo      = htmlspecialchars(substr(trim((string) ($shipping['memo']           ?? '')), 0, 200), ENT_QUOTES, 'UTF-8');

if ($shipRecipient === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '수령인 성함을 입력해주세요.', 'field' => 'recipient_name']);
    exit;
}
if ($shipPhone === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '수령인 연락처를 입력해주세요.', 'field' => 'phone']);
    exit;
}
if (!preg_match('/^0\d{1,2}-?\d{3,4}-?\d{4}$/', $shipPhone)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '수령인 연락처 형식이 올바르지 않습니다. (예: 010-1234-5678)', 'field' => 'phone']);
    exit;
}
if ($shipAddr1 === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '배송 주소를 입력해주세요.', 'field' => 'address1']);
    exit;
}

// ─── 장바구니 확인 ────────────────────────────────────────────────────────────
$cart      = cart_session_read();
$cartItems = cart_fetch_items($cart);

if (empty($cartItems)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '장바구니가 비어있습니다.']);
    exit;
}

// ─── 재고 확인 + 주문 생성 트랜잭션 ─────────────────────────────────────────
$pdo = db();
try {
    $pdo->beginTransaction();

    $totalAmount = 0;
    foreach ($cartItems as $item) {
        // SELECT FOR UPDATE: 재고 잠금 후 확인
        $stmt = $pdo->prepare(
            'SELECT id, stock_qty FROM products WHERE id = :id AND is_active = 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $item['product_id']]);
        $product = $stmt->fetch();

        if (!$product) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => '상품을 찾을 수 없습니다.', 'product_id' => $item['product_id']]);
            exit;
        }
        if ($product['stock_qty'] < $item['quantity']) {
            $pdo->rollBack();
            http_response_code(409);
            echo json_encode(['ok' => false, 'error' => '재고가 부족합니다.', 'product_id' => $item['product_id']]);
            exit;
        }

        $totalAmount += $item['unit_price'] * $item['quantity'];

        $pdo->prepare('UPDATE products SET stock_qty = stock_qty - :qty WHERE id = :id')
            ->execute(['qty' => $item['quantity'], 'id' => $item['product_id']]);
    }

    // 주문 생성
    $pdo->prepare(
        'INSERT INTO orders (session_key, customer_name, phone, email, total_amount)
         VALUES (:sid, :name, :phone, :email, :total)'
    )->execute([
        'sid'   => $sid,
        'name'  => $customerName,
        'phone' => $phone,
        'email' => $email ?: null,
        'total' => $totalAmount,
    ]);
    $orderId = (int) $pdo->lastInsertId();

    // 주문 아이템 삽입
    $itemStmt = $pdo->prepare(
        'INSERT INTO order_items (order_id, product_id, quantity, unit_price)
         VALUES (:oid, :pid, :qty, :price)'
    );
    foreach ($cartItems as $item) {
        $itemStmt->execute([
            'oid'   => $orderId,
            'pid'   => $item['product_id'],
            'qty'   => $item['quantity'],
            'price' => $item['unit_price'],
        ]);
    }

    // 배송지 삽입
    $pdo->prepare(
        'INSERT INTO shipping_info (order_id, recipient_name, phone, zipcode, address1, address2, memo)
         VALUES (:oid, :name, :phone, :zip, :addr1, :addr2, :memo)'
    )->execute([
        'oid'   => $orderId,
        'name'  => $shipRecipient,
        'phone' => $shipPhone,
        'zip'   => $shipZipcode ?: null,
        'addr1' => $shipAddr1,
        'addr2' => $shipAddr2  ?: null,
        'memo'  => $shipMemo   ?: null,
    ]);

    // PG 주문 ID 생성 + payments READY 레코드
    $pgOrderId = sprintf('ORDER_%s_%05d', date('Ymd'), $orderId);
    $pdo->prepare(
        'INSERT INTO payments (order_id, pg_order_id, amount) VALUES (:oid, :pgoid, :amount)'
    )->execute([
        'oid'    => $orderId,
        'pgoid'  => $pgOrderId,
        'amount' => $totalAmount,
    ]);

    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '주문 생성 중 오류가 발생했습니다.']);
    exit;
}

// 장바구니 초기화
cart_session_write([]);

http_response_code(201);
echo json_encode([
    'ok'          => true,
    'order_id'    => $orderId,
    'pg_order_id' => $pgOrderId,
    'amount'      => $totalAmount,
    'payment_url' => null, // TODO: P2 PG 선택 후 실제 결제 URL 반환
]);
