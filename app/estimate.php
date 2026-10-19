<?php
declare(strict_types=1);
/**
 * 견적 공용 함수 (app/estimate.php)
 *
 * 책임: estimate_create() 공용 로직
 * 의존: app/cart.php (cart_session_read, cart_fetch_items, quick_items_fetch)
 * 금지: UI 렌더링, AI Tool 정의
 *
 * 제공 함수:
 *  estimate_create()         — AI 상담 + 즉시견적 양쪽에서 공유하는 견적 생성 로직
 *  estimate_items_match()    — 화면에서 보낸 품목·수량과 세션 품목 일치 여부
 *  estimate_amount_summary() — 견적 화면 금액 요약(상품 합계 + 예상 운임 → 부가세·최종 예상 금액)
 */

require_once __DIR__ . '/cart.php';

/**
 * 견적 생성 공용 로직 — tool_create_estimate() 와 api/estimate.php 에서 공유
 *
 * v2.0: requested_delivery_at, user_id 자동 연결, mode 지원
 * v2.1: quick_items → cart fallback (GAP-V2-01 해소)
 *       - $_SESSION['cart'] 비어있으면 $_SESSION['quick_items'] fallback
 *       - quick 경로 감지 시 mode='quick' 자동 설정, B2B 단가 적용
 *       - 견적 완료 후 cart + quick_items 모두 초기화
 *
 * @param  array{customer_name:string,phone:string,email?:string,
 *               company_name?:string,site_address?:string,memo?:string,
 *               sid?:string,requested_delivery_at?:string|null,mode?:string,
 *               expected_items?:array|null} $data
 * @return array{ok:bool,estimate_id?:int,error?:string,field?:string}
 */
function estimate_create(array $data): array
{
    $name    = htmlspecialchars(substr(trim($data['customer_name'] ?? ''), 0, 80),  ENT_QUOTES, 'UTF-8');
    $phone   = trim($data['phone'] ?? '');
    $email   = trim($data['email'] ?? '');
    $company = htmlspecialchars(substr(trim($data['company_name'] ?? ''), 0, 120), ENT_QUOTES, 'UTF-8');
    $address = htmlspecialchars(substr(trim($data['site_address']  ?? ''), 0, 255), ENT_QUOTES, 'UTF-8');
    $memo    = htmlspecialchars(substr(trim($data['memo']           ?? ''), 0, 500), ENT_QUOTES, 'UTF-8');
    $mode    = in_array($data['mode'] ?? '', ['chat', 'quick'], true) ? $data['mode'] : 'chat';

    // 필수 필드 검증
    if ($name === '') {
        return ['ok' => false, 'error' => '성함을 입력해주세요.', 'field' => 'customer_name'];
    }
    if ($phone === '') {
        return ['ok' => false, 'error' => '연락처를 입력해주세요.', 'field' => 'phone'];
    }
    if (!preg_match('/^0\d{1,2}-?\d{3,4}-?\d{4}$/', $phone)) {
        return ['ok' => false, 'error' => '올바른 연락처 형식이 아닙니다.', 'field' => 'phone'];
    }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => '올바른 이메일 형식이 아닙니다.', 'field' => 'email'];
    }

    // requested_delivery_at 검증: ISO 8601, 현재 시각 이후
    $deliveryAt  = null;
    $rawDelivery = $data['requested_delivery_at'] ?? null;
    if ($rawDelivery !== null && $rawDelivery !== '') {
        $dt = \DateTime::createFromFormat(\DateTime::ATOM, $rawDelivery)
           ?: \DateTime::createFromFormat('Y-m-d\TH:i:s', $rawDelivery)
           ?: \DateTime::createFromFormat('Y-m-d', substr($rawDelivery, 0, 10));
        if (!$dt) {
            return ['ok' => false, 'error' => '배송 요청 일시 형식이 올바르지 않습니다.', 'field' => 'requested_delivery_at'];
        }
        // 오늘 날짜 기준으로 비교 (시간 무시 — 당일 신청 허용)
        if ($dt->format('Y-m-d') < (new \DateTime())->format('Y-m-d')) {
            return ['ok' => false, 'error' => '배송 요청 일시는 오늘 이후여야 합니다.', 'field' => 'requested_delivery_at'];
        }
        $deliveryAt = $dt->format('Y-m-d H:i:s');
    }

    // ── 품목 확인 ─────────────────────────────────────────────────────────────
    // 1순위: $_SESSION['cart'] (AI 상담 경로)
    // 2순위: $_SESSION['quick_items'] (기업회원 즉시견적 경로 — GAP-V2-01 해소)
    $cart  = cart_session_read();
    $items = cart_fetch_items($cart);

    // AI 상담(chat) 경로는 cart 전용 — quick_items 폴백 금지 (다른 세션 데이터 혼입 방지)
    if (empty($items) && $mode !== 'chat') {
        $quickItems = $_SESSION['quick_items'] ?? [];
        if (!empty($quickItems)) {
            $items = quick_items_fetch($quickItems);
            $mode  = 'quick';
        }
    }

    if (empty($items)) {
        return ['ok' => false, 'error' => '견적서가 비어있습니다.'];
    }

    // 신청 직전 수량 검증: 화면에서 보낸 품목·수량(expected_items)이 세션 품목과 다르면 접수하지 않는다.
    // (expected_items 를 넘기지 않는 호출부 — AI 상담 tool 등 — 는 기존과 동일하게 검증 생략)
    if (isset($data['expected_items']) && is_array($data['expected_items'])
        && !estimate_items_match($items, $data['expected_items'])) {
        return ['ok' => false, 'error' => '화면의 품목·수량이 저장된 견적 내용과 다릅니다. 화면을 새로고침한 뒤 수량을 확인하고 다시 신청해 주세요.'];
    }

    // 로그인 상태이면 user_id 자동 연결
    $userId = null;
    if (session_status() === PHP_SESSION_ACTIVE) {
        $sessionUser = $_SESSION['user'] ?? null;
        if (is_array($sessionUser) && isset($sessionUser['id'])) {
            $userId = (int) $sessionUser['id'];
        }
    }

    // 트랜잭션: estimates + estimate_items 삽입
    $pdo = db();
    try {
        $pdo->beginTransaction();

        $pdo->prepare(
            'INSERT INTO estimates
                (user_id, customer_name, company_name, phone, email,
                 site_address, memo, mode, requested_delivery_at, created_at)
             VALUES
                (:uid, :name, :company, :phone, :email,
                 :address, :memo, :mode, :delivery_at, NOW())'
        )->execute([
            'uid'         => $userId,
            'name'        => $name,
            'company'     => $company ?: null,
            'phone'       => $phone,
            'email'       => $email   ?: null,
            'address'     => $address ?: null,
            'memo'        => $memo    ?: null,
            'mode'        => $mode,
            'delivery_at' => $deliveryAt,
        ]);
        $estimateId = (int) $pdo->lastInsertId();

        $itemStmt = $pdo->prepare(
            'INSERT INTO estimate_items (estimate_id, product_id, quantity, unit_price)
             VALUES (:eid, :pid, :qty, :price)'
        );
        foreach ($items as $item) {
            $itemStmt->execute([
                'eid'   => $estimateId,
                'pid'   => $item['product_id'],
                'qty'   => $item['quantity'],
                'price' => $item['unit_price'],
            ]);
        }

        $pdo->commit();
    } catch (\Throwable $e) {
        $pdo->rollBack();
        return ['ok' => false, 'error' => '견적 생성 중 오류가 발생했습니다.'];
    }

    // 견적 완료 후 cart + quick_items 모두 초기화
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $_SESSION['cart']        = [];
    $_SESSION['quick_items'] = [];
    session_write_close();

    return ['ok' => true, 'estimate_id' => $estimateId];
}

/**
 * 화면에서 보낸 품목·수량과 세션 품목(cart_fetch_items / quick_items_fetch 결과)이 정확히 일치하는지.
 * 품목 구성(product_id 집합)과 각 수량이 모두 같아야 true. 형식 오류·중복 product_id 는 false.
 *
 * @param list<array{product_id:int,quantity:int}> $items     세션 기준 품목
 * @param array                                     $expected 클라이언트가 보낸 [{product_id, quantity}, ...]
 */
function estimate_items_match(array $items, array $expected): bool
{
    $actual = [];
    foreach ($items as $it) {
        $actual[(int) $it['product_id']] = (int) $it['quantity'];
    }
    $sent = [];
    foreach ($expected as $e) {
        if (!is_array($e)) { return false; }
        $pid = (int) ($e['product_id'] ?? 0);
        if ($pid <= 0 || isset($sent[$pid])) { return false; }
        $sent[$pid] = (int) ($e['quantity'] ?? 0);
    }
    ksort($actual);
    ksort($sent);
    return $actual === $sent;
}

/**
 * 견적 화면 금액 요약. 과세 대상 = 상품 합계 + 예상 운임, 부가세 = 과세 대상 × 10%(원 단위 반올림).
 * 운임을 산출하지 못했으면($freight = null) 운임 없이 상품 합계만 과세 대상으로 계산하고 freight_included=false
 * — 이 경우 화면은 최종 금액을 확정 금액처럼 표시하지 않는다.
 * ⚠️ public/estimate.php 의 JS estSummary() 와 같은 공식(정수 연산)이다. 변경 시 함께 수정.
 *
 * @return array{taxable:int, vat:int, final:int, freight_included:bool}
 */
function estimate_amount_summary(int $goodsTotal, ?int $freight): array
{
    $taxable = max(0, $goodsTotal) + max(0, $freight ?? 0);
    $vat     = intdiv($taxable + 5, 10); // round(taxable × 0.1) 를 부동소수 오차 없이
    return [
        'taxable'          => $taxable,
        'vat'              => $vat,
        'final'            => $taxable + $vat,
        'freight_included' => $freight !== null,
    ];
}
