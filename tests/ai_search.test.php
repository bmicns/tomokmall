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
 * 회귀 대상(2026-11-09 운영 상담 chat_logs 183~188):
 *  ④ 식당 주방(보행자 전용) 폭 500mm → 대형차용 안전중하중만 표에 나옴(하중 등급 traffic 필터·같은 폭 보행자용 대체)
 *  ⑤ 같은 관련도에서 비싼 제품이 앞(6,000원 보행자용이 표 맨 끝) → 예상 자재비 낮은 순
 *  ⑥ '다시 추천'에 같은 표 반복(exclude_ids) ⑦ '날개형' 같이 없는 조건을 찾았다고 말함(missing_terms)
 * 회귀 대상(2026-11-10): ⑧ 폭 500 검색에 폭 400×길이 500 제품이 섞임 — 폭(width_mm)·제품 길이(product_length_mm)는 규격 이름표로 채운 치수 칸 기준.
 *  데이터: 운영 구조 DB(categories·category_spec_axes 운영과 같음, spec-axes-fill 로 치수 칸 채움)에서 실행해야 치수 칸 단언이 통과한다.
 * 실행 예: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=... DB_PASS=... php tests/ai_search.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

ini_set('session.use_cookies', '0');   // CLI 테스트: 세션 쿠키·캐시 헤더를 보내지 않음(장바구니 시험 때 '헤더 이미 전송' 경고 방지)
ini_set('session.cache_limiter', '');
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
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}" . ($detail !== '' ? "  — {$detail}" : '') . "\n"; }
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

echo "[가격 비교 요약 cost_summary — '어떤 게 더 저렴해?' 답변용]\n";
$r = search(['queries' => ['인조화강블록', '점토블럭'], 'area_m2' => 20]);
$cs = $r['cost_summary'] ?? null;
$lowCards = array_values(array_filter($r['products'] ?? [], static fn($c) => !empty($c['cost_lowest'])));
ok('요약: 최저·최고·차이가 카드 예상 금액과 일치', $cs && $cs['lowest']['est_cost'] === min(array_column($r['products'], 'est_cost')) && $cs['difference'] === $cs['highest']['est_cost'] - $cs['lowest']['est_cost']);
ok('카드별 최저와의 차이(cost_diff) = 예상 금액 − 최저', !array_filter($r['products'] ?? [], static fn($c) => ($c['est_cost'] ?? 0) > 0 && ($c['cost_diff'] ?? -1) !== $c['est_cost'] - $cs['lowest']['est_cost']));
$minCost = min(array_filter(array_column($r['products'], 'est_cost')));
ok('최저 금액 카드(동률 포함)에만 cost_lowest 표시', $lowCards !== [] && !array_filter($lowCards, static fn($c) => $c['est_cost'] !== $minCost)
    && count($lowCards) === count(array_filter($r['products'], static fn($c) => ($c['est_cost'] ?? null) === $minCost)) && in_array($cs['lowest']['name'], array_column($lowCards, 'name'), true));
$t = search(['query' => '인터로킹 블록', 'spec_mm' => 80, 'spec_strict' => true, 'area_m2' => 20]);   // 실제 GPT-4o: i2·U 형 192,000원 동률
$tl = array_values(array_filter($t['products'] ?? [], static fn($c) => !empty($c['cost_lowest'])));
ok('최저 동률: 둘 다 최저 표시 + lowest_tied·tie_note(같은 금액이라고 알리게)', count($tl) >= 2 && count($t['cost_summary']['lowest_tied'] ?? []) === count($tl) && str_contains((string) ($t['cost_summary']['tie_note'] ?? ''), '같은 금액'));
ok('면적·길이 없이 검색하면 요약 없음(금액을 말하지 않음)', !isset(search(['queries' => ['인조화강블록', '점토블럭']])['cost_summary']));

echo "['투수' 검색에 '불투수' 섞지 않음]\n";
$r = search(['query' => '투수블록', 'area_m2' => 20]);
ok('투수블록 검색 → 불투수 제품 없음, 투수 제품 있음', !empty($r['products']) && !array_filter($r['products'], static fn($c) => str_contains($c['name'] . $c['spec'], '불투수')));
$r = search(['queries' => ['인조화강블록(투수)', '인조화강블록(불투수)'], 'area_m2' => 20]);
ok("'불투수' 를 직접 찾으면 비교 가능", (bool) array_filter($r['products'] ?? [], static fn($c) => str_contains($c['name'], '불투수')));

echo "[2026-11-09 운영 상담 재현 — 식당 주방(보행자 전용) 배수로 덮개 폭 500mm·10m]\n";
$names  = static fn(array $r): array => array_column($r['products'] ?? [], 'name');
$heavy  = static fn(array $r): array => array_values(array_filter($names($r), static fn($n) => str_contains($n, '중하중')));
$sizes  = static fn(array $r): array => array_map(static fn($c) => (string) ($c['size'] ?? ''), $r['products'] ?? []);
$r = search(['query' => '그레이팅 수로용', 'spec_mm' => 500, 'length_m' => 10]);
ok('수정 전 동작(통행 조건 없음, spec_mm): 500mm 수로용은 대형차용 안전중하중만', $names($r) === ['안전중하중그레이팅수로용']);
$base   = ['query' => '그레이팅 수로용', 'width_mm' => 500, 'length_m' => 10];
$r = search($base + ['traffic' => 'pedestrian']);
ok('보행자 전용: 중하중·안전중하중 없음(' . implode(', ', $names($r)) . ')', $names($r) !== [] && $heavy($r) === []);
ok('보행자 전용: 모두 폭 W 500mm(이름표로 확인) 보행자용 그레이팅', !array_filter($r['products'] ?? [], static fn($c) => ai_grating_load_class($c['name']) !== 'pedestrian' || !str_starts_with((string) ($c['size'] ?? ''), '폭 W 500mm') || ($c['dim_check'] ?? '') !== 'verified'));
$costs = array_column($r['products'] ?? [], 'est_cost');
ok('표는 예상 자재비 낮은 순(첫 줄이 최저)', $costs && !in_array(null, $costs, true) && $costs === array_values(call_user_func(static function ($c) { sort($c); return $c; }, $costs)));
ok('최저 예상 자재비가 수정 전(안전중하중 1,367,000원)보다 낮음 — 500×500 은 길이 L 500 으로 20개 계산(738,000원)', $costs && min($costs) === 738000);
ok('안내: 같은 폭 보행자용으로 바꾼 사실·뺀 대형차용', str_contains((string) ($r['note'] ?? ''), '같은 폭의 보행자용 그레이팅') && in_array('안전중하중그레이팅수로용', $r['over_spec_excluded'] ?? [], true) && ($r['traffic'] ?? '') === 'pedestrian');
$shown = array_column($r['products'] ?? [], 'id');
$r2 = search($base + ['traffic' => 'pedestrian', 'exclude_ids' => $shown]);
ok("'다른 거' (직전 표 제외): 같은 표를 다시 보여 주지 않음(표 없음)", empty($r2['products']) && empty($r2['_sse']));
ok("'다른 거': 다른 제품 없음 + 대형차용만 있음 + 등록된 보행자용 폭 안내", str_contains((string) ($r2['note'] ?? ''), '외에는 이 조건(폭 500mm)에 맞는 다른 제품이 없습니다')
    && str_contains((string) ($r2['note'] ?? ''), '안전중하중그레이팅수로용(대형차용, 136,700원/개부터)') && str_contains((string) ($r2['note'] ?? ''), '그레이팅수로용 150·200·250mm') && ($r2['excluded'] ?? []) !== []);
ok("'다른 거': 고객이 원할 때만 대형차용으로 다시 찾도록 next 안내", str_contains((string) ($r2['next'] ?? ''), "traffic 'truck'"));
$r3 = search(['query' => '그레이팅 수로용 날개형', 'width_mm' => 500, 'length_m' => 10, 'traffic' => 'pedestrian']);
ok("'날개형은 안돼?': 표에 날개형 없음 → 표 없음 또는 missing_terms·등록 폭 안내(플륨관용 날개형 185~385mm)", !in_array('플륨관용(날개형U-TYPE)', $names($r3), true)
    && (str_contains((string) ($r3['note'] ?? ''), '플륨관용(날개형U-TYPE)') || str_contains((string) ($r3['note'] ?? ''), "폭 500mm로 확인된")));
$r3b = search(['query' => '그레이팅 수로용 날개형', 'spec_mm' => 500, 'length_m' => 10, 'traffic' => 'pedestrian']);
ok("(spec_mm 로 찾아도) 표에 날개형이 없으면 missing_terms·등록 규격 안내", ($r3b['missing_terms'] ?? []) === ['날개형'] && str_contains((string) ($r3b['note'] ?? ''), '플륨관용(날개형U-TYPE) 185·285·295·385mm'));
$r4 = search(['query' => '플륨관용 날개형', 'width_mm' => 285, 'length_m' => 10, 'traffic' => 'pedestrian']);
ok('날개형이 있는 폭이면 missing_terms 없음·폭 W 285mm', !empty($r4['products']) && empty($r4['missing_terms']) && str_starts_with($sizes($r4)[0] ?? '', '폭 W 285mm'));

echo "[하중 등급 — 승용차·대형차, 과잉·부족 등급 제외]\n";
$r = search(['query' => '그레이팅 수로용 중하중', 'width_mm' => 300, 'length_m' => 30, 'traffic' => 'car']);
ok('승용차 폭 300mm: 중하중만(수정 전: 안전중하중 2,649,000원이 첫 줄)', $names($r) === ['중하중그레이팅수로용'] && in_array('안전중하중그레이팅수로용', $r['over_spec_excluded'] ?? [], true));
$r = search(['query' => '그레이팅 수로용', 'width_mm' => 300, 'length_m' => 30, 'traffic' => 'truck']);
ok('대형차 폭 300mm: 안전중하중만(보행자·승용차용은 안전 부족이라 제외)', $names($r) === ['안전중하중그레이팅수로용']);
$r = search(['query' => '그레이팅 수로용', 'width_mm' => 500, 'length_m' => 10, 'traffic' => 'car']);
ok('승용차 폭 500mm: 중하중 수로용 없음 → 표 없이 대형차용만 있다고 안내 + 등록 폭(200~400mm)', empty($r['products'])
    && str_contains((string) ($r['note'] ?? ''), "승용차용 '그레이팅 수로용' 제품은 폭 500mm가 등록되어 있지 않습니다") && str_contains((string) ($r['note'] ?? ''), '중하중그레이팅수로용 200·300·350·400mm'));
$r = search(['query' => '그레이팅 수로용', 'width_mm' => 300, 'length_m' => 30]);
ok('통행 조건 불명확(traffic 없음): 등급 필터 없이 같은 폭 전 등급, 낮은 금액 순(중하중 → 안전중하중)', $names($r) === ['중하중그레이팅수로용', '안전중하중그레이팅수로용'] && !isset($r['traffic']));
$r = search(['query' => 'PE 배수관', 'spec_mm' => 100, 'length_m' => 20, 'traffic' => 'pedestrian']);
ok('그레이팅이 아닌 검색은 하중 등급 영향 없음(traffic 표시도 없음)', !empty($r['products']) && !isset($r['traffic']) && !isset($r['over_spec_excluded']));

echo "[2026-11-10 폭·길이 — 이름표로 채운 치수 칸 기준(숫자 위치로 정하지 않음)]\n";
$r = search(['query' => '그레이팅', 'spec_mm' => 500]);
ok('수정 전 동작(spec_mm 500): 폭 400 제품(400×500 의 500 은 길이)도 섞임 — ' . implode(' / ', $sizes($r)), (bool) array_filter($sizes($r), static fn($s) => str_starts_with($s, '폭 W 400mm')));
$r = search(['query' => '그레이팅', 'width_mm' => 500]);
ok('폭만 500: 모두 폭 W 500mm(확인됨), 폭 400×길이 500 제품 없음 — ' . implode(', ', $names($r)), $r['products'] && !array_filter($r['products'], static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), '폭 W 500mm') || $c['dim_check'] !== 'verified'));
$r = search(['query' => '그레이팅 수로용', 'product_length_mm' => 1000, 'length_m' => 10, 'traffic' => 'pedestrian']);
ok('길이만 1000(제품 1개 길이 — 설치 길이 10m 와 구분): 모두 길이 L 1000mm', $r['products'] && !array_filter($sizes($r), static fn($s) => !str_contains($s, '길이 L 1000mm')) && ($r['dim_condition'] ?? '') === '길이 1000mm');
$r = search(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 500]);
ok('폭 500·길이 500 동시(같은 숫자가 두 치수): 모두 폭 W 500mm · 길이 L 500mm', $r['products'] && !array_filter($sizes($r), static fn($s) => !str_starts_with($s, '폭 W 500mm · 길이 L 500mm')));
$r = search(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000]);
ok('폭 500·길이 1000: 500×500 제품 없음', $r['products'] && !array_filter($sizes($r), static fn($s) => !str_starts_with($s, '폭 W 500mm · 길이 L 1000mm')));
$r = search(['query' => '수로관', 'width_mm' => 500, 'length_m' => 20]);
ok('규격 순서가 다른 품목(수로관 폭×깊이×길이): 폭 W 500mm 만(깊이 500 인 폭 600·400 제품 제외)', $r['products'] && !array_filter($sizes($r), static fn($s) => !str_starts_with($s, '폭 W 500mm')));
$r = search(['query' => '흄관', 'product_length_mm' => 2500, 'length_m' => 30]);
ok('내경×길이 품목(흄관): 길이 L 2500mm 만 · 2.5m 로 수량 계산', $r['products'] && !array_filter($r['products'], static fn($c) => !str_contains((string) ($c['size'] ?? ''), '길이 L 2500mm')) && ($r['products'][0]['suggested_qty'] ?? 0) === 12);
$r = search(['query' => '흄관', 'width_mm' => 600]);
ok('폭이 없는 규격 체계(흄관 내경 D600): 폭 600 검색에 흄관을 맞다고 보이지 않음', !array_filter($r['products'] ?? [], static fn($c) => ($c['dim_check'] ?? '') === 'verified' && str_contains((string) ($c['size'] ?? ''), '내경')));
$r = search(['query' => '화산석', 'width_mm' => 150, 'length_m' => 18]);
ok('치수 칸 없는 상품(이름표 없음): 확인 불가 표시·안내, 정확히 맞는 제품이라 하지 않음', $r['products'] && !array_filter($r['products'], static fn($c) => ($c['dim_check'] ?? '') !== 'unverified')
    && str_contains((string) ($r['note'] ?? ''), '폭 150mm로 확인된 제품은 없고') && str_contains((string) ($r['products'][0]['dim_note'] ?? ''), '규격 확인 필요') && str_contains((string) ($r['products'][0]['dim_note'] ?? ''), '확인되지 않았습니다'));
$r = search(['query' => '그레이팅 수로용', 'width_mm' => '50cm', 'length_m' => 10, 'traffic' => 'pedestrian']);
ok("단위가 다른 입력('50cm' → 500mm)", ($r['dim_condition'] ?? '') === '폭 500mm' && $r['products'] && !array_filter($sizes($r), static fn($s) => !str_starts_with($s, '폭 W 500mm')));
$r = search(['query' => '개거', 'width_mm' => '2m', 'length_m' => 6]);
ok("미터 규격(개거 '2.0*…' → 폭 2000mm)·mm 규격(개거수로관 2000)이 모두 폭 2000mm 로 확인·길이 2m 로 6m → 3본", in_array('폭 W 2.0m', array_map(static fn($s) => substr($s, 0, strlen('폭 W 2.0m')), $sizes($r)), true)
    && !array_filter($r['products'] ?? [], static fn($c) => !preg_match('/^폭 W (2\\.0m|2000mm)/u', (string) ($c['size'] ?? '')) || $c['dim_check'] !== 'verified' || $c['suggested_qty'] !== 3));
$r = search(['query' => '그레이팅 수로용', 'width_mm' => 700, 'length_m' => 10, 'traffic' => 'pedestrian']);
ok('조건과 맞는 제품 없음(보행자 폭 700): 표 없음·싼 다른 폭 대신 안내 + 등록 폭', empty($r['products']) && empty($r['_sse'])
    && str_contains((string) ($r['note'] ?? ''), "폭 700mm로 확인된 '그레이팅 수로용' 제품이 없습니다") && str_contains((string) ($r['note'] ?? ''), '그레이팅수로용 150·200·250mm') && !str_contains((string) ($r['note'] ?? ''), '맨홀'));
$r = search(['query' => '측구 수로관', 'width_mm' => 500, 'length_m' => 50]);
ok('그레이팅 외 품목(측구 수로관 폭 500·50m): 폭 W 500mm·25본', $r['products'] && !array_filter($r['products'], static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), '폭 W 500mm') || $c['suggested_qty'] !== 25));
$r = search(['queries' => ['그레이팅 수로용', '디자인그레이팅 수로용'], 'width_mm' => 200, 'length_m' => 10, 'traffic' => 'pedestrian']);
ok('비교 검색(queries)도 폭 조건: 모두 폭 W 200mm', $r['products'] && !array_filter($sizes($r), static fn($s) => !str_starts_with($s, '폭 W 200mm')));

echo "[가격 순 — 같은 관련도면 예상 자재비 낮은 순]\n";
$r = search(['query' => '그레이팅 수로용', 'length_m' => 10]);
$c0 = $r['products'][0] ?? [];
ok('폭 미지정 10m: 첫 줄 = 그레이팅수로용 150×1000×25 6,000원 → 60,000원(수정 전 표 맨 끝)', ($c0['name'] ?? '') === '그레이팅수로용' && ($c0['est_cost'] ?? 0) === 60000);
$costs = array_column($r['products'] ?? [], 'est_cost');
ok('예상 자재비 오름차순', $costs === array_values(call_user_func(static function ($c) { sort($c); return $c; }, $costs)));
$one = search(['query' => '그레이팅 수로용', 'width_mm' => 200, 'length_m' => 10]);
$exId = (int) ($one['products'][0]['id'] ?? 0);
$exName = (string) ($one['products'][0]['name'] ?? '');
$r = search(['query' => '그레이팅 수로용', 'width_mm' => 200, 'length_m' => 10, 'exclude_ids' => [$exId]]);
ok("exclude_ids: 그 상품명은 다른 규격도 빠지고 나머지는 그대로({$exName} 제외)", $exId > 0 && !in_array($exName, $names($r), true) && $names($r) !== [] && in_array($exName, $r['excluded'] ?? [], true));

echo "[2026-11-11 하중 판정 — 상품명 등급·규격 용도 표기(차도용·보도용)만 근거, 근거 없으면 '하중 확인 필요'(오추천 방지)]\n";
$rKeep = $r;   // 아래 견적 담기 테스트가 앞 검색 결과($r)를 씀
$lc = static fn(array $r): array => array_map(static fn($c) => $c['name'] . ':' . ($c['load_check'] ?? '-'), $r['products'] ?? []);
$r = search(['query' => '맨홀 뚜껑', 'traffic' => 'car']);
ok('승용차: 보도(우수)·보도용 규격 뚜껑은 표에 없음', $r['products'] && !array_filter($r['products'], static fn($c) => preg_match('/보도/u', (string) ($c['spec_label'] ?? $c['spec'] ?? '')) === 1), implode(', ', $lc($r)));
ok('승용차: 차도용 규격 뚜껑은 하중 확인됨(근거: 규격 표기) — 확인된 제품이 먼저', ($r['products'][0]['load_check'] ?? '') === 'verified' && str_contains((string) ($r['products'][0]['load_basis'] ?? ''), '규격 표기(차도용)'));
ok("승용차: 근거 없는 뚜껑은 '하중 확인 필요'(맞다고 하지 않음) + 안내", (bool) array_filter($r['products'], static fn($c) => ($c['load_check'] ?? '') === 'unverified' && str_contains((string) ($c['load_note'] ?? ''), '하중 확인 필요')) && str_contains((string) ($r['note'] ?? ''), '하중 확인 필요'));
$r = search(['query' => '주철뚜껑', 'traffic' => 'truck']);
ok("대형차: '차도용'은 톤수가 없어 확인 필요(대형차에 맞다고 하지 않음) · 확인된 제품 없음 안내", !array_filter($r['products'] ?? [], static fn($c) => ($c['load_check'] ?? '') === 'verified') && str_contains((string) ($r['note'] ?? ''), '확인된 제품은 없습니다'), implode(', ', $lc($r)));
$r = search(['query' => '주철뚜껑', 'traffic' => 'pedestrian']);
ok('보행자: 보도용 규격이 대표(차도용은 과잉 등급이라 뺌), 하중 표시 없음', ($r['products'] && !array_filter($r['products'], static fn($c) => str_contains((string) $c['name'], '주철뚜껑') && !str_contains((string) ($c['spec_label'] ?? $c['spec'] ?? ''), '보도'))) && !array_filter($r['products'], static fn($c) => isset($c['load_check'])), implode(', ', $lc($r)));
$r = search(['query' => '수로관', 'width_mm' => 500, 'product_length_mm' => 2000, 'length_m' => 20, 'traffic' => 'car']);
ok('수로관 + 승용차: 하중 근거가 없어 모두 확인 필요, 수량은 그대로(2m → 10본)', $r['products'] && !array_filter($r['products'], static fn($c) => ($c['load_check'] ?? '') !== 'unverified' || ($c['suggested_qty'] ?? 0) !== 10), implode(', ', $lc($r)));
$r = search(['query' => '수로관', 'width_mm' => 500, 'product_length_mm' => 2000, 'traffic' => 'pedestrian']);
ok('수로관 + 보행자: 하중 판정 표시 없음(필터 안 함)', $r['products'] && !array_filter($r['products'], static fn($c) => isset($c['load_check'])));
$r = search(['query' => '보도블록', 'traffic' => 'car', 'area_m2' => 20]);
ok('블록 + 승용차: 하중 판정에 넣지 않음(블록 차량 기준은 80T 규칙 — 그대로)', $r['products'] && !array_filter($r['products'], static fn($c) => isset($c['load_check'])));
$r = search(['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car']);
ok('그레이팅 폭 400·길이 1000 승용차: 중하중(상품명 등급)만, 보행자용·과잉 등급 없음', $names($r) === ['중하중그레이팅수로용'] && ($r['products'][0]['load_check'] ?? '') === 'verified' && in_array('안전중하중그레이팅수로용', $r['over_spec_excluded'] ?? [], true));
$r = search(['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car', 'recheck_ids' => [738, 753, 768]]);
$rk = array_column($r['recheck'] ?? [], 'status', 'id');
ok('앞서 보인 제품 재확인(recheck_ids): EX메탈망 하중 부족 · 중하중 맞음 · 안전중하중 과잉', ($rk[738] ?? '') === 'load_lighter' && ($rk[753] ?? '') === 'ok' && ($rk[768] ?? '') === 'load_heavier', json_encode($r['recheck'] ?? null, JSON_UNESCAPED_UNICODE));
$r = search(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000, 'traffic' => 'car', 'recheck_ids' => [741]]);
ok('표가 비어도(폭 500 승용차용 없음) 재확인 결과·이유를 함께 알림', empty($r['products']) && ($r['recheck'][0]['status'] ?? '') === 'load_lighter' && str_contains((string) ($r['note'] ?? ''), '이번 조건에 맞지 않아'));
$r = $rKeep;
echo "[2026-11-12 규격 미확인 상품 — 확인된 제품 뒤, '규격 확인 필요' 표기, 확인된 것처럼 표시하지 않음]\n";
$rKeep = $r;
$r = search(['query' => '덮개', 'width_mm' => 300]);
$st = array_column($r['products'] ?? [], 'dim_check');
$firstUnv = array_search('unverified', $st, true);
ok('폭 300 덮개: 확인된 제품(verified)이 모두 미확인(unverified) 앞', $firstUnv !== false && in_array('verified', $st, true) && !in_array('verified', array_slice($st, (int) $firstUnv), true), json_encode($st));
ok("미확인 제품: dim_note '규격 확인 필요', 확인된 제품엔 dim_note 없음", !array_filter($r['products'], static fn($c) => ($c['dim_check'] === 'unverified') !== str_contains((string) ($c['dim_note'] ?? ''), '규격 확인 필요')));
ok("GPT 안내: 미확인 제품 이름과 '규격 확인 필요'", str_contains((string) ($r['note'] ?? ''), '규격 확인 필요') && str_contains((string) ($r['note'] ?? ''), (string) ($r['products'][(int) $firstUnv]['name'] ?? '-')));
$r = search(['query' => '그레이팅', 'exclude_ids' => array_column(search(['query' => '그레이팅'])['products'] ?? [], 'id')]);
ok("넓은 검색어('그레이팅')에서 '다른 제품': 앞 표 이름을 뺀 다른 그레이팅이 나옴(위 40행만 보고 '없음'으로 끝나지 않음)", ($r['products'] ?? []) !== [] && !array_intersect(array_column($r['products'], 'name'), $r['excluded'] ?? []));
$r = $rKeep;

echo "[견적 담기 수량 1~999 — AI add_to_cart]\n";
$pid = (int) ($r['products'][0]['id'] ?? 0);
$add = static fn(int $q): array => json_decode(tool_add_to_cart(['product_id' => $pid, 'quantity' => $q], 'test'), true) ?: [];
$_SESSION['cart'] = [];
ok('1,000개는 담지 않음(1~999 안내)', ($add(1000)['error'] ?? '') === '수량은 1~999 범위여야 합니다.' && empty($_SESSION['cart'][$pid]));
ok('999개는 담김', ($add(999)['quantity'] ?? 0) === 999);
ok('이미 999개면 1개 더 담기 거부(합계 999 초과)', isset($add(1)['error']) && (cart_session_read()[$pid] ?? 0) === 999);
$_SESSION['cart'] = [];

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
