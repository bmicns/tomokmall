<?php
declare(strict_types=1);
/**
 * 운영과 같은 '상용콘크리트 전환 후' 테스트 DB 만들기 (scripts/testdb/prodlike-syc.php, 2026-11-12) — 로컬 전용, 운영 접속 없음.
 *
 * 2026-11-12 운영 고객 화면이 케이토목 → 상용콘크리트(B 방식: 상용콘크리트를 products 에 반영하고 케이토목 숨김)로 바뀌었다.
 * 운영 데이터를 내보내지 않고(대량 추출 승인 필요 작업 없음), 저장소의 같은 원본과 같은 반영 코드로 로컬에서 그대로 재현한다:
 *   ① tomokmall_test(prodlike-db.php 결과 — 운영 구조·분류, 전환 전 상태)를 tomokmall_syc_test 로 복사
 *   ② 상용콘크리트 원본 표 마이그레이션(database/2026111*_syc_*.sql) + 원본 상품 704행(data/syccrt/import.sql — 운영 반영본과 같은 테스트 데이터)
 *   ③ 운영처럼 원본 상품 활성 → app/syc_publish.php syc_catalog_switch('syc') (반영 + 케이토목 숨김)
 * 가격은 원본에 없어 0(운영도 전 상품 0원 = 견적문의, 2026-11-12 확인). 회원·주문 데이터 없음.
 *
 * 실행: php scripts/testdb/prodlike-syc.php      (먼저 load-ktomok-docker.sh → load-legal-docs.sh → prodlike-db.php)
 * 사용: DB_NAME=tomokmall_syc_test php tests/consult_syc_catalog.test.php
 */
if (PHP_SAPI !== 'cli') { exit; }
ini_set('session.use_cookies', '0');   // bootstrap 의 세션 시작보다 먼저(출력 뒤 경고 방지)
ini_set('session.cache_limiter', '');
$root = dirname(__DIR__, 2);
$container = 'tomokmall_ai_test';
$pass = trim((string) shell_exec('docker inspect -f ' . escapeshellarg('{{range .Config.Env}}{{println .}}{{end}}') . ' ' . $container . " 2>/dev/null | sed -n 's/^MYSQL_ROOT_PASSWORD=//p'"));
if ($pass === '') { fwrite(STDERR, "테스트 컨테이너({$container})가 없습니다. 먼저 bash scripts/testdb/load-ktomok-docker.sh → php scripts/testdb/prodlike-db.php\n"); exit(2); }
$db = 'tomokmall_syc_test';
$mysql = static function (string $sqlOrFile, bool $isFile = false, bool $force = false) use ($container, $pass, $db): array {
    $cmd = 'docker exec -i -e MYSQL_PWD=' . escapeshellarg($pass) . " {$container} mysql --default-character-set=utf8mb4 -uroot " . ($force ? '--force ' : '') . $db;
    $cmd .= $isFile ? ' < ' . escapeshellarg($sqlOrFile) : ' -N -e ' . escapeshellarg($sqlOrFile);
    exec($cmd . ' 2>&1', $out, $rc);
    return [$rc, $out];
};

echo "1) tomokmall_test → {$db} 복사\n";
exec('docker exec -e MYSQL_PWD=' . escapeshellarg($pass) . " {$container} sh -c " . escapeshellarg("mysql -uroot -e 'DROP DATABASE IF EXISTS {$db}; CREATE DATABASE {$db} CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;' && mysqldump -uroot --default-character-set=utf8mb4 --single-transaction tomokmall_test | mysql -uroot --default-character-set=utf8mb4 {$db}") . ' 2>&1', $o, $rc);
if ($rc !== 0) { fwrite(STDERR, "복사 실패: " . implode("\n", $o) . "\n"); exit(3); }

echo "2) 상용콘크리트 원본 표·상품 적재\n";
$migs = ['20261110_syc_categories', '20261110_syc_products', '20261111_syc_categories_split', '20261111_syc_categories_merge', '20261111_syc_category_spec_axes',
         '20261111_syc_axes_split', '20261111_syc_axes_acc_labels', '20261111_syc_products_acc_dims', '20261112_syc_categories_regroup'];
foreach ($migs as $m) {
    [$rc, $out] = $mysql("{$root}/database/{$m}.sql", true, true);   // 운영 구조에 이미 있는 칸 추가(중복 칸)는 무시
    $err = array_values(array_filter($out, static fn($l) => str_starts_with((string) $l, 'ERROR') && !str_contains((string) $l, 'Duplicate column')));
    if ($err) { fwrite(STDERR, "{$m}: " . implode(' / ', $err) . "\n"); exit(4); }
}
[$rc, $out] = $mysql("{$root}/data/syccrt/import.sql", true);
if ($rc !== 0) { fwrite(STDERR, 'import.sql: ' . implode(' / ', $out) . "\n"); exit(4); }
// 상용콘크리트용 상담 가이드(grating_syc·pe_pipe_syc, 2026-11-13) — 운영에 반영 전이어도 테스트 DB 에는 넣어 둔다
[$rc, $out] = $mysql("{$root}/database/20261113_consult_guide_syc.sql", true);
if ($rc !== 0) { fwrite(STDERR, '20261113_consult_guide_syc.sql: ' . implode(' / ', $out) . "\n"); exit(4); }

echo "3) 반영·전환(app/syc_publish.php — 운영 관리자 '상용콘크리트로 전환'과 같은 코드)\n";
putenv('DB_HOST=127.0.0.1'); putenv("DB_NAME={$db}"); putenv('DB_USER=root'); putenv("DB_PASS={$pass}");
ob_start();
require "{$root}/app/bootstrap.php";
ob_end_clean();
require_once "{$root}/app/syc_publish.php";
$pdo = db();
if (!$pdo || !syc_publish_ready($pdo)) { fwrite(STDERR, "반영 준비 안 됨(products 칸·syc_catalog_state 확인)\n"); exit(5); }
$pdo->exec('UPDATE syc_products SET is_active = 1');   // 운영: 704행 모두 활성(2026-11-12 확인)
$r = syc_catalog_mode(true) === 'syc' ? ['publish' => syc_publish_run($pdo)] : syc_catalog_switch($pdo, 'syc');
echo '   ' . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n";

$n = (int) $pdo->query("SELECT COUNT(*) FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1 AND c.is_active = 1")->fetchColumn();
$priced = (int) $pdo->query('SELECT COUNT(*) FROM products WHERE is_active = 1 AND price_b2c > 0')->fetchColumn();
echo "\n완료: {$db} — 보이는 상품 {$n}(상용콘크리트), 가격 있는 상품 {$priced}\n";
echo "운영 대조(읽기 전용): php scripts/remote-ops/tomok.php db query --sql \"SELECT COUNT(*) FROM products p JOIN categories c ON c.id=p.category_id WHERE p.is_active=1 AND c.is_active=1\"\n";
