<?php
declare(strict_types=1);
/**
 * 실제 GPT 조건 추출 검증(OpenAI 호출) — 연속 상담에서 GPT 가 고객 말의 폭·제품 1개 길이·설치 총 길이·통행·용도(품목)를
 * search_products 인자로 스스로 바르게 넘기는지, 그리고 서버 보완(rag_prepare_search_input) 뒤 결과가 기준을 지키는지 본다(2026-11-11).
 *
 * 흐름 A: 사용자 요청 복합 문장 8개(폭 500·길이 1m 그레이팅 → 차량 → 폭만 400 → 설치 20m 몇 개 → 앞 제품 빼고 → 더 저렴한 → 폭 500·길이 2m 수로관 → 차량에 안 맞으면 제외)
 * 흐름 E: 10단계(조건 → 추천 → 치수 변경 → 제외 → 더 저렴한 → 설치 총 길이 → 수량 → 차량 추가 → 앞서 추천 재검토 → 최종 비교)
 * 턴마다 기록: GPT 가 실제로 넘긴 인자(도구 기록 raw_input) · 기대값과 비교(일치·다름·빠짐) · 서버가 바로잡은 내용(fills) · 결과 확인(표 치수·하중·수량).
 *   'GPT 자체 추출'은 정보(GPT 품질 지표), '결과 확인'이 실패하면 종료 코드 1.
 *
 * 금지: 운영 DB 사용(DB_NAME=*_test 만, 상담 기록은 끝나면 지움). 키는 환경변수 OPENAI_API_KEY 로만 — 코드·문서·Git·출력에 남기지 않는다.
 * 실행(실제 GPT): OPENAI_API_KEY=… DB_HOST=127.0.0.1 DB_NAME=tomokmall_test php scripts/testdb/consult-conditions-live.php [--md=결과.md] [--only=A|E]
 *   --mock-self-test : 실제 GPT 대신 기대 인자를 그대로 돌려주는 목업(127.0.0.1)으로 이 스크립트 자체를 점검 — 결과는 '목업'으로 표시(실제 GPT 검증 아님)
 * 키가 없으면 'SKIP — 실제 GPT 미검증'(종료 코드 3)으로 끝난다. 목업 테스트는 tests/consult_multiturn_conditions.test.php.
 * 비용: 턴 18개 × OpenAI 호출 2~3회(도구 호출 포함).
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
$opt  = getopt('', ['md:', 'only:', 'mock-self-test']);
$mock = isset($opt['mock-self-test']);
$tmp  = sys_get_temp_dir() . '/conditions_live_' . getmypid();
if ($mock) {
    @mkdir($tmp, 0777, true);
    $port = 19820 + getmypid() % 150;
    putenv("OPENAI_CHAT_URL=http://127.0.0.1:{$port}/v1/chat/completions");
    putenv('OPENAI_API_KEY=test-not-a-key');
}
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require_once dirname(__DIR__, 2) . '/app/rag.php';

$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array((string) ($config['db']['host'] ?? ''), ['127.0.0.1', 'localhost', 'mysql'], true) || !db()) {
    fwrite(STDERR, "로컬 테스트 DB(DB_NAME=*_test, 127.0.0.1)에서만 실행합니다.\n");
    exit(2);
}
$chatUrl = (string) ($config['openai']['chat_url'] ?? '');
$apiKey  = (string) ($config['openai']['api_key'] ?? '');
$isLive  = !$mock && $apiKey !== '' && $apiKey !== 'test-not-a-key' && str_starts_with($chatUrl, 'https://api.openai.com/');
if (!$mock && !$isLive) {
    echo "SKIP — 실제 GPT 키 없음(OPENAI_API_KEY 미설정 또는 목업 주소). 실제 GPT 미검증.\n"
        . "  실제 검증: OPENAI_API_KEY 를 환경변수로만 주고 다시 실행(키를 파일·Git 에 저장하지 않음). 스크립트 점검만: --mock-self-test\n";
    exit(3);
}
if ((int) db()->query("SELECT COUNT(*) FROM products WHERE is_active = 1 AND name = '중하중그레이팅수로용' AND dim_w = 400 AND dim_l = 1000")->fetchColumn() === 0) {
    fwrite(STDERR, "규격 치수 칸이 채워진 수집 상품이 없는 DB입니다(php scripts/testdb/prodlike-db.php).\n");
    exit(2);
}
$label = $mock ? '목업(스크립트 점검 — 실제 GPT 검증 아님)' : '실제 GPT(' . (string) ($config['openai']['model'] ?? '') . ')';
echo "조건 추출 검증 — {$label} · DB {$dbName}\n";

$srv = null;
if ($mock) {
    file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n);
$r = json_decode(file_get_contents("$d/responses.json"), true); header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
    usleep(400000);
}

// ── 시나리오: 고객 말 · GPT 가 넘겨야 할 인자(기대) · 결과 확인 ──────────────────────────────
// gpt: 기대 인자(null = 넘기지 않아야 함). 'query~' = 검색어에 들어 있어야 할 말. 'ids:키' = 그 칸에 id 가 있어야 함
$sz = static fn(string $prefix): Closure => static fn(array $cs): bool => $cs !== [] && !array_filter($cs, static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), $prefix));
$qty = static fn(int $n): Closure => static fn(array $cs): bool => $cs !== [] && !array_filter($cs, static fn($c) => ($c['suggested_qty'] ?? 0) !== $n || ($c['est_cost'] ?? 0) !== (int) $c['price_member'] * $n);
$noPed = static fn(array $cs): bool => !array_filter($cs, static fn($c) => ai_grating_load_class((string) $c['name']) === 'pedestrian');
$loadOk = static fn(array $cs): bool => !array_filter($cs, static fn($c) => ($c['load_check'] ?? '') === '' );
$noVerified = static fn(array $cs): bool => !array_filter($cs, static fn($c) => ($c['load_check'] ?? '') === 'verified');
$flows = [
    'A' => [
        ['say' => '폭 500mm, 길이 1m 그레이팅 찾아줘.', 'gpt' => ['query~' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000, 'length_m' => null],
         'check' => ['폭 W 500·길이 L 1000 만' => $sz('폭 W 500mm · 길이 L 1000mm')]],
        ['say' => '차량이 다니는 곳에 설치할 수 있는 제품으로 추천해줘.', 'gpt' => ['traffic' => 'car', 'width_mm' => 500, 'product_length_mm' => 1000],
         'check' => ['보행자용 없음' => $noPed, '하중 판정 표시' => $loadOk]],
        ['say' => '폭은 400으로 바꾸고 길이는 그대로 해줘.', 'gpt' => ['width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car'],
         'check' => ['폭 W 400·길이 L 1000 만' => $sz('폭 W 400mm · 길이 L 1000mm'), '보행자용 없음' => $noPed]],
        ['say' => '설치 구간이 총 20m야. 몇 개 필요해?', 'gpt' => ['length_m' => 20, 'product_length_mm' => 1000, 'ids:only_ids' => true],
         'check' => ['20개 · 단가×20' => $qty(20)]],
        ['say' => '앞에 보여준 제품은 빼고 다른 제품 추천해줘.', 'gpt' => ['ids:exclude_ids' => true, 'width_mm' => 400, 'traffic' => 'car'],
         'check' => ['보행자용 없음' => $noPed], 'shown_absent' => true],
        ['say' => '조건은 그대로 두고 좀 더 저렴한 제품으로 찾아줘.', 'gpt' => ['ids:exclude_ids' => true, 'max_est_cost>' => 0, 'width_mm' => 400, 'length_m' => 20],
         'check' => ['보행자용 없음' => $noPed], 'shown_absent' => true],
        ['say' => '폭 500인 수로관인데 길이는 2m인 제품 찾아줘.', 'gpt' => ['query~' => '수로관', 'width_mm' => 500, 'product_length_mm' => 2000],
         'check' => ['폭 W 500mm 만' => $sz('폭 W 500mm'), '하중 확인된 수로관처럼 보이지 않음' => $noVerified]],
        ['say' => '가격이 저렴해도 차량 통행에 맞지 않으면 제외해줘.', 'gpt' => ['traffic' => 'car'], 'mock_query' => '수로관',
         'check' => ['하중 확인된 제품처럼 보이지 않음' => $noVerified]],
    ],
    'E' => [
        ['say' => '마당 배수로에 놓을 그레이팅을 찾고 있어요. 폭 500mm, 길이 1m 제품이요.', 'gpt' => ['width_mm' => 500, 'product_length_mm' => 1000, 'traffic' => null], 'optional' => true,
         'check' => []],
        ['say' => '그 조건으로 추천해줘.', 'gpt' => ['width_mm' => 500, 'product_length_mm' => 1000, 'length_m' => null, 'traffic' => null],
         'check' => ['폭 W 500·길이 L 1000 만' => $sz('폭 W 500mm · 길이 L 1000mm')]],
        ['say' => '폭은 400으로 바꿔 줘.', 'gpt' => ['width_mm' => 400, 'product_length_mm' => 1000],
         'check' => ['폭 W 400·길이 L 1000 만' => $sz('폭 W 400mm · 길이 L 1000mm')]],
        ['say' => '앞에 보여준 제품은 빼고 다른 제품 추천해줘.', 'gpt' => ['ids:exclude_ids' => true, 'width_mm' => 400], 'check' => [], 'shown_absent' => true],
        ['say' => '조건은 그대로 두고 좀 더 저렴한 제품으로 찾아줘.', 'gpt' => ['ids:exclude_ids' => true, 'width_mm' => 400], 'check' => [], 'shown_absent' => true],
        ['say' => '설치 구간은 총 20m야.', 'gpt' => ['length_m' => 20, 'product_length_mm' => 1000], 'check' => ['20개 · 단가×20' => $qty(20)]],
        ['say' => '그럼 몇 개 필요해?', 'gpt' => ['length_m' => 20, 'ids:only_ids' => true], 'check' => ['20개 · 단가×20' => $qty(20)]],
        ['say' => '아 그리고 차량이 다니는 곳이야.', 'gpt' => ['traffic' => 'car', 'width_mm' => 400, 'length_m' => 20],
         'check' => ['보행자용 없음' => $noPed, '20개' => $qty(20)]],
        ['say' => '앞에 추천했던 제품들은 차량 통행에 맞아?', 'gpt' => ['traffic' => 'car'], 'check' => ['보행자용 없음' => $noPed]],
        ['say' => '최종적으로 비교해줘.', 'gpt' => ['ids:only_ids' => true, 'traffic' => 'car', 'length_m' => 20], 'check' => ['보행자용 없음' => $noPed]],
    ],
];
if (isset($opt['only'])) { $flows = array_intersect_key($flows, array_flip(explode(',', (string) $opt['only']))); }

/** 기대 인자와 비교 (순수) — [키 => 'match'·'diff(값)'·'missing'·'extra(값)'] */
$compare = static function (array $raw, array $gpt): array {
    $out = [];
    foreach ($gpt as $k => $want) {
        if ($k === 'query~') { $out['query'] = str_contains((string) ($raw['query'] ?? ''), $want) ? 'match' : 'diff(' . (string) ($raw['query'] ?? '') . ')'; continue; }
        if (str_starts_with($k, 'ids:')) { $f = substr($k, 4); $out[$f] = ai_int_list($raw[$f] ?? null) ? 'match' : 'missing'; continue; }
        if (str_ends_with($k, '>')) { $f = substr($k, 0, -1); $out[$f] = (float) ($raw[$f] ?? 0) > $want ? 'match' : 'missing'; continue; }
        $has = array_key_exists($k, $raw) && $raw[$k] !== null && $raw[$k] !== '' && $raw[$k] !== 0;
        if ($want === null) { $out[$k] = $has ? 'extra(' . json_encode($raw[$k], JSON_UNESCAPED_UNICODE) . ')' : 'match'; continue; }
        if (!$has) { $out[$k] = 'missing'; continue; }
        $got = $raw[$k];
        $same = is_string($want) ? (string) $got === $want : (in_array($k, ['width_mm', 'product_length_mm'], true) ? abs((float) ai_parse_mm($got) - $want) < 0.5 : abs((float) $got - $want) < 0.001);
        $out[$k] = $same ? 'match' : 'diff(' . json_encode($got, JSON_UNESCAPED_UNICODE) . ')';
    }
    return $out;
};

$md = ["# 조건 추출 검증 — {$label}", '', '- 실행: ' . date('Y-m-d H:i') . " · DB `{$dbName}`", ''];
$gptMatch = $gptTotal = $okN = $failN = 0;
$sids = [];
try {
    foreach ($flows as $flowName => $steps) {
        $sid = 'conditions-live-' . strtolower($flowName) . '-' . getmypid();
        $sids[] = $sid;
        db()->prepare('DELETE FROM chat_logs WHERE session_key = ?')->execute([$sid]);
        $messages = [];
        $shown = [];
        echo "\n[흐름 {$flowName}]\n";
        $md[] = "## 흐름 {$flowName}";
        foreach ($steps as $i => $st) {
            if ($mock) {   // 목업: 기대 인자를 그대로 넘기는 GPT(스크립트 점검용)
                $args = ['query' => $st['gpt']['query~'] ?? ($st['mock_query'] ?? '그레이팅')];
                foreach ($st['gpt'] as $k => $v) { if ($v !== null && !str_contains($k, ':') && !str_ends_with($k, '~') && !str_ends_with($k, '>')) { $args[$k] = $v; } }
                $resp = !empty($st['optional']) ? [['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '조건을 확인했습니다.']]]]]
                    : [['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => "s{$i}", 'type' => 'function', 'function' => ['name' => 'search_products', 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]]]]]]],
                       ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '결과입니다.']]]]];
                @unlink("{$tmp}/n");
                file_put_contents("{$tmp}/responses.json", json_encode($resp, JSON_UNESCAPED_UNICODE));
            }
            $send = array_merge($messages, [['role' => 'user', 'content' => $st['say']]]);
            $res = rag_process($send, $sid);
            rag_log_chat($sid, $st['say'], (string) ($res['text'] ?? ''), $res['events'] ?? [], (string) ($res['category'] ?? ''), null);
            $messages = array_merge($send, [['role' => 'assistant', 'content' => (string) ($res['text'] ?? '')]]);
            $search = array_values(array_filter((array) ($res['tools'] ?? []), static fn($t) => $t['name'] === 'search_products'));
            $cards = [];
            foreach ($res['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'product_cards') { $cards = array_merge($cards, $e['products'] ?? []); } }
            $n = $i + 1;
            echo " {$n}) {$st['say']}\n";
            $md[] = "### {$n}) {$st['say']}";
            if (!$res['ok']) {
                $failN++;
                echo "  ✗ 응답 실패: " . ($res['error'] ?? '') . "\n";
                $md[] = '- ✗ 응답 실패: ' . ($res['error'] ?? '');
                continue;
            }
            if (!$search) {
                $line = !empty($st['optional']) ? '검색 안 함(이 턴은 조건만 말함 — 검색 여부는 GPT 판단)' : '검색 안 함 — 기대: search_products 호출';
                echo "  · {$line}\n";
                $md[] = "- {$line}";
                if (empty($st['optional'])) { $gptTotal++; }
            } else {
                $raw = (array) ($search[0]['raw_input'] ?? []);
                $cmp = $compare($raw, $st['gpt']);
                $m = count(array_filter($cmp, static fn($v) => $v === 'match'));
                $gptMatch += $m;
                $gptTotal += count($cmp);
                echo '  GPT 인자: ' . json_encode($raw, JSON_UNESCAPED_UNICODE) . "\n  비교: " . json_encode($cmp, JSON_UNESCAPED_UNICODE) . "\n";
                echo '  서버 보완: ' . (implode(' / ', (array) ($search[0]['fills'] ?? [])) ?: '없음') . "\n";
                $md[] = '- GPT 인자: `' . json_encode($raw, JSON_UNESCAPED_UNICODE) . '`';
                $md[] = '- 기대값 비교: ' . implode(', ', array_map(static fn($k, $v) => "{$k} {$v}", array_keys($cmp), $cmp));
                $md[] = '- 서버 보완: ' . (implode(' / ', (array) ($search[0]['fills'] ?? [])) ?: '없음');
            }
            $checks = $st['check'];
            if (!empty($st['shown_absent'])) {
                $checks['앞서 보인 제품이 새 추천으로 다시 나오지 않음'] = static fn(array $cs): bool => !array_intersect(array_map(static fn($c) => (int) $c['id'], $cs), $shown);
            }
            foreach ($checks as $name => $fn) {
                $pass = $fn($cards);   // 표가 비어도 되는 확인(보행자용 없음 등)은 빈 표에서 통과, 치수·수량 확인은 표가 있어야 통과
                $pass ? $okN++ : $failN++;
                echo '  ' . ($pass ? '✓' : '✗') . " 결과: {$name}" . ($cards === [] ? ' (표 없음)' : '') . "\n";
                $md[] = '- ' . ($pass ? '✓' : '✗') . " 결과: {$name}" . ($cards === [] ? ' (표 없음)' : '');
            }
            $md[] = '- 추천표: ' . ($cards ? implode(' · ', array_map(static fn($c) => $c['name'] . ' ' . ($c['size'] ?? '') . (isset($c['suggested_qty']) ? " {$c['suggested_qty']}개" : '') . (isset($c['load_check']) ? " (하중 {$c['load_check']})" : ''), $cards)) : '없음');
            $md[] = '- 답변: ' . str_replace("\n", ' ', mb_substr((string) $res['text'], 0, 400));
            $md[] = '';
            $shown = array_values(array_unique(array_merge($shown, array_map(static fn($c) => (int) $c['id'], $cards))));
        }
    }
} finally {
    foreach ($sids as $s) { db()->prepare('DELETE FROM chat_logs WHERE session_key = ?')->execute([$s]); }
    if ($srv) { proc_terminate($srv); array_map('unlink', glob("{$tmp}/*") ?: []); @rmdir($tmp); }
}
$sum = "GPT 자체 추출 일치 {$gptMatch}/{$gptTotal} · 결과 확인 통과 {$okN} / 실패 {$failN} — {$label}";
echo "\n{$sum}\n";
$md[] = "## 요약\n\n- {$sum}";
if (!empty($opt['md'])) { file_put_contents((string) $opt['md'], implode("\n", $md) . "\n"); echo "결과 문서: {$opt['md']}\n"; }
exit($failN > 0 ? 1 : 0);
