-- ─── 규격 구조화 추가: PVC소형맨홀·PVC오수받이 관 지름(글자 값) + 화강경계 모델 코드(# → G) ──────────────
-- 사용자 결정 2026-11-09:
--   - PVC소형맨홀 'D300*900H*VG200(4구)사각몸통' → 내경 D · 높이 H · 접속구 지름(dim_d_connect = 'VG200') · 옵션 '접속4구(사각몸통)'
--   - PVC오수받이 'D300*900H*VG100/VG150(유입3구/유출1구)' → 내경 D · 높이 H · 유입구 지름(dim_d_inlet)·유출구 지름(dim_d_outlet) ·
--     옵션 '유입3구/유출1구(일반)' (뒤에 '사각몸통' 이 없으면 '일반')
--   - 화강경계경사·화강경계도로·화강경계낮춤의 '#377' → 모델 코드 'G377'(경사는 코드만 바뀌므로 값 다시 채우기로 반영),
--     도로·낮춤은 옵션 대신 모델 코드(읽는 형식 hash)
-- 사용 코드: app/spec_axes.php(SPEC_AXIS_TEXT, 형식 hash·pipe_port). 전제: 20261106_product_spec_axes.sql·20261106_spec_axes_site_seed.sql 반영.
-- 성격: 추가형. products 는 ADD COLUMN 만(가격 칸 변경 없음). category_spec_axes 는 ENUM 값 추가·행 추가/형식 변경만.
-- 순서: [1] DDL → [2] DML → 코드 배포 → 값 다시 채우기(scripts/db-tools/spec-axes-fill.php — 150·151·152·184·185).
--       코드는 새 칸이 모두 있어야 규격 축 화면을 쓰므로(spec_axes_ready), 코드보다 [1] 을 먼저.

-- [1] 구조(DDL) -------------------------------------------------------------------
ALTER TABLE products
    ADD COLUMN dim_d_connect VARCHAR(20) NULL COMMENT '규격 축 d_connect: 접속구 지름(관 종류+지름, 예 VG150·DC200) — PVC소형맨홀' AFTER dim_ts,
    ADD COLUMN dim_d_inlet   VARCHAR(20) NULL COMMENT '규격 축 d_inlet: 유입구 지름(예 VG100) — PVC오수받이' AFTER dim_d_connect,
    ADD COLUMN dim_d_outlet  VARCHAR(20) NULL COMMENT '규격 축 d_outlet: 유출구 지름(예 DC200) — PVC오수받이' AFTER dim_d_inlet;

ALTER TABLE category_spec_axes
    MODIFY axis_key ENUM('d_i','d_o','w','w_b','w_r','w_t','h','h_high','h_low','depth','l','t','t2','mesh','wire_d','wire_no','ts',
                         'd_connect','d_inlet','d_outlet','skip','none') NOT NULL
        COMMENT '값을 담을 products 칸(dim_<axis_key>, wire_no 는 spec_wire_no). skip = 숫자 자리만 차지(저장 안 함), none = 숫자 읽지 않음',
    MODIFY parse_fmt ENUM('default','paren','lead_code','mesh','slash','hash','pipe_port') NOT NULL DEFAULT 'default'
        COMMENT '규격 표기 모양(카테고리 행에 같은 값): default / paren / lead_code / mesh / slash 150*200/100_#377 / hash 100*100_#377 / pipe_port D300*900H*VG100/VG150(유입3구/유출1구)';

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

-- [3] 확인(읽기 전용)
-- SELECT category_id, seq, axis_key, label, parse_fmt FROM category_spec_axes WHERE category_id IN (150, 152, 184, 185) ORDER BY category_id, seq;  -- 150·152 hash 4행, 184 3행, 185 4행

-- [롤백] 승인 후에만
-- DELETE FROM category_spec_axes WHERE category_id IN (184, 185);
-- UPDATE category_spec_axes SET parse_fmt = 'default' WHERE category_id IN (150, 152);
-- (ENUM 값·새 칸은 남겨도 이전 코드가 무시. 지우려면 phpMyAdmin: ALTER TABLE products DROP COLUMN dim_d_outlet, DROP COLUMN dim_d_inlet, DROP COLUMN dim_d_connect;)
