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
// 화면 표시용 단위: 제곱미터 표기 통일(㎡), 그 밖의 단위는 그대로
eq('표시 단위 m 2 → ㎡',  unit_display_label('m 2'), '㎡');
eq('표시 단위 m2·M2·m² → ㎡', [unit_display_label('m2'), unit_display_label('M2'), unit_display_label('m²'), unit_display_label(' ㎡ ')], ['㎡', '㎡', '㎡', '㎡']);
eq('표시 단위 M(미터)·본·개·빈 값 그대로', [unit_display_label('M'), unit_display_label('본'), unit_display_label('개'), unit_display_label('')], ['M', '본', '개', '']);
eq('표시 단위 m 2 이외 m 표기는 그대로', [unit_display_label('m'), unit_display_label('m3'), unit_display_label('m 2 이상')], ['m', 'm3', 'm 2 이상']);

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
// DB 불필요 테스트지만 .env.local 로 로컬 DB(법령 칸 있음)에 붙은 PC 에서도 같은 결과가 나와야 함(2026-11-02 사용자 PC 배포 사전 점검 실패 원인)
$legalHits = rag_search_legal_documents('주차 공간', 'pavement');
if (!db() || !rag_documents_has_source_columns()) {
    eq('DB·출처 칸 없으면(운영 미적용) 법령 근거 검색 빈 배열', $legalHits, []);
} else {
    ok('DB·출처 칸 있으면 검증·활성 공식 근거만(사내 문서·미검증 제외)', !array_filter($legalHits, static fn($d) => ($d['source_type'] ?? '') === 'internal'
        || !in_array($d['verify_level'] ?? '', RAG_LEGAL_VERIFY_LEVELS, true)));
}

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
// v5 근거 기반 재선정(docs/03-analysis/work-types-v5.md)
$wtKeys = array_column($wt, 'key');
ok('v5: 논밭 수로 정비(farm) 추가, 담장(fence)은 정원·담장 꾸미기(garden)로 통합',
    in_array('farm', $wtKeys, true) && !in_array('fence', $wtKeys, true)
    && work_type_has_material(work_type_find('garden'), 102) && work_type_has_material(work_type_find('garden'), 101));
ok('v5: 지하 배수에 침투시설(38 침투측구·63 침투집수정) 미연결 — 빗물을 넣는 시설이라 목적이 반대',
    !work_type_has_material(work_type_find('subdrain'), 38) && !work_type_has_material(work_type_find('subdrain'), 63));
$descOk = true;
foreach ($wt as $t) { foreach ($t['materials'] as $mm) { $len = mb_strlen((string) ($mm[2] ?? '')); if ($len < 1 || $len > 14) { $descOk = false; } } }
ok('v5: 대표 자재 용도 설명 1~14자(카드 한 줄)', $descOk);
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
// 2026-11-05: 사구석(157) 정원 추가·수목보호틀(인조석, 104) 제외. 배수관 주의 안내는 화면에 없음(AI 상담 답변에서만)
$gd = work_type_find('garden');
ok('정원: 사구석(157) 포함, 수목보호틀(인조석, 104) 제외', work_type_has_material($gd, 157) && !work_type_has_material($gd, 104));
$sw = work_type_find('sewer');
ok('배수관 묻기: 파형강관(269) 역할 \'도로 횡배수관\'', in_array([269, '파형강관', '도로 횡배수관'], $sw['materials'], true));
$noteFns = ['work_type_note', 'work_type_note_html', 'work_type_wall_drain_note', 'work_type_cross_drain_note'];
ok('AI 상담 밖 배수관 안내 없음(작업 설정 notes·안내 함수 없음)', !array_filter($wt, static fn($t) => isset($t['notes'])) && !array_filter($noteFns, 'function_exists'));
$bp = rag_build_prompt([['role' => 'user', 'content' => '보강토 옹벽 뒤에 PE 유공관 묻을 건데 어떤 거 써요?']])['system'];
$bq = rag_build_prompt([['role' => 'user', 'content' => '차도 밑으로 파형강관 넣으려는데 관경 몇 mm 써야 하나요']])['system'];
$bn = rag_build_prompt([['role' => 'user', 'content' => '사람만 다니는 정원 길에 블록 깔고 싶어요']])['system'];
ok('AI 상담 프롬프트: 옹벽 배수관·도로 횡단배수관 질문에만 주의 안내 구역', str_contains($bp, '[옹벽 배수관]') && !str_contains($bp, '[도로 횡단배수관]')
    && str_contains($bq, '[도로 횡단배수관]') && !str_contains($bn, '배수관 설치 위치·관경 주의 안내'));
$descs = [];
foreach ($wt as $t) { foreach ($t['materials'] as $mm) { $descs[] = $mm[2]; } }
ok('용도 설명에 근거 없는 \'대구경\' 없음(50 흄관·137·269 파형강관)', !array_filter($descs, static fn($d) => str_contains($d, '대구경')));

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

echo "[상담: 답변 글 형식 정리 rag_clean_answer_format — ** 굵게·끝 콜론]\n";
eq('굵게 기호 제거(목록 제품명)', rag_clean_answer_format("- **인터로킹i2형블럭**: 승용차 통행에 적합합니다."), '- 인터로킹i2형블럭: 승용차 통행에 적합합니다.');
eq('문장 끝 콜론 → 마침표', rag_clean_answer_format("다음은 주차장에 설치할 수 있는 승용차 통행용 그레이팅 제품입니다:\n\n- 중하중그레이팅수로용: 승용차 및 SUV 통행에 적합합니다."),
    "다음은 주차장에 설치할 수 있는 승용차 통행용 그레이팅 제품입니다.\n\n- 중하중그레이팅수로용: 승용차 및 SUV 통행에 적합합니다.");
eq('전각 콜론·요 체도 마침표, 뒤 공백 정리', rag_clean_answer_format("이렇게 비교해 볼게요： \n아래와 같습니다 :"), "이렇게 비교해 볼게요.\n아래와 같습니다.");
eq('줄 중간 콜론·시각·제목 아닌 # 은 그대로', rag_clean_answer_format('상담 시간: 09:00~18:00 · 규격 #3번'), '상담 시간: 09:00~18:00 · 규격 #3번');
eq('제목 기호(#)·짝 없는 ** 제거', rag_clean_answer_format("### 추천 제품\n**중하중 제품이 적합합니다."), "추천 제품\n중하중 제품이 적합합니다.");
eq('정리할 것 없는 글은 그대로', rag_clean_answer_format('원하시는 제품을 선택해 주세요.'), '원하시는 제품을 선택해 주세요.');

echo "[상담: 자재 분류 설명 ai_category_brief·화면 카드 ai_cards_for_screen]\n";
eq('끝의 「전 규격을 공급합니다」 떼고 분류 설명만', ai_category_brief('U형·AN형·벤치플륨관 등 측구 및 배수로용 콘크리트 수로관 전 규격을 공급합니다.'), 'U형·AN형·벤치플륨관 등 측구 및 배수로용 콘크리트 수로관');
eq('「제품을 공급합니다」도 뗌', ai_category_brief('호안블록·잔디블록 등 법면 보호 및 녹화에 적합한 블록 제품을 공급합니다.'), '호안블록·잔디블록 등 법면 보호 및 녹화에 적합한 블록');
eq('여러 줄이면 첫 줄만(둘째 줄 홍보 문구 제외)', ai_category_brief("배선 자재부터 조명 기구까지 전기 공사 자재를 공급합니다.\n안전 기준을 충족하는 검증된 제품입니다."), '배선 자재부터 조명 기구까지 전기 공사 자재');
eq('다른 끝맺음은 그대로, 빈 값은 빈 문자열', [ai_category_brief('200mm~500mm 이상 PE이중벽관을 하수·배수·배관 현장에 공급합니다.'), ai_category_brief(null), ai_category_brief("  \n ")],
    ['200mm~500mm 이상 PE이중벽관을 하수·배수·배관 현장에 공급합니다.', '', '']);
ok('120자 넘으면 줄임', mb_strlen(ai_category_brief(str_repeat('가', 200))) === 120);
$scr = ai_cards_for_screen([['id' => 1, 'name' => 'A', 'guide' => ['g'], 'category_desc' => '설명', 'weight_kg' => 18.0, 'standard' => 'KS F 4403 원심력 철근 콘크리트관', 'est_cost' => 10], ['id' => 2, 'name' => 'B']]);
ok('화면 카드에는 상담 글 재료(category_desc·weight_kg·standard) 없음, 나머지 필드·순서 그대로', !isset($scr[0]['category_desc']) && !isset($scr[0]['weight_kg']) && !isset($scr[0]['standard'])
    && $scr[0]['guide'] === ['g'] && $scr[0]['est_cost'] === 10 && $scr[1] === ['id' => 2, 'name' => 'B']);

echo "[상담: 등록 규격 중량 ai_spec_weight_kg·국가표준 연결 ai_name_ks_ref]\n";
eq('규격 칸 중량(판매 단위당) — kg·㎏·소수·천 단위 쉼표', [ai_spec_weight_kg('규격: D250*2500*28T | 중량: 166kg | 단위: 본'), ai_spec_weight_kg('규격: 220*110*80T_회색 | 중량: 164kg | 단위: m 2'),
    ai_spec_weight_kg('중량 : 1,250 ㎏'), ai_spec_weight_kg('중량: 12.5kg')], [166.0, 164.0, 1250.0, 12.5]);
eq('중량 표기 없거나 0이면 null(추정하지 않음)', [ai_spec_weight_kg('규격: 400*500*50*3T_우수_국산 | 단위: 개'), ai_spec_weight_kg('중량: 0kg'), ai_spec_weight_kg('중량: 약간')], [null, null, null]);
eq('확인된 KS 적용 범위: 사용자 확인·공식 상세 확인 표시만, 표시 괄호는 뗌, 검색 요약은 null', [ai_ks_confirmed_scope('주로 도로 측구에 쓰는 철근 콘크리트 U형의 몸체 및 뚜껑 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)'),
    ai_ks_confirmed_scope('콘크리트 경계 블록 (e나라표준인증 적용 범위, 사용자 확인)'), ai_ks_confirmed_scope('보도 및 차도에 평행 설치(검색 요약)'), ai_ks_confirmed_scope('')],
    ['주로 도로 측구에 쓰는 철근 콘크리트 U형의 몸체 및 뚜껑', '콘크리트 경계 블록', null, null]);
// 공공조달 등록 현황(app/procurement_summary.php 형식 — 테스트용 데이터 주입)
ai_procurement_data(['as_of' => '2026-11-05', 'groups' => [
    'hume_pipe' => ['label' => '원심력 철근콘크리트관(흄관)', 'words' => ['흄관', '원심력'], 'items' => 176, 'makers' => 9, 'certs' => ['ks' => 146, 'group_standard' => 0, 'excellent' => 0]],
    'manhole_block' => ['label' => '콘크리트 맨홀블록', 'words' => ['맨홀블록', '맨홀'], 'items' => 1968, 'makers' => 18, 'certs' => ['ks' => 906, 'group_standard' => 0, 'excellent' => 0]],
    'manhole_cover' => ['label' => '주철 맨홀뚜껑', 'words' => ['맨홀뚜껑', '주철뚜껑'], 'items' => 190, 'makers' => 8, 'certs' => ['ks' => 0, 'group_standard' => 169, 'excellent' => 0]],
    'grating_cover' => ['label' => '그레이팅덮개', 'words' => ['그레이팅덮개'], 'items' => 103, 'makers' => 15, 'certs' => ['ks' => 0, 'group_standard' => 0, 'excellent' => 0]],
]]);
$pc = ai_procurement_summary('흄관 KS 인증 제품인가요?');
eq('공공조달 현황: 질문 자재만, 출처·기준일 있는 한 문장(가격·업체 이름 없음)', [count($pc), $pc[0]['summary'] ?? ''],
    [1, '공공 조달몰(나라장터 쇼핑몰)에 등록된 원심력 철근콘크리트관(흄관) 176개 품목(9개 업체) 중 KS 표시 146개 (조달청 품목 등록 내역, 2026-11-05 기준)']);
eq('긴 낱말 우선(맨홀블록 4글자 > 흄관 2글자), 그 일부로만 걸린 종류는 뺌(맨홀뚜껑 → 맨홀블록 제외)·인증 없는 종류 문구·해당 없음', [array_column(ai_procurement_summary('주철 맨홀뚜껑 크기'), 'name'),
    ai_procurement_summary('그레이팅 덮개')[0]['summary'] ?? '', ai_procurement_summary('잔디 깎는 기계'), array_column(ai_procurement_summary('맨홀 블럭이랑 흄관'), 'name')],
    [['주철 맨홀뚜껑'], '공공 조달몰(나라장터 쇼핑몰)에 등록된 그레이팅덮개 103개 품목(15개 업체) 중 KS 표시·단체표준인증 품목 없음 (조달청 품목 등록 내역, 2026-11-05 기준)', [],
     ['콘크리트 맨홀블록', '원심력 철근콘크리트관(흄관)']]);
$bl = [['standards' => [], 'products' => [], 'procurement' => ai_procurement_summary('흄관')]];
eq('출처 카드: 답에 \'조달\'이 있을 때만 공공조달 현황(이름·품목 수·기준일)', [rag_basic_sources_used('흄관은 KS F 4403 대상입니다.', $bl)['procurement'],
    rag_basic_sources_used('조달청 품목 등록 내역에 따르면 흄관 176개 품목 중 146개가 KS 표시 제품입니다.', $bl)['procurement']],
    [[], [['name' => '원심력 철근콘크리트관(흄관)', 'items' => 176, 'as_of' => '2026-11-05']]]);
ai_procurement_data(is_file(__DIR__ . '/../app/procurement_summary.php') ? (array) require __DIR__ . '/../app/procurement_summary.php' : []);   // 실제 데이터로 되돌림
eq('실제 데이터 파일: 출처 조달청·흄관 집계 있음·가격 칸 없음', [str_contains((string) (ai_procurement_data()['source'] ?? ''), '조달청'), isset(ai_procurement_data()['groups']['hume_pipe']),
    str_contains((string) file_get_contents(__DIR__ . '/../app/procurement_summary.php'), '단가')], [true, true, false]);
$m3401 = '유체 수송용 수도·배수·하수·우수 용도의 압력용 경질 PVC관(공식 표준명: 압력용 경질 폴리염화비닐관 — 목록의 수도용은 이전 명칭) (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)';
eq('공식 표준명 표시가 있으면 그 이름(옛 등록 이름 대신), 적용 범위 문구에서는 뺌 — 확인 표시 없으면 원래 이름', [ai_ks_display_name('수도용 경질 폴리염화비닐관', $m3401), ai_ks_confirmed_scope($m3401),
    ai_ks_display_name('A', '범위(공식 표준명: B)(검색 요약)'), ai_ks_display_name('콘크리트 경계 블록', '콘크리트 경계 블록 (e나라표준인증 적용 범위, 사용자 확인)')],
    ['압력용 경질 폴리염화비닐관', '유체 수송용 수도·배수·하수·우수 용도의 압력용 경질 PVC관', 'A', '콘크리트 경계 블록']);
// 운영 상품명(2026-11-05 읽기 전용 조회)으로 — 표준 적용 범위와 이름이 분명히 맞는 제품군만
$ksYes = ['흄관(A형)' => 'KS F 4403', '흄관(B형)' => 'KS F 4403', '접속흄관(B형)' => 'KS F 4403', 'PE이중벽관(KS)' => 'KS M 3500-1',
          '고강성PVC이중벽관' => 'KS M 3600', '인터로킹U형블럭' => 'KS F 4419', '인터로킹i2형블럭' => 'KS F 4419',
          '장애인사각블럭(점자블럭)' => 'KS F 4561', '파형강관' => 'KS D 3590', 'U형플륨관(벤치1종)' => 'KS F 4010', '벤치플륨관(벤치2종)' => 'KS F 4010',
          '주철뚜껑(우수/오수/전기/통신)' => 'KS D 4040', '조립식원형맨홀(상부.연직.하부구체)' => 'KS F 4012', '콘크리트경계석' => 'KS F 4006', '인조화강경계석' => 'KS F 4006'];
ok('표준 대상 제품군 15종 → 해당 KS 번호', !array_filter($ksYes, static fn($ref, $nm) => ai_name_ks_ref($nm) !== $ref, ARRAY_FILTER_USE_BOTH));
$ksNo = ['VR관(흄관대용)', '유공흄관', '흄관분기구', 'PE이중벽관2종(유공관)', 'HDPE이중벽관(유공관)', 'PE이중벽관분기구', 'PVC이중벽관분기구', 'PVC관VN(구VG1)', 'PVC소형맨홀',
         'PVC오수받이', '고강성PVC이경소켓', '파형강관(유공관)', '파형강관(내면피복)', '플륨관/수로관뚜껑', '플륨관/수로관집게', '그레이팅맨홀(집수정)용', '사각맨홀(집수정)',
         '원형노바시(원형인상맨홀)', '조화(디자인)맨홀뚜껑', '화강경계도로', '경계석집게', '점토블럭', '인조화강블록(투수)', '중하중그레이팅수로용', '측구수로관B형(앵글부착)',
         'PE이중벽관1종', 'HDPE이중벽관(직관)'];   // KS M 3500-1 적용 범위(관 내·외면 평활, 사용자 확인 2026-11-05) — 이름만으로 구조 확인 불가
ok('부속·유공관·대용품·재질이 다른 제품·범위가 애매한 제품 27종은 연결 안 함', !array_filter($ksNo, static fn($nm) => ai_name_ks_ref($nm) !== ''));
ok('rag_process 가 검색 결과의 국가표준도 출처 카드 후보(basicsSeen)에 넣음', (bool) preg_match("/in_array\(\\\$tc\['function'\]\['name'\] \?\? '', \['material_info', 'search_products'\], true\) && !empty\(\\\$toolData\['basics'\]\)\) \{ \\\$basicsSeen\[\] = \\\$toolData\['basics'\];/", (string) file_get_contents(__DIR__ . '/../app/rag.php')));

echo "[AI 상담 키 rag_chat_key_*]\n";
require_once __DIR__ . '/../app/rag.php';
$_SESSION['chat_keys'] = [];
$k1 = rag_chat_key_issue();
$k2 = rag_chat_key_issue();
ok('상담마다 새 키(32자 hex, 세션 ID와 다름)', $k1 !== $k2 && preg_match('/^[0-9a-f]{32}$/', $k1) === 1 && $k1 !== session_id());
ok('발급한 키는 허용', rag_chat_key_allowed($k1) && rag_chat_key_allowed($k2));
ok('발급하지 않은 키·빈 키는 거부', !rag_chat_key_allowed('abcdef0123456789abcdef0123456789') && !rag_chat_key_allowed(''));
ok('기존 방식(세션 ID 키) 허용 유지', session_id() === '' || rag_chat_key_allowed(session_id()));
rag_chat_key_allow($k1);
ok('같은 키 재허용 시 중복 없음', count(array_keys($_SESSION['chat_keys'], $k1, true)) === 1);
for ($i = 0; $i < 40; $i++) rag_chat_key_issue();
ok('허용 목록은 최근 30개까지만', count($_SESSION['chat_keys']) === 30 && !rag_chat_key_allowed($k2));

echo "[이전 상담 카드 복원 rag_pair_turn_events]\n";
$cards = '[{"type":"product_cards","products":[{"id":1}]}]';
$msgs  = [
    ['role' => 'user', 'content' => '안녕'],      ['role' => 'assistant', 'content' => '네'],
    ['role' => 'user', 'content' => '그레이팅'],  ['role' => 'assistant', 'content' => '선택해 주세요.'],
    ['role' => 'user', 'content' => '맨홀'],      ['role' => 'assistant', 'content' => ''],
];
$logs = [
    ['user_msg' => '안녕', 'events_json' => null],
    ['user_msg' => '그레이팅', 'events_json' => $cards],
    ['user_msg' => '맨홀', 'events_json' => '[{"type":"estimate_created","estimate_id":7}]'],
];
$pe = rag_pair_turn_events($msgs, $logs);
eq('카드는 해당 AI 답변 인덱스에(텍스트 없는 답변 포함)', array_keys($pe), [5, 3]);
ok('이벤트 내용 그대로', $pe[3][0]['products'][0]['id'] === 1 && $pe[5][0]['estimate_id'] === 7);
$pe = rag_pair_turn_events($msgs, array_merge([['user_msg' => '앞부분 잘린 질문', 'events_json' => $cards]], [$logs[1]]));
eq('로그 없는 질문은 텍스트만, 잘린 앞부분 로그 무시', array_keys($pe), [3]);
$pe = rag_pair_turn_events($msgs, [['user_msg' => '그레이팅', 'events_json' => '{깨진 JSON']]);
eq('깨진 events_json 은 무시', $pe, []);
$long = str_repeat('가', 1200);
$pe = rag_pair_turn_events([['role' => 'user', 'content' => $long], ['role' => 'assistant', 'content' => 'x']],
    [['user_msg' => str_repeat('가', 1000), 'events_json' => $cards]]);
eq('1000자 초과 질문도 질문 API와 같은 기준으로 짝지음', array_keys($pe), [1]);

echo "[이전 상담 현재가 rag_apply_current_prices]\n";
$snap = [3 => [['type' => 'product_cards', 'products' => [
    ['id' => 1, 'name' => 'A', 'spec' => '규격: 300*1000 | 단위: 개', 'price_b2c' => 45000, 'image_url' => '/a.png', 'qty_basis' => '근거', 'suggested_qty' => 10],
    ['id' => 2, 'name' => 'B', 'spec' => '규격 B', 'price_b2c' => 61000],
    ['id' => 3, 'name' => 'C', 'spec' => '규격 C', 'price_b2c' => 83500],
    ['id' => 4, 'name' => 'D', 'spec' => '규격 D', 'price_b2c' => 127000],
    ['id' => 5, 'name' => 'E', 'spec' => '규격 E', 'price_b2c' => 9000],
]], ['type' => 'estimate_created', 'estimate_id' => 7]]];
eq('상품 ID 일괄 수집', rag_event_product_ids($snap), [1, 2, 3, 4, 5]);
$cur = [1 => ['price' => 47000, 'spec' => ' 규격: 300*1000  | 단위: 개'], 2 => ['price' => 61000, 'spec' => '규격 B'],
        4 => ['price' => 0, 'spec' => '규격 D'], 5 => ['price' => 9500, 'spec' => '규격 E-2']];
$ap = rag_apply_current_prices($snap, $cur);
$st = array_map(static fn($p) => $p['price_state'] . ':' . var_export($p['price_b2c'], true), $ap[3][0]['products']);
eq('상태별 가격(변경·동일·판매중지·0원·규격변경)', $st, ['ok:47000', 'ok:61000', 'stopped:NULL', 'inquiry:0', 'changed:NULL']);
$a = $ap[3][0]['products'][0];
ok('상품명·규격·이미지·근거·권장수량은 상담 당시 값 유지', $a['name'] === 'A' && $a['spec'] === '규격: 300*1000 | 단위: 개'
    && $a['image_url'] === '/a.png' && $a['qty_basis'] === '근거' && $a['suggested_qty'] === 10);
ok('견적 카드 등 다른 이벤트는 그대로', $ap[3][1] === $snap[3][1]);
ok('원본(입력 배열)은 변경되지 않음', $snap[3][0]['products'][0]['price_b2c'] === 45000 && !isset($snap[3][0]['products'][0]['price_state']));
$snapC = [1 => [['type' => 'product_cards', 'products' => [
    // 실제 추천표 카드(tool_search_products·chat_logs 복원)에는 항상 name 이 있다 — 테스트 자료도 같게(없으면 ai_cost_summary 최저·최고 이름에서 경고)
    ['id' => 1, 'name' => '상품 A', 'spec' => '규격 A', 'price_b2c' => 1000, 'suggested_qty' => 10, 'est_cost' => 10000, 'cost_lowest' => true, 'cost_diff' => 0],
    ['id' => 2, 'name' => '상품 B', 'spec' => '규격 B', 'price_b2c' => 1200, 'suggested_qty' => 10, 'est_cost' => 12000, 'cost_lowest' => false, 'cost_diff' => 2000],
    ['id' => 3, 'name' => '상품 C', 'spec' => '규격 C', 'price_b2c' => 900, 'suggested_qty' => 10, 'est_cost' => 9000, 'cost_lowest' => false, 'cost_diff' => 0],
]]]];
$apC = rag_apply_current_prices($snapC, [1 => ['price' => 1500, 'spec' => '규격 A'], 2 => ['price' => 1200, 'spec' => '규격 B']])[1][0]['products'];
ok('복원: 예상 자재비는 현재가 × 권장 수량으로 다시 계산, 판매 중지 상품은 금액 지움, 최저 표시 다시',
    [$apC[0]['est_cost'], $apC[1]['est_cost'], $apC[2]['est_cost']] === [15000, 12000, null]
    && $apC[1]['cost_lowest'] === true && $apC[0]['cost_lowest'] === false && $apC[0]['cost_diff'] === 3000 && !isset($apC[2]['cost_diff']));
$un = rag_apply_current_prices($snap, null);
ok('조회 실패: 모두 가격 확인 불가, 과거 가격 숨김', array_unique(array_map(static fn($p) => $p['price_state'] . ':' . var_export($p['price_b2c'], true), $un[3][0]['products'])) === ['unknown:NULL']);

echo "[상담: 자재 기본 정보(국가표준·등록 규격) 매칭]\n";
ok('표기 통일: 플룸·블럭', ai_material_norm('벤치 플룸관 블럭') === '벤치플륨관블록');
ok('주제 낱말: 일반어 제외', ai_material_words('벤치플룸관 차이 설명') === ['벤치플륨관']);
ok('태그 조각이 주제에 포함되면 일치(벤치플륨 ↔ 벤치플룸관)', ai_material_match('철근 콘크리트 플룸 및 벤치 플룸,벤치플륨, 플륨형 수로관', '벤치플룸관') >= 1);
ok('관계없는 이름은 0점', ai_material_match('PE이중벽관(KS)', '벤치플룸관') === 0);
$picked = ai_material_pick([['인조화강블록(투수)', 'A'], ['인터로킹U형블럭', 'B'], ['점자블록', 'C']], '인터로킹 블록');
ok("구체적 낱말(인터로킹)이 맞는 것만 — '블록'만 맞는 항목 제외", $picked === ['B']);
ok('구체적 낱말이 없으면 넓은 낱말로', ai_material_pick([['콘크리트경계석', 'A'], ['PE관', 'B']], '경계석') === ['A']);
ok('규격 요약: 운반비·설치비 칸 제외', ai_spec_brief('규격: 200*150*2000*35T | 중량: 90kg | 단위: 본 | 상차수량: 200본/25톤 | 운반비예상: 1,500원') === '규격: 200*150*2000*35T · 중량: 90kg · 단위: 본');

echo "[상담: 확인 안 된 KS 번호 안내]\n";
$g = rag_guard_unverified_ks('흄관은 KS F 4009 제품을 쓰시면 됩니다.', '참고 자료: 흄관 (B형) KS F 4403');
ok('자료에 없는 번호 → 본문 그대로 + 끝에 확인 필요 안내', $g['unknown'] === ['KS F 4009'] && str_starts_with($g['text'], '흄관은 KS F 4009 제품을') && str_contains($g['text'], '확인된 자료에 없는 번호·수치'));
ok('자료에 있는 번호(띄어쓰기 달라도) → 그대로', rag_guard_unverified_ks('KS F4403 표기 제품입니다.', 'KS F 4403')['text'] === 'KS F4403 표기 제품입니다.');
ok('자료에 없는 강도 수치(MPa) → 안내, 자료에 있으면 그대로', rag_guard_unverified_ks('압축강도 24MPa 제품입니다.', '휨강도 5.0 MPa')['unknown'] === ['24MPa']
    && rag_guard_unverified_ks('휨강도 5.0MPa 입니다.', '휨강도 5.0 MPa 이상')['unknown'] === []);
ok('KS 번호 없음 → 그대로', rag_guard_unverified_ks('80mm 이상 블록을 쓰세요.', '')['unknown'] === []);
ok('부속 번호(KS M 3500-1)도 본 번호로 대조', rag_guard_unverified_ks('KS M 3500-1 이중벽관', 'KS M 3500-1 배수')['unknown'] === []);
ok('도구 요약: 검색 결과 수·수량·금액', str_contains(rag_tool_summary('search_products', ['products' => [['name' => '인터로킹', 'spec' => '규격: 200*100*80T | 단위: m 2', 'suggested_qty' => 20, 'est_cost' => 300000]], 'cost_summary' => ['x']]), '상품 1건(인터로킹 200*100*80T ×20 ≈300,000원)'));
ok('도구 요약: 자재 자료 출처·국가표준', rag_tool_summary('material_info', ['facts' => [['maker' => 'A산업']], 'basics' => ['standards' => [['ref' => 'KS F 4010']], 'products' => [[], []]]]) === '제조사 문장 1개(A산업) · 국가표준 KS F 4010 · 등록 상품 2종');

ok('기본 정보 출처: 답에 쓴 표준 번호·상품 이름만', rag_basic_sources_used('KS F4010 대상이며 벤치플륨관(벤치2종) 규격이 있습니다.', [['standards' => [['ref' => 'KS F 4010', 'name' => '플룸'], ['ref' => 'KS F 4016', 'name' => 'U형']], 'products' => [['name' => '벤치플륨관(벤치2종)', 'count' => 6], ['name' => '환경수로관', 'count' => 3]]]])
    === ['standards' => [['ref' => 'KS F 4010', 'name' => '플룸', 'url' => '']], 'products' => [['name' => '벤치플륨관(벤치2종)', 'count' => 6]], 'procurement' => []]);
ok('기본 정보 출처: 답에 없으면 빈 목록', rag_basic_sources_used('일반 설명입니다.', [['standards' => [['ref' => 'KS F 4010']], 'products' => []]]) === ['standards' => [], 'products' => [], 'procurement' => []]);

ok('직전 추천표: 최저 동률이면 같은 금액 제품도 알리라는 줄', str_contains(rag_recent_cards_prompt([['id' => 1, 'name' => 'A', 'spec' => '', 'price' => 100, 'qty' => 2, 'unit' => '㎡'], ['id' => 2, 'name' => 'B', 'spec' => '', 'price' => 100, 'qty' => 2, 'unit' => '㎡']]), '같은 금액의 다른 제품')
    && !str_contains(rag_recent_cards_prompt([['id' => 1, 'name' => 'A', 'spec' => '', 'price' => 100, 'qty' => 2, 'unit' => '㎡'], ['id' => 2, 'name' => 'B', 'spec' => '', 'price' => 120, 'qty' => 2, 'unit' => '㎡']]), '같은 금액의 다른 제품'));

$rc = [['id' => 402, 'name' => '인터로킹i2형블럭', 'spec' => '', 'price' => 9600, 'qty' => 20, 'unit' => '㎡'], ['id' => 396, 'name' => '인터로킹U형블럭', 'spec' => '', 'price' => 9600, 'qty' => 20, 'unit' => '㎡'],
       ['id' => 416, 'name' => '인조화강블록(불투수)', 'spec' => '', 'price' => 11000, 'qty' => 20, 'unit' => '㎡']];
ok('동률 최저가 담기 → 다른 동률 상품 이름 한 줄(직전 추천표 기준)', str_contains(rag_tie_note('인터로킹i2형블럭을 20㎡ 담았습니다.', [402], [], $rc), '인터로킹U형블럭도 있습니다')
    && !str_contains(rag_tie_note('인터로킹i2형블럭을 20㎡ 담았습니다.', [402], [], $rc), '인조화강'));
ok('동률 안내: 이미 같은 금액이라고 말했거나·동률 아닌 상품·이번 턴 카드 기준', rag_tie_note('같은 금액의 U형도 있습니다.', [402], [], $rc) === '' && rag_tie_note('담았습니다.', [416], [], $rc) === ''
    && str_contains(rag_tie_note('담았습니다.', [7], [['id' => 7, 'name' => 'A', 'cost_lowest' => true], ['id' => 8, 'name' => 'B', 'cost_lowest' => true], ['id' => 9, 'name' => 'C', 'cost_lowest' => false]], []), 'B도 있습니다'));

ok('자료 공백 이벤트: 제조사 자료 없을 때만, 연락처 모양 제거', ai_material_gap_event('벤치플룸관', false) === ['type' => 'material_gap', 'topic' => '벤치플룸관']
    && ai_material_gap_event('그레이팅', true) === null && ai_material_gap_event('흄관 010-1234-5678', false)['topic'] === '흄관' && ai_material_gap_event('  ', false) === null);

echo "[규격 축 spec_axes_parse·표시·이름표 상속·갱신 계획 (app/spec_axes.php)]\n";
$AX = static fn(array $defs): array => array_map(static fn($d, $i) => ['seq' => $i + 1, 'axis_key' => $d[0], 'label' => $d[1], 'symbol' => $d[2] ?? '', 'unit' => $d[3] ?? 'mm', 'use_filter' => true], $defs, array_keys($defs));
$axFlume  = $AX([['w', '폭', 'W'], ['h', '높이', 'H'], ['l', '길이', 'L'], ['t', '두께', 'T']]);                       // 24 수로관(벤치3종)
$axGaegeo = $AX([['w', '폭', 'W', 'm'], ['h', '높이', 'H', 'm'], ['l', '길이', 'L', 'm'], ['t', '상하두께'], ['t2', '벽체두께']]); // 21 개거
$axSqMh   = $AX([['w', '가로', 'W'], ['l', '세로'], ['h', '높이', 'H'], ['t', '두께', 'T']]);                         // 59 사각맨홀
$axRdMh   = $AX([['d', '내경', 'D'], ['h', '높이', 'H'], ['t', '두께', 'T']]);                                       // 61 원형맨홀
eq('수로관 900*800*2000*85T (900B) → 코드·폭·높이·길이·두께', spec_axes_parse('900*800*2000*85T (900B)', $axFlume),
    ['spec_code' => '900B', 'dims' => ['w' => 900.0, 'h' => 800.0, 'l' => 2000.0, 't' => 85.0], 'option' => null]);
eq('개거 4.0*1.0*2.0_280T*260T → m 를 mm 로, 상하두께·벽체두께', spec_axes_parse('4.0*1.0*2.0_280T*260T', $axGaegeo),
    ['spec_code' => null, 'dims' => ['w' => 4000.0, 'h' => 1000.0, 'l' => 2000.0, 't' => 280.0, 't2' => 260.0], 'option' => null]);
eq('개거 1.5*1.5*2.0_160T*140T', spec_axes_parse('1.5*1.5*2.0_160T*140T', $axGaegeo)['dims'] ?? null, ['w' => 1500.0, 'h' => 1500.0, 'l' => 2000.0, 't' => 160.0, 't2' => 140.0]);
eq('사각맨홀 300*400*600*150T → 가로·세로(l)·높이·두께', spec_axes_parse('300*400*600*150T', $axSqMh)['dims'] ?? null, ['w' => 300.0, 'l' => 400.0, 'h' => 600.0, 't' => 150.0]);
eq('원형맨홀 D900*1200*200T → 내경·높이·두께', spec_axes_parse('D900*1200*200T', $axRdMh)['dims'] ?? null, ['d' => 900.0, 'h' => 1200.0, 't' => 200.0]);
eq('H 접미·뒤 옵션: D600*1000H*150T_하부구체_KS', spec_axes_parse('D600*1000H*150T_하부구체_KS', $axRdMh),
    ['spec_code' => null, 'dims' => ['d' => 600.0, 'h' => 1000.0, 't' => 150.0], 'option' => '하부구체_KS']);
eq('코드 뒤 옵션: 400*1000*1600*80 (1000C)_벤치3종 부착형', spec_axes_parse('400*1000*1600*80 (1000C)_벤치3종 부착형', $axFlume),
    ['spec_code' => '1000C', 'dims' => ['w' => 400.0, 'h' => 1000.0, 'l' => 1600.0, 't' => 80.0], 'option' => '벤치3종 부착형']);
eq('코드가 아닌 괄호는 옵션: 200*1000*50(5*3)_국산', spec_axes_parse('200*1000*50(5*3)_국산', $AX([['w', '폭'], ['l', '길이'], ['h', '높이']])),
    ['spec_code' => null, 'dims' => ['w' => 200.0, 'l' => 1000.0, 'h' => 50.0], 'option' => '(5*3)_국산']);
eq('M 접미는 미터: D200*2M → 내경 200·길이 2000', spec_axes_parse('D200*2M', $AX([['d', '내경', 'D'], ['l', '길이', 'L']]))['dims'] ?? null, ['d' => 200.0, 'l' => 2000.0]);
eq('글자 붙은 꼬리는 옵션: D150*6000*1.6T_0RS → 두께 1.6, 옵션 0RS', spec_axes_parse('D150*6000*1.6T_0RS', $AX([['d', '내경', 'D'], ['l', '길이', 'L'], ['t', '두께', 'T']])),
    ['spec_code' => null, 'dims' => ['d' => 150.0, 'l' => 6000.0, 't' => 1.6], 'option' => '0RS']);
ok('한글이 붙은 숫자(300용)는 숫자 아님 → null', spec_axes_parse('300용 (440*500*80T_무공)', $AX([['w', '폭', 'W']])) === null);
ok('숫자 개수가 이름표와 다르면 null: D200*2M (…) vs 수로관 4축', spec_axes_parse('D200*2M (320*320*2000*60T)', $axFlume) === null);
ok('D 접두는 내경(d) 축만: D900*… vs 수로관 → null', spec_axes_parse('D900*800*2000*85T', $axFlume) === null);
ok('T 접미는 두께 축만: 900T*… → null', spec_axes_parse('900T*800*2000*85', $axFlume) === null);
ok('H 접미는 높이 축만: 사각맨홀 300H*… → null', spec_axes_parse('300H*400*600*150T', $axSqMh) === null);
ok('글자로 시작(수로관집게_800A) → null', spec_axes_parse('수로관집게_800A', $axFlume) === null);
ok('빈 값·이름표 없음 → null', spec_axes_parse('', $axFlume) === null && spec_axes_parse('900*800*2000*85T', []) === null);
ok('none 행만 → null', spec_axes_parse('900*800', [['seq' => 0, 'axis_key' => 'none', 'unit' => 'mm']]) === null);
ok('mm 소수 1자리 초과 → null(1.23456m)', spec_axes_parse('1.23456*1.0*2.0_280T*260T', $axGaegeo) === null);
eq('표시 mm 2000 → 2,000', spec_axis_display(2000.0, 'mm'), '2,000');
eq('표시 mm 1.6 → 1.6', spec_axis_display(1.6, 'mm'), '1.6');
eq('표시 m 1500 → 1.5', spec_axis_display(1500.0, 'm'), '1.5');
eq('표시 m 2000 → 2.0(원문 표기)', spec_axis_display(2000.0, 'm'), '2.0');
eq('정규 값 900.0 → 900, 1.6 → 1.6', [spec_axis_num(900.0), spec_axis_num(1.6), spec_axis_num((float) '2000.0')], ['900', '1.6', '2000']);
eq('이름+약자: 폭 W / 세로 H / 약자 없음', [spec_axis_title(['label' => '폭', 'symbol' => 'W']), spec_axis_title(['label' => '세로', 'symbol' => 'H', 'show_symbol' => true]), spec_axis_title(['label' => '기타', 'symbol' => ''])], ['폭 W', '세로 H', '기타']);
eq('약자 T 저장·표시 안 함(개거 상하두께) → 이름만', spec_axis_title(['label' => '상하두께', 'symbol' => 'T', 'show_symbol' => false]), '상하두께');
eq('단위 표시: mm → (mm), m → (m)', [spec_axis_unit_label(['unit' => 'mm']), spec_axis_unit_label(['unit' => 'm'])], ['(mm)', '(m)']);
eq('약자: 숨김(show_symbol=0)이면 빈 값', [spec_axis_symbol(['symbol' => 'W']), spec_axis_symbol(['symbol' => 'T', 'show_symbol' => false])], ['W', '']);
eq('형식(코드 끝 글자): 900B·M1000C·900·null', [spec_code_type('900B'), spec_code_type('M1000C'), spec_code_type('900'), spec_code_type(null)], ['B', 'C', null, null]);

$axRows = [256 => $axFlume, 31 => [['seq' => 0, 'axis_key' => 'none']], 61 => $axRdMh];
$axPar  = [24 => 256, 31 => 256, 256 => 11, 61 => 259, 59 => 259, 259 => 11, 11 => null, 1 => 2, 2 => 1];
ok('상속: 소분류 24 → 상위 256 이름표', count(spec_axes_resolve(24, $axRows, $axPar)) === 4);
ok('none 행: 31 → 이름표 없음(상위 끊음)', spec_axes_resolve(31, $axRows, $axPar) === []);
ok('자기 이름표 우선: 61 → 내경·높이·두께', array_column(spec_axes_resolve(61, $axRows, $axPar), 'axis_key') === ['d', 'h', 't']);
ok('이름표 없는 계통: 59 → []', spec_axes_resolve(59, $axRows, $axPar) === []);
ok('부모 순환 안전: 1↔2 → []', spec_axes_resolve(1, $axRows, $axPar) === []);

$pRow = ['spec' => '규격: 900*800*2000*85T (900B) | 중량: 1,170kg | 단위: 본'];
$plan = spec_axes_plan($pRow, $axFlume);
eq('갱신 계획: 새로 채움(안 쓰는 축은 비움)', [$plan['spec_code'] ?? null, $plan['dim_w'] ?? null, $plan['dim_t'] ?? null, array_key_exists('dim_d', (array) $plan) ? $plan['dim_d'] : 'x', $plan['spec_dims_src'] ?? null],
    ['900B', '900', '85', null, '900*800*2000*85T (900B)']);
$pSame = $pRow + ['spec_code' => '900B', 'dim_w' => '900.0', 'dim_h' => '800.0', 'dim_l' => '2000.0', 'dim_t' => '85.0', 'spec_dims_src' => '900*800*2000*85T (900B)'];
ok('갱신 계획: 이미 같으면 null', spec_axes_plan($pSame, $axFlume) === null);
ok('갱신 계획: 관리자 저장 형식(공백 없는 |)도 같은 원문', spec_axes_plan(['spec' => '규격:900*800*2000*85T (900B)|단위:본'] + $pSame, $axFlume) === null);
$pChanged = ['spec' => '규격: 수로관 특수규격 | 단위: 본'] + $pSame;
ok('갱신 계획: 규격이 바뀌고 못 읽으면 모두 비움', ($p2 = spec_axes_plan($pChanged, $axFlume)) !== null && !array_filter($p2, static fn($v) => $v !== null));
$pManual = ['spec' => '규격: 수로관 특수규격 | 단위: 본', 'dim_w' => '900.0', 'spec_dims_src' => '수로관 특수규격'];
ok('갱신 계획: 못 읽어도 원문이 같으면 사람이 넣은 값 유지(null)', spec_axes_plan($pManual, $axFlume) === null);
ok('갱신 계획: 이름표 없고 값 남음 → 비움 / 값 없음 → null', spec_axes_plan($pSame, []) !== null && spec_axes_plan($pRow, []) === null);
ok('신선도: 채운 원문 = 지금 원문일 때만', spec_axes_row_fresh($pSame) && !spec_axes_row_fresh($pChanged) && !spec_axes_row_fresh($pRow));
eq('행 값: 폭 900.0 → 900, 형식 B, 없는 축 null', [spec_axes_row_value($pSame, 'w'), spec_axes_row_value($pSame, 'type'), spec_axes_row_value($pSame, 'd')], ['900', 'B', null]);

eq('URL 입력 정리(ax_w·ax_type, 잘못된 값 버림)', spec_axes_filter_input(['ax_w' => ['900', '900.0', 'abc', '1.6'], 'ax_type' => ['b', 'BB', 'C'], 'ax_x' => ['1']]),
    ['ax' => ['w' => ['900', '1.6']], 'ax_type' => ['B', 'C']]);

$axFacet = ['defs' => $AX([['w', '폭', 'W'], ['l', '길이', 'L']]), 'all' => ['w' => ['200', '300'], 'l' => ['2000']],
            'vals' => ['w' => ['200' => 1, '300' => 0], 'l' => ['2000' => 1]], 'type_all' => ['B', 'C'], 'type' => ['B' => 1, 'C' => 1]];
$g = product_filter_axis_groups($axFacet, []);
eq('필터 그룹(아트보드 기준): 모델 코드·폭·길이', array_column($g, 'title'), ['모델 코드', '폭', '길이']);
eq('필터 그룹 옆 작은 글씨: 끝자리 표기 / W(mm) / L(mm)', array_map(static fn($x) => $x['sub'] . $x['unit'], $g), ['끝자리 표기', 'W(mm)', 'L(mm)']);
ok('값이 하나뿐인 축(길이 2,000): 선택된 채 고정', $g[2]['fixed'] && $g[2]['opts'][0]['checked'] && $g[2]['opts'][0]['disabled'] && $g[2]['opts'][0]['label'] === '2,000');
ok('0건 값은 누를 수 없음(폭 300)', $g[1]['opts'][1]['disabled'] && !$g[1]['opts'][0]['disabled']);
$g = product_filter_axis_groups($axFacet, ['ax' => ['w' => ['300']]]);
ok('이미 선택한 0건 값은 해제 가능', $g[1]['opts'][1]['checked'] && !$g[1]['opts'][1]['disabled']);
ok('형식 값 표시 B형, GET 이름 ax_type', $g[0]['opts'][0]['label'] === 'B형' && $g[0]['field'] === 'ax_type');
ok('이름표 없으면 그룹 없음', product_filter_axis_groups([], []) === []);


echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
