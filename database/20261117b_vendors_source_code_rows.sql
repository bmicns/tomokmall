-- 2026-11-17 업체 목록에 상용콘크리트·케이토목 등록(S25) — 20261117_vendors_source_code.sql(출처 코드 칸) 다음에 실행
-- 되돌리기: DELETE FROM vendors WHERE source_code IN ('syccrt','ktomok') AND NOT EXISTS (SELECT 1 FROM product_vendors pv WHERE pv.vendor_id = vendors.id);

INSERT INTO vendors (name, source_code, is_active)
SELECT '상용콘크리트', 'syccrt', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM vendors WHERE source_code = 'syccrt');
INSERT INTO vendors (name, source_code, is_active)
SELECT '케이토목', 'ktomok', 1 FROM DUAL WHERE NOT EXISTS (SELECT 1 FROM vendors WHERE source_code = 'ktomok');

-- 확인: SELECT id, name, source_code, is_active FROM vendors ORDER BY id;
