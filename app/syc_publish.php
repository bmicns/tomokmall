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
 *
 * ── 2026-11-16 공통 분류 반영(A안, 사용자 결정) — syc_publish_std_apply / syc_publish_std_run ──
 *   상용콘크리트·업체 목록 업체 상품(syc_products 모든 출처)을 공통 분류(케이토목 재질 6개 대분류 체계, app/catalog_map.php)에 넣어
 *   products 에 반영. 활성 여부 = 원본 그대로(활성 = 고객 화면에 보임). 케이토목 상품은 products 에 그대로(건드리지 않음).
 *   반영본 분류 'syc-…' 는 더 쓰지 않음(상품이 없어지면 메뉴에서 자동으로 빠짐). 모드 = 'std' 로 기록.
 *   일괄 등록·제품 등록·활성/비활성 변경 뒤 자동 실행(public/api/admin/syc-products.php). 이 모드에서는 예전 반영·전환을 막는다.
 */

require_once __DIR__ . '/syc_catalog.php';   // SYC_AXIS_KEYS · syc_axis_column · syc_axis_row_value
require_once __DIR__ . '/catalog_map.php';   // CATALOG_STD_MAP — 업체 분류 → 공통 분류(2026-11-16)

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
    $prods = (int) $pdo->query("SELECT COUNT(*) FROM syc_products WHERE external_source = 'syccrt'")->fetchColumn();   // 상용콘크리트 원본만(S16)
    $prodsNew = (int) $pdo->query("SELECT COUNT(*) FROM syc_products s WHERE s.external_source = 'syccrt' AND NOT EXISTS (SELECT 1 FROM products p WHERE p.external_source = s.external_source AND p.external_id = s.external_id)")->fetchColumn();
    $stale = (int) $pdo->query("SELECT COUNT(*) FROM products p JOIN categories c ON c.id = p.category_id
                                 WHERE c.slug LIKE 'syc-%' AND p.is_active = 1 AND (p.external_id IS NULL OR p.external_id NOT LIKE 'link-%')
                                   AND NOT EXISTS (SELECT 1 FROM syc_products s WHERE s.external_source = p.external_source AND s.external_id = p.external_id)")->fetchColumn();
    return ['mode' => syc_catalog_mode(true), 'categories' => $cats, 'categories_new' => $catsNew, 'products' => $prods, 'products_new' => $prodsNew,
            'products_active' => (int) $pdo->query("SELECT COUNT(*) FROM syc_products WHERE is_active = 1 AND external_source = 'syccrt'")->fetchColumn(), 'stale' => $stale];
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
    // 썸네일·규격 이미지 칸(database/20261113_product_image_paths.sql) — 칸이 있을 때만
    $imgExtra = array_values(array_intersect(['image_thumb_url', 'image_spec_url'], $pcols));
    $sycCols  = array_column($pdo->query('SHOW COLUMNS FROM syc_products')->fetchAll(), 'Field');
    $cols = array_merge($cols, $imgExtra);
    $upd = implode(', ', array_map(static fn($c) => "{$c} = n.{$c}", array_diff($cols, ['external_source', 'external_id'])));
    $pins = $pdo->prepare('INSERT INTO products (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ') AS n ON DUPLICATE KEY UPDATE ' . $upd);
    $axesBySyc = syc_axes_rows();
    $n = 0;
    // 상용콘크리트 원본만(업체 목록 업체 상품 'vendor-<id>' 는 고객 화면에 반영하지 않음 — 사용자 결정 2026-11-13 S16)
    foreach ($pdo->query("SELECT * FROM syc_products WHERE external_source = 'syccrt' ORDER BY id") as $p) {
        $sid = (int) $p['category_id'];
        $cid = $catId[$slugById[$sid] ?? ''] ?? null;
        if ($cid === null) { continue; }
        $detail = syc_publish_detail($p, $axesBySyc[$sid] ?? []);
        $vals = [$cid, $p['name'], $p['brand'], syc_publish_spec_text($p), $p['spec_code']];
        foreach ($dimCols as $dc) { $vals[] = $p[$dc] ?? null; }
        array_push($vals, $p['spec_option'], $p['spec'], $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null, $p['unit'], $p['weight_kg'], $p['description'],
                   (int) $p['price_b2c'], (int) $p['price_b2b'], (int) $p['cost_price'], (int) $p['stock_qty'],
                   $p['external_source'], $p['external_id'], $p['source_url'], $p['image_file'] ?: $p['image_thumb_file'], $live ? (int) $p['is_active'] : 0);
        foreach ($imgExtra as $c) {
            $vals[] = $c === 'image_thumb_url' ? ($p['image_thumb_file'] ?? null) : (in_array('image_spec_file', $sycCols, true) ? ($p['image_spec_file'] ?? null) : null);
        }
        $pins->execute($vals);
        $n++;
    }
    // 4) 원본에서 빠진 반영본 상품은 숨김(지우지 않음 — 견적·주문 기록이 가리킬 수 있음)
    $stale = $pdo->exec("UPDATE products p JOIN categories c ON c.id = p.category_id SET p.is_active = 0
                          WHERE c.slug LIKE 'syc-%' AND p.is_active = 1 AND (p.external_id IS NULL OR p.external_id NOT LIKE 'link-%')
                            AND NOT EXISTS (SELECT 1 FROM syc_products s WHERE s.external_source = p.external_source AND s.external_id = p.external_id)");
    $pdo->prepare('INSERT INTO syc_catalog_state (id, mode, published_at) VALUES (1, ?, NOW()) AS n ON DUPLICATE KEY UPDATE published_at = n.published_at')->execute([$mode]);
    return ['categories' => count($catId), 'axes' => $axCount, 'products' => $n, 'stale' => (int) $stale];
}

/** 반영 실행(트랜잭션). 지금 출처 그대로 */
function syc_publish_run(PDO $pdo, ?int $userId = null): array
{
    if (syc_catalog_mode(true) === 'std') { throw new InvalidArgumentException('공통 분류 반영으로 바뀌어 예전 반영은 쓰지 않습니다.'); }
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
    if (!in_array($to, SYC_CATALOG_MODES, true) || $to === 'std') { throw new InvalidArgumentException('잘못된 전환'); }
    $from = syc_catalog_mode(true);
    if ($from === 'std') { throw new InvalidArgumentException('공통 분류 반영으로 바뀌어 전환은 쓰지 않습니다(상품별 활성/비활성으로 노출).'); }
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

/**
 * 업체 상품 1행 → 공통 분류 id. 매핑 줄을 조건 종류 순서(모델 코드 → 옵션 → 상품명 → 전부)로 보고 처음 맞는 줄. 순수 함수
 * @param array<string,int> $stdIds 새 품명 slug('std-…') => categories.id, @param ?array $map 매핑(기본 CATALOG_STD_MAP)
 */
function catalog_std_category(string $vendorSlug, array $row, array $stdIds, ?array $map = null): ?int
{
    $order = ['code' => 0, 'option' => 1, 'name' => 2, 'all' => 3];
    $cand = array_values(array_filter($map ?? CATALOG_STD_MAP, static fn($m) => $m[0] === $vendorSlug));
    usort($cand, static fn($a, $b) => ($order[$a[1]] ?? 9) <=> ($order[$b[1]] ?? 9));
    foreach ($cand as [$_, $type, $val, $std]) {
        $hit = match ($type) {
            'code'   => trim((string) ($row['spec_code'] ?? '')) === $val,
            'option' => $val !== '' && str_contains((string) ($row['spec_option'] ?? ''), $val),
            'name'   => $val !== '' && str_contains((string) ($row['name'] ?? ''), $val),
            'all'    => true,
            default  => false,
        };
        if (!$hit) { continue; }
        return is_int($std) ? $std : ($stdIds[$std] ?? null);
    }
    return null;
}

/**
 * 상세 화면 치수(목록 칸 = 주요 치수 main:true, 그 밖 = 세부 치수): 원본 이름표 순서, 값 있는 칸만. 공통 분류의 이름표와 관계없이
 * 업체 칸 이름 그대로 보여 주도록 products.spec_detail 에 함께 담는다(상세 화면 public/product.php). 순수 함수
 * @return list<array{label:string,symbol:string,value:string,unit:string,main:bool}>
 */
function syc_publish_dims(array $p, array $axes): array
{
    $out = [];
    foreach ($axes as $a) {
        $v = syc_axis_row_value($p, $a['axis_key']);
        if ($v === null) { continue; }
        $out[] = ['label' => $a['label'], 'symbol' => $a['symbol'], 'value' => $v,
                  'unit' => in_array($a['axis_key'], SYC_AXIS_TEXT, true) || $a['unit'] === 'no' ? '' : $a['unit'],
                  'main' => (bool) $a['in_list'] && in_array($a['axis_key'], SYC_PUBLISH_LIST_KEYS, true)];
    }
    return $out;
}

/**
 * 공통 분류 반영: syc_products(모든 출처) → products. 트랜잭션은 호출측(syc_publish_std_run).
 * @return array{products:int, unmapped:int, stale:int}
 */
function syc_publish_std_apply(PDO $pdo): array
{
    $stdIds = [];
    foreach ($pdo->query("SELECT id, slug FROM categories WHERE slug LIKE 'std-%'") as $r) { $stdIds[(string) $r['slug']] = (int) $r['id']; }
    $need = array_unique(array_filter(array_map(static fn($m) => is_string($m[3]) ? $m[3] : null, CATALOG_STD_MAP)));
    $missing = array_values(array_diff($need, array_keys($stdIds)));
    if ($missing) { throw new RuntimeException('공통 분류 새 품명이 아직 DB 에 없습니다: ' . implode(', ', $missing) . ' (database/20261116_std_categories.sql)'); }
    $sycSlug = array_column($pdo->query('SELECT id, slug FROM syc_categories')->fetchAll(), 'slug', 'id');

    $pcols = syc_publish_product_columns($pdo);
    $dimCols = array_values(array_filter(array_map('syc_axis_column', SYC_AXIS_KEYS), static fn($c) => $c !== null && in_array($c, $pcols, true)));
    $cols = array_merge(['category_id', 'name', 'brand', 'spec', 'spec_code'], $dimCols,
                        ['spec_option', 'spec_dims_src', 'spec_detail', 'unit', 'weight_kg', 'description', 'price_b2c', 'price_b2b', 'cost_price', 'stock_qty',
                         'external_source', 'external_id', 'source_url', 'image_url', 'is_active']);
    $imgExtra = array_values(array_intersect(['image_thumb_url', 'image_spec_url'], $pcols));
    $sycCols  = array_column($pdo->query('SHOW COLUMNS FROM syc_products')->fetchAll(), 'Field');
    $cols = array_merge($cols, $imgExtra);
    $upd = implode(', ', array_map(static fn($c) => "{$c} = n.{$c}", array_diff($cols, ['external_source', 'external_id'])));
    $pins = $pdo->prepare('INSERT INTO products (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ') AS n ON DUPLICATE KEY UPDATE ' . $upd);
    $axesBySyc = syc_axes_rows();
    $n = 0; $unmapped = 0;
    foreach ($pdo->query('SELECT * FROM syc_products ORDER BY id') as $p) {
        $sid = (int) $p['category_id'];
        $cid = catalog_std_category((string) ($sycSlug[$sid] ?? ''), $p, $stdIds);
        if ($cid === null) { $unmapped++; continue; }      // 매핑 없는 분류(새 분류를 만든 경우) — 반영하지 않고 알림
        $dims = syc_publish_dims($p, $axesBySyc[$sid] ?? []);
        $vals = [$cid, $p['name'], $p['brand'], syc_publish_spec_text($p), $p['spec_code']];
        foreach ($dimCols as $dc) { $vals[] = $p[$dc] ?? null; }
        array_push($vals, $p['spec_option'], $p['spec'], $dims ? json_encode($dims, JSON_UNESCAPED_UNICODE) : null, $p['unit'], $p['weight_kg'], $p['description'],
                   (int) $p['price_b2c'], (int) $p['price_b2b'], (int) $p['cost_price'], (int) $p['stock_qty'],
                   $p['external_source'], $p['external_id'], $p['source_url'], $p['image_file'] ?: $p['image_thumb_file'], (int) $p['is_active']);
        foreach ($imgExtra as $c) {
            $vals[] = $c === 'image_thumb_url' ? ($p['image_thumb_file'] ?? null) : (in_array('image_spec_file', $sycCols, true) ? ($p['image_spec_file'] ?? null) : null);
        }
        $pins->execute($vals);
        $n++;
    }
    // 원본에서 빠진 업체 상품은 숨김(지우지 않음 — 견적·주문 기록이 가리킬 수 있음). 케이토목 행은 건드리지 않음.
    // 업체 연결 상품 줄(출처ID link-…, app/vendors.php — 원본 없이 products 에만 있음)도 건드리지 않음
    $stale = $pdo->exec("UPDATE products p SET p.is_active = 0
                          WHERE (p.external_source = 'syccrt' OR p.external_source LIKE 'vendor-%') AND p.is_active = 1
                            AND (p.external_id IS NULL OR p.external_id NOT LIKE 'link-%')
                            AND NOT EXISTS (SELECT 1 FROM syc_products s WHERE s.external_source = p.external_source AND s.external_id = p.external_id)");
    // 처음 공통 분류로 바꿀 때 한 번: 새 품명(std-…, 숨긴 채 들어옴)과 전환(2026-11-12) 때 숨긴 케이토목 분류를 보이게 —
    // 상품 활성 여부는 그대로(케이토목 상품은 비활성이면 안 보임). 이후 반영에서는 관리자가 바꾼 분류 활성 여부를 건드리지 않음
    $opened = 0;
    if (syc_catalog_mode(true) !== 'std') {
        $opened += $pdo->exec("UPDATE categories SET is_active = 1 WHERE is_active = 0 AND slug LIKE 'std-%'");
        $opened += $pdo->exec("UPDATE categories SET is_active = 1 WHERE is_active = 0 AND id IN (SELECT ref_id FROM syc_catalog_hidden WHERE entity = 'category')");
    }
    $pdo->exec("INSERT INTO syc_catalog_state (id, mode, published_at) VALUES (1, 'std', NOW()) AS n ON DUPLICATE KEY UPDATE mode = n.mode, published_at = n.published_at");
    return ['products' => $n, 'unmapped' => $unmapped, 'stale' => (int) $stale, 'categories_opened' => $opened];
}

/** 공통 분류 반영 실행(트랜잭션). 실패하면 되돌리고 예외 */
function syc_publish_std_run(PDO $pdo, ?int $userId = null): array
{
    $pdo->beginTransaction();
    try {
        $r = syc_publish_std_apply($pdo);
        if ($userId !== null) { $pdo->prepare('UPDATE syc_catalog_state SET updated_by = ? WHERE id = 1')->execute([$userId]); }
        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    syc_catalog_mode(true);
    return $r;
}

