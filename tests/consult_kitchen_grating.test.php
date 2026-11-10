<?php
declare(strict_types=1);
/**
 * 2026-11-09 운영 AI 상담 오류 재현·회귀 테스트 — 목업 OpenAI(127.0.0.1), 로컬 테스트 DB(*_test, 수집 상품 data/ktomok), 외부 접속 없음.
 *
 * 운영 상담(chat_logs 183~188, 비로그인) 흐름을 그대로 돌린다: '식당 주방에 배수관 뚜껑' → '10미터 50센테 폭' → '보행자 전용으로'
 *   → '과한 제품인거 같은데? 다시 추천' → '날개형은 안돼??'. 목업 GPT 는 수정 전 운영처럼 search_products 를 traffic·exclude_ids 없이 부른다.
 * 확인: ① '배수관 뚜껑' → 그레이팅 상담 ② 주방·보행자 전용 → 서버가 traffic=pedestrian 을 채워 대형차용(안전중하중) 없이 보행자용 표, 낮은 금액 순
 *       ③ '다시 추천' → 서버가 직전 추천표를 빼고 검색 → 같은 표를 다시 보여 주지 않고 '다른 제품 없음' 안내
 *       ④ '날개형' → 표에 날개형이 없다는 missing_terms·안내가 GPT 에 전달 ⑤ 'A 말고 다른 자재' → 직전 표와 다른 제품
 * 기록: 테스트 상담 키 'kitchen-test-*' 로 chat_logs 에 쓰고 끝나면 지운다(로컬 테스트 DB만).
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/consult_kitchen_grating.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$tmp = sys_get_temp_dir() . '/kitchen_grating_test_' . getmypid();
@mkdir($tmp, 0777, true);
$port = 19300 + getmypid() % 250;
putenv("OPENAI_CHAT_URL=http://127.0.0.1:{$port}/v1/chat/completions");
putenv('OPENAI_API_KEY=test-not-a-key');
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/rag.php';

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true) || !db()) {
    echo "SKIP: 로컬 테스트 DB(*_test)에서만 실행합니다.\n";
    exit(0);
}
$n = (int) db()->query("SELECT COUNT(*) FROM products WHERE is_active = 1 AND name = '안전중하중그레이팅수로용' AND spec LIKE '%500*1000%'")->fetchColumn();
if ($n === 0) {
    echo "SKIP: 수집 상품(폭 500mm 안전중하중 그레이팅)이 없는 DB입니다. bash scripts/testdb/load-ktomok-docker.sh 로 적재하세요.\n";
    exit(0);
}
$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}\n"; }
}
file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n);
file_put_contents("$d/req-$n.json", file_get_contents("php://input")); $r = json_decode(file_get_contents("$d/responses.json"), true);
header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
usleep(400000);

$say  = static fn(string $t) => ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $t]]]];
$call = static fn(array $args, int $i) => ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
    'tool_calls' => [['id' => "k{$i}", 'type' => 'function', 'function' => ['name' => 'search_products', 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]]]]]]];
$sid = 'kitchen-test-' . getmypid();
$messages = [];
/** 한 턴: 목업 응답을 정해 rag_process 실행 → chat.php 처럼 로그·대화 저장. [결과, 요청 본문들] */
$turn = static function (string $user, array $responses) use ($tmp, $sid, &$messages): array {
    array_map('unlink', glob("{$tmp}/req-*.json") ?: []);
    @unlink("{$tmp}/n");
    file_put_contents("{$tmp}/responses.json", json_encode($responses, JSON_UNESCAPED_UNICODE));
    $send = array_merge($messages, [['role' => 'user', 'content' => $user]]);
    $res = rag_process($send, $sid);
    rag_log_chat($sid, $user, (string) ($res['text'] ?? ''), $res['events'] ?? [], (string) ($res['category'] ?? ''), null);
    $messages = array_merge($send, [['role' => 'assistant', 'content' => (string) ($res['text'] ?? '')]]);
    $reqs = [];
    foreach (range(1, (int) @file_get_contents("{$tmp}/n")) as $i) { $reqs[] = json_decode((string) @file_get_contents("{$tmp}/req-{$i}.json"), true); }
    return [$res, $reqs];
};
$cards = static function (array $res): array {
    $out = [];
    foreach ($res['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'product_cards') { $out = array_merge($out, $e['products'] ?? []); } }
    return $out;
};
$toolMsg = static function (array $reqs): array {   // GPT 에 돌려준 search_products 결과(마지막 요청의 tool 메시지)
    $last = end($reqs) ?: [];
    foreach (array_reverse($last['messages'] ?? []) as $m) { if (($m['role'] ?? '') === 'tool') { return json_decode((string) $m['content'], true) ?: []; } }
    return [];
};
$clean = static fn() => db()->prepare('DELETE FROM chat_logs WHERE session_key = ?')->execute([$sid]);

try {
    $clean();
    echo "[1턴 '식당 주방에 배수관 뚜껑이 필요해요']\n";
    [$r1, $q1] = $turn('식당 주방에 배수관 뚜껑이 필요해요', [$say('배수로 폭과 길이를 알려 주세요.')]);
    ok("'배수관 뚜껑' → 그레이팅 상담(수정 전: PE배수관)", ($r1['category'] ?? '') === 'grating');
    $sys1 = (string) ($q1[0]['messages'][0]['content'] ?? '');
    ok('프롬프트: 장소로 통행을 알 수 있으면 되묻지 않기 + traffic 넘기기', str_contains($sys1, '식당 주방·화장실·실내·베란다·인도·공원 → 보행자 전용') && str_contains($sys1, 'search_products 에 traffic 을 함께 넘긴다'));
    ok('프롬프트: 조건 만족 제품 중 낮은 금액 먼저·과잉 등급을 적합하다고 말하지 않기', str_contains($sys1, '예상 자재비(없으면 단가)가 가장 낮은 제품을 먼저 권한다') && str_contains($sys1, "'적합하다'·'적절하다'고 말하지 않는다"));
    $props = $q1[0]['tools'][0]['function']['parameters']['properties'] ?? [];
    ok('도구 정의: traffic·exclude_ids·width_mm·product_length_mm·max_est_cost', isset($props['traffic'], $props['exclude_ids'], $props['width_mm'], $props['product_length_mm'], $props['max_est_cost']));
    ok('프롬프트: 폭은 width_mm·설치 길이는 length_m·치수는 size 로 말하기', str_contains($sys1, '폭(너비·가로 mm)은 width_mm') && str_contains($sys1, "'폭 500mm 길이 10m'처럼 m 단위 길이는 대개 설치 총 길이(length_m)다") && str_contains($sys1, 'dim_check 가 unverified 인 제품은'));

    echo "[2~3턴 '10미터길이에 50센테 폭이야' → '보행자 전용으로' (GPT 는 traffic 없이 검색)]\n";
    $turn('10미터길이에 50센테 폭이야', [$say('주방 내부라 보행자 전용으로 보면 될까요?')]);
    $search = ['query' => '그레이팅 수로용', 'spec_mm' => 500, 'length_m' => 10];
    [$r3, $q3] = $turn('보행자 전용으로', [$call($search, 1), $say('주방 보행자 전용 배수로 덮개 제품입니다. 원하시는 제품을 선택해 주세요.')]);
    $c3 = $cards($r3);
    $names3 = array_column($c3, 'name');
    ok('서버가 traffic=pedestrian 채움(도구 기록)', ($r3['tools'][0]['input']['traffic'] ?? '') === 'pedestrian');
    ok("서버가 '50센테 폭'을 GPT 의 spec_mm 500 대신 폭 조건 width_mm 500 으로(위치 무관 숫자 맞추기 방지)", (float) ($r3['tools'][0]['input']['width_mm'] ?? 0) === 500.0 && !isset($r3['tools'][0]['input']['spec_mm']));
    ok('추천표 모두 폭 W 500mm 로 확인된 제품', $c3 !== [] && !array_filter($c3, static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), '폭 W 500mm') || ($c['dim_check'] ?? '') !== 'verified'));
    ok('추천표에 대형차용·승용차용(중하중) 없음 — 수정 전: 안전중하중그레이팅수로용 136,700원 1종(' . implode(', ', $names3) . ')', $names3 !== [] && !array_filter($names3, static fn($x) => str_contains($x, '중하중')));
    $costs = array_column($c3, 'est_cost');
    $sorted = $costs; sort($sorted);
    ok('추천표 첫 줄이 최저 예상 비용, 낮은 순', $costs !== [] && $costs === $sorted && $costs[0] < 1367000);
    $t3 = $toolMsg($q3);
    ok('GPT 에 전달: 같은 폭 보행자용으로 바꾼 사실·뺀 대형차용', str_contains((string) ($t3['note'] ?? ''), '같은 폭의 보행자용 그레이팅') && in_array('안전중하중그레이팅수로용', $t3['over_spec_excluded'] ?? [], true));

    echo "[4턴 '과한 제품인거 같은데? 다시 추천' (GPT 는 같은 검색, exclude_ids 없이)]\n";
    [$r4, $q4] = $turn('과한 제품인거 같은데? 다시 추천', [$call($search, 2), $say('조건에 맞는 다른 제품이 없습니다.')]);
    $ex4 = $r4['tools'][0]['input']['exclude_ids'] ?? [];
    ok('서버가 직전 추천표 id 를 exclude_ids 로 채움', $ex4 !== [] && !array_diff(array_column($c3, 'id'), $ex4));
    ok('같은 표를 다시 보여 주지 않음(수정 전: 같은 안전중하중 표 반복)', $cards($r4) === []);
    $t4 = $toolMsg($q4);
    ok("GPT 에 전달: '다른 제품 없음' + 대형차용만 있음 + 등록 보행자용 폭", str_contains((string) ($t4['note'] ?? ''), '외에는 이 조건(폭 500mm)에 맞는 다른 제품이 없습니다') && str_contains((string) ($t4['note'] ?? ''), '그레이팅수로용 150·200·250mm'));

    echo "[5턴 '날개형은 안돼??']\n";
    [$r5, $q5] = $turn('날개형은 안돼??', [$call(['query' => '그레이팅 수로용 날개형', 'spec_mm' => 500, 'length_m' => 10], 3), $say('날개형은 폭 500mm 제품이 없습니다.')]);
    $t5 = $toolMsg($q5);
    ok("GPT 에 전달: missing_terms ['날개형'] + 등록 규격(플륨관용 날개형 185~385mm)", ($t5['missing_terms'] ?? []) === ['날개형'] && str_contains((string) ($t5['note'] ?? ''), '플륨관용(날개형U-TYPE) 185·285·295·385mm'));
    ok('이 턴 표에도 대형차용 없음', !array_filter(array_column($cards($r5), 'name'), static fn($x) => str_contains($x, '중하중')));

    echo "[별도 상담 'A 말고 다른 자재 추천해줘' — 다른 제품이 있으면 바뀐 표]\n";
    $clean();
    $messages = [];
    $s200 = ['query' => '그레이팅 수로용', 'spec_mm' => 200, 'length_m' => 10];
    [$a1] = $turn('사람만 다니는 마당 배수로 폭 200 길이 10m 덮개', [$call($s200, 4), $say('원하시는 제품을 선택해 주세요.')]);
    $first = array_column($cards($a1), 'name');
    [$a2, $qa2] = $turn(($first[0] ?? 'A') . ' 말고 다른 자재 추천해줘', [$call($s200, 5), $say('다른 제품입니다.')]);
    $second = array_column($cards($a2), 'name');
    ok('첫 표(' . implode(', ', $first) . ') → 다른 표(' . implode(', ', $second) . '): 겹치는 제품 없음', $first !== [] && $second !== [] && !array_intersect($first, $second));
    ok('바뀐 표: 같은 폭(200mm)의 다른 보행자용 그레이팅이라고 GPT 에 안내', str_contains((string) ($toolMsg($qa2)['note'] ?? ''), '같은 폭의 다른 보행자용 그레이팅')
        && !array_filter($cards($a2), static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), '폭 W 200mm') || ai_grating_load_class((string) $c['name']) !== 'pedestrian'));

    echo "[요청 6턴: '식당 주방 배수관 뚜껑 추천해줘' → '폭은 500mm이고 길이는 10m야' → '사람만 다니는 곳이야' → '저렴한 제품부터 추천해줘' → '다른 제품도 추천해줘' → '날개형도 있어?']\n";
    $clean();
    $messages = [];
    [$b1] = $turn('식당 주방 배수관 뚜껑 추천해줘.', [$say('배수로 폭과 설치 길이를 알려 주세요.')]);
    ok('1턴: 그레이팅 상담', ($b1['category'] ?? '') === 'grating');
    $s500 = ['query' => '그레이팅 수로용', 'spec_mm' => 500, 'length_m' => 10];
    [$b2] = $turn('폭은 500mm이고 길이는 10m야.', [$call($s500, 6), $say('주방 보행자 전용 배수로 덮개 제품입니다.')]);
    $c2 = $cards($b2);
    ok('2턴: 주방 → 보행자 조건·폭 500 → width_mm(10m 는 설치 길이 length_m 그대로)', ($b2['tools'][0]['input']['traffic'] ?? '') === 'pedestrian' && (float) ($b2['tools'][0]['input']['width_mm'] ?? 0) === 500.0 && (float) ($b2['tools'][0]['input']['length_m'] ?? 0) === 10.0);
    ok('2턴: 폭 W 500mm 보행자용만, 수량은 제품 길이 L 로(500×500 → 20개, 500×1000 → 10개)', $c2 !== [] && !array_filter($c2, static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), '폭 W 500mm') || str_contains($c['name'], '중하중'))
        && !array_filter($c2, static fn($c) => !(str_contains((string) $c['size'], '길이 L 500mm') ? $c['suggested_qty'] === 20 : (str_contains((string) $c['size'], '길이 L 1000mm') ? $c['suggested_qty'] === 10 : false))));
    $e2 = array_column($c2, 'est_cost'); $s2 = $e2; sort($s2);
    ok('2턴: 예상 비용 = 화면 단가 × 권장 수량, 낮은 순', $e2 === $s2 && !array_filter($c2, static fn($c) => $c['est_cost'] !== $c['price_member'] * $c['suggested_qty']));
    [$b3] = $turn('사람만 다니는 곳이야.', [$call($s500 + ['traffic' => 'pedestrian'], 7), $say('보행자용 제품입니다.')]);
    ok('3턴: 보행자 조건 유지(대형차·승용차용 없음)', $cards($b3) !== [] && !array_filter(array_column($cards($b3), 'name'), static fn($x) => str_contains($x, '중하중')));
    [$b4] = $turn('저렴한 제품부터 추천해줘.', [$call($s500, 8), $say('저렴한 순서입니다.')]);
    $e4 = array_column($cards($b4), 'est_cost'); $s4 = $e4; sort($s4);
    ok("4턴: '저렴한 제품부터'는 제외 요청이 아님(exclude_ids 없음) · 표는 낮은 금액 순", !isset($b4['tools'][0]['input']['exclude_ids']) && $e4 !== [] && $e4 === $s4);
    [$b5, $qb5] = $turn('다른 제품도 추천해줘.', [$call($s500, 9), $say('조건에 맞는 다른 제품이 없습니다.')]);
    ok("5턴: 직전 표 제외 → 같은 표 반복 없음 + 이유(다른 제품 없음·대형차용만·등록 폭)", $cards($b5) === [] && ($b5['tools'][0]['input']['exclude_ids'] ?? []) !== []
        && str_contains((string) ($toolMsg($qb5)['note'] ?? ''), '다른 제품이 없습니다') && str_contains((string) ($toolMsg($qb5)['note'] ?? ''), '그레이팅수로용 150·200·250mm'));
    [$b6, $qb6] = $turn('날개형도 있어?', [$call(['query' => '그레이팅 수로용 날개형', 'spec_mm' => 500, 'length_m' => 10], 10), $say('날개형은 폭 500mm 제품이 없습니다.')]);
    $t6 = $toolMsg($qb6);
    ok("6턴: 날개형 폭 500 은 없음 — 표에 날개형 없음 + GPT 에 '등록: 플륨관용(날개형U-TYPE) 185·285·295·385mm' 또는 폭 0건 안내", !in_array('플륨관용(날개형U-TYPE)', array_column($cards($b6), 'name'), true)
        && (str_contains((string) ($t6['note'] ?? ''), '플륨관용(날개형U-TYPE) 185·285·295·385mm') || str_contains((string) ($t6['note'] ?? ''), '폭 500mm로 확인된')));

    echo "[재추천: '앞에 추천한 제품은 제외해줘' · '좀 더 저렴한 제품']\n";
    $clean();
    $messages = [];
    $d200 = ['query' => '디자인그레이팅 수로용', 'width_mm' => 200, 'length_m' => 10, 'traffic' => 'pedestrian'];
    [$d1] = $turn('사람만 다니는 마당 폭 200 길이 10m 디자인 그레이팅', [$call($d200, 11), $say('디자인 그레이팅입니다.')]);
    $dn = array_column($cards($d1), 'name');
    $dmin = min(array_column($cards($d1), 'est_cost') ?: [0]);
    [$d2, $qd2] = $turn('좀 더 저렴한 제품', [$call(['query' => '그레이팅 수로용', 'width_mm' => 200, 'length_m' => 10, 'traffic' => 'pedestrian'], 12), $say('더 저렴한 제품입니다.')]);
    $c22 = $cards($d2);
    ok("'좀 더 저렴한 제품': 서버가 exclude_ids + max_est_cost(직전 최저 {$dmin}원)", (int) ($d2['tools'][0]['input']['max_est_cost'] ?? 0) === $dmin && $dmin > 0 && ($d2['tools'][0]['input']['exclude_ids'] ?? []) !== []);
    ok("'좀 더 저렴한 제품': 직전 표 제품 없음 · 모두 직전 최저보다 싼 예상 비용(같은 10m 기준) — " . implode(', ', array_map(static fn($c) => $c['name'] . ' ' . $c['est_cost'], $c22)),
        $c22 !== [] && !array_intersect($dn, array_column($c22, 'name')) && !array_filter($c22, static fn($c) => !($c['est_cost'] !== null && $c['est_cost'] < $dmin)));
    [$d3, $qd3] = $turn('좀 더 저렴한 제품 없어?', [$call(['query' => '그레이팅 수로용', 'width_mm' => 200, 'length_m' => 10, 'traffic' => 'pedestrian'], 13), $say('더 저렴한 제품은 없습니다.')]);
    ok("한 번 더 '더 저렴한 제품': 없으면 같은 표 대신 이유(직전 최저 예상 자재비보다 싼 제품 없음)", $cards($d3) === [] && str_contains((string) ($toolMsg($qd3)['note'] ?? ''), '보다 저렴한 제품은 없습니다'));
    [$d4] = $turn('앞에 추천한 제품은 제외해줘', [$call(['query' => '그레이팅', 'width_mm' => 200, 'length_m' => 10, 'traffic' => 'pedestrian'], 14), $say('다른 제품입니다.')]);
    $shownBefore = array_merge($dn, array_column($c22, 'name'));
    ok("'앞에 추천한 제품은 제외해줘': 이 상담에서 앞서 보인 제품(" . implode(', ', $shownBefore) . ')이 다시 나오지 않음 → ' . implode(', ', array_column($cards($d4), 'name')), ($d4['tools'][0]['input']['exclude_ids'] ?? []) !== [] && $cards($d4) !== [] && !array_intersect($shownBefore, array_column($cards($d4), 'name')));

    echo "[통행 조건 불명확: 장소·차량 말 없이 '그레이팅 폭 300 길이 30m']\n";
    $clean();
    $messages = [];
    [$u1] = $turn('그레이팅 폭 300 길이 30m', [$call(['query' => '그레이팅 수로용', 'spec_mm' => 300, 'length_m' => 30], 15), $say('표입니다.')]);
    ok('불명확: 서버가 통행 조건을 지어내지 않음(traffic 없음) · 폭 300 은 width_mm 로', !isset($u1['tools'][0]['input']['traffic']) && (float) ($u1['tools'][0]['input']['width_mm'] ?? 0) === 300.0);
    ok('불명확: 같은 폭 전 등급, 낮은 금액 순(프롬프트는 통행 여부를 먼저 묻게 함)', array_column($cards($u1), 'name') === ['중하중그레이팅수로용', '안전중하중그레이팅수로용']);
} finally {
    $clean();
    proc_terminate($srv);
    exec('rm -rf ' . escapeshellarg($tmp));
}
ok('테스트 상담 기록 정리', (int) db()->query("SELECT COUNT(*) FROM chat_logs WHERE session_key LIKE 'kitchen-test-%'")->fetchColumn() === 0);
echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
