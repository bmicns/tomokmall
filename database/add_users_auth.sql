-- ─── 기업회원 인증 테이블 ────────────────────────────────────────────────────
-- Phase 1: users 테이블 신규 생성

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role          ENUM('customer','corporate','staff','admin')
                  NOT NULL DEFAULT 'customer',
    name          VARCHAR(80)  NOT NULL,
    email         VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone         VARCHAR(40)  NULL,
    company_name  VARCHAR(120) NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
