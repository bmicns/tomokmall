-- ─── 보도블록 품목 두께 필터 설정 맞추기 (사용자 결정 2026-11-12) ─────────────────────────────────────────────
-- 왜: 보도블록 중분류 10개 품목의 규격 이름표(가로 W·세로 V·두께 T)가 같은데 '두께 T' 필터 사용 여부만 4개 켬·6개 끔이라
--     목록이 '이름표가 다르다'고 보고 규격을 칸으로 나누지 않았다. 6개를 켬으로 맞춘다(목록 표·필터에 두께 T).
-- 대상: 원본(syc_category_spec_axes)과 사이트 반영본(category_spec_axes) 같은 6개 품목의 axis_key 't' 1행씩 — 가격 무관.
-- 되돌리기: 두 UPDATE 의 use_filter = 1 → 0 (같은 조건)
UPDATE syc_category_spec_axes SET use_filter = 1
WHERE axis_key = 't' AND use_filter = 0
  AND category_id IN (SELECT id FROM syc_categories WHERE slug IN ('syc-s58', 'syc-s59', 'syc-s60', 'syc-s61', 'syc-s62', 'syc-s66'));
UPDATE category_spec_axes SET use_filter = 1
WHERE axis_key = 't' AND use_filter = 0
  AND category_id IN (SELECT id FROM categories WHERE slug IN ('syc-s58', 'syc-s59', 'syc-s60', 'syc-s61', 'syc-s62', 'syc-s66'));
