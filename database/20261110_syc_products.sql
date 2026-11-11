-- ─── 상용콘크리트(syccrt.com) 상품 표: syc_products ─────────────────────────────────────────
-- 목적: 상용콘크리트 상품을 '제품관리 › 일괄 등록'(엑셀 + 이미지 ZIP 두 파일)으로 올릴 때 담을 전용 상품 표.
--       기존 products(케이토목 기준)와 별개. 기존 표·데이터는 건드리지 않는다.
-- 사용 코드: 없음(만들어 두기만 함 — 사용자 결정 2026-11-10). 사이트 목록·검색·견적·가격 계산(app/pricing.php)에 영향 없음.
-- 구조: 운영 products(2026-11-10 DESCRIBE)를 참조하되 다음이 다르다.
--   · category_id → syc_categories.id (database/20261110_syc_categories.sql 을 먼저 실행)
--   · 이미지: image_url(외부 주소 1개) 대신 크기 2가지 파일(사용자 결정 2026-11-11) — 일괄 등록이 이미지 ZIP 에서 꺼내 서버에 저장한 경로
--       image_file = 대표(상세 화면용 큰 이미지), image_thumb_file = 썸네일(목록·관련상품용 작은 이미지)
--       썸네일(…_thumb)이 엑셀·ZIP 에 없으면 비워 두고(NULL) 화면은 기존 '이미지 없음' 표시를 그대로 쓴다(사용자 결정 2026-11-11)
--       (기존 화면의 빈 이미지 표시: 관리자 목록 .prod-no-img · 관리자 상세 .img-empty · 상세 '이미지 준비 중' 등 — 이미지 값이 비면 자동)
--       기존 products 는 40×40 썸네일 1장으로 상세 화면까지 써서 상세 이미지가 흐림 — 이 표는 두 크기를 따로 둔다.
--   · spec_code = 모델코드 = 카탈로그 '호칭'(예: 300A, 500C, 1.0m×1.0m 암거는 '1000*1000')
--   · 치수 칸(dim_*): products 의 dim_d ~ spec_dims_src 를 카탈로그(2025) 판독본·웹사이트 화면의 실제 머리글에 맞춰 조정
--       남김  dim_d·dim_d_i·dim_d_o·dim_w·dim_w_b·dim_w_t·dim_h·dim_depth·dim_l·dim_v·dim_t·dim_t2
--             ·dim_t_top·dim_t_wall·dim_t_bottom·dim_mesh·dim_wire_d·spec_wire_no·dim_d_inlet·dim_d_outlet
--       추가  dim_w_o(외폭 Bo·외경폭 A) · dim_h_o(외높이 Ho·외경높이/외경깊이 B) · dim_t_mid(중간벽 두께 — 2련 암거 T4)
--       세부 치수(사용자 결정 2026-11-10 — 도면 세부 치수도 칸으로): 카탈로그 도면 기호 그대로 번호 칸
--             dim_w1·w2 / dim_h1~h4 / dim_t1·t3·t4(t2 는 기존 dim_t2) / dim_l1~l6 / dim_d1~d5 / dim_r1·r2 / dim_c / dim_x1~x4
--             같은 칸이라도 품목마다 뜻이 다르다 → 품목별 기호 대응표: docs/02-design/syc-products.md 2장
--       뺌    dim_d_base(우레탄볼라드)·dim_w_r(뒷폭)·dim_h_high·dim_h_low(화강경계경사)·dim_ts(그리드 인장강도)
--             ·dim_d_connect(PVC소형맨홀) — 상용콘크리트 자료에 해당 치수 없음
--             ·dim_t_tb(개거 상하두께) — 상판·하판 두께가 따로 나오므로 dim_t_top·dim_t_bottom 으로 나눔
--   · spec_option: 치수·모델코드 외 나머지(그레이팅 규격, 설치방식 매립식/이동식/앙카식, 재질, 구분 3종 등) — 길이 80 → 160
--   · main_category 뺌(products 에서도 코드가 쓰지 않음)
--   · is_active 기본값 0 — 일괄 등록 상품은 비활성으로 저장하고 확인 뒤 활성화(디자인 '제품관리(일괄 등록)')
--   카탈로그 머리글 → 칸 대응표: docs/02-design/syc-products.md
-- 숫자 치수는 모두 mm 로 저장(카탈로그가 m 표기면 ×1000). 중량은 kg(t 표기면 ×1000).
-- ⚠ 가격 보호(CLAUDE.md 12장): price_b2c·price_b2b·cost_price 가 있어 원격 관리 API 는 이 표의 가격 쓰기를 막는다.
--    → 표 생성은 사용자 승인 후 phpMyAdmin 에서 실행. 기존 가격 데이터는 바뀌지 않는다.
-- 성격: 추가형(additive). 새 표 1개(행 없음).
-- 실행: 사용자 승인 후 [1] 사전 확인 → [2] 표 생성 → [3] 확인.
-- 되돌리기: 맨 아래 [롤백] (새 표만 지움 — 기존 데이터 영향 없음)

-- [1] 사전 확인(읽기 전용) ------------------------------------------------------
-- 1-1) syc_categories 가 있어야 한다(외래키 대상): 1
SELECT COUNT(*) AS syc_categories_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syc_categories';
-- 1-2) 0 이면 새로 만듦, 1 이면 이미 있음([2]는 IF NOT EXISTS 라 그대로 실행해도 됨)
SELECT COUNT(*) AS syc_products_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syc_products';

-- [2] 표 생성 ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS syc_products (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category_id   INT UNSIGNED NOT NULL          COMMENT 'syc_categories.id(품목 단계)',
    name          VARCHAR(160) NOT NULL          COMMENT '상품명(예: 측구수로관 앵글미부착 A형)',
    brand         VARCHAR(100) NULL              COMMENT '제조사·브랜드(예: 상용콘크리트, HK, SCG)',
    spec          VARCHAR(180) NULL              COMMENT '규격 원문(화면 표시용, 예: 300*300*2000*125T (300A))',
    spec_code     VARCHAR(30)  NULL              COMMENT '모델코드 = 카탈로그 호칭(예: 300A, 500C, AN300)',
    dim_d         DECIMAL(8,1) NULL COMMENT '직경 Ø (mm) — 볼라드 Ø101.6·주철뚜껑 Φ648·원형수로관 Ø150·물받이지름',
    dim_d_i       DECIMAL(8,1) NULL COMMENT '내경 D (mm) — 흄관 인지름·VR관 몸체 D·원형맨홀·조립식맨홀 Φ·파형강관 관경·주철 내경',
    dim_d_o       DECIMAL(8,1) NULL COMMENT '외경 D (mm) — 주철 COVER 외경',
    dim_w         DECIMAL(8,1) NULL COMMENT '폭·가로 W (mm) — 수로관 W·a(안나비)·암거 내공 B·맨홀 내경 가로·A',
    dim_w_o       DECIMAL(8,1) NULL COMMENT '외폭 (mm) — 암거·개거 바깥나비 Bo·RDG 외경폭 A·사각수로관 외경 폭 A',
    dim_w_b       DECIMAL(8,1) NULL COMMENT '하부폭(밑면) (mm) — 벤치플륨·환경수로관 c(밑나비)·경계석 밑면',
    dim_w_t       DECIMAL(8,1) NULL COMMENT '상부폭(윗면) (mm) — 경계석 윗면',
    dim_h         DECIMAL(8,1) NULL COMMENT '높이 H (mm) — 측구수로관 H·암거 내공 H·맨홀 내경 높이·볼라드 높이·주철 전체높이',
    dim_h_o       DECIMAL(8,1) NULL COMMENT '외높이 (mm) — 암거·개거 바깥높이 Ho·RDG 외경높이 H·사각수로관 외경 깊이 B',
    dim_depth     DECIMAL(8,1) NULL COMMENT '깊이 (mm) — 벤치플륨 h·사각수로관 내경 깊이 b·RDG 깊이 h·알루미늄트렌치 깊이·건널목수로관 h',
    dim_l         DECIMAL(8,1) NULL COMMENT '길이 L (mm) — 유효길이 L·ℓ·l',
    dim_v         DECIMAL(8,1) NULL COMMENT '세로 (mm) — 맨홀 내경 세로·전기통신 맨홀 B·PE 빗물받이 규격 둘째 값·용접철망 B',
    dim_t         DECIMAL(8,1) NULL COMMENT '두께 T (mm) — 대표 두께(측구수로관 T·흄관 T·볼라드 2T 의 2)',
    dim_t2        DECIMAL(8,1) NULL COMMENT '두 번째 두께 t2 (mm) — 측구수로관 t2·T2·원심력수로관 t2·벤치플륨/환경수로관 e',
    dim_t_top     DECIMAL(8,1) NULL COMMENT '상부(상판·슬라브) 두께 (mm) — 암거·개거 T1(상판)·맨홀 슬라브·사각수로관 벽체 상부 c',
    dim_t_wall    DECIMAL(8,1) NULL COMMENT '벽체(측벽) 두께 (mm) — 암거·개거 T3(측벽)·맨홀 벽체·조립식맨홀 벽두께·사각수로관 벽체 e',
    dim_t_bottom  DECIMAL(8,1) NULL COMMENT '하부(하판·기초·바닥) 두께 (mm) — 암거·개거 T2(하판)·맨홀 기초·사각수로관 바닥 d',
    dim_t_mid     DECIMAL(8,1) NULL COMMENT '중간벽 두께 (mm) — 2련 암거 T4',
    -- 세부 치수: 카탈로그 도면 기호 그대로(품목별 뜻은 docs/02-design/syc-products.md 2장)
    dim_w1        DECIMAL(8,1) NULL COMMENT '세부 폭 w1 (mm) — 측구수로관 w1·벤치플륨/환경수로관 b·U형 플륨 A''',
    dim_w2        DECIMAL(8,1) NULL COMMENT '세부 폭 w2 (mm) — 측구수로관 w2',
    dim_h1        DECIMAL(8,1) NULL COMMENT '세부 높이 h1 (mm) — 측구수로관 h1·U형 플륨 H''·원형수로관 높이2·주철 B(뚜껑높이)',
    dim_h2        DECIMAL(8,1) NULL COMMENT '세부 높이 h2 (mm) — 측구수로관 h2·U형 플륨 h',
    dim_h3        DECIMAL(8,1) NULL COMMENT '세부 높이 h3 (mm) — 측구수로관 h3',
    dim_h4        DECIMAL(8,1) NULL COMMENT '세부 높이 h4 (mm) — 측구수로관 h4',
    dim_t1        DECIMAL(8,1) NULL COMMENT '세부 두께 t1 (mm) — 측구수로관 t1·T1·U형 플륨 T''',
    dim_t3        DECIMAL(8,1) NULL COMMENT '세부 두께 t3 (mm) — 측구수로관 t3·T3',
    dim_t4        DECIMAL(8,1) NULL COMMENT '세부 두께 t4 (mm) — 측구수로관 t4',
    dim_l1        DECIMAL(8,1) NULL COMMENT '세부 길이 l1 (mm) — 측구수로관 l1·흄관/VR관 l1·RDG L1·원심력수로관 l1·암거 삽입구 ℓ1·U형 플륨 L''',
    dim_l2        DECIMAL(8,1) NULL COMMENT '세부 길이 l2 (mm) — 측구수로관 l2·흄관/VR관 l2·RDG L2·암거 수구 ℓ2',
    dim_l3        DECIMAL(8,1) NULL COMMENT '세부 길이 l3 (mm) — 흄관/VR관 l3·RDG L3',
    dim_l4        DECIMAL(8,1) NULL COMMENT '세부 길이 l4 (mm) — 흄관 l4·VR관 L4',
    dim_l5        DECIMAL(8,1) NULL COMMENT '세부 길이 l5 (mm) — 흄관 l5·VR관 L5',
    dim_l6        DECIMAL(8,1) NULL COMMENT '세부 길이 l6 (mm) — VR관 L6',
    dim_d1        DECIMAL(8,1) NULL COMMENT '세부 지름 D1 (mm) — 흄관 (D)D1·VR관 D1',
    dim_d2        DECIMAL(8,1) NULL COMMENT '세부 지름 D2 (mm) — 흄관·VR관 D2',
    dim_d3        DECIMAL(8,1) NULL COMMENT '세부 지름 D3 (mm) — 흄관·VR관 D3',
    dim_d4        DECIMAL(8,1) NULL COMMENT '세부 지름 D4 (mm) — 흄관·VR관 D4',
    dim_d5        DECIMAL(8,1) NULL COMMENT '세부 지름 D5 (mm) — VR관 D5',
    dim_r1        DECIMAL(8,1) NULL COMMENT '반지름 r·R (mm) — 벤치플륨Ⅱ r·U형 플륨 R',
    dim_r2        DECIMAL(8,1) NULL COMMENT '반지름 R'' (mm) — U형 플륨 R''',
    dim_c         DECIMAL(8,1) NULL COMMENT '헌치 높이 C (mm) — 암거·개거 C',
    dim_x1        DECIMAL(8,1) NULL COMMENT '기타 도면 기호 1 (mm) — 벤치플륨Ⅲ m·환경수로관 f·알루미늄트렌치 개구부·원심력수로관 타공 m',
    dim_x2        DECIMAL(8,1) NULL COMMENT '기타 도면 기호 2 (mm) — 벤치플륨Ⅲ i·환경수로관 g·원심력수로관 타공 n',
    dim_x3        DECIMAL(8,1) NULL COMMENT '기타 도면 기호 3 (mm) — 예비(대응표에 품목별 뜻 기록 후 사용)',
    dim_x4        DECIMAL(8,1) NULL COMMENT '기타 도면 기호 4 (mm) — 예비(대응표에 품목별 뜻 기록 후 사용)',
    dim_mesh      DECIMAL(8,1) NULL COMMENT '망눈 간격 (mm) — 용접철망 150×150 의 150',
    dim_wire_d    DECIMAL(8,1) NULL COMMENT '선경(철선 지름) (mm) — 용접철망 3.2',
    spec_wire_no  SMALLINT UNSIGNED NULL COMMENT '철선 품번 — 용접철망 #10 의 10',
    dim_d_inlet   VARCHAR(20)  NULL COMMENT '유입측 지름(글자 값) — 내충격 PVC오수받이',
    dim_d_outlet  VARCHAR(20)  NULL COMMENT '유출측 지름(글자 값) — 내충격 PVC오수받이',
    spec_option   VARCHAR(160) NULL COMMENT '치수·모델코드 외 나머지(그레이팅 규격, 매립식/이동식/앙카식, 재질, 구분 등)',
    spec_dims_src VARCHAR(180) NULL COMMENT '치수 칸을 채울 때 기준이 된 규격 원문 — 규격이 바뀌면 다시 채울 때까지 화면은 원문 사용',
    unit          VARCHAR(20)  NULL COMMENT '판매 단위(예: 본, EA, m²)',
    weight_kg     DECIMAL(8,2) NULL COMMENT '중량 kg(카탈로그 중량·참고중량, t 표기는 ×1000)',
    description   TEXT NULL,
    price_b2c     INT UNSIGNED NOT NULL DEFAULT 0,
    price_b2b     INT UNSIGNED NOT NULL DEFAULT 0,
    stock_qty     INT NOT NULL DEFAULT 0,
    external_source VARCHAR(40) NULL             COMMENT '출처(예: syccrt) — external_id 와 함께 다시 올릴 때 같은 상품 찾기',
    external_id   VARCHAR(80)  NULL              COMMENT '출처 상품 식별값(엑셀 행 코드 등)',
    source_url    VARCHAR(255) NULL              COMMENT '출처 페이지 주소(참고)',
    image_file    VARCHAR(255) NULL              COMMENT '대표 이미지(상세 화면용 큰 이미지) 파일 경로(웹루트 기준 상대 경로) — 일괄 등록이 이미지 ZIP 에서 꺼내 저장',
    image_thumb_file VARCHAR(255) NULL           COMMENT '썸네일(목록·관련상품용 작은 이미지) 파일 경로(웹루트 기준 상대 경로) — ZIP 의 …_thumb 파일, 없으면 NULL(화면은 기존 빈 이미지 표시)',
    is_active     TINYINT(1) NOT NULL DEFAULT 0  COMMENT '일괄 등록은 비활성(0)으로 저장 → 확인 뒤 활성(1)',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    cost_price    INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '공장도가(원가) 관리자전용',
    INDEX idx_syc_products_category (category_id),
    INDEX idx_syc_products_name (name),
    UNIQUE KEY uq_syc_products_external (external_source, external_id),
    CONSTRAINT fk_syc_products_category FOREIGN KEY (category_id) REFERENCES syc_categories(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='상용콘크리트(syccrt.com) 상품 — products 와 별개, 일괄 등록(엑셀+이미지 ZIP) 대상';

-- [3] 확인(읽기 전용) ----------------------------------------------------------
-- SHOW CREATE TABLE syc_products;
-- SELECT COUNT(*) FROM syc_products;   -- 0 (행 없음)

-- [롤백] 되돌리기 — 새 표만 지운다(기존 표·데이터 영향 없음). 승인 후에만
-- DROP TABLE syc_products;
