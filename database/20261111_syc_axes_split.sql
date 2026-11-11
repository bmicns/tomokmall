-- ─── 상용콘크리트 카테고리 나누기 ② 새 카테고리 13개에 규격 이름표 복사 (사용자 결정 2026-11-11) ───────────
-- 선행: database/20261111_syc_categories_split.sql (새 카테고리), database/20261111_syc_axes_acc_labels.sql
-- 나눠지기 전 카테고리의 이름표를 그대로 복사(값이 없는 칸은 화면에 안 보임). 새 표만, 가격 무관. 다시 실행해도 중복 없음.
-- 되돌리기: DELETE FROM syc_category_spec_axes WHERE category_id IN (SELECT id FROM syc_categories WHERE slug IN ('syc-s78', 'syc-s79', 'syc-s80', 'syc-s81', 'syc-s82', 'syc-s83', 'syc-s84', 'syc-s85', 'syc-s86', 'syc-s87', 'syc-s88', 'syc-s89', 'syc-w5'));

INSERT INTO syc_category_spec_axes (category_id, seq, axis_key, label, symbol, unit, in_list, use_filter, is_active)
SELECT n.id, a.seq, a.axis_key, a.label, a.symbol, a.unit, a.in_list, a.use_filter, a.is_active
FROM (
    SELECT 'syc-s78' AS new_slug, 'syc-s8' AS old_slug
    UNION ALL SELECT 'syc-s79' AS new_slug, 'syc-s17' AS old_slug
    UNION ALL SELECT 'syc-s80' AS new_slug, 'syc-s18' AS old_slug
    UNION ALL SELECT 'syc-s81' AS new_slug, 'syc-s18' AS old_slug
    UNION ALL SELECT 'syc-s82' AS new_slug, 'syc-s18' AS old_slug
    UNION ALL SELECT 'syc-s83' AS new_slug, 'syc-s28' AS old_slug
    UNION ALL SELECT 'syc-s84' AS new_slug, 'syc-s48' AS old_slug
    UNION ALL SELECT 'syc-s85' AS new_slug, 'syc-s52' AS old_slug
    UNION ALL SELECT 'syc-s86' AS new_slug, 'syc-s53' AS old_slug
    UNION ALL SELECT 'syc-s87' AS new_slug, 'syc-s74' AS old_slug
    UNION ALL SELECT 'syc-s88' AS new_slug, 'syc-s75' AS old_slug
    UNION ALL SELECT 'syc-s89' AS new_slug, 'syc-s75' AS old_slug
    UNION ALL SELECT 'syc-w5' AS new_slug, 'syc-w2' AS old_slug
) m
JOIN syc_categories o ON o.slug = m.old_slug
JOIN syc_categories n ON n.slug = m.new_slug
JOIN syc_category_spec_axes a ON a.category_id = o.id
WHERE NOT EXISTS (SELECT 1 FROM syc_category_spec_axes x WHERE x.category_id = n.id AND x.axis_key = a.axis_key);
