<?php
declare(strict_types=1);
/**
 * 관리자 주문 API
 * POST — 주문 상태 변경
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (\Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
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
    http_response_code(400);
    echo json_encode(['ok'=>false,'error'=>'id 또는 status 오류']);
    exit;
}

$st = db()->prepare('SELECT id FROM orders WHERE id=:id');
$st->execute(['id' => $id]);
if (!$st->fetch()) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'주문 없음']); exit; }

db()->prepare('UPDATE orders SET status=:s, updated_at=NOW() WHERE id=:id')
    ->execute(['s' => $status, 'id' => $id]);

echo json_encode(['ok' => true]);
