-- AI 상담 세션 테이블
CREATE TABLE IF NOT EXISTS ai_sessions (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key VARCHAR(64) NOT NULL UNIQUE,
    user_id    INT UNSIGNED NULL,
    messages   JSON NOT NULL DEFAULT (JSON_ARRAY()),
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_ai_sessions_user (user_id),
    INDEX idx_ai_sessions_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- products 테이블에 RAG용 컬럼 추가 (없으면)
ALTER TABLE products
    ADD COLUMN IF NOT EXISTS unit        VARCHAR(20)  NULL AFTER spec,
    ADD COLUMN IF NOT EXISTS weight_kg   DECIMAL(8,2) NULL AFTER unit,
    ADD COLUMN IF NOT EXISTS main_category VARCHAR(40) NULL AFTER weight_kg;

-- 전문검색 인덱스 (이미 있으면 무시)
ALTER TABLE products DROP INDEX IF EXISTS ft_products_search;
ALTER TABLE products ADD FULLTEXT KEY ft_products_search (name, spec, description);
