-- ============================================================================
-- 02. 운영 → 테스트 DB 데이터 내보내기용 SELECT (초안 — 아직 실행 금지)
-- ----------------------------------------------------------------------------
-- 상태: 초안. 01의 [A3] 컬럼 결과를 받은 뒤 컬럼 목록을 운영과 정확히 맞춰 확정한다
--       (운영에 없는 컬럼이 있으면 오류, 운영에만 있는 컬럼은 빠짐 → 반드시 확정 후 실행).
-- 실행: 사용자 '운영 데이터 내보내기' 승인 후, 사용자가 phpMyAdmin [SQL] 탭에서 E1~E6을 하나씩 실행하고
--       결과 아래 [내보내기] → 형식 SQL, '데이터만', 'INSERT에 열 이름 포함', 문자셋 utf8mb4 로 저장.
-- 원칙: SELECT만 사용. 제외 항목은 내보낼 때 값 자체를 NULL/0으로 바꿔 원본이 운영 밖으로 나가지 않게 한다.
--       SELECT * 는 쓰지 않는다(운영에만 있는 민감 컬럼이 섞이는 것 방지).
-- 데이터를 내보내지 않는 테이블(구조만 복제): users, estimates, estimate_items, orders, order_items,
--   payments, shipping_info, sites, site_orders, chat_logs, ai_sessions, ai_orders, favorite_products,
--   reorder_alerts, reorder_history, search_logs, rag_documents, category_consult_guide 및 [A2]에서 확인되는 그 밖의 테이블
-- ============================================================================

-- [E1] categories — ID·계층·이름·정렬·활성 그대로 (마진율 2개 컬럼은 결정 사항: 기본안 0)
SELECT id, parent_id, name, slug, description, image_url, sort_order, is_active, created_at,
       0 AS discount_customer, 0 AS discount_corporate
  FROM categories ORDER BY id;

-- [E2] products — 매입가(cost_price)는 0, 그 밖의 상품·규격·재고·이미지 그대로
SELECT id, category_id, name, brand, spec, description, price_b2c, price_b2b, stock_qty,
       external_source, external_id, source_url, image_url, is_active, created_at,
       0 AS cost_price
  FROM products ORDER BY id;

-- [E3] category_relations — 유사품목·공정연결 그대로
SELECT id, source_category_id, target_category_id, relation_type, display_order, is_active
  FROM category_relations ORDER BY id;

-- [E4] vendors — 업체명·지역·활성만. 담당자·전화·팩스·이메일·주소·메모는 NULL
SELECT id, name, NULL AS contact, NULL AS phone, NULL AS fax, NULL AS email, region,
       NULL AS address, NULL AS memo, is_active, created_at, updated_at
  FROM vendors ORDER BY id;

-- [E5] product_vendors — 상품·업체 연결만. 공급단가(supply_price)는 0, 메모 NULL
SELECT id, product_id, vendor_id, 0 AS supply_price, lead_days, is_primary, NULL AS memo, updated_at
  FROM product_vendors ORDER BY id;

-- [E6] 화면 보조 테이블 — 저장소에 CREATE가 없어 컬럼을 모름. [A3] 결과로 컬럼 목록을 채운 뒤 활성화
-- SELECT <home_featured 컬럼 목록>    FROM home_featured    ORDER BY id;
-- SELECT <category_gallery 컬럼 목록> FROM category_gallery ORDER BY category_id, sort_order;
