<?php
declare(strict_types=1);
/**
 * POST /api/mypage/withdraw.php
 * 회원 탈퇴(A안 — 계정 잠금 + 개인정보 지우기, 사용자 결정 2026-11-26) — 처리 내용은 app/privacy.php
 *
 * 인증: 로그인 필요(require_login), 개인·기업회원만(직원·관리자는 거절)
 * 입력(JSON): password(지금 비밀번호), confirm = '탈퇴'
 * 오류 응답: 검증 실패·비밀번호 불일치·진행 중 견적도 HTTP 200 + {"ok":false,"error":…} (profile.php 와 같은 방식)
 *           405(메서드)·503(DB 연결 실패)·401(비로그인)은 상태 코드 유지
 * 성공: 세션 파기 + 비회원 상담 토큰 쿠키 삭제 → {"ok":true}
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/privacy.php';
require __DIR__ . '/../../../app/chat_guest.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = require_login();
$pdo  = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body    = json_decode(file_get_contents('php://input') ?: '', true);
$body    = is_array($body) ? $body : [];
$pw      = (string) ($body['password'] ?? '');
$confirm = trim((string) ($body['confirm'] ?? ''));

if ($confirm !== '탈퇴') {
    echo json_encode(['ok' => false, 'error' => "확인 칸에 '탈퇴'라고 적어 주세요."], JSON_UNESCAPED_UNICODE);
    exit;
}
if ($pw === '' || mb_strlen($pw) > 100) {
    echo json_encode(['ok' => false, 'error' => '지금 비밀번호를 입력해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid     = (int) $user['id'];
$blocker = privacy_withdraw_blocker($uid);
if ($blocker !== null) {
    echo json_encode(['ok' => false, 'error' => $blocker], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id AND is_active = 1');
$st->execute(['id' => $uid]);
$hash = $st->fetchColumn();
if (!is_string($hash) || !password_verify($pw, $hash)) {
    echo json_encode(['ok' => false, 'error' => '비밀번호가 올바르지 않습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    privacy_withdraw_user($uid);
} catch (\Throwable $e) {
    error_log('withdraw: ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => '탈퇴 처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}
chat_guest_forget_cookie();

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
