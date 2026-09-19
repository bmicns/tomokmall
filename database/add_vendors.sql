-- ─── 업체 관리 테이블 ────────────────────────────────────────────────────────
-- vendors: 자재 공급업체
-- product_vendors: 품목별 업체 연결

CREATE TABLE IF NOT EXISTS vendors (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(120) NOT NULL,
    contact     VARCHAR(80)  NULL COMMENT '담당자명',
    phone       VARCHAR(40)  NULL,
    fax         VARCHAR(40)  NULL,
    email       VARCHAR(160) NULL,
    region      VARCHAR(60)  NULL COMMENT '지역(출발지)',
    address     VARCHAR(255) NULL,
    memo        TEXT         NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_vendors_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS product_vendors (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    product_id    INT UNSIGNED NOT NULL,
    vendor_id     INT UNSIGNED NOT NULL,
    supply_price  INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '공급단가',
    lead_days     SMALLINT     NOT NULL DEFAULT 0 COMMENT '리드타임(일)',
    is_primary    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '주거래업체 여부',
    memo          VARCHAR(200) NULL,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_product_vendor (product_id, vendor_id),
    CONSTRAINT fk_pv_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
    CONSTRAINT fk_pv_vendor  FOREIGN KEY (vendor_id)  REFERENCES vendors(id)  ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- estimates 테이블에 직원 작업용 컬럼 추가
ALTER TABLE estimates
    ADD COLUMN IF NOT EXISTS staff_id       INT UNSIGNED NULL     COMMENT '담당 직원 user_id',
    ADD COLUMN IF NOT EXISTS staff_memo     TEXT         NULL     COMMENT '직원 내부 메모',
    ADD COLUMN IF NOT EXISTS shipping_fee   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '운임비',
    ADD COLUMN IF NOT EXISTS discount_amt   INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '할인금액',
    ADD COLUMN IF NOT EXISTS sent_at        DATETIME     NULL     COMMENT '발송일시';

-- estimate_items에 직원 수정단가 컬럼 추가
ALTER TABLE estimate_items
    ADD COLUMN IF NOT EXISTS adjusted_price INT UNSIGNED NULL COMMENT '직원 수정단가 (NULL이면 unit_price 사용)',
    ADD COLUMN IF NOT EXISTS pallet_cnt     SMALLINT     NOT NULL DEFAULT 0 COMMENT '파렛트 수',
    ADD COLUMN IF NOT EXISTS weight_kg      INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '무게(kg)';
