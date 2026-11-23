-- ─── 동아산업 연결 안 된 분류: 공통 분류(categories) 새 품명 3개 (2026-11-23 사용자 결정 '추천안대로', S29 보충) ─────────────
-- 왜: 동아산업 분류 중 같은 번호의 케이토목 분류가 없는 맨홀분기구·휀스기초·야자매트는 비슷한 기존 품명이 없어 새 품명을 만든다.
--     (라인잔디블록 → 조경잔디블록 99 는 코드만, 아스콘·레미콘은 '시공 포함' 이라 고객 화면에 올리지 않음 — app/catalog_map.php)
-- 내용: categories 에 품명 3행. 마진율 칸(discount_customer·discount_corporate)은 넣지 않는다 → 0 = 상위 분류 값 상속(가격 계산 같음).
--       맨홀분기구 → 철근콘크리트제품 › 기타(260, 흄관분기구 옆) / 휀스기초 → 콘크리트제품 › 기타(265, 가로등기초 옆) / 야자매트 → 기타(16) 바로 아래
-- ⚠ 가격 보호(P0-4): 마진율 칸이 있는 표라 원격 관리 API 는 막는다 → 사용자가 phpMyAdmin 에서 실행. 기존 행·가격은 바뀌지 않는다(새 행만).
-- 순서: 언제 실행해도 됨. 실행 뒤 동아산업 상품의 활성/비활성을 한 번 바꾸거나 엑셀을 다시 올리면 반영(그 전엔 이 3개 분류 상품은 고객 화면 반영 안 됨).
-- 다시 실행해도 중복 없음(slug 기준).
-- 되돌리기: DELETE FROM categories WHERE slug IN ('std-020','std-021','std-022') AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = categories.id);

-- [1] 사전 확인(읽기 전용): 부모 3행(260·265·16)이 나오고, std-020~022 는 0
SELECT id, name, slug FROM categories WHERE id IN (260, 265, 16) ORDER BY id;
SELECT COUNT(*) AS std_020_022_before FROM categories WHERE slug IN ('std-020', 'std-021', 'std-022');

-- [2] 새 품명 3개
INSERT INTO categories (parent_id, name, slug, sort_order, is_active)
SELECT x.parent_id, x.name, x.slug, x.sort_order, 1 FROM (
              SELECT 260 AS parent_id, '맨홀분기구' AS name, 'std-020' AS slug, 3825 AS sort_order
    UNION ALL SELECT 265, '휀스기초', 'std-021', 2865
    UNION ALL SELECT 16, '야자매트', 'std-022', 3895
) x
WHERE EXISTS (SELECT 1 FROM categories p WHERE p.id = x.parent_id)
  AND NOT EXISTS (SELECT 1 FROM categories c WHERE c.slug = x.slug);

-- [3] 확인(읽기 전용): 3행, 마진율 0(상속)
SELECT id, parent_id, name, slug, is_active, discount_customer, discount_corporate FROM categories WHERE slug IN ('std-020', 'std-021', 'std-022');
