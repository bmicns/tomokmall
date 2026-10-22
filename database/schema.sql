CREATE TABLE IF NOT EXISTS categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id INT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_categories_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS products (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL,
    name VARCHAR(160) NOT NULL,
    brand VARCHAR(100) NULL,
    spec TEXT NULL,
    description TEXT NULL,
    price_b2c INT UNSIGNED NOT NULL DEFAULT 0,
    price_b2b INT UNSIGNED NOT NULL DEFAULT 0,
    stock_qty INT NOT NULL DEFAULT 0,
    external_source VARCHAR(40) NULL,
    external_id VARCHAR(80) NULL,
    source_url VARCHAR(255) NULL,
    image_url VARCHAR(255) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_products_category (category_id),
    UNIQUE KEY uq_products_external (external_source, external_id),
    -- 운영 DB와 동일(2026-10-22 확인): 검색 코드 MATCH(p.name, p.spec, p.description)와 컬럼·순서 일치 필수, 한글 2글자 검색용 ngram
    FULLTEXT KEY idx_ngram_products (name, spec, description) WITH PARSER ngram,
    CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS category_relations (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_category_id INT UNSIGNED NOT NULL,
    target_category_id INT UNSIGNED NOT NULL,
    relation_type ENUM('SIMILAR', 'NEXT_STEP') NOT NULL,
    display_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_category_relation (source_category_id, target_category_id, relation_type),
    CONSTRAINT fk_rel_source FOREIGN KEY (source_category_id) REFERENCES categories(id),
    CONSTRAINT fk_rel_target FOREIGN KEY (target_category_id) REFERENCES categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 인기검색어 집계 (앱에서 IF NOT EXISTS로 자동 생성; 여기 기재는 문서화용)
CREATE TABLE IF NOT EXISTS search_logs (
    term VARCHAR(100) NOT NULL PRIMARY KEY,
    cnt INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estimates (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    customer_name VARCHAR(80) NOT NULL,
    company_name VARCHAR(120) NULL,
    phone VARCHAR(40) NOT NULL,
    email VARCHAR(160) NULL,
    site_address VARCHAR(255) NULL,
    requested_date DATE NULL,
    memo TEXT NULL,
    status ENUM('REQUESTED', 'SENT', 'APPROVED', 'EXPIRED', 'CANCELED') NOT NULL DEFAULT 'REQUESTED',
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS estimate_items (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    estimate_id INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_estimate_items_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id) ON DELETE CASCADE,
    CONSTRAINT fk_estimate_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 토목자재 대분류 (parent_id NULL = 루트)
INSERT IGNORE INTO categories (name, slug, sort_order, is_active) VALUES
('철근콘크리트제품', 'civil-rc',       10, 1),
('콘크리트제품',     'civil-concrete',  20, 1),
('스틸제품',         'civil-steel',     30, 1),
('석재제품',         'civil-stone',     40, 1),
('PE/PVC제품',       'civil-pepvc',     50, 1),
('기타',             'civil-etc',       60, 1);
