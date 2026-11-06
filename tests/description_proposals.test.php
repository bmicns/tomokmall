<?php
declare(strict_types=1);
/**
 * 상품 설명 정비안(scripts/data-quality/description_lib.php·build_description_proposals.php) 단위 테스트 — DB·네트워크 불필요.
 * 확인: 문제 문장 표시(하중·차량·홍보·KS·규격 반복·두께 충돌), 확인된 데이터만으로 만든 항목형 새 설명, 정비 구분(문제 없는 설명은 그대로·문제 문장만 삭제),
 *       기본은 고르는 기준만(분류 안내·KS 적용 범위는 옵션 — 상품 검색 영향),
 *       적용 SQL 은 description 칸만·만든 시점 설명과 같을 때만, 되돌리기 SQL, 저장소 안 출력 거부.
 * 실행: php tests/description_proposals.test.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ob_start();
require __DIR__ . '/../app/bootstrap.php';
ob_end_clean();
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/external_products.php';
require_once __DIR__ . '/../scripts/data-quality/description_lib.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}\n"; }
}
// 운영 상품 설명(2026-11-05 읽기 전용 조회)에 실제로 있던 문장 형태
$old = '흄관(A형)은 주로 하수도 및 배수 시스템에 사용되며, 안정적인 물 흐름을 보장합니다. 이 제품은 중량이 166kg으로 차량 통행이 가능하며, T-25 하중 조건을 만족합니다. 내구성이 뛰어난 재질로 제작되어 오랜 사용이 가능합니다.';

echo "[문제 문장 표시]\n";
$s = desc_sentences($old);
ok('문장 3개로 나눔', count($s) === 3);
ok('T-25·차량 통행·중량 문장 → 하중·차량·규격 반복', !array_diff(['load', 'vehicle', 'spec'], desc_sentence_flags($s[1])));
ok('홍보 문장(내구성·오랜 사용) → 홍보', in_array('promo', desc_sentence_flags($s[2]), true));
ok('KS 주장 문장 → KS', in_array('ks', desc_sentence_flags('KS 인증을 받은 연직 구체로 설치할 수 있습니다.'), true));
ok('문제 없는 용도 문장 → 표시 없음', desc_sentence_flags('낙엽방지덮개는 빗물받이에 설치하여 낙엽이나 이물질의 유입을 방지하는 용도로 사용됩니다.') === []);
ok('두께 80T 블록인데 차량 통행 불가 → 규격 충돌', desc_conflicts_spec('차량 통행이 불가능하며 보행 공간에 적합합니다.', ['name' => '인조화강블록(불투수)', 'spec' => '규격: 300*300*80T | 단위: m 2']));
ok('두께 60T 블록인데 차량 통행 가능 → 규격 충돌', desc_conflicts_spec('차량 통행이 가능하여 주차장에 적합합니다.', ['name' => '인터로킹U형블럭', 'spec' => '규격: 220*110*60T_회색 | 단위: m 2']));
ok('80T 통행 가능·블록 아닌 상품 → 충돌 아님', !desc_conflicts_spec('차량 통행이 가능합니다.', ['name' => '인터로킹U형블럭', 'spec' => '규격: 220*110*80T_회색'])
    && !desc_conflicts_spec('차량 통행이 불가합니다.', ['name' => 'PVC관VN(구VG1)', 'spec' => '규격: D100*4000']));

echo "[새 설명 — 확인된 데이터만, 항목형]\n";
$ks = desc_ks_scopes([
    ['type' => 'KS', 'ref_no' => 'KS F 4403', 'official_name' => '원심력 철근 콘크리트관', 'scope_note' => '원심력 또는 축 전압으로 만든 철근 콘크리트관과 직관 A형 이음용 칼라 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)'],
    ['type' => 'KS', 'ref_no' => 'KS F 4016', 'official_name' => '철근 콘크리트 U형', 'scope_note' => '적용범위·치수 검색에서 확인 안 됨'],
    ['type' => '법령', 'ref_no' => '제3조', 'official_name' => '주차장법 시행규칙', 'scope_note' => '사용자 확인'],
]);
ok('사용자 확인 KS 적용 범위만(검색 요약·법령 제외), 표시 괄호는 뗌', array_keys($ks) === ['KS F 4403'] && $ks['KS F 4403']['scope'] === '원심력 또는 축 전압으로 만든 철근 콘크리트관과 직관 A형 이음용 칼라');
$p1 = desc_proposal(['id' => 1, 'name' => '흄관(A형)', 'spec' => '규격: D300*2500*30T | 중량: 194kg | 단위: 본'], '개거, 흄관, VR관(흄관대용), 레진관 등 철근콘크리트 기타', $ks);
ok('KS 대상 제품군 → 관련 국가표준·적용 범위(인증이라고 쓰지 않음), 분류 안내는 생략', str_starts_with($p1, '관련 국가표준: KS F 4403 원심력 철근 콘크리트관 — 적용 범위: ') && !str_contains($p1, '인증') && !str_contains($p1, '분류 안내'));
$p2 = desc_proposal(['id' => 2, 'name' => '점토블럭', 'spec' => '규격: 200*100*60T | 단위: m 2'], '인조화강·보강도·호안·잔디블록 등 다양한 규격의 인터로킹 보도블록', $ks);
ok('KS 없음 → 분류 안내 + 고르는 기준(두께·재질·판매 단위)', $p2 === "분류 안내: 인조화강·보강도·호안·잔디블록 등 다양한 규격의 인터로킹 보도블록\n고르는 기준\n- 두께 60mm — 사람만 다니는 곳용(차량이 올라가면 80mm 이상)\n- 점토 — 흙을 구워 만든 블록\n- ㎡(면적) 단위 판매 — 시공 면적만큼 주문");
ok('하중·차량·홍보 문구 없음(새 설명)', !preg_match('/T-?25|통행이?\s*(가능|불가)|내구성|뛰어/u', $p1 . $p2));
ok('재료가 없으면 빈 설명', desc_proposal(['id' => 3, 'name' => '0.8*0.8*2.0_130T*130T_구', 'spec' => '규격: 0.8*0.8*2.0'], '', $ks) === '');
ok('VR관(흄관대용)은 KS F 4403 아님 → 분류 안내만', str_starts_with(desc_proposal(['id' => 4, 'name' => 'VR관(흄관대용)', 'spec' => ''], '개거, 흄관 등', $ks), '분류 안내:'));

echo "[정비 구분]\n";
$r = desc_review(['id' => 1, 'name' => '흄관(A형)', 'spec' => '', 'description' => $old], '', $ks);
ok('기존 설명 + 재료 있음 → fix, 문제 문장 3개 모두 표시', $r['kind'] === 'fix' && $r['flagged'] === 3 && $r['sentences'] === 3 && str_starts_with((string) $r['new'], '관련 국가표준'));
$r = desc_review(['id' => 5, 'name' => '낙엽방지덮개', 'spec' => '', 'description' => '낙엽방지덮개는 빗물받이에 설치하여 낙엽이나 이물질의 유입을 방지하는 용도로 사용됩니다. 내구성이 뛰어납니다.'], '', $ks);
ok('재료 없음 → 문제 문장만 뺀 기존 문장(keep_clean)', $r['kind'] === 'keep_clean' && $r['new'] === '낙엽방지덮개는 빗물받이에 설치하여 낙엽이나 이물질의 유입을 방지하는 용도로 사용됩니다.');
$r = desc_review(['id' => 6, 'name' => '0.8*0.8*2.0_130T*130T_구', 'spec' => '', 'description' => 'T-25 하중을 견딥니다. 내구성이 뛰어납니다.'], '', $ks);
ok('재료 없음·남는 문장 없음 → 설명 비움(clear, NULL)', $r['kind'] === 'clear' && $r['new'] === null);
$r = desc_review(['id' => 9, 'name' => 'XPS 압출법 단열재 50T', 'spec' => '900 x 1800 x 50mm', 'description' => '[테스트] 지하 외벽 단열에 쓰는 압출법 단열재'], '단열 공법 자재', $ks);
ok('문제 문장 없는 기존 설명 → 그대로(none — 분류 안내로 덮어쓰지 않음)', $r['kind'] === 'none' && $r['new'] === null && $r['flagged'] === 0);
$r = desc_review(['id' => 10, 'name' => '점토블럭', 'spec' => '규격: 200*100*60T | 단위: m 2', 'description' => '점토블럭은 산책로 포장에 쓰입니다. 내구성이 뛰어납니다. 테라스 바닥에도 씁니다.'], '', []);
ok('일부만 문제 → 그 문장만 빼고 남은 문장(순서 그대로) + 고르는 기준', $r['kind'] === 'fix' && $r['flagged'] === 1
    && str_starts_with((string) $r['new'], "점토블럭은 산책로 포장에 쓰입니다. 테라스 바닥에도 씁니다.\n고르는 기준\n- 두께 60mm"));
$r = desc_review(['id' => 11, 'name' => '낙엽방지덮개', 'spec' => '', 'description' => '빗물받이에 설치합니다. 낙엽을 막습니다. 이물질을 거릅니다. 내구성이 뛰어납니다.'], '', []);
ok('남은 기존 문장은 개수를 줄이지 않음(keep_clean)', $r['kind'] === 'keep_clean' && $r['new'] === '빗물받이에 설치합니다. 낙엽을 막습니다. 이물질을 거릅니다.');
ok('설명 없던 상품 + 재료 → fill / 재료도 없음 → none', desc_review(['id' => 7, 'name' => '점토블럭', 'spec' => '규격: 200*100*60T', 'description' => ''], '분류', $ks)['kind'] === 'fill'
    && desc_review(['id' => 8, 'name' => '알수없음', 'spec' => '', 'description' => null], '', $ks)['kind'] === 'none');

echo "[적용·되돌리기 SQL]\n";
$u = desc_update_sql(10, "어제's 설명", "새 설명\n고르는 기준");
ok('description 칸만, 만든 시점 설명(MD5)과 같을 때만, 따옴표 이스케이프', $u === "UPDATE products SET description = '새 설명\n고르는 기준' WHERE id = 10 AND MD5(COALESCE(description, '')) = '" . md5("어제's 설명") . "';"
    && str_contains(desc_update_sql(11, "a'b", 'x'), "MD5(COALESCE(description, '')) = '" . md5("a'b") . "'") && str_contains(desc_update_sql(12, 'x', "it's"), "'it''s'"));
ok('가격·원가 칸을 건드리지 않음', !preg_match('/price|cost|discount|amount/i', $u . desc_revert_sql(10, 'a', 'b')));
ok('비움은 NULL, 되돌리기는 정비안이 그대로 있을 때만 이전 설명으로(빈 설명은 NULL)', str_contains(desc_update_sql(13, 'old', null), 'SET description = NULL')
    && desc_revert_sql(13, 'old', null) === "UPDATE products SET description = 'old' WHERE id = 13 AND MD5(COALESCE(description, '')) = '" . md5('') . "';"
    && str_contains(desc_revert_sql(14, '', 'new'), 'SET description = NULL'));

echo "[생성기 CLI]\n";
$tmp = sys_get_temp_dir() . '/desc_prop_test_' . getmypid();
@mkdir($tmp);
file_put_contents("{$tmp}/p.json", json_encode(['result' => ['rows' => [
    ['id' => 1, 'category_id' => 2, 'name' => '흄관(A형)', 'spec' => '규격: D300*2500*30T | 중량: 194kg | 단위: 본', 'description' => $old],
    ['id' => 2, 'category_id' => 3, 'name' => '점토블럭', 'spec' => '규격: 200*100*60T | 단위: m 2', 'description' => null],
    ['id' => 3, 'category_id' => 9, 'name' => '알수없음', 'spec' => '', 'description' => ''],
]]], JSON_UNESCAPED_UNICODE));
file_put_contents("{$tmp}/c.json", json_encode([['id' => 2, 'parent_id' => 1, 'name' => '흄관(A형)', 'description' => ''], ['id' => 1, 'parent_id' => null, 'name' => '기타', 'description' => '개거, 흄관 등 철근콘크리트 기타 제품을 공급합니다.'],
    ['id' => 3, 'parent_id' => null, 'name' => '점토블럭', 'description' => '점토 블록을 공급합니다.']], JSON_UNESCAPED_UNICODE));
$bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/data-quality/build_description_proposals.php');
exec("{$bin} --products=" . escapeshellarg("{$tmp}/p.json") . ' --categories=' . escapeshellarg("{$tmp}/c.json") . ' --out=' . escapeshellarg(__DIR__ . '/../docs/should_not_exist') . ' 2>&1', $o1, $code1);
ok('저장소 안 출력 폴더 → 거부(폴더도 만들지 않음)', $code1 === 2 && !is_dir(__DIR__ . '/../docs/should_not_exist'));
exec("{$bin} --products=" . escapeshellarg("{$tmp}/p.json") . ' --categories=' . escapeshellarg("{$tmp}/c.json") . ' --out=' . escapeshellarg("{$tmp}/out") . ' 2>&1', $o2, $code2);
$csv = (string) @file_get_contents("{$tmp}/out/review.csv");
$fix = (string) @file_get_contents("{$tmp}/out/50_description_fix.sql");
$fill = (string) @file_get_contents("{$tmp}/out/51_description_fill.sql");
ok('저장소 밖 → 검토표(BOM)·정비·채우기·되돌리기·요약 생성', $code2 === 0 && str_starts_with($csv, "\xEF\xBB\xBF") && is_file("{$tmp}/out/59_description_revert.sql") && is_file("{$tmp}/out/summary.md"));
ok('정비 1문(흄관)·채우기 1문(점토블럭)·변경 없음은 SQL 없음', substr_count($fix, 'UPDATE products') === 1 && substr_count($fill, 'UPDATE products') === 1 && str_contains($fix, 'WHERE id = 1 ') && str_contains($fill, 'WHERE id = 2 '));
ok('요약에는 설명 본문이 없음(집계만)', !str_contains((string) @file_get_contents("{$tmp}/out/summary.md"), '안정적인 물 흐름'));
ok('기본: 분류 안내·KS 적용 범위 글을 넣지 않음(상품 검색에 다른 종류가 섞이지 않게)', !str_contains($fix . $fill, '분류 안내') && !str_contains($fix . $fill, '관련 국가표준') && str_contains($fill, '고르는 기준'));
ok('검토표 분류 칸 = 분류 이름', str_contains($csv, ',점토블럭,점토블럭,'));
exec("{$bin} --products=" . escapeshellarg("{$tmp}/p.json") . ' --categories=' . escapeshellarg("{$tmp}/c.json") . ' --with-category-brief --with-ks-scope --out=' . escapeshellarg("{$tmp}/out2") . ' 2>&1', $o3, $code3);
$fix2 = (string) @file_get_contents("{$tmp}/out2/50_description_fix.sql") . (string) @file_get_contents("{$tmp}/out2/51_description_fill.sql");
ok('--with-category-brief·--with-ks-scope → 분류 안내·관련 국가표준(사용자 확인분)', $code3 === 0 && str_contains($fix2, '분류 안내: 점토 블록') && str_contains($fix2, '관련 국가표준: KS F 4403'));
array_map('unlink', glob("{$tmp}/out2/*") ?: []);
@rmdir("{$tmp}/out2");
array_map('unlink', glob("{$tmp}/out/*") ?: []);
@rmdir("{$tmp}/out");
array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
