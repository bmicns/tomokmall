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

echo "[하이라이트 highlight_terms]\n";
ok('검색어 <mark> 삽입', strpos(highlight_terms('측구수로관', ['측구']), '<mark') !== false);
ok('HTML 이스케이프',    strpos(highlight_terms('<b>x</b>', []), '&lt;') !== false);

echo "[규격 파싱 parse_spec_field]\n";
$sp = parse_spec_field('규격: 400*400 | 단위: 본 | 중량: 100kg');
ok('규격 추출', $sp['spec'] === '400*400');
ok('단위 추출', $sp['unit'] === '본');

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

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
