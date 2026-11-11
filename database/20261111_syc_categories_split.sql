-- ─── 상용콘크리트 카테고리 나누기: 한 품목 안에 치수 칸 구성이 다른 상품 → 별도 카테고리 (사용자 결정 2026-11-11 '치수 칸 구성별') ───
-- 예: 'PE 부속품'(PE 침사조 W·H·V / PE 인상링 W·V) → 'PE 침사조' + 'PE 인상링'. 10개 품목 → 기존 10개(이름만 맞춤) + 새 13개.
-- 첫 묶음은 기존 카테고리를 그대로 쓰고(상품 이동 없음), 나머지 묶음은 같은 대분류 아래 새 카테고리(바로 뒤 순서).
-- 마진율 칸(discount_*)은 넣지 않음 → 0 = 대분류 값(20/10)을 물려받음(app/syc_catalog.php syc_discount_map, 가격 계산식 그대로).
-- 상품은 일괄 등록 엑셀(분류코드 = 새 slug)로 옮김. 이름표는 database/20261111_syc_axes_split.sql(이 파일 뒤에 실행).
-- 새 표(syc_*)만 바꿈 — 기존 categories·products·가격 칸 변경 없음. 다시 실행해도 중복 없음.
-- 되돌리기: DELETE FROM syc_categories WHERE slug IN ('syc-s78', 'syc-s79', 'syc-s80', 'syc-s81', 'syc-s82', 'syc-s83', 'syc-s84', 'syc-s85', 'syc-s86', 'syc-s87', 'syc-s88', 'syc-s89', 'syc-w5');  (이름표 먼저 지우고, 상품이 옮겨졌으면 엑셀로 되돌린 뒤)
--          이름 되돌리기: syc-s8 → 유공 침투측구수로관 및 집수정; syc-s17 → 원심력수로관 (W2 / S2 / 무개수로관 / 유개수로관); syc-s48 → PE 부속품; syc-s52 → 기타 주철 COVER; syc-s53 → 주철 COVER; syc-s74 → 화강 경계석; syc-s75 → 가로수보호틀

UPDATE syc_categories SET name = CASE slug
    WHEN 'syc-s8' THEN '유공 침투측구수로관'
    WHEN 'syc-s17' THEN '원심력수로관 (W2 / S2)'
    WHEN 'syc-s48' THEN 'PE 침사조'
    WHEN 'syc-s52' THEN '전기·통신용 수공 사각맨홀 뚜껑'
    WHEN 'syc-s53' THEN 'BS수공 전기통신 맨홀 뚜껑'
    WHEN 'syc-s74' THEN '화강 경계석 (도로·보차도·낮춤)'
    WHEN 'syc-s75' THEN '가로수보호틀 (말굽형·정사각형, 높이 있음)'
    ELSE name END
WHERE slug IN ('syc-s8', 'syc-s17', 'syc-s48', 'syc-s52', 'syc-s53', 'syc-s74', 'syc-s75');

INSERT INTO syc_categories (parent_id, name, description, slug, sort_order, is_active)
SELECT g.id, x.name, NULL, x.slug, x.sort_order, 1
FROM (
    SELECT 'syc-g1' AS parent_slug, '유공 침투집수정' AS name, 'syc-s78' AS slug, 81 AS sort_order
    UNION ALL SELECT 'syc-g2' AS parent_slug, '원심력수로관 (무개수로관 / 유개수로관)' AS name, 'syc-s79' AS slug, 81 AS sort_order
    UNION ALL SELECT 'syc-g2' AS parent_slug, '알루미늄트렌치 소켓 (엔젤·엔드·코너·연결)' AS name, 'syc-s80' AS slug, 91 AS sort_order
    UNION ALL SELECT 'syc-g2' AS parent_slug, '알루미늄트렌치 단차소켓' AS name, 'syc-s81' AS slug, 92 AS sort_order
    UNION ALL SELECT 'syc-g2' AS parent_slug, '알루미늄트렌치 이형소켓' AS name, 'syc-s82' AS slug, 93 AS sort_order
    UNION ALL SELECT 'syc-g5' AS parent_slug, '조립식 맨홀 이송관 연결구' AS name, 'syc-s83' AS slug, 51 AS sort_order
    UNION ALL SELECT 'syc-g6' AS parent_slug, 'PE 인상링' AS name, 'syc-s84' AS slug, 171 AS sort_order
    UNION ALL SELECT 'syc-g7' AS parent_slug, '원형 맨홀 뚜껑 (BS 규격·전기통신)' AS name, 'syc-s85' AS slug, 41 AS sort_order
    UNION ALL SELECT 'syc-g7' AS parent_slug, '높이 조절링' AS name, 'syc-s86' AS slug, 51 AS sort_order
    UNION ALL SELECT 'syc-g8' AS parent_slug, '화강 경사 경계석' AS name, 'syc-s87' AS slug, 211 AS sort_order
    UNION ALL SELECT 'syc-g9' AS parent_slug, '가로수보호틀 원형' AS name, 'syc-s88' AS slug, 11 AS sort_order
    UNION ALL SELECT 'syc-g9' AS parent_slug, '가로수보호틀 (정사각형·직사각형, 높이 없음)' AS name, 'syc-s89' AS slug, 12 AS sort_order
    UNION ALL SELECT 'syc-g10' AS parent_slug, '스텐볼라드 U형 - HK' AS name, 'syc-w5' AS slug, 11 AS sort_order
) x
JOIN syc_categories g ON g.slug = x.parent_slug
WHERE NOT EXISTS (SELECT 1 FROM syc_categories c WHERE c.slug = x.slug);

-- 확인(읽기 전용): SELECT slug, name, sort_order FROM syc_categories WHERE slug IN ('syc-s78', 'syc-s79', 'syc-s80', 'syc-s81', 'syc-s82', 'syc-s83', 'syc-s84', 'syc-s85', 'syc-s86', 'syc-s87', 'syc-s88', 'syc-s89', 'syc-w5') ORDER BY parent_id, sort_order;
