<?php
declare(strict_types=1);
/**
 * 외부 상품 데이터 정규화·중복 제거·매칭·상담 카드 선택 도움말 단위 테스트 — DB·네트워크 불필요.
 * 입력 문자열은 테스트용 표기 예시(실제 수집 데이터 아님).
 * 실행: php tests/external_products.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
putenv('DB_HOST=127.0.0.1;port=1');   // DB 없이 실행(접속 시도는 실패해도 순수 함수만 사용)
ob_start();
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/external_products.php';
ob_end_clean();

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}

echo "[정리·개인정보]\n";
[$t, $n] = ext_scrub_pii('담당 홍길동 010-0000-0000 test@example.com 문의');
ok('전화번호·이메일 제거 + 건수', !str_contains($t, '010') && !str_contains($t, '@') && $n === 2);
ok('HTML 엔티티·태그 정리', ext_clean_text('25톤 &raquo; <b>5톤</b>') === '25톤 » 5톤');

echo "[단위·종류·재질·치수·속성]\n";
ok('단위: m 2→㎡, EA→개, 본, set→세트', ext_unit('m 2') === '㎡' && ext_unit('EA') === '개' && ext_unit('본') === '본' && ext_unit('set') === '세트');
ok('종류: 속빈·조적 블록은 구조용(보도블록 아님)', ext_product_type('속빈콘크리트블록') === '구조용블록' && ext_product_type('조적블록') === '구조용블록');
ok('종류: 인터로킹·인조화강·점토·수로관·PE관', ext_product_type('인터로킹U형블럭') === '인터로킹블록' && ext_product_type('인조화강블록(투수)') === '인조화강블록'
    && ext_product_type('점토블럭') === '점토블록' && ext_product_type('돌무늬원형사각수로관') === '수로관' && ext_product_type('HDPE이중벽관') === 'PE관');
ok('재질: 표기 없으면 빈 값(추측 안 함)', ext_material('인터로킹U형블럭') === '' && ext_material('주철뚜껑') === '주철' && ext_material('PE다중벽관') === 'PE');
$d = ext_dims('0.8*0.8*2.0_130T*130T_구');
ok('치수: 소수 미터 → mm, 셋째 치수, 두께', $d['width_mm'] === 800 && $d['length_mm'] === 800 && $d['dim3_mm'] === 2000 && $d['thickness_mm'] === 130);
ok('치수: 블록 가로*세로*두께', ext_dims('규격: 200*200*80T(투수) | 단위: m 2')['thickness_mm'] === 80);
ok('치수: 관경 D300·300A', ext_dims('D300*2M')['diameter_mm'] === 300 && ext_dims('300*300*2000*100T (300A)')['diameter_mm'] === 300);
$a = ext_attrs('인조화강블록(불투수) 안전중하중 KS F 4419 보도용');
ok('속성: 불투수·안전중하중·KS·용도', $a['permeable'] === false && $a['load'] === '안전중하중' && $a['ks'] === ['KS F 4419'] && in_array('보도', $a['uses'], true));
ok('속성: 투수 / 중하중', ext_attrs('투수블록')['permeable'] === true && ext_attrs('중하중그레이팅')['load'] === '중하중');

echo "[정규화·중복 제거]\n";
$e = ext_normalize(['name' => '0.8*0.8*2.0_130T*130T', 'spec' => '중량/단위: 2,640kg / 본', 'category' => '1련암거', 'price' => '695,000'], 'test');
ok('이름 칸이 치수뿐이면 분류명이 상품명, 치수는 규격으로', $e['name'] === '1련암거' && $e['width_mm'] === 800 && $e['thickness_mm'] === 130 && $e['product_type'] === '암거');
ok('가격은 참고가(ref_price)로만', $e['ref_price'] === 695000 && !isset($e['price_b2c']));
$x = ext_normalize(['name' => '인조화강블록(투수)', 'spec' => '200*200*80T', 'maker' => 'A'], 't');
$y = ext_normalize(['name' => '인조화강블럭 (투수)', 'spec' => '200*200*80T', 'maker' => 'A', 'external_id' => 'X1'], 't');
$z = ext_normalize(['name' => '인조화강블록(투수)', 'spec' => '200*200*60T', 'maker' => 'A'], 't');
[$u, $dup] = ext_dedup([$x, $y, $z]);
ok('블록/블럭·공백 차이는 같은 상품으로 합치고, 두께가 다르면 별개', count($u) === 2 && $dup === 1);
ok('합칠 때 빈 칸이 적은 행 유지(식별번호 있는 쪽)', in_array('X1', array_column($u, 'external_id'), true));

echo "[매칭 점수]\n";
$p = static fn(int $id, string $name, string $spec): array => ext_normalize_product(['id' => $id, 'name' => $name, 'spec' => $spec]);
$prods = [
    $p(1, '인조화강블록(투수)', '규격: 200*200*80T(투수) | 단위: m 2'),
    $p(2, '인조화강블록(투수)', '규격: 200*200*60T(투수) | 단위: m 2'),
    $p(3, '인조화강블록(불투수)', '규격: 200*200*80T(불투수) | 단위: m 2'),
    $p(4, '사각수로관(AN형)', '규격: 300*300*2000*100T (300A) | 단위: 본'),
    $p(5, '사각수로관(DN형)', '규격: 300*300*2000*100T (300A) | 단위: 본'),
];
$best = ext_best_matches(ext_normalize(['name' => '인조화강블록 투수', 'spec' => '200*200*80T'], 't'), $prods);
ok('같은 종류·두께·투수 → 1순위·유력(80+)', ($best[0]['product_id'] ?? 0) === 1 && $best[0]['grade'] === 'probable');
ok('두께 다름·불투수는 낮은 순위 또는 제외', !in_array(3, array_slice(array_column($best, 'product_id'), 0, 1), true) && ($best[1]['score'] ?? 0) < $best[0]['score']);
$best = ext_best_matches(ext_normalize(['name' => '사각수로관(DN형)', 'spec' => '300*300*2000*100T (300A)'], 't'), $prods);
ok('모델 표기(DN형·AN형)가 다르면 같은 규격이어도 구분', ($best[0]['product_id'] ?? 0) === 5 && ($best[1]['score'] ?? 0) < 80);
ok('종류가 다르면 0점', ext_match_score(ext_normalize(['name' => '중하중그레이팅수로용'], 't'), $prods[0])['score'] === 0);

echo "[상담 카드 고르는 기준]\n";
$g = ext_selection_guide(['id' => 1, 'name' => '인조화강블록(투수)', 'spec' => '규격: 200*200*80T(투수) | 단위: m 2']);
ok('두께·투수·재질 설명·면적 단위(최대 4개)', count($g) === 4 && str_contains($g[0], '두께 80mm') && str_contains($g[1], '투수형') && str_contains(implode(' ', $g), '㎡'));
$g = ext_selection_guide(['id' => 2, 'name' => '인터로킹U형블럭', 'spec' => '규격: 220*110*60T_회색 | 단위: m 2']);
ok('60T 는 보행용 + 차량은 80mm 이상 안내', str_contains($g[0], '사람만 다니는 곳') && str_contains($g[0], '80mm 이상'));
$g = ext_selection_guide(['id' => 3, 'name' => '중하중그레이팅수로용', 'spec' => '규격: 300*1000*50(7*4) | 단위: 개']);
ok('그레이팅 하중 등급 설명', $g === ['중하중 — 승용차·SUV 통행']);
$g = ext_selection_guide(['id' => 1, 'name' => '인조화강블록(투수)', 'spec' => '규격: 200*200*80T(투수)'], [['maker' => '시험제조', 'ks' => ['KS F 4419'], 'source_name' => '시험 출처']]);
ok('확정된 외부 자료는 출처와 함께(가격 없음)', str_contains(end($g), '외부 자료: 시험제조 · KS F 4419 (출처 시험 출처)') && !preg_match('/원|₩|가격/u', implode(' ', $g)));
ok('관계없는 품목은 설명 없음(지어내지 않음)', ext_selection_guide(['id' => 9, 'name' => '규사', 'spec' => '규격: 5호사_1.0톤 | 단위: 톤']) === []);

echo "[파일 읽기]\n";
$tmp = tempnam(sys_get_temp_dir(), 'ext');
file_put_contents($tmp, mb_convert_encoding("품명\t규격\t제조업체명\t계약단가\t단위\t물품식별번호\t비고\n인조화강블록\t200*200*80T\t시험\t1,000\tm2\tT1\t담당 010-0000-0000\n", 'CP949', 'UTF-8'));
$r = ext_read_csv($tmp);
unlink($tmp);
ok('CP949·탭 CSV 머리글 자동 인식', ($r[0]['name'] ?? '') === '인조화강블록' && ($r[0]['maker'] ?? '') === '시험' && ($r[0]['price'] ?? '') === '1,000' && ($r[0]['external_id'] ?? '') === 'T1');
ok('비고의 연락처는 정규화에서 제거', ext_normalize($r[0], 't')['pii_removed'] === 1);

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
