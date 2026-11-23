<?php
declare(strict_types=1);
/**
 * 운영 복제 테스트 DB 도구 공통 (scripts/testdb/*.php 에서 require)
 *
 * 책임: CLI 전용 확인, 로컬 '_test' DB 접속 가드, phpMyAdmin CSV(blk·결과 JSON) 읽기
 * 금지: 웹 실행, 운영 DB 접속. 이 폴더는 deploy.sh 대상(public/app/database)이 아니다.
 * 데이터 파일(운영 내보내기 CSV)은 저장소 밖(세션 scratchpad 등)에 두고 커밋하지 않는다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require_once __DIR__ . '/../../app/bootstrap.php';

function tdb_line(string $s = ''): void { echo $s, "\n"; }
function tdb_stop(string $s): never { fwrite(STDERR, "중단: {$s}\n"); exit(1); }

/** 로컬 '_test' DB에만 접속(scripts/local-test-accounts.php 와 같은 기준). 아니면 중단. */
function tdb_test_pdo(): PDO
{
    global $config;
    $host = strtolower((string) ($config['db']['host'] ?? ''));
    $name = (string) ($config['db']['name'] ?? '');
    if (!preg_match('/_test$/', $name) || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        tdb_stop("로컬 테스트 DB(DB_NAME=*_test, DB_HOST=127.0.0.1|localhost)가 아닙니다. 현재 DB_HOST={$host} DB_NAME={$name}");
    }
    $pdo = db();
    if (!$pdo) {
        tdb_stop('테스트 DB 접속 실패 — 컨테이너 실행 여부와 DB_USER/DB_PASS 를 확인하세요.');
    }
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) {
        tdb_stop('접속된 DB 이름이 설정과 다릅니다.');
    }
    return $pdo;
}

/**
 * phpMyAdmin 결과 CSV(열: blk, [cnt,] result) → ['blk' => ['cnt' => ?int, 'data' => JSON 디코드 값]]
 * 첫 줄 열 이름은 있어도 없어도 된다(없으면 열 2개 = blk·result, 3개 = blk·cnt·result).
 * 구분자는 쉼표(CSV) 또는 세미콜론(CSV for MS Excel)을 첫 줄로 판별한다.
 * 소수는 문자열로 유지(JSON_BIGINT_AS_STRING + 정밀도 보존).
 */
function tdb_read_csv(string $path): array
{
    if (!is_file($path)) {
        tdb_stop("파일 없음: {$path}");
    }
    $fh = fopen($path, 'rb');
    $first = (string) fgets($fh);
    $first = (string) preg_replace('/^\xEF\xBB\xBF/', '', $first);   // BOM 제거
    $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
    rewind($fh);
    if (fread($fh, 3) !== "\xEF\xBB\xBF") {
        rewind($fh);
    }
    $head = null;
    $out = [];
    while (($r = fgetcsv($fh, 0, $sep, '"', '')) !== false) {
        if (count($r) < 2) {
            continue;
        }
        if ($head === null) {
            $head = $r[0] === 'blk' ? $r : (count($r) >= 3 ? ['blk', 'cnt', 'result'] : ['blk', 'result']);
            if ($r[0] === 'blk') {
                continue;
            }
        }
        $row  = array_combine(array_slice($head, 0, count($r)), array_slice($r, 0, count($head)));
        $json = (string) ($row['result'] ?? '');
        $out[(string) $row['blk']] = [
            'cnt'  => isset($row['cnt']) && $row['cnt'] !== '' && $row['cnt'] !== 'NULL' ? (int) $row['cnt'] : null,
            'data' => $json === '' || $json === 'NULL' ? null : json_decode($json, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR),
        ];
    }
    fclose($fh);
    if (!$out) {
        tdb_stop("CSV에서 결과를 읽지 못했습니다: {$path} — phpMyAdmin 내보내기 형식을 'CSV'로 했는지 확인하세요.");
    }
    return $out;
}

/**
 * 운영 → 테스트 DB 내보내기 대상 표와 칸별 처리 (2026-11-23 — 02 SQL 을 운영 구조(10 결과 A3)에서 만들도록 바꿈).
 * 블록 키 → [표, 반드시 있어야 하는 칸]. 칸 목록은 운영 A3 에서 읽고(새 칸이 생겨도 SQL 을 손으로 고치지 않음), 처리 규칙은 tdb_export_policy.
 * 회원·견적·주문·상담 기록 표는 내보내지 않는다(구조만 복제). syc_* 원본 표는 공통 분류(S20) 반영본이 products 에 있어 내보내지 않는다.
 */
const TDB_EXPORT_TABLES = [
    'E1' => ['categories', ['id', 'parent_id', 'name']],
    'E2' => ['products', ['id', 'category_id', 'name']],
    'E3' => ['category_relations', ['id']],
    'E4' => ['vendors', ['id', 'name']],
    'E5' => ['product_vendors', ['id', 'product_id', 'vendor_id']],
    'E6' => ['category_gallery', ['id']],
    'E9' => ['category_spec_axes', ['id', 'category_id']],
];
/** 업체 표에서 그대로 내보내는 칸(나머지 — 담당자·전화·팩스·이메일·주소·메모 등 — 는 NULL) */
const TDB_VENDOR_KEEP = ['id', 'name', 'region', 'is_active', 'created_at', 'updated_at'];
/** 가격 이름 칸 중 그대로 내보내는 칸(2026-10-23 승인 범위 — 판매가). 그 밖의 가격 이름 칸(매입가·마진율·공급가 등)은 0 */
const TDB_PRICE_KEEP = ['products.price_b2c', 'products.price_b2b'];

/** 칸 처리 (순수 함수) — 'raw' 그대로 · 'zero' 0 · 'null' NULL. 가격 이름 규칙은 CLAUDE.md P0-4 와 같다 */
function tdb_export_policy(string $table, string $col): string
{
    if ($table === 'vendors') {
        return in_array($col, TDB_VENDOR_KEEP, true) ? 'raw' : 'null';
    }
    if ($table === 'product_vendors' && $col === 'memo') {
        return 'null';
    }
    if (preg_match('/price|cost|discount|margin|markup|fee|amount|amt|tax|vat|surcharge/i', $col) && !in_array("{$table}.{$col}", TDB_PRICE_KEEP, true)) {
        return 'zero';
    }
    return 'raw';
}

/** 10(또는 01) 결과 CSV 의 A3 → ['표' => [칸 이름…(운영 순서)]] */
function tdb_schema_columns(array $checks): array
{
    if (!isset($checks['A3 컬럼']['data']) || !is_array($checks['A3 컬럼']['data'])) {
        tdb_stop('점검 CSV 에 A3 컬럼 결과가 없습니다(10_refresh_checks.sql 결과인지 확인).');
    }
    $cols = [];
    foreach ($checks['A3 컬럼']['data'] as $c) {
        $cols[(string) $c['t']][(int) $c['pos']] = (string) $c['col'];
    }
    foreach ($cols as &$list) { ksort($list); $list = array_values($list); }
    return $cols;
}
