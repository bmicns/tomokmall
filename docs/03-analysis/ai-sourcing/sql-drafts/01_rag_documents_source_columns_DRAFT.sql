-- ⚠️ 초안 — 운영 실행 금지(승인 전). 로컬 테스트 DB(MySQL 8.0)에서 검증함.
DRAFT_STOP_승인_전_실행_금지;  -- 안전장치: 이 줄이 있으면 실행 즉시 문법 오류로 중단됩니다. 승인 후 이 줄만 지우고 실행.
-- 목적: rag_documents 에 출처·검토 관리 칸 추가 (추가형 · 기존 9개 칸과 5건 데이터 보존)
-- 운영이 MariaDB 인지 MySQL 인지 먼저 확인: SELECT VERSION();  (MySQL 8.0 은 ADD COLUMN IF NOT EXISTS 미지원)

ALTER TABLE rag_documents
  ADD COLUMN source_type    VARCHAR(20)  NOT NULL DEFAULT 'internal'   COMMENT 'internal/law/kds/kcs/ks' AFTER tags,
  ADD COLUMN source_name    VARCHAR(200) NULL COMMENT '공식 문서명'        AFTER source_type,
  ADD COLUMN source_ref     VARCHAR(100) NULL COMMENT '조문 또는 기준번호'  AFTER source_name,
  ADD COLUMN source_url     VARCHAR(500) NULL COMMENT '원문 URL'           AFTER source_ref,
  ADD COLUMN effective_date DATE         NULL COMMENT '시행일'             AFTER source_url,
  ADD COLUMN revised_date   DATE         NULL COMMENT '최종 개정일'        AFTER effective_date,
  ADD COLUMN applies_to     VARCHAR(300) NULL COMMENT '실제 적용 범위(예: 도로관리청 보도 / 개인 마당 적용 아님)' AFTER revised_date,
  ADD COLUMN legal_status   VARCHAR(20)  NOT NULL DEFAULT 'unknown' COMMENT 'current/repealed/unknown' AFTER applies_to,
  ADD COLUMN author         VARCHAR(100) NULL AFTER legal_status,
  ADD COLUMN reviewer       VARCHAR(100) NULL AFTER author,
  ADD COLUMN reviewed_at    DATE         NULL AFTER reviewer,
  ADD COLUMN review_status  VARCHAR(20)  NOT NULL DEFAULT 'unreviewed' COMMENT 'unreviewed/reviewed' AFTER reviewed_at,
  ADD COLUMN updated_by     INT          NULL AFTER review_status,
  ADD INDEX idx_source_type (source_type),
  ADD INDEX idx_review (review_status);

-- 확인: 기존 5건이 그대로이고 source_type='internal', review_status='unreviewed' 인지
-- SELECT id, title, source_type, review_status, legal_status FROM rag_documents ORDER BY id;

-- 롤백 (새 칸에 입력한 값만 사라짐)
-- ALTER TABLE rag_documents DROP INDEX idx_source_type, DROP INDEX idx_review,
--   DROP COLUMN source_type, DROP COLUMN source_name, DROP COLUMN source_ref, DROP COLUMN source_url,
--   DROP COLUMN effective_date, DROP COLUMN revised_date, DROP COLUMN applies_to, DROP COLUMN legal_status,
--   DROP COLUMN author, DROP COLUMN reviewer, DROP COLUMN reviewed_at, DROP COLUMN review_status, DROP COLUMN updated_by;
