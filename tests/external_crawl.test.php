<?php
declare(strict_types=1);
/**
 * 제조사 공개 페이지 사실 추출(app/external_crawl.php)·robots·약관 판정 단위 테스트 — DB·네트워크 불필요.
 * 입력 HTML 은 테스트용으로 만든 가상 제품 페이지('[테스트]' 표기)다.
 * 실행: php tests/external_crawl.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
putenv('DB_HOST=127.0.0.1;port=1');
ob_start();
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/external_products.php';
require_once __DIR__ . '/../app/external_api.php';
require_once __DIR__ . '/../app/external_crawl.php';
ob_end_clean();

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$page = '<html><head><title>[테스트]제조 | 제품</title></head><body><h2>인조화강블록(투수)</h2>'
    . '<p>투수성 포장재로 빗물이 블록 사이로 스며들어 물고임이 적습니다. 보도, 광장, 주차장에 적용합니다.</p>'
    . '<p>KS F 4419 인증 제품입니다. 시공 시 기층 다짐이 부족하지 않도록 주의하십시오. 담당 홍길동 010-0000-0000</p>'
    . '<table><tr><th>품명</th><th>규격(mm)</th><th>두께</th><th>용도</th><th>단가(원)</th></tr>'
    . '<tr><td>보도용</td><td>200×200</td><td>60</td><td>보도</td><td>9,900</td></tr><tr><td>차도용</td><td>200×200</td><td>80</td><td>차도·주차장</td><td>12,000</td></tr></table>'
    . '<a href="/product/view.php?id=2">다음 제품</a> <a href="/admin/">관리</a> <a href="/bbs/login.php">로그인</a> <a href="https://other.example/x">외부</a>'
    . '<a href="/data/catalog.pdf">카탈로그</a> <a href="/img/a.jpg">사진</a> <a href="./sub02_2.html">규격 안내</a> <a href="javascript:void(0)">팝업</a></body></html>';

echo "[원문 문장 분류]\n";
ok('제목: h2 우선', ext_page_title($page) === '인조화강블록(투수)');
ok('분류: 주의·인증·용도·특징', ext_fact_kind('시공 시 기층 다짐이 부족하지 않도록 주의하십시오.') === 'cautions' && ext_fact_kind('KS F 4419 인증 제품입니다.') === 'certs'
    && ext_fact_kind('보도, 광장, 주차장에 적용합니다.') === 'uses' && ext_fact_kind('투수성 포장재로 빗물이 블록 사이로 스며들어 물고임이 적습니다.') === 'features');
$f = ext_page_facts(ext_html_blocks($page));
ok('문장은 원문 그대로(요약·생성 없음)', in_array('KS F 4419 인증 제품입니다.', $f['certs'] ?? [], true) && in_array('보도, 광장, 주차장에 적용합니다.', $f['uses'] ?? [], true));

echo "[규격표 → 상품 행]\n";
$rows = ext_page_records($page, 'http://maker.example/product/view.php?id=1', '[테스트]제조');
ok('규격표 2행 → 상품 2개, 품명 앞에 페이지 제목', count($rows) === 2 && $rows[0]['name'] === '인조화강블록(투수) 보도용' && $rows[1]['name'] === '인조화강블록(투수) 차도용');
ok('치수 열은 규격, 용도 열은 속성, 가격 열은 버림', str_contains($rows[1]['spec'], '두께 80') && str_contains($rows[1]['note'], '용도: 차도·주차장') && !str_contains(json_encode($rows, JSON_UNESCAPED_UNICODE), '12,000'));
ok('출처 URL·제조사·원문 문장이 행마다', $rows[0]['source_url'] === 'http://maker.example/product/view.php?id=1' && $rows[0]['maker'] === '[테스트]제조' && !empty($rows[0]['facts']['cautions']));
$n = ext_normalize($rows[1] + ['spec' => ''], 'test');
ok('정규화: 종류·두께(규격표 \'두께 80\')·투수·원문 문장 유지, 개인 연락처 제거', $n['thickness_mm'] === 80 && $n['product_type'] === '인조화강블록' && $n['attrs']['permeable'] === true && !empty($n['facts']['uses'])
    && !preg_match('/010-0000-0000/', json_encode($n['facts'], JSON_UNESCAPED_UNICODE)));
$solo = ext_page_records('<h1>인터로킹 블록</h1><p>차량이 다니는 주차장에 적용합니다. 규격 200*100*80T 입니다.</p>', 'http://m.example/p', 'M');
ok('규격표가 없어도 취급 종류 제목 + 원문 문장이면 페이지 1행', count($solo) === 1 && $solo[0]['name'] === '인터로킹 블록' && str_contains($solo[0]['spec'], '200*100*80T'));
ok('취급 종류도 규격표도 아니면 행 없음(회사소개 등)', ext_page_records('<h1>회사 소개</h1><p>우리 회사는 1990년에 설립되었습니다.</p>', 'http://m.example/a', 'M') === []);

echo "[링크 고르기]\n";
[$links, $docs] = ext_page_links($page, 'http://maker.example/product/view.php?id=1');
ok('같은 사이트 제품 단서 링크만(관리·로그인·외부·이미지·스크립트 제외)', $links === ['http://maker.example/product/view.php?id=2', 'http://maker.example/product/sub02_2.html']);
ok('카탈로그 PDF 는 따로(자동으로 읽지 않음)', $docs === ['http://maker.example/data/catalog.pdf']);

echo "[robots·약관]\n";
$rb = ext_robots_check("User-agent: Googlebot\nDisallow: /\n\nUser-agent: *\nDisallow: /admin/\nDisallow: /?mode*\n");
ok('robots: * 규칙만, 금지 경로 판정', $rb['disallow'] === ['/admin/', '/?mode*'] && !ext_robots_allows($rb['disallow'], '/admin/x') && !ext_robots_allows($rb['disallow'], '/?mode=list') && ext_robots_allows($rb['disallow'], '/product/1'));
ok('약관: 자동수집·무단 복제 금지 문장 감지(이메일 무단수집 거부는 제외)', count(ext_terms_prohibits(['게시물을 자동화된 프로그램으로 수집하는 행위를 금지합니다.', '이메일 주소 무단 수집을 거부합니다.', '회원은 정보를 이용할 수 있습니다.'])) === 1);

echo "[수집 행 품질·원문 문장 정리]\n";
$nr = static fn(string $name, string $spec) => ext_normalize(['name' => $name, 'spec' => $spec], 'test');
ok('품질: 유효 행은 사유 없음', ext_record_issue($nr('인조화강블록 차도용', '200*100*80')) === '');
ok('품질: 머리글 행·이름 없음·규격 숫자 없음·취급 종류 아님을 걸러냄', ext_record_issue($nr('품명', '규격 200*100')) === '머리글 행' && ext_record_issue($nr('', '200*100')) === '이름 없음'
    && ext_record_issue($nr('인조화강블록', '규격 별도 문의')) === '규격 숫자 없음' && ext_record_issue($nr('회사 연혁', '1990')) === '취급 종류 아님');
$recs = [];
foreach (range(1, 4) as $i) {
    $recs[] = ['name' => "블록{$i}", 'source_url' => "http://m.example/p{$i}", 'facts' => ['uses' => ['모든 쪽에 반복되는 [테스트] 보도 시공 안내 문장입니다.', "[테스트] 제품{$i}은 보도와 광장에 적용합니다."],
        'features' => ['Copyright (c) 테스트 All rights reserved 내구성 우수']]];
}
$cl = ext_clean_page_facts($recs);
ok('정리: 출처 페이지 절반 이상 반복 문장·저작권 문구 제거, 페이지 고유 문장은 원문 그대로 유지', $cl[0]['facts'] === ['uses' => ['[테스트] 제품1은 보도와 광장에 적용합니다.']]);
ok('정리: 페이지가 4쪽 미만이면 반복 기준을 쓰지 않음', ext_clean_page_facts(array_slice($recs, 0, 2))[0]['facts']['uses'][0] === '모든 쪽에 반복되는 [테스트] 보도 시공 안내 문장입니다.');
ok('충돌 표시: 차량·주차용인데 80mm 미만만', ext_fact_conflict('주차장 차량 통행 구간에 두께 60mm 제품을 사용합니다.') !== '' && ext_fact_conflict('차도용은 두께 80mm 입니다.') === ''
    && ext_fact_conflict('보도용 두께 60mm 제품입니다.') === '');

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
