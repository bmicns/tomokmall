-- ─── 상용콘크리트(syccrt.com) 상품용 카테고리 표: syc_categories ──────────────────────────────
-- 목적: 상용콘크리트 상품(새 상품 표 syc_products — database/20261110_syc_products.sql)을 일괄 등록할 때 쓸 전용 카테고리.
--       기존 categories(케이토목 기준·사이트 메뉴)와 별개. 기존 표·데이터는 읽기만 한다([5] 마진율 참조).
-- 구조: categories 와 같은 칸·순서(id·parent_id·name·description·slug·sort_order·is_active·created_at
--       ·discount_customer·discount_corporate). 마진율 칸 정의·주석도 운영 categories 와 같다(2026-11-10 운영 DESCRIBE).
-- 데이터 원천: 상용콘크리트(주) 카탈로그(2025, syccrt.com 자료실 「카다로그.pdf」) 판독본(spec-viewer, 2026-11-10)
--       대분류 9개(slug syc-g1~g9) → 카탈로그 품목(slug syc-s<n>, 판독본 구분자 s<n> 과 같은 번호).
--       규격표 제목은 제품 이름으로 정리(사용자 요청 2026-11-10): s72~s74 '○○ 경계석 규격표 (단위…)' → '○○ 경계석',
--       s76 '파형강관 규격표' → '파형강관', s77~s81 용접철망 규격표 5개 → '용접철망' 1개(syc-s77, s78~s81 은 만들지 않음).
--     + 웹사이트 전용 품목(카탈로그에 없음, 사용자 제공 화면 캡처 2026-11-10) — slug syc-w1~w4
--       건널목수로관 → 수로관·플륨관 아래 / 볼라드 대분류(syc-g10) → 스텐볼라드 - HK·탄성볼라드 - HK·석재볼라드 - SCG
--       합계: 대분류 10개 + 품목 81개 = 91행.
-- 마진율 값: 기존 categories 에서 복사([5] — 값을 이 파일에 적지 않고 실행 시점의 운영 값을 참조).
--       2026-11-10 운영 조회: categories 245행 모두 discount_customer 20 / discount_corporate 10.
-- 사용 코드: 없음(만들어 두기만 함 — 사용자 결정 2026-11-10). 사이트 메뉴·검색·가격 계산(app/pricing.php)에 영향 없음.
-- 성격: 추가형(additive). 새 표 1개 + 새 표에만 행. 기존 categories 는 SELECT 만(변경 없음).
-- ⚠ 가격 보호(CLAUDE.md 12장): 이 표는 마진율 칸이 있어 원격 관리 API 가 [5](마진율 쓰기)를 막는다.
--    → 사용자 승인 후 phpMyAdmin 에서 실행. 기존 가격 데이터는 바뀌지 않는다.
-- 실행 순서: [1] 사전 확인 → [2] 표 생성 → [3] 대분류 → [4] 품목 → [5] 마진율 → [6] 확인. 다시 실행해도 중복 없음(slug 기준).
-- 되돌리기: 맨 아래 [롤백] (새 표만 지움 — 기존 데이터 영향 없음)

-- [1] 사전 확인(읽기 전용) ------------------------------------------------------
-- 1-1) 0 이면 새로 만듦, 1 이면 이미 있음([2]는 IF NOT EXISTS 라 그대로 실행해도 됨)
SELECT COUNT(*) AS syc_categories_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syc_categories';
-- 1-2) [5] 마진율 참조 대상: 9행이 나와야 한다(이름이 다르면 그 대분류는 복사 안 함 → [6] 6-3 에서 드러남)
SELECT id, slug, name, discount_customer, discount_corporate FROM categories
WHERE slug IN ('civil-concrete', 'civil-pepvc', 'civil-rc', 'civil-steel', 'mid11--10', 'mid11--20', 'mid11--40', 'mid11--50', 'mid13--50') ORDER BY id;

-- [2] 표 생성 ----------------------------------------------------------------
-- parent_id 는 categories 처럼 외래키 없이 색인만 둔다(NULL = 대분류).
CREATE TABLE IF NOT EXISTS syc_categories (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id          INT UNSIGNED NULL                COMMENT 'syc_categories.id — NULL 이면 대분류',
    name               VARCHAR(100) NOT NULL            COMMENT '카테고리 이름',
    description        VARCHAR(200) NULL                COMMENT '설명(categories.description 과 같은 용도)',
    slug               VARCHAR(120) NOT NULL UNIQUE     COMMENT 'syc-g<n> 대분류 / syc-s<n> 카탈로그 품목(판독본 구분자 s<n>) / syc-w<n> 웹사이트 전용 품목',
    sort_order         INT NOT NULL DEFAULT 0,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    created_at         TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    discount_customer  TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '개인회원 할인율(%) 하위상속',
    discount_corporate TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '기업회원 할인율(%) 0~90, 하위 카테고리 상속',
    INDEX idx_syc_categories_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='상용콘크리트(syccrt.com) 상품 전용 카테고리 — categories 와 별개';

-- [3] 대분류 10개 --------------------------------------------------------------
INSERT INTO syc_categories (parent_id, name, slug, sort_order, is_active)
SELECT NULL, v.name, v.slug, v.sort_order, 1
FROM (
              SELECT '측구수로관' AS name, 'syc-g1' AS slug, 10 AS sort_order
    UNION ALL SELECT '수로관·플륨관', 'syc-g2', 20
    UNION ALL SELECT '흄관·V·R관', 'syc-g3', 30
    UNION ALL SELECT '암거', 'syc-g4', 40
    UNION ALL SELECT '맨홀·기초', 'syc-g5', 50
    UNION ALL SELECT 'PE·PVC관', 'syc-g6', 60
    UNION ALL SELECT '그레이팅·주철뚜껑', 'syc-g7', 70
    UNION ALL SELECT '보도블록·옹벽·경계석', 'syc-g8', 80
    UNION ALL SELECT '보호틀·파형강관·철망', 'syc-g9', 90
    UNION ALL SELECT '볼라드', 'syc-g10', 100
) v
WHERE NOT EXISTS (SELECT 1 FROM syc_categories x WHERE x.slug = v.slug);

-- [4] 품목 81개 (대분류 slug 로 parent_id 연결) ----------------------------------
INSERT INTO syc_categories (parent_id, name, slug, sort_order, is_active)
SELECT p.id, v.name, v.slug, v.sort_order, 1
FROM (
              SELECT 'syc-g1' AS parent_slug, '측구수로관 앵글미부착 A형 (200A,250A)' AS name, 'syc-s1' AS slug, 10 AS sort_order
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글미부착 A형 (300A,400A)', 'syc-s2', 20
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글미부착 A형 (300*400A,500A,600A)', 'syc-s3', 30
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글부착 AN형 (300AN, 400AN)', 'syc-s4', 40
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글부착 B형 (200B, 250B, 300B)', 'syc-s5', 50
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글부착 B형 (300*400B, 300*600B, 400B, 500B, 600B, 800B, 1000B)', 'syc-s6', 60
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글부착 (Z)-주문품', 'syc-s7', 70
    UNION ALL SELECT 'syc-g1', '유공 침투측구수로관 및 집수정', 'syc-s8', 80
    UNION ALL SELECT 'syc-g1', '그레이팅 높이조절용 측구수로관-주문품', 'syc-s9', 90
    UNION ALL SELECT 'syc-g2', '벤치플륨 Ⅱ', 'syc-s10', 10
    UNION ALL SELECT 'syc-g2', '벤치플륨 Ⅲ', 'syc-s11', 20
    UNION ALL SELECT 'syc-g2', 'U형 플륨', 'syc-s12', 30
    UNION ALL SELECT 'syc-g2', '환경수로관', 'syc-s13', 40
    UNION ALL SELECT 'syc-g2', '원형수로관 (NS-01, NS-02, NS-03)', 'syc-s14', 50
    UNION ALL SELECT 'syc-g2', '원형사각수로관(RDG)', 'syc-s15', 60
    UNION ALL SELECT 'syc-g2', '사각수로관', 'syc-s16', 70
    UNION ALL SELECT 'syc-g2', '원심력수로관 (W2 / S2 / 무개수로관 / 유개수로관)', 'syc-s17', 80
    UNION ALL SELECT 'syc-g2', '알루미늄트렌치(물도랑,하나로)', 'syc-s18', 90
    UNION ALL SELECT 'syc-g3', '흄관 (원심력관)', 'syc-s19', 10
    UNION ALL SELECT 'syc-g3', 'V·R관 (진동관)', 'syc-s20', 20
    UNION ALL SELECT 'syc-g4', '암거 1련', 'syc-s21', 10
    UNION ALL SELECT 'syc-g4', '암거2련', 'syc-s22', 20
    UNION ALL SELECT 'syc-g4', '개거수로', 'syc-s23', 30
    UNION ALL SELECT 'syc-g5', '사각맨홀(집수정용)', 'syc-s24', 10
    UNION ALL SELECT 'syc-g5', '콘크리트 빗물받이 (집수정용)', 'syc-s25', 20
    UNION ALL SELECT 'syc-g5', '원형맨홀(1,2,3,4호)', 'syc-s26', 30
    UNION ALL SELECT 'syc-g5', '맨홀 안전 사다리', 'syc-s27', 40
    UNION ALL SELECT 'syc-g5', '조립식 맨홀 (상부구체 / 연직구체 / 하부구체)', 'syc-s28', 50
    UNION ALL SELECT 'syc-g5', '전기통신 맨홀·전기통신 핸드홀', 'syc-s29', 60
    UNION ALL SELECT 'syc-g5', '통신맨홀(수공 1, 2, 3호)', 'syc-s30', 70
    UNION ALL SELECT 'syc-g5', '가로등 기초', 'syc-s31', 80
    UNION ALL SELECT 'syc-g6', '고강성 PVC 이중벽관', 'syc-s32', 10
    UNION ALL SELECT 'syc-g6', 'PE 이중벽관', 'syc-s33', 20
    UNION ALL SELECT 'syc-g6', 'PE 삼중벽관', 'syc-s34', 30
    UNION ALL SELECT 'syc-g6', 'PVC 파이프 VG1, VG2', 'syc-s35', 40
    UNION ALL SELECT 'syc-g6', '내충격 PVC오수받이', 'syc-s36', 50
    UNION ALL SELECT 'syc-g6', 'HDPE DC 고압관 (유공,무공)', 'syc-s37', 60
    UNION ALL SELECT 'syc-g6', '고압유공관', 'syc-s38', 70
    UNION ALL SELECT 'syc-g6', 'THP관 (무공/유공)', 'syc-s39', 80
    UNION ALL SELECT 'syc-g6', '파상형 경질 폴리에틸렌전선관 ELP (KS C 8455)', 'syc-s40', 90
    UNION ALL SELECT 'syc-g6', 'PE 빗물받이(차도측)', 'syc-s41', 100
    UNION ALL SELECT 'syc-g6', 'PE 오수받이', 'syc-s42', 110
    UNION ALL SELECT 'syc-g6', '다용도 빗물받이', 'syc-s43', 120
    UNION ALL SELECT 'syc-g6', '가정용 빗물받이', 'syc-s44', 130
    UNION ALL SELECT 'syc-g6', 'PE 홈통받이', 'syc-s45', 140
    UNION ALL SELECT 'syc-g6', 'PE 배수로', 'syc-s46', 150
    UNION ALL SELECT 'syc-g6', 'PE 집수정', 'syc-s47', 160
    UNION ALL SELECT 'syc-g6', 'PE 부속품', 'syc-s48', 170
    UNION ALL SELECT 'syc-g7', '그레이팅', 'syc-s49', 10
    UNION ALL SELECT 'syc-g7', '그레이팅, 디자인그레이팅', 'syc-s50', 20
    UNION ALL SELECT 'syc-g7', '단체표준 주철 COVER — 오수·우수 맨홀 / 전기·통신 맨홀 / 볼트 잠금 맨홀 뚜껑', 'syc-s51', 30
    UNION ALL SELECT 'syc-g7', '기타 주철 COVER', 'syc-s52', 40
    UNION ALL SELECT 'syc-g7', '주철 COVER', 'syc-s53', 50
    UNION ALL SELECT 'syc-g8', '인조화강블록 투수/불투수 (200*200)', 'syc-s54', 10
    UNION ALL SELECT 'syc-g8', '인조화강블록 투수/불투수 (200*100)', 'syc-s55', 20
    UNION ALL SELECT 'syc-g8', 'U-블록', 'syc-s56', 30
    UNION ALL SELECT 'syc-g8', 'I₂-블록', 'syc-s57', 40
    UNION ALL SELECT 'syc-g8', '점자유도 블록', 'syc-s58', 50
    UNION ALL SELECT 'syc-g8', '고강도 점자유도블록', 'syc-s59', 60
    UNION ALL SELECT 'syc-g8', '그린잔디블록', 'syc-s60', 70
    UNION ALL SELECT 'syc-g8', '잔디블록 (I형)', 'syc-s61', 80
    UNION ALL SELECT 'syc-g8', '사각보도블록', 'syc-s62', 90
    UNION ALL SELECT 'syc-g8', '보강토 옹벽블록', 'syc-s63', 100
    UNION ALL SELECT 'syc-g8', '보강토 옹벽 마감블록', 'syc-s64', 110
    UNION ALL SELECT 'syc-g8', '계단형 블록', 'syc-s65', 120
    UNION ALL SELECT 'syc-g8', '대형 잔디블록', 'syc-s66', 130
    UNION ALL SELECT 'syc-g8', '보강토 식생 축조블록', 'syc-s67', 140
    UNION ALL SELECT 'syc-g8', 'I형 호안 블록', 'syc-s68', 150
    UNION ALL SELECT 'syc-g8', '장대 블록', 'syc-s69', 160
    UNION ALL SELECT 'syc-g8', '게다 블록', 'syc-s70', 170
    UNION ALL SELECT 'syc-g8', '녹지 경계 블록', 'syc-s71', 180
    UNION ALL SELECT 'syc-g8', '콘크리트 경계석', 'syc-s72', 190
    UNION ALL SELECT 'syc-g8', '인조 경계석', 'syc-s73', 200
    UNION ALL SELECT 'syc-g8', '화강 경계석', 'syc-s74', 210
    UNION ALL SELECT 'syc-g9', '가로수보호틀', 'syc-s75', 10
    UNION ALL SELECT 'syc-g9', '파형강관', 'syc-s76', 20
    UNION ALL SELECT 'syc-g9', '용접철망', 'syc-s77', 30
    -- 웹사이트 전용 품목(카탈로그에 없음)
    UNION ALL SELECT 'syc-g2', '건널목수로관', 'syc-w1', 100
    UNION ALL SELECT 'syc-g10', '스텐볼라드 - HK', 'syc-w2', 10
    UNION ALL SELECT 'syc-g10', '탄성볼라드 - HK', 'syc-w3', 20
    UNION ALL SELECT 'syc-g10', '석재볼라드 - SCG', 'syc-w4', 30
) v
JOIN syc_categories p ON p.slug = v.parent_slug AND p.parent_id IS NULL
WHERE NOT EXISTS (SELECT 1 FROM syc_categories x WHERE x.slug = v.slug);

-- [5] 마진율 — 기존 categories 값 복사(대분류와 그 품목 모두) ------------------------
-- 대분류마다 가장 가까운 기존 카테고리를 slug + 이름으로 찾는다(둘 다 맞을 때만). 기존 categories 는 읽기만 한다.
-- 아직 0/0 인 행만 채운다(다시 실행해도 나중에 바꾼 값은 덮어쓰지 않음).
UPDATE syc_categories s
JOIN syc_categories g ON g.id = COALESCE(s.parent_id, s.id)
JOIN (
              SELECT 'syc-g1' AS syc_slug, 'mid11--20' AS ref_slug, '측구수로관' AS ref_name   -- 측구수로관 ← 측구수로관
    UNION ALL SELECT 'syc-g2', 'mid11--10', '플륨관/수로관'   -- 수로관·플륨관 ← 벤치플륨·U형플륨·환경수로관·건널목수로관
    UNION ALL SELECT 'syc-g3', 'mid11--50', '기타'   -- 흄관·V·R관 ← 흄관(A·B형)·VR관이 있는 철근콘크리트제품 > 기타
    UNION ALL SELECT 'syc-g4', 'civil-rc', '철근콘크리트제품'   -- 암거 ← 1련암거·개거(2련암거 카테고리 없음)
    UNION ALL SELECT 'syc-g5', 'mid11--40', '맨홀/집수정'   -- 맨홀·기초 ← 사각·원형·조립식·전기·통신 맨홀
    UNION ALL SELECT 'syc-g6', 'civil-pepvc', 'PE/PVC제품'   -- PE·PVC관 ← PE·PVC관·빗물받이·오수받이
    UNION ALL SELECT 'syc-g7', 'civil-steel', '스틸제품'   -- 그레이팅·주철뚜껑 ← 그레이팅·주철뚜껑
    UNION ALL SELECT 'syc-g8', 'civil-concrete', '콘크리트제품'   -- 보도블록·옹벽·경계석 ← 보도블록·보강토·잔디·경계블록
    UNION ALL SELECT 'syc-g9', 'civil-steel', '스틸제품'   -- 보호틀·파형강관·철망 ← 가로수보호틀·파형강관·와이어메쉬
    UNION ALL SELECT 'syc-g10', 'mid13--50', '기타 철재'   -- 볼라드 ← 스텐볼라드가 있는 스틸제품 > 기타 철재
) m ON m.syc_slug = g.slug
JOIN categories c ON c.slug = m.ref_slug AND c.name = m.ref_name
SET s.discount_customer = c.discount_customer, s.discount_corporate = c.discount_corporate
WHERE s.discount_customer = 0 AND s.discount_corporate = 0;

-- [6] 확인(읽기 전용) ----------------------------------------------------------
-- 6-1) SELECT COUNT(*) FROM syc_categories;                                   -- 91 (대분류 10 + 품목 81)
-- 6-2) SELECT p.name, COUNT(c.id) FROM syc_categories p LEFT JOIN syc_categories c ON c.parent_id = p.id
--       WHERE p.parent_id IS NULL GROUP BY p.id, p.name ORDER BY p.sort_order;  -- 10행, 합계 81
-- 6-3) SELECT discount_customer, discount_corporate, COUNT(*) FROM syc_categories GROUP BY 1, 2;  -- 0/0 행이 없어야 함

-- [롤백] 되돌리기 — 새 표만 지운다(기존 표·데이터 영향 없음). 승인 후에만
-- DROP TABLE syc_categories;
