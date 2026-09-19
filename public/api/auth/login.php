<?php
declare(strict_types=1);
/**
 * POST /api/auth/login.php
 * 기업회원 로그인 — email/password 검증 후 세션에 사용자 정보 저장
 *
 * 인증: 불필요
 * 검증: email 형식+160자, password 1~100자, is_active=1, password_verify()
 * 보안: session_regenerate(true), last_login_at 갱신, 비밀번호 미노출
 */

require __DIR__ . '/../../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$body     = json_decode(file_get_contents('php://input'), true) ?? [];
$email    = trim((string) ($body['email']    ?? ''));
$password = (string) ($body['password'] ?? '');

// 입력 검증 (공격자에게 힌트 주지 않기 위해 동일 에러 메시지 사용)
$authError = ['ok' => false, 'error' => '이메일 또는 비밀번호가 올바르지 않습니다.'];

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
    http_response_code(401);
    echo json_encode($authError);
    exit;
}
if ($password === '' || mb_strlen($password) > 100) {
    http_response_code(401);
    echo json_encode($authError);
    exit;
}

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, role, name, email, company_name, password_hash
     FROM users
     WHERE email = :email AND is_active = 1
     LIMIT 1'
);
$stmt->execute(['email' => $email]);
$user = $stmt->fetch();

// password_verify — 타이밍 공격 방지를 위해 user 없어도 실행
$hash = $user ? (string) $user['password_hash'] : '$2y$10$invalid_hash_for_timing_';
if (!$user || !password_verify($password, $hash)) {
    http_response_code(401);
    echo json_encode($authError);
    exit;
}

// 세션 고정 공격 방지: 로그인 성공 시 세션 ID 재발급
session_regenerate_id(true);

$_SESSION['user'] = [
    'id'           => (int)   $user['id'],
    'role'         =>          $user['role'],
    'name'         =>          $user['name'],
    'email'        =>          $user['email'],
    'company_name' =>          $user['company_name'],
];

// last_login_at 갱신
$pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
    ->execute(['id' => (int) $user['id']]);

echo json_encode([
    'ok'   => true,
    'user' => [
        'id'           => (int)  $user['id'],
        'name'         =>        $user['name'],
        'role'         =>        $user['role'],
        'company_name' =>        $user['company_name'],
    ],
], JSON_UNESCAPED_UNICODE);
