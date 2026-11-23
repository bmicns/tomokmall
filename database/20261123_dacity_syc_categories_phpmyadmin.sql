-- ─── 동아산업 상품용 분류: syc_categories 에 대분류 8개 + 품목 204개 추가 (2026-11-23 사용자 결정 S29) ─────────────
-- 왜: 업체 일괄 등록(app/syc_bulk.php)은 syc_categories 의 품목 slug 만 받는다. 동아산업 엑셀(data/dacity/dacity-products-upload-temp-price.xlsx)의
--     분류코드 dac-<사이트 분류번호> 를 그대로 쓸 수 있게 품목을 만든다. 공통 분류 연결은 코드(app/catalog_map.php catalog_dac_std_slug —
--     dac-<번호> → categories.slug ktomok-<번호>)가 한다.
-- 대상: 엑셀에 상품이 있는 분류만(취급 중단 암거·암거몰드·수목보호판 제외). 이름 = 동아산업 사이트 분류 이름. 대분류 slug dac-g1~.
-- ⚠ 가격 보호(P0-4): 마진율 칸(discount_customer·discount_corporate)은 넣지 않는다(기본 0 — 고객 화면 가격은 반영본의 공통 분류 마진율).
--    마진율 칸이 있는 표라 원격 관리 API 는 막는다 → 사용자가 phpMyAdmin 에서 실행. 기존 행·가격은 바뀌지 않는다(새 행만).
-- 성격: 추가형. 다시 실행해도 중복 없음(slug 기준). 순서: 이 파일 → (동아산업 업체 등록) → 제품관리 동아산업 일괄 등록.
-- 되돌리기: 맨 아래 [롤백](상품이 들어가기 전에만 — 상품이 있으면 먼저 상품을 정리)

-- [1] 사전 확인(읽기 전용): 0 이 나와야 한다(이미 있으면 [2]·[3]은 있는 행을 건너뜀)
SELECT COUNT(*) AS dac_categories_before FROM syc_categories WHERE slug LIKE 'dac-%';

-- [2] 대분류 8개 ------------------------------------------------------------
INSERT INTO syc_categories (parent_id, name, description, slug, sort_order, is_active)
SELECT NULL, v.name, '동아산업', v.slug, v.sort_order, 1
FROM (
              SELECT '지상 배수로' AS name, 'dac-g1' AS slug, 1010 AS sort_order
    UNION ALL SELECT '지하 배수로', 'dac-g2', 1020
    UNION ALL SELECT '맨홀(하수/전기/통신)', 'dac-g3', 1030
    UNION ALL SELECT '그레이팅/맨홀수로덮개', 'dac-g4', 1040
    UNION ALL SELECT '보도블럭/경계/안전', 'dac-g5', 1050
    UNION ALL SELECT '공통자재(원자재)', 'dac-g6', 1060
    UNION ALL SELECT '하천블록/법면옹벽', 'dac-g7', 1070
    UNION ALL SELECT '(메뉴 외)', 'dac-g8', 1080
) v
WHERE NOT EXISTS (SELECT 1 FROM syc_categories x WHERE x.slug = v.slug);

-- [3] 품목 204개 (대분류 slug 로 parent_id 연결) ----------------------------------
INSERT INTO syc_categories (parent_id, name, description, slug, sort_order, is_active)
SELECT p.id, v.name, v.description, v.slug, v.sort_order, 1
FROM (
              SELECT 'dac-g1' AS parent_slug, '개거' AS name, '동아산업 플륨관/수로관 (사이트 분류번호 1060)' AS description, 'dac-1060' AS slug, 10 AS sort_order
    UNION ALL SELECT 'dac-g2', '흄관(B형)', '동아산업 콘크리트 관로 (사이트 분류번호 2210)', 'dac-2210', 20
    UNION ALL SELECT 'dac-g2', '흄관(A형)', '동아산업 콘크리트 관로 (사이트 분류번호 2220)', 'dac-2220', 30
    UNION ALL SELECT 'dac-g2', '유공흄관', '동아산업 콘크리트 관로 (사이트 분류번호 2230)', 'dac-2230', 40
    UNION ALL SELECT 'dac-g2', '접속흄관(B형)', '동아산업 콘크리트 관로 (사이트 분류번호 2250)', 'dac-2250', 50
    UNION ALL SELECT 'dac-g2', 'VR관(흄관대용)', '동아산업 콘크리트 관로 (사이트 분류번호 2260)', 'dac-2260', 60
    UNION ALL SELECT 'dac-g2', '레진관', '동아산업 콘크리트 관로 (사이트 분류번호 2310)', 'dac-2310', 70
    UNION ALL SELECT 'dac-g2', 'PE이중벽관1종', '동아산업 PE 관로 (사이트 분류번호 4310)', 'dac-4310', 80
    UNION ALL SELECT 'dac-g2', 'PE이중벽관2종', '동아산업 PE 관로 (사이트 분류번호 4320)', 'dac-4320', 90
    UNION ALL SELECT 'dac-g2', 'PE이중벽관3종', '동아산업 PE 관로 (사이트 분류번호 4330)', 'dac-4330', 100
    UNION ALL SELECT 'dac-g2', 'PE이중벽관(KS)', '동아산업 PE 관로 (사이트 분류번호 4340)', 'dac-4340', 110
    UNION ALL SELECT 'dac-g2', 'PE이중벽관1종(유공관)', '동아산업 PE 관로 (사이트 분류번호 4350)', 'dac-4350', 120
    UNION ALL SELECT 'dac-g2', 'PE이중벽관2종(유공관)', '동아산업 PE 관로 (사이트 분류번호 4360)', 'dac-4360', 130
    UNION ALL SELECT 'dac-g2', 'PE이중벽관3종(유공관)', '동아산업 PE 관로 (사이트 분류번호 4370)', 'dac-4370', 140
    UNION ALL SELECT 'dac-g2', 'PE다중벽관', '동아산업 PE 관로 (사이트 분류번호 4710)', 'dac-4710', 150
    UNION ALL SELECT 'dac-g2', 'PE다중벽관(유공관)', '동아산업 PE 관로 (사이트 분류번호 4720)', 'dac-4720', 160
    UNION ALL SELECT 'dac-g2', 'HDPE이중벽관(직관)', '동아산업 PE 관로 (사이트 분류번호 4575)', 'dac-4575', 170
    UNION ALL SELECT 'dac-g2', 'HDPE이중벽관(유공관)', '동아산업 PE 관로 (사이트 분류번호 4580)', 'dac-4580', 180
    UNION ALL SELECT 'dac-g2', 'THP주름관', '동아산업 PE 관로 (사이트 분류번호 4550)', 'dac-4550', 190
    UNION ALL SELECT 'dac-g2', 'THP유공관', '동아산업 PE 관로 (사이트 분류번호 4555)', 'dac-4555', 200
    UNION ALL SELECT 'dac-g2', 'PE이경소켓', '동아산업 PE 관로 (사이트 분류번호 4380)', 'dac-4380', 210
    UNION ALL SELECT 'dac-g2', '고강성PVC이중벽관', '동아산업 PVC 관로 (사이트 분류번호 4410)', 'dac-4410', 220
    UNION ALL SELECT 'dac-g2', '고강성PVC이경소켓', '동아산업 PVC 관로 (사이트 분류번호 4420)', 'dac-4420', 230
    UNION ALL SELECT 'dac-g2', 'PVC관VN(구VG1)', '동아산업 PVC 관로 (사이트 분류번호 4560)', 'dac-4560', 240
    UNION ALL SELECT 'dac-g2', 'PVC관VN(구VG2)', '동아산업 PVC 관로 (사이트 분류번호 4565)', 'dac-4565', 250
    UNION ALL SELECT 'dac-g2', 'PVC배수관이경소켓', '동아산업 PVC 관로 (사이트 분류번호 4569)', 'dac-4569', 260
    UNION ALL SELECT 'dac-g2', 'HIVG1직관', '동아산업 PVC 관로 (사이트 분류번호 4571)', 'dac-4571', 270
    UNION ALL SELECT 'dac-g2', 'HIVG1편수칼라관', '동아산업 PVC 관로 (사이트 분류번호 4572)', 'dac-4572', 280
    UNION ALL SELECT 'dac-g2', 'HIVG2직관', '동아산업 PVC 관로 (사이트 분류번호 4573)', 'dac-4573', 290
    UNION ALL SELECT 'dac-g2', 'HIVG2편수칼라관', '동아산업 PVC 관로 (사이트 분류번호 4574)', 'dac-4574', 300
    UNION ALL SELECT 'dac-g2', 'HIVP내충격용수도관', '동아산업 PVC 관로 (사이트 분류번호 4570)', 'dac-4570', 310
    UNION ALL SELECT 'dac-g2', '파형강관', '동아산업 파형강관 (사이트 분류번호 4610)', 'dac-4610', 320
    UNION ALL SELECT 'dac-g2', '파형강관(유공관)', '동아산업 파형강관 (사이트 분류번호 4615)', 'dac-4615', 330
    UNION ALL SELECT 'dac-g2', '파형강관(내면피복)', '동아산업 파형강관 (사이트 분류번호 4620)', 'dac-4620', 340
    UNION ALL SELECT 'dac-g2', '파형강관(내면피복)유공관', '동아산업 파형강관 (사이트 분류번호 4625)', 'dac-4625', 350
    UNION ALL SELECT 'dac-g2', '파형강관(양면피복)', '동아산업 파형강관 (사이트 분류번호 4630)', 'dac-4630', 360
    UNION ALL SELECT 'dac-g2', '파형강관(양면피복)유공관', '동아산업 파형강관 (사이트 분류번호 4635)', 'dac-4635', 370
    UNION ALL SELECT 'dac-g2', 'PE이중벽관분기구', '동아산업 분기구 (사이트 분류번호 7611)', 'dac-7611', 380
    UNION ALL SELECT 'dac-g2', 'PVC이중벽관분기구', '동아산업 분기구 (사이트 분류번호 7612)', 'dac-7612', 390
    UNION ALL SELECT 'dac-g2', 'PVC관(VN)분기구', '동아산업 분기구 (사이트 분류번호 7613)', 'dac-7613', 400
    UNION ALL SELECT 'dac-g2', '흄관분기구', '동아산업 분기구 (사이트 분류번호 7614)', 'dac-7614', 410
    UNION ALL SELECT 'dac-g2', '맨홀분기구', '동아산업 분기구 (사이트 분류번호 7616)', 'dac-7616', 420
    UNION ALL SELECT 'dac-g2', '분기구밀봉캡', '동아산업 분기구 (사이트 분류번호 7619)', 'dac-7619', 430
    UNION ALL SELECT 'dac-g2', '부직포', '동아산업 부자재 (사이트 분류번호 4585)', 'dac-4585', 440
    UNION ALL SELECT 'dac-g1', 'U형플륨관(벤치1종)', '동아산업 플륨관/수로관 (사이트 분류번호 1210)', 'dac-1210', 450
    UNION ALL SELECT 'dac-g1', '벤치플륨관(벤치2종)', '동아산업 플륨관/수로관 (사이트 분류번호 1220)', 'dac-1220', 460
    UNION ALL SELECT 'dac-g1', '수로관(벤치3종)', '동아산업 플륨관/수로관 (사이트 분류번호 1230)', 'dac-1230', 470
    UNION ALL SELECT 'dac-g1', '환경수로관', '동아산업 플륨관/수로관 (사이트 분류번호 1240)', 'dac-1240', 480
    UNION ALL SELECT 'dac-g1', '개거수로관', '동아산업 플륨관/수로관 (사이트 분류번호 1250)', 'dac-1250', 490
    UNION ALL SELECT 'dac-g1', '보강수로관(무개생태)', '동아산업 플륨관/수로관 (사이트 분류번호 1260)', 'dac-1260', 500
    UNION ALL SELECT 'dac-g1', '생태탈출로', '동아산업 플륨관/수로관 (사이트 분류번호 1262)', 'dac-1262', 510
    UNION ALL SELECT 'dac-g1', '측구수로관A형(앵글미부착)', '동아산업 측구수로관 (사이트 분류번호 1610)', 'dac-1610', 520
    UNION ALL SELECT 'dac-g1', '측구수로관B형(앵글부착)', '동아산업 측구수로관 (사이트 분류번호 1620)', 'dac-1620', 530
    UNION ALL SELECT 'dac-g1', '측구수로관AN형(앵글부착)', '동아산업 측구수로관 (사이트 분류번호 1630)', 'dac-1630', 540
    UNION ALL SELECT 'dac-g1', '측구수로관Z형(앵글부착)', '동아산업 측구수로관 (사이트 분류번호 1640)', 'dac-1640', 550
    UNION ALL SELECT 'dac-g1', '측구수로관유공(앵글부착)', '동아산업 측구수로관 (사이트 분류번호 1650)', 'dac-1650', 560
    UNION ALL SELECT 'dac-g1', '침투측구수로관(앵글부착)', '동아산업 측구수로관 (사이트 분류번호 1660)', 'dac-1660', 570
    UNION ALL SELECT 'dac-g1', '원형사각수로관(RDG형)', '동아산업 사각수로관 (사이트 분류번호 1850)', 'dac-1850', 580
    UNION ALL SELECT 'dac-g1', '돌무늬원형사각수로관NS-02', '동아산업 사각수로관 (사이트 분류번호 1860)', 'dac-1860', 590
    UNION ALL SELECT 'dac-g1', '돌무늬원형사각수로관NS-03', '동아산업 사각수로관 (사이트 분류번호 1870)', 'dac-1870', 600
    UNION ALL SELECT 'dac-g1', '사각수로관(AN형)', '동아산업 사각수로관 (사이트 분류번호 1810)', 'dac-1810', 610
    UNION ALL SELECT 'dac-g1', '사각수로관(BN형)', '동아산업 사각수로관 (사이트 분류번호 1820)', 'dac-1820', 620
    UNION ALL SELECT 'dac-g1', '사각수로관(CN형)', '동아산업 사각수로관 (사이트 분류번호 1830)', 'dac-1830', 630
    UNION ALL SELECT 'dac-g1', '사각수로관(DN형)', '동아산업 사각수로관 (사이트 분류번호 1840)', 'dac-1840', 640
    UNION ALL SELECT 'dac-g1', '사각수로관(무공)', '동아산업 사각수로관 (사이트 분류번호 1845)', 'dac-1845', 650
    UNION ALL SELECT 'dac-g1', '원심력사각수로관(무개)', '동아산업 사각수로관 (사이트 분류번호 2040)', 'dac-2040', 660
    UNION ALL SELECT 'dac-g1', '원심력사각수로관(유개)', '동아산업 사각수로관 (사이트 분류번호 2050)', 'dac-2050', 670
    UNION ALL SELECT 'dac-g1', '건널목수로관(1종)', '동아산업 사각수로관 (사이트 분류번호 1271)', 'dac-1271', 680
    UNION ALL SELECT 'dac-g1', '건널목수로관(2종)', '동아산업 사각수로관 (사이트 분류번호 1272)', 'dac-1272', 690
    UNION ALL SELECT 'dac-g1', 'PE배수로', '동아산업 PE배수로 (사이트 분류번호 4510)', 'dac-4510', 700
    UNION ALL SELECT 'dac-g1', 'PC트렌치', '동아산업 배수트렌치 (사이트 분류번호 2510)', 'dac-2510', 710
    UNION ALL SELECT 'dac-g1', '알루미늄트렌치', '동아산업 배수트렌치 (사이트 분류번호 2930)', 'dac-2930', 720
    UNION ALL SELECT 'dac-g1', '빗물받이블록', '동아산업 빗물받이블록 (사이트 분류번호 5810)', 'dac-5810', 730
    UNION ALL SELECT 'dac-g1', '플륨관/수로관집게', '동아산업 부자재 (사이트 분류번호 1280)', 'dac-1280', 740
    UNION ALL SELECT 'dac-g3', '사각맨홀(집수정)', '동아산업 콘크리트 맨홀 (사이트 분류번호 2410)', 'dac-2410', 750
    UNION ALL SELECT 'dac-g3', '사각노바시(사각인상맨홀)', '동아산업 콘크리트 맨홀 (사이트 분류번호 2415)', 'dac-2415', 760
    UNION ALL SELECT 'dac-g3', '원형맨홀', '동아산업 콘크리트 맨홀 (사이트 분류번호 2420)', 'dac-2420', 770
    UNION ALL SELECT 'dac-g3', '원형노바시(원형인상맨홀)', '동아산업 콘크리트 맨홀 (사이트 분류번호 2425)', 'dac-2425', 780
    UNION ALL SELECT 'dac-g3', '침투집수정', '동아산업 콘크리트 맨홀 (사이트 분류번호 2440)', 'dac-2440', 790
    UNION ALL SELECT 'dac-g3', '전기맨홀', '동아산업 콘크리트 맨홀 (사이트 분류번호 2450)', 'dac-2450', 800
    UNION ALL SELECT 'dac-g3', '통신맨홀', '동아산업 콘크리트 맨홀 (사이트 분류번호 2460)', 'dac-2460', 810
    UNION ALL SELECT 'dac-g3', 'NS용집수정(사각수로관용)', '동아산업 콘크리트 맨홀 (사이트 분류번호 1880)', 'dac-1880', 820
    UNION ALL SELECT 'dac-g3', '조립식원형맨홀(상부.연직.하부구체)', '동아산업 콘크리트 맨홀 (사이트 분류번호 2470)', 'dac-2470', 830
    UNION ALL SELECT 'dac-g3', '조립식사각맨홀(상부.연직.하부구체)', '동아산업 콘크리트 맨홀 (사이트 분류번호 2475)', 'dac-2475', 840
    UNION ALL SELECT 'dac-g3', 'PE빗물받이', '동아산업 기타소재 집수제품 (사이트 분류번호 4520)', 'dac-4520', 850
    UNION ALL SELECT 'dac-g3', 'PE오수받이', '동아산업 기타소재 집수제품 (사이트 분류번호 4525)', 'dac-4525', 860
    UNION ALL SELECT 'dac-g3', 'PE홈통받이', '동아산업 기타소재 집수제품 (사이트 분류번호 4530)', 'dac-4530', 870
    UNION ALL SELECT 'dac-g3', 'PE전원박스', '동아산업 기타소재 집수제품 (사이트 분류번호 4586)', 'dac-4586', 880
    UNION ALL SELECT 'dac-g3', 'PVC소형맨홀', '동아산업 기타소재 집수제품 (사이트 분류번호 4566)', 'dac-4566', 890
    UNION ALL SELECT 'dac-g3', 'PVC오수받이', '동아산업 기타소재 집수제품 (사이트 분류번호 4567)', 'dac-4567', 900
    UNION ALL SELECT 'dac-g3', '주철우수통', '동아산업 기타소재 집수제품 (사이트 분류번호 2860)', 'dac-2860', 910
    UNION ALL SELECT 'dac-g3', '가로등기초', '동아산업 콘크리트기초 (사이트 분류번호 2610)', 'dac-2610', 920
    UNION ALL SELECT 'dac-g3', '휀스기초', '동아산업 콘크리트기초 (사이트 분류번호 2620)', 'dac-2620', 930
    UNION ALL SELECT 'dac-g3', '몰탈용고무시트', '동아산업 부자재 (사이트 분류번호 7310)', 'dac-7310', 940
    UNION ALL SELECT 'dac-g3', 'PE사다리', '동아산업 부자재 (사이트 분류번호 2490)', 'dac-2490', 950
    UNION ALL SELECT 'dac-g4', '주철뚜껑(우수/오수/전기/통신)', '동아산업 주철뚜껑 (사이트 분류번호 2810)', 'dac-2810', 960
    UNION ALL SELECT 'dac-g4', '사각주철(수로용/닥타일)', '동아산업 주철뚜껑 (사이트 분류번호 2830)', 'dac-2830', 970
    UNION ALL SELECT 'dac-g4', '조화(디자인)맨홀뚜껑', '동아산업 주철뚜껑 (사이트 분류번호 2870)', 'dac-2870', 980
    UNION ALL SELECT 'dac-g4', '각종주철(칼라.정화조.제수변)', '동아산업 주철뚜껑 (사이트 분류번호 2880)', 'dac-2880', 990
    UNION ALL SELECT 'dac-g4', '무소음트렌치', '동아산업 무소음트렌치 (사이트 분류번호 2910)', 'dac-2910', 1000
    UNION ALL SELECT 'dac-g4', '삼각트렌치', '동아산업 무소음트렌치 (사이트 분류번호 2920)', 'dac-2920', 1010
    UNION ALL SELECT 'dac-g4', '그레이팅수로용', '동아산업 그레이팅 (사이트 분류번호 3010)', 'dac-3010', 1020
    UNION ALL SELECT 'dac-g4', '그레이팅맨홀(집수정)용', '동아산업 그레이팅 (사이트 분류번호 3020)', 'dac-3020', 1030
    UNION ALL SELECT 'dac-g4', '플륨관용(날개형U-TYPE)', '동아산업 그레이팅 (사이트 분류번호 3030)', 'dac-3030', 1040
    UNION ALL SELECT 'dac-g4', '톱니바그레이팅', '동아산업 그레이팅 (사이트 분류번호 3033)', 'dac-3033', 1050
    UNION ALL SELECT 'dac-g4', '안전그레이팅', '동아산업 그레이팅 (사이트 분류번호 3035)', 'dac-3035', 1060
    UNION ALL SELECT 'dac-g4', '악취차단오물망그레이팅', '동아산업 그레이팅 (사이트 분류번호 3036)', 'dac-3036', 1070
    UNION ALL SELECT 'dac-g4', 'EX메탈망그레이팅', '동아산업 그레이팅 (사이트 분류번호 3052)', 'dac-3052', 1080
    UNION ALL SELECT 'dac-g4', '스틸타공그레이팅', '동아산업 그레이팅 (사이트 분류번호 3037)', 'dac-3037', 1090
    UNION ALL SELECT 'dac-g4', '중하중그레이팅수로용', '동아산업 중하중그레이팅 (사이트 분류번호 3110)', 'dac-3110', 1100
    UNION ALL SELECT 'dac-g4', '중하중그레이팅맨홀(집수정)용', '동아산업 중하중그레이팅 (사이트 분류번호 3111)', 'dac-3111', 1110
    UNION ALL SELECT 'dac-g4', '안전중하중그레이팅수로용', '동아산업 중하중그레이팅 (사이트 분류번호 3120)', 'dac-3120', 1120
    UNION ALL SELECT 'dac-g4', '안전중하중그레이팅맨홀(집수정)용', '동아산업 중하중그레이팅 (사이트 분류번호 3121)', 'dac-3121', 1130
    UNION ALL SELECT 'dac-g4', '디자인그레이팅수로용', '동아산업 디자인그레이팅 (사이트 분류번호 3230)', 'dac-3230', 1140
    UNION ALL SELECT 'dac-g4', '디자인그레이팅맨홀(집수정)용', '동아산업 디자인그레이팅 (사이트 분류번호 3231)', 'dac-3231', 1150
    UNION ALL SELECT 'dac-g4', '원형디자인', '동아산업 디자인그레이팅 (사이트 분류번호 3232)', 'dac-3232', 1160
    UNION ALL SELECT 'dac-g4', '칼라맨홀뚜껑', '동아산업 디자인그레이팅 (사이트 분류번호 3233)', 'dac-3233', 1170
    UNION ALL SELECT 'dac-g4', 'SUS그레이팅', '동아산업 SUS/AL그레이팅 (사이트 분류번호 3310)', 'dac-3310', 1180
    UNION ALL SELECT 'dac-g4', 'SUS그레이팅(타공)', '동아산업 SUS/AL그레이팅 (사이트 분류번호 3320)', 'dac-3320', 1190
    UNION ALL SELECT 'dac-g4', '알루미늄그레이팅', '동아산업 SUS/AL그레이팅 (사이트 분류번호 3350)', 'dac-3350', 1200
    UNION ALL SELECT 'dac-g4', '체크무늬철판', '동아산업 SUS/AL그레이팅 (사이트 분류번호 3340)', 'dac-3340', 1210
    UNION ALL SELECT 'dac-g4', '플륨관/수로관뚜껑(PC)', '동아산업 기타소재 (사이트 분류번호 1410)', 'dac-1410', 1220
    UNION ALL SELECT 'dac-g4', '플라스틱그레이팅', '동아산업 기타소재 (사이트 분류번호 7210)', 'dac-7210', 1230
    UNION ALL SELECT 'dac-g4', 'PE그레이팅덮개', '동아산업 부자재 (사이트 분류번호 7110)', 'dac-7110', 1240
    UNION ALL SELECT 'dac-g4', 'PE망', '동아산업 부자재 (사이트 분류번호 7120)', 'dac-7120', 1250
    UNION ALL SELECT 'dac-g4', '낙엽방지덮개', '동아산업 부자재 (사이트 분류번호 7730)', 'dac-7730', 1260
    UNION ALL SELECT 'dac-g4', 'EX메탈거름망', '동아산업 부자재 (사이트 분류번호 3053)', 'dac-3053', 1270
    UNION ALL SELECT 'dac-g4', '앵글(직선앵글)', '동아산업 부자재 (사이트 분류번호 3060)', 'dac-3060', 1280
    UNION ALL SELECT 'dac-g4', '고정클립', '동아산업 부자재 (사이트 분류번호 3070)', 'dac-3070', 1290
    UNION ALL SELECT 'dac-g5', '인터로킹U형블럭', '동아산업 보도블럭 (사이트 분류번호 3410)', 'dac-3410', 1300
    UNION ALL SELECT 'dac-g5', '인터로킹I2형블럭', '동아산업 보도블럭 (사이트 분류번호 3420)', 'dac-3420', 1310
    UNION ALL SELECT 'dac-g5', '인조화강블록(투수)', '동아산업 보도블럭 (사이트 분류번호 3425)', 'dac-3425', 1320
    UNION ALL SELECT 'dac-g5', '인조화강블록(불투수)', '동아산업 보도블럭 (사이트 분류번호 3426)', 'dac-3426', 1330
    UNION ALL SELECT 'dac-g5', '다칼라블랜딩블록(투수)', '동아산업 보도블럭 (사이트 분류번호 3450)', 'dac-3450', 1340
    UNION ALL SELECT 'dac-g5', '다칼라블랜딩블록(불투수)', '동아산업 보도블럭 (사이트 분류번호 3451)', 'dac-3451', 1350
    UNION ALL SELECT 'dac-g5', '장애인사각블럭(점자블럭)', '동아산업 보도블럭 (사이트 분류번호 3430)', 'dac-3430', 1360
    UNION ALL SELECT 'dac-g5', '점토블럭', '동아산업 보도블럭 (사이트 분류번호 3510)', 'dac-3510', 1370
    UNION ALL SELECT 'dac-g5', '계단마감재', '동아산업 보도블럭 (사이트 분류번호 3520)', 'dac-3520', 1380
    UNION ALL SELECT 'dac-g5', '조경경계블록', '동아산업 보도블럭 (사이트 분류번호 3530)', 'dac-3530', 1390
    UNION ALL SELECT 'dac-g5', '계단석(화강석)', '동아산업 보도블럭 (사이트 분류번호 4230)', 'dac-4230', 1400
    UNION ALL SELECT 'dac-g5', '콘크리트경계석', '동아산업 경계석 (사이트 분류번호 3610)', 'dac-3610', 1410
    UNION ALL SELECT 'dac-g5', '고강도콘크리트경계석', '동아산업 경계석 (사이트 분류번호 3615)', 'dac-3615', 1420
    UNION ALL SELECT 'dac-g5', '녹지경계블록', '동아산업 경계석 (사이트 분류번호 3620)', 'dac-3620', 1430
    UNION ALL SELECT 'dac-g5', '인조화강경계석', '동아산업 경계석 (사이트 분류번호 3810)', 'dac-3810', 1440
    UNION ALL SELECT 'dac-g5', '화강경계도로', '동아산업 경계석 (사이트 분류번호 4010)', 'dac-4010', 1450
    UNION ALL SELECT 'dac-g5', '화강경계경사', '동아산업 경계석 (사이트 분류번호 4030)', 'dac-4030', 1460
    UNION ALL SELECT 'dac-g5', '화강경계낮춤', '동아산업 경계석 (사이트 분류번호 4050)', 'dac-4050', 1470
    UNION ALL SELECT 'dac-g5', '험프석', '동아산업 경계석 (사이트 분류번호 4210)', 'dac-4210', 1480
    UNION ALL SELECT 'dac-g5', '배수(빗물받이)경계석', '동아산업 경계석 (사이트 분류번호 4280)', 'dac-4280', 1490
    UNION ALL SELECT 'dac-g5', '잔디블록', '동아산업 조경블럭 (사이트 분류번호 5410)', 'dac-5410', 1500
    UNION ALL SELECT 'dac-g5', '조경잔디블록', '동아산업 조경블럭 (사이트 분류번호 5420)', 'dac-5420', 1510
    UNION ALL SELECT 'dac-g5', '라인잔디블록', '동아산업 조경블럭 (사이트 분류번호 5422)', 'dac-5422', 1520
    UNION ALL SELECT 'dac-g5', '판석', '동아산업 조경블럭 (사이트 분류번호 4240)', 'dac-4240', 1530
    UNION ALL SELECT 'dac-g5', '사구석/소포석', '동아산업 조경블럭 (사이트 분류번호 4250)', 'dac-4250', 1540
    UNION ALL SELECT 'dac-g5', '화산석', '동아산업 조경블럭 (사이트 분류번호 4260)', 'dac-4260', 1550
    UNION ALL SELECT 'dac-g5', '수목보호틀(인조석)', '동아산업 도로조경 (사이트 분류번호 2710)', 'dac-2710', 1560
    UNION ALL SELECT 'dac-g5', '수목보호틀(화강석)', '동아산업 도로조경 (사이트 분류번호 4290)', 'dac-4290', 1570
    UNION ALL SELECT 'dac-g5', '야자매트', '동아산업 도로조경 (사이트 분류번호 8010)', 'dac-8010', 1580
    UNION ALL SELECT 'dac-g5', '스텐바리케이드', '동아산업 도로안전 (사이트 분류번호 7710)', 'dac-7710', 1590
    UNION ALL SELECT 'dac-g5', '스텐볼라드', '동아산업 도로안전 (사이트 분류번호 7720)', 'dac-7720', 1600
    UNION ALL SELECT 'dac-g5', '화강볼라드', '동아산업 도로안전 (사이트 분류번호 4220)', 'dac-4220', 1610
    UNION ALL SELECT 'dac-g5', '우레탄볼라드', '동아산업 도로안전 (사이트 분류번호 7520)', 'dac-7520', 1620
    UNION ALL SELECT 'dac-g5', '카스토퍼', '동아산업 도로안전 (사이트 분류번호 7740)', 'dac-7740', 1630
    UNION ALL SELECT 'dac-g5', '과속방지턱', '동아산업 도로안전 (사이트 분류번호 7750)', 'dac-7750', 1640
    UNION ALL SELECT 'dac-g5', '규사', '동아산업 부자재 (사이트 분류번호 3440)', 'dac-3440', 1650
    UNION ALL SELECT 'dac-g5', '경계석집게', '동아산업 부자재 (사이트 분류번호 3630)', 'dac-3630', 1660
    UNION ALL SELECT 'dac-g5', '와이어메쉬', '동아산업 부자재 (사이트 분류번호 3910)', 'dac-3910', 1670
    UNION ALL SELECT 'dac-g5', 'PC방호벽(중앙분리)', '동아산업 방호벽 (사이트 분류번호 4810)', 'dac-4810', 1680
    UNION ALL SELECT 'dac-g6', '아스콘 포장', '동아산업 아스콘 (사이트 분류번호 7420)', 'dac-7420', 1690
    UNION ALL SELECT 'dac-g6', '콘크리트 포장', '동아산업 레미콘 (사이트 분류번호 7410)', 'dac-7410', 1700
    UNION ALL SELECT 'dac-g7', '보강토블록(줄무늬)', '동아산업 보강토 (사이트 분류번호 5010)', 'dac-5010', 1710
    UNION ALL SELECT 'dac-g7', '보강토블록(민무늬)', '동아산업 보강토 (사이트 분류번호 5011)', 'dac-5011', 1720
    UNION ALL SELECT 'dac-g7', '보강토블록(혼합무늬)', '동아산업 보강토 (사이트 분류번호 5012)', 'dac-5012', 1730
    UNION ALL SELECT 'dac-g7', '보강토마감형', '동아산업 보강토 (사이트 분류번호 5020)', 'dac-5020', 1740
    UNION ALL SELECT 'dac-g7', '축조블록(곡선형)', '동아산업 축조블록 (사이트 분류번호 5210)', 'dac-5210', 1750
    UNION ALL SELECT 'dac-g7', '축조블록(평면형)', '동아산업 축조블록 (사이트 분류번호 5230)', 'dac-5230', 1760
    UNION ALL SELECT 'dac-g7', '조립식옹벽블록', '동아산업 옹벽블록 (사이트 분류번호 5025)', 'dac-5025', 1770
    UNION ALL SELECT 'dac-g7', '중력식옹벽블록', '동아산업 옹벽블록 (사이트 분류번호 5310)', 'dac-5310', 1780
    UNION ALL SELECT 'dac-g7', 'PC판넬옹벽', '동아산업 옹벽블록 (사이트 분류번호 5110)', 'dac-5110', 1790
    UNION ALL SELECT 'dac-g7', '환경생태블록', '동아산업 옹벽블록 (사이트 분류번호 5620)', 'dac-5620', 1800
    UNION ALL SELECT 'dac-g7', '돌망태(개비온)', '동아산업 담장/경관 블록 (사이트 분류번호 4110)', 'dac-4110', 1810
    UNION ALL SELECT 'dac-g7', '디자인블록(큐블록)', '동아산업 담장/경관 블록 (사이트 분류번호 6010)', 'dac-6010', 1820
    UNION ALL SELECT 'dac-g7', '콘크리트벽돌', '동아산업 담장/경관 블록 (사이트 분류번호 6020)', 'dac-6020', 1830
    UNION ALL SELECT 'dac-g7', '속빈콘크리트블록', '동아산업 담장/경관 블록 (사이트 분류번호 6030)', 'dac-6030', 1840
    UNION ALL SELECT 'dac-g7', '사비석', '동아산업 담장/경관 블록 (사이트 분류번호 4265)', 'dac-4265', 1850
    UNION ALL SELECT 'dac-g7', '그리드', '동아산업 부자재 (사이트 분류번호 5030)', 'dac-5030', 1860
    UNION ALL SELECT 'dac-g7', '보강토집게', '동아산업 부자재 (사이트 분류번호 5040)', 'dac-5040', 1870
    UNION ALL SELECT 'dac-g7', '축조블록집게', '동아산업 부자재 (사이트 분류번호 5250)', 'dac-5250', 1880
    UNION ALL SELECT 'dac-g6', 'PHC파일A종(고강도파일)', '동아산업 PHC파일(고강도파일) (사이트 분류번호 1110)', 'dac-1110', 1890
    UNION ALL SELECT 'dac-g8', '개거암거몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9014)', 'dac-9014', 1900
    UNION ALL SELECT 'dac-g8', 'U형플륨관(벤치1종)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9020)', 'dac-9020', 1910
    UNION ALL SELECT 'dac-g8', 'U형플륨관(벤치2종)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9021)', 'dac-9021', 1920
    UNION ALL SELECT 'dac-g8', '수로관(벤치3종)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9022)', 'dac-9022', 1930
    UNION ALL SELECT 'dac-g8', '환경수로관몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9023)', 'dac-9023', 1940
    UNION ALL SELECT 'dac-g8', '측구수로관A형(앵글미부착)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9030)', 'dac-9030', 1950
    UNION ALL SELECT 'dac-g8', '측구수로관B형(앵글부착)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9031)', 'dac-9031', 1960
    UNION ALL SELECT 'dac-g8', '원형사각수로관(RDG형)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9040)', 'dac-9040', 1970
    UNION ALL SELECT 'dac-g8', '원형사각수로관(집수정)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9041)', 'dac-9041', 1980
    UNION ALL SELECT 'dac-g8', '돌무늬원형사각수로관(NS)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9042)', 'dac-9042', 1990
    UNION ALL SELECT 'dac-g8', '사각맨홀(집수정)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9050)', 'dac-9050', 2000
    UNION ALL SELECT 'dac-g8', '원형맨홀몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9051)', 'dac-9051', 2010
    UNION ALL SELECT 'dac-g8', '통신맨홀몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9052)', 'dac-9052', 2020
    UNION ALL SELECT 'dac-g8', '사각노바시(사각인상맨홀)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9053)', 'dac-9053', 2030
    UNION ALL SELECT 'dac-g8', '원형노바시(원형인상맨홀)몰드', '동아산업 몰드(거푸집) (사이트 분류번호 9054)', 'dac-9054', 2040
) v
JOIN syc_categories p ON p.slug = v.parent_slug AND p.parent_id IS NULL
WHERE NOT EXISTS (SELECT 1 FROM syc_categories x WHERE x.slug = v.slug);

-- [4] 확인(읽기 전용): 대분류 8 · 품목 204 · 마진율 0 이 아닌 행 0
SELECT SUM(parent_id IS NULL) AS tops, SUM(parent_id IS NOT NULL) AS items,
       SUM(discount_customer <> 0 OR discount_corporate <> 0) AS margin_nonzero
FROM syc_categories WHERE slug LIKE 'dac-%';

-- [롤백] 되돌리기(승인 후에만) — 상품이 들어 있는 분류는 지우지 않는다(조건)
-- DELETE c FROM syc_categories c WHERE c.slug LIKE 'dac-%' AND c.parent_id IS NOT NULL
--   AND NOT EXISTS (SELECT 1 FROM syc_products s WHERE s.category_id = c.id);
-- DELETE c FROM syc_categories c WHERE c.slug LIKE 'dac-g%' AND c.parent_id IS NULL
--   AND NOT EXISTS (SELECT 1 FROM (SELECT parent_id FROM syc_categories) k WHERE k.parent_id = c.id);
