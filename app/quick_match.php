<?php
declare(strict_types=1);

/**
 * 빠른 견적 창(A9·A11 — 케이토목 1분 자동견적 그대로): 품명+규격으로 같은 상품 찾기.
 *  - 견적서 사진에서 읽은 줄(quick_photo) → 줄마다 바로 목록에 담기
 *  - 고객이 제품명과 규격을 직접 말한 질문('그레이팅수로용 300*1000*32 얼마에요?') → 그 상품을 수량 0으로 바로 담기
 * 규격은 숫자 순서가 앞에서부터 같아야 하고(180×200×1000 = 180*200*1000(국산)), 품명은 같거나 비슷해야 한다(사진 글자 오타 허용).
 * 가격은 보지도 바꾸지도 않는다. 상품 조회는 search_products(판매 중 상품)만.
 */

/** 글 속 숫자들(소수 허용) — 규격 비교용 (순수 함수) */
function quick_match_nums(string $s): array
{
    preg_match_all('/\d+(?:\.\d+)?/', $s, $m);
    return array_map('floatval', $m[0]);
}

/** 품명 비교 키: 공백·괄호·기호를 빼고 소문자 (순수 함수) */
function quick_match_name_key(string $s): string
{
    return mb_strtolower((string) preg_replace('/[\s\(\)\[\]\{\}\-_·.,\/]+/u', '', $s));
}

/** 품명 닮음 점수: 3 같음, 2 한쪽이 다른 쪽을 품음, 1 글자 쌍이 60% 이상 겹침(사진 오타), 0 다름 (순수 함수) */
function quick_match_name_score(string $a, string $b): int
{
    $a = quick_match_name_key($a);
    $b = quick_match_name_key($b);
    if ($a === '' || $b === '') { return 0; }
    if ($a === $b) { return 3; }
    if (str_contains($a, $b) || str_contains($b, $a)) { return 2; }
    $pairs = static function (string $s): array {
        $c = mb_str_split($s);
        $p = [];
        for ($i = 0; $i < count($c) - 1; $i++) { $p[] = $c[$i] . $c[$i + 1]; }
        return $p;
    };
    $pa = $pairs($a);
    $pb = $pairs($b);
    if (!$pa || !$pb) { return 0; }
    $common = 0;
    $left = $pb;
    foreach ($pa as $x) {
        $k = array_search($x, $left, true);
        if ($k !== false) { $common++; unset($left[$k]); }
    }
    return (2 * $common / (count($pa) + count($pb))) >= 0.6 ? 1 : 0;
}

/** 상품 규격 글(‘규격: …’ 묶음이면 그 값) (순수 함수) */
function quick_match_spec_text(string $spec): string
{
    $s = parse_spec_field($spec)['spec'];
    return $s !== '' ? $s : $spec;
}

/**
 * 후보 상품 중 품명·규격이 맞는 1개 (순수 함수). 규격 숫자가 없으면 고르지 않는다(크기를 짐작하지 않음).
 * @param array<int,array{id:int|string,name:string,spec:string}> $rows search_products 순서(앞일수록 우선)
 */
function quick_match_pick(string $name, string $spec, array $rows): ?array
{
    $want = quick_match_nums($spec);
    if (!$want || trim($name) === '') { return null; }
    $best = null;
    $bestKey = [0, 0];
    foreach ($rows as $r) {
        $specText = quick_match_spec_text((string) ($r['spec'] ?? ''));
        $have = quick_match_nums($specText);
        if (count($have) < count($want) || array_slice($have, 0, count($want)) !== $want) { continue; }
        $score = quick_match_name_score($name, (string) ($r['name'] ?? ''));
        if ($score === 0) { continue; }
        // 품명이 더 닮은 것, 같으면 규격 글이 짧은 것(덧붙인 '_국산'·형식 글 없는 일반 제품 — 케이토목 '일반 제품' 담기)
        $key = [$score, -mb_strlen($specText)];
        if ($best === null || $key > $bestKey) { $best = $r; $bestKey = $key; }
    }
    return $best;
}

/** 규격 숫자 차례를 찾는 MySQL REGEXP (순수 함수): [300,1000,32] → (^|[^0-9.])300[^0-9.]+1000[^0-9.]+32([^0-9.]|$) */
function quick_match_spec_regexp(array $nums): string
{
    $parts = array_map(static fn($n) => str_replace('.', '[.]', (string) $n), $nums);
    return '(^|[^0-9.])' . implode('[^0-9.]+', $parts) . '([^0-9.]|$)';
}

/**
 * 판매 중 상품에서 품명+규격이 맞는 상품 (DB 조회만).
 * 규격 숫자 차례로 먼저 좁힌 뒤 품명으로 찾는다 — 같은 이름 상품이 많으면(운영 그레이팅 1,400여 개) 이름 검색 앞 60개에 그 규격이 없어 못 찾던 문제(운영 시험 11-24).
 */
function quick_match_find(string $name, string $spec): ?array
{
    $q    = trim((string) preg_replace('/\d+(?:\.\d+)?/', ' ', $name));
    $nums = quick_match_nums($spec);
    if (mb_strlen($q) < 2 || !$nums) { return null; }
    $total = null;
    $rows  = search_products($q, 60, 0, $total, ' AND p.spec REGEXP :qm_spec', [':qm_spec' => quick_match_spec_regexp($nums)]);
    return quick_match_pick($name, $spec, $rows);
}

/**
 * 고객 말에서 '품명 + 규격(숫자×숫자…)' 을 나눈다 (순수 함수). 규격이 없으면 null.
 * 예) '그레이팅수로용 300*1000*32 얼마에요?' → ['name' => '그레이팅수로용', 'spec' => '300*1000*32']
 */
function quick_match_named(string $msg): ?array
{
    if (!preg_match('/(D?\d+(?:\.\d+)?(?:\s*[*xX×]\s*\d+(?:\.\d+)?)+)/u', $msg, $m, PREG_OFFSET_CAPTURE)) { return null; }
    $name = trim(substr($msg, 0, $m[0][1]));
    $name = trim((string) preg_replace('/^.*[,.!?]\s*/u', '', $name));   // 앞 문장은 빼고 규격 바로 앞 말만
    $words = preg_split('/\s+/u', $name) ?: [];
    $name = implode(' ', array_slice($words, -3));   // 품명은 규격 앞 3단어 안
    if (mb_strlen(quick_match_name_key($name)) < 2) { return null; }
    return ['name' => $name, 'spec' => $m[1][0]];
}

/**
 * 바꿔 담기(운영 시험 11-24): 새로 담는 상품과 크기(규격 앞 두 숫자)가 같고 품명이 닮은, 수량 0(미정) 줄의 id (순수 함수).
 * 예) 그레이팅수로용 300*1000*32 (0개) → 차량 통행을 듣고 중하중그레이팅수로용 300*1000*50 을 담으면 32 줄을 뺀다.
 * @param array{id:int|string,name:string,spec:string} $new
 * @param array<int,array{id:int|string,name:string,spec:string}> $zeroRows 목록의 수량 0 줄
 */
function quick_match_swap_ids(array $new, array $zeroRows): array
{
    $size = array_slice(quick_match_nums(quick_match_spec_text((string) ($new['spec'] ?? ''))), 0, 2);
    if (count($size) < 2) { return []; }
    $out = [];
    foreach ($zeroRows as $r) {
        if ((int) $r['id'] === (int) $new['id']) { continue; }
        if (array_slice(quick_match_nums(quick_match_spec_text((string) ($r['spec'] ?? ''))), 0, 2) !== $size) { continue; }
        if (quick_match_name_score((string) ($new['name'] ?? ''), (string) ($r['name'] ?? '')) === 0) { continue; }
        $out[] = (int) $r['id'];
    }
    return $out;
}

/**
 * 이번 말이 새 자재 요청인지(순수 함수) — '도로에 쓸 그레이팅 추천해줘'·'잔디블록 얼마에요' 처럼 자재 이름이나 추천·가격 요청이 있으면 새 상황.
 * 새 상황이면 앞서 담은 수량 미정 줄을 바꿔 담기로 빼지 않는다(사용자 피드백 11-25 — 상황이 바뀌면 앞 상황 제품이 사라지던 문제).
 * $category: rag_detect_category 결과(이번 말만). 조건만 답한 말('아파트 주차장이고 차 다녀요. 20m요')은 새 요청이 아님.
 */
function quick_match_new_request(string $msg, string $category): bool
{
    return $category !== '' || (bool) preg_match('/추천|찾아|얼마|가격|견적|필요한\s*(자재|제품)/u', $msg);
}

/** 고객이 '같이·추가·둘 다'처럼 함께 담기를 말했는지 — 그때는 바꿔 담기로 빼지 않는다 (순수 함수) */
function quick_match_keep_both(string $msg): bool
{
    return (bool) preg_match('/같이|함께|추가|둘\s*다|모두|각각|따로|도\s*(담아|넣어|견적)/u', $msg);
}

/**
 * 이번 요청(한 턴)의 고객 말과 이번에 담은 상품 id — rag_process 가 턴 시작 때 $msg 로 초기화, tool_add_to_cart 가 $added 를 더한다.
 * 같은 턴에 함께 담은 수량 0 상품은 바꿔 담기로 빼지 않기 위함.
 * @return array{msg:string, added:list<int>}
 */
function quick_turn_state(?string $msg = null, ?int $added = null): array
{
    static $s = ['msg' => '', 'added' => [], 'id' => ''];
    if ($msg !== null) { $s = ['msg' => $msg, 'added' => [], 'id' => bin2hex(random_bytes(6))]; }   // id: 이 턴 표시(바꿔 담기는 바로 앞 턴에 담은 줄만 — 11-25)
    if ($added !== null) { $s['added'][] = $added; }
    return $s;
}

/** 글 속 금액(원) 표현이 있는지 — '66,000원'·'약 13만 원'·'6만 6천원' (순수 함수). '원형'·'원문'은 금액이 아님 */
function quick_reply_has_money(string $s): bool
{
    return (bool) preg_match('/\d[\d,]*\s*(?:만\s*(?:\d[\d,]*\s*천\s*)?|천\s*)?원(?!형|문|목|단|추|기|칙|래|활)/u', $s);
}

/**
 * 마디를 뺀 뒤 앞 마디를 문장으로 끝맺기 (순수 함수): '필요하고,' → '필요합니다.' — 맺을 수 없으면 null(문장째 뺌)
 */
function quick_reply_close_clause(string $c): ?string
{
    $c = rtrim($c, " ,");
    $map = ['/(하고|하며|하여)$/u' => '합니다.', '/(이고|이며)$/u' => '입니다.', '/(있고|있으며)$/u' => '있습니다.', '/(되고|되며)$/u' => '됩니다.', '/(했고|했으며)$/u' => '했습니다.'];
    foreach ($map as $re => $end) {
        if (preg_match($re, $c)) { return (string) preg_replace($re, $end, $c); }
    }
    return null;
}

/**
 * 빠른 견적 창 답 글 정리 (순수 함수): '견적서는 화면에 이미 표시되고 있으며'·'견적 카드에 … 표시되어 있습니다' 같은 화면 안내 말을 뺀다(운영 시험 11-24 — 어색한 문구).
 * 금액(원)이 든 마디도 뺀다 — 케이토목처럼 금액은 창의 견적 표에만(사용자 요청 11-24).
 * '견적서에 담았습니다'는 '목록에 담았습니다'로(담는 곳은 창 안 목록).
 * 문장 안의 앞쪽 마디(… 며, / … 시고,)면 그 마디만, 마디가 하나뿐인 문장이면 문장째 뺀다. 수량·지역을 묻는 말이 섞인 화면 안내 마디는 둔다.
 * 문장 끝 마디는 화면 안내면 그대로 두고, 금액이면 빼고 앞 마디를 맺는다('필요하고,' → '필요합니다.' — 맺을 수 없으면 문장째).
 */
function quick_reply_tidy(string $text): string
{
    $screen = '/(화면|견적\s*카드|견적\s*표|(?<![가-힣])창(?=에|의|\s))(에서|에는|에|의)?\s[^,]*?(표시|보이|보입|나와|나타|확인)|견적서(에서|를)\s*확인|화면(에서|에)\s*(확인|보이|보입)/u';
    $keep   = '/알려|말씀|지역|수량|\d/u';
    $lines  = [];
    foreach (explode("\n", $text) as $line) {
        $sentences = preg_split('/(?<=[.!?])\s+/u', $line) ?: [$line];
        $kept = [];
        foreach ($sentences as $sen) {
            $clauses = preg_split('/(?<=,)\s+/u', $sen) ?: [$sen];
            $last    = count($clauses) - 1;
            $out     = [];
            $whole   = false;
            foreach ($clauses as $i => $c) {
                if (quick_reply_has_money($c)) {
                    if ($i === $last && $out) {
                        $closed = quick_reply_close_clause(array_pop($out));
                        if ($closed === null) { $whole = true; } else { $out[] = $closed; }
                    }
                    continue;
                }
                $drop = preg_match($screen, $c) && !preg_match($keep, $c) && ($i < $last || $last === 0);
                if (!$drop) { $out[] = $c; }
            }
            $s = $whole ? '' : trim(implode(' ', $out));
            if ($s !== '') { $kept[] = $s; }
        }
        $lines[] = implode(' ', $kept);
    }
    $text = implode("\n", $lines);
    $text = (string) preg_replace('/견적서에\s*(담았|담아|넣었|넣어)/u', '목록에 $1', $text);   // 담는 곳은 창 안 목록(사이트 견적서가 아님)
    $text = quick_reply_units($text);
    return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
}

/**
 * 빠른 견적 창 답 글의 mm 치수를 m 로 (순수 함수): '폭 150mm, 길이 1000mm … 10m' 처럼 단위가 섞이던 문제(사용자 피드백 11-24 — m 기준).
 * 1000mm → 1m, 300mm → 0.3m, 1,500mm → 1.5m. 상품 규격 표기(300×1000×32, '×50mm' 처럼 곱하기 뒤 숫자)는 그대로 둔다.
 */
function quick_reply_units(string $text): string
{
    return (string) preg_replace_callback(
        '/(?<![\d.,])(\d{1,3}(?:,\d{3})+|\d+)(?:\.(\d+))?\s*(?:mm|㎜)(?![a-zA-Z])/u',
        static function (array $m) use ($text): string {
            $before = rtrim(substr($text, 0, $m[0][1]));
            if ($before !== '' && preg_match('/[×*xX]$|[A-Za-z]$/u', $before)) { return $m[0][0]; }   // 규격 표기의 일부(곱하기 뒤 숫자·D150mm 같은 호칭)
            $mm = (float) (str_replace(',', '', $m[1][0]) . (isset($m[2]) && $m[2][1] >= 0 ? '.' . $m[2][0] : ''));
            if ($mm <= 0) { return $m[0][0]; }
            return rtrim(rtrim(sprintf('%.4f', $mm / 1000), '0'), '.') . 'm';
        },
        $text,
        -1,
        $count,
        PREG_OFFSET_CAPTURE
    );
}

/**
 * 빠른 견적 창 상품 사진: 케이토목 썸네일 주소의 40×40 을 같은 파일의 450×450 으로 (순수 함수, DB 값은 그대로).
 * 운영 상품 1,315개가 ktomok.com/…/thumb-…_40x40.jpg (견적 표에서 흐리게 보임) — 450×450 이 없으면 화면이 원래 주소로 되돌린다.
 */
function quick_chat_big_image(string $url): string
{
    return (string) preg_replace('#^(https?://(?:www\.)?ktomok\.com/data/item/.+/thumb-[^/]+)_40x40(\.(?:jpe?g|png|gif))$#i', '$1_450x450$2', $url);
}

/**
 * 빠른 견적 창: 고객 말의 설치 길이·면적(개수 없이) — '10미터쯤 설치할거야'·'20m요'·'30㎡ 정도' (순수 함수, 사용자 피드백 11-24 — 말만 해도 권장 수량).
 * 여러 줄이면 합계와 줄 구성(runs_m 줄마다 길이, 줄마다 길이를 모르면 run_count) — install_runs_parse.
 * 개수(개·본·장…)를 말했거나 (줄 구성이 아닌) 값이 둘 이상이면 null. 폭·높이·두께·제품 길이 칸 이름이 붙은 값, '짜리'(1개 길이), 규격 표기(×·*)의 숫자는 뺀다.
 * @return array{length_m?:float, area_m2?:float, runs_m?:list<float>, run_count?:int}|null
 */
function quick_install_amount(string $msg): ?array
{
    if (preg_match('/\d\s*(?:개|본|장|매|세트|ea\b)/iu', $msg)) { return null; }
    // 여러 줄('10m씩 2줄'·'10m, 15m'·'양쪽 20m씩') → 합계 + 줄마다 길이, '총 30m 3줄' → 합계 + 줄 수(나누지 않음) — 한 줄 길이로 읽어 수량이 절반이 되던 문제(2026-11-26 B안)
    if (function_exists('install_runs_parse') && ai_install_runs_enabled() && ($r = install_runs_parse($msg)) !== null) {
        return ['length_m' => $r['total']] + ($r['split_known'] ? ['runs_m' => $r['runs']] : ['run_count' => $r['count']]);
    }
    $found = [];
    foreach (['area_m2' => '/(?<![\d.,×*])(\d+(?:\.\d+)?)\s*(?:㎡|m2|m²|제곱\s*미터|평방\s*미터|헤베)/u',
              'length_m' => '/(?<![\d.,×*])(\d+(?:\.\d+)?)\s*(?:m|M|미터)(?![mM²2a-zA-Z])/u'] as $k => $re) {
        if (!preg_match_all($re, $msg, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { continue; }
        foreach ($mm as $x) {
            $before = substr($msg, max(0, $x[0][1] - 30), min(30, $x[0][1]));
            $after  = substr($msg, $x[0][1] + strlen($x[0][0]), 12);
            if (preg_match('/[×*xX]\s*$/u', $before) || preg_match('/^\s*[×*xX]/u', $after) || preg_match('/^\s*짜리/u', $after)) { continue; }   // 규격 표기·1개 길이
            if (preg_match('/(?:폭|너비|높이|두께|깊이|지름|관경|개당|제품\s*길이)\s*(?:은|는|이|가|:)?\s*(?:약\s*)?$/u', $before)) { continue; }
            if (preg_match('/길이\s*(?:은|는|이|가|:)?\s*(?:약\s*)?$/u', $before) && !preg_match('/(?:설치|시공|총|전체)\s*길이\s*(?:은|는|이|가|:)?\s*(?:약\s*)?$/u', $before)) { continue; }   // '길이 1m'(어느 길이인지 모름)
            $v = (float) $x[1][0];
            if ($v > 0) { $found[$k][(string) $v] = $v; }
        }
    }
    if (count($found) !== 1) { return null; }
    $k = array_key_first($found);
    return count($found[$k]) === 1 ? [$k => array_values($found[$k])[0]] : null;
}

/**
 * 설치 길이·면적으로 수량을 맞출 목록 줄 (순수 함수): 수량 미정(0) 줄, 없으면 목록이 한 줄뿐일 때 그 줄. 그 밖은 [](고객이 정한 수량을 서버가 바꾸지 않음).
 * @param array<int,int> $cart 상품 id => 수량
 * @return list<int>
 */
function quick_qty_targets(array $cart): array
{
    $zero = array_values(array_map('intval', array_keys(array_filter($cart, static fn($q) => (int) $q === 0))));
    if ($zero) { return $zero; }
    return count($cart) === 1 ? [(int) array_key_first($cart)] : [];
}
