-- 되돌리기: 20261126_ai_sessions_guest_hash.sql (비회원 기록 코드를 먼저 되돌린 뒤 실행)
ALTER TABLE ai_sessions
  DROP INDEX idx_ai_sessions_guest,
  DROP COLUMN guest_hash;
