<?php
declare(strict_types=1);
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
    if (!vendors_available()) {
        return "NULLIF(p.brand, '')";
    }
    return "COALESCE((SELECT GROUP_CONCAT(v.name ORDER BY pv.is_primary DESC, v.name SEPARATOR '|')
             FROM product_vendors pv
             JOIN vendors v ON v.id = pv.vendor_id
             WHERE pv.product_id = p.id AND v.is_active = 1), NULLIF(p.brand, ''))";
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
 * 업체 상태 바꾸기 영향(직원 업체 목록 확인 창·응답 숫자). 제품관리 상품 = syc_products 출처 vendor-<id>
 * @return array{items:int, primary_items:int, products:int, products_active:int}
 */
function vendor_impact(PDO $pdo, int $vendorId): array
{
    require_once __DIR__ . '/product_sources.php';
    $st = $pdo->prepare('SELECT COUNT(*) AS items, COALESCE(SUM(is_primary=1), 0) AS prim FROM product_vendors WHERE vendor_id=:id');
    $st->execute(['id' => $vendorId]);
    $pv = $st->fetch() ?: ['items' => 0, 'prim' => 0];
    $sp = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(is_active=1), 0) AS act FROM syc_products WHERE external_source=:src');
    $sp->execute(['src' => product_source_vendor_code($vendorId)]);
    $p = $sp->fetch() ?: ['n' => 0, 'act' => 0];
    return ['items' => (int)$pv['items'], 'primary_items' => (int)$pv['prim'], 'products' => (int)$p['n'], 'products_active' => (int)$p['act']];
}

/**
 * 업체를 끌 때 그 업체 상품 전부 비활성(사용자 결정 2026-11-17): 원본 syc_products 와 고객용 반영본 products(같은 출처) 둘 다.
 * 반영본만 바꾸면 다음 공통 분류 반영(app/syc_publish.php) 때 원본 값으로 되살아나므로 원본도 바꾼다. is_active 만 바꾸고 가격 칸은 건드리지 않음.
 * 다시 켤 때는 부르지 않는다(상품은 관리자가 직접 활성으로). 트랜잭션은 호출측. @return array{products_off:int}
 */
function vendor_deactivate_products(PDO $pdo, int $vendorId): array
{
    require_once __DIR__ . '/product_sources.php';
    $src = product_source_vendor_code($vendorId);
    $a = $pdo->prepare('UPDATE syc_products SET is_active=0 WHERE external_source=:src AND is_active=1');
    $a->execute(['src' => $src]);
    $pdo->prepare('UPDATE products SET is_active=0 WHERE external_source=:src AND is_active=1')->execute(['src' => $src]);
    return ['products_off' => $a->rowCount()];
}
