-- ⚠️ 초안 — 운영 실행 금지(승인 전). 로컬 테스트 DB(MySQL 8.0)에서 검증함.
DRAFT_STOP_승인_전_실행_금지;  -- 안전장치: 이 줄이 있으면 실행 즉시 문법 오류로 중단됩니다. 승인 후 이 줄만 지우고 실행.
-- 목적: rag_documents 에 출처·검토 관리 칸 추가 (추가형 · 기존 9개 칸과 5건 데이터 보존)
-- 대상: 운영 MySQL 8.0.46 (사용자 확인 2026-10-27). ADD COLUMN IF NOT EXISTS 등 MariaDB 전용 구문 사용 안 함

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
  ADD COLUMN verify_level   VARCHAR(30)  NULL COMMENT '법령·기준 원문 확인 수준: original_verified(원문 검증)/metadata_confirmed(공식 메타데이터 확인: 고시·기준명·개정일·적용 범위)/search_candidate(검색 후보). 사내 문서는 NULL' AFTER review_status,
  ADD COLUMN verified_by    VARCHAR(100) NULL COMMENT '원문(또는 존재) 확인자' AFTER verify_level,
  ADD COLUMN verified_at    DATE         NULL COMMENT '원문(또는 존재) 확인일' AFTER verified_by,
  ADD COLUMN updated_by     INT          NULL AFTER verified_at,
  ADD INDEX idx_source_type (source_type),
  ADD INDEX idx_review (review_status);

-- 활성화 규칙(코드에서 강제 예정 · 승인 전 미구현):
--   source_type <> 'internal' 인 문서는 verify_level='original_verified' AND review_status='reviewed'
--   AND legal_status='current' 일 때만 is_active=1 로 AI 상담에 사용. 그 외는 확정적 법적 근거로 쓰지 않음.

-- 확인: 기존 5건이 그대로이고 source_type='internal', review_status='unreviewed' 인지
-- SELECT id, title, source_type, review_status, legal_status FROM rag_documents ORDER BY id;

-- 롤백 (새 칸에 입력한 값만 사라짐)
-- ALTER TABLE rag_documents DROP INDEX idx_source_type, DROP INDEX idx_review,
--   DROP COLUMN source_type, DROP COLUMN source_name, DROP COLUMN source_ref, DROP COLUMN source_url,
--   DROP COLUMN effective_date, DROP COLUMN revised_date, DROP COLUMN applies_to, DROP COLUMN legal_status,
--   DROP COLUMN author, DROP COLUMN reviewer, DROP COLUMN reviewed_at, DROP COLUMN review_status,
--   DROP COLUMN verify_level, DROP COLUMN verified_by, DROP COLUMN verified_at, DROP COLUMN updated_by;
