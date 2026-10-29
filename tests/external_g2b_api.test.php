<?php
declare(strict_types=1);
/**
 * 조달청 쇼핑몰 품목정보 API 수집기(scripts/external/g2b-api.php) 통합 테스트 — 로컬 목업 서버 + 로컬 테스트 DB(*_test), 실제 API 호출 없음.
 *
 * 목업: 공공데이터포털 활용가이드 표 형식의 **가상 명세 페이지**(오퍼레이션 getTestPrdctList, 항목 test*)와 표준 응답 구조(response.header/body)의
 *       JSON·오류 XML 을 돌려주는 PHP 내장 서버. 품목 이름은 '[테스트]' 로 시작, 끝나면 source_key='test_g2b' 행·임시 폴더를 지운다.
 * 확인: 명세 확인 → 키 없음·틀린 키·명세에 없는 변수 차단 → 시험 호출 → 호출 상한·이어받기 → 가져오기(취급 종류만·매칭·검증 구역),
 *       명세에 없는 선택 변수를 보내지 않음, 출력·보고서에 인증키가 없음, products 불변.
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/external_g2b_api.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/external_products.php';

$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName) || !db() || !ext_tables_ready()) {
    echo "SKIP: external_products 테이블이 있는 로컬 테스트 DB(*_test)에서만 실행합니다.\n";
    exit(0);
}
$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$pdo = db();
$tmp = sys_get_temp_dir() . '/g2b_test_' . getmypid();
@mkdir("{$tmp}/work", 0777, true);
$port = 18000 + getmypid() % 1000;
$head = ['항목명(영문)', '항목명(국문)', '항목크기', '항목구분', '샘플데이터', '항목설명'];
$tbl = static fn(array $rows): string => '<table>' . implode('', array_map(static fn($r) => '<tr><td>' . implode('</td><td>', $r) . '</td></tr>', $rows)) . '</table>';
file_put_contents("{$tmp}/page.html", '<html><body><h2>테스트 가상 명세</h2><table><tr><th>이용허락범위</th><td>이용허락범위 제한 없음</td></tr></table><p>개발계정 : 일일 트래픽 1000</p>'
    . '<p>요청주소 https://apis.data.go.kr/1230000/TestSvc/getTestPrdctList</p><p>요청변수(Request Parameter)</p>'
    . $tbl([$head, ['serviceKey', '서비스키', '400', '1', '-', '인증키'], ['pageNo', '페이지번호', '4', '1', '1', ''], ['numOfRows', '한 페이지 결과 수', '4', '1', '10', ''],
            ['type', '타입', '4', '0', 'json', 'json'], ['testDiv', '조회구분', '1', '1', '1', ''], ['testOpt', '선택조건', '10', '0', '', '']])
    . '<p>출력결과(Response Element)</p>'
    . $tbl([$head, ['testItemNm', '품명', '200', '1', '', ''], ['testSpec', '규격', '500', '0', '', ''], ['testCorpNm', '계약업체명', '100', '0', '', ''], ['testPrice', '계약단가', '15', '0', '', ''],
            ['testUnit', '단위', '10', '0', '', ''], ['testIdntNo', '물품식별번호', '20', '0', '', '']]) . '</body></html>');
file_put_contents("{$tmp}/router.php", '<?php
$u = parse_url($_SERVER["REQUEST_URI"]); parse_str($u["query"] ?? "", $q);
if ($u["path"] === "/page") { readfile(__DIR__ . "/page.html"); return true; }
file_put_contents(__DIR__ . "/requests.log", ($u["query"] ?? "") . "\n", FILE_APPEND);
if (($q["serviceKey"] ?? "") !== "test-key") { header("Content-Type: text/xml"); echo "<OpenAPI_ServiceResponse><cmmMsgHeader><returnAuthMsg>SERVICE_KEY_IS_NOT_REGISTERED_ERROR</returnAuthMsg><returnReasonCode>30</returnReasonCode></cmmMsgHeader></OpenAPI_ServiceResponse>"; return true; }
$page = (int) $q["pageNo"]; $rows = (int) $q["numOfRows"]; $total = 250; $items = [];
for ($i = ($page - 1) * $rows; $i < min($total, $page * $rows); $i++) {
    $items[] = $i % 5 === 4 ? ["testItemNm" => "[테스트] 사무용 의자 " . $i, "testIdntNo" => "C$i"]
        : ["testItemNm" => "[테스트] 인조화강블록(투수)", "testSpec" => "200*100*" . (60 + ($i % 2) * 20) . "T(투수) 시험$i", "testCorpNm" => "[테스트]업체", "testPrice" => "1000", "testUnit" => "m2", "testIdntNo" => "T$i"];
}
header("Content-Type: application/json");
echo json_encode(["response" => ["header" => ["resultCode" => "00", "resultMsg" => "NORMAL SERVICE."], "body" => ["items" => $items, "numOfRows" => $rows, "pageNo" => $page, "totalCount" => $total]]], JSON_UNESCAPED_UNICODE);
return true;');
$reg = json_decode((string) file_get_contents(__DIR__ . '/../data/external/sources.json'), true);
$reg['sources'][] = ['key' => 'test_g2b', 'name' => '테스트 조달 API', 'permission' => 'allowed', 'basis' => '테스트 전용', 'conditions' => ['테스트 조건']];
file_put_contents("{$tmp}/sources.json", json_encode($reg, JSON_UNESCAPED_UNICODE));
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400000);
$run = static function (string $args, string $key = 'test-key') use ($tmp, $port): array {
    $env = ['EXT_G2B_DIR' => "{$tmp}/work", 'EXT_G2B_PAGE_URL' => "http://127.0.0.1:{$port}/page", 'EXT_G2B_API_BASE' => "http://127.0.0.1:{$port}",
            'EXT_G2B_SOURCE' => 'test_g2b', 'EXT_SOURCES_FILE' => "{$tmp}/sources.json", 'DATA_GO_KR_SERVICE_KEY' => $key];
    $pre = implode(' ', array_map(static fn($k, $v) => $k . '=' . escapeshellarg($v), array_keys($env), $env));
    exec("{$pre} " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/external/g2b-api.php') . " {$args} 2>&1", $out, $code);
    return [$code, implode("\n", $out)];
};
$prodBefore = $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM);
try {
    echo "[명세 확인]\n";
    [$c, $o] = $run('--probe');
    ok('명세 없이 호출하지 않음', $c === 2 && str_contains($o, '--discover'));
    [$c, $o] = $run('--discover');
    $spec = json_decode((string) @file_get_contents("{$tmp}/work/spec.json"), true);
    ok('공식 페이지 표 → spec.json(오퍼레이션·필수·이용허락범위·트래픽)', $c === 0 && ($spec['operations'][0]['name'] ?? '') === 'getTestPrdctList' && str_contains((string) $spec['license'], '제한 없음')
        && str_contains($o, '페이지 단위 수집 가능') && str_contains($o, 'testItemNm→name'));

    echo "[차단]\n";
    [$c, $o] = $run('--probe', '');
    ok('인증키 없으면 중단(.env.local 안내)', $c === 2 && str_contains($o, 'DATA_GO_KR_SERVICE_KEY'));
    [$c, $o] = $run('--probe --param testUnknown=1');
    ok('명세에 없는 변수(--param)는 보내지 않고 중단', $c === 2 && str_contains($o, '명세(getTestPrdctList)에 없는 변수'));
    [$c, $o] = $run('--probe', 'wrong-key');
    ok('틀린 키: 결과코드 30 보고, 실패 종료', $c === 1 && str_contains($o, '결과코드 30'));

    echo "[시험 호출·수집·이어받기]\n";
    [$c, $o] = $run('--probe');
    ok('시험 호출: 결과코드 00·전체 250건·표준 키 첫 품목', $c === 0 && str_contains($o, '결과코드 00') && str_contains($o, '전체 250건') && str_contains($o, '"name":"[테스트]'));
    [$c, $o] = $run('--collect --max-calls=2 --rows=100 --delay=500');
    ok('호출 상한 2회 → 200건, 다음 시작 페이지 3', $c === 0 && str_contains($o, '호출 2회 · 받은 품목 200건') && str_contains($o, '다음 시작 페이지 3'));
    [$c, $o] = $run('--collect --max-calls=5 --rows=100 --delay=500 --import');
    $rows = $pdo->query("SELECT name, spec_raw, thickness_mm, ref_price FROM external_products WHERE source_key = 'test_g2b'")->fetchAll(PDO::FETCH_ASSOC);
    ok('이어받기: 3페이지 50건으로 끝(마지막 페이지)', str_contains($o, '호출 1회 · 받은 품목 50건') && str_contains($o, '마지막 페이지'));
    ok('가져오기: 취급 종류 아닌 의자 10건 제외, 블록만 검증 구역에', $c === 0 && str_contains($o, '취급 종류 아님 10') && $rows && !array_filter($rows, static fn($r) => str_contains($r['name'], '의자')));
    ok('정규화: 두께 60·80 판독, 가격은 참고가', !array_diff(array_unique(array_map('intval', array_column($rows, 'thickness_mm'))), [60, 80]) && (int) $rows[0]['ref_price'] === 1000);
    ok('매칭 후보 기록(우리 인조화강블록)', (int) $pdo->query("SELECT COUNT(*) FROM external_product_matches m JOIN external_products e ON e.id = m.external_product_id JOIN products p ON p.id = m.product_id
        WHERE e.source_key = 'test_g2b' AND p.name LIKE '인조화강블록%'")->fetchColumn() > 0);

    echo "[보안·명세 준수]\n";
    $log = (string) @file_get_contents("{$tmp}/requests.log");
    ok('명세의 선택 변수(testOpt)는 보내지 않음, 필수 testDiv·type=json 은 보냄', !str_contains($log, 'testOpt') && str_contains($log, 'testDiv=1') && str_contains($log, 'type=json'));
    $saved = implode('', array_map('file_get_contents', array_merge(glob("{$tmp}/work/*.json") ?: [], glob("{$tmp}/work/*.md") ?: [])));
    ok('화면 출력·명세·상태·보고서에 인증키 없음', !str_contains($o . $saved, 'test-key'));
    ok('products 건수·판매가 합계 그대로', $prodBefore === $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM));
} finally {
    proc_terminate($srv);
    $pdo->exec("DELETE m FROM external_product_matches m JOIN external_products e ON e.id = m.external_product_id WHERE e.source_key = 'test_g2b'");
    $pdo->exec("DELETE FROM external_products WHERE source_key = 'test_g2b'");
    exec('rm -rf ' . escapeshellarg($tmp));
}
ok('테스트 행·임시 폴더 정리', (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE source_key = 'test_g2b'")->fetchColumn() === 0 && !is_dir($tmp));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
