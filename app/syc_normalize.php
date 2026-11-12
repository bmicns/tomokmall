<?php
declare(strict_types=1);
/**
 * 상용콘크리트 업체 엑셀 자동 정리 엔진 — 일괄 등록에서 '등록 전' 에 행을 정리한다(사용자 요청 2026-11-11).
 *
 * 업체가 예전 표기로 보내도(품명에 형식·옵션이 붙음, 모델 코드에 크기, 옵션에 부속 치수, 나누기 전·합치기 전 분류코드)
 * 사이트 표기 원칙대로 고친다: 품명 = 제품명만, 모델 코드 = 형식만, 옵션 = 글자만, 치수 = 칸, 분류 = 지금 카테고리.
 *
 * 구조
 *   규칙(데이터)  app/syc_normalize_rules.php — 무엇을 어디로 옮길지. 이 파일에는 규칙이 없다.
 *   엔진(이 파일) 순수 함수. DB·파일·세션을 모른다 → 미리보기(올릴 때)와 등록(등록하기)이 같은 결과, 테스트로 확인.
 *   사용          app/syc_bulk.php syc_bulk_read_excel() → api/admin/syc-products.php(미리보기 요약·등록) → 제품관리 일괄 등록 창
 *
 * 단계(순서 고정)
 *   1 분류 합치기   숨긴 카테고리 분류코드 → 모은 카테고리
 *   2 분류 나누기   나눈 카테고리 묶음이면 값이 있는 목록 치수로 알맞은 카테고리
 *   3 옵션 속 치수  '그레이팅 245*995*25' → 옵션 '그레이팅' + 부속 치수 칸(칸이 비어 있을 때만 — 업체 값 우선)
 *   4 모델 코드     카테고리 단위: 모든 코드가 '크기[+형식]' 이고 크기가 치수 칸에 있을 때만 → 형식만(B형·W2), 크기뿐이면 비움,
 *                   형식이 옵션 글자(무개)면 옵션으로. 하나라도 아니면 그 카테고리 코드는 그대로(빈칸·뒤섞임 방지)
 *   4b 규격 원문     끝 괄호 '(300*400B)' 의 예전 코드를 4와 같은 기준으로: 형식 → '(B형)', 크기뿐·옵션 글자 → 괄호 뺌,
 *                   지금 모델 코드와 같거나 정리할 수 없는 글자('(NS-01)'·'(앙카식)')는 그대로(2026-11-12 사용자 요청)
 *   5 품명          품명 속 옵션 글자(규칙 option_words) → 옵션 앞으로, 모델 코드(형식)와 같은 낱말 → 뺌. 품명이 비면 그대로
 *                   (그 밖의 낱말은 옵션에 같은 글자가 있어도 둔다 — 예: '그레이팅 높이조절용 측구수로관' 의 '그레이팅')
 * 원칙: 바꾼 칸마다 [행, 칸, 전, 후, 규칙] 기록. 같은 결과를 다시 넣으면 바뀌는 것 없음(멱등). 가격·출처·이미지 칸은 읽지도 바꾸지도 않음.
 */

const SYC_NORM_RULE_LABELS = [
    'category_merge' => '분류 합치기', 'category_split' => '분류 나누기(치수 칸 구성)', 'option_dims' => '옵션 속 치수 → 치수 칸',
    'code' => '모델 코드 정리', 'spec' => '규격 원문 속 예전 코드', 'name' => '품명 정리', 'option_words' => '품명·코드의 옵션 글자 → 옵션',
];

/** 옵션 글자 → 조각(괄호 밖 쉼표로만 나눔 — '그레이팅(인도,차도)뚜껑' 은 한 조각) */
function syc_norm_option_parts(?string $opt): array
{
    $parts = []; $buf = ''; $depth = 0;
    foreach (preg_split('//u', (string) $opt, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
        if ($ch === '(') { $depth++; } elseif ($ch === ')' && $depth > 0) { $depth--; }
        if ($ch === ',' && $depth === 0) { $parts[] = trim($buf); $buf = ''; continue; }
        $buf .= $ch;
    }
    $parts[] = trim($buf);
    return array_values(array_filter($parts, static fn($p) => $p !== ''));
}

/** 행의 치수 칸 값(숫자) 모음 — 모델 코드의 크기가 치수 칸에 있는지 볼 때 */
function syc_norm_dim_values(array $r): array
{
    $out = [];
    foreach ($r as $k => $v) {
        if (str_starts_with((string) $k, 'dim_') && (is_int($v) || is_float($v))) { $out[] = (float) $v; }
    }
    return $out;
}

/** 모델 코드 하나 분석: ['kind' => keep|clear|code|word, 'value' => 새 코드 또는 옵션 글자]. 크기가 치수에 없으면 null(정리 불가) */
function syc_norm_code_parse(string $code, array $row, array $words): ?array
{
    $code = trim($code);
    if (preg_match('/^[A-Z]{1,3}형$|^[A-Z]+\d+$/u', $code)) { return ['kind' => 'keep', 'value' => $code]; }   // 이미 정리된 형식
    if (!preg_match('/^(\d+(?:\.\d+)?(?:\*\d+(?:\.\d+)?)*)(.*)$/u', $code, $m) || $m[1] === '') { return null; }
    $dims = syc_norm_dim_values($row);
    foreach (explode('*', $m[1]) as $n) {
        $f = (float) $n;
        if (!in_array($f, $dims, true) && !in_array($f * 1000, $dims, true)) { return null; }
    }
    $suf = trim($m[2]);
    if ($suf === '') { return ['kind' => 'clear', 'value' => null]; }
    if (preg_match('/^[A-Z]{1,3}$/', $suf)) { return ['kind' => 'code', 'value' => $suf . '형']; }
    if (preg_match('/^[A-Z]+\d+$/', $suf)) { return ['kind' => 'code', 'value' => $suf]; }
    if (in_array($suf, $words, true)) { return ['kind' => 'word', 'value' => $suf]; }
    return null;
}

/**
 * 행들을 정리한다.
 * @param list<array> $rows 각 행: '_category_slug' + syc_products 칸(name·spec_code·spec_option·dim_*…)
 * @param array $rules syc_normalize_rules()
 * @param array<string,bool>|null $active 분류코드 => 보이는 카테고리인지(경고용, null 이면 검사 안 함)
 * @return array{rows:list<array>, changes:list<array{i:int,field:string,before:mixed,after:mixed,rule:string}>, warnings:list<array{i:int,msg:string}>}
 */
function syc_normalize_rows(array $rows, array $rules, ?array $active = null): array
{
    $changes = [];
    $warnings = [];
    $set = static function (int $i, array &$r, string $field, mixed $after, string $rule) use (&$changes): void {
        $before = $r[$field] ?? null;
        if ($before === $after) { return; }
        $changes[] = ['i' => $i, 'field' => $field, 'before' => $before, 'after' => $after, 'rule' => $rule];
        $r[$field] = $after;
    };
    $words = $rules['option_words'] ?? [];
    $front = [];   // 행 번호 => 옵션 앞에 붙일 글자(단계 4·5)

    // 1~3: 행마다
    foreach ($rows as $i => &$r) {
        $slug = (string) ($r['_category_slug'] ?? '');
        if (isset($rules['category_merge'][$slug])) { $set($i, $r, '_category_slug', $rules['category_merge'][$slug], 'category_merge'); $slug = $r['_category_slug']; }
        foreach ($rules['category_split'] ?? [] as $fam) {
            if (!isset($fam[$slug])) { continue; }
            $keys = array_values(array_unique(array_merge(...array_values($fam))));
            $have = array_values(array_filter($keys, static fn($k) => ($r['dim_' . $k] ?? null) !== null));
            $hit = null;
            foreach ($fam as $s => $dims) {
                $d = $dims; sort($d); $h = $have; sort($h);
                if ($d === $h) { $hit = $s; break; }
            }
            if ($hit === null) { $warnings[] = ['i' => $i, 'msg' => "치수 칸 구성(" . (implode('·', $have) ?: '없음') . ")에 맞는 카테고리가 없어 분류코드 '{$slug}' 그대로"]; }
            elseif ($hit !== $slug) { $set($i, $r, '_category_slug', $hit, 'category_split'); $slug = $hit; }
            break;
        }
        $opt = trim((string) ($r['spec_option'] ?? ''));
        if ($opt !== '') {
            $keep = [];
            foreach (syc_norm_option_parts($opt) as $part) {
                $done = false;
                foreach ($rules['option_dims'] ?? [] as $od) {
                    if (isset($od['only']) && !in_array($slug, $od['only'], true)) { continue; }
                    if (!preg_match($od['re'], $part, $m)) { continue; }
                    $ok = true;
                    foreach ($od['set'] as $g => $field) {
                        $v = (float) $m[$g];
                        $cur = $r[$field] ?? null;
                        if ($cur !== null && (float) $cur !== $v) { $ok = false; $warnings[] = ['i' => $i, 'msg' => "옵션 '{$part}' 의 치수가 [{$field}] 값({$cur})과 달라 옮기지 않음"]; break; }
                    }
                    if (!$ok) { break; }
                    foreach ($od['set'] as $g => $field) { if (($r[$field] ?? null) === null) { $set($i, $r, $field, (float) $m[$g], 'option_dims'); } }
                    $text = trim(preg_replace_callback('/\$(\d)/', static fn($x) => $m[(int) $x[1]] ?? '', $od['text']));
                    if ($text !== '') { $keep[] = $text; }
                    $done = true;
                    break;
                }
                if (!$done && $part !== '') { $keep[] = $part; }
            }
            $set($i, $r, 'spec_option', $keep ? implode(', ', $keep) : null, 'option_dims');
        }
    }
    unset($r);

    // 4: 모델 코드 — 카테고리 단위(모두 정리할 수 있을 때만)
    $byCat = [];
    foreach ($rows as $i => $r) {
        if (trim((string) ($r['spec_code'] ?? '')) !== '') { $byCat[(string) $r['_category_slug']][] = $i; }
    }
    foreach ($byCat as $idx) {
        $parsed = [];
        foreach ($idx as $i) { $parsed[$i] = syc_norm_code_parse((string) $rows[$i]['spec_code'], $rows[$i], $words); }
        if (in_array(null, $parsed, true)) { continue; }
        foreach ($parsed as $i => $p) {
            if ($p['kind'] === 'keep') { continue; }
            if ($p['kind'] === 'word') { $front[$i][] = $p['value']; }
            $set($i, $rows[$i], 'spec_code', $p['kind'] === 'code' ? $p['value'] : null, 'code');
        }
    }

    // 5: 품명 + 옵션 앞 글자
    foreach ($rows as $i => &$r) {
        $code = trim((string) ($r['spec_code'] ?? ''));
        $optParts = syc_norm_option_parts($r['spec_option'] ?? null);
        $name = trim((string) ($r['name'] ?? ''));
        $tokens = preg_split('/\s+/u', $name) ?: [];
        $kept = [];
        foreach ($tokens as $t) {
            if (in_array($t, $words, true)) { $front[$i][] = $t; continue; }
            if ($code !== '' && ($t === $code || $t . '형' === $code)) { continue; }
            $kept[] = $t;
        }
        $newName = trim(preg_replace('/\s+/u', ' ', implode(' ', $kept)));
        if ($newName === '') { $warnings[] = ['i' => $i, 'msg' => "품명 '{$name}' 이 모두 옵션·형식이라 그대로 둠"]; }
        elseif ($newName !== $name) { $set($i, $r, 'name', $newName, 'name'); }
        if (!empty($front[$i])) {
            $add = array_values(array_filter($words, static fn($w) => in_array($w, $front[$i], true)));   // 규칙 순서
            $merged = array_values(array_unique(array_merge($add, $optParts)));
            $set($i, $r, 'spec_option', implode(', ', $merged), 'option_words');
        }
        if ($active !== null && !($active[(string) $r['_category_slug']] ?? false)) {
            $warnings[] = ['i' => $i, 'msg' => "분류코드 '{$r['_category_slug']}' 는 숨긴 카테고리라 사이트에 보이지 않음"];
        }
    }
    unset($r);
    // 6(4b): 규격 원문 끝 괄호의 예전 코드 — 옵션 정리(5) 뒤에(옵션으로 옮긴 글자 '(무개)' 도 뺄 수 있게)
    foreach ($rows as $i => &$r) {
        $spec = (string) ($r['spec'] ?? '');
        if (!preg_match('/^(.*?)\s*\(([^()]+)\)\s*$/u', $spec, $m) || trim($m[1]) === '') { continue; }
        $x = trim($m[2]);
        if ($x === trim((string) ($r['spec_code'] ?? ''))) { continue; }
        if (substr_count($x, '*') >= 2 || preg_match('/^[\d.*]+T$/', $x)) { continue; }   // '(320*320*2000*60T)' 같은 치수 목록은 코드 아님
        $p = syc_norm_code_parse($x, $r, $words);
        if ($p === null && in_array($x, $words, true) && in_array($x, syc_norm_option_parts($r['spec_option'] ?? null), true)) {
            $p = ['kind' => 'word', 'value' => $x];      // '(무개)' — 옵션 글자이고 옵션에 이미 있음 → 괄호 뺌
        }
        if ($p === null || $p['kind'] === 'keep') { continue; }
        $new = $p['kind'] === 'code' ? $m[1] . ' (' . $p['value'] . ')' : $m[1];
        $set($i, $r, 'spec', $new, 'spec');
    }
    unset($r);

    return ['rows' => $rows, 'changes' => $changes, 'warnings' => $warnings];
}

/**
 * 미리보기·결과 요약: 규칙별 바뀐 곳 수, 바뀐 상품 수, 예시(앞 $limit 곳), 경고(앞 20개).
 * @param list<array> $rows 정리 전 행(상품 이름·출처ID 표시용) @param array $res syc_normalize_rows() 결과
 */
function syc_normalize_summary(array $rows, array $res, int $limit = 5000): array
{
    $byRule = [];
    $changedRows = [];
    $items = [];
    foreach ($res['changes'] as $c) {
        $label = SYC_NORM_RULE_LABELS[$c['rule']] ?? $c['rule'];
        $byRule[$label] = ($byRule[$label] ?? 0) + 1;
        $changedRows[$c['i']] = true;
        if (count($items) < $limit) {
            $items[] = ['id' => (string) ($rows[$c['i']]['external_id'] ?? ''), 'name' => (string) ($rows[$c['i']]['name'] ?? ''),
                        'field' => $c['field'] === '_category_slug' ? 'category_slug' : $c['field'],
                        'before' => $c['before'], 'after' => $c['after'], 'rule' => $label];
        }
    }
    $warn = [];
    foreach (array_slice($res['warnings'], 0, 20) as $w) {
        $warn[] = ($rows[$w['i']]['external_id'] ?? '') . ' ' . ($rows[$w['i']]['name'] ?? '') . ': ' . $w['msg'];
    }
    return ['changed_rows' => count($changedRows), 'changes' => count($res['changes']), 'by_rule' => $byRule,
            'items' => $items, 'warnings' => $warn, 'warning_count' => count($res['warnings'])];
}
