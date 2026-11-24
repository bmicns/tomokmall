<?php
declare(strict_types=1);
/**
 * AI 상담 견적 카드 (app/quote_draft.php) — 사용자 결정 2026-11-23 A8(B안)
 *
 * 책임: 상담에서 견적서에 담은 품목의 한 장 요약(품목별 단가·소계, 상품 합계, 예상 운임, 부가세, 최종 예상 금액)을
 *       서버에서 계산해 상담 화면 카드(SSE 'quote_draft')와 AI 도구 결과로 돌려준다. 배송 지역(시·도)은 세션에 기억해
 *       견적 요청 화면(estimate.php)의 배송 지역을 미리 고른다.
 * 계산 기준: 견적 요청 화면과 같다 — 단가 items_with_display_prices(회원 등급별 표시 단가, 비회원 = 판매가),
 *       운임 freight_cart_lines + freight_estimate_lines(app/freight.php), 금액 estimate_amount_summary(app/estimate.php).
 * 금지: 가격·운임 계산식 변경, 저장(견적 접수는 estimate_create 그대로). 운임을 모르면 0원으로 보이지 않게 '별도'로 둔다.
 */

require_once __DIR__ . '/cart.php';
require_once __DIR__ . '/estimate.php';

// AI 도구(add_to_cart·get_cart) region 설명 — app/ai_tools.php
const QUOTE_REGION_PARAM_DESC = "배송 지역 시·도(서울·경기·인천·강원·충북·충남·대전·세종·전북·전남·광주·경북·경남·대구·울산·부산·제주). 고객이 말한 경우에만 넣는다. 시·군 이름만 말하면 그 시·도로 바꿔 넣고(예: 수원 → 경기), 어느 시·도인지 모르면 넣지 말고 묻는다.";

const QUOTE_DRAFT_NOTE = '예상 금액입니다. 실제 금액은 담당자 확인 후 확정됩니다.';

/**
 * 배송 지역을 기억하는 세션 칸 — 기본 AI 상담·견적 요청 화면은 'quote_region'(사이트 공통),
 * 빠른 견적 창은 대화마다 'qc_region_<상담 키>'([새 대화]면 새 지역 — 사용자 결정 11-24). 견적서로 옮길 때 공통 칸으로 복사(quick.php).
 */
function quote_region_key(): string
{
    return cart_store_is_quick_list() ? 'qc_region_' . substr(cart_store_key(), strlen('qc_list_')) : 'quote_region';
}

/** 세션의 상담 배송 지역(시·도 키) — 없거나 목록에 없는 값이면 null */
function quote_region_get(): ?string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $r = $_SESSION[quote_region_key()] ?? null;
    return is_string($r) && isset(FREIGHT_SIDO_COORDS[$r]) ? $r : null;
}

/** 상담 배송 지역 저장(시·도 키만) 후 세션 잠금 해제 — cart_session_write 와 같은 방식 */
function quote_region_set(string $region): void
{
    if (!isset(FREIGHT_SIDO_COORDS[$region])) { return; }
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $_SESSION[quote_region_key()] = $region;
    session_write_close();
}

/**
 * 견적 카드 데이터 (순수 함수 — DB·세션 없음).
 *
 * @param list<array{product_id:int,name:string,spec?:string,quantity:int,unit_price:int,subtotal:int}> $items  표시 단가 적용된 품목
 * @param array{status:string,freight?:int,km?:float,total_kg?:float,origins?:array,missing_names?:array} $freight
 *        status: ok | none(지역 없음) | missing_weight | negotiate | error | empty(품목 없음)
 * @return array  SSE 'quote_draft' 이벤트(그대로 AI 도구 결과에도 씀)
 */
function quote_draft_build(array $items, array $freight, ?string $region): array
{
    $rows       = [];
    $goods      = 0;
    $hasInquiry = false;
    foreach ($items as $it) {
        $unit = (int) ($it['unit_price'] ?? 0);
        $qty  = (int) ($it['quantity'] ?? 0);
        $sub  = $unit > 0 ? $unit * $qty : 0;
        if ($unit > 0) { $goods += $sub; } else { $hasInquiry = true; }
        $rows[] = [
            'product_id' => (int) ($it['product_id'] ?? 0),
            'name'       => (string) ($it['name'] ?? ''),
            'spec'       => parse_spec_field((string) ($it['spec'] ?? ''))['spec'],
            'quantity'   => $qty,
            'unit_price' => $unit,      // 0 = 가격 문의(금액에서 제외, O4)
            'subtotal'   => $sub,
        ];
    }

    $status = $rows ? (string) ($freight['status'] ?? 'none') : 'empty';
    if (!in_array($status, ['ok', 'none', 'missing_weight', 'negotiate', 'error', 'empty'], true)) {
        $status = 'error';
    }
    $f = ['status' => $status];
    if ($status === 'ok') {
        $f += [
            'amount'   => (int) ($freight['freight'] ?? 0),
            'km'       => (float) ($freight['km'] ?? 0),
            'total_kg' => (float) ($freight['total_kg'] ?? 0),
            'origins'  => array_values(array_map('strval', (array) ($freight['origins'] ?? []))),
        ];
    } elseif ($status === 'missing_weight') {
        $f['missing_names'] = array_values(array_map('strval', (array) ($freight['missing_names'] ?? [])));
    }

    $fOk = $status === 'ok';
    $sum = estimate_amount_summary($goods, $fOk ? $f['amount'] : null);   // 견적 요청 화면과 같은 공식
    $f['text'] = match ($status) {
        'ok'             => number_format($f['amount']) . '원',
        'none'           => '배송 지역을 알려 주시면 계산해 드립니다',
        'missing_weight' => '별도 확인(중량 정보 없는 품목 있음)',
        'negotiate'      => '별도 협의',
        'error'          => '별도 확인',
        default          => '-',
    };

    $suffix = '';
    if ($goods > 0) {
        $suffix = ($fOk ? '' : ' + 운임 별도') . ($hasInquiry ? ' + 문의 품목' : '');
    }
    $notes = [QUOTE_DRAFT_NOTE];
    if ($fOk) {
        $notes[] = '예상 운임은 화물 시세 기준 참고 금액(지게차 상하차·편도)입니다.';
    }
    if ($hasInquiry) {
        $notes[] = "'가격 문의' 품목은 금액에 포함되지 않았으며 담당자가 따로 안내드립니다.";
    }

    return [
        'type'        => 'quote_draft',
        'region'      => $region,
        'items'       => $rows,
        'count'       => count($rows),
        'goods_total' => $goods,
        'has_inquiry' => $hasInquiry,
        'freight'     => $f,
        'vat'         => $goods > 0 ? $sum['vat'] : null,
        'final'       => $goods > 0 ? $sum['final'] : null,   // null = 담당자 문의(금액 있는 품목 없음)
        'final_suffix' => $suffix,
        'vat_basis'   => $fOk ? '상품 합계 + 예상 운임' : '상품 합계(운임 제외)',
        'notes'       => $notes,
    ];
}

/**
 * 지금 세션 견적서(장바구니)의 견적 카드 — DB 조회. $region 이 null 이면 운임 없이(지역 묻기).
 */
function quote_draft_current(?string $region): array
{
    $items = items_with_display_prices(cart_fetch_items(cart_session_read()));
    if (!$items) {
        return quote_draft_build([], ['status' => 'empty'], $region);
    }
    if ($region === null) {
        return quote_draft_build($items, ['status' => 'none'], null);
    }
    $qtyById = [];
    foreach ($items as $it) { $qtyById[(int) $it['product_id']] = (int) $it['quantity']; }
    $pdo = db();
    $cl  = $pdo ? freight_cart_lines($pdo, $qtyById) : null;
    if ($cl === null) {
        return quote_draft_build($items, ['status' => 'error'], $region);
    }
    $est = freight_estimate_lines($cl['lines'], $region);
    if ($est['status'] === 'invalid') {
        $est = ['status' => 'error'];
    } elseif ($est['status'] === 'missing_weight') {
        $est['missing_names'] = $cl['missing_names'];
    }
    return quote_draft_build($items, $est, $region);
}
