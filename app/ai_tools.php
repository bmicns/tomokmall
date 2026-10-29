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
                'description' => '데이터베이스에서 건설자재를 검색합니다. ★중요: query에는 "제품명/카테고리/용도" 텍스트만 넣고 숫자(치수·길이·수량)는 넣지 마세요. 규격 치수(폭/관경 mm)는 spec_mm, 설치 길이(m)는 length_m, 면적(㎡)은 area_m2 파라미터로 분리해 전달하세요. 예: 폭500 수로용 그레이팅 → query "그레이팅 수로용 중하중", spec_mm 500, length_m 100. 이렇게 하면 정확한 규격 필터 + 제품별 소요 수량이 자동 계산됩니다.',
                'parameters'  => [
                    'type'       => 'object',
                    'properties' => [
                        'query'    => ['type' => 'string',  'description' => '검색 텍스트: 카테고리/제품명 + 용도/조건(중하중·수로용·우수 등). 숫자(치수·길이·수량)는 절대 넣지 말 것. 예: "그레이팅 수로용 중하중", "이중벽관 하수", "경계석 화강암".'],
                        'queries'  => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => '여러 종류를 한 표로 비교할 때만 사용(2~4개). 예: ["인터로킹 블록", "인조화강블록", "점토블럭"]. 각 종류별 대표 제품이 한 표에 모이고 같은 면적/길이 기준 소요 수량·예상 금액이 함께 계산됩니다. 이때 query에는 대표 품목명을 넣으세요.'],
                        'limit'    => ['type' => 'integer', 'description' => '최대 결과 수 (기본 5, 최대 10)'],
                        'spec_mm'  => ['type' => 'number',  'description' => '규격 치수(폭 또는 관경, mm). 예: 폭 50cm → 500, 관경 300mm → 300. 해당 치수 제품만 필터링.'],
                        'length_m' => ['type' => 'number',  'description' => '설치 총 길이/연장(미터). 예: 100m 수로 → 100. 선형 제품의 소요 수량 자동 계산에 사용.'],
                        'area_m2'  => ['type' => 'number',  'description' => '설치 면적(㎡). 예: 보도블록 200㎡ → 200. 면적 단위(㎡) 제품의 소요 수량 계산에 사용.'],
                        'spec_strict' => ['type' => 'boolean', 'description' => 'spec_mm이 반드시 지켜야 하는 조건이면 true(예: 차량이 올라가는 곳의 블록 두께 80). 맞는 규격이 없으면 다른 규격을 보여주지 않습니다. queries 비교에서는 항상 적용됩니다.'],
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
        default           => json_encode(['error' => '알 수 없는 도구입니다: ' . htmlspecialchars($toolName, ENT_QUOTES, 'UTF-8')]),
    };
}

// ─── 개별 Tool 핸들러 ────────────────────────────────────────────────────────

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
    $unitNorm = str_replace(' ', '', mb_strtolower($unit));
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

    // 선형(본/개/조 등): 규격 최대 치수 = 1개 길이(mm)
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

    $rawQuery = trim((string) ($input['query'] ?? ''));
    if ($rawQuery === '') {
        return json_encode(['error' => 'query 파라미터가 필요합니다.']);
    }

    $limit  = max(1, min(10, (int) ($input['limit'] ?? 5)));
    $specMm = (int) ($input['spec_mm'] ?? 0);

    // ★숫자 역할 분리: AI 질의 텍스트의 떠도는 숫자(길이·수량 등)는 규격 오필터를 유발하므로 제거.
    //   규격 치수 필터는 오직 spec_mm 로만 적용(검색어에 숫자로 붙여 search_products의 규격 인지에 태움).
    $qText = trim(preg_replace('/\s+/', ' ', preg_replace('/\d+/', ' ', $rawQuery) ?? ''));
    if ($qText === '') {
        $qText = $rawQuery; // 안전장치: 전부 숫자였으면 원본 사용
    }
    $effQuery = $specMm > 0 ? ($qText . ' ' . $specMm) : $qText;

    // 중복 제거·재정렬 여지를 두려고 넉넉히 조회
    $fetch = max(40, $limit * 8);
    $rows  = search_products($effQuery, $fetch);
    $query = $qText; // 이후 용도 필터·하이라이트 재정렬은 텍스트 기준

    // ★0건 대안 제시: 데드엔드("제품 선택하세요"만 노출) 방지
    //   ① 오타 교정어로 재검색  ② 규격(spec_mm)이 너무 좁으면 규격 빼고 근접 대안
    $note      = null;   // 모델이 손님에게 전달할 안내(교정/대안 사유)
    $corrected = null;   // 교정된 검색어(있으면)
    if (!$rows) {
        // ① 오타 교정
        if (function_exists('suggest_correction')) {
            $sc = suggest_correction($qText);
            if ($sc) {
                $ceff = $specMm > 0 ? ($sc['query'] . ' ' . $specMm) : $sc['query'];
                $try  = search_products($ceff, $fetch);
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
            $try = search_products($qText, $fetch);
            if ($try) {
                $rows = $try;
                $note = "규격 {$specMm}mm에 딱 맞는 제품이 없어, 해당 품목의 다른 규격을 함께 보여드립니다.";
            }
        }
    }

    // ⓪ 용도 배타 필터: 부속(집게·몰드 등) 제외
    $rows = ai_exclude_accessories($rows, $query);

    // ⓪-2 포장 블록의 엄격 규격(spec_strict)은 '두께'로 본다 — 두께(80T 또는 가로*세로*두께의 셋째 값)가 같은 포장 블록만.
    //      가로·세로 치수가 우연히 같은 제품(200*100*60T 의 100, 호안블록·속빈블록 등)이 두께 기준 검색에 섞이는 것 방지.
    if ($specMm > 0 && !empty($input['spec_strict']) && ai_is_paving_query($query)) {
        $rows = ai_filter_paving_thickness($rows, $specMm);
    }

    // 소요 수량 자동 계산 입력 (고객이 길이/면적을 말한 경우)
    $lengthM = (float) ($input['length_m'] ?? 0);
    $areaM2  = (float) ($input['area_m2'] ?? 0);

    // ① 상품명 기준 중복 제거 (규격 변형 다수 → 대표 1개: 같은 조건에서 예상 자재비가 가장 낮은 변형)
    $distinct = ai_pick_representatives($rows, $lengthM, $specMm > 0 ? (float) $specMm : null, $areaM2 > 0 ? $areaM2 : null);

    // ② 상품명에 검색어(숫자 규격 제외 텍스트)가 들어간 상품을 우선 (설명만 걸린 노이즈 후순위)
    $textTerms = array_values(array_filter(
        search_expand_terms($query),
        static fn($t) => !preg_match('/^\d+$/', (string) $t)
    ));
    if ($textTerms) {
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
        // PHP 8 usort는 안정 정렬 → 동점이면 기존 관련도 순서 유지
        usort($distinct, static fn($a, $b) => $nameHits((string) $b['name']) <=> $nameHits((string) $a['name']));
    }

    $products = array_slice($distinct, 0, $limit);

    $cards = array_map(function ($p) use ($lengthM, $specMm, $areaM2) {
        [$qty, $basis] = ai_estimate_quantity(
            $p,
            $lengthM,
            $specMm > 0 ? (float) $specMm : null,
            $areaM2 > 0 ? $areaM2 : null
        );
        $price = (int) $p['price_b2c'];
        return [
            'id'            => (int)  $p['id'],
            'name'          =>        $p['name'],
            'spec'          =>        $p['spec'],
            'price_b2c'     => $price,
            'unit'          => ai_unit_label((string) $p['spec']),
            'est_cost'      => ($qty !== null && $price > 0) ? $qty * $price : null, // 판매가 기준 자재비(운반·시공비 별도)
            'category_name' =>        $p['category_name'],
            'image_url'     =>        $p['image_url']  ?? null,
            'stock_qty'     => (int)  $p['stock_qty'],
            'suggested_qty' => $qty,     // null 이면 계산 불가
            'qty_basis'     => $basis,   // 근거 문구 (예: "100m ÷ 2m = 50본")
        ];
    }, $products);

    // 그래도 0건이면 손님을 위한 대안 안내(모델이 되묻지 말고 이 안내를 전달)
    if (empty($cards)) {
        $out = [
            'products' => [],
            'count'    => 0,
            'note'     => ($specMm > 0 && !empty($input['spec_strict']))
                ? "'{$qText}'은(는) 규격 {$specMm}mm 제품이 없습니다. 조건에 맞지 않는 다른 규격은 보여드리지 않았습니다."
                : '해당 조건에 맞는 제품을 찾지 못했습니다. 용도(수로·배수·경계·차단 등)나 재질을 알려주시면 다시 찾아드리겠습니다.',
            '_sse'     => [],
        ];
        if ($corrected !== null) { $out['corrected'] = $corrected; }
        return json_encode($out, JSON_UNESCAPED_UNICODE);
    }

    $out = [
        'products' => $cards,
        'count'    => count($cards),
        '_sse'     => [
            ['type' => 'product_cards', 'products' => $cards],
        ],
    ];
    if ($note !== null)      { $out['note']      = $note; }      // 교정/규격완화 사유
    if ($corrected !== null) { $out['corrected'] = $corrected; }
    return json_encode($out, JSON_UNESCAPED_UNICODE);
}

/** 포장 블록 검색어인지 (순수 함수) — 블록·인터로킹·보도·점토·투수·잔디·평판 등. 호안·축조·옹벽 등 구조용은 제외 */
function ai_is_paving_query(string $query): bool
{
    return preg_match('/블[록럭]|인터로킹|보도|보차도|점토|벽돌|투수|잔디|평판|판석|포장/u', $query) === 1
        && preg_match('/호안|축조|옹벽|속빈|조적/u', $query) !== 1;
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
    $wantsPart = (bool) preg_match('/뚜껑|덮개|집게|몰드/u', $query);
    $exclude = [];
    if (!$wantsPart) {
        $exclude[] = $isChannel ? '뚜껑|집게|몰드' : '집게|몰드'; // 덮개는 제외 안 함(그레이팅덮개 등 보존)
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
 */
function ai_pick_representatives(array $rows, float $lengthM, ?float $widthMm, ?float $areaM2): array
{
    $best = [];   // name => [row, key]
    $order = [];
    foreach ($rows as $r) {
        $name = (string) ($r['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $price = (int) ($r['price_b2c'] ?? 0);
        [$qty] = ai_estimate_quantity($r, $lengthM, $widthMm, $areaM2);
        $key = $price <= 0 ? PHP_INT_MAX : ($qty !== null ? $qty * $price : $price);
        if (!isset($best[$name])) {
            $order[] = $name;
            $best[$name] = [$r, $key];
        } elseif ($key < $best[$name][1]) {
            $best[$name] = [$r, $key];
        }
    }
    return array_map(static fn($n) => $best[$n][0], $order);
}

/** 규격 문자열의 '단위:' 값을 표시용으로 정리 (m 2 → ㎡). 없으면 ''. */
function ai_unit_label(string $spec): string
{
    if (!preg_match('/단위:\s*([^|]+)/u', $spec, $m)) {
        return '';
    }
    $u = trim($m[1]);
    return preg_match('/^m\s*2$/iu', $u) ? '㎡' : $u;
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
    $specMm = (int) ($input['spec_mm'] ?? 0);
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
            . ($specMm > 0 ? "규격 {$specMm}mm 제품이 없어" : '해당 조건의 제품을 찾지 못해') . ' 비교에서 빠졌습니다.';
    }

    if (!$cards) {
        return json_encode([
            'products' => [],
            'count'    => 0,
            // 규격(예: 두께)을 걸었는데 모든 종류가 없으면 '규격 없음'을 그대로 알린다(다른 두께를 대신 권하지 않음)
            'note'     => $specMm > 0
                ? "'" . implode("', '", $missing) . "'은(는) 규격 {$specMm}mm 제품이 없습니다. 조건에 맞지 않는 다른 규격은 보여드리지 않았습니다."
                : '해당 조건에 맞는 제품을 찾지 못했습니다. 용도(수로·배수·경계·차단 등)나 재질을 알려주시면 다시 찾아드리겠습니다.',
            '_sse'     => [],
        ], JSON_UNESCAPED_UNICODE);
    }
    $out = [
        'products' => $cards,
        'count'    => count($cards),
        '_sse'     => [
            ['type' => 'product_cards', 'products' => $cards, 'compare' => true],
        ],
    ];
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
    $quantity  = max(1, min(9999, (int) ($input['quantity'] ?? 1)));

    if ($productId <= 0) {
        return json_encode(['error' => 'product_id 가 유효하지 않습니다.']);
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
    $items = cart_fetch_items($cart);
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
