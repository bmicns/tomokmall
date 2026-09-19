<?php
declare(strict_types=1);
/**
 * 견적 생성 API (AI 대화형 + 즉시견적 공통)
 *
 * POST /api/estimate.php
 *
 * v2.0 변경: requested_delivery_at, delivery_undecided, user_id 자동 연결
 *
 * 인증: 불필요 (세션 기반, 로그인 시 user_id 자동 연결)
 */

require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/estimate.php';

header('Content-Type: application/json; charset=utf-8');

// 예상치 못한 예외도 JSON으로 응답
set_exception_handler(function (\Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.']);
    exit;
});

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$body              = json_decode(file_get_contents('php://input'), true) ?? [];
$sid               = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($body['sid'] ?? ''));
$deliveryUndecided = (bool) ($body['delivery_undecided'] ?? false);
$deliveryAt        = $deliveryUndecided ? null : (string) ($body['requested_delivery_at'] ?? '');
// mode: 클라이언트가 명시적으로 전달하거나, estimate_create() 내부에서 quick_items 세션 감지로 자동 설정
$mode = in_array($body['mode'] ?? '', ['chat', 'quick'], true) ? $body['mode'] : '';

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
]);

if (!$result['ok']) {
    http_response_code(400);
    echo json_encode($result);
    exit;
}

http_response_code(201);
echo json_encode([
    'ok'          => true,
    'estimate_id' => $result['estimate_id'],
    'message'     => '견적이 생성되었습니다.',
]);
