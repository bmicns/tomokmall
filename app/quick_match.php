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
    $bestScore = 0;
    foreach ($rows as $r) {
        $have = quick_match_nums(quick_match_spec_text((string) ($r['spec'] ?? '')));
        if (count($have) < count($want) || array_slice($have, 0, count($want)) !== $want) { continue; }
        $score = quick_match_name_score($name, (string) ($r['name'] ?? ''));
        if ($score > $bestScore) { $best = $r; $bestScore = $score; }
    }
    return $best;
}

/** 판매 중 상품에서 품명+규격이 맞는 상품 (DB 조회만) */
function quick_match_find(string $name, string $spec): ?array
{
    $q = trim((string) preg_replace('/\d+(?:\.\d+)?/', ' ', $name));
    if (mb_strlen($q) < 2) { return null; }
    return quick_match_pick($name, $spec, search_products($q, 60));
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
