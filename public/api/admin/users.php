<?php
declare(strict_types=1);
/**
 * 관리자 회원 API
 * POST — 역할·활성화 수정
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

if (!in_array($_SERVER['REQUEST_METHOD'], ['POST','DELETE'], true)) {
    http_response_code(405); echo json_encode(['ok'=>false,'error'=>'허용되지 않는 메서드']); exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$id       = (int)($body['id'] ?? 0);
$role     = $body['role']      ?? '';
$isActive = isset($body['is_active']) ? (int)(bool)$body['is_active'] : null;

if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id 필요']); exit; }

// 자기 자신의 역할·상태는 변경 불가
$me = \current_user();
if ($me && $me['id'] === $id) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'자기 자신은 변경할 수 없습니다.']); exit;
}

$allowed = ['customer','corporate','staff','admin'];
if ($role !== '' && !in_array($role, $allowed, true)) {
    http_response_code(400); echo json_encode(['ok'=>false,'error'=>'유효하지 않은 역할']); exit;
}

// ── DELETE ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    db()->prepare('DELETE FROM users WHERE id=:id')->execute(['id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── POST (역할·상태 수정) ────────────────────────────────────
$sets   = [];
$params = ['id' => $id];
if ($role !== '') { $sets[] = 'role=:role'; $params['role'] = $role; }
if ($isActive !== null) { $sets[] = 'is_active=:active'; $params['active'] = $isActive; }

if (!$sets) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'변경할 내용 없음']); exit; }

db()->prepare('UPDATE users SET ' . implode(',', $sets) . ' WHERE id=:id')->execute($params);
echo json_encode(['ok' => true]);
