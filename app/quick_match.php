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
    static $s = ['msg' => '', 'added' => []];
    if ($msg !== null) { $s = ['msg' => $msg, 'added' => []]; }
    if ($added !== null) { $s['added'][] = $added; }
    return $s;
}

/**
 * 빠른 견적 창 답 글 정리 (순수 함수): '견적서는 화면에 이미 표시되고 있으며'·'견적 카드에 … 표시되어 있습니다' 같은 화면 안내 말을 뺀다(운영 시험 11-24 — 어색한 문구).
 * '견적서에 담았습니다'는 '목록에 담았습니다'로(담는 곳은 창 안 목록).
 * 문장 안의 앞쪽 마디(… 며, / … 시고,)면 그 마디만, 마디가 하나뿐인 문장이면 문장째 뺀다. 수량·지역을 묻는 말이 섞인 마디는 두고, 문장 끝 마디는 손대지 않는다.
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
            foreach ($clauses as $i => $c) {
                $drop = preg_match($screen, $c) && !preg_match($keep, $c) && ($i < $last || $last === 0);
                if (!$drop) { $out[] = $c; }
            }
            $s = trim(implode(' ', $out));
            if ($s !== '') { $kept[] = $s; }
        }
        $lines[] = implode(' ', $kept);
    }
    $text = implode("\n", $lines);
    $text = (string) preg_replace('/견적서에\s*(담았|담아|넣었|넣어)/u', '목록에 $1', $text);   // 담는 곳은 창 안 목록(사이트 견적서가 아님)
    return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
}
