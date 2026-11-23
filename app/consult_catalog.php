<?php
declare(strict_types=1);
/**
 * AI 상담 — 업체 공통 '현재 등록 상품' 안내 (상담 가이드 A안, 사용자 결정 2026-11-17)
 *
 * 책임: 상담 품목(rag_detect_category 의 키)에 맞는 공통 분류 품명을 판매 중 상품(products — 모든 업체, S20)에서 읽어
 *       시스템 프롬프트에 '지금 무엇을 파는지'를 상담할 때마다 자동으로 넣는다(품명·상품 수·업체 수·하중 표기 현황).
 *       '무엇을 물어야 하는지'(업체 중립 확인 항목)는 rag_build_prompt 의 [카테고리별 필수 확인 항목]이 맡는다.
 * 금지: 상품명·업체명을 고정 문장으로 넣지 않는다(업체가 늘어도 고칠 문장이 없게). 가격을 읽거나 넣지 않는다. DB 쓰기 없음.
 * 기존 category_consult_guide 행(케이토목 상품명 기준 고정 문장)은 지우지 않고 읽지 않는다(되돌리기: rag_build_prompt 에서 다시 읽게).
 * 근거: docs/03-analysis/consult-guide-vendor-neutral-2026-11-17.md
 */

/**
 * 상담 품목 → 공통 분류 품명(categories.name)을 고르는 규칙 — [포함 정규식, 제외 정규식, 화면 이름].
 * 품목 키는 rag_detect_category() 와 같다. 이름 규칙은 자재 종류의 일반 명칭만 쓴다(특정 업체 상품명 아님).
 */
const CONSULT_KIND_CATEGORY_RULES = [
    'grating'  => ['/그레이팅|트렌치|주철|뚜껑|덮개/u', '/몰드|집게|낙엽|분기구|소켓/u', '그레이팅·덮개류'],
    'pe_pipe'  => ['/배수관|이중벽관|다중벽관|흄관|VR관|레진관|PVC관|파형강관|주름관|유공관|수도관|직관|칼라관|하수관|우수관/u', '/소켓|분기구|몰드|수로관|캡|받이/u', '배수관·관류'],
    'manhole'  => ['/맨홀|집수정|받이|우수통|노바시/u', '/몰드|뚜껑|그레이팅|사다리/u', '맨홀·집수정'],
    'culvert'  => ['/수로관|플륨관|측구|암거|개거|생태탈출로|배수로/u', '/몰드|집게|뚜껑|그레이팅|집수정|분기구/u', '수로관·암거'],
    'pavement' => ['/블[록럭]|경계석|화강경계|판석|점토/u', '/집게|축조|보강토|옹벽|호안|속빈|벽돌|빗물받이|큐블록|디자인블록/u', '보도블록·경계석'],
];

/** 프롬프트에 넣는 품명 수 상한(상품 많은 순) — 프롬프트 길이 관리 */
const CONSULT_CATALOG_MAX_NAMES = 25;

/** 품명이 이 상담 품목에 드는지 (순수 함수) */
function consult_kind_matches(string $kindKey, string $categoryName): bool
{
    $rule = CONSULT_KIND_CATEGORY_RULES[$kindKey] ?? null;
    if ($rule === null) { return false; }
    return preg_match($rule[0], $categoryName) === 1 && preg_match($rule[1], $categoryName) !== 1;
}

/**
 * 상담 품목의 판매 중 상품 행 (DB) — 품명(분류)·상위 분류 이름·상품명·규격·출처(업체). 가격 칸은 읽지 않는다.
 * 조회 실패(DB 없음·칸 없음)면 null → 프롬프트에 이 구역을 넣지 않는다(기존 고정 안내만으로 동작).
 * @return list<array{category_id:int, category_name:string, parent_name:string, name:string, spec:string, external_source:?string}>|null
 */
function consult_catalog_rows(string $kindKey): ?array
{
    if (!isset(CONSULT_KIND_CATEGORY_RULES[$kindKey])) { return null; }
    return consult_catalog_rows_where(static fn(string $name): bool => consult_kind_matches($kindKey, $name));
}

/**
 * 품명 조건($match: 품명 → 포함 여부)에 드는 판매 중 상품 행 (DB). 가격 칸은 읽지 않는다. 조회 실패면 null.
 * $names 를 주면 품명 목록(활성 분류 이름)을 DB 대신 그것으로 고른다(호출 쪽에서 이미 읽은 경우).
 */
function consult_catalog_rows_where(callable $match, ?array $names = null): ?array
{
    try {
        $pdo = db();
        $ids = [];
        foreach ($names ?? consult_active_category_names() ?? [] as $id => $name) {
            if ($match((string) $name)) { $ids[] = (int) $id; }
        }
        if ($ids === []) { return []; }
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare(
            "SELECT p.category_id, c.name AS category_name, COALESCE(pc.name, '') AS parent_name,
                    p.name, COALESCE(p.spec, '') AS spec, p.external_source
               FROM products p
               JOIN categories c ON c.id = p.category_id
               LEFT JOIN categories pc ON pc.id = c.parent_id
              WHERE p.is_active = 1 AND p.category_id IN ({$ph})
              ORDER BY p.category_id, p.id"
        );
        $st->execute($ids);
        return array_map(static fn(array $r): array => [
            'category_id'     => (int) $r['category_id'],
            'category_name'   => (string) $r['category_name'],
            'parent_name'     => (string) $r['parent_name'],
            'name'            => (string) $r['name'],
            'spec'            => (string) $r['spec'],
            'external_source' => $r['external_source'] === null ? null : (string) $r['external_source'],
        ], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (\Throwable $e) {
        return null;
    }
}

/** 활성 분류 id → 이름 (DB), 실패면 null */
function consult_active_category_names(): ?array
{
    try {
        $out = [];
        foreach (db()->query('SELECT id, name FROM categories WHERE is_active = 1') as $c) { $out[(int) $c['id']] = (string) $c['name']; }
        return $out;
    } catch (\Throwable $e) {
        return null;
    }
}

/** 기타 자재: 질문 단어로 품명을 고를 때 버리는 흔한 말 (자재 이름이 아님) */
const CONSULT_OTHER_STOPWORDS = ['설치', '추천', '가격', '제품', '자재', '문의', '견적', '구매', '주문', '사용', '시공', '얼마', '어떤', '어디', '규격', '사이즈',
    '크기', '종류', '필요', '가능', '정도', '현장', '공사', '알려', '주세요', '해주세요', '있나요', '있어요', '싶어요', '하려고', '하는데', '건데', '때문'];
/** 한 단어가 이보다 많은 품명에 걸리면(너무 넓은 말 — 블록·콘크리트 등) 그 단어로는 고르지 않는다 */
const CONSULT_OTHER_MAX_NAMES_PER_WORD = 12;

/** 품명의 핵심 이름 (순수 함수) — 괄호 부분을 뺀 이름. '스텐볼라드(HK)' → '스텐볼라드' */
function consult_name_core(string $name): string
{
    return trim((string) preg_replace('/\(.*?\)|（.*?）/u', '', $name));
}

/** 질문 글의 단어 (순수 함수) — 2글자 이상, 끝의 조사 떼고, 숫자로 시작하는 말(2개·300mm)·흔한 말 뺌 */
function consult_text_words(string $text): array
{
    $words = [];
    foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($text)) ?: [] as $w) {
        $w = (string) preg_replace('/(으로|에서|이랑|에는|이나|은|는|이|가|을|를|에|의|도|로|와|과|랑|만)$/u', '', $w);
        if (mb_strlen($w) < 2 || preg_match('/^\d/u', $w) || in_array($w, CONSULT_OTHER_STOPWORDS, true)) { continue; }
        $words[$w] = true;
    }
    return array_keys($words);
}

/**
 * 기타 자재 상담(5개 품목 밖)에서 질문에 나온 자재의 품명 고르기 (순수 함수) — $names: id → 품명.
 * 질문 글에 품명 핵심 이름이 그대로 있거나, 질문 단어가 품명 핵심 이름 안에 있으면 고른다('볼라드' → 스텐볼라드·탄성볼라드…).
 * 너무 넓은 단어(품명 CONSULT_OTHER_MAX_NAMES_PER_WORD 개 초과)로는 고르지 않는다. @return list<int> 분류 id
 */
function consult_other_pick(string $text, array $names): array
{
    $lower = mb_strtolower($text);
    $picked = [];
    foreach ($names as $id => $name) {
        $core = mb_strtolower(consult_name_core((string) $name));
        if (mb_strlen($core) >= 2 && mb_strpos($lower, $core) !== false) { $picked[(int) $id] = true; }
    }
    foreach (consult_text_words($text) as $w) {
        $hit = [];
        foreach ($names as $id => $name) {
            if (mb_strpos(mb_strtolower(consult_name_core((string) $name)), $w) !== false) { $hit[] = (int) $id; }
        }
        if ($hit !== [] && count($hit) <= CONSULT_OTHER_MAX_NAMES_PER_WORD) {
            foreach ($hit as $id) { $picked[$id] = true; }
        }
    }
    return array_keys($picked);
}

/** 상담의 고객 글 모음 (순수 함수) — rag_detect_category 와 같이 고객 메시지만 */
function consult_user_text(array $messages): string
{
    $t = '';
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'user') { $t .= ($m['content'] ?? '') . ' '; }
    }
    return $t;
}

/**
 * 기타 자재(5개 품목 밖)의 '현재 등록 상품' 구역 (DB) — 질문에 나온 자재의 품명만. 고른 품명이 없거나 조회 실패면 ''.
 */
function consult_other_catalog_section(array $messages): string
{
    $names = consult_active_category_names();
    if ($names === null || $names === []) { return ''; }
    $ids = array_flip(consult_other_pick(consult_user_text($messages), $names));
    if ($ids === []) { return ''; }
    $rows = consult_catalog_rows_where(static fn(string $n): bool => true, array_intersect_key($names, $ids));
    if ($rows === null || $rows === []) { return ''; }
    return consult_catalog_prompt('other', consult_catalog_summary($rows));
}

/**
 * 품명별 요약 (순수 함수) — 상품 수·업체 수(출처 종류)·하중 표기 현황(덮개·배수로류만, ai_load_class_of 와 같은 판정).
 * 상품 많은 순, 같으면 품명 순.
 * @return list<array{name:string, parent:string, count:int, vendors:int, load:array<string,int>, load_relevant:bool}>
 */
function consult_catalog_summary(array $rows): array
{
    $by = [];
    foreach ($rows as $r) {
        $k = (int) $r['category_id'];
        $by[$k] ??= ['name' => (string) $r['category_name'], 'parent' => (string) $r['parent_name'], 'count' => 0,
                     'sources' => [], 'load' => ['truck' => 0, 'car' => 0, 'pedestrian' => 0, 'none' => 0], 'load_relevant' => false];
        $by[$k]['count']++;
        $by[$k]['sources'][(string) ($r['external_source'] ?? '')] = true;
        $row = ['name' => (string) $r['name'], 'spec' => (string) $r['spec'], 'category_name' => (string) $r['category_name']]
             + (array_key_exists('external_source', $r) ? ['external_source' => $r['external_source']] : []);
        if (ai_load_relevant(ai_load_name($row))) {
            $by[$k]['load_relevant'] = true;
            $cls = ai_load_class_of($row);
            $by[$k]['load'][$cls !== '' ? $cls : 'none']++;
        }
    }
    $out = [];
    foreach ($by as $g) {
        $out[] = ['name' => $g['name'], 'parent' => $g['parent'], 'count' => $g['count'], 'vendors' => count($g['sources']),
                  'load' => $g['load'], 'load_relevant' => $g['load_relevant']];
    }
    usort($out, static fn($a, $b) => [$b['count'], $a['name']] <=> [$a['count'], $b['name']]);
    return $out;
}

/** 하중 표기 현황 한 줄 (순수 함수) — '대형차용 3 · 승용차용 10 · 표기 없음 2', 대상이 아니면 '' */
function consult_load_label(array $g): string
{
    if (!$g['load_relevant']) { return ''; }
    $parts = [];
    foreach (['truck', 'car', 'pedestrian'] as $c) {
        if ($g['load'][$c] > 0) { $parts[] = AI_TRAFFIC_CLASS_LABEL[$c] . ' ' . $g['load'][$c]; }
    }
    if ($g['load']['none'] > 0) { $parts[] = '하중 표기 없음 ' . $g['load']['none']; }
    return implode(' · ', $parts);
}

/**
 * '현재 등록 상품' 프롬프트 구역 (순수 함수). $summary 가 null(조회 실패)이면 ''(구역 없음), 빈 배열이면 '등록 상품 없음' 안내.
 */
function consult_catalog_prompt(string $kindKey, ?array $summary): string
{
    if ($summary === null || ($kindKey !== 'other' && !isset(CONSULT_KIND_CATEGORY_RULES[$kindKey]))) { return ''; }
    $label = $kindKey === 'other' ? '질문한 자재' : CONSULT_KIND_CATEGORY_RULES[$kindKey][2];
    $head = "\n\n=== 현재 등록 상품: {$label} (판매 중 상품에서 자동으로 만든 목록 — 모든 업체 공통) ===\n";
    if ($summary === []) {
        return $head
            . "- 지금 사이트에 판매 중인 {$label} 상품이 없다. 상품을 찾았다고 말하거나 종류를 권하지 않고, 필요한 규격·수량을 정리해 견적요청하기로 안내한다.\n";
    }
    $lines = [];
    $hasNoLoad = false;
    foreach (array_slice($summary, 0, CONSULT_CATALOG_MAX_NAMES) as $g) {
        $where = $g['parent'] !== '' ? "({$g['parent']})" : '';
        $line = "- {$g['name']}{$where}: 상품 {$g['count']}개 · 업체 {$g['vendors']}곳";
        $load = consult_load_label($g);
        if ($load !== '') {
            $line .= " · 하중: {$load}";
            $hasNoLoad = $hasNoLoad || $g['load']['none'] > 0;
        }
        $lines[] = $line;
    }
    $more = count($summary) > CONSULT_CATALOG_MAX_NAMES ? "- 그 밖 " . (count($summary) - CONSULT_CATALOG_MAX_NAMES) . "개 품명(상품 적은 순)은 생략\n" : '';
    return $head
        . implode("\n", $lines) . "\n" . $more
        . "이 목록을 쓰는 규칙:\n"
        . "- search_products 검색어(query)는 위 품명(괄호 앞 이름)이나 그 일반 명칭으로 쓴다. 고객이 찾는 품명·재질이 위에 없으면 있다고 말하거나 권하지 말고"
        . ($more !== '' || $kindKey === 'other' ? " search_products 로 한 번 확인한 뒤(" . ($kindKey === 'other' ? '이 목록은 질문에 나온 말로 고른 것이라 빠진 품명이 있음' : '생략한 품명이 있음') . "), 결과가 없으면" : '')
        . " 사이트에 아직 등록되지 않았다고 알리고 견적요청하기로 안내한다.\n"
        . "- 하중 등급은 상품명·규격에 표기된 것만 근거다(위 '하중' 칸, 검색 결과의 load_check·load_grade). 등급 이름이나 톤수(예: T-25)를 지어내지 않는다.\n"
        . ($hasNoLoad ? "- '하중 표기 없음' 상품은 차량 통행에 적합하다고 말하지 않는다. 차량이 다니면 '하중 확인 필요'라고 알리고 견적 요청 때 확인한다고 안내한다.\n" : '')
        . "- 상품 수·업체 수는 고객이 물을 때만 말한다(업체 이름은 말하지 않는다).\n";
}

/** 상담 품목의 '현재 등록 상품' 구역 (DB) — rag_build_prompt 에서 호출(기타 자재는 consult_other_catalog_section) */
function consult_catalog_section(string $kindKey): string
{
    if ($kindKey === '') { return ''; }
    $rows = consult_catalog_rows($kindKey);
    return consult_catalog_prompt($kindKey, $rows === null ? null : consult_catalog_summary($rows));
}
