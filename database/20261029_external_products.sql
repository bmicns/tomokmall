-- 외부 건축자재 데이터 검증 구역(staging) — 추가형 테이블 2개. 기존 테이블·데이터는 바꾸지 않는다.
-- 용도: 허락된 출처(공공데이터 파일·제조사 제공 카탈로그)의 상품을 정규화해 보관하고, 우리 products 와의 매칭 후보를 기록.
--       외부 가격은 ref_price(참고가)로만 보관 — 우리 판매가(products.price_b2c)로 쓰지 않는다. products 에 자동 등록하지 않는다.
-- 적용: 로컬 테스트 DB 는 scripts/testdb/setup-local-test-db.sh 가 적용. 운영 DB 적용은 사용자 승인 후(phpMyAdmin).
-- 되돌리기: DROP TABLE external_product_matches; DROP TABLE external_products;  (이 두 테이블만 사용, 다른 기능 영향 없음)

CREATE TABLE IF NOT EXISTS external_products (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  source_key    VARCHAR(40)  NOT NULL COMMENT 'data/external/sources.json 의 key',
  external_id   VARCHAR(100) NULL     COMMENT '출처의 식별번호(물품식별번호 등)',
  dedup_key     CHAR(40)     NOT NULL COMMENT '제조사·종류·이름·치수로 만든 중복 키(sha1)',
  name          VARCHAR(200) NOT NULL,
  maker         VARCHAR(100) NULL,
  product_type  VARCHAR(40)  NULL     COMMENT '인터로킹블록·그레이팅·PE관 등',
  spec_raw      VARCHAR(500) NULL,
  width_mm      INT NULL,
  length_mm     INT NULL,
  thickness_mm  INT NULL,
  diameter_mm   INT NULL,
  material      VARCHAR(40)  NULL,
  attrs_json    TEXT         NULL     COMMENT '투수·하중·용도·KS 표기·출처명',
  unit          VARCHAR(10)  NULL,
  ref_price     INT          NULL     COMMENT '참고가(출처 가격 그대로) — 판매가 아님',
  price_basis   VARCHAR(50)  NULL     COMMENT '조달 계약단가·제조사 공표가 등',
  source_url    VARCHAR(500) NULL,
  license_note  VARCHAR(300) NOT NULL COMMENT '가져올 때의 이용 근거(sources.json basis)',
  fetched_at    DATETIME     NOT NULL,
  review_status VARCHAR(20)  NOT NULL DEFAULT 'pending' COMMENT 'pending/approved/rejected',
  UNIQUE KEY uq_source_dedup (source_key, dedup_key),
  KEY idx_type (product_type)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS external_product_matches (
  id                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  external_product_id INT UNSIGNED NOT NULL,
  product_id          INT UNSIGNED NOT NULL,
  score               TINYINT UNSIGNED NOT NULL COMMENT '0~100 (ext_match_score)',
  reasons             VARCHAR(200) NULL,
  status              VARCHAR(20)  NOT NULL DEFAULT 'candidate' COMMENT 'candidate(자동)/confirmed(사람 확정)/rejected',
  created_at          DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_pair (external_product_id, product_id),
  KEY idx_product (product_id, status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
