<?php
declare(strict_types=1);
/**
 * 상품 규격 구조화(products.spec_code·dim_*·spec_option·spec_dims_src + category_spec_axes) 헬퍼.
 *
 * 책임: 카테고리별 축 이름표(category_spec_axes)로 spec 의 '규격:' 값을 축별 숫자(mm)로 나누고,
 *       상품 목록 표·좌측 필터가 그 값을 쓰도록 이름표·값·표시 문자열을 제공한다.
 *  - spec_axes_parse($value, $axes)  순수 함수. 이름표와 숫자 개수·표기가 정확히 맞을 때만 값, 아니면 null(추측하지 않음)
 *  - spec_axes_plan($row, $axes)     순수 함수. 상품 1행의 새 칸을 어떻게 바꿀지(null = 그대로)
 *  - spec_axes_resolve($id, …)       순수 함수. 카테고리 → 가장 가까운 상위의 이름표(none 이면 없음)
 *  - spec_axes_ready()               새 칸·표가 DB 에 있는지. 없으면 화면은 기존 규격 원문 방식 그대로
 *  - spec_axes_common($ids)          여러 카테고리가 같은 이름표를 쓸 때만 그 이름표(목록·필터 축 모드 판단)
 *  - spec_axes_row_fresh($row)       채운 기준 원문 = 지금 규격 원문일 때만 화면에 축 값 사용
 *  - spec_axes_sync_product($id)     상품 1개 새 칸 다시 계산(관리자 상품 저장 시)
 * 금지: 기존 칸(spec 등)·가격 칸 수정. 새 칸(SPEC_AXES_NEW_COLUMNS) 외에는 쓰지 않는다.
 * DB 반영: database/20261106_product_spec_axes.sql, 값 채우기: scripts/db-tools/spec-axes-fill.php
 */

/**
 * 축 키 → products 칸 (사용자 결정 2026-11-06: 같은 뜻의 다른 치수는 칸을 나눔)
 *  d_i 내경 · d_o 외경 · w 폭/가로 · w_b 하부폭(밑면) · w_r 뒷폭 · w_t 상부폭(윗면) · h 높이 · h_high 상단 높이 · h_low 하단 높이 ·
 *  depth 깊이 · l 길이/세로 · t 두께 · t2 두 번째 두께 · mesh 망 간격 · wire_d 철선 직경 · wire_no 철선 번호 · ts 인장강도(t/m) ·
 *  d 직경(원형디자인·볼라드 D116·칼라맨홀뚜껑 원형부직경) · d_base 바닥직경(글자 값, 우레탄볼라드 '142~145') ·
 *  v 세로(길이 L 과 나눔 — 사용자 결정 2026-11-09, 예전 '세로' 이름표는 l 이었음) ·
 *  t_tb 상하두께 · t_wall 벽체두께(개거) · t_top 상부두께 · t_bottom 하부두께(조경경계블록) — 두께 칸 나눔(사용자 결정 2026-11-09).
 *  t2 는 예전 개거 벽체두께 칸(이제 이름표에서 안 씀 — 다시 채울 때 비우도록 목록에만 남김) ·
 *  d_connect 접속구 지름 · d_inlet 유입구 지름 · d_outlet 유출구 지름(글자 값 VG150 — SPEC_AXIS_TEXT)
 */
const SPEC_AXIS_COLUMNS = [
    'd' => 'dim_d', 'd_i' => 'dim_d_i', 'd_o' => 'dim_d_o', 'd_base' => 'dim_d_base',
    'w' => 'dim_w', 'w_b' => 'dim_w_b', 'w_r' => 'dim_w_r', 'w_t' => 'dim_w_t',
    'h' => 'dim_h', 'h_high' => 'dim_h_high', 'h_low' => 'dim_h_low', 'depth' => 'dim_depth',
    'l' => 'dim_l', 'v' => 'dim_v', 't' => 'dim_t', 't2' => 'dim_t2',
    't_tb' => 'dim_t_tb', 't_wall' => 'dim_t_wall', 't_top' => 'dim_t_top', 't_bottom' => 'dim_t_bottom',
    'mesh' => 'dim_mesh', 'wire_d' => 'dim_wire_d', 'wire_no' => 'spec_wire_no', 'ts' => 'dim_ts',
    'd_connect' => 'dim_d_connect', 'd_inlet' => 'dim_d_inlet', 'd_outlet' => 'dim_d_outlet',
    // 상용콘크리트 반영 칸(database/20261112_catalog_switch.sql) — 외경폭·외경높이·중간벽두께·보조 높이·기타 2칸
    'w_o' => 'dim_w_o', 'h_o' => 'dim_h_o', 't_mid' => 'dim_t_mid', 'h1' => 'dim_h1', 'x1' => 'dim_x1', 'x2' => 'dim_x2',
];
/** 위 상용콘크리트 반영 칸(새 칸 목록 SPEC_AXES_NEW_COLUMNS 와 따로 — DB 반영 전에도 기존 화면이 그대로 동작하게) */
const SPEC_AXES_EXTRA_COLUMNS = ['dim_w_o', 'dim_h_o', 'dim_t_mid', 'dim_h1', 'dim_x1', 'dim_x2'];

/** 글자 값 축(숫자가 아니라 'VG150'·'DC200' 처럼 관 종류+지름 그대로 저장·표시) — PVC소형맨홀 접속구, PVC오수받이 유입구·유출구 */
const SPEC_AXIS_TEXT = ['d_connect', 'd_inlet', 'd_outlet', 'd_base'];

/** 지름 축(값 앞 'D' 표기 허용): 내경·외경 */
const SPEC_AXIS_DIAMETER = ['d', 'd_i', 'd_o'];

/** 'T' 접미 허용 축(두께·인장강도 톤), 'H' 접미 허용 축(높이) */
const SPEC_AXIS_T_SUFFIX = ['t', 't2', 't_tb', 't_wall', 't_top', 't_bottom', 'ts'];
const SPEC_AXIS_H_SUFFIX = ['h', 'h_high', 'h_low'];

/** 길이가 아닌 값(mm 환산·M 접미 없음): 인장강도 t/m, 철선 번호 */
const SPEC_AXIS_RAW = ['ts', 'wire_no'];

/** 숫자는 있지만 저장하지 않는 자리(예: 원형사각수로관 괄호 안 길이 2000 — 앞의 2M 와 같은 값) */
const SPEC_AXIS_SKIP = 'skip';

/** 읽는 형식(category_spec_axes.parse_fmt) — 'default' 외에는 그 카테고리 규격 표기 모양에 맞춘 것 */
const SPEC_AXIS_FORMATS = ['default', 'paren', 'lead_code', 'mesh', 'slash', 'hash', 'pipe_port', 'loose', 'range', 'lead_text', 'shape'];

/** 이 기능이 쓰는 products 새 칸(전부 있어야 사용) */
const SPEC_AXES_NEW_COLUMNS = ['spec_code', 'dim_d', 'dim_d_i', 'dim_d_o', 'dim_d_base', 'dim_w', 'dim_w_b', 'dim_w_r', 'dim_w_t', 'dim_h', 'dim_h_high', 'dim_h_low', 'dim_depth',
                               'dim_l', 'dim_v', 'dim_t', 'dim_t2', 'dim_t_tb', 'dim_t_wall', 'dim_t_top', 'dim_t_bottom', 'dim_mesh', 'dim_wire_d', 'spec_wire_no', 'dim_ts',
                               'dim_d_connect', 'dim_d_inlet', 'dim_d_outlet', 'spec_option', 'spec_dims_src'];

/** 목록 필터 '모델 코드' 축 키: 끝 글자(B·C — 수로관 900B 등) / 코드 전체(K2·BS·#377 — 형식이 lead_code·mesh·slash 인 카테고리) */
const SPEC_AXIS_TYPE = 'type';
const SPEC_AXIS_CODE = 'code';

/** 숫자 1개: D접두? + 숫자 + (T|H|M|mm)? — 바로 뒤에 다른 글자·숫자가 붙으면 숫자 아님('_0RS', '300용' 은 옵션), 범위('65~125')도 아님 */
const SPEC_AXIS_TOKEN = 'D?\d+(?:\.\d+)?(?:mm|MM|[TtHhMm])?(?![A-Za-z0-9.가-힣~])';

/**
 * '규격:' 값 → ['spec_code'=>?string, 'dims'=>[축키=>값(float, 길이는 mm)], 'option'=>?string]. 규칙에 안 맞으면 null(추측하지 않음).
 *  - 숫자를 이름표 seq 순서로 읽는다(고정값 행은 숫자를 쓰지 않고 그 값, skip 행은 숫자 자리만 차지). 숫자 개수가 다르면 null.
 *  - 'D' 접두는 지름 축(d_i·d_o)만, 'T' 접미는 t·t2·ts 만, 'H' 접미는 h·h_high·h_low 만. 'M'(미터)·'MM' 접미는 길이 단위.
 *  - 이름표 unit='m' 이면 ×1000 해서 mm 로. mm 소수 1자리를 넘으면 null. 인장강도·철선 번호는 그대로.
 *  - 읽는 형식(parse_fmt, 카테고리 행에 같은 값):
 *      default   '900*800*2000*85T (900B)_옵션'  앞 숫자 묶음 → 괄호 코드(숫자+글자)는 spec_code → 나머지 option
 *      paren     '빗물받이용 (400*500*80T_유공)', 'D200*2M (320*320*2000*60T)_곡선', '6T (2m*50m 1롤)'
 *                괄호 앞(숫자 묶음 또는 글자) + 괄호 안 숫자 묶음 → 숫자, 글자·괄호 안 꼬리·괄호 뒤 → option('1롤' 같은 1단위 표기는 버림)
 *      lead_code 'K2_390*120*120'                   맨 앞 영문·숫자 코드 → spec_code, 나머지는 default
 *      mesh      '1800*1800_150각_#8(Ø3.5)BS'       가로·세로 · 망 간격(각) · 철선 번호(#) · 철선 직경(Ø) · 뒤 영문 → spec_code
 *      slash     '150*200/100_곡선_#377'            폭·상단 높이/하단 높이 · '#숫자' → spec_code 'G숫자'(G377) · 나머지 option
 *      hash      '100*100_곡선_#377'                default 숫자 묶음 · '#숫자' → spec_code 'G숫자' · 나머지 option(화강경계도로·낮춤)
 *      loose     '900*900*1000H*200T', '220*220*80T', '1000*1000*H100'  default 와 같되 숫자 앞뒤 T·H 표기를 이름표와 맞추지 않음
 *                (원본 사이트 상세 표 기준으로 이름을 정한 카테고리 — 값의 T·H 가 실제 뜻과 다른 곳, H 가 앞에 붙은 곳)
 *      range     '230*114*65~125_…'                 default 와 같되 숫자 사이 '~'(범위)를 두 칸으로(조경경계블록 하부두께 65·상부두께 125)
 *      lead_text '버너삼각300*150*1000', '25바용*3T*4M', '600Ø*h70_B.S_보도용', '아치형 … 바리케이드 2.6M×H1100', '부직포(150g)_2m*30m'
 *                앞 글자 → 옵션('이름(내용)' 꼴이면 괄호 안만 — 부직포(150g) → 150g), 숫자 묶음('*'·'×', 600Ø → 지름, h70 → 높이), 나머지 옵션
 *      shape     '원형 D400*400' · '사각 400*400*400'  lead_text 와 같되 첫 숫자가 D 이면 직경·높이, 아니면 가로·세로·높이(모양은 옵션)
 *      pipe_port 'D300*900H*VG200(4구)사각몸통'      내경·높이 · 관 종류+지름(VG150·DC200, '/' 로 유입/유출) → 글자 값 축
 *                'D300*900H*VG100/VG150(유입3구/유출1구)'  option '접속4구(사각몸통)'·'유입3구/유출1구(일반)'(뒤 '사각몸통' 없으면 일반)
 *
 * @param list<array{seq:int, axis_key:string, unit?:string, parse_fmt?:string, fixed_value?:float|string|null}> $axes
 */
function spec_axes_parse(string $value, array $axes): ?array
{
    $value = trim($value);
    $axes  = array_values(array_filter($axes, static fn($a) => isset(SPEC_AXIS_COLUMNS[(string) ($a['axis_key'] ?? '')]) || ($a['axis_key'] ?? '') === SPEC_AXIS_SKIP));
    if ($value === '' || !$axes) { return null; }
    usort($axes, static fn($a, $b) => (int) $a['seq'] <=> (int) $b['seq']);
    $fmt   = spec_axes_fmt($axes);
    $tok   = SPEC_AXIS_TOKEN;
    $run   = $tok . '(?:[*_]' . $tok . ')*';
    $code  = null;
    $opts  = [];
    $tokens = [];

    if ($fmt === 'lead_code') {
        if (!preg_match('/^([A-Z][A-Z0-9]{0,9})_(.+)$/u', $value, $lm)) { return null; }
        $code  = $lm[1];
        $value = $lm[2];
    }
    if ($fmt === 'lead_text' || $fmt === 'shape') {
        $lt  = '[DHh]?\d+(?:\.\d+)?(?:mm|MM|[TtHhMm]|Ø)?';
        if (!preg_match('/^(.*?)(' . $lt . '(?:[*×]' . $lt . ')+)(?![A-Za-z0-9.가-힣])(.*)$/su', $value, $lm)
            && !preg_match('/^(.*?)(' . $lt . ')(?![A-Za-z0-9.가-힣*×])(.*)$/su', $value, $lm)) { return null; }
        $lead = trim((string) preg_replace('/[\s_*]+$/u', '', $lm[1]));
        if ($lead !== '') { $opts[] = preg_match('/^[^()]+\(([^()]+)\)$/u', $lead, $pm2) ? $pm2[1] : $lead; }
        $tokens = array_map(static function (string $t): string {
            if (preg_match('/^(\d+(?:\.\d+)?)Ø$/u', $t, $x)) { return 'D' . $x[1]; }   // 600Ø → 지름
            return (string) preg_replace('/^h(?=\d)/', 'H', $t);                          // h70 → 높이
        }, preg_split('/[*×]/u', $lm[2]) ?: []);
        $opts[] = $lm[3];
        $use = $axes;
        if ($fmt === 'shape') {   // 첫 숫자 D → 직경·높이 / 아니면 가로·세로·높이
            $round = str_starts_with($tokens[0] ?? '', 'D');
            $use = array_values(array_filter($axes, static fn($a) => $round ? in_array((string) $a['axis_key'], ['d', 'h'], true) : (string) $a['axis_key'] !== 'd'));
        }
        $dims = spec_axes_read_tokens($tokens, $use, true);
        if ($dims === null) { return null; }
        $opt = trim(implode('_', array_values(array_filter(array_map(static fn($o) => trim((string) preg_replace('/^[\s_]+/u', '', (string) $o)), $opts), static fn($o) => $o !== ''))));
        if ($opt === '') { $opt = null; }
        if ($opt !== null && mb_strlen($opt) > 80) { return null; }
        return ['spec_code' => null, 'dims' => $dims, 'option' => $opt];
    }
    if ($fmt === 'range') {   // '65~125' → 두 숫자(이름표 두 칸)
        $value = (string) preg_replace('/(\d)~(\d)/', '$1*$2', $value, 1);
    }
    if ($fmt === 'loose') {   // H 가 숫자 앞에 붙은 표기(H100)도 숫자로
        $tok = '[DH]?\d+(?:\.\d+)?(?:mm|MM|[TtHhMm])?(?![A-Za-z0-9.가-힣~])';
        $run = $tok . '(?:[*_]' . $tok . ')*';
    }
    if (in_array($fmt, ['default', 'lead_code', 'loose', 'range'], true)) {
        if (!preg_match('/^' . $run . '/u', $value, $m)) { return null; }
        $tokens = preg_split('/[*_]/', $m[0]) ?: [];
        $rest   = (string) substr($value, strlen($m[0]));
        if ($fmt !== 'lead_code' && preg_match('/^\s*\(\s*([A-Za-z]*\d+[A-Za-z]+)\s*\)/', $rest, $cm)) {
            $code = $cm[1];
            $rest = (string) substr($rest, strlen($cm[0]));
        }
        $opts[] = $rest;
    } elseif ($fmt === 'paren') {
        if (!preg_match('/^(.*?)\s*\(([^()]*)\)(.*)$/su', $value, $pm)) { return null; }
        [$lead, $inner, $after] = [trim($pm[1]), $pm[2], $pm[3]];
        if ($lead !== '') {
            if (preg_match('/^' . $run . '$/u', $lead)) { $tokens = preg_split('/[*_]/', $lead) ?: []; }
            elseif (preg_match('/^[\d.]*[^\d\s*_()]/u', $lead) && !preg_match('/^D?\d+(?:\.\d+)?(?:mm|MM|[TtHhMm])?(?:[*_\s]|$)/u', $lead)) { $opts[] = $lead; }   // '빗물받이용', '300측구용'
            else { return null; }
        }
        if (!preg_match('/^' . $run . '/u', $inner, $im)) { return null; }
        $tokens = array_merge($tokens, preg_split('/[*_]/', $im[0]) ?: []);
        $tail   = trim((string) preg_replace('/^[\s_]+/u', '', (string) substr($inner, strlen($im[0]))));
        if ($tail !== '' && !preg_match('/^1\s*(롤|본|개|EA)$/u', $tail)) { $opts[] = $tail; }   // '1롤' = 단위 칸과 같은 뜻이라 버림
        $opts[] = $after;
    } elseif ($fmt === 'mesh') {
        $re = '/^(' . $run . ')_(\d+(?:\.\d+)?)각_#(\d+)\(\s*Ø\s*(\d+(?:\.\d+)?)\s*\)([A-Z]{1,10})?(.*)$/u';
        if (!preg_match($re, $value, $mm)) { return null; }
        $tokens = array_merge(preg_split('/[*_]/', $mm[1]) ?: [], [$mm[2], $mm[3], $mm[4]]);
        $code   = ($mm[5] ?? '') !== '' ? $mm[5] : null;
        $opts[] = $mm[6] ?? '';
    } elseif ($fmt === 'slash' || $fmt === 'hash') {
        if ($fmt === 'slash') {
            // 폭*상단/하단(*길이…) — 험프석 300*200/100*1000 처럼 뒤에 숫자가 더 있으면 이어서 읽음
            if (!preg_match('/^(' . $tok . ')\*(' . $tok . ')\/(' . $tok . ')((?:\*' . $tok . ')*)(.*)$/u', $value, $sm)) { return null; }
            $tokens = array_merge([$sm[1], $sm[2], $sm[3]], $sm[4] !== '' ? array_slice(explode('*', $sm[4]), 1) : []);
            $rest   = $sm[5];
        } else {
            if (!preg_match('/^' . $run . '/u', $value, $m)) { return null; }
            $tokens = preg_split('/[*_]/', $m[0]) ?: [];
            $rest   = (string) substr($value, strlen($m[0]));
        }
        $left = [];
        foreach (preg_split('/_/u', (string) preg_replace('/^[\s_]+/u', '', $rest)) ?: [] as $seg) {
            $seg = trim($seg);
            if ($seg === '') { continue; }
            // '#377' → 모델 코드 'G377'(사용자 결정 2026-11-09 — '#' 대신 G)
            if ($code === null && preg_match('/^#(\d{1,9})$/', $seg, $hm)) { $code = 'G' . $hm[1]; continue; }
            $left[] = $seg;
        }
        $opts[] = implode('_', $left);
    } elseif ($fmt === 'pipe_port') {
        $port = '(?:VG|DC)\d{2,4}';
        if (!preg_match('/^(' . $tok . ')\*(' . $tok . ')\*(' . $port . ')(?:\/(' . $port . '))?\(([^()]+)\)(사각몸통)?$/u', $value, $pm)) { return null; }
        $tokens = [$pm[1], $pm[2]];
        $ports  = array_values(array_filter([$pm[3], $pm[4] ?? ''], static fn($x) => $x !== ''));
        $texts  = array_values(array_filter($axes, static fn($a) => in_array((string) $a['axis_key'], SPEC_AXIS_TEXT, true) && (string) ($a['fixed_text'] ?? '') === ''));
        if (count($ports) !== count($texts)) { return null; }
        $inner = trim($pm[5]);
        if (preg_match('/^(\d{1,2})구$/u', $inner, $cm))                                    { $kind = '접속' . $cm[1] . '구'; }
        elseif (preg_match('/^유입(\d{1,2})구\/유출(\d{1,2})구$/u', $inner, $cm))           { $kind = '유입' . $cm[1] . '구/유출' . $cm[2] . '구'; }
        else { return null; }
        $opts[] = $kind . '(' . (($pm[6] ?? '') !== '' ? '사각몸통' : '일반') . ')';
    } else {
        return null;
    }

    $dims = spec_axes_read_tokens($tokens, $axes, $fmt === 'loose');
    if ($dims === null) { return null; }
    if ($fmt === 'pipe_port') {   // 글자 값 축: 이름표 seq 순서대로 유입→유출(또는 접속구)
        foreach ($texts as $i => $a) { $dims[(string) $a['axis_key']] = $ports[$i]; }
        $ordered = [];
        foreach ($axes as $a) { $k = (string) $a['axis_key']; if (isset($dims[$k])) { $ordered[$k] = $dims[$k]; } }
        $dims = $ordered;
    }

    $opt = trim(implode('_', array_values(array_filter(array_map(static fn($o) => trim((string) preg_replace('/^[\s_]+/u', '', (string) $o)), $opts), static fn($o) => $o !== ''))));
    if ($opt === '') { $opt = null; }
    if (($code !== null && strlen($code) > 30) || ($opt !== null && mb_strlen($opt) > 80)) { return null; }

    return ['spec_code' => $code, 'dims' => $dims, 'option' => $opt];
}

/** 이름표의 읽는 형식(행 중 처음 값, 없으면 default) */
function spec_axes_fmt(array $axes): string
{
    foreach ($axes as $a) {
        $f = (string) ($a['parse_fmt'] ?? '');
        if ($f !== '') { return in_array($f, SPEC_AXIS_FORMATS, true) ? $f : 'default'; }
    }
    return 'default';
}

/** 필터 '모델 코드' 방식: 끝 글자(type) 또는 코드 전체(code) */
function spec_axes_code_key(array $axes): string
{
    return in_array(spec_axes_fmt($axes), ['lead_code', 'mesh', 'slash', 'hash'], true) ? SPEC_AXIS_CODE : SPEC_AXIS_TYPE;
}

/**
 * 숫자 토큰(순서대로) → 축 값. 고정값 행은 토큰을 쓰지 않고 그 값, skip 행은 토큰만 차지. 규칙에 안 맞으면 null.
 *
 * @param list<string> $tokens
 */
function spec_axes_read_tokens(array $tokens, array $axes, bool $loose = false): ?array
{
    $readers = []; $dims = [];
    foreach ($axes as $a) {
        $key = (string) $a['axis_key'];
        if (in_array($key, SPEC_AXIS_TEXT, true)) {   // 글자 값 축: 고정 글자(바닥직경 '142~145')면 그 값, 아니면 형식별 코드가 채움
            $ft = trim((string) ($a['fixed_text'] ?? ''));
            if ($ft !== '') { $dims[$key] = $ft; }
            continue;
        }
        $fv  = $a['fixed_value'] ?? null;
        if ($key !== SPEC_AXIS_SKIP && $fv !== null && $fv !== '') {
            $v = round((float) $fv, 1);
            if ($v <= 0 || isset($dims[$key])) { return null; }
            $dims[$key] = $v;
            continue;
        }
        $readers[] = $a;
    }
    if (!$readers || count($tokens) !== count($readers)) { return null; }

    foreach ($readers as $i => $a) {
        if (!preg_match($loose ? '/^([DH]?)(\d+(?:\.\d+)?)([A-Za-z]*)$/' : '/^(D?)(\d+(?:\.\d+)?)([A-Za-z]*)$/', (string) $tokens[$i], $tm)) { return null; }
        $key  = (string) $a['axis_key'];
        $raw  = in_array($key, SPEC_AXIS_RAW, true);
        $unit = (($a['unit'] ?? 'mm') === 'm') ? 'm' : 'mm';
        $suf  = strtoupper($tm[3]);
        if ($tm[1] === 'D' && !in_array($key, SPEC_AXIS_DIAMETER, true)) { return null; }
        if ($loose && ($suf === 'T' || $suf === 'H')) { $suf = ''; }   // loose: T·H 표기는 뜻 확인에 쓰지 않음(상세 표 기준 이름표)
        if ($suf === 'T')      { if (!in_array($key, SPEC_AXIS_T_SUFFIX, true)) { return null; } }
        elseif ($suf === 'H')  { if (!in_array($key, SPEC_AXIS_H_SUFFIX, true)) { return null; } }
        elseif ($suf === 'M')  { if ($raw) { return null; } $unit = 'm'; }
        elseif ($suf === 'MM') { if ($raw) { return null; } $unit = 'mm'; }
        elseif ($suf !== '')   { return null; }
        if ($key === SPEC_AXIS_SKIP) { continue; }

        // 이름표가 m 단위인데 접미 없는 숫자가 100 이상이면 mm 표기로 봄(스텐바리케이드 2.6M×H1100 의 1100 = 1.1m)
        if (!$raw && $unit === 'm' && $suf === '' && (float) $tm[2] >= 100 && (($a['unit'] ?? 'mm') === 'm')) { $unit = 'mm'; }
        $val = (float) $tm[2] * (!$raw && $unit === 'm' ? 1000 : 1);
        $x10 = $val * 10;
        if (abs($x10 - round($x10)) > 1e-6) { return null; }      // 소수 1자리까지만(DECIMAL(8,1))
        if ($key === 'wire_no' && abs($val - round($val)) > 1e-9) { return null; }
        $val = round($val, 1);
        if ($val <= 0 || $val > 9999999.9 || isset($dims[$key])) { return null; }
        $dims[$key] = $val;
    }
    // 이름표 seq 순서로 정렬(고정값이 중간에 있어도 같은 순서)
    $ordered = [];
    foreach ($axes as $a) { $k = (string) $a['axis_key']; if (isset($dims[$k])) { $ordered[$k] = $dims[$k]; } }
    return $ordered;
}

/** mm 값 → 필터 값·비교용 정규 문자열('900', '1.6', '1500') */
function spec_axis_num(float $mm): string
{
    return rtrim(rtrim(number_format($mm, 1, '.', ''), '0'), '.');
}

/** 값 → 화면 표시(이름표 단위). mm: '2000' '1.6'(천 단위 쉼표 없음 — 사용자 결정 2026-11-06) / m: '1.5' '2.0' / no: '#8' / t/m: '6' */
function spec_axis_display(float $mm, string $unit): string
{
    if ($unit === 'no') { return '#' . spec_axis_num($mm); }
    if ($unit === 'm') {
        $s = rtrim(rtrim(number_format($mm / 1000, 3, '.', ''), '0'), '.');
        return strpos($s, '.') === false ? $s . '.0' : $s;
    }
    return number_format($mm, abs($mm - round($mm)) < 1e-9 ? 0 : 1, '.', '');
}

/** 글자 값 축의 products 칸 */
function spec_axis_text_columns(): array
{
    return array_map(static fn($k) => SPEC_AXIS_COLUMNS[$k], SPEC_AXIS_TEXT);
}

/** 이름표 행 + 정규 값 → 화면 표시. 글자 값 축(VG150)은 그대로, 숫자는 단위대로(spec_axis_display) */
function spec_axis_value_display(array $axis, string $v): string
{
    return in_array((string) ($axis['axis_key'] ?? ''), SPEC_AXIS_TEXT, true) ? $v : spec_axis_display((float) $v, (string) ($axis['unit'] ?? 'mm'));
}

/** 화면에 보일 약자('W') — 약자가 없거나 show_symbol=0 이면 '' */
function spec_axis_symbol(array $axis): string
{
    $show = $axis['show_symbol'] ?? true;
    return ($show === false || $show === 0 || $show === '0') ? '' : trim((string) ($axis['symbol'] ?? ''));
}

/** 이름표 → 표 머리글·카드 이름('폭 W', '상하두께') */
function spec_axis_title(array $axis): string
{
    $sym = spec_axis_symbol($axis);
    return trim((string) ($axis['label'] ?? '')) . ($sym !== '' ? ' ' . $sym : '');
}

/**
 * 옵션 화면 표시(사용자 결정 2026-11-06). 저장값(spec_option)은 원문 그대로.
 *  - 밑줄을 슬래시로: '하부구체_KS' → '하부구체/KS', '곡선_타공_KS' → '곡선/타공/KS'
 *  - 통째로 소괄호에 싸인 조각은 괄호를 뗌: '(B)' → 'B', '(SA)_곡선' → 'SA/곡선', '(고강도콘크리트_점형_KS)' → '고강도콘크리트/점형/KS'
 *  - 글자에 붙은 괄호는 그대로: '컬러(보도)', '0RS(유공)'
 */
function spec_option_display(string $opt): string
{
    $unwrap = static function (string $s): string {
        $s = trim($s);
        while (preg_match('/^\(([^()]*)\)$/u', $s, $m)) { $s = trim($m[1]); }
        return $s;
    };
    // 괄호 밖의 밑줄로만 나눔(괄호 안 밑줄은 그 조각의 일부)
    $parts = []; $buf = ''; $depth = 0;
    foreach (preg_split('//u', $unwrap($opt), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if ($ch === '(') { $depth++; } elseif ($ch === ')' && $depth > 0) { $depth--; }
        if ($ch === '_' && $depth === 0) { $parts[] = $buf; $buf = ''; continue; }
        $buf .= $ch;
    }
    $parts[] = $buf;
    $out = [];
    foreach ($parts as $p) {
        foreach (explode('_', $unwrap($p)) as $q) { $q = trim($q); if ($q !== '') { $out[] = $q; } }
    }
    return implode('/', $out);
}

/** 단위 표시 '(mm)' / '(m)' / '(t/m)' / 번호는 없음 — 필터 그룹 이름 옆(아트보드: 폭 W(mm)). 표 머리글에는 단위를 쓰지 않는다 */
function spec_axis_unit_label(array $axis): string
{
    if (in_array((string) ($axis['axis_key'] ?? ''), SPEC_AXIS_TEXT, true)) { return ''; }   // VG150 처럼 값에 이미 들어 있음
    $u = (string) ($axis['unit'] ?? 'mm');
    return $u === 'm' ? '(m)' : ($u === 't/m' ? '(t/m)' : ($u === 'no' ? '' : '(mm)'));
}

/** 규격 코드 → 끝자리 글자(필터 '모델 코드'). '900B' → 'B', 'M1000C' → 'C', 없으면 null */
function spec_code_type(?string $code): ?string
{
    return ($code !== null && preg_match('/\d([A-Z])$/', $code, $m)) ? $m[1] : null;
}

/**
 * 카테고리 → 가장 가까운(자기 → 부모 → …) 이름표. 'none' 행이 있는 카테고리에서 멈추면 [].
 *
 * @param array<int, list<array>> $rows    category_id => 이름표 행(seq 순)
 * @param array<int, int|null>    $parents id => parent_id
 */
function spec_axes_resolve(int $categoryId, array $rows, array $parents): array
{
    $cur = $categoryId; $seen = [];
    while ($cur > 0 && !isset($seen[$cur])) {
        $seen[$cur] = 1;
        if (isset($rows[$cur])) {
            return array_values(array_filter($rows[$cur], static fn($a) => isset(SPEC_AXIS_COLUMNS[(string) $a['axis_key']]) || $a['axis_key'] === SPEC_AXIS_SKIP));
        }
        $cur = (int) ($parents[$cur] ?? 0);
    }
    return [];
}

/**
 * 상품 1행 → 새 칸에 쓸 값(SPEC_AXES_NEW_COLUMNS 전부) 또는 null(바꿀 것 없음).
 *  - 이름표 없음: 새 칸에 값이 남아 있으면 모두 비움
 *  - 규칙으로 읽힘: 읽은 값 + spec_dims_src = 지금 규격 원문
 *  - 안 읽힘: 규격 원문이 채울 때와 달라졌으면 모두 비움(옛 값이 남지 않게), 같으면 그대로(사람이 넣은 값 보존)
 */
function spec_axes_plan(array $p, array $axes): ?array
{
    $value = parse_spec_field((string) ($p['spec'] ?? ''))['spec'];
    $cur   = [];
    foreach (SPEC_AXES_NEW_COLUMNS as $col) {
        $v = $p[$col] ?? null;
        $cur[$col] = ($v === null || $v === '') ? null : ((str_starts_with($col, 'dim_') && !in_array($col, spec_axis_text_columns(), true)) ? spec_axis_num((float) $v) : (string) $v);
    }
    $empty = array_fill_keys(SPEC_AXES_NEW_COLUMNS, null);
    $hasAny = (bool) array_filter($cur, static fn($v) => $v !== null);

    $parsed = $axes ? spec_axes_parse($value, $axes) : null;
    if ($parsed !== null) {
        $new = $empty;
        $new['spec_code'] = $parsed['spec_code'];
        foreach ($parsed['dims'] as $k => $mm) { $new[SPEC_AXIS_COLUMNS[$k]] = in_array($k, SPEC_AXIS_TEXT, true) ? (string) $mm : spec_axis_num((float) $mm); }
        $new['spec_option']   = $parsed['option'];
        $new['spec_dims_src'] = $value;
        return $new === $cur ? null : $new;
    }
    if (!$axes || $cur['spec_dims_src'] !== $value) {
        return $hasAny ? $empty : null;
    }
    return null;
}

/** 새 칸·이름표 표가 모두 있는지(요청당 1회 확인). 없으면 목록·필터는 기존 방식 */
function spec_axes_ready(): bool
{
    static $ready = null;
    if ($ready !== null) { return $ready; }
    $ready = false;
    $pdo = db();
    if (!$pdo) { return false; }
    try {
        $ph = implode(',', array_fill(0, count(SPEC_AXES_NEW_COLUMNS), '?'));
        $st = $pdo->prepare(
            "SELECT (SELECT COUNT(*) FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'category_spec_axes') AS t,
                    (SELECT COUNT(*) FROM information_schema.COLUMNS
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME IN ($ph)) AS c"
        );
        $st->execute(SPEC_AXES_NEW_COLUMNS);
        $r = $st->fetch();
        $ready = $r && (int) $r['t'] === 1 && (int) $r['c'] === count(SPEC_AXES_NEW_COLUMNS);
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/** 상용콘크리트 반영 칸(SPEC_AXES_EXTRA_COLUMNS)이 products 에 있는지 — 목록 파세트 조회에 넣을지 */
function spec_axes_extra_ready(): bool
{
    static $ok = null;
    if ($ok !== null) { return $ok; }
    $ok = false;
    if (!db()) { return false; }
    try {
        $ph = implode(',', array_fill(0, count(SPEC_AXES_EXTRA_COLUMNS), '?'));
        $st = db()->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME IN ($ph)");
        $st->execute(SPEC_AXES_EXTRA_COLUMNS);
        $ok = (int) $st->fetchColumn() === count(SPEC_AXES_EXTRA_COLUMNS);
    } catch (\Throwable $e) {
        $ok = false;
    }
    return $ok;
}

/** category_id => 이름표 행 목록(seq 순, 사용 중인 것만). 요청당 1회 */
function spec_axes_rows(): array
{
    static $map = null;
    if ($map !== null) { return $map; }
    $map = [];
    if (!spec_axes_ready()) { return $map; }
    try {
        $rs = db()->query('SELECT category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, parse_fmt, fixed_value, fixed_text
                             FROM category_spec_axes WHERE is_active = 1 ORDER BY category_id, seq');
        foreach ($rs->fetchAll() as $r) {
            $map[(int) $r['category_id']][] = [
                'seq'         => (int) $r['seq'],
                'axis_key'    => (string) $r['axis_key'],
                'label'       => (string) ($r['label'] ?? ''),
                'symbol'      => (string) ($r['symbol'] ?? ''),
                'show_symbol' => (int) $r['show_symbol'] === 1,
                'unit'        => in_array($r['unit'], ['m', 't/m', 'no'], true) ? (string) $r['unit'] : 'mm',
                'use_filter'  => (int) $r['use_filter'] === 1,
                'parse_fmt'   => (string) ($r['parse_fmt'] ?? 'default'),
                'fixed_value' => $r['fixed_value'] !== null ? (float) $r['fixed_value'] : null,
                'fixed_text'  => (string) ($r['fixed_text'] ?? ''),
            ];
        }
    } catch (\Throwable $e) {
        $map = [];
    }
    return $map;
}

/** 카테고리 id => parent_id (요청당 1회) */
function spec_axes_parents(): array
{
    static $par = null;
    if ($par !== null) { return $par; }
    $par = [];
    if (!db()) { return $par; }
    try {
        foreach (db()->query('SELECT id, parent_id FROM categories')->fetchAll() as $c) {
            $par[(int) $c['id']] = $c['parent_id'] !== null ? (int) $c['parent_id'] : null;
        }
    } catch (\Throwable $e) {
        $par = [];
    }
    return $par;
}

/** 카테고리의 이름표(상위 상속). 없으면 [] */
function spec_axes_for_category(int $categoryId): array
{
    $rows = spec_axes_rows();
    return $rows ? spec_axes_resolve($categoryId, $rows, spec_axes_parents()) : [];
}

/** 이름표 비교용 서명 */
function spec_axes_signature(array $axes): string
{
    return (string) json_encode(array_map(static fn($a) => [
        (int) $a['seq'], (string) $a['axis_key'], (string) $a['label'], spec_axis_symbol($a), (string) $a['unit'], (bool) $a['use_filter'],
        (string) ($a['parse_fmt'] ?? 'default'), $a['fixed_value'] ?? null, (string) ($a['fixed_text'] ?? ''),
    ], $axes), JSON_UNESCAPED_UNICODE);
}

/**
 * 결과에 나온 카테고리들이 모두 같은 이름표를 쓰면 그 이름표, 하나라도 없거나 다르면 [].
 * (중분류·검색처럼 이름표가 섞인 목록은 기존 규격 원문 방식)
 *
 * @param list<int> $categoryIds
 */
function spec_axes_common(array $categoryIds): array
{
    $common = null;
    foreach (array_unique(array_map('intval', $categoryIds)) as $cid) {
        $axes = spec_axes_for_category($cid);
        if (!$axes) { return []; }
        if ($common === null) { $common = $axes; }
        elseif (spec_axes_signature($axes) !== spec_axes_signature($common)) { return []; }
    }
    return $common ?? [];
}

/** 행의 축 값(정규 문자열) 또는 null */
function spec_axes_row_value(array $p, string $key): ?string
{
    if ($key === SPEC_AXIS_TYPE) { return spec_code_type(isset($p['spec_code']) ? (string) $p['spec_code'] : null); }
    if ($key === SPEC_AXIS_CODE) { $c = trim((string) ($p['spec_code'] ?? '')); return $c !== '' ? $c : null; }
    $col = SPEC_AXIS_COLUMNS[$key] ?? null;
    $v   = $col !== null ? ($p[$col] ?? null) : null;
    if ($v === null || $v === '') { return null; }
    return in_array($key, SPEC_AXIS_TEXT, true) ? (string) $v : spec_axis_num((float) $v);
}

/** 화면에 축 값을 써도 되는 행인지: 채운 기준 원문 = 지금 규격 원문, 값 1개 이상 */
function spec_axes_row_fresh(array $p): bool
{
    $src = $p['spec_dims_src'] ?? null;
    if ($src === null || $src === '' || (string) $src !== parse_spec_field((string) ($p['spec'] ?? ''))['spec']) { return false; }
    foreach (SPEC_AXIS_COLUMNS as $col) {
        if (($p[$col] ?? null) !== null && $p[$col] !== '') { return true; }
    }
    return false;
}

/**
 * 목록 GET → 축 필터 입력: ['ax' => [축키 => [값…]], 'ax_type' => [형식…]]. 형식이 틀린 값은 버린다.
 * URL 키: ax_w[]=900, ax_t2[]=260, ax_type[]=B
 */
function spec_axes_filter_input(array $get): array
{
    $ax = [];
    foreach (array_keys(SPEC_AXIS_COLUMNS) as $k) {
        $vals = [];
        foreach ((array) ($get['ax_' . $k] ?? []) as $v) {
            $v = trim((string) $v);
            if (in_array($k, SPEC_AXIS_TEXT, true)) { if (preg_match('/^(?:[A-Z]{1,4}\d{1,6}|\d{1,6}~\d{1,6})$/', $v)) { $vals[$v] = true; } continue; }   // VG150·DC200·142~145
            if (preg_match('/^\d{1,7}(?:\.\d)?$/', $v)) { $vals[spec_axis_num((float) $v)] = true; }
        }
        if ($vals) { $ax[$k] = array_slice(array_keys($vals), 0, 50); }
    }
    $types = [];
    foreach ((array) ($get['ax_type'] ?? []) as $v) {
        $v = strtoupper(trim((string) $v));
        if (preg_match('/^[A-Z]$/', $v)) { $types[$v] = true; }
    }
    $codes = [];   // 모델 코드 전체(K2·BS·#377) — 영문·숫자·#·- 만, 30자 이하
    foreach ((array) ($get['ax_code'] ?? []) as $v) {
        $v = trim((string) $v);
        if (preg_match('/^[A-Za-z0-9#\-]{1,30}$/', $v)) { $codes[$v] = true; }
    }
    return ['ax' => array_map(static fn($l) => array_map('strval', $l), $ax), 'ax_type' => array_map('strval', array_keys($types)),
            'ax_code' => array_slice(array_map('strval', array_keys($codes)), 0, 50)];
}

/**
 * 축 필터 → SQL 조각(AND …) + 바인딩(참조). 새 칸이 없으면 ''(조건 없음 — 기존 화면 그대로).
 * 같은 축 안은 OR(IN), 축끼리는 AND. 상품 테이블 별칭 p.
 */
function spec_axes_filter_sql(array $f, array &$params): string
{
    if ((empty($f['ax']) && empty($f['ax_type']) && empty($f['ax_code'])) || !spec_axes_ready()) { return ''; }
    $c = [];
    foreach ((array) ($f['ax'] ?? []) as $k => $vals) {
        $col = SPEC_AXIS_COLUMNS[$k] ?? null;
        if ($col === null || !$vals) { continue; }
        $ph = [];
        foreach (array_values($vals) as $i => $v) { $key = ":fax_{$k}_{$i}"; $ph[] = $key; $params[$key] = (string) $v; }
        $c[] = "p.{$col} IN (" . implode(',', $ph) . ')';
    }
    if (!empty($f['ax_type'])) {
        $ph = [];
        foreach (array_values($f['ax_type']) as $i => $v) { $key = ":faxty{$i}"; $ph[] = $key; $params[$key] = (string) $v; }
        $c[] = "(p.spec_code REGEXP '[0-9][A-Za-z]$' AND RIGHT(p.spec_code, 1) IN (" . implode(',', $ph) . '))';
    }
    if (!empty($f['ax_code'])) {
        $ph = [];
        foreach (array_values($f['ax_code']) as $i => $v) { $key = ":faxcd{$i}"; $ph[] = $key; $params[$key] = (string) $v; }
        $c[] = 'p.spec_code IN (' . implode(',', $ph) . ')';
    }
    return $c ? ' AND ' . implode(' AND ', $c) : '';
}

/**
 * 상품 1개의 새 칸을 지금 규격 원문·이름표 기준으로 다시 계산해 저장(바뀔 때만). 새 칸이 없으면 아무것도 안 함.
 * 관리자 상품 저장 뒤 호출 — 실패해도 저장 자체는 유지되도록 호출측에서 예외를 잡는다.
 */
function spec_axes_sync_product(int $id): bool
{
    if ($id <= 0 || !spec_axes_ready()) { return false; }
    $st = db()->prepare('SELECT p.id, p.category_id, p.spec, c.slug AS cat_slug, ' . implode(', ', array_map(static fn($c) => 'p.' . $c, SPEC_AXES_NEW_COLUMNS)) . '
                         FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE p.id = ?');
    $st->execute([$id]);
    $p = $st->fetch();
    if (!$p) { return false; }
    // 상용콘크리트 반영 상품(app/syc_publish.php): 치수 칸은 원본(일괄 등록·자동 정리)에서 오므로 규격 원문으로 다시 계산하지 않음
    if (str_starts_with((string) ($p['cat_slug'] ?? ''), 'syc-')) { return false; }
    $plan = spec_axes_plan($p, spec_axes_for_category((int) $p['category_id']));
    if ($plan === null) { return false; }
    $sets = []; $vals = [];
    foreach (SPEC_AXES_NEW_COLUMNS as $col) { $sets[] = "{$col} = ?"; $vals[] = $plan[$col]; }
    $vals[] = $id;
    db()->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    return true;
}
