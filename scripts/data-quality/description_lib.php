<?php
declare(strict_types=1);
/**
 * 상품 설명 정비안 (순수 함수) — 운영 상품 설명(products.description)의 문제 문장 표시와, 확인된 실제 데이터로만 조립한 새 설명.
 *
 * 배경(2026-11-05 점검): 운영 상품 설명 691개는 원본 수집 데이터(data/ktomok)에 없는 하중(T-25)·차량 통행 가능/불가·홍보 문구가 대부분이고,
 *       규격과 어긋나는 문장(80T 이상 블록에 '차량 통행 불가' 등)과 근거 없는 용도('공기 순환' 등)가 섞여 있다.
 * 새 설명 재료(모두 확인된 데이터): ① 사용자가 표준 상세 화면에서 확인한 KS 적용 범위(legal-sources/candidates.json, '사용자 확인' 표시분) —
 *       상품명이 그 표준의 대상 제품군일 때만(app/ai_tools.php AI_KS_NAME_RULES) ② 운영자가 쓴 분류 설명(categories.description, 없으면 상위 분류)
 *       ③ 상품 이름·규격에서 읽은 고르는 기준(app/external_products.php ext_selection_guide — 두께·투수·재질·하중 등급·판매 단위).
 *       ①·② 는 생성기 옵션(--with-ks-scope·--with-category-brief)일 때만 — 상품 검색(app/bootstrap.php search_products, AI 상품 검색도 같은 함수)이
 *       description 을 함께 찾으므로, 분류 설명('U형·AN형·벤치플륨관 등')·표준 적용 범위 글이 들어가면 다른 종류 상품까지 검색된다(2026-11-05 측정).
 * 정비 원칙: 문제 문장이 없는 기존 설명은 바꾸지 않는다. 문제 문장이 있으면 그 문장만 빼고, 남은 문장 뒤에 고르는 기준을 붙인다.
 * 금지: 기존 문장을 고쳐 쓰거나 새 사실을 지어내지 않는다(정해진 틀 문장만), 가격·DB 접속 없음(CLI 가 파일만 만든다).
 */

/** 문제 문장 규칙 [키 => [정규식, 사람이 읽는 이유]] — 하나라도 맞으면 그 문장은 새 설명에 쓰지 않는다 */
const DESC_FLAG_RULES = [
    'load'    => ['/T-?\s?\d{2}\b|하중|중량물/u', '하중 표기(T-25 등) — 원본 수집 데이터에 없음'],
    'vehicle' => ['/차량\s*통행|통행이?\s*(?:가능|불가)|통행할\s*수|보행자\s*전용|중장비|대형\s*차량|중량\s*차량/u', '차량 통행 가능·불가 — 원본 수집 데이터에 없음'],
    'promo'   => ['/내구성|뛰어|우수|오랜|수명|자랑|신뢰|보장|최적|효율|안정적|강력|견고|튼튼|친화|기여|효과적|간편|용이|품질|성능|미적|조화|경관|유연성|탁월|넉넉/u', '효과·홍보 표현'],
    'ks'      => ['/KS/u', 'KS 인증·규격 주장 — 상품명 표기 외 근거 없음'],
    'spec'    => ['/중량|\d\s*kg|\bD\d{2,4}|\d+\s*[*x×]\s*\d+|규격|\d{2,4}\s*mm|\d+T\b/iu', '규격·중량 반복 — 등록 규격 칸과 다를 수 있음'],
];

/** 문장 나누기 (순수 함수) — 마침표·물음표·느낌표 뒤 공백 기준 */
function desc_sentences(?string $text): array
{
    $t = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
    if ($t === '') {
        return [];
    }
    return array_values(array_filter(array_map('trim', preg_split('/(?<=[.!?])\s+/u', $t) ?: []), static fn($s) => $s !== ''));
}

/** 한 문장의 문제 표시 키 목록 (순수 함수) */
function desc_sentence_flags(string $sentence): array
{
    $out = [];
    foreach (DESC_FLAG_RULES as $k => [$re]) {
        if (preg_match($re, $sentence) === 1) {
            $out[] = $k;
        }
    }
    return $out;
}

/**
 * 규격(두께)과 충돌하는 차량 문장인지 (순수 함수) — 포장 블록 두께 80mm 이상인데 '통행 불가', 60mm 이하인데 '통행 가능'.
 * 사내 권장 기준(차량이 올라가면 80mm 이상)과 정반대로 안내하는 문장을 따로 센다.
 */
function desc_conflicts_spec(string $sentence, array $product): bool
{
    $type = ext_product_type((string) ($product['name'] ?? ''));
    $paving = in_array($type, ['인조화강블록', '점토블록', '인터로킹블록', '보차도블록', '보도블록', '잔디블록'], true);
    $mm = ai_block_thickness((string) ($product['spec'] ?? ''));
    if (!$paving || $mm === null) {
        return false;
    }
    $no  = preg_match('/통행이?\s*불가|통행이\s*불가능|보행자\s*전용/u', $sentence) === 1;
    $yes = preg_match('/통행이?\s*가능(?!하지)|차량\s*통행이\s*가능/u', $sentence) === 1;
    return ($mm >= 80 && $no) || ($mm <= 60 && $yes && !$no);
}

/** KS 적용 범위 표 [표준번호 => [name, scope]] — candidates.json 의 KS 중 '사용자 확인' 적용 범위가 있는 것만 (순수 함수) */
function desc_ks_scopes(array $candidates): array
{
    $out = [];
    foreach ($candidates as $c) {
        if (($c['type'] ?? '') !== 'KS' || !str_contains((string) ($c['scope_note'] ?? ''), '사용자 확인')) {
            continue;
        }
        $scope = trim((string) preg_replace('/\s*\([^)]*사용자 확인[^)]*\)\s*$/u', '', (string) $c['scope_note']));
        $out[(string) $c['ref_no']] = ['name' => (string) $c['official_name'], 'scope' => $scope];
    }
    return $out;
}

/**
 * 새 설명 (순수 함수). 재료가 하나도 없으면 ''. 문장을 지어 붙이지 않고 항목형(상품 상세 화면은 줄바꿈 그대로 표시):
 *   관련 국가표준: KS F 4403 원심력 철근 콘크리트관 — 적용 범위: …      ← 상품명이 그 표준의 대상 제품군일 때만('관련' — 인증 여부 아님)
 *   분류 안내: …                                                       ← 관련 국가표준이 없을 때 운영자 분류 설명
 *   고르는 기준
 *   - 두께 80mm — 차량이 올라가는 곳 저희 권장 기준(80mm 이상) 충족
 * @param array{id?:int, name:string, spec:string} $product
 * @param array<string,array{name:string,scope:string}> $ksScopes
 */
function desc_proposal(array $product, string $categoryBrief, array $ksScopes): string
{
    $name = trim((string) $product['name']);
    $lines = [];
    $ref = ai_name_ks_ref($name);
    if ($ref !== '' && isset($ksScopes[$ref])) {
        $lines[] = "관련 국가표준: {$ref} {$ksScopes[$ref]['name']} — 적용 범위: {$ksScopes[$ref]['scope']}";
    } elseif ($categoryBrief !== '') {
        $lines[] = '분류 안내: ' . $categoryBrief;
    }
    $guide = array_values(array_filter(ext_selection_guide(['id' => (int) ($product['id'] ?? 0), 'name' => $name, 'spec' => (string) $product['spec']]),
        static fn($g) => !str_starts_with((string) $g, '참고 자료')));
    if ($guide) {
        $lines[] = '고르는 기준';
        foreach ($guide as $g) {
            $lines[] = '- ' . $g;
        }
    }
    return implode("\n", $lines);
}

/**
 * 상품 1개 점검·정비안 (순수 함수).
 * kind: fix(문제 문장을 빼고 남은 기존 문장 + 새 항목) / keep_clean(문제 문장만 뺌 — 새 항목 재료 없음) / clear(남는 문장·재료 없음 → 설명 비움) /
 *       fill(설명이 없던 상품에 새 항목) / none(문제 문장 없는 기존 설명 — 그대로, 또는 설명도 재료도 없음)
 * @return array{kind:string, old:string, new:?string, flags:array<string,int>, conflict:int, flagged:int, sentences:int}
 */
function desc_review(array $product, string $categoryBrief, array $ksScopes): array
{
    $old = trim((string) ($product['description'] ?? ''));
    $sent = desc_sentences($old);
    $flags = [];
    $flagged = 0;
    $conflict = 0;
    $clean = [];
    foreach ($sent as $s) {
        $f = desc_sentence_flags($s);
        if (desc_conflicts_spec($s, $product)) {
            $conflict++;
            $f[] = 'conflict';
        }
        foreach ($f as $k) {
            $flags[$k] = ($flags[$k] ?? 0) + 1;
        }
        if ($f) {
            $flagged++;
        } else {
            $clean[] = $s;
        }
    }
    $proposal = desc_proposal($product, $categoryBrief, $ksScopes);
    if ($old === '') {
        $kind = $proposal !== '' ? 'fill' : 'none';
        $new = $proposal !== '' ? $proposal : null;
    } elseif ($flagged === 0) {
        $kind = 'none';   // 문제 문장이 없는 기존 설명은 그대로 둔다(운영자가 쓴 설명을 새 항목으로 덮어쓰지 않음)
        $new = null;
    } else {
        $keep = implode(' ', $clean);   // 문제 문장만 빼고 나머지 기존 문장은 순서 그대로
        $new = $keep . ($keep !== '' && $proposal !== '' ? "\n" : '') . $proposal;
        $kind = $proposal !== '' ? 'fix' : ($keep !== '' ? 'keep_clean' : 'clear');
        $new = $new !== '' ? $new : null;
    }
    return ['kind' => $kind, 'old' => $old, 'new' => $new, 'flags' => $flags, 'conflict' => $conflict, 'flagged' => $flagged, 'sentences' => count($sent)];
}

/** SQL 문자열 (순수 함수) — NULL 은 NULL */
function desc_sql_q(?string $s): string
{
    return $s === null ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
}

/**
 * 적용 SQL 1문 (순수 함수) — 정비안을 만든 시점의 설명과 같을 때만 바꾼다(그 사이 누가 고쳤으면 0행 — 덮어쓰지 않음).
 * description 칸만 바꾼다(가격 칸 없음).
 */
function desc_update_sql(int $id, string $old, ?string $new): string
{
    return 'UPDATE products SET description = ' . desc_sql_q($new) . " WHERE id = {$id} AND MD5(COALESCE(description, '')) = '" . md5($old) . "';";
}

/** 되돌리기 SQL 1문 (순수 함수) — 정비안이 그대로 들어가 있을 때만 이전 설명으로 */
function desc_revert_sql(int $id, string $old, ?string $new): string
{
    return 'UPDATE products SET description = ' . desc_sql_q($old === '' ? null : $old) . " WHERE id = {$id} AND MD5(COALESCE(description, '')) = '" . md5((string) $new) . "';";
}
