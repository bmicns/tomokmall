<?php
declare(strict_types=1);
/**
 * 직원 업체 API
 * GET  ?list=1              — 전체 업체 목록
 * GET  ?id=N                — 업체 상세
 * GET  ?product_id=N        — 품목-업체 연결 목록
 * POST action=add_product_vendor  — 품목-업체 추가 + 그 업체 상품 줄(products 출처 vendor-<id>·출처ID link-<품목 id>, 비활성으로 시작,
 *      매입가 = 공급단가, 정가 = 원래 상품 정가 — 사용자 결정 2026-11-17). 다시 추가(공급단가 변경)는 그 줄의 매입가만 고침
 * GET  ?impact=N            — 업체 비활성 전 영향(제품관리 상품 수·연결 품목 수·주거래 품목 수)
 * POST action=remove_product_vendor — 삭제 + 그 업체 상품 줄 비활성(행은 남김)
 * POST action=set_primary   — 품목의 주거래 업체 바꾸기(연결 id, 공급단가는 그대로)
 * POST action=set_vendor_active — 업체 활성/비활성(사용자 결정 2026-11-17):
 *      비활성 → 그 업체 상품(제품관리 출처 vendor-<id>: 원본 syc_products + 고객용 반영본 products) 전부 비활성.
 *      다시 활성 → 업체만 활성. 상품은 그대로 비활성(관리자가 제품관리에서 직접 활성으로). 가격 칸은 건드리지 않음
 * POST (id 없음)            — 업체 등록
 * POST (id 있음)            — 업체 수정
 *
 * 오류 응답: 입력 검증 실패(파라미터·업체 없음·업체명·이메일)도 HTTP 200 + {"ok":false,"error":…}
 *           (닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 JSON 문구가 사라짐 — quote.php 와 같은 방식)
 *           405(메서드)·500(예외 — 상세는 서버 로그, 화면에는 일반 문구)·401/403(require_staff)은 상태 코드 유지
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (\Throwable $e) {
    // 내부 오류 문구(SQL 등)는 화면에 보내지 않고 서버 로그에만 남긴다
    error_log('[api/staff/vendors] ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
});

require_staff();
$method = $_SERVER['REQUEST_METHOD'];

// ── GET ────────────────────────────────────────────────────────
if ($method === 'GET') {
    // 전체 업체 목록 (셀렉트용)
    if (isset($_GET['list'])) {
        $rows = db()->query('SELECT id, name, region, phone FROM vendors WHERE is_active=1 ORDER BY name')->fetchAll();
        echo json_encode(['ok' => true, 'data' => $rows]);
        exit;
    }
    // 업체 상세
    if (isset($_GET['id'])) {
        $id = (int)$_GET['id'];
        $st = db()->prepare('SELECT * FROM vendors WHERE id=:id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'없음']); exit; }
        echo json_encode(['ok' => true, 'data' => $row]);
        exit;
    }
    // 업체 비활성 전 영향(확인 창 숫자)
    if (isset($_GET['impact'])) {
        $id = (int)$_GET['impact'];
        $st = db()->prepare('SELECT id, name, is_active FROM vendors WHERE id=:id');
        $st->execute(['id' => $id]);
        $v = $st->fetch();
        if (!$v) { echo json_encode(['ok'=>false,'error'=>'업체를 찾지 못했습니다.'], JSON_UNESCAPED_UNICODE); exit; }
        echo json_encode(['ok' => true, 'data' => ['id' => (int)$v['id'], 'name' => $v['name'], 'is_active' => (int)$v['is_active']] + vendor_impact(db(), $id)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 품목-업체 연결 목록
    if (isset($_GET['product_id'])) {
        $pid = (int)$_GET['product_id'];
        $st  = db()->prepare(
            'SELECT pv.id, pv.vendor_id, pv.supply_price, pv.lead_days, pv.is_primary,
                    v.name AS vendor_name, v.phone, v.region, v.is_active AS vendor_active
             FROM product_vendors pv
             JOIN vendors v ON v.id=pv.vendor_id
             WHERE pv.product_id=:pid
             ORDER BY pv.is_primary DESC, v.is_active DESC, v.name'
        );
        $st->execute(['pid' => $pid]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll()]);
        exit;
    }
    echo json_encode(['ok' => false, 'error' => '파라미터 필요']);
    exit;
}

// ── POST ────────────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    // 품목-업체 추가
    if ($action === 'add_product_vendor') {
        $pid   = (int)($body['product_id']   ?? 0);
        $vid   = (int)($body['vendor_id']    ?? 0);
        $price = max(0, (int)($body['supply_price'] ?? 0));
        $lead  = max(0, (int)($body['lead_days']    ?? 0));
        $prim  = ($body['is_primary'] ?? 0) ? 1 : 0;

        if (!$pid || !$vid) { echo json_encode(['ok'=>false,'error'=>'product_id, vendor_id 필요']); exit; }

        // 업체 연결 = 업체 상품(사용자 결정 2026-11-17): 연결 저장 + 그 업체 상품 줄(비활성으로 시작) — app/vendors.php vendor_link_upsert
        $pdo = db();
        $va = $pdo->prepare('SELECT is_active FROM vendors WHERE id=:id');
        $va->execute(['id' => $vid]);
        $vAct = $va->fetchColumn();
        if ($vAct === false) { echo json_encode(['ok'=>false,'error'=>'업체를 찾지 못했습니다.'], JSON_UNESCAPED_UNICODE); exit; }
        if (vendor_link_is_link_row($pdo, $pid)) { echo json_encode(['ok'=>false,'error'=>'업체 연결로 만든 상품에는 업체를 추가할 수 없습니다. 원래 상품에서 추가해 주세요.'], JSON_UNESCAPED_UNICODE); exit; }
        $ex = $pdo->prepare('SELECT 1 FROM product_vendors WHERE product_id=:pid AND vendor_id=:vid');
        $ex->execute(['pid' => $pid, 'vid' => $vid]);
        $isNewLink = $ex->fetchColumn() === false;
        if ($isNewLink && !(int)$vAct) { echo json_encode(['ok'=>false,'error'=>'비활성 업체는 추가할 수 없습니다. 업체 목록에서 먼저 다시 활성으로 바꿔 주세요.'], JSON_UNESCAPED_UNICODE); exit; }
        if (!(int)$vAct) { $prim = 0; }   // 비활성 업체는 주거래로 지정하지 않음

        $pdo->beginTransaction();
        try {
            // 주거래 설정 시 기존 주거래 해제
            if ($prim) {
                $pdo->prepare('UPDATE product_vendors SET is_primary=0 WHERE product_id=:pid')->execute(['pid'=>$pid]);
            }
            $pdo->prepare(
                'INSERT INTO product_vendors (product_id, vendor_id, supply_price, lead_days, is_primary)
                 VALUES (:pid, :vid, :price, :lead, :prim)
                 ON DUPLICATE KEY UPDATE supply_price=:price2, lead_days=:lead2, is_primary=:prim2'
            )->execute([
                'pid'=>$pid,'vid'=>$vid,'price'=>$price,'lead'=>$lead,'prim'=>$prim,
                'price2'=>$price,'lead2'=>$lead,'prim2'=>$prim,
            ]);
            $link = vendor_link_upsert($pdo, $pid, $vid, $price);
            $pdo->commit();
        } catch (\InvalidArgumentException $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE);
            exit;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        if ($link['created']) {
            try { spec_axes_sync_product($link['id']); }   // 규격 축 칸(목록 '규격' 필터) — 실패해도 상품 줄은 저장됨
            catch (\Throwable $e) { error_log('[api/staff/vendors] 규격 축 갱신 실패 id=' . $link['id'] . ': ' . get_class($e)); }
        }
        echo json_encode(['ok' => true, 'link_product_id' => $link['id'], 'link_created' => $link['created']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 주거래 바꾸기: 같은 품목의 다른 연결은 주거래 해제. 공급단가·리드타임은 건드리지 않음. 비활성 업체는 주거래로 지정하지 않음
    if ($action === 'set_primary') {
        $id = (int)($body['id'] ?? 0);
        $st = db()->prepare('SELECT pv.product_id, v.is_active FROM product_vendors pv JOIN vendors v ON v.id=pv.vendor_id WHERE pv.id=:id');
        $st->execute(['id' => $id]);
        $row = $st->fetch();
        if (!$row) { echo json_encode(['ok'=>false,'error'=>'연결을 찾지 못했습니다. 창을 다시 열어 주세요.'], JSON_UNESCAPED_UNICODE); exit; }
        if (!(int)$row['is_active']) { echo json_encode(['ok'=>false,'error'=>'비활성 업체는 주거래로 지정할 수 없습니다.'], JSON_UNESCAPED_UNICODE); exit; }
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('UPDATE product_vendors SET is_primary=0 WHERE product_id=:pid AND id<>:id')->execute(['pid' => (int)$row['product_id'], 'id' => $id]);
        $pdo->prepare('UPDATE product_vendors SET is_primary=1 WHERE id=:id')->execute(['id' => $id]);
        $pdo->commit();
        echo json_encode(['ok' => true]);
        exit;
    }

    // 업체 활성/비활성
    if ($action === 'set_vendor_active') {
        $id  = (int)($body['id'] ?? 0);
        $val = (int)($body['is_active'] ?? -1);
        if (!$id || !in_array($val, [0, 1], true)) { echo json_encode(['ok'=>false,'error'=>'id, is_active(0/1) 필요'], JSON_UNESCAPED_UNICODE); exit; }
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT is_active FROM vendors WHERE id=:id FOR UPDATE');
            $st->execute(['id' => $id]);
            $cur = $st->fetchColumn();
            if ($cur === false) { $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>'업체를 찾지 못했습니다.'], JSON_UNESCAPED_UNICODE); exit; }
            $pdo->prepare('UPDATE vendors SET is_active=:v WHERE id=:id')->execute(['v' => $val, 'id' => $id]);
            $off = $val === 0 ? vendor_deactivate_products($pdo, $id) : ['products_off' => 0];
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        echo json_encode(['ok' => true, 'is_active' => $val] + $off + vendor_impact($pdo, $id), JSON_UNESCAPED_UNICODE);
        exit;
    }

    // 품목-업체 삭제
    if ($action === 'remove_product_vendor') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { echo json_encode(['ok'=>false,'error'=>'id 필요']); exit; }
        // 연결 삭제 + 그 업체 상품 줄 비활성(행은 남김 — 견적·주문 기록에는 '지금은 없는 상품'으로 보임)
        $pdo = db();
        $pdo->beginTransaction();
        try {
            $st = $pdo->prepare('SELECT product_id, vendor_id FROM product_vendors WHERE id=:id FOR UPDATE');
            $st->execute(['id' => $id]);
            $pv = $st->fetch();
            if (!$pv) { $pdo->rollBack(); echo json_encode(['ok'=>false,'error'=>'연결을 찾지 못했습니다. 창을 다시 열어 주세요.'], JSON_UNESCAPED_UNICODE); exit; }
            $pdo->prepare('DELETE FROM product_vendors WHERE id=:id')->execute(['id'=>$id]);
            $off = vendor_link_deactivate($pdo, (int)$pv['product_id'], (int)$pv['vendor_id']);
            $pdo->commit();
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $e;
        }
        echo json_encode(['ok' => true, 'link_off' => $off]);
        exit;
    }

    // 업체 등록/수정
    $name    = substr(trim($body['name']    ?? ''), 0, 120);
    $contact = substr(trim($body['contact'] ?? ''), 0, 80);
    $phone   = substr(trim($body['phone']   ?? ''), 0, 40);
    $fax     = substr(trim($body['fax']     ?? ''), 0, 40);
    $email   = substr(trim($body['email']   ?? ''), 0, 160);
    $region  = substr(trim($body['region']  ?? ''), 0, 60);
    $address = substr(trim($body['address'] ?? ''), 0, 255);
    $memo    = substr(trim($body['memo']    ?? ''), 0, 1000);

    if ($name === '') { echo json_encode(['ok'=>false,'error'=>'업체명 필수']); exit; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['ok'=>false,'error'=>'이메일 형식 오류']); exit;
    }

    $id = (int)($body['id'] ?? 0);
    if ($id) {
        db()->prepare(
            'UPDATE vendors SET name=:n,contact=:c,phone=:p,fax=:f,email=:e,region=:r,address=:a,memo=:m WHERE id=:id'
        )->execute(['n'=>$name,'c'=>$contact,'p'=>$phone,'f'=>$fax,'e'=>$email,'r'=>$region,'a'=>$address,'m'=>$memo,'id'=>$id]);
    } else {
        db()->prepare(
            'INSERT INTO vendors (name,contact,phone,fax,email,region,address,memo) VALUES (:n,:c,:p,:f,:e,:r,:a,:m)'
        )->execute(['n'=>$name,'c'=>$contact,'p'=>$phone,'f'=>$fax,'e'=>$email,'r'=>$region,'a'=>$address,'m'=>$memo]);
        $id = (int)db()->lastInsertId();
    }
    echo json_encode(['ok' => true, 'id' => $id]);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드']);
