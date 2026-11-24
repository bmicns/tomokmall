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
        $r = catalog_axis_parse_try($value, $axes, $dimCols);
        if ($r === null) { continue; }
        // 원문만 읽었으면 업체 옵션을 뒤에 이어 붙임(같은 글자는 한 번). 옵션까지 함께 읽었으면 읽은 옵션이 곧 업체 옵션 포함
        $parts = $keepOpt ? array_values(array_unique(array_filter([(string) $r['option'], $opt], static fn($x) => $x !== ''))) : ($r['option'] !== null ? [$r['option']] : []);
        if ($r['code'] !== null) { $parts = array_values(array_filter($parts, static fn($x) => $x !== $r['code'])); }
        return ['cols' => $r['cols'], 'code' => $r['code'], 'option' => $parts ? implode('_', $parts) : null];
    }
    return null;
}

/** spec_axes_parse 한 번 → 칸 값(치수 1개 이상일 때만). 순수 함수 @return ?array{cols: array<string, ?string>, code: ?string, option: ?string} */
function catalog_axis_parse_try(string $value, array $axes, array $dimCols): ?array
{
    $parsed = spec_axes_parse($value, $axes);
    if ($parsed === null) { return null; }
    $cols = array_fill_keys($dimCols, null);
    foreach ($parsed['dims'] as $k => $v) {
        $c = SPEC_AXIS_COLUMNS[$k] ?? null;
        if ($c === null || !array_key_exists($c, $cols)) { continue; }
        $cols[$c] = in_array($k, SPEC_AXIS_TEXT, true) ? (string) $v : spec_axis_num((float) $v);
    }
    if (!array_filter($cols, static fn($v) => $v !== null && $v !== '')) { return null; }
    $code = trim((string) ($parsed['spec_code'] ?? ''));
    $po   = trim((string) ($parsed['option'] ?? ''));
    return ['cols' => $cols, 'code' => $code !== '' ? $code : null, 'option' => $po !== '' ? $po : null];
}

/*
 * ── 업체 상품 규격 읽기 보완(사용자 요청 2026-11-24 — 원문으로 남는 상품은 줄이되 치수를 잘못 넣지 않게, S33) ──
 * 기준 상품 = 같은 공통 분류의 케이토목 상품 중 칸 값이 지금 규격 기준인 것(spec_axes_row_fresh·spec_axes_row_named).
 * 아래 규칙은 확실한 것만 채우고, 조금이라도 맞지 않으면 채우지 않는다(원문 한 줄 그대로).
 *  ① 같은 규격: 업체 규격('원문', '원문_옵션', '옵션_원문', 원문이 비면 '옵션')이 기준 상품 규격 원문과 글자 그대로 같으면(띄어쓰기·대소문자만 무시)
 *     그 상품의 칸 값·모델 코드·옵션을 그대로 씀. 같은 규격의 기준 상품끼리 값이 다르면 쓰지 않음
 *  ② 형식 맞춰 읽기: 이름표 형식이 괄호(paren)면 '옵션 (원문)', 앞 글자(lead_text·shape)면 '옵션 원문',
 *     접속구(pipe_port)면 '원문*접속관(구 수)나머지'로 붙여 케이토목과 같은 규칙으로 읽음
 *  ③ 같은 모양에서 배우기: 규격의 숫자 모양(개수와 각 숫자 앞뒤 표시 D·Ø·H·T…)이 기준 상품 2개 이상과 똑같고, 그 기준 상품들이
 *     '몇 번째 숫자 = 어느 칸'으로 모두 같게(하나로만) 읽힐 때만 그 자리의 숫자를 씀. 단위가 다르거나(m 칸) 자리가 둘 이상이면 배우지 않음
 *  ④ 끝 두께 떼기: '…*200T' 처럼 끝 숫자 하나가 T 로 끝나 이름표 칸 수보다 숫자가 하나 많으면, 앞부분만 읽고 '200T' 는 옵션으로(값을 버리지 않음)
 *  ⑤ 분기구: 이름표가 '가지관 내경' 하나뿐이면 '본관*가지관D숫자'의 끝 숫자 = 가지관 내경, 앞 글자는 옵션('본관 …_가지관 …')
 *  ⑥ 범위 값: 이름표가 '적용 크기' 하나뿐이고 원문이 비면 옵션 '형식_숫자-숫자' → 모델 코드 = 형식, 적용 크기 = 범위
 *  ⑦ 구분 기호: 'D116_D142*H850'·'D200용'·'1120*620*h80' 처럼 '_'·'용'·앞 h 때문에 못 읽으면 정리해서 다시 읽음(숫자 수 = 이름표 칸 수, 칸이 모두 찰 때만)
 *  ② 보완: 괄호 형식에서 옵션이 '6T_1롤' 이면 '6T (원문 1롤)'
 *  ⑧ 앞에서부터 읽기: 치수 조각만 있는 규격을 이름표 순서대로 채우고 모자란 뒤 칸은 비움(표시 D·H·T 가 칸과 맞을 때만). 고정값 칸 자리 값은 상세 화면으로
 *  ⑨ 치수 없는 상품: 원문이 비고 옵션에 숫자가 없으면 옵션을 규격외정보로
 */

/** 규격 글자 비교용(띄어쓰기·대소문자 무시) */
function catalog_spec_norm(string $s): string
{
    return mb_strtolower((string) preg_replace('/\s+/u', '', $s));
}

/** 규격 글자의 숫자들: [[앞 표시, 숫자, 뒤 표시]…] — 영문·숫자 바로 뒤 숫자(K2·VG150·M16)는 코드라 뺌. 순수 함수 */
function catalog_spec_numbers(string $s): array
{
    preg_match_all('/(?<![A-Za-z0-9.])([DdHhØ]?)(\d+(?:\.\d+)?)(mm|MM|[TtHhMmØ])?/u', $s, $m, PREG_SET_ORDER);
    $out = [];
    foreach ($m as $x) {
        $pre = $x[1] === 'Ø' ? 'Ø' : strtoupper($x[1]);
        $suf = ($x[3] ?? '') === '' ? '' : (in_array($x[3], ['mm', 'MM'], true) ? 'mm' : ($x[3] === 'Ø' ? 'Ø' : strtoupper($x[3])));
        $out[] = [$pre, $x[2], $suf];
    }
    return $out;
}

/** 숫자 모양 서명: 'D#|D#|D#', '#|#|#H' … */
function catalog_spec_signature(array $nums): string
{
    return implode('|', array_map(static fn($n) => $n[0] . '#' . $n[2], $nums));
}

/**
 * 기준 상품(같은 공통 분류의 케이토목 상품) → 분류별 색인. 순수 함수
 * @param iterable<array> $rows products 행(category_id·spec·spec_dims_src·spec_code·spec_option·dim_* 포함)
 * @return array<int, array{exact: array<string, array|false>, sig: array<string, array<string,int>|false>}>
 */
function catalog_ref_index(iterable $rows): array
{
    $cols = array_values(SPEC_AXIS_COLUMNS);
    $exact = []; $groups = [];
    foreach ($rows as $r) {
        if (!(spec_axes_row_fresh($r) || spec_axes_row_named($r))) { continue; }
        $cid = (int) $r['category_id'];
        $vals = [];
        foreach ($cols as $c) { if (($r[$c] ?? null) !== null && $r[$c] !== '') { $vals[$c] = (string) $r[$c]; } }
        $code = trim((string) ($r['spec_code'] ?? '')); $opt = trim((string) ($r['spec_option'] ?? ''));
        $pay  = ['cols' => $vals, 'code' => $code !== '' ? $code : null, 'option' => $opt !== '' ? $opt : null];
        $k = catalog_spec_norm((string) $r['spec_dims_src']);
        $exact[$cid][$k] = !isset($exact[$cid][$k]) || $exact[$cid][$k] === $pay ? $pay : false;   // 같은 규격인데 값이 다르면 쓰지 않음
        if ($vals) {
            $nums = catalog_spec_numbers((string) $r['spec_dims_src']);
            $groups[$cid][catalog_spec_signature($nums)][] = [$nums, $vals];
        }
    }
    $out = [];
    foreach ($exact as $cid => $e) { $out[$cid] = ['exact' => $e, 'sig' => []]; }
    foreach ($groups as $cid => $sigs) {
        foreach ($sigs as $sig => $list) {
            $out[$cid] ??= ['exact' => [], 'sig' => []];
            $out[$cid]['sig'][$sig] = count($list) >= 2 ? catalog_ref_learn($list) : false;
        }
    }
    return $out;
}

/**
 * 같은 숫자 모양의 기준 상품들 → 칸 => 숫자 자리. 모든 상품에서 같은 칸이 차 있고, 칸마다 값이 같은 자리가 모든 상품에서 하나로만
 * 정해질 때만(정사각 1500*1500 처럼 자리가 둘이면 다른 상품으로 좁힘). 아니면 false. 순수 함수
 */
function catalog_ref_learn(array $list): array|false
{
    $colSet = null; $cand = [];
    foreach ($list as [$nums, $vals]) {
        $keys = array_keys($vals); sort($keys);
        if ($colSet !== null && $keys !== $colSet) { return false; }
        $colSet = $keys;
        foreach ($vals as $c => $v) {
            if (!is_numeric($v)) { return false; }
            $idx = [];
            foreach ($nums as $i => $n) { if (abs((float) $n[1] - (float) $v) < 0.0001) { $idx[] = $i; } }
            if (!$idx) { return false; }                         // 숫자 그대로가 아닌 값(m → mm 등)은 배우지 않음
            $cand[$c] = isset($cand[$c]) ? array_values(array_intersect($cand[$c], $idx)) : $idx;
            if (!$cand[$c]) { return false; }
        }
    }
    $map = [];
    foreach ($cand as $c => $idx) {
        if (count($idx) !== 1) { return false; }
        $map[$c] = $idx[0];
    }
    return count(array_unique($map)) === count($map) ? $map : false;
}

/**
 * 모양 글자와 이름표가 맞지 않는지: '사각'·'수공'인데 이름표에 직경(d)만 있고 가로·폭(w)이 없음, '원형'인데 w 만 있고 직경 칸이 없음.
 * 이런 상품은 분류가 모양과 달라(케이토목은 사각을 따로 나눔) 숫자를 넣으면 잘못 들어가므로 규칙 ⑦ 을 쓰지 않는다(①~④ 는 기준 상품·형식으로 이미 걸러짐). 순수 함수
 */
function catalog_shape_conflict(string $text, array $keys): bool
{
    $hasD = (bool) array_intersect($keys, ['d', 'd_i', 'd_o']);
    $hasW = in_array('w', $keys, true);
    if (preg_match('/사각|수공/u', $text) && $hasD && !$hasW) { return true; }
    if (str_contains($text, '원형') && $hasW && !$hasD) { return true; }
    return false;
}

/**
 * 업체 상품 1행 → 기준 상품·형식 규칙으로 채울 값(규칙 ①~④, 위 설명). 못 정하면 null. 순수 함수
 * @param array $ref catalog_ref_index()[공통 분류 id] (없으면 [])
 * @return ?array{cols: array<string, ?string>, code: ?string, option: ?string, src: string, rule: string}
 *   src = 규격 원문으로 쓸 글자(업체 원문이 비고 옵션에 규격을 적은 경우 그 옵션)
 */
function catalog_axis_from_ref(string $spec, ?string $option, array $axes, array $ref, array $dimCols, bool $tAsHeight = false): ?array
{
    $spec = trim($spec); $opt = trim((string) $option);
    if ($spec === '' && $opt === '') { return null; }
    $texts = $spec === '' ? [$opt] : array_values(array_unique(array_filter([$spec, $opt !== '' ? $spec . '_' . $opt : '', $opt !== '' ? $opt . '_' . $spec : ''])));
    $src = $spec !== '' ? $spec : $opt;
    $fill = static function (array $vals) use ($dimCols): array {
        $cols = array_fill_keys($dimCols, null);
        foreach ($vals as $c => $v) { if (array_key_exists($c, $cols)) { $cols[$c] = $v; } }
        return $cols;
    };
    // ① 같은 규격
    foreach ($texts as $t) {
        $hit = $ref['exact'][catalog_spec_norm($t)] ?? null;
        if (is_array($hit)) { return ['cols' => $fill($hit['cols']), 'code' => $hit['code'], 'option' => $hit['option'], 'src' => $src, 'rule' => 'same']; }
    }
    $keys = array_map(static fn($a) => (string) $a['axis_key'], $axes);
    // ⑨ 치수 없는 상품(주문제작품 등): 원문이 비고 옵션에 숫자가 하나도 없으면 옵션을 규격외정보로(목록 규격 칸 '-'), 칸은 비움
    if ($spec === '' && !preg_match('/\d/u', $opt)) {
        return ['cols' => $fill([]), 'code' => null, 'option' => $opt, 'src' => $src, 'rule' => 'nodims'];
    }
    // ⑨ 보완(2026-11-24 '치수가 없는 건 규격을 표기 안 하는 것처럼'): 원문·옵션 모두 숫자가 없으면('주문제작'·'대형') 원문도 규격외정보로(목록 규격 칸 '-')
    if ($spec !== '' && !preg_match('/\d/u', $spec . $opt)) {
        $parts = $opt !== '' && str_contains($opt, $spec) ? [$opt] : array_values(array_filter([$spec, $opt], static fn($x) => $x !== ''));
        return ['cols' => $fill([]), 'code' => null, 'option' => implode('_', $parts), 'src' => $src, 'rule' => 'nodims'];
    }
    // ⑥ 범위 값(집게·클립): 이름표가 '적용 크기' 하나뿐인 분류에서 옵션 '형식_…_숫자-숫자' → 모델 코드 = 형식, 적용 크기 = 범위, 가운데 = 옵션
    if ($spec === '' && $keys === ['d_base'] && preg_match('/^([^_]+)((?:_[^_]+)*)_(\d+(?:\.\d+)?)-(\d+(?:\.\d+)?)$/u', $opt, $m) && (float) $m[3] < (float) $m[4]) {
        $mid = trim(str_replace('_', ' ', $m[2]));
        return ['cols' => $fill(['dim_d_base' => $m[3] . '~' . $m[4]]), 'code' => trim($m[1]), 'option' => $mid !== '' ? str_replace(' ', '_', $mid) : null, 'src' => $src, 'rule' => 'range'];
    }
    if ($spec === '') { return null; }                         // 아래 규칙은 원문 치수가 있을 때만
    // ② 형식 맞춰 읽기
    $fmt = $axes ? spec_axes_fmt($axes) : '';
    $alts = [];
    if ($opt !== '') {
        if ($fmt === 'paren') { $alts[] = $opt . ' (' . $spec . ')'; }
        if ($fmt === 'paren' && str_contains($opt, '_')) {       // 그리드: 규격 '2m*50m' + 옵션 '6T_1롤' → '6T (2m*50m 1롤)'
            $p = explode('_', $opt);
            $alts[] = $p[0] . ' (' . $spec . ' ' . implode(' ', array_slice($p, 1)) . ')';
        }
        if ($fmt === 'lead_text' || $fmt === 'shape') { $alts[] = $opt . ' ' . $spec; }
        if ($fmt === 'pipe_port') {
            $p = explode('_', $opt);
            if (count($p) >= 2) { $alts[] = $spec . '*' . $p[0] . '(' . $p[1] . ')' . implode('', array_slice($p, 2)); }
        }
    }
    foreach ($alts as $a) {
        $r = catalog_axis_parse_try($a, $axes, $dimCols);
        if ($r !== null) { return $r + ['src' => $src, 'rule' => 'format']; }
    }
    // ③ 같은 모양에서 배우기
    foreach ($texts as $t) {
        $nums = catalog_spec_numbers($t);
        $map = $nums ? ($ref['sig'][catalog_spec_signature($nums)] ?? null) : null;
        if (!is_array($map)) { continue; }
        $vals = [];
        foreach ($map as $c => $i) { $vals[$c] = in_array($c, spec_axis_text_columns(), true) ? $nums[$i][1] : spec_axis_num((float) $nums[$i][1]); }
        return ['cols' => $fill($vals), 'code' => null, 'option' => $opt !== '' ? $opt : null, 'src' => $src, 'rule' => 'learned'];
    }
    // ④ 끝 두께 떼기
    if ($axes && preg_match('/^(.+\d[A-Za-z]?)\*(\d+(?:\.\d+)?T)$/u', $spec, $m)) {
        $r = catalog_axis_parse_try($m[1], $axes, $dimCols);
        if ($r !== null && count(array_filter($r['cols'], static fn($v) => $v !== null)) === count(array_filter($axes, static fn($a) => isset(SPEC_AXIS_COLUMNS[(string) $a['axis_key']])))) {
            $parts = array_values(array_unique(array_filter([(string) $r['option'], $m[2], $opt], static fn($x) => $x !== '')));
            return ['cols' => $r['cols'], 'code' => $r['code'], 'option' => implode('_', $parts), 'src' => $src, 'rule' => 'trailing_t'];
        }
    }
    // ⑤ 분기구: 이름표가 '가지관 내경' 하나뿐인 분류에서 '본관*가지관D숫자'(가지관 글자에 숫자 없음) → 가지관 내경 = 끝 숫자,
    //    옵션 = '본관 …_가지관 …_업체 옵션'(케이토목 분기구 표기와 같게)
    if ($keys === ['d_i'] && preg_match('/^([^*]+)\*([^*\d]+?)D?(\d+(?:\.\d+)?)$/u', $spec, $m)) {
        $parts = array_values(array_filter(['본관 ' . trim($m[1]), '가지관 ' . trim($m[2]), $opt], static fn($x) => $x !== ''));
        return ['cols' => $fill(['dim_d_i' => spec_axis_num((float) $m[3])]), 'code' => null, 'option' => implode('_', $parts), 'src' => $src, 'rule' => 'branch'];
    }
    // ⑦ 구분 기호 맞추기: 숫자 묶음 사이 '_' → '*', 끝 숫자 뒤 '용' 떼기, '*h80' → '*80H'. 다시 읽어 이름표 칸이 모두 차고 숫자 수 = 이름표 칸 수일 때만
    //    사각 상품이 원형 이름표 분류에 있는 경우 등(모양 글자와 이름표가 안 맞음)은 쓰지 않음
    if ($axes && !catalog_shape_conflict($spec . ' ' . $opt, $keys)) {
        $alt = (string) preg_replace(['/(?<=\d)_(?=[DdHhØ]?\d)/u', '/(?<=\d)용$/u', '/(?<=\*)h(\d+(?:\.\d+)?)(?=\*|$)/u'], ['*', '', '${1}H'], $spec);
        $need = count(array_filter($keys, static fn($k) => isset(SPEC_AXIS_COLUMNS[$k])));
        if ($alt !== $spec && count(catalog_spec_numbers($alt)) === $need) {
            $r = catalog_axis_parse_try($alt, $axes, $dimCols);
            if ($r !== null && count(array_filter($r['cols'], static fn($v) => $v !== null)) === $need) {
                $parts = array_values(array_unique(array_filter([(string) $r['option'], $opt], static fn($x) => $x !== '')));
                return ['cols' => $r['cols'], 'code' => $r['code'], 'option' => $parts ? implode('_', $parts) : null, 'src' => $src, 'rule' => 'separator'];
            }
        }
    }
    // ⑧ 앞에서부터 읽기(사용자 요청 2026-11-24 '정리 안 되는 부분은 업체 간 공통 치수만 목록, 나머지는 상세 화면')
    //    같은 규격·같은 숫자 모양의 기준 상품끼리 값이 엇갈리면(자리를 하나로 못 정함) 순서만 믿고 넣지 않음
    foreach ($texts as $t) {
        if (($ref['exact'][catalog_spec_norm($t)] ?? null) === false) { return null; }
        $n = catalog_spec_numbers($t);
        if ($n && ($ref['sig'][catalog_spec_signature($n)] ?? null) === false) { return null; }
    }
    $part = catalog_axis_partial($spec, $opt, $axes, $dimCols, $tAsHeight);
    if ($part !== null) { return $part + ['src' => $src, 'rule' => 'partial']; }
    return null;
}

/**
 * ⑧ 앞에서부터 읽기: 규격이 치수 조각('*'·'_' 로 이음 — 300·D116·H850·80T·648Ø)만으로 되어 있고 조각 수가 이름표 칸 수 이하일 때
 * 이름표 순서대로 앞에서부터 채우고 모자란 뒤 칸은 비운다(1120*620 → 가로·세로, 높이 없음).
 * 표시가 칸과 맞아야 함: D·Ø = 지름 칸, H = 높이 칸, T = 두께 칸($tAsHeight 면 높이 칸도 — 조경잔디블록 80T = 높이, 사용자 결정 11-24).
 * 고정값 칸(우레탄볼라드 바닥직경 142~145)은 업체 상품에 고정값을 쓰지 않음: 조각 수가 고정 칸까지 센 수와 같으면 그 자리 값을 상세 화면(세부 치수)으로,
 * 아니면 고정 칸을 건너뛰고 채움. m·t/m 단위 칸이 있거나, 모양 글자와 이름표가 안 맞거나, 조각이 칸보다 많으면 null.
 * 두께 칸이 남고 옵션에 '50T' 같은 조각이 하나뿐이면 그 값을 두께로(옵션에서 뺌). 목록에는 업체 간 공통 칸만 보이고 나머지는 상세(S26)
 * @return ?array{cols: array<string, ?string>, code: null, option: ?string, detail: list<array>}
 */
function catalog_axis_partial(string $spec, string $opt, array $axes, array $dimCols, bool $tAsHeight = false): ?array
{
    if ($spec === '' || !$axes) { return null; }
    $keys = array_map(static fn($a) => (string) $a['axis_key'], $axes);
    if (catalog_shape_conflict($spec . ' ' . $opt, $keys) || array_intersect($keys, ['skip', 'none'])) { return null; }   // 자리만 차지하는 칸이 있으면 순서를 믿을 수 없음
    $tok = '/^([DdHhØ]?)(\d+(?:\.\d+)?)(mm|[TtHhØ])?$/u';
    $pieces = preg_split('/[*_]/u', $spec);
    $nums = [];
    foreach ($pieces as $pc) {
        if (!preg_match($tok, trim($pc), $m)) { return null; }
        $mark = strtoupper(($m[1] === 'Ø' || ($m[3] ?? '') === 'Ø') ? 'D' : ($m[1] !== '' ? $m[1] : (($m[3] ?? '') === 'mm' ? '' : ($m[3] ?? ''))));
        $nums[] = [$mark, $m[2]];
    }
    $use = array_values(array_filter($axes, static fn($a) => isset(SPEC_AXIS_COLUMNS[(string) $a['axis_key']])));
    foreach ($use as $a) { if (($a['unit'] ?? 'mm') !== 'mm' && !in_array((string) $a['axis_key'], SPEC_AXIS_TEXT, true)) { return null; } }
    $isFixed = static fn($a) => ($a['fixed_value'] ?? null) !== null || trim((string) ($a['fixed_text'] ?? '')) !== '';
    $free = array_values(array_filter($use, static fn($a) => !$isFixed($a)));
    $slots = count($nums) === count($use) ? $use : $free;     // 고정 칸 자리에도 값이 있으면 그 칸까지 셈
    if (!$nums || count($nums) > count($slots)) { return null; }
    $fam = static function (string $k): string {
        if (in_array($k, ['d', 'd_i', 'd_o', 'd_base'], true)) { return 'D'; }
        if (in_array($k, ['h', 'h_high', 'h_low', 'h1'], true)) { return 'H'; }
        if (in_array($k, ['t', 't2', 't_tb', 't_wall', 't_top', 't_bottom', 't_mid'], true)) { return 'T'; }
        return '';
    };
    $vals = []; $detail = [];
    foreach ($nums as $i => [$mark, $num]) {
        $a = $slots[$i]; $k = (string) $a['axis_key']; $f = $fam($k);
        $ok = $mark === '' || $mark === $f || ($mark === 'T' && $tAsHeight && $f === 'H');
        if (!$ok) { return null; }
        $v = in_array($k, SPEC_AXIS_TEXT, true) ? $num : spec_axis_num((float) $num);
        if ($isFixed($a)) {
            $detail[] = ['label' => (string) ($a['label'] ?? $k), 'symbol' => (string) ($a['symbol'] ?? ''), 'value' => $v, 'unit' => 'mm', 'main' => false];
            continue;
        }
        $vals[SPEC_AXIS_COLUMNS[$k]] = $v;
    }
    // 남은 두께 칸 + 옵션의 'NT' 조각 하나 → 두께
    $optParts = $opt === '' ? [] : explode('_', $opt);
    $tLeft = array_values(array_filter(array_slice($slots, count($nums)), static fn($a) => $fam((string) $a['axis_key']) === 'T' && !$isFixed($a)));
    $tIdx = array_keys(array_filter($optParts, static fn($x) => (bool) preg_match('/^\d+(?:\.\d+)?T$/u', trim($x))));
    if ($tLeft && count($tIdx) === 1) {
        $vals[SPEC_AXIS_COLUMNS[(string) $tLeft[0]['axis_key']]] = spec_axis_num((float) $optParts[$tIdx[0]]);
        unset($optParts[$tIdx[0]]);
    }
    $cols = array_fill_keys($dimCols, null);
    foreach ($vals as $c => $v) { if (array_key_exists($c, $cols)) { $cols[$c] = $v; } }
    if (!array_filter($cols, static fn($v) => $v !== null)) { return null; }
    $o = implode('_', array_values($optParts));
    return ['cols' => $cols, 'code' => null, 'option' => $o !== '' ? $o : null, 'detail' => $detail];
}

/*
 * ── 새 업체 규격 자동 정리 보완(사용자 결정 2026-11-24 '1~5 모두' — 업체마다 표기가 달라도 등록하면 정리, S33) ──
 *  ⑩ 표기 통일: 숫자 사이 x·X·×·✕·＊ → '*', '*' 앞뒤 띄어쓰기, Φ·φ·ø·∅ → Ø, ㎜ → mm 로 바꾼 뒤 ①~⑨ 를 다시 읽음(원래 글자로 이미 읽히면 그대로)
 *  ⑪ 이름으로 칸 연결: W300·H200·L1000·T50·D300·Ø300·850H·폭300·높이 200·내경300… 처럼 표시가 붙은 숫자를 순서가 아니라
 *     표시가 가리키는 이름표 칸에 넣음(표시가 하나의 칸으로만 정해질 때만, 같은 칸에 두 값이면 쓰지 않음)
 *     — 이 분류 이름표에 없는 칸(L1000 인데 길이 칸 없음)·표시 없는 숫자는 상세 화면 '세부 치수'로, 숫자 없는 글자는 규격외정보로
 *     (목록에는 업체 간 공통 칸만 보임 — catalog_list_common_axes)
 *  그래도 못 읽은 상품은 원문 한 줄 그대로 + 일괄 등록 결과·제품관리에서 찾아 관리자가 직접 고침
 */

/** ⑩ 표기 통일(위 설명). 바뀌지 않으면 같은 글자. 순수 함수 */
function catalog_spec_normalize(string $s): string
{
    $s = strtr($s, ['Φ' => 'Ø', 'φ' => 'Ø', 'ø' => 'Ø', '∅' => 'Ø', '㎜' => 'mm', 'ｍｍ' => 'mm', '＊' => '*']);
    $mark = '(?:[WwHhLlTtDdVvØ]|폭|가로|세로|높이|길이|두께|지름|직경|내경|외경|깊이)';
    // 숫자(+단위·뒤 표시) 다음의 x·×·✕ 뒤에 (표시+)숫자가 오면 '*'. 영문 바로 뒤 숫자(M16x50 의 16)는 코드라 그대로
    $s = (string) preg_replace('/(?<![A-Za-z0-9.])((?:' . $mark . ')?\s*\d+(?:\.\d+)?(?:mm|[TtHhØ])?)\s*[xX×✕]\s*(?=' . $mark . '?\s*\d)/u', '$1*', $s);
    $s = (string) preg_replace('/(?<![A-Za-z0-9.])((?:' . $mark . ')?\s*\d+(?:\.\d+)?(?:mm|[TtHhØ])?)\s*[xX×✕]\s*(?=' . $mark . '?\s*\d)/u', '$1*', $s);   // 겹친 자리(300x200x1000)
    $s = (string) preg_replace('/\s*\*\s*/u', '*', $s);
    return trim($s);
}

/** ⑪ 표시 글자 → 칸 키 후보(이 순서로 이름표에 있는 첫 칸). 이름표 이름과 글자가 같으면 그 칸이 먼저 */
const CATALOG_MARK_KEYS = [
    'W' => ['w'], '폭' => ['w'], '가로' => ['w'], 'V' => ['v'], '세로' => ['v'],
    'H' => ['h'], '높이' => ['h'], '깊이' => ['depth'], 'L' => ['l'], '길이' => ['l'], 'T' => ['t'], '두께' => ['t'],
    'D' => ['d', 'd_i|d_o'], 'Ø' => ['d', 'd_i|d_o'], '지름' => ['d', 'd_i|d_o'], '직경' => ['d', 'd_i|d_o'], '내경' => ['d_i'], '외경' => ['d_o'],
];

/**
 * ⑪ 표시로 칸 연결(위 설명). 표시가 붙은 숫자가 이름표 칸 하나 이상에 들어갈 때만 결과. 순수 함수
 * @return ?array{cols: array<string, ?string>, code: null, option: ?string, detail: list<array>}
 */
function catalog_axis_marked(string $spec, string $opt, array $axes, array $dimCols): ?array
{
    if ($spec === '' || !$axes) { return null; }
    $keys = array_map(static fn($a) => (string) $a['axis_key'], $axes);
    if (catalog_shape_conflict($spec . ' ' . $opt, $keys)) { return null; }
    $byKey = [];
    foreach ($axes as $a) {
        $k = (string) $a['axis_key'];
        if (!isset(SPEC_AXIS_COLUMNS[$k]) || in_array($k, SPEC_AXIS_TEXT, true)) { continue; }
        if (($a['fixed_value'] ?? null) !== null || trim((string) ($a['fixed_text'] ?? '')) !== '') { continue; }   // 고정값 칸은 업체 값을 넣지 않음
        $byKey[$k] = $a;
    }
    $words = array_merge(array_keys(CATALOG_MARK_KEYS), ['w', 'v', 'h', 'l', 't', 'd'], array_map(static fn($a) => (string) $a['label'], $byKey));
    usort($words, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $wre = implode('|', array_map(static fn($w) => preg_quote($w, '/'), array_unique(array_filter($words, static fn($w) => $w !== ''))));
    // '폭 300'·'높이: 200' → '폭300'·'높이200'(한 조각으로)
    $text = (string) preg_replace('/(' . $wre . ')\s*[:=]?\s+(?=\d)/u', '$1', $spec);
    $pieces = array_values(array_filter(array_map('trim', preg_split('/[*_,\/\s]+/u', $text) ?: []), static fn($x) => $x !== ''));
    $resolve = static function (string $w) use ($byKey): ?string {
        foreach ($byKey as $k => $a) { if ((string) $a['label'] === $w) { return $k; } }   // 이름표 이름 그대로
        $u = preg_match('/^[a-z]$/', $w) ? strtoupper($w) : $w;
        foreach (CATALOG_MARK_KEYS[$u] ?? [] as $cand) {
            if (str_contains($cand, '|')) {                 // 지름 칸이 내경·외경 중 하나만 있을 때만 그 칸
                $hit = array_values(array_intersect(explode('|', $cand), array_keys($byKey)));
                if (count($hit) === 1) { return $hit[0]; }
                continue;
            }
            if (isset($byKey[$cand])) { return $cand; }
        }
        return null;
    };
    $vals = []; $detail = []; $optWords = []; $marked = 0;
    foreach ($pieces as $pc) {
        if (!preg_match('/^(' . $wre . ')?(\d+(?:\.\d+)?)(mm|cm|m|M)?([WwHhLlTtØ])?$/u', $pc, $m) || ($m[1] ?? '') !== '' && ($m[4] ?? '') !== '') {
            $optWords[] = $pc;                               // 숫자 없는 글자·코드('KS'·'2단'·'M16')는 규격외정보
            continue;
        }
        $w = ($m[1] ?? '') !== '' ? $m[1] : (($m[4] ?? '') !== '' ? $m[4] : '');
        $unit = strtolower($m[3] ?? '');
        $mm = (float) $m[2] * ($unit === 'cm' ? 10 : ($unit === 'm' ? 1000 : 1));
        $k = $w !== '' ? $resolve($w) : null;
        if ($k === null) {                                   // 표시 없는 숫자·이름표에 없는 칸 → 상세 화면
            // 영문 한 글자 표시(D·L…)는 뜻이 분류마다 달라(수로관 D = 깊이) 이름을 짐작하지 않고 '기타 치수 D'로, 한글 이름(길이·두께…)은 그대로
            $u = preg_match('/^[a-z]$/', $w) ? strtoupper($w) : $w;
            $one = (bool) preg_match('/^[A-ZØ]$/u', $u);
            $detail[] = ['label' => ($w === '' || $one) ? '기타 치수' : $w, 'symbol' => $one ? $u : '',
                         'value' => spec_axis_num($mm), 'unit' => 'mm', 'main' => false];
            continue;
        }
        $a = $byKey[$k];
        $lu = (string) ($a['unit'] ?? 'mm');
        if ($lu === 'm') {                                   // m 이름표 칸은 단위가 적혀 있을 때만(m 그대로, mm 는 ÷1000)
            if ($unit === '') { $detail[] = ['label' => (string) $a['label'], 'symbol' => (string) ($a['symbol'] ?? ''), 'value' => $m[2], 'unit' => '', 'main' => false]; continue; }
            $v = spec_axis_num($mm / 1000);
        } elseif ($lu === 'mm') {
            $v = spec_axis_num($mm);
        } else {
            return null;                                     // t/m 등 다른 단위 칸은 표시만 믿고 넣지 않음
        }
        $col = SPEC_AXIS_COLUMNS[$k];
        if (isset($vals[$col])) { return null; }             // 같은 칸에 두 값 — 어느 쪽인지 모름
        $vals[$col] = $v;
        $marked++;
    }
    if ($marked === 0) { return null; }
    $cols = array_fill_keys($dimCols, null);
    foreach ($vals as $c => $v) { if (array_key_exists($c, $cols)) { $cols[$c] = $v; } }
    $o = implode('_', array_values(array_unique(array_filter(array_merge($optWords, $opt !== '' ? [$opt] : []), static fn($x) => $x !== ''))));
    return ['cols' => $cols, 'code' => null, 'option' => $o !== '' ? $o : null, 'detail' => $detail];
}

/**
 * 업체 상품 1행 규격 읽기 전체 순서(반영·제품관리 미리보기·일괄 등록 결과가 같은 결과를 쓰게). 순수 함수
 *  이름표로 읽기(catalog_axis_from_spec) → 기준 상품·형식 규칙 ①~⑨(catalog_axis_from_ref) → ⑩ 표기 통일 뒤 다시 → ⑪ 이름으로 칸 연결
 * @return array{fs: ?array, status: string, reason: string}
 *   status: full(정리됨) | partial(일부만 — 비운 칸·상세 화면으로 넘긴 값이 있음) | nodims(치수 없음 — 규격외정보) | unread(못 읽음)
 */
function catalog_axis_read(string $spec, ?string $option, array $axes, array $ref, array $dimCols, bool $tAsHeight = false): array
{
    $spec = trim($spec); $opt = trim((string) $option);
    if ($spec === '' && $opt === '') { return ['fs' => null, 'status' => 'nodims', 'reason' => '규격·옵션 없음']; }
    $fs = catalog_axis_from_spec($spec, $opt, $axes, $dimCols) ?? catalog_axis_from_ref($spec, $opt, $axes, $ref, $dimCols, $tAsHeight);
    if ($fs === null) {
        $ns = catalog_spec_normalize($spec); $no = catalog_spec_normalize($opt);
        if ($ns !== $spec || $no !== $opt) {
            $fs = catalog_axis_from_spec($ns, $no, $axes, $dimCols) ?? catalog_axis_from_ref($ns, $no, $axes, $ref, $dimCols, $tAsHeight);
            if ($fs !== null) { $fs['rule'] = 'normalize+' . ($fs['rule'] ?? 'labels'); }
        }
        if ($fs === null && ($m = catalog_axis_marked($ns, $no, $axes, $dimCols)) !== null) { $fs = $m + ['rule' => 'marked']; }
        if ($fs !== null && $spec === '') { $fs['src'] = $opt; }   // 규격 원문은 업체가 적은 글자 그대로
    }
    if ($fs === null && !$axes) { return ['fs' => null, 'status' => 'unread', 'reason' => '고객 화면 분류에 이름표가 없음']; }
    if ($fs === null) {
        $n = count(catalog_spec_numbers(catalog_spec_normalize($spec !== '' ? $spec : $opt)));
        $need = count(array_filter($axes, static fn($a) => isset(SPEC_AXIS_COLUMNS[(string) $a['axis_key']])));
        return ['fs' => null, 'status' => 'unread', 'reason' => $n > $need ? "숫자 {$n}개가 이름표 칸 {$need}개보다 많음" : '규격 표기를 읽지 못함'];
    }
    if (($fs['rule'] ?? '') === 'nodims') { return ['fs' => $fs, 'status' => 'nodims', 'reason' => '치수 없음 — 규격외정보로']; }
    $free = array_filter($axes, static fn($a) => isset(SPEC_AXIS_COLUMNS[(string) $a['axis_key']]) && ($a['fixed_value'] ?? null) === null && trim((string) ($a['fixed_text'] ?? '')) === '');
    $filled = count(array_filter(array_intersect_key($fs['cols'], array_flip(array_map(static fn($a) => SPEC_AXIS_COLUMNS[(string) $a['axis_key']], $free))), static fn($v) => $v !== null && $v !== ''));
    if (!empty($fs['detail'])) { return ['fs' => $fs, 'status' => 'partial', 'reason' => '일부 값은 상세 화면 세부 치수로']; }
    if ($filled < count($free)) { return ['fs' => $fs, 'status' => 'partial', 'reason' => '빈 칸 ' . (count($free) - $filled) . '개']; }
    return ['fs' => $fs, 'status' => 'full', 'reason' => ''];
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
