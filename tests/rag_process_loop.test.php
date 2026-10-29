<?php
declare(strict_types=1);
/**
 * 상담 도구 반복(rag_process) 회귀 테스트 — 로컬 목업 OpenAI 서버(127.0.0.1)로 응답을 정해 두고 실행, 실제 OpenAI 호출 없음.
 *
 * 배경: 2026-10-29 실제 GPT-4o 자재 설명 시험에서 도구만 3번 부르고 반복 한도에 닿아 '오류 없이 빈 답변'(ok=true, text='')이 2건 나옴.
 * 확인: ① 도구만 부르다 한도에 닿으면 도구 없이(tool_choice=none) 최종 답을 1회 더 받아 돌려줌
 *       ② 그 요청도 빈 답이면 빈 답변 대신 오류(ok=false)로 알림 ③ 보통 답변은 추가 요청 없음
 *       ④ OPENAI_CHAT_URL 은 127.0.0.1 목업만 허용(그 밖의 주소는 무시)
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/rag_process_loop.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$tmp = sys_get_temp_dir() . '/rag_loop_test_' . getmypid();
@mkdir($tmp, 0777, true);
$port = 19600 + getmypid() % 300;
putenv("OPENAI_CHAT_URL=http://127.0.0.1:{$port}/v1/chat/completions");
putenv('OPENAI_API_KEY=test-not-a-key');
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/rag.php';

$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName) || !db()) {
    echo "SKIP: 로컬 테스트 DB(*_test)에서만 실행합니다(프롬프트 생성에 DB 사용).\n";
    exit(0);
}
$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
// 목업: 요청마다 responses.json 의 다음 응답을 돌려주고, 받은 요청 본문을 req-N.json 으로 남긴다
file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n);
file_put_contents("$d/req-$n.json", file_get_contents("php://input")); $r = json_decode(file_get_contents("$d/responses.json"), true);
header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
usleep(400000);
$toolCall = static fn(int $i) => ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
    'tool_calls' => [['id' => "call_{$i}", 'type' => 'function', 'function' => ['name' => 'material_info', 'arguments' => '{"topic":"[테스트]흄관"}']]]]]]];
$answer = static fn(string $t) => ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $t]]]];
$run = static function (array $responses) use ($tmp): array {
    array_map('unlink', glob("{$tmp}/req-*.json") ?: []);
    @unlink("{$tmp}/n");
    file_put_contents("{$tmp}/responses.json", json_encode($responses, JSON_UNESCAPED_UNICODE));
    $res = rag_process([['role' => 'user', 'content' => '흄관은 무슨 KS 규격 제품을 쓰면 돼요?']], 'loop-test');
    $reqs = [];
    foreach (range(1, (int) @file_get_contents("{$tmp}/n")) as $i) { $reqs[] = json_decode((string) @file_get_contents("{$tmp}/req-{$i}.json"), true); }
    return [$res, $reqs];
};
try {
    ok('OPENAI_CHAT_URL: 127.0.0.1 목업만 허용', $config['openai']['chat_url'] === "http://127.0.0.1:{$port}/v1/chat/completions");

    echo "[도구만 부르다 반복 한도]\n";
    [$r1, $q1] = $run([$toolCall(1), $toolCall(2), $toolCall(3), $answer('[테스트] 최종 답변입니다.')]);
    ok('빈 답변 대신 최종 답(ok, 본문 있음)', $r1['ok'] === true && $r1['text'] === '[테스트] 최종 답변입니다.');
    ok('추가 요청은 1회, 도구 없이(tool_choice=none) + 앞선 도구 결과 포함', count($q1) === 4 && ($q1[3]['tool_choice'] ?? '') === 'none'
        && count(array_filter($q1[3]['messages'], static fn($m) => ($m['role'] ?? '') === 'tool')) === 3 && ($q1[2]['tool_choice'] ?? '') === 'auto');

    [$r2, $q2] = $run([$toolCall(1), $toolCall(2), $toolCall(3), $answer('')]);
    ok('추가 요청도 빈 답이면 빈 답변 대신 오류로 알림', $r2['ok'] === false && $r2['text'] === '' && ($r2['error'] ?? '') !== '' && count($q2) === 4);

    echo "[보통 답변]\n";
    [$r3, $q3] = $run([$toolCall(1), $answer('[테스트] 도구 결과를 보고 답합니다.')]);
    ok('도구 1회 → 답: 추가 요청 없음', $r3['ok'] === true && $r3['text'] === '[테스트] 도구 결과를 보고 답합니다.' && count($q3) === 2);
    [$r4, $q4] = $run([$answer('[테스트] 바로 답합니다.')]);
    ok('도구 없이 바로 답: 요청 1회', $r4['ok'] === true && $r4['text'] === '[테스트] 바로 답합니다.' && count($q4) === 1);
} finally {
    proc_terminate($srv);
    exec('rm -rf ' . escapeshellarg($tmp));
}
putenv('OPENAI_CHAT_URL=https://example.com/v1/chat/completions');
$cfg = require __DIR__ . '/../app/config.php';
ok('그 밖의 주소는 무시하고 OpenAI 기본 주소', ($cfg['openai']['chat_url'] ?? '') === 'https://api.openai.com/v1/chat/completions');

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
