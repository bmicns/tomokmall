-- 운영 견적 테이블 추가 확인 (읽기 전용 · SELECT 3개 · 개인정보 값 없음 — 컬럼 이름·건수·날짜만)
-- 배경: prod_schema_check_readonly.sql 결과 estimates.user_id·reorder_alerts '없음' → 현재 운영 코드의 견적 저장이 실패하는지 확인
-- 실행: phpMyAdmin → 운영 DB 선택 → [1] [2] [3] 을 각각 실행 → 결과 표 3개를 전달

-- [1] estimates 컬럼 구성(이름·형식·기본값) — user_id·mode·requested_delivery_at·delivery_completed_at 있는지, status 형식
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estimates'
ORDER BY ORDINAL_POSITION;

-- [2] 견적이 마지막으로 저장된 날짜와 월별 건수(최근 6개월) — 저장이 멈춘 시점 확인
SELECT DATE_FORMAT(created_at, '%Y-%m') AS 월, COUNT(*) AS 견적수, MAX(created_at) AS 마지막_저장
FROM estimates
WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
GROUP BY DATE_FORMAT(created_at, '%Y-%m')
ORDER BY 월;

-- [3] 수정 SQL 이 참조할 컬럼 형식 확인(reorder_alerts 외래 키) — users.id·estimates.id 형식
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND ((TABLE_NAME = 'users' AND COLUMN_NAME = 'id') OR (TABLE_NAME = 'estimates' AND COLUMN_NAME = 'id'));
