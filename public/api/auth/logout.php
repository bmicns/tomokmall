<?php
declare(strict_types=1);
/**
 * POST /api/auth/logout.php
 * 세션 파기 — 로그인 여부와 무관하게 항상 성공 반환
 *
 * 인증: 불필요
 */

require __DIR__ . '/../../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() === PHP_SESSION_ACTIVE) {
    $_SESSION = [];
    session_destroy();
}

echo json_encode(['ok' => true]);
