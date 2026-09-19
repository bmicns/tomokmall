-- ─── estimates 테이블 v2.0 컬럼 추가 ────────────────────────────────────────
-- Phase 1: user_id, mode, status(변경), requested_delivery_at, delivery_completed_at

-- user_id: 기업회원 연결 (NULL = 비회원 허용)
ALTER TABLE estimates
    ADD COLUMN IF NOT EXISTS user_id               INT UNSIGNED NULL         AFTER id,
    ADD COLUMN IF NOT EXISTS mode                  ENUM('chat','quick')
                                                   NOT NULL DEFAULT 'chat'   AFTER memo,
    ADD COLUMN IF NOT EXISTS requested_delivery_at DATETIME NULL,
    ADD COLUMN IF NOT EXISTS delivery_completed_at DATETIME NULL;

-- status ENUM 확장: 기존 값 유지 + DELIVERED 추가
-- 기존: REQUESTED, SENT, APPROVED, EXPIRED, CANCELED
-- 추가: PENDING, CONFIRMED, DELIVERED (레거시 값과 공존)
ALTER TABLE estimates
    MODIFY COLUMN status ENUM(
        'PENDING','CONFIRMED','DELIVERED','CANCELED',
        'REQUESTED','SENT','APPROVED','EXPIRED'
    ) NOT NULL DEFAULT 'PENDING';

-- 인덱스 추가 (중복 실행 방지: IF NOT EXISTS 미지원 → 오류 무시)
ALTER TABLE estimates
    ADD INDEX idx_estimates_user   (user_id),
    ADD INDEX idx_estimates_status (status);
