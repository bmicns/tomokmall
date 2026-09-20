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
                        'limit'    => ['type' => 'integer', 'description' => '최대 결과 수 (기본 5, 최대 10)'],
                        'spec_mm'  => ['type' => 'number',  'description' => '규격 치수(폭 또는 관경, mm). 예: 폭 50cm → 500, 관경 300mm → 300. 해당 치수 제품만 필터링.'],
                        'length_m' => ['type' => 'number',  'description' => '설치 총 길이/연장(미터). 예: 100m 수로 → 100. 선형 제품의 소요 수량 자동 계산에 사용.'],
                        'area_m2'  => ['type' => 'number',  'description' => '설치 면적(㎡). 예: 보도블록 200㎡ → 200. 면적 단위(㎡) 제품의 소요 수량 계산에 사용.'],
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
 * 소요 수량 자동 계산.
 * - 단위 ㎡/m2 : 면적 기준. area_m2 주면 그대로, 없으면 길이×폭(spec_mm)
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

    // 면적 단위 (㎡ / m2)
    if ($unit === '㎡' || strpos($unitNorm, 'm2') !== false || strpos($unit, '㎡') !== false) {
        if ($areaM2 && $areaM2 > 0) {
            $qty = (int) ceil($areaM2);
            return [$qty, sprintf('%s㎡ → %d㎡', $fmt($areaM2), $qty)];
        }
        if ($lengthM > 0 && $widthMm && $widthMm > 0) {
            $area = $lengthM * ($widthMm / 1000.0);
            $qty  = (int) ceil($area);
            return [$qty, sprintf('%sm × %sm = %s㎡ → %d㎡', $fmt($lengthM), $fmt($widthMm / 1000), $fmt($area), $qty)];
        }
        return [null, ''];
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
 * AI 카드용으로 ①상품명 중복 제거(서로 다른 제품), ②상품명에 검색어가 들어간 것 우선 재정렬.
 */
function tool_search_products(array $input): string
{
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
        if (!$rows && $specMm > 0) {
            $try = search_products($qText, $fetch);
            if ($try) {
                $rows = $try;
                $note = "규격 {$specMm}mm에 딱 맞는 제품이 없어, 해당 품목의 다른 규격을 함께 보여드립니다.";
            }
        }
    }

    // ⓪ 용도 배타 필터: '수로/배수로/측구/트렌치' 본체 검색이면
    //    ① 맨홀/집수정(점 설치물)  ② 뚜껑·집게·몰드(부속·거푸집)를 결과에서 제외한다.
    //    단, 고객이 그 부속을 직접 찾으면(query에 언급) 제외하지 않는다.
    //    가드: 필터 후 결과가 0이면 원복(빈 결과 방지).
    $isChannel = (bool) preg_match('/수로|배수로|측구|트렌치|농수로/u', $query);
    $isManhole = (bool) preg_match('/맨홀|집수정/u', $query);
    $wantsPart = (bool) preg_match('/뚜껑|덮개|집게|몰드/u', $query);
    if ($isChannel) {
        $exclude = [];
        if (!$isManhole) { $exclude[] = '맨홀|집수정'; }
        if (!$wantsPart) { $exclude[] = '뚜껑|집게|몰드'; } // 덮개는 제외 안 함(그레이팅덮개 등 보존)
        if ($exclude) {
            $re = '/' . implode('|', $exclude) . '/u';
            $filtered = array_values(array_filter(
                $rows,
                static fn($r) => !preg_match($re, (string) ($r['name'] ?? ''))
            ));
            if ($filtered) {
                $rows = $filtered;
            }
        }
    }

    // ① 상품명 기준 중복 제거 (규격 변형 다수 → 대표 1개, 관련도 상위가 대표)
    $seen = [];
    $distinct = [];
    foreach ($rows as $r) {
        $name = (string) ($r['name'] ?? '');
        if ($name === '' || isset($seen[$name])) {
            continue;
        }
        $seen[$name] = true;
        $distinct[] = $r;
    }

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

    // 소요 수량 자동 계산 입력 (고객이 길이/면적을 말한 경우)
    $lengthM = (float) ($input['length_m'] ?? 0);
    $areaM2  = (float) ($input['area_m2'] ?? 0);

    $cards = array_map(function ($p) use ($lengthM, $specMm, $areaM2) {
        [$qty, $basis] = ai_estimate_quantity(
            $p,
            $lengthM,
            $specMm > 0 ? (float) $specMm : null,
            $areaM2 > 0 ? $areaM2 : null
        );
        return [
            'id'            => (int)  $p['id'],
            'name'          =>        $p['name'],
            'spec'          =>        $p['spec'],
            'price_b2c'     => (int)  $p['price_b2c'],
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
            'note'     => '해당 조건에 맞는 제품을 찾지 못했습니다. 용도(수로·배수·경계·차단 등)나 재질을 알려주시면 다시 찾아드리겠습니다.',
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
