-- ─── 상용콘크리트 중분류 나누기: 콘크리트 3개·PE/PVC 2개 (사용자 결정 2026-11-12) ─────────────────────────────
-- 왜: 고객 화면 메뉴에서 '보도블록·옹벽·경계석'(22개)·'PE·PVC관'(18개)이 한 묶음이라 너무 길고 섞여 보임.
-- 내용(상용콘크리트 원본 syc_categories 만 — 사이트에는 관리자 '사이트에 반영' 때 적용):
--   1) 이름: syc-g8 '보도블록·옹벽·경계석' → '보도블록', syc-g6 'PE·PVC관' → '배수관(PE·PVC관)'
--   2) 새 중분류: syc-g11 '옹벽·호안블록'·syc-g12 '경계석'(콘크리트), syc-g13 '받이·집수정'(PE/PVC) — 원래 중분류 바로 뒤 순서
--   3) 품목 상위 바꾸기: 옹벽·호안 7개 → g11, 경계석 5개 → g12, 받이·집수정 10개 → g13
-- 가격 영향 없음: 품목마다 마진율(20/10)이 직접 들어 있어 상위가 바뀌어도 계산 그대로. 새 중분류의 마진율 칸은 넣지 않음(0, 품목 값 사용).
-- 상품 분류코드(품목 slug)는 그대로 → 일괄 등록·자동 정리 영향 없음. 재질 대분류 연결: app/syc_publish.php SYC_PUBLISH_GROUP_ROOT.
-- 다시 실행해도 같은 결과.
-- 되돌리기: UPDATE syc_categories s JOIN syc_categories g ON g.slug = 'syc-g8' SET s.parent_id = g.id WHERE s.slug IN (<옹벽·경계석 12개>);
--          UPDATE syc_categories s JOIN syc_categories g ON g.slug = 'syc-g6' SET s.parent_id = g.id WHERE s.slug IN (<받이·집수정 10개>);
--          이름 원래대로, DELETE FROM syc_categories WHERE slug IN ('syc-g11','syc-g12','syc-g13');  (그 뒤 '사이트에 반영')

UPDATE syc_categories SET name = CASE slug
    WHEN 'syc-g8' THEN '보도블록'
    WHEN 'syc-g6' THEN '배수관(PE·PVC관)'
    ELSE name END
WHERE slug IN ('syc-g8', 'syc-g6');

INSERT INTO syc_categories (parent_id, name, description, slug, sort_order, is_active)
SELECT NULL AS parent_id, x.name, NULL AS description, x.slug, x.sort_order, 1 AS is_active
FROM (
    SELECT '옹벽·호안블록' AS name, 'syc-g11' AS slug, 81 AS sort_order
    UNION ALL SELECT '경계석' AS name, 'syc-g12' AS slug, 82 AS sort_order
    UNION ALL SELECT '받이·집수정' AS name, 'syc-g13' AS slug, 61 AS sort_order
) x
WHERE NOT EXISTS (SELECT 1 FROM syc_categories c WHERE c.slug = x.slug);

UPDATE syc_categories s JOIN syc_categories g ON g.slug = 'syc-g11' SET s.parent_id = g.id
WHERE s.slug IN ('syc-s63', 'syc-s64', 'syc-s65', 'syc-s67', 'syc-s68', 'syc-s69', 'syc-s70');
UPDATE syc_categories s JOIN syc_categories g ON g.slug = 'syc-g12' SET s.parent_id = g.id
WHERE s.slug IN ('syc-s71', 'syc-s72', 'syc-s73', 'syc-s74', 'syc-s87');
UPDATE syc_categories s JOIN syc_categories g ON g.slug = 'syc-g13' SET s.parent_id = g.id
WHERE s.slug IN ('syc-s36', 'syc-s41', 'syc-s42', 'syc-s43', 'syc-s44', 'syc-s45', 'syc-s46', 'syc-s47', 'syc-s48', 'syc-s84');

-- 확인(읽기 전용)
-- SELECT g.slug, g.name, COUNT(c.id) FROM syc_categories g LEFT JOIN syc_categories c ON c.parent_id = g.id WHERE g.parent_id IS NULL GROUP BY g.id ORDER BY g.sort_order;
