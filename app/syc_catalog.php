<?php
declare(strict_types=1);
/**
 * 상용콘크리트(syc_*) 사이트 화면 공통 — 관리자 확인용(사용자 결정 2026-11-11)
 *   관리자는 사이트에서 케이토목 대신 상용콘크리트 제품만 본다(기본, 활성 상품만 — 고객이 보게 될 조건과 같게).
 *   관리자 상단·사이트 띠의 '케이토목으로 보기'를 누르면 그 관리자 세션만 기존 화면. 관리자 외 사용자는 항상 케이토목.
 *
 * 책임: 미리보기 상태 판단, 상용콘크리트 카테고리·규격 이름표(syc_category_spec_axes)·상품 조회, 목록 필터·파세트,
 *       가격 계산 입력(syc_categories 마진율 → price_bundle 과 같은 식).
 * 화면: public/syc-products.php(목록) · public/syc-product.php(상세). 관리자 전환: public/api/admin/site-source.php
 * 금지: 고객(관리자 아닌 사용자)에게 상용콘크리트 상품 노출, 기존 products·categories·category_spec_axes 읽기/쓰기,
 *       가격 계산식 따로 만들기(app/pricing.php price_bundle 그대로 — 행에 마진율만 넣어 준다).
 * 표시 함수는 기존 규격 축 함수를 그대로 쓴다(spec_axis_title·spec_axis_value_display·product_filter_axis_groups).
 */

require_once __DIR__ . '/auth.php';

/** 사이트 보기 출처(세션) 키와 값 */
const SYC_SOURCE_SESSION_KEY = 'site_source';
const SYC_SOURCE_SYC = 'syc';
const SYC_SOURCE_KTOMOK = 'ktomok';

/**
 * 이름표 키 → syc_products 칸. 'dim_' 를 뗀 이름, wire_no 만 spec_wire_no.
 * 순서 = 상세 화면 기본 순서(이름표 seq 가 우선)
 */
const SYC_AXIS_KEYS = ['d', 'd_i', 'd_o', 'w', 'w_o', 'w_b', 'w_t', 'h', 'h_o', 'depth', 'l', 'v', 't', 't2', 't_top', 't_wall', 't_bottom', 't_mid',
                       'mesh', 'wire_d', 'wire_no', 'd_inlet', 'd_outlet',
                       'w1', 'w2', 'h1', 'h2', 'h3', 'h4', 't1', 't3', 't4', 'l1', 'l2', 'l3', 'l4', 'l5', 'l6', 'd1', 'd2', 'd3', 'd4', 'd5',
                       'r1', 'r2', 'c', 'x1', 'x2', 'x3', 'x4'];
/** 글자 값 칸(숫자 아님) — 기존 SPEC_AXIS_TEXT 와 같은 키라 spec_axis_value_display 가 그대로 처리 */
const SYC_AXIS_TEXT = ['d_inlet', 'd_outlet'];

function syc_axis_column(string $key): ?string
{
    if (!in_array($key, SYC_AXIS_KEYS, true)) { return null; }
    return $key === 'wire_no' ? 'spec_wire_no' : 'dim_' . $key;
}

/** 지금 요청이 상용콘크리트 보기인지: 관리자이고 '케이토목으로 보기'를 고르지 않았을 때(관리자 기본). 그 밖의 사용자는 항상 false(기존 화면) */
function syc_preview_on(): bool
{
    $u = current_user();
    return $u !== null && $u['role'] === 'admin' && ($_SESSION[SYC_SOURCE_SESSION_KEY] ?? SYC_SOURCE_SYC) !== SYC_SOURCE_KTOMOK;
}

/** 비활성 상품도 보이기(기본: 안 보임 — 활성화한 상품이 고객 화면처럼 보이는지 확인. 띠에서 바꿀 수 있음) */
function syc_preview_include_inactive(): bool
{
    return ($_SESSION['syc_preview_inactive'] ?? '0') === '1';
}

/** 필요한 표가 DB 에 있는지(없으면 화면은 안내 문구) */
function syc_tables_ready(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $ok = false;
    if (!db()) { return false; }
    try {
        $n = (int) db()->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()
                                AND TABLE_NAME IN ('syc_categories', 'syc_products', 'syc_category_spec_axes')")->fetchColumn();
        $ok = $n === 3;
    } catch (\Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** 카테고리 전체: id => 행(id·parent_id·name·slug·sort_order·discount_customer·discount_corporate). 요청당 1회 */
function syc_categories_all(): array
{
    static $all = null;
    if ($all !== null) { return $all; }
    $all = [];
    foreach (db()->query('SELECT id, parent_id, name, slug, sort_order, discount_customer, discount_corporate
                            FROM syc_categories WHERE is_active = 1 ORDER BY parent_id IS NOT NULL, sort_order, id') as $r) {
        $all[(int) $r['id']] = $r;
    }
    return $all;
}

/** 대분류 → [대분류 행, 품목 행 목록] (메뉴·카테고리 목록용) */
function syc_category_tree(array $all): array
{
    $tree = [];
    foreach ($all as $id => $c) { if ($c['parent_id'] === null) { $tree[$id] = ['group' => $c, 'items' => []]; } }
    foreach ($all as $c) { if ($c['parent_id'] !== null && isset($tree[(int) $c['parent_id']])) { $tree[(int) $c['parent_id']]['items'][] = $c; } }
    return $tree;
}

/** 카테고리 + 하위(2단계) id */
function syc_category_ids(int $id, array $all): array
{
    if (!isset($all[$id])) { return []; }
    $ids = [$id];
    foreach ($all as $cid => $c) { if ((int) ($c['parent_id'] ?? 0) === $id) { $ids[] = $cid; } }
    return $ids;
}

/**
 * 마진율 맵(category_discount_map 과 같은 규칙: 자기 값이 0 이면 부모에서 물려받음).
 * @param array<int,array> $all syc_categories_all() — 순수 함수(테스트)
 */
function syc_discount_map(array $all, string $col): array
{
    if (!in_array($col, ['discount_customer', 'discount_corporate'], true)) { $col = 'discount_customer'; }
    $map = [];
    foreach ($all as $id => $c) {
        $r = $id; $rate = 0; $seen = [];
        while ($r !== null && isset($all[$r]) && !isset($seen[$r])) {
            $seen[$r] = 1;
            if ((int) $all[$r][$col] > 0) { $rate = (int) $all[$r][$col]; break; }
            $r = $all[$r]['parent_id'] !== null ? (int) $all[$r]['parent_id'] : null;
        }
        $map[$id] = $rate;
    }
    return $map;
}

/** 상품 행에 마진율을 넣어 price_bundle·price_tag_list·price_cost_cell 이 기존과 같은 식으로 계산하게 한다 */
function syc_price_row(array $p, array $all): array
{
    static $maps = [];
    $key = md5((string) json_encode(array_map(static fn($c) => [$c['parent_id'], (int) $c['discount_customer'], (int) $c['discount_corporate']], $all)));
    if (!isset($maps[$key])) { $maps[$key] = [syc_discount_map($all, 'discount_customer'), syc_discount_map($all, 'discount_corporate')]; }
    $cid = (int) ($p['category_id'] ?? 0);
    $p['_margin_customer']  = $maps[$key][0][$cid] ?? 0;
    $p['_margin_corporate'] = $maps[$key][1][$cid] ?? 0;
    return $p;
}

/** 이름표: category_id => 행 목록(seq 순) — 기존 축 표시 함수가 읽는 모양(axis_key·label·symbol·show_symbol·unit·use_filter) + in_list */
function syc_axes_rows(): array
{
    static $map = null;
    if ($map !== null) { return $map; }
    $map = [];
    foreach (db()->query('SELECT category_id, seq, axis_key, label, symbol, unit, in_list, use_filter FROM syc_category_spec_axes
                            WHERE is_active = 1 ORDER BY category_id, seq') as $r) {
        if (syc_axis_column((string) $r['axis_key']) === null) { continue; }   // 모르는 키는 무시
        $map[(int) $r['category_id']][] = [
            'seq' => (int) $r['seq'], 'axis_key' => (string) $r['axis_key'], 'label' => (string) $r['label'],
            'symbol' => (string) ($r['symbol'] ?? ''), 'show_symbol' => true, 'unit' => (string) $r['unit'],
            'in_list' => (int) $r['in_list'] === 1, 'use_filter' => (int) $r['use_filter'] === 1,
        ];
    }
    return $map;
}

/**
 * 목록 표 이름표: 결과 카테고리가 모두 같은 목록 칸(in_list)을 쓰면 그 칸들, 아니면 [](규격 원문 한 칸 — 기존 목록과 같은 규칙).
 * @param list<int> $catIds @param array<int,list<array>> $rows syc_axes_rows() — 순수 함수(테스트)
 */
function syc_axes_common(array $catIds, array $rows): array
{
    $common = null;
    $sig = static fn(array $ax): string => (string) json_encode(array_map(static fn($a) => [$a['axis_key'], $a['label'], $a['symbol'], $a['unit'], $a['use_filter']], $ax), JSON_UNESCAPED_UNICODE);
    foreach (array_unique(array_map('intval', $catIds)) as $cid) {
        $ax = array_values(array_filter($rows[$cid] ?? [], static fn($a) => $a['in_list']));
        if (!$ax) { return []; }
        if ($common === null) { $common = $ax; }
        elseif ($sig($ax) !== $sig($common)) { return []; }
    }
    return $common ?? [];
}

/** 행의 축 값(정규 문자열) 또는 null — 숫자는 spec_axis_num(1.0 → '1', 101.6 → '101.6'), 글자 칸은 그대로 */
function syc_axis_row_value(array $p, string $key): ?string
{
    $col = syc_axis_column($key);
    $v = $col !== null ? ($p[$col] ?? null) : null;
    if ($v === null || $v === '') { return null; }
    return in_array($key, SYC_AXIS_TEXT, true) ? (string) $v : spec_axis_num((float) $v);
}

/** 목록 GET → 필터 입력: ['ax' => [키 => [값…]], 'ax_code' => [코드…]] (기존 필터 표시 함수가 읽는 모양). 형식이 틀린 값은 버림 */
function syc_filter_input(array $get): array
{
    $ax = [];
    foreach (SYC_AXIS_KEYS as $k) {
        $vals = [];
        foreach ((array) ($get['ax_' . $k] ?? []) as $v) {
            $v = trim((string) $v);
            if ($v === '' || mb_strlen($v) > 30) { continue; }
            if (!in_array($k, SYC_AXIS_TEXT, true)) {
                if (!is_numeric($v)) { continue; }
                $v = spec_axis_num((float) $v);
            }
            $vals[] = $v;
        }
        if ($vals) { $ax[$k] = array_values(array_unique($vals)); }
    }
    $codes = array_values(array_unique(array_filter(array_map(static fn($v) => mb_substr(trim((string) $v), 0, 30), (array) ($get['ax_code'] ?? [])), static fn($v) => $v !== '')));
    return ['ax' => $ax, 'ax_code' => $codes, 'ax_type' => []];
}

/** 행이 필터에 맞는지(축끼리는 그리고, 같은 축 안은 또는). $skip 축은 무시(파세트 개수 계산용) */
function syc_row_matches(array $p, array $f, ?string $skip = null): bool
{
    foreach ($f['ax'] as $k => $vals) {
        if ($k === $skip) { continue; }
        if (!in_array(syc_axis_row_value($p, $k), $vals, true)) { return false; }
    }
    if ($skip !== SPEC_AXIS_CODE && $f['ax_code'] && !in_array((string) ($p['spec_code'] ?? ''), $f['ax_code'], true)) { return false; }
    return true;
}

/**
 * 파세트(기존 product_filter_axis_groups 가 읽는 모양): defs(필터 칸), all(필터 무관 값), vals(다른 필터 적용 뒤 개수),
 * type_all·type(모델 코드 — 코드 전체), code_key. 순수 함수(테스트)
 */
function syc_facets(array $rows, array $axes, array $f): array
{
    $defs = array_values(array_filter($axes, static fn($a) => $a['use_filter']));
    $all = []; $vals = [];
    foreach ($defs as $a) {
        $k = $a['axis_key'];
        $set = [];
        foreach ($rows as $p) { $v = syc_axis_row_value($p, $k); if ($v !== null) { $set[$v] = true; } }
        $list = array_keys($set);
        usort($list, static fn($x, $y) => is_numeric($x) && is_numeric($y) ? ((float) $x <=> (float) $y) : strcmp((string) $x, (string) $y));
        $all[$k] = array_map('strval', $list);
        $vals[$k] = [];
        foreach ($rows as $p) {
            if (!syc_row_matches($p, $f, $k)) { continue; }
            $v = syc_axis_row_value($p, $k);
            if ($v !== null) { $vals[$k][$v] = ($vals[$k][$v] ?? 0) + 1; }
        }
    }
    $codes = [];
    foreach ($rows as $p) { $c = trim((string) ($p['spec_code'] ?? '')); if ($c !== '') { $codes[$c] = true; } }
    $typeAll = (count($codes) >= 2 && count($codes) <= 40) ? array_map('strval', array_keys($codes)) : [];
    natsort($typeAll);
    $typeCnt = [];
    if ($typeAll) {
        foreach ($rows as $p) {
            if (!syc_row_matches($p, $f, SPEC_AXIS_CODE)) { continue; }
            $c = trim((string) ($p['spec_code'] ?? ''));
            if ($c !== '') { $typeCnt[$c] = ($typeCnt[$c] ?? 0) + 1; }
        }
    }
    return ['defs' => $defs, 'all' => $all, 'vals' => $vals, 'type_all' => array_values($typeAll), 'type' => $typeCnt, 'code_key' => SPEC_AXIS_CODE];
}

/** 상품 조회(카테고리 범위·목록검색·정렬). 필터는 화면에서 syc_row_matches 로 거른다(품목당 수십~수백 행) */
function syc_fetch_products(array $catIds, string $search, string $sort, bool $includeInactive): array
{
    $where = [];
    $params = [];
    if ($catIds) {
        $where[] = 'p.category_id IN (' . implode(',', array_fill(0, count($catIds), '?')) . ')';
        array_push($params, ...array_map('intval', $catIds));
    }
    if (!$includeInactive) { $where[] = 'p.is_active = 1'; }
    if ($search !== '') {
        $where[] = '(p.name LIKE ? OR p.spec LIKE ? OR p.spec_code LIKE ? OR p.spec_option LIKE ?)';
        $like = '%' . $search . '%';
        array_push($params, $like, $like, $like, $like);
    }
    $order = match ($sort) {
        'price_asc'  => 'p.price_b2c = 0, p.price_b2c ASC, p.id',
        'price_desc' => 'p.price_b2c DESC, p.id',
        'newest'     => 'p.id DESC',
        default      => 'c.sort_order, p.category_id, p.id',
    };
    $st = db()->prepare('SELECT p.*, c.name AS cat_name FROM syc_products p JOIN syc_categories c ON c.id = p.category_id'
                      . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . ' ORDER BY ' . $order . ' LIMIT 3000');
    $st->execute($params);
    return $st->fetchAll();
}

/** 상용콘크리트 목록 URL(필터 표시 함수가 /products.php 를 쓰지 않도록 이 화면 전용) */
function syc_list_url(array $get, array $set = []): string
{
    foreach ($set as $k => $v) {
        if ($v === null || $v === []) { unset($get[$k]); } else { $get[$k] = $v; }
    }
    $qs = http_build_query($get, '', '&', PHP_QUERY_RFC3986);
    return '/syc-products.php' . ($qs !== '' ? '?' . $qs : '');
}

/**
 * 사이트 위 미리보기 띠(관리자 + 상용콘크리트 보기일 때만, app/layout.php render_header 가 부름).
 * 버튼은 /api/admin/site-source.php 로 POST(돌아올 주소 포함).
 */
function syc_render_preview_bar(): void
{
    if (!syc_preview_on()) { return; }
    $back = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $inc = syc_preview_include_inactive();
    ?>
    <div class="syc-preview-bar" role="status">
        <style>
        .syc-preview-bar{background:#1A2035;color:#fff;font-size:13px}
        .syc-preview-in{max-width:1400px;margin:0 auto;padding:8px 16px;display:flex;align-items:center;gap:8px 14px;flex-wrap:wrap}
        .syc-preview-in strong{color:#FFD54F}
        .syc-preview-in form{display:inline;margin:0}
        .syc-preview-in button{min-height:32px;padding:0 12px;border-radius:6px;border:1px solid rgba(255,255,255,.4);background:transparent;color:#fff;font-size:12.5px;font-weight:600;cursor:pointer;font-family:inherit}
        .syc-preview-in button:hover{background:rgba(255,255,255,.12);color:#fff}
        .syc-preview-in .syc-pv-go{margin-left:auto}
        /* 상용콘크리트 메뉴: 대분류 10칸이 한 줄에 넘치지 않게 여러 줄로(기존 메뉴 규칙은 그대로) */
        .syc-mega{flex-wrap:wrap;justify-content:flex-start;row-gap:20px}
        .syc-mega .mega-col{flex:0 0 228px;padding-right:20px}
        .syc-mega .mega-col-title-link{white-space:normal}
        .syc-mega .mega-3item{line-height:1.35;white-space:normal;align-items:flex-start;min-width:0;overflow-wrap:anywhere}
        .syc-mega .mega-3item > img,.syc-mega .mega-3item > span{flex-shrink:0}
        </style>
        <div class="syc-preview-in">
            <span><strong>관리자 화면 · 상용콘크리트 제품만</strong> · 관리자 외 사용자에게는 케이토목 제품이 그대로 보입니다</span>
            <form method="post" action="/api/admin/site-source.php">
                <input type="hidden" name="source" value="<?= SYC_SOURCE_SYC ?>">
                <input type="hidden" name="inactive" value="<?= $inc ? '0' : '1' ?>">
                <input type="hidden" name="return" value="<?= e($back) ?>">
                <button type="submit" aria-pressed="<?= $inc ? 'true' : 'false' ?>"><?= $inc ? '비활성 상품 포함 중 — 활성만 보기' : '활성만 보는 중 — 비활성도 보기' ?></button>
            </form>
            <form method="post" action="/api/admin/site-source.php" class="syc-pv-go">
                <input type="hidden" name="source" value="<?= SYC_SOURCE_KTOMOK ?>">
                <input type="hidden" name="return" value="/index.php">
                <button type="submit">케이토목으로 보기</button>
            </form>
        </div>
    </div>
    <?php
}

/** 관리자 상용콘크리트 보기의 상단 메뉴: 탭 하나('상용콘크리트 제품') + 펼침에 대분류(칸)·품목. 기존 메뉴와 같은 클래스(styles.css) */
function syc_mega_tabs_html(): ?string
{
    if (!syc_tables_ready()) { return null; }
    try {
        $tree = syc_category_tree(syc_categories_all());
        $thumbs = [];
        $st = db()->query('SELECT category_id, MIN(image_thumb_file) AS t FROM syc_products WHERE image_thumb_file IS NOT NULL'
                        . (syc_preview_include_inactive() ? '' : ' AND is_active = 1') . ' GROUP BY category_id');
        foreach ($st as $r) { $thumbs[(int) $r['category_id']] = (string) $r['t']; }
    } catch (\Throwable $e) {
        return null;
    }
    $h = '<div class="mega-tabs"><div class="mega-item"><a href="/syc-products.php" class="mega-tab">상용콘크리트 제품</a>'
       . '<div class="mega-panel"><div class="mega-panel-inner syc-mega">';
    foreach ($tree as $gid => $g) {
        $h .= '<div class="mega-col"><a href="/syc-products.php?category=' . (int) $gid . '" class="mega-col-title-link">' . e($g['group']['name']) . '</a><div class="mega-col-items">';
        foreach ($g['items'] as $it) {
            $t = $thumbs[(int) $it['id']] ?? '';
            $h .= '<a href="/syc-products.php?category=' . (int) $it['id'] . '" class="mega-3item">'
                . ($t !== '' ? '<img src="' . e($t) . '" alt="" class="mega-3item-img">' : '<span class="mega-3item-thumb"></span>')
                . e($it['name']) . '</a>';
        }
        $h .= '</div></div>';
    }
    return $h . '</div></div></div></div>';
}
