-- ─── ① 구조(모두 추가형 — 기존 칸·행 변경 없음) ─────────────────────────────
-- 초안(2026-11-12) — 실행 안 함. 승인 뒤 database/ 마이그레이션으로 옮겨 실행. 설계: docs/02-design/multi-vendor-db.md
-- 원격 관리 API: 가격 칸이 있는 표(products·categories)는 ADD COLUMN·ADD INDEX 만 허용 → FOREIGN KEY 는 넣지 않음(인덱스만, 코드가 존재 확인).
-- 되돌리기: 맨 아래 주석

-- 판매 업체 코드(업체 원본·매핑이 업체를 가리키는 이름). 기존 4곳(샘플)은 code NULL
ALTER TABLE vendors
    ADD COLUMN code VARCHAR(30) NULL COMMENT '업체 코드(예: ktomok, syccrt) — 원본 출처·매핑 기준' AFTER name,
    ADD UNIQUE KEY uq_vendors_code (code);

-- 고객용 상품(합친 표): 업체·목록 칸 3개(dim_t2 는 이미 있음)·상세 전용 칸(JSON)·목록용 작은 이미지
ALTER TABLE products
    ADD COLUMN vendor_id INT UNSIGNED NULL COMMENT '판매 업체(vendors.id) — NULL 은 업체 미지정(기존 일부 행)' AFTER category_id,
    ADD COLUMN dim_t_mid DECIMAL(8,1) NULL COMMENT '중간벽 두께 (mm) — 2련암거',
    ADD COLUMN dim_x1    DECIMAL(8,1) NULL COMMENT '기타 칸 1 (mm) — 뜻은 분류 이름표(예: 파형강관 파형 피치)',
    ADD COLUMN dim_x2    DECIMAL(8,1) NULL COMMENT '기타 칸 2 (mm) — 뜻은 분류 이름표(예: 파형강관 파형 깊이)',
    ADD COLUMN dim_extra JSON NULL COMMENT '상세 화면 전용 치수 {칸 이름: 값} — 이름표 in_list=0 칸(업체 고유 세부·부속 치수)',
    ADD COLUMN image_thumb_url VARCHAR(255) NULL COMMENT '목록용 작은 이미지(없으면 image_url)' AFTER image_url,
    ADD KEY idx_products_vendor (vendor_id);

-- 규격 이름표: 목록에 보일 칸(1) / 상세 화면에만(0). 기존 행은 모두 1(지금과 같음)
-- axis_key: ENUM(products 칸 목록) → VARCHAR(20). 업체 고유 칸(세부·부속 치수 — products.dim_extra)을 이름표에 넣기 위해 넓힘(기존 값 그대로).
--   허용 칸 검사는 코드(app/spec_axes.php SPEC_AXIS_COLUMNS + 상세 칸 규칙)가 한다. 새 업체 칸이 생겨도 구조 변경 없음.
ALTER TABLE category_spec_axes
    MODIFY COLUMN axis_key VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '칸 이름: 목록 칸 = products 칸(dim_<키>, wire_no 는 spec_wire_no), 상세 칸(in_list=0) = products.dim_extra 의 키. skip·none = 읽기용 자리',
    ADD COLUMN in_list TINYINT(1) NOT NULL DEFAULT 1 COMMENT '목록 표에 보임(1) / 상세 화면에만(0)' AFTER use_filter;

-- 업체 분류 → 표준 분류(categories) 매핑. match_type: all(품목 전체) / code(모델 코드) / option(옵션 첫 낱말) / name(상품명에 포함)
CREATE TABLE IF NOT EXISTS vendor_category_map (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_id       INT UNSIGNED NOT NULL,
    vendor_category VARCHAR(40)  NOT NULL COMMENT '업체 분류 코드(예: syc-s1)',
    match_type      ENUM('all','code','option','name') NOT NULL DEFAULT 'all',
    match_value     VARCHAR(60)  NOT NULL DEFAULT '',
    std_category_id INT UNSIGNED NOT NULL COMMENT '표준 분류 categories.id',
    confidence      ENUM('상','중','하','새') NOT NULL DEFAULT '중',
    note            VARCHAR(200) NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vcm (vendor_id, vendor_category, match_type, match_value),
    KEY idx_vcm_std (std_category_id),
    CONSTRAINT fk_vcm_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='업체 분류 → 표준 분류';

-- 치수 칸 맞춤: 매핑 1줄마다 업체 칸 → 표준 칸(column) 또는 상세 전용(extra = products.dim_extra)
CREATE TABLE IF NOT EXISTS vendor_axis_map (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    map_id     INT UNSIGNED NOT NULL COMMENT 'vendor_category_map.id',
    src_key    VARCHAR(20)  NOT NULL COMMENT '업체 원본 칸(dim_ 뗀 이름)',
    dst_key    VARCHAR(20)  NOT NULL COMMENT '표준 칸(dim_ 뗀 이름)',
    target     ENUM('column','extra') NOT NULL DEFAULT 'column',
    confidence ENUM('상','중','하') NOT NULL DEFAULT '중',
    note       VARCHAR(200) NULL,
    UNIQUE KEY uq_vam (map_id, src_key),
    CONSTRAINT fk_vam_map FOREIGN KEY (map_id) REFERENCES vendor_category_map(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='업체 칸 → 표준 칸';

-- 되돌리기(데이터 없을 때): DROP TABLE vendor_axis_map, vendor_category_map;
--   ALTER TABLE category_spec_axes DROP COLUMN in_list;  (axis_key 를 ENUM 으로 되돌리려면 새로 넣은 칸 행을 먼저 지움)
--   ALTER TABLE products DROP KEY idx_products_vendor, DROP COLUMN image_thumb_url, DROP COLUMN dim_extra, DROP COLUMN dim_x2, DROP COLUMN dim_x1, DROP COLUMN dim_t_mid, DROP COLUMN vendor_id;
--   ALTER TABLE vendors DROP KEY uq_vendors_code, DROP COLUMN code;
