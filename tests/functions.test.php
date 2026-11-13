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
require_once __DIR__ . '/../app/auth.php';

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
// 목록 '상세설명' 칸 불렛 줄(관리자·직원 전용) — 임시값 메모는 빼기, 괄호 보충 설명 빼기, 말줄임 없이 항목마다 한 줄, 긴 항목은 경계에서 나눔
eq('설명 줄: 임시값 메모 + 제품 정보', product_desc_summary('[임시값] 균열2종 원문 "4800" → 1800(앞뒤 행) / 균열하중 2종 1800 · 파괴하중 2종 2700(원문, 단위 미표기)'),
    ['lines' => ['균열하중 2종 1800 · 파괴하중 2종 2700'], 'temp' => true]);
eq('설명 줄: 임시값 메모뿐이면 줄 없음', product_desc_summary('[임시값] 길이 원문 없음 → 2500(기존 DB …)'), ['lines' => [], 'temp' => true]);
eq('설명 줄: 긴 항목은 · 경계에서 나눠 모두 표시', product_desc_summary('소켓부 치수(mm): aS 311이상 · cS 284이상 · dS 27이상 · eS 30이상 · hS 210이상 · lS 50이상'),
    ['lines' => ['소켓부 치수: aS 311이상 · cS 284이상 · dS 27이상', 'eS 30이상 · hS 210이상 · lS 50이상'], 'temp' => false]);
eq('설명 줄: 긴 항목은 쉼표 경계 먼저', product_desc_summary('세로철근 D6×50개, 가로철근 D6×26개, 보강근 D16 가로 12개·세로 12개')['lines'],
    ['세로철근 D6×50개, 가로철근 D6×26개', '보강근 D16 가로 12개·세로 12개']);
eq('설명 줄: 문장 끝에서 나누고 소수점은 그대로', product_desc_summary('구성: 겉뚜껑, 속뚜껑, 몸체. 유출구 150Φ는 PVC 본드타입 / 세로철근 Ø4.0×10개')['lines'],
    ['구성: 겉뚜껑, 속뚜껑, 몸체', '유출구 150Φ는 PVC 본드타입', '세로철근 Ø4.0×10개']);
eq('설명 줄: 줄 수 제한 없음(말줄임 없음)', product_desc_summary("수량 50개/m²\n뚜껑 일체형 / 스텐304 기둥 Ø114(도면)"), ['lines' => ['수량 50개/m²', '뚜껑 일체형', '스텐304 기둥 Ø114'], 'temp' => false]);
eq('설명 줄: 빈 값·kg/m 같은 빗금은 나누지 않음', [product_desc_summary(''), product_desc_summary('단위중량 83.2kg/m')['lines']], [['lines' => [], 'temp' => false], ['단위중량 83.2kg/m']]);
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
eq('공공조달 현황: 질문 자재만, 기준일 있는 한 문장(가격·업체 이름·조달몰 언급 없음 — 2026-11-11)', [count($pc), $pc[0]['summary'] ?? ''],
    [1, '원심력 철근콘크리트관(흄관) 176개 품목(9개 업체) 중 KS 표시 146개 (2026-11-05 기준)']);
eq('긴 낱말 우선(맨홀블록 4글자 > 흄관 2글자), 그 일부로만 걸린 종류는 뺌(맨홀뚜껑 → 맨홀블록 제외)·인증 없는 종류 문구·해당 없음', [array_column(ai_procurement_summary('주철 맨홀뚜껑 크기'), 'name'),
    ai_procurement_summary('그레이팅 덮개')[0]['summary'] ?? '', ai_procurement_summary('잔디 깎는 기계'), array_column(ai_procurement_summary('맨홀 블럭이랑 흄관'), 'name')],
    [['주철 맨홀뚜껑'], '그레이팅덮개 103개 품목(15개 업체) 중 KS 표시·단체표준인증 품목 없음 (2026-11-05 기준)', [],
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
$axSqMh   = $AX([['w', '가로', 'W'], ['l', '세로', 'L'], ['h', '높이', 'H'], ['t', '두께', 'T']]);                         // 59 사각맨홀
$axRdMh   = $AX([['d_i', '내경', 'D'], ['h', '높이', 'H'], ['t', '두께', 'T']]);                                       // 61 원형맨홀
eq('수로관 900*800*2000*85T (900B) → 코드·폭·높이·길이·두께', spec_axes_parse('900*800*2000*85T (900B)', $axFlume),
    ['spec_code' => '900B', 'dims' => ['w' => 900.0, 'h' => 800.0, 'l' => 2000.0, 't' => 85.0], 'option' => null]);
eq('개거 4.0*1.0*2.0_280T*260T → m 를 mm 로, 상하두께·벽체두께', spec_axes_parse('4.0*1.0*2.0_280T*260T', $axGaegeo),
    ['spec_code' => null, 'dims' => ['w' => 4000.0, 'h' => 1000.0, 'l' => 2000.0, 't' => 280.0, 't2' => 260.0], 'option' => null]);
eq('개거 1.5*1.5*2.0_160T*140T', spec_axes_parse('1.5*1.5*2.0_160T*140T', $axGaegeo)['dims'] ?? null, ['w' => 1500.0, 'h' => 1500.0, 'l' => 2000.0, 't' => 160.0, 't2' => 140.0]);
eq('사각맨홀 300*400*600*150T → 가로·세로(l)·높이·두께', spec_axes_parse('300*400*600*150T', $axSqMh)['dims'] ?? null, ['w' => 300.0, 'l' => 400.0, 'h' => 600.0, 't' => 150.0]);
eq('원형맨홀 D900*1200*200T → 내경·높이·두께', spec_axes_parse('D900*1200*200T', $axRdMh)['dims'] ?? null, ['d_i' => 900.0, 'h' => 1200.0, 't' => 200.0]);
eq('조립식원형맨홀 D600*1000H*150T_하부구체_KS → 옵션 하부구체_KS', spec_axes_parse('D600*1000H*150T_하부구체_KS', $axRdMh),
    ['spec_code' => null, 'dims' => ['d_i' => 600.0, 'h' => 1000.0, 't' => 150.0], 'option' => '하부구체_KS']);
eq('다칼라블랜딩블록 1200*1000*60_컬러(보도) → 가로·세로·두께 + 옵션', spec_axes_parse('1200*1000*60_컬러(보도)', $AX([['w', '가로', 'W'], ['l', '세로', 'L'], ['t', '두께', 'T']])),
    ['spec_code' => null, 'dims' => ['w' => 1200.0, 'l' => 1000.0, 't' => 60.0], 'option' => '컬러(보도)']);
eq('옵션 표시: 밑줄 → 슬래시', [spec_option_display('하부구체_KS'), spec_option_display('컬러(보도)'), spec_option_display('곡선_타공_KS'), spec_option_display(''), spec_option_display('_국산')], ['하부구체/KS', '컬러(보도)', '곡선/타공/KS', '', '국산']);
eq('흄관(B형) D600*2500*50T → 내경·길이·두께', spec_axes_parse('D600*2500*50T', $AX([['d_i', '내경', 'D'], ['l', '길이', 'L'], ['t', '두께', 'T']])),
    ['spec_code' => null, 'dims' => ['d_i' => 600.0, 'l' => 2500.0, 't' => 50.0], 'option' => null]);
eq('H 접미·뒤 옵션: D600*1000H*150T_하부구체_KS', spec_axes_parse('D600*1000H*150T_하부구체_KS', $axRdMh),
    ['spec_code' => null, 'dims' => ['d_i' => 600.0, 'h' => 1000.0, 't' => 150.0], 'option' => '하부구체_KS']);
eq('코드 뒤 옵션: 400*1000*1600*80 (1000C)_벤치3종 부착형', spec_axes_parse('400*1000*1600*80 (1000C)_벤치3종 부착형', $axFlume),
    ['spec_code' => '1000C', 'dims' => ['w' => 400.0, 'h' => 1000.0, 'l' => 1600.0, 't' => 80.0], 'option' => '벤치3종 부착형']);
eq('코드가 아닌 괄호는 옵션: 200*1000*50(5*3)_국산', spec_axes_parse('200*1000*50(5*3)_국산', $AX([['w', '폭'], ['l', '길이'], ['h', '높이']])),
    ['spec_code' => null, 'dims' => ['w' => 200.0, 'l' => 1000.0, 'h' => 50.0], 'option' => '(5*3)_국산']);
eq('M 접미는 미터: D200*2M → 내경 200·길이 2000', spec_axes_parse('D200*2M', $AX([['d_i', '내경', 'D'], ['l', '길이', 'L']]))['dims'] ?? null, ['d_i' => 200.0, 'l' => 2000.0]);
eq('글자 붙은 꼬리는 옵션: D150*6000*1.6T_0RS → 두께 1.6, 옵션 0RS', spec_axes_parse('D150*6000*1.6T_0RS', $AX([['d_i', '내경', 'D'], ['l', '길이', 'L'], ['t', '두께', 'T']])),
    ['spec_code' => null, 'dims' => ['d_i' => 150.0, 'l' => 6000.0, 't' => 1.6], 'option' => '0RS']);
ok('한글이 붙은 숫자(300용)는 숫자 아님 → null', spec_axes_parse('300용 (440*500*80T_무공)', $AX([['w', '폭', 'W']])) === null);
ok('숫자 개수가 이름표와 다르면 null: D200*2M (…) vs 수로관 4축', spec_axes_parse('D200*2M (320*320*2000*60T)', $axFlume) === null);
ok('D 접두는 지름(내경 d_i·외경 d_o) 축만: D900*… vs 수로관 → null', spec_axes_parse('D900*800*2000*85T', $axFlume) === null);
ok('T 접미는 두께 축만: 900T*… → null', spec_axes_parse('900T*800*2000*85', $axFlume) === null);
ok('H 접미는 높이 축만: 사각맨홀 300H*… → null', spec_axes_parse('300H*400*600*150T', $axSqMh) === null);
ok('글자로 시작(수로관집게_800A) → null', spec_axes_parse('수로관집게_800A', $axFlume) === null);
ok('빈 값·이름표 없음 → null', spec_axes_parse('', $axFlume) === null && spec_axes_parse('900*800*2000*85T', []) === null);
ok('none 행만 → null', spec_axes_parse('900*800', [['seq' => 0, 'axis_key' => 'none', 'unit' => 'mm']]) === null);
ok('mm 소수 1자리 초과 → null(1.23456m)', spec_axes_parse('1.23456*1.0*2.0_280T*260T', $axGaegeo) === null);
eq('표시 mm 2000 → 2000(천 단위 쉼표 없음)', spec_axis_display(2000.0, 'mm'), '2000');
eq('표시 mm 12500.5 → 12500.5', spec_axis_display(12500.5, 'mm'), '12500.5');
eq('표시 mm 1.6 → 1.6', spec_axis_display(1.6, 'mm'), '1.6');
eq('표시 m 1500 → 1.5', spec_axis_display(1500.0, 'm'), '1.5');
eq('표시 m 2000 → 2.0(원문 표기)', spec_axis_display(2000.0, 'm'), '2.0');
eq('정규 값 900.0 → 900, 1.6 → 1.6', [spec_axis_num(900.0), spec_axis_num(1.6), spec_axis_num((float) '2000.0')], ['900', '1.6', '2000']);
eq('이름+약자: 폭 W / 세로 L / 약자 없음 → 이름만', [spec_axis_title(['label' => '폭', 'symbol' => 'W']), spec_axis_title(['label' => '세로', 'symbol' => 'L', 'show_symbol' => true]), spec_axis_title(['label' => '기타', 'symbol' => null])], ['폭 W', '세로 L', '기타']);
eq('약자 T 저장·표시 안 함(개거 상하두께) → 이름만', spec_axis_title(['label' => '상하두께', 'symbol' => 'T', 'show_symbol' => false]), '상하두께');
eq('단위 표시: mm → (mm), m → (m)', [spec_axis_unit_label(['unit' => 'mm']), spec_axis_unit_label(['unit' => 'm'])], ['(mm)', '(m)']);
eq('약자: 숨김(show_symbol=0)이면 빈 값', [spec_axis_symbol(['symbol' => 'W']), spec_axis_symbol(['symbol' => 'T', 'show_symbol' => false])], ['W', '']);
eq('형식(코드 끝 글자): 900B·M1000C·900·null', [spec_code_type('900B'), spec_code_type('M1000C'), spec_code_type('900'), spec_code_type(null)], ['B', 'C', null, null]);

$axRows = [256 => $axFlume, 31 => [['seq' => 0, 'axis_key' => 'none']], 61 => $axRdMh];
$axPar  = [24 => 256, 31 => 256, 256 => 11, 61 => 259, 59 => 259, 259 => 11, 11 => null, 1 => 2, 2 => 1];
ok('상속: 소분류 24 → 상위 256 이름표', count(spec_axes_resolve(24, $axRows, $axPar)) === 4);
ok('none 행: 31 → 이름표 없음(상위 끊음)', spec_axes_resolve(31, $axRows, $axPar) === []);
ok('자기 이름표 우선: 61 → 내경·높이·두께', array_column(spec_axes_resolve(61, $axRows, $axPar), 'axis_key') === ['d_i', 'h', 't']);
ok('이름표 없는 계통: 59 → []', spec_axes_resolve(59, $axRows, $axPar) === []);
ok('부모 순환 안전: 1↔2 → []', spec_axes_resolve(1, $axRows, $axPar) === []);

$pRow = ['spec' => '규격: 900*800*2000*85T (900B) | 중량: 1,170kg | 단위: 본'];
$plan = spec_axes_plan($pRow, $axFlume);
eq('갱신 계획: 새로 채움(안 쓰는 축은 비움)', [$plan['spec_code'] ?? null, $plan['dim_w'] ?? null, $plan['dim_t'] ?? null, array_key_exists('dim_d_i', (array) $plan) ? $plan['dim_d_i'] : 'x', $plan['spec_dims_src'] ?? null],
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
eq('행 값: 폭 900.0 → 900, 형식 B, 없는 축 null', [spec_axes_row_value($pSame, 'w'), spec_axes_row_value($pSame, 'type'), spec_axes_row_value($pSame, 'd_i')], ['900', 'B', null]);

ok('범위 표기(65~125)는 숫자 하나로 읽지 않음 → null', spec_axes_parse('230*114*65~125_조경경계블록_레드', $AX([['w', '가로', 'W'], ['l', '세로', 'L'], ['t', '두께', 'T']])) === null);
eq('외경 D(d_o): PHC파일 D500*5000*80T', spec_axes_parse('D500*5000*80T', $AX([['d_o', '외경', 'D'], ['l', '길이', 'L'], ['t', '두께', 'T']]))['dims'] ?? null, ['d_o' => 500.0, 'l' => 5000.0, 't' => 80.0]);
eq('깊이 D(depth): 환경수로관 400*400*2000*120T (400C)', spec_axes_parse('400*400*2000*120T (400C)', $AX([['w', '폭', 'W'], ['depth', '깊이', 'D'], ['l', '길이', 'L'], ['t', '두께', 'T']])),
    ['spec_code' => '400C', 'dims' => ['w' => 400.0, 'depth' => 400.0, 'l' => 2000.0, 't' => 120.0], 'option' => null]);
eq('높이·뒷폭·깊이 함께: 보강토마감형 500*100*300*400_회색', spec_axes_parse('500*100*300*400_회색', $AX([['w', '폭', 'W'], ['h', '높이', 'H'], ['w_r', '뒷폭', 'WR'], ['depth', '깊이', 'D']])),
    ['spec_code' => null, 'dims' => ['w' => 500.0, 'h' => 100.0, 'w_r' => 300.0, 'depth' => 400.0], 'option' => '회색']);
eq('상부폭 WT·하부폭 WB: 경계석 150*170*200(A)', spec_axes_parse('150*170*200(A)', $AX([['w_t', '상부폭', 'WT'], ['w_b', '하부폭', 'WB'], ['h', '높이', 'H']])),
    ['spec_code' => null, 'dims' => ['w_t' => 150.0, 'w_b' => 170.0, 'h' => 200.0], 'option' => '(A)']);
ok('D 접두는 깊이(depth)에 안 됨: 400*D400 → null', spec_axes_parse('400*D400', $AX([['w', '폭', 'W'], ['depth', '깊이', 'D']])) === null);
eq('축 키 → 칸: 내경·외경·하부폭·뒷폭·상부폭·깊이', [SPEC_AXIS_COLUMNS['d_i'], SPEC_AXIS_COLUMNS['d_o'], SPEC_AXIS_COLUMNS['w_b'], SPEC_AXIS_COLUMNS['w_r'], SPEC_AXIS_COLUMNS['w_t'], SPEC_AXIS_COLUMNS['depth']],
    ['dim_d_i', 'dim_d_o', 'dim_w_b', 'dim_w_r', 'dim_w_t', 'dim_depth']);
eq('URL 입력: 새 축 키(ax_d_i·ax_w_b·ax_depth)', spec_axes_filter_input(['ax_d_i' => ['150'], 'ax_w_b' => ['170'], 'ax_depth' => ['400'], 'ax_dd' => ['1']])['ax'], ['d_i' => ['150'], 'w_b' => ['170'], 'depth' => ['400']]);


// 표기 모양이 다른 카테고리(사용자 결정 2026-11-06) — parse_fmt·fixed_value·skip
$AXF = static fn(string $fmt, array $defs): array => array_map(static fn($r) => $r + ['parse_fmt' => $fmt], $AX($defs));
$axRing = $AXF('paren', [['d_i', '내경', 'D'], ['l', '길이', 'L', 'm'], ['d_o', '외경', 'D'], ['depth', '깊이', 'D'], ['skip', '', ''], ['t', '두께', 'T']]);
eq('paren 원형사각수로관 D200*2M (320*360*2000*60T)_곡선_타공 → 내경·길이(m)·외경·깊이·두께, 괄호 안 길이는 skip', spec_axes_parse('D200*2M (320*360*2000*60T)_곡선_타공', $axRing),
    ['spec_code' => null, 'dims' => ['d_i' => 200.0, 'l' => 2000.0, 'd_o' => 320.0, 'depth' => 360.0, 't' => 60.0], 'option' => '곡선_타공']);
eq('paren 0.5M → 길이 500', spec_axes_parse('D300*0.5M (460*470*500*80T)', $axRing)['dims']['l'] ?? null, 500.0);
eq('paren 앞 글자는 옵션: 빗물받이용 (400*500*80T_유공) → 옵션 빗물받이용_유공(화면 빗물받이용/유공)', spec_axes_parse('빗물받이용 (400*500*80T_유공)', $AXF('paren', [['d_o', '외경', 'D'], ['l', '길이', 'L'], ['t', '두께', 'T']])),
    ['spec_code' => null, 'dims' => ['d_o' => 400.0, 'l' => 500.0, 't' => 80.0], 'option' => '빗물받이용_유공']);
eq('paren 숫자로 시작하는 글자(300측구용)도 옵션', spec_axes_parse('300측구용 (395*500*50T_무공)', $AXF('paren', [['d_o', '외경', 'D'], ['l', '길이', 'L'], ['t', '두께', 'T']]))['option'] ?? null, '300측구용_무공');
eq('paren 그리드 6T (2m*50m 1롤) → 인장강도 6(t/m 그대로)·폭 2m·길이 50m, 1롤은 버림', spec_axes_parse('6T (2m*50m 1롤)', $AXF('paren', [['ts', '인장강도', '', 't/m'], ['w', '폭', 'W', 'm'], ['l', '길이', 'L', 'm']])),
    ['spec_code' => null, 'dims' => ['ts' => 6.0, 'w' => 2000.0, 'l' => 50000.0], 'option' => null]);
eq('paren 원심력사각수로관 D250 (340*330*2500*40T)', spec_axes_parse('D250 (340*330*2500*40T)', $AXF('paren', [['d_i', '내경', 'D'], ['d_o', '외경', 'D'], ['h', '높이', 'H'], ['l', '길이', 'L'], ['t', '두께', 'T']]))['dims'] ?? null,
    ['d_i' => 250.0, 'd_o' => 340.0, 'h' => 330.0, 'l' => 2500.0, 't' => 40.0]);
eq('lead_code 디자인블록 K3MB_120*120*120 → 모델 코드 K3MB', spec_axes_parse('K3MB_120*120*120', $AXF('lead_code', [['w', '가로', 'W'], ['l', '세로', 'L'], ['depth', '깊이', 'D']])),
    ['spec_code' => 'K3MB', 'dims' => ['w' => 120.0, 'l' => 120.0, 'depth' => 120.0], 'option' => null]);
$axMesh = $AXF('mesh', [['w', '가로', 'W'], ['l', '세로', 'L'], ['mesh', '망 간격', 'M'], ['wire_no', '철선 번호', '', 'no'], ['wire_d', '철선 직경', 'D']]);
eq('mesh 와이어메쉬 1800*1800_150각_#8(Ø3.5)BS', spec_axes_parse('1800*1800_150각_#8(Ø3.5)BS', $axMesh),
    ['spec_code' => 'BS', 'dims' => ['w' => 1800.0, 'l' => 1800.0, 'mesh' => 150.0, 'wire_no' => 8.0, 'wire_d' => 3.5], 'option' => null]);
$axSlash = $AXF('slash', [['w', '폭', 'W'], ['h_high', '상단 높이', 'H'], ['h_low', '하단 높이', 'H'], ['l', '길이', 'L']]);
$axSlash[3]['fixed_value'] = 1000;
eq('slash 화강경계경사 150*200/100_곡선_#377 → 폭·상단/하단 높이·길이 1000(고정)·모델 코드 G377(# → G)', spec_axes_parse('150*200/100_곡선_#377', $axSlash),
    ['spec_code' => 'G377', 'dims' => ['w' => 150.0, 'h_high' => 200.0, 'h_low' => 100.0, 'l' => 1000.0], 'option' => '곡선']);
$pk = spec_axes_parse('150*200/100(국산)_곡선', $axSlash);
eq('slash (국산)_곡선 → 코드 없음, 옵션 표시 국산/곡선', [is_array($pk) && array_key_exists('spec_code', $pk) ? $pk['spec_code'] : 'x', spec_option_display((string) ($pk['option'] ?? ''))], [null, '국산/곡선']);
ok('형식이 안 맞으면 null(lead_code 에 코드 없음, mesh 에 각 없음)', spec_axes_parse('390*120*120', $AXF('lead_code', [['w', '가로'], ['l', '세로'], ['depth', '깊이']])) === null
    && spec_axes_parse('1800*1800_150_#8(Ø3.5)BS', $axMesh) === null);
eq('표시: 철선 번호 #8, 인장강도 단위 (t/m), 번호는 단위 없음', [spec_axis_display(8.0, 'no'), spec_axis_unit_label(['unit' => 't/m']), spec_axis_unit_label(['unit' => 'no'])], ['#8', '(t/m)', '']);
eq('모델 코드 필터 방식: 끝 글자(default) / 코드 전체(lead_code·mesh·slash)', [spec_axes_code_key($axFlume), spec_axes_code_key($axMesh), spec_axes_code_key($axRing)], ['type', 'code', 'type']);
eq('URL 입력: 모델 코드 전체 ax_code(K2·#377), 이상한 값 버림', spec_axes_filter_input(['ax_code' => ['K2', '#377', 'a b', str_repeat('x', 31)]])['ax_code'], ['K2', '#377']);
// 화강경계도로·낮춤(hash), PVC소형맨홀·오수받이(pipe_port) — 사용자 결정 2026-11-09
$axHash = $AXF('hash', [['w', '폭', 'W'], ['h', '높이', 'H']]);
eq('hash 화강경계도로 100*100_곡선_#377 → 모델 코드 G377, 옵션 곡선', spec_axes_parse('100*100_곡선_#377', $axHash), ['spec_code' => 'G377', 'dims' => ['w' => 100.0, 'h' => 100.0], 'option' => '곡선']);
eq('hash 100*100(국산)_곡선 → 코드 없음, 옵션 표시 국산/곡선', spec_option_display((string) (spec_axes_parse('100*100(국산)_곡선', $axHash)['option'] ?? '')), '국산/곡선');
eq('hash 는 모델 코드 전체 필터', spec_axes_code_key($axHash), 'code');
$axPvc = $AXF('pipe_port', [['d_i', '내경', 'D'], ['h', '높이', 'H'], ['d_connect', '접속구 지름', '']]);
eq('pipe_port PVC소형맨홀 D300*900H*VG200(4구)사각몸통 → 접속구 VG200, 옵션 접속4구(사각몸통)', spec_axes_parse('D300*900H*VG200(4구)사각몸통', $axPvc),
    ['spec_code' => null, 'dims' => ['d_i' => 300.0, 'h' => 900.0, 'd_connect' => 'VG200'], 'option' => '접속4구(사각몸통)']);
eq('pipe_port 뒤 표기 없으면 (일반), DC200', spec_axes_parse('D300*900H*DC200(4구)', $axPvc)['option'] ?? null, '접속4구(일반)');
$axSew = $AXF('pipe_port', [['d_i', '내경', 'D'], ['h', '높이', 'H'], ['d_inlet', '유입구 지름', ''], ['d_outlet', '유출구 지름', '']]);
eq('pipe_port PVC오수받이 D300*900H*VG150/DC200(유입3구/유출1구)사각몸통', spec_axes_parse('D300*900H*VG150/DC200(유입3구/유출1구)사각몸통', $axSew),
    ['spec_code' => null, 'dims' => ['d_i' => 300.0, 'h' => 900.0, 'd_inlet' => 'VG150', 'd_outlet' => 'DC200'], 'option' => '유입3구/유출1구(사각몸통)']);
ok('pipe_port 관 개수가 이름표와 다르면 null', spec_axes_parse('D300*900H*VG150(4구)', $axSew) === null);
eq('글자 값 축: 표시 그대로·단위 없음·URL 입력 VG150 만', [spec_axis_value_display(['axis_key' => 'd_connect'], 'VG150'), spec_axis_unit_label(['axis_key' => 'd_inlet', 'unit' => 'mm']), spec_axes_filter_input(['ax_d_connect' => ['VG150', '150', 'vg 1']])['ax']['d_connect'] ?? null], ['VG150', '', ['VG150']]);
eq('갱신 계획: 글자 값 축은 글자 그대로 저장', (spec_axes_plan(['spec' => '규격: D300*900H*VG150(4구) | 단위: 개'], $axPvc) ?? [])['dim_d_connect'] ?? null, 'VG150');
// 원본 사이트 상세 표 기준(loose — 값의 T·H 표기를 뜻 확인에 쓰지 않음) — 사용자 결정 2026-11-09
$axLoose = $AXF('loose', [['w', '가로', 'W'], ['l', '세로', 'L'], ['h', '높이', 'H']]);
eq('loose 잔디블록 220*220*80T_회색 → 80 은 높이(T 표기 무시)', spec_axes_parse('220*220*80T_회색', $axLoose), ['spec_code' => null, 'dims' => ['w' => 220.0, 'l' => 220.0, 'h' => 80.0], 'option' => '회색']);
eq('loose 수목보호틀 1000*1000*H100_… → 앞에 붙은 H 도 숫자', spec_axes_parse('1000*1000*H100_받침틀(인조석)_정사각형', $axLoose)['dims'] ?? null, ['w' => 1000.0, 'l' => 1000.0, 'h' => 100.0]);
eq('loose 조립식사각맨홀 900*900*1000H*200T → 1000 은 깊이', spec_axes_parse('900*900*1000H*200T_하부구체_KS', $AXF('loose', [['w', '가로', 'W'], ['l', '세로', 'L'], ['depth', '깊이', 'D'], ['t', '두께', 'T']]))['dims'] ?? null,
    ['w' => 900.0, 'l' => 900.0, 'depth' => 1000.0, 't' => 200.0]);
ok('default 는 지금처럼 엄격(80T 를 높이로 읽지 않음, H100 안 읽음)', spec_axes_parse('220*220*80T', $AX([['w', '가로'], ['l', '세로'], ['h', '높이']])) === null && spec_axes_parse('1000*1000*H100', $AX([['w', '가로'], ['l', '세로'], ['h', '높이']])) === null);
ok('loose 도 D 접두는 지름 축만', spec_axes_parse('D220*220*80', $axLoose) === null);
// 두께 칸 나눔(사용자 결정 2026-11-09): 상하두께 t_tb·벽체두께 t_wall(개거), 하부·상부두께(조경경계블록, 형식 range)
eq('개거 새 칸: 상하두께 t_tb·벽체두께 t_wall', spec_axes_parse('1.5*1.5*2.0_160T*140T', $AX([['w', '폭', 'W', 'm'], ['h', '높이', 'H', 'm'], ['l', '길이', 'L', 'm'], ['t_tb', '상하두께', 'T'], ['t_wall', '벽체두께', 'T']]))['dims'] ?? null,
    ['w' => 1500.0, 'h' => 1500.0, 'l' => 2000.0, 't_tb' => 160.0, 't_wall' => 140.0]);
eq('range 조경경계블록 230*114*65~125_조경경계블록_레드 → 하부두께 65·상부두께 125', spec_axes_parse('230*114*65~125_조경경계블록_레드', $AXF('range', [['w', '가로', 'W'], ['l', '세로', 'L'], ['t_bottom', '하부두께', 'T'], ['t_top', '상부두께', 'T']])),
    ['spec_code' => null, 'dims' => ['w' => 230.0, 'l' => 114.0, 't_bottom' => 65.0, 't_top' => 125.0], 'option' => '조경경계블록_레드']);
eq('갱신 계획: 예전 칸(dim_t2)은 다시 채울 때 비움', (static function () use ($AX) { $p = ['spec' => '규격: 1.5*1.5*2.0_160T*140T', 'dim_t' => '160.0', 'dim_t2' => '140.0'];
    $n = spec_axes_plan($p, $AX([['w', '폭', 'W', 'm'], ['h', '높이', 'H', 'm'], ['l', '길이', 'L', 'm'], ['t_tb', '상하두께', 'T'], ['t_wall', '벽체두께', 'T']])); return [$n['dim_t'] ?? 'x', $n['dim_t2'] ?? 'x', $n['dim_t_tb'], $n['dim_t_wall']]; })(), ['x', 'x', '160', '140']);
// 직경 d·바닥직경 d_base(고정 글자)·세로 v·험프석 slash 4칸 — 사용자 결정 2026-11-09
eq('원형디자인 D648*50*4T → 직경 D 648·높이·두께', spec_axes_parse('D648*50*4T_우수_국산', $AX([['d', '직경', 'D'], ['h', '높이', 'H'], ['t', '두께', 'T']])),
    ['spec_code' => null, 'dims' => ['d' => 648.0, 'h' => 50.0, 't' => 4.0], 'option' => '우수_국산']);
$axBol = $AX([['d', '직경', 'D'], ['d_base', '바닥직경', 'D'], ['h', '높이', 'H']]); $axBol[1]['fixed_text'] = '142~145';
eq('우레탄볼라드 D116*850(…) → 직경 116·바닥직경 142~145(고정 글자)·높이 850', spec_axes_parse('D116*850(매립형우레탄볼라드)', $axBol),
    ['spec_code' => null, 'dims' => ['d' => 116.0, 'd_base' => '142~145', 'h' => 850.0], 'option' => '(매립형우레탄볼라드)']);
eq('칼라맨홀뚜껑 800*800*D648*H60*4T(loose) → 가로·세로 V·원형부직경·높이·두께', spec_axes_parse('800*800*D648*H60*4T_우수_국산', $AXF('loose', [['w', '가로', 'W'], ['v', '세로', 'V'], ['d', '원형부직경', ''], ['h', '높이', 'H'], ['t', '두께', 'T']]))['dims'] ?? null,
    ['w' => 800.0, 'v' => 800.0, 'd' => 648.0, 'h' => 60.0, 't' => 4.0]);
eq('세로 V 와 길이 L 함께: 배수경계석 180*200*1000', spec_axes_parse('180*200*1000(국산)', $AX([['w', '폭', 'W'], ['v', '세로', 'V'], ['l', '길이', 'L']]))['dims'] ?? null, ['w' => 180.0, 'v' => 200.0, 'l' => 1000.0]);
eq('slash 험프석 300*200/100*1000(국산) → 폭·상단/하단 높이·길이', spec_axes_parse('300*200/100*1000(국산)', $AXF('slash', [['w', '폭', 'W'], ['h_high', '상단 높이', 'H'], ['h_low', '하단 높이', 'H'], ['l', '길이', 'L']])),
    ['spec_code' => null, 'dims' => ['w' => 300.0, 'h_high' => 200.0, 'h_low' => 100.0, 'l' => 1000.0], 'option' => '(국산)']);
eq('축 키 → 칸: 직경·바닥직경·세로', [SPEC_AXIS_COLUMNS['d'], SPEC_AXIS_COLUMNS['d_base'], SPEC_AXIS_COLUMNS['v']], ['dim_d', 'dim_d_base', 'dim_v']);
// 앞 글자 → 옵션(lead_text)·모양별(shape)·고정값 — 사용자 결정 2026-11-09
eq('lead_text 앵글 25바용*3T*4M → 폭 30(고정)·두께 3·길이 4000, 옵션 25바용', spec_axes_parse('25바용*3T*4M', (static function () use ($AXF) { $a = $AXF('lead_text', [['w', '폭', 'W'], ['t', '두께', 'T'], ['l', '길이', 'L']]); $a[0]['fixed_value'] = 30; return $a; })()),
    ['spec_code' => null, 'dims' => ['w' => 30.0, 't' => 3.0, 'l' => 4000.0], 'option' => '25바용']);
eq('lead_text 주철뚜껑 600Ø*h70_B.S_보도용_오수 → 직경 600·높이 70', spec_axes_parse('600Ø*h70_B.S_보도용_오수', $AXF('lead_text', [['d', '직경', 'D'], ['h', '높이', 'H']])),
    ['spec_code' => null, 'dims' => ['d' => 600.0, 'h' => 70.0], 'option' => 'B.S_보도용_오수']);
eq('lead_text 부직포(200g)_2m*50m_KS기준 → 폭 2m·길이 50m, 옵션 200g_KS기준(이름 뗌)', spec_axes_parse('부직포(200g)_2m*50m_KS기준', $AXF('lead_text', [['w', '폭', 'W', 'm'], ['l', '길이', 'L', 'm']])),
    ['spec_code' => null, 'dims' => ['w' => 2000.0, 'l' => 50000.0], 'option' => '200g_KS기준']);
eq('lead_text 스텐바리케이드 2.6M×H1100 → 폭 2.6m·높이 1.1m(m 이름표에서 100 이상 숫자는 mm)', spec_axes_parse('아치형 스텐접이식 바리케이드 2.6M×H1100', $AXF('lead_text', [['w', '폭', 'W', 'm'], ['h', '높이', 'H', 'm']]))['dims'] ?? null, ['w' => 2600.0, 'h' => 1100.0]);
$axShape = $AXF('shape', [['d', '직경', 'D'], ['w', '가로', 'W'], ['v', '세로', 'V'], ['h', '높이', 'H']]);
eq('shape 볼라드 원형 D400*400 / 사각 400*400*400', [spec_axes_parse('원형 D400*400', $axShape), spec_axes_parse('사각 400*400*400', $axShape)['dims'] ?? null],
    [['spec_code' => null, 'dims' => ['d' => 400.0, 'h' => 400.0], 'option' => '원형'], ['w' => 400.0, 'v' => 400.0, 'h' => 400.0]]);
ok('lead_text 숫자 하나뿐(버너 30T 외 규격)은 이름표 칸 수와 달라 null', spec_axes_parse('버너 30T 외 규격', $AXF('lead_text', [['w', '폭', 'W'], ['v', '세로', 'V'], ['t', '두께', 'T']])) === null);
eq('옵션 표시: 통째 괄호 조각은 괄호 뗌, 글자에 붙은 괄호는 그대로', [spec_option_display('(B)'), spec_option_display('(SA)_곡선'), spec_option_display('(고강도콘크리트_점형_KS)'), spec_option_display('(7*4)_국산'), spec_option_display('컬러(보도)'), spec_option_display('0RS(유공)')],
    ['B', 'SA/곡선', '고강도콘크리트/점형/KS', '7*4/국산', '컬러(보도)', '0RS(유공)']);
eq('URL 입력 정리(ax_w·ax_type, 잘못된 값 버림)', spec_axes_filter_input(['ax_w' => ['900', '900.0', 'abc', '1.6'], 'ax_type' => ['b', 'BB', 'C'], 'ax_x' => ['1']]),
    ['ax' => ['w' => ['900', '1.6']], 'ax_type' => ['B', 'C'], 'ax_code' => []]);

$axFacet = ['defs' => $AX([['w', '폭', 'W'], ['l', '길이', 'L']]), 'all' => ['w' => ['200', '300'], 'l' => ['2000']],
            'vals' => ['w' => ['200' => 1, '300' => 0], 'l' => ['2000' => 1]], 'type_all' => ['B', 'C'], 'type' => ['B' => 1, 'C' => 1]];
$g = product_filter_axis_groups($axFacet, []);
eq('필터 그룹(아트보드 기준): 모델 코드·폭·길이', array_column($g, 'title'), ['모델 코드', '폭', '길이']);
eq('필터 그룹 옆 작은 글씨: 끝자리 표기 / W(mm) / L(mm)', array_map(static fn($x) => $x['sub'] . $x['unit'], $g), ['끝자리 표기', 'W(mm)', 'L(mm)']);
ok('값이 하나뿐인 축(길이 2000): 선택된 채 고정', $g[2]['fixed'] && $g[2]['opts'][0]['checked'] && $g[2]['opts'][0]['disabled'] && $g[2]['opts'][0]['label'] === '2000');
ok('0건 값은 누를 수 없음(폭 300)', $g[1]['opts'][1]['disabled'] && !$g[1]['opts'][0]['disabled']);
$g = product_filter_axis_groups($axFacet, ['ax' => ['w' => ['300']]]);
ok('이미 선택한 0건 값은 해제 가능', $g[1]['opts'][1]['checked'] && !$g[1]['opts'][1]['disabled']);
ok('형식 값 표시 B형, GET 이름 ax_type', $g[0]['opts'][0]['label'] === 'B형' && $g[0]['field'] === 'ax_type');
ok('이름표 없으면 그룹 없음', product_filter_axis_groups([], []) === []);

echo "[필터 펼치기/접기(비로그인·개인회원) render_product_filters]\n";
eq('적용된 필터 개수: 재질1+축2+모델코드1+재고', product_filters_applied_count(['mat' => [11], 'ax' => ['w' => ['600', '700']], 'ax_type' => ['B']], true), 5);
$rf = static function (array $a, bool $collapsible, bool $inStock = false): string {
    ob_start();
    render_product_filters($a, null, 24, '', ['mat' => [], 'shape' => [], 'load' => [], 'spec' => [], 'total' => 0, 'axes' => []], $inStock, [], $collapsible);
    return (string) ob_get_clean();
};
$h = $rf([], true);
ok('상황별 비로그인·개인: 접을 수 있는 필터 "자재 규격", 적용 없으면 접힌 채 시작', str_contains($h, 'class="fltbox is-collapsible">') && str_contains($h, '<span class="flt-sum-title">자재 규격</span>') && str_contains($h, 'flt-sum-state') && !str_contains($h, 'flt-sum-cnt'));
ok('접는 필터: 위 "필터로 좁히기·초기화" 줄 없음, 맨 아래 초기화 버튼(적용 없으면 비활성) + 필터 적용', !str_contains($h, 'class="flt-top"') && str_contains($h, '<div class="flt-actions">') && str_contains($h, 'class="flt-reset" href="/products.php?category=24" role="button" aria-disabled="true" tabindex="-1"') && str_contains($h, 'data-applied="0"'));
$h = $rf(['ax' => ['w' => ['600']]], true, true);
ok('적용된 필터가 있으면 펼친 채 시작, "N개 적용" 글자 없음, 초기화 켜짐', str_contains($h, 'class="fltbox is-collapsible" open>') && !str_contains($h, '개 적용') && !str_contains($h, 'flt-sum-cnt') && str_contains($h, 'role="button"><svg') && str_contains($h, 'data-applied="1"'));
$h = $rf([], false);
ok('그 밖의 목록·기업회원: 지금처럼 항상 펼침, 제목 "필터로 좁히기", 위 초기화 링크 유지', str_contains($h, 'class="fltbox" open>') && !str_contains($h, 'flt-sum-state') && str_contains($h, '<span class="flt-sum-title">필터로 좁히기</span>') && str_contains($h, '<div class="flt-top"><b>필터로 좁히기</b><a href="/products.php?category=24">초기화</a></div>') && !str_contains($h, 'flt-actions') && !str_contains($h, 'data-applied'));
$hx = (static function (): string { ob_start(); render_product_filters([], null, 24, '', ['mat' => [], 'shape' => [], 'load' => [], 'spec' => [], 'total' => 10,
    'axes' => ['defs' => [['seq' => 1, 'axis_key' => 'w', 'label' => '폭', 'symbol' => 'W', 'unit' => 'mm', 'use_filter' => true]], 'all' => ['w' => ['200', '300']], 'vals' => ['w' => ['200' => 4, '300' => 6]], 'type_all' => [], 'type' => []]]); return (string) ob_get_clean(); })();
ok('규격 값 버튼에 개수 표시 없음(값만)', str_contains($hx, '<span class="fchip-box">200</span>') && !str_contains($hx, '<small>4</small>'));
ok('값 조합 데이터가 없으면 즉시 계산 안 함(적용 후 서버 계산만)', !str_contains($hx, 'flt-combos') && !str_contains($hx, 'data-live'));
$axLive = ['defs' => [['seq' => 1, 'axis_key' => 'w', 'label' => '폭', 'symbol' => 'W', 'unit' => 'mm', 'use_filter' => true], ['seq' => 2, 'axis_key' => 'l', 'label' => '길이', 'symbol' => 'L', 'unit' => 'mm', 'use_filter' => true]],
    'all' => ['w' => ['200', '300'], 'l' => ['2000']], 'vals' => ['w' => ['200' => 4, '300' => 6], 'l' => ['2000' => 10]], 'type_all' => [], 'type' => [],
    'combos' => ['keys' => ['w', 'l', 'type'], 'rows' => [['200', '2000', '', 1], ['300', '2000', '', 0]]]];
$hl = (static function (array $ax): string { ob_start(); render_product_filters([], null, 24, '', ['mat' => [], 'shape' => [], 'load' => [], 'spec' => [], 'total' => 10, 'axes' => $ax], false, [], true); return (string) ob_get_clean(); })($axLive);
ok('접는 필터: 값 조합(JSON)·data-live 를 넣어 누르는 즉시 0건 값 비활성', str_contains($hl, 'data-live="1"') && str_contains($hl, '<script type="application/json" class="flt-combos">{"keys":["w","l","type"],"rows":[["200","2000","",1],["300","2000","",0]]}</script>'));
ok('값이 하나뿐인 축 버튼은 data-fixed(즉시 계산에서 제외)', str_contains($hl, 'value="2000" checked disabled data-fixed="1"') && !str_contains($hl, 'value="200" data-fixed'));
$hc = (static function (array $ax): string { ob_start(); render_product_filters([], null, 24, '', ['mat' => [], 'shape' => [], 'load' => [], 'spec' => [], 'total' => 10, 'axes' => $ax]); return (string) ob_get_clean(); })($axLive);
ok('기업회원·그 밖의 목록(접지 않는 필터)도 누르는 즉시 비활성, 초기화는 위 링크 그대로', str_contains($hc, 'data-live="1"') && str_contains($hc, 'class="flt-combos"') && str_contains($hc, '<div class="flt-top"><b>필터로 좁히기</b><a href="/products.php?category=24">초기화</a></div>') && !str_contains($hc, 'flt-actions') && !str_contains($hc, 'flt-reset'));
$hs = (static function (): string { ob_start(); render_product_filters([], null, 24, '', ['mat' => [], 'shape' => [], 'load' => [], 'spec' => ['300*300' => 3, '400*400' => 2], 'total' => 5, 'axes' => []]); return (string) ob_get_clean(); })();
ok('규격 원문 목록(이름표 없는 카테고리): 값 옆 개수 없음', str_contains($hs, '<span class="fopt-lbl">300×300</span></label>') && !str_contains($hs, 'class="fcnt"'));

echo "[AI 상담 2026-11-09 운영 오류: 그레이팅 하중 등급 ai_grating_load_class·ai_filter_load_class]\n";
eq('안전중하중 → 대형차', ai_grating_load_class('안전중하중그레이팅수로용'), 'truck');
eq('중하중 → 승용차', ai_grating_load_class('중하중그레이팅맨홀(집수정)용'), 'car');
eq('그 밖의 그레이팅 → 보행자', [ai_grating_load_class('그레이팅수로용'), ai_grating_load_class('EX메탈망그레이팅'), ai_grating_load_class('안전그레이팅')], ['pedestrian', 'pedestrian', 'pedestrian']);
eq('그레이팅이 아니면 등급 없음', [ai_grating_load_class('사각주철(수로용/닥타일)'), ai_grating_load_class('PE배수관')], ['', '']);
eq('통행 조건 값: 허용 값만', [ai_traffic_value('Pedestrian'), ai_traffic_value('car'), ai_traffic_value('bus'), ai_traffic_value(null)], ['pedestrian', 'car', '', '']);
$gr = [['id' => 1, 'name' => '그레이팅수로용'], ['id' => 2, 'name' => '중하중그레이팅수로용'], ['id' => 3, 'name' => '안전중하중그레이팅수로용'], ['id' => 4, 'name' => '사각주철(수로용/닥타일)']];
[$keep, $heavy] = ai_filter_load_class($gr, 'pedestrian');
eq('보행자: 보행자용 + 등급 없는 상품만 남고, 차량용은 높은 등급으로 따로', [array_column($keep, 'id'), array_column($heavy, 'id')], [[1, 4], [2, 3]]);
[$keep, $heavy] = ai_filter_load_class($gr, 'car');
eq('승용차: 보행자용(안전 부족)은 버리고, 안전중하중은 과잉으로 따로', [array_column($keep, 'id'), array_column($heavy, 'id')], [[2, 4], [3]]);
[$keep, $heavy] = ai_filter_load_class($gr, 'truck');
eq('대형차: 안전중하중 + 등급 없는 상품만', [array_column($keep, 'id'), $heavy], [[3, 4], []]);
eq('통행 조건 없으면 그대로', ai_filter_load_class($gr, '')[0], $gr);

echo "[AI 상담 2026-11-11: 하중 판정 근거 ai_load_status — 상품명 등급·등록 규격 용도 표기만, 근거 없으면 '확인 필요'(등급을 만들지 않음)]\n";
$lr = [
    'b'  => ['name' => '주철뚜껑(우수/오수/전기/통신)', 'spec' => '규격: 600Ø*h70_B.S_보도용_오수 | 중량: 28kg | 단위: 조'],
    'r'  => ['name' => '주철뚜껑(우수/오수/전기/통신)', 'spec' => '규격: 600Ø*h70_B.S_차도용_오수 | 중량: 32kg | 단위: 조'],
    'b2' => ['name' => '조화(디자인)맨홀뚜껑', 'spec' => '규격: 멀티맨홀원형D648*60T*h80보도(우수) | 중량: 54kg | 단위: 조'],
    'r2' => ['name' => '조화(디자인)맨홀뚜껑', 'spec' => '규격: 멀티맨홀원형D648*80T*h100차도(우수) | 중량: 64kg | 단위: 조'],
    'u'  => ['name' => '칼라맨홀뚜껑', 'spec' => '규격: 600Ø | 단위: 조'],
    'ch' => ['name' => '수로관(벤치3종)', 'spec' => '규격: 200*200*2000*40T (200C) | 단위: 본'],
    'md' => ['name' => '수로관(벤치3종)몰드', 'spec' => '규격: 1000*1000 | 단위: 조'],
    'bk' => ['name' => '다칼라블랜딩블록(투수)', 'spec' => '규격: 1200*1000*60_컬러(보도) | 단위: m 2'],
    'hp' => ['name' => '흄관', 'spec' => '규격: D300*2500 | 단위: 본'],
    'gc' => ['name' => '중하중그레이팅수로용', 'spec' => '규격: 400*1000*50'],
    'gt' => ['name' => '안전중하중그레이팅수로용', 'spec' => '규격: 400*1000*50'],
    'gp' => ['name' => '그레이팅수로용', 'spec' => '규격: 400*1000*25'],
];
$ls = static fn(string $t): array => array_map(static fn($r) => ai_load_status($r, $t)['status'], $lr);
eq('규격 용도 표기: 보도용·…보도(우수) → 보행자, 차도용·…차도(우수) → 차도, 품목 이름의 보도(보도블록·보차도)는 아님',
    array_map('ai_spec_use_class', ['규격: 600Ø*h70_B.S_보도용_오수', '규격: 멀티맨홀원형D648*60T*h80보도(우수)', '규격: 멀티맨홀원형D648*80T*h100차도(우수)', '규격: 보도블록용 300*300', '규격: 보차도경계석 180*205', '규격: 300*300']),
    ['pedestrian', 'pedestrian', 'road', '', '', '']);
eq('승용차 통행: 보도용 뚜껑·보행자 그레이팅은 낮은 등급, 차도용·중하중은 맞음, 근거 없는 뚜껑·수로관은 확인 필요, 몰드·블록·흄관은 판정 대상 아님',
    $ls('car'), ['b' => 'lighter', 'r' => 'ok', 'b2' => 'lighter', 'r2' => 'ok', 'u' => 'unverified', 'ch' => 'unverified', 'md' => 'na', 'bk' => 'na', 'hp' => 'na', 'gc' => 'ok', 'gt' => 'heavier', 'gp' => 'lighter']);
eq("대형차 통행: '차도용'은 톤수가 없어 확인 필요(맞다고 하지 않음), 안전중하중만 맞음",
    $ls('truck'), ['b' => 'lighter', 'r' => 'unverified', 'b2' => 'lighter', 'r2' => 'unverified', 'u' => 'unverified', 'ch' => 'unverified', 'md' => 'na', 'bk' => 'na', 'hp' => 'na', 'gc' => 'lighter', 'gt' => 'ok', 'gp' => 'lighter']);
eq('보행자 통행: 근거 없는 덮개·수로관은 판정 안 함(과잉 등급만 따로)',
    $ls('pedestrian'), ['b' => 'ok', 'r' => 'heavier', 'b2' => 'ok', 'r2' => 'heavier', 'u' => 'na', 'ch' => 'na', 'md' => 'na', 'bk' => 'na', 'hp' => 'na', 'gc' => 'heavier', 'gt' => 'heavier', 'gp' => 'ok']);
eq('판정 근거 표시', [ai_load_status($lr['gc'], 'car')['basis'], ai_load_status($lr['r'], 'car')['basis'], ai_load_status($lr['r'], 'truck')['basis'], ai_load_status($lr['u'], 'car')['basis']], ['상품명 등급', '규격 표기(차도용)', '규격 표기(차도용, 톤수 없음)', '하중 정보 없음']);
[$keep] = ai_filter_load_class(array_values($lr), 'car');
eq('승용차: 낮은 등급(보도용·보행자 그레이팅)은 표에서 빼고, 확인 필요 제품은 표시(_load)와 함께 남김', array_map(static fn($r) => $r['name'] . ':' . $r['_load'], $keep),
    ['주철뚜껑(우수/오수/전기/통신):ok', '조화(디자인)맨홀뚜껑:ok', '칼라맨홀뚜껑:unverified', '수로관(벤치3종):unverified', '수로관(벤치3종)몰드:na', '다칼라블랜딩블록(투수):na', '흄관:na', '중하중그레이팅수로용:ok']);
eq('블록 하중은 이 판정에 넣지 않음(80T 규칙 그대로)', [ai_load_class_of($lr['bk']), ai_load_relevant('다칼라블랜딩블록(투수)'), ai_load_relevant('보도블록'), ai_load_relevant('경계석')], ['', false, false, false]);
$rr = [['id' => 10, 'name' => '중하중그레이팅수로용', 'spec' => '규격: W400*L1000*H50'], ['id' => 12, 'name' => '그레이팅수로용', 'spec' => '규격: W400*L1000*H25'], ['id' => 14, 'name' => '칼라맨홀뚜껑', 'spec' => '규격: W400*L400'], ['id' => 13, 'name' => 'EX메탈망그레이팅', 'spec' => '규격: W500*L1000*H25']];
$rk = ai_recheck($rr, ['w' => 400.0], 'car');
eq('앞서 추천한 제품 재확인(폭 400·승용차): 맞음 / 하중 부족 / 하중 확인 필요 / 폭 다름', array_column($rk, 'status'), ['ok', 'load_lighter', 'load_unverified', 'dim_mismatch']);
eq('재확인 안내: 맞지 않게 된 제품과 확인이 필요한 제품을 나눠 이유와 함께', ai_recheck_note($rk), '앞서 보여 드린 제품 중 그레이팅수로용(승용차 통행 조건보다 낮은 하중 등급(보행자용)), EX메탈망그레이팅(폭 400mm 조건과 치수가 다름)은(는) 이번 조건에 맞지 않아 표에서 뺐습니다. 앞서 보여 드린 칼라맨홀뚜껑(하중 등급 확인 필요(하중 정보 없음))은(는) 이번 조건에 맞는지 확인이 필요합니다.');
eq('모두 맞으면 안내 없음', ai_recheck_note([['id' => 1, 'name' => 'A', 'status' => 'ok', 'reason' => '']]), '');
eq('표의 검색 조건 기록(다음 턴 조건 유지·금액 비교 기준)', ai_search_cond('그레이팅', ['w' => 400.0, 'l' => 1000.0], 0, 20.0, 0.0, 'car'), ['query' => '그레이팅', 'width_mm' => 400.0, 'product_length_mm' => 1000.0, 'length_m' => 20.0, 'traffic' => 'car']);

echo "[AI 상담: 이미 본 제품 제외 ai_int_list·ai_exclude_shown]\n";
eq('id 목록 정리(양수·중복 제거·문자 숫자 허용)', ai_int_list([3, '7', 0, -1, 3, 'x', 9.0]), [3, 7, 9]);
eq('배열이 아니면 빈 목록', ai_int_list('3,4'), []);
[$keep, $hit] = ai_exclude_shown([['id' => 10, 'name' => 'A'], ['id' => 11, 'name' => 'A'], ['id' => 12, 'name' => 'B']], [10], [10 => 'A']);
eq('뺀 id 와 같은 상품명(다른 규격)도 뺌', [array_column($keep, 'id'), $hit], [[12], ['A']]);

echo "[AI 상담 2026-11-10: 치수 입력 단위 ai_parse_mm·이름 붙은 규격 ai_spec_labeled_dims·숫자 포함 ai_spec_has_mm]\n";
eq('단위: 500·"50cm"·"0.5m"·"50센테"·"2미터"·"1,000mm"', [ai_parse_mm(500), ai_parse_mm('50cm'), ai_parse_mm('0.5m'), ai_parse_mm('50센테'), ai_parse_mm('2미터'), ai_parse_mm('1,000mm')], [500.0, 500.0, 500.0, 500.0, 2000.0, 1000.0]);
eq('읽을 수 없거나 0 이하면 null', [ai_parse_mm('abc'), ai_parse_mm(0), ai_parse_mm(-5), ai_parse_mm(null), ai_parse_mm('폭500')], [null, null, null, null, null]);
eq('이름 붙은 값만 읽음(W·L·폭·길이, m 단위)', [ai_spec_labeled_dims('규격: W500*L1000*H50'), ai_spec_labeled_dims('규격: 폭500_길이2M'), ai_spec_labeled_dims('규격: 500*1000*50(900B)')], [['w' => 500.0, 'l' => 1000.0], ['w' => 500.0, 'l' => 2000.0], []]);
eq('규격에 그 숫자가 있는지(위치 무관·미터 표기)', [ai_spec_has_mm('규격: 400*500*50', 500.0), ai_spec_has_mm('규격: 400*1500*50', 500.0), ai_spec_has_mm('규격: 2M*30m 1롤', 2000.0), ai_spec_has_mm('규격: 2.0*1.5', 2000.0)], [true, false, true, false]);

echo "[AI 상담: 폭·길이 판정 ai_dim_check — 이름표로 채운 치수 칸 우선, 숫자 위치로 정하지 않음]\n";
// 이름표로 채운 행(채운 기준 원문 = 지금 규격 원문). 그레이팅 이름표: 폭 W × 길이 L × 높이 H
$fr = static fn(string $reg, array $dims, int $price = 1000, string $name = 'G', string $unit = '개'): array => ['name' => $name, 'spec' => "규격: {$reg} | 단위: {$unit}", 'price_b2c' => $price, 'spec_dims_src' => $reg] + $dims;
$A = $fr('500*400*50', ['dim_w' => 500, 'dim_l' => 400, 'dim_h' => 50]);    // 폭 500 × 길이 400
$B = $fr('400*500*50', ['dim_w' => 400, 'dim_l' => 500, 'dim_h' => 50]);    // 폭 400 × 길이 500
$C = $fr('500*1000*50', ['dim_w' => 500, 'dim_l' => 1000, 'dim_h' => 50]);  // 폭 500 × 길이 1000
$st = static fn(array $r, array $w): string => ai_dim_check($r, $w)['status'];
eq('폭만 500: A(500×400)·C(500×1000) 맞음, B(400×500) 다름', [$st($A, ['w' => 500.0]), $st($B, ['w' => 500.0]), $st($C, ['w' => 500.0])], ['match', 'mismatch', 'match']);
eq('길이만 500: B 맞음, A·C 다름', [$st($A, ['l' => 500.0]), $st($B, ['l' => 500.0]), $st($C, ['l' => 500.0])], ['mismatch', 'match', 'mismatch']);
eq('폭 500·길이 1000 동시: C 만', [$st($A, ['w' => 500.0, 'l' => 1000.0]), $st($B, ['w' => 500.0, 'l' => 1000.0]), $st($C, ['w' => 500.0, 'l' => 1000.0])], ['mismatch', 'mismatch', 'match']);
$D = $fr('500*500*50', ['dim_w' => 500, 'dim_l' => 500, 'dim_h' => 50]);
eq('같은 숫자가 여러 치수(500×500): 폭·길이 모두 맞음, 높이 50 만 같은 폭 50 은 다름', [$st($D, ['w' => 500.0]), $st($D, ['l' => 500.0]), $st($D, ['w' => 50.0])], ['match', 'match', 'mismatch']);
$U = ['name' => 'X', 'spec' => '규격: 400*500*50 | 단위: 개'];
eq('치수 칸 없음(이름표 없는 규격): 숫자가 있으면 확인 불가, 없으면 다름', [$st($U, ['w' => 500.0]), $st($U, ['w' => 600.0])], ['unknown', 'mismatch']);
$S2 = ['name' => 'X', 'spec' => '규격: 500*400*50 | 단위: 개', 'spec_dims_src' => '400*500*50', 'dim_w' => 400, 'dim_l' => 500];
eq('문자 규격과 치수 칸 불일치(원문이 바뀜): 옛 칸(폭 400)을 믿지 않고 확인 불가', $st($S2, ['w' => 500.0]), 'unknown');
$L = ['name' => 'X', 'spec' => '규격: W500*L1000 | 단위: 개'];
eq('칸이 없어도 이름 붙은 규격(W500*L1000)은 확인', [$st($L, ['w' => 500.0]), $st($L, ['w' => 400.0]), $st($L, ['w' => 500.0, 'l' => 1000.0])], ['match', 'mismatch', 'match']);
$M = $fr('2.0*1.5*2.0_160T*140T', ['dim_w' => 2000, 'dim_h' => 1500, 'dim_l' => 2000, 'dim_t_tb' => 160, 'dim_t_wall' => 140], 1000, '개거', '본');
eq('단위가 다른 규격(개거 m 표기 2.0 → 폭 2000mm)', [$st($M, ['w' => 2000.0]), $st($M, ['w' => ai_parse_mm('2m')]), $st($M, ['w' => 1500.0])], ['match', 'match', 'mismatch']);
$K = $fr('150*180*1000', ['dim_w_t' => 150, 'dim_w_b' => 180, 'dim_h' => 1000], 1000, '경계석');
eq('폭 칸 없이 상부폭·하부폭만(경계석): 같은 값이면 확인 불가, 아니면 다름', [$st($K, ['w' => 150.0]), $st($K, ['w' => 300.0])], ['unknown', 'mismatch']);
$P = $fr('D500*2500*50T', ['dim_d_i' => 500, 'dim_l' => 2500, 'dim_t' => 50], 1000, '흄관', '본');
eq('폭이 없는 규격 체계(흄관 내경 D500): 폭 500 아님', $st($P, ['w' => 500.0]), 'mismatch');
[$ok, $unk, $mis] = ai_filter_dims([$B, ['name' => 'G'] + $U, $A, ['name' => 'Y', 'spec' => '규격: 500*30'], $C], ['w' => 500.0]);
eq('나누기: 확인 2·확인 불가 1(같은 상품명 G 는 확인된 규격이 있어 뺌, Y 만)·다름 1', [count($ok), array_column($unk, 'name'), $mis, $ok[0]['_dim']], [2, ['Y'], 1, 'match']);
eq('조건 표기', [ai_dim_cond_label(['w' => 500.0]), ai_dim_cond_label(['w' => 500.0, 'l' => 1000.0]), ai_dim_cond_label([], 80), ai_dim_cond_label([])], ['폭 500mm', '폭 500mm·길이 1000mm', '규격 80mm', '']);
$ax = [['axis_key' => 'w', 'label' => '폭', 'symbol' => 'W', 'unit' => 'mm'], ['axis_key' => 'l', 'label' => '길이', 'symbol' => 'L', 'unit' => 'mm'], ['axis_key' => 'h', 'label' => '높이', 'symbol' => 'H', 'unit' => 'mm']];
eq('이름표대로 읽은 치수(GPT 설명용)', [ai_row_size_label($B, $ax), ai_row_size_label($U, $ax)], ['폭 W 400mm · 길이 L 500mm · 높이 H 50mm', '']);
eq('상품명별 확인된 폭(오름차순·확인 불가 제외)', ai_widths_by_name([$fr('250*1000*25', ['dim_w' => 250, 'dim_l' => 1000], 1, 'A'), $fr('150*1000*25', ['dim_w' => 150, 'dim_l' => 1000], 1, 'A'), $fr('150*1000*32', ['dim_w' => 150, 'dim_l' => 1000], 1, 'A'), ['name' => 'Z', 'spec' => '규격: 300*1000*25']]), ['A' => ['150', '250']]);
eq('폭 표기', ai_format_widths(['그레이팅수로용' => ['150', '200', '250']]), '그레이팅수로용 150·200·250mm');

echo "[AI 상담: 설치 길이 → 수량은 이름표의 길이 L 로(숫자 크기로 짐작하지 않음)·금액 비교 키 ai_cost_key·대표 규격]\n";
eq('길이 L 500 그레이팅 10m → 20개', ai_estimate_quantity($D, 10.0, null, null), [20, '10m ÷ 0.5m = 20개']);
eq('길이 L 995(날개형) 10m → 11개', ai_estimate_quantity($fr('285*995*25', ['dim_w' => 285, 'dim_l' => 995, 'dim_h' => 25]), 10.0, null, null)[0], 11);
eq('개거 길이 2.0m(2000mm) 6m → 3본(칸이 없을 때는 계산 못 하던 m 표기)', ai_estimate_quantity($M, 6.0, null, null)[0], 3);
eq('칸이 없는 상품은 기존 규칙(1m 미만이면 계산 안 함)', [ai_estimate_quantity(['spec' => '규격: 500*500*50 | 단위: 개'], 10.0, null, null)[0], ai_estimate_quantity(['spec' => '규격: 500*1000*50 | 단위: 개'], 10.0, null, null)[0]], [null, 10]);
$g10 = ['spec' => '규격: 500*1000*50 | 단위: 개'];
$g05 = ['spec' => '규격: 500*500*50 | 단위: 개'];
ok('예상 자재비가 계산되는 규격이 단가만 있는 규격보다 앞(500*500 단가 36,900 < 500*1000 10개 759,000 이어도)', ai_cost_key($g10, 75900, 10.0, 500.0, null) < ai_cost_key($g05, 36900, 10.0, 500.0, null));
ok('가격 문의(0원)는 맨 뒤', ai_cost_key($g05, 36900, 10.0, 500.0, null) < ai_cost_key($g10, 0, 10.0, 500.0, null));
$reps = ai_pick_representatives([$fr('500*500*50', ['dim_w' => 500, 'dim_l' => 500], 36900, 'EX'), $fr('500*1000*50', ['dim_w' => 500, 'dim_l' => 1000], 75900, 'EX')], 10.0, 500.0, null);
ok('대표 규격: 같은 폭이면 길이 L 로 수량 계산 → 10m 당 더 싼 500×500(20개 738,000원 < 10개 759,000원)', count($reps) === 1 && $reps[0]['price_b2c'] === 36900);
$reps = ai_pick_representatives([['name' => 'EX', 'spec' => $g05['spec'], 'price_b2c' => 36900], ['name' => 'EX', 'spec' => $g10['spec'], 'price_b2c' => 75900]], 10.0, 500.0, null);
ok('대표 규격: 폭을 확인할 수 없는 규격(칸 없음)은 숫자 위치로 같은 폭이라 보지 않고 기존 규칙', count($reps) === 1 && $reps[0]['price_b2c'] === 36900);
$reps = ai_pick_representatives([['name' => 'F', 'spec' => '규격: 흄관D200~D800*PVC관D75 | 단위: 개', 'price_b2c' => 30800], ['name' => 'F', 'spec' => '규격: 흄관D450~D1000*PE관D150 | 단위: 개', 'price_b2c' => 19100]], 30.0, null, null);
ok('대표 규격: 폭을 읽을 수 없는 변형(관경 표기)은 기존 규칙 그대로', count($reps) === 1 && $reps[0]['price_b2c'] === 30800);

echo "[AI 상담: 하중 등급·제외로 빈 결과 안내 ai_filter_empty_note·ai_broadened_note·ai_missing_terms_note]\n";
$hv = [['id' => 1, 'name' => '안전중하중그레이팅수로용', 'spec' => '규격: 500*1000*50 | 단위: 개']];
$n = ai_filter_empty_note('그레이팅 수로용', '폭 500mm', 'pedestrian', $hv, [], ['안전중하중그레이팅수로용' => ['price' => 136700, 'unit' => '개']], ['그레이팅수로용' => ['150', '200', '250']]);
ok('과잉 등급만: 등록 없음·높은 등급 이름·가격·고객 조건 등록 폭·고객에게 확인', str_contains($n, "보행자용 '그레이팅 수로용' 제품은 폭 500mm가 등록되어 있지 않습니다") && str_contains($n, '안전중하중그레이팅수로용(대형차용, 136,700원/개부터)')
    && str_contains($n, '그레이팅수로용 150·200·250mm') && str_contains($n, '높은 등급 제품도 보실지 알려 주세요') && !str_contains($n, 'traffic'));
$n = ai_filter_empty_note('그레이팅 수로용', '폭 500mm', 'truck', [], ['안전중하중그레이팅수로용']);
eq('다른 제품 없음(제외)', $n, '직전에 보여 드린 안전중하중그레이팅수로용 외에는 이 조건(폭 500mm)에 맞는 다른 제품이 없습니다.');
eq('같은 폭 같은 등급으로 바꿀 때: 이유 먼저 → 대안(제품 이름 작은따옴표, 2026-11-12)', ai_broadened_note('그레이팅 수로용', '폭 500mm', 'pedestrian', $hv),
    "보행자용 '그레이팅 수로용' 제품은 폭 500mm가 없습니다. 같은 폭의 '그레이팅 수로용'은 '안전중하중그레이팅수로용'(대형차용)뿐이라 필요보다 높은 하중 등급이어서 표에서 뺐습니다. 대안으로 같은 폭의 보행자용 '그레이팅' 제품을 보여 드립니다.");
eq('같은 폭 다른 제품으로 바꿀 때(직전 표 제외)', ai_broadened_note('그레이팅 수로용', '폭 200mm', 'pedestrian', [], ['그레이팅수로용']), "보행자용 '그레이팅 수로용' 제품은 폭 200mm에서 직전에 보여 드린 '그레이팅수로용' 외에는 없습니다. 대안으로 같은 폭의 다른 보행자용 '그레이팅' 제품을 보여 드립니다.");
eq('없는 말 안내', ai_missing_terms_note(['날개형' => ['플륨관용(날개형U-TYPE)' => ['185', '285']]], '폭 500mm'), "'날개형'이(가) 들어간 제품은 이 조건(폭 500mm)에서 찾지 못해 표에 없습니다(등록: 플륨관용(날개형U-TYPE) 185·285mm). 표의 제품은 그 제품이 아닙니다.");
eq('폭 조건 0건 안내(조건이 다른 제품을 대신 보이지 않음·등록 폭)', ai_dim_no_match_text('그레이팅 수로용', '폭 700mm', ['그레이팅수로용' => ['150', '200', '250']], '폭'), "폭 700mm로 확인된 '그레이팅 수로용' 제품이 없습니다. 조건이 다른 제품은 보여 드리지 않았습니다. 등록된 폭은 그레이팅수로용 150·200·250mm입니다. 규격을 다시 확인해 주세요.");
eq('검색어 중 표에 없는 말', ai_missing_terms('그레이팅 수로용 날개형', [['name' => '안전중하중그레이팅수로용', 'spec' => '규격: 500*1000*50']]), ['날개형']);
eq('모두 있으면 없음', ai_missing_terms('그레이팅 수로용', [['name' => '그레이팅수로용', 'spec' => '규격: 150*1000*25']]), []);
eq('용도어(우수)·분류어(수로관)는 없는 제품이라 하지 않음(2026-11-10 오탐)', [ai_missing_terms('맨홀 우수 중하중', [['name' => '중하중그레이팅맨홀(집수정)용', 'spec' => '규격: 400*500*50']]), ai_missing_terms('U형 수로관', [['name' => 'U형플륨관(벤치1종)', 'spec' => '규격: 310*275*2000*32T']])], [[], []]);
eq('형태·재질 말은 그대로 확인(체크무늬·알루미늄·타공)', ai_missing_terms('그레이팅 체크무늬 알루미늄 타공', [['name' => '안전중하중그레이팅수로용', 'spec' => '규격: 500*1000*50(7*4)_체크무늬_국산']]), ['알루미늄', '타공']);

echo "[AI 상담: 고객 통행 조건 rag_traffic_context]\n";
$u = static fn(string ...$t): array => array_map(static fn($x) => ['role' => 'user', 'content' => $x], $t);
eq('식당 주방 → 보행자', rag_traffic_context($u('식당 주방에 배수관 뚜껑이 필요해요', '10미터길이에 50센테 폭이야')), 'pedestrian');
eq("'보행자 전용으로' → 보행자", rag_traffic_context($u('배수로 덮개', '보행자 전용으로')), 'pedestrian');
eq("'차는 안 다녀요' → 보행자", rag_traffic_context($u('마당 배수로 그레이팅', '차는 안 다녀요')), 'pedestrian');
eq('주차장·승용차 → 승용차', rag_traffic_context($u('주차장, 승용차, 30개')), 'car');
eq('지게차·트럭·5톤 → 대형차', [rag_traffic_context($u('공장 지게차 다니는 곳')), rag_traffic_context($u('트럭은 들어와요')), rag_traffic_context($u('우수, 5톤, 잘 몰라'))], ['truck', 'truck', 'truck']);
eq('안전 쪽: 주방이라도 차량 단서가 있으면 차량', rag_traffic_context($u('식당 주방 앞 주차장 배수로')), 'car');
eq("'트럭은 안 다니고 승용차만' → 승용차", rag_traffic_context($u('트럭은 안 다니고 승용차만 다녀요')), 'car');
eq("'보도로 사람만 다녀요'('보도로' ≠ 도로) → 보행자", rag_traffic_context($u('공원 보도로 사람만 다녀요')), 'pedestrian');
eq('도로 측구 → 승용차', rag_traffic_context($u('도로 측구 덮개')), 'car');
eq('단서 없음 → 필터 안 함', rag_traffic_context($u('그레이팅 폭 300 길이 20m')), '');
eq('AI 말은 보지 않음', rag_traffic_context([['role' => 'assistant', 'content' => '주방이면 보행자 전용입니다'], ['role' => 'user', 'content' => '그레이팅 300']]), '');

echo "[AI 상담: '다른 제품' 요청 rag_wants_alternative·'더 저렴한' rag_wants_cheaper]\n";
foreach (['A말고 다른 자재 추천해줘', '과한 제품인거 같은데? 다시 추천', '다른 거 보여 주세요', '딴 거 없어요?', '대체품 있나요'] as $q) { ok("'{$q}' → 다른 제품 요청", rag_wants_alternative($q)); }
foreach (['날개형은 안돼??', '다른 규격으로 보여줘', '두 번째 거 20개 담아줘', '견적에 담지 말고 보여만 줘'] as $q) { ok("'{$q}' → 아님", !rag_wants_alternative($q)); }
foreach (['다른 제품도 추천해줘', '앞에 추천한 제품은 제외해줘', '그거 빼고 보여줘', '좀 더 저렴한 제품', '앞에 보여준 제품은 빼고 다른 제품 추천해줘.', '조건은 그대로 두고 좀 더 저렴한 제품으로 찾아줘.'] as $q) { ok("'{$q}' → 다른 제품 요청", rag_wants_alternative($q)); }
foreach (['저렴한 제품부터 추천해줘', '사람만 다니는 곳이야', '폭은 500mm이고 길이는 10m야', '가격이 저렴해도 차량 통행에 맞지 않으면 제외해줘.'] as $q) { ok("'{$q}' → 아님(정렬·조건 말)", !rag_wants_alternative($q)); }
eq("'더 저렴한 제품' 판정", [rag_wants_cheaper('좀 더 저렴한 제품'), rag_wants_cheaper('더 싼 거 없어?'), rag_wants_cheaper('저렴한 거 있어요?'), rag_wants_cheaper('저렴한 제품부터 추천해줘'), rag_wants_cheaper('다른 제품도 추천해줘'), rag_wants_cheaper('비싼 거 있어?'), rag_wants_cheaper('더 비싼 건 없어?'), rag_wants_cheaper('가격이 저렴해도 차량 통행에 맞지 않으면 제외해줘.')], [true, true, true, false, false, false, false, false]);
eq('직전 추천표 최저 예상 자재비(단가×권장 수량, 비교 가능한 것만)', [rag_recent_min_est([['price' => 36900, 'qty' => 20], ['price' => 108900, 'qty' => 10], ['price' => null, 'qty' => 5], ['price' => 9000, 'qty' => null]]), rag_recent_min_est([['price' => 9000, 'qty' => null]])], [738000, 0]);
eq('더 저렴한 제품 없음 안내(비교 기준 밝힘)', ai_cheaper_note(738000, ['EX메탈망그레이팅'], '폭 500mm', true), '직전에 보여 드린 EX메탈망그레이팅 외에 폭 500mm 조건에 맞으면서 직전 추천표의 가장 낮은 예상 자재비(738,000원)보다 저렴한 제품은 없습니다(같은 설치 길이·면적 기준, 운반·시공비 별도).');
eq('수량·비교 질문 판정', [rag_wants_quantity('설치 구간이 총 20m야. 몇 개 필요해?'), rag_wants_quantity('수량 알려줘'), rag_wants_quantity('폭 500 그레이팅'), rag_wants_compare('최종적으로 비교해줘'), rag_wants_compare('어느 게 나아?'), rag_wants_compare('차량이 다니는 곳이야')], [true, true, false, true, true, false]);

echo "[AI 상담 2026-11-11: 통행 조건 확실성 rag_traffic_info — 분명한 말(strong)·장소로 짐작(weak)·마지막 말]\n";
eq("'차량이 다니는 곳에 설치할 수 있는 제품' → 승용차·분명·마지막 말", rag_traffic_info($u('폭 500mm, 길이 1m 그레이팅 찾아줘.', '차량이 다니는 곳에 설치할 수 있는 제품으로 추천해줘.')), ['class' => 'car', 'strength' => 'strong', 'last_strong' => true]);
eq("'차량 통행에 맞지 않으면 제외' → 승용차·분명", rag_traffic_info($u('가격이 저렴해도 차량 통행에 맞지 않으면 제외해줘.'))['class'] . '/' . rag_traffic_info($u('가격이 저렴해도 차량 통행에 맞지 않으면 제외해줘.'))['strength'], 'car/strong');
eq('나중에 말한 분명한 조건이 이김(차량 → 차는 안 다녀요)', rag_traffic_info($u('차량이 다녀요', '아 차는 안 다녀요'))['class'], 'pedestrian');
eq('앞 말의 분명한 조건은 유지·마지막 말이 아님', rag_traffic_info($u('트럭이 다니는 곳', '폭은 400으로 바꿔줘')), ['class' => 'truck', 'strength' => 'strong', 'last_strong' => false]);
eq('장소로 짐작(주차장·주방)은 weak', [rag_traffic_info($u('주차장 배수로'))['strength'], rag_traffic_info($u('식당 주방 배수로'))['strength'], rag_traffic_info($u('그레이팅'))['strength']], ['weak', 'weak', '']);

echo "[AI 상담 2026-11-11: 고객이 분명히 말한 폭·제품 1개 길이·설치 총 길이 rag_explicit_conditions]\n";
$x = static fn(string ...$t): array => rag_explicit_conditions($u(...$t));
eq("'폭 500mm, 길이 1m 그레이팅' → 폭 500·제품 길이 1000(설치 길이 아님)", $x('폭 500mm, 길이 1m 그레이팅 찾아줘.')['cond'], ['width_mm' => 500.0, 'product_length_mm' => 1000.0]);
$e3 = $x('폭 500mm, 길이 1m 그레이팅 찾아줘.', '차량이 다니는 곳에 설치할 수 있는 제품으로 추천해줘.', '폭은 400으로 바꾸고 길이는 그대로 해줘.');
eq("'폭은 400으로 바꾸고 길이는 그대로' → 폭만 400(마지막 말), 제품 길이 1000 유지", [$e3['cond'], $e3['last']], [['width_mm' => 400.0, 'product_length_mm' => 1000.0], ['width_mm']]);
$e4 = $x('폭 500mm, 길이 1m 그레이팅 찾아줘.', '설치 구간이 총 20m야. 몇 개 필요해?');
eq("'설치 구간이 총 20m' → 설치 길이 20m(제품 길이와 따로)", [$e4['cond'], $e4['last']], [['width_mm' => 500.0, 'product_length_mm' => 1000.0, 'length_m' => 20.0], ['length_m']]);
eq("'폭 500인 수로관인데 길이는 2m인 제품' → 폭 500·제품 길이 2000", $x('폭 500인 수로관인데 길이는 2m인 제품 찾아줘.')['cond'], ['width_mm' => 500.0, 'product_length_mm' => 2000.0]);
eq('설치 길이 표현(총·연장·구간·m 설치)', [$x('연장 50m')['cond'], $x('20m 구간이에요')['cond'], $x('20m 설치해야 해')['cond'], $x('총 길이 35m')['cond']], [['length_m' => 50.0], ['length_m' => 20.0], ['length_m' => 20.0], ['length_m' => 35.0]]);
eq('제품 길이 표현(짜리·1개·제품 길이·L 표기)', [$x('길이 2m짜리')['cond'], $x('1개 길이는 1m야')['cond'], $x('제품 길이 1000')['cond'], $x('W500 L1000 그레이팅')['cond']], [['product_length_mm' => 2000.0], ['product_length_mm' => 1000.0], ['product_length_mm' => 1000.0], ['width_mm' => 500.0, 'product_length_mm' => 1000.0]]);
eq("어느 길이인지 말에 없으면 넣지 않음('길이는 10m야'·'10미터길이에 50센테 폭'·'길이 10m 그레이팅')", [$x('길이는 10m야')['cond'], $x('10미터길이에 50센테 폭이야')['cond'], $x('길이 10m 그레이팅 20개')['cond']], [[], ['width_mm' => 500.0], []]);
eq('높이·직경·관경·두께는 폭·길이로 만들지 않음', [$x('높이 500 그레이팅')['cond'], $x('직경 500 흄관')['cond'], $x('관경 300')['cond'], $x('두께 80T 블록')['cond'], $x('L형 측구')['cond']], [[], [], [], [], []]);
eq('단위(cm·m)·나중 값 우선', [$x('폭 50cm')['cond'], $x('폭 300', '아 폭이 0.4m 예요')['cond'], $x('폭 0.5m 길이 1000mm 그레이팅')['cond']], [['width_mm' => 500.0], ['width_mm' => 400.0], ['width_mm' => 500.0, 'product_length_mm' => 1000.0]]);
eq("'폭은 상관없어' → 폭 조건 지움", [$x('폭 500 그레이팅', '폭은 상관없어')['cond'], $x('폭 500 그레이팅', '폭은 상관없어')['released']], [[], ['width_mm' => true]]);
eq('AI 말은 보지 않음', rag_explicit_conditions([['role' => 'assistant', 'content' => '폭 500mm 제품입니다'], ['role' => 'user', 'content' => '그레이팅']])['cond'], []);

echo "[AI 상담 2026-11-11: 검색 입력 준비 rag_prepare_search_input — GPT 가 칸을 헷갈리거나 빠뜨려도 고객이 말한 조건만 바로잡음]\n";
$cx = static fn(array $msgs, array $extra = []): array => $extra + ['explicit' => rag_explicit_conditions($msgs), 'traffic' => rag_traffic_info($msgs), 'recent_cards' => [], 'recent_cond' => [], 'exclude' => [], 'cheaper' => false, 'only_recent' => false, 'recheck' => false];
$prep = static fn(array $in, array $c): array => rag_prepare_search_input($in, $c)[0];
$m1 = $u('폭 500mm, 길이 1m 그레이팅 찾아줘.');
eq("① '길이 1m'을 설치 길이(length_m 1)로, 폭을 spec_mm 으로 넘김 → 제품 길이 1000·폭 500", $prep(['query' => '그레이팅', 'spec_mm' => 500, 'length_m' => 1], $cx($m1)), ['query' => '그레이팅', 'product_length_mm' => 1000.0, 'width_mm' => 500.0]);
eq('① 바르게 넘기면 그대로(바꾼 내용 없음)', rag_prepare_search_input(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000], $cx($m1)), [['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000], [], '']);
$rc  = [['id' => 11, 'price' => 30000, 'qty' => null, 'unit' => '개'], ['id' => 12, 'price' => 45000, 'qty' => null, 'unit' => '개']];
$c1  = ['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000];
$m2  = $u('폭 500mm, 길이 1m 그레이팅 찾아줘.', '차량이 다니는 곳에 설치할 수 있는 제품으로 추천해줘.');
eq('② 차량 조건만 말함 → 통행 car, 폭·제품 길이 유지, 앞 표 재확인', $prep(['query' => '그레이팅'], $cx($m2, ['recent_cards' => $rc, 'recent_cond' => $c1, 'recheck' => true])), ['query' => '그레이팅', 'traffic' => 'car', 'width_mm' => 500.0, 'product_length_mm' => 1000.0, 'recheck_ids' => [11, 12]]);
eq('② GPT 가 통행을 반대로(pedestrian) 넘기면 고객 말(car)로', $prep(['query' => '그레이팅', 'traffic' => 'pedestrian', 'width_mm' => 500, 'product_length_mm' => 1000], $cx($m2))['traffic'], 'car');
$m3 = array_merge($m2, $u('폭은 400으로 바꾸고 길이는 그대로 해줘.'));
$c2 = $c1 + ['traffic' => 'car'];
eq('③ GPT 가 옛 폭(500)을 다시 넘김 → 400, 제품 길이·통행 유지', $prep(['query' => '그레이팅', 'width_mm' => 500], $cx($m3, ['recent_cards' => $rc, 'recent_cond' => $c2])), ['query' => '그레이팅', 'width_mm' => 400.0, 'traffic' => 'car', 'product_length_mm' => 1000.0]);
$m4 = array_merge($m3, $u('설치 구간이 총 20m야. 몇 개 필요해?'));
$c3 = ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car'];
eq('④ 설치 20m 를 제품 길이(20000)로 넘김 → 설치 길이 20m, 직전 표 제품으로 수량', $prep(['query' => '그레이팅', 'product_length_mm' => 20000], $cx($m4, ['recent_cards' => $rc, 'recent_cond' => $c3, 'only_recent' => true])), ['query' => '그레이팅', 'traffic' => 'car', 'length_m' => 20.0, 'width_mm' => 400.0, 'product_length_mm' => 1000.0, 'only_ids' => [11, 12]]);
$m7 = array_merge($m4, $u('앞에 보여준 제품은 빼고 다른 제품 추천해줘.', '조건은 그대로 두고 좀 더 저렴한 제품으로 찾아줘.', '폭 500인 수로관인데 길이는 2m인 제품 찾아줘.'));
$c4 = ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'traffic' => 'car'];
eq('⑦ 다른 품목(수로관): 이번 말의 폭·제품 길이만, 그레이팅 설치 길이는 가져오지 않음(GPT 판단)', $prep(['query' => '수로관', 'spec_mm' => 500, 'length_m' => 2], $cx($m7, ['recent_cards' => $rc, 'recent_cond' => $c4])), ['query' => '수로관', 'traffic' => 'car', 'product_length_mm' => 2000.0, 'width_mm' => 500.0]);
eq('다른 품목이면 직전 표 수량 확인(only_ids)을 하지 않음', $prep(['query' => '수로관'], $cx($u('그레이팅', '수로관 몇 개 필요해?'), ['recent_cards' => $rc, 'recent_cond' => ['query' => '그레이팅'], 'only_recent' => true])), ['query' => '수로관']);
eq('말하지 않은 조건은 만들지 않음(경계석·직경 500 흄관 spec_mm 그대로)', [$prep(['query' => '경계석'], $cx($u('경계석 추천해줘'))), $prep(['query' => '흄관', 'spec_mm' => 500], $cx($u('직경 500 흄관')))], [['query' => '경계석'], ['query' => '흄관', 'spec_mm' => 500]]);
eq('두께 기준(spec_strict)은 그대로', $prep(['query' => '블록', 'spec_mm' => 80, 'spec_strict' => true], $cx($u('두께 80 블록'))), ['query' => '블록', 'spec_mm' => 80, 'spec_strict' => true]);
eq("'폭은 상관없어' → GPT 가 넘긴 폭도 뺌", $prep(['query' => '그레이팅', 'width_mm' => 500], $cx($u('폭 500 그레이팅', '폭은 상관없어'))), ['query' => '그레이팅']);
eq('장소로 짐작한 통행: GPT 가 넘긴 값 우선, 빠졌을 때만 채움', [$prep(['query' => '그레이팅', 'traffic' => 'truck'], $cx($u('주차장 배수로 그레이팅'))), $prep(['query' => '그레이팅'], $cx($u('주차장 배수로 그레이팅')))], [['query' => '그레이팅', 'traffic' => 'truck'], ['query' => '그레이팅', 'traffic' => 'car']]);
eq("'앞에 추천했던 제품들' 판정", [rag_wants_previous('앞에 추천했던 제품들은 차량 통행에 맞아?'), rag_wants_previous('아까 보여준 거 괜찮아?'), rag_wants_previous('차량이 다니는 곳이야'), rag_wants_previous('폭 400으로 바꿔줘')], [true, true, false, false]);
eq('재확인: 이 상담에서 보인 제품(recheck_ids)이 있으면 그것, GPT 가 넘긴 id 는 앞에 두고 더함', [$prep(['query' => 'q'], $cx($u('차량이 다녀요'), ['recent_cards' => $rc, 'recheck' => true, 'recheck_ids' => [12, 30, 31]]))['recheck_ids'], $prep(['query' => 'q', 'recheck_ids' => [11]], $cx($u('차량이 다녀요'), ['recent_cards' => $rc, 'recheck' => true]))['recheck_ids']], [[12, 30, 31], [11, 12]]);
eq('수량 확인(only_ids)일 때는 재확인 안 함', isset($prep(['query' => 'q', 'only_ids' => [11]], $cx($u('차량이 다녀요'), ['recent_cards' => $rc, 'recheck' => true]))['recheck_ids']), false);
eq('제외: 이 상담에서 보인 제품 전체 — GPT 가 직전 표 id 만 넘겨도 그 전 표 제품까지 더함', [$prep(['query' => 'q'], $cx($u('다른 제품'), ['exclude' => [3, 4]])), $prep(['query' => 'q', 'exclude_ids' => [9, 3]], $cx($u('다른 제품'), ['exclude' => [3, 4]]))], [['query' => 'q', 'exclude_ids' => [3, 4]], ['query' => 'q', 'exclude_ids' => [9, 3, 4]]]);
eq('제외 요청이 아니면 GPT 값 그대로', $prep(['query' => 'q', 'exclude_ids' => [9]], $cx($u('폭 400'))), ['query' => 'q', 'exclude_ids' => [9], 'width_mm' => 400.0]);

echo "[AI 상담 2026-11-11: '더 저렴한' 금액 기준 rag_cheaper_bound — 직전 표와 같은 조건일 때만]\n";
$rcE = [['id' => 21, 'price' => 30000, 'qty' => 20, 'unit' => '개'], ['id' => 22, 'price' => 25000, 'qty' => 20, 'unit' => '개']];
eq('같은 폭·제품 길이·설치 길이·통행 → 직전 표 최저 예상 자재비', rag_cheaper_bound($rcE, $c4, ['width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'traffic' => 'car']), ['max_est_cost' => 500000]);
eq('설치 길이·통행이 바뀌면 옛 금액을 쓰지 않음(비교 불가)', [rag_cheaper_bound($rcE, $c4, ['width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 30, 'traffic' => 'car']), rag_cheaper_bound($rcE, $c4, ['width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20])], [['cheaper_incomparable' => 'condition'], ['cheaper_incomparable' => 'condition']]);
eq('설치 길이 없이 같은 규격·같은 판매 단위 → 최저 단가', rag_cheaper_bound($rc, $c3, ['width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car']), ['max_unit_price' => 30000, 'price_unit' => '개']);
eq('판매 단위가 섞였거나 직전 표가 없으면 비교 불가', [rag_cheaper_bound([['id' => 1, 'price' => 100, 'qty' => null, 'unit' => '개'], ['id' => 2, 'price' => 90, 'qty' => null, 'unit' => 'M']], $c3, ['width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car']), rag_cheaper_bound([], [], [])], [['cheaper_incomparable' => 'unit'], ['cheaper_incomparable' => 'no_table']]);
eq("검색 입력에 '더 저렴한' 기준 넣음(GPT 가 넘긴 금액은 그대로)", [$prep(['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'traffic' => 'car'], $cx($u('좀 더 저렴한 제품'), ['recent_cards' => $rcE, 'recent_cond' => $c4, 'cheaper' => true]))['max_est_cost'] ?? null,
    $prep(['query' => 'q', 'max_est_cost' => 400000], $cx($u('좀 더 저렴한 제품'), ['recent_cards' => $rcE, 'recent_cond' => $c4, 'cheaper' => true]))['max_est_cost']], [500000, 400000]);

echo "[AI 상담 2026-11-12(4차): 모호한 말 — 어느 길이인지·어느 치수인지 말에 없으면 서버가 정하지 않고 확인 질문 근거]\n";
$amb = static fn(string ...$t): array => array_map(static fn($a) => $a['kind'], rag_explicit_conditions($u(...$t))['ambiguous']);
eq("'길이 10m짜리 그레이팅' → 1개 길이로 보기엔 너무 김(제품·설치 길이 어느 칸에도 안 넣음)", [$x('길이 10m짜리 그레이팅 찾아줘.')['cond'], $amb('길이 10m짜리 그레이팅 찾아줘.')], [[], ['piece_too_long']]);
eq("'길이는 10m'·'10미터길이에' → 어느 길이인지 말에 없음", [$amb('폭은 500mm이고 길이는 10m야'), $amb('식당 주방 배수로', '10미터길이에 50센테 폭이야')], [['length_unclear'], ['length_unclear']]);
eq("'폭 400 길이 1m'(폭과 붙인 3m 이하 길이) → 제품 크기, '폭 300 길이 5m 그레이팅' → 확인", [$x('중하중 그레이팅 수로용 폭 400 길이 1m 찾아줘.')['cond'], $x('폭 500, 길이 2m')['cond'], $amb('폭 300 길이 5m 그레이팅')], [['width_mm' => 400.0, 'product_length_mm' => 1000.0], ['width_mm' => 500.0, 'product_length_mm' => 2000.0], ['piece_too_long']]);
eq('관은 6m 까지 1개 길이(PE·흄관)', $x('흄관 길이 2.5m짜리', 'PE 배수관 길이 6m짜리')['cond'], ['product_length_mm' => 6000.0]);
eq("'길이만 두 배' — 앞 조건이 제품 길이 하나뿐이면 그 값 × 2(마지막 말로 표시)", [$x('폭 500mm, 길이 1m 그레이팅', '폭은 그대로 하고 길이만 두 배로 해줘.')['cond'], $x('폭 500mm, 길이 1m 그레이팅', '폭은 그대로 하고 길이만 두 배로 해줘.')['last']], [['width_mm' => 500.0, 'product_length_mm' => 2000.0], ['product_length_mm']]);
eq("'길이만 두 배' — 제품 길이·설치 길이가 둘 다 있거나 없으면 모호(값 그대로)", [$amb('폭 500mm, 길이 1m 그레이팅', '설치 20m', '길이만 두 배로 해줘.'), $x('폭 500mm, 길이 1m 그레이팅', '설치 20m', '길이만 두 배로 해줘.')['cond'], $amb('폭 500 그레이팅', '길이만 두 배로')], [['relative_length'], ['width_mm' => 500.0, 'product_length_mm' => 1000.0, 'length_m' => 20.0], ['relative_length']]);
eq("'폭을 절반으로' — 앞 폭이 있으면 ½, 없으면 모호", [$x('폭 400', '폭을 절반으로')['cond'], $amb('폭을 절반으로')], [['width_mm' => 200.0], ['relative_width']]);
eq("'500짜리 수로관 20m 필요해' → 500 은 치수 이름 없음(unlabeled), 20m 는 설치 길이", [rag_explicit_conditions($u('500짜리 수로관 20m 필요해.'))['unlabeled'], $x('500짜리 수로관 20m 필요해.')['cond']], [[500.0], ['length_m' => 20.0]]);
eq('치수 이름·표기가 있으면 unlabeled 아님(폭 500인·D300·80T)', [rag_explicit_conditions($u('폭 500인 수로관'))['unlabeled'], rag_explicit_conditions($u('D300 흄관'))['unlabeled'], rag_explicit_conditions($u('80T 블록'))['unlabeled']], [[], [], []]);
eq('모호한 말은 마지막 고객 말만(앞 턴의 모호함은 이미 답함)', $amb('길이 10m짜리 그레이팅', '폭 300으로'), []);

$ap = static fn(array $in, string ...$t): array => rag_apply_ambiguity($in, rag_explicit_conditions($u(...$t)));
[$i1, , $q1] = $ap(['query' => '그레이팅', 'product_length_mm' => 10000, 'length_m' => 10], '길이 10m짜리 그레이팅 찾아줘.');
eq("piece_too_long: GPT 가 넣은 10m(제품 길이·설치 길이)를 빼고 확인 질문 근거", [$i1, str_contains($q1[0] ?? '', '설치 구간 총 10m')], [['query' => '그레이팅'], true]);
[$i2, , $q2] = $ap(['query' => '그레이팅', 'width_mm' => 500, 'length_m' => 10], '폭은 500mm이고 길이는 10m야');
eq('length_unclear: GPT 해석(설치 길이)은 두고, 그 기준을 밝히고 확인하라고', [$i2, str_contains($q2[0] ?? '', '이번 검색은 설치 구간 총 길이로')], [['query' => '그레이팅', 'width_mm' => 500, 'length_m' => 10], true]);
[$i3, , $q3] = $ap(['query' => 'q', 'product_length_mm' => 1000, 'length_m' => 40], '폭 500mm, 길이 1m 그레이팅', '설치 20m', '길이만 두 배로 해줘.');
eq('relative_length(모호): GPT 가 바꾼 설치 길이 40 → 확인 전까지 앞 조건 20', [$i3, str_contains($q3[0] ?? '', '어느 길이를 바꿀지')], [['query' => 'q', 'product_length_mm' => 1000, 'length_m' => 20.0], true]);
[$i4, , $q4] = $ap(['query' => '수로관', 'width_mm' => 500, 'length_m' => 20], '500짜리 수로관 20m 필요해.');
eq('unlabeled: GPT 가 폭으로 넣은 500 → spec_mm(어느 자리든), 확인 질문 근거', [$i4, str_contains($q4[0] ?? '', '폭이라고 단정하지 않음')], [['query' => '수로관', 'length_m' => 20, 'spec_mm' => 500.0], true]);
[$i5, $f5, $q5] = $ap(['query' => '수로관', 'width_mm' => 500], '폭 500인 수로관');
eq('폭이라고 말한 500 은 그대로(확인 질문 없음)', [$i5, $f5, $q5], [['query' => '수로관', 'width_mm' => 500], [], []]);
eq('시스템 안내: 모호한 말이 있을 때만', [rag_clarify_hint(rag_explicit_conditions($u('폭 400으로 바꾸고 나머지는 그대로.'))), str_contains(rag_clarify_hint(rag_explicit_conditions($u('500짜리 수로관 20m 필요해.'))), "'500': 치수 이름")], ['', true]);

echo "[AI 상담 2026-11-12(4차): '더 튼튼한'·'비슷한 규격'·'이 제품 써도 돼?' 판정, 앞 표 공통 확인 치수, '가장 저렴' 기준]\n";
eq("중장비·덤프트럭·레미콘 → 대형차(차도용 표기만으로 맞다고 하지 않는 등급)", [rag_traffic_info($u('중장비가 드나드는 곳'))['class'], rag_traffic_info($u('덤프트럭 진입로'))['class'], rag_traffic_info($u('레미콘 차량 다녀요'))['class'], ai_load_status(['name' => '주철뚜껑(우수/오수/전기/통신)', 'spec' => '규격: 600Ø*h70_B.S_차도용_오수'], rag_traffic_info($u('중장비가 드나드는 곳'))['class'])['status']], ['truck', 'truck', 'truck', 'unverified']);
eq("'더 튼튼한' 판정", [rag_wants_stronger('아까 그 제품 말고 더 튼튼한 거.'), rag_wants_stronger('하중이 더 큰 제품'), rag_wants_stronger('튼튼한가요?'), rag_wants_stronger('더 저렴한 거')], [true, true, false, false]);
eq("'비슷한 규격' 판정", [rag_wants_similar('이 제품 대신 비슷한 규격의 다른 제품 보여줘.'), rag_wants_similar('같은 크기로 다른 거'), rag_wants_similar('다른 제품 보여줘')], [true, true, false]);
eq("'이 제품 써도 돼?' 판정(앞에 추천한 제품들은 재검토로 따로)", [rag_wants_suitability('차가 다니는데 이 제품 써도 돼?'), rag_wants_suitability('이거 차량 다니는 곳에 괜찮아?'), rag_wants_suitability('앞에 추천했던 제품들은 차량 통행에 맞아?'), rag_wants_suitability('차가 다녀요')], [true, true, false, false]);
$fr = static fn(array $r): array => $r + ['spec' => '규격: 400*1000*50', 'spec_dims_src' => '400*1000*50'];
eq('앞 표 공통 확인 치수: 모두 같으면 그 값, 다르면 모호, 치수 칸 없으면 없음', [rag_common_dims([$fr(['dim_w' => 400, 'dim_l' => 1000]), $fr(['dim_w' => 400, 'dim_l' => 1000])]), rag_common_dims([$fr(['dim_w' => 400, 'dim_l' => 1000]), $fr(['dim_w' => 500, 'dim_l' => 1000])]), rag_common_dims([['spec' => '규격: 400*1000', 'spec_dims_src' => null, 'dim_w' => null]])],
    [['w' => 400.0, 'l' => 1000.0], ['ambiguous' => true], []]);
$mix = [['id' => 1, 'name' => 'A', 'spec' => '', 'price' => 27600, 'qty' => null, 'unit' => '개'], ['id' => 2, 'name' => 'B', 'spec' => '', 'price' => 30800, 'qty' => null, 'unit' => 'M']];
$same = [['id' => 1, 'name' => 'A', 'spec' => '', 'price' => 27600, 'qty' => null, 'unit' => '개'], ['id' => 2, 'name' => 'B', 'spec' => '', 'price' => 20000, 'qty' => null, 'unit' => '개']];
ok("직전 표 '가장 저렴': 수량 없이 판매 단위가 섞이면 정하지 않음(설치 길이를 묻게)", str_contains(rag_recent_cards_prompt($mix), "단가만으로 '가장 저렴한' 제품을 정할 수 없다") && !str_contains(rag_recent_cards_prompt($mix), '가장 저렴)'));
ok("직전 표 '가장 저렴': 같은 판매 단위면 단가 기준 표시", preg_match('/\[id 2\][^\n]*\(단가 가장 저렴, 같은 판매 단위\)/u', rag_recent_cards_prompt($same)) === 1 && !str_contains(rag_recent_cards_prompt($same), '정할 수 없다'));
ok("직전 표 '가장 저렴': 예상 자재비가 있으면 그 기준(단위가 달라도 같은 설치 길이 총액)", preg_match('/\[id 1\][^\n]*\(가장 저렴\)/u', rag_recent_cards_prompt([['id' => 1, 'name' => 'A', 'spec' => '', 'price' => 27600, 'qty' => 20, 'unit' => '개'], ['id' => 2, 'name' => 'B', 'spec' => '', 'price' => 30800, 'qty' => 20, 'unit' => 'M']])) === 1);

echo "[AI 상담 2026-11-12(5차): 1련암거 — 상품명 규격 코드를 '확인 필요' 근거로만(숫자 자리 뜻은 추측하지 않음)]\n";
$box = ['name' => '1.0*0.8*2.0_130T*130T_구', 'spec' => '중량/단위: 2,900kg / 본', 'category_name' => '1련암거'];
eq('상품명 규격 코드: 등록 규격이 없고 이름이 치수 코드로 시작할 때만', [ai_name_size_spec($box), ai_name_size_spec(['name' => '1.0*1.0*2.0_160T*160T', 'spec' => '중량/단위: 3,935kg / 본']), ai_name_size_spec(['name' => '1.0*1.0*2.0', 'spec' => '규격: 1000*1000']), ai_name_size_spec(['name' => '중하중그레이팅수로용', 'spec' => '중량: 3kg'])],
    ['1.0*0.8*2.0_130T*130T', '1.0*1.0*2.0_160T*160T', '', '']);   // 뜻을 모르는 '_구'는 빼고 치수·두께 표기만
eq('m 소수 표기(1.0 = 1000mm, 0.8 = 800mm)는 규격 코드에서만, 두께(130T)는 m 로 보지 않음', [ai_spec_has_mm('1.0*0.8*2.0_130T*130T', 1000, true), ai_spec_has_mm('1.0*0.8*2.0_130T*130T', 800, true), ai_spec_has_mm('1.0*0.8*2.0_130T*130T', 1200, true), ai_spec_has_mm('1.0*0.8*2.0', 1000)], [true, true, false, false]);
eq('폭 판정: 코드에 숫자가 있으면 확인 필요(unknown), 없으면 다름 — 중량 2,900kg 의 900 은 치수로 보지 않음', [ai_dim_check($box, ['w' => 1000.0])['status'], ai_dim_check($box, ['w' => 900.0])['status'], ai_dim_check($box, ['l' => 2000.0])['status']], ['unknown', 'mismatch', 'unknown']);
eq("판매 단위: '중량/단위: 2,900kg / 본' → 본(다른 형식 그대로)", [ai_unit_label('중량/단위: 2,900kg / 본'), ai_unit_label('규격: 1 | 단위: m 2'), ai_unit_label('규격: 1 | 중량: 3kg | 단위: 개')], ['본', '㎡', '개']);
eq('하중: 암거는 분류 이름으로 판정 대상(근거 없음 → 차량 통행에 확인 필요), 보행자는 판정 안 함', [ai_load_status($box, 'truck')['status'], ai_load_status($box, 'car')['status'], ai_load_status($box, 'pedestrian')['status'], ai_load_status(['name' => '1.0*0.8*2.0', 'spec' => ''], 'truck')['status']], ['unverified', 'unverified', 'na', 'na']);

echo "[AI 상담 2026-11-12(5차): 모호한 자연어 표현 확대 — 앞 조건 '폭 400mm, 길이 1m 그레이팅' 뒤에 말했을 때]\n";
$ctx0 = '폭 400mm, 길이 1m 그레이팅';
$phr = static function (string $t) use ($u, $ctx0): array {
    $e = rag_explicit_conditions($u($ctx0, $t));
    return [$e['cond'], array_column($e['ambiguous'], 'kind'), $e['unlabeled']];
};
$W = ['width_mm' => 400.0];
$cases = [
    // [말, 기대 조건, 모호함 종류, 이름 없는 숫자]
    ['1m짜리 그레이팅 10개', $W + ['product_length_mm' => 1000.0], [], []],
    ['2미터짜리 수로관 5개', $W + ['product_length_mm' => 2000.0], [], []],
    ['10미터짜리 그레이팅', $W + ['product_length_mm' => 1000.0], ['piece_too_long'], []],
    ['20m 정도 깔 거야', $W + ['product_length_mm' => 1000.0, 'length_m' => 20.0], [], []],
    ['대략 30미터 정도 필요해요', $W + ['product_length_mm' => 1000.0, 'length_m' => 30.0], [], []],
    ['그레이팅 10개 필요해', $W + ['product_length_mm' => 1000.0], [], []],
    ['50센치짜리 그레이팅', $W + ['product_length_mm' => 1000.0], [], [500.0]],
    ['300짜리로 보여줘', $W + ['product_length_mm' => 1000.0], [], [300.0]],
    ['폭 30 그레이팅', $W + ['product_length_mm' => 1000.0], ['unit_unclear'], []],
    ['폭 30cm 그레이팅', ['width_mm' => 300.0, 'product_length_mm' => 1000.0], [], []],
    ['폭 40센치 길이 1미터', ['width_mm' => 400.0, 'product_length_mm' => 1000.0], [], []],
    ['길이를 1.5배로', $W + ['product_length_mm' => 1500.0], [], []],
    ['폭을 반으로 줄여줘', ['width_mm' => 200.0, 'product_length_mm' => 1000.0], [], []],
    ['아까보다 좀 더 넓은 거', $W + ['product_length_mm' => 1000.0], ['relative_vague'], []],
    ['조금 더 짧은 걸로', $W + ['product_length_mm' => 1000.0], ['relative_vague'], []],
    ['높이 50짜리 그레이팅', $W + ['product_length_mm' => 1000.0], [], []],
    ['두께 80T 블록', $W + ['product_length_mm' => 1000.0], [], []],
    ['직경 600 맨홀뚜껑', $W + ['product_length_mm' => 1000.0], [], []],
    ['폭은 그대로 두고 다른 제품', $W + ['product_length_mm' => 1000.0], [], []],
    ['길이는 10m야', $W + ['product_length_mm' => 1000.0], ['length_unclear'], []],
];
foreach ($cases as [$t, $c, $k, $n]) { eq("'{$t}'", $phr($t), [$c, $k, $n]); }
[$iu, $fu, $qu] = rag_apply_ambiguity(['query' => '그레이팅', 'width_mm' => 300], rag_explicit_conditions($u('폭 30 그레이팅')));
eq("'폭 30'(단위 없음): GPT 가 30cm 로 넣은 폭 300 도 빼고 단위 확인", [$iu, str_contains($qu[0] ?? '', '단위를 확인')], [['query' => '그레이팅'], true]);
[$iv, , $qv] = rag_apply_ambiguity(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000], rag_explicit_conditions($u($ctx0, '아까보다 좀 더 넓은 거')));
eq("'좀 더 넓은 거': GPT 가 임의로 정한 폭 500 → 확인 전까지 앞 조건 400", [$iv, str_contains($qv[0] ?? '', '얼마나(몇 mm)')], [['query' => '그레이팅', 'width_mm' => 400.0, 'product_length_mm' => 1000], true]);
eq('통행 표현: 가끔·거의 안 다녀도 차량(안전 쪽) · 사람이랑 자전거만 → 보행자 · 못 들어와요 → 보행자',
    array_map(static fn($t) => rag_traffic_info($u($t))['class'], ['차는 가끔 다녀요', '차가 거의 안 다녀요', '가끔 트럭이 들어와요', '사람이랑 자전거만 다녀요', '차는 못 들어와요', '오토바이 정도만 다녀요']),
    ['car', 'car', 'truck', 'pedestrian', 'pedestrian', 'car']);
eq("'다시 보여줘'(앞서 뺀 제품 다시) 판정 — '다시 추천'(다른 제품)은 아님", [rag_wants_reshow('아까 그거 다시 보여줘'), rag_wants_reshow('제외했던 거 다시 보여줘'), rag_wants_reshow('다시 추천해줘'), rag_wants_reshow('폭 300으로 찾아줘')], [true, true, false, false]);
eq("'다시 보여줘'면 고객이 앞서 뺀 제품 제외를 이어 가지 않음", [isset($prep(['query' => '그레이팅'], $cx($u('그레이팅', '다른 제품', '아까 그거 다시 보여줘'), ['recent_cond' => ['query' => '그레이팅', 'exclude_ids' => [1, 2]], 'reshow' => true]))['exclude_ids']),
    $prep(['query' => '그레이팅'], $cx($u('그레이팅', '다른 제품', '폭 300으로'), ['recent_cond' => ['query' => '그레이팅', 'exclude_ids' => [1, 2]]]))['exclude_ids'] ?? null], [false, [1, 2]]);

echo "[상담원 연결 유도 노출 consult_cta_visible — 로그인한 개인·기업회원만(사용자 결정 2026-11-10)]\n";
eq('비로그인·개인·기업·직원·관리자', [consult_cta_visible(null), consult_cta_visible(['role' => 'customer']), consult_cta_visible(['role' => 'corporate']), consult_cta_visible(['role' => 'staff']), consult_cta_visible(['role' => 'admin'])], [false, true, true, false, false]);
eq('역할 값이 없거나 다른 값이면 표시 안 함', [consult_cta_visible([]), consult_cta_visible(['role' => 'CUSTOMER']), consult_cta_visible(['role' => 'guest'])], [false, false, false]);

echo "[운영 상담 #193~#195 (2026-11-11) — 대체품 안내·과한 제품·수로 덮개 유지·형태 말]\n";
eq('수로 덮개 검색어 판정(맨홀·집수정은 아님)', [ai_is_channel_cover_query('그레이팅 수로용'), ai_is_channel_cover_query('트렌치 커버'), ai_is_channel_cover_query('그레이팅 맨홀 집수정'), ai_is_channel_cover_query('측구 수로관'), ai_is_channel_cover_query('그레이팅')], [true, true, false, false, false]);
eq("형태 말 후보: ○○형·○○무늬만, 조사 떼고, 크기 말(대형 등) 제외", [rag_form_terms('날개형은 안돼??'), rag_form_terms('대형차 다녀요'), rag_form_terms('U형으로 바꿔줘'), rag_form_terms('하트무늬도 있어?'), rag_form_terms('폭 300 그레이팅')], [['날개형'], [], ['U형'], ['하트무늬'], []]);
eq("'과하다' 판정", [rag_wants_lighter('과한 제품인거 같은데? 다시 추천'), rag_wants_lighter('오버스펙 같아요'), rag_wants_lighter('너무 튼튼한 거 아니야?'), rag_wants_lighter('다른 제품 보여줘'), rag_wants_lighter('더 튼튼한 거')], [true, true, true, false, false]);
eq('가장 낮은 하중 등급 판정(상품명 등급)', [rag_all_lowest_grade([['name' => 'EX메탈망그레이팅'], ['name' => '플라스틱그레이팅']]), rag_all_lowest_grade([['name' => '그레이팅수로용'], ['name' => '중하중그레이팅수로용']]), rag_all_lowest_grade([])], [true, false, false]);
$subN = "보행자용 '그레이팅 수로용' 제품은 폭 500mm가 없습니다. 대안으로 같은 폭의 보행자용 '그레이팅' 제품을 보여 드립니다.";
$subs = [['searched' => '그레이팅 수로용', 'note' => $subN]];
eq('대체품 안내가 없으면 둘째 문단에 넣음', rag_substitute_note("폭 500mm 보행자용 그레이팅입니다.\n\n- A: 설명", $subs), "폭 500mm 보행자용 그레이팅입니다.\n\n{$subN}\n\n- A: 설명");
eq('GPT 가 따로 말한 수로용 없음·제외 문장은 빼고 서버 안내 한 번만(#227 중복, 2026-11-12)', rag_substitute_note("식당 주방에 맞는 그레이팅입니다. 폭 500mm의 보행자용 제품으로, '그레이팅 수로용'은 안전중하중 등급 이상이라 제외되었습니다.\n\n- A: 설명", $subs),
    "식당 주방에 맞는 그레이팅입니다.\n\n{$subN}\n\n- A: 설명");
eq('대체품이라고 따로 말한 문장(#225)도 빼고 안내 한 번', rag_substitute_note("표에 있는 제품은 대형차용으로 과잉 등급인 '안전중하중그레이팅수로용'이 아닌 보행자용 대체품입니다.\n\n- A", $subs), "{$subN}\n\n- A");
eq("제품 줄('- ')에 수로용이 있어도 지우지 않음", str_contains(rag_substitute_note("표입니다.\n\n- 디자인그레이팅수로용: 수로용 덮개가 아닌 디자인형입니다.", $subs), '- 디자인그레이팅수로용: 수로용 덮개가 아닌 디자인형입니다.'), true);
echo "[장소 상식 근거·제조사 출처 앞말 걸러내기 (2026-11-12 운영 #224·#226·#197)]\n";
eq('장소 판단 앞부분만 지우고 뒤 문장은 둠', [rag_strip_place_inference('식당 주방은 보행자 전용 구역이므로, 보행자용 그레이팅을 사용하시면 됩니다. 폭을 알려 주세요.'),
    rag_strip_place_inference('식당 주방은 일반적으로 보행자 전용 구역으로 간주되므로, 그레이팅을 선택하면 됩니다.')],
    ['보행자용 그레이팅을 사용하시면 됩니다. 폭을 알려 주세요.', '그레이팅을 선택하면 됩니다.']);
eq('문장 전체가 장소 판단뿐이면 그 문장을 뺌', [rag_strip_place_inference('식당 주방은 보행자 전용 구역입니다. 폭과 길이를 알려 주세요.'),
    rag_strip_place_inference('식당 주방에 사용할 배수관 뚜껑이라면 보행자 전용 구역으로 분류해야 합니다. 이 경우에는 보행자용 제품을 고려하시면 됩니다.')],
    ['폭과 길이를 알려 주세요.', '이 경우에는 보행자용 제품을 고려하시면 됩니다.']);
eq('장소 판단이 아닌 문장은 그대로', rag_strip_place_inference('식당 주방의 보행자 전용 구역에 적합한 그레이팅 제품입니다. 주차장은 차량용을 씁니다.'), '식당 주방의 보행자 전용 구역에 적합한 그레이팅 제품입니다. 주차장은 차량용을 씁니다.');
eq("제조사 출처 앞말('○○ 자료에 따르면')만 지움(띄어쓰기 유지)", rag_strip_source_phrases('(주)가야스틸 자료에 따르면, 중하중그레이팅은 ㄷ형강 구조입니다. 가야스틸의 설명에 따르면 규격이 결정됩니다. 제조사 자료에 따르면 끝입니다.', ['(주)가야스틸']),
    '중하중그레이팅은 ㄷ형강 구조입니다. 규격이 결정됩니다. 끝입니다.');
eq('받침에 맞는 조사', [ai_josa('그레이팅 수로용', '은', '는'), ai_josa('그레이팅 덮개', '은', '는'), ai_josa('U-TYPE', '은', '는')], ['은', '는', '은(는)']);
eq('대체품 표가 없으면 그대로', rag_substitute_note('답', []), '답');
eq("'과한'만 말함(already_lowest): GPT 가 넘긴 exclude_ids 를 뺌", isset($prep(['query' => '그레이팅 수로용', 'exclude_ids' => [1, 2]], $cx($u('그레이팅', '좀 과한 거 같은데'), ['already_lowest' => true]))['exclude_ids']), false);
eq("같은 품목·앞 표가 수로 덮개면 cover_use=channel, 고객 형태 말을 검색어에 더함", (function () use ($prep, $cx, $u) {
    $i = $prep(['query' => '그레이팅'], $cx($u('그레이팅 수로용 폭 500', '날개형은 안돼??'), ['recent_cond' => ['query' => '그레이팅 수로용', 'width_mm' => 500], 'form_terms' => ['날개형']]));
    return [$i['cover_use'] ?? '', $i['query']];
})(), ['channel', '그레이팅 날개형']);
eq('검색어에 용도 말이 있거나(집수정) 품목이 바뀌면 cover_use 를 넣지 않음', [isset($prep(['query' => '그레이팅 집수정'], $cx($u('그레이팅 수로용', '집수정용으로'), ['recent_cond' => ['query' => '그레이팅 수로용']]))['cover_use']),
    isset($prep(['query' => '측구 수로관'], $cx($u('그레이팅 수로용', '수로관도'), ['recent_cond' => ['query' => '그레이팅 수로용']]))['cover_use'])], [false, false]);


echo "[상용콘크리트 일괄 등록 app/syc_bulk.php]\n";
require_once __DIR__ . '/../app/syc_bulk.php';
// 칸 규칙 = 마이그레이션(database/20261110_syc_products.sql) 칸·글자 수
$mig = (string) file_get_contents(__DIR__ . '/../database/20261110_syc_products.sql');
preg_match('/CREATE TABLE IF NOT EXISTS syc_products \((.*?)\n\) ENGINE/s', $mig, $mm);
preg_match_all('/^\s{4}([a-z0-9_]+)\s+(VARCHAR\((\d+)\)|DECIMAL\((\d+),(\d+)\)|[A-Z]+)/m', $mm[1] ?? '', $cm, PREG_SET_ORDER);
$migCols = [];
foreach ($cm as $c) { $migCols[$c[1]] = $c; }
// 2026-11-11 부속 치수 칸(ALTER … ADD COLUMN)도 같은 규칙
$migAcc = (string) file_get_contents(__DIR__ . '/../database/20261111_syc_products_acc_dims.sql');
preg_match_all('/ADD COLUMN ([a-z0-9_]+)\s+(DECIMAL\((\d+),(\d+)\))/', $migAcc, $am2, PREG_SET_ORDER);
foreach ($am2 as $c) { $migCols[$c[1]] = [$c[0], $c[1], $c[2], '', $c[3], $c[4]]; }
eq('부속 치수 칸 6개(ADD COLUMN)', count($am2), 6);
$skip = ['id', 'category_id', 'spec_dims_src', 'image_file', 'image_thumb_file', 'is_active', 'created_at'];
$mc = array_values(array_diff(array_keys($migCols), $skip)); $bc = array_keys(syc_bulk_columns()); sort($mc); sort($bc);
eq('엑셀 칸 = 마이그레이션 칸(자동 칸 7개 제외, 순서 무관) — 칸 수', [count($bc), $mc === $bc], [71, true]);
$bad = [];
foreach (syc_bulk_columns() as $k => [$t, $lim]) {
    $c = $migCols[$k] ?? null;
    if ($t === 'str' && ($c[3] ?? '') !== '' && (int) $c[3] !== $lim) { $bad[] = $k; }
    if ($t === 'dec' && ((int) ($c[4] ?? 0) - (int) ($c[5] ?? 0) !== $lim[0] || (int) ($c[5] ?? 0) !== $lim[1])) { $bad[] = $k; }
}
eq('글자 수·소수 자리 = 마이그레이션', $bad, []);
eq('머리글: [칸 이름]·칸 이름·모르는 칸', array_map('syc_bulk_header_key', ['상품명 [name]', 'dim_w', '폭·가로 W [dim_w]', '분류명(참고) [category_name]', '아무거나', '대표이미지(상세용, ZIP 안 파일명) [image_file]', '[id]']),
    ['name', 'dim_w', 'dim_w', 'category_name', '', 'image_file', '']);
$thrown = static function (callable $f): string { try { $f(); return ''; } catch (InvalidArgumentException $e) { return $e->getMessage(); } };
ok('필수 머리글 [category_slug] 없으면 오류', str_contains($thrown(fn() => syc_bulk_header_map(['상품명 [name]'])), 'category_slug'));
ok('같은 칸 두 번이면 오류', str_contains($thrown(fn() => syc_bulk_header_map(['[name]', '[category_slug]', 'name'])), '두 번'));
eq('값 변환: 치수 반올림·쉼표·빈 칸·가격 빈 칸 0', [syc_bulk_cell('dim_w', '101.64'), syc_bulk_cell('weight_kg', '1,290'), syc_bulk_cell('dim_h', ''), syc_bulk_cell('price_b2b', ''), syc_bulk_cell('spec_wire_no', '10')],
    [[101.6, null], [1290.0, null], [null, null], [0, null], [10, null]]);
eq('값 오류: 숫자 아님·음수·자리 넘침·소수 정수칸·글자 수', array_map(static fn($r) => $r[1] !== null, [syc_bulk_cell('dim_w', '12mm'), syc_bulk_cell('dim_w', '-5'), syc_bulk_cell('dim_w', '10000000'), syc_bulk_cell('price_b2c', '10.5'), syc_bulk_cell('spec_code', str_repeat('가', 31))]),
    [true, true, true, true, true]);
eq('이미지 파일명: 정상·대문자 확장자 / 폴더·php·숨김 파일은 오류', array_map(static fn($v) => syc_bulk_image_name($v)[1] === null, ['syc-s1-01.jpg', 'A.PNG', 'dir/a.jpg', 'a.php', '.a.jpg', '..\\a.jpg']),
    [true, true, false, false, false, false]);
$map = [0 => 'category_slug', 1 => 'name', 2 => 'dim_w', 3 => 'external_id', 4 => 'image_thumb_file'];
$pr = syc_bulk_parse_row(['syc-s1', '측구', '200', 'x-1', ''], $map, ['syc-s1' => 7]);
eq('행 변환: 분류 id·출처 기본 syccrt·규격 원문 칸·썸네일 없음 null', [$pr['row']['category_id'], $pr['row']['external_source'], $pr['row']['dim_w'], $pr['row']['_image_thumb_file'], $pr['errors']], [7, 'syccrt', 200.0, null, []]);
$pr = syc_bulk_parse_row(['syc-x9', '', 'abc', '', ''], $map, ['syc-s1' => 7]);
eq('행 오류 3개(숫자·상품명·분류코드) → 저장 행 없음', [count($pr['errors']), $pr['row']], [3, null]);
eq('빈 행은 건너뜀', syc_bulk_parse_row(['', ' ', '', '', ''], $map, [])['empty'], true);
// 업체별 제품관리(2026-11-13): 출처 = 고른 업체, 다른 업체 출처가 적힌 행은 오류, 같은 제품 열쇠 = 제품명+규격+옵션
$pr = syc_bulk_parse_row(['syc-s1', '측구', '200', 'x-1', ''], $map, ['syc-s1' => 7], 'vendor-5');
eq('업체 행: 출처 = 고른 업체(vendor-5)', [$pr['row']['external_source'], $pr['errors']], ['vendor-5', []]);
$mapSrc = [0 => 'category_slug', 1 => 'name', 2 => 'external_source', 3 => 'external_id'];
$pr = syc_bulk_parse_row(['syc-s1', '측구', 'syccrt', 'x-1'], $mapSrc, ['syc-s1' => 7], 'vendor-5');
eq('업체 행: 엑셀 출처가 다른 업체(syccrt)면 오류 → 저장 안 함(다른 업체 상품 덮어쓰기 방지)', [$pr['row'], count($pr['errors'])], [null, 1]);
$pr = syc_bulk_parse_row(['syc-s1', '측구', 'syccrt', 'x-1'], $mapSrc, ['syc-s1' => 7], 'syccrt');
eq('상용콘크리트 행: 엑셀 출처 syccrt 그대로 통과', [$pr['row']['external_source'], $pr['errors']], ['syccrt', []]);
eq('같은 제품 열쇠: 공백 정리·옵션 null = 빈칸', [product_match_key(' 환경수로관 ', '3000*3150  *1500', null) === product_match_key('환경수로관', '3000*3150 *1500', ''),
    product_match_key('환경수로관', '3000*3150*1500', null) === product_match_key('환경수로관', '2500*2650*1500', null)], [true, false]);
eq('자동 출처ID: 같은 열쇠 → 같은 ID, auto- + 24자', [product_auto_external_id('a') === product_auto_external_id('a'), strlen(product_auto_external_id('a')), str_starts_with(product_auto_external_id('a'), 'auto-')], [true, 29, true]);
eq('업체 출처 코드', product_source_vendor_code(12), 'vendor-12');
eq('활성/비활성 변경 기능 멈춤(사용자 결정 2026-11-13)', PRODUCT_ACTIVE_TOGGLE_ENABLED, false);
require_once __DIR__ . '/../app/ktomok_bulk.php';
eq('케이토목 규격 줄 나누기', ktomok_spec_parts('규격: 1.5*1.5*2.0_160T*140T | 중량: 3,640kg | 단위: 본'), ['spec' => '1.5*1.5*2.0_160T*140T', 'weight' => '3,640kg', 'unit' => '본', 'rest' => []]);
eq('케이토목 규격 줄: 이름표 없으면 통째로 규격', ktomok_spec_parts('300*300')['spec'], '300*300');
eq('케이토목 규격 줄 합치기(빈 조각 뺌)', ktomok_spec_compose(['spec' => '300*300', 'weight' => null, 'unit' => '개', 'rest' => ['상차수량: 32']]), '규격: 300*300 | 단위: 개 | 상차수량: 32');
eq('케이토목 중량 글자', [ktomok_weight_text(3640.0), ktomok_weight_text(12.5), ktomok_weight_text(null)], ['3,640kg', '12.5kg', null]);
$kmap = syc_bulk_header_map(['분류코드 [category_slug]', '상품명 [name]', '규격 [spec]', '매입가 [cost_price]', '모델 코드 [spec_code]'], ktomok_bulk_columns(), KTOMOK_BULK_META_KEYS);
eq('케이토목 양식 머리글: 케이토목 칸만(모델 코드는 상용콘크리트 칸이라 무시)', array_values($kmap), ['category_slug', 'name', 'spec', 'cost_price']);
$kcsv = tempnam(sys_get_temp_dir(), 'kt') . '.csv';
file_put_contents($kcsv, "\xEF\xBB\xBF분류코드 [category_slug],상품명 [name],규격 [spec],중량(kg) [weight_kg],매입가 [cost_price]\nmid11--20,측구수로관,300*300,120,1000\nmid11--20,측구수로관,300*300,130,\nmid11--20,측구수로관,400*400,,\nsyc-s1,측구수로관,500*500,,\n");
$kr = ktomok_bulk_read_excel($kcsv, 'csv', ['mid11--20' => 257]);
unlink($kcsv);
eq('케이토목 엑셀: 같은 제품명·규격 두 번 → 오류, 상용콘크리트 분류코드 → 오류, 정상 2행', [count($kr['rows']), $kr['error_count'], $kr['present'], $kr['rows'][0]['category_id'], $kr['rows'][1]['cost_price']],
    [2, 2, ['name', 'spec', 'weight_kg', 'cost_price'], 257, 0]);
// 실제 테스트 엑셀(data/syccrt) 704행 — 분류코드는 카테고리 마이그레이션(+ 2026-11-11 나누기)의 품목 slug
preg_match_all("/'(syc-[sw]\d+)'/", (string) file_get_contents(__DIR__ . '/../database/20261110_syc_categories.sql') . (string) file_get_contents(__DIR__ . '/../database/20261111_syc_categories_split.sql'), $sm);
$cats = array_flip(array_values(array_unique($sm[1])));
$rx = syc_bulk_read_excel(__DIR__ . '/../data/syccrt/syc-products-bulk-test.xlsx', 'xlsx', $cats);
eq('테스트 엑셀: 704행·오류 0·대표/썸네일 파일명', [count($rx['rows']), $rx['error_count'], $rx['rows'][0]['_image_file'], $rx['rows'][0]['_image_thumb_file'], $rx['rows'][0]['dim_w']], [704, 0, 'syc-s1-01.jpg', 'syc-s1-01_thumb.jpg', 200.0]);
$csv = tempnam(sys_get_temp_dir(), 'sycb') . '.csv';
file_put_contents($csv, "\xEF\xBB\xBF상품명 [name],분류코드 [category_slug],출처ID [external_id],폭 [dim_w]\n가,syc-s1,a1,100\n나,syc-s1,a1,100\n다,없는코드,a3,1x\n\n");
$rc = syc_bulk_read_excel($csv, 'csv', $cats);
unlink($csv);
eq('CSV: 출처ID 중복·없는 분류·숫자 오류 → 정상 1행·오류 2행(행 번호 포함)', [count($rc['rows']), $rc['error_count'], str_starts_with($rc['errors'][0] ?? '', '3행:'), str_starts_with($rc['errors'][1] ?? '', '4행:')], [1, 2, true, true]);
eq('엑셀에 있는 저장 칸(present) — 덮어쓸 때 이 칸만 바꿈', $rc['present'], ['name', 'dim_w', 'external_id']);
// ZIP: 폴더 안 이미지는 이름으로, __MACOSX·숨김·이미지 아닌 파일 제외, 같은 이름 두 개는 오류
$zf = tempnam(sys_get_temp_dir(), 'sycz');
$z = new ZipArchive(); $z->open($zf, ZipArchive::OVERWRITE);
$png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR4nGNgYGD4DwABBAEAwS2OUAAAAABJRU5ErkJggg==');
$z->addFromString('imgs/A-1.png', $png); $z->addFromString('__MACOSX/imgs/._A-1.png', 'x'); $z->addFromString('readme.txt', 'x'); $z->addFromString('.hidden.png', $png); $z->addFromString('fake.jpg', '<?php echo 1;');
$z->close(); $z->open($zf);
$zi = syc_bulk_zip_index($z);
eq('ZIP 목록(소문자 이름 → 경로)', $zi, ['a-1.png' => 'imgs/A-1.png', 'fake.jpg' => 'fake.jpg']);
$dir = sys_get_temp_dir() . '/sycimg_' . bin2hex(random_bytes(4)); mkdir($dir);
$web = syc_bulk_store_image($z, 'imgs/A-1.png', $dir, '/uploads/syc');
eq('이미지 저장: 실제 PNG 는 해시 이름 .png, 이미지 아닌 .jpg 는 저장 안 함', [preg_match('#^/uploads/syc/[0-9a-f]{24}\.png$#', (string) $web), syc_bulk_store_image($z, 'fake.jpg', $dir, '/uploads/syc'), count(glob($dir . '/*'))], [1, null, 1]);
$z->close(); array_map('unlink', glob($dir . '/*')); rmdir($dir);
$z = new ZipArchive(); $z->open($zf, ZipArchive::OVERWRITE); $z->addFromString('a/x.jpg', $png); $z->addFromString('b/X.JPG', $png); $z->close(); $z->open($zf);
ok('ZIP 안 같은 이름(폴더만 다름) → 오류', str_contains($thrown(fn() => syc_bulk_zip_index($z)), '같은 이름'));
$z->close(); unlink($zf);

echo "[상용콘크리트 사이트 화면 app/syc_catalog.php · 가격 price_bundle 마진율 키]\n";
require_once __DIR__ . '/../app/syc_catalog.php';
// 가격: 마진율 키가 없는 행(기존 products)은 기존 결과 그대로, 키가 있으면 같은 식(매입가 × (1+마진율))
$pbOld = price_bundle(['price_b2c' => 1000, 'cost_price' => 500, 'category_id' => 0]);
eq('기존 행(마진율 키 없음, 카테고리 없음): 매입가 그대로·정가 1000', [$pbOld['customer'], $pbOld['corporate'], $pbOld['list']], [500, 500, 1000]);
$pbSyc = price_bundle(['price_b2c' => 1000, 'cost_price' => 500, 'category_id' => 0, '_margin_customer' => 20, '_margin_corporate' => 10]);
eq('상용콘크리트 행: 개인 500×1.2=600·기업 500×1.1=550·마진율 표시', [$pbSyc['customer'], $pbSyc['corporate'], $pbSyc['rate'], $pbSyc['corp_rate']], [600, 550, 20, 10]);
eq('매입가 0 이면 정가로(기존과 같음)', price_bundle(['price_b2c' => 900, 'cost_price' => 0, '_margin_customer' => 20, '_margin_corporate' => 10])['corporate'], 900);
eq('개인할인율 외부 지정($discountRate)이 마진율 키보다 우선(기존 규칙)', price_bundle(['price_b2c' => 1000, 'cost_price' => 500, '_margin_customer' => 20], 30)['customer'], 650);
$sycCats = [1 => ['parent_id' => null, 'discount_customer' => 20, 'discount_corporate' => 10], 2 => ['parent_id' => 1, 'discount_customer' => 0, 'discount_corporate' => 15], 3 => ['parent_id' => 1, 'discount_customer' => 25, 'discount_corporate' => 0]];
eq('마진율 상속: 0 이면 대분류 값', [syc_discount_map($sycCats, 'discount_customer'), syc_discount_map($sycCats, 'discount_corporate')], [[1 => 20, 2 => 20, 3 => 25], [1 => 10, 2 => 15, 3 => 10]]);
$pr = syc_price_row(['category_id' => 2, 'price_b2c' => 0], $sycCats);
eq('syc_price_row: 품목 마진율을 행에 넣음', [$pr['_margin_customer'], $pr['_margin_corporate']], [20, 15]);
// 이름표 마이그레이션 키 = 허용 키, 허용 키의 칸 = 상품 표 칸
$axSql = (string) file_get_contents(__DIR__ . '/../database/20261111_syc_category_spec_axes.sql');
preg_match_all("/SELECT '(syc-[sw]\d+)'(?: AS slug)?, (\d+)(?: AS seq)?, '([a-z0-9_]+)'/", $axSql, $am);
eq('이름표 마이그레이션: 키 모두 허용 목록·행 392', [count(array_diff(array_unique($am[3]), SYC_AXIS_KEYS)), count($am[3])], [0, 392]);
$mig2 = (string) file_get_contents(__DIR__ . '/../database/20261110_syc_products.sql');
eq('허용 키의 칸이 syc_products 마이그레이션에 모두 있음', array_values(array_filter(SYC_AXIS_KEYS, static fn($k) => !preg_match('/^\s{4}' . syc_axis_column($k) . '\s/m', $mig2) && !str_contains($migAcc, 'ADD COLUMN ' . syc_axis_column($k) . ' '))), []);
$axSql2 = (string) file_get_contents(__DIR__ . '/../database/20261111_syc_axes_acc_labels.sql');
preg_match_all("/SELECT '(syc-[sw]\d+)' AS slug, (\d+) AS seq, '([a-z0-9_]+)'/", $axSql2, $am3);
eq('부속 이름표 마이그레이션: 키 모두 허용 목록·같은 품목 안 키·순번 겹침 없음', [count($am3[3]) > 0, count(array_diff(array_unique($am3[3]), SYC_AXIS_KEYS)),
    count(array_unique(array_map(static fn($a, $b) => $a . '/' . $b, $am3[1], $am3[3]))) === count($am3[3]), count(array_unique(array_map(static fn($a, $b) => $a . '/' . $b, $am3[1], $am3[2]))) === count($am3[3])], [true, 0, true, true]);
// 이름표 순번이 기존 이름표(20261111_syc_category_spec_axes.sql)와 겹치지 않음
$oldSeq = array_map(static fn($a, $b) => $a . '/' . $b, $am[1], $am[2]);
eq('부속 이름표 순번이 기존 순번과 겹치지 않음', array_values(array_intersect(array_map(static fn($a, $b) => $a . '/' . $b, $am3[1], $am3[2]), $oldSeq)), []);
eq('칸 이름: dim_ 접두·wire_no·모르는 키', [syc_axis_column('w_o'), syc_axis_column('wire_no'), syc_axis_column('price_b2c'), syc_axis_column('dim_w')], ['dim_w_o', 'spec_wire_no', null, null]);
eq('행 값: 숫자 정규화·글자 칸 그대로·빈 값 null', [syc_axis_row_value(['dim_w' => '200.0'], 'w'), syc_axis_row_value(['dim_d' => '101.6'], 'd'), syc_axis_row_value(['dim_d_inlet' => 'VG100'], 'd_inlet'), syc_axis_row_value(['dim_h' => null], 'h'), syc_axis_row_value(['spec_wire_no' => 10], 'wire_no')],
    ['200', '101.6', 'VG100', null, '10']);
$fi = syc_filter_input(['ax_w' => ['300', '300.0', 'abc', ''], 'ax_d_inlet' => ['VG100'], 'ax_price_b2c' => ['1'], 'ax_code' => ['300A', ' ', '300A']]);
eq('필터 입력: 숫자 아닌 값·모르는 키 버림, 같은 값 하나로', $fi, ['ax' => ['w' => ['300'], 'd_inlet' => ['VG100']], 'ax_code' => ['300A'], 'ax_type' => []]);
$axA = [['axis_key' => 'w', 'label' => '폭', 'symbol' => 'W', 'unit' => 'mm', 'in_list' => true, 'use_filter' => true], ['axis_key' => 'w1', 'label' => '세부', 'symbol' => 'w1', 'unit' => 'mm', 'in_list' => false, 'use_filter' => false]];
$axB = [['axis_key' => 'd_i', 'label' => '내경', 'symbol' => 'D', 'unit' => 'mm', 'in_list' => true, 'use_filter' => true]];
eq('공통 이름표: 같은 목록 칸이면 그 칸(세부 칸 제외), 다르면 []', [array_column(syc_axes_common([1, 2], [1 => $axA, 2 => $axA]), 'axis_key'), syc_axes_common([1, 3], [1 => $axA, 3 => $axB]), syc_axes_common([9], [1 => $axA])], [['w'], [], []]);
$rowsF = [['dim_w' => 300, 'spec_code' => '300A'], ['dim_w' => 300, 'spec_code' => '300B'], ['dim_w' => 400, 'spec_code' => '400A']];
$fc = syc_facets($rowsF, [$axA[0]], ['ax' => ['w' => ['300']], 'ax_code' => [], 'ax_type' => []]);
eq('파세트: 값 목록(숫자 순)·개수·모델 코드(다른 필터 적용 뒤)', [$fc['all']['w'], $fc['vals']['w'], $fc['type_all'], $fc['type']], [['300', '400'], ['300' => 2, '400' => 1], ['300A', '300B', '400A'], ['300A' => 1, '300B' => 1]]);
eq('필터 일치: 축끼리 그리고·축 안 또는', [syc_row_matches($rowsF[0], ['ax' => ['w' => ['300', '400']], 'ax_code' => ['300A']]), syc_row_matches($rowsF[1], ['ax' => ['w' => ['300']], 'ax_code' => ['300A']])], [true, false]);
// 카테고리 나누기(2026-11-11): 새 slug 가 기존과 겹치지 않고, 테스트 데이터 분류코드가 모두 마이그레이션에 있음
$catMig = (string) file_get_contents(__DIR__ . '/../database/20261110_syc_categories.sql') . (string) file_get_contents(__DIR__ . '/../database/20261111_syc_categories_split.sql');
preg_match_all("/AS name, '(syc-[sw]\d+)' AS slug/", (string) file_get_contents(__DIR__ . '/../database/20261111_syc_categories_split.sql'), $cs2);
$pj = json_decode((string) file_get_contents(__DIR__ . '/../data/syccrt/products.json'), true);
$pjSlugs = array_values(array_unique(array_column($pj['rows'] ?? [], 'category_slug')));
$orig = (string) file_get_contents(__DIR__ . '/../database/20261110_syc_categories.sql');
eq('카테고리 나누기: 새 13개·기존 slug 와 안 겹침', [count(array_unique($cs2[1])), count(array_filter($cs2[1], static fn($x) => str_contains($orig, "'" . $x . "'")))], [13, 0]);
eq('테스트 데이터 분류코드가 모두 카테고리 마이그레이션에 있음', array_values(array_filter($pjSlugs, static fn($x) => !str_contains($catMig, "'" . $x . "'"))), []);
eq('카테고리 나누기: 새 카테고리 INSERT 에 마진율 칸 없음(대분류 값 상속)', preg_match('/INSERT INTO syc_categories \([^)]*discount/', (string) file_get_contents(__DIR__ . '/../database/20261111_syc_categories_split.sql')), 0);
// 측구수로관 합치기(2026-11-11): 숨긴 카테고리(syc-s2~s7)를 테스트 데이터가 쓰지 않음, 측구수로관 모델 코드 = 형식만
$mergeMig = (string) file_get_contents(__DIR__ . '/../database/20261111_syc_categories_merge.sql');
preg_match('/SET is_active = 0\s+WHERE slug IN \(([^)]*)\)/', $mergeMig, $hm);
preg_match_all("/'(syc-[sw]\d+)'/", $hm[1] ?? '', $hidden);
$sideCodes = array_values(array_unique(array_column(array_filter($pj['rows'] ?? [], static fn($r) => $r['category_slug'] === 'syc-s1'), 'spec_code')));
sort($sideCodes);
eq('측구수로관 합치기: 숨긴 6개를 데이터가 안 씀·모델 코드 형식만·품명 하나', [count($hidden[1]), array_values(array_intersect($pjSlugs, $hidden[1])), $sideCodes,
    array_values(array_unique(array_column(array_filter($pj['rows'] ?? [], static fn($r) => $r['category_slug'] === 'syc-s1'), 'name')))], [6, [], ['AN형', 'A형', 'B형', 'Z형'], ['측구수로관']]);
// ── 업체 엑셀 자동 정리(app/syc_normalize.php — 규칙 app/syc_normalize_rules.php) ──
$NR = syc_normalize_rules();
eq('모델 코드 분석: 크기+형식·크기+W2·크기+옵션 글자·크기뿐·이미 정리됨', [
    syc_norm_code_parse('300*400B', ['dim_w' => 300.0, 'dim_h' => 400.0], $NR['option_words']),
    syc_norm_code_parse('250W2', ['dim_d_i' => 250.0], $NR['option_words']),
    syc_norm_code_parse('250무개', ['dim_d_i' => 250.0], $NR['option_words']),
    syc_norm_code_parse('0.6*0.6', ['dim_w' => 600.0, 'dim_h' => 600.0], $NR['option_words']),
    syc_norm_code_parse('B형', [], $NR['option_words'])],
    [['kind' => 'code', 'value' => 'B형'], ['kind' => 'code', 'value' => 'W2'], ['kind' => 'word', 'value' => '무개'], ['kind' => 'clear', 'value' => null], ['kind' => 'keep', 'value' => 'B형']]);
eq('모델 코드 분석: 크기가 치수 칸에 없거나(800B·폭 795) 크기로 시작 안 하면 정리 안 함', [syc_norm_code_parse('800B', ['dim_w' => 795.0], []), syc_norm_code_parse('KCI-S1', [], []), syc_norm_code_parse('NS-01', [], [])], [null, null, null]);
$sp = syc_normalize_rows([
    ['_category_slug' => 'syc-s1', 'name' => '측구수로관', 'spec' => '300*400*2000*150T (300*400B)', 'spec_code' => 'B형', 'spec_option' => null, 'dim_w' => 300.0, 'dim_h' => 400.0],
    ['_category_slug' => 'syc-s79', 'name' => '원심력수로관', 'spec' => 'D250 (340*330*2500*45T) (무개)', 'spec_code' => null, 'spec_option' => '무개', 'dim_d_i' => 250.0],
    ['_category_slug' => 'syc-s14', 'name' => '원형수로관', 'spec' => 'D150*2M (260*294*1998) (NS-01)', 'spec_code' => 'NS-01', 'spec_option' => null],
    ['_category_slug' => 'syc-s15', 'name' => '원형사각수로관', 'spec' => 'D200*2M (320*320*2000*60T)', 'spec_code' => null, 'spec_option' => null, 'dim_d_i' => 200.0],
], $NR);
eq('규격 끝 예전 코드: 형식으로·옵션 글자 뺌·지금 코드와 같으면 그대로·치수 목록 괄호 그대로', array_column($sp['rows'], 'spec'),
    ['300*400*2000*150T (B형)', 'D250 (340*330*2500*45T)', 'D150*2M (260*294*1998) (NS-01)', 'D200*2M (320*320*2000*60T)']);
eq('옵션 조각: 괄호 안 쉼표는 나누지 않음', syc_norm_option_parts('그레이팅(인도,차도)뚜껑 / PE뚜껑 1050, 주문품'), ['그레이팅(인도,차도)뚜껑 / PE뚜껑 1050', '주문품']);
$nr = syc_normalize_rows([
    ['_category_slug' => 'syc-s6', 'name' => '측구수로관 앵글부착 B형', 'spec_code' => '300*400B', 'spec_option' => '그레이팅 395*995*50', 'dim_w' => 300.0, 'dim_h' => 400.0, 'price_b2c' => 1000, 'external_id' => 'x1'],
    ['_category_slug' => 'syc-s12', 'name' => 'U형 플륨', 'spec_code' => null, 'spec_option' => null, 'dim_w' => 300.0],
], $NR);
eq('행 정리: 측구수로관 B형(분류 합치기·형식 코드·옵션 앞 앵글부착·부속 치수) / U형 플륨 품명은 그대로', [
    array_intersect_key($nr['rows'][0], array_flip(['_category_slug', 'name', 'spec_code', 'spec_option', 'dim_a_w', 'dim_a_l', 'dim_a_t', 'price_b2c', 'external_id'])), $nr['rows'][1]['name']],
    [['_category_slug' => 'syc-s1', 'name' => '측구수로관', 'spec_code' => 'B형', 'spec_option' => '앵글부착, 그레이팅', 'price_b2c' => 1000, 'external_id' => 'x1', 'dim_a_w' => 395.0, 'dim_a_l' => 995.0, 'dim_a_t' => 50.0], 'U형 플륨']);
$nw = syc_normalize_rows([['_category_slug' => 'syc-s1', 'name' => '측구수로관', 'spec_code' => null, 'spec_option' => '그레이팅 395*995*50', 'dim_a_w' => 400.0],
                          ['_category_slug' => 'syc-s48', 'name' => 'PE 부속', 'spec_code' => null, 'spec_option' => null, 'dim_d' => 1.0]], $NR, ['syc-s1' => true, 'syc-s48' => true]);
eq('경고: 업체 치수와 옵션 치수가 다르면 옮기지 않음·나누기 묶음에 맞는 치수 구성 없음', [$nw['rows'][0]['spec_option'], $nw['rows'][0]['dim_a_w'], count($nw['warnings']), $nw['rows'][1]['_category_slug']],
    ['그레이팅 395*995*50', 400.0, 2, 'syc-s48']);
// 실제 데이터: 옛 표기 원본 엑셀(data/syccrt/syc-products-bulk-test-raw.xlsx) → 정리 결과 = 지금 데이터(products.json), 정리된 엑셀 다시 넣기 = 바뀜 0
$normCats = $cats;
$rawR = syc_bulk_read_excel(__DIR__ . '/../data/syccrt/syc-products-bulk-test-raw.xlsx', 'xlsx', $normCats, true);
$pjById = array_column($pj['rows'] ?? [], null, 'external_id');
$gd = 0; $guard = 0;
foreach ($rawR['rows'] as $row) {
    $w = $pjById[$row['external_id']] ?? [];
    foreach ($w as $f => $ev) {
        if ($f !== 'category_slug' && $f !== 'name' && $f !== 'spec' && $f !== 'spec_code' && $f !== 'spec_option' && !str_starts_with($f, 'dim_')) { continue; }
        $gv = $f === 'category_slug' ? $row['_category_slug'] : ($row[$f] ?? null);
        if (is_numeric($gv) && is_numeric($ev) ? (float) $gv !== (float) $ev : $gv !== $ev) { $gd++; }
    }
}
$rawPlain = syc_bulk_read_excel(__DIR__ . '/../data/syccrt/syc-products-bulk-test-raw.xlsx', 'xlsx', $normCats, false);
foreach ($rawPlain['rows'] as $k => $row) {
    foreach (['price_b2c', 'price_b2b', 'cost_price', 'stock_qty', 'external_source', 'external_id', '_image_file', '_image_thumb_file', 'spec_dims_src', 'weight_kg', 'unit', 'brand'] as $f) {
        if (($rawR['rows'][$k][$f] ?? null) !== ($row[$f] ?? null)) { $guard++; }
    }
}
$again = syc_bulk_read_excel(__DIR__ . '/../data/syccrt/syc-products-bulk-test.xlsx', 'xlsx', $normCats, true);
eq('자동 정리 실제 데이터: 옛 표기 704행 → 지금 데이터와 차이 0(규격 끝 예전 코드 포함)·가격·출처·이미지·규격 원본(spec_dims_src) 그대로·정리된 엑셀 다시 넣으면 바뀜 0', [count($rawR['rows']), $gd, $guard, $again['normalize']['changes'], $rawR['normalize']['warning_count']], [704, 0, 0, 0, 0]);
// ── 사이트 반영·전환(app/syc_publish.php, B 방식 2026-11-12) ──
require_once __DIR__ . '/../app/syc_publish.php';
require_once __DIR__ . '/../app/work_types.php';
eq('반영 spec: 기존 상품과 같은 규격·중량(천 단위 쉼표)·단위 형식, 빈 값은 뺌', [
    syc_publish_spec_text(['spec' => '1.5*1.5*2.0_160T*140T', 'weight_kg' => 3640.0, 'unit' => '본']),
    syc_publish_spec_text(['spec' => '200*200', 'weight_kg' => 12.5, 'unit' => null]), syc_publish_spec_text(['spec' => '', 'weight_kg' => null, 'unit' => '개'])],
    ['규격: 1.5*1.5*2.0_160T*140T | 중량: 3,640kg | 단위: 본', '규격: 200*200 | 중량: 12.5kg', '단위: 개']);
$pd = syc_publish_detail(['dim_w' => 300.0, 'dim_w1' => 600.0, 'dim_a_w' => 395.0, 'dim_x1' => 68.0],
    [['axis_key' => 'w', 'label' => '폭', 'symbol' => 'W', 'unit' => 'mm', 'in_list' => true], ['axis_key' => 'w1', 'label' => '세부', 'symbol' => 'w1', 'unit' => 'mm', 'in_list' => false],
     ['axis_key' => 'a_w', 'label' => '그레이팅 폭', 'symbol' => '', 'unit' => 'mm', 'in_list' => false], ['axis_key' => 'x1', 'label' => '파형 피치', 'symbol' => 'p', 'unit' => 'mm', 'in_list' => true]]);
eq('세부 치수: 목록 칸(폭·파형 피치) 빼고 상세 전용 칸만, 이름표 순서', array_column($pd, 'label'), ['세부', '그레이팅 폭']);
$catSql = (string) file_get_contents(__DIR__ . '/../database/20261110_syc_categories.sql');
preg_match_all("/'(syc-g\d+)'/", $catSql, $gm);
eq('대분류 연결: 상용콘크리트 대분류 10개 모두 재질 대분류(11·12·13·15)에', [array_values(array_diff(array_unique($gm[1]), array_keys(SYC_PUBLISH_GROUP_ROOT))), array_values(array_unique(array_values(SYC_PUBLISH_GROUP_ROOT)))],
    [[], [11, 15, 13, 12]]);
preg_match_all("/'(syc-g\d+)' AS slug/", (string) file_get_contents(__DIR__ . '/../database/20261112_syc_categories_regroup.sql'), $rg);
eq('중분류 나누기(2026-11-12): 새 중분류 3개 모두 재질 대분류에(콘크리트 2·PE/PVC 1)', array_map(static fn($g) => SYC_PUBLISH_GROUP_ROOT[$g] ?? null, $rg[1]), [12, 12, 15]);
$swSql = (string) file_get_contents(__DIR__ . '/../database/20261112_catalog_switch.sql');
preg_match('/MODIFY axis_key ENUM\(([^)]*)\)/s', $swSql, $em);
preg_match_all("/'([a-z0-9_]+)'/", $em[1] ?? '', $ek);
eq('목록 칸 키: 이름표 ENUM(마이그레이션)·SPEC_AXIS_COLUMNS 에 모두 있음', [array_values(array_diff(SYC_PUBLISH_LIST_KEYS, $ek[1])), array_values(array_diff(SYC_PUBLISH_LIST_KEYS, array_keys(SPEC_AXIS_COLUMNS)))], [[], []]);
eq('추가 칸 6개 = 마이그레이션 ADD COLUMN', array_values(array_filter(SPEC_AXES_EXTRA_COLUMNS, static fn($c) => !str_contains($swSql, 'ADD COLUMN ' . $c . ' '))), []);
$withProducts = array_count_values(array_column($pj['rows'] ?? [], 'category_slug'));
$wtBad = [];
foreach (WORK_TYPE_SYC_MATERIALS as $k => $mats) { foreach ($mats as [$slug]) { if (empty($withProducts[$slug])) { $wtBad[] = "{$k}:{$slug}"; } } }
eq('상황별 자재(상용콘크리트): 설정 규칙 통과·모든 품목에 상품 있음·12개 작업 모두', [work_types_syc_validate(work_types(), WORK_TYPE_SYC_MATERIALS), $wtBad, count(WORK_TYPE_SYC_MATERIALS)], [[], [], 12]);
// 메인 '주요 자재' 묶음(2026-11-12): 카드 8개, 키가 작업 유형과 겹치지 않음, 품목은 상품 있는 품목 또는 중분류(syc-g), 묶음 안 중복 없음
$mmBad = [];
foreach (MAIN_MATERIAL_GROUPS as $g) {
    $sl = array_column($g['materials'], 0);
    if (count($sl) !== count(array_unique($sl))) { $mmBad[] = $g['key'] . ':중복'; }
    foreach ($sl as $x) { if (!str_starts_with($x, 'syc-g') && empty($withProducts[$x])) { $mmBad[] = $g['key'] . ':' . $x; } }
}
eq('주요 자재 묶음: 8개·키 고유(작업 유형과 안 겹침)·품목 유효', [count(MAIN_MATERIAL_GROUPS), count(array_unique(array_merge(array_column(MAIN_MATERIAL_GROUPS, 'key'), array_column(work_types(), 'key')))) === count(MAIN_MATERIAL_GROUPS) + count(work_types()), $mmBad], [8, true, []]);
// 목록 표 나누기: 값이 있는 목록 칸 구성이 같은 상품끼리(유공 침투측구수로관 W·H·L / 침투집수정 W·H·V)
$axG = [['axis_key' => 'w', 'label' => '폭'], ['axis_key' => 'h', 'label' => '높이'], ['axis_key' => 'l', 'label' => '길이'], ['axis_key' => 'v', 'label' => '세로']];
$gr = syc_list_groups([['name' => '수로관', 'dim_w' => 300, 'dim_h' => 300, 'dim_l' => 2000], ['name' => '집수정', 'dim_w' => 600, 'dim_h' => 850, 'dim_v' => 600], ['name' => '수로관', 'dim_w' => 400, 'dim_h' => 400, 'dim_l' => 2000]], $axG);
eq('표 나누기: 칸 구성별 2표·순서 유지·표마다 값 있는 칸만·제목 = 상품명', array_map(static fn($g) => [$g['title'], count($g['rows']), array_column($g['axes'], 'axis_key')], $gr),
    [['수로관', 2, ['w', 'h', 'l']], ['집수정', 1, ['w', 'h', 'v']]]);
$gr1 = syc_list_groups([['name' => 'A', 'dim_w' => 1], ['name' => 'B', 'dim_w' => 2]], $axG);
eq('표 나누기: 칸 구성이 모두 같으면 표 1개·제목 없음', [count($gr1), $gr1[0]['title'], array_column($gr1[0]['axes'], 'axis_key')], [1, '', ['w']]);
eq('표 나누기: 상품명 4종 이상이면 2개 + 외 N종', syc_list_groups(array_map(static fn($n) => ['name' => $n, 'dim_w' => 1], ['A', 'B', 'C', 'D']), $axG)[0]['title'] ?? null, '');
$gr4 = syc_list_groups(array_merge(array_map(static fn($n) => ['name' => $n, 'dim_w' => 1], ['A', 'B', 'C', 'D']), [['name' => 'E', 'dim_h' => 1]]), $axG);
eq('표 나누기: 제목 상품명 4종 이상', $gr4[0]['title'], 'A · B 외 2종');
eq('목록 주소: 필터 해제·빈 값 제거', syc_list_url(['category' => 5, 'ax_w' => ['300']], ['ax_w' => []]), '/syc-products.php?category=5');
echo "[운영 상담 #196·#199 (2026-11-11) — 등록 규격 안내 보장·평가 반복 제거]\n";
$regs = ['날개형' => '플륨관용(날개형U-TYPE) 185·285·295·385mm'];
eq('등록 규격이 답에 없으면 둘째 문단에 한 문장', rag_registered_note("날개형 제품은 폭 500mm에서 찾을 수 없었습니다.\n\n- A: 설명", $regs),
    "날개형 제품은 폭 500mm에서 찾을 수 없었습니다.\n\n'날개형' 제품은 플륨관용(날개형U-TYPE) 185·285·295·385mm 규격만 등록되어 있습니다.\n\n- A: 설명");
eq('등록 치수가 이미 답에 있으면 그대로', rag_registered_note("날개형은 185·285mm만 있습니다.", $regs), "날개형은 185·285mm만 있습니다.");
eq('등록 규격 정보가 없으면 그대로', rag_registered_note('답', []), '답');
$dup = "폭 500mm 제품입니다.\n\n- 사각주철: 주철 덮개입니다. 권장 수량은 17개이며, 예상 자재비는 약 366,520원입니다. 이 제품이 가장 경제적인 선택입니다.\n- EX메탈망: 스틸입니다. 권장 수량은 20개입니다.\n\n사각주철 제품이 가장 저렴합니다. 원하시는 제품을 선택해 주세요.";
eq("'가장 경제적'을 제품 줄·마지막 문단에서 두 번 → 제품 줄 쪽만 뺌", rag_dedupe_evaluation($dup),
    "폭 500mm 제품입니다.\n\n- 사각주철: 주철 덮개입니다. 권장 수량은 17개이며, 예상 자재비는 약 366,520원입니다.\n- EX메탈망: 스틸입니다. 권장 수량은 20개입니다.\n\n사각주철 제품이 가장 저렴합니다. 원하시는 제품을 선택해 주세요.");
eq('마지막 문단에 평가가 없으면 제품 줄 평가를 지우지 않음', rag_dedupe_evaluation("- A: 가장 저렴한 제품입니다.\n\n원하시는 제품을 선택해 주세요."), "- A: 가장 저렴한 제품입니다.\n\n원하시는 제품을 선택해 주세요.");

echo "[운영 테스트 지적 (2026-11-11) — '(더 비쌈)' 제거·조달 문구]\n";
eq("대체품 안내에 '(더 비쌈)' 없음", str_contains(ai_broadened_note('그레이팅 수로용', '폭 500mm', 'pedestrian', [['name' => '안전중하중그레이팅수로용']]), '더 비쌈'), false);
eq("조달 요약·답변 규칙에 조달몰·나라장터 쇼핑몰 없음, '조달청에 따르면'", [str_contains(AI_PROCUREMENT_NOTE, "'조달청에 따르면'으로 시작"), str_contains(ai_procurement_summary('흄관')[0]['summary'] ?? '', '조달몰')], [true, false]);

echo "[운영 #224·#226·#228·#233·#235·#237 (2026-11-12) — 검색 강제·면적 질문·법령 카드·'배수로' 오인]\n";
$ec = static fn(string $t): array => rag_explicit_conditions([['role' => 'user', 'content' => $t]]);
eq("'폭 300 배수로'의 '배'를 배수로 읽지 않음(폭 300, 90000 아님)", [$ec('마당에 폭 300 배수로 20m 덮개 필요해요')['cond']['width_mm'] ?? null, $ec('폭 300 배수관 덮개')['cond']['width_mm'] ?? null], [300.0, 300.0]);
eq('검색 강제 판정: 카탈로그 형태 말(직전 표 있음)·폭+품목은 강제, 일반 질문·과한(검색 금지)은 아님', [
    rag_must_search('날개형은 안돼??', $ec('날개형은 안돼??'), ['날개형'], true, '그레이팅 수로용', false),
    rag_must_search('마당에 폭 300 배수로 20m 덮개 필요해요', $ec('마당에 폭 300 배수로 20m 덮개 필요해요'), [], false, '', false),
    rag_must_search('배송은 언제 돼요?', $ec('배송은 언제 돼요?'), [], true, '그레이팅', false),
    rag_must_search('날개형은 안돼??', $ec('날개형은 안돼??'), ['날개형'], true, '그레이팅', true)], [true, true, false, false]);
eq('길이로 까는 자재의 면적 질문 → 그 자재 치수(블록은 그대로)', [
    rag_fix_area_question('필요한 배수관 뚜껑의 면적(㎡) 또는 수량을 알려주시면 추천해 드리겠습니다.', 'grating'),
    rag_fix_area_question('필요한 면적(㎡)이나 수량(개)을 말씀해 주시면 안내해 드리겠습니다.', 'pe_pipe'),
    rag_fix_area_question('면적(㎡)을 알려 주세요.', 'pavement')],
    ['필요한 배수관 뚜껑의 폭(mm)과 설치 길이(m)를 알려주시면 추천해 드리겠습니다.', '관경(mm)과 총 길이(m)를 말씀해 주시면 안내해 드리겠습니다.', '면적(㎡)을 알려 주세요.']);
$ld = [['id' => 57, 'source_name' => '보행안전 및 편의증진에 관한 법률 시행규칙 (행정안전부령 제344호)', 'content' => '자동차 진입억제용 말뚝은 … 간격은 1.5미터 안팎(1.5m)으로 한다'],
       ['id' => 56, 'source_name' => '보행안전 및 편의증진에 관한 법률 시행규칙 (행정안전부령 제344호)', 'content' => '보행자길에 설치하는 시설'],
       ['id' => 68, 'source_name' => '보도 설치 및 관리 지침 (국토교통부예규 제321호)', 'content' => '구분 Ⅱ … 표층 80mm']];
eq('[L번호] 없이 쓴 근거: 수치가 원문에 있는 근거만, 없으면 이름이 나온 근거, 법령을 쓰지 않으면 없음', [
    rag_legal_implicit_ids('보행안전법 시행규칙 별표 1 기준 간격 1.5m 내외로 설치합니다.', $ld),
    rag_legal_implicit_ids('보행안전법 시행규칙에서 기준을 정하고 있습니다.', $ld),
    rag_legal_implicit_ids('포장 블록 두께는 80mm 이상을 권합니다.', $ld),
    rag_legal_implicit_ids('스테인리스 볼라드가 있습니다.', $ld)], [[57], [57, 56], [68], []]);


echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
