<?php
declare(strict_types=1);
/**
 * 2026-11-10 운영 AI 상담 #192~#195 재현·회귀 테스트 — 목업 OpenAI(127.0.0.1), 로컬 운영 구조 테스트 DB(*_test), 외부 접속 없음.
 *
 * 운영 GPT 가 실제로 한 대로 부른다(chat_logs 표 조건 기준): ① 193 — '그레이팅 수로용' 폭 500 보행자 검색 후 대체품 안내(note)를 빼고 답함
 *   ② 194 '과한 제품인거 같은데? 다시 추천' — 직전 3개 제외 ③ 195 '날개형은 안돼??' — 검색어에서 '수로용'·'날개형'을 뺀 '그레이팅'.
 * 확인: ① 대체품 표시(substitute)가 GPT 에 전달되고, 답에 빠지면 서버가 안내 문장을 넣음(화면 카드에는 표시 없음)
 *       ② 직전 표가 이미 가장 낮은 하중 등급이라는 안내가 GPT 에 전달 · '과한'만 말하면 제외 검색을 하지 않음
 *       ③④ '수로' 상담이 이어져 집수정(맨홀)용이 표에 없고, '날개형' 등록 규격(185~385mm) 안내
 * 기록: 테스트 상담 키 'chat193-test-*' 로 chat_logs 에 쓰고 끝나면 지운다(로컬 테스트 DB만).
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/consult_chat193_195.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$tmp = sys_get_temp_dir() . '/chat193_test_' . getmypid();
@mkdir($tmp, 0777, true);
$port = 19560 + getmypid() % 200;
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
$sid = 'chat193-test-' . getmypid();
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
    echo "[192 '식당 주방에 배수관 뚜껑이 필요해요' — 검색 없이 안내]\n";
    [$r1, $q1] = $turn('식당 주방에 배수관 뚜껑이 필요해요', [$say('그레이팅수로용은 배수로 위에 덮는 격자 덮개입니다. 폭과 길이를 알려 주세요.')]);
    $sys1 = (string) ($q1[0]['messages'][0]['content'] ?? '');
    ok('프롬프트: 근거는 제품 쪽 사실, 상식·고객 조건 되풀이 금지', str_contains($sys1, '근거는 제품 쪽 사실이어야 한다') && str_contains($sys1, "누구나 아는 상식(예: '주방은 차량이 다니지 않는 보행자 구역이므로')은 근거로 쓰지 않는다"));
    ok('프롬프트: 대체품(substitute)을 찾은 품목처럼 말하지 않기', str_contains($sys1, '도구 결과에 substitute 가 있으면'));

    echo "[193 '10미터길이에 50센치 폭이야' — GPT 가 대체품 안내를 빼고 답함]\n";
    $s193 = ['query' => '그레이팅 수로용', 'width_mm' => 500, 'length_m' => 10, 'traffic' => 'pedestrian'];
    $gptText = "설치 구간 총 길이가 10미터이고 폭이 500mm인 보행자용 그레이팅을 추천합니다.\n\n- EX메탈망그레이팅: 스틸 평판 그레이팅입니다.\n\n원하시는 제품을 선택해 주세요.";
    [$r2, $q2] = $turn('10미터길이에 50센치 폭이야', [$call($s193, 1), $say($gptText)]);
    $c2 = $cards($r2);
    $t2 = $toolMsg($q2);
    ok('표: 폭 500 보행자용, 집수정(맨홀)용 없음(' . implode(', ', array_column($c2, 'name')) . ')', $c2 !== [] && !array_filter($c2, static fn($c) => preg_match('/맨홀|집수정|중하중/u', (string) $c['name'])));
    ok('GPT 에 대체품 표시: substitute.searched = 그레이팅 수로용 · 카드마다 substitute_for', ($t2['substitute']['searched'] ?? '') === '그레이팅 수로용' && !array_filter($t2['products'] ?? [], static fn($p) => ($p['substitute_for'] ?? '') !== '그레이팅 수로용'));
    ok('화면 카드에는 대체품 표시 없음(사용자 결정)', !array_filter($c2, static fn($c) => isset($c['substitute_for'])));
    $txt2 = (string) ($r2['text'] ?? '');
    ok('답에 대체품 안내가 빠져 서버가 둘째 문단에 넣음', str_contains($txt2, "보행자용 '그레이팅 수로용' 제품은 폭 500mm가 없어") && strpos($txt2, '설치 구간') === 0);
    [$r2b] = (function () use ($turn, $call, $say, $s193, &$messages) {   // GPT 가 스스로 말하면 다시 넣지 않음(같은 대화 기록은 남기지 않게 되돌림)
        $keep = $messages;
        $out = $turn('10미터길이에 50센치 폭이야', [$call($s193, 2), $say("보행자용 수로용 그레이팅은 폭 500mm가 없어, 같은 폭의 다른 보행자용 그레이팅을 보여 드립니다.\n\n원하시는 제품을 선택해 주세요.")]);
        $messages = $keep;
        db()->prepare('DELETE FROM chat_logs WHERE session_key = ? ORDER BY id DESC LIMIT 1')->execute([$GLOBALS['sid']]);
        return $out;
    })();
    ok('답에 이미 대체품 사실이 있으면 안내를 다시 넣지 않음', substr_count((string) ($r2b['text'] ?? ''), '폭 500mm') === 1);

    echo "[194 '과한 제품인거 같은데? 다시 추천' — 직전 표는 이미 가장 낮은 등급]\n";
    $ids2 = array_map(static fn($c) => (int) $c['id'], $c2);
    [$r3, $q3] = $turn('과한 제품인거 같은데? 다시 추천', [$call($s193 + ['exclude_ids' => $ids2], 3), $say('다시 찾았습니다.')]);
    $sys3 = (string) ($q3[0]['messages'][0]['content'] ?? '');
    $t3 = $toolMsg($q3);
    ok('시스템 안내: 직전 표가 이미 가장 낮은 하중 등급(보행자용) · 다른 제품을 원했으므로 검색 결과를 이어서', str_contains($sys3, '가장 낮은 하중 등급(보행자용) 제품입니다') && str_contains($sys3, '고객이 다른 제품도 원했으므로'));
    ok('도구 결과 note 맨 앞에 같은 안내', str_starts_with((string) ($t3['note'] ?? ''), '직전에 보여 드린'));
    ok("'다시 추천'을 함께 말했으므로 제외 검색은 그대로", array_diff($ids2, ai_int_list($r3['tools'][0]['input']['exclude_ids'] ?? null)) === []);

    echo "[194b '좀 과한 거 같은데' — '다시 추천' 없이 과하다고만 함]\n";
    $keep = $messages;
    [$r3b, $q3b] = $turn('좀 과한 거 같은데', [$call($s193 + ['exclude_ids' => $ids2], 4), $say('확인했습니다.')]);
    $messages = $keep;
    $sys3b = (string) ($q3b[0]['messages'][0]['content'] ?? '');
    ok('과하다고만 함: 다른 제품을 찾지 말고 금액·규격 중 무엇이 문제인지 묻게', str_contains($sys3b, '다른 제품을 찾지 않는다') && str_contains($sys3b, '한 가지만 묻는다'));
    ok('GPT 가 제외 검색을 해도 서버가 exclude_ids 를 뺌 → 같은 제품 표', !isset($r3b['tools'][0]['input']['exclude_ids']) && array_column($cards($r3b), 'id') === array_column($c2, 'id'));

    echo "[195 '날개형은 안돼??' — GPT 가 검색어에서 '수로용'·'날개형'을 뺌]\n";
    [$r4, $q4] = $turn('날개형은 안돼??', [$call(['query' => '그레이팅', 'width_mm' => 500, 'length_m' => 10, 'traffic' => 'pedestrian'], 5), $say('날개형 확인 결과입니다.')]);
    $i4 = $r4['tools'][0]['input'] ?? [];
    $c4 = $cards($r4);
    $t4 = $toolMsg($q4);
    ok("서버가 수로 덮개 상담 유지(cover_use=channel) · 고객이 말한 '날개형'을 검색어에 더함", ($i4['cover_use'] ?? '') === 'channel' && str_contains((string) ($i4['query'] ?? ''), '날개형'));
    ok('표에 집수정(맨홀)용 없음 — 수정 전: 그레이팅맨홀(집수정)용 17조 추천(' . implode(', ', array_column($c4, 'name')) . ')', $c4 !== [] && !array_filter($c4, static fn($c) => preg_match('/맨홀|집수정/u', (string) $c['name'])));
    ok("GPT 에 '날개형' 없음 + 등록 규격(플륨관용(날개형U-TYPE) 185~385mm) 안내", in_array('날개형', $t4['missing_terms'] ?? [], true) && str_contains((string) ($t4['note'] ?? ''), '플륨관용(날개형U-TYPE)'));
} finally {
    $clean();
    proc_terminate($srv);
    exec('rm -rf ' . escapeshellarg($tmp));
}
ok('테스트 상담 기록 정리', (int) db()->query("SELECT COUNT(*) FROM chat_logs WHERE session_key LIKE 'chat193-test-%'")->fetchColumn() === 0);
echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
