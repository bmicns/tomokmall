<?php
declare(strict_types=1);
/**
 * GET|POST /api/mypage/profile.php
 * 내 정보 조회 / 수정
 *
 * 인증: 로그인 필요 (require_login)
 * GET  → name, email, phone, company_name 반환
 * POST → name, phone, company_name 수정 / 비밀번호 선택 변경
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
if (!in_array($method, ['GET', 'POST'], true)) {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$user = require_login();
$pdo  = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// ── GET ─────────────────────────────────────────────────────
if ($method === 'GET') {
    $st = $pdo->prepare('SELECT name, email, phone, company_name, address_zip, address, address_detail FROM users WHERE id = :id');
    $st->execute(['id' => $user['id']]);
    $row = $st->fetch();
    echo json_encode(['ok' => true, 'profile' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── POST ────────────────────────────────────────────────────
$body           = json_decode(file_get_contents('php://input'), true) ?? [];
$name           = trim((string) ($body['name']           ?? ''));
$phone          = trim((string) ($body['phone']          ?? ''));
$company_name   = trim((string) ($body['company_name']   ?? ''));
$address_zip    = trim((string) ($body['address_zip']    ?? ''));
$address        = trim((string) ($body['address']        ?? ''));
$address_detail = trim((string) ($body['address_detail'] ?? ''));
$cur_pw         = (string) ($body['current_password'] ?? '');
$new_pw         = (string) ($body['new_password']     ?? '');

if ($name === '' || mb_strlen($name) > 80) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '이름은 1~80자로 입력해주세요.']);
    exit;
}
if ($phone !== '' && mb_strlen($phone) > 40) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '전화번호는 40자 이하로 입력해주세요.']);
    exit;
}
if ($company_name !== '' && mb_strlen($company_name) > 120) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => '회사명은 120자 이하로 입력해주세요.']);
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

// 비밀번호 변경 요청 시 검증
$newHash = null;
if ($new_pw !== '') {
    if (mb_strlen($new_pw) < 8 || mb_strlen($new_pw) > 100) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => '새 비밀번호는 8~100자로 입력해주세요.']);
        exit;
    }
    // 현재 비밀번호 확인
    $st = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
    $st->execute(['id' => $user['id']]);
    $row = $st->fetch();
    if (!$row || !password_verify($cur_pw, (string) $row['password_hash'])) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => '현재 비밀번호가 올바르지 않습니다.']);
        exit;
    }
    $newHash = password_hash($new_pw, PASSWORD_BCRYPT);
}

if ($newHash !== null) {
    $st = $pdo->prepare(
        'UPDATE users SET name=:name, phone=:phone, company_name=:co, address_zip=:zip, address=:addr, address_detail=:addr_detail, password_hash=:pw WHERE id=:id'
    );
    $st->execute([
        'name' => $name, 'phone' => $phone ?: null,
        'co'   => $company_name ?: null, 'pw' => $newHash, 'id' => $user['id'],
        'zip'  => $address_zip ?: null, 'addr' => $address ?: null, 'addr_detail' => $address_detail ?: null,
    ]);
} else {
    $st = $pdo->prepare(
        'UPDATE users SET name=:name, phone=:phone, company_name=:co, address_zip=:zip, address=:addr, address_detail=:addr_detail WHERE id=:id'
    );
    $st->execute([
        'name' => $name, 'phone' => $phone ?: null,
        'co'   => $company_name ?: null, 'id' => $user['id'],
        'zip'  => $address_zip ?: null, 'addr' => $address ?: null, 'addr_detail' => $address_detail ?: null,
    ]);
}

// 세션 갱신
$_SESSION['user']['name']         = $name;
$_SESSION['user']['company_name'] = $company_name ?: null;

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
