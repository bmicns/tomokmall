<?php
declare(strict_types=1);
/**
 * 운영 내보내기 CSV → 로컬 테스트 DB 적재 (scripts/testdb/load-export.php)
 *
 * 입력: docs/03-analysis/testdb/02_prod_export_selects.sql 결과 CSV(blk·cnt·result)
 * 책임: 적재 전 검사(허용 테이블·컬럼, 건수, 민감 항목 NULL/0, 이메일·전화번호 형태 문자열) → 통과 시에만 적재
 * 사용: php scripts/testdb/load-export.php <02.csv>                    검사만(기본, DB 변경 없음)
 *       php scripts/testdb/load-export.php <02.csv> --apply            검사 통과 시 6개 테이블 내용을 교체 적재
 *       ... --apply --mask-contacts                                     상품 설명 등의 연락처 형태 문자열을 '[비식별]'로 바꿔 적재
 * 안전장치: CLI + DB 이름 '_test' + 로컬 호스트일 때만(common.php). 운영 DB 접속 경로 없음.
 * 금지: 값 출력 금지(검사 결과는 테이블·컬럼·건수·ID만).
 */

require __DIR__ . '/common.php';

$csv   = $argv[1] ?? '';
$apply = in_array('--apply', $argv, true);
$mask  = in_array('--mask-contacts', $argv, true);
if ($csv === '') {
    fwrite(STDERR, "사용법: php scripts/testdb/load-export.php <02.csv> [--apply] [--mask-contacts]\n");
    exit(2);
}

// 테이블별 허용 컬럼(운영 A3 기준, 02 SELECT 와 같은 순서)과 강제값(내보낼 때 NULL/0 처리된 항목)
const TDB_TABLES = [
    'E1' => ['categories', ['id', 'parent_id', 'name', 'description', 'slug', 'sort_order', 'is_active', 'created_at', 'discount_customer', 'discount_corporate'],
             ['discount_customer' => 0, 'discount_corporate' => 0]],
    'E2' => ['products', ['id', 'category_id', 'name', 'brand', 'spec', 'unit', 'weight_kg', 'main_category', 'description', 'price_b2c', 'price_b2b',
                          'stock_qty', 'external_source', 'external_id', 'source_url', 'image_url', 'is_active', 'created_at', 'cost_price'],
             ['cost_price' => 0]],
    'E3' => ['category_relations', ['id', 'source_category_id', 'target_category_id', 'relation_type', 'display_order', 'is_active'], []],
    'E4' => ['vendors', ['id', 'name', 'contact', 'phone', 'fax', 'email', 'region', 'address', 'memo', 'is_active', 'created_at', 'updated_at'],
             ['contact' => null, 'phone' => null, 'fax' => null, 'email' => null, 'address' => null, 'memo' => null]],
    'E5' => ['product_vendors', ['id', 'product_id', 'vendor_id', 'supply_price', 'lead_days', 'is_primary', 'memo', 'updated_at'],
             ['supply_price' => 0, 'memo' => null]],
    'E6' => ['category_gallery', ['id', 'category_id', 'image_url', 'caption', 'sort_order'], []],
];
// 연락처 형태 검사에서 뺄 컬럼(URL·식별자: 긴 숫자열 오탐)
const TDB_SCAN_SKIP = ['slug', 'image_url', 'source_url', 'external_id', 'external_source', 'created_at', 'updated_at'];
const TDB_EMAIL = '/[[:alnum:]._%+-]+@[[:alnum:].-]+\.[[:alpha:]]{2,}/u';
const TDB_PHONE = '/(?<![0-9])0[0-9]{1,2}[- .]?[0-9]{3,4}[- .]?[0-9]{4}(?![0-9])/u';

$in = tdb_read_csv($csv);
$rows = $errors = $hits = [];
foreach ($in as $blk => $r) {
    $key = substr($blk, 0, 2);
    if (!isset(TDB_TABLES[$key])) {
        tdb_stop("허용되지 않은 결과 블록: {$blk}");
    }
    [$table, $cols, $forced] = TDB_TABLES[$key];
    $data = $r['data'] ?? [];
    if (!is_array($data) || $r['cnt'] === null || count($data) !== $r['cnt']) {
        $errors[] = "{$blk}: 건수 불일치(cnt={$r['cnt']}, 실제 " . (is_array($data) ? count($data) : 0) . ') — 결과가 잘렸을 수 있음';
        continue;
    }
    foreach ($data as $row) {
        $id = $row['id'] ?? '?';
        $keys = array_keys($row);
        sort($keys);
        $want = $cols;
        sort($want);
        if ($keys !== $want) {
            $errors[] = "{$table} id={$id}: 컬럼 구성이 다름";
            continue;
        }
        foreach ($forced as $c => $v) {
            if ($row[$c] !== $v && !($v === 0 && (string) $row[$c] === '0')) {
                $errors[] = "{$table} id={$id}: {$c} 가 비식별 처리되지 않음";
            }
        }
        foreach ($row as $c => $v) {
            if (!is_string($v) || in_array($c, TDB_SCAN_SKIP, true)) {
                continue;
            }
            foreach (['email' => TDB_EMAIL, 'phone' => TDB_PHONE] as $kind => $re) {
                if (preg_match($re, $v)) {
                    $hits["{$table}.{$c} {$kind}"][] = $id;
                    if ($mask) {
                        $row[$c] = $v = (string) preg_replace($re, '[비식별]', $v);
                    }
                }
            }
        }
        $rows[$table][] = $row;
    }
}

tdb_line('[검사]');
foreach (TDB_TABLES as [$table]) {
    tdb_line(sprintf('  %-20s %5d행', $table, count($rows[$table] ?? [])));
}
foreach ($errors as $e) {
    tdb_line("  ✗ {$e}");
}
foreach ($hits as $k => $ids) {
    tdb_line("  ! 연락처 형태 문자열 {$k}: " . count($ids) . '건 (ID ' . implode(',', array_slice($ids, 0, 20)) . (count($ids) > 20 ? ' …' : '') . ')'
        . ($mask ? ' → [비식별] 치환' : ''));
}
if ($errors) {
    tdb_stop('검사 실패 — 적재하지 않았습니다.');
}
if ($hits && !$mask) {
    tdb_stop('연락처 형태 문자열이 있습니다. 내용 확인 후 --mask-contacts 로 치환 적재하거나 원인을 보고하세요.');
}
tdb_line('  ✓ 검사 통과');
if (!$apply) {
    tdb_line('검사만 실행했습니다(--apply 없음). DB는 변경하지 않았습니다.');
    exit(0);
}

// 적재: 대상 6개 테이블 내용을 교체(FK 검사 잠시 해제 — 운영 ID 그대로 넣기 위해)
$pdo = tdb_test_pdo();
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach (TDB_TABLES as [$table, $cols]) {
    $pdo->exec("DELETE FROM `{$table}`");
    $list = $rows[$table] ?? [];
    $ph   = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
    foreach (array_chunk($list, 200) as $chunk) {
        $st = $pdo->prepare("INSERT INTO `{$table}` (`" . implode('`,`', $cols) . '`) VALUES ' . implode(',', array_fill(0, count($chunk), $ph)));
        $vals = [];
        foreach ($chunk as $row) {
            foreach ($cols as $c) {
                $v = $row[$c];
                // JSON_OBJECT 의 DATETIME 은 'YYYY-MM-DD HH:MM:SS.ffffff' → 소수부 제거
                $vals[] = is_string($v) && preg_match('/^\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\.\d+$/', $v) ? substr($v, 0, 19) : $v;
            }
        }
        $st->execute($vals);
    }
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
tdb_line('[적재 완료]');
foreach (TDB_TABLES as [$table]) {
    tdb_line(sprintf('  %-20s %5d행', $table, (int) $pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn()));
}
