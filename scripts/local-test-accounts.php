<?php
declare(strict_types=1);
/**
 * 로컬 테스트 DB 전용 테스트 계정 점검·생성 도구 (scripts/local-test-accounts.php)
 *
 * 책임: 로컬 '_test' DB에 일반회원(customer)·기업회원(corporate) 테스트 계정 1개씩 생성/갱신, 접속 환경 점검
 * 금지: 웹 실행, 운영/개발 DB 접근, 비밀번호 출력, [TEST] 표시가 없는 회원 수정
 *
 * 계정 값은 저장소에 두지 않는다 — 프로젝트 루트 .env.local(Git 제외)에서 읽는다:
 *   LOCAL_TEST_CUSTOMER_EMAIL=     LOCAL_TEST_CUSTOMER_PASSWORD=
 *   LOCAL_TEST_CORPORATE_EMAIL=    LOCAL_TEST_CORPORATE_PASSWORD=
 *
 * 사용 (CLI 전용):
 *   php scripts/local-test-accounts.php            점검만(읽기 전용, 기본)
 *   php scripts/local-test-accounts.php --apply    계정 생성/갱신(재실행 안전: 이미 있으면 비밀번호·등급만 맞춤)
 *   php scripts/local-test-accounts.php --remove   테스트 계정 삭제(관심상품 등 연결 행은 FK CASCADE)
 *
 * 비밀번호 저장: 회원가입 API(public/api/auth/register.php)와 같은 password_hash(PASSWORD_BCRYPT).
 * 로그인 API(public/api/auth/login.php)의 password_verify()로 그대로 로그인된다. 인증 로직은 변경하지 않는다.
 * 안전장치: CLI + DB 이름이 '_test'로 끝남 + 로컬 호스트일 때만 동작. 테스트 계정은 이름 '[TEST] ' 접두어로 구분.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require __DIR__ . '/../app/bootstrap.php';

const TEST_MARK = '[TEST] ';
const TEST_ACCOUNTS = [
    'customer'  => ['env' => 'LOCAL_TEST_CUSTOMER',  'name' => '[TEST] 일반회원', 'company' => null],
    'corporate' => ['env' => 'LOCAL_TEST_CORPORATE', 'name' => '[TEST] 기업회원', 'company' => '[TEST] 테스트기업'],
];

$mode = $argv[1] ?? '--check';
if (!in_array($mode, ['--check', '--apply', '--remove'], true)) {
    fwrite(STDERR, "사용법: php scripts/local-test-accounts.php [--check|--apply|--remove]\n");
    exit(2);
}

function line(string $s = ''): void { echo $s, "\n"; }
function stop(string $s): never { fwrite(STDERR, "중단: {$s}\n"); exit(1); }

// ── 1. 접속 환경 점검(읽기 전용) ────────────────────────────
$dbHost = (string) ($config['db']['host'] ?? '');
$dbName = (string) ($config['db']['name'] ?? '');
$dbUser = (string) ($config['db']['user'] ?? '');
line('[접속 환경]');
line('  PHP ' . PHP_VERSION . ' / ' . PHP_OS_FAMILY);
line("  DB_HOST={$dbHost}  DB_NAME={$dbName}  DB_USER={$dbUser}  (비밀번호는 출력하지 않음)");
line('  .env.local: ' . (is_file(__DIR__ . '/../.env.local') ? '있음' : '없음 → 프로젝트 루트에 만들어야 로컬 DB 설정이 적용됨'));
if (is_file(__DIR__ . '/../app/.env')) {
    line('  ⚠ app/.env 가 있음 → .env.local 보다 먼저 읽혀 DB 설정을 덮어씀(운영용 파일이면 로컬에서 제거 필요)');
}

$isLocalHost = in_array(strtolower($dbHost), ['localhost', '127.0.0.1', '::1', 'mysql'], true);
if (!preg_match('/_test$/', $dbName) || !$isLocalHost) {
    stop("로컬 테스트 DB(DB_NAME=*_test, DB_HOST=localhost|127.0.0.1)가 아닙니다. .env.local 의 DB 설정을 확인하세요.");
}
$pdo = db();
if (!$pdo) {
    stop('DB 접속 실패 — XAMPP MySQL(MariaDB) 실행 여부, .env.local 의 DB_USER/DB_PASS, DB 생성 여부를 확인하세요.');
}
$connected = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
if ($connected !== $dbName) {
    stop("접속된 DB({$connected})가 설정({$dbName})과 다릅니다.");
}
line("  접속 확인: {$connected} @ " . (string) $pdo->query('SELECT VERSION()')->fetchColumn());

$tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
if (!in_array('users', $tables, true)) {
    stop('users 테이블이 없습니다. database/add_users_auth.sql 을 로컬 DB에 먼저 적용하세요.');
}
line('  favorite_products 테이블: ' . (in_array('favorite_products', $tables, true) ? '있음' : '없음 → database/add_reorder.sql 적용 필요(관심상품 저장 불가)'));
$byRole = $pdo->query('SELECT role, COUNT(*) AS cnt FROM users GROUP BY role ORDER BY role')->fetchAll();
line('  회원 수(역할별): ' . ($byRole ? implode(', ', array_map(static fn($r) => "{$r['role']} {$r['cnt']}", $byRole)) : '0명'));

// ── 2. .env.local 의 테스트 계정 값 검증 ─────────────────────
$want = [];
foreach (TEST_ACCOUNTS as $role => $a) {
    $email = trim((string) getenv($a['env'] . '_EMAIL'));
    $pw    = (string) getenv($a['env'] . '_PASSWORD');
    $want[$role] = ['email' => $email, 'pw' => $pw] + $a;
}

$sel = $pdo->prepare('SELECT id, role, name, company_name, password_hash, is_active FROM users WHERE email = :email');
line();
line('[테스트 계정]');
$problems = [];
foreach ($want as $role => $w) {
    $label = $role === 'customer' ? '일반회원' : '기업회원';
    if ($w['email'] === '' || $w['pw'] === '') {
        $problems[] = "{$label}: .env.local 에 {$w['env']}_EMAIL / {$w['env']}_PASSWORD 가 비어 있음";
        line("  {$label}: 설정 없음");
        continue;
    }
    if (!filter_var($w['email'], FILTER_VALIDATE_EMAIL) || mb_strlen($w['email']) > 160) {
        $problems[] = "{$label}: 이메일 형식 오류";
    }
    if (mb_strlen($w['pw']) < 8 || mb_strlen($w['pw']) > 100) {
        $problems[] = "{$label}: 비밀번호는 8~100자(회원가입 규칙과 동일)";
    }
    $sel->execute(['email' => $w['email']]);
    $row = $sel->fetch();
    if (!$row) {
        line("  {$label} {$w['email']}: 없음");
    } elseif (strpos((string) $row['name'], TEST_MARK) !== 0) {
        $problems[] = "{$label}: {$w['email']} 은(는) [TEST] 표시가 없는 기존 회원 — 이 도구로 수정하지 않음. 다른 이메일을 쓰세요";
        line("  {$label} {$w['email']}: [TEST] 아닌 기존 회원(수정 불가)");
    } else {
        $pwOk = password_verify($w['pw'], (string) $row['password_hash']);
        line("  {$label} {$w['email']}: 있음 (id {$row['id']}, role {$row['role']}, "
            . ((int) $row['is_active'] === 1 ? '활성' : '비활성') . ', 비밀번호 ' . ($pwOk ? '.env.local 과 일치' : '불일치') . ')');
    }
}
if ($want['customer']['email'] !== '' && strcasecmp($want['customer']['email'], $want['corporate']['email']) === 0) {
    $problems[] = '일반회원·기업회원 이메일이 같음';
}

if ($mode === '--check') {
    foreach ($problems as $p) { line("  ⚠ {$p}"); }
    line();
    line('점검만 했습니다(DB 변경 없음). 계정 생성/갱신: php scripts/local-test-accounts.php --apply');
    exit(0);
}

// ── 3. 생성/갱신 또는 삭제 (로컬 _test DB만) ───────────────────
if ($mode === '--remove') {
    $del = $pdo->prepare('DELETE FROM users WHERE email = :email AND name LIKE :mark');
    foreach ($want as $role => $w) {
        if ($w['email'] === '') { continue; }
        $del->execute(['email' => $w['email'], 'mark' => TEST_MARK . '%']);
        line("  삭제 {$w['email']}: " . $del->rowCount() . '건');
    }
    exit(0);
}

if ($problems) {
    foreach ($problems as $p) { line("  ✗ {$p}"); }
    stop('위 문제를 .env.local 에서 고친 뒤 다시 실행하세요. (DB 변경 없음)');
}

$ins = $pdo->prepare(
    'INSERT INTO users (role, name, email, password_hash, company_name, is_active, created_at)
     VALUES (:role, :name, :email, :hash, :company, 1, NOW())'
);
$upd = $pdo->prepare(
    'UPDATE users SET role = :role, name = :name, company_name = :company, is_active = 1, password_hash = :hash
     WHERE id = :id AND name LIKE :mark'
);
line();
line('[생성/갱신]');
$pdo->beginTransaction();
try {
    foreach ($want as $role => $w) {
        $sel->execute(['email' => $w['email']]);
        $row = $sel->fetch();
        if (!$row) {
            $ins->execute(['role' => $role, 'name' => $w['name'], 'email' => $w['email'],
                           'hash' => password_hash($w['pw'], PASSWORD_BCRYPT), 'company' => $w['company']]);
            line("  생성 {$w['email']} (role {$role}, id " . $pdo->lastInsertId() . ')');
            continue;
        }
        $same = $row['role'] === $role && $row['name'] === $w['name'] && $row['company_name'] === $w['company']
            && (int) $row['is_active'] === 1 && password_verify($w['pw'], (string) $row['password_hash']);
        if ($same) {
            line("  변경 없음 {$w['email']} (id {$row['id']})");
            continue;
        }
        $hash = password_verify($w['pw'], (string) $row['password_hash']) ? (string) $row['password_hash'] : password_hash($w['pw'], PASSWORD_BCRYPT);
        $upd->execute(['role' => $role, 'name' => $w['name'], 'company' => $w['company'], 'hash' => $hash,
                       'id' => (int) $row['id'], 'mark' => TEST_MARK . '%']);
        line("  갱신 {$w['email']} (id {$row['id']}, role {$role})");
    }
    $pdo->commit();
} catch (\Throwable $e) {
    $pdo->rollBack();
    stop('저장 실패(변경 취소됨): ' . $e->getMessage());
}
line();
line('완료. 로컬 사이트 헤더의 [로그인]에서 .env.local 의 이메일/비밀번호로 로그인하세요.');
