-- 현장/프로젝트 관리 테이블
-- 고객이 현장 단위로 주문(견적)을 묶어서 관리

CREATE TABLE IF NOT EXISTS sites (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    name        VARCHAR(120) NOT NULL,
    address     VARCHAR(255) NULL,
    memo        TEXT NULL,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sites_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS site_orders (
    site_id     INT UNSIGNED NOT NULL,
    estimate_id INT UNSIGNED NOT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (site_id, estimate_id),
    CONSTRAINT fk_site_orders_site     FOREIGN KEY (site_id)     REFERENCES sites(id)     ON DELETE CASCADE,
    CONSTRAINT fk_site_orders_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
