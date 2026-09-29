-- ─── 회원 등급별 차등 가격 ──────────────────────────────────────────────────
-- categories.discount_customer : 개인회원 할인율(%). 카테고리별 설정, 하위 상속.
-- products.cost_price          : 공장도가(원가). 관리자/직원 전용 노출.
-- 기업할인가는 기존 products.price_b2b 를 그대로 사용(추가 컬럼 없음).
--
-- ⚠️ MySQL 8.0/9.x는 ALTER ... ADD COLUMN IF NOT EXISTS 를 지원하지 않는다(MariaDB 전용).
--    아래는 phpMyAdmin에서 최초 1회만 실행할 것(이미 컬럼이 있으면 에러 → 무시).
--    운영 자동 반영은 scripts/db-tools/db_setup_pricing_vendors.php 가 information_schema로
--    존재를 확인한 뒤 조건부로 추가하므로 재실행에 안전하다.

ALTER TABLE categories
    ADD COLUMN discount_customer TINYINT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '개인회원 할인율(%) 0~90, 하위 카테고리 상속';

ALTER TABLE products
    ADD COLUMN cost_price INT UNSIGNED NOT NULL DEFAULT 0
        COMMENT '공장도가(원가). 관리자/직원 전용';
