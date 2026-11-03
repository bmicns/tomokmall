<?php
declare(strict_types=1);
/**
 * 관리자 주문 API
 * POST — 주문 상태 변경
 *
 * 오류 응답: 입력 검증 실패(id·status·주문 없음)도 HTTP 200 + {"ok":false,"error":…}
 *           (닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 JSON 문구가 사라짐 — quote.php 와 같은 방식)
 *           405(메서드)·500(예외 — 상세는 서버 로그, 화면에는 일반 문구)은 상태 코드 유지
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (\Throwable $e) {
    // 내부 오류 문구(SQL 등)는 화면에 보내지 않고 서버 로그에만 남긴다
    error_log('[api/admin/orders] ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
});

require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'허용되지 않는 메서드']); exit;
}

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$id     = (int)($body['id'] ?? 0);
$status = $body['status'] ?? '';

$allowed = ['PENDING','PAID','PREPARING','SHIPPED','DELIVERED','CANCELED'];
if (!$id || !in_array($status, $allowed, true)) {
    echo json_encode(['ok'=>false,'error'=>'id 또는 status 오류']);
    exit;
}

$st = db()->prepare('SELECT id FROM orders WHERE id=:id');
$st->execute(['id' => $id]);
if (!$st->fetch()) { echo json_encode(['ok'=>false,'error'=>'주문 없음']); exit; }

db()->prepare('UPDATE orders SET status=:s, updated_at=NOW() WHERE id=:id')
    ->execute(['s' => $status, 'id' => $id]);

echo json_encode(['ok' => true]);
