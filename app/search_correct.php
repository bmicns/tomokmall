<?php
declare(strict_types=1);
/**
 * 검색어 오타·유사어 교정 (Phase 3)
 *
 * - 목적: 사전(동의어/별칭)에 없는 "오타"를 잡아 "혹시 이걸 찾으셨나요?"를 제안한다.
 *   예) '그래이팅' → '그레이팅', '칙구' → '측구', '측구수로' → '측구수로관'
 * - 핵심: 한글은 음절이 초/중/종성 자모로 이뤄지므로, PHP 기본 levenshtein()(바이트 단위)은
 *   한글에서 오작동한다. 여기서는 음절을 자모로 분해한 뒤 편집거리를 계산해
 *   '레→래'처럼 자모 1개 차이를 정확히 거리 1로 잡는다.
 * - 사전: synonyms.php 의 segments(카탈로그 용어) + 동의어/별칭 어휘 + 카테고리명.
 *   (모두 큐레이션된 용어라 오교정 위험이 낮다. 상품명 전수 토큰은 노이즈가 커서 제외.)
 * - 호출 규약: search_products()가 0건을 낸 뒤에만 부른다(잃을 게 없을 때만 교정 제안).
 */

// 한글 자모 테이블
const HANGUL_CHO = ['ㄱ','ㄲ','ㄴ','ㄷ','ㄸ','ㄹ','ㅁ','ㅂ','ㅃ','ㅅ','ㅆ','ㅇ','ㅈ','ㅉ','ㅊ','ㅋ','ㅌ','ㅍ','ㅎ'];
const HANGUL_JUNG = ['ㅏ','ㅐ','ㅑ','ㅒ','ㅓ','ㅔ','ㅕ','ㅖ','ㅗ','ㅘ','ㅙ','ㅚ','ㅛ','ㅜ','ㅝ','ㅞ','ㅟ','ㅠ','ㅡ','ㅢ','ㅣ'];
const HANGUL_JONG = ['','ㄱ','ㄲ','ㄳ','ㄴ','ㄵ','ㄶ','ㄷ','ㄹ','ㄺ','ㄻ','ㄼ','ㄽ','ㄾ','ㄿ','ㅀ','ㅁ','ㅂ','ㅄ','ㅅ','ㅆ','ㅇ','ㅈ','ㅊ','ㅋ','ㅌ','ㅍ','ㅎ'];

/**
 * 문자열을 자모(초/중/종성) 배열로 분해. 한글이 아닌 문자는 소문자 그대로 1글자.
 * 예: '측구' → ['ㅊ','ㅡ','ㄱ','ㄱ','ㅜ']
 */
function hangul_to_jamo(string $s): array
{
    $out = [];
    $len = mb_strlen($s);
    for ($i = 0; $i < $len; $i++) {
        $ch   = mb_substr($s, $i, 1);
        $code = mb_ord($ch, 'UTF-8');
        if ($code !== false && $code >= 0xAC00 && $code <= 0xD7A3) {
            $v    = $code - 0xAC00;
            $out[] = HANGUL_CHO[intdiv($v, 588)];
            $out[] = HANGUL_JUNG[intdiv($v % 588, 28)];
            $jong  = $v % 28;
            if ($jong > 0) {
                $out[] = HANGUL_JONG[$jong];
            }
        } else {
            $out[] = mb_strtolower($ch);
        }
    }
    return $out;
}

/**
 * 두 배열(자모 시퀀스)의 편집거리(Levenshtein DP). 대·소 무관하게 이미 정규화됨 가정.
 */
function _edit_distance(array $a, array $b): int
{
    $m = count($a);
    $n = count($b);
    if ($m === 0) { return $n; }
    if ($n === 0) { return $m; }

    $prev = range(0, $n);
    for ($i = 1; $i <= $m; $i++) {
        $curr = [$i];
        for ($j = 1; $j <= $n; $j++) {
            $cost   = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
            $curr[$j] = min(
                $prev[$j] + 1,        // 삭제
                $curr[$j - 1] + 1,    // 삽입
                $prev[$j - 1] + $cost // 치환
            );
        }
        $prev = $curr;
    }
    return $prev[$n];
}

/**
 * 두 문자열의 자모 단위 편집거리. 한글 오타(모음/자음 1개 차이)를 거리 1~2로 잡는다.
 */
function jamo_distance(string $a, string $b): int
{
    return _edit_distance(hangul_to_jamo($a), hangul_to_jamo($b));
}

/**
 * 교정 후보 용어 목록(원형). segments + 동의어/별칭 어휘 + 카테고리명. 1회 캐시.
 * - 2글자 이상만, 중복 제거. DB 불가 시 파일 어휘만으로도 동작.
 */
function correction_terms(): array
{
    static $terms = null;
    if ($terms !== null) {
        return $terms;
    }
    $set = [];
    $add = static function ($t) use (&$set): void {
        $t = trim((string) $t);
        if ($t !== '' && mb_strlen($t) >= 2) {
            $set[$t] = true;
        }
    };

    // synonyms.php: segments + groups + aliases(키·타깃)
    $file = __DIR__ . '/synonyms.php';
    if (is_file($file)) {
        $data = require $file;
        foreach (($data['segments'] ?? []) as $s) { $add($s); }
        foreach (($data['groups'] ?? []) as $group) {
            if (is_array($group)) {
                foreach ($group as $t) { $add($t); }
            }
        }
        foreach (($data['aliases'] ?? []) as $k => $targets) {
            $add($k);
            if (is_array($targets)) {
                foreach ($targets as $t) { $add($t); }
            }
        }
    }

    // 카테고리명 (DB 가능할 때만)
    if (function_exists('db') && db()) {
        try {
            $st = db()->query('SELECT name FROM categories WHERE is_active = 1');
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $name) { $add($name); }
        } catch (\Throwable $e) {
            // 무시: 파일 어휘만으로 진행
        }
    }

    $terms = array_keys($set);
    return $terms;
}

/**
 * 한 토큰의 최근접 사전 용어와 자모 거리를 반환. [term, distance] 또는 null.
 * - 성능: 길이 차가 큰 후보는 건너뛴다(자모 거리는 최소 길이차 이상이므로).
 */
function nearest_term(string $token): ?array
{
    $tj    = hangul_to_jamo($token);
    $tlen  = count($tj);
    if ($tlen === 0) { return null; }

    // 허용 거리: 길수록 관대하게(대략 1/3), 최소 1. 최대 3으로 상한.
    $maxDist = min(3, max(1, (int) floor($tlen / 3)));

    $best   = null;
    $bestD  = PHP_INT_MAX;
    foreach (correction_terms() as $term) {
        $cj   = hangul_to_jamo($term);
        $clen = count($cj);
        if (abs($clen - $tlen) > $maxDist) {
            continue; // 길이차만으로 이미 임계 초과
        }
        $d = _edit_distance($tj, $cj);
        if ($d < $bestD) {
            $bestD = $d;
            $best  = $term;
            if ($d === 0) { break; } // 완전 일치면 종료(오타 아님)
        }
    }

    if ($best === null || $bestD > $maxDist) {
        return null;
    }
    return [$best, $bestD];
}

/**
 * 검색어 교정 제안. search_products()가 0건일 때만 호출한다.
 * @return array{query:string, from:string, to:string}|null
 *   - query: 교정된 전체 검색어(교정 토큰만 치환)
 *   - from : 실제로 바뀐 원래 토큰(대표 1개, 안내 문구용)
 *   - to   : 대체된 용어
 *   교정할 게 없거나 결과가 원본과 같으면 null.
 */
function suggest_correction(string $query): ?array
{
    $clean  = preg_replace('/[^\w가-힣\s]/u', ' ', $query) ?? '';
    $tokens = array_values(array_filter(
        preg_split('/\s+/u', trim($clean)) ?: [],
        static fn($k) => mb_strlen($k) >= 2 && !preg_match('/^\d+$/', $k)
    ));
    if (!$tokens) {
        return null;
    }

    $from = null;
    $to   = null;
    $corrected = [];
    foreach ($tokens as $t) {
        $near = nearest_term($t);
        if ($near !== null && $near[1] > 0) {
            $corrected[] = $near[0];
            if ($from === null) { // 첫 교정 토큰을 대표 안내로
                $from = $t;
                $to   = $near[0];
            }
        } else {
            $corrected[] = $t; // 정확 일치(거리0)거나 근접 없음 → 원본 유지
        }
    }

    if ($from === null) {
        return null; // 바뀐 토큰 없음
    }
    $newQuery = implode(' ', $corrected);
    if (mb_strtolower(trim($newQuery)) === mb_strtolower(trim($query))) {
        return null;
    }
    return ['query' => $newQuery, 'from' => $from, 'to' => $to];
}
