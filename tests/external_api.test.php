<?php
declare(strict_types=1);
/**
 * 공공데이터포털 OpenAPI 명세 읽기·응답 해석·요청 URL 단위 테스트 — DB·네트워크 불필요.
 * 입력은 공공데이터포털 활용가이드 표 형식(항목명(영문)·항목명(국문)·항목크기·항목구분·샘플데이터·항목설명)을 흉내 낸 **테스트용 가상 명세**다.
 * 오퍼레이션·항목 이름(getTestPrdctList, test*)은 실제 조달청 API 이름이 아니다.
 * 실행: php tests/external_api.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
putenv('DB_HOST=127.0.0.1;port=1');
ob_start();
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/external_products.php';
require_once __DIR__ . '/../app/external_api.php';
ob_end_clean();

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$head = ['항목명(영문)', '항목명(국문)', '항목크기', '항목구분', '샘플데이터', '항목설명'];
$req = [['serviceKey', '서비스키', '400', '1', '-', '공공데이터포털에서 받은 인증키'], ['pageNo', '페이지번호', '4', '1', '1', '페이지번호'],
        ['numOfRows', '한 페이지 결과 수', '4', '1', '10', '한 페이지 결과 수'], ['type', '타입', '4', '0', 'json', '오픈API 리턴 타입을 JSON으로 받고 싶을 경우 json'],
        ['testDiv', '조회구분', '1', '1', '1', '1: 등록일 기준'], ['testOpt', '선택조건', '10', '0', '', '선택']];
$res = [['resultCode', '결과코드', '2', '1', '00', ''], ['testItemNm', '품명', '200', '1', '블록', ''], ['testSpec', '규격', '500', '0', '200*100*80T', ''],
        ['testCorpNm', '계약업체명', '100', '0', '시험', ''], ['testPrice', '계약단가', '15', '0', '1000', ''], ['testUnit', '단위', '10', '0', '㎡', ''],
        ['testIdntNo', '물품식별번호', '20', '0', 'T1', ''], ['testClsfcNm', '물품분류명', '100', '0', '보도블록', ''], ['testCert', '인증구분', '50', '0', 'KS', '']];

echo "[활용가이드 표(블록) → 명세]\n";
$blocks = [
    ['text', '조달청_나라장터쇼핑몰 품목정보 서비스 (테스트 가상 명세)'],
    ['table', [['서비스 URL', 'https://apis.data.go.kr/1230000/TestSvc'], ['이용허락범위', '이용허락범위 제한 없음']]],
    ['text', '개발계정 : 일일 트래픽 1000'],
    ['table', [['상세기능명(영문)', 'getTestPrdctList'], ['상세기능명(국문)', '시험 품목 목록 조회']]],
    ['text', '요청 메시지 명세'], ['table', array_merge([$head], $req)],
    ['text', '응답 메시지 명세'], ['table', array_merge([$head], $res)],
    ['table', [['상세기능명(영문)', 'getTestOtherInfo']]],
    ['text', '요청 메시지 명세'], ['table', [$head, ['serviceKey', '서비스키', '400', '1', '-', '']]],
];
$s = ext_api_spec_from_blocks($blocks);
$op = $s['operations'][0] ?? [];
ok('서비스 URL·이용허락범위·트래픽', $s['service_url'] === 'https://apis.data.go.kr/1230000/TestSvc' && str_contains($s['license'], '제한 없음') && str_contains($s['traffic'], '개발계정 일일 트래픽 1000'));
ok('오퍼레이션 2개, 첫째 이름·국문명·URL', count($s['operations']) === 2 && $op['name'] === 'getTestPrdctList' && $op['title'] === '시험 품목 목록 조회' && $op['url'] === 'https://apis.data.go.kr/1230000/TestSvc/getTestPrdctList');
ok('요청변수 6개 — 필수·샘플값 그대로', count($op['request']) === 6 && $op['request'][4]['name'] === 'testDiv' && $op['request'][4]['required'] && $op['request'][4]['sample'] === '1' && !$op['request'][5]['required']);
ok('출력항목 9개(요청과 섞이지 않음)', count($op['response']) === 9 && $op['response'][1]['ko'] === '품명');
ok('표 앞 글이 없어도 순서로 요청→출력', ext_api_spec_from_blocks([['table', [['상세기능명(영문)', 'getTestPrdctList']]], ['table', array_merge([$head], $req)], ['table', array_merge([$head], $res)]])['operations'][0]['response'][1]['name'] === 'testItemNm');

echo "[HTML·DOCX 읽기]\n";
$tbl = static fn(array $rows): string => '<table>' . implode('', array_map(static fn($r) => '<tr>' . implode('', array_map(static fn($c) => '<td>' . htmlspecialchars($c) . '</td>', $r)) . '</tr>', $rows)) . '</table>';
$html = '<html><body><h3>상세기능</h3><p>요청주소 https://apis.data.go.kr/1230000/TestSvc/getTestPrdctList</p><p>요청변수(Request Parameter)</p>' . $tbl(array_merge([$head], $req))
    . '<p>출력결과(Response Element)</p>' . $tbl(array_merge([$head], $res)) . '<a href="/cmm/cmm/fileDownload.do?atchFileId=FILE_1&fileDetailSn=1">참고문서 활용가이드.docx</a><a href="javascript:void(0)">참고문서</a></body></html>';
$hs = ext_api_spec_from_blocks(ext_html_blocks($html));
ok('HTML: 요청주소의 오퍼레이션 + 요청·출력 표', ($hs['operations'][0]['name'] ?? '') === 'getTestPrdctList' && count($hs['operations'][0]['request']) === 6 && count($hs['operations'][0]['response']) === 9);
ok('HTML: 참고문서 내려받기 링크(스크립트 링크 제외)', array_keys(ext_doc_links($html, 'https://www.data.go.kr/data/15129471/openapi.do')) === ['https://www.data.go.kr/cmm/cmm/fileDownload.do?atchFileId=FILE_1&fileDetailSn=1']);
$w = static fn(string $t): string => '<w:p><w:r><w:t>' . htmlspecialchars($t) . '</w:t></w:r></w:p>';
$wt = static fn(array $rows): string => '<w:tbl>' . implode('', array_map(static fn($r) => '<w:tr>' . implode('', array_map(static fn($c) => '<w:tc><w:p><w:r><w:t>' . htmlspecialchars($c) . '</w:t></w:r></w:p></w:tc>', $r)) . '</w:tr>', $rows)) . '</w:tbl>';
$docx = sys_get_temp_dir() . '/ext_api_' . getmypid() . '.docx';
$z = new ZipArchive();
$z->open($docx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
$z->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
    . $wt([['서비스 URL', 'https://apis.data.go.kr/1230000/TestSvc']]) . $wt([['상세기능명(영문)', 'getTestPrdctList']]) . $w('요청 메시지 명세') . $wt(array_merge([$head], $req))
    . $w('응답 메시지 명세') . $wt(array_merge([$head], $res)) . '</w:body></w:document>');
$z->close();
$ds = ext_api_spec_from_blocks(ext_docx_blocks($docx));
unlink($docx);
ok('DOCX 참고문서: 같은 명세', ($ds['operations'][0]['name'] ?? '') === 'getTestPrdctList' && count($ds['operations'][0]['request']) === 6 && count($ds['operations'][0]['response']) === 9);

echo "[swagger]\n";
$sw = json_decode('{"swagger":"2.0","host":"apis.data.go.kr","basePath":"/1230000/TestSvc","schemes":["https"],"paths":{"/getTestPrdctList":{"get":{"summary":"시험 품목",'
    . '"parameters":[{"name":"serviceKey","in":"query","required":true,"description":"인증키"},{"name":"pageNo","in":"query","required":true,"x-example":"1"}],'
    . '"responses":{"200":{"schema":{"properties":{"response":{"properties":{"body":{"properties":{"items":{"type":"array","items":{"properties":{"testItemNm":{"description":"품명"}}}}}}}}}}}}}}}}', true);
$html2 = '<script>var swaggerJson = ' . json_encode(json_encode($sw, JSON_UNESCAPED_UNICODE)) . ';</script>';
$ss = ext_api_spec_from_swagger((array) ext_swagger_from_html($html2));
ok('swagger 2.0: URL·필수 변수·샘플·출력 항목(설명=국문)', ($ss['operations'][0]['url'] ?? '') === 'https://apis.data.go.kr/1230000/TestSvc/getTestPrdctList'
    && $ss['operations'][0]['request'][1]['sample'] === '1' && $ss['operations'][0]['response'] === [['name' => 'testItemNm', 'ko' => '품명', 'required' => false, 'sample' => '', 'desc' => '']]);

echo "[응답 해석]\n";
$j = ext_api_response('{"response":{"header":{"resultCode":"00","resultMsg":"NORMAL SERVICE."},"body":{"items":[{"testItemNm":"블록"},{"testItemNm":"관"}],"numOfRows":2,"pageNo":1,"totalCount":250}}}');
ok('JSON: 결과코드·전체 건수·목록', $j['code'] === '00' && $j['total'] === 250 && count($j['items']) === 2);
ok('JSON: items.item 단건 객체도 목록으로', count(ext_api_response('{"response":{"header":{"resultCode":"00"},"body":{"items":{"item":{"testItemNm":"블록"}},"totalCount":1}}}')['items']) === 1);
$x = ext_api_response('<response><header><resultCode>00</resultCode><resultMsg>OK</resultMsg></header><body><items><item><testItemNm>블록</testItemNm><testSpec>200*100*80T</testSpec></item></items><totalCount>1</totalCount></body></response>');
ok('XML: 항목 이름 그대로', $x['code'] === '00' && $x['total'] === 1 && ($x['items'][0]['testSpec'] ?? '') === '200*100*80T');
$e = ext_api_response('<OpenAPI_ServiceResponse><cmmMsgHeader><errMsg>SERVICE ERROR</errMsg><returnAuthMsg>SERVICE_KEY_IS_NOT_REGISTERED_ERROR</returnAuthMsg><returnReasonCode>30</returnReasonCode></cmmMsgHeader></OpenAPI_ServiceResponse>');
ok('포털 공통 오류(키 미등록) → 코드 30', $e['code'] === '30' && str_contains($e['msg'], 'SERVICE_KEY'));

echo "[출력항목 국문명 → 표준 키]\n";
$m = ext_api_field_map($op['response']);
ok('품명·규격·업체·단가·단위·식별번호·분류명·인증', $m === ['testItemNm' => 'name', 'testSpec' => 'spec', 'testCorpNm' => 'maker', 'testPrice' => 'price', 'testUnit' => 'unit',
    'testIdntNo' => 'external_id', 'testClsfcNm' => 'category', 'testCert' => 'note']);
ok('결과코드 등 관계없는 항목은 버림', !isset($m['resultCode']));

echo "[요청 URL — 명세에 있는 변수만]\n";
[$url, $shown, $missing] = ext_api_build_url($op, 'abc+/=', [], 3, 100);
ok('필수 샘플값·페이지 변수·json·인증키(인코딩) — 선택 변수(testOpt) 안 보냄', $missing === [] && str_contains($url, 'serviceKey=abc%2B%2F%3D') && str_contains($url, 'pageNo=3')
    && str_contains($url, 'numOfRows=100') && str_contains($url, 'type=json') && str_contains($url, 'testDiv=1') && !str_contains($url, 'testOpt'));
ok('화면 표시용은 키 가림', str_contains($shown, 'serviceKey=****') && !str_contains($shown, 'abc'));
ok('Encoding 키(%xx 포함)는 다시 인코딩하지 않음', str_contains(ext_api_build_url($op, 'abc%2B', [], 1, 10)[0], 'serviceKey=abc%2B&'));
$op2 = $op;
$op2['request'][4]['sample'] = '';
ok('필수 변수에 샘플값이 없으면 보내지 않고 알림', ext_api_build_url($op2, 'k', [], 1, 10)[2] === ['testDiv'] && ext_api_build_url($op2, 'k', ['testDiv' => '2'], 1, 10)[2] === []);
ok('명세에 인증키 변수가 없으면 호출 안 함', ext_api_build_url(['url' => 'u', 'request' => []], 'k', [], 1, 10)[0] === '');
ok('페이지 변수가 있는 오퍼레이션 자동 선택', (ext_api_pick_op($s, null)['name'] ?? '') === 'getTestPrdctList' && ext_api_pick_op($s, 'getTestOtherInfo')['name'] === 'getTestOtherInfo');

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
