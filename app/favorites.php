<?php
declare(strict_types=1);
/**
 * 관심상품(자주찾는 품목) 공통 규칙 (app/favorites.php)
 *
 * 책임: 관심상품 저장 한도·다중 추가 입력 검증·추가 결과 분류(순수 함수)
 * 금지: DB 쿼리, 세션 접근, 출력 — DB 처리는 public/api/estimate/favorites.php
 *
 * 저장 테이블: favorite_products (database/add_reorder.sql, UNIQUE user_id+product_id)
 */

/** 사용자 1명당 관심상품 최대 개수 */
const FAVORITES_MAX = 50;

/** 다중 추가 1회 요청당 최대 상품 수(상품 목록 1페이지 표시 수와 동일) */
const FAVORITES_BULK_MAX = 60;

/**
 * 다중 추가 요청의 product_ids 검증·정규화.
 * - 배열이어야 하고, 각 값은 양의 정수(정수형 또는 숫자 문자열)
 * - 중복 제거(처음 등장 순서 유지), 1 ~ $max 개
 *
 * @param mixed $raw
 * @return int[]|null  유효하지 않으면 null
 */
function favorite_parse_ids($raw, int $max = FAVORITES_BULK_MAX): ?array
{
    if (!is_array($raw) || $raw === []) {
        return null;
    }
    $ids = [];
    foreach ($raw as $v) {
        if (is_int($v)) {
            $id = $v;
        } elseif (is_string($v) && preg_match('/^[1-9][0-9]{0,9}$/', $v)) {
            $id = (int) $v;
        } else {
            return null;
        }
        if ($id <= 0) {
            return null;
        }
        $ids[$id] = true;
    }
    $ids = array_keys($ids);
    return count($ids) <= $max ? $ids : null;
}

/**
 * 다중 추가 결과 분류(저장 전 계획).
 * - 판매 중인 상품이 아니면 failed(invalid)
 * - 이미 등록된 상품은 already(기존 저장 내역·수량 유지, 새로 저장하지 않음)
 * - 나머지는 한도 안에서 요청 순서대로 add, 한도를 넘으면 failed(limit)
 *
 * @param int[] $ids          요청 상품 id(정규화됨)
 * @param int[] $validIds     판매 중(is_active=1)으로 확인된 id
 * @param int[] $existingIds  사용자가 이미 등록한 id
 * @param int   $currentCount 사용자의 현재 관심상품 수
 * @return array{add:int[], already:int[], failed:array<int,array{product_id:int,reason:string}>}
 */
function favorite_bulk_plan(array $ids, array $validIds, array $existingIds, int $currentCount, int $max = FAVORITES_MAX): array
{
    $valid    = array_fill_keys($validIds, true);
    $existing = array_fill_keys($existingIds, true);
    $plan  = ['add' => [], 'already' => [], 'failed' => []];
    $count = $currentCount;
    foreach ($ids as $id) {
        if (isset($existing[$id])) {
            $plan['already'][] = $id;
        } elseif (!isset($valid[$id])) {
            $plan['failed'][] = ['product_id' => $id, 'reason' => 'invalid'];
        } elseif ($count >= $max) {
            $plan['failed'][] = ['product_id' => $id, 'reason' => 'limit'];
        } else {
            $plan['add'][] = $id;
            $count++;
        }
    }
    return $plan;
}
