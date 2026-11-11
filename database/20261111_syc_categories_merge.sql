-- ─── 상용콘크리트 측구수로관 카테고리 합치기 (사용자 결정 2026-11-11) ─────────────────────────────────────
-- 왜: '측구수로관 앵글미부착 A형 (200A,250A)'·'… AN형'·'… B형'·'… (Z)-주문품' 처럼 형식별로 7개 메뉴가 있었다.
--     형식(A형·AN형·B형·Z형)은 모델 코드로, 앵글미부착·앵글부착·주문품은 옵션으로 빼고 품명은 '측구수로관' 하나로.
-- 내용:
--   1) syc-s1 이름을 '측구수로관'으로(이 카테고리에 모두 모음)
--   2) syc-s1 에 없던 세부 칸 이름표 4개(h4·t4·l1·l2 — 다른 형식에만 있던 칸) 추가, 두께 T 를 필터에도(형식마다 다름)
--   3) syc-s2 ~ syc-s7 숨김(is_active = 0 — 지우지 않음. 상품은 일괄 등록 엑셀의 분류코드 syc-s1 로 옮김)
-- 새 표(syc_*)만 바꿈 — 기존 categories·products·가격 칸 변경 없음. 다시 실행해도 같은 결과.
-- 되돌리기: UPDATE syc_categories SET is_active = 1 WHERE slug IN ('syc-s2','syc-s3','syc-s4','syc-s5','syc-s6','syc-s7');
--          UPDATE syc_categories SET name = '측구수로관 앵글미부착 A형 (200A,250A)' WHERE slug = 'syc-s1';
--          DELETE FROM syc_category_spec_axes WHERE category_id IN (SELECT id FROM syc_categories WHERE slug = 'syc-s1') AND axis_key IN ('h4','t4','l1','l2');
--          UPDATE syc_category_spec_axes SET use_filter = 0 WHERE axis_key = 't' AND category_id IN (SELECT id FROM syc_categories WHERE slug = 'syc-s1');

UPDATE syc_categories SET name = CASE slug
    WHEN 'syc-s1' THEN '측구수로관'
    ELSE name END
WHERE slug IN ('syc-s1');

INSERT INTO syc_category_spec_axes (category_id, seq, axis_key, label, symbol, unit, in_list, use_filter)
SELECT c.id, x.seq, x.axis_key, x.label, x.symbol, x.unit, x.in_list, x.use_filter
FROM (
    SELECT 'syc-s1' AS slug, 16 AS seq, 'h4' AS axis_key, '세부' AS label, 'h4' AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s1' AS slug, 17 AS seq, 't4' AS axis_key, '세부' AS label, 't4' AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s1' AS slug, 18 AS seq, 'l1' AS axis_key, '세부' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s1' AS slug, 19 AS seq, 'l2' AS axis_key, '세부' AS label, 'l2' AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
) x
JOIN syc_categories c ON c.slug = x.slug
WHERE NOT EXISTS (SELECT 1 FROM syc_category_spec_axes a WHERE a.category_id = c.id AND a.axis_key = x.axis_key);

UPDATE syc_category_spec_axes SET use_filter = 1
WHERE axis_key = 't' AND use_filter = 0 AND category_id IN (SELECT id FROM syc_categories WHERE slug = 'syc-s1');

UPDATE syc_categories SET is_active = 0
WHERE slug IN ('syc-s2', 'syc-s3', 'syc-s4', 'syc-s5', 'syc-s6', 'syc-s7') AND is_active = 1;

-- 확인(읽기 전용)
-- SELECT slug, name, is_active FROM syc_categories WHERE slug IN ('syc-s1','syc-s2','syc-s3','syc-s4','syc-s5','syc-s6','syc-s7');
-- SELECT seq, axis_key, label, in_list, use_filter FROM syc_category_spec_axes WHERE category_id = (SELECT id FROM syc_categories WHERE slug = 'syc-s1') ORDER BY seq;
