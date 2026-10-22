-- 로컬 개발 환경 초기화 SQL (MySQL 9.x 호환)
-- schema.sql 임포트 후 실행

-- AI 상담 세션 테이블
CREATE TABLE IF NOT EXISTS ai_sessions (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key VARCHAR(64) NOT NULL UNIQUE,
    user_id     INT UNSIGNED NULL,
    messages    JSON NOT NULL DEFAULT (JSON_ARRAY()),
    tool_state  JSON NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ai_sessions_user (user_id),
    INDEX idx_ai_sessions_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- products 추가 컬럼은 schema.sql에 이미 포함됨 (unit, weight_kg, main_category)

-- FULLTEXT 인덱스: schema.sql이 이미 생성함 (운영과 동일한 idx_ngram_products: name, spec, description / ngram)

-- AI 견적 주문 연결 테이블
CREATE TABLE IF NOT EXISTS ai_orders (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key  VARCHAR(64) NOT NULL,
    estimate_id  INT UNSIGNED NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ai_orders_session (session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 재주문 이력 테이블
CREATE TABLE IF NOT EXISTS reorder_history (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    estimate_id INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_reorder_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 어드민 계정 (admin@admin.com / 1111)
INSERT IGNORE INTO users (email, password_hash, name, company_name, role, is_active)
VALUES (
    'admin@admin.com',
    '$2y$10$JvqoTReW4V3VKlTcmN3y5OMqXgmRLJVHYk8eB3HJG8gqBrqIvYH.6',
    '관리자',
    'BMI C&S',
    'admin',
    1
);
