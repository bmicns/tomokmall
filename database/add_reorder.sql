-- ─── 재구매 알림 + 단골품목 테이블 ────────────────────────────────────────────
-- Phase 1: reorder_alerts, favorite_products 신규 생성

CREATE TABLE IF NOT EXISTS reorder_alerts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    estimate_id INT UNSIGNED NOT NULL,
    alert_after ENUM('1y','2-3y','5y+','unknown') NOT NULL DEFAULT 'unknown',
    alert_at    DATE         NULL,
    is_sent     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_estimate (user_id, estimate_id),
    CONSTRAINT fk_alert_user     FOREIGN KEY (user_id)     REFERENCES users(id),
    CONSTRAINT fk_alert_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS favorite_products (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity   INT UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_product (user_id, product_id),
    CONSTRAINT fk_fav_user    FOREIGN KEY (user_id)    REFERENCES users(id)     ON DELETE CASCADE,
    CONSTRAINT fk_fav_product FOREIGN KEY (product_id) REFERENCES products(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
