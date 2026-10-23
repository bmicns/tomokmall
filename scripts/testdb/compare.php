<?php
declare(strict_types=1);
/**
 * 테스트 DB ↔ 운영 점검 결과 대조 (scripts/testdb/compare.php)
 *
 * 책임: 운영에 실행했던 docs/03-analysis/testdb/01_prod_readonly_checks.sql 을 로컬 테스트 DB에 그대로 실행하고,
 *       운영 결과 CSV(1부·2부)와 블록별로 비교해 일치/불일치를 출력한다.
 * 사용: php scripts/testdb/compare.php <1부.csv> [P1.csv P2.csv ...]
 * 비교 제외(설계상 다름): A1 배포판 문구(버전만 비교), A2 행 수(구조만 복제한 테이블은 0행)
 * 안전장치: common.php 가드(로컬 '_test' DB만). SELECT만 실행.
 */

require __DIR__ . '/common.php';

if (count($argv) < 2) {
    fwrite(STDERR, "사용법: php scripts/testdb/compare.php <1부.csv> [P*.csv ...]\n");
    exit(2);
}
$prod = [];
foreach (array_slice($argv, 1) as $f) {
    $prod += tdb_read_csv($f);
}

$pdo = tdb_test_pdo();
$sql = (string) file_get_contents(__DIR__ . '/../../docs/03-analysis/testdb/01_prod_readonly_checks.sql');
$sql = preg_replace('/^\s*--.*$/m', '', $sql);                 // 주석 줄 제거(값 안에는 '--' 없음)
$test = [];
foreach (array_filter(array_map('trim', explode(";\n", $sql . "\n"))) as $stmt) {
    try {
        foreach ($pdo->query($stmt)->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $test[$r['blk']] = $r['result'] === null ? null : json_decode((string) $r['result'], true, 512, JSON_BIGINT_AS_STRING);
        }
    } catch (\Throwable $e) {
        tdb_line('  (테스트 DB 실행 실패: ' . mb_substr($e->getMessage(), 0, 120) . ')');
    }
}

/** 순서 무관 비교용 정규화: 배열 목록은 원소를 JSON 문자열로 정렬, 숫자는 문자열화 */
function tdb_norm($v)
{
    if (is_array($v)) {
        if (array_is_list($v)) {
            $items = array_map(static fn($x) => json_encode(tdb_norm($x), JSON_UNESCAPED_UNICODE), $v);
            sort($items);
            return $items;
        }
        ksort($v);
        return array_map('tdb_norm', $v);
    }
    return $v === null ? null : (string) $v;
}

$ok = $bad = 0;
foreach ($prod as $blk => $p) {
    $pv = $p['data'];
    $tv = $test[$blk] ?? '(없음)';
    if (str_starts_with($blk, 'A1')) {
        $pv = ['version' => $pv['version'] ?? null];
        $tv = ['version' => is_array($tv) ? ($tv['version'] ?? null) : null];
    } elseif (str_starts_with($blk, 'A2')) {
        $strip = static fn($list) => is_array($list) ? array_map(static fn($t) => array_diff_key($t, ['rows' => 1, 'ai' => 1]), $list) : $list;
        $pv = $strip($pv);
        $tv = $strip($tv);
    }
    $same = tdb_norm($pv) === tdb_norm($tv);
    $same ? $ok++ : $bad++;
    tdb_line(sprintf('  %s %s', $same ? '✓' : '✗', $blk));
    if (!$same && !is_array($pv)) {
        tdb_line('      운영=' . json_encode($pv, JSON_UNESCAPED_UNICODE) . ' / 테스트=' . json_encode($tv, JSON_UNESCAPED_UNICODE));
    } elseif (!$same && is_array($pv) && is_array($tv) && !array_is_list($pv)) {
        foreach ($pv as $k => $v) {
            if (tdb_norm($v) !== tdb_norm($tv[$k] ?? null)) {
                tdb_line("      {$k}: 운영=" . json_encode($v, JSON_UNESCAPED_UNICODE) . ' / 테스트=' . json_encode($tv[$k] ?? null, JSON_UNESCAPED_UNICODE));
            }
        }
    } elseif (!$same && is_array($pv) && is_array($tv)) {
        $a = tdb_norm($pv);
        $b = tdb_norm($tv);
        $onlyP = array_values(array_diff($a, $b));
        $onlyT = array_values(array_diff($b, $a));
        tdb_line('      운영에만 ' . count($onlyP) . '건, 테스트에만 ' . count($onlyT) . '건' . ($onlyP ? ' 예: ' . mb_substr($onlyP[0], 0, 200) : ''));
        if ($onlyT) { tdb_line('      테스트 예: ' . mb_substr($onlyT[0], 0, 200)); }
    }
}
tdb_line("결과: 일치 {$ok} / 불일치 {$bad}");
exit($bad > 0 ? 1 : 0);
