<?php
declare(strict_types=1);
/**
 * 운영 내보내기 SELECT(02) 만들기 (scripts/testdb/make-export-sql.php, 2026-11-23)
 *
 * 입력: 10_refresh_checks.sql 결과 CSV(운영 구조 A2 테이블·A3 컬럼) — 사용자가 phpMyAdmin 에서 먼저 실행
 * 출력: 표준출력으로 02 SELECT(phpMyAdmin [SQL] 탭에 그대로 붙여 넣음). 칸 목록은 운영 A3 그대로라 새 칸이 생겨도 손으로 고치지 않는다.
 *       민감 칸은 SELECT 안에서 값 자체를 0/NULL 로 바꾼다(tdb_export_policy — 매입가·마진율·공급가 0, 업체 연락처·메모 NULL).
 *       상품은 결과 칸 크기를 줄이려고 ID 350 개씩 나눈다(AUTO_INCREMENT 기준).
 * 사용: php scripts/testdb/make-export-sql.php <10.csv> > 02_YYYYMMDD.sql
 * 금지: DB 접속 없음(파일 변환만). 생성한 SQL 에 데이터는 없다(칸 이름만).
 */

require __DIR__ . '/common.php';

$csv = $argv[1] ?? '';
if ($csv === '') {
    fwrite(STDERR, "사용법: php scripts/testdb/make-export-sql.php <10.csv>\n");
    exit(2);
}
$checks = tdb_read_csv($csv);
$schema = tdb_schema_columns($checks);
$ai = [];
foreach ($checks['A2 테이블']['data'] ?? [] as $t) { $ai[(string) $t['t']] = (int) ($t['ai'] ?? 0); }

$q = static fn(string $id): string => '`' . str_replace('`', '``', $id) . '`';
$obj = static function (string $table, array $cols) use ($q): string {
    $parts = [];
    foreach ($cols as $c) {
        $v = match (tdb_export_policy($table, $c)) { 'zero' => '0', 'null' => 'NULL', default => $q($c) };
        $parts[] = "'" . $c . "', " . $v;
    }
    return 'JSON_ARRAYAGG(JSON_OBJECT(' . implode(', ', $parts) . '))';
};

$blocks = [];
foreach (TDB_EXPORT_TABLES as $key => [$table, $need]) {
    if (!isset($schema[$table])) {
        if ($key === 'E9') { continue; }   // 규격 이름표 표는 없어도 된다(없으면 빼고 만든다)
        tdb_stop("운영 구조에 {$table} 표가 없습니다.");
    }
    if ($miss = array_diff($need, $schema[$table])) {
        tdb_stop("{$table} 에 필수 칸이 없습니다: " . implode(', ', $miss));
    }
    $json = $obj($table, $schema[$table]);
    if ($table === 'products') {
        $max = max($ai['products'] ?? 0, 1);
        for ($lo = 1, $n = 1; $lo <= $max; $lo += 350, $n++) {
            $hi = $lo + 349;
            $where = $lo + 350 > $max ? "id >= {$lo}" : "id BETWEEN {$lo} AND {$hi}";   // 마지막 구간은 끝을 열어 둠(그 사이 새 상품)
            $blocks[] = "SELECT '{$key} {$table} {$n}' AS blk, COUNT(*) AS cnt,\n       {$json} AS result\n  FROM {$table} WHERE {$where}";
        }
        continue;
    }
    $blocks[] = "SELECT '{$key} {$table}' AS blk, COUNT(*) AS cnt,\n       {$json} AS result\n  FROM {$table}";
}

$zeroed = $nulled = [];
foreach (TDB_EXPORT_TABLES as [$table]) {
    foreach ($schema[$table] ?? [] as $c) {
        $p = tdb_export_policy($table, $c);
        if ($p === 'zero') { $zeroed[] = "{$table}.{$c}"; }
        if ($p === 'null') { $nulled[] = "{$table}.{$c}"; }
    }
}
echo "-- ============================================================================\n";
echo "-- 02. 운영 → 테스트 DB 데이터 내보내기 SELECT — scripts/testdb/make-export-sql.php 가 운영 구조(" . basename($csv) . ")로 만듦\n";
echo "-- 실행: 닷홈 phpMyAdmin → 사이트 DB → [SQL] 탭에 통째로 붙여 넣고 [실행] → 결과 표(blk·cnt·result, " . count($blocks) . "줄)\n";
echo "--       → [쿼리 결과 작업 → 내보내기] → 형식 CSV → 파일 1개를 Claude 세션에 첨부\n";
echo "-- 원칙: SELECT만(운영 데이터 변경 없음). 민감 항목은 여기서 값 자체를 바꿔 내보낸다(원본 값은 운영 밖으로 나가지 않음)\n";
echo "--   0 으로: " . implode(', ', $zeroed) . "\n";
echo "--   NULL 로: " . implode(', ', $nulled) . "\n";
echo "-- 내보내지 않는 표(구조만): 회원·견적·주문·결제·현장·상담 기록·검색 기록·업체 원본(syc_*) 등 위에 없는 표 전부\n";
echo "-- ============================================================================\n\n";
echo implode("\nUNION ALL\n", $blocks), ";\n";
