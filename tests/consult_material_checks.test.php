<?php
declare(strict_types=1);
/**
 * 자재 설명 상담 시험(consult-scenarios.php --material) 자동 점검 회귀 테스트 — 로컬 목업 OpenAI(127.0.0.1)·로컬 테스트 DB, 실제 OpenAI 호출 없음.
 *
 * 목업 답변은 2026-10-29 실제 GPT-4o 승인 전·후 결과에서 나온 문제 유형을 '[테스트]' 문장으로 흉내 낸 것(실제 답변 아님).
 * 승인 자료는 '[테스트]' 가상 제조사 행(import-crawled → approve-crawled)으로 만들고 끝나면 지운다.
 * 확인: 원문에 없는 하중 수치(톤) → 실패, 제조사 이름으로 출처를 밝히면 출처 표시로 인정, 승인 자료의 핵심(작은 바퀴) 미사용 → 확인 필요,
 *       자료 없이 '제조사 자료에 따르면' → 실패(확대 해석 표현은 참고 자료를 쓴 답에서만 판정), 자료에 없는 KS 번호 → 실패, 도구만 부르다 한도 → 최종 답(빈 답변 아님),
 *       두께 질문(7번)은 제조사 자료가 있어도 안 써도 됨, 부른 도구 기록, V21·V22·승인 상태 불변.
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/consult_material_checks.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/external_products.php';

$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName) || !db() || !ext_tables_ready()) {
    echo "SKIP: external_products 테이블이 있는 로컬 테스트 DB(*_test)에서만 실행합니다.\n";
    exit(0);
}
$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$pdo = db();
if ((int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE review_status = 'approved'")->fetchColumn() > 0) {
    echo "SKIP: 이 테스트 DB 에 이미 승인된 외부 자료가 있습니다(결과가 섞임).\n";
    exit(0);
}
$tmp = sys_get_temp_dir() . '/consult_mat_test_' . getmypid();
@mkdir($tmp, 0777, true);
$port = 19900 + getmypid() % 90;
// ── 가상 제조사 승인 자료 ──
$reg = json_decode((string) file_get_contents(__DIR__ . '/../data/external/sources.json'), true);
$reg['sources'] = [['key' => 'test_cmat', 'name' => '[테스트]제조', 'domain' => 'm.example', 'permission' => 'facts_if_clear', 'basis' => '테스트', 'conditions' => ['테스트']]];
file_put_contents("{$tmp}/sources.json", json_encode($reg, JSON_UNESCAPED_UNICODE));
$rec = static fn(string $name, string $spec, int $page, array $facts) => ['name' => $name, 'spec' => $spec, 'maker' => '[테스트]제조', 'category' => '', 'note' => '',
    'source_url' => "http://m.example/p{$page}", 'facts' => $facts];
file_put_contents("{$tmp}/test_cmat-20261029-000000.json", json_encode([
    $rec('[테스트]안전 그레이팅', '폭 300 / 높이 30', 1, ['features' => ['[테스트] 안전 그레이팅은 유모차, 휠체어 등 작은 바퀴가 빠지지 않게 촘촘히 만듭니다.', '[테스트] 그레이팅 상부 면은 톱니 형상이라 미끄럼을 줄입니다.']]),
    $rec('[테스트]컬러 보도블록', '200*200*60', 2, ['features' => ['[테스트] 블록 표면은 오염방지 처리를 했습니다.']]),
], JSON_UNESCAPED_UNICODE));
$env = 'EXT_SOURCES_FILE=' . escapeshellarg("{$tmp}/sources.json") . ' EXT_CRAWL_DIR=' . escapeshellarg($tmp);
$ext = static fn(string $s, string $a = '') => exec("{$env} " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/../scripts/external/{$s}") . " {$a} 2>&1");
// ── 목업 OpenAI: 요청 순서대로 정해 둔 응답 ──
file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n);
$r = json_decode(file_get_contents("$d/responses.json"), true); header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
$tool = static fn(string $topic, int $i) => ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
    'tool_calls' => [['id' => "c{$i}", 'type' => 'function', 'function' => ['name' => 'material_info', 'arguments' => json_encode(['topic' => $topic], JSON_UNESCAPED_UNICODE)]]]]]]];
$say = static fn(string $t) => ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $t]]]];
file_put_contents("{$tmp}/responses.json", json_encode([
    $tool('그레이팅', 1), $say('[테스트]제조 자료에 따르면 중하중 그레이팅은 5톤 이상 차량도 됩니다.'),                     // 1: 원문에 없는 톤수
    $tool('그레이팅 유모차 휠체어', 2), $say('[테스트]제조에 따르면 미끄럼을 줄이는 그레이팅을 고르시면 됩니다.'),          // 2: 출처는 제조사 이름, 핵심(작은 바퀴) 빠짐
    $tool('그레이팅 미끄럼', 3), $say('[테스트]제조 자료에 따르면 상부 면이 톱니 형상이라 미끄럼을 줄입니다.'),           // 3: 정상
    $tool('수로관', 7), $say('벤치플룸관은 하수도에 씁니다.'),                                                          // 4: 자료 없음인데 일반 설명이라고 밝히지 않음
    $say('흄관은 KS F 4009 제품을 쓰시면 됩니다.'),                                                                    // 5: 자료에 없는 KS 번호
    $tool('보도블록', 4), $tool('보도블록 오염', 5), $tool('블록 오염', 6), $say('[테스트]제조 자료에 따르면 표면 오염방지 처리 제품이 있습니다.'),   // 6: 도구만 3번 → 최종 답
    $say('차가 다니면 두께 80mm 이상 블록을 쓰세요.'),                                                                 // 7: 두께 질문, 제조사 자료 불필요
], JSON_UNESCAPED_UNICODE));
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
usleep(400000);
$v2122 = $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', is_active, ':', review_status) ORDER BY id) FROM rag_documents WHERE tags LIKE '@scope:public_road%'")->fetchColumn();
try {
    $ext('import-crawled.php');
    $ext('approve-crawled.php', '--approve=test_cmat');
    $approvedBefore = (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE review_status = 'approved'")->fetchColumn();
    exec("OPENAI_API_KEY=test-not-a-key OPENAI_CHAT_URL=http://127.0.0.1:{$port}/v1/chat/completions " . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/../scripts/testdb/consult-scenarios.php') . ' --material --md=' . escapeshellarg("{$tmp}/m.md") . ' 2>&1', $o, $code);
    $md = (string) @file_get_contents("{$tmp}/m.md");
    $sec = static function (int $i) use (&$md): string { return preg_match('/## ' . $i . '\. (.*?)(?=\n## \d\. |\n합계)/su', $md, $m) ? $m[1] : ''; };
    ok('승인 자료 준비(가상 제조사 2행)', $approvedBefore === 2 && $md !== '');
    ok('1: 원문에 없는 하중 수치(5톤) → 실패', str_contains($sec(1), '자동 점검: 실패(자료에 없는 하중 수치: 5톤)'));
    ok('2: 제조사 이름으로 밝힌 출처는 인정, 핵심(작은 바퀴) 미사용 → 확인 필요', str_contains($sec(2), '자동 점검: 확인 필요(승인 자료의 핵심 문장 미사용)'));
    ok('3: 원문대로·출처 → 통과, 부른 도구 기록', str_contains($sec(3), '자동 점검: 통과') && str_contains($sec(3), '부른 도구: material_info({"topic":"그레이팅 미끄럼"})'));
    ok('4: 자료를 찾았지만 없음 → 일반 설명이라고 밝히지 않으면 확인 필요', str_contains($sec(4), '자동 점검: 확인 필요(참고 자료가 없는데 일반 설명이라고 밝히지 않음)'));
    ok('5: 자료에 없는 KS 번호 → 실패', str_contains($sec(5), '자동 점검: 실패(자료에 없는 KS 번호: KS F 4009)'));
    ok('6: 도구만 3번 불러도 빈 답변이 아니라 최종 답', !str_contains($sec(6), '실행 오류') && str_contains($sec(6), '표면 오염방지 처리 제품이 있습니다') && substr_count($sec(6), 'material_info(') === 3);
    ok('7: 두께 질문은 제조사 자료를 안 써도 통과(80mm 안내 확인)', str_contains($sec(7), '자동 점검: 통과') && (bool) preg_match("/승인 자료\\(주제 '인조화강블록'\\): [1-9]/u", $sec(7)));
    ok('합계·종료 코드(실패 있음 → 1)', str_contains($md, '합계: 통과 3 · 확인 필요 2 · 실패 2') && $code === 1);
    // 2회차: 원문 제품명을 넓은 종류로 바꿔 말함(1), 자료 없이 '제조사 자료에 따르면'(4)
    file_put_contents("{$tmp}/responses.json", json_encode([
        $tool('그레이팅', 11), $say('[테스트]제조 자료에 따르면 **중하중 그레이팅**은 유모차, 휠체어 바퀴가 빠지지 않습니다.'),
        $say('일반 설명입니다.'), $say('일반 설명입니다.'), $say('제조사 자료에 따르면 벤치플룸관은 대형입니다.'), $say('일반 설명입니다.'), $say('일반 설명입니다.'),
        $say('차가 다니면 두께 80mm 이상 블록을 쓰세요.'),
    ], JSON_UNESCAPED_UNICODE));
    @unlink("{$tmp}/n");
    exec("OPENAI_API_KEY=test-not-a-key OPENAI_CHAT_URL=http://127.0.0.1:{$port}/v1/chat/completions " . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/../scripts/testdb/consult-scenarios.php') . ' --material --md=' . escapeshellarg("{$tmp}/m.md") . ' 2>&1', $o, $code);
    $md = (string) @file_get_contents("{$tmp}/m.md");
    ok("1(2회차): '안전 그레이팅' 설명을 '중하중 그레이팅' 전체로 바꿔 말함(굵게 표시 포함) → 실패", str_contains($sec(1), '자동 점검: 실패(원문 밖 확대 해석: 중하중 그레이팅은 유모차'));
    ok("4(2회차): 참고 자료 없이 '제조사 자료에 따르면' → 실패", str_contains($sec(4), "자동 점검: 실패(참고 자료가 없는데 '제조사 자료에 따르면' 표현)"));
    ok('V21·V22·승인 상태 그대로', $v2122 === $pdo->query("SELECT GROUP_CONCAT(CONCAT(id, ':', is_active, ':', review_status) ORDER BY id) FROM rag_documents WHERE tags LIKE '@scope:public_road%'")->fetchColumn()
        && $approvedBefore === (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE review_status = 'approved'")->fetchColumn());
} finally {
    proc_terminate($srv);
    $pdo->exec("DELETE m FROM external_product_matches m JOIN external_products e ON e.id = m.external_product_id WHERE e.source_key = 'test_cmat'");
    $pdo->exec("DELETE FROM external_products WHERE source_key = 'test_cmat'");
    exec('rm -rf ' . escapeshellarg($tmp));
}
ok('테스트 행·임시 폴더 정리', (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE source_key = 'test_cmat'")->fetchColumn() === 0 && !is_dir($tmp));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
