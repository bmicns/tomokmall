<?php
declare(strict_types=1);
/**
 * 순수함수 단위 테스트 (프레임워크 없이). DB 불필요.
 * 실행: php tests/functions.test.php
 * 검색 분해/동의어/수량계산/하이라이트/규격파싱 회귀 방지용.
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/ai_tools.php';
require __DIR__ . '/../app/product_filter_renderer.php';
require __DIR__ . '/../app/favorites.php';
require __DIR__ . '/../app/work_types.php';
require __DIR__ . '/../app/corp_home.php';
require_once __DIR__ . '/../app/rag.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
function eq(string $name, $a, $b): void {
    ok($name . '  =>  ' . json_encode($a, JSON_UNESCAPED_UNICODE), $a == $b);
}

echo "[복합어 분해 segment_compound]\n";
eq('PE배수관',        segment_compound('PE배수관'),        ['PE', '배수관']);
eq('콘크리트수로관',  segment_compound('콘크리트수로관'),  ['콘크리트', '수로관']);
eq('중하중그레이팅수로용', segment_compound('중하중그레이팅수로용'), ['중하중그레이팅', '수로용']);
eq('그레이팅(미분해)', segment_compound('그레이팅'),        ['그레이팅']);
eq('미지어(미분해)',   segment_compound('알수없는말xyz'),   ['알수없는말xyz']);

echo "[소요수량 ai_estimate_quantity]\n";
[$q] = ai_estimate_quantity(['spec' => '규격: 400*400*2000*70T | 단위: 본'], 100.0, null, null);
ok('수로관 2m → 100m=50본', $q === 50);
[$q] = ai_estimate_quantity(['spec' => '규격: 150*1000*25 | 단위: 개'], 100.0, null, null);
ok('그레이팅 1m → 100m=100개', $q === 100);
[$q] = ai_estimate_quantity(['spec' => '규격: 120*120*300 | 단위: 개'], 100.0, null, null);
ok('경계석(길이<1m) → 수량없음(null)', $q === null);
[$q] = ai_estimate_quantity(['spec' => '단위: m 2'], 0.0, null, 200.0);
ok('면적 200㎡ → 200', $q === 200);

echo "[포장 블록 장수 환산 ai_tile_area_m2 / ai_estimate_quantity]\n";
ok('인터로킹 220*110*60T → 0.0242㎡', abs((float) ai_tile_area_m2('220*110*60T_회색') - 0.0242) < 1e-9);
ok('경계석 120*120*120(T 없음) → null', ai_tile_area_m2('120*120*120(SA)') === null);
ok('다칼라 900*1200*60(T 없음) → null', ai_tile_area_m2('900*1200*60_컬러(보도)') === null);
ok('조경잔디 1000*1000*150T(600mm 초과) → null', ai_tile_area_m2('1000*1000*150T') === null);
[$q, $b] = ai_estimate_quantity(['spec' => '규격: 220*110*60T_회색 | 단위: m 2'], 0.0, null, 200.0);
ok('인터로킹(㎡) 200㎡ → 200㎡', $q === 200);
ok('인터로킹(㎡) 근거에 장수 표시(약 8,265장)', strpos($b, '약 8,265장') !== false);
[$q] = ai_estimate_quantity(['spec' => '규격: 230*114*60T_아이보리 | 단위: 개'], 0.0, null, 10.0);
ok('점토블럭(개) 10㎡ → 382개', $q === 382);
[$q] = ai_estimate_quantity(['spec' => '규격: 120*120*120(SA) | 단위: 개'], 0.0, null, 10.0);
ok('경계석(개) 면적만 → 수량없음(null)', $q === null);
[$q] = ai_estimate_quantity(['spec' => '규격: 230*114*60T_아이보리 | 단위: 개'], 100.0, null, null);
ok('점토블럭(개) 길이만 → 수량없음(null)', $q === null);
eq('단위 라벨 m 2 → ㎡', ai_unit_label('규격: 220*110*60T | 단위: m 2'), '㎡');
eq('단위 라벨 본',        ai_unit_label('규격: 400*400*2000 | 단위: 본'), '본');
eq('단위 라벨 없음',      ai_unit_label('220*110*60T'), '');

echo "[AI 검색 대표 규격·부속 제외 — 실제 수집 상품(data/ktomok/products.json)]\n";
// 운영 DB 적재 형식과 같게 '규격: … | 단위: …'로 구성. 상품 순서는 수집 순서(관련도 동점 시 기존엔 id가 큰 변형이 대표가 됨)
$ktomok = json_decode((string) file_get_contents(__DIR__ . '/../data/ktomok/products.json'), true) ?: [];
$realRows = static function (string $category, ?string $specRe = null) use ($ktomok): array {
    $rows = [];
    foreach ($ktomok as $i => $p) {
        if ($p['categoryName'] !== $category || ($specRe !== null && !preg_match($specRe, $p['spec']))) { continue; }
        $rows[] = ['id' => $i + 1, 'name' => $p['name'], 'spec' => "규격: {$p['spec']} | 단위: {$p['unit']}", 'price_b2c' => (int) $p['price']];
    }
    return array_reverse($rows); // 검색 정렬(p.id DESC) 재현: 마지막 변형(80T 칼라 등)이 먼저
};
$specOf = static fn(array $r): string => preg_match('/규격:\s*([^|]+)/u', $r['spec'], $m) ? trim($m[1]) : '';

$il = $realRows('인터로킹U형블럭');
ok('실데이터 로드: 인터로킹U형블럭 6종', count($il) === 6);
$rep = ai_pick_representatives($il, 0.0, null, 20.0);
eq('보행용(두께 조건 없음) 대표 = 가장 저렴한 60T 회색', $specOf($rep[0]), '220*110*60T_회색');
$rep = ai_pick_representatives($realRows('인터로킹U형블럭', '/\*80T/'), 0.0, 80.0, 20.0);
eq('차량용 80T만 남은 경우 대표 = 80T 회색', $specOf($rep[0]), '220*110*80T_회색');
$rep = ai_pick_representatives($realRows('인조화강블록(투수)'), 0.0, null, 20.0);
ok('인조화강블록(투수) 대표 = 60T 최저가(9,900원/㎡, 200×200·200×100 동가)', (int) $rep[0]['price_b2c'] === 9900 && strpos($specOf($rep[0]), '60T') !== false);
$rep = ai_pick_representatives($realRows('점토블럭'), 0.0, null, 20.0);
eq('점토블럭 대표 = 개당 가장 싼 레드 계열(720원)', (int) $rep[0]['price_b2c'], 720);
ok('점토블럭 80T 규격 없음(차량용 비교에서 제외돼야 함)', $realRows('점토블럭', '/\*80T/') === []);
$curb = array_merge($realRows('경계석집게'), $realRows('콘크리트경계석'));
$names = array_unique(array_column(ai_exclude_accessories($curb, '경계석'), 'name'));
ok('경계석 검색 → 경계석집게 제외', !in_array('경계석집게', $names, true) && in_array('콘크리트경계석', $names, true));
$names = array_unique(array_column(ai_exclude_accessories($curb, '경계석 집게'), 'name'));
ok('고객이 집게를 직접 찾으면 유지', in_array('경계석집게', $names, true));
$rep = ai_pick_representatives($realRows('콘크리트경계석'), 18.0, null, null);
ok('경계석 대표는 직선형(곡선 아님)', strpos($specOf($rep[0]), '곡선') === false);
ok('집게만 있으면 원복(빈 결과 방지)', count(ai_exclude_accessories($realRows('경계석집게'), '경계석')) === count($realRows('경계석집게')));

echo "[공식 법령 근거 — 점수·프롬프트·답변 출처 처리 (순수 함수)]\n";
$lawDoc = ['id' => 7, 'tags' => '주차,주차장,주차공간', 'category_key' => 'pavement', 'source_type' => 'law',
    'source_name' => '주차장법 시행규칙', 'source_ref' => '제3조제1항제2호', 'effective_date' => '2026-07-27',
    'applies_to' => '주차장법상 주차장', 'source_url' => 'https://www.law.go.kr/법령/주차장법시행규칙/제3조',
    'verify_level' => 'original_verified', 'content' => '요지: 일반형 2.5m×5.0m 이상'];
eq('태그 2개 일치(주차·주차공간) +6, 카테고리 +1', rag_legal_score($lawDoc, '마당 주차공간 포장', 'pavement'), 3 + 3 + 1);
eq('태그 불일치면 카테고리만으로는 0', rag_legal_score($lawDoc, '보도블록 두께', 'pavement'), 0);
$sec = rag_legal_prompt_section([$lawDoc]);
ok('프롬프트 구역: [L7]·적용 조건·시행일', str_contains($sec, '[L7]') && str_contains($sec, '적용 조건: 주차장법상 주차장') && str_contains($sec, '시행 2026-07-27'));
eq('근거 없으면 프롬프트 구역 없음', rag_legal_prompt_section([]), '');
$cit = rag_extract_legal_citations('1칸은 2.5m×5.0m 이상입니다 [L7]. 참고[L7] 추가[L3]', [$lawDoc]);
eq('표시 제거', $cit['text'], '1칸은 2.5m×5.0m 이상입니다. 참고 추가');
eq('넣은 근거만·중복 없이', array_column($cit['sources'], 'id'), [7]);
eq('표시 없으면 출처 없음', rag_extract_legal_citations('80T를 권해 드려요.', [$lawDoc])['sources'], []);
eq('DB·출처 칸 없으면(운영 미적용) 법령 근거 검색 빈 배열', rag_search_legal_documents('주차 공간', 'pavement'), []);

echo "[하이라이트 highlight_terms]\n";
ok('검색어 <mark> 삽입', strpos(highlight_terms('측구수로관', ['측구']), '<mark') !== false);
ok('HTML 이스케이프',    strpos(highlight_terms('<b>x</b>', []), '&lt;') !== false);

echo "[규격 파싱 parse_spec_field]\n";
$sp = parse_spec_field('규격: 400*400 | 단위: 본 | 중량: 100kg');
ok('규격 추출', $sp['spec'] === '400*400');
ok('단위 추출', $sp['unit'] === '본');

echo "[중량 파싱 freight_unit_kg — 천단위 쉼표]\n";
eq('950kg',            freight_unit_kg('950kg'), 950.0);
eq('2,640kg',          freight_unit_kg('2,640kg'), 2640.0);
eq('12,345kg',         freight_unit_kg('12,345kg'), 12345.0);
eq('0.5 kg',           freight_unit_kg('0.5 kg'), 0.5);
eq('빈 값 → 0',        freight_unit_kg(''), 0.0);
eq('kg 표기 없음 → 0', freight_unit_kg('문의'), 0.0);
eq('0kg → 0',          freight_unit_kg('0kg'), 0.0);
// 변경 전 정규식은 쉼표 앞을 버려 2,640kg → 640kg 로 읽었다(회귀 방지용 기록)
$oldKg = (function (string $w): float { return preg_match('/([0-9]+(?:\.[0-9]+)?)\s*kg/i', $w, $m) ? (float) $m[1] : 0.0; });
ok('변경 전 2,640kg → 640 (버그 재현)', $oldKg('2,640kg') === 640.0);
// 1련암거(2,640kg) 2본, 경기→경기: 변경 전 1,280kg=2.5톤 10만원 → 변경 후 5,280kg=8톤 15만원
ok('변경 전 운임 2.5톤 100,000원', freight_estimate('경기', '경기', $oldKg('2,640kg') * 2)['total'] === 100000);
ok('변경 후 운임 8톤 150,000원',   freight_estimate('경기', '경기', freight_unit_kg('2,640kg') * 2)['total'] === 150000);

echo "[견적 합산 운임 freight_estimate_lines]\n";
$r = freight_estimate_lines([
    ['unit_kg' => 600.0, 'qty' => 1, 'origin' => '경기'],
    ['unit_kg' => 300.0, 'qty' => 2, 'origin' => '경기'],
], '경기');
ok('총중량 1,200kg 1회 배차 = 2.5톤 100,000원(상품별 6만+6만 합산 아님)', $r['status'] === 'ok' && $r['freight'] === 100000 && $r['total_kg'] === 1200.0);
$r = freight_estimate_lines([['unit_kg' => 120.0, 'qty' => 10, 'origin' => '경기']], '서울');
ok('경기→서울 1,200kg = 2.5톤(≤60km) 100,000원', $r['status'] === 'ok' && $r['freight'] === 100000);
$r = freight_estimate_lines([
    ['unit_kg' => 600.0, 'qty' => 1, 'origin' => '경기'],
    ['unit_kg' => 0.0,   'qty' => 3, 'origin' => '경기'],
], '경기');
ok('중량 없는 품목 포함 → missing_weight, 금액 없음', $r['status'] === 'missing_weight' && $r['missing'] === 1 && !isset($r['freight']));
ok('제주 → negotiate', freight_estimate_lines([['unit_kg' => 100.0, 'qty' => 1, 'origin' => '경기']], '제주')['status'] === 'negotiate');
ok('미지 지역 → invalid', freight_estimate_lines([['unit_kg' => 100.0, 'qty' => 1, 'origin' => '경기']], '없는시도')['status'] === 'invalid');
ok('품목 없음 → invalid', freight_estimate_lines([], '경기')['status'] === 'invalid');
$r = freight_estimate_lines([
    ['unit_kg' => 800.0, 'qty' => 1, 'origin' => '경기'],
    ['unit_kg' => 800.0, 'qty' => 1, 'origin' => '서울'],
], '서울');
ok('출하지 2곳 → 출하지별 배차 합산(경기→서울 44km 1톤 7만 + 서울→서울 1톤 6만)', $r['status'] === 'ok' && $r['freight'] === 130000 && count($r['origins']) === 2);

echo "[견적 금액 요약 estimate_amount_summary]\n";
$s = estimate_amount_summary(404400, 30000);
ok('404,400 + 운임 30,000 → 부가세 43,440 / 최종 477,840', $s['taxable'] === 434400 && $s['vat'] === 43440 && $s['final'] === 477840 && $s['freight_included']);
$s = estimate_amount_summary(218600, null);
ok('운임 미산출 218,600 → 부가세 21,860 / 최종 240,460(기존 화면과 동일)', $s['vat'] === 21860 && $s['final'] === 240460 && !$s['freight_included']);
ok('부가세 원 단위 반올림(1,005 → 101)', estimate_amount_summary(1005, null)['vat'] === 101);
ok('부가세 원 단위 반올림(1,004 → 100)', estimate_amount_summary(1004, null)['vat'] === 100);
ok('운임 0원도 산출로 간주(freight_included)', estimate_amount_summary(1000, 0)['freight_included'] === true);

echo "[신청 수량 대조 estimate_items_match]\n";
$sess = [['product_id' => 1, 'quantity' => 2], ['product_id' => 5, 'quantity' => 4]];
ok('동일(순서 무관) → true',  estimate_items_match($sess, [['product_id' => 5, 'quantity' => 4], ['product_id' => 1, 'quantity' => 2]]));
ok('수량 다름 → false',       !estimate_items_match($sess, [['product_id' => 1, 'quantity' => 3], ['product_id' => 5, 'quantity' => 4]]));
ok('품목 누락 → false',       !estimate_items_match($sess, [['product_id' => 1, 'quantity' => 2]]));
ok('품목 추가 → false',       !estimate_items_match($sess, [['product_id' => 1, 'quantity' => 2], ['product_id' => 5, 'quantity' => 4], ['product_id' => 9, 'quantity' => 1]]));
ok('중복 product_id → false', !estimate_items_match($sess, [['product_id' => 1, 'quantity' => 2], ['product_id' => 1, 'quantity' => 2], ['product_id' => 5, 'quantity' => 4]]));
ok('형식 오류 → false',       !estimate_items_match($sess, ['x', 'y']));

echo "[자모 편집거리 jamo_distance]\n";
ok('그레이팅~그래이팅 = 1', jamo_distance('그레이팅', '그래이팅') === 1);
ok('측구~칙구 = 1',        jamo_distance('측구', '칙구') === 1);
ok('측구~그레이팅 크다(>4)', jamo_distance('측구', '그레이팅') > 4);
ok('동일어 = 0',           jamo_distance('수로관', '수로관') === 0);
ok('자모분해(측구)',       implode('', hangul_to_jamo('측구')) === 'ㅊㅡㄱㄱㅜ');

echo "[오타 교정 suggest_correction]\n";
ok('그래이팅 → 그레이팅', (suggest_correction('그래이팅')['to'] ?? '') === '그레이팅');
ok('칙구 → 측구',         (suggest_correction('칙구')['to'] ?? '') === '측구');
ok('측구수로 → 측구수로관', (suggest_correction('측구수로')['to'] ?? '') === '측구수로관');
ok('정확어(그레이팅) 교정 없음', suggest_correction('그레이팅') === null);
ok('무의미어 교정 없음',   suggest_correction('알수없는긴말xyz') === null);
ok('보강토블럭 → 보강토블록', (suggest_correction('보강토블럭')['to'] ?? '') === '보강토블록');

echo "[운임 엔진 freight]\n";
ok('동일시도 거리 25km',      freight_road_km('대전','대전') === 25.0);
ok('경기→서울 근~중거리',    (function(){ $k=freight_road_km('경기','서울'); return $k>20 && $k<80; })());
ok('미지 시도 null',          freight_road_km('경기','없는시도') === null);
ok('800kg→1톤 1대',          (function(){ $t=freight_pick_trucks(800); return count($t)===1 && FREIGHT_TON_LABEL[$t[0]['col']]==1; })());
ok('12000kg→25톤(11톤초과)', (function(){ $t=freight_pick_trucks(12000); return FREIGHT_TON_LABEL[$t[0]['col']]==25; })());
ok('26000kg→25톤+1톤',       (function(){ $t=freight_pick_trucks(26000); return count($t)===2 && FREIGHT_TON_LABEL[$t[1]['col']]==1; })());
ok('서울내 0.8t 운임=6만원',  (function(){ $r=freight_estimate('서울','서울',800); return $r['ok'] && $r['total']===60000; })());
ok('경기→부산 19t=58만원',    (function(){ $r=freight_estimate('경기','부산',19000); return $r['ok'] && $r['total']===580000; })());
ok('중량0 운임 실패',         freight_estimate('서울','부산',0)['ok'] === false);

echo "[재고 필터 product_filter_sql instock]\n";
$fp = [];
ok('instock=true → stock_qty > 0 조건', product_filter_sql(['instock' => true], $fp) === ' AND p.stock_qty > 0' && $fp === []);
$fp = [];
ok('instock=false → 조건 없음(전체)', product_filter_sql(['instock' => false], $fp) === '');
$fp = [];
ok('instock 미지정 → 기존과 동일(조건 없음)', product_filter_sql([], $fp) === '');
$fp = [];
$sql = product_filter_sql(['shape' => ['원형'], 'instock' => true], $fp);
ok('형상+재고 동시 적용(AND)', $sql === ' AND (p.name LIKE :fsh0) AND p.stock_qty > 0' && $fp === [':fsh0' => '%원형%']);

echo "[목록검색 product_list_search_sql — 개수·페이지와 일치하도록 SQL로 거름]\n";
$fp = [];
ok('빈 목록검색 → 조건 없음', product_list_search_sql('  ', $fp) === '' && $fp === []);
// 업체 테이블(DB 연결 시)이 있으면 업체명 조건(:ls4)이 추가된다 → DB 유무와 무관하게 검증
$vsel = vendor_names_subquery();
$fp = [];
$sql = product_list_search_sql(' 원형 ', $fp);
$expSql = ' AND (p.name LIKE :ls0 OR p.spec LIKE :ls1 OR p.brand LIKE :ls2 OR p.description LIKE :ls3'
    . ($vsel !== 'NULL' ? " OR {$vsel} LIKE :ls4" : '') . ')';
$expFp  = array_fill_keys([':ls0', ':ls1', ':ls2', ':ls3'] + ($vsel !== 'NULL' ? [4 => ':ls4'] : []), '%원형%');
ok('품명·규격·브랜드·설명(+업체명) OR 조건', $sql === $expSql && $fp === $expFp);
$fp = [];
product_list_search_sql('50%_a', $fp);
eq('LIKE 와일드카드 이스케이프', $fp[':ls0'], '%50\\%\\_a%');
$fp = [':fsh0' => '%원형%'];
$sql = product_filter_sql(['shape' => ['원형']], $fp) . product_list_search_sql('PE', $fp);
ok('필터와 함께 쓰면 파라미터 병합(키 충돌 없음)', count($fp) === ($vsel !== 'NULL' ? 6 : 5) && str_contains($sql, ':fsh0') && str_contains($sql, ':ls0'));

echo "[목록 URL product_list_url]\n";
eq('재고 해제 시 instock 제거·페이지 초기화',
    product_list_url(['q' => '수로관', 'instock' => '1', 'p' => '3', 'sort' => 'price_asc'], ['instock' => null]),
    '/products.php?q=%EC%88%98%EB%A1%9C%EA%B4%80&sort=price_asc');
eq('페이지 이동 시 검색·정렬·필터·재고 유지',
    product_list_url(['q' => 'a', 'sort' => 'newest', 'shape' => ['원형'], 'instock' => '1', 'p' => '1'], ['p' => 2]),
    '/products.php?q=a&sort=newest&shape%5B0%5D=%EC%9B%90%ED%98%95&instock=1&p=2');
eq('칩 개별 해제(빈 배열 → 키 제거)',
    product_list_url(['category' => '5', 'mat' => ['11']], ['mat' => []]),
    '/products.php?category=5');

echo "[사이드바 재고 섹션 render_product_filters]\n";
$renderFlt = static function (array $facets, bool $inStock): string {
    ob_start();
    render_product_filters(['spec' => [], 'shape' => []], null, 9101, 'price_asc', $facets, $inStock);
    return (string) ob_get_clean();
};
$fct  = ['mat' => [], 'shape' => ['사각' => 3, '원형' => 2], 'load' => [], 'spec' => ['300*300' => 2, '400*400' => 3], 'total' => 5];
$html = $renderFlt($fct, false);
$pos  = static fn(string $needle) => mb_strpos($html, $needle);
ok('순서: 규격 → 형상/옵션 → 구분선 → 재고 → 필터 적용',
    $pos('규격') < $pos('형상 / 옵션') && $pos('형상 / 옵션') < $pos('flt-div')
    && $pos('flt-div') < $pos('<b>재고</b>') && $pos('<b>재고</b>') < $pos('flt-apply'));
ok('재고 체크박스 name="instock" value="1"(배열 아님)', strpos($html, 'type="checkbox" name="instock" value="1"') !== false);
ok('즉시 제출(onchange submit) 없음', stripos($html, 'onchange') === false && strpos($html, '.submit()') === false);
ok('미적용 상태: 체크 해제', !preg_match('/name="instock" value="1"\s+checked/', $html));
ok('정렬 조건 유지(hidden sort)', strpos($html, 'name="sort" value="price_asc"') !== false);
$html2 = $renderFlt($fct, true);
ok('적용 상태: 체크됨 + hidden instock 중복 없음',
    preg_match('/name="instock" value="1"\s+checked/', $html2) === 1 && substr_count($html2, 'name="instock"') === 1);
$html3 = $renderFlt(['mat' => [], 'shape' => [], 'load' => [], 'spec' => [], 'total' => 0], false);
ok('파세트 없어도 재고 섹션 표시(구분선 없음)', strpos($html3, '<b>재고</b>') !== false && strpos($html3, 'flt-div') === false);

echo "[관심상품 다중 추가 favorite_parse_ids / favorite_bulk_plan]\n";
eq('정수·숫자문자열 허용, 중복 제거(순서 유지)', favorite_parse_ids([3, '5', 3, 7]), [3, 5, 7]);
ok('빈 배열 거부', favorite_parse_ids([]) === null);
ok('배열 아님 거부', favorite_parse_ids('1,2') === null && favorite_parse_ids(null) === null);
ok('0·음수·소수·문자 거부', favorite_parse_ids([1, 0]) === null && favorite_parse_ids([-2]) === null
    && favorite_parse_ids([1.5]) === null && favorite_parse_ids(['1a']) === null && favorite_parse_ids(['01']) === null);
ok('1회 최대 개수 초과 거부', favorite_parse_ids(range(1, FAVORITES_BULK_MAX + 1)) === null
    && count(favorite_parse_ids(range(1, FAVORITES_BULK_MAX)) ?? []) === FAVORITES_BULK_MAX);
$pl = favorite_bulk_plan([1, 2, 3, 4], [1, 2, 3], [2], 10);
eq('신규만 추가, 기존은 already(유지), 판매중 아님은 invalid', $pl,
    ['add' => [1, 3], 'already' => [2], 'failed' => [['product_id' => 4, 'reason' => 'invalid']]]);
$pl = favorite_bulk_plan([1, 2, 3], [1, 2, 3], [], FAVORITES_MAX - 1);
ok('한도 50: 남은 1개만 추가, 나머지 limit', $pl['add'] === [1]
    && array_column($pl['failed'], 'reason') === ['limit', 'limit']);
$pl = favorite_bulk_plan([5, 6], [5, 6], [5], FAVORITES_MAX);
ok('한도 도달 상태: 이미 등록된 상품은 already(실패 아님)', $pl['already'] === [5] && $pl['add'] === []
    && $pl['failed'] === [['product_id' => 6, 'reason' => 'limit']]);
ok('최대 개수 상수 50 유지', FAVORITES_MAX === 50);

echo "[작업 유형 설정 work_types_validate]\n";
$wt = work_types();
eq('현재 설정 규칙 위반 없음', work_types_validate($wt), []);
ok('작업당 대표 자재 최대 10개', max(array_map(static fn($t) => count($t['materials']), $wt)) <= 10);
$allIds = [];
foreach ($wt as $t) { $allIds = array_merge($allIds, array_column($t['materials'], 0)); }
ok('PE망(203)·몰드(215~229) 미연결', !array_intersect($allIds, WORK_TYPE_EXCLUDED_CATEGORY_IDS) && count(WORK_TYPE_EXCLUDED_CATEGORY_IDS) === 16);
ok('대분류(1~8, 11~16) 미연결', !array_intersect($allIds, WORK_TYPE_STRUCTURE_CATEGORY_IDS));
$names = array_column($wt, 'name');
ok('작업 유형 12개(메인 전부 노출), 첫 작업은 배수로 설치·정비', count($wt) === 12 && $names[0] === '배수로 설치·정비');
ok('통합 전 작업명 없음(배수시설·노면배수·지하배수관·집수정·횡단·안전시설·담장·급수 등)',
    !array_intersect($names, ['배수시설 설치·정비', '도로·진입로 노면 배수 정비', '지하 배수관 매설', '집수정·맨홀 설치',
        '도로 밑 횡단 배수로 놓기', '도로·단지 안전시설 설치', '주차장 만들기·정비', '담장 쌓기', '건물 기초·바닥 콘크리트',
        '전기·통신·가로등 지중시설', '수도 급수관 연결']));
$bad = [['key' => 'x', 'name' => 'X', 'materials' => array_map(static fn($i) => [$i, 'n', 'd'], range(30, 40))]];
ok('11개 연결 시 위반 검출', count(work_types_validate($bad)) === 1);
$bad = [['key' => 'x', 'name' => 'X', 'materials' => [[203, 'PE망', ''], [1, '철근/시멘트', ''], [24, 'a', ''], [24, 'a', '']]]];
ok('제외 대상·대분류·중복 각각 검출', count(work_types_validate($bad)) === 3);
$m = work_type_materials(['materials' => [[24, '수로관', '본체'], [133, 'SUS', '덮개'], [999, '없음', '']]],
    [24 => ['name' => '수로관(벤치3종)', 'is_active' => true, 'thumb' => ''], 133 => ['name' => 'SUS그레이팅', 'is_active' => false, 'thumb' => '']]);
eq('DB 정보 기준: 비활성·없는 카테고리는 제외, 이름은 DB 값, 링크는 카테고리 페이지',
    array_map(static fn($x) => [$x['id'], $x['name'], $x['href']], $m), [[24, '수로관(벤치3종)', '/products.php?category=24']]);
eq('DB 미연결 시 설정 이름으로 모두 표시', count(work_type_materials(['materials' => [[24, '수로관', ''], [116, '그레이팅', '']]], [])), 2);
$m = work_type_materials(['key' => 'drainage', 'materials' => [[24, '수로관', '본체']]], []);
eq('작업 키가 있으면 작업별 목록 링크(work=키)', $m[0]['href'], '/products.php?category=24&work=drainage');
ok('work_type_find: 있는 키·없는 키·빈 키', (work_type_find('drainage')['key'] ?? '') === 'drainage'
    && work_type_find('nope') === null && work_type_find('') === null);
$dr = work_type_find('drainage');
ok('work_type_has_material: 대표 자재만 true', work_type_has_material($dr, 24) && !work_type_has_material($dr, 999)
    && !work_type_has_material($dr, 0));
$imgOk = true;
foreach (work_types() as $t) { foreach (array_filter([$t['img'] ?? null, $t['banner'] ?? null]) as $im) { if (!is_file(__DIR__ . '/../public' . $im)) { $imgOk = false; } } }
ok('작업 카드·배너 이미지 파일 존재', $imgOk);

echo "[작업별 목록 필터 = 기존 products.php 필터 render_product_filters]\n";
$fc = ['mat' => [11 => 28, 13 => 10], 'shape' => ['U형' => 2], 'load' => [], 'spec' => ['200*200' => 3, '300*300' => 1], 'total' => 38];
$act = ['mat' => [11], 'spec' => [], 'shape' => [], 'load' => []];
ob_start(); render_product_filters($act, null, 24, '', $fc, false);                       $plain = ob_get_clean();
ob_start(); render_product_filters($act, null, 24, '', $fc, false, ['work' => 'drainage']); $work  = ob_get_clean();
$groups = static fn(string $h): array => preg_match_all('/<div class="fgrp[^"]*"><b>([^<]+)/u', $h, $m) ? array_map('trim', $m[1]) : [];
eq('필터 그룹 동일(재질·규격·형상/옵션·재고)', $groups($work), $groups($plain));
ok('그룹 4개 모두 표시', $groups($plain) === ['재질', '규격', '형상 / 옵션', '재고']);
ok('차이는 work 유지(hidden·초기화 링크)뿐', str_replace(['<input type="hidden" name="work" value="drainage">', '&amp;work=drainage'], '', $work) === $plain);

echo "[규격 치수 표시 spec_size_label]\n";
eq('곱셈 기호 × 통일',      spec_size_label('규격: 200*150*2000*35T | 중량: 90kg | 단위: 본'), '200×150×2000×35T');
eq('관경·옵션 유지',        spec_size_label('규격: D300*6000_국산 | 단위: 본'), 'D300×6000_국산');
eq('x 표기도 ×',            spec_size_label('규격: 400x400 | 단위: 개'), '400×400');
eq('규격 항목 없음 → 빈 값', spec_size_label('단위: 본'), '');

echo "[기업회원 메인 corp_home_*]\n";
eq('기업가 있으면 기업가', corp_home_unit_price(27000, 29900), 27000);
eq('기업가 0 → 정가',      corp_home_unit_price(0, 29900), 29900);
eq('요약: 여러 품목',      corp_home_items_summary('벤치플륨관(벤치2종)', 4), '벤치플륨관(벤치2종) 외 3개 품목');
eq('요약: 1개 품목',       corp_home_items_summary('그레이팅수로용', 1), '그레이팅수로용');
eq('요약: 품목 없음',      corp_home_items_summary(null, 0), '품목 없음');
$tree = corp_home_category_tree([
    ['id' => 12, 'parent_id' => null, 'name' => '콘크리트제품'],
    ['id' => 11, 'parent_id' => null, 'name' => '철근콘크리트제품'],
    ['id' => 6,  'parent_id' => null, 'name' => '기타'],
    ['id' => 256, 'parent_id' => 11, 'name' => '플륨관/수로관'],
    ['id' => 23, 'parent_id' => '256', 'name' => '벤치플륨관(벤치2종)'],
]);
eq('대분류: GNB 5개만, 순서 고정, 제품 접미사 제거', $tree['lv1'], [['id' => 11, 'name' => '철근콘크리트'], ['id' => 12, 'name' => '콘크리트']]);
eq('하위: 부모 id 기준', $tree['byParent'][256] ?? null, [['id' => 23, 'name' => '벤치플륨관(벤치2종)']]);
ok('매입 상태 = 완료·납품완료', CORP_HOME_PURCHASED_STATUSES === ['APPROVED', 'DELIVERED']);

echo "[검색 자동완성 카테고리 경로 category_path_label]\n";
$catTree = [
    11  => ['parent_id' => null,  'name' => '철근콘크리트제품'],
    256 => ['parent_id' => '11',  'name' => '플륨관/수로관'],
    23  => ['parent_id' => 256,   'name' => '벤치플륨관(벤치2종)'],
    300 => ['parent_id' => 23,    'name' => '4단계'],
    7   => ['parent_id' => null,  'name' => '전기/조명'],
    90  => ['parent_id' => 999,   'name' => '부모없음'],
    91  => ['parent_id' => 92,    'name' => '순환A'],
    92  => ['parent_id' => 91,    'name' => '순환B'],
];
eq('3단계: 최상위 제품 접미사 제거', category_path_label(23, $catTree), '철근콘크리트 › 플륨관/수로관 › 벤치플륨관(벤치2종)');
eq('4단계 이상도 전체 경로', category_path_label(300, $catTree), '철근콘크리트 › 플륨관/수로관 › 벤치플륨관(벤치2종) › 4단계');
eq('최상위 자신(제품 접미사 없음)', category_path_label(7, $catTree), '전기/조명');
eq('최상위 자신(제품 접미사 제거)', category_path_label(11, $catTree), '철근콘크리트');
eq('부모 누락: 있는 데까지(접미사 처리 안 함)', category_path_label(90, $catTree), '부모없음');
eq('부모 순환: 무한루프 없이 멈춤', category_path_label(91, $catTree), '순환B › 순환A');
eq('없는 id → 빈 문자열', category_path_label(12345, $catTree), '');

echo "[검색 자동완성 규격 종류 수 spec_variant_count]\n";
eq('규격 값 기준(중량·운반비 차이는 같은 규격)', spec_variant_count([
    '규격: 300*300*80T | 단위: m2 | 중량: 176kg',
    '규격: 300*300*80T | 단위: m2 | 중량: 180kg | 운반비예상: 1000',
    '규격: 200*100*60T | 단위: m2',
]), 2);
eq('규격 항목 없음·빈 값·NULL 은 세지 않음', spec_variant_count(['단위: 본 | 중량: 10kg', '', null, '규격:  ']), 0);
eq('빈 목록 → 0', spec_variant_count([]), 0);

echo "[DB 접속 실패 진단 db_connect_error_hint / env_shadowed_keys]\n";
eq('1045 → 계정·비밀번호', db_connect_error_hint(1045), '계정·비밀번호 불일치(DB_USER/DB_PASS)');
eq('1049 → DB 없음',       db_connect_error_hint(1049), 'DB 없음(DB_NAME)');
eq('2002 → 서버 접속 불가', db_connect_error_hint(2002), 'DB 서버 접속 불가(DB_HOST·MySQL/MariaDB 실행 여부)');
$envTmp = tempnam(sys_get_temp_dir(), 'env');
file_put_contents($envTmp, "TM_TEST_SHADOW=from_file\nTM_TEST_SAME=same\nTM_TEST_NEW=new\n");
putenv('TM_TEST_SHADOW=from_shell'); putenv('TM_TEST_SAME=same');
load_env_file($envTmp); unlink($envTmp);
ok('셸 값 우선 유지(기존 동작)', getenv('TM_TEST_SHADOW') === 'from_shell' && getenv('TM_TEST_NEW') === 'new');
eq('값이 다른 키만 기록(같은 값·새 키 제외)', env_shadowed_keys('TM_TEST_'), ['TM_TEST_SHADOW']);

echo "[포장 블록 두께 판독 ai_block_thickness / 두께 필터]\n";
eq('80T 표기', ai_block_thickness('규격: 199*99*80T_회색 | 중량: 165kg'), 80);
eq('셋째 치수(T 없음)', ai_block_thickness('규격: 1200*900*80_컬러(차도) | 단위: m 2'), 80);
eq('가로 100 은 두께 아님(200*100*60T → 60)', ai_block_thickness('규격: 200*100*60T(불투수)'), 60);
eq('범위 표기 → 판독 안 함', ai_block_thickness('규격: 230*114*65~125_조경경계블록'), null);
eq('치수 4개 이상 → 판독 안 함', ai_block_thickness('규격: 400*400*2000*120'), null);
ok('포장 검색어 판별', ai_is_paving_query('인터로킹 블록') && ai_is_paving_query('보차도블록') && !ai_is_paving_query('호안블록') && !ai_is_paving_query('측구 수로관'));
$rowsT = [
    ['name' => '인터로킹i2형블럭', 'spec' => '규격: 199*99*100T_회색'],
    ['name' => '인조화강블록(불투수)', 'spec' => '규격: 200*100*60T(불투수)'],
    ['name' => '호안블록(저류용)', 'spec' => '규격: 400*200*100_아이블록'],
    ['name' => '녹지경계블록', 'spec' => '규격: 190*160*100_적색'],
    ['name' => '다칼라블랜딩블록', 'spec' => '규격: 1200*900*100_컬러(차도)'],
];
eq('두께 100 포장 블록만(60T·호안·경계 제외)', array_column(ai_filter_paving_thickness($rowsT, 100), 'name'), ['인터로킹i2형블럭', '다칼라블랜딩블록']);

echo "[상담: 카드 없는 답의 '원하시는 제품을 선택해 주세요.' 제거]\n";
$cardsEv = [['type' => 'product_cards', 'products' => []]];
ok('카드 없음 → 끝의 선택 안내 제거', rag_strip_dangling_choice("안전 그레이팅이 적합합니다.\n\n원하시는 제품을 선택해 주세요.", [['type' => 'material_sources', 'sources' => []]]) === '안전 그레이팅이 적합합니다.');
ok('카드 있음 → 그대로', rag_strip_dangling_choice("고르는 기준입니다.\n원하시는 제품을 선택해 주세요.", $cardsEv) === "고르는 기준입니다.\n원하시는 제품을 선택해 주세요.");
ok('그 문장뿐이면 빈 답변이 되지 않게 그대로, 중간 문장은 손대지 않음', rag_strip_dangling_choice('원하시는 제품을 선택해 주세요.', []) === '원하시는 제품을 선택해 주세요.'
    && rag_strip_dangling_choice('원하시는 제품을 선택해 주세요. 면적도 알려 주세요.', []) === '원하시는 제품을 선택해 주세요. 면적도 알려 주세요.');

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
