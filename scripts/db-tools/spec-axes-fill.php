<?php
declare(strict_types=1);
/**
 * 규격 축 새 칸 채우기 (scripts/db-tools/spec-axes-fill.php) — CLI 전용
 *
 * 책임: category_spec_axes 이름표가 있는 카테고리(하위 상속 포함)의 상품마다 spec 의 '규격:' 값을 읽어
 *       products.spec_code·dim_*·spec_option·spec_dims_src 를 계산한다. 규칙은 app/spec_axes.php(spec_axes_plan)와 같다.
 *   php scripts/db-tools/spec-axes-fill.php report [--category=24,21]          읽기 전용: 카테고리별 결과 + 못 읽은 상품 목록
 *   php scripts/db-tools/spec-axes-fill.php apply  [--category=…]              로컬 테스트 DB(*_test @ 127.0.0.1)에만 반영
 *   php scripts/db-tools/spec-axes-fill.php sql --out=<파일> [--category=…]    운영 반영용 UPDATE 파일 만들기(DB 는 읽기만)
 *       각 줄은 WHERE id=… AND spec=<지금 규격 원문> — 운영의 원문이 다르면 그 줄은 0행(엉뚱한 값이 들어가지 않음).
 *       운영 복제 DB(devdb)에서 만들어 사용자 승인 후 원격 관리 API(db stage-sql) 또는 phpMyAdmin 으로 실행.
 * 금지: 운영 DB 직접 접속·쓰기, 기존 칸(spec 등)·가격 칸 변경, 못 읽은 규격을 추측해 채우기(목록으로 보고만 한다).
 * 전제: 대상 DB 에 database/20261106_product_spec_axes.sql 반영. 결과 파일은 저장소 밖(scratchpad 등)에 둔다.
 */

require __DIR__ . '/../testdb/common.php';   // CLI 확인 + bootstrap + 로컬 _test DB 가드(tdb_test_pdo)

$mode = (string) ($argv[1] ?? '');
$opt  = ['category' => '', 'out' => ''];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--(category|out)=(.*)$/', $a, $m)) { $opt[$m[1]] = $m[2]; }
    else { tdb_stop("알 수 없는 인자: {$a}"); }
}
if (!in_array($mode, ['report', 'apply', 'sql'], true)) {
    fwrite(STDERR, "사용법: php scripts/db-tools/spec-axes-fill.php report|apply|sql [--category=24,21] [--out=파일(sql 전용)]\n");
    exit(2);
}
if ($mode === 'sql' && $opt['out'] === '') { tdb_stop('sql 은 --out=<파일> 이 필요합니다.'); }

$pdo = $mode === 'apply' ? tdb_test_pdo() : db();
if (!$pdo) { tdb_stop('DB 접속 실패'); }
if (!spec_axes_ready()) { tdb_stop('이 DB 에 새 칸·category_spec_axes 가 없습니다 — database/20261106_product_spec_axes.sql 먼저.'); }

// 대상 카테고리: 이름표가 있는(상속 포함) 카테고리. --category 를 주면 그 카테고리와 하위만
$names = [];
foreach ($pdo->query('SELECT id, name FROM categories')->fetchAll() as $c) { $names[(int) $c['id']] = (string) $c['name']; }
$scope = null;
if ($opt['category'] !== '') {
    $scope = [];
    foreach (array_filter(array_map('intval', explode(',', $opt['category']))) as $cid) {
        foreach (category_descendants($cid) as $d) { $scope[$d] = true; }
    }
}
$axesOf = [];
foreach (array_keys($names) as $cid) {
    if ($scope !== null && !isset($scope[$cid])) { continue; }
    $ax = spec_axes_for_category($cid);
    if ($ax) { $axesOf[$cid] = $ax; }
}
if (!$axesOf) { tdb_stop('이름표가 있는 카테고리가 없습니다(category_spec_axes 확인).'); }

$ph = implode(',', array_fill(0, count($axesOf), '?'));
$st = $pdo->prepare('SELECT id, category_id, name, is_active, spec, ' . implode(', ', SPEC_AXES_NEW_COLUMNS)
                  . " FROM products WHERE category_id IN ({$ph}) ORDER BY category_id, id");
$st->execute(array_keys($axesOf));
$rows = $st->fetchAll();

$fmtDims = static function (array $plan, array $axes): string {
    $out = [];
    if ($plan['spec_code'] !== null) { $out[] = '규격 코드 ' . $plan['spec_code']; }
    foreach ($axes as $a) {
        $col = SPEC_AXIS_COLUMNS[$a['axis_key']] ?? null;   // skip(저장 안 하는 숫자 자리)는 칸 없음
        $v   = $col !== null ? ($plan[$col] ?? null) : null;
        if ($v !== null) { $out[] = spec_axis_title($a) . ' ' . spec_axis_display((float) $v, $a['unit']); }
    }
    if ($plan['spec_option'] !== null) { $out[] = '옵션 ' . $plan['spec_option']; }
    return implode(' · ', $out);
};

$byCat = []; $changes = [];
foreach ($rows as $p) {
    $cid   = (int) $p['category_id'];
    $axes  = $axesOf[$cid];
    $value = parse_spec_field((string) ($p['spec'] ?? ''))['spec'];
    $parsed = spec_axes_parse($value, $axes);
    $plan   = spec_axes_plan($p, $axes);
    if ($parsed !== null) {
        $kind = $plan === null ? 'same' : 'fill';
    } elseif ($plan === null && spec_axes_row_fresh($p)) {
        $kind = 'manual';     // 규칙으로는 못 읽지만 같은 원문에 사람이 넣은 값 — 유지
    } else {
        $kind = 'fail';
    }
    $byCat[$cid][$kind][] = ['p' => $p, 'value' => $value, 'plan' => $plan];
    if ($plan !== null) { $changes[] = ['p' => $p, 'plan' => $plan, 'value' => $value]; }
}

// ── 보고(모든 모드 공통) ─────────────────────────────────────────
tdb_line("규격 축 채우기 — {$mode} (DB: " . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . ')');
$tot = ['fill' => 0, 'same' => 0, 'manual' => 0, 'fail' => 0];
foreach ($axesOf as $cid => $axes) {
    $k = $byCat[$cid] ?? [];
    $n = array_sum(array_map('count', $k));
    if ($n === 0) { continue; }
    foreach ($tot as $t => $_) { $tot[$t] += count($k[$t] ?? []); }
    tdb_line();
    tdb_line(sprintf('[%d] %s — 이름표: %s', $cid, $names[$cid] ?? '?', implode(' × ', array_map('spec_axis_title', array_filter($axes, static fn($a) => $a['axis_key'] !== SPEC_AXIS_SKIP)))));
    tdb_line(sprintf('    상품 %d: 채움 %d · 그대로 %d · 수동값 유지 %d · 못 읽음 %d',
        $n, count($k['fill'] ?? []), count($k['same'] ?? []), count($k['manual'] ?? []), count($k['fail'] ?? [])));
    foreach (array_slice($k['fill'] ?? [], 0, 3) as $r) {
        tdb_line(sprintf('    ✓ id=%d %s → %s', $r['p']['id'], $r['value'], $fmtDims($r['plan'], $axes)));
    }
    foreach ($k['fail'] ?? [] as $r) {
        tdb_line(sprintf('    ✗ id=%d%s 못 읽음: %s', $r['p']['id'], (int) $r['p']['is_active'] === 1 ? '' : '(비활성)', $r['value'] === '' ? '(규격 없음)' : $r['value']));
    }
}
tdb_line();
tdb_line(sprintf('합계: 채움 %d · 그대로 %d · 수동값 유지 %d · 못 읽음 %d (바뀌는 행 %d)', $tot['fill'], $tot['same'], $tot['manual'], $tot['fail'], count($changes)));
if ($tot['fail'] > 0) { tdb_line('못 읽은 상품은 채우지 않았습니다 — 이름표(숫자 개수·순서)를 확인하거나 사람이 값을 넣어야 합니다.'); }

if ($mode === 'report' || !$changes) { exit(0); }

$setSql = implode(', ', array_map(static fn($c) => "{$c} = ?", SPEC_AXES_NEW_COLUMNS));

if ($mode === 'apply') {
    $up = $pdo->prepare("UPDATE products SET {$setSql} WHERE id = ?");
    $pdo->beginTransaction();
    try {
        foreach ($changes as $c) { $up->execute(array_merge(array_values($c['plan']), [(int) $c['p']['id']])); }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        tdb_stop('반영 실패(되돌림): ' . get_class($e));
    }
    tdb_line("반영: {$tot['fill']}행 채움" . (count($changes) - $tot['fill'] > 0 ? ', ' . (count($changes) - $tot['fill']) . '행 비움' : '') . ' (로컬 테스트 DB)');
    exit(0);
}

// sql: 운영 반영용 파일 — 값은 이 DB 연결의 quote 로 이스케이프
$q   = static fn($v): string => $v === null ? 'NULL' : $pdo->quote((string) $v);
$out = [];
$out[] = '-- 규격 축 새 칸 채우기(products.spec_code·dim_*·spec_option·spec_dims_src) — scripts/db-tools/spec-axes-fill.php sql';
$out[] = '-- 만든 곳: ' . (string) $pdo->query('SELECT DATABASE()')->fetchColumn() . ' / ' . date('Y-m-d H:i') . ' / 바뀌는 행 ' . count($changes);
$out[] = '-- 전제: database/20261106_product_spec_axes.sql 반영. 새 칸만 바꾼다(spec·가격 칸 그대로).';
$out[] = '-- 각 줄은 id 와 지금 규격 원문(spec)이 모두 같을 때만 반영된다 — 운영 원문이 바뀌었으면 0행.';
$out[] = '-- 되돌리기: UPDATE products SET ' . implode(' = NULL, ', SPEC_AXES_NEW_COLUMNS) . ' = NULL WHERE id IN (' . implode(',', array_map(static fn($c) => (int) $c['p']['id'], $changes)) . ');';
$out[] = '';
foreach ($changes as $c) {
    $sets = [];
    foreach (SPEC_AXES_NEW_COLUMNS as $col) { $sets[] = "{$col} = " . $q($c['plan'][$col]); }
    $out[] = 'UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ' . (int) $c['p']['id'] . ' AND spec = ' . $q($c['p']['spec']) . ';';
}
$out[] = '';
$out[] = '-- 확인(읽기 전용): 채운 행 수';
$out[] = 'SELECT COUNT(*) AS filled FROM products WHERE spec_dims_src IS NOT NULL;';
if (file_put_contents($opt['out'], implode("\n", $out) . "\n") === false) { tdb_stop('파일 쓰기 실패: ' . $opt['out']); }
tdb_line('SQL 파일: ' . $opt['out'] . ' (' . count($changes) . '줄)');
