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
 *  estimate_create() — AI 상담 + 즉시견적 양쪽에서 공유하는 견적 생성 로직
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
 *               sid?:string,requested_delivery_at?:string|null,mode?:string} $data
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
