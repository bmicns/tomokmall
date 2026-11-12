<?php
declare(strict_types=1);
/**
 * 상용콘크리트 → 사이트 상품 표 반영·전환 (B 방식, 사용자 결정 2026-11-12)
 *
 * 왜: 고객 화면(메인·목록·상세·검색·장바구니·견적·주문·AI 상담)은 products·categories·category_spec_axes 를 쓴다.
 *     상용콘크리트 원본(syc_*)을 이 표들에 '반영'하고 케이토목을 '숨기면' 화면 코드·디자인을 바꾸지 않고 상용콘크리트가 보인다.
 *
 * 원본과 반영본
 *   원본  syc_categories·syc_category_spec_axes·syc_products — 일괄 등록·자동 정리로 관리(app/syc_bulk.php)
 *   반영본 categories(slug 'syc-…')·category_spec_axes·products(출처 + 출처ID) — 반영할 때마다 원본 값으로 덮어씀(직접 고치지 않음)
 *
 * 대분류 연결: 상용콘크리트 대분류(측구수로관 등 10개)는 기존 재질 대분류(철근콘크리트제품 11·콘크리트제품 12·스틸제품 13·PE/PVC제품 15)
 *   아래에 붙인다 → 상단 메뉴·빠른 견적 3단 선택·재질 필터·마진율 상속이 기존 그대로. 연결표: SYC_PUBLISH_GROUP_ROOT
 *   (상용콘크리트 대분류 마진율 연결 database/20261110_syc_categories.sql [5] 와 같은 재질)
 * 활성 여부: 전환 전(ktomok)에는 반영본을 모두 숨긴 채 넣고, 전환 뒤(syc)에는 원본 활성 여부 그대로.
 * 전환: syc_catalog_switch() — 케이토목 카테고리(대분류 제외)·상품을 숨기고 숨긴 id 를 syc_catalog_hidden 에 기록, 되돌리면 그 행만 다시 보이게.
 * 가격: 원본의 판매가·매입가·마진율을 그대로 옮긴다(사용자 결정 2026-11-12 '마진율은 기존 그대로'). 계산식(app/pricing.php)은 그대로.
 * 금지: 케이토목 행의 값 변경(활성 여부 외), 반영본 직접 수정, 원본에 없는 값 만들기.
 * 사용: public/api/admin/syc-catalog.php(미리보기·반영·전환) ← 제품관리 화면. DB: database/20261112_catalog_switch.sql
 */

require_once __DIR__ . '/syc_catalog.php';   // SYC_AXIS_KEYS · syc_axis_column · syc_axis_row_value

/** 상용콘크리트 대분류 slug → 기존 재질 대분류 categories.id */
const SYC_PUBLISH_GROUP_ROOT = [
    'syc-g1' => 11, 'syc-g2' => 11, 'syc-g3' => 11, 'syc-g4' => 11, 'syc-g5' => 11,   // 측구수로관·수로관·흄관·암거·맨홀 → 철근콘크리트제품
    'syc-g6' => 15, 'syc-g13' => 15,                                                   // 배수관(PE·PVC관)·받이·집수정 → PE/PVC제품
    'syc-g7' => 13, 'syc-g9' => 13, 'syc-g10' => 13,                                   // 그레이팅·주철 / 보호틀·파형강관·철망 / 볼라드 → 스틸제품
    'syc-g8' => 12, 'syc-g11' => 12, 'syc-g12' => 12,                                 // 보도블록·옹벽·호안블록·경계석 → 콘크리트제품(2026-11-12 나눔)
];
/** category_spec_axes.axis_key 에 넣을 수 있는 키(목록 칸) — 그 밖의 키는 세부 치수(products.spec_detail)로 */
const SYC_PUBLISH_LIST_KEYS = ['d', 'd_base', 'v', 'd_i', 'd_o', 'w', 'w_b', 'w_r', 'w_t', 'h', 'h_high', 'h_low', 'depth', 'l', 't', 't2', 't_tb', 't_wall',
                               't_top', 't_bottom', 'mesh', 'wire_d', 'wire_no', 'ts', 'd_connect', 'd_inlet', 'd_outlet', 'w_o', 'h_o', 't_mid', 'h1', 'x1', 'x2'];

/** 반영본 spec(기존 상품과 같은 '규격: … | 중량: …kg | 단위: …' 형식 — 화면이 이 줄을 나눠 쓴다). 순수 함수 */
function syc_publish_spec_text(array $p): string
{
    $parts = [];
    $spec = trim((string) ($p['spec'] ?? ''));
    if ($spec !== '') { $parts[] = '규격: ' . $spec; }
    if (($p['weight_kg'] ?? null) !== null && $p['weight_kg'] !== '') {
        $w = (float) $p['weight_kg'];
        $parts[] = '중량: ' . (floor($w) === $w ? number_format($w) : rtrim(rtrim(number_format($w, 2), '0'), '.')) . 'kg';
    }
    $unit = trim((string) ($p['unit'] ?? ''));
    if ($unit !== '') { $parts[] = '단위: ' . $unit; }
    return implode(' | ', $parts);
}

/**
 * 세부 치수(상세 화면 전용): 목록 칸이 아닌 이름표(in_list=0) 중 값이 있는 것, 이름표 순서. 순수 함수
 * @param list<array> $axes syc_axes_rows()[category_id]
 * @return list<array{label:string,symbol:string,value:string,unit:string}>
 */
function syc_publish_detail(array $p, array $axes): array
{
    $out = [];
    foreach ($axes as $a) {
        if ($a['in_list'] && in_array($a['axis_key'], SYC_PUBLISH_LIST_KEYS, true)) { continue; }
        $v = syc_axis_row_value($p, $a['axis_key']);
        if ($v === null) { continue; }
        $out[] = ['label' => $a['label'], 'symbol' => $a['symbol'], 'value' => $v, 'unit' => in_array($a['axis_key'], SYC_AXIS_TEXT, true) || $a['unit'] === 'no' ? '' : $a['unit']];
    }
    return $out;
}

/** products 칸 이름 목록(요청당 1회) */
function syc_publish_product_columns(PDO $pdo): array
{
    static $cols = null;
    if ($cols !== null) { return $cols; }
    $cols = [];
    foreach ($pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'") as $r) { $cols[] = (string) $r['COLUMN_NAME']; }
    return $cols;
}

/** 준비 확인: DB 변경(20261112_catalog_switch.sql)이 됐는지 */
function syc_publish_ready(PDO $pdo): bool
{
    $c = syc_publish_product_columns($pdo);
    if (!in_array('spec_detail', $c, true) || !in_array('dim_x2', $c, true)) { return false; }
    try { $pdo->query('SELECT 1 FROM syc_catalog_state LIMIT 1'); $pdo->query('SELECT 1 FROM syc_catalog_hidden LIMIT 1'); } catch (\Throwable $e) { return false; }
    return true;
}

/** 반영 미리보기: 추가·변경될 카테고리·상품 수, 숨겨질(원본에서 빠진) 상품 수 */
function syc_publish_plan(PDO $pdo): array
{
    $cats = (int) $pdo->query('SELECT COUNT(*) FROM syc_categories')->fetchColumn();
    $catsNew = (int) $pdo->query('SELECT COUNT(*) FROM syc_categories s WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.slug = s.slug)')->fetchColumn();
    $prods = (int) $pdo->query('SELECT COUNT(*) FROM syc_products')->fetchColumn();
    $prodsNew = (int) $pdo->query('SELECT COUNT(*) FROM syc_products s WHERE NOT EXISTS (SELECT 1 FROM products p WHERE p.external_source = s.external_source AND p.external_id = s.external_id)')->fetchColumn();
    $stale = (int) $pdo->query("SELECT COUNT(*) FROM products p JOIN categories c ON c.id = p.category_id
                                 WHERE c.slug LIKE 'syc-%' AND p.is_active = 1
                                   AND NOT EXISTS (SELECT 1 FROM syc_products s WHERE s.external_source = p.external_source AND s.external_id = p.external_id)")->fetchColumn();
    return ['mode' => syc_catalog_mode(true), 'categories' => $cats, 'categories_new' => $catsNew, 'products' => $prods, 'products_new' => $prodsNew,
            'products_active' => (int) $pdo->query('SELECT COUNT(*) FROM syc_products WHERE is_active = 1')->fetchColumn(), 'stale' => $stale];
}

/**
 * 반영: 원본 → 반영본. 트랜잭션은 호출측(syc_publish_run·syc_catalog_switch)에서.
 * @return array{categories:int, axes:int, products:int, stale:int}
 */
function syc_publish_apply(PDO $pdo, string $mode): array
{
    $live = $mode === 'syc';
    // 1) 카테고리: 대분류(재질 대분류 아래) → 품목(대분류 아래). slug 기준 넣기/덮어쓰기
    $sc = $pdo->query('SELECT id, parent_id, name, description, slug, sort_order, is_active, discount_customer, discount_corporate FROM syc_categories ORDER BY parent_id IS NOT NULL, sort_order, id')->fetchAll();
    $slugById = array_column($sc, 'slug', 'id');
    $up = $pdo->prepare('INSERT INTO categories (parent_id, name, description, slug, sort_order, is_active, discount_customer, discount_corporate)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?) AS n
                         ON DUPLICATE KEY UPDATE parent_id = n.parent_id, name = n.name, description = n.description, sort_order = n.sort_order,
                                                 is_active = n.is_active, discount_customer = n.discount_customer, discount_corporate = n.discount_corporate');
    $idOf = $pdo->prepare('SELECT id FROM categories WHERE slug = ?');
    $catId = [];
    foreach ($sc as $c) {
        if ($c['parent_id'] === null) {
            $parent = SYC_PUBLISH_GROUP_ROOT[$c['slug']] ?? null;
            if ($parent === null) { throw new RuntimeException("재질 대분류 연결이 없는 상용콘크리트 대분류: {$c['slug']}"); }
        } else {
            $parent = $catId[$slugById[$c['parent_id']] ?? ''] ?? null;
            if ($parent === null) { throw new RuntimeException("상위 카테고리를 찾지 못함: {$c['slug']}"); }
        }
        $up->execute([$parent, $c['name'], $c['description'], $c['slug'], (int) $c['sort_order'], $live ? (int) $c['is_active'] : 0,
                      (int) $c['discount_customer'], (int) $c['discount_corporate']]);
        $idOf->execute([$c['slug']]);
        $catId[$c['slug']] = (int) $idOf->fetchColumn();
    }
    // 2) 이름표: 반영본 카테고리의 이름표를 원본 목록 칸(in_list)으로 다시 씀(케이토목 이름표는 건드리지 않음)
    $ids = array_values($catId);
    $axCount = 0;
    if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("DELETE FROM category_spec_axes WHERE category_id IN ({$ph})")->execute($ids);
        $ins = $pdo->prepare('INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt, is_active)
                              VALUES (?, ?, ?, ?, ?, 1, ?, ?, \'default\', 1)');
        foreach (syc_axes_rows() as $sid => $axes) {
            $slug = $slugById[$sid] ?? null;
            if ($slug === null || !isset($catId[$slug])) { continue; }
            foreach ($axes as $a) {
                if (!$a['in_list'] || !in_array($a['axis_key'], SYC_PUBLISH_LIST_KEYS, true)) { continue; }
                $ins->execute([$catId[$slug], $a['seq'], $a['axis_key'], $a['label'], $a['symbol'] !== '' ? $a['symbol'] : null, $a['unit'], $a['use_filter'] ? 1 : 0]);
                $axCount++;
            }
        }
    }
    // 3) 상품: 출처 + 출처ID 기준 넣기/덮어쓰기
    $pcols = syc_publish_product_columns($pdo);
    $dimCols = array_values(array_filter(array_map('syc_axis_column', SYC_AXIS_KEYS), static fn($c) => $c !== null && in_array($c, $pcols, true)));
    $cols = array_merge(['category_id', 'name', 'brand', 'spec', 'spec_code'], $dimCols,
                        ['spec_option', 'spec_dims_src', 'spec_detail', 'unit', 'weight_kg', 'description', 'price_b2c', 'price_b2b', 'cost_price', 'stock_qty',
                         'external_source', 'external_id', 'source_url', 'image_url', 'is_active']);
    $upd = implode(', ', array_map(static fn($c) => "{$c} = n.{$c}", array_diff($cols, ['external_source', 'external_id'])));
    $pins = $pdo->prepare('INSERT INTO products (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ') AS n ON DUPLICATE KEY UPDATE ' . $upd);
    $axesBySyc = syc_axes_rows();
    $n = 0;
    foreach ($pdo->query('SELECT * FROM syc_products ORDER BY id') as $p) {
        $sid = (int) $p['category_id'];
        $cid = $catId[$slugById[$sid] ?? ''] ?? null;
        if ($cid === null) { continue; }
        $detail = syc_publish_detail($p, $axesBySyc[$sid] ?? []);
        $vals = [$cid, $p['name'], $p['brand'], syc_publish_spec_text($p), $p['spec_code']];
        foreach ($dimCols as $dc) { $vals[] = $p[$dc] ?? null; }
        array_push($vals, $p['spec_option'], $p['spec'], $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, $p['unit'], $p['weight_kg'], $p['description'],
                   (int) $p['price_b2c'], (int) $p['price_b2b'], (int) $p['cost_price'], (int) $p['stock_qty'],
                   $p['external_source'], $p['external_id'], $p['source_url'], $p['image_file'] ?: $p['image_thumb_file'], $live ? (int) $p['is_active'] : 0);
        $pins->execute($vals);
        $n++;
    }
    // 4) 원본에서 빠진 반영본 상품은 숨김(지우지 않음 — 견적·주문 기록이 가리킬 수 있음)
    $stale = $pdo->exec("UPDATE products p JOIN categories c ON c.id = p.category_id SET p.is_active = 0
                          WHERE c.slug LIKE 'syc-%' AND p.is_active = 1
                            AND NOT EXISTS (SELECT 1 FROM syc_products s WHERE s.external_source = p.external_source AND s.external_id = p.external_id)");
    $pdo->prepare('INSERT INTO syc_catalog_state (id, mode, published_at) VALUES (1, ?, NOW()) AS n ON DUPLICATE KEY UPDATE published_at = n.published_at')->execute([$mode]);
    return ['categories' => count($catId), 'axes' => $axCount, 'products' => $n, 'stale' => (int) $stale];
}

/** 반영 실행(트랜잭션). 지금 출처 그대로 */
function syc_publish_run(PDO $pdo, ?int $userId = null): array
{
    $pdo->beginTransaction();
    try {
        $r = syc_publish_apply($pdo, syc_catalog_mode(true));
        $pdo->prepare('UPDATE syc_catalog_state SET updated_by = ? WHERE id = 1')->execute([$userId]);
        $pdo->commit();
        return $r;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * 전환. 'syc': 케이토목 카테고리(대분류 제외)·상품 중 보이는 것을 숨기고 기록 → 반영(상용콘크리트 보이게).
 *       'ktomok': 반영본을 숨기고 기록해 둔 케이토목 행만 다시 보이게 → 기록 지움.
 * @return array{mode:string, hidden_categories:int, hidden_products:int, restored_categories:int, restored_products:int, publish:?array}
 */
function syc_catalog_switch(PDO $pdo, string $to, ?int $userId = null): array
{
    if (!in_array($to, SYC_CATALOG_MODES, true)) { throw new InvalidArgumentException('잘못된 전환'); }
    $from = syc_catalog_mode(true);
    if ($from === $to) { throw new InvalidArgumentException('이미 ' . ($to === 'syc' ? '상용콘크리트' : '케이토목') . ' 상품을 보여 주고 있습니다.'); }
    $out = ['mode' => $to, 'hidden_categories' => 0, 'hidden_products' => 0, 'restored_categories' => 0, 'restored_products' => 0, 'publish' => null];
    $pdo->beginTransaction();
    try {
        if ($to === 'syc') {
            $pdo->exec("INSERT IGNORE INTO syc_catalog_hidden (entity, ref_id)
                        SELECT 'category', id FROM categories WHERE is_active = 1 AND parent_id IS NOT NULL AND slug NOT LIKE 'syc-%'");
            $pdo->exec("INSERT IGNORE INTO syc_catalog_hidden (entity, ref_id)
                        SELECT 'product', p.id FROM products p LEFT JOIN categories c ON c.id = p.category_id
                         WHERE p.is_active = 1 AND (c.slug IS NULL OR c.slug NOT LIKE 'syc-%')");
            $out['hidden_categories'] = $pdo->exec("UPDATE categories c JOIN syc_catalog_hidden h ON h.entity = 'category' AND h.ref_id = c.id SET c.is_active = 0 WHERE c.is_active = 1");
            $out['hidden_products'] = $pdo->exec("UPDATE products p JOIN syc_catalog_hidden h ON h.entity = 'product' AND h.ref_id = p.id SET p.is_active = 0 WHERE p.is_active = 1");
            $pdo->prepare("INSERT INTO syc_catalog_state (id, mode, switched_at, updated_by) VALUES (1, 'syc', NOW(), ?) AS n
                           ON DUPLICATE KEY UPDATE mode = n.mode, switched_at = n.switched_at, updated_by = n.updated_by")->execute([$userId]);
            $out['publish'] = syc_publish_apply($pdo, 'syc');
        } else {
            $pdo->exec("UPDATE products p JOIN categories c ON c.id = p.category_id SET p.is_active = 0 WHERE c.slug LIKE 'syc-%' AND p.is_active = 1");
            $pdo->exec("UPDATE categories SET is_active = 0 WHERE slug LIKE 'syc-%' AND is_active = 1");
            $out['restored_categories'] = $pdo->exec("UPDATE categories c JOIN syc_catalog_hidden h ON h.entity = 'category' AND h.ref_id = c.id SET c.is_active = 1");
            $out['restored_products'] = $pdo->exec("UPDATE products p JOIN syc_catalog_hidden h ON h.entity = 'product' AND h.ref_id = p.id SET p.is_active = 1");
            $pdo->exec('DELETE FROM syc_catalog_hidden');
            $pdo->prepare("INSERT INTO syc_catalog_state (id, mode, switched_at, updated_by) VALUES (1, 'ktomok', NOW(), ?) AS n
                           ON DUPLICATE KEY UPDATE mode = n.mode, switched_at = n.switched_at, updated_by = n.updated_by")->execute([$userId]);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    syc_catalog_mode(true);
    return $out;
}
