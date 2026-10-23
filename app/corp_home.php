<?php
declare(strict_types=1);
/**
 * 기업회원 메인화면 데이터 (app/corp_home.php)
 *
 * 책임: 기업회원 메인(빠른 견적하기)에 필요한 읽기 전용 데이터 조회·가공
 * 호출자: public/index.php (기업회원 홈) → app/corp_home_renderer.php 로 전달
 * 금지: UI 렌더링, 데이터 변경(INSERT/UPDATE/DELETE), 세션 쓰기
 *
 * 제공 함수:
 *  corp_home_category_tree()  — 대분류(GNB 5개)·하위 카테고리 트리 (estimate-quick.php 와 같은 규칙)
 *  corp_home_regulars()       — 단골품목(favorite_products) + 매입횟수·최근 매입일 (본인 데이터만)
 *  corp_home_history()        — 이전 견적서 목록 (본인 데이터만)
 *  corp_home_unit_price()     — 기업회원 견적 단가 (quick-items.php 와 같은 규칙)
 *  corp_home_items_summary()  — '첫 품목명 외 N개 품목' 요약 문구
 */

/** GNB 대분류 id (layout.php GNB·estimate-quick.php 와 동일) */
const CORP_HOME_ROOT_IDS = [11, 12, 13, 14, 15];

/** 매입으로 보는 견적 상태: 완료(APPROVED)·납품완료(DELIVERED) */
const CORP_HOME_PURCHASED_STATUSES = ['APPROVED', 'DELIVERED'];

/**
 * 대분류 + 하위 카테고리 트리. 대분류 이름의 '제품' 접미사는 떼고 GNB 순서로 정렬.
 *
 * @param list<array{id:int|string,parent_id:int|string|null,name:string}> $categories fetch_categories() 결과
 * @return array{lv1: list<array{id:int,name:string}>, byParent: array<int, list<array{id:int,name:string}>>}
 */
function corp_home_category_tree(array $categories): array
{
    $lv1 = [];
    $byParent = [];
    foreach ($categories as $c) {
        $id  = (int) $c['id'];
        $pid = $c['parent_id'] === null ? null : (int) $c['parent_id'];
        if ($pid === null && in_array($id, CORP_HOME_ROOT_IDS, true)) {
            $lv1[] = ['id' => $id, 'name' => (string) preg_replace('/제품$/u', '', (string) $c['name'])];
        } elseif ($pid !== null) {
            $byParent[$pid][] = ['id' => $id, 'name' => (string) $c['name']];
        }
    }
    usort($lv1, fn($a, $b) => array_search($a['id'], CORP_HOME_ROOT_IDS, true) <=> array_search($b['id'], CORP_HOME_ROOT_IDS, true));
    return ['lv1' => $lv1, 'byParent' => $byParent];
}

/**
 * 기업회원 견적 단가: price_b2b > 0 이면 price_b2b, 아니면 price_b2c.
 * ⚠️ 담은 품목 금액은 api/estimate/quick-items.php 가 같은 규칙으로 계산한다. 변경 시 함께 수정.
 */
function corp_home_unit_price(int $priceB2b, int $priceB2c): int
{
    return $priceB2b > 0 ? $priceB2b : $priceB2c;
}

/**
 * '첫 품목명 외 N개 품목' 요약. 품목이 1개면 품목명만, 없으면 '품목 없음'.
 */
function corp_home_items_summary(?string $firstName, int $count): string
{
    $firstName = trim((string) $firstName);
    if ($count <= 0 || $firstName === '') {
        return '품목 없음';
    }
    return $count > 1 ? $firstName . ' 외 ' . ($count - 1) . '개 품목' : $firstName;
}

/**
 * 단골품목(관심상품) + 매입 통계. 매입 = 본인 견적 중 상태가 완료·납품완료인 것.
 * 정렬: 매입횟수 많은 순 → 관심상품 등록 순서.
 *
 * @return list<array{product_id:int,name:string,size:string,unit:string,price:int,quantity:int,
 *                    image_url:string,buy_count:int,last_buy:string}>
 */
function corp_home_regulars(PDO $pdo, int $userId): array
{
    $ph = implode(',', array_fill(0, count(CORP_HOME_PURCHASED_STATUSES), '?'));
    try {
        $st = $pdo->prepare(
            "SELECT f.product_id, f.quantity, p.name, p.spec, p.price_b2c, p.price_b2b, p.image_url,
                    COALESCE(b.buy_count, 0) AS buy_count, b.last_buy
             FROM favorite_products f
             JOIN products p ON p.id = f.product_id AND p.is_active = 1
             LEFT JOIN (
                 SELECT ei.product_id, COUNT(DISTINCT e.id) AS buy_count, MAX(e.created_at) AS last_buy
                 FROM estimates e
                 JOIN estimate_items ei ON ei.estimate_id = e.id
                 WHERE e.user_id = ? AND e.status IN ($ph)
                 GROUP BY ei.product_id
             ) b ON b.product_id = f.product_id
             WHERE f.user_id = ?
             ORDER BY buy_count DESC, f.sort_order, f.created_at, f.id"
        );
        $st->execute(array_merge([$userId], CORP_HOME_PURCHASED_STATUSES, [$userId]));
        $rows = $st->fetchAll();
    } catch (\Throwable $e) {
        // 테이블 미존재 등 → 빈 목록(화면은 '등록된 단골품목 없음' 안내)
        return [];
    }

    return array_map(static fn(array $r): array => [
        'product_id' => (int) $r['product_id'],
        'name'       => (string) $r['name'],
        'size'       => spec_size_label((string) $r['spec']),
        'unit'       => parse_spec_field((string) $r['spec'])['unit'],
        'price'      => corp_home_unit_price((int) $r['price_b2b'], (int) $r['price_b2c']),
        'quantity'   => max(1, (int) $r['quantity']),
        'image_url'  => (string) ($r['image_url'] ?? ''),
        'buy_count'  => (int) $r['buy_count'],
        'last_buy'   => $r['last_buy'] ? date('Y.m.d', strtotime((string) $r['last_buy'])) : '',
    ], $rows);
}

/**
 * 이전 견적서 목록(최신순). 현장(sites)에 연결된 견적은 현장명을 제목으로 쓴다.
 * $limit 보다 1건 더 조회해 has_more 를 판단한다.
 *
 * @return array{items: list<array{estimate_id:int,date:string,title:string,summary:string,item_count:int}>, has_more: bool}
 */
function corp_home_history(PDO $pdo, int $userId, int $limit = 20): array
{
    $limit = max(1, min(50, $limit));
    try {
        $st = $pdo->prepare(
            'SELECT e.id, e.created_at,
                    (SELECT COUNT(*) FROM estimate_items ei WHERE ei.estimate_id = e.id) AS item_count,
                    (SELECT p.name FROM estimate_items ei2 JOIN products p ON p.id = ei2.product_id
                     WHERE ei2.estimate_id = e.id ORDER BY ei2.id LIMIT 1) AS first_name
             FROM estimates e
             WHERE e.user_id = :uid
             ORDER BY e.id DESC
             LIMIT :lim'
        );
        $st->bindValue(':uid', $userId, PDO::PARAM_INT);
        $st->bindValue(':lim', $limit + 1, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll();
    } catch (\Throwable $e) {
        return ['items' => [], 'has_more' => false];
    }

    $hasMore = count($rows) > $limit;
    $rows    = array_slice($rows, 0, $limit);

    // 현장명(선택 기능 — sites 테이블이 없는 DB에서도 동작하도록 실패 격리)
    $siteNames = [];
    if ($rows) {
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $ss = $pdo->prepare(
                "SELECT so.estimate_id, MIN(s.name) AS site_name
                 FROM site_orders so JOIN sites s ON s.id = so.site_id AND s.user_id = ?
                 WHERE so.estimate_id IN ($ph)
                 GROUP BY so.estimate_id"
            );
            $ss->execute(array_merge([$userId], $ids));
            foreach ($ss->fetchAll() as $s) {
                $siteNames[(int) $s['estimate_id']] = (string) $s['site_name'];
            }
        } catch (\Throwable $e) {
            $siteNames = [];
        }
    }

    $items = array_map(static function (array $r) use ($siteNames): array {
        $id = (int) $r['id'];
        return [
            'estimate_id' => $id,
            'date'        => date('Y.m.d', strtotime((string) $r['created_at'])),
            'title'       => $siteNames[$id] ?? ('견적 #' . $id),
            'summary'     => corp_home_items_summary($r['first_name'] ?? null, (int) $r['item_count']),
            'item_count'  => (int) $r['item_count'],
        ];
    }, $rows);

    return ['items' => $items, 'has_more' => $hasMore];
}
