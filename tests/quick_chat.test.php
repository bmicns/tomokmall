<?php
declare(strict_types=1);
/**
 * 우측 하단 'AI 빠른 견적' 창(사용자 결정 2026-11-23 — 지금 AI 상담과 비교용) 테스트 — 로컬 테스트 DB 전용, 실제 OpenAI 호출 없음(127.0.0.1 목업).
 *
 * 확인:
 *  - 보이는 사람: 스위치 'staff' = 관리자·직원만, 'all' = 모두, 'off' = 아무도, 견적 카드 스위치를 끄면 안 보임
 *  - 프롬프트: 기본 상담은 그대로, 빠른 창은 기본 + '빠른 견적 창 답변 방식' 구역만 덧붙음
 *  - API(/api/chat/quick.php): 비로그인 403, 직원 → 질문 → 검색·담기 도구 실행 → 견적 표(quote_draft) 돌려줌, 상담 키 'qc…', 대화 저장, 200자 초과 거부
 *  - 화면: 직원에게만 버튼·창 출력, AI 상담 화면(chat)에는 없음
 * 테스트가 만든 행: 로컬 테스트 DB 의 ai_sessions·chat_logs(상담 키 'qc…') — 끝에 지운다.
 * 실행: php tests/quick_chat.test.php (.env.local 의 로컬 _test DB)
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ob_start();   // CLI 에서 세션을 다시 열어 파일에 저장하려면 출력을 모아 둬야 함
$tmp  = sys_get_temp_dir() . '/quick_chat_test_' . getmypid();
@mkdir($tmp, 0777, true);
$mockPort = 19300 + getmypid() % 300;
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/rag.php';
require_once __DIR__ . '/../app/layout.php';

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true) || !db() || !function_exists('curl_init')) {
    echo "SKIP: 로컬 테스트 DB(*_test)·curl 에서만 실행합니다.\n";
    ob_end_flush();
    exit(0);
}
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, $detail = null): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}" . ($detail !== null ? '  — ' . mb_substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 300) : '') . "\n"; }
}

echo "[보이는 사람 — 기능 스위치]\n";
$staff = ['id' => 1, 'role' => 'staff', 'name' => 's', 'email' => 's@x'];
$admin = ['role' => 'admin'] + $staff;
$cust  = ['role' => 'customer'] + $staff;
ok("'staff': 직원·관리자만", ai_quick_chat_visible($staff, 'staff') && ai_quick_chat_visible($admin) && !ai_quick_chat_visible($cust) && !ai_quick_chat_visible(null));
ok("'all': 비로그인도", ai_quick_chat_visible(null, 'all') && ai_quick_chat_visible($cust));
ok("'off': 아무도", !ai_quick_chat_visible($admin, 'off'));
ai_quick_chat_visible(null, 'all');
ai_quote_card_enabled(false);
ok('견적 카드 스위치를 끄면 안 보임', !ai_quick_chat_visible($admin));
ai_quote_card_enabled(true);
ai_quick_chat_visible(null, FEATURE_AI_QUICK_CHAT);
ok('운영 기본값 = 관리자·직원만', FEATURE_AI_QUICK_CHAT === 'staff');

echo "[프롬프트 — 기본 상담 그대로, 빠른 창은 구역만 덧붙음]\n";
$msgs = [['role' => 'user', 'content' => '마당 경계석 30m 차는 안 다녀요']];
$pc = rag_build_prompt($msgs, [], 'consult')['system'];
$pq = rag_build_prompt($msgs, [], 'quick')['system'];
ok('기본 = style 생략과 같음', $pc === rag_build_prompt($msgs)['system']);
ok('빠른 창 = 기본 + 빠른 견적 창 구역', $pq === $pc . RAG_QUICK_STYLE_PROMPT && !str_contains($pc, '빠른 견적 창 답변 방식'));

echo "[화면 — 버튼·창 출력]\n";
$html = static function (string $mode, ?array $user): string {
    $_SESSION['user'] = $user;
    $GLOBALS['__layout_mode'] = $mode;
    ob_start();
    render_quick_chat();
    return (string) ob_get_clean();
};
ok('직원·일반 화면: 버튼·창·스크립트', str_contains($h = $html('normal', $staff), 'id="qc-fab"') && str_contains($h, '/assets/quick-chat.js') && str_contains($h, '관리자·직원 시험 중'));
ok('고객·비로그인: 없음', $html('normal', $cust) === '' && $html('home', null) === '');
ok('AI 상담 화면(chat): 없음', $html('chat', $staff) === '');
$_SESSION['user'] = null;

// ── 목업 OpenAI + 내장 서버 ───────────────────────────────────
$pdo = db();
// 로그인 세션의 사용자 id: 직원 계정이 있으면 그 id, 없으면(개인정보 없이 복제한 테스트 DB) [TEST] 계정 id — 역할 'staff' 는 세션에만
$staffId = (int) ($pdo->query("SELECT id FROM users WHERE role IN ('staff','admin') ORDER BY id LIMIT 1")->fetchColumn()
    ?: $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn());
$pid = 0;
foreach ($pdo->query("SELECT id, spec FROM products WHERE is_active = 1 AND price_b2c > 0 AND name LIKE '%경계석%' ORDER BY id LIMIT 50")->fetchAll() as $r) {
    if (freight_unit_kg(parse_spec_field((string) $r['spec'])['weight']) > 0) { $pid = (int) $r['id']; break; }
}
if ($staffId <= 0 || $pid <= 0) {
    echo "SKIP(API): 직원 계정 또는 중량 있는 경계석 상품이 없습니다\n";
} else {
    file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n);
file_put_contents("$d/req-$n.json", file_get_contents("php://input")); $r = json_decode(file_get_contents("$d/responses.json"), true);
header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
    $call = static fn(string $name, array $args, int $i) => ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
        'tool_calls' => [['id' => "call_{$i}", 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]]]]]]];
    $say = static fn(string $t) => ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $t]]]];
    file_put_contents("{$tmp}/responses.json", json_encode([
        $call('search_products', ['query' => '경계석', 'length_m' => 30], 1),
        $call('add_to_cart', ['product_id' => $pid, 'quantity' => 30, 'region' => '경기'], 2),
        $say('[테스트] 차가 다니지 않는 마당이라 일반 경계석을 담았습니다.'),
    ], JSON_UNESCAPED_UNICODE));
    $mock = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$mockPort}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p1, $tmp);

    $port = 18000 + random_int(0, 999);
    $env  = ['OPENAI_CHAT_URL' => "http://127.0.0.1:{$mockPort}/v1/chat/completions", 'OPENAI_API_KEY' => 'test-not-a-key'];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'PATH'] as $k) { $env[$k] = (string) getenv($k); }
    $web = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', realpath(__DIR__ . '/../public')], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2, null, $env);
    register_shutdown_function(static function () use ($mock, $web) { proc_terminate($mock); proc_terminate($web); });
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port) && @fsockopen('127.0.0.1', $mockPort)) { break; } usleep(100000); }
    $post = static function (array $body, ?string $cookie) use ($port): array {
        $ch = curl_init("http://127.0.0.1:{$port}/api/chat/quick.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 60]);
        if ($cookie !== null) { curl_setopt($ch, CURLOPT_COOKIE, $cookie); }
        $raw = (string) curl_exec($ch);
        $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$st, json_decode($raw, true)];
    };

    echo "[API — 권한]\n";
    [$st] = $post(['action' => 'new'], null);
    ok('비로그인 → 403', $st === 403);

    // 직원 로그인 세션(로컬 테스트 DB 의 직원 id, 세션 파일에만)
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $_SESSION = ['user' => ['id' => $staffId, 'role' => 'staff', 'name' => '[TEST] 직원', 'email' => 'staff@test.local'], 'cart' => []];
    $cookie = session_name() . '=' . session_id();
    session_write_close();

    echo "[API — 질문 → 검색·담기 → 견적 표]\n";
    [$st, $d] = $post(['action' => 'ask', 'sid' => '', 'msg' => str_repeat('가', 201)], $cookie);
    ok('200자 초과 거부', $st === 200 && ($d['ok'] ?? null) === false);
    [$st, $d] = $post(['action' => 'new'], $cookie);
    $sid = (string) ($d['sid'] ?? '');
    ok("새 대화 키 'qc…'", ($d['ok'] ?? false) && str_starts_with($sid, 'qc') && strlen($sid) === 32);
    [$st, $d] = $post(['action' => 'ask', 'sid' => $sid, 'msg' => '마당 경계석 30m, 차는 안 다녀요. 경기도예요'], $cookie);
    $q = null;
    foreach ($d['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'quote_draft') { $q = $e; } }
    ok('답변 + 같은 상담 키', ($d['ok'] ?? false) && ($d['sid'] ?? '') === $sid && str_contains((string) $d['text'], '[테스트]'), $d);
    ok('견적 표: 담은 상품 30개 · 경기 운임 계산', $q !== null && ($q['items'][0]['product_id'] ?? 0) === $pid && ($q['items'][0]['quantity'] ?? 0) === 30
        && ($q['region'] ?? '') === '경기' && in_array($q['freight']['status'] ?? '', ['ok', 'missing_weight', 'negotiate'], true), $q);
    ok('창에 쓰는 이벤트만(견적 표·장바구니·추천 제품)', !array_diff(array_column($d['events'] ?? [], 'type'), ['quote_draft', 'cart_update', 'product_cards']));
    $req1 = json_decode((string) @file_get_contents("{$tmp}/req-1.json"), true);
    ok('OpenAI 로 보낸 시스템 프롬프트에 빠른 견적 창 구역', str_contains((string) ($req1['messages'][0]['content'] ?? ''), '빠른 견적 창 답변 방식'));
    $saved = $pdo->prepare('SELECT messages FROM ai_sessions WHERE session_key = ?');
    $saved->execute([$sid]);
    $hist = json_decode((string) $saved->fetchColumn(), true) ?: [];
    ok('대화 저장(사용자·AI 2줄)', count($hist) === 2 && ($hist[0]['role'] ?? '') === 'user' && ($hist[1]['role'] ?? '') === 'assistant');
    $logs = $pdo->prepare('SELECT COUNT(*) FROM chat_logs WHERE session_key = ?');
    $logs->execute([$sid]);
    ok('상담 로그 1건(빠른 창 상담은 키로 구분)', (int) $logs->fetchColumn() === 1);
    [$st, $d] = $post(['action' => 'ask', 'sid' => 'qc' . str_repeat('0', 30), 'msg' => '안녕하세요'], $cookie);
    ok('남의(허용 안 된) 키로 물으면 새 키로', ($d['sid'] ?? '') !== 'qc' . str_repeat('0', 30) && str_starts_with((string) ($d['sid'] ?? ''), 'qc'));

    // 정리: 이 테스트가 만든 상담 행(로컬 테스트 DB)
    $pdo->exec("DELETE FROM chat_logs WHERE session_key LIKE 'qc%'");
    $pdo->exec("DELETE FROM ai_sessions WHERE session_key LIKE 'qc%'");
}
array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);
echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
ob_end_flush();
exit($fail > 0 ? 1 : 0);
