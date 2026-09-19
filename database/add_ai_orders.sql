-- AI 주문/결제 테이블 마이그레이션
-- P2: orders, order_items, payments, shipping_info 테이블 추가
-- ai_sessions.tool_state 컬럼 추가

-- 주문 테이블
CREATE TABLE IF NOT EXISTS orders (
    id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key    VARCHAR(64)  NOT NULL,
    customer_name  VARCHAR(80)  NOT NULL,
    phone          VARCHAR(40)  NOT NULL,
    email          VARCHAR(160) NULL,
    status         ENUM('PENDING','PAID','PREPARING','SHIPPED','DELIVERED','CANCELED')
                   NOT NULL DEFAULT 'PENDING',
    total_amount   INT UNSIGNED NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_orders_session (session_key),
    INDEX idx_orders_status  (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 주문 아이템 테이블
CREATE TABLE IF NOT EXISTS order_items (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id    INT UNSIGNED NOT NULL,
    product_id  INT UNSIGNED NOT NULL,
    quantity    INT UNSIGNED NOT NULL DEFAULT 1,
    unit_price  INT UNSIGNED NOT NULL DEFAULT 0,
    CONSTRAINT fk_order_items_order   FOREIGN KEY (order_id)   REFERENCES orders(id)   ON DELETE CASCADE,
    CONSTRAINT fk_order_items_product FOREIGN KEY (product_id) REFERENCES products(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 결제 정보 테이블
CREATE TABLE IF NOT EXISTS payments (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL UNIQUE,
    pg_provider     ENUM('TOSS','NICE','INICIS') NOT NULL DEFAULT 'TOSS',
    pg_payment_key  VARCHAR(200) NULL,
    pg_order_id     VARCHAR(64)  NOT NULL,
    amount          INT UNSIGNED NOT NULL,
    status          ENUM('READY','DONE','CANCELED','ABORTED','PARTIAL_CANCELED')
                    NOT NULL DEFAULT 'READY',
    paid_at         DATETIME NULL,
    raw_response    JSON NULL,
    created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_payments_order FOREIGN KEY (order_id) REFERENCES orders(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 배송지 정보 테이블
CREATE TABLE IF NOT EXISTS shipping_info (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    order_id        INT UNSIGNED NOT NULL UNIQUE,
    recipient_name  VARCHAR(80)  NOT NULL,
    phone           VARCHAR(40)  NOT NULL,
    zipcode         VARCHAR(10)  NULL,
    address1        VARCHAR(255) NOT NULL,
    address2        VARCHAR(100) NULL,
    memo            VARCHAR(200) NULL,
    carrier         VARCHAR(40)  NULL,
    tracking_number VARCHAR(80)  NULL,
    shipped_at      DATETIME NULL,
    CONSTRAINT fk_shipping_order FOREIGN KEY (order_id) REFERENCES orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ai_sessions 에 tool_state 컬럼 추가
-- 용도: 대화 중 수집 중인 견적/주문 정보(성함, 연락처 등) 중간 저장
ALTER TABLE ai_sessions
    ADD COLUMN IF NOT EXISTS tool_state JSON NULL AFTER messages;
