<?php
declare(strict_types=1);
/**
 * '재고 있는 상품만 보기'(instock) 통합 테스트 — 목(Mock) 데이터 + 로컬 테스트 DB 전용.
 *
 * 목 데이터 원칙:
 *  - 운영/개발 DB에는 절대 쓰지 않는다. DB 이름이 '_test'로 끝나고 호스트가 로컬일 때만 실행(아니면 SKIP).
 *  - 모든 목 행은 external_source='MOCK', 이름 '[MOCK] ...', 카테고리 slug 'mock-...'로 구분한다.
 *  - 실행할 때마다 기존 MOCK 행을 지우고 다시 넣는다. 테스트 후에도 남겨 두어 로컬 화면 확인에 쓸 수 있다.
 *  - 제거: 테스트 DB를 DROP 하거나, 아래 cleanup_mock() 과 같은 DELETE(external_source='MOCK', slug 'mock-%').
 *  - 운영 코드에는 목 전환 스위치가 없다. 기능은 products.stock_qty 만 읽으므로 실제 재고가 등록되면 그대로 동작.
 *
 * 실행 예 (스키마 적용된 빈 tomokmall_test DB 필요):
 *   DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=... DB_PASS=... php tests/stock_filter.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require __DIR__ . '/../app/bootstrap.php';

// ── 안전장치: 로컬 '_test' DB가 아니면 아무것도 하지 않고 종료 ──────────
$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true)) {
    echo "SKIP: 로컬 테스트 DB(DB_NAME=*_test, DB_HOST=127.0.0.1|localhost|mysql)에서만 실행합니다. 현재 DB_NAME='{$dbName}'\n";
    exit(0);
}
if (!db()) {
    echo "SKIP: 테스트 DB 접속 실패 (DB_NAME={$dbName})\n";
    exit(0);
}

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}

// ── 목 데이터 ───────────────────────────────────────────────
// 카테고리: 재질 루트 11(철근콘크리트)·13(스틸)은 FLT_MAT_LABELS와 같은 id를 써야 재질 필터를 검증할 수 있다.
const MOCK_CATS = [
    [11,   null, '[MOCK] 철근콘크리트', 'mock-rc'],
    [13,   null, '[MOCK] 스틸',         'mock-steel'],
    [9101, 11,   '[MOCK] 테스트수로관', 'mock-channel'],   // 130개: 재고 0·1·음수·여러 개 혼합, 페이지네이션용
    [9102, 13,   '[MOCK] 테스트그레이팅', 'mock-grating'], // 하중 필터용
    [9103, 11,   '[MOCK] 테스트경계석', 'mock-curb'],      // 전부 재고 0 → 재고 필터 0건
];

function mock_products(): array
{
    $rows = [];
    for ($n = 1; $n <= 130; $n++) {
        $stock = $n % 4 === 0 ? 0 : $n;          // 4의 배수는 재고 0
        if ($n === 1) { $stock = 1; }             // 재고 1개(경계값)
        if ($n === 2) { $stock = -3; }            // 음수 재고 → 재고 없음 취급
        if ($n === 3) { $stock = 999; }           // 재고 여러 개
        $rows[] = [9101, '[MOCK] 테스트수로관 ' . ($n % 2 ? '사각' : '원형') . " {$n}호",
                   '규격: ' . ($n % 3 === 0 ? '300*300*1000' : '400*400*2000') . ' | 단위: 본 | 중량: 100kg',
                   10000 + $n * 100, $stock, 1, "ch-{$n}"];
    }
    $rows[] = [9101, '[MOCK] 테스트수로관 비활성', '규격: 400*400*2000 | 단위: 본', 5000, 50, 0, 'ch-inactive']; // 비활성(재고 있어도 미노출)
    $rows[] = [9102, '[MOCK] 테스트그레이팅 중하중 A',     '규격: 150*1000*25 | 단위: 개', 30000, 5, 1, 'gr-1'];
    $rows[] = [9102, '[MOCK] 테스트그레이팅 중하중 B',     '규격: 150*1000*25 | 단위: 개', 31000, 0, 1, 'gr-2'];
    $rows[] = [9102, '[MOCK] 테스트그레이팅 안전중하중 C', '규격: 200*1000*25 | 단위: 개', 40000, 0, 1, 'gr-3'];
    $rows[] = [9102, '[MOCK] 테스트그레이팅 안전중하중 D', '규격: 200*1000*25 | 단위: 개', 41000, 1, 1, 'gr-4'];
    for ($n = 1; $n <= 3; $n++) {
        $rows[] = [9103, "[MOCK] 테스트경계석 {$n}호", '규격: 120*120*1000 | 단위: 개', 8000, 0, 1, "cb-{$n}"];
    }
    return $rows;
}

function cleanup_mock(PDO $pdo): void
{
    $pdo->exec("DELETE FROM products WHERE external_source = 'MOCK'");
    $pdo->exec("DELETE FROM categories WHERE slug LIKE 'mock-%' AND parent_id IS NOT NULL");
    $pdo->exec("DELETE FROM categories WHERE slug LIKE 'mock-%'");
}

$pdo = db();
cleanup_mock($pdo);
$ic = $pdo->prepare('INSERT INTO categories (id, parent_id, name, slug, sort_order, is_active) VALUES (?, ?, ?, ?, 0, 1)');
foreach (MOCK_CATS as $c) { $ic->execute($c); }
$ip = $pdo->prepare(
    "INSERT INTO products (category_id, name, spec, price_b2c, stock_qty, is_active, external_source, external_id)
     VALUES (?, ?, ?, ?, ?, ?, 'MOCK', ?)"
);
$mock = mock_products();
foreach ($mock as $r) { $ip->execute($r); }

// 기대값은 목 데이터에서 직접 계산(하드코딩 최소화)
$active    = array_values(array_filter($mock, static fn($r) => $r[5] === 1));
$inCat     = static fn(array $rows, int $cid) => array_values(array_filter($rows, static fn($r) => $r[0] === $cid));
$inStockOf = static fn(array $rows) => array_values(array_filter($rows, static fn($r) => $r[4] > 0));
$names     = static fn(array $rows) => array_map(static fn($r) => $r['name'], $rows);
$allStock  = static fn(array $rows) => array_reduce($rows, static fn($ok, $r) => $ok && (int) $r['stock_qty'] > 0, true);
$f = static function (array $flt): array { $p = []; $s = product_filter_sql($flt, $p); return [$s, $p]; };

echo "[목 데이터 구분]\n";
$cnt = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE external_source = 'MOCK' AND name LIKE '[MOCK]%'")->fetchColumn();
ok('목 상품 전부 MOCK 표시(' . count($mock) . '개)', $cnt === count($mock));

echo "[카테고리 목록 fetch_products]\n";
$chActive = $inCat($active, 9101);
$all = fetch_products(9101);
ok('체크 해제: 전체 ' . count($chActive) . '개 표시(재고 무관)', count($all) === count($chActive));
[$s, $p] = $f(['instock' => true]);
$st = fetch_products(9101, null, $s, $p);
ok('체크: 재고 있는 ' . count($inStockOf($chActive)) . '개만 표시', count($st) === count($inStockOf($chActive)));
ok('체크: 결과 전부 stock_qty > 0', $allStock($st));
ok('재고 1개 상품 포함', in_array('[MOCK] 테스트수로관 사각 1호', $names($st), true));
ok('재고 0개 상품 제외', !in_array('[MOCK] 테스트수로관 원형 4호', $names($st), true));
ok('음수 재고 상품 제외', !in_array('[MOCK] 테스트수로관 원형 2호', $names($st), true));
ok('비활성 상품(재고 50) 제외', !in_array('[MOCK] 테스트수로관 비활성', $names($st), true));
[$s, $p] = $f(['instock' => false]);
ok('다시 해제: 전체로 복귀', count(fetch_products(9101, null, $s, $p)) === count($chActive));

echo "[상위 카테고리(하위 포함) + 재고]\n";
[$s, $p] = $f(['instock' => true]);
$rc = fetch_products(11, null, $s, $p);
ok('루트 11 하위(수로관+경계석) 중 재고만', count($rc) === count($inStockOf(array_merge($inCat($active, 9101), $inCat($active, 9103)))) && $allStock($rc));

echo "[재고 0건 상황]\n";
ok('경계석(전부 재고 0) 체크 → 0건', fetch_products(9103, null, $s, $p) === []);
ok('경계석 체크 해제 → 3건', count(fetch_products(9103)) === 3);

echo "[정렬 + 재고]\n";
[$s, $p] = $f(['instock' => true]);
$sorted = fetch_products(9101, null, $s, $p, product_sort_sql('price_asc'));
$prices = array_map(static fn($r) => (int) $r['price_b2c'], $sorted);
$copy = $prices; sort($copy);
ok('낮은가격순 + 재고: 정렬 유지', $prices === $copy && $allStock($sorted));
$byStock = fetch_products(9101, null, $s, $p, product_sort_sql('stock_desc'));
ok('재고많은순 + 재고: 첫 상품 재고 999', (int) ($byStock[0]['stock_qty'] ?? 0) === 999);

echo "[사이드바 필터 + 재고]\n";
[$s, $p] = $f(['shape' => ['원형'], 'instock' => true]);
$sh = fetch_products(9101, null, $s, $p);
$expSh = array_filter($inStockOf($chActive), static fn($r) => mb_strpos($r[1], '원형') !== false);
ok('형상(원형) + 재고: ' . count($expSh) . '개', count($sh) === count($expSh) && $allStock($sh));
[$s, $p] = $f(['spec' => ['300*300*1000'], 'instock' => true]);
$sp = fetch_products(9101, null, $s, $p);
$expSp = array_filter($inStockOf($chActive), static fn($r) => strpos($r[2], '300*300*1000') !== false);
ok('규격(300*300*1000) + 재고: ' . count($expSp) . '개', count($sp) === count($expSp) && $allStock($sp));
[$s, $p] = $f(['load' => ['안전중하중'], 'instock' => true]);
$ld = fetch_products(9102, null, $s, $p);
ok('하중(안전중하중) + 재고: D 1개', $names($ld) === ['[MOCK] 테스트그레이팅 안전중하중 D']);
[$s, $p] = $f(['mat' => [13], 'instock' => true]);
$mt = search_products('테스트그레이팅', 60, 0, $t, $s, $p);
ok('재질(스틸) + 재고 + 검색: 2개', $t === 2 && count($mt) === 2 && $allStock($mt));

echo "[키워드 검색 + 페이지네이션 + 재고]\n";
$t0 = 0; search_products('테스트수로관', 60, 0, $t0);
[$s, $p] = $f(['instock' => true]);
$t1 = 0; $pg1 = search_products('테스트수로관', 60, 0, $t1, $s, $p);
$t2 = 0; $pg2 = search_products('테스트수로관', 60, 60, $t2, $s, $p);
$expKw = count($inStockOf($chActive));
ok("검색 전체 {$t0}개 → 재고 {$t1}개", $t0 === count($chActive) && $t1 === $expKw);
ok('1페이지 60개 + 2페이지 나머지', count($pg1) === 60 && count($pg2) === $expKw - 60);
ok('2페이지도 재고 조건 유지', $allStock($pg2));
ok('페이지 간 중복 없음', !array_intersect(array_column($pg1, 'id'), array_column($pg2, 'id')));
$tz = 0;
ok('검색 결과 0건(없는 검색어 + 재고)', search_products('목데이터없는검색어zz', 60, 0, $tz, $s, $p) === [] && $tz === 0);

echo "[파세트 product_facets + 재고]\n";
$fa = product_facets(9101, null, ['instock' => false]);
$fb = product_facets(9101, null, ['instock' => true]);
ok('파세트 total: 전체 ' . $fa['total'] . ' → 재고 ' . $fb['total'], $fa['total'] === count($chActive) && $fb['total'] === count($inStockOf($chActive)));
ok('파세트 형상(원형) 개수도 재고 기준', ($fb['shape']['원형'] ?? 0) === count($expSh));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
