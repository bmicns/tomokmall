<?php
declare(strict_types=1);
/**
 * 상품 설명 정비안 생성 — 운영 상품·분류 내보내기(읽기 전용 조회 결과)를 받아 검토표·적용 SQL·되돌리기 SQL 을 만든다.
 *
 * 입력: --products=상품.json|csv (id, category_id, name, spec, description — tomok db query 결과 JSON, 행 배열 JSON, 또는 머리글 있는 CSV)
 *       --categories=분류.json|csv (id, parent_id, name, description)
 *       [--candidates=docs/03-analysis/ai-sourcing/legal-sources/candidates.json] (사용자 확인 KS 적용 범위)
 *       [--with-ks-scope] [--with-category-brief] 새 항목에 KS 적용 범위·분류 설명도 넣기(기본: 넣지 않음 — 상품 검색에 다른 종류 상품이 섞임,
 *       2026-11-05 측정: '콘크리트' 31→307건, 'KS' 52→155건. 기본(고르는 기준만)은 크게 늘어난 검색어 0개)
 * 출력(--out 폴더 — 저장소 밖만 허용: 운영 데이터가 들어 있으므로 Git·배포 대상에 두지 않는다):
 *   review.csv               검토표(UTF-8 BOM — 엑셀): 상품ID·이름·분류·구분·현재 설명·문제 표시·제안 설명·제안 근거
 *   50_description_fix.sql   기존 설명 정비(fix·keep_clean·clear)  — description 칸만, 만든 시점 설명과 같을 때만 바꿈
 *   51_description_fill.sql  설명이 없던 상품 채우기(fill) — 따로 승인
 *   59_description_revert.sql 되돌리기(정비안이 그대로 있을 때만 이전 설명으로)
 *   summary.md               건수 요약(설명 본문 없음 — 저장소 문서에 옮겨도 되는 집계만)
 * 금지: DB 접속·실행(파일만 만든다), 가격 칸, 저장소 안 출력. 운영 반영은 사용자 승인 후(원격 관리 API 는 관리자 화면 승인).
 * 실행: php scripts/data-quality/build_description_proposals.php --products=… --categories=… --out=/저장소/밖/폴더
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_WARNING);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$root = dirname(__DIR__, 2);
ob_start();
require "{$root}/app/bootstrap.php";
ob_end_clean();
require_once "{$root}/app/ai_tools.php";
require_once "{$root}/app/external_products.php";
require_once __DIR__ . '/description_lib.php';

$opt = getopt('', ['products:', 'categories:', 'candidates:', 'out:', 'with-ks-scope', 'with-category-brief']);
foreach (['products', 'categories', 'out'] as $k) {
    if (empty($opt[$k])) {
        fwrite(STDERR, "사용법: --products=… --categories=… --out=저장소 밖 폴더 [--candidates=…]\n");
        exit(2);
    }
}
$out = rtrim((string) $opt['out'], '/');
$outAbs = str_starts_with($out, '/') ? $out : getcwd() . '/' . $out;
$outAbs = (string) preg_replace('#/(\./)+#', '/', $outAbs);
while (preg_match('#/[^/]+/\.\.(/|$)#', $outAbs)) { $outAbs = (string) preg_replace('#/[^/]+/\.\.(/|$)#', '/', $outAbs, 1); }
$outReal = realpath($out) ?: $outAbs;   // 폴더를 만들기 전에 판정(저장소 안이면 아무것도 만들지 않는다)
if (str_starts_with($outReal . '/', realpath($root) . '/')) {
    fwrite(STDERR, "거부: 출력 폴더가 저장소 안입니다({$outReal}). 운영 데이터가 들어가므로 저장소 밖(세션 scratchpad 등)에 두세요.\n");
    exit(2);
}
@mkdir($out, 0700, true);

/** 내보내기 파일 → 행 배열 (tomok JSON / 행 배열 JSON / 머리글 CSV) */
$load = static function (string $file): array {
    $raw = (string) file_get_contents($file);
    if (str_ends_with(strtolower($file), '.csv')) {
        $fh = fopen($file, 'r');
        $head = array_map(static fn($h) => trim((string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $h)), (array) fgetcsv($fh, null, ',', '"', ''));
        $rows = [];
        while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
            $rows[] = array_combine($head, array_pad($r, count($head), null));
        }
        fclose($fh);
        return $rows;
    }
    $j = json_decode($raw, true);
    return (array) ($j['result']['rows'] ?? $j);
};
$products = $load((string) $opt['products']);
$cats = $load((string) $opt['categories']);
$candidates = json_decode((string) file_get_contents((string) ($opt['candidates'] ?? "{$root}/docs/03-analysis/ai-sourcing/legal-sources/candidates.json")), true) ?: [];
$ksScopes = isset($opt['with-ks-scope']) ? desc_ks_scopes($candidates) : [];

$catById = array_column($cats, null, 'id');
$withBrief = isset($opt['with-category-brief']);
$brief = static function (int $catId) use ($catById, $withBrief): string {
    $c = $withBrief ? ($catById[$catId] ?? null) : null;
    if ($c === null) { return ''; }
    $b = ai_category_brief((string) ($c['description'] ?? ''));
    if ($b === '' && !empty($c['parent_id']) && isset($catById[(int) $c['parent_id']])) {
        $b = ai_category_brief((string) ($catById[(int) $c['parent_id']]['description'] ?? ''));
    }
    return $b;
};

$labels = ['fix' => '정비(문제 문장 삭제 + 고르는 기준)', 'keep_clean' => '정비(문제 문장만 삭제)', 'clear' => '정비(설명 비움)', 'fill' => '채우기(설명 없던 상품)', 'none' => '변경 없음'];
$flagNames = array_map(static fn($r) => $r[1], DESC_FLAG_RULES) + ['conflict' => '규격(두께)과 충돌하는 차량 문장'];
$count = array_fill_keys(array_keys($labels), 0);
$flagTotal = [];
$conflictProducts = 0;
$csv = fopen("{$out}/review.csv", 'w');
fwrite($csv, "\xEF\xBB\xBF");
fputcsv($csv, ['상품ID', '상품명', '분류', '구분', '현재 설명', '문제 표시(문장 수)', '제안 설명', '제안 근거'], ',', '"', '');
$fix = $fill = $revert = [];
foreach ($products as $p) {
    $id = (int) $p['id'];
    $catBrief = $brief((int) ($p['category_id'] ?? 0));
    $r = desc_review(['id' => $id, 'name' => (string) $p['name'], 'spec' => (string) ($p['spec'] ?? ''), 'description' => (string) ($p['description'] ?? '')], $catBrief, $ksScopes);
    $count[$r['kind']]++;
    foreach ($r['flags'] as $k => $n) { $flagTotal[$k] = ($flagTotal[$k] ?? 0) + $n; }
    if ($r['conflict'] > 0) { $conflictProducts++; }
    if ($r['kind'] === 'none') { continue; }
    $ref = ai_name_ks_ref((string) $p['name']);
    $basis = array_filter([
        ($ref !== '' && isset($ksScopes[$ref])) ? "KS 적용 범위({$ref}, 사용자 확인)" : '',
        ($catBrief !== '' && !($ref !== '' && isset($ksScopes[$ref]))) ? '분류 설명(운영자)' : '',
        str_contains((string) $r['new'], '고르는 기준') ? '이름·규격에서 읽은 고르는 기준' : '',
        in_array($r['kind'], ['fix', 'keep_clean'], true) && $r['flagged'] < $r['sentences'] ? '기존 문장 중 문제 표시 없는 문장(근거 미확인 — 검토)' : '',
    ]);
    $flagText = implode(', ', array_map(static fn($k, $n) => ($flagNames[$k] ?? $k) . " {$n}", array_keys($r['flags']), $r['flags']));
    fputcsv($csv, [$id, (string) $p['name'], (string) ($catById[(int) ($p['category_id'] ?? 0)]['name'] ?? ''), $labels[$r['kind']], $r['old'], $flagText, (string) $r['new'], implode(' · ', $basis)], ',', '"', '');
    $stmt = desc_update_sql($id, $r['old'], $r['new']);
    if ($r['kind'] === 'fill') { $fill[] = $stmt; } else { $fix[] = $stmt; }
    $revert[] = desc_revert_sql($id, $r['old'], $r['new']);
}
fclose($csv);

$head = static fn(string $what, int $n): string => "-- 상품 설명 {$what} — {$n}문 (생성: scripts/data-quality/build_description_proposals.php, " . date('Y-m-d') . ")\n"
    . "-- ⚠️ 운영 실행은 사용자 승인 후(데이터 변경 — products.description 칸만, 가격 칸 없음). 실행 전 products 의 id·description 백업.\n"
    . "-- 각 문은 정비안을 만든 시점의 설명과 같을 때만 바꾼다(그 사이 관리자 화면에서 고친 상품은 0행 — 덮어쓰지 않음). 되돌리기: 59_description_revert.sql\n"
    . "-- 원격 관리 API 로 할 때: tomok db request --tables products --actions update --columns products.description --max-rows {$n} 승인 후 db stage-sql.\n\nSTART TRANSACTION;\n";
file_put_contents("{$out}/50_description_fix.sql", $head('정비(기존 설명 → 새 설명·문제 문장 삭제·비움)', count($fix)) . implode("\n", $fix) . "\nCOMMIT;\n");
file_put_contents("{$out}/51_description_fill.sql", $head('채우기(설명이 없던 상품)', count($fill)) . implode("\n", $fill) . "\nCOMMIT;\n");
file_put_contents("{$out}/59_description_revert.sql", "-- 상품 설명 정비 되돌리기 — " . count($revert) . "문. 정비안이 그대로 들어가 있는 상품만 이전 설명으로(그 뒤 다시 고친 상품은 0행).\n-- ⚠️ 운영 실행은 사용자 승인 후.\n\nSTART TRANSACTION;\n" . implode("\n", $revert) . "\nCOMMIT;\n");

$md = "# 상품 설명 정비안 요약 (" . date('Y-m-d') . ")\n\n| 구분 | 상품 수 |\n|---|---:|\n";
foreach ($labels as $k => $l) { $md .= "| {$l} | {$count[$k]} |\n"; }
$md .= "| 합계(활성 상품) | " . count($products) . " |\n\n| 기존 설명의 문제 표시 | 문장 수 |\n|---|---:|\n";
arsort($flagTotal);
foreach ($flagTotal as $k => $n) { $md .= '| ' . ($flagNames[$k] ?? $k) . " | {$n} |\n"; }
$md .= "\n규격(두께)과 충돌하는 차량 문장이 있는 상품: {$conflictProducts}개\n";
file_put_contents("{$out}/summary.md", $md);
echo $md;
