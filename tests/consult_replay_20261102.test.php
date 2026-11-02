<?php
declare(strict_types=1);
/**
 * 실제 GPT-4o 답변 재생 테스트 — 2026-11-02 사용자 PC 실행(material-after8.md · consult-core-2026-11-02.md)에서 자동 점검이 '통과'로 놓친 답을
 * 그대로 다시 넣어 지금의 자동 점검이 잡는지 확인(목업 OpenAI, 로컬 테스트 DB). 답변 원문: tests/fixtures/gpt4o-20261102-material-answers.json
 *
 * 자재(--material): 4번 'KS F 4010에 따라 제조'·자료에 없는 용도, 5번 'KS 번호에 맞는 제품을 선택하는 것이 바람직', 8번 규격 숫자를 폭(cm)으로 풀이 → 실패
 * 핵심(--core) 4번: 2턴째 비교표 없이 종류만 되물음 → 확인 필요(이전 판정: 통과)
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php tests/consult_replay_20261102.test.php
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
$pdo = db();
if (!preg_match('/_test$/', $dbName) || !$pdo || !ext_tables_ready()) { echo "SKIP: 로컬 테스트 DB 전용\n"; exit(0); }
if ((int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE review_status = 'approved'")->fetchColumn() > 0) { echo "SKIP: 승인 자료가 있는 DB(결과가 섞임)\n"; exit(0); }
$real = json_decode((string) file_get_contents(__DIR__ . '/fixtures/gpt4o-20261102-material-answers.json'), true);
$tmp = sys_get_temp_dir() . '/consult_replay_' . getmypid();
@mkdir($tmp, 0777, true);
$port = 19700 + getmypid() % 90;
file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n);
$r = json_decode(file_get_contents("$d/responses.json"), true); header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
$call = static fn(string $name, array $args, int $i) => ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
    'tool_calls' => [['id' => "c{$i}", 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]]]]]]];
$say = static fn(string $t) => ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $t]]]];
// 흄관 제조사 승인 자료(동양콘크리트 유형) — 5번은 실제처럼 자료가 있는 상태
$pdo->exec("DELETE FROM external_products WHERE source_key = 'test_replay'");
$pdo->prepare("INSERT INTO external_products (source_key, dedup_key, name, maker, product_type, spec_raw, attrs_json, source_url, license_note, fetched_at, review_status)
               VALUES ('test_replay', ?, '[테스트]흄관', '[테스트]동양', '흄관', 'D300', ?, 'http://d.example/p1', '테스트', '2026-10-29 06:18:00', 'approved')")
    ->execute([sha1('replay1'), json_encode(['facts' => ['certs' => ['[테스트]흄관 (B형 / 원심력 철근 콘크리트관) KS F 4403']]], JSON_UNESCAPED_UNICODE)]);
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
usleep(400000);
$runCs = static function (array $responses, string $args) use ($tmp, $port): string {
    file_put_contents("{$tmp}/responses.json", json_encode($responses, JSON_UNESCAPED_UNICODE));
    @unlink("{$tmp}/n");
    exec("OPENAI_API_KEY=test-not-a-key OPENAI_CHAT_URL=http://127.0.0.1:{$port}/v1/chat/completions " . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/../scripts/testdb/consult-scenarios.php') . " {$args} --md=" . escapeshellarg("{$tmp}/r.md") . ' 2>&1');
    return (string) @file_get_contents("{$tmp}/r.md");
};
$sec = static fn(string $md, int $i): string => preg_match('/## ' . $i . '\. (.*?)(?=\n## \d+\. |\n합계|$)/su', $md, $m) ? $m[1] : '';
try {
    echo "[자재 설명 — 실제 답변 재생]\n";
    $md = $runCs([
        $say('일반 설명입니다.'), $say('일반 설명입니다.'), $say('일반 설명입니다.'),
        $call('material_info', ['topic' => '벤치플룸관'], 4), $say($real['4']),
        $call('material_info', ['topic' => '흄관'], 5), $say($real['5']),
        $say('일반 설명입니다.'), $say('차가 다니면 두께 80mm 이상 블록을 쓰세요.'),
        $call('material_info', ['topic' => '벤치플룸관 규격'], 8), $say($real['8']),
    ], '--material');
    ok("4번(이전: 통과) 'KS F 4010에 따라 제조되는 제품' → 실패", str_contains($sec($md, 4), '자동 점검: 실패(국가표준·KS 를 적합 단정'));
    ok("5번(이전: 확인 필요) 'KS 번호에 맞는 제품을 선택하는 것이 바람직' → 실패", str_contains($sec($md, 5), '자동 점검: 실패(국가표준·KS 를 적합 단정 또는 선택 기준으로 권함'));
    ok("8번(이전: 확인 필요) 규격 숫자를 '폭(cm), 높이(cm)' 로 풀이 → 실패", str_contains($sec($md, 8), '자동 점검: 실패(등록 규격 숫자를 폭·높이·단위로 풀어 씀'));
    ok("8번 주제 '벤치플룸관 규격' 에 다른 종류(그레이팅) 제조사 자료가 섞이지 않음", str_contains($sec($md, 8), '답변에 쓰인 참고 자료 출처: 없음'));
    $md4 = $runCs([
        $call('material_info', ['topic' => '벤치플룸관'], 4),
        $say("제조사 자료가 없어 일반 설명으로 안내합니다. 벤치플룸관은 주로 하천이나 농업용지의 배수에 쓰입니다. U형 수로관은 퇴적물 청소가 용이합니다."),
    ], '--material');
    ok('자료 없는 자재의 일반 상식 용도(하천·농업·퇴적물 청소) → 확인 필요', str_contains($sec($md4, 1), '') && str_contains($md4, '자료에 없는 용도·장점 설명'));

    echo "[핵심 4번 — 실제 흐름 재생: 2턴째 종류만 되물음 → 3턴째 검색과 담기 동시]\n";
    $pid = (int) $pdo->query("SELECT id FROM products WHERE is_active = 1 AND spec LIKE '%80T%' AND name LIKE '%인터로킹%' ORDER BY id LIMIT 1")->fetchColumn();
    $prior = [$say('저희 권장 기준은 80mm 이상입니다. 마당 크기를 알려 주세요.'),
              $call('search_products', ['query' => '인터로킹 블록', 'spec_mm' => 80, 'spec_strict' => true, 'area_m2' => 30], 1), $say('원하시는 제품을 선택해 주세요.')];
    $md = $runCs(array_merge($prior, [
        $say('차가 지나갈 수 있는 보도블록은 두께 80mm 이상이 필요합니다. 면적을 알려 주세요.'),
        $say('면적은 20㎡입니다. 인터로킹 블록, 인조화강블록, 점토블럭 중 어떤 종류를 선호하시나요?'),
        $call('search_products', ['queries' => ['인터로킹 블록', '인조화강블록', '점토블럭'], 'area_m2' => 20, 'spec_mm' => 80, 'spec_strict' => true], 3),
        $call('add_to_cart', ['product_id' => $pid, 'quantity' => 20], 4), $say('가장 저렴한 제품을 견적에 담았습니다. 견적요청하기 버튼을 눌러 신청해 주세요.'),
    ]), '--core --only=private --skip-product-check');
    ok('비교표 없이 종류를 되물은 흐름 → 확인 필요(이전: 통과)', str_contains($sec($md, 2), '확인 필요(면적까지 받은 턴에 비교표 없이 되물음'));
    ok('결과 머리말: 핵심 4개', str_contains($md, '핵심 4개'));
} finally {
    proc_terminate($srv);
    $pdo->exec("DELETE FROM external_products WHERE source_key = 'test_replay'");
    exec('rm -rf ' . escapeshellarg($tmp));
}
echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail ? 1 : 0);
