<?php
declare(strict_types=1);
/**
 * 상품 목록 상단 배너 검증 (scripts/testdb/verify-list-banner.php) — 운영 복제 개발 DB + 로컬 웹 서버 대상
 *
 * 기준값은 앱 함수가 아닌 독립 SQL(재귀 CTE 등)로 계산하고, 실제 페이지(HTTP GET) 배너와 비교한다.
 *  1. 카테고리 진입(products.php?category=ID) 전체: 위치 '홈 › 상위 경로'(최상위 '제품' 접미사 제거)·제목·설명·품목 사진,
 *     시공사례 갤러리 없음, 관련 제품(동종·공정연결) 줄은 활성 관계가 있을 때만, 제목 h1 1개
 *  2. 상황별 진입(category=ID&work=키) 12개 작업 × 대표 자재: '홈 › 상황별 자재 › 작업명'·제목·설명·사진 종류(현장/자재)
 *  3. 비로그인·개인회원·기업회원([TEST] 계정) 모두 같은 배너, PHP 경고·오류 문구 없음
 * 사용: php scripts/testdb/verify-list-banner.php [http://127.0.0.1:8080]   (devdb.sh verify 에 포함)
 * 안전장치: common.php 가드(로컬 '_test' DB만). DB는 SELECT만, 웹은 GET(+ 테스트 계정 로그인·로그아웃)만.
 */

require __DIR__ . '/common.php';
require __DIR__ . '/../../app/work_types.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) {
    tdb_stop('로컬 웹 서버 주소만 허용합니다.');
}
$pdo = tdb_test_pdo();

$fail = 0; $pass = 0;
function chk(string $name, bool $ok, string $detail = ''): bool
{
    global $fail, $pass;
    $ok ? $pass++ : $fail++;
    if (!$ok) { tdb_line("  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '')); }
    return $ok;
}
function http(string $url, ?string $cookie = null, ?string $post = null): array
{
    $hdr = $cookie ? "Cookie: {$cookie}\r\n" : '';
    $opt = ['http' => ['method' => $post === null ? 'GET' : 'POST', 'header' => $hdr, 'ignore_errors' => true, 'timeout' => 20]];
    if ($post !== null) { $opt['http']['header'] .= "Content-Type: application/json\r\n"; $opt['http']['content'] = $post; }
    $body = @file_get_contents($url, false, stream_context_create($opt));
    if ($body === false) { tdb_stop("요청 실패: {$url} — 서버(php -S)가 실행 중인지 확인하세요."); }
    return [$body, $http_response_header ?? []];
}
function login(string $base, string $role): ?string
{
    $email = (string) getenv('LOCAL_TEST_' . $role . '_EMAIL');
    $pw    = (string) getenv('LOCAL_TEST_' . $role . '_PASSWORD');
    if ($email === '' || $pw === '') { return null; }
    [$body, $hdr] = http($base . '/api/auth/login.php', null, json_encode(['email' => $email, 'password' => $pw]));
    $cookie = '';
    foreach ($hdr as $h) { if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $h, $m)) { $cookie = $m[1]; } }
    return ((json_decode($body, true)['ok'] ?? false) === true && $cookie !== '') ? $cookie : null;
}
/** 페이지의 첫 배너(.ut-banner) → [path, title, sub, photo, side, h1수, 갤러리, 관련줄, 경고] */
function banner(string $html): array
{
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    $dom->loadHTML('<?xml encoding="utf-8"?>' . $html);
    libxml_clear_errors();
    $x = new DOMXPath($dom);
    $q = static function (string $path) use ($x): ?DOMNode { $n = $x->query($path); return $n && $n->length ? $n->item(0) : null; };
    $txt = static fn(?DOMNode $n): ?string => $n ? trim((string) preg_replace('/\s+/u', ' ', $n->textContent)) : null;
    $src = static fn(?DOMNode $n): ?string => $n instanceof DOMElement ? $n->getAttribute('src') : null;
    $b = "//section[contains(concat(' ', normalize-space(@class), ' '), ' ut-banner ')]";
    return [
        'count'   => $x->query($b)->length,
        'path'    => $txt($q("$b//nav[contains(@class,'ut-banner-path')]")),
        'home'    => ($a = $q("$b//nav[contains(@class,'ut-banner-path')]/a")) instanceof DOMElement ? $a->getAttribute('href') : null,
        'title'   => $txt($q("$b//h1[contains(@class,'ut-banner-title')]")),
        'sub'     => $txt($q("$b//p[contains(@class,'ut-banner-sub')]")),
        'photo'   => $src($q("$b//div[contains(@class,'ut-banner-photo')]/img")),
        'side'    => $src($q("$b//img[contains(@class,'ut-banner-side')]")),
        'has_photo_cls' => (bool) $q("//section[contains(@class,'ut-banner') and contains(@class,'has-photo')]"),
        'h1'      => $x->query('//h1')->length,
        'gallery' => str_contains($html, 'pt-gallery') || str_contains($html, 'gallery-modal'),
        'related' => (bool) $q("//div[contains(@class,'ut-related')]"),
        'warn'    => (bool) preg_match('#<b>(Warning|Fatal error|Notice|Deprecated)</b>#', $html),
    ];
}

// ── 기준값(독립 SQL) ────────────────────────────────────
$cats = [];
foreach ($pdo->query('SELECT id, parent_id, name, description FROM categories ORDER BY id') as $r) { $cats[(int) $r['id']] = $r; }
// 위치: 재귀 CTE 로 조상 경로(자기 제외). 최상위 이름만 '제품' 접미사 제거
$paths = [];
$st = $pdo->query(
    "WITH RECURSIVE up AS (
        SELECT id AS leaf, parent_id AS anc, 1 AS lvl FROM categories WHERE parent_id IS NOT NULL
        UNION ALL
        SELECT up.leaf, c.parent_id, up.lvl + 1 FROM up JOIN categories c ON c.id = up.anc WHERE c.parent_id IS NOT NULL AND up.lvl < 10
     )
     SELECT up.leaf, GROUP_CONCAT(CASE WHEN a.parent_id IS NULL THEN REGEXP_REPLACE(a.name, '제품$', '') ELSE a.name END
                                  ORDER BY up.lvl DESC SEPARATOR ' › ') AS path
       FROM up JOIN categories a ON a.id = up.anc GROUP BY up.leaf"
);
foreach ($st as $r) { $paths[(int) $r['leaf']] = (string) $r['path']; }
// 품목 사진: 직접 연결된 판매중 상품 중 사진 있는 첫 상품(ID 순)
$imgs = [];
foreach ($pdo->query("SELECT category_id, image_url FROM products p
                       WHERE is_active = 1 AND image_url IS NOT NULL AND image_url <> ''
                         AND id = (SELECT MIN(id) FROM products q WHERE q.category_id = p.category_id AND q.is_active = 1
                                     AND q.image_url IS NOT NULL AND q.image_url <> '')") as $r) {
    $imgs[(int) $r['category_id']] = (string) $r['image_url'];
}
// 관련 제품: 활성 관계(SIMILAR·NEXT_STEP)가 있는 카테고리
$rel = [];
foreach ($pdo->query("SELECT DISTINCT source_category_id FROM category_relations WHERE is_active = 1 AND relation_type IN ('SIMILAR','NEXT_STEP')") as $r) {
    $rel[(int) $r['source_category_id']] = true;
}
// 설명: 자기 설명, 없으면 부모(최상위 11~15 제외) 설명 — 기존 화면 규칙 유지 확인
$desc = static function (int $id) use ($cats): string {
    $d = (string) ($cats[$id]['description'] ?? '');
    $pid = $cats[$id]['parent_id'];
    if ($d === '' && $pid !== null && !in_array((int) $pid, [11, 12, 13, 14, 15], true)) {
        $d = (string) ($cats[(int) $pid]['description'] ?? '');
    }
    return trim((string) preg_replace('/\s+/u', ' ', $d));
};

$roles = ['비로그인' => null];
foreach (['CUSTOMER' => '개인회원', 'CORPORATE' => '기업회원'] as $env => $label) {
    $c = login($base, $env);
    chk("{$label} 테스트 계정 로그인", $c !== null, '.env.local LOCAL_TEST_' . $env . '_* 확인(devdb.sh up)');
    if ($c !== null) { $roles[$label] = $c; }
}

// ── 1. 카테고리 진입 ─────────────────────────────────────
tdb_line('[1] 카테고리 진입 배너 (카테고리 ' . count($cats) . '개 × ' . count($roles) . '개 권한)');
$samples = [];
foreach ($roles as $label => $cookie) {
    $bad0 = $fail;
    foreach ($cats as $id => $c) {
        [$html] = http("{$base}/products.php?category={$id}", $cookie);
        $b = banner($html);
        $tag = "{$label} category={$id}";
        if (!chk("{$tag} 배너 1개", $b['count'] === 1, "배너 {$b['count']}개")) { continue; }
        $wantPath = '홈' . (isset($paths[$id]) && $paths[$id] !== '' ? ' › ' . $paths[$id] : '');
        chk("{$tag} 위치", $b['path'] === $wantPath && $b['home'] === '/index.php', "화면='{$b['path']}' 기준='{$wantPath}'");
        chk("{$tag} 제목", $b['title'] === trim((string) $c['name']), "화면='{$b['title']}'");
        $wd = $desc($id);
        chk("{$tag} 설명", ($b['sub'] ?? '') === $wd, '화면=' . mb_substr((string) $b['sub'], 0, 30) . ' / 기준=' . mb_substr($wd, 0, 30));
        chk("{$tag} 품목 사진", $b['side'] === ($imgs[$id] ?? null) && $b['photo'] === null && !$b['has_photo_cls'], "화면={$b['side']}");
        chk("{$tag} 시공사례 없음", !$b['gallery']);
        chk("{$tag} 관련 제품 줄", $b['related'] === isset($rel[$id]), $b['related'] ? '표시됨' : '없음');
        chk("{$tag} h1 1개", $b['h1'] === 1, "h1 {$b['h1']}개");
        chk("{$tag} PHP 경고 없음", !$b['warn']);
        if ($label === '비로그인' && count($samples) < 3 && isset($paths[$id], $imgs[$id]) && $wd !== '') { $samples[] = "{$id}: {$b['path']} / {$b['title']}"; }
    }
    tdb_line("  {$label}: " . ($fail === $bad0 ? '전부 일치' : ($fail - $bad0) . '건 불일치'));
}
foreach ($samples as $s) { tdb_line("    예) {$s}"); }
tdb_line('  기준: 위치 있는 카테고리 ' . count(array_filter($paths)) . ', 품목 사진 있는 ' . count($imgs) . ', 관련 제품 있는 ' . count($rel));

// ── 2. 상황별 진입 ───────────────────────────────────────
tdb_line('[2] 상황별 진입 배너 (작업 12개 × 대표 자재)');
$n = 0;
foreach ($roles as $label => $cookie) {
    $bad0 = $fail;
    foreach (work_types() as $t) {
        $wantFull = isset($t['banner']) || ($t['img_kind'] ?? '') === 'photo';
        foreach ($t['materials'] as [$mid]) {
            if (!isset($cats[$mid]) || (int) ($pdo->query('SELECT is_active FROM categories WHERE id = ' . (int) $mid)->fetchColumn()) !== 1) { continue; }
            [$html] = http("{$base}/products.php?category={$mid}&work=" . rawurlencode($t['key']), $cookie);
            $b = banner($html);
            $tag = "{$label} {$t['key']}/{$mid}";
            $n++;
            if (!chk("{$tag} 배너 1개", $b['count'] === 1, "배너 {$b['count']}개")) { continue; }
            chk("{$tag} 위치", $b['path'] === '홈 › 상황별 자재 › ' . $t['name'], "화면='{$b['path']}'");
            chk("{$tag} 제목·설명", $b['title'] === $t['name'] && $b['sub'] === $t['summary']);
            if ($wantFull) {
                chk("{$tag} 현장 사진", $b['photo'] === ($t['banner'] ?? $t['img']) && $b['side'] === null && $b['has_photo_cls']);
            } else {
                chk("{$tag} 자재 사진(작게)", $b['photo'] === null && !$b['has_photo_cls'] && $b['side'] !== null && $b['side'] !== '');
            }
            chk("{$tag} 시공사례·관련 줄 없음", !$b['gallery'] && !$b['related']);
            chk("{$tag} PHP 경고 없음", !$b['warn']);
        }
    }
    tdb_line("  {$label}: " . ($fail === $bad0 ? '전부 일치' : ($fail - $bad0) . '건 불일치'));
}
tdb_line("  조합 {$n}건(권한 합계)");

foreach ($roles as $cookie) { if ($cookie) { http($base . '/api/auth/logout.php', $cookie, '{}'); } }
tdb_line();
tdb_line("결과: {$pass} 통과 / {$fail} 실패");
exit($fail > 0 ? 1 : 0);
