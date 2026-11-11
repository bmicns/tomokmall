-- ─── 상용콘크리트 규격 이름표: 부속 치수 칸 이름 추가·이름 하나 변경 (사용자 검토 반영 2026-11-11) ─────────────
-- 선행: database/20261111_syc_category_spec_axes.sql, database/20261111_syc_products_acc_dims.sql(부속 치수 칸 추가)
-- 내용:
--   1) 옵션에 있던 부속 치수(그레이팅·덮개·스틸그레이팅·슬라브·그레이팅뚜껑·받침틀)를 칸으로 옮긴 품목에 이름표 추가 — 상세 화면 전용(in_list=0)
--   2) 파형강관: 옵션 '파형 68x13' → 기타 칸 x1(파형 피치)·x2(파형 깊이), 목록·필터에 보임(in_list=1)
--   3) 건널목수로관: 옵션 '두께 110 이상' 을 뺀 대신 두께 칸 이름을 '최소두께'로
-- 새 표(syc_*)만 바꿈 — 기존 category_spec_axes·가격 칸 변경 없음. 다시 실행해도 중복 없음(이미 있는 이름표는 건너뜀).
-- 행 수: 추가 50, 변경 1
-- 되돌리기: DELETE FROM syc_category_spec_axes WHERE axis_key IN ('a_w','a_l','a_t','a2_w','a2_l','a2_t')
--            OR (axis_key IN ('x1','x2') AND category_id = (SELECT id FROM syc_categories WHERE slug = 'syc-s76'));
--          UPDATE syc_category_spec_axes a JOIN syc_categories c ON c.id = a.category_id SET a.label = '두께' WHERE c.slug = 'syc-w1' AND a.axis_key = 't';

INSERT INTO syc_category_spec_axes (category_id, seq, axis_key, label, symbol, unit, in_list, use_filter)
SELECT c.id, x.seq, x.axis_key, x.label, x.symbol, x.unit, x.in_list, x.use_filter
FROM (
    SELECT 'syc-s1' AS slug, 13 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s1' AS slug, 14 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s1' AS slug, 15 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s2' AS slug, 15 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s2' AS slug, 16 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s2' AS slug, 17 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s3' AS slug, 14 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s3' AS slug, 15 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s3' AS slug, 16 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s4' AS slug, 15 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s4' AS slug, 16 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s4' AS slug, 17 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s5' AS slug, 13 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s5' AS slug, 14 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s5' AS slug, 15 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s6' AS slug, 15 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s6' AS slug, 16 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s6' AS slug, 17 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s7' AS slug, 15 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s7' AS slug, 16 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s7' AS slug, 17 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s24' AS slug, 7 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s24' AS slug, 8 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s24' AS slug, 9 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s9' AS slug, 4 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s9' AS slug, 5 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s9' AS slug, 6 AS seq, 'a_t' AS axis_key, '그레이팅 높이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s25' AS slug, 6 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s25' AS slug, 7 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s25' AS slug, 8 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s15' AS slug, 9 AS seq, 'a_w' AS axis_key, '스틸그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s15' AS slug, 10 AS seq, 'a_t' AS axis_key, '스틸그레이팅 높이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s15' AS slug, 11 AS seq, 'a_l' AS axis_key, '스틸그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s17' AS slug, 10 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s17' AS slug, 11 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s17' AS slug, 12 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s17' AS slug, 13 AS seq, 'a2_w' AS axis_key, '그레이팅2 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s17' AS slug, 14 AS seq, 'a2_l' AS axis_key, '그레이팅2 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s17' AS slug, 15 AS seq, 'a2_t' AS axis_key, '그레이팅2 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s18' AS slug, 5 AS seq, 'a_w' AS axis_key, '덮개 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s18' AS slug, 6 AS seq, 'a_l' AS axis_key, '덮개 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s18' AS slug, 7 AS seq, 'a_t' AS axis_key, '덮개 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s26' AS slug, 5 AS seq, 'a_w' AS axis_key, '슬라브 지름' AS label, 'Ø' AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s47' AS slug, 4 AS seq, 'a_w' AS axis_key, '그레이팅뚜껑 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s47' AS slug, 5 AS seq, 'a_l' AS axis_key, '그레이팅뚜껑 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s47' AS slug, 6 AS seq, 'a_t' AS axis_key, '그레이팅뚜껑 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s75' AS slug, 5 AS seq, 'a_w' AS axis_key, '받침틀 가로' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s75' AS slug, 6 AS seq, 'a_l' AS axis_key, '받침틀 세로' AS label, NULL AS symbol, 'mm' AS unit, 0 AS in_list, 0 AS use_filter
    UNION ALL SELECT 'syc-s76' AS slug, 4 AS seq, 'x1' AS axis_key, '파형 피치' AS label, 'p' AS symbol, 'mm' AS unit, 1 AS in_list, 1 AS use_filter
    UNION ALL SELECT 'syc-s76' AS slug, 5 AS seq, 'x2' AS axis_key, '파형 깊이' AS label, 'd' AS symbol, 'mm' AS unit, 1 AS in_list, 1 AS use_filter
) x
JOIN syc_categories c ON c.slug = x.slug
WHERE NOT EXISTS (SELECT 1 FROM syc_category_spec_axes a WHERE a.category_id = c.id AND a.axis_key = x.axis_key);

UPDATE syc_category_spec_axes a JOIN syc_categories c ON c.id = a.category_id
SET a.label = '최소두께'
WHERE c.slug = 'syc-w1' AND a.axis_key = 't' AND a.label = '두께';

-- 확인(읽기 전용)
-- SELECT c.slug, a.seq, a.axis_key, a.label, a.in_list FROM syc_category_spec_axes a JOIN syc_categories c ON c.id = a.category_id
--  WHERE a.axis_key LIKE 'a%\_%' OR c.slug IN ('syc-s76', 'syc-w1') ORDER BY c.id, a.seq;
