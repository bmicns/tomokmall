<?php
declare(strict_types=1);
/**
 * 장바구니 유틸 (app/cart.php)
 *
 * 책임: 세션 기반 장바구니 읽기/쓰기 + 품목 조회
 * 금지: UI 렌더링, AI Tool 정의
 *
 * 제공 함수:
 *  cart_session_read()          — 세션 장바구니 읽기
 *  cart_session_write()         — 세션 장바구니 저장 + 세션 잠금 해제
 *  cart_fetch_items()           — product_id IN(...) 단일 쿼리 (B2C 단가)
 *  cart_summary_from_items()    — 조회된 items 기준 count/total 계산
 *  cart_summary()               — cart 배열로 count/total 계산
 *  quick_items_fetch()          — quick_items 세션 품목 조회 (B2B 단가 우선)
 */

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
    return $_SESSION['cart'] ?? [];
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
    $_SESSION['cart'] = $cart;
    session_write_close();
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
 * 화면 표시용 회원 단가 — 상품 상세(public/product.php)·목록과 같은 규칙. app/pricing.php 의 계산을 그대로 쓴다.
 * 표시 전용: 견적 접수 시 저장하는 단가(cart_fetch_items·quick_items_fetch → estimate_create)는 바꾸지 않는다.
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
