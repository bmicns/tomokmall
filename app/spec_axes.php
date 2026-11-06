<?php
declare(strict_types=1);
/**
 * 상품 규격 구조화(products.spec_code·dim_*·spec_option·spec_dims_src + category_spec_axes) 헬퍼.
 *
 * 책임: 카테고리별 축 이름표(category_spec_axes)로 spec 의 '규격:' 값을 축별 숫자(mm)로 나누고,
 *       상품 목록 표·좌측 필터가 그 값을 쓰도록 이름표·값·표시 문자열을 제공한다.
 *  - spec_axes_parse($value, $axes)  순수 함수. 이름표와 숫자 개수·표기가 정확히 맞을 때만 값, 아니면 null(추측하지 않음)
 *  - spec_axes_plan($row, $axes)     순수 함수. 상품 1행의 새 칸을 어떻게 바꿀지(null = 그대로)
 *  - spec_axes_resolve($id, …)       순수 함수. 카테고리 → 가장 가까운 상위의 이름표(none 이면 없음)
 *  - spec_axes_ready()               새 칸·표가 DB 에 있는지. 없으면 화면은 기존 규격 원문 방식 그대로
 *  - spec_axes_common($ids)          여러 카테고리가 같은 이름표를 쓸 때만 그 이름표(목록·필터 축 모드 판단)
 *  - spec_axes_row_fresh($row)       채운 기준 원문 = 지금 규격 원문일 때만 화면에 축 값 사용
 *  - spec_axes_sync_product($id)     상품 1개 새 칸 다시 계산(관리자 상품 저장 시)
 * 금지: 기존 칸(spec 등)·가격 칸 수정. 새 칸(SPEC_AXES_NEW_COLUMNS) 외에는 쓰지 않는다.
 * DB 반영: database/20261106_product_spec_axes.sql, 값 채우기: scripts/db-tools/spec-axes-fill.php
 */

/** 축 키 → products 칸 */
const SPEC_AXIS_COLUMNS = ['d' => 'dim_d', 'w' => 'dim_w', 'h' => 'dim_h', 'l' => 'dim_l', 't' => 'dim_t', 't2' => 'dim_t2'];

/** 이 기능이 쓰는 products 새 칸(전부 있어야 사용) */
const SPEC_AXES_NEW_COLUMNS = ['spec_code', 'dim_d', 'dim_w', 'dim_h', 'dim_l', 'dim_t', 'dim_t2', 'spec_option', 'spec_dims_src'];

/** 목록 필터 '모델 코드'(규격 코드 끝 글자 B·C) 축 키 */
const SPEC_AXIS_TYPE = 'type';

/**
 * '규격:' 값 → ['spec_code'=>?string, 'dims'=>[축키=>mm(float)], 'option'=>?string]. 규칙에 안 맞으면 null.
 *  - 앞부분 숫자 묶음('*' 또는 '_' 로 이어진 숫자)을 이름표 seq 순서로 읽는다. 숫자 개수가 이름표와 다르면 null.
 *    숫자 뒤 글자는 T·H·M·mm 만 숫자의 일부로 본다(다른 글자가 붙은 '0RS'·'300용' 은 숫자가 아님).
 *  - 'D' 접두는 d(직경) 축만, 'T' 접미는 t·t2 축만, 'H' 접미는 h 축만 허용. 'M'(미터)·'MM' 접미는 단위로 읽는다.
 *  - 이름표 unit='m' 이면 ×1000 해서 mm 로. mm 소수 1자리를 넘으면 null.
 *  - 숫자 묶음 바로 뒤 괄호 코드('(900B)')는 spec_code, 그 뒤 나머지(앞의 '_'·공백 제거)는 option.
 *
 * @param list<array{seq:int, axis_key:string, unit?:string}> $axes
 */
function spec_axes_parse(string $value, array $axes): ?array
{
    $value = trim($value);
    $axes  = array_values(array_filter($axes, static fn($a) => isset(SPEC_AXIS_COLUMNS[(string) ($a['axis_key'] ?? '')])));
    if ($value === '' || !$axes) { return null; }
    usort($axes, static fn($a, $b) => (int) $a['seq'] <=> (int) $b['seq']);

    // 숫자 1개: D접두? + 숫자 + (T|H|M|mm)? — 바로 뒤에 다른 글자·숫자가 붙어 있으면 숫자로 보지 않음('_0RS', '300용' 등은 옵션)
    $tok = 'D?\d+(?:\.\d+)?(?:mm|MM|[TtHhMm])?(?![A-Za-z0-9.가-힣])';
    if (!preg_match('/^' . $tok . '(?:[*_]' . $tok . ')*/u', $value, $m)) { return null; }
    $head   = $m[0];
    $rest   = (string) substr($value, strlen($head));
    $tokens = preg_split('/[*_]/', $head) ?: [];
    if (count($tokens) !== count($axes)) { return null; }

    $dims = [];
    foreach ($axes as $i => $a) {
        if (!preg_match('/^(D?)(\d+(?:\.\d+)?)([A-Za-z]*)$/', $tokens[$i], $tm)) { return null; }
        $key  = (string) $a['axis_key'];
        $unit = (($a['unit'] ?? 'mm') === 'm') ? 'm' : 'mm';
        $suf  = strtoupper($tm[3]);
        if ($tm[1] === 'D' && $key !== 'd') { return null; }
        if ($suf === 'T')      { if ($key !== 't' && $key !== 't2') { return null; } }
        elseif ($suf === 'H')  { if ($key !== 'h') { return null; } }
        elseif ($suf === 'M')  { $unit = 'm'; }
        elseif ($suf === 'MM') { $unit = 'mm'; }
        elseif ($suf !== '')   { return null; }

        $raw = (float) $tm[2] * ($unit === 'm' ? 1000 : 1);
        $x10 = $raw * 10;
        if (abs($x10 - round($x10)) > 1e-6) { return null; }      // mm 소수 1자리까지만(DECIMAL(8,1))
        $mm = round($raw, 1);
        if ($mm <= 0 || $mm > 9999999.9 || isset($dims[$key])) { return null; }
        $dims[$key] = $mm;
    }

    $code = null;
    if (preg_match('/^\s*\(\s*([A-Za-z]*\d+[A-Za-z]+)\s*\)/', $rest, $cm)) {
        $code = $cm[1];
        $rest = (string) substr($rest, strlen($cm[0]));
    }
    $opt = trim((string) preg_replace('/^[\s_]+/u', '', $rest));
    if ($opt === '') { $opt = null; }
    if (($code !== null && strlen($code) > 30) || ($opt !== null && mb_strlen($opt) > 80)) { return null; }

    return ['spec_code' => $code, 'dims' => $dims, 'option' => $opt];
}

/** mm 값 → 필터 값·비교용 정규 문자열('900', '1.6', '1500') */
function spec_axis_num(float $mm): string
{
    return rtrim(rtrim(number_format($mm, 1, '.', ''), '0'), '.');
}

/** mm 값 → 화면 표시(이름표 단위). mm: '2,000' '1.6' / m: '1.5' '2.0' */
function spec_axis_display(float $mm, string $unit): string
{
    if ($unit === 'm') {
        $s = rtrim(rtrim(number_format($mm / 1000, 3, '.', ''), '0'), '.');
        return strpos($s, '.') === false ? $s . '.0' : $s;
    }
    return number_format($mm, abs($mm - round($mm)) < 1e-9 ? 0 : 1);
}

/** 화면에 보일 약자('W') — 약자가 없거나 show_symbol=0 이면 '' */
function spec_axis_symbol(array $axis): string
{
    $show = $axis['show_symbol'] ?? true;
    return ($show === false || $show === 0 || $show === '0') ? '' : trim((string) ($axis['symbol'] ?? ''));
}

/** 이름표 → 표 머리글·카드 이름('폭 W', '상하두께') */
function spec_axis_title(array $axis): string
{
    $sym = spec_axis_symbol($axis);
    return trim((string) ($axis['label'] ?? '')) . ($sym !== '' ? ' ' . $sym : '');
}

/**
 * 옵션 화면 표시: 밑줄을 슬래시로(사용자 결정 2026-11-06). 저장값(spec_option)은 원문 그대로.
 *  '하부구체_KS' → '하부구체/KS', '곡선_타공_KS' → '곡선/타공/KS', '컬러(보도)'(밑줄 없음) → 그대로
 */
function spec_option_display(string $opt): string
{
    return implode('/', array_values(array_filter(array_map('trim', explode('_', $opt)), static fn($s) => $s !== '')));
}

/** 단위 표시 '(mm)' / '(m)' — 필터 그룹 이름 옆(아트보드: 폭 W(mm)). 표 머리글에는 단위를 쓰지 않는다 */
function spec_axis_unit_label(array $axis): string
{
    return (($axis['unit'] ?? 'mm') === 'm') ? '(m)' : '(mm)';
}

/** 규격 코드 → 끝자리 글자(필터 '모델 코드'). '900B' → 'B', 'M1000C' → 'C', 없으면 null */
function spec_code_type(?string $code): ?string
{
    return ($code !== null && preg_match('/\d([A-Z])$/', $code, $m)) ? $m[1] : null;
}

/**
 * 카테고리 → 가장 가까운(자기 → 부모 → …) 이름표. 'none' 행이 있는 카테고리에서 멈추면 [].
 *
 * @param array<int, list<array>> $rows    category_id => 이름표 행(seq 순)
 * @param array<int, int|null>    $parents id => parent_id
 */
function spec_axes_resolve(int $categoryId, array $rows, array $parents): array
{
    $cur = $categoryId; $seen = [];
    while ($cur > 0 && !isset($seen[$cur])) {
        $seen[$cur] = 1;
        if (isset($rows[$cur])) {
            return array_values(array_filter($rows[$cur], static fn($a) => isset(SPEC_AXIS_COLUMNS[(string) $a['axis_key']])));
        }
        $cur = (int) ($parents[$cur] ?? 0);
    }
    return [];
}

/**
 * 상품 1행 → 새 칸에 쓸 값(SPEC_AXES_NEW_COLUMNS 전부) 또는 null(바꿀 것 없음).
 *  - 이름표 없음: 새 칸에 값이 남아 있으면 모두 비움
 *  - 규칙으로 읽힘: 읽은 값 + spec_dims_src = 지금 규격 원문
 *  - 안 읽힘: 규격 원문이 채울 때와 달라졌으면 모두 비움(옛 값이 남지 않게), 같으면 그대로(사람이 넣은 값 보존)
 */
function spec_axes_plan(array $p, array $axes): ?array
{
    $value = parse_spec_field((string) ($p['spec'] ?? ''))['spec'];
    $cur   = [];
    foreach (SPEC_AXES_NEW_COLUMNS as $col) {
        $v = $p[$col] ?? null;
        $cur[$col] = ($v === null || $v === '') ? null : (str_starts_with($col, 'dim_') ? spec_axis_num((float) $v) : (string) $v);
    }
    $empty = array_fill_keys(SPEC_AXES_NEW_COLUMNS, null);
    $hasAny = (bool) array_filter($cur, static fn($v) => $v !== null);

    $parsed = $axes ? spec_axes_parse($value, $axes) : null;
    if ($parsed !== null) {
        $new = $empty;
        $new['spec_code'] = $parsed['spec_code'];
        foreach ($parsed['dims'] as $k => $mm) { $new[SPEC_AXIS_COLUMNS[$k]] = spec_axis_num($mm); }
        $new['spec_option']   = $parsed['option'];
        $new['spec_dims_src'] = $value;
        return $new === $cur ? null : $new;
    }
    if (!$axes || $cur['spec_dims_src'] !== $value) {
        return $hasAny ? $empty : null;
    }
    return null;
}

/** 새 칸·이름표 표가 모두 있는지(요청당 1회 확인). 없으면 목록·필터는 기존 방식 */
function spec_axes_ready(): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    $ready = false;
    $pdo = db();
    if (!$pdo) { return false; }
    try {
        $ph = implode(',', array_fill(0, count(SPEC_AXES_NEW_COLUMNS), '?'));
        $st = $pdo->prepare(
            "SELECT (SELECT COUNT(*) FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'category_spec_axes') AS t,
                    (SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME IN ($ph)) AS c"
        );
        $st->execute(SPEC_AXES_NEW_COLUMNS);
        $r = $st->fetch();
        $ready = $r && (int) $r['t'] === 1 && (int) $r['c'] === count(SPEC_AXES_NEW_COLUMNS);
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** category_id => 이름표 행 목록(seq 순, 사용 중인 것만). 요청당 1회 */
function spec_axes_rows(): array
{
    static $map = null;
    if ($map !== null) { return $map; }
    $map = [];
    if (!spec_axes_ready()) { return $map; }
    try {
        $rs = db()->query('SELECT category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter
                             FROM category_spec_axes WHERE is_active = 1 ORDER BY category_id, seq');
        foreach ($rs->fetchAll() as $r) {
            $map[(int) $r['category_id']][] = [
                'seq'         => (int) $r['seq'],
                'axis_key'    => (string) $r['axis_key'],
                'label'       => (string) ($r['label'] ?? ''),
                'symbol'      => (string) ($r['symbol'] ?? ''),
                'show_symbol' => (int) $r['show_symbol'] === 1,
                'unit'        => $r['unit'] === 'm' ? 'm' : 'mm',
                'use_filter'  => (int) $r['use_filter'] === 1,
            ];
        }
    } catch (\Throwable $e) {
        $map = [];
    }
    return $map;
}

/** 카테고리 id => parent_id (요청당 1회) */
function spec_axes_parents(): array
{
    static $par = null;
    if ($par !== null) { return $par; }
    $par = [];
    if (!db()) { return $par; }
    try {
        foreach (db()->query('SELECT id, parent_id FROM categories')->fetchAll() as $c) {
            $par[(int) $c['id']] = $c['parent_id'] !== null ? (int) $c['parent_id'] : null;
        }
    } catch (\Throwable $e) {
        $par = [];
    }
    return $par;
}

/** 카테고리의 이름표(상위 상속). 없으면 [] */
function spec_axes_for_category(int $categoryId): array
{
    $rows = spec_axes_rows();
    return $rows ? spec_axes_resolve($categoryId, $rows, spec_axes_parents()) : [];
}

/** 이름표 비교용 서명 */
function spec_axes_signature(array $axes): string
{
    return (string) json_encode(array_map(static fn($a) => [
        (int) $a['seq'], (string) $a['axis_key'], (string) $a['label'], spec_axis_symbol($a), (string) $a['unit'], (bool) $a['use_filter'],
    ], $axes), JSON_UNESCAPED_UNICODE);
}

/**
 * 결과에 나온 카테고리들이 모두 같은 이름표를 쓰면 그 이름표, 하나라도 없거나 다르면 [].
 * (중분류·검색처럼 이름표가 섞인 목록은 기존 규격 원문 방식)
 *
 * @param list<int> $categoryIds
 */
function spec_axes_common(array $categoryIds): array
{
    $common = null;
    foreach (array_unique(array_map('intval', $categoryIds)) as $cid) {
        $axes = spec_axes_for_category($cid);
        if (!$axes) { return []; }
        if ($common === null) { $common = $axes; }
        elseif (spec_axes_signature($axes) !== spec_axes_signature($common)) { return []; }
    }
    return $common ?? [];
}

/** 행의 축 값(정규 문자열) 또는 null */
function spec_axes_row_value(array $p, string $key): ?string
{
    if ($key === SPEC_AXIS_TYPE) { return spec_code_type(isset($p['spec_code']) ? (string) $p['spec_code'] : null); }
    $col = SPEC_AXIS_COLUMNS[$key] ?? null;
    $v   = $col !== null ? ($p[$col] ?? null) : null;
    return ($v === null || $v === '') ? null : spec_axis_num((float) $v);
}

/** 화면에 축 값을 써도 되는 행인지: 채운 기준 원문 = 지금 규격 원문, 값 1개 이상 */
function spec_axes_row_fresh(array $p): bool
{
    $src = $p['spec_dims_src'] ?? null;
    if ($src === null || $src === '' || (string) $src !== parse_spec_field((string) ($p['spec'] ?? ''))['spec']) { return false; }
    foreach (SPEC_AXIS_COLUMNS as $col) {
        if (($p[$col] ?? null) !== null && $p[$col] !== '') { return true; }
    }
    return false;
}

/**
 * 목록 GET → 축 필터 입력: ['ax' => [축키 => [값…]], 'ax_type' => [형식…]]. 형식이 틀린 값은 버린다.
 * URL 키: ax_w[]=900, ax_t2[]=260, ax_type[]=B
 */
function spec_axes_filter_input(array $get): array
{
    $ax = [];
    foreach (array_keys(SPEC_AXIS_COLUMNS) as $k) {
        $vals = [];
        foreach ((array) ($get['ax_' . $k] ?? []) as $v) {
            $v = trim((string) $v);
            if (preg_match('/^\d{1,7}(?:\.\d)?$/', $v)) { $vals[spec_axis_num((float) $v)] = true; }
        }
        if ($vals) { $ax[$k] = array_slice(array_keys($vals), 0, 50); }
    }
    $types = [];
    foreach ((array) ($get['ax_type'] ?? []) as $v) {
        $v = strtoupper(trim((string) $v));
        if (preg_match('/^[A-Z]$/', $v)) { $types[$v] = true; }
    }
    return ['ax' => array_map(static fn($l) => array_map('strval', $l), $ax), 'ax_type' => array_map('strval', array_keys($types))];
}

/**
 * 축 필터 → SQL 조각(AND …) + 바인딩(참조). 새 칸이 없으면 ''(조건 없음 — 기존 화면 그대로).
 * 같은 축 안은 OR(IN), 축끼리는 AND. 상품 테이블 별칭 p.
 */
function spec_axes_filter_sql(array $f, array &$params): string
{
    if ((empty($f['ax']) && empty($f['ax_type'])) || !spec_axes_ready()) { return ''; }
    $c = [];
    foreach ((array) ($f['ax'] ?? []) as $k => $vals) {
        $col = SPEC_AXIS_COLUMNS[$k] ?? null;
        if ($col === null || !$vals) { continue; }
        $ph = [];
        foreach (array_values($vals) as $i => $v) { $key = ":fax_{$k}_{$i}"; $ph[] = $key; $params[$key] = (string) $v; }
        $c[] = "p.{$col} IN (" . implode(',', $ph) . ')';
    }
    if (!empty($f['ax_type'])) {
        $ph = [];
        foreach (array_values($f['ax_type']) as $i => $v) { $key = ":faxty{$i}"; $ph[] = $key; $params[$key] = (string) $v; }
        $c[] = "(p.spec_code REGEXP '[0-9][A-Za-z]$' AND RIGHT(p.spec_code, 1) IN (" . implode(',', $ph) . '))';
    }
    return $c ? ' AND ' . implode(' AND ', $c) : '';
}

/**
 * 상품 1개의 새 칸을 지금 규격 원문·이름표 기준으로 다시 계산해 저장(바뀔 때만). 새 칸이 없으면 아무것도 안 함.
 * 관리자 상품 저장 뒤 호출 — 실패해도 저장 자체는 유지되도록 호출측에서 예외를 잡는다.
 */
function spec_axes_sync_product(int $id): bool
{
    if ($id <= 0 || !spec_axes_ready()) { return false; }
    $st = db()->prepare('SELECT id, category_id, spec, ' . implode(', ', SPEC_AXES_NEW_COLUMNS) . ' FROM products WHERE id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) { return false; }
    $plan = spec_axes_plan($p, spec_axes_for_category((int) $p['category_id']));
    if ($plan === null) { return false; }
    $sets = []; $vals = [];
    foreach (SPEC_AXES_NEW_COLUMNS as $col) { $sets[] = "{$col} = ?"; $vals[] = $plan[$col]; }
    $vals[] = $id;
    db()->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    return true;
}
