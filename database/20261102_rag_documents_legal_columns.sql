-- 20261102_rag_documents_legal_columns.sql
-- 목적: rag_documents 에 법령·건설기준 근거의 출처·확인·검토 관리 칸 16개와 색인 2개 추가(추가형).
--       운영에는 이 칸이 없어 AI 상담이 법령 근거 없이 동작한다. app/rag.php 는 칸이 생기면 자동으로 근거 검색을 켠다
--       (rag_documents_has_source_columns). 칸만 추가해서는 상담이 바뀌지 않는다 — 근거 행이 없고, 기존 사내 문서는
--       source_type='internal' 로 채워져 기존 검색 그대로 동작.
-- 원본: docs/03-analysis/ai-sourcing/sql-drafts/01_rag_documents_source_columns_DRAFT.sql (같은 칸·형식·순서)
-- 대상: MySQL 8.0 (운영 8.0.46). ADD COLUMN IF NOT EXISTS 는 MySQL 에 없어 칸마다 information_schema 로 확인 후 추가.
-- 재실행: 안전 — 이미 있는 칸·색인은 건너뜀('DO 0'). 이미 같은 이름의 칸이 다른 형식으로 있으면 바꾸지 않으므로 사전 점검 ③ 확인.
-- ⚠️ 운영 실행은 사용자 승인 후 phpMyAdmin 에서(구조 변경). 실행 전 rag_documents 내보내기 백업.
-- 다음 단계: docs/03-analysis/ai-sourcing/prod-apply/README.md (10 등록 → 20 활성화 → 30 확인)
--
-- [사전 점검 — 읽기 전용, 먼저 따로 실행]
--  ① SELECT VERSION();                                                         -- 8.0.x
--  ② SELECT COUNT(*) AS docs, SUM(is_active) AS active FROM rag_documents;     -- 값을 적어 둠(사후 확인의 internal 건수)
--  ③ SELECT COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS
--      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rag_documents' ORDER BY ORDINAL_POSITION;
--      -- 기대: id·title·content·category_key·tags·is_active·sort_order·created_at·updated_at (9개). 새 칸 이름이 이미 있으면 보고
--  ④ SELECT INDEX_NAME FROM information_schema.STATISTICS
--      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rag_documents' GROUP BY INDEX_NAME;  -- idx_source_type·idx_review 없음
--
-- [사후 확인 — 읽기 전용]
--  SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rag_documents'; -- 25 (9 + 16)
--  SELECT id, title, source_type, review_status, legal_status, verify_level FROM rag_documents ORDER BY id;
--      -- 기존 행 전부 source_type='internal', review_status='unreviewed', legal_status='unknown', verify_level NULL
--
-- [되돌리기 — 새 칸에 넣은 값이 모두 사라짐. 근거 행이 있으면 prod-apply/90_rollback.sql [B] 먼저]
--  ALTER TABLE rag_documents DROP INDEX idx_source_type, DROP INDEX idx_review,
--    DROP COLUMN source_type, DROP COLUMN source_name, DROP COLUMN source_ref, DROP COLUMN source_url,
--    DROP COLUMN effective_date, DROP COLUMN revised_date, DROP COLUMN applies_to, DROP COLUMN legal_status,
--    DROP COLUMN author, DROP COLUMN reviewer, DROP COLUMN reviewed_at, DROP COLUMN review_status,
--    DROP COLUMN verify_level, DROP COLUMN verified_by, DROP COLUMN verified_at, DROP COLUMN updated_by;

SET @t = 'rag_documents';

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN source_type VARCHAR(20) NOT NULL DEFAULT ''internal'' COMMENT ''internal/law/kds/kcs/ks'' AFTER tags', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'source_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN source_name VARCHAR(200) NULL COMMENT ''공식 문서명'' AFTER source_type', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'source_name');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN source_ref VARCHAR(100) NULL COMMENT ''조문 또는 기준번호'' AFTER source_name', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'source_ref');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN source_url VARCHAR(500) NULL COMMENT ''원문 URL'' AFTER source_ref', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'source_url');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN effective_date DATE NULL COMMENT ''시행일'' AFTER source_url', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'effective_date');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN revised_date DATE NULL COMMENT ''최종 개정일'' AFTER effective_date', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'revised_date');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN applies_to VARCHAR(300) NULL COMMENT ''실제 적용 범위(예: 도로관리청 보도 / 개인 마당 적용 아님)'' AFTER revised_date', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'applies_to');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN legal_status VARCHAR(20) NOT NULL DEFAULT ''unknown'' COMMENT ''current/repealed/unknown'' AFTER applies_to', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'legal_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN author VARCHAR(100) NULL AFTER legal_status', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'author');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN reviewer VARCHAR(100) NULL AFTER author', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'reviewer');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN reviewed_at DATE NULL AFTER reviewer', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'reviewed_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN review_status VARCHAR(20) NOT NULL DEFAULT ''unreviewed'' COMMENT ''unreviewed/reviewed'' AFTER reviewed_at', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'review_status');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN verify_level VARCHAR(30) NULL COMMENT ''법령·기준 원문 확인 수준: original_verified(공식 원문 검증)/api_text_verified(법제처 OPEN API 원문으로 조문 확인 — 공식 API 대조 또는 원문 미러)/metadata_confirmed(공식 메타데이터 확인: 고시·기준명·개정일·적용 범위)/search_candidate(검색 후보). 사내 문서는 NULL'' AFTER review_status', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'verify_level');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN verified_by VARCHAR(100) NULL COMMENT ''원문(또는 존재) 확인자'' AFTER verify_level', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'verified_by');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN verified_at DATE NULL COMMENT ''원문(또는 존재) 확인일'' AFTER verified_by', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'verified_at');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD COLUMN updated_by INT NULL AFTER verified_at', 'DO 0')
          FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND COLUMN_NAME = 'updated_by');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD INDEX idx_source_type (source_type)', 'DO 0')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND INDEX_NAME = 'idx_source_type');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

SET @s = (SELECT IF(COUNT(*) = 0, 'ALTER TABLE rag_documents ADD INDEX idx_review (review_status)', 'DO 0')
          FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = @t AND INDEX_NAME = 'idx_review');
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 활성화 규칙(app/rag.php rag_search_legal_documents() 가 강제):
--   source_type <> 'internal' 인 문서는 verify_level IN ('original_verified','api_text_verified') AND review_status='reviewed'
--   AND legal_status='current' AND is_active=1 일 때만 AI 상담 근거로 사용. 그 외는 확정적 법적 근거로 쓰지 않음.
