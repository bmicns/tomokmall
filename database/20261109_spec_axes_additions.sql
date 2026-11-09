-- ─── 규격 구조화 추가: PVC 관 지름(글자 값) · 화강경계 모델 코드(# → G) · 상세 표 기준 6개 카테고리 ──────────────
-- 사용자 결정 2026-11-09:
--   - PVC소형맨홀 'D300*900H*VG200(4구)사각몸통' → 내경 D · 높이 H · 접속구 지름(dim_d_connect = 'VG200') · 옵션 '접속4구(사각몸통)'
--   - PVC오수받이 'D300*900H*VG100/VG150(유입3구/유출1구)' → 내경 D · 높이 H · 유입구 지름(dim_d_inlet)·유출구 지름(dim_d_outlet) ·
--     옵션 '유입3구/유출1구(일반)' (뒤에 '사각몸통' 이 없으면 '일반')
--   - 화강경계경사·화강경계도로·화강경계낮춤의 '#377' → 모델 코드 'G377'(경사는 코드만 바뀌므로 값 다시 채우기로 반영),
--     도로·낮춤은 옵션 대신 모델 코드(읽는 형식 hash)
--   - 사이트 머리글과 값이 안 맞던 카테고리 → 원본 사이트 상품 상세 페이지의 규격 표(이미지)로 뜻 확인 후 이름표:
--     47 사각수로관(무공) 300*300*2000*100T (300A) = 폭·깊이·길이·두께(내경) / 62 원형노바시 D700*300H*200T = 안지름(내경)·높이·두께 /
--     67 조립식사각맨홀 900*900*1000H*200T = 가로·세로·깊이·두께 / 104 수목보호틀 1000*1000*H100 = 가로·세로·높이 /
--     264 잔디블록 220*220*80T = 가로·세로·높이 / 98 원형잔디블록(사이트에서 상품 삭제 — 잔디블록과 같게, 사용자 결정)
--     값의 T·H 표기가 상세 표의 뜻과 다르거나(80T=높이, 1000H=깊이) H 가 앞에 붙은 곳(H100)은 읽는 형식 loose
--   - 두께 칸 나눔: 두께 dim_t(그대로) · 상하두께 dim_t_tb · 벽체두께 dim_t_wall(개거 — 예전 dim_t·dim_t2 에서 옮김) ·
--     하부두께 dim_t_bottom · 상부두께 dim_t_top(79 조경경계블록 230*114*65~125 = 가로·세로·하부두께 65·상부두께 125, 읽는 형식 range)
--     약자는 모두 T. 예전 dim_t2 칸은 개거 값 다시 채우기 때 비워지고 더는 쓰지 않음(지우려면 phpMyAdmin — 원격 관리 API 는 가격 보호 표 DROP 불가)
-- 사용 코드: app/spec_axes.php(SPEC_AXIS_TEXT, 형식 hash·pipe_port). 전제: 20261106_product_spec_axes.sql·20261106_spec_axes_site_seed.sql 반영.
-- 성격: 추가형. products 는 ADD COLUMN 만(가격 칸 변경 없음). category_spec_axes 는 ENUM 값 추가·행 추가/형식 변경만.
-- 순서: [1] DDL → [2] DML → 코드 배포 → 값 다시 채우기(scripts/db-tools/spec-axes-fill.php — 150·151·152·184·185·47·62·67·98·104·264·21·79).
--       코드는 새 칸이 모두 있어야 규격 축 화면을 쓰므로(spec_axes_ready), 코드보다 [1] 을 먼저.

-- [1] 구조(DDL) -------------------------------------------------------------------
ALTER TABLE products
    ADD COLUMN dim_d         DECIMAL(8,1) NULL COMMENT '규격 축 d: 직경 D (mm) — 원형디자인 D648·우레탄볼라드 D116·칼라맨홀뚜껑 원형부직경' AFTER spec_code,
    ADD COLUMN dim_d_base    VARCHAR(20)  NULL COMMENT '규격 축 d_base: 바닥직경(글자 값, 예 142~145) — 우레탄볼라드' AFTER dim_d_o,
    ADD COLUMN dim_v         DECIMAL(8,1) NULL COMMENT '규격 축 v: 세로 V (mm) — 길이 L(dim_l)과 나눔(2026-11-09)' AFTER dim_l,
    ADD COLUMN dim_d_connect VARCHAR(20) NULL COMMENT '규격 축 d_connect: 접속구 지름(관 종류+지름, 예 VG150·DC200) — PVC소형맨홀' AFTER dim_ts,
    ADD COLUMN dim_d_inlet   VARCHAR(20) NULL COMMENT '규격 축 d_inlet: 유입구 지름(예 VG100) — PVC오수받이' AFTER dim_d_connect,
    ADD COLUMN dim_d_outlet  VARCHAR(20) NULL COMMENT '규격 축 d_outlet: 유출구 지름(예 DC200) — PVC오수받이' AFTER dim_d_inlet,
    ADD COLUMN dim_t_tb      DECIMAL(8,1) NULL COMMENT '규격 축 t_tb: 상하두께 T (mm) — 개거' AFTER dim_t2,
    ADD COLUMN dim_t_wall    DECIMAL(8,1) NULL COMMENT '규격 축 t_wall: 벽체두께 T (mm) — 개거' AFTER dim_t_tb,
    ADD COLUMN dim_t_top     DECIMAL(8,1) NULL COMMENT '규격 축 t_top: 상부두께 T (mm) — 조경경계블록 125' AFTER dim_t_wall,
    ADD COLUMN dim_t_bottom  DECIMAL(8,1) NULL COMMENT '규격 축 t_bottom: 하부두께 T (mm) — 조경경계블록 65' AFTER dim_t_top;

ALTER TABLE category_spec_axes
    ADD COLUMN fixed_text VARCHAR(30) NULL COMMENT '글자 값 축의 고정값(규격 원문에 없고 카테고리 전체 같은 값 — 예: 우레탄볼라드 바닥직경 142~145)' AFTER fixed_value,
    MODIFY axis_key ENUM('d','d_base','v','d_i','d_o','w','w_b','w_r','w_t','h','h_high','h_low','depth','l','t','t2','t_tb','t_wall','t_top','t_bottom',
                         'mesh','wire_d','wire_no','ts','d_connect','d_inlet','d_outlet','skip','none') NOT NULL
        COMMENT '값을 담을 products 칸(dim_<axis_key>, wire_no 는 spec_wire_no). skip = 숫자 자리만 차지(저장 안 함), none = 숫자 읽지 않음',
    MODIFY parse_fmt ENUM('default','paren','lead_code','mesh','slash','hash','pipe_port','loose','range') NOT NULL DEFAULT 'default'
        COMMENT '규격 표기 모양(카테고리 행에 같은 값): default / paren / lead_code / mesh / slash 150*200/100_#377 / hash 100*100_#377 / pipe_port D300*900H*VG100/VG150(유입3구/유출1구) / loose T·H 표기 무시';

-- [2] 이름표(DML) — id 와 이름이 모두 맞을 때만 ------------------------------------------
-- 2-1) 화강경계도로(150)·화강경계낮춤(152): 옵션 '#377' → 모델 코드(읽는 형식 hash)
UPDATE category_spec_axes SET parse_fmt = 'hash'
WHERE category_id IN (SELECT id FROM categories WHERE (id = 150 AND name = '화강경계도로') OR (id = 152 AND name = '화강경계낮춤'));

-- 2-2) PVC소형맨홀(184)·PVC오수받이(185)
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt, fixed_value)
SELECT c.id, v.seq, v.axis_key, v.label, v.symbol, 1, 'mm', 1, 'pipe_port', NULL
FROM (
              SELECT 184 AS cid, 'PVC소형맨홀' AS cname, 1 AS seq, 'd_i' AS axis_key, '내경' AS label, 'D' AS symbol
    UNION ALL SELECT 184, 'PVC소형맨홀', 2, 'h',         '높이',        'H'
    UNION ALL SELECT 184, 'PVC소형맨홀', 3, 'd_connect', '접속구 지름', NULL
    UNION ALL SELECT 185, 'PVC오수받이', 1, 'd_i',       '내경',        'D'
    UNION ALL SELECT 185, 'PVC오수받이', 2, 'h',         '높이',        'H'
    UNION ALL SELECT 185, 'PVC오수받이', 3, 'd_inlet',   '유입구 지름', NULL
    UNION ALL SELECT 185, 'PVC오수받이', 4, 'd_outlet',  '유출구 지름', NULL
) v
JOIN categories c ON c.id = v.cid AND c.name = v.cname
WHERE NOT EXISTS (SELECT 1 FROM category_spec_axes x WHERE x.category_id = c.id);

-- 2-3) 상세 표로 뜻을 확인한 6개 카테고리
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt, fixed_value)
SELECT c.id, v.seq, v.axis_key, v.label, v.symbol, 1, 'mm', 1, v.parse_fmt, NULL
FROM (
              SELECT 47 AS cid, '사각수로관(무공)' AS cname, 1 AS seq, 'w' AS axis_key, '폭' AS label, 'W' AS symbol, 'default' AS parse_fmt
    UNION ALL SELECT 47,  '사각수로관(무공)', 2, 'depth', '깊이', 'D', 'default'
    UNION ALL SELECT 47,  '사각수로관(무공)', 3, 'l',     '길이', 'L', 'default'
    UNION ALL SELECT 47,  '사각수로관(무공)', 4, 't',     '두께', 'T', 'default'
    UNION ALL SELECT 62,  '원형노바시(원형인상맨홀)', 1, 'd_i', '내경', 'D', 'default'
    UNION ALL SELECT 62,  '원형노바시(원형인상맨홀)', 2, 'h',   '높이', 'H', 'default'
    UNION ALL SELECT 62,  '원형노바시(원형인상맨홀)', 3, 't',   '두께', 'T', 'default'
    UNION ALL SELECT 67,  '조립식사각맨홀(상부.연직.하부구체)', 1, 'w',     '가로', 'W', 'loose'
    UNION ALL SELECT 67,  '조립식사각맨홀(상부.연직.하부구체)', 2, 'l',     '세로', 'L', 'loose'
    UNION ALL SELECT 67,  '조립식사각맨홀(상부.연직.하부구체)', 3, 'depth', '깊이', 'D', 'loose'
    UNION ALL SELECT 67,  '조립식사각맨홀(상부.연직.하부구체)', 4, 't',     '두께', 'T', 'loose'
    UNION ALL SELECT 98,  '원형잔디블록', 1, 'w', '가로', 'W', 'loose'
    UNION ALL SELECT 98,  '원형잔디블록', 2, 'l', '세로', 'L', 'loose'
    UNION ALL SELECT 98,  '원형잔디블록', 3, 'h', '높이', 'H', 'loose'
    UNION ALL SELECT 104, '수목보호틀(인조석)', 1, 'w', '가로', 'W', 'loose'
    UNION ALL SELECT 104, '수목보호틀(인조석)', 2, 'l', '세로', 'L', 'loose'
    UNION ALL SELECT 104, '수목보호틀(인조석)', 3, 'h', '높이', 'H', 'loose'
    UNION ALL SELECT 264, '잔디블록', 1, 'w', '가로', 'W', 'loose'
    UNION ALL SELECT 264, '잔디블록', 2, 'l', '세로', 'L', 'loose'
    UNION ALL SELECT 264, '잔디블록', 3, 'h', '높이', 'H', 'loose'
) v
JOIN categories c ON c.id = v.cid AND c.name = v.cname
WHERE NOT EXISTS (SELECT 1 FROM category_spec_axes x WHERE x.category_id = c.id);

-- 2-4) 개거(21): 상하두께 t → t_tb, 벽체두께 t2 → t_wall
UPDATE category_spec_axes SET axis_key = 't_tb'
WHERE axis_key = 't' AND label = '상하두께' AND category_id IN (SELECT id FROM categories WHERE id = 21 AND name = '개거');
UPDATE category_spec_axes SET axis_key = 't_wall'
WHERE axis_key = 't2' AND label = '벽체두께' AND category_id IN (SELECT id FROM categories WHERE id = 21 AND name = '개거');

-- 2-5) 조경경계블록(79): 230*114*65~125 = 가로 W · 세로 L · 하부두께 T 65 · 상부두께 T 125 (읽는 형식 range)
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt, fixed_value)
SELECT c.id, v.seq, v.axis_key, v.label, v.symbol, 1, 'mm', 1, 'range', NULL
FROM (
              SELECT 79 AS cid, '조경경계블록' AS cname, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol
    UNION ALL SELECT 79, '조경경계블록', 2, 'l',        '세로',     'L'
    UNION ALL SELECT 79, '조경경계블록', 3, 't_bottom', '하부두께', 'T'
    UNION ALL SELECT 79, '조경경계블록', 4, 't_top',    '상부두께', 'T'
) v
JOIN categories c ON c.id = v.cid AND c.name = v.cname
WHERE NOT EXISTS (SELECT 1 FROM category_spec_axes x WHERE x.category_id = c.id);

-- 2-6) 사이트 목록에 구조 표기가 없던 카테고리 → 상품 상세 페이지 규격 표(이미지)로 확인(사용자 결정 2026-11-09)
--   끝 T 숫자(디자인그레이팅 3T·4T, EX메탈거름망 1.6T)는 두께 T · 원형 지름은 직경 D(dim_d) · 칼라맨홀뚜껑 D648 은 원형부직경(dim_d) ·
--   우레탄볼라드 바닥직경은 상세 표 '142~145' 고정 글자(dim_d_base — 규격 원문에 없음) · 배수경계석 세로 V·길이 L · 험프석 상단/하단 높이
--   (낙엽방지덮개 400*500 은 상세 표 '가로 500·세로 400' 과 순서가 달라 제외 — 원문 유지)
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt, fixed_value, fixed_text)
SELECT c.id, v.seq, v.axis_key, v.label, v.symbol, 1, 'mm', 1, v.parse_fmt, NULL, v.fixed_text
FROM (
              SELECT 109 AS cid, '사각주철(수로용/닥타일)' AS cname, 1 AS seq, 'w' AS axis_key, '폭' AS label, 'W' AS symbol, 'default' AS parse_fmt, NULL AS fixed_text
    UNION ALL SELECT 109, '사각주철(수로용/닥타일)', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 109, '사각주철(수로용/닥타일)', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 110, '주철우수통', 1, 'w', '가로', 'W', 'default', NULL
    UNION ALL SELECT 110, '주철우수통', 2, 'v', '세로', 'V', 'default', NULL
    UNION ALL SELECT 110, '주철우수통', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 114, '삼각트렌치', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 114, '삼각트렌치', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 114, '삼각트렌치', 3, 't', '두께', 'T', 'default', NULL
    UNION ALL SELECT 122, 'EX메탈망그레이팅', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 122, 'EX메탈망그레이팅', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 122, 'EX메탈망그레이팅', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 128, '디자인그레이팅수로용', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 128, '디자인그레이팅수로용', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 128, '디자인그레이팅수로용', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 128, '디자인그레이팅수로용', 4, 't', '두께', 'T', 'default', NULL
    UNION ALL SELECT 129, '디자인그레이팅맨홀(집수정)용', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 129, '디자인그레이팅맨홀(집수정)용', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 129, '디자인그레이팅맨홀(집수정)용', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 129, '디자인그레이팅맨홀(집수정)용', 4, 't', '두께', 'T', 'default', NULL
    UNION ALL SELECT 130, '원형디자인', 1, 'd', '직경', 'D', 'default', NULL
    UNION ALL SELECT 130, '원형디자인', 2, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 130, '원형디자인', 3, 't', '두께', 'T', 'default', NULL
    UNION ALL SELECT 131, '칼라맨홀뚜껑', 1, 'w', '가로', 'W', 'loose', NULL
    UNION ALL SELECT 131, '칼라맨홀뚜껑', 2, 'v', '세로', 'V', 'loose', NULL
    UNION ALL SELECT 131, '칼라맨홀뚜껑', 3, 'd', '원형부직경', 'D', 'loose', NULL
    UNION ALL SELECT 131, '칼라맨홀뚜껑', 4, 'h', '높이', 'H', 'loose', NULL
    UNION ALL SELECT 131, '칼라맨홀뚜껑', 5, 't', '두께', 'T', 'loose', NULL
    UNION ALL SELECT 132, '수목보호판', 1, 'w', '가로', 'W', 'default', NULL
    UNION ALL SELECT 132, '수목보호판', 2, 'v', '세로', 'V', 'default', NULL
    UNION ALL SELECT 132, '수목보호판', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 132, '수목보호판', 4, 't', '두께', 'T', 'default', NULL
    UNION ALL SELECT 135, '알루미늄그레이팅', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 135, '알루미늄그레이팅', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 135, '알루미늄그레이팅', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 143, 'EX메탈거름망', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 143, 'EX메탈거름망', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 143, 'EX메탈거름망', 3, 't', '두께', 'T', 'default', NULL
    UNION ALL SELECT 153, '험프석', 1, 'w', '폭', 'W', 'slash', NULL
    UNION ALL SELECT 153, '험프석', 2, 'h_high', '상단 높이', 'H', 'slash', NULL
    UNION ALL SELECT 153, '험프석', 3, 'h_low', '하단 높이', 'H', 'slash', NULL
    UNION ALL SELECT 153, '험프석', 4, 'l', '길이', 'L', 'slash', NULL
    UNION ALL SELECT 160, '배수(빗물받이)경계석', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 160, '배수(빗물받이)경계석', 2, 'v', '세로', 'V', 'default', NULL
    UNION ALL SELECT 160, '배수(빗물받이)경계석', 3, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 161, '수목보호틀(화강석)', 1, 'w', '가로', 'W', 'default', NULL
    UNION ALL SELECT 161, '수목보호틀(화강석)', 2, 'v', '세로', 'V', 'default', NULL
    UNION ALL SELECT 177, 'PE빗물받이', 1, 'w', '가로', 'W', 'default', NULL
    UNION ALL SELECT 177, 'PE빗물받이', 2, 'v', '세로', 'V', 'default', NULL
    UNION ALL SELECT 177, 'PE빗물받이', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 178, 'PE오수받이', 1, 'w', '가로', 'W', 'default', NULL
    UNION ALL SELECT 178, 'PE오수받이', 2, 'v', '세로', 'V', 'default', NULL
    UNION ALL SELECT 178, 'PE오수받이', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 179, 'PE홈통받이', 1, 'd_i', '내경', 'D', 'default', NULL
    UNION ALL SELECT 179, 'PE홈통받이', 2, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 180, 'THP주름관', 1, 'd_i', '내경', 'D', 'default', NULL
    UNION ALL SELECT 180, 'THP주름관', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 181, 'THP유공관', 1, 'd_i', '내경', 'D', 'default', NULL
    UNION ALL SELECT 181, 'THP유공관', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 202, '그레이팅덮개', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 202, '그레이팅덮개', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 202, '그레이팅덮개', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 206, '우레탄볼라드', 1, 'd', '직경', 'D', 'default', NULL
    UNION ALL SELECT 206, '우레탄볼라드', 2, 'd_base', '바닥직경', 'D', 'default', '142~145'
    UNION ALL SELECT 206, '우레탄볼라드', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 208, '플라스틱그레이팅', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 208, '플라스틱그레이팅', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 208, '플라스틱그레이팅', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 210, 'PE전원박스', 1, 'w', '가로', 'W', 'default', NULL
    UNION ALL SELECT 210, 'PE전원박스', 2, 'v', '세로', 'V', 'default', NULL
    UNION ALL SELECT 210, 'PE전원박스', 3, 'h', '높이', 'H', 'default', NULL
    UNION ALL SELECT 268, '무소음트렌치', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 268, '무소음트렌치', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 268, '무소음트렌치', 3, 't', '두께', 'T', 'default', NULL
    UNION ALL SELECT 68, 'PE사다리', 1, 'w', '폭', 'W', 'default', NULL
    UNION ALL SELECT 68, 'PE사다리', 2, 'l', '길이', 'L', 'default', NULL
    UNION ALL SELECT 68, 'PE사다리', 3, 't', '두께', 'T', 'default', NULL
) v
JOIN categories c ON c.id = v.cid AND c.name = v.cname
WHERE NOT EXISTS (SELECT 1 FROM category_spec_axes x WHERE x.category_id = c.id);

-- 2-7) 세로를 길이 L 과 나눔: 이름표 '세로'(axis l·약자 L) → axis v·약자 V (운영 22개 + 이 파일에서 넣은 카테고리 전부)
UPDATE category_spec_axes SET axis_key = 'v', symbol = 'V' WHERE axis_key = 'l' AND label = '세로';

-- [3] 확인(읽기 전용)
-- SELECT category_id, seq, axis_key, label, parse_fmt FROM category_spec_axes WHERE category_id IN (150, 152, 184, 185) ORDER BY category_id, seq;  -- 150·152 hash 4행, 184 3행, 185 4행

-- [롤백] 승인 후에만
-- DELETE FROM category_spec_axes WHERE category_id IN (184, 185, 47, 62, 67, 98, 104, 264, 79, 109, 110, 114, 122, 128, 129, 130, 131, 132, 135, 143, 153, 160, 161, 177, 178, 179, 180, 181, 202, 206, 208, 210, 268, 68);
-- UPDATE category_spec_axes SET axis_key = 'l', symbol = 'L' WHERE axis_key = 'v' AND label = '세로';
-- UPDATE category_spec_axes SET axis_key = 't' WHERE category_id = 21 AND axis_key = 't_tb';  UPDATE category_spec_axes SET axis_key = 't2' WHERE category_id = 21 AND axis_key = 't_wall';
-- UPDATE category_spec_axes SET parse_fmt = 'default' WHERE category_id IN (150, 152);
-- (ENUM 값·새 칸은 남겨도 이전 코드가 무시. 지우려면 phpMyAdmin: ALTER TABLE products DROP COLUMN dim_d_outlet, DROP COLUMN dim_d_inlet, DROP COLUMN dim_d_connect;)
