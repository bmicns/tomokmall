<?php
declare(strict_types=1);

/**
 * 작업 유형(메인페이지 '어떤 작업에 사용할 예정인가요?') 설정과 조회 헬퍼
 *
 * 책임:
 *  - work_types()               작업 유형 목록 12개(작업명·설명·대표 자재 카테고리 ID). 근거: docs/03-analysis/work-types-v5.md
 *                               (법령·국가건설기준·공공기관 자료 기준 재선정, v4 '시공 관행' 선정을 대체)
 *  - work_types_validate()      설정 규칙 검사(순수 함수, 단위 테스트 대상)
 *  - work_type_category_info()  대표 자재 카테고리의 이름·활성 여부·썸네일 일괄 조회(DB)
 *  - work_type_materials()      화면에 표시할 대표 자재 목록(비활성·없는 카테고리 제외)
 *  - work_type_find() / work_type_has_material()  작업별 상품 목록(products.php?category=ID&work=키) 판별(순수 함수)
 *  (배수관 설치 위치·관경 주의 안내는 화면이 아니라 AI 상담 답변에서만 — app/rag.php rag_drain_notice_prompt())
 *
 * 규칙(확정 정책):
 *  - 작업 유형별 대표 자재는 최대 WORK_TYPE_MAX_MATERIALS(10)개, 같은 작업 안에서 중복 금지
 *  - PE망(203)·제조용 몰드(215~229)는 어떤 작업의 대표 자재로도 연결하지 않음 (DB·메뉴·검색은 그대로)
 *  - 대분류 카테고리(1~8, 11~16)는 상품이 아닌 분류 구조이므로 대표 자재로 연결하지 않음
 *
 * 금지: 이 파일에서 DB 쓰기 금지(조회만). 카테고리 ID는 운영 DB categories.id 기준.
 */

const WORK_TYPE_MAX_MATERIALS = 10;

/**
 * 상용콘크리트로 전환했을 때(app/syc_publish.php)의 작업별 대표 자재 — 케이토목 대표 자재(work_types() materials)와 같은 기준으로
 * 같은 쓰임의 상용콘크리트 품목을 고름(사용자 결정 2026-11-12). 상용콘크리트에 같은 쓰임 품목이 없으면 넣지 않음(추측 금지).
 * [syc_categories slug, 짧은 용도 설명] — 이름은 DB(카테고리 이름). 상품이 있는 품목만, 작업당 최대 10개, 중복 금지(work_types_validate)
 */
const WORK_TYPE_SYC_MATERIALS = [
    'drainage' => [['syc-s11', '마당 배수로 본체'], ['syc-s1', '덮개 얹는 길가 측구'], ['syc-s10', '얕은 소형 배수로'], ['syc-s18', '진입로 가로 배수'],
                   ['syc-w1', '진입로 밑 배수로'], ['syc-s9', '그레이팅 높이 맞춤 측구'], ['syc-s16', '덮개 있는 사각 배수로'], ['syc-s15', '스틸그레이팅 원형 배수로'],
                   ['syc-s14', '원형 배수로'], ['syc-s46', '가벼운 마당 배수로']],
    'paving'   => [['syc-s56', '기본 보도블록'], ['syc-s54', '빗물 스미는 블록'], ['syc-s55', '광장·건물 앞 포장'], ['syc-s58', '시각장애인 안내'],
                   ['syc-s59', '시각장애인 안내(고강도)'], ['syc-s57', '보도블록 I형'], ['syc-s62', '사각 보도 포장']],
    'curb'     => [['syc-s74', '차도·보도 경계·턱 낮춤'], ['syc-s72', '경제형 경계석'], ['syc-s87', '진입부 높이 전환'], ['syc-s73', '화강석 대체 경계석'],
                   ['syc-s58', '낮춤부 점자 안내'], ['syc-s71', '보도·녹지 경계']],
    'retaining' => [['syc-s63', '보강토 옹벽 본체'], ['syc-s67', '식생형 블록 옹벽'], ['syc-s64', '옹벽 상단 마감']],
    'parking'  => [['syc-s61', '잔디 주차 바닥'], ['syc-s54', '빗물 스미는 바닥'], ['syc-w3', '보행로 차량 막기'], ['syc-s18', '출입구 빗물 차단'],
                   ['syc-s72', '주차장 가장자리'], ['syc-w2', '차량 진입 차단'], ['syc-s58', '볼라드 앞 점자 안내']],
    'garden'   => [['syc-s71', '화단·녹지 테두리']],
    'building-drain' => [['syc-s42', '건물 오수 모으기'], ['syc-s41', '마당 빗물받이'], ['syc-s36', '오수관 연결 받이'], ['syc-s45', '홈통 빗물 받기'],
                   ['syc-s43', '다용도 빗물받이'], ['syc-s44', '가정용 빗물받이'], ['syc-s47', '빗물 모으는 집수정'], ['syc-s78', '빗물 땅에 스미게']],
    'sewer'    => [['syc-s19', '도로 밑 배수관'], ['syc-s26', '관 연결 맨홀'], ['syc-s24', '빗물 모으는 집수정'], ['syc-s51', '맨홀 뚜껑'],
                   ['syc-s76', '큰 관경 배수관'], ['syc-s21', '도로 밑 대형 수로'], ['syc-s20', '압력 받는 배수관'], ['syc-s28', '깊은 맨홀 조립']],
    'subdrain' => [['syc-s8', '땅속으로 스미는 측구'], ['syc-s78', '땅속으로 스미는 집수정'], ['syc-s24', '지하 배수 모으기']],
    'river'    => [['syc-s68', '하천 호안'], ['syc-s67', '식생형 비탈면 블록'], ['syc-s66', '비탈면 잔디 보호']],
    'farm'     => [['syc-s12', '논밭 기본 수로'], ['syc-s10', '얕은 소형 수로'], ['syc-s11', '수로 본체'], ['syc-w1', '농로 밑 배수로'],
                   ['syc-s13', '생태 수로'], ['syc-s19', '농로 밑 배수관']],
    'utility'  => [['syc-s29', '전기·통신 맨홀'], ['syc-s30', '통신 맨홀'], ['syc-s31', '가로등 기초'], ['syc-s52', '전기·통신 맨홀 뚜껑'],
                   ['syc-s53', '전기통신 맨홀 뚜껑(BS)'], ['syc-s85', '원형 전기통신 뚜껑']],
];
const WORK_TYPE_EXCLUDED_CATEGORY_IDS = [203, 215, 216, 217, 218, 219, 220, 221, 222, 223, 224, 225, 226, 227, 228, 229];
const WORK_TYPE_STRUCTURE_CATEGORY_IDS = [1, 2, 3, 4, 5, 6, 7, 8, 11, 12, 13, 14, 15, 16];

/**
 * 작업 유형 목록 — 메인페이지에 모두(순서대로) 노출. 사용자가 하려는 공사·상황 기준으로 12개로 통합.
 * materials: [카테고리ID, 이름(DB 미연결 시 표시용), 짧은 용도 설명] — 순서는 근거 강도·핵심도 순(자재별 근거는 v5 문서 3장)
 * img: 작업 카드 이미지(로컬, null이면 첫 대표 자재 이미지), img_kind: photo(현장 사진) | product(자재 이미지, 여백·배경 적용)
 * banner: (선택) 작업별 상품 목록 상단 배너용 가로형 이미지. 없으면 img 사용
 */
function work_types(): array
{
    return [
        // 배수로 신설·보수 + 도로·진입로 노면 배수(측구·트렌치·진입로 밑 수로) 통합. 근거: 도로 배수시설 지침·도로 연결 규칙(v5 3-1)
        ['key' => 'drainage', 'name' => '배수로 설치·정비',
         'summary' => '마당·도로변·진입로에 배수로를 놓거나, 깨진 덮개를 바꿀 때',
         'img' => '/assets/images/home-use-배수로.png', 'img_kind' => 'photo', 'img_alt' => '보도 옆 배수로에 그레이팅이 설치된 모습',
         'banner' => '/assets/images/home-use-배수로-wide.png',
         'materials' => [
             [24,  '수로관(벤치3종)',            '마당 배수로 본체'],
             [34,  '측구수로관B형(앵글부착)',    '덮개 얹는 길가 측구'],
             [23,  '벤치플륨관(벤치2종)',        '얕은 소형 배수로'],
             [106, 'PC트렌치',                   '진입로 가로 배수'],
             [29,  '건널목수로관(1종)',          '진입로 밑 배수로'],
             [116, '그레이팅수로용',             '보행 구간 덮개'],
             [124, '중하중그레이팅수로용',       '차량 구간 덮개'],
             [117, '그레이팅맨홀(집수정)용',     '집수정 덮개'],
             [32,  '플륨관/수로관뚜껑',          '콘크리트 뚜껑'],
             [176, 'PE배수로',                   '가벼운 마당 배수로'],
         ]],
        // 근거: 보도 설치 및 관리 지침·KDS 34 60 10·교통약자법 시행규칙·LH 조경포장 시방(v5 3-2)
        ['key' => 'paving', 'name' => '보도·마당 바닥 포장',
         'summary' => '보도·마당·가게 앞 바닥을 블록이나 돌로 깔 때',
         'img' => '/assets/images/home-use-보도포장.jpg', 'img_kind' => 'photo', 'img_alt' => '작업자가 바닥에 보도블록을 한 장씩 깔고 있는 포장 공사 현장',
         'materials' => [
             [69,  '인터로킹U형블럭',            '기본 보도블록'],
             [71,  '인조화강블록(투수)',         '빗물 스미는 블록'],
             [77,  '점토블럭',                   '벽돌 느낌 포장'],
             [72,  '인조화강블록(불투수)',       '광장·건물 앞 포장'],
             [156, '판석',                       '돌판 포장'],
             [75,  '장애인사각블럭(점자블럭)',   '시각장애인 안내'],
             [76,  '규사',                       '줄눈 채움 모래'],
             [79,  '조경경계블록',               '포장 끝선 마감'],
             [73,  '다칼라블랜딩블록(투수)',     '무늬 투수 포장'],
             [70,  '인터로킹I2형블럭',           '보도블록 I형'],
         ]],
        // 근거: 도로의 구조·시설 기준에 관한 규칙 제16조·교통약자 이동편의시설 기준(턱낮춤)·LH 조경포장경계(v5 3-3)
        ['key' => 'curb', 'name' => '도로·보도 경계 만들기',
         'summary' => '차도와 보도 사이 경계를 세우거나, 진입로 턱을 낮출 때',
         'img' => '/assets/images/home-use-경계석.jpg', 'img_kind' => 'photo', 'img_alt' => '차도와 보도 사이에 놓인 경계석과 턱을 낮춘 진입부의 점자블록',
         'materials' => [
             [150, '화강경계도로',               '차도·보도 경계'],
             [152, '화강경계낮춤',               '횡단·진입로 턱 낮춤'],
             [272, '콘크리트경계석',             '경제형 경계석'],
             [91,  '고강도콘크리트경계석',       '충격이 잦은 구간'],
             [151, '화강경계경사',               '진입부 높이 전환'],
             [160, '배수(빗물받이)경계석',       '빗물받이 겸용'],
             [75,  '장애인사각블럭(점자블럭)',   '낮춤부 점자 안내'],
             [93,  '인조화강경계석',             '화강석 대체 경계석'],
             [92,  '녹지경계블록',               '보도·녹지 경계'],
         ]],
        // 근거: KDS 11 80 10 보강토옹벽·KCS 11 80 25 블록쌓기옹벽·건축법 시행규칙 제25조(배수구멍)·KOSHA C-68(v5 3-4)
        ['key' => 'retaining', 'name' => '경사지 옹벽 설치',
         'summary' => '높낮이 차이가 있는 땅이나 비탈이 무너지지 않게 벽을 쌓을 때',
         'img' => '/assets/images/home-use-옹벽.jpg', 'img_kind' => 'photo', 'img_alt' => '비탈 아래에 회색 블록을 쌓아 흙을 받친 보강토 블록 옹벽',
         'materials' => [
             [84,  '보강토블록(민무늬)',         '보강토 옹벽 본체'],
             [88,  '그리드',                     '보강토 보강재'],
             [207, '부직포',                     '배면 흙 유실 방지'],
             [167, 'PE이중벽관1종(유공관)',      '옹벽 뒤 배수관'],
             [107, '중력식옹벽블록',             '자중식 옹벽'],
             [87,  '조립식옹벽블록',             '소규모 단차 옹벽'],
             [81,  '축조블록(평면형)',           '식생형 블록 옹벽'],
             [162, '돌망태(개비온)',             '돌망태 옹벽'],
             [86,  '보강토마감형',               '옹벽 상단 마감'],
             [182, 'PVC관VN(구VG1)',             '옹벽 물빼기 구멍'],
         ]],
        // 주차장 조성 + 단지 도로 안전시설 통합. 근거: 주차장법 시행규칙·과속방지턱 지침·단지내도로 기준·교통약자법 시행규칙(v5 3-5)
        ['key' => 'parking', 'name' => '주차장·단지 도로 정비',
         'summary' => '주차장 바닥을 깔고 차량 정지·감속·진입 차단 시설을 갖출 때',
         'img' => '/assets/images/home-use-주차장.jpg', 'img_kind' => 'photo', 'img_alt' => '잔디블록을 깐 주차면에 카스토퍼가 놓이고 보행로 쪽에 볼라드가 선 소규모 주차장',
         'materials' => [
             [264, '잔디블록',                   '잔디 주차 바닥'],
             [71,  '인조화강블록(투수)',         '빗물 스미는 바닥'],
             [205, '과속방지턱',                 '통로 속도 줄임'],
             [206, '우레탄볼라드',               '보행로 차량 막기'],
             [153, '험프석',                     '고원식 횡단보도'],
             [204, '카스토퍼',                   '바퀴 멈춤'],
             [268, '무소음트렌치',               '출입구 빗물 차단'],
             [272, '콘크리트경계석',             '주차장 가장자리'],
             [148, '스텐볼라드',                 '차량 진입 차단'],
             [75,  '장애인사각블럭(점자블럭)',   '볼라드 앞 점자 안내'],
         ]],
        // 정원·화단 + 블록·벽돌 담장 통합(v4 '담장·기초 콘크리트'는 관련 자재가 적어 합침). 근거: 조경설계기준·KDS 34 50 45·KCS 34 60 20·KS F 4002(v5 3-6)
        // 157 사구석(사고석포장, KCS 34 60 20)을 넣고 104 수목보호틀(인조석)을 뺌 — 사용자 결정 2026-11-05
        ['key' => 'garden', 'name' => '정원·담장 꾸미기',
         'summary' => '정원에 디딤돌·계단·화단을 놓거나 블록 담장을 쌓을 때',
         'img' => '/assets/images/home-use-포장.png', 'img_kind' => 'photo', 'img_alt' => '꽃과 나무 사이로 블록이 깔린 정원 보행로',
         'materials' => [
             [156, '판석',                       '정원 길 돌판'],
             [157, '사구석/소포석',              '정원 길 큐브석 포장'],
             [159, '사비석',                     '자연석 디딤돌'],
             [155, '계단석',                     '정원 계단'],
             [79,  '조경경계블록',               '화단 테두리'],
             [92,  '녹지경계블록',               '잔디·화단 경계'],
             [158, '화산석',                     '현무암 경계·조경석'],
             [100, '디자인블록(큐블록)',         '화단 쌓기·앉음벽'],
             [102, '속빈콘크리트블록',           '담장 기본 블록'],
             [101, '콘크리트벽돌',               '담장·벽체 벽돌'],
         ]],
        // 근거: 하수도법 시행규칙 제23조·별표 7(배수설비)·KDS 61 00 00 관로시설·개인하수처리시설 기준(v5 3-7)
        ['key' => 'building-drain', 'name' => '건물 외부 배수시설 설치',
         'summary' => '건물을 짓거나 고치며 주변 오수받이·빗물받이·홈통을 연결할 때',
         'img' => '/assets/images/home-use-배관.png', 'img_kind' => 'photo', 'img_alt' => '땅속에 연결된 배관',
         'materials' => [
             [178, 'PE오수받이',                 '화장실·주방 물받이'],
             [182, 'PVC관VN(구VG1)',             '건물 배수관'],
             [177, 'PE빗물받이',                 '마당 빗물받이'],
             [185, 'PVC오수받이',                '소구경 오수받이'],
             [184, 'PVC소형맨홀',                '배수관 점검구'],
             [198, 'PVC관(VN)분기구',            '공공 하수관 접속'],
             [112, '각종주철(칼라.정화조.제수변)', '정화조 뚜껑'],
             [179, 'PE홈통받이',                 '선홈통 빗물 연결'],
             [121, '악취차단오물망그레이팅',     '빗물받이 냄새 차단'],
             [63,  '침투집수정',                 '빗물 땅속 침투'],
         ]],
        // 지하 배수관 매설 + 집수정·맨홀 설치 + 도로 밑 횡단 배수 통합. 근거: 하수도법 시행규칙 별표 5·KDS 61 00 00·도로 배수시설 지침(v5 3-8)
        ['key' => 'sewer', 'name' => '배수관 묻기·맨홀 설치',
         'summary' => '빗물·하수를 땅속 관으로 보내거나, 맨홀·집수정·도로 밑 물길을 놓을 때',
         'img' => '/assets/images/home-use-맨홀.png', 'img_kind' => 'photo', 'img_alt' => '집수정 구조물과 맨홀 뚜껑',
         'materials' => [
             [164, 'PE이중벽관2종',              '일반 우수·오수관'],
             [163, 'PE이중벽관1종',              '차도 하부 우수관'],
             [50,  '흄관(B형)',                  '콘크리트 배수관'],
             [174, '고강성PVC이중벽관',          '오수 관로'],
             [61,  '원형맨홀',                   '관로 점검 맨홀'],
             [59,  '사각맨홀(집수정)',           '빗물 집수정'],
             [108, '주철뚜껑(우수/오수/전기/통신)', '맨홀 뚜껑'],
             [196, 'PE이중벽관분기구',           '지관 접속'],
             [269, '파형강관',                   '도로 횡배수관'],
             [9,   '1련암거',                    '도로 횡단 구조물'],
         ]],
        // 근거: KDS 44 40 10 지하배수·조경설계기준(운동장 배수)·국도 설계실무요령·농촌진흥청 상담(v5 3-9). 침투시설(38·63)은 목적이 반대라 제외
        ['key' => 'subdrain', 'name' => '땅속 물 빼기(지하 배수)',
         'summary' => '운동장·농경지·옹벽 뒤가 늘 질척여 땅속 물을 빼야 할 때',
         'img' => '/assets/images/home-use-지하배수.jpg', 'img_kind' => 'photo', 'img_alt' => '부직포를 깐 도랑 바닥의 자갈 위에 유공 배수관을 묻는 지하 배수 공사',
         'materials' => [
             [167, 'PE이중벽관1종(유공관)',      '유공 배수관'],
             [168, 'PE이중벽관2종(유공관)',      '일반 지하 배수'],
             [207, '부직포',                     '막힘 방지 필터'],
             [181, 'THP유공관',                  '밭·과수원 유공관'],
             [173, 'PE다중벽관(유공관)',         '고강성 유공관'],
             [180, 'THP주름관',                  '모인 물 배출관'],
             [52,  '유공흄관',                   '콘크리트 유공관'],
             [137, '파형강관(유공관)',           '강관 유공 배수관'],
             [59,  '사각맨홀(집수정)',           '모인 물 집수정'],
             [182, 'PVC관VN(구VG1)',             '옹벽 물빼기 구멍'],
         ]],
        // 근거: KDS 51 50 10 하천호안·하천공사 설계실무요령·국도 설계실무요령(비탈면)(v5 3-10). 생태수로(27·28)는 '논밭 수로 정비'로 옮김
        ['key' => 'river', 'name' => '하천·비탈면 보호',
         'summary' => '하천 둑이나 비탈면이 깎이고 쓸려 내려가는 것을 막을 때',
         'img' => '/assets/images/home-use-하천.jpg', 'img_kind' => 'photo', 'img_alt' => '돌망태를 쌓아 깎이지 않게 보호한 개울 둑',
         'materials' => [
             [96,  '호안블록(식생용)',           '식생형 호안'],
             [162, '돌망태(개비온)',             '돌망태 호안'],
             [81,  '축조블록(평면형)',           '블록 쌓기 호안'],
             [99,  '조경잔디블록',               '비탈면 식생 블록'],
             [95,  '호안블록(저류용)',           '저류지 비탈 호안'],
             [80,  '축조블록(곡선형)',           '곡선 구간 호안'],
             [207, '부직포',                     '블록 밑 흙 빠짐 방지'],
         ]],
        // 농수로·농로 수로(농가 고객). 근거: KS F 4010 벤치플륨·환경부 수로 탈출시설 지침·국립생태원 연구·도로 배수시설 지침(v5 3-11)
        ['key' => 'farm', 'name' => '논밭 수로 정비',
         'summary' => '논밭 농수로를 놓거나 고치고, 농로 밑으로 물길을 낼 때',
         'img' => '/assets/images/home-use-농수로.jpg', 'img_kind' => 'photo', 'img_alt' => '갈아 놓은 논 옆으로 이어진 콘크리트 농수로',
         'materials' => [
             [22,  'U형플륨관(벤치1종)',         '농수로 본체'],
             [23,  '벤치플륨관(벤치2종)',        '얕은 농수로'],
             [24,  '수로관(벤치3종)',            '깊은 농수로'],
             [29,  '건널목수로관(1종)',          '농로 건너는 수로'],
             [118, '플륨관용(날개형U-TYPE)',     '플륨관 전용 덮개'],
             [32,  '플륨관/수로관뚜껑',          '콘크리트 뚜껑'],
             [28,  '생태탈출로',                 '동물 탈출로'],
             [27,  '보강수로관(무개생태)',       '생태형 수로'],
             [50,  '흄관(B형)',                  '농로 밑 배수관'],
         ]],
        // 전기·통신·가로등 지중시설 + 수도 급수관(계량기 뒤) 통합. 근거: KEC 334 지중전선로·건축물 설비기준 규칙 별표 3의2·지자체 급수 조례(v5 3-12)
        ['key' => 'utility', 'name' => '전기·통신·수도 설비 묻기',
         'summary' => '전기·통신선이나 수도관을 땅에 묻고, 가로등을 세울 때',
         'img' => '/assets/images/home-use-지중매설.jpg', 'img_kind' => 'photo', 'img_alt' => '굴착기로 판 도랑에 작업자가 수도관을 묻는 공사 현장',
         'materials' => [
             [210, 'PE전원박스',                 '지중 접속함'],
             [64,  '전기맨홀',                   '전기 케이블 맨홀'],
             [108, '주철뚜껑(우수/오수/전기/통신)', '맨홀·접속함 뚜껑'],
             [65,  '통신맨홀',                   '통신 인입 맨홀'],
             [103, '가로등기초',                 '가로등·정원등 기초'],
             [187, 'HIVP내충격용수도관',         '계량기 뒤 수도관'],
             [192, 'HIVP내충격용수도관이경소켓', '수도관 관경 연결'],
             [112, '각종주철(칼라.정화조.제수변)', '제수변·지수전 뚜껑'],
         ]],
    ];
}

/**
 * 작업 유형 설정 규칙 검사(순수 함수). 위반 내용 문자열 배열을 돌려준다(비어 있으면 통과).
 */
function work_types_validate(array $types): array
{
    $errors = [];
    $keys   = [];
    foreach ($types as $i => $t) {
        $key = (string) ($t['key'] ?? '');
        if ($key === '' || isset($keys[$key])) {
            $errors[] = "작업 #{$i}: key가 비었거나 중복({$key})";
        }
        $keys[$key] = true;
        if (trim((string) ($t['name'] ?? '')) === '') {
            $errors[] = "{$key}: 작업명 없음";
        }
        $mats = $t['materials'] ?? [];
        if (!$mats) {
            $errors[] = "{$key}: 대표 자재 없음";
        }
        if (count($mats) > WORK_TYPE_MAX_MATERIALS) {
            $errors[] = "{$key}: 대표 자재 " . count($mats) . '개(최대 ' . WORK_TYPE_MAX_MATERIALS . '개)';
        }
        $seen = [];
        foreach ($mats as $m) {
            $id = $m[0] ?? null;
            if (!is_int($id) || $id <= 0) {
                $errors[] = "{$key}: 잘못된 카테고리 ID";
                continue;
            }
            if (isset($seen[$id])) {
                $errors[] = "{$key}: 카테고리 {$id} 중복";
            }
            $seen[$id] = true;
            if (in_array($id, WORK_TYPE_EXCLUDED_CATEGORY_IDS, true)) {
                $errors[] = "{$key}: 대표 자재 제외 대상 카테고리 {$id}";
            }
            if (in_array($id, WORK_TYPE_STRUCTURE_CATEGORY_IDS, true)) {
                $errors[] = "{$key}: 대분류(구조) 카테고리 {$id}";
            }
        }
    }
    return $errors;
}

/**
 * 대표 자재 카테고리 정보 일괄 조회: [id => ['name', 'is_active', 'thumb']].
 * DB가 없거나 조회 실패 시 빈 배열(화면은 설정의 이름으로 표시).
 */
function work_type_category_info(array $ids): array
{
    $ids = array_values(array_unique(array_map('intval', $ids)));
    if (!$ids || !db()) {
        return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = db()->prepare(
            "SELECT c.id, c.name, c.is_active,
                    (SELECT p.image_url FROM products p
                      WHERE p.category_id = c.id AND p.is_active = 1
                        AND p.image_url IS NOT NULL AND p.image_url <> ''
                      ORDER BY p.id LIMIT 1) AS thumb
               FROM categories c
              WHERE c.id IN ($ph)"
        );
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $r) {
            $out[(int) $r['id']] = ['name' => (string) $r['name'], 'is_active' => (int) $r['is_active'] === 1, 'thumb' => (string) ($r['thumb'] ?? '')];
        }
        return $out;
    } catch (\Throwable $e) {
        error_log('work_type_category_info: ' . $e->getMessage());
        return [];
    }
}

/**
 * 화면에 표시할 대표 자재: [['id','name','desc','href','img'], ...].
 * DB 정보가 있으면 DB 이름을 쓰고, 없는 카테고리·비활성 카테고리는 링크가 깨지지 않도록 뺀다.
 * 이미지: 로컬 자재 이미지 → 카테고리 대표 상품 썸네일 → 없음(빈 문자열) 순.
 */
function work_type_materials(array $type, array $info): array
{
    $localImg = work_type_local_images();
    $out = [];
    foreach ($type['materials'] as [$id, $name, $desc]) {
        if ($info) {
            if (!isset($info[$id]) || !$info[$id]['is_active']) {
                continue;
            }
            $name = $info[$id]['name'];
        }
        $out[] = [
            'id'   => $id,
            'name' => $name,
            'desc' => $desc,
            // 작업 키가 있으면 작업별 목록(작업 배너 + 자재 종류 패널)으로 연결
            'href' => '/products.php?category=' . $id . (isset($type['key']) ? '&work=' . rawurlencode((string) $type['key']) : ''),
            'img'  => $localImg[$id] ?? ($info[$id]['thumb'] ?? ''),
        ];
    }
    return $out;
}

/**
 * 화면에 쓸 작업 유형 목록: 고객 화면이 상용콘크리트로 전환돼 있으면(app/syc_catalog.php syc_catalog_mode) 대표 자재를
 * WORK_TYPE_SYC_MATERIALS(반영된 categories 의 slug → id)로 바꾼다. 아니면 work_types() 그대로.
 */
function work_types_effective(): array
{
    $types = work_types();
    if (!function_exists('syc_catalog_mode')) { require_once __DIR__ . '/syc_catalog.php'; }
    if (syc_catalog_mode() !== 'syc' || !db()) { return $types; }
    static $ids = null;
    if ($ids === null) {
        $ids = [];
        try {
            foreach (db()->query("SELECT id, slug, name FROM categories WHERE slug LIKE 'syc-%'")->fetchAll() as $r) { $ids[(string) $r['slug']] = [(int) $r['id'], (string) $r['name']]; }
        } catch (\Throwable $e) {
            error_log('work_types_effective: ' . $e->getMessage());
        }
    }
    foreach ($types as $i => $t) {
        $mats = [];
        foreach (WORK_TYPE_SYC_MATERIALS[$t['key']] ?? [] as [$slug, $desc]) {
            if (isset($ids[$slug])) { $mats[] = [$ids[$slug][0], $ids[$slug][1], $desc]; }
        }
        $types[$i]['materials'] = $mats;
    }
    return $types;
}

/** 상용콘크리트 대표 자재 설정 검사(순수 함수): 작업 키 존재·최대 개수·중복·slug 형식 */
function work_types_syc_validate(array $types, array $map): array
{
    $errors = [];
    $keys = array_column($types, 'key');
    foreach ($map as $key => $mats) {
        if (!in_array($key, $keys, true)) { $errors[] = "{$key}: 없는 작업 키"; }
        if (count($mats) > WORK_TYPE_MAX_MATERIALS) { $errors[] = "{$key}: 대표 자재 " . count($mats) . '개'; }
        $slugs = array_column($mats, 0);
        if (count($slugs) !== count(array_unique($slugs))) { $errors[] = "{$key}: 중복"; }
        foreach ($mats as $m) {
            if (!preg_match('/^syc-[sw]\d+$/', (string) ($m[0] ?? '')) || trim((string) ($m[1] ?? '')) === '') { $errors[] = "{$key}: 잘못된 항목"; }
        }
    }
    return $errors;
}

/** 키로 작업 유형 1건 조회(없으면 null). 순수 함수 — 화면은 work_types_effective() 를 넘겨 상용콘크리트 전환을 반영 */
function work_type_find(string $key, ?array $types = null): ?array
{
    foreach ($types ?? work_types() as $t) {
        if ($key !== '' && $t['key'] === $key) {
            return $t;
        }
    }
    return null;
}

/** 카테고리가 작업 유형의 대표 자재인지 — 작업별 목록 화면을 쓸지 판단 */
function work_type_has_material(array $type, int $categoryId): bool
{
    return $categoryId > 0 && in_array($categoryId, array_column($type['materials'] ?? [], 0), true);
}

/** 로컬에 고해상도 자재 이미지가 있는 카테고리 (DB 썸네일보다 우선) */
function work_type_local_images(): array
{
    $flume   = '/assets/images/home-플륨관.png';
    $channel = '/assets/images/home-수로관.png';
    $grating = '/assets/images/home-그레이팅.png';
    $pe      = '/assets/images/home-PE이중벽관.png';
    $block   = '/assets/images/home-블록.png';
    $wall    = '/assets/images/home-옹벽블록.png';
    $curb    = '/assets/images/home-경계석.png';
    $basin   = '/assets/images/home-집수정.png';
    $cover   = '/assets/images/home-맨홀.png';
    return [
        22 => $flume, 23 => $flume, 24 => $channel, 116 => $grating, 124 => $grating, 117 => $grating,
        163 => $pe, 164 => $pe, 167 => $pe, 168 => $pe, 69 => $block, 70 => $block,
        84 => $wall, 86 => $wall, 87 => $wall, 107 => $wall, 150 => $curb, 272 => $curb,
        59 => $basin, 108 => $cover,
        162 => '/assets/images/home-need-돌망태.png', 101 => '/assets/images/home-need-벽돌.png',
    ];
}

/**
 * 대표 자재 카드 HTML (메인 작업 패널).
 * 이미지가 없거나 불러오지 못하면(외부 썸네일 실패 등) 자재명 앞 두 글자 대체 표시.
 */
function work_type_material_card_html(array $n): string
{
    $initial = e(mb_substr($n['name'], 0, 2));
    $img = $n['img'] !== ''
        ? '<img src="' . e($n['img']) . '" alt="" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false">'
          . '<span class="gh-need-noimg" aria-hidden="true" hidden>' . $initial . '</span>'
        : '<span class="gh-need-noimg" aria-hidden="true">' . $initial . '</span>';
    return '<a class="gh-need-card" href="' . e($n['href']) . '" data-category-id="' . (int) $n['id'] . '">'
        . '<span class="gh-need-img">' . $img . '</span>'
        . '<span class="gh-need-name"><span>' . e($n['name']) . '</span>'
        . '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>'
        . '</span>'
        . '<span class="gh-need-desc">' . e($n['desc']) . '</span>'
        . '</a>';
}
