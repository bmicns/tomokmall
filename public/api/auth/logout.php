<?php
declare(strict_types=1);
/**
 * POST /api/auth/logout.php
 * 세션 파기 + 비회원 상담 토큰 쿠키 삭제 — 로그인 여부와 무관하게 항상 성공 반환
 *
 * 인증: 불필요
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/chat_guest.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}

// 비회원 상담 토큰도 지움 — 이 기기를 다음에 쓰는 사람이 새로 시작(A12)
chat_guest_forget_cookie();

echo json_encode(['ok' => true]);
