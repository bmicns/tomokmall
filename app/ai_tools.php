<?php
declare(strict_types=1);
/**
 * AI Tool Use 정의 및 핸들러
 *
 * - ai_tool_definitions() : Claude API tools 배열 반환
 * - ai_tool_dispatch()    : tool 이름으로 핸들러 라우팅
 * - tool_*()              : 개별 Tool 실행 함수 (JSON 문자열 반환)
 *
 * 반환 규칙:
 *   각 핸들러는 JSON 문자열을 반환한다.
 *   SSE 사이드 이펙트는 '_sse' 키 아래 이벤트 배열로 포함한다.
 *   예) {"ok":true, "_sse":[{"type":"cart_update","count":2,"total":24000}]}
 *   rag_stream_with_tools() 가 _sse 를 소비해 SSE 전송 후 Claude 에는 제거된 결과를 전달한다.
 */

// ─── Tool 스펙 ────────────────────────────────────────────────────────────────

/**
 * Claude API 에 전달할 tools 배열 반환
 *
 * @return list<array{name:string,description:string,input_schema:array}>
 */
function ai_tool_definitions(): array
{
    return [
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'search_products',
                'description' => '데이터베이스에서 건설자재를 검색합니다. ★중요: query에는 "제품명/카테고리/용도" 텍스트만 넣고 숫자(치수·길이·수량)는 넣지 마세요. 폭은 width_mm, 제품 1개의 길이 치수는 product_length_mm, 그 밖의 규격 치수(관경·포장 블록 두께 등)는 spec_mm, 설치 총 길이(연장, m)는 length_m, 면적(㎡)은 area_m2 로 분리해 전달하세요. 예: 폭 500mm 수로용 그레이팅 100m → query "그레이팅 수로용 중하중", width_mm 500, length_m 100. 폭·길이는 상품마다 다른 규격 체계(이름표)로 확인되고 제품별 소요 수량이 자동 계산됩니다.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query'    => ['type' => 'string',  'description' => '검색 텍스트: 카테고리/제품명 + 용도/조건(중하중·수로용·우수 등). 숫자(치수·길이·수량)는 절대 넣지 말 것. 예: "그레이팅 수로용 중하중", "이중벽관 하수", "경계석 화강암".'],
                        'queries'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '여러 종류를 한 표로 비교할 때만 사용(2~4개). 예: ["인터로킹 블록", "인조화강블록", "점토블럭"]. 각 종류별 대표 제품이 한 표에 모이고 같은 면적/길이 기준 소요 수량·예상 금액이 함께 계산됩니다. 이때 query에는 대표 품목명을 넣으세요.'],
                        'limit'    => ['type' => 'integer', 'description' => '최대 결과 수 (기본 5, 최대 10)'],
                        'spec_mm'  => ['type' => 'number',  'description' => '폭·길이가 아닌 규격 치수(관경·포장 블록 두께 등, mm). 예: 관경 300mm → 300, 차량용 블록 두께 80. 폭은 width_mm, 제품 길이는 product_length_mm 로.'],
                        'width_mm' => ['type' => 'number',  'description' => '제품의 폭(너비·가로, mm). 예: 폭 50cm → 500. 상품 규격 이름표의 폭 칸으로 확인하며 숫자 위치로 짐작하지 않습니다(폭 400×길이 500 제품은 폭 500 이 아님).'],
                        'product_length_mm' => ['type' => 'number', 'description' => '제품 1개의 길이 치수(mm). 예: 길이 1m 그레이팅 → 1000. 설치 총 길이(연장 10m 등)는 length_m 로.'],
                        'length_m' => ['type' => 'number',  'description' => '설치 총 길이/연장(미터). 예: 100m 수로 → 100. 선형 제품의 소요 수량 자동 계산에 사용(제품 1개 길이는 product_length_mm).'],
                        'area_m2'  => ['type' => 'number',  'description' => '설치 면적(㎡). 예: 보도블록 200㎡ → 200. 면적 단위(㎡) 제품의 소요 수량 계산에 사용.'],
                        'spec_strict' => ['type' => 'boolean', 'description' => 'spec_mm이 반드시 지켜야 하는 조건이면 true(예: 차량이 올라가는 곳의 블록 두께 80). 맞는 규격이 없으면 다른 규격을 보여주지 않습니다. queries 비교에서는 항상 적용됩니다.'],
                        'traffic'  => ['type' => 'string', 'enum' => ['pedestrian', 'car', 'truck'], 'description' => '그레이팅(수로·맨홀 덮개) 검색의 통행 조건. 사람만 다님(식당 주방·화장실·실내·베란다·인도·공원·보행자 전용) → pedestrian, 승용차·SUV → car, 트럭·버스·지게차 등 대형차 → truck. 조건보다 낮은 하중 등급(안전 부족)과 높은 등급(과잉)은 표에서 빠집니다.'],
                        'exclude_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => "고객이 '다른 제품', 'A 말고', '다시 추천', '앞에 추천한 제품은 제외'처럼 이미 본 제품이 아닌 다른 제품을 원할 때 뺄 상품 id(직전 추천표의 id). 같은 상품명의 다른 규격도 함께 빠집니다."],
                        'max_est_cost' => ['type' => 'integer', 'description' => "고객이 '좀 더 저렴한 제품'을 원할 때 직전 추천표의 가장 낮은 예상 자재비(원). 같은 설치 길이·면적으로 계산한 예상 자재비가 이보다 낮은 제품만 나옵니다(수량을 계산할 수 없는 제품은 비교하지 않음)."],
                        'max_unit_price' => ['type' => 'integer', 'description' => "설치 길이·면적이 아직 없어 예상 자재비가 없을 때의 '더 저렴한 제품' 기준 — 직전 추천표의 가장 낮은 단가(원). price_unit 과 같은 판매 단위 제품만 비교합니다."],
                        'price_unit' => ['type' => 'string', 'description' => 'max_unit_price 의 판매 단위(개·M·본 등)'],
                        'only_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '직전 추천표 제품만 다시 확인할 때의 상품 id — 설치 길이·면적이 정해져 수량을 다시 계산할 때, 최종 비교할 때. 검색어로 다시 찾지 않고 그 제품을 지금 조건(폭·길이·하중)으로 다시 확인해 맞는 것만 표에 보입니다.'],
                        'recheck_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => '조건(차량 통행 등)이 바뀌었을 때 앞서 추천한 제품이 새 조건에 맞는지 함께 확인할 상품 id. 결과 recheck 에 제품별 판정과 이유가 옵니다.'],
                    ],
                    'required' => ['query'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'add_to_cart',
                'description' => '고객이 선택한 상품을 견적서에 담습니다.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'product_id' => ['type' => 'integer', 'description' => '상품 ID'],
                        'quantity'   => ['type' => 'integer', 'description' => '수량 (기본 1)'],
                    ],
                    'required' => ['product_id'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'get_cart',
                'description' => '현재 견적서에 담긴 상품 목록과 합계를 조회합니다.',
                'parameters'  => ['type' => 'object', 'properties' => new \stdClass()],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'create_estimate',
                'description' => '견적서에 담긴 상품으로 견적을 생성합니다. 고객 정보(성함, 연락처)가 모두 수집된 경우에만 호출합니다.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'customer_name' => ['type' => 'string'],
                        'phone'         => ['type' => 'string'],
                        'email'         => ['type' => 'string'],
                        'company_name'  => ['type' => 'string'],
                        'site_address'  => ['type' => 'string'],
                        'memo'          => ['type' => 'string'],
                    ],
                    'required' => ['customer_name', 'phone'],
                ],
            ],
        ],
        [
            'type'     => 'function',
            'function' => [
                'name'        => 'material_info',
                'description' => '자재 종류의 차이·특징·용도·물빠짐(투수)·주의사항을 설명하거나, 사용 조건(유모차·휠체어·미끄럼·오염 등)에 맞는 자재를 고를 때 쓰는 참고 자료 조회. 제조사 공개 자료 중 사람이 승인한 원문 문장만 출처와 함께 돌려준다. 판매 상품·가격이 아니므로 상품 추천·가격은 search_products 로.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'topic' => ['type' => 'string', 'description' => "자재 종류 + 고객이 말한 사용 조건·특성 키워드. 예: '인터로킹 블록', '투수블록', '스틸 그레이팅 유모차 휠체어', '그레이팅 미끄럼'"],
                    ],
                    'required' => ['topic'],
                ],
            ],
        ],
    ];
}

// ─── 디스패처 ─────────────────────────────────────────────────────────────────

/**
 * Tool 이름으로 핸들러 라우팅.
 * Claude 가 반환한 input 도 서버에서 재검증 후 실행한다.
 *
 * @return string JSON 문자열
 */
function ai_tool_dispatch(string $toolName, array $input, string $sid): string
{
    return match ($toolName) {
        'search_products' => tool_search_products($input),
        'add_to_cart'     => tool_add_to_cart($input, $sid),
        'get_cart'        => tool_get_cart($sid),
        'create_estimate' => tool_create_estimate($input, $sid),
        'material_info'   => tool_material_info($input),
        default           => json_encode(['error' => '알 수 없는 도구입니다: ' . htmlspecialchars($toolName, ENT_QUOTES, 'UTF-8')]),
    };
}

// ─── 개별 Tool 핸들러 ────────────────────────────────────────────────────────

/** 자재 참고 자료(승인된 제조사 공개 자료 원문 + 출처). 상담 화면에는 출처 목록 카드(material_sources) */
function tool_material_info(array $input): string
{
    $topic = trim((string) ($input['topic'] ?? ''));
    $info = ext_material_info($topic);
    if ($info['sources']) {
        $info['_sse'] = [['type' => 'material_sources', 'sources' => $info['sources']]];
    }
    unset($info['sources']);
    // 승인 제조사 자료가 없는 질문 주제 기록(화면 표시 없음 — 상담 기록 chat_logs 에만 남아 다음 자료 확보 우선순위에 씀)
    $gap = ai_material_gap_event($topic, (bool) $info['facts']);
    if ($gap !== null) { $info['_sse'][] = $gap; }
    if ($info['facts']) {   // 제품별로 묶어 준다 — 한 목록에 섞여 오면 GPT 가 다른 제품 문장을 옮겨 말함(실제 GPT-4o 1번: 안전 중하중의 '작은 바퀴'를 중하중에)
        $by = [];
        foreach ($info['facts'] as $f) {
            $k = $f['about'] . '|' . $f['maker'];
            $by[$k] ??= ['product' => $f['about'], 'maker' => $f['maker'], 'statements' => []];
            $by[$k]['statements'][] = '[' . $f['kind'] . '] ' . $f['text'];
        }
        $info['by_product'] = array_values($by);
        $info['note'] .= ' by_product 는 같은 문장을 제품별로 묶은 것이다. 답할 때도 제품별로 나눠 쓰고, 고객이 묻지 않은 제품(예: 안전 중하중 그레이팅)의 문장은 그 제품 이름을 밝혀 따로 한 줄로만 쓴다.';
    }
    // 확인된 기본 정보(국가표준 번호·이름, 토목몰 등록 규격) — 제조사 자료가 없을 때 일반 지식으로 사양을 지어내지 않게 실제 근거를 준다
    $basics = ai_material_basics($topic) + ['procurement' => ai_procurement_summary($topic)];
    if ($basics['standards'] || $basics['products'] || $basics['procurement']) {
        $info['basics'] = $basics;
        $info['basics_note'] = 'basics 는 확인된 사실만 담았다: standards = 국가표준 목록에서 확인한 표준 번호·이름(요구 수치·조항은 미확인 — 수치를 말하지 않음), '
            . 'products = 토목몰에 등록된 상품 이름과 규격 표기 그대로. 제품 설명은 이 둘에서 바로 읽히는 것(종류 이름·등록 규격·중량·판매 단위)만 말한다. '
            . '국가표준은 \'KS F 4010(철근 콘크리트 플룸 및 벤치 플룸) 표준의 대상 종류\'처럼만 말하고 \'이 표준에 따라 제조된다\'·\'이 번호 제품을 고르면 된다\'고 단정하지 않는다(개별 상품의 KS 인증 여부는 상품 표기·제조사 자료로 확인). '
            . '규격은 표기 그대로(예: 200*150*2000*35T) 쓰고 각 숫자가 폭·높이·길이 중 무엇인지, 단위(cm·mm)를 풀어 쓰지 않는다. 구조(개거·폐쇄형 등)·성능·용도는 이름에 없으면 덧붙이지 않는다. 출처는 \'국가표준 목록\'·\'토목몰 등록 상품\'으로 밝힌다. '
            . AI_PROCUREMENT_NOTE;
    }
    return json_encode($info, JSON_UNESCAPED_UNICODE);
}

/** 자료 공백 이벤트 (순수 함수) — 제조사 자료가 없을 때만 {type: material_gap, topic}. 주제는 60자까지, 전화번호·이메일 모양은 지움 */
function ai_material_gap_event(string $topic, bool $hasFacts): ?array
{
    $t = trim((string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+|\d{2,4}[-.\s]?\d{3,4}[-.\s]?\d{4}/u', '', $topic));
    return ($hasFacts || $t === '') ? null : ['type' => 'material_gap', 'topic' => mb_substr($t, 0, 60)];
}

/** 자재 이름 표기 통일 (순수 함수) — 블럭→블록, 플룸·플럼→플륨, 공백 제거 후 비교 */
function ai_material_norm(string $s): string
{
    return mb_strtolower(str_replace([' ', '블럭', '플룸', '플럼'], ['', '블록', '플륨', '플륨'], $s));
}

/** 주제 낱말 (순수 함수) — 2글자 이상, 질문 일반어 제외, 표기 통일 */
function ai_material_words(string $topic): array
{
    $ws = preg_split('/[\s,·\/]+/u', $topic) ?: [];
    $ws = array_filter($ws, static fn($w) => mb_strlen($w) >= 2 && !preg_match('/^(차이|종류|어떤|무엇|추천|좋은|제품|자재|설명|용도|특징|규격|가격|사용|시공)$/u', $w));
    return array_values(array_unique(array_map('ai_material_norm', $ws)));
}

/**
 * 이름·태그와 주제의 일치 점수 (순수 함수) — 주제 낱말이 이름에 있거나, 이름·태그 조각(쉼표·괄호로 나눈 3글자 이상)이 주제에 있으면 하나당 1점.
 * 예: 주제 '벤치플룸관' ↔ 태그 '벤치플륨'(표기 통일 후 주제에 포함) → 1점.
 */
function ai_material_match(string $nameAndTags, string $topic, bool $specificOnly = false): int
{
    $hay = ai_material_norm($nameAndTags);
    $t = ai_material_norm($topic);
    $words = ai_material_words($topic);
    if ($specificOnly) { $words = array_values(array_diff($words, AI_MATERIAL_GENERIC)); }
    $score = count(array_filter($words, static fn($w) => mb_strpos($hay, $w) !== false));
    foreach (preg_split('/[,()（）]+/u', $hay) ?: [] as $piece) {
        if (mb_strlen($piece) >= 3 && mb_strpos($t, $piece) !== false && !in_array($piece, $words, true)
            && !($specificOnly && in_array($piece, AI_MATERIAL_GENERIC, true))) { $score++; }
    }
    return $score;
}

/** 종류만 나타내는 넓은 낱말 — 주제에 더 구체적인 낱말(벤치플륨관·인터로킹 등)이 있으면 이것만 맞는 항목은 뺀다 */
const AI_MATERIAL_GENERIC = ['블록', '보도블록', '수로관', '관', '그레이팅', '맨홀', '경계석', '배수관', '하수관', '덮개', '포장'];

/** 후보 중 구체적 낱말이 맞는 것이 있으면 그것만 (순수 함수). $items: [[이름·태그, 값], …] → 값 목록(점수 순) */
function ai_material_pick(array $items, string $topic): array
{
    $hasSpecific = (bool) array_diff(ai_material_words($topic), AI_MATERIAL_GENERIC);
    $scored = [];
    foreach ($items as $i => [$hay, $val]) {
        $all = ai_material_match($hay, $topic);
        if ($all === 0) { continue; }
        $spec = $hasSpecific ? ai_material_match($hay, $topic, true) : 0;
        $scored[] = [$spec, $all, $i, $val];
    }
    if ($hasSpecific && array_filter($scored, static fn($x) => $x[0] > 0)) {
        $scored = array_values(array_filter($scored, static fn($x) => $x[0] > 0));
    }
    usort($scored, static fn($a, $b) => [$b[0], $b[1], $a[2]] <=> [$a[0], $a[1], $b[2]]);
    return array_map(static fn($x) => $x[3], $scored);
}

/** 규격 문자열에서 규격·중량·단위만 (순수 함수) — 운반비·설치비 예상 같은 부가 칸은 뺀다 */
function ai_spec_brief(string $spec): string
{
    $keep = array_filter(array_map('trim', explode('|', $spec)), static fn($p) => preg_match('/^(규격|중량|단위)\s*:/u', $p) === 1);
    return $keep ? implode(' · ', $keep) : mb_substr(trim($spec), 0, 60);
}

/**
 * 확인된 KS 적용 범위 (순수 함수) — applies_to(=candidates.json scope_note) 끝의 확인 표시 괄호가 있을 때만 그 앞 문구, 없으면 null.
 * 확인 표시: '사용자 확인'(사용자가 e나라표준인증·KSSN 상세 화면 캡처) 또는 '공식 상세 확인'(e나라표준인증 상세 페이지를 직접 열람, 2026-11-05~).
 * 검색 요약에서 온 적용 범위는 표시가 없으므로 쓰지 않는다.
 */
function ai_ks_confirmed_scope(string $appliesTo): ?string
{
    if (!preg_match('/(사용자 확인|공식 상세 확인)/u', $appliesTo)) {
        return null;
    }
    $scope = trim((string) preg_replace('/\s*\([^)]*(?:사용자 확인|공식 상세 확인)[^)]*\)\s*$/u', '', $appliesTo));
    $scope = trim((string) preg_replace('/\s*\(공식 표준명:[^)]*\)/u', '', $scope));   // 공식 표준명 표시는 이름으로 쓰고(ai_ks_display_name) 적용 범위 문구에서는 뺀다
    return $scope !== '' ? $scope : null;
}

/**
 * 상담에 보이는 KS 표준명 (순수 함수) — 등록 제목에서 온 이름이 옛 이름·검색 요약 이름일 수 있어, 적용 범위에 확인 표시와 함께
 * '(공식 표준명: …)'이 있으면 그 이름을 쓴다(예: KS M 3401 '수도용 …' → '압력용 경질 폴리염화비닐관'). 운영 행 제목은 바꾸지 않는다(title 로 맞추므로).
 */
function ai_ks_display_name(string $name, string $appliesTo): string
{
    if (ai_ks_confirmed_scope($appliesTo) !== null && preg_match('/\(공식 표준명:\s*([^)—]+?)\s*(?:—[^)]*)?\)/u', $appliesTo, $m)) {
        return trim($m[1]);
    }
    return $name;
}

/** 공공조달 등록 현황(basics.procurement)을 답에 쓰는 규칙 — material_info·search_products 공통 */
const AI_PROCUREMENT_NOTE = 'procurement = 조달청에 등록된 같은 종류 다른 회사 품목의 집계다. KS·인증을 설명할 때만 summary 를 \'조달청에 따르면 파형강관 466개 품목 중 465개가 KS 표시 제품입니다\'처럼 \'조달청에 따르면\'으로 시작해 한 문장으로 쓴다. 조달몰·나라장터 쇼핑몰·공공 구매처는 말하지 않는다(다른 곳에서 사라는 뜻으로 들림). 품목 수만 말하지 않는다. 토목몰 상품이 KS 인증·조달 등록 제품이라고 말하지 않고, 비율로 품질·신뢰성을 단정하지 않는다(\'품질 면에서 신뢰할 수 있다\' 같은 해석 금지).';

/**
 * 자재 종류별 공공조달 등록 현황 데이터(app/procurement_summary.php — scripts/external/g2b_summary.php 가 조달청 공공데이터에서 생성). 파일이 없으면 [].
 * $override: 테스트용 데이터 주입.
 */
function ai_procurement_data(?array $override = null): array
{
    static $data = null;
    if ($override !== null) {
        $data = $override;
    }
    if ($data === null) {
        $f = __DIR__ . '/procurement_summary.php';
        $data = is_file($f) ? (array) require $f : [];
    }
    return $data;
}

/**
 * 질문·상품명 → 해당 자재의 공공조달 등록 현황(최대 $limit 종) — 공공기관 구매용 조달몰에 등록된 다른 회사 품목의 집계일 뿐,
 * 토목몰 상품의 인증·조달 여부가 아니다. 더 긴 낱말로 걸린 종류가 먼저(예: '맨홀뚜껑' → 주철 맨홀뚜껑).
 * @return list<array{name:string, items:int, makers:int, ks:int, group_standard:int, excellent:int, as_of:string, summary:string}>
 */
function ai_procurement_summary(string $text, int $limit = 2): array
{
    $data = ai_procurement_data();
    $t = ai_material_norm($text);
    if ($t === '' || empty($data['groups'])) {
        return [];
    }
    $hits = [];
    foreach ($data['groups'] as $g) {
        $best = '';
        foreach ((array) $g['words'] as $w) {
            $nw = ai_material_norm((string) $w);
            if ($nw !== '' && str_contains($t, $nw) && mb_strlen($nw) > mb_strlen($best)) {
                $best = $nw;
            }
        }
        if ($best !== '') {
            $hits[] = [mb_strlen($best), (int) $g['items'], $g, $best];
        }
    }
    usort($hits, static fn($a, $b) => [$b[0], $b[1]] <=> [$a[0], $a[1]]);
    $kept = [];
    foreach ($hits as $h) {   // 더 긴 낱말의 일부로만 걸린 종류는 뺀다('맨홀뚜껑' 질문의 '맨홀' → 맨홀블록 제외)
        if (!array_filter($kept, static fn($k) => $k[3] !== $h[3] && str_contains($k[3], $h[3]))) {
            $kept[] = $h;
        }
    }
    $hits = $kept;
    $asOf = (string) ($data['as_of'] ?? '');
    return array_map(static function ($h) use ($asOf) {
        $g = $h[2];
        $c = (array) $g['certs'];
        $certs = array_filter(['KS 표시' => (int) ($c['ks'] ?? 0), '단체표준인증' => (int) ($c['group_standard'] ?? 0)]);
        return [
            'name' => (string) $g['label'], 'items' => (int) $g['items'], 'makers' => (int) $g['makers'],
            'ks' => (int) ($c['ks'] ?? 0), 'group_standard' => (int) ($c['group_standard'] ?? 0), 'excellent' => (int) ($c['excellent'] ?? 0), 'as_of' => $asOf,
            'summary' => "{$g['label']} {$g['items']}개 품목({$g['makers']}개 업체) 중 "
                . ($certs ? implode('·', array_map(static fn($k, $v) => "{$k} {$v}개", array_keys($certs), $certs)) : 'KS 표시·단체표준인증 품목 없음')
                . " ({$asOf} 기준)",
        ];
    }, array_slice($hits, 0, $limit));
}

/**
 * 자재 기본 정보 — 국가표준 번호·이름(rag_documents 의 KS 메타데이터 확인 행) + 토목몰 등록 상품 이름·규격(판매 중).
 * 둘 다 저장된 사실만 돌려준다(설명문 생성 없음). 운영 DB 에 근거 칸이 없으면 standards 는 빈 배열.
 * @return array{standards: list<array>, products: list<array>}
 */
function ai_material_basics(string $topic): array
{
    $out = ['standards' => [], 'products' => []];
    $pdo = db();
    if (!ai_material_words($topic) || !$pdo) { return $out; }
    if (function_exists('rag_documents_has_source_columns') && rag_documents_has_source_columns()) {
        try {
            $rows = $pdo->query("SELECT source_ref, title, tags, applies_to, source_url FROM rag_documents
                                 WHERE source_type = 'ks' AND verify_level IN ('metadata_confirmed', 'original_verified', 'api_text_verified') AND legal_status <> 'repealed'
                                 ORDER BY source_ref")->fetchAll(PDO::FETCH_ASSOC);
        } catch (\Throwable $e) {
            $rows = [];
        }
        $items = [];
        foreach ($rows as $r) {
            $name = trim((string) preg_replace('/^\[KS\]\s*|\s*\(KS[^)]*\)\s*$/u', '', (string) $r['title']));
            $s = ['ref' => (string) $r['source_ref'], 'name' => $name, 'basis' => '국가표준 목록(표준 번호·이름 확인, 본문 수치 미확인)', 'url' => (string) ($r['source_url'] ?? '')];
            if (($scope = ai_ks_confirmed_scope((string) $r['applies_to'])) !== null) {   // 공식 표준 상세 페이지에서 확인한 적용 범위만
                $s['scope'] = $scope;
                $s['name'] = ai_ks_display_name($s['name'], (string) $r['applies_to']);
            }
            $items[] = [$name . ',' . $r['tags'], $s];
        }
        $out['standards'] = array_slice(ai_material_pick($items, $topic), 0, 3);
    }
    $st = $pdo->query('SELECT name, spec FROM products WHERE is_active = 1 ORDER BY id LIMIT 5000');
    $groups = [];
    foreach ($st ? $st->fetchAll(PDO::FETCH_ASSOC) : [] as $p) {
        $groups[(string) $p['name']][] = ai_spec_brief((string) $p['spec']);
    }
    $items = [];
    $acc = '/몰드|거푸집|집게|크램프|운반구/u';   // 부속·시공 도구는 주제가 그것을 말할 때만
    $topicType = ext_product_type(str_replace(['플룸', '플럼', '블럭'], ['플륨', '플륨', '블록'], $topic));
    foreach ($groups as $name => $specs) {
        if (preg_match($acc, $name) && !preg_match($acc, $topic)) { continue; }
        // 주제가 자재 종류를 말하면 다른 종류 상품은 빼기(실제 GPT-4o: 'U형 수로관' 에 포장 블록 '인터로킹U형블럭' 이 'U형' 낱말로 섞여 수로관처럼 설명됨)
        $nameType = ext_product_type(str_replace(['플룸', '플럼', '블럭'], ['플륨', '플륨', '블록'], $name));
        if ($topicType !== '' && $nameType !== '' && $nameType !== $topicType) { continue; }
        $items[] = [$name, ['name' => $name, 'count' => count($specs), 'specs' => array_slice(array_values(array_unique($specs)), 0, 3)]];
    }
    $out['products'] = array_slice(ai_material_pick($items, $topic), 0, 5);
    return $out;
}

/**
 * 포장 블록 1장 면적(㎡). 규격이 '가로*세로*두께T' 형태(예: 220*110*60T, 300*300*30T)일 때만 계산.
 * 두께 표기(T)가 없거나(경계석 120*120*120 등) 한 변이 50~600mm 밖이면 null(틀린 장수 방지).
 */
function ai_tile_area_m2(string $reg): ?float
{
    if (!preg_match('/(\d+(?:\.\d+)?)\s*[*x×]\s*(\d+(?:\.\d+)?)\s*[*x×]\s*\d+(?:\.\d+)?\s*T/iu', $reg, $m)) {
        return null;
    }
    $w = (float) $m[1];
    $l = (float) $m[2];
    if ($w < 50 || $w > 600 || $l < 50 || $l > 600) {
        return null;
    }
    return ($w / 1000.0) * ($l / 1000.0);
}

/**
 * 소요 수량 자동 계산.
 * - 단위 ㎡/m2 : 면적 기준. area_m2 주면 그대로, 없으면 길이×폭(spec_mm). 블록 규격이면 장수도 안내
 * - 개 단위 포장 블록(가로*세로*두께T) + area_m2 : 면적 ÷ 블록 1장 면적
 * - 단위 M     : 길이(m) 그대로
 * - 그 외(본/개/조 등 선형): 규격의 최대 치수 = 1개 길이로 보고 길이 ÷ 1개길이
 * 여유율 없이 정확히(올림). 계산 불가하면 [null, ''] 반환.
 *
 * @param ?float $widthMm 폭(mm) — 면적 제품에서 길이×폭 계산용 (spec_mm)
 * @param ?float $areaM2  면적(㎡) — 면적 제품 수량 직접 지정
 * @return array{0:?int,1:string} [수량, 근거문구]
 */
function ai_estimate_quantity(array $p, float $lengthM, ?float $widthMm, ?float $areaM2 = null): array
{
    $spec = (string) ($p['spec'] ?? '');
    $reg  = preg_match('/규격:\s*([^|]+)/u', $spec, $m) ? $m[1] : $spec;
    $unit = preg_match('/단위:\s*([^|]+)/u', $spec, $m) ? trim($m[1]) : '';

    $fmt = static fn(float $n): string => rtrim(rtrim(sprintf('%.2f', $n), '0'), '.');
    $unitNorm = str_replace([' ', '²'], ['', '2'], mb_strtolower($unit));   // 상용콘크리트 상품은 'm²'(위첨자 ²) — 2026-11-12
    $tileM2   = ai_tile_area_m2($reg);

    // 면적 단위 (㎡ / m2)
    if ($unit === '㎡' || strpos($unitNorm, 'm2') !== false || strpos($unit, '㎡') !== false) {
        $area = null;
        $basis = '';
        if ($areaM2 && $areaM2 > 0) {
            $area  = $areaM2;
            $basis = sprintf('%s㎡', $fmt($areaM2));
        } elseif ($lengthM > 0 && $widthMm && $widthMm > 0) {
            $area  = $lengthM * ($widthMm / 1000.0);
            $basis = sprintf('%sm × %sm = %s㎡', $fmt($lengthM), $fmt($widthMm / 1000), $fmt($area));
        }
        if ($area === null) {
            return [null, ''];
        }
        $qty = (int) ceil($area);
        $basis .= sprintf(' → %d㎡', $qty);
        // 블록 1장 크기를 알면 장수도 안내(줄눈·절단 손실 제외한 이론값)
        if ($tileM2 !== null) {
            $basis .= sprintf(' (블록 약 %s장)', number_format((int) ceil($area / $tileM2)));
        }
        return [$qty, $basis];
    }

    // 개 단위 포장 블록(점토블럭·점자블럭 등): 면적 ÷ 블록 1장 면적
    if ($areaM2 && $areaM2 > 0 && $tileM2 !== null) {
        $qty = (int) ceil($areaM2 / $tileM2);
        $u   = $unit !== '' ? $unit : '개';
        return [$qty, sprintf('%s㎡ ÷ 1장 %s㎡ = %s%s', $fmt($areaM2), rtrim(rtrim(sprintf('%.4f', $tileM2), '0'), '.'), number_format($qty), $u)];
    }

    if ($lengthM <= 0) {
        return [null, ''];
    }

    // 미터 단위
    if ($unitNorm === 'm') {
        $qty = (int) ceil($lengthM);
        return [$qty, sprintf('%sm → %dM', $fmt($lengthM), $qty)];
    }

    // 선형(본/개/조 등): 1개 길이 = 규격 이름표로 채운 '길이 L'(원문 일치) 또는 규격의 이름 붙은 길이(L1000) — 숫자 위치·크기로 짐작하지 않음(2026-11-10).
    //    예: 그레이팅 500×500(폭 W × 길이 L) → 10m ÷ 0.5m = 20개. 길이 칸이 없는 상품만 아래 기존 규칙.
    $perMm = ai_row_dim_mm($p, 'l');
    if ($perMm !== null && $perMm >= 100) {
        $perM = $perMm / 1000.0;
        $qty  = (int) ceil(round($lengthM / $perM, 6));
        $u    = $unit !== '' ? $unit : '개';
        return [$qty, sprintf('%sm ÷ %sm = %d%s', $fmt($lengthM), $fmt($perM), $qty, $u)];
    }

    // 선형(본/개/조 등) — 길이 칸이 없는 상품: 규격 최대 치수 = 1개 길이(mm)
    // ⚠️ 최대 치수가 1000mm(1m) 미만이면 규격에 '길이'가 없는 것(예: 경계석은 단면치수만
    //    기재 120*120*120)으로 보고 수량 계산을 생략한다(틀린 수량 방지 > 억지 계산).
    if (preg_match_all('/\d+(?:\.\d+)?/', $reg, $mm)) {
        $nums  = array_map('floatval', $mm[0]);
        $maxMm = $nums ? max($nums) : 0;
        if ($maxMm >= 1000) { // 1m 이상만 신뢰 가능한 1개 길이로 인정
            $perM = $maxMm / 1000.0;
            $qty  = (int) ceil($lengthM / $perM);
            $u    = $unit !== '' ? $unit : '개';
            return [$qty, sprintf('%sm ÷ %sm = %d%s', $fmt($lengthM), $fmt($perM), $qty, $u)];
        }
    }
    return [null, ''];
}

/**
 * 상품 검색 — 통합 검색엔진(search_products: 동의어+규격인지+관련도) 사용.
 * AI 카드용으로 ⓪부속(집게·몰드) 제외, ①상품명별 대표 규격 1개(예상 자재비 최저),
 * ②상품명에 검색어가 들어간 것 우선 재정렬.
 */
function tool_search_products(array $input): string
{
    // 여러 종류 비교(queries 2~4개): 종류별로 같은 조건 검색 → 한 표로 병합
    $multi = array_values(array_filter(
        array_map(static fn($q) => trim((string) $q), is_array($input['queries'] ?? null) ? $input['queries'] : []),
        static fn($q) => $q !== ''
    ));
    if (count($multi) >= 2) {
        return ai_search_compare(array_slice($multi, 0, 4), $input);
    }

    // queries 를 1개만 보내고 query 를 비운 호출(실제 GPT-4o 2026-11-02 공공도로·공공 보도 시나리오) → 그 1개를 query 로(오류 응답 → '오류가 발생' 안내가 손님에게 보이던 문제)
    $rawQuery = trim((string) ($input['query'] ?? '')) ?: ($multi[0] ?? '');
    if ($rawQuery === '') {
        return json_encode(['error' => 'query 파라미터가 필요합니다.']);
    }

    $limit  = max(1, min(10, (int) ($input['limit'] ?? 5)));
    $specMm = (int) ($input['spec_mm'] ?? 0);
    // 치수 조건(2026-11-10): 폭(width_mm)·제품 1개 길이(product_length_mm)는 이름표로 채운 치수 칸으로 확인한다(숫자 위치로 정하지 않음).
    //   설치 총 길이(length_m)와 다르다. spec_mm 은 그 밖의 규격 치수(관경·포장 블록 두께 등) — 기존 동작 그대로.
    $want = array_filter(['w' => ai_parse_mm($input['width_mm'] ?? null), 'l' => ai_parse_mm($input['product_length_mm'] ?? null)], static fn($v) => $v !== null);
    if ($want && $specMm > 0 && in_array((float) $specMm, array_values($want), true)) {
        $specMm = 0;   // 같은 숫자를 폭(길이)으로도 넘긴 경우: 위치와 관계없이 맞추는 spec_mm 대신 폭(길이)으로만 확인
    }
    $dimParams = [];
    $dimSql    = $want ? ai_dim_sql($want, $dimParams) : '';
    $dimTotal  = null;
    $find      = static fn(string $q, int $n) => search_products($q, $n, 0, $dimTotal, $dimSql, $dimParams);   // 치수 조건은 모든 재검색에 그대로(완화하지 않음)
    $cond      = ai_dim_cond_label($want, $specMm);

    // ★숫자 역할 분리: AI 질의 텍스트의 떠도는 숫자(길이·수량 등)는 규격 오필터를 유발하므로 제거.
    //   규격 치수 필터는 오직 spec_mm 로만 적용(검색어에 숫자로 붙여 search_products의 규격 인지에 태움).
    $qText = trim(preg_replace('/\s+/', ' ', preg_replace('/\d+/', ' ', $rawQuery) ?? ''));
    if ($qText === '') {
        $qText = $rawQuery; // 안전장치: 전부 숫자였으면 원본 사용
    }
    $effQuery = $specMm > 0 ? ($qText . ' ' . $specMm) : $qText;

    // 중복 제거·재정렬 여지를 두려고 넉넉히 조회
    $fetch = max(40, $limit * 8);
    // 제외할 제품이 있으면 더 깊이 조회 — 넓은 검색어('그레이팅')는 위 40행이 모두 뺄 상품명의 다른 규격이라 '다른 제품 없음'으로 잘못 끝났다(2026-11-12)
    if (ai_int_list($input['exclude_ids'] ?? null) !== []) { $fetch = max($fetch, 300); }
    $query = $qText; // 이후 용도 필터·하이라이트 재정렬은 텍스트 기준
    $note      = null;   // 모델이 손님에게 전달할 안내(교정/대안 사유)
    $corrected = null;   // 교정된 검색어(있으면)
    $traffic   = ai_traffic_value($input['traffic'] ?? null);
    // 상품 지정(only_ids, 2026-11-11): 직전 추천표 제품의 수량 다시 계산·최종 비교·조건 바뀐 뒤 재검토 — 검색어로 다시 찾지 않고
    //   그 상품만 지금 조건(폭·길이·하중)으로 다시 확인한다(조건에 안 맞으면 표에서 빼고 이유를 recheck 로)
    $onlyIds   = ai_int_list($input['only_ids'] ?? null, 20);
    $recheckIds = $onlyIds ?: ai_int_list($input['recheck_ids'] ?? null, 20);
    $recheckRows = $recheckIds ? ai_rows_by_ids($recheckIds) : [];
    $recheck   = $recheckRows ? ai_recheck($recheckRows, $want, $traffic) : [];
    $rows      = $onlyIds ? $recheckRows : $find($effQuery, $fetch);
    $matchCatIds = [];
    // 상품명에 품목 이름이 없는 카테고리(1련암거 — 상품명이 규격 코드뿐): 검색어가 카테고리 이름에 들어 있으면 그 상품을 후보로(2026-11-12)
    if (!$onlyIds) {
        $catRows = ai_category_name_rows($qText, $rows, $want, $specMm);
        if ($catRows) { $rows = array_merge($rows, $catRows); }
        // 검색어가 분류 이름에만 있는 상품(상용콘크리트 2026-11-12: '투수블록' → 분류 '인조화강블록 투수/불투수', 'U형 수로관' → 'U형 플륨')
        [$matchRows, $matchCatIds] = ai_category_match_rows($qText, $rows, $want, $specMm);
        if ($matchRows) { $rows = array_merge($rows, $matchRows); }
    }
    if ($onlyIds) {
        $limit = max($limit, count($rows));   // 지정한 상품은 모두 표에(조건에 맞는 것만)
    }

    // ★0건 대안 제시: 데드엔드("제품 선택하세요"만 노출) 방지
    //   ① 오타 교정어로 재검색  ② 규격(spec_mm)이 너무 좁으면 규격 빼고 근접 대안
    if (!$rows && !$onlyIds) {
        // ① 오타 교정
        if (function_exists('suggest_correction')) {
            $sc = suggest_correction($qText);
            if ($sc) {
                $ceff = $specMm > 0 ? ($sc['query'] . ' ' . $specMm) : $sc['query'];
                $try  = $find($ceff, $fetch);
                if ($try) {
                    $rows      = $try;
                    $query     = $sc['query'];
                    $corrected = $sc['query'];
                    $note      = "'{$sc['from']}'에 대한 제품이 없어 '{$sc['to']}'(으)로 찾은 결과입니다.";
                }
            }
        }
        // ② 규격 완화: 여전히 0건이고 규격을 좁게 걸었다면 규격 빼고 근접 제품
        //    단, spec_strict(차량용 두께 80T 등 반드시 지켜야 하는 규격)면 완화하지 않는다(다른 두께 노출 금지).
        if (!$rows && $specMm > 0 && empty($input['spec_strict'])) {
            $try = $find($qText, $fetch);
            if ($try) {
                $rows = $try;
                $note = "규격 {$specMm}mm에 딱 맞는 제품이 없어, 해당 품목의 다른 규격을 함께 보여드립니다.";
            }
        }
    }

    // ⓪ 치수 조건: 폭·길이가 확인된 규격 → 확인 불가 규격 순, 다른 규격은 뺀다(싸다는 이유로 조건이 다른 제품을 넣지 않음)
    if ($want) {
        [$okRows, $unkRows] = ai_filter_dims($rows, $want);
        $rows = array_merge($okRows, $unkRows);
    }

    // ⓪ 용도 배타 필터: 부속(집게·몰드 등) 제외 — 지정 상품(only_ids)은 이미 고객에게 보인 제품이라 그대로
    if (!$onlyIds) {
        $rows = ai_exclude_accessories($rows, $query);
        // 덮개(그레이팅·뚜껑·커버)를 찾으면 덮개 상품만 — 이름에 '그레이팅'이 들어간 수로관 본체(상용콘크리트 '그레이팅 높이조절용 측구수로관')를
        // 덮개처럼 추천하지 않는다. 덮개가 하나도 없으면 표 없이 '판매 중인 덮개 없음'(2026-11-12)
        if (ai_is_cover_query($query)) {
            $covers = array_values(array_filter($rows, 'ai_is_cover_row'));
            if (!$covers && $rows) {
                $coverNote = ai_cover_missing_note($query, $rows);
            }
            $rows = $covers;
        }
        // 수로 덮개를 찾는데 맨홀·집수정 위 덮개가 섞이지 않게(쓰는 곳이 다름 — 2026-11-10 운영 상담 #195: 10m 수로에 집수정용 그레이팅 17조 추천)
        if (ai_is_channel_cover_query($query) || (($input['cover_use'] ?? '') === 'channel' && preg_match('/맨홀|집수정/u', $query) !== 1)) {
            $rows = array_values(array_filter($rows, static fn($r) => !preg_match('/맨홀|집수정/u', (string) ($r['name'] ?? '') . ' ' . (string) ($r['category_name'] ?? ''))));
        }
    }
    // ⓪-1 '투수' 를 찾으면 '불투수' 제품은 빼기(글자 포함 관계로 반대 성질이 섞이던 문제). 둘 다 비교하려면 '불투수' 를 함께 적는다
    if (preg_match('/투수/u', $query) && !preg_match('/불\s*투수/u', $query)) {
        $filtered = array_values(array_filter($rows, static fn($r) => !preg_match('/불\s*투수/u', (string) ($r['name'] ?? '') . ' ' . (string) ($r['spec'] ?? ''))));
        if ($filtered) { $rows = $filtered; }
    }

    // ⓪-2 포장 블록의 엄격 규격(spec_strict)은 '두께'로 본다 — 두께(80T 또는 가로*세로*두께의 셋째 값)가 같은 포장 블록만.
    //      가로·세로 치수가 우연히 같은 제품(200*100*60T 의 100, 호안블록·속빈블록 등)이 두께 기준 검색에 섞이는 것 방지.
    if ($specMm > 0 && !empty($input['spec_strict']) && ai_is_paving_query($query)) {
        $rows = ai_filter_paving_thickness($rows, $specMm);
    }

    // ⓪-3 하중 등급(그레이팅): 통행 조건(traffic)보다 낮은 등급은 버리고(안전 부족), 높은 등급은 표에서 뺀다(과잉 — 같은 자리에 더 비싼 제품).
    //      2026-11-09 운영 상담: 식당 주방(보행자 전용) 폭 500mm → 대형차용 안전중하중(136,700원/개)만 표에 나와 '적합'으로 안내됨.
    $heavier      = [];
    $classEmptied = false;
    // ⓪-2b '더 튼튼한 거'(stronger_than_ids — 서버가 넣음, 2026-11-12): 앞 제품보다 하중 등급이 높다고 상품명 등급·규격 용도 표기로 확인된 제품만.
    //       앞 제품 등급을 모르면 비교하지 않고 이유만(튼튼하다고 말할 근거 없음). 통행 조건이 있으면 그 등급 이상만.
    $strongerIds = ai_int_list($input['stronger_than_ids'] ?? null, 20);
    $strongerNote = '';
    if ($strongerIds) {
        $prevRows = ai_rows_by_ids($strongerIds);
        $prevRank = $prevRows ? max(array_map(static fn($r) => AI_LOAD_RANK[ai_load_class_of($r)] ?? 0, $prevRows)) : 0;
        if ($prevRank === 0) {
            return json_encode(['products' => [], 'count' => 0, '_sse' => [], 'stronger_basis' => 'unknown',
                'note' => '앞서 보여 드린 제품의 하중 등급이 상품명·등록 규격으로 확인되지 않아 \'더 튼튼한\' 제품인지 비교할 수 없습니다. 차량이 다니는지, 어떤 차량(승용차·트럭 등)인지 알려 주시면 그 하중 등급으로 찾아 드립니다.'], JSON_UNESCAPED_UNICODE);
        }
        $minRank = max($prevRank + 1, AI_LOAD_RANK[$traffic] ?? 0);
        $rows = array_values(array_filter($rows, static fn($r) => (AI_LOAD_RANK[ai_load_class_of($r)] ?? 0) >= $minRank));
        $rows = array_map(static function ($r) { $r['_load'] = 'ok'; return $r; }, $rows);
        $prevLabel = AI_TRAFFIC_CLASS_LABEL[array_search($prevRank, AI_LOAD_RANK, true)] ?? '';
        $strongerNote = "앞서 보여 드린 제품({$prevLabel})보다 하중 등급이 높다고 상품명 등급·규격 표기로 확인된 제품만 보여 드립니다(확인되지 않은 제품은 넣지 않음).";
        if (!$rows) {
            return json_encode(['products' => [], 'count' => 0, '_sse' => [], 'stronger_basis' => $prevLabel,
                'note' => "앞서 보여 드린 제품({$prevLabel})보다 하중 등급이 높다고 확인된 '{$query}' 제품이 " . ($want ? "{$cond} 조건에서 " : '') . '없습니다. 조건을 바꾸시려면 알려 주세요.'], JSON_UNESCAPED_UNICODE);
        }
    }
    if ($traffic !== '' && !$strongerIds) {
        $hadRows = $rows !== [];
        [$rows, $heavier] = ai_filter_load_class($rows, $traffic);
        $classEmptied = $hadRows && $rows === [];
        // 수로 덮개 검색이면 맨홀·집수정용은 '높은 등급 대안'으로도 안내하지 않는다(부속 필터가 0건일 때 되돌린 다른 종류)
        if (preg_match('/수로|배수로|측구|트렌치|농수로/u', $query) && !preg_match('/맨홀|집수정/u', $query)) {
            $heavier = array_values(array_filter($heavier, static fn($r) => !preg_match('/맨홀|집수정/u', (string) ($r['name'] ?? ''))));
        }
    }
    // ⓪-4 이미 본 제품 제외(exclude_ids — 고객이 '다른 제품'·'A 말고'를 원할 때). 표는 상품명별 대표 1개라 같은 상품명의 다른 규격도 뺀다.
    //      2026-11-09 운영 상담: '다시 추천'·'다른 거' 요청에 같은 조건으로 다시 검색해 같은 표가 나오고 글만 '다른 제품'이라고 함.
    $excludeIds   = ai_int_list($input['exclude_ids'] ?? null, 30);
    $excludeNames = $excludeIds ? ai_product_names($excludeIds) : [];
    $excludedHit  = [];
    $exclEmptied  = false;
    if ($excludeIds) {
        $hadRows = $rows !== [];
        [$rows, $excludedHit] = ai_exclude_shown($rows, $excludeIds, $excludeNames);
        $exclEmptied = $hadRows && $rows === [];
    }
    // ⓪-5 맞는 등급이 없거나 이미 본 제품뿐이라 비었으면: 같은 폭의 같은 등급 그레이팅(검색어의 '수로용' 같은 세부어 없이)을 찾는다
    //      — 과잉 등급만 남기거나 '다른 제품 없음'으로 끝내지 않게(예: 보행자용 폭 500mm → EX메탈망·플라스틱 그레이팅)
    $broadened = false;
    $substituteNote = '';
    $coverNote = $coverNote ?? '';
    $extraNotes = [];
    if (!$onlyIds && ($classEmptied || $exclEmptied) && $traffic !== '' && isset($want['w']) && preg_match('/그레이팅/u', $query)) {
        $alt = ai_same_class_gratings($query, $want['w'], $traffic, $fetch);
        if ($excludeIds) {
            [$alt, $altHit] = ai_exclude_shown($alt, $excludeIds, $excludeNames);
            $excludedHit = array_values(array_unique(array_merge($excludedHit, $altHit)));
        }
        if ($alt) {
            $rows = $alt;
            $broadened = true;
            $substituteNote = ai_broadened_note($query, ai_dim_cond_label(['w' => $want['w']]), $traffic, $classEmptied ? $heavier : [], $exclEmptied ? $excludedHit : []);
            $extraNotes[] = $substituteNote;
        }
    }

    // 소요 수량 자동 계산 입력 (고객이 길이/면적을 말한 경우)
    $lengthM = (float) ($input['length_m'] ?? 0);
    $areaM2  = (float) ($input['area_m2'] ?? 0);

    // 면적 단위(㎡) 제품의 길이×폭 계산에 쓰는 폭: 폭 조건(width_mm)이 있으면 그 값, 없으면 기존처럼 spec_mm
    $areaWidth = $want['w'] ?? ($specMm > 0 ? (float) $specMm : null);

    // ① 상품명 기준 중복 제거 (규격 변형 다수 → 대표 1개: 같은 조건에서 예상 자재비가 가장 낮은 변형)
    $distinct = ai_pick_representatives($rows, $lengthM, $areaWidth, $areaM2 > 0 ? $areaM2 : null);

    // ② 상품명에 검색어(숫자 규격 제외 텍스트)가 들어간 상품을 우선 (설명만 걸린 노이즈 후순위)
    //    같은 순위 안에서는 예상 자재비(없으면 화면 단가)가 낮은 순 — 2026-11-09 운영 상담: 6,000원 보행자용이 표 맨 끝, 과잉 등급 고가 제품이 앞에 나와 비싼 제품을 추천
    // 로그인 등급 단가(회원은 할인가, 비회원은 판매가 — app/cart.php display_unit_price). 표·AI 답변 금액·정렬이 같도록(대표 상품명 전체를 한 번에 조회)
    $memberMap = display_price_map(array_column($distinct, 'id'));
    $textTerms = array_values(array_filter(
        search_expand_terms($query),
        static fn($t) => !preg_match('/^\d+$/', (string) $t)
    ));
    $nameHits = static function (string $name) use ($textTerms): int {
        $nl = mb_strtolower($name);
        $c = 0;
        foreach ($textTerms as $t) {
            if (mb_strpos($nl, mb_strtolower((string) $t)) !== false) {
                $c++;
            }
        }
        return $c;
    };
    $costKey = static function (array $p) use ($memberMap, $lengthM, $areaWidth, $areaM2): array {
        $unit = (int) ($memberMap[(int) $p['id']]['price'] ?? (int) ($p['price_b2c'] ?? 0));
        return ai_cost_key($p, $unit, $lengthM, $areaWidth, $areaM2 > 0 ? $areaM2 : null);
    };
    $dimUnknown = static fn(array $p): bool => ($p['_dim'] ?? '') === 'unknown' || ($p['_load'] ?? '') === 'unverified';   // 치수·하중을 확인하지 못한 제품은 확인된 제품 뒤
    // PHP 8 usort는 안정 정렬 → 이름 순위·금액이 같으면 기존 관련도 순서 유지. 치수 조건이 있으면 확인된 규격이 먼저(조건 확인이 가격보다 우선)
    $catHit = static fn(array $p): int => in_array((int) ($p['category_id'] ?? 0), $matchCatIds, true) ? 1 : 0;   // 검색어가 모두 분류 이름에 있는 상품 먼저(2026-11-12)
    usort($distinct, static fn($a, $b) => ($dimUnknown($a) <=> $dimUnknown($b)) ?: ($catHit($b) <=> $catHit($a)) ?: ($nameHits((string) $b['name']) <=> $nameHits((string) $a['name'])) ?: ($costKey($a) <=> $costKey($b)));

    // 검색어가 모두 분류 이름에 맞은 상품이 있으면, 그 상품과 상품명에 검색어가 모두 있는 상품만(나머지는 '블록' 같은 한 낱말로만 걸린 다른 품목 — 2026-11-12 '투수블록')
    if ($matchCatIds && array_filter($distinct, $catHit)) {
        $full = max(1, count($textTerms));
        $distinct = array_values(array_filter($distinct, static fn($p) => $catHit($p) === 1 || $nameHits((string) $p['name']) >= $full));
    }
    $products = array_slice($distinct, 0, $limit);

    // 고르는 기준(초보 고객용 쉬운 설명) — 상품 규격 + 사람이 확정한 외부 자료(제조사·KS 표기, 가격 제외). 외부 테이블이 없으면 상품 규격만
    $knowledge = ext_knowledge_for_products(array_column($products, 'id'));
    $vehicle = in_array($traffic, ['car', 'truck'], true);
    $cards = array_map(function ($p) use ($lengthM, $areaWidth, $areaM2, $knowledge, $memberMap, $want, $cond, $vehicle, $traffic, $strongerIds) {
        [$qty, $basis] = ai_estimate_quantity(
            $p,
            $lengthM,
            $areaWidth,
            $areaM2 > 0 ? $areaM2 : null
        );
        $price  = (int) $p['price_b2c'];
        $member = $memberMap[(int) $p['id']]['price'] ?? $price;
        return [
            'id'            => (int)  $p['id'],
            'name'          =>        $p['name'],
            'spec'          =>        $p['spec'],
            'price_b2c'     => $price,    // 판매가(화면 대체값·이전 상담 복원 비교용)
            'price_member'  => $member,   // 로그인 등급 단가 — 회원은 할인가, 비회원은 판매가와 같음
            'unit'          => ai_unit_label((string) $p['spec']),
            'est_cost'      => ($qty !== null && $member > 0) ? $qty * $member : null, // 로그인 등급 단가 기준 자재비(운반·시공비 별도) — 비회원은 판매가 기준(기존과 같음)
            'category_name' =>        $p['category_name'],
            'image_url'     =>        $p['image_url']  ?? null,
            'stock_qty'     => (int)  $p['stock_qty'],
            'suggested_qty' => $qty,     // null 이면 계산 불가
            'qty_basis'     => $basis,   // 근거 문구 (예: "100m ÷ 2m = 50본")
            'guide'         => ext_selection_guide($p, $knowledge[(int) $p['id']] ?? []),
            'spec_label'    => spec_size_label((string) $p['spec']) ?: (ai_name_size_spec($p) !== '' ? (string) ($p['category_name'] ?? '') : ''),   // 화면 표시용 규격(치수만) — 운반비·상차수량 같은 부가 표기 제외. 상품명이 규격 코드뿐이면 품목(카테고리) 이름
        ] + (($size = ai_row_size_label($p, spec_axes_for_category((int) ($p['category_id'] ?? 0)))) !== '' ? ['size' => $size] : [])   // 이름표대로 읽은 치수(폭·길이 뜻 구분)
          + ($want ? ['dim_check' => ($p['_dim'] ?? '') === 'unknown' ? 'unverified' : 'verified'] : [])
          + ($want && ($p['_dim'] ?? '') === 'unknown' ? ['dim_note' => "규격 확인 필요 — {$cond}인지 규격 표기로 확인되지 않았습니다"] : [])
          // 차량 통행: 하중 등급이 상품명·등록 규격(차도용)으로 확인된 덮개와 확인이 필요한 덮개를 나눈다(확인 안 된 제품을 적합하다고 하지 않음)
          + ($vehicle && ($p['_load'] ?? '') === 'ok' ? ['load_check' => 'verified', 'load_basis' => ai_load_status($p, $traffic)['basis']] : [])
          + ($vehicle && ($p['_load'] ?? '') === 'unverified' ? ['load_check' => 'unverified', 'load_note' => '하중 확인 필요 — 차량 통행 적합 여부가 상품 정보로 확인되지 않았습니다'] : [])
          // '더 튼튼한' 요청: 확인된 하중 등급과 근거(상품명 등급·규격 표기)
          + ($strongerIds && ($lc = ai_load_class_of($p)) !== '' ? ['load_grade' => AI_TRAFFIC_CLASS_LABEL[$lc], 'load_basis' => ai_load_status($p, $lc)['basis']] : []);
    }, $products);
    // 자재 분류 설명(운영자가 쓴 카테고리 설명 — 상품 분류, 없으면 상위 분류) — 답변 글의 '자재 설명' 재료. 화면 표에는 보내지 않는다(ai_cards_for_screen)
    $catDesc = ai_category_descs(array_map(static fn($p) => (int) ($p['category_id'] ?? 0), $products));
    // 등록 규격의 실제 사실(1개 중량)과 그 제품 종류의 국가표준(KS 확인 행) — 제품 간 비교·설명 재료(화면 표에는 보내지 않음)
    $ksInfo = ai_ks_standards(array_map(static fn($c) => ai_name_ks_ref((string) $c['name']), $cards));
    $standards = [];
    foreach ($cards as $i => $c) {
        $d = $catDesc[(int) ($products[$i]['category_id'] ?? 0)] ?? '';
        if ($d !== '') { $cards[$i]['category_desc'] = $d; }
        $w = ai_spec_weight_kg((string) $c['spec']);
        if ($w !== null) { $cards[$i]['weight_kg'] = $w; }
        $ref = ai_name_ks_ref((string) $c['name']);
        if ($ref !== '' && isset($ksInfo[$ref])) {
            $cards[$i]['standard'] = $ksInfo[$ref]['ref'] . ' ' . $ksInfo[$ref]['name'];
            $standards[$ref] = $ksInfo[$ref];
        }
    }

    // ③ '더 저렴한 제품' 요청(max_est_cost — 직전 추천표의 가장 낮은 예상 자재비): 같은 설치 길이·면적으로 계산한 예상 자재비가 그보다 낮은 제품만.
    //    예상 자재비가 없는 제품(수량 계산 불가)은 금액을 비교할 수 없으므로 '더 저렴한 제품'으로 보이지 않는다(2026-11-10).
    //    설치 길이·면적이 아직 없으면(예상 자재비 없음) 같은 폭·길이 규격·같은 판매 단위의 단가로만 비교한다(max_unit_price·price_unit, 2026-11-11).
    $maxEst    = max(0, (int) ($input['max_est_cost'] ?? 0));
    $maxUnit   = $maxEst > 0 ? 0 : max(0, (int) ($input['max_unit_price'] ?? 0));
    $priceUnit = trim((string) ($input['price_unit'] ?? ''));
    $cheaperDropped = 0;
    if (($maxEst > 0 || $maxUnit > 0) && $cards) {
        $keepIdx = array_keys(array_filter($cards, static fn($c) => $maxEst > 0
            ? (($c['est_cost'] ?? null) !== null && $c['est_cost'] < $maxEst)
            : ((string) ($c['unit'] ?? '') === $priceUnit && (int) ($c['price_member'] ?? 0) > 0 && (int) $c['price_member'] < $maxUnit)));
        $cheaperDropped = count($cards) - count($keepIdx);
        $cards    = array_values(array_intersect_key($cards, array_flip($keepIdx)));
        $products = array_values(array_intersect_key($products, array_flip($keepIdx)));
    }
    // 결과가 비어도 앞서 보인 제품의 재확인 결과(recheck_ids)는 알린다 — '차량 조건 추가' 뒤 표가 비면 앞 제품이 왜 안 맞는지 말할 근거
    $withRecheck = static function (array $out) use ($recheck): array {
        if ($recheck && !isset($out['recheck'])) {
            $out['recheck'] = $recheck;
            $rn = ai_recheck_note($recheck);
            if ($rn !== '') { $out['note'] = trim((string) ($out['note'] ?? '') . ' ' . $rn); }
        }
        return $out;
    };
    $cheapBound = $maxEst > 0 ? $maxEst : $maxUnit;
    $cheapBasis = $maxEst > 0 ? 'est' : 'unit';
    if (empty($cards) && $cheapBound > 0 && $cheaperDropped > 0) {
        $out = ['products' => [], 'count' => 0, '_sse' => [], ($maxEst > 0 ? 'max_est_cost' : 'max_unit_price') => $cheapBound,
                'note' => ai_cheaper_note($cheapBound, $excludedHit, $cond, true, $cheapBasis, $priceUnit)];
        if ($excludedHit) { $out['excluded'] = $excludedHit; }
        return json_encode($withRecheck($out), JSON_UNESCAPED_UNICODE);
    }
    if ($cheapBound > 0 && $cheaperDropped > 0) {
        $extraNotes[] = ai_cheaper_note($cheapBound, [], $cond, false, $cheapBasis, $priceUnit);
    }
    if (!empty($input['cheaper_incomparable'])) {   // 직전 표와 같은 기준으로 금액을 비교할 수 없음(rag_cheaper_bound 이유) — 옛 금액 기준을 쓰지 않음
        $extraNotes[] = match ((string) $input['cheaper_incomparable']) {
            'unit'     => '직전 추천표 제품의 판매 단위가 서로 달라(개·M 등) 금액을 같은 기준으로 비교할 수 없습니다. 설치 길이를 알려 주시면 예상 자재비로 비교해 드립니다.',
            'no_table' => '비교할 직전 추천표가 없습니다.',
            'no_basis' => '직전 추천표와 같은 기준(설치 길이·규격)으로 비교할 금액이 없습니다.',
            default    => '조건이 직전 추천표와 달라(폭·길이·설치 길이·통행) 직전 금액과 직접 비교하지 않았습니다.',
        } . ' 이번 조건에서 낮은 금액 순으로 보여 드립니다.';
    }

    // 지정 상품(only_ids)이 이번 조건에 하나도 맞지 않으면: 다른 제품을 끼워 넣지 않고 이유만
    if (empty($cards) && $onlyIds) {
        $out = ['products' => [], 'count' => 0, '_sse' => [], 'recheck' => $recheck,
                'note' => (ai_recheck_note($recheck) ?: '앞서 보여 드린 제품을 이번 조건으로 다시 확인했지만 표에 남길 제품이 없습니다.') . ' 새 조건으로 다시 찾으려면 검색어로 검색하세요.'];
        return json_encode($withRecheck($out), JSON_UNESCAPED_UNICODE);
    }

    // 하중 등급·이미 본 제품 제외로 비었으면: 같은 표를 다시 보여 주거나 과잉 등급을 '적합'하게 보이게 하지 않고, 사정(과잉 등급만 있음·다른 제품 없음)과 등록 규격을 알린다
    if (empty($cards) && ($excludedHit || ($classEmptied && $heavier))) {
        $out = ['products' => [], 'count' => 0, '_sse' => []] + ai_filtered_empty_result($query, $cond, $traffic, $classEmptied ? $heavier : [], $excludedHit);
        if ($corrected !== null) { $out['corrected'] = $corrected; }
        return json_encode($withRecheck($out), JSON_UNESCAPED_UNICODE);
    }
    // 덮개를 찾았는데 판매 중인 덮개가 없음(수로관 본체만 걸림) — 본체를 덮개처럼 보여 주지 않고 사실만(2026-11-12)
    if (empty($cards) && $coverNote !== '') {
        return json_encode($withRecheck(['products' => [], 'count' => 0, '_sse' => [], 'note' => $coverNote, 'not_carried' => true]), JSON_UNESCAPED_UNICODE);
    }
    // 그래도 0건이면 손님을 위한 대안 안내(모델이 되묻지 말고 이 안내를 전달)
    if (empty($cards) && $specMm > 0 && !empty($input['spec_strict']) && ai_is_paving_query($qText)) {
        $out = ['products' => [], 'count' => 0] + ai_paving_no_match([$qText], $specMm, ai_paving_names_with_thickness($specMm));   // 포장 블록 두께 기준 미충족
        if ($corrected !== null) { $out['corrected'] = $corrected; }
        return json_encode($withRecheck($out), JSON_UNESCAPED_UNICODE);
    }
    // 폭·길이 조건에 맞는 제품이 없으면: 조건이 다른 제품(싸더라도)을 대신 보여 주지 않고, 확인된 등록 치수를 알린다
    if (empty($cards) && $want) {
        $out = ['products' => [], 'count' => 0, '_sse' => [], 'note' => ai_dim_no_match_note($query, $cond, $want, $traffic)];
        if ($corrected !== null) { $out['corrected'] = $corrected; }
        return json_encode($withRecheck($out), JSON_UNESCAPED_UNICODE);
    }
    if (empty($cards)) {
        $out = [
            'products' => [],
            'count'    => 0,
            'note'     => ($specMm > 0 && !empty($input['spec_strict']))
                ? "'{$qText}'은(는) 규격 {$specMm}mm 제품이 없습니다. 조건에 맞지 않는 다른 규격은 보여드리지 않았습니다."
                : ((($nc = ai_not_carried_note($qText)) !== '') ? $nc : '해당 조건에 맞는 제품을 찾지 못했습니다. 용도(수로·배수·경계·차단 등)나 재질을 알려주시면 다시 찾아드리겠습니다.'),
            '_sse'     => [],
        ];
        if ($corrected !== null) { $out['corrected'] = $corrected; }
        return json_encode($withRecheck($out), JSON_UNESCAPED_UNICODE);
    }

    [$cards, $costSummary] = ai_cost_summary($cards, price_basis_label());
    $out = [
        'products' => $cards,
        'count'    => count($cards),
        '_sse'     => [
            ['type' => 'product_cards', 'products' => ai_cards_for_screen(ai_cards_with_member_price($cards)),   // 화면용: 회원은 할인가
             'cond' => ai_search_cond($query, $want, $specMm, $lengthM, $areaM2, $traffic, $excludeIds)
                 + (ai_is_channel_cover_query($query) || ($input['cover_use'] ?? '') === 'channel' ? ['cover_use' => 'channel'] : [])],   // 수로 덮개 상담 — 다음 턴 검색어에 '수로'가 빠져도 집수정(맨홀)용을 빼도록   // 이 표의 검색 조건(고객이 뺀 제품 포함) — 다음 턴 조건 유지·금액 비교 기준 확인용(화면은 쓰지 않음)
        ],
    ];
    if ($strongerNote !== '') { $extraNotes[] = $strongerNote; }
    // 찾은 품목이 없어 같은 폭·같은 등급의 다른 그레이팅으로 넓힌 표(대체품): GPT 가 표의 제품을 찾은 품목('수로용')처럼 말하지 않게 알리고,
    // 답에서 이 사실을 빠뜨리면 rag_process 가 안내 문장을 넣는다(2026-11-10 운영 상담 #193 — 화면 카드에는 표시하지 않음, 사용자 결정 2026-11-11)
    if ($broadened && $substituteNote !== '') {
        $out['substitute'] = ['searched' => $query, 'note' => $substituteNote];
        foreach ($out['products'] as $i => $c) { $out['products'][$i]['substitute_for'] = $query; }
    }
    if ($recheck) {
        $out['recheck'] = $recheck;   // 앞서 보인 제품을 지금 조건으로 다시 확인한 결과
        $rn = ai_recheck_note($recheck);
        if ($rn !== '') { $extraNotes[] = $rn; }
    }
    if ($costSummary) { $out['cost_summary'] = $costSummary; }   // '어떤 게 더 저렴해?' 답변용 — 도구가 계산한 값만
    // 상품 추천 결과에는 공공조달 등록 현황을 넣지 않는다(2026-11-11 사용자 결정 — '공공 조달몰에 여러 품목 등록'이 조달청에서 사라는 뜻으로 들림).
    // KS·인증을 묻는 자재 설명(material_info)에서만 쓴다.
    if ($standards) {   // 답에 표준 번호를 쓰면 '확인된 기본 정보' 출처 카드(rag_process)
        $out['basics'] = ['standards' => array_values($standards), 'products' => [], 'procurement' => []];
    }
    if ($traffic !== '' && ($heavier || array_filter($products, static fn($p) => ai_load_class_of($p) !== '' || ($p['_load'] ?? '') === 'unverified'))) {
        $out['traffic'] = $traffic;   // 적용한 통행 조건(하중 등급 필터) — 그레이팅이 있을 때만
        // 고객 조건보다 높은 등급이라 표에서 뺀 상품명 — 같은 이름의 맞는 규격이 표에 있으면(주철뚜껑 차도용 뺌·보도용 표시) 이름을 넣지 않는다(서로 어긋난 안내 방지)
        $overNames = array_values(array_diff(array_unique(array_column($heavier, 'name')), array_column($cards, 'name')));
        if ($overNames) { $out['over_spec_excluded'] = $overNames; }
    }
    if ($excludedHit) { $out['excluded'] = $excludedHit; }   // 직전에 보여 줘서 뺀 상품명
    // 차량 통행: 하중 등급을 확인하지 못한 덮개가 표에 있으면 알린다(적합하다고 말하지 않게, 확인된 제품이 먼저)
    if ($vehicle) {
        $loadUnv = array_values(array_unique(array_map(static fn($c) => (string) $c['name'], array_filter($cards, static fn($c) => ($c['load_check'] ?? '') === 'unverified'))));
        if ($loadUnv) {
            $allUnv = !array_filter($cards, static fn($c) => ($c['load_check'] ?? '') === 'verified');
            $extraNotes[] = ($allUnv ? '차량 통행 하중이 상품 정보로 확인된 제품은 없습니다. ' : '')
                . '표의 ' . implode(', ', array_slice($loadUnv, 0, 4)) . '은(는) 하중 등급이 상품 정보로 확인되지 않아 차량 통행에 맞는지 확인이 필요합니다(하중 확인 필요).';
        }
    }
    // 폭·길이를 확인하지 못한 제품이 표에 있으면 알린다(정확히 맞는 제품이라고 말하지 않게)
    if ($want) {
        $out['dim_condition'] = $cond;
        $unverified = array_values(array_unique(array_map(static fn($c) => (string) $c['name'], array_filter($cards, static fn($c) => ($c['dim_check'] ?? '') === 'unverified'))));
        if ($unverified) {
            $extraNotes[] = (count($unverified) === count($cards) ? "{$cond}로 확인된 제품은 없고, 규격 표기에 그 숫자가 있는 제품만 보여 드립니다. " : '')
                . '표의 ' . implode(', ', array_slice($unverified, 0, 4)) . "은(는) {$cond}인지 규격 표기로 확인되지 않았습니다(규격 확인 필요).";
        }
    }
    // 검색어의 제품 종류·형태(예: '날개형')가 표의 어느 제품에도 없으면 알린다 — 다른 제품을 그 제품처럼 말하지 않게(2026-11-09 운영 상담 '날개형은 안돼?')
    // 표의 제품이 모두 가격 문의(판매가 0원 — 2026-11-12 상용콘크리트 전환 직후 전 상품)면 금액 비교를 하지 않게(GPT 용 지시, 고객에게 전하는 note 아님)
    if (!array_filter($out['products'], static fn($c) => (int) ($c['price_member'] ?? 0) > 0)) {
        $out['price_inquiry'] = true;
        $out['price_note'] = '표의 제품은 모두 가격 문의(견적 요청) 상품이다. 금액·예상 자재비를 말하거나 \'가장 저렴·경제적\'으로 비교하지 말고, 권장 수량이 있으면 수량과 함께 견적요청하기로 가격을 받을 수 있다고 안내한다.';
    }
    // 검색어의 품목 이름이 카탈로그에 아예 없으면(예: '이중벽관'·'PE 배수관') 표의 제품이 그 품목이 아니라고 알린다(2026-11-12)
    if (($nc = ai_not_carried_note($qText)) !== '') {
        $extraNotes[] = $nc . ' 표의 제품은 이름이 비슷한 다른 제품입니다.';
        $out['not_carried'] = true;
    }
    // 대체품 표(broadened)도 확인한다 — 2026-11-10 운영 상담 #195 '날개형은 안돼??': 날개형 등록 규격(185~385mm) 안내 없이 다른 제품만 보임
    $missing = ai_missing_terms_registered(ai_missing_terms($query, $products));
    if ($missing) {
        $out['missing_terms'] = array_keys($missing);
        // 말별 등록 규격('플륨관용(날개형U-TYPE) 185·285·295·385mm') — 답에 빠지면 rag_process 가 한 문장으로 넣는다(2026-11-11 운영 상담 #199)
        $out['missing_registered'] = array_filter(array_map(static fn($w) => $w ? ai_format_widths($w, 2) : '', $missing));
        $extraNotes[] = ai_missing_terms_note($missing, $cond);
    }
    $notes = array_values(array_filter(array_merge([$note], $extraNotes), static fn($n) => is_string($n) && $n !== ''));
    if ($notes)              { $out['note']      = implode(' ', $notes); }      // 교정/규격완화·하중 등급·없는 조건 안내
    if ($corrected !== null) { $out['corrected'] = $corrected; }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

// ─── 하중 등급·이미 본 제품 제외·없는 조건 안내 (2026-11-09 운영 상담 오류 수정) ─────────────

/** 통행 조건 이름 — 하중 등급 필터 안내 문구용 */
const AI_TRAFFIC_CLASS_LABEL = ['pedestrian' => '보행자용', 'car' => '승용차용', 'truck' => '대형차용'];
/** 하중 등급 순서(낮음 → 높음) */
const AI_LOAD_RANK = ['pedestrian' => 1, 'car' => 2, 'truck' => 3];

/** 통행 조건 값 정리 (순수 함수) — 'pedestrian'|'car'|'truck' 만, 그 밖은 '' */
function ai_traffic_value(mixed $v): string
{
    $v = is_string($v) ? strtolower(trim($v)) : '';
    return isset(AI_LOAD_RANK[$v]) ? $v : '';
}

/**
 * 그레이팅 하중 등급 (순수 함수) — 카탈로그 상품명의 3단계(시스템 프롬프트 [그레이팅] 하중 등급·상담 가이드와 같은 기준):
 * '안전중하중' → truck(트럭·버스 등 대형차), '중하중' → car(승용차·SUV), 그 밖의 '그레이팅' → pedestrian(보행자 전용).
 * 그레이팅이 아니면 ''(등급 없음 — 하중 필터 대상 아님, 그대로 둔다).
 */
function ai_grating_load_class(string $name): string
{
    if (str_contains($name, '안전중하중')) { return 'truck'; }
    if (str_contains($name, '중하중'))     { return 'car'; }
    if (str_contains($name, '그레이팅'))   { return 'pedestrian'; }
    return '';
}

/** 차량 통행 조건에서 하중 판정이 필요한 제품인지 (순수 함수) — 차량이 위로 지나가는 덮개류(그레이팅·뚜껑·덮개·커버·트렌치·주철·날개형)와
 *  배수로 본체(수로관·플륨관·측구·개거·집수정·생태탈출로(플륨관/수로관 분류)·PE배수로·빗물받이 등 받이, 2026-11-11 추가 — 상품 정보에 하중 근거가 없어
 *  차량 통행이면 '확인 필요'). 2026-11-12: 암거(1련암거 — 상품명이 규격 코드뿐이라 분류 이름으로 판정, ai_load_name).
 *  몰드·집게(부속)는 아님. 관(흄관 등 매설관)·블록(80T 규칙)·경계석은 대상 아님. */
function ai_load_relevant(string $name): bool
{
    return preg_match('/그레이팅|뚜껑|덮개|커버|트렌치|주철|날개형|수로관|플륨관|측구|개거|집수정|생태탈출로|배수로|받이|암거/u', $name) === 1
        && preg_match('/몰드|집게/u', $name) !== 1;
}

/** 등록 규격의 용도 표기 (순수 함수) — '차도용'·'…h100차도(오수)' → 'road', '보도용'·'…h80보도(우수)' → 'pedestrian', 없으면 ''.
 *  규격(규격: 값)만 본다. '보도블록'·'보도경계석'처럼 품목 이름의 '보도'는 용도 표기가 아니다. */
function ai_spec_use_class(string $spec): string
{
    $reg = parse_spec_field($spec)['spec'] ?: $spec;
    if (preg_match('/(?<!보)차도/u', $reg)) { return 'road'; }
    if (preg_match('/보도용|보행용|보도(?!\s*(블|경계|교|판))/u', $reg)) { return 'pedestrian'; }
    return '';
}

/** 행의 확인된 하중 등급 (순수 함수) — 상품명 등급, 없으면 덮개류(ai_load_relevant)만 규격 용도 표기(차도용 → car, 보도용 → pedestrian), 없으면 ''.
 *  블록·경계석의 '(보도)'·'(차도)'는 하중 판정에 쓰지 않는다(블록 차량 기준은 80T 규칙 — 그대로 둠). */
/** 하중 판정에 쓰는 이름 (순수 함수) — 상품명 + 분류 이름(상품명에 품목 이름이 없는 1련암거 같은 상품 — 다른 상품은 상품명만으로 같은 결과) */
function ai_load_name(array $row): string
{
    return trim((string) ($row['name'] ?? '') . ' ' . (string) ($row['category_name'] ?? ''));
}

function ai_load_class_of(array $row): string
{
    $name = (string) ($row['name'] ?? '');
    $cls = ai_grating_load_class($name);
    if ($cls !== '' || !ai_load_relevant(ai_load_name($row))) { return $cls; }
    return match (ai_spec_use_class((string) ($row['spec'] ?? ''))) { 'road' => 'car', 'pedestrian' => 'pedestrian', default => '' };
}

/**
 * 하중 판정 (순수 함수, 2026-11-11) — 통행 조건 대비 'ok' 맞음 · 'heavier' 높은 등급(과잉) · 'lighter' 낮은 등급(안전 부족) ·
 * 'unverified' 확인 필요 · 'na' 판정 대상 아님. 근거는 둘만 쓴다(없는 등급을 만들지 않음):
 *  ① 상품명 등급(카탈로그 3단계: 안전중하중·중하중·그 밖의 그레이팅)  ② 등록 규격의 용도 표기('차도용'·'보도용').
 * '차도용'은 차량용이지만 톤수가 없어 대형차 조건에서는 확인 필요. 근거가 없는 덮개류(사각주철·트렌치 등)는 차량 통행에서 확인 필요.
 * @return array{status:string, basis:string}
 */
function ai_load_status(array $row, string $traffic): array
{
    $want = AI_LOAD_RANK[$traffic] ?? 0;
    if ($want === 0) {
        return ['status' => 'na', 'basis' => ''];
    }
    $name = (string) ($row['name'] ?? '');
    $cls  = ai_grating_load_class($name);
    if ($cls !== '') {
        $rank = AI_LOAD_RANK[$cls];
        $basis = '상품명 등급';
    } else {
        $use = ai_load_relevant(ai_load_name($row)) ? ai_spec_use_class((string) ($row['spec'] ?? '')) : '';   // 덮개류만(블록은 80T 규칙)
        if ($use === 'pedestrian') {
            $rank = AI_LOAD_RANK['pedestrian'];
            $basis = '규격 표기(보도용)';
        } elseif ($use === 'road') {
            if ($want === AI_LOAD_RANK['truck']) {
                return ['status' => 'unverified', 'basis' => '규격 표기(차도용, 톤수 없음)'];
            }
            $rank = AI_LOAD_RANK['car'];
            $basis = '규격 표기(차도용)';
        } elseif (ai_load_relevant(ai_load_name($row)) && $want > AI_LOAD_RANK['pedestrian']) {
            return ['status' => 'unverified', 'basis' => '하중 정보 없음'];
        } else {
            return ['status' => 'na', 'basis' => ''];
        }
    }
    if ($rank === $want) {
        return ['status' => 'ok', 'basis' => $basis];
    }
    return ['status' => $rank > $want ? 'heavier' : 'lighter', 'basis' => $basis];
}

/**
 * 통행 조건에 맞는 하중 등급만 남김 (순수 함수).
 * 등급이 맞거나 판정 대상이 아닌 상품·하중 확인이 필요한 덮개는 그대로(행마다 '_load' = ok·unverified·na 표시),
 * 조건보다 낮은 등급(안전 부족)은 버리고, 높은 등급(과잉 — 같은 자리에 더 비싼 제품)은 따로 돌려준다(표에는 넣지 않고 안내에만 쓴다).
 * @return array{0: list<array>, 1: list<array>} [맞는 행, 높은 등급 행]
 */
function ai_filter_load_class(array $rows, string $traffic): array
{
    if ((AI_LOAD_RANK[$traffic] ?? 0) === 0) {
        return [$rows, []];
    }
    $keep = [];
    $heavier = [];
    foreach ($rows as $r) {
        $st = ai_load_status($r, $traffic)['status'];
        if ($st === 'heavier') { $heavier[] = $r; continue; }
        if ($st === 'lighter') { continue; }
        $r['_load'] = $st;
        $keep[] = $r;
    }
    return [$keep, $heavier];
}

/** 판매 중 상품 행(검색 결과와 같은 칸 + 분류 이름)을 id 순서대로 (DB) — 지정 상품 재확인(only_ids·recheck_ids)용. 조회 실패 시 빈 배열 */
function ai_rows_by_ids(array $ids): array
{
    $ids = ai_int_list($ids, 30);
    if (!$ids || !db()) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1 AND p.id IN ({$ph})");
        $st->execute($ids);
        $by = [];
        foreach ($st->fetchAll() as $r) { $by[(int) $r['id']] = $r; }
        return array_values(array_filter(array_map(static fn($id) => $by[$id] ?? null, $ids)));
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 앞서 보인 제품을 지금 조건(폭·길이·하중)으로 다시 확인 (순수 함수) — 조건이 바뀐 뒤 '기존 추천의 적합성'을 알리는 데 쓴다.
 * status: ok · dim_mismatch(치수 다름) · dim_unknown(치수 확인 필요) · load_lighter(하중 등급 부족) · load_heavier(과잉 등급) · load_unverified(하중 확인 필요)
 * @return list<array{id:int, name:string, status:string, reason:string}>
 */
function ai_recheck(array $rows, array $want, string $traffic): array
{
    $label = ['pedestrian' => '보행자 전용', 'car' => '승용차 통행', 'truck' => '대형차 통행'][$traffic] ?? '';
    $out = [];
    foreach ($rows as $r) {
        $status = 'ok';
        $reason = '';
        if ($want) {
            $d = ai_dim_check($r, $want)['status'];
            if ($d === 'mismatch') { $status = 'dim_mismatch'; $reason = ai_dim_cond_label($want) . ' 조건과 치수가 다름'; }
            elseif ($d === 'unknown') { $status = 'dim_unknown'; $reason = ai_dim_cond_label($want) . '인지 규격 확인 필요'; }
        }
        if ($status === 'ok' && $traffic !== '') {
            $l = ai_load_status($r, $traffic);
            $cls = AI_TRAFFIC_CLASS_LABEL[ai_load_class_of($r)] ?? '';
            if ($l['status'] === 'lighter') { $status = 'load_lighter'; $reason = "{$label} 조건보다 낮은 하중 등급({$cls})"; }
            elseif ($l['status'] === 'heavier') { $status = 'load_heavier'; $reason = "{$label}에는 필요보다 높은 하중 등급({$cls}, 과잉)"; }
            elseif ($l['status'] === 'unverified') { $status = 'load_unverified'; $reason = "하중 등급 확인 필요({$l['basis']})"; }
        }
        $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'status' => $status, 'reason' => $reason];
    }
    return $out;
}

/** 재검토 안내 (순수 함수) — 조건에 안 맞게 된 제품과 확인이 필요한 제품을 이유와 함께. 모두 맞으면 '' */
function ai_recheck_note(array $recheck): string
{
    $bad = array_values(array_filter($recheck, static fn($x) => in_array($x['status'], ['dim_mismatch', 'load_lighter', 'load_heavier'], true)));
    $unk = array_values(array_filter($recheck, static fn($x) => in_array($x['status'], ['dim_unknown', 'load_unverified'], true)));
    $fmt = static fn(array $xs): string => implode(', ', array_map(static fn($x) => "{$x['name']}({$x['reason']})", array_slice($xs, 0, 4)));
    $parts = [];
    if ($bad) { $parts[] = '앞서 보여 드린 제품 중 ' . $fmt($bad) . '은(는) 이번 조건에 맞지 않아 표에서 뺐습니다.'; }
    if ($unk) { $parts[] = '앞서 보여 드린 ' . $fmt($unk) . '은(는) 이번 조건에 맞는지 확인이 필요합니다.'; }
    return implode(' ', $parts);
}

/** 표의 검색 조건 기록 (순수 함수) — 폭·제품 길이(mm)·그 밖의 규격(spec_mm)·설치 길이(m)·면적(㎡)·통행 조건·고객이 뺀 제품(exclude_ids).
 *  다음 턴에 조건 유지·금액 비교 기준·제외 유지를 확인하는 데 쓴다 */
function ai_search_cond(string $query, array $want, int $specMm, float $lengthM, float $areaM2, string $traffic, array $excludeIds = []): array
{
    return array_filter([
        'query' => $query, 'width_mm' => $want['w'] ?? null, 'product_length_mm' => $want['l'] ?? null, 'spec_mm' => $specMm > 0 ? $specMm : null,
        'length_m' => $lengthM > 0 ? $lengthM : null, 'area_m2' => $areaM2 > 0 ? $areaM2 : null, 'traffic' => $traffic !== '' ? $traffic : null,
        'exclude_ids' => $excludeIds ? array_values($excludeIds) : null,
    ], static fn($v) => $v !== null && $v !== '');
}

/** 정수 id 목록 정리 (순수 함수) — 양수만, 중복 제거, 최대 $max 개 */
function ai_int_list(mixed $v, int $max = 30): array
{
    if (!is_array($v)) {
        return [];
    }
    $ids = array_values(array_unique(array_filter(array_map(static fn($x) => is_numeric($x) ? (int) $x : 0, $v), static fn(int $x) => $x > 0)));
    return array_slice($ids, 0, $max);
}

/** 상품 id → 상품명 (판매 중지 포함 — 직전 표에 나온 상품을 이름으로 빼기 위함). 조회 실패 시 빈 배열 */
function ai_product_names(array $ids): array
{
    $ids = ai_int_list($ids, 30);
    if (!$ids || !db()) {
        return [];
    }
    try {
        $st = db()->prepare('SELECT id, name FROM products WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll() as $r) { $out[(int) $r['id']] = (string) $r['name']; }
        return $out;
    } catch (\Throwable $e) {
        return [];
    }
}

/**
 * 이미 본 제품 빼기 (순수 함수) — id 가 같거나 상품명이 같은 행(같은 제품의 다른 규격)을 뺀다.
 * @param array<int,string> $names ai_product_names() 결과
 * @return array{0: list<array>, 1: list<string>} [남은 행, 뺀 상품명]
 */
function ai_exclude_shown(array $rows, array $ids, array $names): array
{
    $idSet   = array_flip(array_map('intval', $ids));
    $nameSet = array_flip(array_values(array_filter(array_map('strval', $names), static fn($n) => $n !== '')));
    $keep = [];
    $hit  = [];
    foreach ($rows as $r) {
        $n = (string) ($r['name'] ?? '');
        if (isset($idSet[(int) ($r['id'] ?? 0)]) || isset($nameSet[$n])) {
            $hit[$n] = true;
            continue;
        }
        $keep[] = $r;
    }
    return [$keep, array_keys($hit)];
}

// ─── 치수 조건(폭·제품 길이) — 규격 이름표로 채운 치수 칸 우선 (2026-11-10: 폭 500 검색에 폭 400×길이 500 제품이 섞이던 문제) ──────
// 숫자의 위치(첫 번째 = 폭)로 폭·길이를 정하지 않는다. 품목마다 규격 순서가 다르므로(수로관 폭×깊이×길이, 흄관 내경×길이,
// 보도블록 가로×세로×두께, 경계석 상부폭×하부폭×높이) 카테고리 이름표(category_spec_axes, 원본 사이트 표 머리글 기준)로 채운 칸을 쓴다.

/** 치수 조건 축 → [기본 칸, 뜻이 가까운 다른 칸(같은 값이면 '확인 불가'), 이름] — 폭: 폭/가로 W(하부폭·상부폭·뒷폭은 다른 칸), 길이: 길이 L(세로 V 는 다른 칸) */
const AI_DIM_AXES = [
    'w' => ['dim_w', ['dim_w_b', 'dim_w_t', 'dim_w_r'], '폭'],
    'l' => ['dim_l', ['dim_v'], '길이'],
];

/** 치수 입력 → mm (순수 함수). 숫자는 mm 그대로, 글자는 단위(mm·cm·센티·m·미터)를 읽는다. 0 이하·읽을 수 없으면 null */
function ai_parse_mm(mixed $v): ?float
{
    if (is_int($v) || is_float($v)) {
        return $v > 0 ? round((float) $v, 1) : null;
    }
    if (!is_string($v)) {
        return null;
    }
    $s = str_replace([',', ' '], '', mb_strtolower(trim($v)));
    if (!preg_match('/^(\d+(?:\.\d+)?)(mm|㎜|밀리|cm|㎝|센티|센치|센테|m|미터)?$/u', $s, $m)) {
        return null;
    }
    $mul = match ($m[2] ?? '') { 'cm', '㎝', '센티', '센치', '센테' => 10.0, 'm', '미터' => 1000.0, default => 1.0 };
    $mm = round((float) $m[1] * $mul, 1);
    return $mm > 0 ? $mm : null;
}

/**
 * 규격 표기에 이름이 붙은 치수 (순수 함수) — '폭500'·'폭 500mm'·'W500'·'W=500'·'길이 1000'·'L1000'·'L=2M' 처럼 이름(약자)이 붙은 값만.
 * 이름 없는 숫자(500*1000)는 순서만으로 폭·길이를 정하지 않으므로 읽지 않는다. [축 => mm]
 */
function ai_spec_labeled_dims(string $spec): array
{
    $reg = parse_spec_field($spec)['spec'] ?: $spec;
    $out = [];
    foreach (['w' => '(?:폭|너비|(?<![A-Za-z])W)', 'l' => '(?:길이|(?<![A-Za-z])L)'] as $k => $p) {
        if (preg_match('/' . $p . '\s*[:=]?\s*(\d+(?:\.\d+)?)\s*(mm|cm|m|M)?(?![\d.])/u', $reg, $m)) {
            $mm = ai_parse_mm($m[1] . strtolower($m[2] ?? ''));
            if ($mm !== null) { $out[$k] = $mm; }
        }
    }
    return $out;
}

/** 규격 값에 그 치수 숫자가 들어 있는지 (순수 함수, 위치 무관 — '2M'·'2.0m' 같은 미터 표기 포함). 이름 없는 규격에서 '확인 불가'와 '다름'을 가르는 데만 쓴다 */
function ai_spec_has_mm(string $spec, float $mm, bool $meterDecimals = false): bool
{
    $reg = parse_spec_field($spec)['spec'] ?: $spec;
    if (preg_match('/(?<![\d.])' . preg_quote(spec_axis_num($mm), '/') . '(?:\.0+)?(?![\d.])/u', $reg)) {
        return true;
    }
    if ($meterDecimals && preg_match('/(?<![\d.])' . preg_quote(spec_axis_num($mm / 1000), '/') . '(?:\.0+)?(?![\d.])/u', preg_replace('/\d+(?:\.\d+)?\s*T\b/u', ' ', $reg) ?? $reg)) {
        return true;   // 상품명 규격 코드의 m 소수 표기(0.8·1.0·2.0 — 두께 130T 같은 mm 값은 제외)
    }
    return (bool) preg_match('/(?<![\d.])' . preg_quote(spec_axis_num($mm / 1000), '/') . '(?:\.0+)?\s*[mM](?![a-zA-Z])/u', $reg);
}

/**
 * 상품명에 들어 있는 규격 코드 (순수 함수, 2026-11-12) — 등록 규격(규격:)이 없고 상품명이 치수 코드로 시작하는 상품만(운영 1련암거 10개: '1.0*1.0*2.0_130T*130T_구').
 * 숫자 자리의 뜻(폭·높이·길이)은 확인되지 않았으므로 '규격 표기에 그 숫자가 있음'(확인 필요) 근거로만 쓴다. 해당 없으면 ''.
 */
function ai_name_size_spec(array $row): string
{
    if (parse_spec_field((string) ($row['spec'] ?? ''))['spec'] !== '') {
        return '';
    }
    return preg_match('/^\s*(\d+(?:\.\d+)?(?:\s*[*×xX]\s*\d+(?:\.\d+)?){1,3}(?:_[0-9A-Za-z.*×]+)*)/u', (string) ($row['name'] ?? ''), $m) ? trim($m[1]) : '';
}

/**
 * 검색어가 카테고리 이름에 있는데 그 카테고리 상품이 글자 검색에 안 잡히는 경우의 후보 (DB, 2026-11-12) — 상품명이 규격 코드뿐이고
 * 품목 이름은 카테고리에만 있는 상품(운영 1련암거: '암거' 검색 0건). 검색 결과에 그 카테고리 상품이 이미 있으면 [](일반 검색은 그대로).
 * 상품명·규격·설명에 그 말이 없는 상품만 더한다. 치수 조건(폭·길이·spec_mm)은 상품명 규격 코드로 '확인 필요' 이상만 남긴다.
 */
function ai_category_name_rows(string $query, array $rows, array $want, int $specMm): array
{
    $pdo = db();
    if (!$pdo) {
        return [];
    }
    $have = array_flip(array_map(static fn($r) => (int) ($r['category_id'] ?? 0), $rows));
    $out = [];
    foreach (preg_split('/\s+/u', trim((string) preg_replace('/[^가-힣A-Za-z\s]/u', ' ', $query))) ?: [] as $tok) {
        if (mb_strlen($tok) < 2) { continue; }
        try {
            $st = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id
                WHERE p.is_active = 1 AND c.is_active = 1 AND c.name LIKE ? AND p.name NOT LIKE ? AND p.spec NOT LIKE ? AND COALESCE(p.description, '') NOT LIKE ?
                ORDER BY p.id LIMIT 60");
            $like = '%' . $tok . '%';
            $st->execute([$like, $like, $like, $like]);
            $found = $st->fetchAll();
        } catch (\Throwable $e) {
            continue;
        }
        foreach ($found as $r) {
            if (isset($have[(int) $r['category_id']]) || ai_name_size_spec($r) === '') { continue; }   // 결과에 이미 있는 카테고리·이름이 규격 코드가 아닌 상품은 제외
            if ($want && ai_dim_check($r, $want)['status'] === 'mismatch') { continue; }
            if ($specMm > 0 && !ai_spec_has_mm(ai_name_size_spec($r), (float) $specMm, true)) { continue; }
            $out[(int) $r['id']] = $r;
        }
    }
    return array_values($out);
}

/**
 * 상품 1행이 치수 조건(폭·제품 길이)에 맞는지 (순수 함수) — 'match' 확인됨 · 'mismatch' 다름(표에서 뺌) · 'unknown' 확인 불가.
 *  ① 규격 이름표로 채운 치수 칸(채운 기준 원문 = 지금 규격일 때만 — spec_axes_row_fresh): 폭 = dim_w, 길이 = dim_l.
 *     그 칸이 비었으면 뜻이 가까운 칸(하부·상부·뒷폭, 세로)에 같은 값이 있을 때만 확인 불가, 아니면 다름(그 치수가 아닌 상품).
 *  ② 칸이 없거나 규격 원문이 바뀐 상품(문자 규격과 치수 칸 불일치): 규격 표기의 이름 붙은 값(폭500·W500·L1000)만 믿는다.
 *  ③ 그것도 없으면 규격에 그 숫자가 있으면 확인 불가(어느 치수인지 모름), 없으면 다름.
 * @param array<string,float> $want ['w' => mm, 'l' => mm]
 * @return array{status:string, by:array<string,string>}
 */
function ai_dim_check(array $row, array $want): array
{
    $fresh   = spec_axes_row_fresh($row);
    $labeled = $fresh ? [] : ai_spec_labeled_dims((string) ($row['spec'] ?? ''));
    $by = [];
    foreach ($want as $k => $mm) {
        [$col, $alts] = AI_DIM_AXES[$k];
        if ($fresh) {
            $v = $row[$col] ?? null;
            if ($v !== null && $v !== '') {
                $by[$k] = abs((float) $v - $mm) < 0.5 ? 'match' : 'mismatch';
                continue;
            }
            $near = array_filter($alts, static fn($c) => ($row[$c] ?? null) !== null && $row[$c] !== '' && abs((float) $row[$c] - $mm) < 0.5);
            $by[$k] = $near ? 'unknown' : 'mismatch';
            continue;
        }
        if (isset($labeled[$k])) {
            $by[$k] = abs($labeled[$k] - $mm) < 0.5 ? 'match' : 'mismatch';
            continue;
        }
        $nameSize = ai_name_size_spec($row);   // 등록 규격이 없고 상품명이 규격 코드인 상품(1련암거): 그 코드만 본다(중량 '2,900kg' 같은 숫자는 치수 아님)
        $by[$k] = ($nameSize !== '' ? ai_spec_has_mm($nameSize, $mm, true) : ai_spec_has_mm((string) ($row['spec'] ?? ''), $mm)) ? 'unknown' : 'mismatch';
    }
    $status = in_array('mismatch', $by, true) ? 'mismatch' : (in_array('unknown', $by, true) ? 'unknown' : 'match');
    return ['status' => $status, 'by' => $by];
}

/**
 * 치수 조건으로 나누기 (순수 함수) — [확인된 행, 확인 불가 행, 다른 행 수]. 행마다 '_dim'(match·unknown) 표시.
 * 같은 상품명에 확인된 규격이 있으면 그 상품명의 확인 불가 규격은 뺀다(표는 상품명별 대표 1개).
 */
function ai_filter_dims(array $rows, array $want): array
{
    $ok = $unk = [];
    $mismatch = 0;
    foreach ($rows as $r) {
        $c = ai_dim_check($r, $want)['status'];
        if ($c === 'mismatch') { $mismatch++; continue; }
        $r['_dim'] = $c;
        if ($c === 'match') { $ok[] = $r; } else { $unk[] = $r; }
    }
    $okNames = array_flip(array_map(static fn($r) => (string) $r['name'], $ok));
    $unk = array_values(array_filter($unk, static fn($r) => !isset($okNames[(string) $r['name']])));
    return [$ok, $unk, $mismatch];
}

/** 치수 조건 표기 (순수 함수) — ['w'=>500] → '폭 500mm', ['w'=>500,'l'=>1000] → '폭 500mm·길이 1000mm', 폭·길이 없이 규격만 → '규격 500mm', 없으면 '' */
function ai_dim_cond_label(array $want, int $specMm = 0): string
{
    $parts = [];
    foreach ($want as $k => $mm) { $parts[] = AI_DIM_AXES[$k][2] . ' ' . spec_axis_num((float) $mm) . 'mm'; }
    if ($specMm > 0) { $parts[] = "규격 {$specMm}mm"; }
    return implode('·', $parts);
}

/** 행의 확인된 폭·길이(mm) (순수 함수) — 이름표로 채운 칸(원문 일치) 또는 규격의 이름 붙은 값. 숫자 위치로 정하지 않는다. 없으면 null */
function ai_row_dim_mm(array $row, string $k): ?float
{
    if (spec_axes_row_fresh($row)) {
        $v = $row[AI_DIM_AXES[$k][0]] ?? null;
        return ($v !== null && $v !== '') ? (float) $v : null;
    }
    return ai_spec_labeled_dims((string) ($row['spec'] ?? ''))[$k] ?? null;
}

/**
 * 치수 조건 SQL 조각 (DB 검색 전 거르기 — 최종 판정은 ai_dim_check). 규격 글자에 그 숫자가 있거나, 이름표로 채운 칸 값이 같은 상품만.
 * 칸 값 비교는 미터 표기(개거 '2.0' → 2000mm)처럼 규격 글자에 mm 숫자가 없는 상품용. 새 칸이 없는 DB(spec_axes_ready 거짓)는 글자만.
 */
function ai_dim_sql(array $want, array &$params): string
{
    $ready = spec_axes_ready();
    $sql = '';
    foreach ($want as $k => $mm) {
        $n = spec_axis_num((float) $mm);
        $params[":aid{$k}re"] = '(^|[^0-9.])' . str_replace('.', '[.]', $n) . '([^0-9]|$)';
        $or = ["p.spec REGEXP :aid{$k}re"];
        if ($ready) {
            $params[":aid{$k}"] = $mm;
            $or[] = 'p.' . AI_DIM_AXES[$k][0] . " = :aid{$k}";
        }
        $sql .= ' AND (' . implode(' OR ', $or) . ')';
    }
    return $sql;
}

/** 상품명별 확인된 폭 목록 (순수 함수) — [상품명 => 오름차순 폭(mm 표기) 목록]. 폭을 확인할 수 없는 규격은 넣지 않는다(숫자 위치로 정하지 않음) */
function ai_widths_by_name(array $rows): array
{
    return ai_dims_by_name($rows, 'w');
}

/** 상품명별 확인된 치수(폭 'w'·길이 'l') 목록 (순수 함수) — [상품명 => 오름차순 mm 표기 목록] */
function ai_dims_by_name(array $rows, string $k): array
{
    $out = [];
    foreach ($rows as $r) {
        $w = ai_row_dim_mm($r, $k);
        if ($w !== null) { $out[(string) $r['name']][spec_axis_num($w)] = $w; }
    }
    foreach ($out as $n => $ws) {
        asort($ws);
        $out[$n] = array_map('strval', array_keys($ws));
    }
    return $out;
}

/** 치수 조건 0건 안내 문구 (순수 함수) — 확인된 등록 치수가 있으면 함께. $dims [상품명 => 치수 목록], $axisName '폭'·'길이' */
function ai_dim_no_match_text(string $query, string $cond, array $dims, string $axisName): string
{
    return "{$cond}로 확인된 '{$query}' 제품이 없습니다. 조건이 다른 제품은 보여 드리지 않았습니다."
        . ($dims ? ' 등록된 ' . ($axisName === '길이' ? '길이는 ' : '폭은 ') . ai_format_widths($dims) . '입니다. 규격을 다시 확인해 주세요.' : '');
}

/** 치수 조건 0건 안내 (DB: 같은 검색어의 확인된 등록 치수 — 통행 조건이 있으면 그 하중 등급만) */
function ai_dim_no_match_note(string $query, string $cond, array $want, string $traffic = ''): string
{
    $k = isset($want['w']) ? 'w' : 'l';
    $dims = [];
    try {
        $all = ai_exclude_accessories(search_products($query, 80), $query);
        if ($traffic !== '') { [$all] = ai_filter_load_class($all, $traffic); }
        $dims = ai_dims_by_name($all, $k);
    } catch (\Throwable $e) {
        $dims = [];
    }
    return ai_dim_no_match_text($query, $cond, $dims, AI_DIM_AXES[$k][2]);
}

/**
 * '더 저렴한 제품' 안내 (순수 함수). $empty: 더 싼 제품이 없음 / 아니면 직전 표보다 싼 제품만 보여 준다는 안내.
 * 금액 비교 기준을 밝힌다 — $basis 'est': 같은 설치 길이·면적의 예상 자재비(운반·시공비 별도), 'unit': 같은 폭·길이 규격·같은 판매 단위의 단가.
 * 수량을 계산할 수 없거나 단위가 다른 제품은 비교하지 않음.
 */
function ai_cheaper_note(int $bound, array $excludedNames, string $cond, bool $empty, string $basis = 'est', string $unit = ''): string
{
    $won  = number_format($bound) . '원';
    $what = $basis === 'unit' ? "가장 낮은 단가({$won}" . ($unit !== '' ? "/{$unit}" : '') . ')' : "가장 낮은 예상 자재비({$won})";
    $base = $basis === 'unit' ? '같은 규격·같은 판매 단위 기준' : '같은 설치 길이·면적 기준, 운반·시공비 별도';
    if ($empty) {
        return ($excludedNames ? '직전에 보여 드린 ' . implode(', ', array_slice($excludedNames, 0, 4)) . ' 외에 ' : '')
            . ($cond !== '' ? "{$cond} 조건에 맞으면서 " : '조건에 맞으면서 ')
            . "직전 추천표의 {$what}보다 저렴한 제품은 없습니다({$base}).";
    }
    return "직전 추천표의 {$what}보다 저렴한 제품만 보여 드립니다({$base} — " . ($basis === 'unit' ? '단위가 다른 제품' : '수량을 계산할 수 없는 제품') . '은 금액을 비교하지 않았습니다).';
}

/**
 * 치수 이름표대로 읽은 규격 (순수 함수) — '폭 W 500mm · 길이 L 1000mm · 높이 H 50mm'. 채운 기준 원문 = 지금 규격일 때만, 아니면 ''.
 * GPT 가 숫자 순서로 폭·길이를 짐작하지 않고 상품마다 다른 규격 체계(수로관 폭×깊이×길이, 흄관 내경×길이 등)대로 설명하게 한다.
 */
function ai_row_size_label(array $row, array $axes): string
{
    if (!$axes || !spec_axes_row_fresh($row)) {
        return '';
    }
    $parts = [];
    foreach ($axes as $a) {
        $key = (string) ($a['axis_key'] ?? '');
        $v = spec_axes_row_value($row, $key);
        if ($v === null || !isset(SPEC_AXIS_COLUMNS[$key])) { continue; }
        $unit = (string) ($a['unit'] ?? 'mm');
        $val  = spec_axis_value_display($a, $v);
        $parts[] = spec_axis_title($a) . ' ' . $val . (in_array($key, SPEC_AXIS_TEXT, true) || $unit === 'no' ? '' : $unit);
    }
    return implode(' · ', $parts);
}

/** '그레이팅수로용 150·200·250mm, 디자인그레이팅수로용 150·200mm' (순수 함수) */
function ai_format_widths(array $widthsByName, int $maxNames = 3): string
{
    $parts = [];
    foreach (array_slice($widthsByName, 0, $maxNames, true) as $n => $ws) {
        $parts[] = $n . ($ws ? ' ' . implode('·', $ws) . 'mm' : '');
    }
    return implode(', ', $parts);
}

/** 같은 조건의 금액 비교 키 (순수 함수) — [0, 예상 자재비] < [1, 단가](수량 계산 불가) < [2, 0](가격 문의). 대표 규격 선택·표 정렬에 공용 */
function ai_cost_key(array $p, int $unitPrice, float $lengthM, ?float $widthMm, ?float $areaM2): array
{
    if ($unitPrice <= 0) {
        return [2, 0];
    }
    [$qty] = ai_estimate_quantity($p, $lengthM, $widthMm, $areaM2);
    return $qty !== null ? [0, $qty * $unitPrice] : [1, $unitPrice];
}

/**
 * 같은 폭(첫 치수)·같은 하중 등급의 그레이팅 (DB) — 검색어의 세부어('수로용' 등) 없이 '그레이팅'으로 다시 찾는다.
 * 수로 덮개 검색이면 맨홀·집수정용은 빼고, 맨홀·집수정 검색이면 그것만 남긴다.
 */
function ai_same_class_gratings(string $query, float $widthMm, string $traffic, int $fetch = 40): array
{
    $isManhole = (bool) preg_match('/맨홀|집수정/u', $query);
    $params = [];
    $total = null;
    $rows = search_products('그레이팅', max($fetch, 80), 0, $total, ai_dim_sql(['w' => $widthMm], $params), $params);
    [$ok] = ai_filter_dims($rows, ['w' => $widthMm]);   // 폭이 확인된 규격만 대체 후보(확인 불가는 쓰지 않음)
    return array_values(array_filter($ok, static function ($r) use ($traffic, $isManhole) {
        $n = (string) ($r['name'] ?? '');
        return ai_load_class_of($r) === $traffic
            && (bool) preg_match('/맨홀|집수정/u', $n) === $isManhole
            && !preg_match('/집게|몰드/u', $n);
    }));
}

/** 덮개(그레이팅·뚜껑·덮개·커버)를 찾는 검색어인지 (순수 함수) — 본체(수로관·플륨·집수정·맨홀 구체)를 함께 말하면 아님 */
function ai_is_cover_query(string $query): bool
{
    $q = (string) preg_replace('/맨홀\s*(뚜껑|덮개|커버)/u', '뚜껑', $query);
    return preg_match('/그레이팅|덮개|뚜껑|커버|cover/iu', $q) === 1 && preg_match('/수로관|플륨|집수정|구체|맨홀/u', $q) !== 1;
}

/** 덮개 상품인지 (순수 함수) — 이름·분류에 뚜껑·덮개·커버·주철(주철 덮개류), 또는 '그레이팅'이 있되 수로관 본체(…측구수로관·높이조절용)가 아닌 것 */
function ai_is_cover_row(array $r): bool
{
    $n = (string) ($r['name'] ?? '');
    if (preg_match('/뚜껑|덮개|커버|cover|주철/iu', $n . ' ' . (string) ($r['category_name'] ?? ''))) { return true; }
    return preg_match('/그레이팅/u', $n) === 1 && preg_match('/수로관|플륨|높이\s*조절/u', $n) !== 1;
}

/** 판매 중인 덮개가 없을 때 안내 (순수 함수) — 이름에 '그레이팅'이 들어간 본체가 걸렸으면 그 이름을 밝혀 덮개가 아님을 알린다 */
function ai_cover_missing_note(string $query, array $rows): string
{
    $bodies = array_values(array_unique(array_filter(array_map(static fn($r) => (string) ($r['name'] ?? ''), $rows), static fn($n) => preg_match('/그레이팅/u', $n) === 1)));
    return "현재 판매 중인 '{$query}'(덮개) 제품이 없습니다."
        . ($bodies ? ' 이름에 그레이팅이 들어간 ' . implode(', ', array_map(static fn($n) => "'{$n}'", array_slice($bodies, 0, 3))) . '은(는) 덮개가 아니라 수로관 본체입니다.' : '');
}

/**
 * 검색어가 모두 분류 이름(상위 분류 포함)에 있는 분류의 상품 (DB) — [행, 분류 id]. 2026-11-12 상용콘크리트: 품목 이름이 분류에만 있는 경우
 * ('투수블록' → '인조화강블록 투수/불투수', '보도블록' → 상위 분류 '보도블록' 아래 블록들, 'U형 수로관' → 'U형 플륨').
 * 낱말마다 그 낱말(동의어·합성어 조각 포함)이 분류 이름에 있어야 한다 — 'U형'만 맞는 'U형 볼라드'는 아님.
 */
function ai_category_match_rows(string $query, array $rows, array $want, int $specMm): array
{
    $pdo = db();
    $toks = array_values(array_filter(preg_split('/\s+/u', trim((string) preg_replace('/[^가-힣A-Za-z0-9\s]/u', ' ', $query))) ?: [], static fn($t) => mb_strlen($t) >= 2 && !preg_match('/^\d+$/', $t)));
    if (!$pdo || !$toks) { return [[], []]; }
    $extra = ['수로관' => ['플륨', '수로'], '플륨관' => ['플륨'], '블럭' => ['블록']];
    $alts = static function (string $tok) use ($extra): array {   // 낱말 → 조각마다 [다른 표현들] (조각마다 하나라도 있으면 맞음)
        $segs = function_exists('segment_compound') ? segment_compound($tok) : [$tok];
        $out = [];
        foreach ($segs ?: [$tok] as $seg) {
            $e = function_exists('expand_search_token') ? expand_search_token($seg) : [$seg];
            $out[] = array_values(array_unique(array_merge([$seg], $e, $extra[$seg] ?? [])));
        }
        return $out;
    };
    try {
        $cats = $pdo->query("SELECT c.id, CONCAT(COALESCE(pc.name, ''), ' ', c.name) AS nm FROM categories c LEFT JOIN categories pc ON pc.id = c.parent_id WHERE c.is_active = 1")->fetchAll();
    } catch (\Throwable $e) {
        return [[], []];
    }
    $ids = [];
    foreach ($cats as $c) {
        $nm = mb_strtolower(str_replace(' ', '', (string) $c['nm']));
        $all = true;
        foreach ($toks as $t) {
            foreach ($alts($t) as $choices) {
                if (!array_filter($choices, static fn($x) => $x !== '' && mb_strpos($nm, mb_strtolower(str_replace(' ', '', (string) $x))) !== false)) { $all = false; break 2; }
            }
        }
        if ($all) { $ids[] = (int) $c['id']; }
    }
    if (!$ids) { return [[], []]; }
    $have = array_flip(array_map(static fn($r) => (int) ($r['id'] ?? 0), $rows));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    try {
        $st = $pdo->prepare("SELECT p.*, c.name AS category_name FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1 AND p.category_id IN ({$ph}) ORDER BY p.id LIMIT 120");
        $st->execute($ids);
        $found = $st->fetchAll();
    } catch (\Throwable $e) {
        return [[], []];
    }
    $out = [];
    foreach ($found as $r) {
        if (isset($have[(int) $r['id']])) { continue; }
        if ($want && ai_dim_check($r, $want)['status'] === 'mismatch') { continue; }
        if ($specMm > 0 && !ai_spec_has_mm((string) ($r['spec'] ?? ''), (float) $specMm)) { continue; }   // 미터 소수 읽기는 끔('1종'을 1m 로 보지 않게)
        $out[] = $r;
    }
    return [$out, $ids];
}

/**
 * 검색어의 품목 이름 중 카탈로그(판매 중인 상품 이름·분류 이름)에 아예 없는 것 안내 (DB) — '이중벽관'·'PE 배수관'의 '배수관'(2026-11-12 상용콘크리트).
 * 품목 이름 낱말(…관·…블록·…석·맨홀·…받이·그레이팅·트렌치·볼라드·…철망·플륨·암거·집수정)만 본다. 합성어는 조각이 모두 있으면 있음('투수블록' → '인조화강블록 투수/불투수').
 */
function ai_not_carried_note(string $query): string
{
    static $hay = null;
    $pdo = db();
    if (!$pdo) { return ''; }
    if ($hay === null) {
        try {
            $hay = mb_strtolower(str_replace(' ', '', implode(' ', $pdo->query("SELECT CONCAT(p.name, ' ', c.name) FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1 AND c.is_active = 1")->fetchAll(PDO::FETCH_COLUMN))));
        } catch (\Throwable $e) {
            $hay = '';
        }
    }
    if ($hay === '') { return ''; }
    $missing = [];
    foreach (preg_split('/\s+/u', trim((string) preg_replace('/[^가-힣A-Za-z0-9\s]/u', ' ', $query))) ?: [] as $tok) {
        if (mb_strlen($tok) < 2 || !preg_match('/(관|블록|블럭|석|맨홀|받이|그레이팅|트렌치|볼라드|철망|플륨|암거|집수정)$/u', $tok)) { continue; }
        $segs = function_exists('segment_compound') ? (segment_compound($tok) ?: [$tok]) : [$tok];
        $found = !array_filter($segs, static fn($x) => mb_strpos($hay, mb_strtolower((string) $x)) === false);
        if (!$found) { $missing[] = $tok; }
    }
    return $missing ? implode(', ', array_map(static fn($t) => "'{$t}'", $missing)) . '은(는) 현재 판매 중인 제품이 없습니다.' : '';
}

/** 수로(배수로·측구·트렌치) 덮개를 찾는 검색어인지 (순수 함수) — 맨홀·집수정 위 덮개는 쓰는 곳이 달라 후보에서 뺀다 */
function ai_is_channel_cover_query(string $query): bool
{
    return preg_match('/그레이팅|덮개|뚜껑|커버/u', $query) === 1
        && preg_match('/수로|배수로|측구|트렌치/u', $query) === 1
        && preg_match('/맨홀|집수정/u', $query) !== 1;
}

/** 같은 폭의 같은 등급 그레이팅으로 바꿔 보여 줄 때의 안내 (순수 함수) — $cond '폭 500mm'. 높은 등급만 있었거나($heavier) 이미 보여 준 제품뿐이었을 때($excludedNames) */
function ai_broadened_note(string $query, string $cond, string $traffic, array $heavier, array $excludedNames = []): string
{
    // 2026-11-12 사용자 요청: 제품 이름은 작은따옴표로, 이유를 먼저·'대안으로'로 이어 흐름이 보이게, 같은 말을 두 번 하지 않게
    $label = AI_TRAFFIC_CLASS_LABEL[$traffic] ?? '';
    $q = "'{$query}'";
    if ($excludedNames && !$heavier) {
        return "{$label} {$q} 제품은 {$cond}에서 직전에 보여 드린 " . implode(', ', array_map(static fn($n) => "'{$n}'", array_slice($excludedNames, 0, 4)))
            . " 외에는 없습니다. 대안으로 같은 폭의 다른 {$label} '그레이팅' 제품을 보여 드립니다.";
    }
    $why = $heavier
        ? ' 같은 폭의 ' . $q . ai_josa($query, '은', '는') . ' ' . implode(', ', array_map(static fn($l) => (string) preg_replace("/^([^(]+)/u", "'$1'", $l), ai_heavier_labels($heavier))) . '뿐이라 필요보다 높은 하중 등급이어서 표에서 뺐습니다.'
        : '';
    return "{$label} {$q} 제품은 {$cond}가 없습니다.{$why} 대안으로 같은 폭의 {$label} '그레이팅' 제품을 보여 드립니다.";
}

/** 받침 유무로 조사 고르기 (순수 함수) — 한글 끝 글자 받침이 있으면 $withFinal('은'), 없으면 $without('는'). 한글이 아니면 '은(는)' 식 */
function ai_josa(string $word, string $withFinal, string $without): string
{
    $last = mb_substr(trim($word), -1);
    $code = mb_ord($last, 'UTF-8');
    if ($code === false || $code < 0xAC00 || $code > 0xD7A3) { return "{$withFinal}({$without})"; }
    return (($code - 0xAC00) % 28) !== 0 ? $withFinal : $without;
}

/** 높은 등급 행 → '안전중하중그레이팅수로용(대형차용)' 목록 (순수 함수, 등급 낮은 순) */
function ai_heavier_labels(array $heavier, array $priceByName = []): array
{
    $byName = [];
    $clsOf  = [];
    foreach ($heavier as $r) {
        $n = (string) ($r['name'] ?? '');
        $byName[$n] ??= AI_LOAD_RANK[ai_load_class_of($r)] ?? 0;
        $clsOf[$n] ??= ai_load_class_of($r);
    }
    asort($byName);
    $out = [];
    foreach (array_keys($byName) as $n) {
        $cls = $clsOf[$n] ?? '';
        $p   = $priceByName[$n] ?? null;
        $out[] = $n . '(' . (AI_TRAFFIC_CLASS_LABEL[$cls] ?? '') . ($p ? ', ' . number_format($p['price']) . '원' . ($p['unit'] !== '' ? '/' . $p['unit'] : '') . '부터' : '') . ')';
    }
    return array_slice($out, 0, 3);
}

/**
 * 하중 등급·이미 본 제품 제외로 결과가 빈 경우의 안내 문구 (순수 함수) — 고객에게 그대로 전하는 문장.
 * @param array<string,array{price:int,unit:string}> $priceByName 높은 등급 상품명별 최저 화면 단가
 * @param array<string,list<string>>                 $classWidths 고객 조건 등급의 상품명별 확인된 폭
 */
function ai_filter_empty_note(string $query, string $cond, string $traffic, array $heavier, array $excludedNames, array $priceByName = [], array $classWidths = []): string
{
    $parts = [];
    $condTxt = $cond !== '' ? "이 조건({$cond})" : '이 조건';
    if ($excludedNames) {
        $parts[] = '직전에 보여 드린 ' . implode(', ', array_slice($excludedNames, 0, 4)) . " 외에는 {$condTxt}에 맞는 다른 제품이 없습니다.";
    }
    if ($heavier && $traffic !== '') {
        $label = AI_TRAFFIC_CLASS_LABEL[$traffic] ?? '';
        $parts[] = "{$label} '{$query}' 제품은 " . ($cond !== '' ? "{$cond}가 등록되어 있지 않습니다." : '등록되어 있지 않습니다.')
            . ' 같은 조건에는 ' . implode(', ', ai_heavier_labels($heavier, $priceByName)) . '만 있어, 필요보다 높은 하중 등급이고 가격이 높습니다.';
        if ($classWidths) {
            $parts[] = "등록된 {$label} 제품은 " . ai_format_widths($classWidths) . '입니다.';
        }
        $parts[] = '규격을 다시 확인해 주시거나, 높은 등급 제품도 보실지 알려 주세요.';
    }
    return implode(' ', $parts);
}

/**
 * 하중 등급·이미 본 제품 제외로 결과가 빈 경우의 도구 결과 (DB: 높은 등급 단가·고객 등급의 등록 폭 조회)
 * @return array{note:string, traffic?:string, over_spec_options?:list<array>, excluded?:list<string>, next?:string}
 */
function ai_filtered_empty_result(string $query, string $cond, string $traffic, array $heavier, array $excludedNames): array
{
    $priceByName = [];
    if ($heavier) {
        $map = display_price_map(array_map(static fn($r) => (int) $r['id'], $heavier));
        foreach ($heavier as $r) {
            $n = (string) $r['name'];
            $p = (int) ($map[(int) $r['id']]['price'] ?? (int) ($r['price_b2c'] ?? 0));
            if ($p > 0 && (!isset($priceByName[$n]) || $p < $priceByName[$n]['price'])) {
                $priceByName[$n] = ['price' => $p, 'unit' => ai_unit_label((string) $r['spec'])];
            }
        }
    }
    $classWidths = [];
    if ($heavier && $traffic !== '') {
        try {
            $all = ai_exclude_accessories(search_products($query, 80), $query);
            $classWidths = ai_widths_by_name(array_filter($all, static fn($r) => ai_load_class_of($r) === $traffic));
        } catch (\Throwable $e) {
            $classWidths = [];
        }
    }
    $out = ['note' => ai_filter_empty_note($query, $cond, $traffic, $heavier, $excludedNames, $priceByName, $classWidths)];
    if ($traffic !== '') { $out['traffic'] = $traffic; }
    if ($heavier) {
        $out['over_spec_options'] = ai_heavier_labels($heavier, $priceByName);
        $ranks = array_map(static fn($r) => AI_LOAD_RANK[ai_load_class_of($r)] ?? 0, $heavier);
        $next  = array_search(min($ranks), AI_LOAD_RANK, true);
        $out['next'] = "고객이 높은 등급 제품도 보겠다고 하면 traffic '{$next}' 로 다시 검색한다. 그 전에는 높은 등급 제품을 추천하거나 적합하다고 말하지 않는다.";
    }
    if ($excludedNames) { $out['excluded'] = $excludedNames; }
    return $out;
}

/** 제품 형태·재질·표기 말(없으면 '그 제품이 아니다'라고 알릴 대상) — ○○형·○○무늬, 타공·유공·무공·투수·국산·재질 등. 용도어(우수·오수)·분류어(수로관)는 대상 아님 */
const AI_ATTR_TERM = '/(형|무늬)$|^(타공|유공|무공|투수|불투수|국산|수입|칼라|컬러|스텐|스테인리스|sus|알루미늄|주철|플라스틱|논슬립|체크|날개|디자인|안전)/iu';

/**
 * 검색어 중 표의 어느 제품(상품명·규격·분류·설명)에도 없는 제품 형태·재질 말 (순수 함수 — 동의어 사전 사용).
 * 형태·재질 말(AI_ATTR_TERM)만 본다 — 2026-11-10: '맨홀 우수'의 우수(용도), 'U형 수로관'의 수로관(U형플륨관도 수로관류)을
 * '없는 제품'이라 안내하던 오탐. 조건어도 ai_missing_terms_registered() 가 카탈로그에 실제로 있는 말만 남긴다.
 * @return list<string>
 */
function ai_missing_terms(string $query, array $rows): array
{
    if (!$rows) {
        return [];
    }
    $hay = mb_strtolower(implode(' ', array_map(static fn($r) => ($r['name'] ?? '') . ' ' . ($r['spec'] ?? '') . ' ' . ($r['category_name'] ?? '') . ' ' . ($r['description'] ?? ''), $rows)));
    $clean = preg_replace('/[^\w가-힣\s]/u', ' ', $query) ?? '';
    $out = [];
    foreach (preg_split('/\s+/u', trim($clean)) ?: [] as $tok) {
        if (mb_strlen($tok) < 2 || preg_match('/^\d+$/', $tok)) {
            continue;
        }
        foreach (segment_compound($tok) as $seg) {
            if (!preg_match(AI_ATTR_TERM, $seg)) { continue; }
            $found = false;
            foreach (expand_search_token($seg) as $m) {
                $m = mb_strtolower(trim((string) $m));
                if ($m !== '' && mb_strpos($hay, $m) !== false) { $found = true; break; }
            }
            if (!$found) { $out[$seg] = true; }
        }
    }
    return array_slice(array_keys($out), 0, 3);
}

/**
 * 없는 말 중 카탈로그 판매 상품의 상품명·규격에 실제로 있는 것만 (DB) — [말 => [상품명 => 등록 폭]]. 조건어(보행자·주방 등)는 상품명에 없으므로 빠진다.
 */
function ai_missing_terms_registered(array $terms): array
{
    $out = [];
    foreach ($terms as $t) {
        try {
            $rows = search_products((string) $t, 60);
        } catch (\Throwable $e) {
            continue;
        }
        $tl = mb_strtolower((string) $t);
        $rows = array_values(array_filter($rows, static fn($r) => mb_strpos(mb_strtolower(($r['name'] ?? '') . ' ' . ($r['spec'] ?? '')), $tl) !== false));
        if ($rows) {
            $out[(string) $t] = ai_widths_by_name($rows);
        }
    }
    return $out;
}

/** 없는 말 안내 (순수 함수) — "'날개형'이(가) 들어간 제품은 이 조건(규격 500mm)에서 찾지 못해 표에 없습니다(등록: 플륨관용(날개형U-TYPE) 185·285·295·385mm)." */
function ai_missing_terms_note(array $missing, string $cond = ''): string
{
    $cond = $cond !== '' ? "이 조건({$cond})" : '이 조건';
    $parts = [];
    foreach ($missing as $t => $widths) {
        $parts[] = "'{$t}'이(가) 들어간 제품은 {$cond}에서 찾지 못해 표에 없습니다" . ($widths ? '(등록: ' . ai_format_widths($widths, 2) . ')' : '') . '.';
    }
    return implode(' ', $parts) . ' 표의 제품은 그 제품이 아닙니다.';
}

/**
 * 카테고리 설명 → 상담용 한 줄 (순수 함수): 첫 줄만, 끝의 '…(전 규격·제품)을 공급합니다.'는 떼어 자재 분류 설명으로. 최대 120자.
 * 예: 'U형·AN형·벤치플륨관 등 측구 및 배수로용 콘크리트 수로관 전 규격을 공급합니다.' → 'U형·AN형·벤치플륨관 등 측구 및 배수로용 콘크리트 수로관'
 */
function ai_category_brief(?string $desc): string
{
    $lines = preg_split('/\R/u', trim((string) $desc)) ?: [''];
    $line = trim((string) $lines[0]);
    $line = trim((string) preg_replace('/\s*(?:전\s*규격|제품)?\s*(?:을|를)\s*공급합니다\.?$/u', '', $line));
    return mb_strlen($line) > 120 ? mb_substr($line, 0, 119) . '…' : $line;
}

/** 상품 분류별 설명 [category_id => 한 줄] — 분류에 설명이 없으면 상위 분류 설명. 1회 조회(N+1 없음). 실패하면 빈 배열(설명 없이 기존대로) */
function ai_category_descs(array $categoryIds): array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $categoryIds))));
    if (!$ids) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $st = db()->prepare("SELECT c.id, c.description AS own_desc, p.description AS parent_desc
                             FROM categories c LEFT JOIN categories p ON p.id = c.parent_id WHERE c.id IN ({$ph})");
        $st->execute($ids);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $b = ai_category_brief($r['own_desc'] ?? '') ?: ai_category_brief($r['parent_desc'] ?? '');
            if ($b !== '') { $out[(int) $r['id']] = $b; }
        }
        return $out;
    } catch (\Throwable $e) {
        return [];
    }
}

/** 화면 비교표 이벤트용 카드 (순수 함수) — 상담 글 재료(category_desc·weight_kg·standard)는 빼고 보낸다(표에 표시하지 않고 상담 기록 용량도 줄임) */
function ai_cards_for_screen(array $cards): array
{
    return array_map(static function (array $c): array { unset($c['category_desc'], $c['weight_kg'], $c['standard'], $c['substitute_for']); return $c; }, $cards);
}

/** 규격 칸의 판매 단위 1개당 중량 kg (순수 함수) — 등록 규격의 '중량: 40kg' 표기만 읽는다(단위가 ㎡면 1㎡당, 본이면 1본당). 없으면 null(추정하지 않음) */
function ai_spec_weight_kg(string $spec): ?float
{
    if (!preg_match('/중량\s*[:：]\s*([\d,]+(?:\.\d+)?)\s*(?:kg|㎏)/iu', $spec, $m)) {
        return null;
    }
    $v = (float) str_replace(',', '', $m[1]);
    return $v > 0 ? $v : null;
}

/**
 * 상품 이름 → 그 제품 종류의 국가표준(KS) 번호 규칙. 국가표준 목록에서 확인한 표준의 적용 범위(rag_documents KS 행)와
 * 이름이 분명히 맞는 제품군만 — 부속(분기구·소켓·뚜껑·집게)·유공관·대용품(VR관)·범위가 애매한 제품은 넣지 않는다(틀린 연결 방지).
 * 이 연결은 '표준의 대상 종류'라는 뜻이고 개별 상품의 KS 인증 여부가 아니다.
 */
const AI_KS_NAME_RULES = [
    'KS F 4403'   => '/^(흄관\([AB]형\)|접속흄관)/u',                        // 원심력 철근 콘크리트관
    'KS M 3500-1' => '/^PE이중벽관\(KS\)$/u',                                 // 배수·하수용 구조형 PE관 — 이중벽관. 적용 범위(사용자 확인 2026-11-05)가 '관 내·외면 평활'이라 이름에 KS 가 표기된 상품만(1~3종·HDPE 는 구조 미확인)
    'KS M 3600'   => '/^고강성PVC이중벽관$/u',                                 // 배수·하수용 구조형 PVC관 — 이중벽관·리브관
    'KS F 4419'   => '/^인터로킹/u',                                           // 보차도용 콘크리트 인터로킹 블록
    'KS F 4561'   => '/점자블[록럭]/u',                                        // 시각장애인용 점자블록
    'KS D 3590'   => '/^파형강관$/u',                                          // 파형 강관 및 파형 섹션
    'KS F 4010'   => '/^(U형플륨관|벤치플륨관)/u',                             // 철근 콘크리트 플룸 및 벤치 플룸
    'KS D 4040'   => '/^주철뚜껑/u',                                           // 주철(강)재 맨홀 뚜껑 및 틀
    'KS F 4012'   => '/^조립식원형맨홀/u',                                     // 하수도용 콘크리트 맨홀 블록
    'KS F 4006'   => '/^(콘크리트경계석|고강도콘크리트경계석|인조화강경계석)$/u', // 콘크리트 경계 블록(인조석 표면층 포함)
];

/** 상품 이름 → 해당 국가표준 번호 (순수 함수). 규칙에 없으면 '' */
function ai_name_ks_ref(string $name): string
{
    $n = trim($name);
    foreach (AI_KS_NAME_RULES as $ref => $re) {
        if (preg_match($re, $n) === 1) {
            return $ref;
        }
    }
    return '';
}

/**
 * 국가표준 번호 → 확인된 표준 정보 [ref => {ref, name, basis, url, scope?}] — rag_documents 의 KS 확인 행(국가표준 목록에서 번호·이름 확인)만.
 * ai_material_basics 의 standards 와 같은 형식(출처 카드 rag_basic_sources_used 가 그대로 쓴다). 근거 칸이 없는 DB 면 빈 배열.
 */
function ai_ks_standards(array $refs): array
{
    $refs = array_values(array_unique(array_filter($refs)));
    if (!$refs || !function_exists('rag_documents_has_source_columns') || !rag_documents_has_source_columns()) {
        return [];
    }
    try {
        $ph = implode(',', array_fill(0, count($refs), '?'));
        $st = db()->prepare("SELECT source_ref, title, applies_to, source_url FROM rag_documents
                             WHERE source_type = 'ks' AND verify_level IN ('metadata_confirmed', 'original_verified', 'api_text_verified')
                               AND legal_status <> 'repealed' AND source_ref IN ({$ph})");
        $st->execute($refs);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $name = trim((string) preg_replace('/^\[KS\]\s*|\s*\(KS[^)]*\)\s*$/u', '', (string) $r['title']));
            $s = ['ref' => (string) $r['source_ref'], 'name' => $name, 'basis' => '국가표준 목록(표준 번호·이름 확인, 본문 수치 미확인)', 'url' => (string) ($r['source_url'] ?? '')];
            if (($scope = ai_ks_confirmed_scope((string) $r['applies_to'])) !== null) {   // 공식 표준 상세 페이지에서 확인한 적용 범위만
                $s['scope'] = $scope;
                $s['name'] = ai_ks_display_name($s['name'], (string) $r['applies_to']);
            }
            $out[$s['ref']] = $s;
        }
        return $out;
    } catch (\Throwable $e) {
        return [];
    }
}

/** 포장 블록 검색어인지 (순수 함수) — 블록·인터로킹·보도·점토·투수·잔디·평판 등. 호안·축조·옹벽 등 구조용은 제외 */
function ai_is_paving_query(string $query): bool
{
    return preg_match('/블[록럭]|인터로킹|보도|보차도|점토|벽돌|투수|잔디|평판|판석|포장/u', $query) === 1
        && preg_match('/호안|축조|옹벽|속빈|조적/u', $query) !== 1;
}

/**
 * 포장 블록 두께 기준을 충족하는 등록 상품이 없을 때의 안내 (순수 함수).
 * 기준보다 얇은 제품을 대체품으로 권하지 않고, 설계도서 규격 확인·별도 견적이 필요함을 알린다.
 * 상담 화면에는 spec_unavailable 카드로 표시(public/assets/chat-ai.js). 상품을 만들거나 DB 에 추가하지 않는다.
 */
function ai_paving_unavailable(array $items, int $mm): array
{
    $label = "'" . implode("', '", $items) . "'";
    return [
        'note' => "{$label}은(는) 규격 {$mm}mm 제품이 없습니다(현재 등록 상품 기준). 기준보다 얇은 다른 두께는 대체품으로 권하지 않습니다."
            . ' 발주처 설계도서·시방서의 블록 규격을 확인해 주세요 — 해당 규격은 별도 견적이 필요합니다.',
        '_sse' => [['type' => 'spec_unavailable', 'spec_mm' => $mm, 'item' => implode(', ', $items)]],
    ];
}

/**
 * 찾는 종류에 기준 두께 제품이 없을 때의 안내 (순수 함수).
 * - 같은 두께의 다른 포장 블록이 등록돼 있으면($sameThickness): 그 이름을 alternatives 로 알려 모델이 다시 검색하게 한다(카드 없음).
 *   예: 개인 마당 80mm 에서 [참조 문서]의 분류명('보차도블록' 등)으로 찾아 0건 → 등록된 80T 인터로킹 등으로 재검색.
 * - 같은 두께 포장 블록이 하나도 없으면(예: 100mm): ai_paving_unavailable — '현재 등록된 적합 상품 없음' 카드.
 * 어느 경우에도 더 얇은 두께는 권하지 않는다.
 */
function ai_paving_no_match(array $items, int $mm, array $sameThickness): array
{
    if (!$sameThickness) {
        return ai_paving_unavailable($items, $mm);
    }
    $label = "'" . implode("', '", $items) . "'";
    return [
        'note' => "{$label}은(는) 규격 {$mm}mm 제품이 없습니다. 같은 {$mm}mm 두께로 등록된 포장 블록: " . implode(', ', $sameThickness)
            . '. 다른 두께는 대신 보여드리지 않았습니다.',
        'alternatives' => array_values($sameThickness),
        '_sse' => [],
    ];
}

/** 해당 두께(mm)로 등록된 포장 블록 상품명 (최대 6개, 구조용·경계 블록 제외). FULLTEXT 를 쓰지 않아 DB 종류와 무관 */
function ai_paving_names_with_thickness(int $mm): array
{
    $pdo = db();
    if (!$pdo || $mm <= 0) {
        return [];
    }
    $rows = $pdo->query("SELECT name, spec FROM products WHERE is_active = 1 AND (name LIKE '%블록%' OR name LIKE '%블럭%') ORDER BY id")
        ->fetchAll(PDO::FETCH_ASSOC);
    return array_slice(array_values(array_unique(array_column(ai_filter_paving_thickness($rows, $mm), 'name'))), 0, 6);
}

/**
 * 블록 두께(mm) 읽기 (순수 함수). 규격 값에서 'NNT' 표기, 없으면 '가로*세로*두께' 의 셋째 값. 범위(65~125)·넷 이상 치수는 null.
 * 예: '199*99*80T_회색' → 80, '1200*900*80_컬러(차도)' → 80, '230*114*65~125' → null
 */
function ai_block_thickness(string $spec): ?int
{
    $v = parse_spec_field($spec)['spec'] ?: $spec;
    if (preg_match('/(?<![\d.])(\d{2,3})\s*T(?![a-z])/iu', $v, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/^\s*\d+\s*[*x×]\s*\d+\s*[*x×]\s*(\d+)(?!\s*[*x×~\d])/u', $v, $m)) {
        return (int) $m[1];
    }
    return null;
}

/** 두께가 $mm 인 포장 블록만 남김 (순수 함수). 이름이 구조용(호안·축조·옹벽·속빈·조적)·경계용(경계블록·경계석)이면 제외 */
function ai_filter_paving_thickness(array $rows, int $mm): array
{
    return array_values(array_filter($rows, static fn($r) => ai_block_thickness((string) ($r['spec'] ?? '')) === $mm
        && preg_match('/호안|축조|옹벽|속빈|조적|경계/u', (string) ($r['name'] ?? '')) !== 1));
}

/**
 * 용도 배타 필터 (DB 불필요한 순수 함수).
 * - 모든 검색: 집게·몰드(시공 장비·거푸집)는 본체 검색 결과에서 제외 (예: '경계석' → 경계석집게 제외)
 * - '수로/배수로/측구/트렌치' 본체 검색: 맨홀/집수정(점 설치물)과 뚜껑도 제외
 * 단, 고객이 그 부속을 직접 찾으면(query에 언급) 제외하지 않는다. 필터 후 0건이면 원복(빈 결과 방지).
 */
function ai_exclude_accessories(array $rows, string $query): array
{
    $isChannel = (bool) preg_match('/수로|배수로|측구|트렌치|농수로/u', $query);
    $isManhole = (bool) preg_match('/맨홀|집수정/u', $query);
    $wantsPart = (bool) preg_match('/뚜껑|덮개|집게|몰드|소켓|조절링|인상링|연결구/u', $query);
    $exclude = [];
    if (!$wantsPart) {
        // 덮개는 제외 안 함(그레이팅덮개 등 보존). 소켓·조절링·인상링·연결구: 상용콘크리트 부속(트렌치 소켓·맨홀 높이 조절링 등, 2026-11-12)
        $exclude[] = $isChannel ? '뚜껑|집게|몰드|소켓|조절링|인상링|연결구' : '집게|몰드|소켓|조절링|인상링|연결구';
    }
    if ($isChannel && !$isManhole) {
        $exclude[] = '맨홀|집수정';
    }
    if (!$exclude) {
        return $rows;
    }
    $re = '/' . implode('|', $exclude) . '/u';
    $filtered = array_values(array_filter(
        $rows,
        static fn($r) => !preg_match($re, (string) ($r['name'] ?? ''))
    ));
    return $filtered ?: $rows;
}

/**
 * 상품명별 대표 규격 1개 선택 (DB 불필요한 순수 함수).
 * 같은 상품명의 변형(두께·색상·곡선 등) 중 같은 조건에서 예상 자재비(수량×단가)가 가장 낮은 것,
 * 수량을 계산할 수 없으면 단가가 가장 낮은 것을 고른다(가격 0은 후순위). 동점이면 기존 관련도 순서 유지.
 * 상품명 순서는 처음 나온 순서(관련도) 그대로.
 * ※ 이전: 관련도 1위가 대표 → 동점 시 id가 큰 변형(예: 80T 칼라, 가장 비쌈)이 뽑혀 비용 비교가 부풀려짐.
 * ※ 폭(첫 치수)이 같고 길이만 다른 변형끼리는 수량을 계산할 수 있는 변형을 먼저 본다 — 예상 자재비와 단가를 한 줄로 비교하면
 *    1m 미만 변형(500*500, 단가만)이 1m 변형(500*1000, 예상 자재비)보다 싸 보여 대표가 되고 표에 예상 비용이 빠지던 문제(2026-11-09).
 *    폭이 다르거나 폭을 확인할 수 없는 변형(이름표 없는 규격·관경 'D450~D1000' 표기 등)은 기존 규칙 그대로.
 */
function ai_pick_representatives(array $rows, float $lengthM, ?float $widthMm, ?float $areaM2): array
{
    $best = [];   // name => [row, key, 수량 계산 여부, 폭]
    $order = [];
    foreach ($rows as $r) {
        $name = (string) ($r['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $price = (int) ($r['price_b2c'] ?? 0);
        [$qty] = ai_estimate_quantity($r, $lengthM, $widthMm, $areaM2);
        $key = $price <= 0 ? PHP_INT_MAX : ($qty !== null ? $qty * $price : $price);
        $cur = [$r, $key, $price > 0 && $qty !== null, ai_row_dim_mm($r, 'w')];
        if (!isset($best[$name])) {
            $order[] = $name;
            $best[$name] = $cur;
            continue;
        }
        $old = $best[$name];
        $sameWidth = $cur[3] !== null && $cur[3] === $old[3];
        if ($sameWidth && $cur[2] !== $old[2]) {
            if ($cur[2]) { $best[$name] = $cur; }   // 같은 폭: 예상 자재비가 계산되는 변형 우선
        } elseif ($key < $old[1]) {
            $best[$name] = $cur;
        }
    }
    return array_map(static fn($n) => $best[$n][0], $order);
}

/** 규격 문자열의 '단위:' 값을 표시용으로 정리 (m 2 → ㎡). 없으면 ''. */
function ai_unit_label(string $spec): string
{
    if (preg_match('/중량\s*\/\s*단위:\s*[^|\/]*\/\s*([^|]+)/u', $spec, $m)) {   // '중량/단위: 2,640kg / 본'(1련암거) → 본
        $u = trim($m[1]);
        return preg_match('/^m\s*[2²]$/iu', $u) ? '㎡' : $u;
    }
    if (!preg_match('/단위:\s*([^|]+)/u', $spec, $m)) {
        return '';
    }
    $u = trim($m[1]);
    return preg_match('/^m\s*[2²]$/iu', $u) ? '㎡' : $u;   // 'm2'·'m²'(상용콘크리트) → ㎡
}

/**
 * 예상 자재비 비교 요약 (순수 함수) — 같은 면적·길이로 계산된 카드가 2개 이상일 때만.
 * 가장 낮은 카드에 cost_lowest 표시. [카드 목록, 요약|null]. 금액은 카드의 est_cost(로그인 등급 단가 기준 자재비, 운반·시공비 별도) — 지어낸 값이 아님.
 * $priceLabel: 요약 근거 문구의 금액 이름(비회원 '판매가', 회원은 '' — 이름 없이 — price_basis_label()).
 */
function ai_cost_summary(array $cards, string $priceLabel = '판매가'): array
{
    $priced = array_values(array_filter($cards, static fn($c) => ($c['est_cost'] ?? 0) > 0));
    if (count($priced) < 2) {
        return [$cards, null];
    }
    usort($priced, static fn($a, $b) => $a['est_cost'] <=> $b['est_cost']);
    [$lo, $hi] = [$priced[0], $priced[count($priced) - 1]];
    $tied = array_values(array_filter($priced, static fn($p) => $p['est_cost'] === $lo['est_cost']));   // 최저 금액이 같은 제품이 여럿(실제 GPT-4o: 인터로킹 i2·U 192,000원 동률인데 하나만 '가장 저렴')
    foreach ($cards as &$c) {
        $c['cost_lowest'] = ($c['est_cost'] ?? null) === $lo['est_cost'] && $lo['est_cost'] > 0;
        if (($c['est_cost'] ?? 0) > 0) {
            $c['cost_diff'] = $c['est_cost'] - $lo['est_cost'];   // 같은 조건 최저 예상 자재비와의 차이(원)
        }
    }
    unset($c);
    return [$cards, [
        'lowest'  => ['name' => $lo['name'], 'spec' => spec_size_label((string) $lo['spec']), 'est_cost' => $lo['est_cost']],
        'highest' => ['name' => $hi['name'], 'spec' => spec_size_label((string) $hi['spec']), 'est_cost' => $hi['est_cost']],
        'difference' => $hi['est_cost'] - $lo['est_cost'],
        'basis' => '같은 면적·길이 기준 ' . ($priceLabel !== '' ? $priceLabel . ' ' : '') . '자재비(운반·시공비 별도)',
    ] + (count($tied) > 1 ? [
        'lowest_tied' => array_map(static fn($t) => $t['name'] . ' ' . spec_size_label((string) $t['spec']), $tied),
        'tie_note'    => '최저 예상 자재비가 같은 제품이 ' . count($tied) . '개입니다. 가장 저렴한 것을 말하거나 담을 때 같은 금액 제품이 여럿이라고 함께 알리고, 고객이 고르지 않았으면 표에서 먼저 나온 제품으로 하되 다른 제품도 같은 금액이라고 안내하세요.',
    ] : [])];
}

/**
 * 추천표(화면) 카드에 로그인 등급의 표시 단가 price_member 를 (다시) 넣는다 — 회원(개인·기업)은 판매가 대신 할인가
 * (app/cart.php display_unit_price, 비회원은 판매가 그대로). 검색 카드는 이미 price_member·est_cost 를 로그인 등급 단가로
 * 만들므로(GPT 답변 금액도 같음) 여기서는 화면 사본의 값을 현재 기준으로 맞춘다. 저장 금액·관리자 화면은 그대로.
 */
function ai_cards_with_member_price(array $cards): array
{
    try {
        $map = display_price_map(array_column($cards, 'id'));
    } catch (\Throwable $e) {
        return $cards;
    }
    foreach ($cards as &$c) {
        $id = (int) ($c['id'] ?? 0);
        if (isset($map[$id])) {
            $c['price_member'] = $map[$id]['price'];
        }
    }
    unset($c);
    return $cards;
}

/**
 * 여러 종류 비교 검색: 종류별 대표 제품을 같은 조건(spec_mm·length_m·area_m2)으로 찾아 한 표로 합친다.
 * 종류별 결과는 기존 tool_search_products 그대로 사용(검색·수량 계산 로직 공유).
 */
function ai_search_compare(array $queries, array $input): string
{
    $per = max(1, min(3, intdiv(max(1, min(10, (int) ($input['limit'] ?? 6))), count($queries))));
    $cards = [];
    $seen  = [];
    $notes = [];
    $missing = [];
    $standards = [];   // 종류별 검색 결과의 국가표준(확인된 기본 정보) — 합쳐서 출처 카드 후보로
    $specMm = (int) ($input['spec_mm'] ?? 0);
    $wantC  = array_filter(['w' => ai_parse_mm($input['width_mm'] ?? null), 'l' => ai_parse_mm($input['product_length_mm'] ?? null)], static fn($v) => $v !== null);
    $condC  = ai_dim_cond_label($wantC, ($wantC && in_array((float) $specMm, array_values($wantC), true)) ? 0 : $specMm);   // '폭 500mm'·'규격 80mm'
    foreach ($queries as $q) {
        $one = $input;
        unset($one['queries']);
        $one['query'] = $q;
        $one['limit'] = $per;
        // 비교는 같은 조건이어야 하므로 규격(예: 차량용 80T)을 완화하지 않는다 → 맞는 규격이 없는 종류는 비교에서 제외
        $one['spec_strict'] = true;
        $res = json_decode(tool_search_products($one), true) ?? [];
        if (empty($res['products'])) {
            $missing[] = $q;
            continue;
        }
        if (!empty($res['note'])) {
            $notes[] = "'{$q}': " . $res['note'];
        }
        foreach ((array) ($res['basics']['standards'] ?? []) as $st) {
            $standards[(string) $st['ref']] = $st;
        }
        foreach ($res['products'] as $c) {
            if (isset($seen[$c['id']])) {
                continue;
            }
            $seen[$c['id']] = true;
            $cards[] = $c;
        }
    }
    if ($missing) {
        $notes[] = "'" . implode("', '", $missing) . "'은(는) "
            . ($condC !== '' ? "{$condC} 제품이 없어" : '해당 조건의 제품을 찾지 못해') . ' 비교에서 빠졌습니다.';
    }

    $allPaving = $specMm > 0 && !array_filter($queries, static fn($q) => !ai_is_paving_query((string) $q));
    if (!$cards && $allPaving) {
        return json_encode(['products' => [], 'count' => 0] + ai_paving_no_match($missing, $specMm, ai_paving_names_with_thickness($specMm)), JSON_UNESCAPED_UNICODE);
    }
    if (!$cards) {
        return json_encode([
            'products' => [],
            'count'    => 0,
            // 규격(예: 두께)을 걸었는데 모든 종류가 없으면 '규격 없음'을 그대로 알린다(다른 두께를 대신 권하지 않음)
            'note'     => $condC !== ''
                ? "'" . implode("', '", $missing) . "'은(는) {$condC} 제품이 없습니다. 조건에 맞지 않는 다른 규격은 보여드리지 않았습니다."
                : '해당 조건에 맞는 제품을 찾지 못했습니다. 용도(수로·배수·경계·차단 등)나 재질을 알려주시면 다시 찾아드리겠습니다.',
            '_sse'     => [],
        ], JSON_UNESCAPED_UNICODE);
    }
    [$cards, $costSummary] = ai_cost_summary($cards, price_basis_label());
    $out = [
        'products' => $cards,
        'count'    => count($cards),
        '_sse'     => [
            ['type' => 'product_cards', 'products' => ai_cards_for_screen(ai_cards_with_member_price($cards)), 'compare' => true,   // 화면용: 회원은 할인가
             'cond' => ai_search_cond(implode(' / ', $queries), $wantC, $specMm, (float) ($input['length_m'] ?? 0), (float) ($input['area_m2'] ?? 0), ai_traffic_value($input['traffic'] ?? null))],
        ],
    ];
    if ($costSummary) { $out['cost_summary'] = $costSummary; }
    if ($standards) { $out['basics'] = ['standards' => array_values($standards), 'products' => []]; }
    if ($notes) { $out['note'] = implode(' ', $notes); }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/**
 * 장바구니에 상품 추가
 * is_active = 1, DB 존재 확인 후 세션 갱신
 */
function tool_add_to_cart(array $input, string $sid): string
{
    $productId = (int) ($input['product_id'] ?? 0);
    $quantity  = (int) ($input['quantity'] ?? 1);

    if ($productId <= 0) {
        return json_encode(['error' => 'product_id 가 유효하지 않습니다.']);
    }
    if ($quantity < 1 || $quantity > 999) {   // 견적 담기 수량 1~999 (장바구니 API 와 같음)
        return json_encode(['error' => '수량은 1~999 범위여야 합니다.'], JSON_UNESCAPED_UNICODE);
    }

    $stmt = db()->prepare(
        'SELECT id, name, price_b2c FROM products WHERE id = :id AND is_active = 1'
    );
    $stmt->execute(['id' => $productId]);
    $product = $stmt->fetch();

    if (!$product) {
        return json_encode(['error' => '상품을 찾을 수 없습니다.', 'product_id' => $productId]);
    }

    $cart = cart_session_read();
    if (($cart[$productId] ?? 0) + $quantity > 999) {
        return json_encode(['error' => '수량은 1~999 범위여야 합니다.'], JSON_UNESCAPED_UNICODE);
    }
    $cart[$productId] = ($cart[$productId] ?? 0) + $quantity;
    cart_session_write($cart);

    [$count, $total] = cart_summary($cart);

    return json_encode([
        'ok'         => true,
        'product_id' => $productId,
        'name'       => $product['name'],
        'quantity'   => $cart[$productId],
        '_sse'       => [
            ['type' => 'cart_update', 'count' => $count, 'total' => $total],
        ],
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 장바구니 조회
 */
function tool_get_cart(string $sid): string
{
    $cart  = cart_session_read();
    $items = items_with_display_prices(cart_fetch_items($cart));   // 견적서 패널·견적 저장과 같은 회원 등급별 단가
    [$count, $total] = cart_summary_from_items($items);

    return json_encode([
        'items' => $items,
        'count' => $count,
        'total' => $total,
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * 견적 생성 (P1) — estimate_create() 공용 함수 위임
 */
function tool_create_estimate(array $input, string $sid): string
{
    $result = estimate_create([
        'customer_name' => $input['customer_name'] ?? '',
        'phone'         => $input['phone']         ?? '',
        'email'         => $input['email']         ?? '',
        'company_name'  => $input['company_name']  ?? '',
        'site_address'  => $input['site_address']  ?? '',
        'memo'          => $input['memo']           ?? '',
        'sid'           => $sid,
    ]);

    if (!$result['ok']) {
        return json_encode($result);
    }

    return json_encode([
        'ok'          => true,
        'estimate_id' => $result['estimate_id'],
        '_sse'        => [
            ['type' => 'estimate_created', 'estimate_id' => $result['estimate_id']],
        ],
    ]);
}

// ─── 장바구니 유틸 + 견적 공용 함수 ─────────────────────────────────────────
// app/cart.php     — cart_session_read/write, cart_fetch_items, cart_summary*, quick_items_fetch
// app/estimate.php — estimate_create() (cart.php 자동 로드)

require_once __DIR__ . '/estimate.php';
require_once __DIR__ . '/external_products.php';
