-- ─── 관심상품(favorite_products) 테이블 생성 ─────────────────────────────────
-- 목적: 상품 목록·상세의 '관심상품 추가', 마이페이지 관심상품 목록(/api/estimate/favorites.php)이 쓰는 테이블.
--       운영 DB에 이 테이블이 없어(2026-10-23 운영 읽기 전용 점검) 추가 시 'Table doesn't exist' → 500 → '관심상품 저장에 실패했습니다' 오류.
-- 내용: database/add_reorder.sql 의 favorite_products 정의와 동일(같은 파일의 reorder_alerts 는 포함하지 않음).
-- 성격: 추가형(additive). 기존 테이블·데이터 변경 없음. IF NOT EXISTS 라 이미 있으면 아무 일도 하지 않음.
-- 실행: 사용자 승인 후 phpMyAdmin 에서 [1] 사전 확인 → [2] 생성 → [3] 확인 순서로.

-- [1] 사전 확인(읽기 전용) ------------------------------------------------------
-- 1-1) 0 이면 생성 필요, 1 이면 이미 있음([2] 생략)
SELECT COUNT(*) AS favorite_products_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'favorite_products';

-- 1-2) 외래키 대상 확인: 두 행 모두 COLUMN_TYPE = 'int unsigned'(MariaDB·구버전 표기 'int(10) unsigned'), ENGINE = 'InnoDB' 여야 [2]가 성공한다
SELECT c.TABLE_NAME, c.COLUMN_TYPE, t.ENGINE
FROM information_schema.COLUMNS c
JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = c.TABLE_SCHEMA AND t.TABLE_NAME = c.TABLE_NAME
WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME IN ('users', 'products') AND c.COLUMN_NAME = 'id';

-- [2] 생성 ---------------------------------------------------------------------
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

-- [3] 확인(읽기 전용) ----------------------------------------------------------
-- SHOW CREATE TABLE favorite_products;
-- SELECT COUNT(*) FROM favorite_products;   -- 생성 직후 0

-- [롤백] 되돌리기 — 회원이 저장한 관심상품도 함께 지워지므로, 먼저 아래 COUNT 가 0 인지 확인하고 승인 후에만 실행
-- SELECT COUNT(*) FROM favorite_products;
-- DROP TABLE favorite_products;
