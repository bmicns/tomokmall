<?php
declare(strict_types=1);
/**
 * 제조사 공개 자료 자동 수집(scripts/external/crawl-sources.php) 통합 테스트 — 로컬 목업 사이트 3개 + 로컬 테스트 DB(*_test), 외부 접속 없음.
 *
 * 목업: A(허용 — 제품 목록·규격표·원문 문장·약관 문제 없음, /admin/ 은 robots 금지), B(약관에 자동수집 금지 문장), C(robots.txt 전체 금지).
 *       모든 내용은 '[테스트]' 가상 페이지. 끝나면 source_key 'test_site_%' 행·임시 폴더를 지운다.
 * 확인: 출처별 판정(수집·약관 금지·robots 금지·허락 필요·blocked), robots 금지 경로 미접속, 가격 미저장, 가져오기·매칭,
 *       승인 전에는 상담 참고 자료 없음 → 승인 후 material_info 가 원문 문장·출처를 돌려줌, products 불변.
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/external_crawl_sources.test.php
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
$tmp = sys_get_temp_dir() . '/crawl_test_' . getmypid();
$base = 19000 + getmypid() % 500;
$ports = ['a' => $base, 'b' => $base + 1, 'c' => $base + 2];
$site = static function (string $name, array $files) use ($tmp): string {
    $d = "{$tmp}/{$name}";
    foreach ($files as $f => $c) { @mkdir(dirname("{$d}/{$f}"), 0777, true); file_put_contents("{$d}/{$f}", $c); }
    file_put_contents("{$d}/router.php", '<?php file_put_contents(__DIR__ . "/access.log", $_SERVER["REQUEST_URI"] . "\n", FILE_APPEND);
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH); $f = __DIR__ . ($p === "/" ? "/index.html" : $p);
if (is_dir($f)) { $f .= "/index.html"; } if (!is_file($f)) { http_response_code(404); return true; }
header("Content-Type: " . (str_ends_with($f, ".txt") ? "text/plain" : (str_ends_with($f, ".pdf") ? "application/pdf" : "text/html; charset=utf-8"))); readfile($f); return true;');
    return $d;
};
$prod = static fn(string $t, string $rows) => "<html><body><h2>{$t}</h2><p>[테스트] 투수성 포장재로 빗물이 블록 사이로 스며들어 물고임이 적습니다. 보도, 광장, 주차장에 적용합니다.</p>"
    . "<p>KS F 4419 인증 제품입니다. 시공 시 기층 다짐이 부족하지 않도록 주의하십시오.</p><table><tr><th>품명</th><th>규격</th><th>두께</th><th>단가(원)</th></tr>{$rows}</table></body></html>";
$site('a', [
    'robots.txt' => "User-agent: *\nDisallow: /admin/\n",
    'index.html' => '<html><body><a href="/terms.html">이용약관</a> <a href="/product/list.html">제품소개</a></body></html>',
    'terms.html' => '<p>회원은 서비스를 이용할 수 있습니다. 이메일 주소 무단 수집을 거부합니다.</p>',
    'product/list.html' => '<html><body><a href="/product/view1.html">인조화강블록 보기</a> <a href="/product/view2.html">인터로킹 보기</a> <a href="/admin/product.html">관리 제품</a> <a href="/data/cat.pdf">카탈로그</a></body></html>',
    'product/view1.html' => $prod('인조화강블록(투수)', '<tr><td>[테스트]차도용</td><td>200*100</td><td>80</td><td>99,999</td></tr>'),
    'product/view2.html' => $prod('인터로킹블럭', '<tr><td>[테스트]보도용</td><td>220*110</td><td>60</td><td>88,888</td></tr>'),
    'admin/product.html' => $prod('관리 페이지', '<tr><td>비공개</td><td>1*1</td><td>1</td><td>1</td></tr>'),
    'data/cat.pdf' => '%PDF-1.4 test',
]);
$site('b', ['index.html' => '<a href="/agreement.html">이용약관</a> <a href="/product/a.html">제품</a>', 'agreement.html' => '<p>본 사이트의 게시물을 자동화된 프로그램으로 수집하거나 무단으로 복제하는 행위를 금지합니다.</p>',
            'product/a.html' => $prod('인조화강블록', '<tr><td>B</td><td>200*200</td><td>60</td><td>1</td></tr>')]);
$site('c', ['robots.txt' => "User-agent: *\nDisallow: /\n", 'index.html' => '<a href="/product/a.html">제품</a>', 'product/a.html' => $prod('인조화강블록', '<tr><td>C</td><td>200*200</td><td>60</td><td>1</td></tr>')]);
$srv = [];
foreach ($ports as $k => $port) {
    $srv[] = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/{$k}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, "{$tmp}/{$k}");
}
usleep(500000);
$reg = json_decode((string) file_get_contents(__DIR__ . '/../data/external/sources.json'), true);
$reg['sources'] = array_merge(array_filter($reg['sources'], static fn($s) => in_array($s['key'], ['ktomok', 'tomok114'], true)), [
    ['key' => 'test_site_a', 'name' => '[테스트]제조A', 'domain' => "127.0.0.1:{$ports['a']}", 'permission' => 'facts_if_clear', 'basis' => '테스트', 'conditions' => ['테스트'], 'seeds' => ["http://127.0.0.1:{$ports['a']}/product/list.html"], 'crawl_priority' => 1],
    ['key' => 'test_site_b', 'name' => '[테스트]제조B', 'domain' => "127.0.0.1:{$ports['b']}", 'permission' => 'facts_if_clear', 'basis' => '테스트', 'conditions' => ['테스트'], 'seeds' => [], 'crawl_priority' => 2],
    ['key' => 'test_site_c', 'name' => '[테스트]제조C', 'domain' => "127.0.0.1:{$ports['c']}", 'permission' => 'facts_if_clear', 'basis' => '테스트', 'conditions' => ['테스트'], 'seeds' => [], 'crawl_priority' => 3],
]);
$reg['sources'] = array_values($reg['sources']);
file_put_contents("{$tmp}/sources.json", json_encode($reg, JSON_UNESCAPED_UNICODE));
$env = 'EXT_SOURCES_FILE=' . escapeshellarg("{$tmp}/sources.json") . ' EXT_CRAWL_DIR=' . escapeshellarg("{$tmp}/out");
$prodBefore = $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM);
try {
    exec("{$env} " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/external/crawl-sources.php') . ' --delay=1000 --import 2>&1', $out, $code);
    $report = (string) @file_get_contents("{$tmp}/out/report.md");
    echo "[출처별 판정]\n";
    ok('A 수집 · B 약관 금지 건너뜀 · C robots 전체 금지 건너뜀', $code === 0 && preg_match('/test_site_a \| \[테스트\]제조A \| 수집/u', $report) && str_contains($report, 'test_site_b | [테스트]제조B | 이용약관 금지 문장')
        && str_contains($report, 'test_site_c | [테스트]제조C | robots.txt 전체 수집 금지'));
    ok('허락 필요(ktomok)·수집 안 함(tomok114)은 접속 없이 기록', str_contains($report, 'ktomok | ') && str_contains($report, '허락 필요') && str_contains($report, 'tomok114') && str_contains($report, '수집 안 함(blocked)'));
    ok('카탈로그 PDF 는 링크만 기록', str_contains($report, '/data/cat.pdf'));
    $logA = (string) @file_get_contents("{$tmp}/a/access.log");
    ok('robots 금지 경로(/admin/) 접속 안 함', !str_contains($logA, '/admin/') && str_contains($logA, '/product/view1.html'));
    ok('B·C 는 제품 페이지에 접속하지 않음', !str_contains((string) @file_get_contents("{$tmp}/b/access.log"), '/product/') && !str_contains((string) @file_get_contents("{$tmp}/c/access.log"), '/product/'));

    echo "[가져오기·정규화]\n";
    $rows = $pdo->query("SELECT id, name, product_type, thickness_mm, ref_price, attrs_json, source_url, review_status FROM external_products WHERE source_key = 'test_site_a' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    ok('A: 상품 2행 저장(검토 대기), 종류·두께 판독', count($rows) === 2 && $rows[0]['review_status'] === 'pending' && in_array('인조화강블록', array_column($rows, 'product_type'), true)
        && array_map('intval', array_column($rows, 'thickness_mm')) === [80, 60]);
    ok('가격 열은 저장 안 함, 출처 URL·원문 문장 저장', !array_filter($rows, static fn($r) => $r['ref_price'] !== null) && !str_contains(json_encode($rows, JSON_UNESCAPED_UNICODE), '99,999')
        && str_contains($rows[0]['source_url'], '/product/view1.html') && str_contains($rows[0]['attrs_json'], 'KS F 4419'));
    ok('우리 상품 매칭 후보 기록', (int) $pdo->query("SELECT COUNT(*) FROM external_product_matches m JOIN external_products e ON e.id = m.external_product_id WHERE e.source_key = 'test_site_a'")->fetchColumn() > 0);

    echo "[상담 참고 자료 — 승인한 것만]\n";
    $before = json_decode(tool_material_info(['topic' => '인조화강블록']), true);
    ok('승인 전: material_info 에 이 자료 없음', !str_contains(json_encode($before, JSON_UNESCAPED_UNICODE), '[테스트]'));
    exec("{$env} " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/external/import.php') . ' --approve-source=test_site_a 2>&1', $o2, $c2);
    $info = json_decode(tool_material_info(['topic' => '물이 잘 빠지는 투수 블록']), true);
    $kinds = array_unique(array_column($info['facts'] ?? [], 'kind'));
    ok('승인 후: 원문 문장(특징·용도·주의·인증)과 출처 URL', $c2 === 0 && in_array('특징', $kinds, true) && in_array('주의', $kinds, true) && in_array('인증', $kinds, true)
        && str_contains(json_encode($info, JSON_UNESCAPED_UNICODE), '빗물이 블록 사이로 스며들어') && str_contains((string) ($info['facts'][0]['source_url'] ?? ''), '127.0.0.1'));
    ok('상담 화면 출처 카드 이벤트, 가격 없음, 판매 상품 아님 안내', ($info['_sse'][0]['type'] ?? '') === 'material_sources' && !preg_match('/99,999|88,888/', json_encode($info))
        && str_contains((string) $info['note'], '판매 상품·가격이 아님'));
    ok('관계없는 주제는 빈 목록(지어내지 않음)', (json_decode(tool_material_info(['topic' => 'PE 이중벽관']), true)['facts'] ?? null) === []);
    ok('products 건수·판매가 합계 그대로', $prodBefore === $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM));
} finally {
    foreach ($srv as $p) { proc_terminate($p); }
    $pdo->exec("DELETE m FROM external_product_matches m JOIN external_products e ON e.id = m.external_product_id WHERE e.source_key LIKE 'test_site_%'");
    $pdo->exec("DELETE FROM external_products WHERE source_key LIKE 'test_site_%'");
    exec('rm -rf ' . escapeshellarg($tmp));
}
ok('테스트 행·임시 폴더 정리', (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE source_key LIKE 'test_site_%'")->fetchColumn() === 0 && !is_dir($tmp));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
