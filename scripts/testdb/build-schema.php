<?php
declare(strict_types=1);
/**
 * 운영 구조 → 테스트 DB CREATE TABLE 생성 (scripts/testdb/build-schema.php)
 *
 * 입력: 운영 읽기 전용 점검(docs/03-analysis/testdb/01_prod_readonly_checks.sql) 1부 결과 CSV
 *       — A2 테이블·A3 컬럼·A4 인덱스·A5 외래키(information_schema)
 * 출력: 표준출력으로 DDL(데이터 없음). 운영과 같은 테이블·컬럼·타입·기본값·문자셋·인덱스·외래키·AUTO_INCREMENT.
 *       FULLTEXT 의 ngram 파서는 information_schema 에 나오지 않으므로 idx_ngram_* 이름 규칙(db_setup_ngram.php)으로 붙인다.
 * 사용: php scripts/testdb/build-schema.php <1부.csv> > prod_schema.sql
 * 금지: DB 접속 없음(파일 변환만).
 */

require __DIR__ . '/common.php';

$csv = $argv[1] ?? '';
if ($csv === '') {
    fwrite(STDERR, "사용법: php scripts/testdb/build-schema.php <1부.csv>\n");
    exit(2);
}
$d = tdb_read_csv($csv);
foreach (['A2 테이블', 'A3 컬럼', 'A4 인덱스', 'A5 외래키'] as $k) {
    if (!isset($d[$k]['data'])) {
        tdb_stop("CSV에 {$k} 결과가 없습니다.");
    }
}

$q  = static fn(string $id): string => '`' . str_replace('`', '``', $id) . '`';
$lit = static fn(?string $v): string => "'" . str_replace(["\\", "'"], ["\\\\", "''"], (string) $v) . "'";

$cols = $idx = $fks = [];
foreach ($d['A3 컬럼']['data'] as $c) { $cols[$c['t']][(int) $c['pos']] = $c; }
foreach ($d['A4 인덱스']['data'] as $i) { $idx[$i['t']][$i['idx']][(int) $i['seq']] = $i; }
foreach ($d['A5 외래키']['data'] as $f) { $fks[$f['t']][$f['fk']][] = $f; }

$tables = $d['A2 테이블']['data'];
usort($tables, static fn($a, $b) => strcmp($a['t'], $b['t']));

echo "-- 운영 구조 복제 DDL (데이터 없음). 생성: scripts/testdb/build-schema.php\n";
echo "-- 원본: 운영 information_schema 점검 결과(" . basename($csv) . ")\n";
echo "SET FOREIGN_KEY_CHECKS = 0;\n\n";

foreach ($tables as $t) {
    $name = $t['t'];
    $defs = [];
    ksort($cols[$name]);
    foreach ($cols[$name] as $c) {
        $extra = strtolower((string) $c['extra']);
        $s = $q($c['col']) . ' ' . $c['type'];
        if ($c['collation']) {
            $s .= ' CHARACTER SET ' . explode('_', $c['collation'])[0] . ' COLLATE ' . $c['collation'];
        }
        $s .= $c['null'] === 'YES' ? ' NULL' : ' NOT NULL';
        if ($c['def'] !== null) {
            if (str_contains($extra, 'default_generated')) {
                // 식 기본값: CURRENT_TIMESTAMP 는 그대로, 그 밖의 식은 괄호
                $s .= preg_match('/^current_timestamp(\(\d*\))?$/i', $c['def']) ? ' DEFAULT ' . $c['def'] : ' DEFAULT (' . $c['def'] . ')';
            } else {
                $s .= ' DEFAULT ' . $lit($c['def']);
            }
        }
        if (str_contains($extra, 'auto_increment')) { $s .= ' AUTO_INCREMENT'; }
        if (preg_match('/on update (current_timestamp(\(\d*\))?)/i', (string) $c['extra'], $m)) { $s .= ' ON UPDATE ' . strtoupper($m[1]); }
        if ((string) $c['comment'] !== '') { $s .= ' COMMENT ' . $lit($c['comment']); }
        $defs[] = '  ' . $s;
    }
    foreach ($idx[$name] ?? [] as $iname => $parts) {
        ksort($parts);
        $first = reset($parts);
        $list  = implode(', ', array_map(static fn($p) => $q($p['col']) . ($p['sub'] ? '(' . (int) $p['sub'] . ')' : ''), $parts));
        if ($iname === 'PRIMARY') {
            $defs[] = "  PRIMARY KEY ({$list})";
        } elseif ($first['type'] === 'FULLTEXT') {
            $defs[] = '  FULLTEXT KEY ' . $q($iname) . " ({$list})" . (str_starts_with($iname, 'idx_ngram') ? ' WITH PARSER ngram' : '');
        } else {
            $defs[] = '  ' . ((int) $first['non_unique'] === 0 ? 'UNIQUE KEY ' : 'KEY ') . $q($iname) . " ({$list})";
        }
    }
    foreach ($fks[$name] ?? [] as $fname => $parts) {
        $defs[] = '  CONSTRAINT ' . $q($fname) . ' FOREIGN KEY (' . implode(', ', array_map(static fn($p) => $q($p['col']), $parts)) . ')'
            . ' REFERENCES ' . $q($parts[0]['ref_t']) . ' (' . implode(', ', array_map(static fn($p) => $q($p['ref_col']), $parts)) . ')'
            . ' ON DELETE ' . $parts[0]['on_delete'] . ' ON UPDATE ' . $parts[0]['on_update'];
    }
    $coll = (string) $t['collation'];
    echo 'CREATE TABLE ' . $q($name) . " (\n" . implode(",\n", $defs) . "\n) ENGINE=" . $t['engine']
        . ' DEFAULT CHARSET=' . explode('_', $coll)[0] . ' COLLATE=' . $coll
        . ($t['ai'] !== null ? ' AUTO_INCREMENT=' . (int) $t['ai'] : '') . ";\n\n";
}
echo "SET FOREIGN_KEY_CHECKS = 1;\n";
