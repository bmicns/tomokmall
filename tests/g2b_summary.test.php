<?php
declare(strict_types=1);
/**
 * 조달 품목 등록 내역 → 자재 종류별 공공조달 등록 현황(scripts/external/g2b_summary.php) 단위 테스트 — DB·네트워크 불필요.
 * 입력은 조달데이터허브 보고서 모양으로 테스트용으로 만든 가상 행('[테스트]' 업체)이다. 실제 조달 데이터가 아니다.
 * 확인: UTF-16·위쪽 검색조건 줄, 물품식별번호 중복 제거(납품장소마다 반복), 우리 취급 세부품명만, 인증 표시 집계, 가격·사업자등록번호·업체 이름 미포함, 저장소 안 원본 거부.
 * 실행: php tests/g2b_summary.test.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../scripts/external/g2b_summary.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}\n"; }
}
$head = ['계약구분', '계약번호', '변경차수', '계약기간', '대분류쇼핑카테고리', '중분류쇼핑카테고리', '물품분류번호', '품명', '세부품명번호', '세부품명', '물품식별번호', '품목명', '단가', '단위',
         '납품장소', '인도조건', '공급지역', '납품일수', 'MAS여부', '우수제품여부', '물품인증유형목록', '업체', '업체소재시군구', '기업구분', '업체사업자등록번호', '구매방법구분명', '쇼핑몰등록여부', '쇼핑몰등록일자'];
$row = static function (string $detail, string $id, string $name, string $price, string $place, string $excellent, string $certs, string $maker, string $biz, string $date) use ($head): string {
    $v = array_combine($head, array_fill(0, count($head), ''));
    $v = array_merge($v, ['계약구분' => '제3자단가계약', '세부품명' => $detail, '물품식별번호' => $id, '품목명' => $name, '단가' => $price, '납품장소' => $place,
                          '우수제품여부' => $excellent, '물품인증유형목록' => $certs, '업체' => $maker, '업체사업자등록번호' => $biz, '쇼핑몰등록일자' => $date]);
    return implode("\t", array_map(static fn($x) => '"' . $x . '"', $v));
};
$lines = ["검색조건 : 프롬프트 1: 쇼핑몰등록일자(From)", '', '프롬프트 6: 대분류쇼핑카테고리', '토목/건축/자재', '출력일자 : 2026-11-05', 'UI-ADOXFA-183R.나라장터쇼핑몰 품목 등록 내역',
    implode("\t", array_map(static fn($x) => '"' . $x . '"', $head)),
    $row('원심력철근콘크리트관', '90000001', '원심력철근콘크리트관, [테스트]가상관, 보통관B, Φ300×2500mm', '98765', '1001', 'N', 'KS, 소기업', '[테스트]가상관', '1112233333', '20260703'),
    $row('원심력철근콘크리트관', '90000001', '원심력철근콘크리트관, [테스트]가상관, 보통관B, Φ300×2500mm', '98765', '1002', 'N', 'KS, 소기업', '[테스트]가상관', '1112233333', '20260703'),
    $row('원심력철근콘크리트관', '90000002', '원심력철근콘크리트관, [테스트]다른관, 보통관B, Φ400×2500mm', '123456', '1001', 'Y', '단체표준인증', '[테스트]다른관', '4445566666', '20261021'),
    $row('철근콘크리트용배수로관', '90000003', '철근콘크리트용배수로관, [테스트]가상수로, 300×300×1000mm', '55555', '1001', 'N', '단체표준인증, 환경표지제품', '[테스트]가상수로', '7778899999', '20250407'),
    $row('금속제끈', '90000004', '금속제끈, 인식표줄용', '461', '1001', 'N', '소상공인', '[테스트]끈', '1231231234', '20260707'),
    ''];
$tmp = sys_get_temp_dir() . '/g2b_sum_test_' . getmypid();
@mkdir($tmp);
file_put_contents("{$tmp}/hub.csv", "\xFF\xFE" . mb_convert_encoding(implode("\r\n", $lines), 'UTF-16LE', 'UTF-8'));

echo "[읽기·중복 제거]\n";
$read = g2b_read_lines("{$tmp}/hub.csv");
ok('UTF-16(BOM)·CRLF → UTF-8 줄', in_array('토목/건축/자재', $read, true));
[$asOf, $items] = g2b_parse_items($read);
ok('출력일자 읽음 · 머리글 위 검색조건 줄은 건너뜀', $asOf === '2026-11-05' && count($items) === 4);
ok('물품식별번호 중복(납품장소만 다름) 1개로', count(array_filter($items, static fn($i) => str_contains($i['name'], 'Φ300'))) === 1);
ok('품목 행에 단가·사업자등록번호·납품장소 칸이 없음', !array_filter($items, static fn($i) => array_intersect(array_keys($i), ['단가', 'price', 'biz', '업체사업자등록번호', 'place'])));

echo "[자재 종류별 집계]\n";
$sum = g2b_summarize($items, $asOf);
$hume = $sum['groups']['hume_pipe'] ?? [];
ok('우리 취급 세부품명만(금속제끈 제외)', array_keys($sum['groups']) === ['hume_pipe', 'drain_channel']);
ok('흄관: 품목 2 · 업체 2 · KS 1 · 단체표준 1 · 우수조달 1(우수제품여부 Y)', ($hume['items'] ?? 0) === 2 && $hume['makers'] === 2 && $hume['certs']['ks'] === 1
    && $hume['certs']['group_standard'] === 1 && $hume['certs']['excellent'] === 1);
ok('등록일 범위', $hume['registered_from'] === '2026-07-03' && $hume['registered_to'] === '2026-10-21');
ok('상담 낱말·표시 이름', $hume['label'] === '원심력 철근콘크리트관(흄관)' && in_array('흄관', $hume['words'], true));
$php = g2b_summary_php($sum);
ok('결과 파일에 가격·사업자등록번호·업체 이름 없음', !preg_match('/98765|123456|1112233333|4445566666|\[테스트\]/u', $php) && str_contains($php, 'return array'));
file_put_contents("{$tmp}/out.php", $php);
$back = require "{$tmp}/out.php";
ok('결과 파일은 PHP 배열(출처·기준일 포함)로 다시 읽힘', ($back['as_of'] ?? '') === '2026-11-05' && str_contains((string) $back['source'], '조달청') && isset($back['groups']['drain_channel']));
$md = g2b_summary_md($sum, count($items));
ok('사람이 읽는 표(비율 포함)', str_contains($md, '| 원심력철근콘크리트관 | 2 | 2 | 1 (50%) |') && str_contains($md, '전체 품목(중복 제거) 4개'));

echo "[CLI]\n";
$bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/external/g2b_summary.php');
copy("{$tmp}/hub.csv", __DIR__ . '/../docs/g2b_should_not_be_here.csv');
exec("{$bin} --file=" . escapeshellarg(__DIR__ . '/../docs/g2b_should_not_be_here.csv') . ' --out=' . escapeshellarg("{$tmp}/x.php") . ' 2>&1', $o1, $c1);
@unlink(__DIR__ . '/../docs/g2b_should_not_be_here.csv');
ok('저장소 안 원본 파일 → 거부(가격·사업자등록번호가 든 원본은 저장소 밖)', $c1 === 2 && !is_file("{$tmp}/x.php"));
exec("{$bin} --file=" . escapeshellarg("{$tmp}/hub.csv") . ' --out=' . escapeshellarg("{$tmp}/y.php") . ' --md=' . escapeshellarg("{$tmp}/y.md") . ' 2>&1', $o2, $c2);
ok('저장소 밖 원본 → 데이터 파일·요약 표 생성', $c2 === 0 && is_file("{$tmp}/y.php") && is_file("{$tmp}/y.md"));
array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
