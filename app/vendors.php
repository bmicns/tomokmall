<?php
declare(strict_types=1);
require_once __DIR__ . '/product_sources.php';   // 업체 ↔ 출처 코드(vendor_source_sql 등)
/**
 * 공급업체(vendor) 표시 유틸 (단일 책임: 업체명 조회 조각 + 표시).
 *
 * 한 상품에 복수 업체가 연결될 수 있음(product_vendors 다대다).
 * 목록/상세에서 업체명을 노출한다.
 *
 * ⚠️ 견고성: 운영 DB에 vendors/product_vendors 테이블이 아직 없을 수 있으므로
 *    (마이그레이션 전 코드 배포 등) 테이블 존재를 감지해 조건부로 조인한다.
 *    없으면 vendor_names = NULL 로 폴백 → 목록/상세 무손상.
 */

/** product_vendors 테이블 사용 가능 여부(요청당 1회 캐시). */
function vendors_available(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $ok = false;
    if (!db()) { return false; }
    try {
        db()->query('SELECT 1 FROM product_vendors LIMIT 1');
        $ok = true;
    } catch (\Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/**
 * 상품 SELECT에 붙일 업체명 서브쿼리 조각(별칭 vendor_names).
 * 주거래(is_primary) 우선, '|' 구분. 테이블 없으면 NULL.
 * 상품 별칭은 p. 로 가정.
 */
function vendor_names_subquery(): string
{
    // 업체공급가(product_vendors)가 없으면 상품의 업체명(brand) — 상용콘크리트 반영 상품(app/syc_publish.php)은 업체가 상품 행에 있음.
    // 케이토목 상품은 brand 가 비어 있어 기존 표시 그대로(운영 확인 2026-11-12: 업체공급가 없이 brand 만 있는 상품 0건)
    // 업체 연결 상품 줄(출처ID link-<이 상품 id>)이 있는 업체는 그 줄에 따로 보이므로 원래 상품 줄 이름에서 뺀다(S17 업체가 다르면 다른 줄)
    if (!vendors_available()) {
        return "NULLIF(p.brand, '')";
    }
    return "COALESCE((SELECT GROUP_CONCAT(v.name ORDER BY pv.is_primary DESC, v.name SEPARATOR '|')
             FROM product_vendors pv
             JOIN vendors v ON v.id = pv.vendor_id
             WHERE pv.product_id = p.id AND v.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM products lp WHERE lp.external_source = " . vendor_source_sql(db(), 'v') . "
                                                           AND lp.external_id = CONCAT('link-', p.id))), NULLIF(p.brand, '')" . vendor_names_ktomok_fallback() . ")";
}

/**
 * 연결 업체·brand 가 모두 없는 케이토목 상품 → 업체 목록의 케이토목(출처 코드 ktomok) 업체명(활성일 때만, S25).
 * 케이토목 상품은 brand 가 비어 있어, 임시 업체 연결을 끄면 '-' 로 보이던 문제(2026-11-23).
 */
function vendor_names_ktomok_fallback(): string
{
    if (!vendors_has_source_code(db())) {
        return '';
    }
    [$cond] = product_source_rows_sql(PRODUCT_SOURCE_KTOMOK, 'p.external_source', 'src');
    return ", CASE WHEN {$cond} THEN (SELECT kv.name FROM vendors kv WHERE kv.source_code = '" . PRODUCT_SOURCE_KTOMOK . "' AND kv.is_active = 1 LIMIT 1) END";
}

/**
 * vendor_names('A|B|C') → 표시 HTML.
 * @param bool $full true=전체 나열(상세), false=대표 1곳 + '외 N'(목록)
 */
function render_vendor_names(?string $names, bool $full = false): string
{
    if ($names === null || $names === '') { return ''; }
    $list = array_values(array_filter(array_map('trim', explode('|', $names)), static fn($s) => $s !== ''));
    if (!$list) { return ''; }

    if ($full) {
        return implode(', ', array_map('e', $list));
    }
    $html = '<span class="pt-vendor-name">' . e($list[0]) . '</span>';
    $more = count($list) - 1;
    if ($more > 0) {
        $html .= ' <span class="pt-vendor-more">외 ' . $more . '</span>';
    }
    return $html;
}

/**
 * 고객용 상품 표(products)에만 있는 이 업체 상품 줄(원본 syc_products 없음) 조건: 케이토목 = 케이토목 상품 전부(예전 출처 포함),
 * 그 밖 = 업체 연결 상품 줄(출처ID link-…). @return array{0:string,1:array<string,string>}
 */
function vendor_products_only_rows_sql(string $src): array
{
    if ($src === PRODUCT_SOURCE_KTOMOK) { return product_source_rows_sql($src, 'external_source', 'src'); }
    return ["external_source = :src AND external_id LIKE 'link-%'", ['src' => $src]];
}

/**
 * 업체 상태 바꾸기 영향(직원 업체 목록 확인 창·응답 숫자). 제품관리 상품 = syc_products 의 그 업체 출처 + products 에만 있는 줄
 * (출처 = 업체 표 '출처 코드' 칸, 비면 vendor-<id> — S25)
 * @return array{items:int, primary_items:int, products:int, products_active:int}
 */
function vendor_impact(PDO $pdo, int $vendorId): array
{
    require_once __DIR__ . '/product_sources.php';
    $src = vendor_source_code($pdo, $vendorId);
    $st = $pdo->prepare('SELECT COUNT(*) AS items, COALESCE(SUM(is_primary=1), 0) AS prim FROM product_vendors WHERE vendor_id=:id');
    $st->execute(['id' => $vendorId]);
    $pv = $st->fetch() ?: ['items' => 0, 'prim' => 0];
    $sp = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(is_active=1), 0) AS act FROM syc_products WHERE external_source=:src');
    $sp->execute(['src' => $src]);
    $p = $sp->fetch() ?: ['n' => 0, 'act' => 0];
    [$w, $wp] = vendor_products_only_rows_sql($src);
    $lp = $pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(is_active=1), 0) AS act FROM products WHERE {$w}");
    $lp->execute($wp);
    $l = $lp->fetch() ?: ['n' => 0, 'act' => 0];
    return ['items' => (int)$pv['items'], 'primary_items' => (int)$pv['prim'],
            'products' => (int)$p['n'] + (int)$l['n'], 'products_active' => (int)$p['act'] + (int)$l['act']];
}

/**
 * 업체를 끌 때 그 업체 상품 전부 비활성(사용자 결정 2026-11-17): 원본 syc_products 와 고객용 반영본 products(같은 출처) 둘 다.
 * 반영본만 바꾸면 다음 공통 분류 반영(app/syc_publish.php) 때 원본 값으로 되살아나므로 원본도 바꾼다. is_active 만 바꾸고 가격 칸은 건드리지 않음.
 * 케이토목(출처 코드 ktomok)은 고객용 표의 케이토목 상품 전부(예전 출처 포함). 다시 켤 때는 부르지 않는다(상품은 관리자가 직접 활성으로).
 * 트랜잭션은 호출측. @return array{products_off:int}
 */
function vendor_deactivate_products(PDO $pdo, int $vendorId): array
{
    require_once __DIR__ . '/product_sources.php';
    $src = vendor_source_code($pdo, $vendorId);
    $a = $pdo->prepare('UPDATE syc_products SET is_active=0 WHERE external_source=:src AND is_active=1');
    $a->execute(['src' => $src]);
    $offSyc = $a->rowCount();
    // products 에만 있는 줄(케이토목 상품·업체 연결 상품 줄) 수만 더함 — 반영본은 원본 수로 셈
    [$w, $wp] = vendor_products_only_rows_sql($src);
    $l = $pdo->prepare("SELECT COUNT(*) FROM products WHERE ({$w}) AND is_active=1");
    $l->execute($wp);
    $offOnly = (int) $l->fetchColumn();
    [$all, $ap] = product_source_rows_sql($src, 'external_source', 'src');
    $pdo->prepare("UPDATE products SET is_active=0 WHERE ({$all}) AND is_active=1")->execute($ap);
    return ['products_off' => $offSyc + $offOnly];
}

/*
 * 업체 연결 = 업체 상품(사용자 결정 2026-11-17, 가안): 품목의 [업체 관리]에서 업체를 추가하면 그 업체가 파는 상품 줄을
 * 고객용 상품 표(products)에 만든다 — 출처 = 그 업체 출처 코드(vendor-<업체 id>, 상용콘크리트·케이토목은 syccrt·ktomok — S25),
 * 출처ID link-<원래 상품 id>. DB 구조 변경 없음.
 * 분류·이름·규격·치수·이미지·정가(price_b2c)는 원래 상품에서 복사, 매입가(cost_price) = 공급단가, 브랜드 = 업체명,
 * 비활성으로 시작(관리자가 제품관리 '모든 업체'에서 활성으로). 회원가는 기존 계산(app/pricing.php) 그대로.
 * 연결 삭제(공급 제외) → 연결 행 삭제 + 이 줄 비활성(행은 남김 — 견적·주문 기록이 가리킴).
 */
const VENDOR_LINK_PREFIX = 'link-';

/** 업체 연결 상품의 출처ID(link-<원래 상품 id>) */
function vendor_link_external_id(int $productId): string
{
    return VENDOR_LINK_PREFIX . $productId;
}

/** 업체 연결 상품 줄 id(없으면 null) */
function vendor_link_find(PDO $pdo, int $productId, int $vendorId): ?int
{
    require_once __DIR__ . '/product_sources.php';
    $st = $pdo->prepare('SELECT id FROM products WHERE external_source = :src AND external_id = :eid');
    $st->execute(['src' => vendor_source_code($pdo, $vendorId), 'eid' => vendor_link_external_id($productId)]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int) $id;
}

/** 이 상품이 업체 연결 상품 줄인가(연결 상품에 다시 업체를 붙이지 않음) */
function vendor_link_is_link_row(PDO $pdo, int $productId): bool
{
    $st = $pdo->prepare('SELECT 1 FROM products WHERE id = :id AND external_id LIKE :pre');
    $st->execute(['id' => $productId, 'pre' => VENDOR_LINK_PREFIX . '%']);
    return $st->fetchColumn() !== false;
}

/**
 * 업체 연결 상품 줄 만들기 또는 고치기. 트랜잭션은 호출측.
 * - 없으면 원래 상품 줄을 복사해 새로 만든다(비활성).
 * - 있으면(전에 삭제했다가 다시 추가 포함) 매입가·브랜드만 고친다. 활성 상태는 그대로(삭제 때 꺼졌으면 비활성 유지).
 * @return array{id:int, created:bool}
 */
function vendor_link_upsert(PDO $pdo, int $productId, int $vendorId, int $supplyPrice): array
{
    require_once __DIR__ . '/product_sources.php';
    $vn = $pdo->prepare('SELECT name FROM vendors WHERE id = :id');
    $vn->execute(['id' => $vendorId]);
    $vendorName = $vn->fetchColumn();
    if ($vendorName === false) { throw new InvalidArgumentException('업체를 찾지 못했습니다.'); }
    $brand = mb_substr((string) $vendorName, 0, 100);
    $supplyPrice = max(0, $supplyPrice);

    $id = vendor_link_find($pdo, $productId, $vendorId);
    if ($id !== null) {
        $pdo->prepare('UPDATE products SET cost_price = :c, brand = :b WHERE id = :id')->execute(['c' => $supplyPrice, 'b' => $brand, 'id' => $id]);
        return ['id' => $id, 'created' => false];
    }
    // 원래 상품의 칸을 그대로 복사(칸 목록은 지금 표 구조에서 — 운영·로컬 칸 차이에 안전). 바꾸는 칸만 따로 넣는다
    $own  = ['id', 'created_at', 'updated_at', 'brand', 'external_source', 'external_id', 'source_url', 'cost_price', 'is_active'];
    $copy = [];
    foreach ($pdo->query('SHOW COLUMNS FROM products')->fetchAll() as $c) {
        $f = (string) $c['Field'];
        if (in_array($f, $own, true) || stripos((string) ($c['Extra'] ?? ''), 'GENERATED') !== false) { continue; }
        if (!preg_match('/^[a-z0-9_]+$/', $f)) { continue; }
        $copy[] = $f;
    }
    $hasCost = (bool) $pdo->query("SHOW COLUMNS FROM products LIKE 'cost_price'")->fetch();
    $colSql = implode(', ', array_map(static fn($f) => "`{$f}`", $copy));
    $sql = "INSERT INTO products ({$colSql}, brand, external_source, external_id" . ($hasCost ? ', cost_price' : '') . ", is_active)
            SELECT {$colSql}, :b, :src, :eid" . ($hasCost ? ', :c' : '') . ", 0 FROM products WHERE id = :pid";
    $params = ['b' => $brand, 'src' => vendor_source_code($pdo, $vendorId), 'eid' => vendor_link_external_id($productId), 'pid' => $productId];
    if ($hasCost) { $params['c'] = $supplyPrice; }
    $ins = $pdo->prepare($sql);
    $ins->execute($params);
    if ($ins->rowCount() !== 1) { throw new InvalidArgumentException('원래 상품을 찾지 못했습니다.'); }
    return ['id' => (int) $pdo->lastInsertId(), 'created' => true];
}

/** 업체 연결 삭제 때: 그 업체 상품 줄 비활성(행은 남김). @return int 바뀐 줄 수 */
function vendor_link_deactivate(PDO $pdo, int $productId, int $vendorId): int
{
    require_once __DIR__ . '/product_sources.php';
    $st = $pdo->prepare('UPDATE products SET is_active = 0 WHERE external_source = :src AND external_id = :eid AND is_active = 1');
    $st->execute(['src' => vendor_source_code($pdo, $vendorId), 'eid' => vendor_link_external_id($productId)]);
    return $st->rowCount();
}
