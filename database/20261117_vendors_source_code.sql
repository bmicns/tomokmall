-- 2026-11-17 업체 목록에 상용콘크리트·케이토목 넣기(사용자 결정 2026-11-17, 가안 — data-decisions S25)
-- vendors(업체 표, 가격 칸 없음)에 '출처 코드' 칸 추가: 이 업체 상품이 어떤 출처 코드로 저장돼 있는지.
--   비워 두면 지금처럼 'vendor-<업체 id>'. 상품 데이터(products·syc_products)의 출처 코드는 바꾸지 않는다.
-- 실행 순서: 이 SQL(운영 DB, 승인 후) → 코드 배포. 코드는 칸이 없어도 예전처럼 동작한다.
-- 되돌리기: DELETE FROM vendors WHERE source_code IN ('syccrt','ktomok') AND NOT EXISTS (SELECT 1 FROM product_vendors pv WHERE pv.vendor_id = vendors.id);
--          ALTER TABLE vendors DROP INDEX uq_vendors_source_code, DROP COLUMN source_code;

ALTER TABLE vendors
    ADD COLUMN source_code VARCHAR(40) NULL COMMENT '상품 출처 코드(syccrt·ktomok). 비면 vendor-<id>' AFTER name,
    ADD UNIQUE KEY uq_vendors_source_code (source_code);

INSERT INTO vendors (name, source_code, is_active)
SELECT '상용콘크리트', 'syccrt', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM vendors WHERE source_code = 'syccrt');
INSERT INTO vendors (name, source_code, is_active)
SELECT '케이토목', 'ktomok', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM vendors WHERE source_code = 'ktomok');

-- 확인: SELECT id, name, source_code, is_active FROM vendors ORDER BY id;
