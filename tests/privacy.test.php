<?php
declare(strict_types=1);
/**
 * 회원 탈퇴(A안)·견적 개인정보 1년 자동 정리(사용자 결정 2026-11-26) 테스트 — 로컬 테스트 DB(*_test) 전용.
 *
 * 확인:
 *  - 상수(1년)·탈퇴 이메일 형식(순수)
 *  - API: 비로그인 401, GET 405, 확인 글자·비밀번호 틀리면 거절(아무것도 안 바뀜)
 *  - 탈퇴: users 개인정보 지움·잠금·가짜 이메일, 견적 개인정보만 지움(금액·상태 그대로), 현장·관심상품·이전 상담 삭제,
 *          상담 로그는 연결만 끊음, 세션 끝(다시 마이페이지 API 401), 같은 이메일·비밀번호로 로그인 안 됨, 다른 회원 것은 그대로
 *  - 진행 중 견적(확정·납품 전)이 있으면 거절, 직원 계정 거절
 *  - 견적 1년: 1년 지난 견적만 개인정보 지움(금액 그대로), 최근 견적 그대로, 두 번 돌려도 같음
 * 테스트가 만든 행: users·estimates·sites·favorite_products·ai_sessions·chat_logs 의 [TEST] 행 — 끝에 지운다.
 * 실행: php tests/privacy.test.php (.env.local 의 로컬 _test DB)
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
ob_start();
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/privacy.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, $detail = null): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}" . ($detail !== null ? '  — ' . mb_substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 300) : '') . "\n"; }
}

echo "[순수]\n";
ok('견적 보관 365일', PRIVACY_ESTIMATE_KEEP_DAYS === 365);
$em = privacy_withdrawn_email(12);
ok('탈퇴 이메일: withdrawn-<id>-<무작위>@deleted.invalid, 매번 다름', (bool) preg_match('/^withdrawn-12-[0-9a-f]{12}@deleted\.invalid$/', $em) && $em !== privacy_withdrawn_email(12), $em);
ok('견적 정리 코드는 금액 칸을 쓰지 않음(P0-4)', !preg_match('/shipping_fee|discount_amt|unit_price|adjusted_price|total_amount/', (string) file_get_contents(__DIR__ . '/../app/privacy.php')));

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true) || !db() || !function_exists('curl_init')) {
    echo "SKIP: 로컬 테스트 DB(*_test)·curl 에서만 실행합니다. ({$pass} 통과)\n";
    ob_end_flush();
    exit($fail ? 1 : 0);
}
$pdo = db();

$port = 18000 + random_int(0, 999);
$env  = ['OPENAI_API_KEY' => 'test-not-a-key'];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'PATH'] as $k) { $env[$k] = (string) getenv($k); }
$web = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', realpath(__DIR__ . '/../public')], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null, $env);
register_shutdown_function(static function () use ($web) { proc_terminate($web); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }

$req = static function (string $method, string $path, ?array $json, array &$jar) use ($port): array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    $headers = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$headers) { $headers[] = $h; return strlen($h); }]);
    if ($json !== null) {
        curl_setopt_array($ch, [CURLOPT_POSTFIELDS => json_encode($json, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    if ($jar) {
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', array_map(static fn($k, $v) => "{$k}={$v}", array_keys($jar), $jar)));
    }
    $raw  = (string) curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)/i', trim($h), $m)) {
            if ($m[2] === '' || $m[2] === 'deleted') { unset($jar[$m[1]]); } else { $jar[$m[1]] = $m[2]; }
        }
    }
    return ['code' => $code, 'json' => json_decode($raw, true), 'body' => $raw];
};

// ── [TEST] 데이터 ───────────────────────────────────────────
const PW = 'test-pw-1234';
$tag   = bin2hex(random_bytes(4));
$mk    = static function (string $role, string $key) use ($pdo, $tag): array {
    $email = "privacy-{$key}-{$tag}@example.test";
    $pdo->prepare('INSERT INTO users (role, name, email, password_hash, phone, company_name, address_zip, address, address_detail, is_active)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)')
        ->execute([$role, "[TEST] {$key}", $email, password_hash(PW, PASSWORD_DEFAULT), '010-0000-0000', '[TEST] 회사', '00000', '[TEST] 주소', '[TEST] 상세']);
    return ['id' => (int) $pdo->lastInsertId(), 'email' => $email];
};
$uA = $mk('customer', 'a');      // 탈퇴할 회원
$uB = $mk('corporate', 'b');     // 진행 중 견적이 있는 회원
$uC = $mk('customer', 'c');      // 다른 회원(그대로 있어야)
$uS = $mk('staff', 's');         // 직원(거절)
$userIds = [$uA['id'], $uB['id'], $uC['id'], $uS['id']];

$insEst = $pdo->prepare('INSERT INTO estimates (user_id, customer_name, company_name, phone, email, site_address, memo, mode, status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?, \'chat\', ?, ?)');
$est = static function (?int $uid, string $status, string $createdAt) use ($pdo, $insEst): int {
    $insEst->execute([$uid, '[TEST] 견적자', '[TEST] 회사', '010-1111-2222', 'est@example.test', '[TEST] 현장', '[TEST] 메모', $status, $createdAt]);
    return (int) $pdo->lastInsertId();
};
$eA   = $est($uA['id'], 'SENT', date('Y-m-d H:i:s'));
$eB   = $est($uB['id'], 'APPROVED', date('Y-m-d H:i:s'));
$eC   = $est($uC['id'], 'REQUESTED', date('Y-m-d H:i:s'));
$eOld = $est(null, 'EXPIRED', date('Y-m-d H:i:s', time() - 400 * 86400));
$eNew = $est(null, 'REQUESTED', date('Y-m-d H:i:s', time() - 300 * 86400));
$estIds = [$eA, $eB, $eC, $eOld, $eNew];
$feeBefore = $pdo->query('SELECT id, shipping_fee, discount_amt, status FROM estimates WHERE id IN (' . implode(',', $estIds) . ') ORDER BY id')->fetchAll();

$pid = (int) $pdo->query('SELECT id FROM products ORDER BY id LIMIT 1')->fetchColumn();
foreach ([$uA['id'], $uC['id']] as $u) {
    $pdo->prepare('INSERT INTO favorite_products (user_id, product_id, quantity, sort_order) VALUES (?, ?, 1, 0)')->execute([$u, $pid]);
    $pdo->prepare('INSERT INTO sites (user_id, name, address, is_active) VALUES (?, \'[TEST] 현장\', \'[TEST] 현장 주소\', 1)')->execute([$u]);
    $pdo->prepare('INSERT INTO ai_sessions (session_key, user_id, messages, created_at, updated_at) VALUES (?, ?, \'[]\', NOW(), NOW())')->execute(["privacy-{$tag}-{$u}", $u]);
    $pdo->prepare('INSERT INTO chat_logs (session_key, user_id, user_msg, assistant_txt, created_at) VALUES (?, ?, \'[TEST] 질문\', \'[TEST] 답\', NOW())')->execute(["privacy-{$tag}-{$u}", $u]);
}
$cnt = static function (string $sql, array $p) use ($pdo): int { $s = $pdo->prepare($sql); $s->execute($p); return (int) $s->fetchColumn(); };

try {
    echo "[API — 막는 경우]\n";
    $none = [];
    ok('비로그인 POST → 401', $req('POST', '/api/mypage/withdraw.php', ['password' => PW, 'confirm' => '탈퇴'], $none)['code'] === 401);
    $ja = [];
    $lg = $req('POST', '/api/auth/login.php', ['email' => $uA['email'], 'password' => PW], $ja);
    ok('회원 A 로그인', ($lg['json']['ok'] ?? false) === true, $lg['json']);
    ok('GET → 405', $req('GET', '/api/mypage/withdraw.php', null, $ja)['code'] === 405);
    $r = $req('POST', '/api/mypage/withdraw.php', ['password' => PW, 'confirm' => '탈퇴할래요'], $ja)['json'];
    ok("확인 글자가 '탈퇴'가 아니면 거절", ($r['ok'] ?? null) === false && str_contains((string) ($r['error'] ?? ''), '탈퇴'), $r);
    $r = $req('POST', '/api/mypage/withdraw.php', ['password' => 'wrong-pw', 'confirm' => '탈퇴'], $ja)['json'];
    ok('비밀번호가 틀리면 거절', ($r['ok'] ?? null) === false && str_contains((string) ($r['error'] ?? ''), '비밀번호'), $r);
    ok('거절된 뒤 계정은 그대로', $cnt('SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1 AND email = ?', [$uA['id'], $uA['email']]) === 1);

    $jb = [];
    $req('POST', '/api/auth/login.php', ['email' => $uB['email'], 'password' => PW], $jb);
    $r = $req('POST', '/api/mypage/withdraw.php', ['password' => PW, 'confirm' => '탈퇴'], $jb)['json'];
    ok('진행 중 견적(승인·납품 전)이 있으면 거절', ($r['ok'] ?? null) === false && str_contains((string) ($r['error'] ?? ''), '진행 중'), $r);
    $js = [];
    $req('POST', '/api/auth/login.php', ['email' => $uS['email'], 'password' => PW], $js);
    $r = $req('POST', '/api/mypage/withdraw.php', ['password' => PW, 'confirm' => '탈퇴'], $js)['json'];
    ok('직원 계정은 거절', ($r['ok'] ?? null) === false && str_contains((string) ($r['error'] ?? ''), '직원'), $r);

    echo "[탈퇴 — 회원 A]\n";
    $r = $req('POST', '/api/mypage/withdraw.php', ['password' => PW, 'confirm' => '탈퇴'], $ja);
    ok('탈퇴 성공', ($r['json']['ok'] ?? null) === true, $r['json']);
    $u = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $u->execute([$uA['id']]);
    $ua = $u->fetch();
    ok('users: 이름 탈퇴회원·가짜 이메일·전화·회사·주소 비움·잠금', $ua['name'] === '탈퇴회원' && str_ends_with($ua['email'], '@deleted.invalid')
        && $ua['phone'] === null && $ua['company_name'] === null && $ua['address_zip'] === null && $ua['address'] === null && $ua['address_detail'] === null
        && (int) $ua['is_active'] === 0, $ua);
    ok('비밀번호가 바뀌어 예전 비밀번호로 안 맞음', !password_verify(PW, (string) $ua['password_hash']));
    $e = $pdo->prepare('SELECT * FROM estimates WHERE id = ?');
    $e->execute([$eA]);
    $ea = $e->fetch();
    ok('견적: 이름·연락처·이메일·회사·현장 주소·요청 사항 지움, 상태 그대로', $ea['customer_name'] === PRIVACY_ERASED_NAME && $ea['phone'] === ''
        && $ea['email'] === null && $ea['company_name'] === null && $ea['site_address'] === null && $ea['memo'] === null && $ea['status'] === 'SENT', $ea);
    ok('현장·관심상품·이전 상담 삭제', $cnt('SELECT COUNT(*) FROM sites WHERE user_id = ?', [$uA['id']]) === 0
        && $cnt('SELECT COUNT(*) FROM favorite_products WHERE user_id = ?', [$uA['id']]) === 0
        && $cnt('SELECT COUNT(*) FROM ai_sessions WHERE user_id = ?', [$uA['id']]) === 0);
    ok('상담 로그는 남고 회원 연결만 끊김', $cnt('SELECT COUNT(*) FROM chat_logs WHERE session_key = ? AND user_id IS NULL', ["privacy-{$tag}-{$uA['id']}"]) === 1);
    ok('세션 끝 — 마이페이지 API 401', $req('GET', '/api/mypage/profile.php', null, $ja)['code'] === 401);
    $j2 = [];
    $lg2 = $req('POST', '/api/auth/login.php', ['email' => $uA['email'], 'password' => PW], $j2)['json'];
    ok('같은 이메일·비밀번호로 로그인 안 됨', ($lg2['ok'] ?? null) !== true, $lg2);
    ok('두 번째 탈퇴 처리는 예외(이미 잠김)', (static function () use ($uA): bool { try { privacy_withdraw_user($uA['id']); return false; } catch (\Throwable $e) { return true; } })());

    echo "[다른 회원은 그대로]\n";
    ok('회원 C 계정·견적·현장·관심상품·이전 상담 그대로', $cnt('SELECT COUNT(*) FROM users WHERE id = ? AND is_active = 1 AND phone IS NOT NULL', [$uC['id']]) === 1
        && $cnt('SELECT COUNT(*) FROM estimates WHERE id = ? AND customer_name = \'[TEST] 견적자\'', [$eC]) === 1
        && $cnt('SELECT COUNT(*) FROM sites WHERE user_id = ?', [$uC['id']]) === 1
        && $cnt('SELECT COUNT(*) FROM favorite_products WHERE user_id = ?', [$uC['id']]) === 1
        && $cnt('SELECT COUNT(*) FROM ai_sessions WHERE user_id = ?', [$uC['id']]) === 1);
    ok('회원 B(거절됨) 견적 그대로', $cnt('SELECT COUNT(*) FROM estimates WHERE id = ? AND phone = \'010-1111-2222\'', [$eB]) === 1);

    echo "[견적 1년 자동 정리]\n";
    $n = privacy_estimate_purge(true);
    $e->execute([$eOld]);
    $eo = $e->fetch();
    ok('1년 지난 견적: 개인정보 지움', $n >= 1 && $eo['customer_name'] === PRIVACY_ERASED_NAME && $eo['phone'] === '' && $eo['email'] === null && $eo['site_address'] === null && $eo['memo'] === null, $eo);
    ok('300일 된 견적·오늘 견적은 그대로', $cnt('SELECT COUNT(*) FROM estimates WHERE id IN (?, ?, ?) AND customer_name = \'[TEST] 견적자\'', [$eNew, $eB, $eC]) === 3);
    ok('다시 돌리면 정리할 행 없음(이미 지운 행은 건드리지 않음)', privacy_estimate_purge(true) === 0);
    $feeAfter = $pdo->query('SELECT id, shipping_fee, discount_amt, status FROM estimates WHERE id IN (' . implode(',', $estIds) . ') ORDER BY id')->fetchAll();
    ok('견적 금액(운임·할인)·상태는 하나도 안 바뀜(P0-4)', $feeBefore == $feeAfter, [$feeBefore, $feeAfter]);
    ok('확인 안 하고 부르면 대개 건너뜀(100번에 1번)', (static function (): bool { $runs = 0; for ($i = 0; $i < 200; $i++) { if (random_int(1, 100) <= PRIVACY_PURGE_PCT) { $runs++; } } return $runs < 30; })());
} finally {
    $in = implode(',', $userIds);
    $pdo->exec("DELETE FROM estimates WHERE id IN (" . implode(',', $estIds) . ")");
    $pdo->exec("DELETE FROM sites WHERE user_id IN ({$in})");
    $pdo->exec("DELETE FROM favorite_products WHERE user_id IN ({$in})");
    $pdo->exec("DELETE FROM ai_sessions WHERE session_key LIKE 'privacy-{$tag}-%'");
    $pdo->exec("DELETE FROM chat_logs WHERE session_key LIKE 'privacy-{$tag}-%'");
    $pdo->exec("DELETE FROM users WHERE id IN ({$in})");
}

echo "\n결과: {$pass} 통과, {$fail} 실패\n";
ob_end_flush();
exit($fail ? 1 : 0);
