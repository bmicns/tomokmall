<?php
declare(strict_types=1);
/**
 * 초보 고객 전체 흐름 점검(consult-scenarios.php --core 4번째 시나리오) 테스트 — 목업 OpenAI, 로컬 테스트 DB(*_test), 외부 접속 없음.
 *
 * 흐름: '집 앞에 보도블록 … 차가 지나가도?' → '마당·승용차·4m×5m' → '제일 저렴한 걸로 견적에 담아 주세요'
 * 확인: ① 정상 흐름(80T 비교표 + 필요 수량 + 3턴째 add_to_cart) → 통과  ② 담기 안 함 → 실패  ③ 80T 를 법정 기준처럼 말함 → 확인 필요
 *       실제 도구(search_products·add_to_cart)가 테스트 DB 상품으로 실행된다(OpenAI 응답만 목업).
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php tests/consult_journey.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/rag.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}\n"; }
}
$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName) || !db()) { echo "SKIP: 로컬 테스트 DB(*_test)에서만 실행합니다.\n"; exit(0); }
$pid = (int) db()->query("SELECT id FROM products WHERE is_active = 1 AND spec LIKE '%80T%' AND name LIKE '%인터로킹%' ORDER BY id LIMIT 1")->fetchColumn();
if ($pid === 0) { echo "SKIP: 80T 인터로킹 상품이 없는 DB\n"; exit(0); }
$tmp = sys_get_temp_dir() . '/consult_journey_' . getmypid();
@mkdir($tmp, 0777, true);
$port = 19800 + getmypid() % 90;
file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n); file_put_contents("$d/req_$n.json", file_get_contents("php://input"));
$r = json_decode(file_get_contents("$d/responses.json"), true); header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
$call = static fn(string $name, array $args, int $i) => ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
    'tool_calls' => [['id' => "c{$i}", 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]]]]]]];
$say = static fn(string $t) => ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $t]]]];
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
usleep(400000);
// 앞 시나리오(사유지 2턴)는 같은 목업 응답 순서로 소비되므로 그 몫도 넣는다
$prior = [$say('차량이 올라가면 저희 권장 기준은 80mm 이상입니다. 마당 크기를 알려 주세요.'),
          $call('search_products', ['query' => '인터로킹 블록', 'spec_mm' => 80, 'spec_strict' => true, 'area_m2' => 30], 1), $say('원하시는 제품을 선택해 주세요.')];
$run = static function (array $journey) use ($tmp, $port, $prior): string {
    file_put_contents("{$tmp}/responses.json", json_encode(array_merge($prior, $journey), JSON_UNESCAPED_UNICODE));
    @unlink("{$tmp}/n");
    array_map('unlink', glob("{$tmp}/req_*.json") ?: []);
    exec("OPENAI_API_KEY=test-not-a-key OPENAI_CHAT_URL=http://127.0.0.1:{$port}/v1/chat/completions " . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/../scripts/testdb/consult-scenarios.php') . ' --core --only=private --skip-product-check --md=' . escapeshellarg("{$tmp}/j.md") . ' 2>&1');
    $md = (string) @file_get_contents("{$tmp}/j.md");
    return preg_match('/## 2\. (.*?)(?=\n## \d+\. |\n합계|$)/su', $md, $m) ? $m[1] : '';
};
$search = $call('search_products', ['query' => '인터로킹 블록', 'spec_mm' => 80, 'spec_strict' => true, 'area_m2' => 20], 2);
try {
    $sec = $run([$say('차가 지나가는 곳이면 블록이 깨지지 않게 두꺼운 제품이 필요합니다. 저희 권장 기준은 80mm(80T) 이상입니다. 개인 마당인가요, 도로인가요? 면적도 알려 주세요.'),
                 $search, $say('원하시는 제품을 선택해 주세요.'),
                 $call('add_to_cart', ['product_id' => $pid, 'quantity' => 20], 3), $say('가장 저렴한 제품을 담았습니다. 같은 금액의 다른 인터로킹 블록도 있습니다. 견적요청하기 버튼을 눌러 신청해 주세요.')]);
    ok('정상 흐름: 80T 비교표·필요 수량·견적 담기 → 통과', str_contains($sec, '자동 점검 통과') && str_contains($sec, '(80mm)'));
    // 3턴째(담기) 요청의 시스템 프롬프트에 2턴째 화면에 보인 추천표가 실제 상품 ID·현재가·권장 수량과 함께 들어감 — 대화 기록엔 표가 글로 없음
    $sys3 = (string) (json_decode((string) @file_get_contents("{$tmp}/req_7.json"), true)['messages'][0]['content'] ?? '');
    $sys1 = (string) (json_decode((string) @file_get_contents("{$tmp}/req_4.json"), true)['messages'][0]['content'] ?? '');
    ok('3턴째 프롬프트에 직전 추천표(id·판매가·권장 수량·가장 저렴), 1턴째에는 없음', str_contains($sys3, '=== 직전 추천표') && (bool) preg_match('/\[id \d+\] [^\n]*판매가 [\d,]+원[^\n]*권장 수량 \d+/u', $sys3)
        && str_contains($sys3, '(가장 저렴)') && $sys1 !== '' && !str_contains($sys1, '=== 직전 추천표'));
    ok('시험이 남긴 상담 기록 정리', (int) db()->query("SELECT COUNT(*) FROM chat_logs WHERE session_key LIKE 'scenario-test-%'")->fetchColumn() === 0);
    ok('결과 문서에 3턴 모두 기록', substr_count($sec, '### 턴') === 3);
    $sec = $run([$say('저희 권장 기준은 80mm 이상입니다. 면적을 알려 주세요.'), $search, $say('원하시는 제품을 선택해 주세요.'), $say('견적에 담으시려면 견적추가를 눌러 주세요.')]);
    ok('3턴째 add_to_cart 안 함 → 실패', str_contains($sec, '실패(마지막 턴에서 견적 담기(add_to_cart)를 하지 않음)'));
    $sec = $run([$say('법정 기준상 차량이 다니면 80mm 이상이어야 합니다.'), $search, $say('원하시는 제품을 선택해 주세요.'),
                 $call('add_to_cart', ['product_id' => $pid, 'quantity' => 20], 4), $say('가장 저렴한 제품을 담았습니다. 같은 금액의 다른 인터로킹 블록도 있습니다. 견적요청하기 버튼을 눌러 신청해 주세요.')]);
    ok('저희 권장 80T 를 법정 기준처럼 말함 → 확인 필요', str_contains($sec, '확인 필요(저희 권장 80T 를 법정 기준처럼 말함)'));
} finally {
    proc_terminate($srv);
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail ? 1 : 0);
