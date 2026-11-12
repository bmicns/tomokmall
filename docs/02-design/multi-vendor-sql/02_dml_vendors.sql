-- ─── ② 판매 업체: 케이토목·상용콘크리트 등록, 샘플 4곳 비활성, 케이토목 상품에 업체 표시 ─────────────────────────────
-- 초안(2026-11-12) — 실행 안 함. 승인 뒤 database/ 마이그레이션으로 옮겨 실행. 설계: docs/02-design/multi-vendor-db.md
-- 샘플 4곳(대성산업·한길콘크리트·동보건설자재·삼정산업)은 지우지 않고 비활성(사용자 확인: 샘플 데이터). product_vendors 샘플 연결 1,788건은 그대로(고객 화면은 products.vendor_id 로 업체 표시).
-- products 는 가격 표 — vendor_id(가격 아님) 만 바꿈. category_id·가격 칸은 그대로. 외부 출처 dacity 10행·출처 없음 12행은 업체 미지정(NULL)으로 둠(결정 필요).

INSERT INTO vendors (name, code, is_active, memo)
SELECT x.name, x.code, 1, x.memo FROM (
    SELECT '케이토목' AS name, 'ktomok' AS code, '기존 상품 데이터(products external_source=ktomok)' AS memo
    UNION ALL SELECT '상용콘크리트', 'syccrt', '상용콘크리트(주) — 원본 syc_products'
) x WHERE NOT EXISTS (SELECT 1 FROM vendors v WHERE v.code = x.code);

UPDATE vendors SET is_active = 0 WHERE code IS NULL AND id IN (1, 2, 3, 4);

-- 케이토목 상품 1,319행에 업체 표시(원격 관리 API 규칙: 한 테이블 + WHERE … IN (SELECT …) — 값은 아래 확인 SELECT 로 id 를 읽어 숫자로 넣는다)
-- SELECT id FROM vendors WHERE code = 'ktomok';   -- 이 값을 :ktomok_id 자리에
UPDATE products SET vendor_id = :ktomok_id WHERE external_source = 'ktomok' AND vendor_id IS NULL;
