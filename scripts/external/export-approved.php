<?php
declare(strict_types=1);
/**
 * 승인된 외부 자재 자료(external_products.review_status='approved')만 운영 이관 SQL 로 내보내기 — 로컬 테스트 DB(*_test) 에서 실행
 *
 * 책임: ① 승인 행 전체를 ext_export_issues 로 다시 검증(출처 권한·도메인, 현재 규칙의 문장 재판정, 개인 연락처, 메뉴 글자, 다른 종류 제품 문장, 긴 인용, 문장 중복·섞임)
 *       ② 막힌 행이 하나라도 있으면 SQL 을 만들지 않음(--allow-skip 이면 막힌 행만 빼고 생성, 보고서에 표시). 중단하면 이전 실행의 20·30 도 지움
 *       ③ --expect=N 과 이관 행 수가 다르면 중단(예: 승인 14행)
 *       ④ 출력 폴더(기본 data/external/crawl/export — .gitignore)에
 *          10_create_tables.sql(database/20261029_external_products.sql 원본) · 20_insert_approved.sql(다시 실행해도 중복 없음, 한 트랜잭션)
 *          30_post_check_readonly.sql(행 수·출처별·지문 — 기대값 주석) · 90_rollback.sql · export-report.md
 * 금지: 운영 DB 접속(이 스크립트는 *_test 만), 문장 수정, 매칭(external_product_matches) 이관 — products ID 가 로컬·운영에서 다름(운영 매칭은 별도 확정),
 *       제조사 공개 자료의 참고가 이관(비움).
 * 사용: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php scripts/external/export-approved.php --expect=14
 *       옵션: --out=폴더 · --allow-skip · --expect=N
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$root = dirname(__DIR__, 2);
ob_start();
require "{$root}/app/bootstrap.php";
ob_end_clean();
require_once "{$root}/app/external_export.php";

$opt = getopt('', ['out:', 'allow-skip', 'expect:']);
$out = rtrim((string) ($opt['out'] ?? (getenv('EXT_EXPORT_DIR') ?: "{$root}/data/external/crawl/export")), '/\\');
$reg = json_decode((string) file_get_contents(getenv('EXT_SOURCES_FILE') ?: "{$root}/data/external/sources.json"), true);
$srcs = array_column($reg['sources'] ?? [], null, 'key');
$die = static function (string $m): never { fwrite(STDERR, "[중단] {$m}\n"); exit(2); };
$dbName = (string) ($config['db']['name'] ?? '');
$pdo = db();
if (!$pdo) { $die("DB 에 연결하지 못했습니다(DB_HOST='{$config['db']['host']}')."); }
if (!preg_match('/_test$/', $dbName)) { $die("로컬 테스트 DB(DB_NAME=*_test)에서만 실행합니다. 현재 '{$dbName}'"); }
if (!ext_tables_ready()) { $die('외부 자료 테이블이 없습니다.'); }

$cols = implode(', ', array_merge(['id'], EXT_EXPORT_COLUMNS));
$rows = $pdo->query("SELECT {$cols} FROM external_products WHERE review_status = 'approved' ORDER BY source_key, id")->fetchAll(PDO::FETCH_ASSOC);
if (!$rows) { $die('승인(approved) 행이 없습니다 — approve-crawled.php --approve=… 로 먼저 승인하세요.'); }
$owners = ext_fact_owners($rows);
$ok = $blocked = $warns = [];
foreach ($rows as $r) {
    $is = ext_export_issues($r, $srcs[$r['source_key']] ?? null, $owners);
    if ($is['block']) { $blocked[] = [$r, $is['block']]; } else { $ok[] = ext_export_row($r, $srcs[$r['source_key']] ?? null); }
    if ($is['warn']) { $warns[] = [$r, $is['warn']]; }
}
$line = static fn(array $r) => "{$r['id']} [{$r['source_key']}] {$r['name']}";
$report = "# 승인 자료 운영 이관 검증 (" . date('Y-m-d H:i') . ", {$dbName})\n\n- 승인 행 " . count($rows) . " · 이관 가능 " . count($ok) . ' · 막힘 ' . count($blocked) . ' · 경고 ' . count($warns) . "\n";
if ($blocked) { $report .= "\n## 막힘(이관 안 함)\n\n" . implode("\n", array_map(static fn($x) => '- ' . $line($x[0]) . ' — ' . implode(' / ', $x[1]), $blocked)) . "\n"; }
if ($warns) { $report .= "\n## 경고(이관함)\n\n" . implode("\n", array_map(static fn($x) => '- ' . $line($x[0]) . ' — ' . implode(' / ', $x[1]), $warns)) . "\n"; }
@mkdir($out, 0777, true);
// 이전 실행의 20·30 이 남아 있으면 막힘·기대값 불일치로 중단돼도 옛 SQL(옛 지문)을 운영에 적용할 수 있음 → 먼저 지움(성공하면 아래에서 다시 만듦)
foreach (['20_insert_approved.sql', '30_post_check_readonly.sql'] as $stale) {
    if (is_file("{$out}/{$stale}")) { unlink("{$out}/{$stale}"); }
}
if ($blocked && !isset($opt['allow-skip'])) {
    file_put_contents("{$out}/export-report.md", $report);
    fwrite(STDERR, $report . "\n");
    $die("막힌 행 " . count($blocked) . "개 — 원인을 고치거나(다시 승인) --allow-skip 으로 막힌 행만 빼고 만드세요. 보고서: {$out}/export-report.md");
}
if (isset($opt['expect']) && (int) $opt['expect'] !== count($ok)) {
    file_put_contents("{$out}/export-report.md", $report);
    $die('이관 행 ' . count($ok) . "개가 기대값 {$opt['expect']} 과 다릅니다. 보고서: {$out}/export-report.md");
}

$bySrc = array_count_values(array_column($ok, 'source_key'));
ksort($bySrc);
$fp = ext_export_fingerprint($ok);
$stamp = date('Y-m-d H:i') . " · {$dbName} · 이관 " . count($ok) . '행';
copy("{$root}/database/20261029_external_products.sql", "{$out}/10_create_tables.sql");
$ins = "-- 승인 외부 자재 자료 이관 ({$stamp}) — scripts/external/export-approved.php 생성. 수정하지 말 것.\n"
    . "-- 전제: 10_create_tables.sql 적용됨. 다시 실행해도 같은 source_key·dedup_key 행은 건너뜀(중복 없음).\n"
    . "-- 매칭(external_product_matches)은 이관하지 않음 — 상담은 매칭 없이 material_info 로만 사용. 참고가(ref_price)는 비움.\n"
    . "SET NAMES utf8mb4;\nSTART TRANSACTION;\n\n" . implode("\n\n", array_map('ext_export_insert_sql', $ok)) . "\n\nCOMMIT;\n";
file_put_contents("{$out}/20_insert_approved.sql", $ins);
$q = static fn(string $s) => ext_sql_value($s);
$post = "-- 운영 사후 확인(읽기 전용) — {$stamp}\n-- 기대값은 각 줄 주석. 다르면 90_rollback.sql 검토.\n\n"
    . "SELECT COUNT(*) AS approved_rows FROM external_products WHERE review_status = 'approved';   -- 기대 " . count($ok) . " (이관 전 운영 승인 행이 0 일 때)\n\n"
    . "SELECT source_key, COUNT(*) AS n FROM external_products WHERE review_status = 'approved' GROUP BY source_key ORDER BY source_key;\n"
    . implode("\n", array_map(static fn($k, $n) => "--   {$k}: {$n}", array_keys($bySrc), $bySrc)) . "\n\n"
    . ext_export_fingerprint_sql() . "\n-- 기대 approved_rows " . count($ok) . " · fingerprint {$fp}\n\n"
    . "SELECT COUNT(*) AS matches FROM external_product_matches;   -- 기대 0 (매칭 이관 안 함)\n"
    . "SELECT COUNT(*) AS priced FROM external_products WHERE review_status = 'approved' AND ref_price IS NOT NULL;   -- 기대 0\n";
file_put_contents("{$out}/30_post_check_readonly.sql", $post);
$keys = implode(",\n  ", array_map(static fn($r) => '(' . $q($r['source_key']) . ', ' . $q($r['dedup_key']) . ')', $ok));
file_put_contents("{$out}/90_rollback.sql", "-- 이관 되돌리기 ({$stamp}) — 이 이관으로 들어간 행만 지움. 영향 행 수를 먼저 확인:\n"
    . "SELECT COUNT(*) FROM external_products WHERE (source_key, dedup_key) IN (\n  {$keys}\n);   -- 기대 " . count($ok) . "\n\n"
    . "DELETE FROM external_products WHERE (source_key, dedup_key) IN (\n  {$keys}\n);\n\n"
    . "-- 테이블까지 지우려면(다른 자료가 없을 때만): DROP TABLE external_product_matches; DROP TABLE external_products;\n");
$report .= "\n## 이관 대상 — 출처별\n\n" . implode("\n", array_map(static fn($k, $n) => "- {$k}: {$n}행", array_keys($bySrc), $bySrc))
    . "\n\n- 지문(fingerprint): `{$fp}` — 운영에서 30_post_check_readonly.sql 의 값과 같아야 함\n"
    . "- 적용 순서(phpMyAdmin, 승인 후): 10_create_tables.sql → 20_insert_approved.sql → 30_post_check_readonly.sql\n";
file_put_contents("{$out}/export-report.md", $report);
echo $report . "\n출력: {$out}\n";
