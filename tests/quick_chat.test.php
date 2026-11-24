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
 *  - 케이토목처럼 바로 담기(A11): 찾고도 '골라 주세요'로 끝나면 담기 1회 강제, 확인 질문('?')이면 강제 안 함, 견적 표에 사진·상품목록·도면자료 연결
 *  - 견적서 사진 첨부(A10): 사진 확인(jpg·png·10MB)·개인정보 모양 지우기·품목 글 만들기, 동의 없으면 거부(OpenAI 호출 없음),
 *    사진 → 목업 비전 → 품목 글로 검색·담기 → 견적 표, 사진·개인정보는 대화·상담 로그에 남지 않음
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
require_once __DIR__ . '/../app/quick_photo.php';

$pure = ['pass' => 0, 'fail' => 0];
$pok = static function (string $name, bool $cond) use (&$pure): void {
    $cond ? $pure['pass']++ : $pure['fail']++;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $name . "\n";
};
echo "[견적서 사진 — 순수 함수]\n";
$png1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$dec = quick_photo_decode('data:image/png;base64,' . $png1);
$pok('data URL png → 확인됨', $dec['ok'] && $dec['mime'] === 'image/png' && strlen($dec['bytes']) > 0);
$pok('base64 만 와도 됨', quick_photo_decode($png1)['ok']);
$pok('이미지가 아니면 거부', !quick_photo_decode(base64_encode('<?php echo 1; ?>'))['ok']);
$pok('gif 는 거부(jpg·png 만)', !quick_photo_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')['ok']);
$pok('빈 값·깨진 base64 거부', !quick_photo_decode('')['ok'] && !quick_photo_decode('@@@@')['ok']);
$pok('10MB 넘으면 거부', !quick_photo_decode(str_repeat('A', (int) ceil(QUICK_PHOTO_MAX_BYTES * 4 / 3) + 100))['ok']);
$pok('전화·이메일·사업자번호 지움', quick_photo_scrub('경계석 010-1234-5678 a@b.co 123-45-67890 031)123-4567') === '경계석');
$pok('규격 숫자는 그대로', quick_photo_scrub('300×1000×100 KS') === '300×1000×100 KS');
$it = quick_photo_items_text(json_encode(['items' => [
    ['name' => '도로경계석', 'spec' => '150×170×1000', 'qty' => 30, 'unit' => '개'],
    ['name' => 'U형 측구 문의 02-123-4567', 'spec' => '300', 'qty' => null, 'unit' => '', 'unclear' => true],
    ['name' => '', 'spec' => 'x'],
    ['name' => '그레이팅', 'qty' => 2.5, 'unit' => 'm'],
]], JSON_UNESCAPED_UNICODE));
$pok('품목 글: 머리말 + 번호 줄 3개', $it['ok'] && $it['count'] === 3 && str_starts_with($it['text'], QUICK_PHOTO_PREFIX . "\n1) 도로경계석 150×170×1000 · 수량 30개\n"));
$pok('수량 모름·읽기 애매함·전화번호 지움', str_contains($it['text'], '2) U형 측구 문의 300 · 수량 모름 (읽기 애매함)') && !str_contains($it['text'], '4567'));
$pok('소수 수량', str_contains($it['text'], '3) 그레이팅 · 수량 2.5m'));
$pok('품목 없음·JSON 아님 → 안내', !quick_photo_items_text('{"items":[],"note":"자재 목록이 아닙니다"}')['ok'] && !quick_photo_items_text('nope')['ok']);
$many = quick_photo_items_text(json_encode(['items' => array_fill(0, 15, ['name' => '블록', 'qty' => 1])]));
$pok('최대 ' . QUICK_PHOTO_MAX_ITEMS . '개', $many['count'] === QUICK_PHOTO_MAX_ITEMS);
$pok('프롬프트: 개인정보·가격 읽지 않게', str_contains(QUICK_PHOTO_PROMPT, '전화번호') && str_contains(QUICK_PHOTO_PROMPT, '단가'));
$pok('운영 기본값: 사진 첨부 켬', FEATURE_AI_QUICK_PHOTO === true && ai_quick_photo_enabled());

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true) || !db() || !function_exists('curl_init')) {
    echo "SKIP: 로컬 테스트 DB(*_test)·curl 에서만 실행합니다.\n";
    ob_end_flush();
    exit(0);
}
$pass = $pure['pass']; $fail = $pure['fail'];
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

    echo "[API — 케이토목처럼 항상 바로 담기(A11, 운영 상담 #260)]\n";
    $mockSeq = static function (array $responses) use ($tmp): void {
        file_put_contents("{$tmp}/responses.json", json_encode($responses, JSON_UNESCAPED_UNICODE));
        @unlink("{$tmp}/n");
        array_map('unlink', glob("{$tmp}/req-*.json") ?: []);
    };
    $mockSeq([
        $call('search_products', ['query' => '경계석'], 21),
        $say('[테스트] 조건에 맞는 경계석입니다. 원하시는 제품을 선택해 주세요.'),
        $call('add_to_cart', ['product_id' => $pid, 'quantity' => 10], 22),
        $say('[테스트] 일반 경계석을 담았습니다.'),
    ]);
    [$st, $d] = $post(['action' => 'new'], $cookie);
    $fsid = (string) ($d['sid'] ?? '');
    [$st, $d] = $post(['action' => 'ask', 'sid' => $fsid, 'msg' => '경계석 10개'], $cookie);
    $r3 = json_decode((string) @file_get_contents("{$tmp}/req-3.json"), true);
    $q = null;
    foreach ($d['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'quote_draft') { $q = $e; } }
    ok("'골라 주세요'로 끝나면 담기 1회 강제(tool_choice add_to_cart)", ($r3['tool_choice']['function']['name'] ?? '') === 'add_to_cart', $r3['tool_choice'] ?? null);
    ok('강제 후 답 = 담은 뒤 답(골라 달라는 글은 버림)', ($d['ok'] ?? false) && str_contains((string) $d['text'], '담았습니다') && !str_contains((string) $d['text'], '선택해 주세요'), $d['text'] ?? null);
    $qtyBefore = (int) ($q['items'][0]['quantity'] ?? 0);
    ok('견적 표 + 상품 사진·상품목록 연결(화면 표시용)', $q !== null && array_key_exists('image', $q['items'][0] ?? []) && ($q['items'][0]['category_id'] ?? 0) > 0
        && array_key_exists('spec_image', $q['items'][0]), $q['items'][0] ?? null);
    $mockSeq([
        $call('search_products', ['query' => '그레이팅'], 31),
        $say('[테스트] 차량이 다니는 곳인가요?'),
    ]);
    [$st, $d] = $post(['action' => 'ask', 'sid' => $fsid, 'msg' => '그레이팅 폭 300 20m'], $cookie);
    ok("확인 질문('?')으로 끝나면 강제하지 않음", ($d['ok'] ?? false) && str_contains((string) $d['text'], '차량이 다니는') && !is_file("{$tmp}/req-3.json"), $d);
    ok('기본 AI 상담(consult)은 강제 담기 없음 — 코드상 style 조건', str_contains((string) file_get_contents(__DIR__ . '/../app/rag.php'), "\$style === 'quick' && !\$forcedAdd"));

    echo "[API — 견적서 사진 첨부]\n";
    file_put_contents("{$tmp}/responses.json", json_encode([
        $say(json_encode(['items' => [
            ['name' => '경계석', 'spec' => '150×170×1000', 'qty' => 30, 'unit' => '개'],
            ['name' => '홍길동 010-9876-5432 맨홀뚜껑', 'spec' => '', 'qty' => null, 'unit' => '', 'unclear' => true],
        ]], JSON_UNESCAPED_UNICODE)),
        $call('search_products', ['query' => '경계석 150×170×1000'], 11),
        $call('add_to_cart', ['product_id' => $pid, 'quantity' => 30], 12),
        $say("[테스트] 사진의 경계석을 담았습니다.\n확인 필요:\n맨홀뚜껑"),
    ], JSON_UNESCAPED_UNICODE));
    @unlink("{$tmp}/n");
    array_map('unlink', glob("{$tmp}/req-*.json") ?: []);
    [$st, $d] = $post(['action' => 'new'], $cookie);
    $psid = (string) ($d['sid'] ?? '');
    $img  = 'data:image/png;base64,' . $png1;
    [$st, $d] = $post(['action' => 'photo', 'sid' => $psid, 'image' => $img], $cookie);
    ok('동의 없으면 거부 · OpenAI 호출 없음', ($d['ok'] ?? null) === false && str_contains((string) ($d['error'] ?? ''), '동의') && !is_file("{$tmp}/n"), $d);
    [$st, $d] = $post(['action' => 'photo', 'sid' => $psid, 'image' => base64_encode('not an image'), 'consent' => true], $cookie);
    ok('이미지가 아니면 거부 · OpenAI 호출 없음', ($d['ok'] ?? null) === false && !is_file("{$tmp}/n"), $d);
    [$st, $d] = $post(['action' => 'photo', 'sid' => $psid, 'image' => $img, 'consent' => true], $cookie);
    $q = null;
    foreach ($d['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'quote_draft') { $q = $e; } }
    ok('사진 → 읽은 품목 글(asked) + 답변 + 같은 상담 키', ($d['ok'] ?? false) && ($d['sid'] ?? '') === $psid
        && str_starts_with((string) ($d['asked'] ?? ''), QUICK_PHOTO_PREFIX) && str_contains((string) $d['text'], '확인 필요'), $d);
    ok('견적 표: 사진 품목 30개 더 담김(같은 장바구니)', $q !== null && ($q['items'][0]['product_id'] ?? 0) === $pid && ($q['items'][0]['quantity'] ?? 0) === $qtyBefore + 30, $q);
    $v = json_decode((string) @file_get_contents("{$tmp}/req-1.json"), true);
    $vc = $v['messages'][0]['content'] ?? [];
    ok('비전 요청: gpt-4o · 사진 data URL · 품목만 읽는 지시', ($v['model'] ?? '') === 'gpt-4o' && str_starts_with((string) ($vc[1]['image_url']['url'] ?? ''), 'data:image/png;base64,')
        && ($vc[0]['text'] ?? '') === QUICK_PHOTO_PROMPT);
    $r2 = json_decode((string) @file_get_contents("{$tmp}/req-2.json"), true);
    $lastUserMsg = (string) (end($r2['messages'])['content'] ?? '');
    ok('상담 요청: 품목 글로 질문 · 전화번호 지움 · 사진 없음', str_starts_with($lastUserMsg, QUICK_PHOTO_PREFIX) && !str_contains($lastUserMsg, '9876')
        && !str_contains((string) json_encode($r2), 'base64'));
    $saved->execute([$psid]);
    $ph = (string) $saved->fetchColumn();
    $lg = $pdo->prepare('SELECT CONCAT(user_msg, assistant_txt, COALESCE(tools_json, "")) FROM chat_logs WHERE session_key = ?');
    $lg->execute([$psid]);
    $lgText = (string) $lg->fetchColumn();
    ok('대화·상담 로그에 사진·전화번호 없음(품목 글만)', str_contains($ph, '경계석') && !str_contains($ph, 'base64') && !str_contains($ph, '9876')
        && $lgText !== '' && !str_contains($lgText, 'base64') && !str_contains($lgText, '9876'));

    // 정리: 이 테스트가 만든 상담 행(로컬 테스트 DB)
    $pdo->exec("DELETE FROM chat_logs WHERE session_key LIKE 'qc%'");
    $pdo->exec("DELETE FROM ai_sessions WHERE session_key LIKE 'qc%'");
}
array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);
echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
ob_end_flush();
exit($fail > 0 ? 1 : 0);
