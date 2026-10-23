<?php
declare(strict_types=1);
/**
 * 작업별 상품 목록 검증 (scripts/testdb/verify-work-list.php) — 운영 복제 테스트 DB + 로컬 웹 서버 대상
 *
 * 기준값은 앱 함수가 아닌 독립 SQL(재귀 CTE)로 계산하고, 실제 페이지(HTTP GET) 결과와 비교한다.
 *  1. 메인: 작업 유형 12개, 작업별 대표 자재 = 설정 ID 중 DB에 있는 활성 카테고리
 *  2. 작업별 목록(category=ID&work=키) 전 조합: 체크 자재·제목·상품 수·상품 ID 집합(하위 카테고리 포함, 판매중)
 *  3. 필터 패널이 기존 목록(category=ID)과 동일(차이는 work 유지용 hidden 입력·초기화 링크뿐)
 *  4. 필터 옵션 적용: 각 그룹 첫 옵션 적용 시 건수 = 옵션 표시 건수 = 기존 목록 같은 필터 건수
 *  5. 재고 있는 상품만: 건수 = 독립 SQL(stock_qty > 0)
 *  6. 정렬·목록검색: 작업별 목록과 기존 목록의 상품 순서가 같음
 * 사용: php scripts/testdb/verify-work-list.php [http://127.0.0.1:8080]
 * 안전장치: common.php 가드(로컬 '_test' DB만). DB는 SELECT만, 웹은 GET만.
 */

require __DIR__ . '/common.php';
require __DIR__ . '/../../app/work_types.php';

$base = rtrim($argv[1] ?? 'http://127.0.0.1:8080', '/');
if (!preg_match('#^http://(127\.0\.0\.1|localhost)(:\d+)?$#', $base)) {
    tdb_stop('로컬 웹 서버 주소만 허용합니다.');
}
$pdo = tdb_test_pdo();

$fail = 0; $pass = 0;
function chk(string $name, bool $ok, string $detail = ''): void
{
    global $fail, $pass;
    $ok ? $pass++ : $fail++;
    if (!$ok) { tdb_line("  ✗ {$name}" . ($detail !== '' ? " — {$detail}" : '')); }
}
function get(string $url): string
{
    $html = @file_get_contents($url);
    if ($html === false) { tdb_stop("요청 실패: {$url}"); }
    return $html;
}
function page_count(string $h): ?int
{
    return preg_match('#검색결과 <strong>([\d,]+)</strong>#u', $h, $m) ? (int) str_replace(',', '', $m[1]) : null;
}
function page_ids(string $h): array
{
    preg_match_all('#<a class="pt-name-text" href="/product\.php\?id=(\d+)"#', $h, $m);
    return array_map('intval', $m[1]);
}
function filter_block(string $h): string
{
    return preg_match('#<details class="fltbox".*?</details>#s', $h, $m) ? $m[0] : '';
}

// 독립 기준값: 카테고리 하위 전체(재귀) 판매중 상품
$descSql = 'WITH RECURSIVE d AS (SELECT id FROM categories WHERE id = ? UNION ALL SELECT c.id FROM categories c JOIN d ON c.parent_id = d.id)';
$stIds   = $pdo->prepare("{$descSql} SELECT p.id FROM products p JOIN d ON d.id = p.category_id WHERE p.is_active = 1 ORDER BY p.id DESC");
$stStock = $pdo->prepare("{$descSql} SELECT COUNT(*) FROM products p JOIN d ON d.id = p.category_id WHERE p.is_active = 1 AND p.stock_qty > 0");
$catRows = $pdo->query('SELECT id, name, is_active FROM categories')->fetchAll(PDO::FETCH_UNIQUE);

// 1. 메인
tdb_line('[1] 메인 작업 유형·대표 자재');
$home  = get("{$base}/");
$types = work_types();
chk('작업 유형 12개', count($types) === 12);
$combos = 0;
foreach ($types as $t) {
    $expect = array_values(array_filter(array_column($t['materials'], 0), static fn($id) => isset($catRows[$id]) && (int) $catRows[$id]['is_active'] === 1));
    foreach ($expect as $id) {
        chk("메인에 {$t['key']} 자재 {$id} 링크", str_contains($home, "category={$id}&amp;work={$t['key']}"));
    }
    $combos += count($expect);
}
tdb_line("  작업×자재 조합 {$combos}개");

// 2~5. 작업별 목록 전 조합
tdb_line('[2~5] 작업별 목록 전 조합');
$groupsSeen = [];
$filterChecked = 0;
foreach ($types as $t) {
    $mats = array_values(array_filter(array_column($t['materials'], 0), static fn($id) => isset($catRows[$id]) && (int) $catRows[$id]['is_active'] === 1));
    foreach ($mats as $id) {
        $url  = "{$base}/products.php?category={$id}&work={$t['key']}";
        $h    = get($url);
        $orig = get("{$base}/products.php?category={$id}");
        $stIds->execute([$id]);
        $want = array_map('intval', $stIds->fetchAll(PDO::FETCH_COLUMN));
        $tag  = "{$t['key']}/{$id} {$catRows[$id]['name']}";

        chk("{$tag}: 작업 배너", str_contains($h, 'class="ut-banner-title"'));
        chk("{$tag}: 체크 자재", (bool) preg_match('#class="ut-mat is-on" href="/products\.php\?category=' . $id . '&amp;work=#', $h));
        chk("{$tag}: 체크는 1개", substr_count($h, 'ut-mat is-on') === 1);
        chk("{$tag}: 자재 종류 수", substr_count($h, '<li><a class="ut-mat') === count($mats));
        chk("{$tag}: 제목", (bool) preg_match('#pt-table-title is-usage">\s*' . preg_quote(e($catRows[$id]['name']), '#') . '\s*<#u', $h));
        chk("{$tag}: 상품 수", page_count($h) === count($want), 'page=' . var_export(page_count($h), true) . ' sql=' . count($want));
        $ids = page_ids($h);
        chk("{$tag}: 상품 ID·순서", $ids === $want, 'page ' . count($ids) . '건');
        chk("{$tag}: 기존 목록과 같은 상품", page_ids($orig) === $ids);

        // 3. 필터 패널 = 기존 목록 필터(work 유지 차이만 제거 후 비교)
        $fw = filter_block($h);
        $fo = filter_block($orig);
        $fwNorm = str_replace(['<input type="hidden" name="work" value="' . $t['key'] . '">', '&amp;work=' . $t['key']], '', $fw);
        chk("{$tag}: 필터 패널 = 기존", $fwNorm === $fo && ($fo !== '' || count($want) === 0));
        preg_match_all('#<div class="fgrp[^"]*"><b>([^<]+)#u', $fw, $g);
        foreach ($g[1] as $gn) { $groupsSeen[trim($gn)] = ($groupsSeen[trim($gn)] ?? 0) + 1; }

        // 4. 그룹별 첫 옵션 적용
        if (preg_match_all('#<div class="fgrp[^"]*"><b>[^<]+.*?<input type="checkbox" name="(mat|spec|shape|load)\[\]" value="([^"]*)"[^>]*><span class="fopt-lbl">[^<]*</span>(?:<span class="fcnt">(\d+)</span>)?#su', $fw, $opts, PREG_SET_ORDER)) {
            foreach ($opts as [$all, $field, $val, $cnt]) {
                $val = html_entity_decode($val, ENT_QUOTES);
                $q   = '&' . rawurlencode($field . '[]') . '=' . rawurlencode($val);
                $ah  = get($url . $q);
                $oh  = get("{$base}/products.php?category={$id}" . $q);
                chk("{$tag}: 필터 {$field}={$val} 건수=표시값", page_count($ah) === (int) $cnt, 'page=' . var_export(page_count($ah), true) . " 표시={$cnt}");
                chk("{$tag}: 필터 {$field} 기존과 동일", page_ids($ah) === page_ids($oh));
                chk("{$tag}: 필터 적용 후 work 유지", str_contains($ah, 'ut-mat is-on'));
                $filterChecked++;
            }
        }

        // 5. 재고 있는 상품만
        $stStock->execute([$id]);
        $sh = get($url . '&instock=1');
        $stockWant = (int) $stStock->fetchColumn();
        $stockGot  = page_count($sh);
        chk("{$tag}: 재고 필터 건수", $stockGot === $stockWant || ($stockWant === 0 && str_contains($sh, '재고 있는 상품이 없습니다')),
            'page=' . var_export($stockGot, true) . " sql={$stockWant}");
    }
}
tdb_line('  필터 그룹 노출 횟수: ' . json_encode($groupsSeen, JSON_UNESCAPED_UNICODE) . " / 옵션 적용 검사 {$filterChecked}건");

// 6. 정렬·목록검색 — 대표 1개 조합
tdb_line('[6] 정렬·목록검색');
$t0 = $types[0];
$id0 = (int) $t0['materials'][0][0];
foreach (['price_asc', 'price_desc', 'stock_desc', 'newest'] as $sort) {
    $a = page_ids(get("{$base}/products.php?category={$id0}&work={$t0['key']}&sort={$sort}"));
    $o = page_ids(get("{$base}/products.php?category={$id0}&sort={$sort}"));
    chk("정렬 {$sort} 순서 = 기존", $a === $o && $a !== []);
}
$sa = get("{$base}/products.php?category={$id0}&work={$t0['key']}&s=" . rawurlencode('300'));
$so = get("{$base}/products.php?category={$id0}&s=" . rawurlencode('300'));
chk('목록검색 s=300 결과 = 기존', page_ids($sa) === page_ids($so) && page_count($sa) === page_count($so));
chk('목록검색 후 work 유지', str_contains($sa, 'ut-mat is-on'));

tdb_line("결과: {$pass} 통과 / {$fail} 실패");
exit($fail > 0 ? 1 : 0);
