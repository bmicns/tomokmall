<?php
declare(strict_types=1);
/**
 * 자재 참고 자료(material_info) 정확도 회귀 테스트 — 실제 GPT-4o 자재 설명 8문항(2026-11-02, material-after8.md)에서 찾은 문제를 재현.
 *
 * 재현용 '[테스트]' 승인 행(가야스틸 유형 그레이팅 문장 5개)을 넣고 확인:
 *  ① '벤치플룸관 규격' — 다른 종류(그레이팅) 문장이 '규격' 낱말로 섞이지 않음(8번: 그레이팅 용도를 벤치플룸관 설명으로 말함)
 *  ② '그레이팅 미끄럼' — 미끄럼을 직접 말하는 문장만(3번: 크로스 바 '지지' 문장을 미끄럼 방지로 말함)
 *  ③ '그레이팅 유모차 휠체어' — 작은 바퀴 문장만
 *  ④ '스틸 그레이팅' — 제품별 묶음(by_product): 안전 중하중 문장이 중하중 묶음에 섞이지 않음(1번 실패 유형)
 *  ⑤ 자료 없을 때 안내: 용도·설치 장소 일반 상식 금지, 국가표준 '대상 종류'로만, 규격 숫자 풀어 쓰기 금지
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php tests/material_precision.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/rag.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}\n"; }
}
echo "순수 함수\n";
ok("조건 낱말: '그레이팅 미끄럼' → 미끄럼", in_array('미끄럼', ext_condition_words('그레이팅 미끄럼'), true));
ok("조건 낱말: '유모차' → 휠체어·바퀴 포함", array_intersect(['유모차', '휠체어', '바퀴'], ext_condition_words('인도 덮개 유모차')) === ['유모차', '휠체어', '바퀴']);
ok('조건 낱말 없음 → []', ext_condition_words('스틸 그레이팅') === []);

$dbName = (string) ($config['db']['name'] ?? '');
$pdo = db();
if (!preg_match('/_test$/', $dbName) || !$pdo || !ext_tables_ready()) { echo "통합: SKIP\n\n{$pass} 통과 / {$fail} 실패\n"; exit($fail ? 1 : 0); }
if ((int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE review_status = 'approved' AND source_key NOT LIKE 'test_prec%'")->fetchColumn() > 0) {
    echo "통합: SKIP(다른 승인 자료가 있는 DB)\n\n{$pass} 통과 / {$fail} 실패\n"; exit($fail ? 1 : 0);
}
echo "통합 ({$dbName})\n";
$pdo->exec("DELETE FROM external_products WHERE source_key = 'test_prec'");
$ins = $pdo->prepare("INSERT INTO external_products (source_key, dedup_key, name, maker, product_type, spec_raw, attrs_json, source_url, license_note, fetched_at, review_status)
                      VALUES ('test_prec', ?, ?, '[테스트]가야', '그레이팅', '', ?, ?, '테스트', '2026-10-29 06:18:00', 'approved')");
$row = static function (string $name, array $facts, int $i) use ($ins): void {
    $ins->execute([sha1("prec{$i}"), $name, json_encode(['facts' => $facts], JSON_UNESCAPED_UNICODE), "http://g.example/p{$i}"]);
};
$cross = '[테스트] 크로스 바(Cross Bar) : 베어링 바를 지지하는 형강입니다.';
$twist = '[테스트] 트위스트 바를 사용할 경우 미끄럼 방지 효과가 있음.';
$wheel = '[테스트] 안전 중하중 그레이팅은 스틸 그레이팅과 달리 유모차, 휠체어 등 작은 바퀴가 빠지지 않도록 제작합니다.';
$heavy = '[테스트] 중하중그레이팅은 ㄷ형강이 하중을 견디는 역할을 하는 구조입니다.';
$uses  = '[테스트] 지하차도, 공장, 건물 주변의 배수로에 사용하며 통과 하중에 따라 규격을 결정합니다.';
$row('[테스트]스틸그레이팅 종류', ['features' => [$cross, $twist], 'uses' => [$uses]], 1);
$row('[테스트]중하중그레이팅', ['features' => [$heavy]], 2);
$row('[테스트]안전 중하중 그레이팅', ['features' => [$wheel]], 3);
try {
    $t = static fn(string $topic) => array_column(ext_material_info($topic)['facts'], 'text');
    ok("① '벤치플룸관 규격' — 그레이팅 문장 없음(다른 종류가 '규격' 낱말로 섞이지 않음)", $t('벤치플룸관 규격') === [] && $t('벤치플룸관') === []);
    $slip = $t('그레이팅 미끄럼');
    ok("② '그레이팅 미끄럼' — 미끄럼 문장만(크로스 바 '지지' 문장 제외)", in_array($twist, $slip, true) && !in_array($cross, $slip, true) && !in_array($heavy, $slip, true));
    $w = $t('스틸 그레이팅 유모차 휠체어');
    ok("③ '유모차 휠체어' — 작은 바퀴 문장만", $w === [$wheel]);
    $all = json_decode(tool_material_info(['topic' => '스틸 그레이팅']), true);
    $groups = array_column($all['by_product'] ?? [], 'statements', 'product');
    $heavyGroup = implode(' ', $groups['중하중그레이팅'] ?? $groups['[테스트]중하중그레이팅'] ?? []);
    ok("④ '스틸 그레이팅' — 제품별 묶음, 안전 중하중 문장은 자기 묶음에만", count($groups) >= 3 && str_contains(implode(' ', $groups['안전 중하중 그레이팅'] ?? []), '작은 바퀴')
        && $heavyGroup !== '' && !str_contains($heavyGroup, '작은 바퀴') && str_contains((string) ($all['note'] ?? ''), 'by_product'));
    $none = json_decode(tool_material_info(['topic' => '벤치플룸관 U형 수로관']), true);
    ok('⑤ 자료 없음 안내: 용도·설치 장소 일반 상식 금지 + 국가표준 대상 종류로만 + 규격 숫자 풀어 쓰기 금지', str_contains((string) $none['note'], '일반 상식으로도 채우지 마세요')
        && (!isset($none['basics_note']) || (str_contains($none['basics_note'], '대상 종류') && str_contains($none['basics_note'], '풀어 쓰지 않는다'))));
    $u = array_column(ai_material_basics('U형 수로관')['products'], 'name');
    ok("⑥ 'U형 수로관' 기본 정보에 포장 블록 '인터로킹U형블럭' 이 섞이지 않음(2차 실제 4번)", !in_array('인터로킹U형블럭', $u, true) && (bool) array_filter($u, static fn($n) => str_contains($n, '플륨')));
} finally {
    $pdo->exec("DELETE FROM external_products WHERE source_key = 'test_prec'");
}
echo "\n{$pass} 통과 / {$fail} 실패\n";
exit($fail ? 1 : 0);
