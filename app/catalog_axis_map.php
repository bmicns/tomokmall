<?php
declare(strict_types=1);
/**
 * 업체 규격 칸 → 케이토목 이름표 칸 대응표(데이터) — 여러 업체 규격 통일(사용자 결정 2026-11-23, S26)
 *
 * 케이토목 이름표가 기준이다. 상용콘크리트(syc_products — 분류는 syc_categories 공용) 상품을 고객 화면에 반영할 때
 * (app/syc_publish.php syc_publish_std_apply) 업체 칸 값을 이 표대로 케이토목 칸(products.dim_*)에 옮겨 담는다.
 * 근거: 프로젝트 파일 analysis/ktomok-syc-spec-axes-crosswalk.md(11-18 값 대조 64짝)·
 *       analysis/spec-unify/ktomok-syc-axis-crosswalk-drawings.md(11-23 도면 대조 33짝), docs/02-design/syc-products.md 19장
 *
 * 한 줄 = 업체 분류코드(syc_categories.slug) => [업체 칸 키 => 케이토목 칸 키]
 *  - 표에 있는 분류: 적힌 칸만 케이토목 칸에 담고, 나머지 업체 칸은 products 치수 칸에 담지 않는다(상세 화면 '세부 치수'는
 *    products.spec_detail 에 업체 이름표 그대로 남아 계속 보인다).
 *  - 표에 없는 분류(대응 케이토목 품명이 없거나 아직 대조 전): 지금처럼 같은 키 칸에 그대로 담는다.
 * 지킬 것(사용자 결정):
 *  - 순서·위치가 다른 칸은 도면으로 확인한 대로(보강토·축조블록 가로/세로/두께 → 폭/높이/깊이 등)
 *  - PE 빗물받이(차도측·다용도)·PE 오수받이 가로·세로는 묶지 않음(상콘 410 = 틀 바깥, 케이토목 400 = 그레이팅) → 높이만
 *  - 측구수로관: 케이토목 두께 = 바닥두께 = 상콘 t3(상콘 두께 T 는 윗면 벽두께라 다른 곳). 목록 공통 칸에서는 뺀다(CATALOG_AXIS_LIST_EXCLUDE)
 *  - 칸 키는 업체 쪽 SYC_AXIS_KEYS(app/syc_catalog.php), 케이토목 쪽 SPEC_AXIS_COLUMNS(app/spec_axes.php)
 */

require_once __DIR__ . '/spec_axes.php';   // SPEC_AXIS_COLUMNS — 케이토목 칸 키 → products 칸

const CATALOG_AXIS_MAP = [
    // 수로관
    'syc-s1'  => ['w' => 'w', 'h' => 'depth', 'l' => 'l', 't3' => 't'],              // 측구수로관 → A·B·AN·Z형(높이 = 깊이, 바닥두께 t3 = 케이토목 두께)
    'syc-s8'  => ['w' => 'w', 'h' => 'depth', 'l' => 'l'],                            // 유공 침투측구 → 침투측구(케이토목 두께는 도면 위치 표시 없음, 상콘 세로는 상세)
    'syc-s10' => ['w' => 'w', 'depth' => 'depth', 'l' => 'l', 't' => 't'],           // 벤치플륨Ⅱ(안나비 = 폭)
    'syc-s11' => ['w' => 'w', 'depth' => 'depth', 'l' => 'l', 't' => 't'],           // 벤치플륨Ⅲ
    'syc-s12' => ['w' => 'w', 'h' => 'depth', 'l' => 'l', 't' => 't'],               // U형 플륨(높이 = 깊이)
    'syc-s13' => ['w' => 'w', 'depth' => 'depth', 'l' => 'l', 't2' => 't'],          // 환경수로관(두께 = 도면 e = 우리 DB t2)
    'syc-s14' => ['d' => 'd_i', 'w' => 'd_o', 'h' => 'depth', 'l' => 'l'],           // 원형수로관 NS(직경 = 내경, 폭 = 외경, 높이 = 케이토목 '깊이' — 바깥 높이)
    'syc-s15' => ['d_i' => 'd_i', 'w_o' => 'd_o', 'h_o' => 'depth', 'l' => 'l', 't' => 't'],   // 원형사각수로관 RDG
    'syc-s79' => ['d_i' => 'd_i', 'w' => 'd_o', 'h' => 'h', 'l' => 'l'],             // 원심력수로관 무개·유개(호칭 = 내경, 폭 = 외경, 두께는 값이 달라 상세)
    'syc-s18' => ['w' => 'w', 'depth' => 'h', 'l' => 'l'],                           // 알루미늄트렌치(상콘 깊이 = 케이토목 높이, 상콘 두께는 판 두께라 다름)
    'syc-s19' => ['d_i' => 'd_i', 'l' => 'l', 't' => 't'],                           // 흄관
    'syc-s20' => ['d_i' => 'd_i', 'l' => 'l', 't' => 't'],                           // V·R관
    'syc-s23' => ['w' => 'w', 'h' => 'h', 'l' => 'l', 't_top' => 't_tb', 't_wall' => 't_wall'],   // 개거수로(상판두께 = 상하두께)
    'syc-s46' => ['w' => 'w', 'depth' => 'depth', 'l' => 'l'],                       // PE 배수로
    // 맨홀·집수정
    'syc-s24' => ['w' => 'w', 'v' => 'v', 'h' => 'h', 't_wall' => 't'],              // 사각맨홀(집수정)(벽체두께 = 두께)
    'syc-s26' => ['d_i' => 'd_i', 'h' => 'h', 't_wall' => 't'],                      // 원형맨홀
    'syc-s28' => ['d_i' => 'd_i', 'h' => 'h', 't_wall' => 't'],                      // 조립식 맨홀
    'syc-s29' => ['w' => 'w', 'v' => 'v', 'h' => 'h', 't' => 't'],                   // 전기통신 맨홀
    'syc-s30' => ['w' => 'w', 'v' => 'v', 'h' => 'h', 't' => 't'],                   // 통신맨홀
    'syc-s78' => ['w' => 'w', 'v' => 'v', 'h' => 'h'],                               // 유공 침투집수정(상콘 길이는 상세)
    'syc-s31' => ['w' => 'w_t', 'v' => 'w_b', 'h' => 'h'],                           // 가로등 기초(가로 = 상부폭, 세로 = 하부폭)
    // PE·PVC 받이
    'syc-s36' => ['d' => 'd_i', 'd_inlet' => 'd_inlet', 'd_outlet' => 'd_outlet'],   // 내충격 PVC오수받이(물받이지름 = 내경)
    'syc-s41' => ['h' => 'h'],                                                       // PE 빗물받이(차도측) — 가로·세로 묶지 않음(11-23)
    'syc-s42' => ['h' => 'h'],                                                       // PE 오수받이 — 위와 같음
    'syc-s43' => ['h' => 'h'],                                                       // 다용도 빗물받이 — 위와 같음
    'syc-s44' => ['w' => 'w', 'v' => 'v', 'h' => 'h'],                               // 가정용 빗물받이(300×400×450 같은 값)
    'syc-s45' => ['d' => 'd_i', 'h' => 'h'],                                         // PE 홈통받이(직경 = 내경)
    // 주철뚜껑(원형) — 사각은 주철뚜껑(사각)으로 나눔(S26)
    'syc-s51' => ['d' => 'd', 'h' => 'h'],                                           // 단체표준 주철 COVER(전체높이 = 높이, 뚜껑높이는 상세)
    'syc-s85' => ['d' => 'd', 'h' => 'h'],                                           // 원형 맨홀 뚜껑
    // 블록·경계석
    'syc-s54' => ['w' => 'w', 'v' => 'v', 't' => 't'],                               // 인조화강블록 200*200
    'syc-s55' => ['w' => 'w', 'v' => 'v', 't' => 't'],                               // 인조화강블록 200*100
    'syc-s56' => ['w' => 'w', 'v' => 'v', 't' => 't'],                               // U-블록
    'syc-s57' => ['w' => 'w', 'v' => 'v', 't' => 't'],                               // I₂-블록
    'syc-s58' => ['w' => 'w', 'v' => 'v', 't' => 't'],                               // 점자유도 블록
    'syc-s59' => ['w' => 'w', 'v' => 'v', 't' => 't'],                               // 고강도 점자유도블록
    'syc-s60' => ['w' => 'w', 'v' => 'v', 't' => 't'],                               // 그린잔디블록 → 잔디블록(새 이름표 가로·세로·두께)
    'syc-s61' => ['w' => 'w', 'v' => 'v', 't' => 't'],                               // 잔디블록(I형)
    'syc-s63' => ['w' => 'w', 't' => 'h', 'v' => 'depth'],                           // 보강토 옹벽블록(두께 200 = 높이, 세로 420 = 깊이)
    'syc-s64' => ['w' => 'w', 't' => 'h', 'v' => 'depth'],                           // 보강토 옹벽 마감블록
    'syc-s66' => ['w' => 'w', 'v' => 'v', 't' => 'h'],                               // 대형 잔디블록 → 조경잔디블록(두께 = 높이)
    'syc-s67' => ['w' => 'w', 'v' => 'h', 't' => 'depth'],                           // 보강토 식생 축조블록(세로 = 높이, 두께 = 뒷길이 = 깊이)
    'syc-s68' => ['w' => 'w', 'v' => 'v', 't' => 'h'],                               // I형 호안 블록(두께 = 높이)
    'syc-s71' => ['w' => 'w', 'v' => 'v', 't' => 't'],                               // 녹지 경계 블록
    'syc-s72' => ['w_t' => 'w_t', 'w_b' => 'w_b', 'h' => 'h'],                       // 콘크리트 경계석(윗면 = 상부폭, 밑면 = 하부폭, 길이는 상세)
    'syc-s73' => ['w_t' => 'w_t', 'w_b' => 'w_b', 'h' => 'h'],                       // 인조 경계석
    'syc-s74' => ['w' => 'w', 'h' => 'h'],                                           // 화강 경계석(도로·보차도·낮춤) — 길이·낮은쪽높이는 상세
    'syc-s87' => ['w' => 'w', 'h' => 'h_high', 'h1' => 'h_low', 'l' => 'l'],         // 화강 경사 경계석(높이 = 상단, 낮은쪽 = 하단)
    // 스틸
    'syc-s76' => ['d_i' => 'd_i', 'l' => 'l', 't' => 't'],                           // 파형강관(관경 = 내경, 피치·깊이는 상세)
    'syc-s77' => ['w' => 'w', 'v' => 'v', 'mesh' => 'mesh', 'wire_d' => 'wire_d', 'wire_no' => 'wire_no'],   // 용접철망
    'syc-w5'  => ['d' => 'd', 'h' => 'h', 'w' => 'l'],                               // 스텐볼라드 U형(상콘 폭 = 케이토목 길이 — 다리 사이)
];

/**
 * 목록 공통 칸에서 빼는 케이토목 칸(공통 분류 id => 칸 키들) — 값은 상세 화면 '세부 치수'로.
 * 측구수로관 A·B·AN·Z형 두께(= 바닥두께, 11-23 사용자 결정 4), PE 빗물받이·오수받이 가로·세로(공통 칸은 높이만, 11-23 사용자 결정 6)
 */
const CATALOG_AXIS_LIST_EXCLUDE = [
    33 => ['t'], 34 => ['t'], 35 => ['t'], 36 => ['t'],
    177 => ['w', 'v'], 178 => ['w', 'v'],
];

/** 상세 화면에서 케이토목 칸 이름을 바꿔 보여 줄 곳(공통 분류 id => [칸 키 => 이름]) — 측구수로관 두께 = 바닥두께(11-23) */
const CATALOG_AXIS_DETAIL_LABEL = [
    33 => ['t' => '바닥두께'], 34 => ['t' => '바닥두께'], 35 => ['t' => '바닥두께'], 36 => ['t' => '바닥두께'],
];

/**
 * 업체 칸 값이 하나도 없는 업체 상품(동아산업 등 — 원본 분류에 이름표·대응표가 없음)의 규격 원문을 반영할 공통 분류(케이토목)
 * 이름표로 읽어 케이토목 칸에 담는다(사용자 결정 2026-11-24 — 업체와 관계없이 등록만 하면 규격 정리, S33). 케이토목 상품과
 * 같은 규칙(spec_axes_parse). 업체 엑셀은 규격·옵션을 나눠 적는 경우가 많아 원문만 → '원문_옵션' → '옵션_원문'(모델 코드가
 * 앞에 오는 형식) 순서로 읽어 본다. 치수를 하나도 못 읽으면 null(목록은 지금처럼 원문 한 줄). 순수 함수
 * @param list<array> $axes    공통 분류 이름표(spec_axes_for_category)
 * @param list<string> $dimCols products 에 있는 치수 칸 이름들
 * @return ?array{cols: array<string, ?string>, code: ?string, option: ?string}
 */
function catalog_axis_from_spec(string $spec, ?string $option, array $axes, array $dimCols): ?array
{
    $spec = trim($spec);
    $opt  = trim((string) $option);
    if ($spec === '' || !$axes) { return null; }
    $tries = $opt === '' ? [[$spec, true]] : [[$spec, true], [$spec . '_' . $opt, false], [$opt . '_' . $spec, false]];
    foreach ($tries as [$value, $keepOpt]) {
        $parsed = spec_axes_parse($value, $axes);
        if ($parsed === null) { continue; }
        $cols = array_fill_keys($dimCols, null);
        foreach ($parsed['dims'] as $k => $v) {
            $c = SPEC_AXIS_COLUMNS[$k] ?? null;
            if ($c === null || !array_key_exists($c, $cols)) { continue; }
            $cols[$c] = in_array($k, SPEC_AXIS_TEXT, true) ? (string) $v : spec_axis_num((float) $v);
        }
        if (!array_filter($cols, static fn($v) => $v !== null && $v !== '')) { continue; }
        // 원문만 읽었으면 업체 옵션을 뒤에 이어 붙임(같은 글자는 한 번). 옵션까지 함께 읽었으면 읽은 옵션이 곧 업체 옵션 포함
        $po    = trim((string) ($parsed['option'] ?? ''));
        $parts = $keepOpt ? array_values(array_unique(array_filter([$po, $opt], static fn($x) => $x !== ''))) : ($po !== '' ? [$po] : []);
        $code  = trim((string) ($parsed['spec_code'] ?? ''));
        if ($code !== '' && $parts) { $parts = array_values(array_filter($parts, static fn($x) => $x !== $code)); }
        return ['cols' => $cols, 'code' => $code !== '' ? $code : null, 'option' => $parts ? implode('_', $parts) : null];
    }
    return null;
}

/**
 * 업체 상품 1행의 치수를 products 치수 칸 값으로 바꿈. 순수 함수
 * @param string        $vendorSlug syc_categories.slug
 * @param array         $p          syc_products 행
 * @param list<string>  $dimCols    products 에 있는 치수 칸 이름들(쓸 칸 목록)
 * @param callable      $srcCol     업체 칸 키 → syc_products 칸 이름(syc_axis_column)
 * @return array<string, mixed> 칸 이름 => 값($dimCols 전부)
 */
function catalog_axis_remap(string $vendorSlug, array $p, array $dimCols, callable $srcCol): array
{
    $map = CATALOG_AXIS_MAP[$vendorSlug] ?? null;
    if ($map === null) {                                   // 표에 없는 분류: 같은 키 칸에 그대로(지금 동작)
        $out = [];
        foreach ($dimCols as $c) { $out[$c] = $p[$c] ?? null; }
        return $out;
    }
    $out = array_fill_keys($dimCols, null);
    foreach ($map as $from => $to) {
        $sc = $srcCol($from);
        $tc = SPEC_AXIS_COLUMNS[$to] ?? null;
        if ($sc === null || $tc === null || !array_key_exists($tc, $out)) { continue; }
        $v = $p[$sc] ?? null;
        if ($v === null || $v === '') { continue; }
        $out[$tc] = $v;
    }
    return $out;
}

/** 상품 행 → 업체 구분 키(목록 공통 칸 판단용). 케이토목 = 출처 ktomok·예전 dacity·출처 없음(S25) */
function catalog_row_vendor(array $p): string
{
    $s = trim((string) ($p['external_source'] ?? ''));
    return ($s === '' || $s === 'ktomok' || $s === 'dacity') ? 'ktomok' : $s;
}

/**
 * 목록 공통 칸(S26): 이름표 칸 중 목록 머리글·필터에 둘 칸. 순수 함수
 *  - 목록에서 빼기로 한 칸(CATALOG_AXIS_LIST_EXCLUDE — 결과 분류가 모두 그 분류일 때) 제외
 *  - 업체가 둘 이상이면 모든 업체에 값이 있는 칸만(한쪽이 '-'인 칸은 머리글에 안 둠). 업체 하나면 값 있는 칸 전부
 *  - 업체 판단은 축 값을 써도 되는 행(spec_axes_row_fresh)만. 나머지 칸은 상세 화면 '세부 치수'
 * @param list<array> $defs 이름표(skip 제외)
 * @param list<array> $rows 목록 상품 행
 * @param list<int>   $categoryIds 결과 상품의 분류 id
 * @return list<array> 공통 칸 이름표(순서 그대로)
 */
function catalog_list_common_axes(array $defs, array $rows, array $categoryIds): array
{
    $cats = array_values(array_unique(array_map('intval', $categoryIds)));
    $excl = [];
    if ($cats) {
        $excl = CATALOG_AXIS_LIST_EXCLUDE[$cats[0]] ?? [];
        foreach ($cats as $c) { $excl = array_intersect($excl, CATALOG_AXIS_LIST_EXCLUDE[$c] ?? []); }
    }
    $has = [];   // 업체 => [칸 => true]
    foreach ($rows as $p) {
        if (!spec_axes_row_fresh($p)) { continue; }
        $vk = catalog_row_vendor($p);
        $has[$vk] ??= [];
        foreach ($defs as $a) {
            $k = (string) $a['axis_key'];
            if (spec_axes_row_value($p, $k) !== null) { $has[$vk][$k] = true; }
        }
    }
    if (!$has) { return []; }
    $out = [];
    foreach ($defs as $a) {
        $k = (string) $a['axis_key'];
        if (in_array($k, $excl, true)) { continue; }
        $all = true;
        foreach ($has as $set) { if (empty($set[$k])) { $all = false; break; } }
        if ($all) { $out[] = $a; }
    }
    return $out;
}
