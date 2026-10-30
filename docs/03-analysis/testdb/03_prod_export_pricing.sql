-- ============================================================================
-- 03. (선택) 회원가 산정용 매입가·마진율 내보내기 SELECT — 결과 1개 표 → CSV 1개
-- ----------------------------------------------------------------------------
-- 용도: 개발 DB 재복제(scripts/testdb/devdb.sh refresh)의 선택 입력 ③.
--       02는 매입가(products.cost_price)·마진율(categories.discount_customer/discount_corporate)을 0으로 내보낸다.
--       이 값이 0이면 로컬에서 개인회원가·기업회원가가 정가로 보인다(app/pricing.php: 매입가 없으면 정가).
--       회원가 화면·견적 금액을 운영과 같게 확인해야 할 때만, 사용자가 결정해 이 파일을 추가로 실행한다.
-- 실행: 닷홈 phpMyAdmin → 사이트 DB → [SQL] 탭 → 이 파일 내용 전체 → [실행]
--       → 결과 표(blk, cnt, result) 2줄 → [쿼리 결과 작업 → 내보내기] → CSV 1개
-- 원칙: SELECT만(운영 데이터 변경 없음). 개인정보 없음. 다만 매입가·마진율은 영업 정보이므로
--       내보낸 파일은 저장소·메일·메신저에 두지 않고 Claude 세션 첨부로만 전달한다(적재 후 삭제).
-- ============================================================================

SELECT 'E7 product_costs' AS blk, COUNT(*) AS cnt,
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'cost_price', cost_price)) AS result
  FROM products
UNION ALL
SELECT 'E8 category_margins', COUNT(*),
       JSON_ARRAYAGG(JSON_OBJECT('id', id, 'discount_customer', discount_customer, 'discount_corporate', discount_corporate))
  FROM categories;
