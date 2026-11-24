-- ─── 모양이 섞인 분류 나누기(2026-11-23 사용자 결정 A안, S26 '공통 칸 3개 미만이면 분류를 나눈다'): 새 품명 5개 ─────────────
-- 왜: 한 품명 안에 원형·사각 등 모양이 섞여 공통 규격 칸이 3개 미만인 분류를 모양별로 나눈다.
-- 내용(categories 한 표, 가격 칸 쓰지 않음):
--   새 품명 5개(std-023~027) — 마진율 칸을 넣지 않아 0 = 상위 분류 값(개인 20%·기업 10%) 상속.
--   지금 분류(108·111·112·148·158)와 상위(267·270·273)의 마진율이 모두 20%/10% 라 옮겨도 가격 계산 같음(11-23 조회 확인).
--   상품이 없는 동안은 화면에서 숨겨진다(S14) — 활성(1)으로 넣어 상품을 옮기면 바로 보이게.
-- 상품 옮기기(이 파일에서 하지 않음 — category_id 는 가격 계산 입력, P0-4):
--   상용콘크리트 15개 → app/catalog_map.php 매핑(배포 후 관리자가 상콘 상품 하나 저장하면 자동 반영)
--   케이토목 11개(111 사각·수공 6, 112 수도계량기 1, 158 경계석 3, 154 사각 1 은 D7 결정 뒤) → 사용자가 관리자 상품 관리에서
-- 석재볼라드(154)는 확정 결정 D7('볼라드 모양 = 옵션')과 부딪혀 사용자 결정 전까지 넣지 않음.
-- 되돌리기: DELETE FROM categories WHERE slug IN ('std-023','std-024','std-025','std-026','std-027')
--          AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = categories.id);

INSERT INTO categories (parent_id, name, slug, sort_order, is_active)
SELECT x.parent_id, x.name, x.slug, x.sort_order, 1 FROM (
    SELECT 267 AS parent_id, '주철뚜껑(사각)' AS name, 'std-023' AS slug, 2911 AS sort_order
    UNION ALL SELECT 267 AS parent_id, '조화(디자인)맨홀뚜껑(사각·수공)' AS name, 'std-024' AS slug, 2941 AS sort_order
    UNION ALL SELECT 267 AS parent_id, '수도계량기뚜껑(사각·타원)' AS name, 'std-025' AS slug, 2951 AS sort_order
    UNION ALL SELECT 270 AS parent_id, '스텐볼라드(일자형)' AS name, 'std-026' AS slug, 3311 AS sort_order
    UNION ALL SELECT 273 AS parent_id, '현무암경계석' AS name, 'std-027' AS slug, 3411 AS sort_order
) x WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.slug = x.slug);
