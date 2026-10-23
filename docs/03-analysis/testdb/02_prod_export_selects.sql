-- ============================================================================
-- 02. 운영 → 테스트 DB 데이터 내보내기 SELECT (확정본 — 운영 컬럼은 01 점검 결과 A3 기준)
-- ----------------------------------------------------------------------------
-- 실행: 사용자 승인 후, 사용자가 닷홈 phpMyAdmin → 사이트 DB → [SQL] 탭에서 아래 SELECT 하나를 통째로 실행
--       → 결과 표(blk·cnt·result, 10줄) → [쿼리 결과 작업 → 내보내기] → 형식 CSV → 파일 1개를 Claude 세션에 첨부
-- 원칙: SELECT만(운영 데이터 변경 없음). SELECT * 금지(운영에만 있는 컬럼이 섞이지 않게 컬럼을 명시).
--       민감 항목은 여기서 값 자체를 NULL/0으로 바꿔 내보낸다 → 원본 값은 운영 밖으로 나가지 않는다.
--         · products.cost_price(매입가) → 0, categories.discount_customer/discount_corporate(마진율) → 0
--         · vendors: 업체명·지역·활성만. contact·phone·fax·email·address·memo → NULL
--         · product_vendors.supply_price(공급단가) → 0, memo → NULL
-- 내보내지 않는 테이블(구조만 복제): users, estimates, estimate_items, orders, order_items, payments, shipping_info,
--   sites, site_orders, chat_logs, ai_sessions, search_logs, rag_documents, category_consult_guide
--   (home_featured 는 운영에 없음, category_gallery 는 01 P5에서 이메일·전화번호 형태 0건 확인 → 그대로 복제)
-- 상품은 결과 칸 크기를 줄이려고 ID 구간 5개로 나눈다(cnt 합계 = 01 C1 products 와 같아야 함).
-- ============================================================================

SELECT 'E1 categories' AS blk, COUNT(*) AS cnt,
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'parent_id', parent_id, 'name', name, 'description', description, 'slug', slug,
                                 'sort_order', sort_order, 'is_active', is_active, 'created_at', created_at,
                                 'discount_customer', 0, 'discount_corporate', 0)) AS result
  FROM categories
UNION ALL
SELECT 'E2 products 1', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'category_id', category_id, 'name', name, 'brand', brand, 'spec', spec, 'unit', unit,
                                 'weight_kg', weight_kg, 'main_category', main_category, 'description', description,
                                 'price_b2c', price_b2c, 'price_b2b', price_b2b, 'stock_qty', stock_qty,
                                 'external_source', external_source, 'external_id', external_id, 'source_url', source_url,
                                 'image_url', image_url, 'is_active', is_active, 'created_at', created_at, 'cost_price', 0))
  FROM products WHERE id BETWEEN 1 AND 350
UNION ALL
SELECT 'E2 products 2', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'category_id', category_id, 'name', name, 'brand', brand, 'spec', spec, 'unit', unit,
                                 'weight_kg', weight_kg, 'main_category', main_category, 'description', description,
                                 'price_b2c', price_b2c, 'price_b2b', price_b2b, 'stock_qty', stock_qty,
                                 'external_source', external_source, 'external_id', external_id, 'source_url', source_url,
                                 'image_url', image_url, 'is_active', is_active, 'created_at', created_at, 'cost_price', 0))
  FROM products WHERE id BETWEEN 351 AND 700
UNION ALL
SELECT 'E2 products 3', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'category_id', category_id, 'name', name, 'brand', brand, 'spec', spec, 'unit', unit,
                                 'weight_kg', weight_kg, 'main_category', main_category, 'description', description,
                                 'price_b2c', price_b2c, 'price_b2b', price_b2b, 'stock_qty', stock_qty,
                                 'external_source', external_source, 'external_id', external_id, 'source_url', source_url,
                                 'image_url', image_url, 'is_active', is_active, 'created_at', created_at, 'cost_price', 0))
  FROM products WHERE id BETWEEN 701 AND 1050
UNION ALL
SELECT 'E2 products 4', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'category_id', category_id, 'name', name, 'brand', brand, 'spec', spec, 'unit', unit,
                                 'weight_kg', weight_kg, 'main_category', main_category, 'description', description,
                                 'price_b2c', price_b2c, 'price_b2b', price_b2b, 'stock_qty', stock_qty,
                                 'external_source', external_source, 'external_id', external_id, 'source_url', source_url,
                                 'image_url', image_url, 'is_active', is_active, 'created_at', created_at, 'cost_price', 0))
  FROM products WHERE id BETWEEN 1051 AND 1400
UNION ALL
SELECT 'E2 products 5', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'category_id', category_id, 'name', name, 'brand', brand, 'spec', spec, 'unit', unit,
                                 'weight_kg', weight_kg, 'main_category', main_category, 'description', description,
                                 'price_b2c', price_b2c, 'price_b2b', price_b2b, 'stock_qty', stock_qty,
                                 'external_source', external_source, 'external_id', external_id, 'source_url', source_url,
                                 'image_url', image_url, 'is_active', is_active, 'created_at', created_at, 'cost_price', 0))
  FROM products WHERE id > 1400
UNION ALL
SELECT 'E3 category_relations', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'source_category_id', source_category_id, 'target_category_id', target_category_id,
                                 'relation_type', relation_type, 'display_order', display_order, 'is_active', is_active))
  FROM category_relations
UNION ALL
SELECT 'E4 vendors', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'name', name, 'contact', NULL, 'phone', NULL, 'fax', NULL, 'email', NULL,
                                 'region', region, 'address', NULL, 'memo', NULL, 'is_active', is_active,
                                 'created_at', created_at, 'updated_at', updated_at))
  FROM vendors
UNION ALL
SELECT 'E5 product_vendors', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'product_id', product_id, 'vendor_id', vendor_id, 'supply_price', 0,
                                 'lead_days', lead_days, 'is_primary', is_primary, 'memo', NULL, 'updated_at', updated_at))
  FROM product_vendors
UNION ALL
SELECT 'E6 category_gallery', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'category_id', category_id, 'image_url', image_url, 'caption', caption,
                                 'sort_order', sort_order))
  FROM category_gallery;
