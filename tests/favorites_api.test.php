<?php
declare(strict_types=1);
/**
 * 관심상품 API(/api/estimate/favorites.php) + 상품 목록 선택 모드 마크업 통합 테스트 — 목(Mock) 데이터 + 로컬 테스트 DB 전용.
 *
 * 목 데이터 원칙(tests/stock_filter.test.php 와 동일):
 *  - DB 이름이 '_test'로 끝나고 호스트가 로컬일 때만 실행(아니면 SKIP). 운영/개발 DB에는 쓰지 않는다.
 *  - 상품: external_source='MOCK', external_id 'fav-...', 이름 '[MOCK] ...', 카테고리 slug 'mock-fav'(id 9201)·하위 'mock-fav-sub'(id 9202)
 *  - 회원: 이메일 '*@mock.test', 이름 '[MOCK] ...' (개인 1명, 기업 2명). 관심상품 행은 이 회원들 것만 생성.
 *  - 실행할 때마다 위 목 행을 지우고 다시 넣는다.
 *
 * PHP 내장 서버(php -S)를 임시 포트로 띄워 실제 HTTP·세션으로 검증한다.
 * 실행 예: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=... DB_PASS=... php tests/favorites_api.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/favorites.php';

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

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}

// ── 목 데이터 ───────────────────────────────────────────────
// 목 회원 비밀번호는 실행마다 무작위 생성(저장소에 남기지 않음). 화면 수동 확인용으로 고정하려면 FAV_TEST_PW 환경변수 사용
define('MOCK_PW', getenv('FAV_TEST_PW') ?: bin2hex(random_bytes(12)));
const MOCK_USERS = [
    'cust'  => ['customer',  '[MOCK] 개인회원',  'fav-cust@mock.test'],
    'corp'  => ['corporate', '[MOCK] 기업회원A', 'fav-corp@mock.test'],
    'corp2' => ['corporate', '[MOCK] 기업회원B', 'fav-corp2@mock.test'],
];

function cleanup_fav_mock(PDO $pdo): void
{
    $pdo->exec("DELETE FROM favorite_products WHERE user_id IN (SELECT id FROM users WHERE email LIKE '%@mock.test')");
    $pdo->exec("DELETE FROM users WHERE email LIKE '%@mock.test'");
    $pdo->exec("DELETE FROM products WHERE external_source = 'MOCK' AND external_id LIKE 'fav-%'");
    $pdo->exec("DELETE FROM categories WHERE id = 9202 AND slug = 'mock-fav-sub'");
    $pdo->exec("DELETE FROM categories WHERE id = 9201 AND slug = 'mock-fav'");
    if (vendors_available()) { $pdo->exec("DELETE FROM vendors WHERE name LIKE '[MOCK] 관심상품업체%'"); }
}

$pdo = db();
cleanup_fav_mock($pdo);
$pdo->exec("INSERT INTO categories (id, parent_id, name, slug, sort_order, is_active) VALUES (9201, NULL, '[MOCK] 관심상품테스트', 'mock-fav', 0, 1)");
$ip = $pdo->prepare(
    "INSERT INTO products (category_id, name, spec, price_b2c, price_b2b, stock_qty, is_active, external_source, external_id)
     VALUES (9201, ?, '규격: 400*400*2000 | 단위: 본 | 중량: 100kg', ?, ?, 10, ?, 'MOCK', ?)"
);
$pids = [];
for ($n = 1; $n <= 55; $n++) {
    $ip->execute(["[MOCK] 관심상품테스트 {$n}호", 20000 + $n, 15000 + $n, 1, "fav-{$n}"]);
    $pids[$n] = (int) $pdo->lastInsertId();
}
$ip->execute(['[MOCK] 관심상품테스트 판매중지', 9000, 8000, 0, 'fav-inactive']);
$inactiveId = (int) $pdo->lastInsertId();
// 마이페이지 표시용: 1호에 업체 2곳(A 주거래), 2호에 이미지
$pdo->prepare("UPDATE products SET image_url = '/assets/images/products-prod.png' WHERE id = ?")->execute([$pids[2]]);
if (vendors_available()) {
    $iv = $pdo->prepare('INSERT INTO vendors (name, is_active) VALUES (?, 1)');
    $ipv = $pdo->prepare('INSERT INTO product_vendors (product_id, vendor_id, is_primary) VALUES (?, ?, ?)');
    foreach (['[MOCK] 관심상품업체A' => 1, '[MOCK] 관심상품업체B' => 0] as $vn => $prim) {
        $iv->execute([$vn]);
        $ipv->execute([$pids[1], (int) $pdo->lastInsertId(), $prim]);
    }
}

$iu = $pdo->prepare('INSERT INTO users (role, name, email, password_hash, is_active) VALUES (?, ?, ?, ?, 1)');
$uids = [];
foreach (MOCK_USERS as $k => [$role, $name, $email]) {
    $iu->execute([$role, $name, $email, password_hash(MOCK_PW, PASSWORD_DEFAULT)]);
    $uids[$k] = (int) $pdo->lastInsertId();
}
// 기존 기업회원 데이터(단건 저장분, 수량 7) — 다중 추가 후에도 유지돼야 함
$pdo->prepare('INSERT INTO favorite_products (user_id, product_id, quantity) VALUES (?, ?, 7)')->execute([$uids['corp'], $pids[1]]);

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
function http(string $method, string $url, ?array $body = null, ?string $jar = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 15]);
    if ($jar !== null) { curl_setopt($ch, CURLOPT_COOKIEJAR, $jar); curl_setopt($ch, CURLOPT_COOKIEFILE, $jar); }
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    }
    $raw = (string) curl_exec($ch);
    $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$st, json_decode($raw, true), $raw];
}
function login(string $base, string $email): string
{
    $jar = tempnam(sys_get_temp_dir(), 'favjar');
    http('POST', "{$base}/api/auth/login.php", ['email' => $email, 'password' => MOCK_PW], $jar);
    return $jar;
}
$api = "{$base}/api/estimate/favorites.php";
$favIds = static function (array $d): array { return array_map(static fn($f) => (int) $f['product_id'], $d['favorites'] ?? []); };

echo "[비로그인 접근 제한]\n";
[$st, $d] = http('GET', $api);
ok('GET → 401', $st === 401 && ($d['ok'] ?? null) === false);
[$st] = http('POST', $api, ['product_ids' => [$pids[2]]]);
ok('POST(다중) → 401', $st === 401);
[$st] = http('DELETE', $api, ['product_id' => $pids[1]]);
ok('DELETE → 401', $st === 401);
ok('비로그인 요청으로 DB 변화 없음', (int) $pdo->query("SELECT COUNT(*) FROM favorite_products f JOIN users u ON u.id=f.user_id WHERE u.email LIKE '%@mock.test'")->fetchColumn() === 1);

$jCust = login($base, MOCK_USERS['cust'][2]);
$jCorp = login($base, MOCK_USERS['corp'][2]);
$jCorp2 = login($base, MOCK_USERS['corp2'][2]);

echo "[일반회원 추가·조회·삭제]\n";
[$st, $d] = http('POST', $api, ['product_ids' => [$pids[2], $pids[3], (string) $pids[4]]], $jCust);
ok('다중 추가 3개 → 200 added 3', $st === 200 && ($d['ok'] ?? false) && count($d['added']) === 3 && $d['failed'] === [] && $d['total'] === 3);
[$st, $d] = http('GET', $api, null, $jCust);
ok('조회: 본인 3개', $st === 200 && $favIds($d) === [$pids[2], $pids[3], $pids[4]]);
$row = $d['favorites'][0] ?? [];
ok('개인회원 조회 가격: 기업가(price_b2b) 노출 안 함 → 개인 단가(매입가 미설정이면 정가)', ($row['price_b2b'] ?? null) === 20000 + 2);
ok('기본 수량 1로 저장', ($row['quantity'] ?? null) === 1);
ok('목록 표시 필드: 이미지·규격(× 표기)·업체 배열', ($row['image_url'] ?? null) === '/assets/images/products-prod.png'
    && ($row['size'] ?? null) === '400×400×2000' && ($row['vendors'] ?? null) === []
    && array_key_exists('spec', $row) && array_key_exists('price_b2b', $row));
[$st, $d] = http('DELETE', $api, ['product_id' => $pids[3]], $jCust);
ok('삭제 → ok', $st === 200 && ($d['ok'] ?? false));
[, $d] = http('GET', $api, null, $jCust);
ok('삭제 후 2개', $favIds($d) === [$pids[2], $pids[4]]);

echo "[중복 저장 방지 + 기존 내역 유지]\n";
[$st, $d] = http('POST', $api, ['product_ids' => [$pids[2], $pids[5]]], $jCust);
ok('이미 등록 1 + 신규 1 → added 1, already 1', $st === 200 && $d['added'] === [$pids[5]] && $d['already'] === [$pids[2]]);
ok('DB 중복 행 없음', (int) $pdo->query("SELECT COUNT(*) FROM favorite_products WHERE user_id={$uids['cust']} AND product_id={$pids[2]}")->fetchColumn() === 1);
[$st, $d] = http('POST', $api, ['product_ids' => [$pids[2], $pids[2], $pids[2]]], $jCust);
ok('같은 상품 반복 요청 → 1건으로 정규화, already', $st === 200 && $d['already'] === [$pids[2]] && $d['added'] === []);

echo "[기업회원 추가·조회·삭제 + 기존 데이터 유지]\n";
[$st, $d] = http('POST', $api, ['product_ids' => [$pids[1], $pids[6]]], $jCorp);
ok('기존 등록(수량 7) 상품 + 신규 → already/added', $st === 200 && $d['already'] === [$pids[1]] && $d['added'] === [$pids[6]]);
[, $d] = http('GET', $api, null, $jCorp);
$q1 = 0; foreach ($d['favorites'] as $f) { if ($f['product_id'] === $pids[1]) { $q1 = $f['quantity']; $p1 = $f['price_b2b']; } }
ok('기존 수량 7 유지(다중 추가가 덮어쓰지 않음)', $q1 === 7);
ok('기업회원 조회 가격: 기존대로 price_b2b', ($p1 ?? null) === 15000 + 1);
$v1 = []; foreach ($d['favorites'] as $f) { if ($f['product_id'] === $pids[1]) { $v1 = $f['vendors']; } }
ok('업체명: 주거래 업체 먼저(' . implode(', ', $v1) . ')', !vendors_available() || $v1 === ['[MOCK] 관심상품업체A', '[MOCK] 관심상품업체B']);
[$st, $d] = http('POST', $api, ['product_id' => $pids[7], 'quantity' => 3], $jCorp);
ok('기존 단건 POST 형식 호환 → 201 favorite_id', $st === 201 && ($d['ok'] ?? false) && ($d['favorite_id'] ?? 0) > 0);
[$st] = http('DELETE', $api, ['product_id' => $pids[7]], $jCorp);
ok('기업회원 삭제', $st === 200);

echo "[다른 사용자 데이터 접근 차단]\n";
[, $d] = http('GET', $api, null, $jCorp2);
ok('기업회원B 조회: 다른 회원 관심상품 안 보임(0개)', $favIds($d) === []);
[$st] = http('DELETE', $api, ['product_id' => $pids[2]], $jCorp2);
[, $d] = http('GET', $api, null, $jCust);
ok('기업회원B가 같은 상품 DELETE 해도 개인회원 데이터 유지', in_array($pids[2], $favIds($d), true));
[, $d] = http('POST', $api, ['product_ids' => [$pids[2]], 'user_id' => $uids['cust']], $jCorp2);
ok('body의 user_id 무시(세션 사용자에만 저장)', ($d['added'] ?? []) === [$pids[2]]
    && (int) $pdo->query("SELECT COUNT(*) FROM favorite_products WHERE user_id={$uids['corp2']} AND product_id={$pids[2]}")->fetchColumn() === 1);

echo "[입력 검증 + 부분 실패]\n";
[$st, $d] = http('POST', $api, ['product_ids' => []], $jCust);
ok('빈 배열 → 400', $st === 400 && ($d['ok'] ?? true) === false);
[$st] = http('POST', $api, ['product_ids' => ['abc']], $jCust);
ok('잘못된 id → 400', $st === 400);
[$st] = http('POST', $api, ['product_ids' => range(1, FAVORITES_BULK_MAX + 1)], $jCust);
ok('1회 최대 개수 초과 → 400', $st === 400);
[$st, $d] = http('POST', $api, ['product_ids' => [$pids[8], $inactiveId, 999999999]], $jCust);
ok('판매중지·없는 상품은 failed(invalid), 나머지 저장', $st === 200 && $d['added'] === [$pids[8]]
    && array_column($d['failed'], 'reason') === ['invalid', 'invalid']);

echo "[최대 50개 제한]\n";
[, $d] = http('GET', $api, null, $jCust);
$have = count($d['favorites']);
$rest = array_values(array_diff(array_slice($pids, 0, 55), $favIds($d)));
[$st, $d] = http('POST', $api, ['product_ids' => $rest], $jCust);
ok("기존 {$have}개 + " . count($rest) . '개 요청 → 50개까지만 저장, 나머지 limit',
    $st === 200 && $d['total'] === FAVORITES_MAX && count($d['added']) === FAVORITES_MAX - $have
    && count($d['failed']) === count($rest) - (FAVORITES_MAX - $have)
    && array_unique(array_column($d['failed'], 'reason')) === ['limit']);
ok('DB 50개', (int) $pdo->query("SELECT COUNT(*) FROM favorite_products WHERE user_id={$uids['cust']}")->fetchColumn() === FAVORITES_MAX);
[$st, $d] = http('POST', $api, ['product_ids' => [$pids[2]]], $jCust);
ok('한도 도달 상태에서 이미 등록된 상품 → already(실패 아님)', $st === 200 && $d['already'] === [$pids[2]] && $d['failed'] === []);
[$st, $d] = http('POST', $api, ['product_id' => $rest[count($rest) - 1]], $jCust);
ok('단건 POST도 한도 50 유지 → 400', $st === 400);

echo "[저장 실패 후 재시도]\n";
http('DELETE', $api, ['product_id' => $pids[9]], $jCust);
http('DELETE', $api, ['product_id' => $pids[10]], $jCust);
[$st] = http('POST', $api, ['product_ids' => 'x'], $jCust);
[$st2, $d] = http('POST', $api, ['product_ids' => [$pids[9], $pids[10]]], $jCust);
ok('잘못된 요청(400) 뒤 같은 세션으로 재시도 → 저장', $st === 400 && $st2 === 200 && count($d['added']) === 2);

echo "[마이페이지·상품 목록 화면]\n";
[$st, , $html] = http('GET', "{$base}/mypage.php?tab=favorites", null, $jCust);
ok('개인회원 마이페이지: 기업회원 전용 안내 없음 + 목록 영역 있음', $st === 200
    && strpos($html, '기업회원에게 제공되는 기능입니다') === false && strpos($html, 'id="fav-list-wrap"') !== false);
ok('개인회원 마이페이지: 즉시견적(기업 전용) 링크 없음', strpos($html, 'btn-to-quick') === false);
ok('마이페이지: 카테고리 탭 4개(아이콘·role=tab) + 자주찾는 품목 활성 + 관심상품 총 개수 영역',
    substr_count($html, 'role="tab"') === 4 && strpos($html, 'mp-tab-favorites active') !== false
    && substr_count($html, 'class="mp-tab-ico"') === 4 && strpos($html, 'id="fav-total"') !== false);
[, , $html] = http('GET', "{$base}/mypage.php?tab=favorites", null, $jCorp);
ok('기업회원 마이페이지: 목록 + 즉시견적 링크 유지', strpos($html, 'id="fav-list-wrap"') !== false && strpos($html, 'btn-to-quick') !== false);
[, , $html] = http('GET', "{$base}/estimate-quick.php", null, $jCust);
ok('즉시견적 화면은 여전히 기업회원 전용(개인회원에게 단골품목 영역 없음)', strpos($html, 'id="fav-list"') === false);
[, , $html] = http('GET', "{$base}/estimate-quick.php", null, $jCorp);
ok('기업회원 즉시견적 단골품목 영역 유지', strpos($html, 'id="fav-list"') !== false);
[$st, $d] = http('POST', "{$base}/api/estimate/quick-items.php", ['action' => 'list'], $jCust);
ok('즉시견적 API 권한 그대로(개인회원 403)', $st === 403);

[$st, , $html] = http('GET', "{$base}/products.php?category=9201");
ok('비로그인 상품 목록: 관심품목 추가하기 버튼 표시 + data-logged-in=0', $st === 200
    && strpos($html, 'id="pt-fav-start"') !== false && strpos($html, 'data-logged-in="0"') !== false);
ok('체크박스 열은 마크업만 있고 기본 상태 숨김(CSS)', substr_count($html, 'class="pt-fav-chk"') === 55 && strpos($html, 'class="pt-col-check"') !== false);
ok('선택 모드 버튼은 hidden 으로 시작, 완료 버튼 disabled', preg_match('/id="pt-fav-done" hidden disabled/', $html) === 1
    && preg_match('/id="pt-fav-cancel" hidden/', $html) === 1);
ok('재고 필터·필터 칩 영역·정렬·목록검색 유지', strpos($html, 'name="instock"') !== false && strpos($html, '적용된 필터: 없음') !== false
    && strpos($html, 'class="pt-sort"') !== false && strpos($html, 'placeholder="목록검색"') !== false);
ok('행 클릭 상세 이동 유지', strpos($html, "location.href='/product.php?id={$pids[1]}'") !== false);
[, , $html] = http('GET', "{$base}/products.php?category=9201", null, $jCust);
ok('로그인 상품 목록: data-logged-in=1', strpos($html, 'data-logged-in="1"') !== false);
[, , $html] = http('GET', "{$base}/products.php?category=9201&instock=1&s=" . urlencode('없는목록검색어zz'));
ok('결과 0건이면 관심품목 버튼·체크박스 없음', strpos($html, 'id="pt-fav-start"') === false && strpos($html, 'pt-fav-chk') === false);

echo "[상품 상세: 하위 카테고리 상품 표시 + 관심상품 버튼]\n";
// 하위 카테고리 상품: 상세의 시공사례(category_gallery) 조회 경로. 테이블이 없는 DB에서도 500 없이 표시돼야 함
// (목록 55개 검증에 섞이지 않도록 목록 확인 뒤에 추가)
$pdo->exec("INSERT INTO categories (id, parent_id, name, slug, sort_order, is_active) VALUES (9202, 9201, '[MOCK] 관심상품테스트 하위', 'mock-fav-sub', 0, 1)");
$pdo->exec("INSERT INTO products (category_id, name, spec, price_b2c, price_b2b, stock_qty, is_active, external_source, external_id)
            VALUES (9202, '[MOCK] 관심상품테스트 하위상품', '규격: 400*400*2000 | 단위: 본 | 중량: 100kg', 30000, 25000, 1, 1, 'MOCK', 'fav-sub')");
$subId  = (int) $pdo->lastInsertId();
$detail = static function (int $id, ?string $jar = null) use ($base): array { return http('GET', "{$base}/product.php?id={$id}", null, $jar); };
$btnRe  = static function (int $id, bool $added, string $loggedIn): string {
    return '/class="pd-fav-btn' . ($added ? ' is-added' : '') . '" id="pd-fav-btn"\\s+data-pid="' . $id . '" data-logged-in="' . $loggedIn . '">'
        . ($added ? '관심상품에 추가됨' : '관심상품에 추가') . '<\\/button>/u';
};
[$st, , $html] = $detail($subId);
ok('비로그인 하위 카테고리 상품 상세 → 200 + 페이지 끝까지 출력', $st === 200 && strpos($html, '[MOCK] 관심상품테스트 하위상품') !== false && strpos($html, '</html>') !== false);
ok('비로그인 상세: 관심상품에 추가(기본 상태) + data-logged-in=0', preg_match($btnRe($subId, false, '0'), $html) === 1);
ok('상세 기존 구조 유지(가격·규격·견적 버튼·시공사례 준비 중 안내)', strpos($html, 'pd-price-row') !== false && strpos($html, '<span class="pd-spec-key">규격</span>') !== false
    && strpos($html, '견적에 넣기') !== false && strpos($html, '시공사례 이미지를 준비 중입니다.') !== false);
[$st] = $detail(999999999);
ok('존재하지 않는 상품 id → 기존대로 홈으로 이동(302)', $st === 302);
[$st] = $detail($inactiveId);
ok('판매중지 상품 id → 기존대로 홈으로 이동(302)', $st === 302);

[$st, , $html] = $detail($subId, $jCorp2);
ok('기업회원B 상세(미등록): 기본 상태 + data-logged-in=1', $st === 200 && preg_match($btnRe($subId, false, '1'), $html) === 1);
[$st, $d] = http('POST', $api, ['product_ids' => [$subId]], $jCorp2);
ok('상세 버튼과 같은 요청({product_ids:[id]}) → added', $st === 200 && ($d['added'] ?? null) === [$subId]);
[$st, , $html] = $detail($subId, $jCorp2);
ok('등록 후 상세 진입 → 관심상품에 추가됨 상태', $st === 200 && preg_match($btnRe($subId, true, '1'), $html) === 1);
[$st, $d] = http('POST', $api, ['product_ids' => [$subId]], $jCorp2);
ok('다시 눌러도 already, DB 1행', ($d['already'] ?? null) === [$subId]
    && (int) $pdo->query("SELECT COUNT(*) FROM favorite_products WHERE user_id={$uids['corp2']} AND product_id={$subId}")->fetchColumn() === 1);
[, $d] = http('GET', $api, null, $jCorp2);
ok('마이페이지 목록에 하위 상품 포함 + 상품보기 URL 형식(/product.php?id=상품ID) 유지', in_array($subId, $favIds($d), true)
    && strpos((string) file_get_contents(__DIR__ . '/../public/assets/mypage.js'), "'/product.php?id='+f.product_id") !== false);

[$st, , $html] = $detail($pids[1], $jCorp);
ok('기업회원A: 기존 등록 상품(수량 7) 상세 → 추가됨 상태', $st === 200 && preg_match($btnRe($pids[1], true, '1'), $html) === 1);
http('POST', $api, ['product_ids' => [$pids[1]]], $jCorp);
ok('상세에서 다시 눌러도 기존 수량 7 유지', (int) $pdo->query("SELECT quantity FROM favorite_products WHERE user_id={$uids['corp']} AND product_id={$pids[1]}")->fetchColumn() === 7);

[$st, , $html] = $detail($pids[2], $jCust);
ok('일반회원: 등록 상품 상세 → 추가됨 상태', $st === 200 && preg_match($btnRe($pids[2], true, '1'), $html) === 1);
[$st, , $html] = $detail($subId, $jCust);
ok('일반회원: 미등록 하위 상품 상세 → 기본 상태', $st === 200 && preg_match($btnRe($subId, false, '1'), $html) === 1);
[$st, $d] = http('POST', $api, ['product_ids' => [$subId]], $jCust);
ok('일반회원 50개 상태에서 상세 추가 → failed(limit), 50개 유지', $st === 200 && array_column($d['failed'] ?? [], 'reason') === ['limit']
    && (int) $pdo->query("SELECT COUNT(*) FROM favorite_products WHERE user_id={$uids['cust']}")->fetchColumn() === FAVORITES_MAX);

foreach ([$jCust, $jCorp, $jCorp2] as $j) { @unlink($j); }
echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
