-- ⚠️ 초안 — 운영 실행 금지(승인 전). 로컬 테스트 DB(tomokmall_test)에서만 검증함.
DRAFT_STOP_승인_전_실행_금지;  -- 안전장치: 이 줄이 있으면 실행 즉시 문법 오류로 중단됩니다. 승인 후 이 줄만 지우고 실행.
-- 목적: ktomok 수집 데이터의 '상차수량' 칸에 잘못 들어간 공급업체 담당자 연락처(이름·휴대폰·이메일·주소) 제거
-- 범위: products.spec 의 '| 상차수량: …' 구간 중 휴대폰번호 또는 이메일이 들어 있는 구간만 삭제(다른 칸·다른 상품은 그대로)

-- [1] 사전 확인 (읽기 전용) — 운영에서 먼저 실행해 건수 확인 (테스트 DB 결과: 102)
SELECT COUNT(*) AS pii_rows
FROM products
WHERE spec REGEXP '상차수량: [^|]*(01[016789]-[0-9]{3,4}-[0-9]{4}|@)';

-- [2] 백업 (실행 전 필수) — 대상 행만 별도 테이블로 복사
-- CREATE TABLE products_spec_backup_YYYYMMDD AS
--   SELECT id, spec, NOW() AS backed_up_at FROM products
--   WHERE spec REGEXP '상차수량: [^|]*(01[016789]-[0-9]{3,4}-[0-9]{4}|@)';

-- [3] 정리
-- UPDATE products
--    SET spec = TRIM(REGEXP_REPLACE(spec, '\\|\\s*상차수량: [^|]*(01[016789]-[0-9]{3,4}-[0-9]{4}|@)[^|]*', ''))
--  WHERE spec REGEXP '상차수량: [^|]*(01[016789]-[0-9]{3,4}-[0-9]{4}|@)';

-- [4] 사후 확인: 0 이어야 함
-- SELECT COUNT(*) FROM products WHERE spec REGEXP '01[016789]-[0-9]{3,4}-[0-9]{4}' OR spec LIKE '%@%';

-- [롤백] UPDATE products p JOIN products_spec_backup_YYYYMMDD b ON b.id = p.id SET p.spec = b.spec;
