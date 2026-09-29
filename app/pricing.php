<?php
declare(strict_types=1);
/**
 * 회원 등급별 차등 가격 유틸 (단일 책임: 가격 계산 + 표시 판단).
 *
 * 등급(price_tier): guest(비회원) / customer(개인) / corporate(기업) / internal(직원·관리자)
 *
 *   판매가(정가)  = price_b2c  (취소선 기준)
 *   매입가        = cost_price (회원가 산정 기준, internal 전용 노출)
 *   개인회원가    = round(cost_price × (100 + 카테고리 개인마진율) / 100)   (매입가 없으면 정가)
 *   기업회원가    = round(cost_price × (100 + 카테고리 기업마진율) / 100)   (매입가 없으면 정가)
 *     └ 마진율은 categories.discount_customer / discount_corporate 컬럼(매입가 대비 %).
 *
 * 표시 규칙:
 *   guest      → 판매가만
 *   customer   → 개인회원가 + 판매가(취소선)   (회원가 ≥ 정가면 판매가만)
 *   corporate  → 기업회원가 + 판매가(취소선)
 *   internal   → 매입가 · 판매가 · 개인회원가 · 기업회원가 전부 + 매입가 대비 마진율
 *
 * 결제/견적 단가(effective_unit_price):
 *   guest=정가, customer=개인회원가, corporate·internal=기업회원가
 */

/**
 * 카테고리별 유효 할인율(%) 맵. 말단에 값이 없으면(0) 부모 체인 상속.
 * @param string $col 'discount_customer'(개인) | 'discount_corporate'(기업)
 * @return array<int,int> [category_id => rate]
 */
function category_discount_map(string $col = 'discount_customer'): array
{
    // SQL 인젝션 방지: 컬럼명 화이트리스트
    if (!in_array($col, ['discount_customer', 'discount_corporate'], true)) {
        $col = 'discount_customer';
    }
    static $cache = [];
    if (isset($cache[$col])) { return $cache[$col]; }
    $map = [];
    if (!db()) { return $cache[$col] = $map; }

    $par = []; $own = [];
    try {
        $rows = db()->query("SELECT id, parent_id, COALESCE({$col},0) AS d FROM categories")->fetchAll();
    } catch (\Throwable $e) {
        return $cache[$col] = $map; // 컬럼 미존재 등 → 전부 할인 0(안전)
    }
    foreach ($rows as $c) {
        $id = (int) $c['id'];
        $par[$id] = $c['parent_id'] !== null ? (int) $c['parent_id'] : null;
        $own[$id] = (int) $c['d'];
    }
    foreach (array_keys($own) as $id) {
        $r = $id; $rate = 0; $seen = [];
        while ($r !== null && !isset($seen[$r])) {
            $seen[$r] = 1;
            if (($own[$r] ?? 0) > 0) { $rate = (int) $own[$r]; break; }
            $r = $par[$r] ?? null;
        }
        $map[$id] = $rate;
    }
    return $cache[$col] = $map;
}

/**
 * 원가·관리자뷰(internal 등급)를 볼 수 있는 지정 계정 이메일.
 * 이 계정으로 로그인했을 때만 원가 + 판매가 3종(일반·개인·기업)이 노출된다.
 * 그 외 모든 계정(기업회원·직원·관리자 role 포함)은 원가를 볼 수 없다.
 */
const COST_VIEWER_EMAIL = 'admin@admin.com';

/** 현재 로그인 사용자의 가격 등급. */
function price_tier(): string
{
    $u = $_SESSION['user'] ?? null;
    if (!is_array($u) || empty($u['role'])) { return 'guest'; }
    // 원가·관리자뷰(internal)는 지정 계정 이메일에서만 노출(대소문자 무시)
    if (strtolower((string) ($u['email'] ?? '')) === COST_VIEWER_EMAIL) { return 'internal'; }
    $role = (string) $u['role'];
    // 그 외: 기업회원·직원·관리자는 기업가, 개인은 개인할인가
    if (in_array($role, ['corporate', 'staff', 'admin'], true)) { return 'corporate'; }
    return 'customer';
}

/**
 * 상품 1건의 가격 묶음 계산.
 * @param array    $p            products 행(price_b2c, price_b2b, cost_price, category_id)
 * @param int|null $discountRate 개인할인율%. null이면 category_discount_map()에서 조회
 * @return array{list:int,customer:int,corporate:int,cost:int,rate:int,has_customer_discount:bool,has_corporate_discount:bool}
 */
function price_bundle(array $p, ?int $discountRate = null): array
{
    $list = (int) ($p['price_b2c'] ?? 0);   // 정가(취소선 기준)
    $cost = (int) ($p['cost_price'] ?? 0);   // 매입가(회원가 산정 기준)
    $cid  = (int) ($p['category_id'] ?? 0);

    // 회원 등급별 '매입가 대비 마진율(%)'. 카테고리 상속.
    //   개인회원가 = 매입가 × (1 + 개인마진율/100)
    //   기업회원가 = 매입가 × (1 + 기업마진율/100)
    // (discount_customer/discount_corporate 컬럼을 '매입가 대비 마진율'로 사용)
    $custMargin = $cid ? (category_discount_map('discount_customer')[$cid] ?? 0) : 0;
    if ($discountRate !== null) { $custMargin = $discountRate; } // 외부 오버라이드(테스트 등)
    $corpMargin = $cid ? (category_discount_map('discount_corporate')[$cid] ?? 0) : 0;
    $custMargin = max(0, min(1000, (int) $custMargin));
    $corpMargin = max(0, min(1000, (int) $corpMargin));

    // 매입가 기준 마진으로 회원가 산정. 매입가 미설정(0)이면 정가로 폴백(할인 없음).
    $customer  = $cost > 0 ? (int) round($cost * (100 + $custMargin) / 100) : $list;
    $corporate = $cost > 0 ? (int) round($cost * (100 + $corpMargin) / 100) : $list;

    return [
        'list'      => $list,
        'customer'  => $customer,
        'corporate' => $corporate,
        'cost'      => $cost,
        'rate'      => $custMargin,
        'corp_rate' => $corpMargin,
        // 회원가가 정가보다 쌀 때만 '정가 취소선 + 회원가'로 표시.
        'has_customer_discount'  => $customer  > 0 && $customer  < $list,
        'has_corporate_discount' => $corporate > 0 && $corporate < $list,
    ];
}

/**
 * 현재 사용자가 실제 지불하는 단가(장바구니·견적).
 * guest=정가, customer=개인할인가, corporate·internal=기업할인가.
 */
function effective_unit_price(array $p, ?int $discountRate = null): int
{
    $b = price_bundle($p, $discountRate);
    switch (price_tier()) {
        case 'customer':  return $b['customer'] > 0 ? $b['customer'] : $b['list'];
        case 'corporate':
        case 'internal':  return $b['corporate'];
        default:          return $b['list'];
    }
}

/**
 * 목록(표) 셀용 가격 HTML. 좁은 공간에 등급별로 압축 표시.
 */
function price_tag_list(array $p, ?int $discountRate = null): string
{
    $b = price_bundle($p, $discountRate);
    if ($b['list'] <= 0) {
        return '<span class="pt-price-ask">견적문의</span>';
    }
    $tier = price_tier();
    $nf   = static fn(int $v): string => number_format($v);

    if ($tier === 'internal') {
        // 원가는 별도 컬럼(price_cost_cell)에서 표시. 판매가 컬럼엔 일반/개인/기업 3종 + 원가 대비 마진율.
        $cost = $b['cost'];
        $mg = static function (int $price) use ($cost): string {
            $r = markup_rate($price, $cost);
            return $r === null ? '' : ' <span class="pt-margin">' . ($r >= 0 ? '+' : '') . $r . '%</span>';
        };
        $h  = '<span class="pt-price-sub">일반 ' . $nf($b['list'])      . $mg($b['list'])      . '</span>';
        $h .= '<span class="pt-price-sub">개인 ' . $nf($b['customer'])  . $mg($b['customer'])  . '</span>';
        $h .= '<span class="pt-price-sub">기업 ' . $nf($b['corporate']) . $mg($b['corporate']) . '</span>';
        return $h;
    }
    if ($tier === 'customer' && $b['has_customer_discount']) {
        return '<del class="pt-price-was">' . $nf($b['list']) . '</del>'
             . '<strong class="pt-price-val pt-price-sale">' . $nf($b['customer']) . '</strong>';
    }
    if ($tier === 'corporate' && $b['has_corporate_discount']) {
        return '<del class="pt-price-was">' . $nf($b['list']) . '</del>'
             . '<strong class="pt-price-val pt-price-sale">' . $nf($b['corporate']) . '</strong>';
    }
    // guest 또는 할인 없음
    return '<strong class="pt-price-val">' . $nf($b['list']) . '</strong>';
}

/**
 * 원가(매입가) 대비 마진율(마크업, %). (판매가 - 원가) / 원가 × 100.
 * 원가가 0 이하면 계산 불가 → null.
 */
function markup_rate(int $price, int $cost): ?int
{
    if ($cost <= 0) { return null; }
    return (int) round(($price - $cost) / $cost * 100);
}

/**
 * 목록 '매입가' 컬럼 셀 HTML. internal(지정 계정) 전용.
 * internal 이 아니면 빈 문자열(호출부에서 컬럼 자체를 렌더하지 않아야 함).
 */
function price_cost_cell(array $p, ?int $discountRate = null): string
{
    if (price_tier() !== 'internal') { return ''; }
    $b = price_bundle($p, $discountRate);
    if ($b['cost'] <= 0) { return '<span class="pt-vendor-none">-</span>'; }
    return '<span class="pt-price-cost">' . number_format($b['cost']) . '</span>';
}
