<?php
declare(strict_types=1);
/**
 * 실제 GPT 조건 추출 검증(OpenAI 호출) — 연속 상담에서 GPT 가 고객 말의 폭·제품 1개 길이·설치 총 길이·통행·용도(품목)를
 * search_products 인자로 스스로 바르게 넘기는지, 모호한 말에 확인 질문을 하는지, 서버 보완(rag_prepare_search_input) 뒤 결과가 기준을 지키는지 본다.
 * (2026-11-11 작성, 2026-11-12 4차: 모호한 질문 8개 흐름·턴별 기록 항목·유료 호출 확인 추가)
 *
 * 흐름 A: 복합 문장 8개(폭 500·길이 1m 그레이팅 → 차량 → 폭만 400 → 설치 20m 몇 개 → 앞 제품 빼고 → 더 저렴한 → 폭 500·길이 2m 수로관 → 차량에 안 맞으면 제외)
 * 흐름 E: 10단계(조건 → 추천 → 치수 변경 → 제외 → 더 저렴한 → 설치 총 길이 → 수량 → 차량 추가 → 앞서 추천 재검토 → 최종 비교)
 * 흐름 Q1~Q8: 모호한 질문 8개(길이 10m짜리 · 길이만 두 배 · 더 튼튼한 거 · 이 제품 써도 돼 · 500짜리 수로관 · 앞 추천 중 가장 저렴 · 나머지는 그대로 · 비슷한 규격)
 * 흐름 Q9~Q11(5차): 50센치짜리(치수 이름 없음) · 좀 더 넓은 거(얼마나 없음) · 암거(상품명이 규격 코드뿐인 1련암거, 차량 → 하중 확인 필요)
 * 턴마다 기록: 고객 원문 · GPT 가 추출한 조건(raw_input) · 서버가 보완한 조건(fills) · 최종 검색 조건(input) · 추천 상품 ID ·
 *   조건 누락·잘못된 해석(기대값과 다른 칸) · 확인 질문 근거(clarify)와 GPT 가 실제로 물었는지 · 예상 결과와 실제 결과의 차이(실패한 결과 확인).
 *   'GPT 자체 추출'·'GPT 확인 질문'은 정보(GPT 품질 지표), '결과 확인'(서버 보완 뒤 표·확인 근거)이 실패하면 종료 코드 1.
 *
 * 금지: 운영 DB 사용(DB_NAME=*_test 만, 상담 기록은 끝나면 지움). 키는 환경변수 OPENAI_API_KEY 로만 — 코드·문서·Git·출력에 남기지 않는다.
 * 유료 호출: 실제 GPT 는 --confirm-paid 를 줄 때만 호출한다(없으면 예정 호출 수만 알리고 종료 코드 4). 범위를 줄이려면 --only=Q1,Q5
 * 실행(실제 GPT): OPENAI_API_KEY 는 환경변수로만 · DB_HOST=127.0.0.1 DB_NAME=tomokmall_test php scripts/testdb/consult-conditions-live.php --confirm-paid [--md=결과.md] [--only=A,E,Q1…Q11]
 *   --mock-self-test : 실제 GPT 대신 기대 인자를 그대로 돌려주는 목업(127.0.0.1)으로 이 스크립트 자체를 점검 — 결과는 '목업'으로 표시(실제 GPT 검증 아님)
 * 키가 없으면 'SKIP — 실제 GPT 미검증'(종료 코드 3). 목업 테스트: tests/consult_multiturn_conditions.test.php · tests/consult_ambiguous_questions.test.php.
 * 비용: 전체 고객 턴 약 39개 × OpenAI 호출 2~3회(도구 호출 포함).
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
$opt  = getopt('', ['md:', 'only:', 'mock-self-test', 'confirm-paid']);
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
        . "  실제 검증: OPENAI_API_KEY 를 환경변수로만 주고 --confirm-paid 와 함께 다시 실행(키를 파일·Git 에 저장하지 않음). 스크립트 점검만: --mock-self-test\n";
    exit(3);
}
if ((int) db()->query("SELECT COUNT(*) FROM products WHERE is_active = 1 AND name = '중하중그레이팅수로용' AND dim_w = 400 AND dim_l = 1000")->fetchColumn() === 0) {
    fwrite(STDERR, "규격 치수 칸이 채워진 수집 상품이 없는 DB입니다(php scripts/testdb/prodlike-db.php).\n");
    exit(2);
}
$label = $mock ? '목업(스크립트 점검 — 실제 GPT 검증 아님)' : '실제 GPT(' . (string) ($config['openai']['model'] ?? '') . ')';

// ── 시나리오: 고객 말 · GPT 가 넘겨야 할 인자(기대) · 확인 질문 기대 · 결과 확인 ─────────────────────
// gpt: 기대 인자(null = 넘기지 않아야 함). 'query~' = 검색어에 들어 있어야 할 말. 'ids:키' = 그 칸에 id 가 있어야 함. '키>' = 0보다 큼
// clarify: true = 서버가 모호하다고 보고 확인 질문 근거를 줘야 함(검색 대신 GPT 가 먼저 물어도 됨), false = 주지 않아야 함
// ctx: 맥락을 만드는 턴(결과 확인은 하되 모호 질문 지표에는 넣지 않음). mock_say: 목업에서 도구 없이 답만
$sz = static fn(string $prefix): Closure => static fn(array $cs): bool => $cs !== [] && !array_filter($cs, static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), $prefix));
$qty = static fn(int $n): Closure => static fn(array $cs): bool => $cs !== [] && !array_filter($cs, static fn($c) => ($c['suggested_qty'] ?? 0) !== $n || ($c['est_cost'] ?? 0) !== (int) $c['price_member'] * $n);
$noPed = static fn(array $cs): bool => !array_filter($cs, static fn($c) => ai_grating_load_class((string) $c['name']) === 'pedestrian');
$loadOk = static fn(array $cs): bool => !array_filter($cs, static fn($c) => ($c['load_check'] ?? '') === '');
$noVerified = static fn(array $cs): bool => !array_filter($cs, static fn($c) => ($c['load_check'] ?? '') === 'verified');
$noQty = static fn(array $cs): bool => !array_filter($cs, static fn($c) => isset($c['suggested_qty']));
$stronger = static fn(array $cs): bool => !array_filter($cs, static fn($c) => !in_array($c['load_grade'] ?? '', ['승용차용', '대형차용'], true));
$has500 = static fn(array $cs): bool => $cs !== [] && !array_filter($cs, static fn($c) => !str_contains((string) ($c['size'] ?? ''), '500mm'));
$ped300 = ['say' => '그레이팅 수로용 폭 300 찾아줘. 사람만 다녀요.', 'gpt' => ['width_mm' => 300, 'traffic' => 'pedestrian'], 'query' => '그레이팅 수로용', 'ctx' => true, 'check' => []];
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
        ['say' => '가격이 저렴해도 차량 통행에 맞지 않으면 제외해줘.', 'gpt' => ['traffic' => 'car'], 'query' => '수로관',
         'check' => ['하중 확인된 제품처럼 보이지 않음' => $noVerified]],
    ],
    'E' => [
        ['say' => '마당 배수로에 놓을 그레이팅을 찾고 있어요. 폭 500mm, 길이 1m 제품이요.', 'gpt' => ['width_mm' => 500, 'product_length_mm' => 1000, 'traffic' => null], 'optional' => true, 'mock_say' => true, 'check' => []],
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
    'Q1' => [
        ['say' => '길이 10m짜리 그레이팅 찾아줘.', 'gpt' => ['query~' => '그레이팅', 'product_length_mm' => null, 'length_m' => null], 'clarify' => true, 'check' => ['길이 확인 전 수량 없음' => $noQty]],
    ],
    'Q2' => [
        ['say' => '폭 500mm, 길이 1m 그레이팅 찾아줘.', 'gpt' => ['width_mm' => 500, 'product_length_mm' => 1000], 'ctx' => true, 'check' => []],
        ['say' => '설치 구간은 총 20m야.', 'gpt' => ['length_m' => 20], 'ctx' => true, 'check' => []],
        ['say' => '폭은 그대로 하고 길이만 두 배로 해줘.', 'gpt' => ['width_mm' => 500, 'product_length_mm' => 1000, 'length_m' => 20], 'clarify' => true, 'check' => []],
    ],
    'Q3' => [$ped300,
        ['say' => '아까 그 제품 말고 더 튼튼한 거.', 'gpt' => ['ids:exclude_ids' => true, 'width_mm' => 300], 'query' => '그레이팅 수로용', 'check' => ['확인된 더 높은 하중 등급만' => $stronger], 'shown_absent' => true],
    ],
    'Q4' => [$ped300,
        ['say' => '차가 다니는데 이 제품 써도 돼?', 'gpt' => ['traffic' => 'car', 'ids:only_ids' => true], 'query' => '그레이팅 수로용', 'check' => ['보행자용을 맞다고 표에 남기지 않음' => $noPed]],
    ],
    'Q5' => [
        ['say' => '500짜리 수로관 20m 필요해.', 'gpt' => ['query~' => '수로관', 'width_mm' => null, 'spec_mm' => 500, 'length_m' => 20], 'clarify' => true, 'check' => ['규격에 500mm 있는 제품만' => $has500]],
    ],
    'Q6' => [
        ['say' => '폭 400mm, 길이 1m 그레이팅 찾아줘.', 'gpt' => ['width_mm' => 400, 'product_length_mm' => 1000], 'ctx' => true, 'check' => []],
        ['say' => '설치 구간은 총 20m야.', 'gpt' => ['length_m' => 20], 'ctx' => true, 'check' => ['20개 · 단가×20' => $qty(20)]],
        ['say' => '앞에서 추천한 것 중 가장 저렴한 제품으로 해줘.', 'gpt' => [], 'mock_say' => true, 'expect_cheapest' => true, 'check' => []],
    ],
    'Q7' => [
        ['say' => '폭 500mm, 길이 1m 그레이팅 찾아줘. 차량이 다녀요.', 'gpt' => ['width_mm' => 500, 'product_length_mm' => 1000, 'traffic' => 'car'], 'ctx' => true, 'check' => []],
        ['say' => '설치 구간은 총 20m야.', 'gpt' => ['length_m' => 20], 'ctx' => true, 'check' => []],
        ['say' => '폭 400으로 바꾸고 나머지는 그대로.', 'gpt' => ['width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'traffic' => 'car'], 'clarify' => false,
         'check' => ['폭 W 400·길이 L 1000 만' => $sz('폭 W 400mm · 길이 L 1000mm'), '20개' => $qty(20), '보행자용 없음' => $noPed]],
    ],
    'Q8' => [
        ['say' => '중하중 그레이팅 수로용 폭 400 길이 1m 찾아줘.', 'gpt' => ['width_mm' => 400, 'product_length_mm' => 1000], 'query' => '중하중 그레이팅 수로용', 'ctx' => true, 'check' => []],
        ['say' => '이 제품 대신 비슷한 규격의 다른 제품 보여줘.', 'gpt' => ['ids:exclude_ids' => true, 'width_mm' => 400, 'product_length_mm' => 1000],
         'check' => ['폭 W 400·길이 L 1000 만' => $sz('폭 W 400mm · 길이 L 1000mm')], 'shown_absent' => true],
    ],
    // 5차(2026-11-12) 추가 표현
    'Q9' => [
        ['say' => '50센치짜리 그레이팅 찾아줘.', 'gpt' => ['query~' => '그레이팅', 'width_mm' => null, 'spec_mm' => 500], 'clarify' => true, 'check' => []],
    ],
    'Q10' => [
        ['say' => '폭 400mm, 길이 1m 그레이팅 찾아줘.', 'gpt' => ['width_mm' => 400, 'product_length_mm' => 1000], 'ctx' => true, 'check' => []],
        ['say' => '아까보다 좀 더 넓은 거.', 'gpt' => ['width_mm' => 400, 'product_length_mm' => 1000], 'clarify' => true, 'check' => []],
    ],
    'Q11' => [
        ['say' => '암거 찾아줘. 차가 다니는 도로 밑이야.', 'gpt' => ['query~' => '암거', 'traffic' => 'car'], 'query' => '암거',
         'check' => ['1련암거·하중 확인 필요' => static fn(array $cs): bool => $cs !== [] && !array_filter($cs, static fn($c) => ($c['category_name'] ?? '') !== '1련암거' || ($c['load_check'] ?? '') !== 'unverified')]],
    ],
];
if (isset($opt['only'])) { $flows = array_intersect_key($flows, array_flip(explode(',', (string) $opt['only']))); }
$turns = array_sum(array_map('count', $flows));
if ($isLive && !isset($opt['confirm-paid'])) {
    echo "실제 GPT 키가 있지만 --confirm-paid 가 없어 호출하지 않았습니다(유료). 예정: 흐름 " . implode(',', array_keys($flows)) . " · 고객 턴 {$turns}개 × 호출 2~3회.\n";
    exit(4);
}
echo "조건 추출 검증 — {$label} · DB {$dbName} · 흐름 " . implode(',', array_keys($flows)) . " · 턴 {$turns}\n";

$srv = null;
if ($mock) {
    file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n);
$r = json_decode(file_get_contents("$d/responses.json"), true); header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
    $srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
    usleep(400000);
}

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
$asked = static fn(string $t): bool => preg_match('/\?|？|확인해\s*주|알려\s*주|말씀해\s*주|어느\s*(쪽|것|길이|치수)|맞나요|맞으신가요|인가요/u', $t) === 1;   // 답에 확인 질문이 있는지
$j = static fn($v): string => json_encode($v, JSON_UNESCAPED_UNICODE);

$md = ["# 조건 추출 검증 — {$label}", '', '- 실행: ' . date('Y-m-d H:i') . " · DB `{$dbName}` · 흐름 " . implode(',', array_keys($flows)), '',
       '| 흐름·턴 | 고객 원문 | GPT 추출 조건 | 서버 보완 | 최종 검색 조건 | 추천 상품 ID | 누락·잘못된 해석 | 확인 질문(근거/GPT) | 예상과 다른 결과 |', '|---|---|---|---|---|---|---|---|---|'];
$detail = [];
$gptMatch = $gptTotal = $okN = $failN = $askN = $askTotal = 0;
$sids = [];
$cell = static fn(string $s): string => str_replace(['|', "\n"], ['/', ' '], $s);
try {
    foreach ($flows as $flowName => $steps) {
        $sid = 'conditions-live-' . strtolower($flowName) . '-' . getmypid();
        $sids[] = $sid;
        db()->prepare('DELETE FROM chat_logs WHERE session_key = ?')->execute([$sid]);
        $messages = [];
        $shown = [];
        $prevCards = [];
        echo "\n[흐름 {$flowName}]\n";
        foreach ($steps as $i => $st) {
            if ($mock) {   // 목업: 기대 인자를 그대로 넘기는 GPT(스크립트 점검용)
                $args = ['query' => $st['gpt']['query~'] ?? ($st['query'] ?? '그레이팅')];
                foreach ($st['gpt'] as $k => $v) { if ($v !== null && !str_contains($k, ':') && !str_ends_with($k, '~') && !str_ends_with($k, '>')) { $args[$k] = $v; } }
                $resp = !empty($st['mock_say']) ? [['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '조건을 확인했습니다.']]]]]
                    : [['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => [['id' => "s{$i}", 'type' => 'function', 'function' => ['name' => 'search_products', 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]]]]]]],
                       ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '결과입니다. 맞나요?']]]]];
                @unlink("{$tmp}/n");
                file_put_contents("{$tmp}/responses.json", json_encode($resp, JSON_UNESCAPED_UNICODE));
            }
            $send = array_merge($messages, [['role' => 'user', 'content' => $st['say']]]);
            $res = rag_process($send, $sid);
            rag_log_chat($sid, $st['say'], (string) ($res['text'] ?? ''), $res['events'] ?? [], (string) ($res['category'] ?? ''), null);
            $messages = array_merge($send, [['role' => 'assistant', 'content' => (string) ($res['text'] ?? '')]]);
            $search = array_values(array_filter((array) ($res['tools'] ?? []), static fn($t) => $t['name'] === 'search_products'));
            $added  = array_values(array_filter((array) ($res['tools'] ?? []), static fn($t) => $t['name'] === 'add_to_cart'));
            $cards = [];
            foreach ($res['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'product_cards') { $cards = array_merge($cards, $e['products'] ?? []); } }
            $n = $i + 1;
            $tag = "{$flowName}-{$n}" . (!empty($st['ctx']) ? '(맥락)' : '');
            echo " {$tag}) {$st['say']}\n";
            $wrong = [];   // 조건 누락·잘못된 해석
            $diffs = [];   // 예상과 다른 결과
            if (!$res['ok']) {
                $failN++;
                $diffs[] = '응답 실패: ' . ($res['error'] ?? '');
                echo "  ✗ 응답 실패: " . ($res['error'] ?? '') . "\n";
                $md[] = "| {$tag} | {$cell($st['say'])} | — | — | — | — | — | — | {$cell(implode('; ', $diffs))} |";
                continue;
            }
            $raw = $search ? (array) ($search[0]['raw_input'] ?? []) : [];
            $final = $search ? (array) ($search[0]['input'] ?? []) : [];
            $fills = $search ? implode(' / ', (array) ($search[0]['fills'] ?? [])) : '';
            $clar = $search ? (string) ($search[0]['clarify'] ?? '') : '';
            $text = (string) ($res['text'] ?? '');
            if (!$search) {
                if (!empty($st['optional']) || !empty($st['expect_cheapest']) || (!empty($st['clarify']) && $asked($text))) {
                    // 조건만 말한 턴·선택 질문·확인 질문으로 대신한 턴은 검색하지 않아도 된다
                } else {
                    $gptTotal++;
                    $wrong[] = '검색 안 함(기대: search_products)';
                }
            } elseif ($st['gpt']) {
                $cmp = $compare($raw, $st['gpt']);
                $gptMatch += count(array_filter($cmp, static fn($v) => $v === 'match'));
                $gptTotal += count($cmp);
                foreach ($cmp as $k => $v) { if ($v !== 'match') { $wrong[] = "{$k} {$v}"; } }
            }
            // 확인 질문: 서버 근거(clarify)는 결과 확인, GPT 가 실제로 물었는지는 지표
            if (array_key_exists('clarify', $st)) {
                if ($search) {
                    $okC = $st['clarify'] ? $clar !== '' : $clar === '';
                    $okC ? $okN++ : $failN++;
                    if (!$okC) { $diffs[] = $st['clarify'] ? '확인 질문 근거(clarify) 없음' : '모호하지 않은데 확인 질문 근거 붙음'; }
                }
                if ($st['clarify']) { $askTotal++; if ($asked($text)) { $askN++; } else { $wrong[] = '확인 질문 안 함'; } }
            }
            if (!empty($st['expect_cheapest'])) {   // 앞 표에서 같은 기준(예상 자재비)으로 가장 저렴한 제품을 골랐는지
                $c0 = $prevCards ? array_reduce($prevCards, static fn($a, $c) => $a === null || ($c['est_cost'] ?? PHP_INT_MAX) < ($a['est_cost'] ?? PHP_INT_MAX) ? $c : $a) : null;
                $pick = $added ? (int) ($added[0]['input']['product_id'] ?? 0) : 0;
                $okP = $c0 !== null && ($pick === (int) $c0['id'] || ($pick === 0 && str_contains($text, (string) $c0['name'])));
                if (!$mock) { $okP ? $okN++ : $failN++; if (!$okP) { $diffs[] = '가장 저렴한 제품(' . ($c0['name'] ?? '?') . ')을 고르지 않음'; } }
            }
            $checks = $st['check'];
            if (!empty($st['shown_absent'])) {
                $checks['앞서 보인 제품이 새 추천으로 다시 나오지 않음'] = static fn(array $cs): bool => !array_intersect(array_map(static fn($c) => (int) $c['id'], $cs), $shown);
            }
            foreach ($checks as $name => $fn) {
                $pass = $fn($cards);   // 표가 비어도 되는 확인(보행자용 없음 등)은 빈 표에서 통과, 치수·수량 확인은 표가 있어야 통과
                $pass ? $okN++ : $failN++;
                if (!$pass) { $diffs[] = $name . ($cards === [] ? '(표 없음)' : ''); }
                echo '  ' . ($pass ? '✓' : '✗') . " 결과: {$name}" . ($cards === [] ? ' (표 없음)' : '') . "\n";
            }
            $idsOut = array_map(static fn($c) => (int) $c['id'], $cards);
            echo '  GPT 조건: ' . $j($raw) . "\n  서버 보완: " . ($fills ?: '없음') . "\n  최종 조건: " . $j($final) . "\n  추천 ID: " . $j($idsOut)
                . ($wrong ? "\n  누락·잘못된 해석: " . implode('; ', $wrong) : '') . ($clar !== '' ? "\n  확인 근거: " . mb_substr($clar, 0, 80) . '…' : '') . "\n";
            $md[] = "| {$tag} | {$cell($st['say'])} | `{$cell($j($raw))}` | {$cell($fills ?: '없음')} | `{$cell($j($final))}` | " . ($idsOut ? implode(',', $idsOut) : '없음')
                . " | {$cell($wrong ? implode('; ', $wrong) : '없음')} | " . ($clar !== '' ? '근거 있음' : '—') . ' / ' . ($asked($text) ? '물음' : '안 물음') . " | {$cell($diffs ? implode('; ', $diffs) : '없음')} |";
            $detail[] = "- {$tag} 답변: " . $cell(mb_substr($text, 0, 300));
            $shown = array_values(array_unique(array_merge($shown, $idsOut)));
            if ($cards) { $prevCards = $cards; }
        }
    }
} finally {
    foreach ($sids as $s) { db()->prepare('DELETE FROM chat_logs WHERE session_key = ?')->execute([$s]); }
    if ($srv) { proc_terminate($srv); array_map('unlink', glob("{$tmp}/*") ?: []); @rmdir($tmp); }
}
$sum = "GPT 자체 추출 일치 {$gptMatch}/{$gptTotal} · GPT 확인 질문 {$askN}/{$askTotal} · 결과 확인 통과 {$okN} / 실패 {$failN} — {$label}";
echo "\n{$sum}\n";
$md[] = '';
$md[] = "## 답변(앞 300자)\n\n" . implode("\n", $detail);
$md[] = "\n## 요약\n\n- {$sum}";
if (!empty($opt['md'])) { file_put_contents((string) $opt['md'], implode("\n", $md) . "\n"); echo "결과 문서: {$opt['md']}\n"; }
exit($failN > 0 ? 1 : 0);
