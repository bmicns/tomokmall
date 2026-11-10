-- ─── 상용콘크리트(syccrt.com) 상품용 카테고리 표: syc_categories ──────────────────────────────
-- 목적: 상용콘크리트 상품(새 상품 표 syc_products — 다음 단계)을 일괄 등록할 때 쓸 전용 카테고리.
--       기존 categories(케이토목 기준·사이트 메뉴·마진율)와 완전히 별개. 기존 표·데이터는 건드리지 않는다.
-- 구조: categories 를 참조(id·parent_id·name·description·slug·sort_order·is_active·created_at 동일)
--       + source_page(카탈로그 쪽 번호). 마진율 칸(discount_customer·discount_corporate)은 넣지 않는다
--       (가격 정책은 상품 표 단계에서 별도 결정 — 필요하면 ADD COLUMN 으로 추가).
-- 데이터 원천: 상용콘크리트(주) 카탈로그(2025, syccrt.com 자료실 「카다로그.pdf」) 판독본(spec-viewer, 2026-11-10)
--       2단계 — 대분류 9개(slug syc-g1~g9) → 품목 81개(slug syc-s1~s81, 판독본 구분자 s1~s81 과 같은 번호).
--       품목 이름은 판독본 제목 원문 그대로. 웹사이트 전용 품목(건널목수로관·볼라드 등)은 없음 → 사용자가 따로 추가 예정.
-- 사용 코드: 없음(만들어 두기만 함 — 사용자 결정 2026-11-10). 사이트 메뉴·검색·가격 계산에 영향 없음.
-- 성격: 추가형(additive). 새 표 1개 + 새 표에만 행 90개. 가격 칸 없음(가격 보호 정책 대상 아님).
-- 실행: 사용자 승인 후 [1] 사전 확인 → [2] 표 생성 → [3] 대분류 → [4] 품목 → [5] 확인. 다시 실행해도 중복 없음(slug 기준).
-- 되돌리기: 맨 아래 [롤백] (새 표만 지움 — 기존 데이터 영향 없음)

-- [1] 사전 확인(읽기 전용) ------------------------------------------------------
-- 0 이면 새로 만듦, 1 이면 이미 있음([2]는 IF NOT EXISTS 라 그대로 실행해도 됨)
SELECT COUNT(*) AS syc_categories_exists
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syc_categories';

-- [2] 표 생성 ----------------------------------------------------------------
-- parent_id 는 categories 처럼 외래키 없이 색인만 둔다(NULL = 대분류).
CREATE TABLE IF NOT EXISTS syc_categories (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    parent_id   INT UNSIGNED NULL                     COMMENT 'syc_categories.id — NULL 이면 대분류',
    name        VARCHAR(100) NOT NULL                 COMMENT '카테고리 이름(카탈로그 판독본 원문)',
    description VARCHAR(200) NULL                     COMMENT '설명(categories.description 과 같은 용도)',
    slug        VARCHAR(120) NOT NULL UNIQUE          COMMENT 'syc-g<n> 대분류 / syc-s<n> 품목(판독본 구분자 s<n>)',
    sort_order  INT NOT NULL DEFAULT 0,
    is_active   TINYINT(1) NOT NULL DEFAULT 1,
    source_page SMALLINT UNSIGNED NULL                COMMENT '카탈로그(2025) 쪽 번호 — 대분류는 첫 품목 쪽',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_syc_categories_parent (parent_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
  COMMENT='상용콘크리트(syccrt.com) 상품 전용 카테고리 — categories 와 별개';

-- [3] 대분류 9개 --------------------------------------------------------------
INSERT INTO syc_categories (parent_id, name, slug, sort_order, is_active, source_page)
SELECT NULL, v.name, v.slug, v.sort_order, 1, v.source_page
FROM (
              SELECT '측구수로관' AS name, 'syc-g1' AS slug, 10 AS sort_order, 2 AS source_page
    UNION ALL SELECT '수로관·플륨관', 'syc-g2', 20, 6
    UNION ALL SELECT '흄관·V·R관', 'syc-g3', 30, 13
    UNION ALL SELECT '암거', 'syc-g4', 40, 14
    UNION ALL SELECT '맨홀·기초', 'syc-g5', 50, 16
    UNION ALL SELECT 'PE·PVC관', 'syc-g6', 60, 20
    UNION ALL SELECT '그레이팅·주철뚜껑', 'syc-g7', 70, 26
    UNION ALL SELECT '보도블록·옹벽·경계석', 'syc-g8', 80, 30
    UNION ALL SELECT '보호틀·파형강관·철망', 'syc-g9', 90, 35
) v
WHERE NOT EXISTS (SELECT 1 FROM syc_categories x WHERE x.slug = v.slug);

-- [4] 품목 81개 (대분류 slug 로 parent_id 연결) ----------------------------------
INSERT INTO syc_categories (parent_id, name, slug, sort_order, is_active, source_page)
SELECT p.id, v.name, v.slug, v.sort_order, 1, v.source_page
FROM (
              SELECT 'syc-g1' AS parent_slug, '측구수로관 앵글미부착 A형 (200A,250A)' AS name, 'syc-s1' AS slug, 10 AS sort_order, 2 AS source_page
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글미부착 A형 (300A,400A)', 'syc-s2', 20, 2
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글미부착 A형 (300*400A,500A,600A)', 'syc-s3', 30, 3
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글부착 AN형 (300AN, 400AN)', 'syc-s4', 40, 3
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글부착 B형 (200B, 250B, 300B)', 'syc-s5', 50, 4
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글부착 B형 (300*400B, 300*600B, 400B, 500B, 600B, 800B, 1000B)', 'syc-s6', 60, 4
    UNION ALL SELECT 'syc-g1', '측구수로관 앵글부착 (Z)-주문품', 'syc-s7', 70, 5
    UNION ALL SELECT 'syc-g1', '유공 침투측구수로관 및 집수정', 'syc-s8', 80, 5
    UNION ALL SELECT 'syc-g1', '그레이팅 높이조절용 측구수로관-주문품', 'syc-s9', 90, 5
    UNION ALL SELECT 'syc-g2', '벤치플륨 Ⅱ', 'syc-s10', 10, 6
    UNION ALL SELECT 'syc-g2', '벤치플륨 Ⅲ', 'syc-s11', 20, 6
    UNION ALL SELECT 'syc-g2', 'U형 플륨', 'syc-s12', 30, 7
    UNION ALL SELECT 'syc-g2', '환경수로관', 'syc-s13', 40, 7
    UNION ALL SELECT 'syc-g2', '원형수로관 (NS-01, NS-02, NS-03)', 'syc-s14', 50, 8
    UNION ALL SELECT 'syc-g2', '원형사각수로관(RDG)', 'syc-s15', 60, 9
    UNION ALL SELECT 'syc-g2', '사각수로관', 'syc-s16', 70, 10
    UNION ALL SELECT 'syc-g2', '원심력수로관 (W2 / S2 / 무개수로관 / 유개수로관)', 'syc-s17', 80, 11
    UNION ALL SELECT 'syc-g2', '알루미늄트렌치(물도랑,하나로)', 'syc-s18', 90, 12
    UNION ALL SELECT 'syc-g3', '흄관 (원심력관)', 'syc-s19', 10, 13
    UNION ALL SELECT 'syc-g3', 'V·R관 (진동관)', 'syc-s20', 20, 13
    UNION ALL SELECT 'syc-g4', '암거 1련', 'syc-s21', 10, 14
    UNION ALL SELECT 'syc-g4', '암거2련', 'syc-s22', 20, 15
    UNION ALL SELECT 'syc-g4', '개거수로', 'syc-s23', 30, 15
    UNION ALL SELECT 'syc-g5', '사각맨홀(집수정용)', 'syc-s24', 10, 16
    UNION ALL SELECT 'syc-g5', '콘크리트 빗물받이 (집수정용)', 'syc-s25', 20, 16
    UNION ALL SELECT 'syc-g5', '원형맨홀(1,2,3,4호)', 'syc-s26', 30, 17
    UNION ALL SELECT 'syc-g5', '맨홀 안전 사다리', 'syc-s27', 40, 17
    UNION ALL SELECT 'syc-g5', '조립식 맨홀 (상부구체 / 연직구체 / 하부구체)', 'syc-s28', 50, 18
    UNION ALL SELECT 'syc-g5', '전기통신 맨홀·전기통신 핸드홀', 'syc-s29', 60, 19
    UNION ALL SELECT 'syc-g5', '통신맨홀(수공 1, 2, 3호)', 'syc-s30', 70, 19
    UNION ALL SELECT 'syc-g5', '가로등 기초', 'syc-s31', 80, 19
    UNION ALL SELECT 'syc-g6', '고강성 PVC 이중벽관', 'syc-s32', 10, 20
    UNION ALL SELECT 'syc-g6', 'PE 이중벽관', 'syc-s33', 20, 21
    UNION ALL SELECT 'syc-g6', 'PE 삼중벽관', 'syc-s34', 30, 21
    UNION ALL SELECT 'syc-g6', 'PVC 파이프 VG1, VG2', 'syc-s35', 40, 22
    UNION ALL SELECT 'syc-g6', '내충격 PVC오수받이', 'syc-s36', 50, 22
    UNION ALL SELECT 'syc-g6', 'HDPE DC 고압관 (유공,무공)', 'syc-s37', 60, 23
    UNION ALL SELECT 'syc-g6', '고압유공관', 'syc-s38', 70, 23
    UNION ALL SELECT 'syc-g6', 'THP관 (무공/유공)', 'syc-s39', 80, 23
    UNION ALL SELECT 'syc-g6', '파상형 경질 폴리에틸렌전선관 ELP (KS C 8455)', 'syc-s40', 90, 23
    UNION ALL SELECT 'syc-g6', 'PE 빗물받이(차도측)', 'syc-s41', 100, 24
    UNION ALL SELECT 'syc-g6', 'PE 오수받이', 'syc-s42', 110, 24
    UNION ALL SELECT 'syc-g6', '다용도 빗물받이', 'syc-s43', 120, 24
    UNION ALL SELECT 'syc-g6', '가정용 빗물받이', 'syc-s44', 130, 24
    UNION ALL SELECT 'syc-g6', 'PE 홈통받이', 'syc-s45', 140, 24
    UNION ALL SELECT 'syc-g6', 'PE 배수로', 'syc-s46', 150, 25
    UNION ALL SELECT 'syc-g6', 'PE 집수정', 'syc-s47', 160, 25
    UNION ALL SELECT 'syc-g6', 'PE 부속품', 'syc-s48', 170, 25
    UNION ALL SELECT 'syc-g7', '그레이팅', 'syc-s49', 10, 26
    UNION ALL SELECT 'syc-g7', '그레이팅, 디자인그레이팅', 'syc-s50', 20, 27
    UNION ALL SELECT 'syc-g7', '단체표준 주철 COVER — 오수·우수 맨홀 / 전기·통신 맨홀 / 볼트 잠금 맨홀 뚜껑', 'syc-s51', 30, 28
    UNION ALL SELECT 'syc-g7', '기타 주철 COVER', 'syc-s52', 40, 28
    UNION ALL SELECT 'syc-g7', '주철 COVER', 'syc-s53', 50, 29
    UNION ALL SELECT 'syc-g8', '인조화강블록 투수/불투수 (200*200)', 'syc-s54', 10, 30
    UNION ALL SELECT 'syc-g8', '인조화강블록 투수/불투수 (200*100)', 'syc-s55', 20, 30
    UNION ALL SELECT 'syc-g8', 'U-블록', 'syc-s56', 30, 30
    UNION ALL SELECT 'syc-g8', 'I₂-블록', 'syc-s57', 40, 30
    UNION ALL SELECT 'syc-g8', '점자유도 블록', 'syc-s58', 50, 31
    UNION ALL SELECT 'syc-g8', '고강도 점자유도블록', 'syc-s59', 60, 31
    UNION ALL SELECT 'syc-g8', '그린잔디블록', 'syc-s60', 70, 31
    UNION ALL SELECT 'syc-g8', '잔디블록 (I형)', 'syc-s61', 80, 31
    UNION ALL SELECT 'syc-g8', '사각보도블록', 'syc-s62', 90, 31
    UNION ALL SELECT 'syc-g8', '보강토 옹벽블록', 'syc-s63', 100, 32
    UNION ALL SELECT 'syc-g8', '보강토 옹벽 마감블록', 'syc-s64', 110, 32
    UNION ALL SELECT 'syc-g8', '계단형 블록', 'syc-s65', 120, 32
    UNION ALL SELECT 'syc-g8', '대형 잔디블록', 'syc-s66', 130, 32
    UNION ALL SELECT 'syc-g8', '보강토 식생 축조블록', 'syc-s67', 140, 32
    UNION ALL SELECT 'syc-g8', 'I형 호안 블록', 'syc-s68', 150, 33
    UNION ALL SELECT 'syc-g8', '장대 블록', 'syc-s69', 160, 33
    UNION ALL SELECT 'syc-g8', '게다 블록', 'syc-s70', 170, 33
    UNION ALL SELECT 'syc-g8', '녹지 경계 블록', 'syc-s71', 180, 33
    UNION ALL SELECT 'syc-g8', '콘크리트 경계석 규격표 (단위: mm, 중량 단위 표기 없음)', 'syc-s72', 190, 34
    UNION ALL SELECT 'syc-g8', '인조 경계석 규격표 (단위: mm)', 'syc-s73', 200, 34
    UNION ALL SELECT 'syc-g8', '화강 경계석 규격표 (단위: mm)', 'syc-s74', 210, 34
    UNION ALL SELECT 'syc-g9', '가로수보호틀', 'syc-s75', 10, 35
    UNION ALL SELECT 'syc-g9', '파형강관 규격표', 'syc-s76', 20, 36
    UNION ALL SELECT 'syc-g9', '용접철망의 종류 및 적용 선 지름 (1)', 'syc-s77', 30, 37
    UNION ALL SELECT 'syc-g9', '원형철선의 선 지름 허용차', 'syc-s78', 40, 37
    UNION ALL SELECT 'syc-g9', '용접철망의 종류 및 적용 선 지름 (2) — 기계적 성질', 'syc-s79', 50, 37
    UNION ALL SELECT 'syc-g9', '망목치수, 허용차', 'syc-s80', 60, 37
    UNION ALL SELECT 'syc-g9', '용접철망의 종류 및 적용 선 지름 (3) — 품번별 규격', 'syc-s81', 70, 37
) v
JOIN syc_categories p ON p.slug = v.parent_slug AND p.parent_id IS NULL
WHERE NOT EXISTS (SELECT 1 FROM syc_categories x WHERE x.slug = v.slug);

-- [5] 확인(읽기 전용) ----------------------------------------------------------
-- SELECT COUNT(*) FROM syc_categories;                                   -- 90 (대분류 9 + 품목 81)
-- SELECT p.name, COUNT(c.id) FROM syc_categories p LEFT JOIN syc_categories c ON c.parent_id = p.id
--  WHERE p.parent_id IS NULL GROUP BY p.id, p.name ORDER BY p.sort_order;  -- 9행, 합계 81

-- [롤백] 되돌리기 — 새 표만 지운다(기존 표·데이터 영향 없음). 승인 후에만
-- DROP TABLE syc_categories;
