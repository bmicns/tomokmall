-- 탈퇴 사유(사용자 결정 2026-11-27, data-decisions P1 — 1번 방식: 회원과 연결 없는 별도 표)
-- 왜: 탈퇴할 때 고른 사유·남기실 말씀을 서비스 개선에 쓰려고 모은다. 탈퇴 계정 행(users)과 이어지는 칸(user_id·이메일·시각)은
--     두지 않아 누가 남긴 사유인지 알 수 없게 한다. 남기실 말씀의 전화·이메일·사업자번호 모양은 저장 전에 지운다(app/privacy.php).
-- 보관: 기한 없이 두고, 관리자가 관리자 화면(/admin-withdraw-reasons.php)에서 직접 지운다.
-- 영향: 새 표 1개(가격 칸 없음). 기존 표는 바꾸지 않는다. 코드는 이 표가 없으면 사유를 저장하지 않고 탈퇴만 처리한다.
-- 순서: 이 SQL → 탈퇴 사유 코드 배포.
-- 되돌리기: database/20261127_withdraw_reasons_rollback.sql
CREATE TABLE IF NOT EXISTS withdraw_reasons (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  reason_code TINYINT UNSIGNED NULL DEFAULT NULL COMMENT '1~7(app/privacy.php PRIVACY_WITHDRAW_REASONS), NULL = 고르지 않음',
  memo        VARCHAR(300) NULL DEFAULT NULL COMMENT '남기실 말씀(전화·이메일·사업자번호 모양 지움)',
  member_role VARCHAR(20) NOT NULL COMMENT 'customer / corporate',
  created_on  DATE NOT NULL COMMENT '날짜만(시각은 계정 행과 이어질 수 있어 저장 안 함)',
  KEY idx_withdraw_reasons_created (created_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- @verify SELECT COUNT(*) AS total FROM withdraw_reasons
