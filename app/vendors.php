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
    if (!vendors_available()) {
        return 'NULL';
    }
    return "(SELECT GROUP_CONCAT(v.name ORDER BY pv.is_primary DESC, v.name SEPARATOR '|')
             FROM product_vendors pv
             JOIN vendors v ON v.id = pv.vendor_id
             WHERE pv.product_id = p.id AND v.is_active = 1)";
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
