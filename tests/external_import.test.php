<?php
declare(strict_types=1);
/**
 * 외부 상품 가져오기 통합 테스트 — 로컬 테스트 DB(*_test) 전용, 네트워크 없음.
 *
 * 확인: 출처 권한 차단(pending·blocked·이용 조건 미확인·운영 DB), CSV 가져오기 → 검증 구역 저장·중복 합침·개인정보 제거·매칭 후보,
 *       사람이 확정한 매칭만 상담 카드 '고르는 기준'에 외부 자료로 표시, products 는 바뀌지 않음.
 * 데이터: 실행 중에만 만드는 '[테스트]' 표기 CSV 와 테스트 전용 출처(test_fixture) — 끝나면 source_key='test_fixture' 행을 지운다.
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/external_import.test.php
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
$tmp = sys_get_temp_dir() . '/ext_test_' . getmypid();
@mkdir($tmp);
$reg = json_decode((string) file_get_contents(__DIR__ . '/../data/external/sources.json'), true);
$reg['sources'][] = ['key' => 'test_fixture', 'name' => '테스트 출처', 'permission' => 'allowed', 'basis' => '테스트 전용', 'conditions' => []];
$reg['sources'][] = ['key' => 'test_cond', 'name' => '조건 있는 테스트 출처', 'permission' => 'allowed', 'basis' => '테스트 전용', 'conditions' => ['이용허락범위 확인']];
file_put_contents("{$tmp}/sources.json", json_encode($reg, JSON_UNESCAPED_UNICODE));
file_put_contents("{$tmp}/items.csv", "품명,규격,제조사,단가,단위,물품식별번호,비고\n"
    . "[테스트] 인조화강블록(투수),200*100*80T(투수),[테스트]제조,1000,m2,T-1,담당 010-0000-0000\n"
    . "[테스트] 인조화강블럭 (투수),200*100*80T(투수),[테스트]제조,1000,m2,T-1,\n"
    . "[테스트] 중하중그레이팅수로용,300*1000*50(7*4),[테스트]제조,,개,T-2,KS D 3503\n"
    . "[테스트] 사무용 노트북 컴퓨터,15인치,[테스트]제조,900000,대,T-3,\n");
$run = static function (string $args) use ($tmp): array {
    $env = 'EXT_SOURCES_FILE=' . escapeshellarg("{$tmp}/sources.json");
    exec("{$env} " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/external/import.php') . " {$args} 2>&1", $out, $code);
    return [$code, implode("\n", $out)];
};
$prodBefore = $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM);
try {
    echo "[출처 권한 차단]\n";
    [$c, $o] = $run('--source=dacity --format=scrape-json --file=' . escapeshellarg(__DIR__ . '/../data/dacity/products.json'));
    ok('허락 전(pending) 출처 저장 거부', $c === 2 && str_contains($o, 'pending'));
    [$c] = $run('--source=tomok114 --file=' . escapeshellarg("{$tmp}/items.csv") . ' --dry-run');
    ok('blocked 출처는 분석도 거부', $c === 2);
    [$c, $o] = $run('--source=test_cond --file=' . escapeshellarg("{$tmp}/items.csv"));
    ok('이용 조건 있는 출처는 --license-checked 없으면 거부', $c === 2 && str_contains($o, '이용허락범위 확인'));
    exec('DB_NAME=tomokmall ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/external/import.php') . ' --source=g2b_mall_items_file --license-checked --file=' . escapeshellarg("{$tmp}/items.csv") . ' 2>&1', $o2, $c2);
    ok('운영 DB 이름(*_test 아님)에는 저장 거부', $c2 === 2 && str_contains(implode("\n", $o2), '로컬 테스트 DB'));
    [$c, $o] = $run('--source=dacity --format=scrape-json --file=' . escapeshellarg(__DIR__ . '/../data/dacity/products.json') . ' --dry-run');
    ok('허락 전 출처도 --dry-run 분석은 가능(저장 0건)', $c === 0 && str_contains($o, '검증 구역 저장 0건') && str_contains($o, '유력(80점 이상) 10'));

    echo "[CSV 가져오기 → 검증 구역]\n";
    [$c, $o] = $run('--source=test_fixture --file=' . escapeshellarg("{$tmp}/items.csv"));
    ok('가져오기 성공 — 3행 중 중복 1건 합침, 고유 2건 저장', $c === 0 && str_contains($o, '중복 합침 1') && str_contains($o, '검증 구역 저장 2건'));
    ok('개인 연락처 제거 보고', str_contains($o, '개인 연락처 제거 1건'));
    ok('우리 취급 종류가 아닌 행(노트북)은 제외', str_contains($o, '취급 종류 아님 1') && !str_contains($o, '노트북'));
    [$c, $o2] = $run('--show-columns --file=' . escapeshellarg("{$tmp}/items.csv"));
    ok('--show-columns: 머리글 인식 결과만 출력(저장 없음)', $c === 0 && str_contains($o2, '품명') && str_contains($o2, '→ name') && str_contains($o2, '비고'));
    $rows = $pdo->query("SELECT * FROM external_products WHERE source_key = 'test_fixture' ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    ok('정규화 값 저장(종류·두께·단위·참고가)', count($rows) === 2 && $rows[0]['product_type'] === '인조화강블록' && (int) $rows[0]['thickness_mm'] === 80 && $rows[0]['unit'] === '㎡' && (int) $rows[0]['ref_price'] === 1000);
    ok('개인 연락처는 DB 어디에도 없음', !preg_match('/010-0000-0000/', json_encode($rows, JSON_UNESCAPED_UNICODE)));
    ok('KS 표기는 속성으로', str_contains((string) $rows[1]['attrs_json'], 'KS D 3503'));
    [$c] = $run('--source=test_fixture --file=' . escapeshellarg("{$tmp}/items.csv"));
    ok('다시 가져와도 행이 늘지 않음(같은 상품 갱신)', $c === 0 && (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE source_key = 'test_fixture'")->fetchColumn() === 2);
    $m = $pdo->query("SELECT m.*, p.name, p.spec FROM external_product_matches m JOIN products p ON p.id = m.product_id
                      WHERE m.external_product_id = {$rows[0]['id']} ORDER BY m.score DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    ok('매칭 후보 1순위 = 우리 인조화강블록(투수) 200*100*80T, 상태 candidate', $m && $m['name'] === '인조화강블록(투수)' && str_contains($m['spec'], '200*100*80T') && $m['status'] === 'candidate');

    echo "[상담 카드 — 확정한 외부 자료만]\n";
    $card = static function () use ($m): array {
        $o = json_decode(tool_search_products(['query' => '인조화강블록', 'spec_mm' => 80, 'spec_strict' => true, 'area_m2' => 10]), true);
        foreach ($o['products'] ?? [] as $p) { if ((int) $p['id'] === (int) $m['product_id']) { return $p; } }
        return [];
    };
    ok('후보(candidate) 상태에서는 외부 자료 표시 안 함', !str_contains(implode(' ', $card()['guide'] ?? []), '참고 자료'));
    [$c] = $run("--confirm={$rows[0]['id']}:{$m['product_id']}");
    $g = implode(' ', $card()['guide'] ?? []);
    ok('사람이 확정(--confirm)하면 제조사·출처 표시, 가격은 표시 안 함', $c === 0 && str_contains($g, '참고 자료(판매 상품 아님): [테스트]제조') && str_contains($g, '테스트 출처') && !str_contains($g, '1000'));
    [$c, $o] = $run('--source=test_fixture --confirm-min=50');
    $per = $pdo->query("SELECT MAX(cnt) FROM (SELECT m.external_product_id, COUNT(*) cnt FROM external_product_matches m JOIN external_products e ON e.id = m.external_product_id
                        WHERE e.source_key = 'test_fixture' AND m.status = 'confirmed' GROUP BY m.external_product_id) x")->fetchColumn();
    ok('--confirm-min: 80점 미만 값은 80으로 올리고, 외부 상품당 1순위 1건만 확정', $c === 0 && str_contains($o, '80점 이상 1순위') && (int) $per === 1);
    $prodAfter = $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM);
    ok('products 건수·판매가 합계 그대로', $prodBefore === $prodAfter);
} finally {
    $pdo->exec("DELETE m FROM external_product_matches m JOIN external_products e ON e.id = m.external_product_id WHERE e.source_key IN ('test_fixture', 'test_cond')");
    $pdo->exec("DELETE FROM external_products WHERE source_key IN ('test_fixture', 'test_cond')");
    array_map('unlink', glob("{$tmp}/*") ?: []);
    @rmdir($tmp);
}
ok('테스트 행 정리', (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE source_key LIKE 'test_%'")->fetchColumn() === 0);

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
