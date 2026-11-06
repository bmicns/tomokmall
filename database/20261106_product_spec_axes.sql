-- ─── 상품 규격 구조화: products 새 칸 + 카테고리별 규격 축 이름표(category_spec_axes) ─────────────
-- 목적: products.spec 의 '규격:' 값(예: '900*800*2000*85T (900B)')을 축별 숫자 칸으로 나눠 저장해
--       상품 목록 표·좌측 필터가 문자열 통째 대신 폭 W·높이 H·두께 T 같은 축별 값을 쓰게 한다.
-- 사용 코드: app/spec_axes.php, app/bootstrap.php(product_facets·product_filter_sql), app/product_filter_renderer.php,
--           public/products.php, public/api/admin/products.php(저장 시 새 칸 다시 계산), scripts/db-tools/spec-axes-fill.php
-- 성격: 추가형(additive). 기존 칸·데이터는 바꾸지 않는다(products.spec 원문 그대로).
--       새 칸은 모두 NULL 허용 — 비어 있거나 이름표가 없는 카테고리는 화면이 지금처럼 규격 원문을 쓴다.
--       products 는 가격 칸이 있는 표 → ADD COLUMN 만 한다(가격 보호 정책 허용 범위). 가격 칸·category_id 변경 없음.
--       외래키는 두지 않는다(categories 는 마진율이 있는 가격 보호 표 — 연결은 코드에서 확인).
-- 실행: 사용자 승인 후 [1] 사전 확인 → [2] 칸 추가 → [3] 이름표 표 생성 → [4] 확인된 카테고리 이름표 → [5] 확인.
--       (MySQL 8.0 — ADD COLUMN 에 IF NOT EXISTS 가 없으므로 [1]에서 0 일 때만 [2] 실행)
--       새 칸 값 채우기는 이 파일이 아니라 scripts/db-tools/spec-axes-fill.php (별도 승인).
--       순서: 이 파일 → 코드 배포 → 값 채우기. 코드는 칸·표가 없으면 지금 화면 그대로 동작한다.
-- 되돌리기: 맨 아래 [롤백] (새 칸·새 표만 지움 — 기존 데이터 영향 없음)

-- [1] 사전 확인(읽기 전용) ------------------------------------------------------
-- 1-1) 0 이면 [2] 필요, 9 이면 이미 있음([2] 생략)
SELECT COUNT(*) AS new_product_columns
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products'
  AND COLUMN_NAME IN ('spec_code', 'dim_d', 'dim_w', 'dim_h', 'dim_l', 'dim_t', 'dim_t2', 'spec_option', 'spec_dims_src');

-- 1-2) 0 이면 [3] 필요, 1 이면 이미 있음
SELECT COUNT(*) AS category_spec_axes_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'category_spec_axes';

-- 1-3) [4] 대상 카테고리: 7행(아래 이름)이 나와야 한다. 이름이 다르면 [4]는 그 카테고리를 건너뛴다(엉뚱한 카테고리에 붙지 않음)
--      21 개거 / 24 수로관(벤치3종) / 50 흄관(B형) / 59 사각맨홀(집수정) / 61 원형맨홀 / 66 조립식원형맨홀(상부.연직.하부구체) / 73 다칼라블랜딩블록(투수)
SELECT id, name FROM categories WHERE id IN (21, 24, 50, 59, 61, 66, 73) ORDER BY id;

-- [2] products 새 칸 -----------------------------------------------------------
-- 숫자 칸은 모두 mm 로 저장(규격이 m 표기면 ×1000). 칸 이름의 뜻(폭·가로 등)은 카테고리마다 category_spec_axes 가 정한다.
ALTER TABLE products
    ADD COLUMN spec_code     VARCHAR(30)  NULL COMMENT '규격 코드(예: 900B) — 규격 원문 괄호 속 코드' AFTER spec,
    ADD COLUMN dim_d         DECIMAL(8,1) NULL COMMENT '규격 축 d: 직경·내경 D (mm)' AFTER spec_code,
    ADD COLUMN dim_w         DECIMAL(8,1) NULL COMMENT '규격 축 w: 폭·가로 W (mm)' AFTER dim_d,
    ADD COLUMN dim_h         DECIMAL(8,1) NULL COMMENT '규격 축 h: 높이 H (mm)' AFTER dim_w,
    ADD COLUMN dim_l         DECIMAL(8,1) NULL COMMENT '규격 축 l: 길이 L·세로 (mm)' AFTER dim_h,
    ADD COLUMN dim_t         DECIMAL(8,1) NULL COMMENT '규격 축 t: 두께 T·상하두께 (mm)' AFTER dim_l,
    ADD COLUMN dim_t2        DECIMAL(8,1) NULL COMMENT '규격 축 t2: 두 번째 두께(예: 개거 벽체두께) (mm)' AFTER dim_t,
    ADD COLUMN spec_option   VARCHAR(80)  NULL COMMENT '규격 원문 중 치수·코드 뒤 나머지(예: 곡선, (유공))' AFTER dim_t2,
    ADD COLUMN spec_dims_src VARCHAR(180) NULL COMMENT '새 칸을 채울 때 기준이 된 규격 원문 — 규격이 바뀌면 다시 채울 때까지 화면은 원문 사용' AFTER spec_option;

-- [3] 카테고리별 규격 축 이름표 ---------------------------------------------------
-- 한 행 = "이 카테고리 규격의 seq 번째 숫자는 label(symbol)이고 products.dim_<axis_key> 에 담는다".
-- 하위 카테고리는 가장 가까운 상위 카테고리의 이름표를 물려받는다. axis_key='none' 행은 상위 이름표를 끊는다(숫자를 읽지 않음).
CREATE TABLE IF NOT EXISTS category_spec_axes (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id INT UNSIGNED NOT NULL                                  COMMENT 'categories.id — 하위 카테고리가 물려받음',
    seq         TINYINT UNSIGNED NOT NULL                              COMMENT '규격 표기에서 몇 번째 숫자인지(1부터) = 화면 칸 순서. none 은 0',
    axis_key    ENUM('d','w','h','l','t','t2','none') NOT NULL         COMMENT '값을 담을 products 칸(dim_d … dim_t2). none = 숫자 읽지 않음',
    label       VARCHAR(20) NULL                                       COMMENT '화면 이름(예: 폭, 내경, 상하두께)',
    symbol      VARCHAR(4)  NULL                                       COMMENT '약자(예: W, D) — 없으면 이름만 표시',
    show_symbol TINYINT(1) NOT NULL DEFAULT 1                          COMMENT '화면에 약자 표시(0이면 이름만 — 예: 개거 상하두께·벽체두께)',
    unit        ENUM('mm','m') NOT NULL DEFAULT 'mm'                   COMMENT '규격 원문·화면의 단위(저장은 항상 mm)',
    use_filter  TINYINT(1) NOT NULL DEFAULT 1                          COMMENT '좌측 필터에 보일지(0이면 표에만)',
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_csa_axis (category_id, axis_key),
    UNIQUE KEY uq_csa_seq  (category_id, seq)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [4] 확인된 카테고리 이름표(사용자 확인 2026-11-05~06) --------------------------------
--   24 수로관(벤치3종)  '900*800*2000*85T (900B)'  폭 W × 높이 H × 길이 L × 두께 T (+ 규격 코드)
--   21 개거             '4.0*1.0*2.0_280T*260T'    폭 W × 높이 H × 길이 L (m) _ 상하두께 T × 벽체두께 T (mm)
--   59 사각맨홀(집수정) '300*400*600*150T'         가로 W × 세로 L × 높이 H × 두께 T
-- 약자 규칙(사용자 결정 2026-11-06): 약자 = 값을 담는 칸의 글자(dim_w W·dim_l L·dim_h H·dim_t T·dim_d D). 뜻은 이름(label)이 구분
--   61 원형맨홀         'D900*1200*200T'           내경 D × 높이 H × 두께 T
--   50 흄관(B형)        'D600*2500*50T'            내경 D × 길이 L × 두께 T
--   66 조립식원형맨홀   'D600*1000H*150T_하부구체_KS' 내경 D × 높이 H × 두께 T (+ 옵션 하부구체_KS 등)
--   73 다칼라블랜딩블록(투수) '1200*1000*60_컬러(보도)' 가로 W × 세로 L × 두께 T (+ 옵션 컬러(보도) 등)
-- id 와 이름이 모두 맞는 카테고리에만, 이름표가 아직 없을 때만 넣는다(다시 실행해도 중복 없음).
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit)
SELECT c.id, v.seq, v.axis_key, v.label, v.symbol, v.show_symbol, v.unit
FROM (
              SELECT 24 AS cid, '수로관(벤치3종)' AS cname, 1 AS seq, 'w' AS axis_key, '폭' AS label, 'W' AS symbol, 1 AS show_symbol, 'mm' AS unit
    UNION ALL SELECT 24, '수로관(벤치3종)',   2, 'h',  '높이',     'H', 1, 'mm'
    UNION ALL SELECT 24, '수로관(벤치3종)',   3, 'l',  '길이',     'L', 1, 'mm'
    UNION ALL SELECT 24, '수로관(벤치3종)',   4, 't',  '두께',     'T', 1, 'mm'
    UNION ALL SELECT 21, '개거',              1, 'w',  '폭',       'W', 1, 'm'
    UNION ALL SELECT 21, '개거',              2, 'h',  '높이',     'H', 1, 'm'
    UNION ALL SELECT 21, '개거',              3, 'l',  '길이',     'L', 1, 'm'
    UNION ALL SELECT 21, '개거',              4, 't',  '상하두께', 'T', 1, 'mm'
    UNION ALL SELECT 21, '개거',              5, 't2', '벽체두께', 'T', 1, 'mm'
    UNION ALL SELECT 59, '사각맨홀(집수정)',  1, 'w',  '가로',     'W', 1, 'mm'
    UNION ALL SELECT 59, '사각맨홀(집수정)',  2, 'l',  '세로',     'L', 1, 'mm'
    UNION ALL SELECT 59, '사각맨홀(집수정)',  3, 'h',  '높이',     'H', 1, 'mm'
    UNION ALL SELECT 59, '사각맨홀(집수정)',  4, 't',  '두께',     'T', 1, 'mm'
    UNION ALL SELECT 61, '원형맨홀',          1, 'd',  '내경',     'D', 1, 'mm'
    UNION ALL SELECT 61, '원형맨홀',          2, 'h',  '높이',     'H', 1, 'mm'
    UNION ALL SELECT 61, '원형맨홀',          3, 't',  '두께',     'T', 1, 'mm'
    UNION ALL SELECT 50, '흄관(B형)',         1, 'd',  '내경',     'D', 1, 'mm'
    UNION ALL SELECT 50, '흄관(B형)',         2, 'l',  '길이',     'L', 1, 'mm'
    UNION ALL SELECT 50, '흄관(B형)',         3, 't',  '두께',     'T', 1, 'mm'
    UNION ALL SELECT 66, '조립식원형맨홀(상부.연직.하부구체)', 1, 'd', '내경', 'D', 1, 'mm'
    UNION ALL SELECT 66, '조립식원형맨홀(상부.연직.하부구체)', 2, 'h', '높이', 'H', 1, 'mm'
    UNION ALL SELECT 66, '조립식원형맨홀(상부.연직.하부구체)', 3, 't', '두께', 'T', 1, 'mm'
    UNION ALL SELECT 73, '다칼라블랜딩블록(투수)', 1, 'w',  '가로',     'W', 1, 'mm'
    UNION ALL SELECT 73, '다칼라블랜딩블록(투수)', 2, 'l',  '세로',     'L', 1, 'mm'
    UNION ALL SELECT 73, '다칼라블랜딩블록(투수)', 3, 't',  '두께',     'T', 1, 'mm'
) v
JOIN categories c ON c.id = v.cid AND c.name = v.cname
WHERE NOT EXISTS (SELECT 1 FROM category_spec_axes x WHERE x.category_id = c.id);

-- [5] 확인(읽기 전용) ----------------------------------------------------------
-- SELECT category_id, seq, axis_key, label, symbol, show_symbol, unit FROM category_spec_axes ORDER BY category_id, seq;   -- 25행
-- SHOW COLUMNS FROM products LIKE 'dim\_%';                                                                     -- 6행
-- SELECT COUNT(*) FROM products WHERE spec_dims_src IS NOT NULL;                                                -- 채우기 전 0

-- [롤백] 되돌리기 — 새 칸·새 표만 지운다(기존 칸·데이터 영향 없음). 승인 후에만
-- DROP TABLE category_spec_axes;
-- ALTER TABLE products DROP COLUMN spec_dims_src, DROP COLUMN spec_option, DROP COLUMN dim_t2, DROP COLUMN dim_t,
--     DROP COLUMN dim_l, DROP COLUMN dim_h, DROP COLUMN dim_w, DROP COLUMN dim_d, DROP COLUMN spec_code;
