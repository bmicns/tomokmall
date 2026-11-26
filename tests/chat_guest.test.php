<?php
declare(strict_types=1);
/**
 * 비회원 AI 상담 이전 기록(사용자 결정 2026-11-26, data-decisions A12) 테스트 — 로컬 테스트 DB 전용(ai_sessions.guest_hash 칸 필요).
 *
 * 확인:
 *  - 토큰 형식·해시(순수)
 *  - 저장하면 비회원 토큰 쿠키(HttpOnly·SameSite=Lax·30일) 발급, DB 에는 해시만(토큰 원본 없음)
 *  - 브라우저를 닫고 와도(PHP 세션 없이 토큰 쿠키만) 목록·복원(/chat.php?sid=) 됨
 *  - 다른 사람: 토큰 없음·다른 토큰 → 목록 빈칸, sid 를 알아도 복원 안 됨, 남의 비회원 상담에 쓰기 불가
 *  - 빠른 상담 이전 대화: 비회원도 서버 목록(guest 표시)
 *  - 로그인 때 이 기기 비회원 대화 → 회원 계정(묻지 않고), chat_logs 도, 토큰 쿠키 삭제
 *  - 보관: 30일 지난 비회원 대화·1년 지난 상담 로그 정리, 최근 것·회원 것은 그대로
 *  - '이 기기 상담 기록 지우기': 이 토큰 대화만 삭제 + 쿠키 삭제, 회원은 거부
 * 테스트가 만든 행: 로컬 테스트 DB 의 ai_sessions·chat_logs — 끝에 지운다.
 * 실행: php tests/chat_guest.test.php (.env.local 의 로컬 _test DB)
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ob_start();
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/rag.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, $detail = null): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}" . ($detail !== null ? '  — ' . mb_substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 300) : '') . "\n"; }
}

echo "[순수 — 토큰 형식·해시]\n";
$t = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
ok('random_bytes(32) base64url = 43자, 형식 통과', strlen($t) === 43 && chat_guest_token_valid($t));
ok('형식이 다르면 거부(짧음·특수문자·빈 값)', !chat_guest_token_valid('abc') && !chat_guest_token_valid(str_repeat('a', 42) . '!') && !chat_guest_token_valid(''));
ok('해시 = SHA-256 64자, 토큰과 다름', strlen(chat_guest_hash_of($t)) === 64 && chat_guest_hash_of($t) !== $t && chat_guest_hash_of($t) === hash('sha256', $t));
ok('보관 기간 = 30일 / 상담 로그 1년', CHAT_GUEST_DAYS === 30 && CHAT_LOG_KEEP_DAYS === 365);
ok('로그인 API 가 기록 옮기기를 부름 · 로그아웃은 토큰 삭제', str_contains((string) file_get_contents(__DIR__ . '/../public/api/auth/login.php'), 'chat_guest_claim(')
    && str_contains((string) file_get_contents(__DIR__ . '/../public/api/auth/logout.php'), 'chat_guest_forget_cookie('));

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true) || !db() || !function_exists('curl_init')) {
    echo "SKIP: 로컬 테스트 DB(*_test)·curl 에서만 실행합니다. ({$pass} 통과)\n";
    ob_end_flush();
    exit($fail ? 1 : 0);
}
$pdo = db();
if (!chat_guest_ready()) {
    echo "SKIP: 로컬 테스트 DB 에 ai_sessions.guest_hash 칸이 없습니다(database/20261126_ai_sessions_guest_hash.sql). ({$pass} 통과)\n";
    ob_end_flush();
    exit($fail ? 1 : 0);
}

$port = 18000 + random_int(0, 999);
$env  = ['OPENAI_API_KEY' => 'test-not-a-key'];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'PATH'] as $k) { $env[$k] = (string) getenv($k); }
$web = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', realpath(__DIR__ . '/../public')], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pp, null, $env);
register_shutdown_function(static function () use ($web) { proc_terminate($web); });
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }

/** 요청 — 쿠키 묶음(이름 => 값)을 보내고 응답 Set-Cookie 로 갱신 */
$req = static function (string $method, string $path, ?array $json, array &$jar) use ($port): array {
    $ch = curl_init("http://127.0.0.1:{$port}{$path}");
    $headers = [];
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60, CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HEADERFUNCTION => static function ($c, $h) use (&$headers) { $headers[] = $h; return strlen($h); }]);
    if ($json !== null) {
        curl_setopt_array($ch, [CURLOPT_POSTFIELDS => json_encode($json, JSON_UNESCAPED_UNICODE), CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    }
    if ($jar) {
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', array_map(static fn($k, $v) => "{$k}={$v}", array_keys($jar), $jar)));
    }
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    $set = [];
    foreach ($headers as $h) {
        if (preg_match('/^Set-Cookie:\s*([^=]+)=([^;]*)(.*)$/i', trim($h), $m)) {
            $set[$m[1]] = $m[2] . $m[3];
            if ($m[2] === '' || stripos($m[3], 'expires=Thu, 01 Jan 1970') !== false || preg_match('/Max-Age=0\b/i', $m[3])) { unset($jar[$m[1]]); }
            else { $jar[$m[1]] = $m[2]; }
        }
    }
    return ['body' => $raw, 'json' => json_decode($raw, true), 'set' => $set];
};
$sidOf = static fn(string $html): string => preg_match("/window\\.SID = '([a-zA-Z0-9_-]+)'/", $html, $m) ? $m[1] : '';
$made  = [];   // 만든 상담 키(끝에 지움)

echo "[비회원 A — 저장하면 토큰 쿠키·해시만 저장]\n";
$a = [];
$page = $req('GET', '/chat.php', null, $a);
$sidA = $sidOf($page['body']);
$made[] = $sidA;
ok('처음 방문: 상담 키 발급, 토큰 쿠키는 아직 없음', $sidA !== '' && !isset($a[CHAT_GUEST_COOKIE]));
$r = $req('POST', '/chat.php', ['sid' => $sidA, 'user' => '[TEST] 비회원 경계석 문의', 'assistant' => '[TEST] 답변'], $a);
$tokA = (string) ($a[CHAT_GUEST_COOKIE] ?? '');
$ck = (string) ($r['set'][CHAT_GUEST_COOKIE] ?? '');
ok('대화 저장 → 토큰 쿠키 발급(HttpOnly·SameSite=Lax·30일)', ($r['json']['ok'] ?? false) && chat_guest_token_valid($tokA)
    && stripos($ck, 'httponly') !== false && stripos($ck, 'samesite=lax') !== false && preg_match('/Max-Age=(\d+)/i', $ck, $mm) && (int) $mm[1] === 30 * 86400, $ck);
$row = $pdo->prepare('SELECT user_id, guest_hash FROM ai_sessions WHERE session_key = ?');
$row->execute([$sidA]);
$ra = $row->fetch();
ok('DB: user_id NULL · guest_hash = 토큰 해시(원본 아님)', $ra && $ra['user_id'] === null && $ra['guest_hash'] === hash('sha256', $tokA) && $ra['guest_hash'] !== $tokA);
$hasTok = $pdo->prepare('SELECT COUNT(*) FROM ai_sessions WHERE messages LIKE ? OR guest_hash = ?');
$hasTok->execute(['%' . $tokA . '%', $tokA]);
ok('DB 어디에도 토큰 원본 없음', (int) $hasTok->fetchColumn() === 0);

echo "[브라우저를 닫고 다시 — PHP 세션 없이 토큰 쿠키만]\n";
$a2 = [CHAT_GUEST_COOKIE => $tokA];
$h = $req('GET', '/api/chat/history.php?limit=10', null, $a2)['json'];
ok('이전 상담 목록에 보임(guest 표시)', ($h['ok'] ?? false) && ($h['guest'] ?? false) === true && in_array($sidA, array_column($h['sessions'] ?? [], 'session_key'), true), $h);
$p2 = $req('GET', '/chat.php?sid=' . $sidA, null, $a2)['body'];
ok('/chat.php?sid= 복원 — 같은 상담 키·대화 글', $sidOf($p2) === $sidA && str_contains($p2, '[TEST] 비회원 경계석 문의'));
ok('이전 상담 목록 막대가 화면에 있음', str_contains($p2, 'id="history-bar"') && str_contains($p2, 'id="hist-forget"'));
$r = $req('POST', '/chat.php', ['sid' => $sidA, 'user' => '[TEST] 이어서 질문', 'assistant' => '[TEST] 이어서 답'], $a2);
ok('복원한 상담에 이어 저장', ($r['json']['ok'] ?? false) === true);

echo "[다른 사람 — 토큰 없음·다른 토큰]\n";
$b = [];
$hb = $req('GET', '/api/chat/history.php?limit=10', null, $b)['json'];
ok('토큰 없음: 목록 빈칸', ($hb['ok'] ?? false) && ($hb['sessions'] ?? null) === []);
$pb = $req('GET', '/chat.php?sid=' . $sidA, null, $b)['body'];
ok('sid 를 알아도 복원 안 됨(새 상담 키, 대화 글 없음)', $sidOf($pb) !== $sidA && !str_contains($pb, '[TEST] 비회원 경계석 문의'));
ok('토큰 없는 비회원에겐 이전 상담 막대 없음', !str_contains($pb, 'id="history-bar"'));
$r = $req('POST', '/chat.php', ['sid' => $sidA, 'user' => '[TEST] 끼어들기', 'assistant' => 'x'], $b);
ok('남의 상담 키로 저장 불가', ($r['json']['ok'] ?? null) === false);
$fake = [CHAT_GUEST_COOKIE => rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=')];
$hf = $req('GET', '/api/chat/history.php?limit=10', null, $fake)['json'];
ok('다른 토큰: 목록 빈칸', ($hf['ok'] ?? false) && ($hf['sessions'] ?? null) === []);
ok('다른 토큰: 복원 안 됨', !str_contains($req('GET', '/chat.php?sid=' . $sidA, null, $fake)['body'], '[TEST] 비회원 경계석 문의'));
$_COOKIE[CHAT_GUEST_COOKIE] = $fake[CHAT_GUEST_COOKIE];
ok('쓰기 권한: 다른 토큰이면 거부', rag_session_writable($sidA, null) === false);
$_COOKIE[CHAT_GUEST_COOKIE] = $tokA;
ok('쓰기 권한: 같은 토큰이면 허용', rag_session_writable($sidA, null) === true);
unset($_COOKIE[CHAT_GUEST_COOKIE]);
ok('쓰기 권한: 토큰 없으면 거부', rag_session_writable($sidA, null) === false);
ok('소유 확인: 회원 id 로는 비회원 상담 안 열림', rag_load_owned_session($sidA, 999999) === null);

echo "[빠른 상담 이전 대화 — 비회원도 서버 목록]\n";
$qcKey = 'qc' . bin2hex(random_bytes(15));
$made[] = $qcKey;
$pdo->prepare('INSERT INTO ai_sessions (session_key, user_id, guest_hash, messages) VALUES (?, NULL, ?, ?)')
    ->execute([$qcKey, hash('sha256', $tokA), json_encode([['role' => 'user', 'content' => '[TEST] 빠른 질문'], ['role' => 'assistant', 'content' => 'ok']], JSON_UNESCAPED_UNICODE)]);
$qh = $req('POST', '/api/chat/quick.php', ['action' => 'history', 'sid' => ''], $a2)['json'];
ok('history: server·guest, 이 기기 대화', ($qh['server'] ?? false) && ($qh['guest'] ?? false) && in_array($qcKey, array_column($qh['list'] ?? [], 'sid'), true), $qh);
$ql = $req('POST', '/api/chat/quick.php', ['action' => 'load', 'sid' => $qcKey], $a2)['json'];
ok('load: 본인 대화 열림', ($ql['ok'] ?? false) && ($ql['sid'] ?? '') === $qcKey);
$qb = $req('POST', '/api/chat/quick.php', ['action' => 'load', 'sid' => $qcKey], $fake)['json'];
ok('load: 다른 토큰은 못 엶', ($qb['ok'] ?? null) === false || ($qb['server'] ?? null) === false);
$qn = $req('POST', '/api/chat/quick.php', ['action' => 'history', 'sid' => ''], $b)['json'];
ok('토큰 없는 비회원: server:false(탭 기록 그대로)', ($qn['server'] ?? null) === false);

echo "[로그인 — 이 기기 비회원 대화를 회원 계정으로]\n";
$uid = (int) $pdo->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
$pdo->prepare('INSERT INTO chat_logs (session_key, user_id, user_msg, assistant_txt) VALUES (?, NULL, ?, ?)')->execute([$sidA, '[TEST] 비회원 경계석 문의', '[TEST] 답변']);
$_COOKIE[CHAT_GUEST_COOKIE] = $tokA;
$moved = chat_guest_claim($uid);
$row->execute([$sidA]);
$rc = $row->fetch();
$lg = $pdo->prepare('SELECT COUNT(*) FROM chat_logs WHERE session_key = ? AND user_id = ?');
$lg->execute([$sidA, $uid]);
ok('옮긴 대화 2건(기존 AI 상담 + 빠른 상담)', $moved === 2, $moved);
ok('user_id = 회원, guest_hash = NULL', $rc && (int) $rc['user_id'] === $uid && $rc['guest_hash'] === null);
ok('chat_logs 도 회원으로', (int) $lg->fetchColumn() === 1);
ok('토큰 쿠키 지움', !isset($_COOKIE[CHAT_GUEST_COOKIE]));
ok('회원 소유 확인으로 열림', rag_load_owned_session($sidA, $uid) !== null);
ok('옛 토큰으로는 더 이상 안 보임', ($req('GET', '/api/chat/history.php?limit=10', null, $a2)['json']['sessions'] ?? null) === []);

echo "[보관 기간 정리 — 30일·1년]\n";
$oldG = 'zzt' . bin2hex(random_bytes(10)); $newG = 'zzt' . bin2hex(random_bytes(10)); $oldM = 'zzt' . bin2hex(random_bytes(10));
array_push($made, $oldG, $newG, $oldM);
$ins = $pdo->prepare('INSERT INTO ai_sessions (session_key, user_id, guest_hash, messages, updated_at) VALUES (?, ?, ?, ?, ?)');
$ins->execute([$oldG, null, hash('sha256', 'x'), '[]', date('Y-m-d H:i:s', time() - 31 * 86400)]);
$ins->execute([$newG, null, hash('sha256', 'y'), '[]', date('Y-m-d H:i:s', time() - 29 * 86400)]);
$ins->execute([$oldM, $uid, null, '[]', date('Y-m-d H:i:s', time() - 400 * 86400)]);
$pdo->prepare('INSERT INTO chat_logs (session_key, user_id, user_msg, assistant_txt, created_at) VALUES (?, NULL, ?, ?, ?)')
    ->execute([$oldG, '[TEST] 오래된 로그', 'x', date('Y-m-d H:i:s', time() - 366 * 86400)]);
$pdo->prepare('INSERT INTO chat_logs (session_key, user_id, user_msg, assistant_txt, created_at) VALUES (?, NULL, ?, ?, ?)')
    ->execute([$newG, '[TEST] 11개월 로그', 'x', date('Y-m-d H:i:s', time() - 330 * 86400)]);
$purged = chat_guest_purge(true);
$exists = static function (string $k) use ($pdo): bool { $s = $pdo->prepare('SELECT COUNT(*) FROM ai_sessions WHERE session_key = ?'); $s->execute([$k]); return (int) $s->fetchColumn() > 0; };
$logs   = static function (string $k) use ($pdo): int { $s = $pdo->prepare('SELECT COUNT(*) FROM chat_logs WHERE session_key = ?'); $s->execute([$k]); return (int) $s->fetchColumn(); };
ok('31일 지난 비회원 대화 삭제 · 29일은 그대로', !$exists($oldG) && $exists($newG), $purged);
ok('회원 대화는 오래돼도 그대로', $exists($oldM));
ok('1년 지난 상담 로그 삭제 · 11개월은 그대로', $logs($oldG) === 0 && $logs($newG) === 1);

echo "[이 기기 상담 기록 지우기]\n";
$c = [];
$sidC = $sidOf($req('GET', '/chat.php', null, $c)['body']);
$made[] = $sidC;
$req('POST', '/chat.php', ['sid' => $sidC, 'user' => '[TEST] 지울 상담', 'assistant' => 'x'], $c);
ok('지우기 전: 저장됨·토큰 있음', $exists($sidC) && isset($c[CHAT_GUEST_COOKIE]));
$f = $req('POST', '/api/chat/history.php', ['action' => 'forget'], $c);
ok('지우기: 이 토큰 대화 삭제 + 쿠키 삭제', ($f['json']['ok'] ?? false) && ($f['json']['deleted'] ?? 0) === 1 && !$exists($sidC) && !isset($c[CHAT_GUEST_COOKIE]), $f['json']);
ok('다른 사람 대화는 그대로', $exists($newG) && $exists($sidA));
ok('잘못된 요청 거부', ($req('POST', '/api/chat/history.php', ['action' => 'x'], $c)['json']['ok'] ?? null) === false);

// 정리
$in = implode(',', array_fill(0, count($made), '?'));
$pdo->prepare("DELETE FROM chat_logs WHERE session_key IN ($in)")->execute($made);
$pdo->prepare("DELETE FROM ai_sessions WHERE session_key IN ($in)")->execute($made);

echo "\n결과: {$pass} 통과, {$fail} 실패\n";
ob_end_flush();
exit($fail ? 1 : 0);
