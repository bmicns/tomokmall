-- ─── 화산석 원형 디딤석 새 분류(2026-11-24 사용자 결정 '원형만 따로 분류해주세요', S26 '모양이 섞인 분류는 나눈다') ─────────────
-- 왜: 화산석(158) 이름표는 가로·세로·두께라 원형 디딤석(D500·D400)의 지름을 넣을 칸이 없다.
-- 내용: categories 에 새 품명 1개(std-028, 화강석 273 아래 — 화산석 바로 뒤) + 이름표 2칸(직경 D·두께 T).
--   마진율 칸을 넣지 않아 0 = 상위 화강석(273) 값(개인 20%·기업 10%) 상속. 화산석(158)도 20%/10% 라 옮겨도 가격 계산 같음(11-24 조회 확인).
--   가격 칸·기존 상품의 category_id 는 이 파일에서 바꾸지 않음. 동아산업 상품 3개는 app/catalog_map.php 매핑(dac-4260 옵션 '원형' → std-028)으로
--   배포 후 업체 상품 하나를 저장하면 자동으로 옮겨진다.
-- 실행: phpMyAdmin 에서 사용자가 직접(위에서부터 한 번). 다시 실행해도 같은 slug·같은 칸이 있으면 추가하지 않음.
-- 되돌리기: DELETE FROM category_spec_axes WHERE category_id = (SELECT id FROM categories WHERE slug = 'std-028');
--          DELETE FROM categories WHERE slug = 'std-028' AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = categories.id);

-- ① 확인(0 이어야 함)
SELECT COUNT(*) FROM categories WHERE slug = 'std-028';

-- ② 새 품명
INSERT INTO categories (parent_id, name, slug, sort_order, is_active)
SELECT 273, '현무암디딤석(원형)', 'std-028', 3412, 1 FROM DUAL
WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.slug = 'std-028');

-- ③ 이름표(직경·두께)
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt)
SELECT c.id, 1, 'd', '직경', 'D', 1, 'mm', 1, 'default' FROM categories c
WHERE c.slug = 'std-028' AND NOT EXISTS (SELECT 1 FROM category_spec_axes a WHERE a.category_id = c.id AND a.axis_key = 'd');
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt)
SELECT c.id, 2, 't', '두께', 'T', 1, 'mm', 1, 'default' FROM categories c
WHERE c.slug = 'std-028' AND NOT EXISTS (SELECT 1 FROM category_spec_axes a WHERE a.category_id = c.id AND a.axis_key = 't');

-- ④ 결과 확인(분류 1행, 이름표 2행)
SELECT c.id, c.name, c.parent_id, c.discount_customer, c.discount_corporate, a.seq, a.axis_key, a.label
FROM categories c LEFT JOIN category_spec_axes a ON a.category_id = c.id WHERE c.slug = 'std-028' ORDER BY a.seq;
