<?php
declare(strict_types=1);
/**
 * 회원가 화면 표시 통합 테스트 — 목(Mock) 데이터 + 로컬 테스트 DB 전용.
 *
 * 확인 대상(가격 계산 app/pricing.php·기존 DB 데이터는 그대로):
 *  - display_unit_price(): 상품 상세와 같은 규칙(비회원=판매가, 개인=개인회원가, 기업=기업회원가, 회원가≥판매가면 판매가, 판매가 0=문의)
 *  - 견적 요청(/estimate.php): 개인=개인회원가·기업=기업회원가만 표시(취소선 없음), 합계도 같은 단가
 *  - API 추가 필드: by-category(price_list·price_member), quick-items(display_*), favorites(price_list·price_member) — 기존 필드 그대로, 매입가 미노출
 *  - 기업회원 홈 단골품목 데이터(price_list·price_member)
 *  - 새 견적 저장 단가 = 화면 단가(비회원=판매가, 개인=개인회원가, 기업=기업회원가) → 마이페이지 이전주문·요청된 견적 금액도 같음
 *  - AI 상담 견적서 패널(/api/cart.php)·AI 도구 장바구니 조회(tool_get_cart)도 같은 단가
 *  - 장바구니 비우기: GET ?clear=1 로는 비워지지 않고 POST action=clear 로만
 *
 * 목 데이터 원칙(tests/favorites_api.test.php 와 동일):
 *  - DB 이름이 '_test'로 끝나고 호스트가 로컬일 때만 실행(아니면 SKIP). 운영/개발 DB에는 쓰지 않는다.
 *  - 카테고리 id 9301 slug 'mock-mprice'(개인 마진 20%·기업 15%), 상품 external_source='MOCK' external_id 'mprice-*' 이름 '[MOCK] ...',
 *    회원 '*@mock.test' 이름 '[MOCK] ...'. 실행 시작·끝에 목 행(견적 포함)을 지운다(MPRICE_KEEP=1 이면 끝에 남김 — 화면 확인용).
 *  - 매입가·마진율은 목 값(운영 값 아님).
 *
 * 실행: php tests/member_price_display.test.php  (.env.local 의 로컬 _test DB 사용)
 * 화면 확인용으로 남기기: MPRICE_KEEP=1 MPRICE_TEST_PW=<임의 비밀번호> php tests/member_price_display.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/cart.php';
require_once __DIR__ . '/../app/ai_tools.php';

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true)) {
    echo "SKIP: 로컬 테스트 DB(DB_NAME=*_test, DB_HOST=127.0.0.1|localhost|mysql)에서만 실행합니다. 현재 DB_NAME='{$dbName}'\n";
    exit(0);
}
if (!db() || !function_exists('curl_init')) {
    echo "SKIP: 테스트 DB 접속 실패 또는 curl 확장 없음 (DB_NAME={$dbName})\n";
    exit(0);
}
$hasCorpRate = (bool) db()->query("SHOW COLUMNS FROM categories LIKE 'discount_corporate'")->fetch();
if (!$hasCorpRate) {
    echo "SKIP: categories.discount_corporate 컬럼 없음(운영 구조와 다른 테스트 DB)\n";
    exit(0);
}

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}

define('MOCK_PW', getenv('MPRICE_TEST_PW') ?: bin2hex(random_bytes(12)));
const MOCK_USERS = [
    'cust' => ['customer',  '[MOCK] 회원가 개인', 'mprice-cust@mock.test'],
    'corp' => ['corporate', '[MOCK] 회원가 기업', 'mprice-corp@mock.test'],
];
// [이름, 판매가 b2c, 기업가 b2b, 매입가] — 개인 마진 20%, 기업 마진 15%
const MOCK_PRODUCTS = [
    1 => ['[MOCK] 회원가 상품 1', 10000, 9500, 7000],   // 개인 8,400 · 기업 8,050
    2 => ['[MOCK] 회원가 상품 2', 20000, 19000, 15000], // 개인 18,000 · 기업 17,250
    3 => ['[MOCK] 회원가 상품 3', 10000, 9000, 9000],   // 회원가(10,800·10,350) ≥ 판매가 → 판매가 10,000
    4 => ['[MOCK] 회원가 상품 4', 0, 5000, 3000],        // 판매가 0 → 문의
];

function cleanup_mprice_mock(PDO $pdo): void
{
    $uids = "SELECT id FROM users WHERE email LIKE 'mprice-%@mock.test'";
    $mockEst = "user_id IN ({$uids}) OR (user_id IS NULL AND customer_name LIKE '[MOCK] 회원가%')";   // 비회원 목 견적 포함
    $pdo->exec("DELETE FROM estimate_items WHERE estimate_id IN (SELECT id FROM (SELECT id FROM estimates WHERE {$mockEst}) t)");
    $pdo->exec("DELETE FROM estimates WHERE {$mockEst}");
    $pdo->exec("DELETE FROM favorite_products WHERE user_id IN ({$uids})");
    $pdo->exec("DELETE FROM users WHERE email LIKE 'mprice-%@mock.test'");
    $pdo->exec("DELETE FROM products WHERE external_source = 'MOCK' AND external_id LIKE 'mprice-%'");
    $pdo->exec("DELETE FROM categories WHERE id = 9301 AND slug = 'mock-mprice'");
}

$pdo = db();
cleanup_mprice_mock($pdo);
$pdo->exec("INSERT INTO categories (id, parent_id, name, slug, sort_order, is_active, discount_customer, discount_corporate)
            VALUES (9301, NULL, '[MOCK] 회원가표시', 'mock-mprice', 0, 1, 20, 15)");
$ip = $pdo->prepare(
    "INSERT INTO products (category_id, name, spec, price_b2c, price_b2b, cost_price, stock_qty, is_active, external_source, external_id)
     VALUES (9301, ?, '규격: 400*400*2000 | 단위: 본 | 중량: 100kg', ?, ?, ?, 10, 1, 'MOCK', ?)"
);
$pids = [];
foreach (MOCK_PRODUCTS as $n => [$name, $b2c, $b2b, $cost]) {
    $ip->execute([$name, $b2c, $b2b, $cost, "mprice-{$n}"]);
    $pids[$n] = (int) $pdo->lastInsertId();
}
$iu = $pdo->prepare('INSERT INTO users (role, name, email, password_hash, is_active) VALUES (?, ?, ?, ?, 1)');
$uids = [];
foreach (MOCK_USERS as $k => [$role, $name, $email]) {
    $iu->execute([$role, $name, $email, password_hash(MOCK_PW, PASSWORD_DEFAULT)]);
    $uids[$k] = (int) $pdo->lastInsertId();
}
$pdo->prepare('INSERT INTO favorite_products (user_id, product_id, quantity) VALUES (?, ?, 2)')->execute([$uids['corp'], $pids[1]]);

// ── 함수 단위(등급별) ─────────────────────────────────────────
echo "[display_unit_price — 상품 상세와 같은 규칙]\n";
$row = static function (int $n) use ($pdo, $pids): array {
    $st = $pdo->prepare('SELECT id, category_id, price_b2c, price_b2b, cost_price FROM products WHERE id = ?');
    $st->execute([$pids[$n]]);
    return $st->fetch();
};
$_SESSION['user'] = null;
ok('비회원: 판매가 10,000', display_unit_price($row(1)) === 10000);
$_SESSION['user'] = ['role' => 'customer', 'email' => 'mprice-cust@mock.test'];
ok('개인회원: 개인회원가 8,400(매입가 7,000 × 1.20)', display_unit_price($row(1)) === 8400);
ok('개인회원: 회원가(10,800) ≥ 판매가 → 판매가 10,000', display_unit_price($row(3)) === 10000);
ok('판매가 0 → 0(견적문의)', display_unit_price($row(4)) === 0);
$m = display_price_map([$pids[1], $pids[2], 0, -5]);
ok('display_price_map: [판매가, 표시 단가] 단일 조회', ($m[$pids[1]] ?? null) === ['list' => 10000, 'price' => 8400] && ($m[$pids[2]] ?? null) === ['list' => 20000, 'price' => 18000] && count($m) === 2);
$_SESSION['user'] = ['role' => 'corporate', 'email' => 'mprice-corp@mock.test'];
ok('기업회원: 기업회원가 8,050(매입가 7,000 × 1.15)', display_unit_price($row(1)) === 8050);
ok('기업회원: 회원가(10,350) ≥ 판매가 → 판매가 10,000', display_unit_price($row(3)) === 10000);
$_SESSION['user'] = null;

// ── 내장 서버 ───────────────────────────────────────────────
$port = 18000 + random_int(0, 999);
$env  = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) { $env[$k] = (string) getenv($k); }
$env['PATH'] = (string) getenv('PATH');
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', realpath(__DIR__ . '/../public')],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, null, $env
);
register_shutdown_function(static function () use ($proc) { proc_terminate($proc); });
$base = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }

/** @return array{0:int,1:mixed,2:string} [status, json, raw] */
function http(string $method, string $url, $body = null, ?string $jar = null, bool $form = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20]);
    if ($jar !== null) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($body !== null) {
        if ($form) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($body));
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }
    }
    $raw = (string) curl_exec($ch);
    $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, json_decode($raw, true), $raw];
}
function login(string $base, string $email): string
{
    $jar = tempnam(sys_get_temp_dir(), 'mpjar');
    http('POST', "{$base}/api/auth/login.php", ['email' => $email, 'password' => MOCK_PW], $jar);
    return $jar;
}
/** 견적 요청 화면 품목 행의 data-price(표시 단가) [product_id => price] */
function estimate_prices(string $html): array
{
    preg_match_all('/<tr data-pid="(\d+)" data-price="(\d+)"/', $html, $mm, PREG_SET_ORDER);
    $out = [];
    foreach ($mm as $x) { $out[(int) $x[1]] = (int) $x[2]; }
    return $out;
}
function total_display(string $html): string
{
    return preg_match('/id="total-display">([^<]*)</', $html, $mm) ? trim($mm[1]) : '';
}
/** 견적 요청 화면 품목 표 부분(취소선 등 검사용) */
function items_table(string $html): string
{
    return preg_match('/<table class="est-items".*?<\/table>/s', $html, $mm) ? $mm[0] : '';
}
$byCat = "{$base}/api/products/by-category.php?category_id=9301";
$findP = static function ($d, int $pid): array { foreach (($d['products'] ?? []) as $p) { if ((int) $p['id'] === $pid) { return $p; } } return []; };

echo "[비회원 — 목록 API 표시 가격]\n";
[, $d, $raw] = http('GET', $byCat);
$p1 = $findP($d, $pids[1]);
ok('by-category: price_list 10,000 · price_member 10,000(비회원 = 판매가)', ($p1['price_list'] ?? null) === 10000 && ($p1['price_member'] ?? null) === 10000);
ok('by-category: 기존 필드 price_b2c 그대로, 기업가 미노출', ($p1['price_b2c'] ?? null) === 10000 && !array_key_exists('price_b2b', $p1));
ok('by-category: 매입가 미노출(cost 필드·7,000 값 없음)', !str_contains($raw, 'cost') && !str_contains($raw, '7000'));

$jCust = login($base, MOCK_USERS['cust'][2]);
$jCorp = login($base, MOCK_USERS['corp'][2]);

echo "[개인회원 — 견적 요청 화면: 개인회원가만]\n";
foreach ([[1, 2], [2, 1], [4, 1]] as [$n, $q]) {
    http('POST', "{$base}/api/cart.php", ['action' => 'add', 'product_id' => $pids[$n], 'quantity' => $q], $jCust);
}
[$st, , $html] = http('GET', "{$base}/estimate.php", null, $jCust);
$ep = estimate_prices($html);
ok('단가: 상품1 8,400 · 상품2 18,000 · 상품4 0(문의)', $st === 200 && ($ep[$pids[1]] ?? null) === 8400 && ($ep[$pids[2]] ?? null) === 18000 && ($ep[$pids[4]] ?? null) === 0);
ok('화면 표기: "8,400원"·소계 "16,800원"', str_contains($html, '>8,400원<') && str_contains($html, '>16,800원<'));
ok('상품 합계 34,800원 + 문의(8,400×2 + 18,000)', total_display($html) === '34,800원 + 문의');
ok('할인가만 표시(품목 표에 취소선·판매가 10,000 없음)', !str_contains(items_table($html), '<del') && !str_contains(items_table($html), '10,000'));
[, $d] = http('GET', $byCat, null, $jCust);
ok('by-category(개인): price_member 8,400', ($findP($d, $pids[1])['price_member'] ?? null) === 8400);

echo "[개인회원 — AI 상담 견적서 패널(장바구니 API) = 화면 단가]\n";
[, $d] = http('POST', "{$base}/api/cart.php", ['action' => 'get'], $jCust);
$ci = []; foreach (($d['cart']['items'] ?? []) as $x) { $ci[(int) $x['product_id']] = $x; }
ok('/api/cart.php get: 상품1 단가 8,400 · 소계 16,800, 합계 34,800', ($ci[$pids[1]]['unit_price'] ?? null) === 8400 && ($ci[$pids[1]]['subtotal'] ?? null) === 16800 && ($d['cart']['total'] ?? null) === 34800);
[, $d] = http('POST', "{$base}/api/cart.php", ['action' => 'update', 'product_id' => $pids[2], 'quantity' => 1], $jCust);
ok('/api/cart.php update 응답도 같은 단가(합계 34,800)', ($d['cart']['total'] ?? null) === 34800);

echo "[개인회원 — 접수 시 저장 단가 = 화면 단가 → 이전주문·요청된 견적 금액도 같음]\n";
$items = array_map(static fn($n, $q) => ['product_id' => $pids[$n], 'quantity' => $q], [1, 2, 4], [2, 1, 1]);
[, $d] = http('POST', "{$base}/quote.php", ['customer_name' => '[MOCK] 회원가 개인', 'phone' => '010-0000-0000', 'delivery_undecided' => true, 'items' => $items], $jCust);
$eid = (int) ($d['estimate_id'] ?? 0);
$saved = $eid ? $pdo->query("SELECT product_id, unit_price FROM estimate_items WHERE estimate_id = {$eid}")->fetchAll(PDO::FETCH_KEY_PAIR) : [];
ok('견적 접수 ok', ($d['ok'] ?? false) === true && $eid > 0);
ok('저장 단가: 상품1 8,400 · 상품2 18,000 · 상품4 0 (화면과 같음)', (int) ($saved[$pids[1]] ?? -1) === 8400 && (int) ($saved[$pids[2]] ?? -1) === 18000 && (int) ($saved[$pids[4]] ?? -1) === 0);
[, , $html] = http('GET', "{$base}/my-estimates.php", null, $jCust);
ok('요청된 견적: 금액 34,800원', str_contains($html, '34,800원'));
[, , $html] = http('GET', "{$base}/mypage.php?tab=orders", null, $jCust);
ok('마이페이지 이전주문: 금액 34,800', str_contains($html, '34,800'));

echo "[비회원 — 견적 요청·저장은 판매가 그대로]\n";
$jGuest = tempnam(sys_get_temp_dir(), 'mpjar');
http('POST', "{$base}/api/cart.php", ['action' => 'add', 'product_id' => $pids[1], 'quantity' => 2], $jGuest);
[, , $html] = http('GET', "{$base}/estimate.php", null, $jGuest);
ok('비회원 견적 요청: 단가 10,000 · 합계 20,000원', (estimate_prices($html)[$pids[1]] ?? null) === 10000 && total_display($html) === '20,000원');
[, $d] = http('POST', "{$base}/quote.php", ['customer_name' => '[MOCK] 회원가 비회원', 'phone' => '010-0000-0000', 'delivery_undecided' => true, 'items' => [['product_id' => $pids[1], 'quantity' => 2]]], $jGuest);
$geid = (int) ($d['estimate_id'] ?? 0);
$gsaved = $geid ? (int) $pdo->query("SELECT unit_price FROM estimate_items WHERE estimate_id = {$geid} AND product_id = {$pids[1]}")->fetchColumn() : -1;
ok('비회원 저장 단가: 10,000(판매가)', $gsaved === 10000);

echo "[AI 도구 장바구니 조회(tool_get_cart) = 패널과 같은 단가]\n";
$_SESSION['cart'] = [$pids[1] => 2];
$_SESSION['user'] = ['role' => 'customer', 'email' => 'mprice-cust@mock.test'];
$t = json_decode(tool_get_cart('mock'), true);
ok('개인회원: 단가 8,400 · 합계 16,800', ($t['items'][0]['unit_price'] ?? null) === 8400 && ($t['total'] ?? null) === 16800);
$_SESSION['user'] = null;
$t = json_decode(tool_get_cart('mock'), true);
ok('비회원: 단가 10,000 · 합계 20,000', ($t['items'][0]['unit_price'] ?? null) === 10000 && ($t['total'] ?? null) === 20000);
$_SESSION['cart'] = [];

echo "[기업회원 — 즉시견적 담은 품목 API]\n";
[, $d] = http('POST', "{$base}/api/estimate/quick-items.php", ['action' => 'add', 'product_id' => $pids[1], 'quantity' => 3], $jCorp);
$it = $d['items'][0] ?? [];
ok('display_price 8,050 · display_subtotal 24,150 · display_total 24,150', ($it['display_price'] ?? null) === 8050 && ($it['display_subtotal'] ?? null) === 24150 && ($d['display_total'] ?? null) === 24150);
ok('기존 필드 그대로: unit_price 9,500(price_b2b) · total 28,500 · list_price 10,000', ($it['unit_price'] ?? null) === 9500 && ($d['total'] ?? null) === 28500 && ($it['list_price'] ?? null) === 10000);
[, $d] = http('GET', $byCat, null, $jCorp);
$p1 = $findP($d, $pids[1]);
ok('by-category(기업): price_list 10,000 · price_member 8,050 · price_b2b 9,500 그대로', ($p1['price_list'] ?? null) === 10000 && ($p1['price_member'] ?? null) === 8050 && ($p1['price_b2b'] ?? null) === 9500);
ok('by-category(기업): 회원가 ≥ 판매가 상품은 price_member = 판매가', ($findP($d, $pids[3])['price_member'] ?? null) === 10000);

echo "[기업회원 — 견적 요청 화면(즉시견적 품목): 기업회원가만 + 규격 표시]\n";
[$st, , $html] = http('GET', "{$base}/estimate.php", null, $jCorp);
$ep = estimate_prices($html);
ok('단가 8,050 · 합계 24,150원', $st === 200 && ($ep[$pids[1]] ?? null) === 8050 && total_display($html) === '24,150원');
ok('규격 칸 표시(400*400*2000) — 즉시견적 경로에서 비어 있던 것', (bool) preg_match('/<td style="font-size:12px;color:#555">400\*400\*2000<\/td>/', $html));
ok('할인가만 표시(취소선 없음)', !str_contains(items_table($html), '<del'));
[, $d] = http('POST', "{$base}/quote.php", ['customer_name' => '[MOCK] 회원가 기업', 'phone' => '010-0000-0000', 'delivery_undecided' => true, 'mode' => 'quick', 'items' => [['product_id' => $pids[1], 'quantity' => 3]]], $jCorp);
$ceid = (int) ($d['estimate_id'] ?? 0);
$csaved = $ceid ? (int) $pdo->query("SELECT unit_price FROM estimate_items WHERE estimate_id = {$ceid} AND product_id = {$pids[1]}")->fetchColumn() : -1;
ok('기업회원 즉시견적 접수: 저장 단가 8,050(기업회원가 — 예전 price_b2b 9,500 아님)', $csaved === 8050);

echo "[기업회원 — 단골품목 표시 가격]\n";
[, $d] = http('GET', "{$base}/api/estimate/favorites.php", null, $jCorp);
$f = $d['favorites'][0] ?? [];
ok('favorites: price_list 10,000 · price_member 8,050 · price_b2b 9,500 그대로', ($f['price_list'] ?? null) === 10000 && ($f['price_member'] ?? null) === 8050 && ($f['price_b2b'] ?? null) === 9500);
require_once __DIR__ . '/../app/corp_home.php';
$_SESSION['user'] = ['role' => 'corporate', 'email' => 'mprice-corp@mock.test'];
$reg = corp_home_regulars($pdo, $uids['corp']);
ok('기업회원 홈 단골품목: price_list 10,000 · price_member 8,050 · price 9,500 그대로', ($reg[0]['price_list'] ?? null) === 10000 && ($reg[0]['price_member'] ?? null) === 8050 && ($reg[0]['price'] ?? null) === 9500);
$_SESSION['user'] = null;

echo "[장바구니 비우기 — POST 만]\n";
http('POST', "{$base}/api/cart.php", ['action' => 'add', 'product_id' => $pids[1], 'quantity' => 1], $jCust);
http('GET', "{$base}/cart.php?clear=1", null, $jCust);
[, $d] = http('POST', "{$base}/api/cart.php", ['action' => 'get'], $jCust);
ok('GET /cart.php?clear=1 → 비워지지 않음', ($d['cart']['count'] ?? 0) > 0);
[, , $html] = http('GET', "{$base}/cart.php", null, $jCust);
ok('장바구니 화면: 비우기 = POST 폼(링크 아님)', str_contains($html, 'name="action" value="clear"') && !str_contains($html, 'clear=1'));
http('POST', "{$base}/cart.php", ['action' => 'clear'], $jCust, true);
[, $d] = http('POST', "{$base}/api/cart.php", ['action' => 'get'], $jCust);
ok('POST /cart.php action=clear → 비워짐', ($d['cart']['count'] ?? -1) === 0);

foreach ([$jCust, $jCorp, $jGuest] as $j) { @unlink($j); }
if (getenv('MPRICE_KEEP') === '1') {
    echo "목 데이터 남김(MPRICE_KEEP=1) — 확인 후 다시 실행(MPRICE_KEEP 없이)하면 지워집니다.\n";
} else {
    cleanup_mprice_mock($pdo);
}

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
