-- ─── ⑤ 상용콘크리트 매핑: 분류(vendor_category_map) + 치수 칸(vendor_axis_map) ─────────────────────────────
-- 초안(2026-11-12) — 실행 안 함. 승인 뒤 database/ 마이그레이션으로 옮겨 실행. 설계: docs/02-design/multi-vendor-db.md
-- 매핑 대조표·치수 칸 맞춤표 확정본(2026-11-12). 상품 없는 카탈로그 품목도 분류 매핑은 넣음(칸 맞춤은 상품 있는 것만).
-- 인조화강블록 투수/불투수(syc-s54·s55)는 상품을 투수·불투수로 나눈 뒤에 맞음(지금 옵션 "투수/불투수" 는 어느 줄에도 안 맞음 → 반영 때 경고).

INSERT INTO vendor_category_map (vendor_id, vendor_category, match_type, match_value, std_category_id, confidence, note)
SELECT v.id, x.vc, x.mt, x.mv, x.std, x.cf, x.note FROM (
    SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 33 AS std, '상' AS cf, '표준이 형식별 품목(A·B·AN·Z형)으로 나뉨 — 모델 코드로 나눔' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 34 AS std, '상' AS cf, '표준이 형식별 품목(A·B·AN·Z형)으로 나뉨 — 모델 코드로 나눔' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 35 AS std, '상' AS cf, '표준이 형식별 품목(A·B·AN·Z형)으로 나뉨 — 모델 코드로 나눔' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 36 AS std, '상' AS cf, '표준이 형식별 품목(A·B·AN·Z형)으로 나뉨 — 모델 코드로 나눔' AS note
    UNION ALL SELECT 'syc-s8' AS vc, 'all' AS mt, '' AS mv, 38 AS std, '중' AS cf, '유공 침투측구수로관 ≈ 침투측구수로관(앵글부착) / 후보: 37 측구수로관유공(앵글부착)' AS note
    UNION ALL SELECT 'syc-s78' AS vc, 'all' AS mt, '' AS mv, 63 AS std, '상' AS cf, '이름 같음(침투집수정)' AS note
    UNION ALL SELECT 'syc-s9' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-001') AS std, '새' AS cf, '표준에 높이조절용 없음 → 측구수로관 아래 새 품목' AS note
    UNION ALL SELECT 'syc-s10' AS vc, 'all' AS mt, '' AS mv, 23 AS std, '상' AS cf, '벤치플륨Ⅱ = 벤치2종' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 24 AS std, '상' AS cf, '벤치플륨Ⅲ = 수로관(벤치3종)' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 22 AS std, '상' AS cf, 'U형 플륨 = U형플륨관(벤치1종)' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 25 AS std, '상' AS cf, '이름 같음' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-02' AS mv, 40 AS std, '중' AS cf, '표준은 NS-02·03만 있음, NS-01 은 새 품목. 상품명(원형수로관 vs 돌무늬원형사각수로관) 같은 제품인지 확인' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-03' AS mv, 41 AS std, '중' AS cf, '표준은 NS-02·03만 있음, NS-01 은 새 품목. 상품명(원형수로관 vs 돌무늬원형사각수로관) 같은 제품인지 확인' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-01' AS mv, (SELECT id FROM categories WHERE slug = 'std-002') AS std, '중' AS cf, '표준은 NS-02·03만 있음, NS-01 은 새 품목. 상품명(원형수로관 vs 돌무늬원형사각수로관) 같은 제품인지 확인' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 39 AS std, '상' AS cf, '이름 같음(RDG)' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'A형' AS mv, 43 AS std, '하' AS cf, '표준은 AN·BN·CN·DN형 — A→AN, B→BN 로 추정. 같은 형식인지 확인 필요 / 후보: 45 CN형, 46 DN형, 47 무공' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'B형' AS mv, 44 AS std, '하' AS cf, '표준은 AN·BN·CN·DN형 — A→AN, B→BN 로 추정. 같은 형식인지 확인 필요 / 후보: 45 CN형, 46 DN형, 47 무공' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-003') AS std, '새' AS cf, '표준의 원심력사각수로관은 무개·유개만 — W2·S2 는 새 품목' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 48 AS std, '상' AS cf, '원심력사각수로관(무개)/(유개)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 49 AS std, '상' AS cf, '원심력사각수로관(무개)/(유개)' AS note
    UNION ALL SELECT 'syc-s18' AS vc, 'all' AS mt, '' AS mv, 115 AS std, '중' AS cf, '알루미늄트렌치(스틸제품 › 무소음트렌치)' AS note
    UNION ALL SELECT 'syc-s80' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-004') AS std, '새' AS cf, '소켓(부속)은 표준에 없음 — 소켓 3개 품목(엔젤·엔드·코너·연결 / 단차 / 이형)을 하나로' AS note
    UNION ALL SELECT 'syc-s81' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-004') AS std, '새' AS cf, '위와 같은 새 품목' AS note
    UNION ALL SELECT 'syc-s82' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-004') AS std, '새' AS cf, '위와 같은 새 품목' AS note
    UNION ALL SELECT 'syc-w1' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-005') AS std, '새' AS cf, '표준은 1종·2종만 — 3종 새 품목 / 후보: 29 1종, 30 2종' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 50 AS std, '하' AS cf, '흄관 형식(A·B형) 정보가 원본에 없음 — B형으로 추정 / 후보: 51 흄관(A형)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 54 AS std, '상' AS cf, 'V·R관 = VR관(흄관대용)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 9 AS std, '상' AS cf, '1련암거' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-006') AS std, '새' AS cf, '표준에 2련 없음 — 1련암거 옆에 새 품목' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 21 AS std, '중' AS cf, '개거수로 ≈ 개거(상하·벽체 두께 칸 구성이 같음) / 후보: 26 개거수로관(플륨형)' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 59 AS std, '상' AS cf, '사각맨홀(집수정)' AS note
    UNION ALL SELECT 'syc-s25' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-007') AS std, '새' AS cf, '표준에 콘크리트 빗물받이 집수정 없음 / 후보: 105 빗물받이블록, 59 사각맨홀' AS note
    UNION ALL SELECT 'syc-s26' AS vc, 'all' AS mt, '' AS mv, 61 AS std, '상' AS cf, '원형맨홀(소형맨홀 1~4호)' AS note
    UNION ALL SELECT 'syc-s27' AS vc, 'all' AS mt, '' AS mv, 68 AS std, '하' AS cf, '상품 없음(카탈로그만). 재질 확인 필요' AS note
    UNION ALL SELECT 'syc-s28' AS vc, 'all' AS mt, '' AS mv, 66 AS std, '상' AS cf, '조립식원형맨홀(상부·연직·하부구체)' AS note
    UNION ALL SELECT 'syc-s83' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-008') AS std, '새' AS cf, '부속 — 표준에 없음' AS note
    UNION ALL SELECT 'syc-s29' AS vc, 'all' AS mt, '' AS mv, 64 AS std, '중' AS cf, '전기통신용 맨홀 → 전기맨홀 / 후보: 65 통신맨홀' AS note
    UNION ALL SELECT 'syc-s30' AS vc, 'all' AS mt, '' AS mv, 65 AS std, '상' AS cf, '통신맨홀(수공 1~3호)' AS note
    UNION ALL SELECT 'syc-s31' AS vc, 'all' AS mt, '' AS mv, 103 AS std, '상' AS cf, '가로등기초(콘크리트제품 › 기타)' AS note
    UNION ALL SELECT 'syc-s32' AS vc, 'all' AS mt, '' AS mv, 174 AS std, '상' AS cf, '상품 없음(카탈로그만)' AS note
    UNION ALL SELECT 'syc-s33' AS vc, 'all' AS mt, '' AS mv, 163 AS std, '중' AS cf, '상품 없음 — 종(1·2·3종) 정보 없어 1종으로 추정 / 후보: 164, 165, 166' AS note
    UNION ALL SELECT 'syc-s34' AS vc, 'all' AS mt, '' AS mv, 172 AS std, '중' AS cf, '상품 없음 — 삼중벽 ≈ 다중벽관' AS note
    UNION ALL SELECT 'syc-s35' AS vc, 'name' AS mt, 'VG1' AS mv, 182 AS std, '상' AS cf, '상품 없음 — PVC관VN(구VG1·VG2)' AS note
    UNION ALL SELECT 'syc-s35' AS vc, 'name' AS mt, 'VG2' AS mv, 183 AS std, '상' AS cf, '상품 없음 — PVC관VN(구VG1·VG2)' AS note
    UNION ALL SELECT 'syc-s36' AS vc, 'all' AS mt, '' AS mv, 185 AS std, '상' AS cf, 'PVC오수받이(유입·유출구 칸도 같음)' AS note
    UNION ALL SELECT 'syc-s37' AS vc, 'all' AS mt, '' AS mv, 194 AS std, '중' AS cf, '상품 없음 — HDPE 이중벽관(직관·유공) / 후보: 195 유공관' AS note
    UNION ALL SELECT 'syc-s38' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-009') AS std, '새' AS cf, '상품 없음 — 표준에 없음' AS note
    UNION ALL SELECT 'syc-s39' AS vc, 'name' AS mt, '무공' AS mv, 180 AS std, '상' AS cf, '상품 없음 — THP주름관·유공관' AS note
    UNION ALL SELECT 'syc-s39' AS vc, 'name' AS mt, '유공' AS mv, 181 AS std, '상' AS cf, '상품 없음 — THP주름관·유공관' AS note
    UNION ALL SELECT 'syc-s40' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-010') AS std, '새' AS cf, '상품 없음 — 표준에 없음' AS note
    UNION ALL SELECT 'syc-s41' AS vc, 'all' AS mt, '' AS mv, 177 AS std, '상' AS cf, 'PE빗물받이' AS note
    UNION ALL SELECT 'syc-s42' AS vc, 'all' AS mt, '' AS mv, 178 AS std, '상' AS cf, 'PE오수받이' AS note
    UNION ALL SELECT 'syc-s43' AS vc, 'all' AS mt, '' AS mv, 177 AS std, '중' AS cf, '다용도 빗물받이 → PE빗물받이' AS note
    UNION ALL SELECT 'syc-s44' AS vc, 'all' AS mt, '' AS mv, 177 AS std, '중' AS cf, '가정용 빗물받이 → PE빗물받이' AS note
    UNION ALL SELECT 'syc-s45' AS vc, 'all' AS mt, '' AS mv, 179 AS std, '상' AS cf, 'PE홈통받이' AS note
    UNION ALL SELECT 'syc-s46' AS vc, 'all' AS mt, '' AS mv, 176 AS std, '상' AS cf, 'PE배수로' AS note
    UNION ALL SELECT 'syc-s47' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-011') AS std, '새' AS cf, '표준에 없음 — PE배수 아래' AS note
    UNION ALL SELECT 'syc-s48' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-012') AS std, '새' AS cf, '표준에 없음 — PE배수 아래' AS note
    UNION ALL SELECT 'syc-s84' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-013') AS std, '새' AS cf, '표준에 없음 — PE배수 아래' AS note
    UNION ALL SELECT 'syc-s49' AS vc, 'all' AS mt, '' AS mv, 116 AS std, '하' AS cf, '상품 없음 — 그레이팅 종류 모름(수로용으로 추정) / 후보: 117 맨홀용' AS note
    UNION ALL SELECT 'syc-s50' AS vc, 'all' AS mt, '' AS mv, 128 AS std, '하' AS cf, '상품 없음 — 디자인그레이팅(수로용으로 추정) / 후보: 129 맨홀용' AS note
    UNION ALL SELECT 'syc-s51' AS vc, 'all' AS mt, '' AS mv, 108 AS std, '상' AS cf, '주철뚜껑(우수/오수/전기/통신)' AS note
    UNION ALL SELECT 'syc-s52' AS vc, 'all' AS mt, '' AS mv, 112 AS std, '하' AS cf, '사각 맨홀 뚜껑 — 각종주철로 추정 / 후보: 109 사각주철(수로용)' AS note
    UNION ALL SELECT 'syc-s85' AS vc, 'all' AS mt, '' AS mv, 108 AS std, '중' AS cf, '원형 맨홀 뚜껑 → 주철뚜껑' AS note
    UNION ALL SELECT 'syc-s53' AS vc, 'all' AS mt, '' AS mv, 108 AS std, '하' AS cf, 'BS수공 뚜껑(사각) — 주철뚜껑으로 추정 / 후보: 112 각종주철' AS note
    UNION ALL SELECT 'syc-s86' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-014') AS std, '새' AS cf, '부속 — 표준에 없음' AS note
    UNION ALL SELECT 'syc-s54' AS vc, 'name' AS mt, '투수' AS mv, 71 AS std, '중' AS cf, '한 상품이 투수/불투수 둘 다 — 상품을 투수·불투수로 나눠야 매핑됨' AS note
    UNION ALL SELECT 'syc-s54' AS vc, 'name' AS mt, '불투수' AS mv, 72 AS std, '중' AS cf, '한 상품이 투수/불투수 둘 다 — 상품을 투수·불투수로 나눠야 매핑됨' AS note
    UNION ALL SELECT 'syc-s55' AS vc, 'name' AS mt, '투수' AS mv, 71 AS std, '중' AS cf, '위와 같음(200*100)' AS note
    UNION ALL SELECT 'syc-s55' AS vc, 'name' AS mt, '불투수' AS mv, 72 AS std, '중' AS cf, '위와 같음(200*100)' AS note
    UNION ALL SELECT 'syc-s56' AS vc, 'all' AS mt, '' AS mv, 69 AS std, '상' AS cf, '인터로킹U형블럭' AS note
    UNION ALL SELECT 'syc-s57' AS vc, 'all' AS mt, '' AS mv, 70 AS std, '상' AS cf, '인터로킹I2형블럭' AS note
    UNION ALL SELECT 'syc-s58' AS vc, 'all' AS mt, '' AS mv, 75 AS std, '상' AS cf, '장애인사각블럭(점자블럭)' AS note
    UNION ALL SELECT 'syc-s59' AS vc, 'all' AS mt, '' AS mv, 75 AS std, '중' AS cf, '고강도 점자블럭 → 점자블럭' AS note
    UNION ALL SELECT 'syc-s60' AS vc, 'all' AS mt, '' AS mv, 97 AS std, '중' AS cf, '그린잔디블록 → 잔디블록 / 후보: 99 조경잔디블록' AS note
    UNION ALL SELECT 'syc-s61' AS vc, 'all' AS mt, '' AS mv, 97 AS std, '중' AS cf, '잔디블록(I형) → 잔디블록' AS note
    UNION ALL SELECT 'syc-s62' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-015') AS std, '새' AS cf, '표준에 없음 — 인터로킹(보도블럭) 아래 / 후보: 77 점토블럭' AS note
    UNION ALL SELECT 'syc-s63' AS vc, 'all' AS mt, '' AS mv, 84 AS std, '하' AS cf, '보강토 옹벽블록 — 무늬 정보 없어 민무늬로 추정 / 후보: 83 줄무늬, 85 혼합무늬' AS note
    UNION ALL SELECT 'syc-s64' AS vc, 'all' AS mt, '' AS mv, 86 AS std, '상' AS cf, '보강토마감형' AS note
    UNION ALL SELECT 'syc-s65' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-016') AS std, '새' AS cf, '표준에 없음 — 식생축조블록 아래로 추정 / 후보: 78 계단마감재' AS note
    UNION ALL SELECT 'syc-s66' AS vc, 'all' AS mt, '' AS mv, 99 AS std, '중' AS cf, '대형 잔디블록 → 조경잔디블록 / 후보: 97 잔디블록' AS note
    UNION ALL SELECT 'syc-s67' AS vc, 'all' AS mt, '' AS mv, 81 AS std, '중' AS cf, '보강토 식생 축조블록 → 축조블록(평면형) / 후보: 80 곡선형' AS note
    UNION ALL SELECT 'syc-s68' AS vc, 'all' AS mt, '' AS mv, 96 AS std, '하' AS cf, 'I형 호안 블록 — 용도(저류·식생) 모름 / 후보: 95 호안블록(저류용)' AS note
    UNION ALL SELECT 'syc-s69' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-017') AS std, '새' AS cf, '표준에 없음 — 콘크리트제품 › 기타' AS note
    UNION ALL SELECT 'syc-s70' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-018') AS std, '새' AS cf, '표준에 없음 — 콘크리트제품 › 기타' AS note
    UNION ALL SELECT 'syc-s71' AS vc, 'all' AS mt, '' AS mv, 92 AS std, '상' AS cf, '녹지경계블록' AS note
    UNION ALL SELECT 'syc-s72' AS vc, 'all' AS mt, '' AS mv, 90 AS std, '상' AS cf, '콘크리트경계석 / 후보: 91 고강도' AS note
    UNION ALL SELECT 'syc-s73' AS vc, 'all' AS mt, '' AS mv, 93 AS std, '상' AS cf, '인조화강경계석' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '도로' AS mv, 150 AS std, '중' AS cf, '도로·낮춤은 같음, 보차도는 표준에 없어 도로로' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '낮춤' AS mv, 152 AS std, '중' AS cf, '도로·낮춤은 같음, 보차도는 표준에 없어 도로로' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '보차도' AS mv, 150 AS std, '중' AS cf, '도로·낮춤은 같음, 보차도는 표준에 없어 도로로' AS note
    UNION ALL SELECT 'syc-s87' AS vc, 'all' AS mt, '' AS mv, 151 AS std, '상' AS cf, '화강경계경사' AS note
    UNION ALL SELECT 'syc-s75' AS vc, 'all' AS mt, '' AS mv, 132 AS std, '중' AS cf, '가로수보호틀 → 수목보호판(스틸제품 › 그레이팅) / 후보: 104 인조석, 161 화강석' AS note
    UNION ALL SELECT 'syc-s88' AS vc, 'all' AS mt, '' AS mv, 132 AS std, '중' AS cf, '위와 같음(원형)' AS note
    UNION ALL SELECT 'syc-s89' AS vc, 'all' AS mt, '' AS mv, 132 AS std, '중' AS cf, '위와 같음(정사각·직사각)' AS note
    UNION ALL SELECT 'syc-s76' AS vc, 'all' AS mt, '' AS mv, 136 AS std, '상' AS cf, '파형강관(표준 품목에 상품 0개) / 후보: 137~141 유공·피복' AS note
    UNION ALL SELECT 'syc-s77' AS vc, 'all' AS mt, '' AS mv, 144 AS std, '상' AS cf, '와이어메쉬(망눈·철선 칸도 같음)' AS note
    UNION ALL SELECT 'syc-w2' AS vc, 'all' AS mt, '' AS mv, 148 AS std, '상' AS cf, '스텐볼라드' AS note
    UNION ALL SELECT 'syc-w5' AS vc, 'all' AS mt, '' AS mv, 148 AS std, '중' AS cf, '스텐볼라드 U형 → 스텐볼라드' AS note
    UNION ALL SELECT 'syc-w3' AS vc, 'all' AS mt, '' AS mv, (SELECT id FROM categories WHERE slug = 'std-019') AS std, '새' AS cf, '실리콘(탄성) — 표준 우레탄볼라드와 재질 다름 / 후보: 206 우레탄볼라드' AS note
    UNION ALL SELECT 'syc-w4' AS vc, 'all' AS mt, '' AS mv, 154 AS std, '상' AS cf, '볼라드(석재제품 › 화강석)' AS note
) x JOIN vendors v ON v.code = 'syccrt'
WHERE x.std IS NOT NULL AND NOT EXISTS (SELECT 1 FROM vendor_category_map m WHERE m.vendor_id = v.id AND m.vendor_category = x.vc AND m.match_type = x.mt AND m.match_value = x.mv);

INSERT INTO vendor_axis_map (map_id, src_key, dst_key, target, confidence, note)
SELECT m.id, x.sk, x.dk, x.tg, x.cf, x.note FROM (
    SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'h' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 't2' AS sk, 't2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'w2' AS sk, 'w2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'h2' AS sk, 'h2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'h3' AS sk, 'h3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 't1' AS sk, 't1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 't3' AS sk, 't3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'h4' AS sk, 'h4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 't4' AS sk, 't4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'A형' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'h' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 't2' AS sk, 't2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'w2' AS sk, 'w2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'h2' AS sk, 'h2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'h3' AS sk, 'h3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 't1' AS sk, 't1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 't3' AS sk, 't3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'h4' AS sk, 'h4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 't4' AS sk, 't4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'B형' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'h' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 't2' AS sk, 't2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'w2' AS sk, 'w2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'h2' AS sk, 'h2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'h3' AS sk, 'h3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 't1' AS sk, 't1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 't3' AS sk, 't3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'h4' AS sk, 'h4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 't4' AS sk, 't4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'AN형' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'h' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 't2' AS sk, 't2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'w2' AS sk, 'w2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'h2' AS sk, 'h2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'h3' AS sk, 'h3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 't1' AS sk, 't1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 't3' AS sk, 't3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'h4' AS sk, 'h4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 't4' AS sk, 't4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s1' AS vc, 'code' AS mt, 'Z형' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s8' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s8' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'depth' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s8' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s8' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s78' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s78' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s78' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s78' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s9' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s9' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s9' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s9' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s9' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s9' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s10' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s10' AS vc, 'all' AS mt, '' AS mv, 'w_b' AS sk, 'w_b' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s10' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s10' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s10' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s10' AS vc, 'all' AS mt, '' AS mv, 't2' AS sk, 't' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s10' AS vc, 'all' AS mt, '' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s10' AS vc, 'all' AS mt, '' AS mv, 'r1' AS sk, 'r1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 'w_b' AS sk, 'w_b' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 't2' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 'x1' AS sk, 'x1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s11' AS vc, 'all' AS mt, '' AS mv, 'x2' AS sk, 'x2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'depth' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 'h2' AS sk, 'h2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 't1' AS sk, 't1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 'r1' AS sk, 'r1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s12' AS vc, 'all' AS mt, '' AS mv, 'r2' AS sk, 'r2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 'w_b' AS sk, 'w_b' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 't2' AS sk, 't' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 'x1' AS sk, 'x1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s13' AS vc, 'all' AS mt, '' AS mv, 'x2' AS sk, 'x2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-02' AS mv, 'd' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-02' AS mv, 'w' AS sk, 'd_o' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-02' AS mv, 'h' AS sk, 'depth' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-02' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-02' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-03' AS mv, 'd' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-03' AS mv, 'w' AS sk, 'd_o' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-03' AS mv, 'h' AS sk, 'depth' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-03' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-03' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-01' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-01' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-01' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-01' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s14' AS vc, 'code' AS mt, 'NS-01' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'w_o' AS sk, 'd_o' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'h_o' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'l3' AS sk, 'l3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s15' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'A형' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'A형' AS mv, 'w_o' AS sk, 'w_o' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'A형' AS mv, 'h_o' AS sk, 'h_o' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'A형' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'A형' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'A형' AS mv, 't_top' AS sk, 't_top' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'A형' AS mv, 't_wall' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'A형' AS mv, 't_bottom' AS sk, 't_bottom' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'B형' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'B형' AS mv, 'w_o' AS sk, 'w_o' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'B형' AS mv, 'h_o' AS sk, 'h_o' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'B형' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'B형' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'B형' AS mv, 't_top' AS sk, 't_top' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'B형' AS mv, 't_wall' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s16' AS vc, 'code' AS mt, 'B형' AS mv, 't_bottom' AS sk, 't_bottom' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 't2' AS sk, 't2' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'x1' AS sk, 'x1' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'x2' AS sk, 'x2' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'a2_w' AS sk, 'a2_w' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'a2_l' AS sk, 'a2_l' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s17' AS vc, 'all' AS mt, '' AS mv, 'a2_t' AS sk, 'a2_t' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'w' AS sk, 'd_o' AS dk, 'column' AS tg, '하' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 't' AS sk, 't' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 't2' AS sk, 't' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'x1' AS sk, 'x1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'x2' AS sk, 'x2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'a2_w' AS sk, 'a2_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'a2_l' AS sk, 'a2_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '무개' AS mv, 'a2_t' AS sk, 'a2_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'w' AS sk, 'd_o' AS dk, 'column' AS tg, '하' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 't' AS sk, 't' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 't2' AS sk, 't' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'x1' AS sk, 'x1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'x2' AS sk, 'x2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'a2_w' AS sk, 'a2_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'a2_l' AS sk, 'a2_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s79' AS vc, 'option' AS mt, '유개' AS mv, 'a2_t' AS sk, 'a2_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s18' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s18' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s18' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s18' AS vc, 'all' AS mt, '' AS mv, 'x1' AS sk, 'x1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s18' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s18' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s18' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s80' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s80' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s80' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s80' AS vc, 'all' AS mt, '' AS mv, 'x1' AS sk, 'x1' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s80' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s80' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s80' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s81' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s81' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s81' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s81' AS vc, 'all' AS mt, '' AS mv, 'x1' AS sk, 'x1' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s81' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s81' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s81' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s82' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s82' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s82' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s82' AS vc, 'all' AS mt, '' AS mv, 'x1' AS sk, 'x1' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s82' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s82' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s82' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-w1' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-w1' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-w1' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-w1' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'l3' AS sk, 'l3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'l4' AS sk, 'l4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'l5' AS sk, 'l5' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'd1' AS sk, 'd1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'd2' AS sk, 'd2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'd3' AS sk, 'd3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s19' AS vc, 'all' AS mt, '' AS mv, 'd4' AS sk, 'd4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'l3' AS sk, 'l3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'l4' AS sk, 'l4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'l5' AS sk, 'l5' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'l6' AS sk, 'l6' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'd1' AS sk, 'd1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'd2' AS sk, 'd2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'd3' AS sk, 'd3' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'd4' AS sk, 'd4' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s20' AS vc, 'all' AS mt, '' AS mv, 'd5' AS sk, 'd5' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 'w_o' AS sk, 'w_o' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 'h_o' AS sk, 'h_o' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 't_top' AS sk, 't_top' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 't_wall' AS sk, 't_wall' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 't_bottom' AS sk, 't_bottom' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s21' AS vc, 'all' AS mt, '' AS mv, 'c' AS sk, 'c' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 'w_o' AS sk, 'w_o' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 'h_o' AS sk, 'h_o' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 't_top' AS sk, 't_top' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 't_wall' AS sk, 't_wall' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 't_bottom' AS sk, 't_bottom' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 't_mid' AS sk, 't_mid' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s22' AS vc, 'all' AS mt, '' AS mv, 'c' AS sk, 'c' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 'w_o' AS sk, 'w_o' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 'h_o' AS sk, 'h_o' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 't_top' AS sk, 't_tb' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 't_wall' AS sk, 't_wall' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 't_bottom' AS sk, 't_bottom' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 'l1' AS sk, 'l1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 'l2' AS sk, 'l2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s23' AS vc, 'all' AS mt, '' AS mv, 'c' AS sk, 'c' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 't_top' AS sk, 't_top' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 't_wall' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 't_bottom' AS sk, 't_bottom' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s24' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s25' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s25' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s25' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s25' AS vc, 'all' AS mt, '' AS mv, 't_wall' AS sk, 't_wall' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s25' AS vc, 'all' AS mt, '' AS mv, 't_bottom' AS sk, 't_bottom' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s25' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s25' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s25' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s26' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s26' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s26' AS vc, 'all' AS mt, '' AS mv, 't_wall' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s26' AS vc, 'all' AS mt, '' AS mv, 't_bottom' AS sk, 't_bottom' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s26' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s28' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s28' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s28' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s28' AS vc, 'all' AS mt, '' AS mv, 't_wall' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s83' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s83' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s83' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s83' AS vc, 'all' AS mt, '' AS mv, 't_wall' AS sk, 't_wall' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s29' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s29' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s29' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s29' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s30' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s30' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s30' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s30' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s31' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w_t' AS dk, 'column' AS tg, '하' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s31' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s31' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'extra' AS tg, '하' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s36' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd_i' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s36' AS vc, 'all' AS mt, '' AS mv, 'd_inlet' AS sk, 'd_inlet' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s36' AS vc, 'all' AS mt, '' AS mv, 'd_outlet' AS sk, 'd_outlet' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s41' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s41' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s41' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s41' AS vc, 'all' AS mt, '' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s41' AS vc, 'all' AS mt, '' AS mv, 'w2' AS sk, 'w2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s42' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s42' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s42' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s43' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s43' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s43' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s43' AS vc, 'all' AS mt, '' AS mv, 'w1' AS sk, 'w1' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s43' AS vc, 'all' AS mt, '' AS mv, 'w2' AS sk, 'w2' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s44' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s44' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s44' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s45' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s45' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s46' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s46' AS vc, 'all' AS mt, '' AS mv, 'depth' AS sk, 'depth' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s46' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s47' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s47' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s47' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s47' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s47' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s47' AS vc, 'all' AS mt, '' AS mv, 'a_t' AS sk, 'a_t' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
    UNION ALL SELECT 'syc-s48' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s48' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s48' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s84' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s84' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s84' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s51' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s51' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s51' AS vc, 'all' AS mt, '' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s52' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s52' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s52' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s52' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s85' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s85' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s85' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s85' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s53' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'extra' AS tg, '하' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s53' AS vc, 'all' AS mt, '' AS mv, 'd_o' AS sk, 'd_o' AS dk, 'extra' AS tg, '하' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s53' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'extra' AS tg, '하' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s53' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s53' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'extra' AS tg, '하' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s86' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s86' AS vc, 'all' AS mt, '' AS mv, 'd_o' AS sk, 'd_o' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s86' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s86' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s86' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s54' AS vc, 'name' AS mt, '투수' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s54' AS vc, 'name' AS mt, '투수' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s54' AS vc, 'name' AS mt, '투수' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s54' AS vc, 'name' AS mt, '불투수' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s54' AS vc, 'name' AS mt, '불투수' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s54' AS vc, 'name' AS mt, '불투수' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s55' AS vc, 'name' AS mt, '투수' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s55' AS vc, 'name' AS mt, '투수' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s55' AS vc, 'name' AS mt, '투수' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s55' AS vc, 'name' AS mt, '불투수' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s55' AS vc, 'name' AS mt, '불투수' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s55' AS vc, 'name' AS mt, '불투수' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s56' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s56' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s56' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s57' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s57' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s57' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s58' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s58' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s58' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s59' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s59' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s59' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s60' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s60' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s60' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s61' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s61' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s61' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s62' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s62' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s62' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s63' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s63' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'depth' AS dk, 'column' AS tg, '하' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s63' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 'h' AS dk, 'column' AS tg, '하' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s64' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s64' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'depth' AS dk, 'column' AS tg, '하' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s64' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 'h' AS dk, 'column' AS tg, '하' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s65' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s65' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s65' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s66' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s66' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s66' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 'h' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s67' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s67' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'h' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s67' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 'depth' AS dk, 'column' AS tg, '하' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s68' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s68' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s68' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 'h' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s69' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s69' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s69' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s70' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s70' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s70' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s71' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s71' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s71' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s72' AS vc, 'all' AS mt, '' AS mv, 'w_b' AS sk, 'w_b' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s72' AS vc, 'all' AS mt, '' AS mv, 'w_t' AS sk, 'w_t' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s72' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s72' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s73' AS vc, 'all' AS mt, '' AS mv, 'w_b' AS sk, 'w_b' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s73' AS vc, 'all' AS mt, '' AS mv, 'w_t' AS sk, 'w_t' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s73' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s73' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '도로' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '도로' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '도로' AS mv, 'l' AS sk, 'l' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '도로' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '낮춤' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '낮춤' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '낮춤' AS mv, 'l' AS sk, 'l' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '낮춤' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '보차도' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '보차도' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '보차도' AS mv, 'l' AS sk, 'l' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s74' AS vc, 'name' AS mt, '보차도' AS mv, 'h1' AS sk, 'h1' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s87' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s87' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h_high' AS dk, 'column' AS tg, '중' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s87' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s87' AS vc, 'all' AS mt, '' AS mv, 'h1' AS sk, 'h_low' AS dk, 'column' AS tg, '상' AS cf, '이름 바꿈' AS note
    UNION ALL SELECT 'syc-s75' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '중' AS cf, '표준 이름표에 칸 추가(목록)' AS note
    UNION ALL SELECT 'syc-s75' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s75' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s75' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s75' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s75' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s88' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '중' AS cf, '표준 이름표에 칸 추가(목록)' AS note
    UNION ALL SELECT 'syc-s88' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s88' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s88' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s88' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s88' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s89' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '중' AS cf, '표준 이름표에 칸 추가(목록)' AS note
    UNION ALL SELECT 'syc-s89' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s89' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s89' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s89' AS vc, 'all' AS mt, '' AS mv, 'a_w' AS sk, 'a_w' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s89' AS vc, 'all' AS mt, '' AS mv, 'a_l' AS sk, 'a_l' AS dk, 'extra' AS tg, '상' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-s76' AS vc, 'all' AS mt, '' AS mv, 'd_i' AS sk, 'd_i' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s76' AS vc, 'all' AS mt, '' AS mv, 'l' AS sk, 'l' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s76' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s76' AS vc, 'all' AS mt, '' AS mv, 'x1' AS sk, 'x1' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s76' AS vc, 'all' AS mt, '' AS mv, 'x2' AS sk, 'x2' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-s77' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s77' AS vc, 'all' AS mt, '' AS mv, 'v' AS sk, 'v' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s77' AS vc, 'all' AS mt, '' AS mv, 'mesh' AS sk, 'mesh' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s77' AS vc, 'all' AS mt, '' AS mv, 'wire_d' AS sk, 'wire_d' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-s77' AS vc, 'all' AS mt, '' AS mv, 'wire_no' AS sk, 'wire_no' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-w2' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-w2' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-w2' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-w2' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-w5' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-w5' AS vc, 'all' AS mt, '' AS mv, 'w' AS sk, 'w' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-w5' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '같은 칸' AS note
    UNION ALL SELECT 'syc-w5' AS vc, 'all' AS mt, '' AS mv, 't' AS sk, 't' AS dk, 'extra' AS tg, '중' AS cf, '상세(추가)' AS note
    UNION ALL SELECT 'syc-w3' AS vc, 'all' AS mt, '' AS mv, 'd' AS sk, 'd' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-w3' AS vc, 'all' AS mt, '' AS mv, 'h' AS sk, 'h' AS dk, 'column' AS tg, '상' AS cf, '표준 이름표로 채택(목록)' AS note
    UNION ALL SELECT 'syc-w3' AS vc, 'all' AS mt, '' AS mv, 'd1' AS sk, 'd1' AS dk, 'extra' AS tg, '상' AS cf, '표준 이름표로 채택(상세)' AS note
) x
JOIN vendors v ON v.code = 'syccrt'
JOIN vendor_category_map m ON m.vendor_id = v.id AND m.vendor_category = x.vc AND m.match_type = x.mt AND m.match_value = x.mv
WHERE NOT EXISTS (SELECT 1 FROM vendor_axis_map a WHERE a.map_id = m.id AND a.src_key = x.sk);
