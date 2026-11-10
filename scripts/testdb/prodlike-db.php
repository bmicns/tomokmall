<?php
declare(strict_types=1);
/**
 * 운영 구조 로컬 테스트 DB 만들기 (scripts/testdb/prodlike-db.php) — 운영 복제본(devdb 스냅샷)이 없을 때의 대체 구성.
 *
 * 책임: 테스트 컨테이너(tomokmall_ai_test, load-ktomok-docker.sh 로 생성)에 DB 3개를 만든다.
 *   - tomokmall_test      운영과 같은 구조(31개 테이블) + 운영 카테고리 트리·규격 이름표(category_spec_axes)·상담 가이드
 *                         + 수집 상품(data/ktomok)을 운영 카테고리 번호로 옮김(이름 기준, 운영에 없는 카테고리 상품은 뺌)
 *                         + 법령 근거(load-legal-docs.sh 로 넣은 행) + 규격 치수 칸 채우기(spec-axes-fill.php apply)
 *   - tomokmall_mock_test 운영과 같은 구조, 데이터 없음 — '빈 DB 전용' 목 데이터 테스트(tests/stock_filter.test.php)
 *   - ktomok_test         수집 원본 구조·데이터 그대로(+ external_products) — 수집 원본 기대값 테스트(tests/external_import.test.php)
 * 운영에서 읽는 것(원격 관리 API, 읽기 전용 SELECT — scripts/tomok db query):
 *   information_schema 구조(테이블·컬럼·인덱스·외래키), categories 의 id·parent_id·name·description·slug·sort_order·is_active,
 *   category_spec_axes 전체, category_consult_guide. 가격(마진율 칸 포함)·회원·주문 데이터는 읽지 않는다.
 * 금지: 운영 DB 쓰기. 읽은 자료는 임시 폴더에만 두고 끝나면 지운다(저장소·운영 서버에 남기지 않음).
 * 로컬 변환: 수집 규격 문자열은 운영 모양(규격·중량·단위 조각)만 남긴다. 상품 id 는 운영과 다르다.
 *
 * 사용 (CLI, TOMOK_OPS_KEY 환경변수 필요):
 *   bash scripts/testdb/load-ktomok-docker.sh   → 마지막 줄 export 실행
 *   bash scripts/testdb/load-legal-docs.sh
 *   php scripts/testdb/prodlike-db.php
 * 이후 테스트: DB_NAME=tomokmall_test(기본) · stock_filter 는 DB_NAME=tomokmall_mock_test · external_import 는 DB_NAME=ktomok_test
 */

if (PHP_SAPI !== 'cli') { exit(1); }
$root = dirname(__DIR__, 2);
$container = 'tomokmall_ai_test';
$pass = trim((string) shell_exec('docker inspect -f ' . escapeshellarg('{{range .Config.Env}}{{println .}}{{end}}') . ' ' . $container . " 2>/dev/null | sed -n 's/^MYSQL_ROOT_PASSWORD=//p'"));
if ($pass === '') { fwrite(STDERR, "테스트 컨테이너({$container})가 없습니다. 먼저 bash scripts/testdb/load-ktomok-docker.sh\n"); exit(2); }
if ((string) getenv('TOMOK_OPS_KEY') === '') { fwrite(STDERR, "TOMOK_OPS_KEY 가 없습니다(운영 구조 읽기에 필요). docs/REMOTE-OPS.md 3-2\n"); exit(2); }
$tmp = sys_get_temp_dir() . '/prodlike-' . getmypid();
@mkdir($tmp, 0700, true);
register_shutdown_function(static fn() => exec('rm -rf ' . escapeshellarg($tmp)));

/** 컨테이너 mysql 실행(표준입력 파일) */
$mysql = static function (string $db, ?string $file = null, string $sql = '') use ($container, $pass): string {
    $cmd = 'docker exec -i -e MYSQL_PWD=' . escapeshellarg($pass) . " {$container} mysql -uroot --default-character-set=utf8mb4 -N -B"
        . ($db !== '' ? ' ' . escapeshellarg($db) : '') . ($sql !== '' ? ' -e ' . escapeshellarg($sql) : '') . ($file !== null ? ' < ' . escapeshellarg($file) : '') . ' 2>&1';
    exec($cmd, $out, $rc);
    if ($rc !== 0) { fwrite(STDERR, "mysql 오류: " . implode("\n", array_slice($out, -3)) . "\n"); exit(3); }
    return implode("\n", $out);
};
/** 운영 읽기 전용 조회(원격 관리 API) → 행 목록 */
$prod = static function (string $sql) use ($root): array {
    exec('php ' . escapeshellarg("{$root}/scripts/remote-ops/tomok.php") . ' db query --limit 2000 --sql ' . escapeshellarg($sql) . ' 2>&1', $out, $rc);
    $d = json_decode(implode("\n", $out), true);
    if ($rc !== 0 || !is_array($d) || empty($d['ok'])) { fwrite(STDERR, "운영 조회 실패: " . mb_substr(implode(' ', $out), 0, 200) . "\n"); exit(4); }
    if (!empty($d['result']['truncated'])) { fwrite(STDERR, "운영 조회 결과가 잘렸습니다(--limit).\n"); exit(4); }
    return $d['result']['rows'];
};
$lit = static fn($v): string => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : "'" . str_replace(['\\', "'"], ['\\\\', "''"], (string) $v) . "'");

echo "1) 운영 구조 읽기(information_schema) → DDL\n";
$checks = (string) file_get_contents("{$root}/docs/03-analysis/testdb/01_prod_readonly_checks.sql");
$fh = fopen("{$tmp}/part1.csv", 'wb');
fputcsv($fh, ['blk', 'result'], ',', '"', '');
foreach (['A2', 'A3', 'A4', 'A5'] as $k) {
    if (!preg_match("/(SELECT '{$k} [^']*'.*?)\\nUNION ALL/su", $checks, $m)) { fwrite(STDERR, "점검 SQL 에서 {$k} 를 찾지 못했습니다.\n"); exit(5); }
    $row = $prod((string) preg_replace('/\s+/u', ' ', $m[1]))[0] ?? [];
    $vals = array_values($row);
    fputcsv($fh, [(string) $vals[0], is_string($vals[1]) ? $vals[1] : json_encode($vals[1], JSON_UNESCAPED_UNICODE)], ',', '"', '');
}
fclose($fh);
exec('php ' . escapeshellarg("{$root}/scripts/testdb/build-schema.php") . ' ' . escapeshellarg("{$tmp}/part1.csv") . ' > ' . escapeshellarg("{$tmp}/schema.sql"), $o, $rc);
if ($rc !== 0) { fwrite(STDERR, "build-schema 실패\n"); exit(5); }

echo "2) 수집 원본 DB 보관(ktomok_test) + external_products\n";
$mysql('', null, 'DROP DATABASE IF EXISTS ktomok_test; CREATE DATABASE ktomok_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
exec('docker exec -e MYSQL_PWD=' . escapeshellarg($pass) . " {$container} sh -c " . escapeshellarg('mysqldump -uroot --default-character-set=utf8mb4 --single-transaction tomokmall_test | mysql -uroot --default-character-set=utf8mb4 ktomok_test') . ' 2>&1', $o, $rc);
if ($rc !== 0) { fwrite(STDERR, "ktomok_test 복사 실패\n"); exit(6); }
$mysql('ktomok_test', "{$root}/database/20261029_external_products.sql");

echo "3) 운영 카테고리·규격 이름표·상담 가이드 읽기\n";
$cats   = $prod('SELECT id, parent_id, name, description, slug, sort_order, is_active FROM categories ORDER BY id');
$axes   = $prod('SELECT category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt, fixed_value, fixed_text, is_active FROM category_spec_axes ORDER BY category_id, seq');
$guides = $prod('SELECT category_key, title, guide_text, is_active, sort_order FROM category_consult_guide');
$counts = $prod('SELECT c.id, COUNT(p.id) AS n FROM categories c LEFT JOIN products p ON p.category_id = c.id GROUP BY c.id');
$withProducts = [];
foreach ($counts as $c) { $withProducts[(int) $c['id']] = (int) $c['n']; }
$byName = [];
foreach ($cats as $c) { $byName[(string) $c['name']][] = (int) $c['id']; }

$sql = ["SET FOREIGN_KEY_CHECKS = 0;", 'SET NAMES utf8mb4;'];
foreach ($cats as $c) {
    $sql[] = 'INSERT INTO categories (id, parent_id, name, description, slug, sort_order, is_active) VALUES ('
        . implode(', ', array_map($lit, [(int) $c['id'], $c['parent_id'] === null ? null : (int) $c['parent_id'], $c['name'], $c['description'], $c['slug'], (int) $c['sort_order'], (int) $c['is_active']])) . ');';
}
foreach ($axes as $a) {
    $k = ['category_id', 'seq', 'axis_key', 'label', 'symbol', 'show_symbol', 'unit', 'use_filter', 'parse_fmt', 'fixed_value', 'fixed_text', 'is_active'];
    $sql[] = 'INSERT INTO category_spec_axes (' . implode(', ', $k) . ') VALUES (' . implode(', ', array_map(static fn($x) => $lit($a[$x]), $k)) . ');';
}
foreach ($guides as $g) {
    $k = ['category_key', 'title', 'guide_text', 'is_active', 'sort_order'];
    $sql[] = 'INSERT INTO category_consult_guide (' . implode(', ', $k) . ') VALUES (' . implode(', ', array_map(static fn($x) => $lit($g[$x]), $k)) . ');';
}
// 수집 상품 카테고리 이름 → 운영 카테고리 id. 같은 이름이 둘이면(상위·하위) 운영에서 상품이 있는 쪽
$sql[] = 'CREATE TEMPORARY TABLE catmap (src_name VARCHAR(100) COLLATE utf8mb4_unicode_ci PRIMARY KEY, prod_id INT UNSIGNED);';
foreach ($byName as $n => $ids) {
    usort($ids, static fn($a, $b) => ($withProducts[$b] ?? 0) <=> ($withProducts[$a] ?? 0));
    $sql[] = 'INSERT INTO catmap VALUES (' . $lit($n) . ', ' . $ids[0] . ');';
}
$sql[] = "INSERT INTO products (id, category_id, name, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active, created_at)
SELECT p.id, m.prod_id, p.name, LEFT(p.spec, 180), p.description, p.price_b2c, p.price_b2b, p.stock_qty, p.external_source, p.external_id, p.source_url, p.image_url, p.is_active, p.created_at
FROM ktomok_test.products p JOIN ktomok_test.categories c ON c.id = p.category_id JOIN catmap m ON m.src_name = c.name;";
$sql[] = 'SET FOREIGN_KEY_CHECKS = 1;';
file_put_contents("{$tmp}/data.sql", implode("\n", $sql));

echo "4) tomokmall_test 다시 만들기(운영 구조) + 데이터\n";
$mysql('', null, 'DROP DATABASE IF EXISTS tomokmall_test; CREATE DATABASE tomokmall_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
$mysql('tomokmall_test', "{$tmp}/schema.sql");
$mysql('tomokmall_test', "{$tmp}/data.sql");
// 규격 문자열: 운영 모양(규격·중량·단위 조각)만
$rows = $mysql('ktomok_test', null, 'SELECT id, REPLACE(REPLACE(spec, CHAR(9), \' \'), CHAR(10), \' \') FROM products');
$up = [];
foreach (explode("\n", $rows) as $line) {
    [$id, $spec] = array_pad(explode("\t", $line, 2), 2, '');
    $keep = array_values(array_filter(array_map('trim', explode('|', $spec)), static fn($s) => preg_match('/^(규격|중량|단위):/u', $s)));
    if ($keep && (int) $id > 0) { $up[] = 'UPDATE products SET spec = ' . $lit(implode(' | ', $keep)) . ' WHERE id = ' . (int) $id . ';'; }
}
file_put_contents("{$tmp}/spec.sql", implode("\n", $up));
$mysql('tomokmall_test', "{$tmp}/spec.sql");
// 법령 근거: 같은 이름 칸만 옮김
$cols = $mysql('', null, "SELECT GROUP_CONCAT(CONCAT('`', a.COLUMN_NAME, '`') ORDER BY a.ORDINAL_POSITION) FROM information_schema.COLUMNS a JOIN information_schema.COLUMNS b
  ON b.TABLE_SCHEMA = 'ktomok_test' AND b.TABLE_NAME = 'rag_documents' AND b.COLUMN_NAME = a.COLUMN_NAME WHERE a.TABLE_SCHEMA = 'tomokmall_test' AND a.TABLE_NAME = 'rag_documents'");
$mysql('tomokmall_test', null, "INSERT INTO rag_documents ({$cols}) SELECT {$cols} FROM ktomok_test.rag_documents");

echo "5) 규격 치수 칸 채우기(spec-axes-fill.php apply)\n";
passthru('DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS=' . escapeshellarg($pass) . ' php ' . escapeshellarg("{$root}/scripts/db-tools/spec-axes-fill.php") . ' apply | tail -3');

echo "6) tomokmall_mock_test(운영 구조, 데이터 없음)\n";
$mysql('', null, 'DROP DATABASE IF EXISTS tomokmall_mock_test; CREATE DATABASE tomokmall_mock_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;');
$mysql('tomokmall_mock_test', "{$tmp}/schema.sql");

echo "\n완료: " . $mysql('tomokmall_test', null, "SELECT CONCAT('상품 ', COUNT(*), ' · 치수 채움 ', SUM(spec_dims_src IS NOT NULL), ' · 이름표 ', (SELECT COUNT(*) FROM category_spec_axes), ' · 카테고리 ', (SELECT COUNT(*) FROM categories)) FROM products") . "\n";
echo "운영 대조(읽기 전용): php scripts/remote-ops/tomok.php db query --sql \"SELECT SUM(spec_dims_src IS NOT NULL) FROM products WHERE is_active=1\"\n";
