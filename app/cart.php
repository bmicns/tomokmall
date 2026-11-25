<?php
declare(strict_types=1);
/**
 * 장바구니 유틸 (app/cart.php)
 *
 * 책임: 세션 기반 장바구니 읽기/쓰기 + 품목 조회
 * 금지: UI 렌더링, AI Tool 정의
 *
 * 제공 함수:
 *  cart_store_key()             — 담는 세션 칸(기본 사이트 견적서, 빠른 견적 창은 창 안 목록)
 *  cart_merge_quick_list()      — 창 안 목록 → 사이트 견적서(순수)
 *  cart_session_read()          — 세션 장바구니 읽기
 *  cart_session_write()         — 세션 장바구니 저장 + 세션 잠금 해제
 *  cart_fetch_items()           — product_id IN(...) 단일 쿼리 (B2C 단가)
 *  cart_summary_from_items()    — 조회된 items 기준 count/total 계산
 *  cart_summary()               — cart 배열로 count/total 계산
 *  quick_items_fetch()          — quick_items 세션 품목 조회 (B2B 단가 우선)
 */

/**
 * 장바구니를 읽고 쓰는 세션 칸 — 기본 'cart'(사이트 견적서).
 * 우측 하단 빠른 견적 창은 케이토목 1분 자동견적처럼 '창 안 목록'(대화마다 'qc_list_<상담 키>')에 담고,
 * '견적서 만들어줘'·[견적 요청하기] 때만 사이트 견적서로 옮긴다(사용자 결정 2026-11-24, A11).
 * 이 요청 동안만 바뀐다(public/api/chat/quick.php 가 설정).
 */
function cart_store_key(?string $set = null): string
{
    static $key = 'cart';
    if ($set !== null) { $key = $set; }
    return $key;
}

/** 지금 담는 곳이 빠른 견적 창 목록인지(수량 0 = '수량 미정' 허용) */
function cart_store_is_quick_list(): bool
{
    return str_starts_with(cart_store_key(), 'qc_list_');
}

/**
 * 창 안 목록 → 사이트 견적서 (순수 함수). 같은 상품은 목록 수량으로 맞춘다(여러 번 눌러도 두 배가 되지 않음).
 * 수량 0(미정) 품목은 옮기지 않고 따로 돌려준다.
 *
 * @param array<int,int> $cart  사이트 견적서
 * @param array<int,int> $list  창 안 목록
 * @return array{cart:array<int,int>, moved:list<int>, skipped:list<int>}
 */
function cart_merge_quick_list(array $cart, array $list): array
{
    $moved = [];
    $skipped = [];
    foreach ($list as $pid => $qty) {
        $pid = (int) $pid;
        $qty = (int) $qty;
        if ($pid <= 0) { continue; }
        if ($qty < 1) { $skipped[] = $pid; continue; }
        $cart[$pid] = min(999, $qty);
        $moved[] = $pid;
    }
    return ['cart' => $cart, 'moved' => $moved, 'skipped' => $skipped];
}

/**
 * 빠른 견적 창 '견적 목록' 탭의 수량 −/+·빼기 (순수 함수). 목록에 있는 상품만 바꾼다(새 상품은 담지 않음), 수량 0(미정)~999.
 * @param array<int,int> $list
 * @return array<int,int>|null  바뀐 목록, 목록에 없는 상품이면 null
 */
function cart_quick_list_edit(array $list, int $pid, ?int $qty): ?array
{
    if ($pid <= 0 || !array_key_exists($pid, $list)) { return null; }
    if ($qty === null) {
        unset($list[$pid]);
    } else {
        $list[$pid] = max(0, min(999, $qty));
    }
    return $list;
}

/**
 * 세션 장바구니 읽기 (세션이 닫혀 있으면 재개)
 *
 * @return array<int,int>  [product_id => quantity]
 */
function cart_session_read(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    return $_SESSION[cart_store_key()] ?? [];
}

/**
 * 세션 장바구니 저장 후 쓰기 잠금 해제
 * SSE 장기 연결 중 다른 요청 블록 방지
 */
function cart_session_write(array $cart): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION[cart_store_key()] = $cart;
    session_write_close();
}

/**
 * 장바구니에 담겨 있지만 지금은 팔지 않는 상품(비활성·없어짐) — 견적 요청 화면에 회색 '지금은 없는 상품입니다' 줄로 보여 주고
 * X 로 빼게 한다(사용자 결정 2026-11-17). 금액·접수(cart_fetch_items)에는 원래대로 들어가지 않는다.
 *
 * @param  array<int,int> $cart
 * @return list<array{product_id:int,name:string,spec:string,quantity:int}>
 */
function cart_fetch_gone_items(array $cart): array
{
    if (empty($cart) || !db()) return [];
    $ids = array_map('intval', array_keys($cart));
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare("SELECT id, name, spec, is_active FROM products WHERE id IN ({$ph})");
    $stmt->execute($ids);
    $found = [];
    foreach ($stmt->fetchAll() as $r) { $found[(int) $r['id']] = $r; }
    $out = [];
    foreach ($ids as $pid) {
        $r = $found[$pid] ?? null;
        if ($r !== null && (int) $r['is_active'] === 1) { continue; }
        $out[] = ['product_id' => $pid, 'name' => $r['name'] ?? '삭제된 상품', 'spec' => (string) ($r['spec'] ?? ''), 'quantity' => (int) ($cart[$pid] ?? 0)];
    }
    return $out;
}

/**
 * 장바구니 items 배열 반환 — product_id IN (...) 단일 쿼리로 N+1 방지
 * B2C 단가 기준 (일반 장바구니 / AI 상담 경로용)
 *
 * @param  array<int,int> $cart
 * @return list<array{product_id:int,name:string,quantity:int,unit_price:int,subtotal:int}>
 */
function cart_fetch_items(array $cart): array
{
    if (empty($cart)) return [];

    $ids = array_map('intval', array_keys($cart));
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare(
        "SELECT id, name, spec, price_b2c, image_url FROM products WHERE id IN ({$ph}) AND is_active = 1"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $pid     = (int) $row['id'];
        $qty     = (int) ($cart[$pid] ?? 0);
        $price   = (int) $row['price_b2c'];
        $items[] = [
            'product_id' => $pid,
            'name'       => $row['name'],
            'spec'       => $row['spec'] ?? '',
            'quantity'   => $qty,
            'unit_price' => $price,
            'subtotal'   => $price * $qty,
            'image_url'  => $row['image_url'] ?? '',
        ];
    }

    return $items;
}

/**
 * 이미 조회된 items 배열로 count, total 계산 — DB 재호출 없음
 *
 * @param  list<array{quantity:int,subtotal:int}> $items  cart_fetch_items() 반환값
 * @return array{int,int}  [count, total]
 */
function cart_summary_from_items(array $items): array
{
    $count = (int) array_sum(array_column($items, 'quantity'));
    $total = (int) array_sum(array_column($items, 'subtotal'));
    return [$count, $total];
}

/**
 * 장바구니 count, total 계산 — DB 가격 기준
 * items 배열이 이미 있으면 cart_summary_from_items() 를 사용하라.
 *
 * @param  array<int,int> $cart
 * @return array{int,int}  [count, total]
 */
function cart_summary(array $cart): array
{
    if (empty($cart)) return [0, 0];

    $items = cart_fetch_items($cart);
    return cart_summary_from_items($items);
}

/**
 * quick_items 세션의 품목 배열 반환 — B2B 단가 우선 (기업회원 즉시견적용)
 * estimate_create() quick mode fallback 에서 사용
 *
 * @param  array<int,int> $quickItems  [product_id => quantity]
 * @return list<array{product_id:int,name:string,spec:string,quantity:int,unit_price:int,subtotal:int}>
 */
function quick_items_fetch(array $quickItems): array
{
    if (empty($quickItems)) return [];

    $ids = array_map('intval', array_keys($quickItems));
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare(
        "SELECT id, name, spec, price_b2c, price_b2b FROM products WHERE id IN ({$ph}) AND is_active = 1"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $items = [];
    foreach ($rows as $row) {
        $pid       = (int) $row['id'];
        $qty       = (int) ($quickItems[$pid] ?? 0);
        $b2b       = (int) $row['price_b2b'];
        $unitPrice = $b2b > 0 ? $b2b : (int) $row['price_b2c'];
        $items[]   = [
            'product_id' => $pid,
            'name'       => $row['name'],
            'spec'       => $row['spec'] ?? '',   // 견적 요청 화면 규격 칸(cart_fetch_items 와 같은 필드) — 없으면 기업회원 즉시견적 경로에서 규격이 비었음
            'quantity'   => $qty,
            'unit_price' => $unitPrice,
            'subtotal'   => $unitPrice * $qty,
        ];
    }

    return $items;
}

/**
 * 회원 화면 표시 단가 — 상품 상세(public/product.php)·목록과 같은 규칙. app/pricing.php 의 계산을 그대로 쓴다.
 * 회원(개인·기업)이 보는 화면(견적 요청·AI 상담·기업회원 즉시견적·요청된 견적·이전주문 등)이 같은 금액을 쓰도록 한 곳에서 계산.
 * 표시 전용: 저장 단가(견적 estimate_items·주문 order_items, cart_fetch_items·quick_items_fetch 원래 값)와
 * 관리자·직원 화면 가격은 바꾸지 않는다(사용자 결정 2026-11-05).
 *   판매가(price_b2c) 0 이하 → 0(견적문의)
 *   그 외 → min(등급별 단가 effective_unit_price, 판매가)
 *           비회원=판매가, 개인=개인회원가, 기업·직원·관리자=기업회원가 (회원가가 판매가 이상이면 상세 화면처럼 판매가)
 *
 * @param array $p products 행(price_b2c, price_b2b, cost_price, category_id)
 */
function display_unit_price(array $p): int
{
    $list = (int) ($p['price_b2c'] ?? 0);
    if ($list <= 0) {
        return 0;
    }
    return min(effective_unit_price($p), $list);
}

/**
 * 상품 id 목록의 화면 표시 가격 — 단일 쿼리(N+1 방지). 조회 실패(컬럼 미존재 등) 시 빈 배열 → 호출부는 기존 단가 유지.
 *
 * @param  list<int> $productIds
 * @return array<int, array{list:int, price:int}>  [product_id => [판매가, 표시 단가]]
 */
function display_price_map(array $productIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $v): bool => $v > 0)));
    if (!$ids || !db()) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $stmt = db()->prepare("SELECT id, category_id, price_b2c, price_b2b, cost_price FROM products WHERE id IN ({$ph})");
        $stmt->execute($ids);
        $rows = $stmt->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['id']] = ['list' => (int) $row['price_b2c'], 'price' => display_unit_price($row)];
    }
    return $map;
}

/**
 * 품목 배열(cart_fetch_items·quick_items_fetch 결과)의 unit_price·subtotal 을 회원 등급별 단가(display_unit_price)로 바꾼 사본.
 * 비회원=판매가, 개인=개인회원가, 기업=기업회원가. 조회 실패 시 원래 값 유지.
 *
 * @param  list<array{product_id:int,quantity:int,unit_price:int,subtotal:int}> $items
 * @return list<array{product_id:int,quantity:int,unit_price:int,subtotal:int}>
 */
function items_with_display_prices(array $items): array
{
    $map = display_price_map(array_column($items, 'product_id'));
    foreach ($items as $i => $it) {
        $pid = (int) $it['product_id'];
        if (isset($map[$pid])) {
            $items[$i]['unit_price'] = $map[$pid]['price'];
            $items[$i]['subtotal']   = $map[$pid]['price'] * (int) $it['quantity'];
        }
    }
    return $items;
}

/**
 * 회원 화면용 견적 합계 — 견적 품목 수량 × 회원 등급별 단가(display_unit_price, 현재 상품 가격 기준).
 * 회원이 보는 요청된 견적·마이페이지 이전주문·현장별 주문 금액용. 저장 단가(estimate_items.unit_price)는 바꾸지 않고
 * 관리자·직원 화면은 저장값을 그대로 쓴다. 상품이 없어진 품목은 저장 단가로 계산. 조회 실패 시 빈 배열(호출부는 저장 합계 유지).
 *
 * @param  list<int> $estimateIds
 * @return array<int,int> [estimate_id => 합계]
 */
function estimate_display_totals(array $estimateIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $estimateIds), static fn(int $v): bool => $v > 0)));
    if (!$ids || !db()) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = db()->prepare("SELECT estimate_id, product_id, quantity, unit_price FROM estimate_items WHERE estimate_id IN ({$ph})");
        $st->execute($ids);
        $rows = $st->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
    $map    = display_price_map(array_column($rows, 'product_id'));
    $totals = array_fill_keys($ids, 0);
    foreach ($rows as $r) {
        $pid   = (int) $r['product_id'];
        $price = isset($map[$pid]) ? $map[$pid]['price'] : (int) $r['unit_price'];
        $totals[(int) $r['estimate_id']] += $price * (int) $r['quantity'];
    }
    return $totals;
}

/**
 * AI 상담 금액 문구의 이름표 — 비회원 '판매가', 로그인 회원은 ''(이름을 붙이지 않음).
 * 회원 금액은 회원 할인가로 계산하되 화면·AI 답변에 '회원가'라는 말을 쓰지 않는다(사용자 결정 2026-11-05).
 * display_unit_price 와 같은 등급 기준(price_tier).
 */
function price_basis_label(): string
{
    return price_tier() === 'guest' ? '판매가' : '';
}
