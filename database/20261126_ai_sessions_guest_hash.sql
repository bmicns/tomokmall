-- 비회원 AI 상담 이전 기록(사용자 결정 2026-11-26, data-decisions A12 — 칸 추가 승인 11-26)
-- 왜: 비회원 대화가 어느 방문자 것인지 표시할 칸이 없다. 쿠키 토큰의 SHA-256 해시(64자)만 저장하고 토큰 원본은 저장하지 않는다.
-- 영향: ai_sessions(가격 칸 없음)에 NULL 허용 칸 1개 + 일반 인덱스 1개 추가. 기존 행은 NULL 로 남고 지금 코드는 이 칸을 쓰지 않는다.
-- 순서: 이 SQL → 비회원 기록 코드 배포(코드는 칸이 없으면 지금처럼 동작).
-- 되돌리기: database/20261126_ai_sessions_guest_hash_rollback.sql
ALTER TABLE ai_sessions
  ADD COLUMN guest_hash CHAR(64) NULL DEFAULT NULL AFTER user_id,
  ADD INDEX idx_ai_sessions_guest (guest_hash);
-- @verify SELECT COUNT(*) AS total, SUM(guest_hash IS NULL) AS guest_hash_null FROM ai_sessions
