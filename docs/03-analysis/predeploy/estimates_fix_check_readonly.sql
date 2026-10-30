-- 견적 복구 SQL(database/20261030_estimates_missing_columns.sql) 사전·사후 확인 — 읽기 전용(SELECT·EXPLAIN 만, 데이터 변경 없음, 개인정보 값 없음)
-- EXPLAIN 은 실제로 실행하지 않고 '이 문장이 실행될 수 있는지'만 검사한다(행이 추가되지 않음).
-- 실행: phpMyAdmin → 운영 DB 선택 → 각 블록을 따로 실행 → 결과 전달

-- [사전] 복구 SQL 실행 전 — users.id·estimates.id 형식과 저장 엔진(reorder_alerts 외래 키 조건), 견적 행 수
--   기대: 두 줄 모두 COLUMN_TYPE = int unsigned, ENGINE = InnoDB / 견적수 = 0
SELECT c.TABLE_NAME, c.COLUMN_TYPE, t.ENGINE
FROM information_schema.COLUMNS c JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
WHERE c.TABLE_SCHEMA = DATABASE() AND c.COLUMN_NAME = 'id' AND c.TABLE_NAME IN ('users', 'estimates');
SELECT COUNT(*) AS 견적수 FROM estimates;

-- [사후-A] [A] 실행 후 — 컬럼 4개 추가, status 기본값 그대로
--   기대: 5줄 — user_id(int unsigned, YES, NULL), mode(enum('chat','quick'), NO, chat), requested_delivery_at·delivery_completed_at(datetime, YES, NULL), status(기본값 REQUESTED)
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'estimates'
  AND COLUMN_NAME IN ('user_id', 'mode', 'requested_delivery_at', 'delivery_completed_at', 'status')
ORDER BY ORDINAL_POSITION;
--   기대: 오류 없이 실행 계획 1줄(복구 전에는 "Unknown column 'user_id'" 오류) — 견적 접수 문장이 실행 가능한지 검사, 행은 추가되지 않음
EXPLAIN INSERT INTO estimates (user_id, customer_name, company_name, phone, email, site_address, memo, mode, requested_delivery_at, created_at)
VALUES (NULL, 'x', NULL, 'x', NULL, NULL, NULL, 'chat', NULL, NOW());
--   기대: 견적수 = 0 (EXPLAIN 으로 행이 생기지 않았는지)
SELECT COUNT(*) AS 견적수 FROM estimates;

-- [사후-B] [B] 실행 후 — reorder_alerts 생성(빈 테이블), 배송 내역 조회 문장이 실행 가능한지
--   기대: 행수 = 0
SELECT COUNT(*) AS reorder_alerts_행수 FROM reorder_alerts;
--   기대: 오류 없이 실행 계획(복구 전에는 테이블 없음 오류) — public/api/mypage/delivery-history.php 와 같은 조회
EXPLAIN SELECT e.id FROM estimates e
JOIN estimate_items ei ON ei.estimate_id = e.id
LEFT JOIN reorder_alerts ra ON ra.estimate_id = e.id AND ra.user_id = 0
WHERE e.user_id = 0 AND e.status = 'DELIVERED';
