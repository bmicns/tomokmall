<?php
declare(strict_types=1);

/**
 * 작업 유형(메인페이지 '어떤 작업에 사용할 예정인가요?') 설정과 조회 헬퍼
 *
 * 책임:
 *  - work_types()               작업 유형 목록 12개(작업명·설명·대표 자재 카테고리 ID). 근거: docs/03-analysis/work-types-v4.md 를 사용자 상황 기준으로 통합
 *  - work_types_validate()      설정 규칙 검사(순수 함수, 단위 테스트 대상)
 *  - work_type_category_info()  대표 자재 카테고리의 이름·활성 여부·썸네일 일괄 조회(DB)
 *  - work_type_materials()      화면에 표시할 대표 자재 목록(비활성·없는 카테고리 제외)
 *  - work_type_find() / work_type_has_material()  작업별 상품 목록(products.php?category=ID&work=키) 판별(순수 함수)
 *
 * 규칙(확정 정책):
 *  - 작업 유형별 대표 자재는 최대 WORK_TYPE_MAX_MATERIALS(10)개, 같은 작업 안에서 중복 금지
 *  - PE망(203)·제조용 몰드(215~229)는 어떤 작업의 대표 자재로도 연결하지 않음 (DB·메뉴·검색은 그대로)
 *  - 대분류 카테고리(1~8, 11~16)는 상품이 아닌 분류 구조이므로 대표 자재로 연결하지 않음
 *
 * 금지: 이 파일에서 DB 쓰기 금지(조회만). 카테고리 ID는 운영 DB categories.id 기준.
 */

const WORK_TYPE_MAX_MATERIALS = 10;
const WORK_TYPE_EXCLUDED_CATEGORY_IDS = [203, 215, 216, 217, 218, 219, 220, 221, 222, 223, 224, 225, 226, 227, 228, 229];
const WORK_TYPE_STRUCTURE_CATEGORY_IDS = [1, 2, 3, 4, 5, 6, 7, 8, 11, 12, 13, 14, 15, 16];

/**
 * 작업 유형 목록 — 메인페이지에 모두(순서대로) 노출. 사용자가 하려는 공사·상황 기준으로 12개로 통합.
 * materials: [카테고리ID, 이름(DB 미연결 시 표시용), 짧은 용도 설명]
 * img: 작업 카드 이미지(로컬, null이면 첫 대표 자재 이미지), img_kind: photo(현장 사진) | product(자재 이미지, 여백·배경 적용)
 * banner: (선택) 작업별 상품 목록 상단 배너용 가로형 이미지. 없으면 img 사용
 */
function work_types(): array
{
    return [
        // 배수로 신설·보수 + 도로·진입로 노면 배수(측구·트렌치) 통합
        ['key' => 'drainage', 'name' => '배수로 설치·정비',
         'summary' => '마당·도로변·진입로에 배수로를 놓거나, 깨진 덮개를 바꿀 때',
         'img' => '/assets/images/home-use-배수로.png', 'img_kind' => 'photo', 'img_alt' => '보도 옆 배수로에 그레이팅이 설치된 모습',
         'banner' => '/assets/images/home-use-배수로-wide.png',
         'materials' => [
             [24,  '수로관(벤치3종)',            '배수로 본체(U형)'],
             [23,  '벤치플륨관(벤치2종)',        '소형 배수로 본체'],
             [39,  '원형사각수로관(RDG형)',      '덮개 일체형 배수로'],
             [34,  '측구수로관B형(앵글부착)',    '도로변 측구'],
             [106, 'PC트렌치',                   '진입로 횡배수'],
             [176, 'PE배수로',                   '가벼운 마당 배수로'],
             [116, '그레이팅수로용',             '보행 구간 덮개'],
             [124, '중하중그레이팅수로용',       '차량 구간 덮개'],
             [117, '그레이팅맨홀(집수정)용',     '집수정 덮개'],
             [32,  '플륨관/수로관뚜껑',          '콘크리트 뚜껑'],
         ]],
        ['key' => 'paving', 'name' => '보도·마당 바닥 포장',
         'summary' => '보도·광장·마당 바닥을 블록이나 석재로 깔 때',
         'img' => '/assets/images/home-블록.png', 'img_kind' => 'product', 'img_alt' => '보도블록',
         'materials' => [
             [69,  '인터로킹U형블럭',            '보도블럭 기본형'],
             [71,  '인조화강블록(투수)',         '투수 포장 블록'],
             [72,  '인조화강블록(불투수)',       '광장·건물 앞 포장'],
             [70,  '인터로킹I2형블럭',           '보도블럭 I형'],
             [77,  '점토블럭',                   '마당·보행로 포장'],
             [73,  '다칼라블랜딩블록(투수)',     '패턴 포장(투수)'],
             [156, '판석',                       '석재 포장'],
             [75,  '장애인사각블럭(점자블럭)',   '보행 안내 블록'],
             [76,  '규사',                       '줄눈 채움 모래'],
             [79,  '조경경계블록',               '포장 끝선 마감'],
         ]],
        ['key' => 'curb', 'name' => '도로·보도 경계 만들기',
         'summary' => '차도와 보도, 보도와 화단·녹지 사이 경계를 세울 때',
         'img' => '/assets/images/home-경계석.png', 'img_kind' => 'product', 'img_alt' => '경계석',
         'materials' => [
             [150, '화강경계도로',               '차도·보도 경계'],
             [151, '화강경계경사',               '진입부 경사 경계'],
             [152, '화강경계낮춤',               '횡단보도 낮춤 경계'],
             [272, '콘크리트경계석',             '경제형 경계석'],
             [91,  '고강도콘크리트경계석',       '충격이 잦은 구간'],
             [93,  '인조화강경계석',             '화강석 대체 경계석'],
             [160, '배수(빗물받이)경계석',       '빗물 유입 겸용'],
             [92,  '녹지경계블록',               '보도·녹지 경계'],
             [79,  '조경경계블록',               '화단 낮은 경계'],
             [75,  '장애인사각블럭(점자블럭)',   '낮춤부 점자 안내'],
         ]],
        ['key' => 'retaining', 'name' => '경사지 옹벽 설치',
         'summary' => '높낮이 차이가 있는 땅이나 비탈이 무너지지 않게 벽을 쌓을 때',
         'img' => '/assets/images/home-옹벽블록.png', 'img_kind' => 'product', 'img_alt' => '옹벽블록',
         'materials' => [
             [84,  '보강토블록(민무늬)',         '보강토 옹벽 본체'],
             [86,  '보강토마감형',               '옹벽 상단 마감'],
             [88,  '그리드',                     '보강토 보강재'],
             [107, '중력식옹벽블록',             '자중식 옹벽'],
             [87,  '조립식옹벽블록',             '소규모 단차 옹벽'],
             [81,  '축조블록(평면형)',           '식생형 축조 옹벽'],
             [162, '돌망태(개비온)',             '배수성 옹벽·비탈 보강'],
             [58,  'PC판넬옹벽',                 '판넬형 옹벽'],
             [207, '부직포',                     '배면 흙 유실 방지'],
             [167, 'PE이중벽관1종(유공관)',      '옹벽 뒤 배수관'],
         ]],
        // 주차장 조성 + 단지 도로 안전시설 통합
        ['key' => 'parking', 'name' => '주차장·단지 도로 정비',
         'summary' => '주차장 바닥을 깔고 차량 정지·감속·진입 차단 시설을 갖출 때',
         'img' => null, 'img_kind' => 'product', 'img_alt' => '잔디블록',
         'materials' => [
             [264, '잔디블록',                   '녹지형 주차 바닥'],
             [71,  '인조화강블록(투수)',         '투수형 주차 바닥'],
             [272, '콘크리트경계석',             '주차면 구획'],
             [204, '카스토퍼',                   '차량 정지'],
             [205, '과속방지턱',                 '통로 감속'],
             [153, '험프석',                     '고원식 횡단보도'],
             [206, '우레탄볼라드',               '시선 유도'],
             [148, '스텐볼라드',                 '차량 진입 차단'],
             [147, '스텐바리케이드',             '통행 통제'],
             [268, '무소음트렌치',               '출입구 빗물 차단'],
         ]],
        ['key' => 'garden', 'name' => '정원·화단 꾸미기',
         'summary' => '나무 주변·화단·정원 계단 등 조경 공간을 만들 때',
         'img' => '/assets/images/home-use-포장.png', 'img_kind' => 'photo', 'img_alt' => '꽃과 나무 사이로 블록이 깔린 정원 보행로',
         'materials' => [
             [104, '수목보호틀(인조석)',         '나무 주변 보호'],
             [161, '수목보호틀(화강석)',         '석재형 수목보호틀'],
             [100, '디자인블록(큐블록)',         '화단 쌓기·앉음벽'],
             [79,  '조경경계블록',               '화단 테두리'],
             [92,  '녹지경계블록',               '잔디·화단 경계'],
             [158, '화산석',                     '경계·조경석'],
             [156, '판석',                       '디딤·바닥 석재'],
             [159, '사비석',                     '자연석 디딤돌'],
             [155, '계단석',                     '정원 계단'],
             [264, '잔디블록',                   '녹화 바닥'],
         ]],
        ['key' => 'building-drain', 'name' => '건물 외부 배수시설 설치',
         'summary' => '건물을 짓거나 고치며 주변 오수받이·빗물받이·홈통을 연결할 때',
         'img' => '/assets/images/home-use-배관.png', 'img_kind' => 'photo', 'img_alt' => '땅속에 연결된 배관',
         'materials' => [
             [178, 'PE오수받이',                 '오수관 연결 받이'],
             [177, 'PE빗물받이',                 '마당 빗물 집수'],
             [179, 'PE홈통받이',                 '선홈통 빗물 연결'],
             [185, 'PVC오수받이',                '소구경 오수받이'],
             [184, 'PVC소형맨홀',                '배수 점검구'],
             [182, 'PVC관VN(구VG1)',             '건물 배수관'],
             [186, 'PVC배수관이경소켓',          '관경 변환 연결'],
             [198, 'PVC관(VN)분기구',            '공공 하수관 접속'],
             [176, 'PE배수로',                   '건물 둘레 배수로'],
             [112, '각종주철(칼라.정화조.제수변)', '정화조 뚜껑'],
         ]],
        // 지하 배수관 매설 + 집수정·맨홀 설치 + 도로 밑 횡단 배수 통합
        ['key' => 'sewer', 'name' => '배수관 묻기·맨홀 설치',
         'summary' => '빗물·하수를 땅속 관으로 보내거나, 맨홀·집수정·도로 밑 물길을 놓을 때',
         'img' => '/assets/images/home-use-맨홀.png', 'img_kind' => 'photo', 'img_alt' => '집수정 구조물과 맨홀 뚜껑',
         'materials' => [
             [163, 'PE이중벽관1종',              '차도 하부 우수관'],
             [164, 'PE이중벽관2종',              '일반 우수·오수관'],
             [50,  '흄관(B형)',                  '대구경 콘크리트관'],
             [174, '고강성PVC이중벽관',          '오수 관로'],
             [59,  '사각맨홀(집수정)',           '빗물 집수정'],
             [61,  '원형맨홀',                   '관로 점검 맨홀'],
             [108, '주철뚜껑(우수/오수/전기/통신)', '맨홀 뚜껑'],
             [9,   '1련암거',                    '도로 횡단 구조물'],
             [269, '파형강관',                   '대구경 횡배수관'],
             [196, 'PE이중벽관분기구',           '지관 접속'],
         ]],
        ['key' => 'subdrain', 'name' => '땅속 물 빼기(지하 배수)',
         'summary' => '운동장·농경지·옹벽 뒤가 늘 질척여 땅속 물을 빼야 할 때',
         'img' => '/assets/images/home-PE이중벽관.png', 'img_kind' => 'product', 'img_alt' => 'PE이중벽관',
         'materials' => [
             [167, 'PE이중벽관1종(유공관)',      '유공 배수관'],
             [168, 'PE이중벽관2종(유공관)',      '일반 지하 배수'],
             [180, 'THP주름관',                  '암거배수 주름관'],
             [181, 'THP유공관',                  '암거배수 유공관'],
             [173, 'PE다중벽관(유공관)',         '고강성 유공관'],
             [137, '파형강관(유공관)',           '대구경 지하 배수'],
             [52,  '유공흄관',                   '콘크리트 유공관'],
             [207, '부직포',                     '막힘 방지 필터'],
             [38,  '침투측구수로관(앵글부착)',   '지표수 침투'],
             [63,  '침투집수정',                 '집수·침투'],
         ]],
        ['key' => 'river', 'name' => '하천·비탈면 보호',
         'summary' => '하천 둑이나 비탈면이 깎이고 쓸려 내려가는 것을 막을 때',
         'img' => '/assets/images/home-need-돌망태.png', 'img_kind' => 'product', 'img_alt' => '돌망태',
         'materials' => [
             [95,  '호안블록(저류용)',           '저류지·하천 호안'],
             [96,  '호안블록(식생용)',           '식생형 호안'],
             [162, '돌망태(개비온)',             '사면 침식 방지'],
             [81,  '축조블록(평면형)',           '식생 축조 호안'],
             [80,  '축조블록(곡선형)',           '곡선 구간 호안'],
             [99,  '조경잔디블록',               '비탈면 녹화'],
             [264, '잔디블록',                   '완경사 녹화'],
             [27,  '보강수로관(무개생태)',       '생태형 소하천 수로'],
             [28,  '생태탈출로',                 '소동물 탈출로'],
             [207, '부직포',                     '토사 유실 방지'],
         ]],
        // 담장 쌓기 + 건물 기초·바닥 콘크리트 통합
        ['key' => 'fence', 'name' => '담장·기초 콘크리트 공사',
         'summary' => '블록·벽돌 담장을 쌓거나 기초·바닥 콘크리트를 칠 때',
         'img' => '/assets/images/home-need-벽돌.png', 'img_kind' => 'product', 'img_alt' => '벽돌',
         'materials' => [
             [102, '속빈콘크리트블록',           '담장 기본 블록'],
             [101, '콘크리트벽돌',               '담장·벽체'],
             [100, '디자인블록(큐블록)',         '경관형 담장'],
             [144, '와이어메쉬',                 '기초·바닥 콘크리트 보강'],
             [57,  'PHC파일A종(고강도파일)',     '기초 말뚝'],
         ]],
        // 전기·통신·가로등 지중시설 + 수도 급수관 연결 통합
        ['key' => 'utility', 'name' => '전기·통신·수도 설비 묻기',
         'summary' => '전기·통신선이나 수도관을 땅에 묻고, 가로등을 세울 때',
         'img' => '/assets/images/home-맨홀.png', 'img_kind' => 'product', 'img_alt' => '맨홀 뚜껑',
         'materials' => [
             [64,  '전기맨홀',                   '전기 케이블 맨홀'],
             [65,  '통신맨홀',                   '통신 케이블 맨홀'],
             [210, 'PE전원박스',                 '지중 접속함'],
             [103, '가로등기초',                 '가로등 기초'],
             [108, '주철뚜껑(우수/오수/전기/통신)', '전기·통신 맨홀 뚜껑'],
             [187, 'HIVP내충격용수도관',         '수도관'],
             [192, 'HIVP내충격용수도관이경소켓', '수도관 연결'],
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

/** 키로 작업 유형 1건 조회(없으면 null) */
function work_type_find(string $key): ?array
{
    foreach (work_types() as $t) {
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
