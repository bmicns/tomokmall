<?php
declare(strict_types=1);
/**
 * 설치 구간(줄) 읽기·줄별 수량 계산 — AI 상담·빠른 상담 공통 (사용자 결정 2026-11-26 B안).
 *
 * 책임:
 *  - install_runs_parse()        고객 말에서 줄 구성 읽기 — '10m씩 2줄'·'10m, 15m'·'양쪽 20m씩'·'2줄 각 10m'·'총 30m 3줄'(줄마다 길이 모름)
 *  - install_runs_from_messages() 대화에서 지금 유효한 줄 구성(마지막으로 설치 길이를 말한 고객 말 기준)
 *  - install_runs_ctx()          도구 한 번 실행 동안의 줄 구성(ai_tool_dispatch 가 넣고 비움) — ai_estimate_quantity 가 읽는다
 *  - install_runs_reuse_offcut() 자투리를 다른 줄에 이어 쓸 수 있다고 검증된 자재인지(지금은 없음 — 개·본 단위 길이 자재는 모두 줄마다 올림)
 *  - install_runs_piece_qty()    1개 길이로 줄별 수량 계산 + 근거 문구
 *
 * 금지: 가격·단가·마진율·가격 계산식은 다루지 않는다(수량만). 예상 자재비 = 단가 × 수량 계산은 기존 그대로(ai_tools.php).
 * '총 30m 3줄'처럼 줄마다 길이를 모르면 나누지 않는다(균등 분배 금지 — 합계로 계산하고 줄마다 길이를 확인).
 * 순수 함수(install_runs_ctx 의 요청 범위 상태 빼고) — DB·세션 없음.
 */

require_once __DIR__ . '/features.php';   // 스위치 FEATURE_AI_INSTALL_RUNS(bootstrap 없이 rag.php 만 읽는 테스트에서도)

/** 줄 구성으로 볼 최대 줄 수·한 줄 최대 길이(m) — 넘으면 줄 구성으로 읽지 않음(오인 방지) */
const INSTALL_RUNS_MAX_COUNT = 30;
const INSTALL_RUNS_MAX_LEN_M = 2000.0;

/** 줄 수를 말하는 낱말 */
const INSTALL_RUNS_LINE_WORD = '(?:줄|열|라인|line|군데|곳|개소|구간|개\s*구간|개\s*라인)';

/**
 * 자투리(절단 잔여분)를 다른 줄에 이어 쓸 수 있다고 **검증된** 자재 — 상품명·분류 이름 정규식 목록. 지금은 비어 있음(사용자 결정 2026-11-26).
 * PE·PVC 관류도 '자를 수 있다'는 것만으로 재사용을 가정하지 않는다: 제조사 공식 시공지침·국가건설기준(KDS·KCS) 등에서
 * 다른 구간 재사용이 확인된 자재만 근거와 함께 여기에 더한다. 그 전에는 개·본 단위 길이 자재 모두 줄마다 올림(미터 단위 판매는 합계).
 * @var list<string>
 */
const INSTALL_RUNS_REUSE_VERIFIED = [];

/** 한글 수 낱말 → 숫자 (모르면 null) */
function install_runs_count_num(string $w): ?int
{
    $w = trim($w);
    if (preg_match('/^\d+$/', $w)) { return (int) $w; }
    return ['한' => 1, '하나' => 1, '두' => 2, '둘' => 2, '세' => 3, '셋' => 3, '네' => 4, '넷' => 4, '다섯' => 5, '여섯' => 6, '일곱' => 7, '여덟' => 8, '아홉' => 9, '열' => 10][$w] ?? null;
}

/**
 * 고객 말 하나에서 줄 구성 읽기 (순수 함수).
 * 줄 구성이 분명한 말만 읽는다 — 줄 하나의 길이만 말한 '20m 설치'는 null(기존 설치 길이 읽기가 맡음, 한 줄로 계산).
 * @return null|array{runs: list<float>, total: float, count: int, split_known: bool, text: string}
 *   split_known false = '총 30m 3줄'(줄마다 길이 모름 — runs 는 비고 total·count 만)
 */
function install_runs_parse(string $msg): ?array
{
    $t = str_replace(['，', '、', '＋'], [',', ',', '+'], $msg);
    $num = '(\d+(?:\.\d+)?)';
    $m   = '\s*(?:m|M|미터)(?![mM²2a-zA-Z㎡])';
    $cnt = '(\d+|한|두|세|네|다섯|여섯|일곱|여덟|아홉|열)\s*' . INSTALL_RUNS_LINE_WORD;
    $total = '(?:총|전체|합계|합쳐서|합해서|모두\s*합쳐|다\s*합쳐)';
    // 치수(폭·높이…)·1개 길이로 말한 숫자는 설치 길이가 아님
    $notInstall = static function (int $off) use ($t): bool {
        $before = substr($t, max(0, $off - 24), min(24, $off));
        return preg_match('/(?:폭|너비|높이|두께|깊이|지름|직경|관경|내경|외경|개당|1\s*개|한\s*개|제품\s*길이|낱개)\s*(?:은|는|이|가|:)?\s*(?:약\s*)?$/u', $before) === 1
            || preg_match('/[×*xX]\s*$/u', $before) === 1;
    };
    $sane = static fn(float $v): bool => $v > 0 && $v <= INSTALL_RUNS_MAX_LEN_M;

    // ① 줄마다 길이를 모름: '총 30m 3줄'·'3줄 총 30m'·'30m를 3줄로'·'두 줄 합쳐서 30m'
    foreach (['/' . $total . '\s*(?:길이|연장)?\s*(?:은|는|이|가|:)?\s*(?:약\s*)?' . $num . $m . '\s*(?:정도|쯤)?\s*(?:,|이고|에|으로|로|을|를)?\s*' . $cnt . '/u' => [1, 2],
              '/' . $cnt . '\s*(?:을|를|에|으로|로|이고|,)?\s*' . $total . '\s*(?:길이|연장)?\s*(?:은|는|이|가|:)?\s*(?:약\s*)?' . $num . $m . '/u' => [2, 1],
              '/' . $num . $m . '\s*(?:정도|쯤)?\s*(?:을|를)\s*' . $cnt . '\s*(?:으로|로)/u' => [1, 2],
              // '두 줄로 20m'·'2줄에 20m'(각·씩 없음 — 줄마다 같은 길이인지 말에 없음)
              '/' . $cnt . '\s*(?:으로|로|에)\s*(?:약\s*)?' . $num . $m . '(?!\s*씩)/u' => [2, 1]] as $re => [$vi, $ci]) {
        if (preg_match($re, $t, $x)) {
            $v = (float) $x[$vi];
            $c = install_runs_count_num((string) $x[$ci]);
            if ($c !== null && $c >= 2 && $c <= INSTALL_RUNS_MAX_COUNT && $sane($v)) {
                return ['runs' => [], 'total' => $v, 'count' => $c, 'split_known' => false, 'text' => trim($x[0])];
            }
        }
    }

    // ② 줄 항목을 말 순서대로 모은다: 'N m(씩) K줄' / 'K줄 (각) N m(씩)' / '양쪽 (각) N m(씩)' / 'N m'(목록 안의 한 줄)
    $items = [];   // [offset, end, value, count, text]
    $taken = [];
    $free = static function (int $a, int $b) use (&$taken): bool {
        foreach ($taken as [$x, $y]) { if ($a < $y && $b > $x) { return false; } }
        return true;
    };
    $patterns = [
        // 'N m씩 K줄'·'N m K줄'·'N m짜리 K줄'·'N m × K줄'
        ['/(?<![\d.])' . $num . $m . '\s*(?:씩|짜리|정도|쯤)?\s*(?:로|으로)?\s*(?:[x×*]\s*)?' . $cnt . '/u', 'len_cnt'],
        // 'K줄 각 N m'·'K줄 N m씩'·'K줄에 각각 N m'
        ['/' . $cnt . '\s*(?:을|를|에|은|는|이고|,)?\s*(?:각각|각|모두|다|전부)\s*' . $num . $m . '\s*(?:씩)?/u', 'cnt_each'],
        ['/' . $cnt . '\s*(?:을|를|에|은|는|이고|,)?\s*' . $num . $m . '\s*씩/u', 'cnt_each'],
        // '양쪽 각 20m'·'양쪽으로 20m씩'·'20m씩 양쪽'·'좌우 20m씩'
        ['/(?:양쪽|양옆|양측|좌우|양편)\s*(?:으로|에|로|모두|다)?\s*(?:각각|각)?\s*' . $num . $m . '\s*(씩)?/u', 'both'],
        ['/(?<![\d.])' . $num . $m . '\s*씩\s*(?:양쪽|양옆|양측|좌우|양편)/u', 'both_after'],
    ];
    foreach ($patterns as [$re, $kind]) {
        if (!preg_match_all($re, $t, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($all as $x) {
            $a = $x[0][1];
            $b = $a + strlen($x[0][0]);
            if (!$free($a, $b)) { continue; }
            if ($kind === 'len_cnt') { [$v, $c] = [(float) $x[1][0], install_runs_count_num($x[2][0])]; $vOff = $x[1][1]; }
            elseif ($kind === 'cnt_each') { [$v, $c] = [(float) $x[2][0], install_runs_count_num($x[1][0])]; $vOff = $x[2][1]; }
            elseif ($kind === 'both') {
                // '양쪽 합쳐 40m'은 위 ①이나 아래에서 — 여기서는 '각'·'씩'이 있거나 양쪽 다음 바로 길이일 때 줄마다 같은 길이
                [$v, $c] = [(float) $x[1][0], 2]; $vOff = $x[1][1];
            } else { [$v, $c] = [(float) $x[1][0], 2]; $vOff = $x[1][1]; }
            if ($c === null || $c < 1 || $c > INSTALL_RUNS_MAX_COUNT || !$sane($v) || $notInstall($vOff)) { continue; }
            // 앞에 '총'이 붙은 길이(총 30m 2줄)는 ①에서 다룸 — 여기서는 줄마다 길이로 읽지 않는다
            if (preg_match('/' . $total . '\s*(?:길이|연장)?\s*(?:은|는|이|가|:)?\s*(?:약\s*)?$/u', substr($t, max(0, $vOff - 20), min(20, $vOff)))) { continue; }
            $items[] = [$a, $b, $v, $c, trim($x[0][0])];
            $taken[] = [$a, $b];
        }
    }
    // 목록 안의 한 줄 'N m'(앞뒤 항목과 쉼표·와·랑·+ 등으로 이어진 것만)
    if (preg_match_all('/(?<![\d.])' . $num . $m . '(?:\s*(?:짜리\s*)?(?:한\s*줄|1\s*줄|하나))?/u', $t, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
        foreach ($all as $x) {
            $a = $x[0][1];
            $b = $a + strlen($x[0][0]);
            if (!$free($a, $b) || $notInstall($x[1][1])) { continue; }
            $v = (float) $x[1][0];
            if (!$sane($v)) { continue; }
            $items[] = [$a, $b, $v, 1, trim($x[0][0]), 'single'];
        }
    }
    if (!$items) { return null; }
    usort($items, static fn($p, $q) => $p[0] <=> $q[0]);
    // 이어진 항목 묶기 — 사이가 구분 말(쉼표·와·과·랑·하고·그리고·+·/·및)뿐이면 같은 묶음
    // 띄어쓰기만('10m 30m')은 묶지 않는다(어느 값이 무엇인지 말에 없음 — 기존처럼 읽지 않음)
    $sep = '/^\s*(?:,|\+|\/|와|과|랑|이랑|하고|그리고|및|또는)\s*(?:그리고|또)?\s*$/u';
    $groups = [];
    $cur = [];
    foreach ($items as $it) {
        if ($cur) {
            $prev = end($cur);
            $gap = substr($t, $prev[1], max(0, $it[0] - $prev[1]));
            if (preg_match($sep, $gap) !== 1) { $groups[] = $cur; $cur = []; }
        }
        $cur[] = $it;
    }
    if ($cur) { $groups[] = $cur; }
    // 줄 구성으로 볼 묶음: 항목이 2개 이상이거나, 줄 수를 말한 항목이 있음 — 마지막 묶음(고객이 나중에 고쳐 말한 값)
    $best = null;
    foreach ($groups as $g) {
        $multi = count($g) >= 2 || array_filter($g, static fn($it) => ($it[5] ?? '') !== 'single');
        if ($multi) { $best = $g; }
    }
    if ($best === null) { return null; }
    $runs = [];
    // '10m, 15m 두 줄' — 마지막 항목의 줄 수가 묶음 전체 줄 수(항목 수)를 말한 것: 줄마다 한 번씩
    $lastIt = end($best);
    $singlesBefore = count($best) >= 2 && !array_filter(array_slice($best, 0, -1), static fn($it) => ($it[5] ?? '') !== 'single');
    if ($singlesBefore && ($lastIt[5] ?? '') !== 'single' && (int) $lastIt[3] === count($best)) {
        foreach ($best as $k => $it) { $best[$k][3] = 1; }
    }
    foreach ($best as $it) {
        for ($i = 0; $i < $it[3]; $i++) { $runs[] = (float) $it[2]; }
    }
    if (count($runs) < 2 || count($runs) > INSTALL_RUNS_MAX_COUNT) {
        // 'N m 1줄'처럼 한 줄뿐이면 줄 구성이 아님(한 줄 계산 — 기존 읽기가 맡음)
        return null;
    }
    $text = trim(substr($t, $best[0][0], end($best)[1] - $best[0][0]));
    return ['runs' => $runs, 'total' => round(array_sum($runs), 3), 'count' => count($runs), 'split_known' => true, 'text' => $text];
}

/**
 * 대화에서 지금 유효한 줄 구성 (순수 함수) — 설치 길이를 말한 마지막 고객 말 기준.
 * 그 말이 줄 하나의 길이만이면('20m 설치') null(한 줄 계산). $singleLen 은 고객 말 하나가 설치 길이 하나를 말했는지 판정(기본: 'Nm 설치·필요·구간·연장·총').
 * @param list<array{role:string, content:string}> $messages
 * @return null|array{runs: list<float>, total: float, count: int, split_known: bool, text: string, last: bool}
 */
function install_runs_from_messages(array $messages, ?callable $singleLen = null): ?array
{
    $singleLen ??= static fn(string $t): bool => preg_match('/(?<![\d.×*])\d+(?:\.\d+)?\s*(?:m|M|미터)(?![mM²2a-zA-Z㎡])\s*(?:정도|쯤|가량|내외)?\s*(?:를|을|가|이|요|예요|이에요|입니다)?\s*(?:설치|시공|깔|놓|필요|들어|구간|연장|$|[.!?])/u', $t) === 1
        || preg_match('/(?:설치|시공|총|전체|구간|연장)\s*(?:길이|연장|구간|거리)?\s*(?:은|는|이|가|:)?\s*(?:총\s*)?(?:약\s*)?\d+(?:\.\d+)?\s*(?:m|M|미터)/u', $t) === 1;
    $users = array_values(array_filter($messages, static fn($m) => ($m['role'] ?? '') === 'user'));
    $cur = null;
    foreach ($users as $i => $m) {
        $t = (string) ($m['content'] ?? '');
        $r = install_runs_parse($t);
        if ($r !== null) { $cur = $r + ['last' => $i === count($users) - 1]; continue; }
        if ($singleLen($t)) { $cur = null; }   // 줄 하나 길이로 고쳐 말함 → 한 줄 계산
    }
    return $cur;
}

/**
 * 도구 한 번 실행 동안의 줄 구성(요청 범위 상태) — ai_tool_dispatch 가 도구 입력(runs_m·run_count)으로 넣고, 끝나면 비운다.
 * ai_estimate_quantity 는 합계가 설치 길이(length_m)와 같을 때만 쓴다(다른 길이로 부른 계산에는 쓰지 않음).
 * @param array|null $set ['runs' => list<float>, 'count' => int] 또는 ['runs' => [], 'count' => n](줄마다 길이 모름)
 */
function install_runs_ctx(?array $set = null, bool $reset = false): ?array
{
    static $ctx = null;
    if ($reset) { $ctx = null; return null; }
    if ($set !== null) { $ctx = $set; }
    return $ctx;
}

/**
 * 도구 입력의 줄 구성 정리 (순수 함수) — runs_m(줄마다 길이 m 목록)·run_count(줄마다 길이를 모를 때 줄 수).
 * runs_m 이 2줄 이상이면 length_m = 합계로 맞춘다. [정리한 입력, 줄 구성 또는 null]
 * @return array{0: array, 1: ?array}
 */
function install_runs_input(array $input): array
{
    $runs = [];
    foreach (is_array($input['runs_m'] ?? null) ? $input['runs_m'] : [] as $v) {
        $f = is_numeric($v) ? (float) $v : 0.0;
        if ($f > 0 && $f <= INSTALL_RUNS_MAX_LEN_M) { $runs[] = $f; }
    }
    if (count($runs) >= 2 && count($runs) <= INSTALL_RUNS_MAX_COUNT) {
        $input['length_m'] = round(array_sum($runs), 3);
        unset($input['run_count']);
        return [$input, ['runs' => $runs, 'count' => count($runs)]];
    }
    unset($input['runs_m']);
    $n = (int) ($input['run_count'] ?? 0);
    if ($n >= 2 && $n <= INSTALL_RUNS_MAX_COUNT && (float) ($input['length_m'] ?? 0) > 0) {
        return [$input, ['runs' => [], 'count' => $n]];
    }
    unset($input['run_count']);
    return [$input, null];
}

/** 자투리를 다른 줄에 이어 쓸 수 있다고 검증된 자재인지 (순수 함수) — INSTALL_RUNS_REUSE_VERIFIED(지금은 없음 → 항상 false) */
function install_runs_reuse_offcut(array $p): bool
{
    $text = ((string) ($p['name'] ?? '')) . ' ' . ((string) ($p['category_name'] ?? ''));
    foreach (INSTALL_RUNS_REUSE_VERIFIED as $re) {
        if (preg_match($re, $text) === 1) { return true; }
    }
    return false;
}

/** 지금 계산에 쓸 줄 구성 (순수 함수 + 요청 범위 상태) — 합계가 설치 길이와 같을 때만 */
function install_runs_for_length(float $lengthM): ?array
{
    $c = install_runs_ctx();
    if (!$c || $lengthM <= 0) { return null; }
    if ($c['runs'] && abs(array_sum($c['runs']) - $lengthM) > 0.001) { return null; }
    return $c;
}

/** 줄 구성 설명 (순수 함수) — '10m×2줄'·'10m·15m'·'총 30m(3줄, 줄마다 길이 모름)' */
function install_runs_label(array $c, float $lengthM): string
{
    $f = static fn(float $n): string => rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
    if (!$c['runs']) { return '총 ' . $f($lengthM) . 'm(' . (int) $c['count'] . '줄, 줄마다 길이 모름)'; }
    $u = array_values(array_unique(array_map($f, $c['runs'])));
    return count($u) === 1 ? $u[0] . 'm×' . count($c['runs']) . '줄' : implode('·', array_map(static fn($v) => $v . 'm', array_map($f, $c['runs'])));
}

/**
 * 1개 길이(m)로 줄별 수량 (순수 함수). 줄마다 길이를 알면 줄마다 올림해 더한다(줄 끝에서 자른 자투리를 다른 줄에 쓰지 않는 자재).
 * 자투리 재사용이 검증된 자재($reuse — 지금은 없음)·줄마다 길이를 모르면 합계로 계산한다(근거에 그 사실을 밝힘).
 * @return array{0:int, 1:string}
 */
function install_runs_piece_qty(float $lengthM, float $perM, string $unit, ?array $c, bool $reuse): array
{
    $f = static fn(float $n): string => rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
    $u = $unit !== '' ? $unit : '개';
    $whole = (int) ceil(round($lengthM / $perM, 6));
    if ($c === null) {
        return [$whole, sprintf('%sm ÷ %sm = %d%s', $f($lengthM), $f($perM), $whole, $u)];
    }
    $label = install_runs_label($c, $lengthM);
    if (!$c['runs']) {
        return [$whole, sprintf('%s: %sm ÷ %sm = %d%s', $label, $f($lengthM), $f($perM), $whole, $u)];
    }
    if ($reuse) {
        return [$whole, sprintf('%s = 총 %sm ÷ %sm = %d%s(자투리를 다른 줄에 이어 써 합계로 계산)', $label, $f($lengthM), $f($perM), $whole, $u)];
    }
    $per = array_map(static fn(float $r): int => (int) ceil(round($r / $perM, 6)), $c['runs']);
    $qty = array_sum($per);
    $uniq = array_values(array_unique($c['runs']));
    if (count($uniq) === 1) {
        $basis = sprintf('%s: 한 줄 %sm ÷ %sm = %d%s × %d줄 = %d%s', $label, $f($uniq[0]), $f($perM), $per[0], $u, count($per), $qty, $u);
    } else {
        $basis = $label . ': ' . implode(' + ', array_map(static fn(float $r, int $q) => $f($r) . 'm ' . $q . $u, $c['runs'], $per)) . sprintf(' = %d%s(줄마다 ÷ %sm 올림)', $qty, $u, $f($perM));   // 줄 길이를 함께('10m 4본 + 15m 6본') — 고객이 어느 줄이 몇 개인지 알게(사용자 요청 11-27)
    }
    if ($qty > $whole) { $basis .= '(줄 끝은 잘라 쓰고 자투리는 다른 줄에 쓰지 않는 기준)'; }
    return [$qty, $basis];
}

/**
 * 수량 계산의 줄 기준 (순수 함수 + 요청 범위 상태) — 답 글·견적 표에 밝힐 한 줄.
 * 줄 구성이 없으면 '한 줄 20m 기준'(고객이 길이만 말함 — 되묻지 않고 한 줄로 계산), 여러 줄이면 '10m×2줄(총 20m)', 줄마다 길이를 모르면 그 사실.
 */
function install_runs_basis_label(float $lengthM): string
{
    if ($lengthM <= 0) { return ''; }
    $f = static fn(float $n): string => rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
    $c = install_runs_for_length($lengthM);
    if ($c === null) { return '한 줄 ' . $f($lengthM) . 'm 기준'; }
    return $c['runs'] ? install_runs_label($c, $lengthM) . '(총 ' . $f($lengthM) . 'm)' : install_runs_label($c, $lengthM);
}

/** 줄마다 길이를 모를 때 확인 안내 (순수 함수) — 줄마다 올림하는 자재에만(미터 단위 판매·재사용 검증 자재는 합계로 정확) */
function install_runs_split_note(float $lengthM, array $p): ?string
{
    $c = install_runs_for_length($lengthM);
    $unit = preg_match('/단위:\s*([^|]+)/u', (string) ($p['spec'] ?? ''), $m) ? mb_strtolower(str_replace(' ', '', trim($m[1]))) : '';
    if ($c === null || $c['runs'] || $unit === 'm' || install_runs_reuse_offcut($p)) { return null; }
    return '줄마다 길이를 몰라 합계로 계산함(줄마다 나눠 짐작하지 않음) — 줄마다 끝을 잘라 쓰는 자재라 줄마다 길이를 알면 수량이 조금 늘 수 있음. 줄마다 길이를 한 번만 물어볼 것(예: 10m, 12m, 8m).';
}
