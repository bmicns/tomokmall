<?php
declare(strict_types=1);
/**
 * 제조사 카탈로그 글 → 수집기 레코드(scripts/external/catalog_lib.php)·카탈로그 도구 권한 확인(catalog-extract.php) 단위 테스트 — DB·네트워크 불필요.
 * 입력 글은 pdftotext -layout 결과 모양으로 테스트용으로 만든 가상 카탈로그('[테스트용 가상 카탈로그]' 표기)다. 실제 제조사 자료가 아니다.
 * 실행: php tests/catalog_extract.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
putenv('DB_HOST=127.0.0.1;port=1');
ob_start();
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/external_products.php';
require_once __DIR__ . '/../app/external_crawl.php';
require_once __DIR__ . '/../scripts/external/catalog_lib.php';
ob_end_clean();

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}

$text = "[테스트용 가상 카탈로그] 가상제조(주)\n회사 소개\n저희는 1990년에 설립되었습니다.\n대표전화 000-0000-0000\n"
    . "\f인터로킹 블럭\n\n\n보도, 광장, 공원 산책로에 적용합니다.\n블록 사이 맞물림 구조로 하중이 고르게 분산되는 것이\n특징입니다.\n"
    . "기층 다짐이 부족하지 않도록 주의하십시오.\nKS F 4419 인증 제품입니다.\n\n"
    . "구분               규격(mm)     두께     단가(원)\n보도용              200x200    60mm   9,900원\n차도용              200x200    80mm   12,000원\n\n"
    . "공급가격은 별도 문의 바랍니다.\n가상제조(주) Tel 000-0000-0000\n"
    . "\fPE 이중벽관\n\n하수·우수 배수관로에 사용합니다.\n\n"
    . "호칭             내경         외경      길이       재질\nD100             D100       118mm   6000mm     HDPE\nD150             D150       177mm   6000mm     HDPE\n"
    . "\n기타 규격\nD200             D200       236mm\n"
    . "\f200x200x60\n측구 수로관\n도로 가장자리 배수에 적합합니다.\n규격 300x300x1000 과 400x400x1000 을 생산합니다.\n"
    . "\f인증 현황\n가상제조(주) Tel 000-0000-0000\n\f\n";

echo "[쪽·제목·가격 줄]\n";
$pages = catalog_pages($text);
ok('쪽 나누기: \\f 기준, 빈 쪽 버림', count($pages) === 5);
ok('제목: 자재 종류가 읽히는 첫 짧은 줄(블럭 표기도)', catalog_heading(preg_split('/\n/u', $pages[1])) === '인터로킹 블럭');
ok('제목: 치수만 있는 줄은 건너뜀', catalog_heading(preg_split('/\n/u', $pages[3])) === '측구 수로관');
ok('제목 없음: 회사 소개·인증 현황 쪽', catalog_heading(preg_split('/\n/u', $pages[0])) === '' && catalog_heading(preg_split('/\n/u', $pages[4])) === '');
ok('가격 줄 판정: 원·단가·가격', catalog_is_price('9,900원') && catalog_is_price('단가(원)') && catalog_is_price('공급가격은 별도 문의 바랍니다.') && !catalog_is_price('원심력 철근 콘크리트관') && !catalog_is_price('200x200'));

echo "[규격 줄 — 머리글·가격 칸]\n";
$rows = catalog_spec_rows(preg_split('/\n/u', $pages[1]));
ok('규격 줄 2개(머리글 행은 레코드 아님)', count($rows) === 2);
ok('머리글을 칸에 붙임: 규격(mm) 200x200 / 두께 60mm', ($rows[0][1] ?? []) === ['규격(mm) 200x200', '두께 60mm'] && $rows[0][0] === '보도용');
ok('가격 칸(단가) 버림', !str_contains(json_encode($rows, JSON_UNESCAPED_UNICODE), '9,900') && !str_contains(json_encode($rows, JSON_UNESCAPED_UNICODE), '12,000'));
$pe = catalog_spec_rows(preg_split('/\n/u', $pages[2]));
ok('첫 칸(호칭 D100)은 이름 — 치수 칸에 겹치지 않음', ($pe[0][0] ?? '') === 'D100' && ($pe[0][1] ?? []) === ['내경 D100', '외경 118mm', '길이 6000mm']);
ok('치수 아닌 칸은 비고(머리글: 값)', ($pe[0][2] ?? []) === ['재질: HDPE']);
ok('글 한 줄 뒤에는 머리글을 잊음(다음 표 칸 수가 달라도 엉뚱한 머리글 없음)', ($pe[2][1] ?? []) === ['D200', '236mm']);

echo "[카탈로그 → 수집기 레코드]\n";
$recs = catalog_records($text, '[테스트] 가상제조(주)', 'catalog:test.pdf');
ok('레코드: 인터로킹 2 + PE관 3 + 수로관 1(규격 줄 없는 쪽은 쪽당 1건)', count($recs) === 6);
$r0 = $recs[0];
ok('수집기 형식 키', array_keys($r0) === ['name', 'spec', 'maker', 'category', 'note', 'source_url', 'facts']);
ok('이름 = 제목 + 첫 칸, 출처 = 파일#쪽', $r0['name'] === '인터로킹 블럭 보도용' && $r0['source_url'] === 'catalog:test.pdf#p2' && $r0['maker'] === '[테스트] 가상제조(주)');
ok('원문 문장 그대로(줄바꿈으로 끊긴 문장은 이어 붙임, 고쳐 쓰기 없음)', in_array('블록 사이 맞물림 구조로 하중이 고르게 분산되는 것이 특징입니다.', $r0['facts']['features'] ?? [], true)
    && in_array('KS F 4419 인증 제품입니다.', $r0['facts']['certs'] ?? [], true) && in_array('기층 다짐이 부족하지 않도록 주의하십시오.', $r0['facts']['cautions'] ?? [], true));
$all = json_encode($recs, JSON_UNESCAPED_UNICODE);
ok('가격·가격 문장 저장 안 함', !preg_match('/[\d,]+원|단가|가격/u', $all));
ok('회사 소개·인증 현황 쪽은 레코드 없음', !str_contains($all, '#p1') && !str_contains($all, '#p5') && !str_contains($all, '1990년'));
$ditch = array_values(array_filter($recs, static fn($r) => $r['category'] === '측구 수로관'))[0] ?? [];
ok('규격 줄 없는 쪽: 쪽의 치수 표기(앞뒤 공백 없이)', ($ditch['spec'] ?? '') === '200x200x60 / 300x300x1000 / 400x400x1000' && ($ditch['source_url'] ?? '') === 'catalog:test.pdf#p4');
ok('수집기 정규화를 통과(종류 판별)', ext_normalize($r0, 'test_catalog')['product_type'] === '인터로킹블록' && ext_normalize($recs[2], 'test_catalog')['product_type'] === 'PE관');

echo "[카탈로그 도구 — 허락 기록 확인]\n";
$tmp = sys_get_temp_dir() . '/catalog_extract_test_' . getmypid();
@mkdir($tmp, 0777, true);
$reg = ['sources' => [
    ['key' => 'test_catalog_ok', 'name' => '[테스트] 허락 있음', 'maker' => '[테스트] 가상제조(주)', 'permission' => 'allowed', 'basis' => '[테스트]', 'methods' => ['file'], 'conditions' => []],
    ['key' => 'test_catalog_wait', 'name' => '[테스트] 허락 전', 'permission' => 'pending', 'basis' => '-', 'methods' => ['file'], 'conditions' => []],
]];
file_put_contents("{$tmp}/sources.json", json_encode($reg, JSON_UNESCAPED_UNICODE));
file_put_contents("{$tmp}/cat.txt", $text);
file_put_contents("{$tmp}/empty.txt", "회사 소개\n\f인증 현황\n");
file_put_contents("{$tmp}/cat.xlsx", '');
file_put_contents("{$tmp}/cat.hwp", '');
$cli = static function (string $args) use ($tmp): array {
    $cmd = 'EXT_SOURCES_FILE=' . escapeshellarg("{$tmp}/sources.json") . " DB_HOST='127.0.0.1;port=1' " . escapeshellarg(PHP_BINARY) . ' '
        . escapeshellarg(__DIR__ . '/../scripts/external/catalog-extract.php') . " {$args} --out=" . escapeshellarg("{$tmp}/out") . ' 2>&1';
    exec($cmd, $o, $code);
    return [$code, implode("\n", $o)];
};
[$c, $o] = $cli('--source=test_catalog_wait --file=' . escapeshellarg("{$tmp}/cat.txt"));
ok('허락 전(pending) 출처는 저장 거부', $c === 2 && str_contains($o, '사용 허락 기록이 없습니다') && !is_dir("{$tmp}/out"));
[$c, $o] = $cli('--source=test_catalog_wait --file=' . escapeshellarg("{$tmp}/cat.txt") . ' --dry-run');
ok('허락 전 출처도 --dry-run 분석은 가능(저장 없음)', $c === 0 && str_contains($o, '레코드 6') && !is_dir("{$tmp}/out"));
[$c, $o] = $cli('--source=없는출처 --file=' . escapeshellarg("{$tmp}/cat.txt"));
ok('등록부에 없는 출처 거부', $c === 2 && str_contains($o, '출처가 없습니다'));
[$c, $o] = $cli('--source=test_catalog_ok --file=' . escapeshellarg("{$tmp}/cat.xlsx"));
ok('엑셀은 import.php 로 안내', $c === 2 && str_contains($o, 'import.php'));
[$c, $o] = $cli('--source=test_catalog_ok --file=' . escapeshellarg("{$tmp}/cat.hwp"));
ok('HWP 는 PDF 로 저장 요청', $c === 2 && str_contains($o, 'PDF 로 저장'));
[$c, $o] = $cli('--source=test_catalog_ok --file=' . escapeshellarg("{$tmp}/empty.txt"));
ok('레코드 0건이면 저장 안 함(이전 수집본을 가리지 않게)', $c === 2 && !glob("{$tmp}/out/*.json"));
[$c, $o] = $cli('--source=test_catalog_ok --file=' . escapeshellarg("{$tmp}/cat.txt") . ' --file=' . escapeshellarg("{$tmp}/empty.txt"));
$saved = glob("{$tmp}/out/test_catalog_ok-catalog-*.json") ?: [];
$js = $saved ? json_decode((string) file_get_contents($saved[0]), true) : [];
ok('허락(allowed) 출처: 여러 파일을 한 JSON 으로 저장(출처키-catalog-날짜.json)', $c === 0 && count($saved) === 1 && count($js) === 6);
ok('제조사 이름은 등록부 maker', ($js[0]['maker'] ?? '') === '[테스트] 가상제조(주)' && ($js[0]['source_url'] ?? '') === 'catalog:cat.txt#p2');
array_map('unlink', array_merge(glob("{$tmp}/out/*") ?: [], glob("{$tmp}/*.*") ?: []));
@rmdir("{$tmp}/out");
@rmdir($tmp);

echo "\n통과 {$pass} · 실패 {$fail}\n";
exit($fail ? 1 : 0);
