<?php
declare(strict_types=1);
/**
 * POST /api/auth/register.php
 * 기업회원 신규 가입
 *
 * 인증: 불필요
 * 검증: name/email/password 필수, company_name·phone 선택
 * 보안: password_hash(BCRYPT), email 중복 체크
 */

require __DIR__ . '/../../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

$role           = trim((string) ($body['role']           ?? 'customer'));
$name           = trim((string) ($body['name']           ?? ''));
$email          = trim((string) ($body['email']          ?? ''));
$password       = (string) ($body['password']       ?? '');
$company_name   = trim((string) ($body['company_name']   ?? ''));
$phone          = trim((string) ($body['phone']          ?? ''));
$address_zip    = trim((string) ($body['address_zip']    ?? ''));
$address        = trim((string) ($body['address']        ?? ''));
$address_detail = trim((string) ($body['address_detail'] ?? ''));

// ── 입력 검증 ──────────────────────────────────────────────
if (!in_array($role, ['customer', 'corporate'], true)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '회원 유형이 올바르지 않습니다.']);
    exit;
}
if ($name === '' || mb_strlen($name) > 80) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '담당자명을 올바르게 입력해주세요. (1~80자)']);
    exit;
}
if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 160) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '이메일 형식이 올바르지 않습니다.']);
    exit;
}
if (mb_strlen($password) < 8 || mb_strlen($password) > 100) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '비밀번호는 8자 이상 100자 이하로 입력해주세요.']);
    exit;
}
if ($company_name !== '' && mb_strlen($company_name) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '회사명은 120자 이하로 입력해주세요.']);
    exit;
}
if ($phone !== '' && mb_strlen($phone) > 40) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '전화번호는 40자 이하로 입력해주세요.']);
    exit;
}
if ($address_zip !== '' && mb_strlen($address_zip) > 10) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '우편번호가 올바르지 않습니다.']);
    exit;
}
if ($address !== '' && mb_strlen($address) > 255) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '기본주소는 255자 이하로 입력해주세요.']);
    exit;
}
if ($address_detail !== '' && mb_strlen($address_detail) > 255) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '상세주소는 255자 이하로 입력해주세요.']);
    exit;
}

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// ── 이메일 중복 체크 ────────────────────────────────────────
$chk = $pdo->prepare('SELECT id FROM users WHERE email = :email LIMIT 1');
$chk->execute(['email' => $email]);
if ($chk->fetch()) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => '이미 사용 중인 이메일입니다.']);
    exit;
}

// ── 회원 등록 ───────────────────────────────────────────────
$hash = password_hash($password, PASSWORD_BCRYPT);

$ins = $pdo->prepare(
    'INSERT INTO users (role, name, email, password_hash, phone, company_name, address_zip, address, address_detail, is_active, created_at)
     VALUES (:role, :name, :email, :password_hash, :phone, :company_name, :address_zip, :address, :address_detail, 1, NOW())'
);
$ins->execute([
    'role'           => $role,
    'name'           => $name,
    'email'          => $email,
    'password_hash'  => $hash,
    'phone'          => $phone !== '' ? $phone : null,
    'company_name'   => $company_name !== '' ? $company_name : null,
    'address_zip'    => $address_zip !== '' ? $address_zip : null,
    'address'        => $address !== '' ? $address : null,
    'address_detail' => $address_detail !== '' ? $address_detail : null,
]);

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
