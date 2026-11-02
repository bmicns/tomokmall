<?php
declare(strict_types=1);
/**
 * 관리자 회원 API
 * POST — 역할·활성화 수정
 *
 * 오류 응답: 입력 검증 실패(id·역할·변경 내용 없음)도 HTTP 200 + {"ok":false,"error":…}
 *           (닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 JSON 문구가 사라짐 — quote.php 와 같은 방식)
 *           405(메서드)·403(자기 자신 변경)·500(예외 — 상세는 서버 로그, 화면에는 일반 문구)은 상태 코드 유지
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (\Throwable $e) {
    // 내부 오류 문구(SQL 등)는 화면에 보내지 않고 서버 로그에만 남긴다
    error_log('[api/admin/users] ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
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

if (!$id) { echo json_encode(['ok'=>false,'error'=>'id 필요']); exit; }

// 자기 자신의 역할·상태는 변경 불가
$me = \current_user();
if ($me && $me['id'] === $id) {
    http_response_code(403); echo json_encode(['ok'=>false,'error'=>'자기 자신은 변경할 수 없습니다.']); exit;
}

$allowed = ['customer','corporate','staff','admin'];
if ($role !== '' && !in_array($role, $allowed, true)) {
    echo json_encode(['ok'=>false,'error'=>'유효하지 않은 역할']); exit;
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

if (!$sets) { echo json_encode(['ok'=>false,'error'=>'변경할 내용 없음']); exit; }

db()->prepare('UPDATE users SET ' . implode(',', $sets) . ' WHERE id=:id')->execute($params);
echo json_encode(['ok' => true]);
