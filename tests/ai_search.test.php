<?php
declare(strict_types=1);
/**
 * AI 상담 상품 검색 도구(tool_search_products) 통합 회귀 테스트 — 실제 수집 상품 데이터 + 로컬 테스트 DB 전용.
 *
 * 데이터: data/ktomok/import.sql 1,452건 (scripts/testdb/load-ktomok-docker.sh 로 적재). 목 데이터를 만들지 않는다.
 *  - DB 이름이 '_test'로 끝나고 호스트가 로컬일 때만 실행(아니면 SKIP). 읽기 전용(SELECT)만 한다.
 *  - OpenAI 호출 없음: AI가 호출하는 도구 함수를 직접 실행해 카드 결과(대표 규격·수량·예상 자재비)를 검증.
 * 회귀 대상(2026-10-27 발견 결함):
 *  ① 대표 규격이 가장 비싼 변형(80T 칼라)으로 뽑혀 비용 비교가 부풀려짐
 *  ② 차량용 80T 조건이 비교 검색에서 풀려 76T 점토블럭이 섞임
 *  ③ 경계석 검색에 경계석집게(부속)가 섞임
 * 실행 예: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=... DB_PASS=... php tests/ai_search.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/ai_tools.php';

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true)) {
    echo "SKIP: 로컬 테스트 DB(DB_NAME=*_test, DB_HOST=127.0.0.1|localhost|mysql)에서만 실행합니다. 현재 DB_NAME='{$dbName}'\n";
    exit(0);
}
$n = db() ? (int) db()->query("SELECT COUNT(*) FROM products WHERE external_source = 'ktomok' AND is_active = 1")->fetchColumn() : 0;
if ($n < 1000) {
    echo "SKIP: 테스트 DB에 ktomok 수집 상품이 없습니다({$n}건). bash scripts/testdb/load-ktomok-docker.sh 로 적재하세요.\n";
    exit(0);
}

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
function search(array $input): array { return json_decode(tool_search_products($input), true) ?: []; }
function reg(array $c): string { return preg_match('/규격:\s*([^|]+)/u', (string) $c['spec'], $m) ? trim($m[1]) : ''; }
function byName(array $res, string $name): ?array {
    foreach ($res['products'] ?? [] as $c) { if ($c['name'] === $name) { return $c; } }
    return null;
}

$BLOCKS = ['인터로킹 블록', '인조화강블록', '점토블럭'];

echo "[① 보행용 20㎡ 종류 비교 — 대표 규격은 같은 조건 최저가]\n";
$r = search(['query' => '보도블록', 'queries' => $BLOCKS, 'area_m2' => 20]);
$specs = array_map('reg', $r['products'] ?? []);
ok('결과 있음(' . count($specs) . '종)', count($specs) >= 3);
ok('80T 칼라가 대표로 뽑히지 않음', !array_filter($specs, static fn($s) => str_contains($s, '80T_칼라')));
$u = byName($r, '인터로킹U형블럭');
ok('인터로킹U형 대표 = 60T 회색 8,500원/㎡ → 20㎡ 170,000원', $u && reg($u) === '220*110*60T_회색' && $u['est_cost'] === 170000);
$c = byName($r, '점토블럭');
ok('점토블럭 대표 = 720원/개 → 763개 549,360원', $c && $c['price_b2c'] === 720 && $c['suggested_qty'] === 763 && $c['est_cost'] === 549360);
$costs = array_filter(array_column($r['products'] ?? [], 'est_cost'));
ok('최저 예상 자재비 170,000원 (수정 전 218,000원)', $costs && min($costs) === 170000);

echo "[② 차량용 80T 비교 — 조건 유지, 80T 없는 종류는 제외·안내]\n";
$r = search(['query' => '보도블록', 'queries' => $BLOCKS, 'area_m2' => 20, 'spec_mm' => 80]);
$specs = array_map('reg', $r['products'] ?? []);
ok('결과 있음(' . count($specs) . '종)', count($specs) >= 2);
ok('모든 대표가 80T', $specs && !array_filter($specs, static fn($s) => !str_contains($s, '80T')));
ok('76T 점토블럭 제외', byName($r, '점토블럭') === null);
ok('안내에 제외 종류·규격 표시', str_contains((string) ($r['note'] ?? ''), '점토블럭') && str_contains((string) ($r['note'] ?? ''), '80mm'));
$u = byName($r, '인터로킹U형블럭');
ok('인터로킹U형 대표 = 80T 회색 9,600원/㎡', $u && reg($u) === '220*110*80T_회색');

echo "[②' 단일 검색 spec_strict — 다른 두께로 완화하지 않음]\n";
$r = search(['query' => '점토블럭', 'area_m2' => 20, 'spec_mm' => 80, 'spec_strict' => true]);
ok('80T 점토블럭 없음 → 빈 결과(76T 대체 노출 안 함)', empty($r['products']));
ok('안내에 규격 없음을 명시', str_contains((string) ($r['note'] ?? ''), '80mm 제품이 없습니다'));
$r = search(['query' => '점토블럭', 'area_m2' => 20, 'spec_mm' => 80]);
ok('spec_strict 없으면 기존처럼 다른 규격 안내(호환)', !empty($r['products']) && str_contains((string) ($r['note'] ?? ''), '80mm'));

echo "[②'' 포장 블록 엄격 규격 = 두께 (공공도로 100mm 검색)]\n";
$r = search(['query' => '인터로킹 블록', 'area_m2' => 50, 'spec_mm' => 100, 'spec_strict' => true]);
ok('100mm 포장 블록 없음 → 빈 결과(가로 100 블록·호안블록·60T 노출 안 함)', empty($r['products']));
ok('안내에 100mm 제품 없음 명시', str_contains((string) ($r['note'] ?? ''), '100mm 제품이 없습니다'));
$unavail = static fn(array $r): bool => str_contains((string) ($r['note'] ?? ''), '대체품으로 권하지 않습니다') && str_contains((string) ($r['note'] ?? ''), '설계도서')
    && (($r['_sse'][0]['type'] ?? '') === 'spec_unavailable') && (($r['_sse'][0]['spec_mm'] ?? 0) === 100);
ok('얇은 두께 대체 금지·설계도서 확인 안내 + 상담 화면 카드(spec_unavailable)', $unavail($r));
$r = search(['queries' => ['인터로킹 블록', '보차도블록', '점토블럭'], 'area_m2' => 50, 'spec_mm' => 100, 'spec_strict' => true]);
ok('비교 검색도 빈 결과 + 규격 없음 안내', empty($r['products']) && str_contains((string) ($r['note'] ?? ''), '규격 100mm 제품이 없습니다'));
ok('비교 검색도 대체 금지 안내 + 카드', $unavail($r));
$r = search(['query' => '점토블럭', 'area_m2' => 20, 'spec_mm' => 80, 'spec_strict' => true]);
ok('80T 엄격 검색에서 없는 종류: 규격 없음 안내 + 같은 80T 등록 블록 alternatives(적합 상품 없음 카드 없음)', str_contains((string) ($r['note'] ?? ''), '80mm 제품이 없습니다')
    && empty($r['_sse']) && in_array('인터로킹U형블럭', $r['alternatives'] ?? [], true) && !array_filter($r['alternatives'] ?? [], static fn($n) => preg_match('/호안|옹벽|속빈|경계/u', $n)));
$r = search(['queries' => $r['alternatives'] ?? [], 'area_m2' => 20, 'spec_mm' => 80, 'spec_strict' => true]);
ok('alternatives 로 다시 검색하면 80T 상품 표시', !empty($r['products']) && !array_filter($r['products'], static fn($c) => ai_block_thickness((string) $c['spec']) !== 80));
ok('100mm 는 같은 두께 등록 블록 없음(→ 적합 상품 없음 카드 유지)', ai_paving_names_with_thickness(100) === []);
$r = search(['query' => 'PE 배수관', 'spec_mm' => 999, 'spec_strict' => true]);
ok('포장 블록이 아닌 품목은 기존 안내(카드 없음)', empty($r['_sse']));
$r = search(['queries' => ['인터로킹 블록', '인조화강블록', '점토블럭'], 'area_m2' => 30, 'spec_mm' => 80, 'spec_strict' => true]);
ok('80T 비교: 결과 전부 두께 80', !empty($r['products']) && !array_filter($r['products'], static fn($c) => ai_block_thickness((string) $c['spec']) !== 80));
ok('80T 비교: 구조용(호안·축조)·경계 블록 없음', !array_filter(array_column($r['products'], 'name'), static fn($n) => preg_match('/호안|축조|옹벽|속빈|경계/u', $n)));

echo "[③ 경계석 — 부속(집게) 제외, 직선형 대표]\n";
$r = search(['query' => '경계석', 'length_m' => 18]);
$names = array_column($r['products'] ?? [], 'name');
ok('결과 있음', count($names) >= 2);
ok('경계석집게 제외', !in_array('경계석집게', $names, true));
ok('곡선형이 대표로 뽑히지 않음', !array_filter(array_map('reg', $r['products'] ?? []), static fn($s) => str_contains($s, '곡선')));
$r = search(['query' => '경계석 집게']);
ok('집게를 직접 찾으면 노출', in_array('경계석집게', array_column($r['products'] ?? [], 'name'), true));

echo "[기존 동작 유지 — 수로 검색 부속·맨홀 제외]\n";
$r = search(['query' => '측구 수로관', 'length_m' => 50]);
$names = array_column($r['products'] ?? [], 'name');
ok('결과 있음', count($names) >= 1);
ok('맨홀·집수정·뚜껑·집게·몰드 제외', !array_filter($names, static fn($x) => preg_match('/맨홀|집수정|뚜껑|집게|몰드/u', $x)));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
