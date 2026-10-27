<?php
declare(strict_types=1);
/**
 * 검색 자동완성(검색(After)) 검증 (scripts/testdb/verify-suggest.php) — 로컬 '_test' DB + 로컬 웹 서버 대상
 *
 * 기준값은 앱 함수와 따로 계산(재귀 CTE·SQL 문자열 함수)하고, 실제 API(HTTP GET) 응답과 비교한다.
 *  0. 환경: DB 버전·SQL 모드, 카테고리·상품 수, 분류 깊이별 개수, 고아·순환·비활성 상위 분류
 *  1. SQL 실행: public/api/suggest.php 와 같은 SQL을 대표 검색어로 직접 실행(오류 여부) — API는 오류를 삼키므로 별도 확인
 *  2. 카테고리 경로: 활성 카테고리 전체의 category_path_label() = 재귀 CTE 경로(최상위 '제품' 접미사 제거)
 *     + 활성 상품 0개 카테고리도 검색 결과에 나오는지(전체 카테고리 정책)
 *  3. 규격 N종·사진: 활성 상품명 전체의 spec_variant_count() = SQL '규격:' 값 DISTINCT, 첫 사진 = id 순 첫 비어있지 않은 image_url
 *  4. API 응답 구조: 입력 전(popular)·입력 중(items·terms) 필드·타입이 search-suggest.js 사용 필드와 일치 + 값이 2·3의 기준값과 일치
 *  5. 기업회원 메인: .env.local 의 LOCAL_TEST_CORPORATE_* 로 로그인 → 메인에 검색창(#ch-q data-suggest)·위젯 스크립트·헤더 검색 존재
 * 사용: php scripts/testdb/verify-suggest.php [http://localhost:8080]
 * 안전장치: common.php 가드(로컬 '_test' DB만). DB는 SELECT만, 웹은 GET + 5번의 테스트 계정 로그인 POST만.
 */

require __DIR__ . '/common.php';

$base = rtrim($argv[1] ?? 'http://localhost:8080', '/');
if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) {
    tdb_stop('로컬 웹 서버 주소만 허용합니다.');
}
$pdo = tdb_test_pdo();

$fail = 0; $pass = 0; $warn = 0;
function chk(string $name, bool $ok, string $detail = ''): bool
{
    global $fail, $pass;
    $ok ? $pass++ : $fail++;
    tdb_line(($ok ? '  ✓ ' : '  ✗ ') . $name . ($detail !== '' ? " — {$detail}" : ''));
    return $ok;
}
function note(string $s): void { global $warn; $warn++; tdb_line("  ⚠ {$s}"); }
function http(string $url, ?string $cookie = null, ?string $post = null): array
{
    $hdr = "Accept: application/json\r\n" . ($cookie ? "Cookie: {$cookie}\r\n" : '');
    $opt = ['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 20]];
    if ($post !== null) { $opt['http']['header'] .= "Content-Type: application/json\r\n"; $opt['http']['content'] = $post; }
    $body = @file_get_contents($url, false, stream_context_create($opt));
    if ($body === false) { tdb_stop("요청 실패: {$url} — 서버(php -S)가 실행 중인지 확인하세요."); }
    return [$body, $http_response_header ?? []];
}
function api(string $base, string $q): array
{
    [$body] = http($base . '/api/suggest.php?q=' . rawurlencode($q));
    $d = json_decode($body, true);
    if (!is_array($d)) { tdb_stop("API 응답이 JSON이 아님(q={$q}): " . mb_substr($body, 0, 200)); }
    return $d;
}
function short(array $list, int $n = 5): string
{
    return implode(', ', array_slice($list, 0, $n)) . (count($list) > $n ? ' 외 ' . (count($list) - $n) . '건' : '');
}

// ── 0. 환경 ─────────────────────────────────────────────
tdb_line('[0] 환경');
$ver  = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
$mode = (string) $pdo->query('SELECT @@sql_mode')->fetchColumn();
tdb_line("  DB {$ver}  sql_mode={$mode}");
if (stripos($ver, 'mariadb') !== false) {
    note('MariaDB(XAMPP)입니다. 운영은 MySQL 8.0 — 같은 SQL은 이 세션에서 MySQL 8.0.46 컨테이너로도 실행 확인함(보고서 참고).');
}
$tree = $pdo->query('SELECT id, parent_id, name, is_active FROM categories')->fetchAll(PDO::FETCH_UNIQUE);
$activeCats = array_keys(array_filter($tree, static fn($c) => (int) $c['is_active'] === 1));
$prodActive = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1')->fetchColumn();
tdb_line('  카테고리 ' . count($tree) . '개(활성 ' . count($activeCats) . '), 활성 상품 ' . $prodActive . '개');
chk('카테고리·상품 데이터 있음', count($activeCats) > 0 && $prodActive > 0, '없으면 2~4번 비교가 의미 없음');

// 깊이·고아·순환·비활성 상위 (PHP로 부모 체인 추적 — 2번의 SQL 기준값과 별개)
$depth = []; $orphans = []; $cycles = []; $inactiveAnc = [];
foreach ($tree as $id => $c) {
    $d = 1; $cur = $id; $seen = [$id => 1];
    while ($tree[$cur]['parent_id'] !== null) {
        $p = (int) $tree[$cur]['parent_id'];
        if (!isset($tree[$p])) { $orphans[] = $id; break; }
        if (isset($seen[$p])) { $cycles[] = $id; break; }
        if ((int) $c['is_active'] === 1 && (int) $tree[$p]['is_active'] !== 1) { $inactiveAnc[$id] = 1; }
        $seen[$p] = 1; $cur = $p; $d++;
    }
    $depth[$d] = ($depth[$d] ?? 0) + 1;
}
ksort($depth);
tdb_line('  분류 깊이별 개수: ' . implode(', ', array_map(static fn($k, $v) => "{$k}단계 {$v}", array_keys($depth), $depth)));
chk('고아 카테고리(부모 id 없음) 0', !$orphans, $orphans ? short($orphans) : '');
chk('부모 순환 0', !$cycles, $cycles ? short($cycles) : '');
if ($inactiveAnc) {
    note('상위 분류가 비활성인 활성 카테고리 ' . count($inactiveAnc) . '개 — 경로에 비활성 분류 이름도 표시됨: '
        . short(array_map(static fn($i) => "{$i}:{$tree[$i]['name']}", array_keys($inactiveAnc))));
}

// ── 1. SQL 실행 (suggest.php 와 같은 SQL) ─────────────────
tdb_line('[1] SQL 실행 (suggest.php 와 같은 문장, 대표 검색어)');
$terms = ['수로', '그레이팅', '맨홀', '경계석', '블록', 'PE', 'D300', '300*300'];
// 데이터에서 뽑은 검색어(카테고리명 앞 2글자) 추가
foreach (array_slice($activeCats, 0, 400) as $i => $cid) {
    if ($i % 25 === 0) { $terms[] = mb_substr((string) $tree[$cid]['name'], 0, 2); }
}
$terms = array_values(array_unique(array_filter($terms, static fn($t) => $t !== '')));
$sqlErr = [];
$stProd = $pdo->prepare(
    'SELECT name, COUNT(*) AS cnt FROM products WHERE is_active = 1 AND (name LIKE ?) GROUP BY name
     ORDER BY (name LIKE ?) DESC, cnt DESC, name LIMIT 20'
);
$stCat = $pdo->prepare(
    'SELECT id, name FROM categories WHERE is_active = 1 AND name LIKE ? ORDER BY (name LIKE ?) DESC, name LIMIT 10'
);
$sqlHits = [];
foreach ($terms as $t) {
    try {
        $stProd->execute(['%' . $t . '%', $t . '%']);
        $names = array_column($stProd->fetchAll(), 'name');
        if ($names) {
            $st = $pdo->prepare('SELECT name, spec, image_url FROM products WHERE is_active = 1 AND name IN ('
                . implode(',', array_fill(0, count($names), '?')) . ') ORDER BY id');
            $st->execute($names);
            $st->fetchAll();
        }
        $stCat->execute(['%' . $t . '%', $t . '%']);
        $sqlHits[$t] = [count($names), count($stCat->fetchAll())];
    } catch (\Throwable $e) {
        $sqlErr[] = "{$t}: " . $e->getMessage();
    }
}
try { $pdo->query('SELECT id, parent_id, name FROM categories')->fetchAll(PDO::FETCH_UNIQUE); }
catch (\Throwable $e) { $sqlErr[] = '트리 조회: ' . $e->getMessage(); }
try { $pdo->query('SELECT term FROM search_logs ORDER BY cnt DESC, updated_at DESC LIMIT 10')->fetchAll(); }
catch (\Throwable $e) { $sqlErr[] = '인기 검색어: ' . $e->getMessage(); }
chk('검색어 ' . count($terms) . '개 × 상품·규격·카테고리·트리·인기 SQL 오류 0', !$sqlErr, $sqlErr ? short($sqlErr, 3) : '');

// ── 2. 카테고리 경로 ────────────────────────────────────
tdb_line('[2] 카테고리 경로 (활성 카테고리 전체)');
// 기준값: 최상위부터 내려가는 재귀 CTE. 최상위 '제품' 접미사 제거. 고아·순환은 최상위에 닿지 않아 빠짐(0번에서 따로 확인)
$cte = $pdo->query(
    "WITH RECURSIVE t AS (
        SELECT id, CAST(CASE WHEN name LIKE '%제품' THEN LEFT(name, CHAR_LENGTH(name) - 2) ELSE name END AS CHAR(2000)) AS path, 1 AS lv
        FROM categories WHERE parent_id IS NULL
        UNION ALL
        SELECT c.id, CONCAT(t.path, ' › ', c.name), t.lv + 1 FROM categories c JOIN t ON c.parent_id = t.id WHERE t.lv < 30
     ) SELECT id, path FROM t"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$treeForLabel = array_map(static fn($c) => ['parent_id' => $c['parent_id'], 'name' => $c['name']], $tree);
$pathBad = [];
foreach ($activeCats as $cid) {
    if (!isset($cte[$cid])) { continue; }  // 고아·순환(0번에서 보고)
    $got = category_path_label((int) $cid, $treeForLabel);
    if ($got !== $cte[$cid]) { $pathBad[] = "{$cid}: '{$got}' ≠ '{$cte[$cid]}'"; }
}
chk('활성 카테고리 ' . count($activeCats) . '개 경로 = 재귀 CTE 기준값', !$pathBad, $pathBad ? short($pathBad, 3) : '');
$sample = array_slice(array_values(array_filter($activeCats, static fn($i) => isset($cte[$i]) && substr_count($cte[$i], ' › ') >= 2)), 0, 3);
foreach ($sample as $cid) { tdb_line("    예) {$tree[$cid]['name']} → {$cte[$cid]}"); }

// 활성 상품 0개 카테고리도 검색 결과에 포함(전체 카테고리 정책)
$zero = $pdo->query(
    'SELECT c.id, c.name FROM categories c
     WHERE c.is_active = 1 AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = c.id AND p.is_active = 1)
     ORDER BY c.id'
)->fetchAll(PDO::FETCH_KEY_PAIR);
tdb_line('  활성 상품 0개(직접 연결 기준) 활성 카테고리: ' . count($zero) . '개');
$zeroMiss = []; $zeroTried = 0;
foreach ($zero as $cid => $cname) {
    if ($zeroTried >= 15) { break; }
    // 같은 글자를 포함한 활성 카테고리가 10개 넘으면 상한(LIMIT 10)에 밀릴 수 있어 그런 이름은 건너뜀
    $st = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE is_active = 1 AND name LIKE ?');
    $st->execute(['%' . $cname . '%']);
    if ((int) $st->fetchColumn() > 10 || mb_strlen((string) $cname) > 40) { continue; }
    $zeroTried++;
    $ids = array_map(static fn($it) => $it['url'] ?? '', array_filter(api($base, (string) $cname)['items'] ?? [], static fn($it) => ($it['type'] ?? '') === 'category'));
    if (!in_array('/products.php?category=' . $cid, $ids, true)) { $zeroMiss[] = "{$cid}:{$cname}"; }
}
chk("상품 0개 카테고리 {$zeroTried}개를 이름으로 검색 → 모두 카테고리 결과에 포함", !$zeroMiss, $zeroMiss ? short($zeroMiss) : '');

// ── 3. 규격 N종·사진 ────────────────────────────────────
tdb_line('[3] 규격 N종·사진 (활성 상품명 전체)');
// 기준값: '|'로 나눈 항목 중 '규격:'으로 시작하는 항목의 값(앞뒤 공백 제거) — SQL 문자열 함수로 계산
//  spec 앞에 '|'를 붙여 '|규격:' 위치를 찾고(항목 시작), 다음 '|' 전까지를 값으로 본다. 공백 '| 규격:' 형식도 처리
$specSql = $pdo->query(
    "SELECT name, COUNT(DISTINCT NULLIF(TRIM(SUBSTRING_INDEX(SUBSTRING(s, LOCATE('|규격:', s) + 4), '|', 1)), '')) AS n
     FROM (SELECT name, REPLACE(REPLACE(CONCAT('|', COALESCE(spec, '')), '|  ', '|'), '| ', '|') AS s
           FROM products WHERE is_active = 1) x
     WHERE LOCATE('|규격:', s) > 0
     GROUP BY name"
)->fetchAll(PDO::FETCH_KEY_PAIR);
$oldSql = $pdo->query('SELECT name, COUNT(DISTINCT spec) FROM products WHERE is_active = 1 GROUP BY name')->fetchAll(PDO::FETCH_KEY_PAIR);
$rowsByName = [];
foreach ($pdo->query('SELECT name, spec, image_url FROM products WHERE is_active = 1 ORDER BY id') as $r) {
    $rowsByName[mb_strtolower(rtrim((string) $r['name']))][] = $r;
}
$specBad = []; $oldDiff = 0; $firstImg = [];
foreach ($oldSql as $name => $oldN) {
    $k = mb_strtolower(rtrim((string) $name));
    $rows = $rowsByName[$k] ?? [];
    $got = spec_variant_count(array_column($rows, 'spec'));
    $want = (int) ($specSql[$name] ?? 0);
    if ($got !== $want) { $specBad[] = "{$name}: 앱 {$got} ≠ SQL {$want}"; }
    if ($got !== (int) $oldN) { $oldDiff++; }
    foreach ($rows as $r) { if ((string) $r['image_url'] !== '') { $firstImg[$k] = (string) $r['image_url']; break; } }
}
chk('상품명 ' . count($oldSql) . '개 규격 종류 수 = SQL 기준값', !$specBad, $specBad ? short($specBad, 3) : '');
tdb_line("  참고: 수정 전 방식(spec 전체 문자열 DISTINCT)과 숫자가 달랐던 상품명 {$oldDiff}개");
$noSpec = (int) $pdo->query("SELECT COUNT(*) FROM products WHERE is_active = 1 AND (spec IS NULL OR spec NOT LIKE '%규격:%')")->fetchColumn();
tdb_line("  '규격:' 항목이 없는 활성 상품 {$noSpec}개(이 상품만 있는 이름은 '규격 N종' 표시 안 함)");
$img = $pdo->query(
    "SELECT SUM(image_url IS NULL OR image_url = '') AS none,
            SUM(image_url LIKE 'http://%' OR image_url LIKE 'https://%') AS abs,
            SUM(image_url LIKE '/%') AS root,
            COUNT(*) AS total
     FROM products WHERE is_active = 1"
)->fetch();
$other = (int) $img['total'] - (int) $img['none'] - (int) $img['abs'] - (int) $img['root'];
tdb_line("  사진: 없음 {$img['none']}, http(s) {$img['abs']}, '/'로 시작 {$img['root']}, 기타 {$other} (총 {$img['total']})");
if ($other > 0) { note("image_url 이 상대경로인 상품 {$other}개 — 페이지 위치에 따라 사진이 깨질 수 있음(상품 목록 화면과 같은 값 사용)"); }

// ── 4. API 응답 구조 + 값 ───────────────────────────────
tdb_line('[4] API 응답 구조 (search-suggest.js 사용 필드) + 값');
$d0 = api($base, '');
$popOk = ($d0['ok'] ?? null) === true && is_array($d0['popular'] ?? null) && count($d0['popular']) <= 10;
foreach ($d0['popular'] ?? [] as $p) {
    $popOk = $popOk && ($p['type'] ?? '') === 'popular' && is_string($p['name'] ?? null) && $p['name'] !== ''
        && is_string($p['url'] ?? null) && str_starts_with($p['url'], '/products.php?q=');
}
chk('입력 전: ok=true, popular[] ≤10, 각 {type:popular, name, url}', $popOk, 'popular ' . count($d0['popular'] ?? []) . '개');
if (!($d0['popular'] ?? [])) { note('인기 검색어 0개 — search_logs 가 비어 있으면 정상(화면엔 최근 검색어만 표시)'); }

$structBad = []; $valBad = []; $silent = []; $checked = 0;
foreach ($terms as $t) {
    $d = api($base, $t);
    $items = $d['items'] ?? null;
    if (($d['ok'] ?? null) !== true || !is_array($items) || !is_array($d['terms'] ?? null)) { $structBad[] = "{$t}: ok/items/terms"; continue; }
    [$wantP, $wantC] = $sqlHits[$t] ?? [0, 0];
    $prods = array_values(array_filter($items, static fn($it) => ($it['type'] ?? '') === 'product'));
    $cats  = array_values(array_filter($items, static fn($it) => ($it['type'] ?? '') === 'category'));
    // 동의어 확장으로 API가 더 많을 수는 있어도, SQL에 결과가 있는데 API가 0이면 오류를 삼킨 것
    if (($wantP > 0 && !$prods) || ($wantC > 0 && !$cats)) { $silent[] = "{$t}: SQL 상품{$wantP}·카테고리{$wantC} / API 상품" . count($prods) . '·카테고리' . count($cats); }
    if (count($prods) > 20 || count($cats) > 10) { $structBad[] = "{$t}: 개수 상한 초과"; }
    foreach ($prods as $it) {
        $checked++;
        if (!is_string($it['name'] ?? null) || !is_int($it['cnt'] ?? null) || !is_int($it['specs'] ?? null)
            || !is_string($it['img'] ?? null) || !is_string($it['url'] ?? null)) { $structBad[] = "{$t}: 상품 필드 " . json_encode($it, JSON_UNESCAPED_UNICODE); continue; }
        $k = mb_strtolower(rtrim($it['name']));
        $wantS = (int) ($specSql[$it['name']] ?? 0);
        if ($it['specs'] !== $wantS) { $valBad[] = "{$it['name']} 규격 {$it['specs']}≠{$wantS}"; }
        if ($it['img'] !== ($firstImg[$k] ?? '')) { $valBad[] = "{$it['name']} 사진 불일치"; }
    }
    foreach ($cats as $it) {
        $checked++;
        if (!is_string($it['name'] ?? null) || !is_string($it['path'] ?? null) || !is_string($it['url'] ?? null)
            || !preg_match('#^/products\.php\?category=(\d+)$#', $it['url'], $m)) { $structBad[] = "{$t}: 카테고리 필드"; continue; }
        if ($it['path'] !== ($cte[(int) $m[1]] ?? '')) { $valBad[] = "{$m[1]} 경로 '{$it['path']}'≠'" . ($cte[(int) $m[1]] ?? '') . "'"; }
    }
}
chk('입력 중: ok·items[]·terms[], 상품 {name,cnt,specs,img,url}·카테고리 {name,path,url}, 상한 20/10', !$structBad, $structBad ? short($structBad, 3) : "항목 {$checked}개");
chk('API 값 = 기준값(규격 N종·사진·경로)', !$valBad, $valBad ? short($valBad, 3) : '');
chk('SQL 결과가 있는 검색어에서 API가 빈 목록을 주지 않음(오류 삼킴 없음)', !$silent, $silent ? short($silent, 3) : '');

// ── 5. 기업회원 메인 ────────────────────────────────────
tdb_line('[5] 기업회원 메인 검색창');
$email = (string) getenv('LOCAL_TEST_CORPORATE_EMAIL');
$pw    = (string) getenv('LOCAL_TEST_CORPORATE_PASSWORD');
if ($email === '' || $pw === '') {
    note('.env.local 에 LOCAL_TEST_CORPORATE_EMAIL/PASSWORD 가 없어 건너뜀 — php scripts/local-test-accounts.php 참고');
} else {
    [$body, $hdr] = http($base . '/api/auth/login.php', null, json_encode(['email' => $email, 'password' => $pw]));
    $cookie = '';
    foreach ($hdr as $h) { if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m)) { $cookie = $m[1]; } }
    $login = json_decode($body, true);
    if (chk('테스트 기업회원 로그인', ($login['ok'] ?? false) === true && $cookie !== '', (string) ($login['error'] ?? ''))) {
        [$html] = http($base . '/', $cookie);
        chk('기업회원 메인(mode-corp) 표시', str_contains($html, 'class="mode-corp"'));
        chk('메인 검색창 #ch-q 에 data-suggest', (bool) preg_match('#<input[^>]*id="ch-q"[^>]*data-suggest#', $html));
        chk('자동완성 스크립트 search-suggest.js 포함', str_contains($html, '/assets/search-suggest.js'));
        chk('헤더 검색 줄(X 버튼·어둡게) 포함', str_contains($html, 'header-search-x') && str_contains($html, 'id="header-search-dim"'));
        chk('PHP 경고·오류 문구 없음', !preg_match('#<b>(Warning|Fatal error|Notice|Deprecated)</b>#', $html));
        http($base . '/api/auth/logout.php', $cookie, '{}');
    }
}

tdb_line();
tdb_line("결과: {$pass} 통과 / {$fail} 실패 / 참고 {$warn}");
exit($fail > 0 ? 1 : 0);
