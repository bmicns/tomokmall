-- ─── 규격 축 이름표 추가: 원본 사이트(ktomok.com) 표 머리글 기준 카테고리 ───────────────────────────
-- 목적: 20261106_product_spec_axes.sql(확정 7개)에 이어, 원본 사이트 카테고리 목록 표의 '규격' 칸 아래 구조 표기
--       (예: 환경수로관 '(폭X깊이X길이X두께)')를 이름표로 넣는다. 분석: docs/03-analysis/spec-axes-site-structures.md
-- 넣는 기준(사용자 결정 2026-11-06): 사이트 이름이 모두 아래 규칙에 있고, 운영 규격 값(활성 상품)이 전부 그 순서로 읽히는 카테고리만.
--   폭 W(dim_w)·가로 W(dim_w)·하부폭/밑면 → 하부폭 WB(dim_w_b)·윗면 → 상부폭 WT(dim_w_t)·뒷폭 WR(dim_w_r)
--   높이 H(dim_h)·깊이 D(dim_depth)·길이 L(dim_l)·세로 L(dim_l)·두께 T(dim_t)·내경 D(dim_d_i)·외경 D(dim_d_o)
--   '높이(I-BAR)' 는 높이, '_파형' 은 숫자가 아닌 옵션 자리, '외경(…)'·'내경(…)' 묶음은 안쪽 이름으로 읽음.
-- 성격: category_spec_axes 데이터 추가만(가격·기존 칸 변경 없음). id 와 이름이 모두 맞고 이름표가 아직 없는 카테고리에만 넣는다(다시 실행해도 중복 없음).
-- 실행: 사용자 승인 후, 20261106_product_spec_axes.sql 다음에. 새 칸 값 채우기는 scripts/db-tools/spec-axes-fill.php(별도 승인).
-- 대상: 103개 카테고리 · 312행

-- [1] 사전 확인(읽기 전용): 아래 목록과 id·이름이 같은 카테고리 수(103이어야 함 — 다르면 그 카테고리는 [2]에서 건너뜀)
SELECT COUNT(*) AS matched_categories FROM categories WHERE 
       (id = 22 AND name = 'U형플륨관(벤치1종)')
    OR (id = 23 AND name = '벤치플륨관(벤치2종)')
    OR (id = 25 AND name = '환경수로관')
    OR (id = 26 AND name = '개거수로관')
    OR (id = 27 AND name = '보강수로관(무개생태)')
    OR (id = 28 AND name = '생태탈출로')
    OR (id = 29 AND name = '건널목수로관(1종)')
    OR (id = 30 AND name = '건널목수로관(2종)')
    OR (id = 33 AND name = '측구수로관A형(앵글미부착)')
    OR (id = 34 AND name = '측구수로관B형(앵글부착)')
    OR (id = 35 AND name = '측구수로관AN형(앵글부착)')
    OR (id = 36 AND name = '측구수로관Z형(앵글부착)')
    OR (id = 37 AND name = '측구수로관유공(앵글부착)')
    OR (id = 38 AND name = '침투측구수로관(앵글부착)')
    OR (id = 42 AND name = 'NS용집수정(사각수로관용)')
    OR (id = 43 AND name = '사각수로관(AN형)')
    OR (id = 44 AND name = '사각수로관(BN형)')
    OR (id = 45 AND name = '사각수로관(CN형)')
    OR (id = 46 AND name = '사각수로관(DN형)')
    OR (id = 51 AND name = '흄관(A형)')
    OR (id = 52 AND name = '유공흄관')
    OR (id = 53 AND name = '접속흄관(B형)')
    OR (id = 54 AND name = 'VR관(흄관대용)')
    OR (id = 55 AND name = '레진관')
    OR (id = 56 AND name = 'PC방호벽(중앙분리)')
    OR (id = 57 AND name = 'PHC파일A종(고강도파일)')
    OR (id = 58 AND name = 'PC판넬옹벽')
    OR (id = 60 AND name = '사각노바시(사각인상맨홀)')
    OR (id = 63 AND name = '침투집수정')
    OR (id = 64 AND name = '전기맨홀')
    OR (id = 65 AND name = '통신맨홀')
    OR (id = 69 AND name = '인터로킹U형블럭')
    OR (id = 70 AND name = '인터로킹I2형블럭')
    OR (id = 71 AND name = '인조화강블록(투수)')
    OR (id = 72 AND name = '인조화강블록(불투수)')
    OR (id = 74 AND name = '다칼라블랜딩블록(불투수)')
    OR (id = 75 AND name = '장애인사각블럭(점자블럭)')
    OR (id = 77 AND name = '점토블럭')
    OR (id = 78 AND name = '계단마감재')
    OR (id = 80 AND name = '축조블록(곡선형)')
    OR (id = 81 AND name = '축조블록(평면형)')
    OR (id = 83 AND name = '보강토블록(줄무늬)')
    OR (id = 84 AND name = '보강토블록(민무늬)')
    OR (id = 85 AND name = '보강토블록(혼합무늬)')
    OR (id = 86 AND name = '보강토마감형')
    OR (id = 87 AND name = '조립식옹벽블록')
    OR (id = 91 AND name = '고강도콘크리트경계석')
    OR (id = 92 AND name = '녹지경계블록')
    OR (id = 93 AND name = '인조화강경계석')
    OR (id = 95 AND name = '호안블록(저류용)')
    OR (id = 96 AND name = '호안블록(식생용)')
    OR (id = 99 AND name = '조경잔디블록')
    OR (id = 101 AND name = '콘크리트벽돌')
    OR (id = 102 AND name = '속빈콘크리트블록')
    OR (id = 103 AND name = '가로등기초')
    OR (id = 105 AND name = '빗물받이블록')
    OR (id = 106 AND name = 'PC트렌치')
    OR (id = 107 AND name = '중력식옹벽블록')
    OR (id = 115 AND name = '알루미늄트렌치')
    OR (id = 116 AND name = '그레이팅수로용')
    OR (id = 117 AND name = '그레이팅맨홀(집수정)용')
    OR (id = 118 AND name = '플륨관용(날개형U-TYPE)')
    OR (id = 120 AND name = '안전그레이팅')
    OR (id = 121 AND name = '악취차단오물망그레이팅')
    OR (id = 123 AND name = '스틸타공그레이팅')
    OR (id = 124 AND name = '중하중그레이팅수로용')
    OR (id = 125 AND name = '중하중그레이팅맨홀(집수정)용')
    OR (id = 126 AND name = '안전중하중그레이팅수로용')
    OR (id = 127 AND name = '안전중하중그레이팅맨홀(집수정)용')
    OR (id = 137 AND name = '파형강관(유공관)')
    OR (id = 138 AND name = '파형강관(내면피복)')
    OR (id = 139 AND name = '파형강관(내면피복)유공관')
    OR (id = 140 AND name = '파형강관(양면피복)')
    OR (id = 141 AND name = '파형강관(양면피복)유공관')
    OR (id = 142 AND name = '체크무늬철판')
    OR (id = 150 AND name = '화강경계도로')
    OR (id = 152 AND name = '화강경계낮춤')
    OR (id = 162 AND name = '돌망태(개비온)')
    OR (id = 163 AND name = 'PE이중벽관1종')
    OR (id = 164 AND name = 'PE이중벽관2종')
    OR (id = 165 AND name = 'PE이중벽관3종')
    OR (id = 166 AND name = 'PE이중벽관(KS)')
    OR (id = 167 AND name = 'PE이중벽관1종(유공관)')
    OR (id = 168 AND name = 'PE이중벽관2종(유공관)')
    OR (id = 169 AND name = 'PE이중벽관3종(유공관)')
    OR (id = 172 AND name = 'PE다중벽관(KS)')
    OR (id = 173 AND name = 'PE다중벽관(유공관)')
    OR (id = 174 AND name = '고강성PVC이중벽관')
    OR (id = 176 AND name = 'PE배수로')
    OR (id = 182 AND name = 'PVC관VN(구VG1)')
    OR (id = 183 AND name = 'PVC관VN(구VG2)')
    OR (id = 187 AND name = 'HIVP내충격용수도관')
    OR (id = 188 AND name = 'HIVG1직관')
    OR (id = 189 AND name = 'HIVG1편수칼라관')
    OR (id = 190 AND name = 'HIVG2직관')
    OR (id = 191 AND name = 'HIVG2편수칼라관')
    OR (id = 194 AND name = 'HDPE이중벽관(직관)')
    OR (id = 195 AND name = 'HDPE이중벽관(유공관)')
    OR (id = 204 AND name = '카스토퍼')
    OR (id = 205 AND name = '과속방지턱')
    OR (id = 269 AND name = '파형강관')
    OR (id = 272 AND name = '콘크리트경계석')
    OR (id = 275 AND name = 'PE다중벽관');

-- [2] 이름표 넣기
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit)
SELECT c.id, v.seq, v.axis_key, v.label, v.symbol, 1, v.unit
FROM (
    -- 22 U형플륨관(벤치1종): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 310*275*2000*32T
              SELECT 22 AS cid, 'U형플륨관(벤치1종)' AS cname, 1 AS seq, 'w' AS axis_key, '폭' AS label, 'W' AS symbol, 'mm' AS unit
    UNION ALL SELECT 22, 'U형플륨관(벤치1종)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 22, 'U형플륨관(벤치1종)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 22, 'U형플륨관(벤치1종)', 4, 't', '두께', 'T', 'mm'
    -- 23 벤치플륨관(벤치2종): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 200*150*2000*35T
    UNION ALL SELECT 23, '벤치플륨관(벤치2종)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 23, '벤치플륨관(벤치2종)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 23, '벤치플륨관(벤치2종)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 23, '벤치플륨관(벤치2종)', 4, 't', '두께', 'T', 'mm'
    -- 25 환경수로관: 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 400*400*2000*120T (400C)
    UNION ALL SELECT 25, '환경수로관', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 25, '환경수로관', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 25, '환경수로관', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 25, '환경수로관', 4, 't', '두께', 'T', 'mm'
    -- 26 개거수로관: 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 2000*2000*2000*160T (2000C)
    UNION ALL SELECT 26, '개거수로관', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 26, '개거수로관', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 26, '개거수로관', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 26, '개거수로관', 4, 't', '두께', 'T', 'mm'
    -- 27 보강수로관(무개생태): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 400*400*2000*120 (400C)
    UNION ALL SELECT 27, '보강수로관(무개생태)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 27, '보강수로관(무개생태)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 27, '보강수로관(무개생태)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 27, '보강수로관(무개생태)', 4, 't', '두께', 'T', 'mm'
    -- 28 생태탈출로: 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 400*300*1600*70 (300C)_벤치3종 부착형
    UNION ALL SELECT 28, '생태탈출로', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 28, '생태탈출로', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 28, '생태탈출로', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 28, '생태탈출로', 4, 't', '두께', 'T', 'mm'
    -- 29 건널목수로관(1종): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 295*265*2000*100 (300C)
    UNION ALL SELECT 29, '건널목수로관(1종)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 29, '건널목수로관(1종)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 29, '건널목수로관(1종)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 29, '건널목수로관(1종)', 4, 't', '두께', 'T', 'mm'
    -- 30 건널목수로관(2종): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 295*265*2000*100 (300C)
    UNION ALL SELECT 30, '건널목수로관(2종)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 30, '건널목수로관(2종)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 30, '건널목수로관(2종)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 30, '건널목수로관(2종)', 4, 't', '두께', 'T', 'mm'
    -- 33 측구수로관A형(앵글미부착): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 200*180*2000*70T
    UNION ALL SELECT 33, '측구수로관A형(앵글미부착)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 33, '측구수로관A형(앵글미부착)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 33, '측구수로관A형(앵글미부착)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 33, '측구수로관A형(앵글미부착)', 4, 't', '두께', 'T', 'mm'
    -- 34 측구수로관B형(앵글부착): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 200*200*2000*110T
    UNION ALL SELECT 34, '측구수로관B형(앵글부착)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 34, '측구수로관B형(앵글부착)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 34, '측구수로관B형(앵글부착)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 34, '측구수로관B형(앵글부착)', 4, 't', '두께', 'T', 'mm'
    -- 35 측구수로관AN형(앵글부착): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 200*200*2000*40T
    UNION ALL SELECT 35, '측구수로관AN형(앵글부착)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 35, '측구수로관AN형(앵글부착)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 35, '측구수로관AN형(앵글부착)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 35, '측구수로관AN형(앵글부착)', 4, 't', '두께', 'T', 'mm'
    -- 36 측구수로관Z형(앵글부착): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 200*200*2000*80T
    UNION ALL SELECT 36, '측구수로관Z형(앵글부착)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 36, '측구수로관Z형(앵글부착)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 36, '측구수로관Z형(앵글부착)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 36, '측구수로관Z형(앵글부착)', 4, 't', '두께', 'T', 'mm'
    -- 37 측구수로관유공(앵글부착): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 200*200*2000*80T
    UNION ALL SELECT 37, '측구수로관유공(앵글부착)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 37, '측구수로관유공(앵글부착)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 37, '측구수로관유공(앵글부착)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 37, '측구수로관유공(앵글부착)', 4, 't', '두께', 'T', 'mm'
    -- 38 침투측구수로관(앵글부착): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 200*200*2000*120T
    UNION ALL SELECT 38, '침투측구수로관(앵글부착)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 38, '침투측구수로관(앵글부착)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 38, '침투측구수로관(앵글부착)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 38, '침투측구수로관(앵글부착)', 4, 't', '두께', 'T', 'mm'
    -- 42 NS용집수정(사각수로관용): 사이트 '(가로X세로X높이X두께)' · 예 220*1000*400*70T(NS200용)
    UNION ALL SELECT 42, 'NS용집수정(사각수로관용)', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 42, 'NS용집수정(사각수로관용)', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 42, 'NS용집수정(사각수로관용)', 3, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 42, 'NS용집수정(사각수로관용)', 4, 't', '두께', 'T', 'mm'
    -- 43 사각수로관(AN형): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 300*300*2000*100T (300A)
    UNION ALL SELECT 43, '사각수로관(AN형)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 43, '사각수로관(AN형)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 43, '사각수로관(AN형)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 43, '사각수로관(AN형)', 4, 't', '두께', 'T', 'mm'
    -- 44 사각수로관(BN형): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 300*300*2000*100T (300A)
    UNION ALL SELECT 44, '사각수로관(BN형)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 44, '사각수로관(BN형)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 44, '사각수로관(BN형)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 44, '사각수로관(BN형)', 4, 't', '두께', 'T', 'mm'
    -- 45 사각수로관(CN형): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 300*300*2000*100T (300A)
    UNION ALL SELECT 45, '사각수로관(CN형)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 45, '사각수로관(CN형)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 45, '사각수로관(CN형)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 45, '사각수로관(CN형)', 4, 't', '두께', 'T', 'mm'
    -- 46 사각수로관(DN형): 사이트 '(폭X깊이X길이X두께)' · 내경기준 · 예 300*300*2000*100T (300A)
    UNION ALL SELECT 46, '사각수로관(DN형)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 46, '사각수로관(DN형)', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 46, '사각수로관(DN형)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 46, '사각수로관(DN형)', 4, 't', '두께', 'T', 'mm'
    -- 51 흄관(A형): 사이트 '(내경X길이X두께)' · 내경기준 · 예 D250*2500*28T
    UNION ALL SELECT 51, '흄관(A형)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 51, '흄관(A형)', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 51, '흄관(A형)', 3, 't', '두께', 'T', 'mm'
    -- 52 유공흄관: 사이트 '(내경X길이X두께)' · 내경기준 · 예 D250*2500*28T
    UNION ALL SELECT 52, '유공흄관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 52, '유공흄관', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 52, '유공흄관', 3, 't', '두께', 'T', 'mm'
    -- 53 접속흄관(B형): 사이트 '(내경X길이X두께)' · 내경기준 · 예 D400*2500*35T
    UNION ALL SELECT 53, '접속흄관(B형)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 53, '접속흄관(B형)', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 53, '접속흄관(B형)', 3, 't', '두께', 'T', 'mm'
    -- 54 VR관(흄관대용): 사이트 '(내경X길이X두께)' · 내경기준 · 예 D300*2500*50T
    UNION ALL SELECT 54, 'VR관(흄관대용)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 54, 'VR관(흄관대용)', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 54, 'VR관(흄관대용)', 3, 't', '두께', 'T', 'mm'
    -- 55 레진관: 사이트 '(내경X길이X두께)' · 내경기준 · 예 D250*2500*17T
    UNION ALL SELECT 55, '레진관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 55, '레진관', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 55, '레진관', 3, 't', '두께', 'T', 'mm'
    -- 56 PC방호벽(중앙분리): 사이트 '(하부폭X높이X길이X두께)' · 예 500*780*2000*150T
    UNION ALL SELECT 56, 'PC방호벽(중앙분리)', 1, 'w_b', '하부폭', 'WB', 'mm'
    UNION ALL SELECT 56, 'PC방호벽(중앙분리)', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 56, 'PC방호벽(중앙분리)', 3, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 56, 'PC방호벽(중앙분리)', 4, 't', '두께', 'T', 'mm'
    -- 57 PHC파일A종(고강도파일): 사이트 '(외경X길이X두께)' · 외경기준 · 예 D500*5000*80T
    UNION ALL SELECT 57, 'PHC파일A종(고강도파일)', 1, 'd_o', '외경', 'D', 'mm'
    UNION ALL SELECT 57, 'PHC파일A종(고강도파일)', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 57, 'PHC파일A종(고강도파일)', 3, 't', '두께', 'T', 'mm'
    -- 58 PC판넬옹벽: 사이트 '(폭X높이X두께)' · 내경기준 · 예 1500*1500*200
    UNION ALL SELECT 58, 'PC판넬옹벽', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 58, 'PC판넬옹벽', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 58, 'PC판넬옹벽', 3, 't', '두께', 'T', 'mm'
    -- 60 사각노바시(사각인상맨홀): 사이트 '(가로X세로X높이)' · 내경기준 · 예 600*600*500H
    UNION ALL SELECT 60, '사각노바시(사각인상맨홀)', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 60, '사각노바시(사각인상맨홀)', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 60, '사각노바시(사각인상맨홀)', 3, 'h', '높이', 'H', 'mm'
    -- 63 침투집수정: 사이트 '(가로X세로X높이X두께)' · 내경기준 · 예 450*450*850*150T
    UNION ALL SELECT 63, '침투집수정', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 63, '침투집수정', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 63, '침투집수정', 3, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 63, '침투집수정', 4, 't', '두께', 'T', 'mm'
    -- 64 전기맨홀: 사이트 '(가로X세로X높이X두께)' · 내경기준 · 예 600*600*600*150T
    UNION ALL SELECT 64, '전기맨홀', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 64, '전기맨홀', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 64, '전기맨홀', 3, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 64, '전기맨홀', 4, 't', '두께', 'T', 'mm'
    -- 65 통신맨홀: 사이트 '(가로X세로X높이X두께)' · 내경기준 · 예 450*950*700*150T_(수공1호)
    UNION ALL SELECT 65, '통신맨홀', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 65, '통신맨홀', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 65, '통신맨홀', 3, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 65, '통신맨홀', 4, 't', '두께', 'T', 'mm'
    -- 69 인터로킹U형블럭: 사이트 '(가로X세로X두께)' · 예 220*110*60T_회색
    UNION ALL SELECT 69, '인터로킹U형블럭', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 69, '인터로킹U형블럭', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 69, '인터로킹U형블럭', 3, 't', '두께', 'T', 'mm'
    -- 70 인터로킹I2형블럭: 사이트 '(가로X세로X두께)' · 예 199*99*60T_회색
    UNION ALL SELECT 70, '인터로킹I2형블럭', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 70, '인터로킹I2형블럭', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 70, '인터로킹I2형블럭', 3, 't', '두께', 'T', 'mm'
    -- 71 인조화강블록(투수): 사이트 '(가로X세로X두께)' · 예 200*200*60T(투수)
    UNION ALL SELECT 71, '인조화강블록(투수)', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 71, '인조화강블록(투수)', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 71, '인조화강블록(투수)', 3, 't', '두께', 'T', 'mm'
    -- 72 인조화강블록(불투수): 사이트 '(가로X세로X두께)' · 예 200*200*60T(불투수)
    UNION ALL SELECT 72, '인조화강블록(불투수)', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 72, '인조화강블록(불투수)', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 72, '인조화강블록(불투수)', 3, 't', '두께', 'T', 'mm'
    -- 74 다칼라블랜딩블록(불투수): 사이트 '(가로X세로X두께)' · 예 900*1200*60_컬러(보도)
    UNION ALL SELECT 74, '다칼라블랜딩블록(불투수)', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 74, '다칼라블랜딩블록(불투수)', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 74, '다칼라블랜딩블록(불투수)', 3, 't', '두께', 'T', 'mm'
    -- 75 장애인사각블럭(점자블럭): 사이트 '(가로X세로X두께)' · 예 300*300*60T(고강도콘크리트_점형_KS)
    UNION ALL SELECT 75, '장애인사각블럭(점자블럭)', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 75, '장애인사각블럭(점자블럭)', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 75, '장애인사각블럭(점자블럭)', 3, 't', '두께', 'T', 'mm'
    -- 77 점토블럭: 사이트 '(가로X세로X두께)' · 예 230*114*60T_아이보리
    UNION ALL SELECT 77, '점토블럭', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 77, '점토블럭', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 77, '점토블럭', 3, 't', '두께', 'T', 'mm'
    -- 78 계단마감재: 사이트 '(가로X세로X두께)' · 예 230*114*55_계단마감재_레드
    UNION ALL SELECT 78, '계단마감재', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 78, '계단마감재', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 78, '계단마감재', 3, 't', '두께', 'T', 'mm'
    -- 80 축조블록(곡선형): 사이트 '(폭X높이X깊이)' · 예 1000*500*700
    UNION ALL SELECT 80, '축조블록(곡선형)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 80, '축조블록(곡선형)', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 80, '축조블록(곡선형)', 3, 'depth', '깊이', 'D', 'mm'
    -- 81 축조블록(평면형): 사이트 '(폭X높이X깊이)' · 예 1000*500*700
    UNION ALL SELECT 81, '축조블록(평면형)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 81, '축조블록(평면형)', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 81, '축조블록(평면형)', 3, 'depth', '깊이', 'D', 'mm'
    -- 83 보강토블록(줄무늬): 사이트 '(폭X높이X깊이)' · 예 500*200*450_회색_C형
    UNION ALL SELECT 83, '보강토블록(줄무늬)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 83, '보강토블록(줄무늬)', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 83, '보강토블록(줄무늬)', 3, 'depth', '깊이', 'D', 'mm'
    -- 84 보강토블록(민무늬): 사이트 '(폭X높이X깊이)' · 예 500*200*330_회색_B형
    UNION ALL SELECT 84, '보강토블록(민무늬)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 84, '보강토블록(민무늬)', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 84, '보강토블록(민무늬)', 3, 'depth', '깊이', 'D', 'mm'
    -- 85 보강토블록(혼합무늬): 사이트 '(폭X높이X깊이)' · 예 500*200*430_회색_F형
    UNION ALL SELECT 85, '보강토블록(혼합무늬)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 85, '보강토블록(혼합무늬)', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 85, '보강토블록(혼합무늬)', 3, 'depth', '깊이', 'D', 'mm'
    -- 86 보강토마감형: 사이트 '(폭X높이X뒷폭X깊이)' · 예 500*100*300*400_회색
    UNION ALL SELECT 86, '보강토마감형', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 86, '보강토마감형', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 86, '보강토마감형', 3, 'w_r', '뒷폭', 'WR', 'mm'
    UNION ALL SELECT 86, '보강토마감형', 4, 'depth', '깊이', 'D', 'mm'
    -- 87 조립식옹벽블록: 사이트 '(폭X높이X깊이)' · 예 1200*600*600_기본형
    UNION ALL SELECT 87, '조립식옹벽블록', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 87, '조립식옹벽블록', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 87, '조립식옹벽블록', 3, 'depth', '깊이', 'D', 'mm'
    -- 91 고강도콘크리트경계석: 사이트 '(윗면X밑면X높이)' · 예 150*170*200(A)
    UNION ALL SELECT 91, '고강도콘크리트경계석', 1, 'w_t', '상부폭', 'WT', 'mm'
    UNION ALL SELECT 91, '고강도콘크리트경계석', 2, 'w_b', '하부폭', 'WB', 'mm'
    UNION ALL SELECT 91, '고강도콘크리트경계석', 3, 'h', '높이', 'H', 'mm'
    -- 92 녹지경계블록: 사이트 '(가로X세로X두께)' · 예 190*160*100_적색
    UNION ALL SELECT 92, '녹지경계블록', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 92, '녹지경계블록', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 92, '녹지경계블록', 3, 't', '두께', 'T', 'mm'
    -- 93 인조화강경계석: 사이트 '(윗면X밑면X높이)' · 예 120*120*120(SA)
    UNION ALL SELECT 93, '인조화강경계석', 1, 'w_t', '상부폭', 'WT', 'mm'
    UNION ALL SELECT 93, '인조화강경계석', 2, 'w_b', '하부폭', 'WB', 'mm'
    UNION ALL SELECT 93, '인조화강경계석', 3, 'h', '높이', 'H', 'mm'
    -- 95 호안블록(저류용): 사이트 '(가로X세로X높이)' · 예 400*400*100_아이블록
    UNION ALL SELECT 95, '호안블록(저류용)', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 95, '호안블록(저류용)', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 95, '호안블록(저류용)', 3, 'h', '높이', 'H', 'mm'
    -- 96 호안블록(식생용): 사이트 '(가로X세로X높이)' · 예 400*400*100_F형블록
    UNION ALL SELECT 96, '호안블록(식생용)', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 96, '호안블록(식생용)', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 96, '호안블록(식생용)', 3, 'h', '높이', 'H', 'mm'
    -- 99 조경잔디블록: 사이트 '(가로X세로X높이)' · 예 1000*1000*150
    UNION ALL SELECT 99, '조경잔디블록', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 99, '조경잔디블록', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 99, '조경잔디블록', 3, 'h', '높이', 'H', 'mm'
    -- 101 콘크리트벽돌: 사이트 '(길이X높이X두께)' · 예 190*90*57
    UNION ALL SELECT 101, '콘크리트벽돌', 1, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 101, '콘크리트벽돌', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 101, '콘크리트벽돌', 3, 't', '두께', 'T', 'mm'
    -- 102 속빈콘크리트블록: 사이트 '(길이X높이X두께)' · 예 390*190*100(4인치)
    UNION ALL SELECT 102, '속빈콘크리트블록', 1, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 102, '속빈콘크리트블록', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 102, '속빈콘크리트블록', 3, 't', '두께', 'T', 'mm'
    -- 103 가로등기초: 사이트 '(윗면X밑면X높이)' · 예 200*300*300(무앙카,홀타공형)
    UNION ALL SELECT 103, '가로등기초', 1, 'w_t', '상부폭', 'WT', 'mm'
    UNION ALL SELECT 103, '가로등기초', 2, 'w_b', '하부폭', 'WB', 'mm'
    UNION ALL SELECT 103, '가로등기초', 3, 'h', '높이', 'H', 'mm'
    -- 105 빗물받이블록: 사이트 '외경(폭X길이X높이)' · 예 210*300*150
    UNION ALL SELECT 105, '빗물받이블록', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 105, '빗물받이블록', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 105, '빗물받이블록', 3, 'h', '높이', 'H', 'mm'
    -- 106 PC트렌치: 사이트 '(폭X길이X높이)' · 내경기준 · 예 150*1000*50_오픈형
    UNION ALL SELECT 106, 'PC트렌치', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 106, 'PC트렌치', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 106, 'PC트렌치', 3, 'h', '높이', 'H', 'mm'
    -- 107 중력식옹벽블록: 사이트 '(폭X높이X깊이)' · 내경기준 · 예 1172*457*710_레디락_상단마감
    UNION ALL SELECT 107, '중력식옹벽블록', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 107, '중력식옹벽블록', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 107, '중력식옹벽블록', 3, 'depth', '깊이', 'D', 'mm'
    -- 115 알루미늄트렌치: 사이트 '(폭X길이X높이)' · 내경기준 · 예 100*2500*60
    UNION ALL SELECT 115, '알루미늄트렌치', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 115, '알루미늄트렌치', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 115, '알루미늄트렌치', 3, 'h', '높이', 'H', 'mm'
    -- 116 그레이팅수로용: 사이트 '(폭X길이X높이)' · 예 150*1000*25
    UNION ALL SELECT 116, '그레이팅수로용', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 116, '그레이팅수로용', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 116, '그레이팅수로용', 3, 'h', '높이', 'H', 'mm'
    -- 117 그레이팅맨홀(집수정)용: 사이트 '(폭X길이X높이)' · 예 400*500*44_국산
    UNION ALL SELECT 117, '그레이팅맨홀(집수정)용', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 117, '그레이팅맨홀(집수정)용', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 117, '그레이팅맨홀(집수정)용', 3, 'h', '높이', 'H', 'mm'
    -- 118 플륨관용(날개형U-TYPE): 사이트 '(폭X길이X높이)' · 예 185*995*25
    UNION ALL SELECT 118, '플륨관용(날개형U-TYPE)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 118, '플륨관용(날개형U-TYPE)', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 118, '플륨관용(날개형U-TYPE)', 3, 'h', '높이', 'H', 'mm'
    -- 120 안전그레이팅: 사이트 '(폭X길이X높이)' · 예 200*1000*25(4.5T)_벌집그레이팅_국산
    UNION ALL SELECT 120, '안전그레이팅', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 120, '안전그레이팅', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 120, '안전그레이팅', 3, 'h', '높이', 'H', 'mm'
    -- 121 악취차단오물망그레이팅: 사이트 '(폭X길이X높이)' · 예 300*1000*50_국산
    UNION ALL SELECT 121, '악취차단오물망그레이팅', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 121, '악취차단오물망그레이팅', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 121, '악취차단오물망그레이팅', 3, 'h', '높이', 'H', 'mm'
    -- 123 스틸타공그레이팅: 사이트 '(폭X길이X높이)' · 예 195*995*25_엠보싱_국산
    UNION ALL SELECT 123, '스틸타공그레이팅', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 123, '스틸타공그레이팅', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 123, '스틸타공그레이팅', 3, 'h', '높이', 'H', 'mm'
    -- 124 중하중그레이팅수로용: 사이트 '(폭X길이X높이)' · 예 200*1000*50(7*4)_국산
    UNION ALL SELECT 124, '중하중그레이팅수로용', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 124, '중하중그레이팅수로용', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 124, '중하중그레이팅수로용', 3, 'h', '높이', 'H', 'mm'
    -- 125 중하중그레이팅맨홀(집수정)용: 사이트 '(폭X길이X높이(I-BAR))' · 앵글미포함 · 예 400*500*50(7*4)
    UNION ALL SELECT 125, '중하중그레이팅맨홀(집수정)용', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 125, '중하중그레이팅맨홀(집수정)용', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 125, '중하중그레이팅맨홀(집수정)용', 3, 'h', '높이', 'H', 'mm'
    -- 126 안전중하중그레이팅수로용: 사이트 '(폭X길이X높이(I-BAR))' · 앵글미포함 · 예 300*1000*50(7*4)_국산
    UNION ALL SELECT 126, '안전중하중그레이팅수로용', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 126, '안전중하중그레이팅수로용', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 126, '안전중하중그레이팅수로용', 3, 'h', '높이', 'H', 'mm'
    -- 127 안전중하중그레이팅맨홀(집수정)용: 사이트 '(폭X길이X높이(I-BAR))' · 앵글미포함 · 예 400*500*50(7*4)_국산
    UNION ALL SELECT 127, '안전중하중그레이팅맨홀(집수정)용', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 127, '안전중하중그레이팅맨홀(집수정)용', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 127, '안전중하중그레이팅맨홀(집수정)용', 3, 'h', '높이', 'H', 'mm'
    -- 137 파형강관(유공관): 사이트 '(내경X길이X두께_파형)' · 예 D150*6000*1.6T_0RS(유공)
    UNION ALL SELECT 137, '파형강관(유공관)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 137, '파형강관(유공관)', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 137, '파형강관(유공관)', 3, 't', '두께', 'T', 'mm'
    -- 138 파형강관(내면피복): 사이트 '(내경X길이X두께_파형)' · 예 D150*6000*1.6T_0RS
    UNION ALL SELECT 138, '파형강관(내면피복)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 138, '파형강관(내면피복)', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 138, '파형강관(내면피복)', 3, 't', '두께', 'T', 'mm'
    -- 139 파형강관(내면피복)유공관: 사이트 '(내경X길이X두께_파형)' · 예 D150*6000*1.6T_0RS(유공)
    UNION ALL SELECT 139, '파형강관(내면피복)유공관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 139, '파형강관(내면피복)유공관', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 139, '파형강관(내면피복)유공관', 3, 't', '두께', 'T', 'mm'
    -- 140 파형강관(양면피복): 사이트 '(내경X길이X두께_파형)' · 예 D150*6000*1.6T_0RS
    UNION ALL SELECT 140, '파형강관(양면피복)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 140, '파형강관(양면피복)', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 140, '파형강관(양면피복)', 3, 't', '두께', 'T', 'mm'
    -- 141 파형강관(양면피복)유공관: 사이트 '(내경X길이X두께_파형)' · 예 D150*6000*1.6T_0RS(유공)
    UNION ALL SELECT 141, '파형강관(양면피복)유공관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 141, '파형강관(양면피복)유공관', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 141, '파형강관(양면피복)유공관', 3, 't', '두께', 'T', 'mm'
    -- 142 체크무늬철판: 사이트 '(폭X길이X높이X두께)' · 예 500*500*50*3.2T_국산
    UNION ALL SELECT 142, '체크무늬철판', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 142, '체크무늬철판', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 142, '체크무늬철판', 3, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 142, '체크무늬철판', 4, 't', '두께', 'T', 'mm'
    -- 150 화강경계도로: 사이트 '(폭X높이)' · 예 100*100_#377
    UNION ALL SELECT 150, '화강경계도로', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 150, '화강경계도로', 2, 'h', '높이', 'H', 'mm'
    -- 152 화강경계낮춤: 사이트 '(폭X높이)' · 예 150*100_#377
    UNION ALL SELECT 152, '화강경계낮춤', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 152, '화강경계낮춤', 2, 'h', '높이', 'H', 'mm'
    -- 162 돌망태(개비온): 사이트 '(폭X높이X두께)' · 내경기준 · 예 1000*500*500
    UNION ALL SELECT 162, '돌망태(개비온)', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 162, '돌망태(개비온)', 2, 'h', '높이', 'H', 'mm'
    UNION ALL SELECT 162, '돌망태(개비온)', 3, 't', '두께', 'T', 'mm'
    -- 163 PE이중벽관1종: 사이트 '(내경X길이)' · 내경기준 · 예 D150*6000
    UNION ALL SELECT 163, 'PE이중벽관1종', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 163, 'PE이중벽관1종', 2, 'l', '길이', 'L', 'mm'
    -- 164 PE이중벽관2종: 사이트 '(내경X길이)' · 내경기준 · 예 D150*6000
    UNION ALL SELECT 164, 'PE이중벽관2종', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 164, 'PE이중벽관2종', 2, 'l', '길이', 'L', 'mm'
    -- 165 PE이중벽관3종: 사이트 '(내경X길이)' · 내경기준 · 예 D150*4000
    UNION ALL SELECT 165, 'PE이중벽관3종', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 165, 'PE이중벽관3종', 2, 'l', '길이', 'L', 'mm'
    -- 166 PE이중벽관(KS): 사이트 '(내경X길이)' · 내경기준 · 예 D150*6000
    UNION ALL SELECT 166, 'PE이중벽관(KS)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 166, 'PE이중벽관(KS)', 2, 'l', '길이', 'L', 'mm'
    -- 167 PE이중벽관1종(유공관): 사이트 '(내경X길이)' · 내경기준 · 예 D150*6000
    UNION ALL SELECT 167, 'PE이중벽관1종(유공관)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 167, 'PE이중벽관1종(유공관)', 2, 'l', '길이', 'L', 'mm'
    -- 168 PE이중벽관2종(유공관): 사이트 '(내경X길이)' · 내경기준 · 예 D150*6000
    UNION ALL SELECT 168, 'PE이중벽관2종(유공관)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 168, 'PE이중벽관2종(유공관)', 2, 'l', '길이', 'L', 'mm'
    -- 169 PE이중벽관3종(유공관): 사이트 '(내경X길이)' · 내경기준 · 예 D150*4000
    UNION ALL SELECT 169, 'PE이중벽관3종(유공관)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 169, 'PE이중벽관3종(유공관)', 2, 'l', '길이', 'L', 'mm'
    -- 172 PE다중벽관(KS): 사이트 '(내경X길이)' · 내경기준 · 예 D150*6000
    UNION ALL SELECT 172, 'PE다중벽관(KS)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 172, 'PE다중벽관(KS)', 2, 'l', '길이', 'L', 'mm'
    -- 173 PE다중벽관(유공관): 사이트 '(내경X길이)' · 내경기준 · 예 D150*6000
    UNION ALL SELECT 173, 'PE다중벽관(유공관)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 173, 'PE다중벽관(유공관)', 2, 'l', '길이', 'L', 'mm'
    -- 174 고강성PVC이중벽관: 사이트 '(내경X길이)' · 내경기준 · 예 D150*6000
    UNION ALL SELECT 174, '고강성PVC이중벽관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 174, '고강성PVC이중벽관', 2, 'l', '길이', 'L', 'mm'
    -- 176 PE배수로: 사이트 '내경(폭X깊이X길이)' · 예 200*150*1000_A형
    UNION ALL SELECT 176, 'PE배수로', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 176, 'PE배수로', 2, 'depth', '깊이', 'D', 'mm'
    UNION ALL SELECT 176, 'PE배수로', 3, 'l', '길이', 'L', 'mm'
    -- 182 PVC관VN(구VG1): 사이트 '(내경X길이)' · 내경기준 · 예 D16*4000
    UNION ALL SELECT 182, 'PVC관VN(구VG1)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 182, 'PVC관VN(구VG1)', 2, 'l', '길이', 'L', 'mm'
    -- 183 PVC관VN(구VG2): 사이트 '(내경X길이)' · 내경기준 · 예 D35*4000
    UNION ALL SELECT 183, 'PVC관VN(구VG2)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 183, 'PVC관VN(구VG2)', 2, 'l', '길이', 'L', 'mm'
    -- 187 HIVP내충격용수도관: 사이트 '(내경X길이)' · 내경기준 · 예 D16*4000_직관
    UNION ALL SELECT 187, 'HIVP내충격용수도관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 187, 'HIVP내충격용수도관', 2, 'l', '길이', 'L', 'mm'
    -- 188 HIVG1직관: 사이트 '(내경X길이)' · 내경기준 · 예 D100*6000
    UNION ALL SELECT 188, 'HIVG1직관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 188, 'HIVG1직관', 2, 'l', '길이', 'L', 'mm'
    -- 189 HIVG1편수칼라관: 사이트 '(내경X길이)' · 내경기준 · 예 D100*6000
    UNION ALL SELECT 189, 'HIVG1편수칼라관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 189, 'HIVG1편수칼라관', 2, 'l', '길이', 'L', 'mm'
    -- 190 HIVG2직관: 사이트 '(내경X길이)' · 내경기준 · 예 D100*6000
    UNION ALL SELECT 190, 'HIVG2직관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 190, 'HIVG2직관', 2, 'l', '길이', 'L', 'mm'
    -- 191 HIVG2편수칼라관: 사이트 '(내경X길이)' · 내경기준 · 예 D100*6000
    UNION ALL SELECT 191, 'HIVG2편수칼라관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 191, 'HIVG2편수칼라관', 2, 'l', '길이', 'L', 'mm'
    -- 194 HDPE이중벽관(직관): 사이트 '(내경X길이)' · 내경기준 · 예 D100*6000
    UNION ALL SELECT 194, 'HDPE이중벽관(직관)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 194, 'HDPE이중벽관(직관)', 2, 'l', '길이', 'L', 'mm'
    -- 195 HDPE이중벽관(유공관): 사이트 '(내경X길이)' · 내경기준 · 예 D100*6000
    UNION ALL SELECT 195, 'HDPE이중벽관(유공관)', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 195, 'HDPE이중벽관(유공관)', 2, 'l', '길이', 'L', 'mm'
    -- 204 카스토퍼: 사이트 '(가로X세로X높이)' · 예 750*150*120_고무(아스팔트용앙카)
    UNION ALL SELECT 204, '카스토퍼', 1, 'w', '가로', 'W', 'mm'
    UNION ALL SELECT 204, '카스토퍼', 2, 'l', '세로', 'L', 'mm'
    UNION ALL SELECT 204, '카스토퍼', 3, 'h', '높이', 'H', 'mm'
    -- 205 과속방지턱: 사이트 '(폭X길이X높이)' · 예 400*2000*50
    UNION ALL SELECT 205, '과속방지턱', 1, 'w', '폭', 'W', 'mm'
    UNION ALL SELECT 205, '과속방지턱', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 205, '과속방지턱', 3, 'h', '높이', 'H', 'mm'
    -- 269 파형강관: 사이트 '(내경X길이X두께_파형)' · 예 D150*6000*1.6T_0RS
    UNION ALL SELECT 269, '파형강관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 269, '파형강관', 2, 'l', '길이', 'L', 'mm'
    UNION ALL SELECT 269, '파형강관', 3, 't', '두께', 'T', 'mm'
    -- 272 콘크리트경계석: 사이트 '(윗면X밑면X높이)' · 예 120*120*120(SA)
    UNION ALL SELECT 272, '콘크리트경계석', 1, 'w_t', '상부폭', 'WT', 'mm'
    UNION ALL SELECT 272, '콘크리트경계석', 2, 'w_b', '하부폭', 'WB', 'mm'
    UNION ALL SELECT 272, '콘크리트경계석', 3, 'h', '높이', 'H', 'mm'
    -- 275 PE다중벽관: 사이트 '(내경X길이)' · 내경기준 · 예 D150*6000
    UNION ALL SELECT 275, 'PE다중벽관', 1, 'd_i', '내경', 'D', 'mm'
    UNION ALL SELECT 275, 'PE다중벽관', 2, 'l', '길이', 'L', 'mm'
) v
JOIN categories c ON c.id = v.cid AND c.name = v.cname
WHERE NOT EXISTS (SELECT 1 FROM category_spec_axes x WHERE x.category_id = c.id);

-- [3] 확인(읽기 전용)
-- SELECT COUNT(DISTINCT category_id) FROM category_spec_axes;   -- 확정 7 + 103

-- [롤백] 이 파일로 넣은 행만 지움(승인 후에만). category_spec_axes 는 가격 칸 없음
-- DELETE FROM category_spec_axes WHERE category_id IN (22, 23, 25, 26, 27, 28, 29, 30, 33, 34, 35, 36, 37, 38, 42, 43, 44, 45, 46, 51, 52, 53, 54, 55, 56, 57, 58, 60, 63, 64, 65, 69, 70, 71, 72, 74, 75, 77, 78, 80, 81, 83, 84, 85, 86, 87, 91, 92, 93, 95, 96, 99, 101, 102, 103, 105, 106, 107, 115, 116, 117, 118, 120, 121, 123, 124, 125, 126, 127, 137, 138, 139, 140, 141, 142, 150, 152, 162, 163, 164, 165, 166, 167, 168, 169, 172, 173, 174, 176, 182, 183, 187, 188, 189, 190, 191, 194, 195, 204, 205, 269, 272, 275);
