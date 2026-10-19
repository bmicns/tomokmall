<?php
declare(strict_types=1);
/**
 * 견적 신청 API — POST /quote.php
 * (닷홈 서버가 /api/ 하위 POST를 차단하므로 루트에 위치)
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/estimate.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (\Throwable $e) {
    // 닷홈: 5xx 코드 설정 시 Apache가 JSON 바디를 HTML 에러 페이지로 덮어씀 → 200 유지
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.']);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$body              = json_decode(file_get_contents('php://input'), true) ?? [];
$deliveryUndecided = (bool) ($body['delivery_undecided'] ?? false);
$deliveryAt        = $deliveryUndecided ? null : (string) ($body['requested_delivery_at'] ?? '');
$mode              = in_array($body['mode'] ?? '', ['chat', 'quick'], true) ? $body['mode'] : '';
$sid               = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($body['sid'] ?? ''));

$result = estimate_create([
    'customer_name'         => $body['customer_name'] ?? '',
    'phone'                 => $body['phone']         ?? '',
    'email'                 => $body['email']         ?? '',
    'company_name'          => $body['company_name']  ?? '',
    'site_address'          => $body['site_address']  ?? '',
    'memo'                  => $body['memo']           ?? '',
    'sid'                   => $sid,
    'requested_delivery_at' => $deliveryAt ?: null,
    'mode'                  => $mode,
    // 화면에서 보낸 품목·수량 — 세션 품목과 다르면 접수 거부(estimate_items_match)
    'expected_items'        => is_array($body['items'] ?? null) ? $body['items'] : null,
]);

if (!$result['ok']) {
    // 닷홈: 4xx 코드 설정 시 Apache가 JSON 바디를 HTML 에러 페이지로 덮어씀 → 200 유지
    echo json_encode($result);
    exit;
}

echo json_encode(['ok' => true, 'estimate_id' => $result['estimate_id'], 'message' => '견적이 생성되었습니다.']);
