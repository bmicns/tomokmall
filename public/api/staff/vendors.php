<?php
declare(strict_types=1);
/**
 * 직원 업체 API
 * GET  ?list=1              — 전체 업체 목록
 * GET  ?id=N                — 업체 상세
 * GET  ?product_id=N        — 품목-업체 연결 목록
 * POST action=add_product_vendor  — 품목-업체 추가
 * POST action=remove_product_vendor — 삭제
 * POST (id 없음)            — 업체 등록
 * POST (id 있음)            — 업체 수정
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (\Throwable $e) {
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
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
        if (!$row) { http_response_code(404); echo json_encode(['ok'=>false,'error'=>'없음']); exit; }
        echo json_encode(['ok' => true, 'data' => $row]);
        exit;
    }
    // 품목-업체 연결 목록
    if (isset($_GET['product_id'])) {
        $pid = (int)$_GET['product_id'];
        $st  = db()->prepare(
            'SELECT pv.id, pv.supply_price, pv.lead_days, pv.is_primary,
                    v.name AS vendor_name, v.phone, v.region
             FROM product_vendors pv
             JOIN vendors v ON v.id=pv.vendor_id
             WHERE pv.product_id=:pid
             ORDER BY pv.is_primary DESC, v.name'
        );
        $st->execute(['pid' => $pid]);
        echo json_encode(['ok' => true, 'data' => $st->fetchAll()]);
        exit;
    }
    http_response_code(400);
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

        if (!$pid || !$vid) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'product_id, vendor_id 필요']); exit; }

        // 주거래 설정 시 기존 주거래 해제
        if ($prim) {
            db()->prepare('UPDATE product_vendors SET is_primary=0 WHERE product_id=:pid')->execute(['pid'=>$pid]);
        }
        db()->prepare(
            'INSERT INTO product_vendors (product_id, vendor_id, supply_price, lead_days, is_primary)
             VALUES (:pid, :vid, :price, :lead, :prim)
             ON DUPLICATE KEY UPDATE supply_price=:price2, lead_days=:lead2, is_primary=:prim2'
        )->execute([
            'pid'=>$pid,'vid'=>$vid,'price'=>$price,'lead'=>$lead,'prim'=>$prim,
            'price2'=>$price,'lead2'=>$lead,'prim2'=>$prim,
        ]);
        echo json_encode(['ok' => true]);
        exit;
    }

    // 품목-업체 삭제
    if ($action === 'remove_product_vendor') {
        $id = (int)($body['id'] ?? 0);
        if (!$id) { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'id 필요']); exit; }
        db()->prepare('DELETE FROM product_vendors WHERE id=:id')->execute(['id'=>$id]);
        echo json_encode(['ok' => true]);
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

    if ($name === '') { http_response_code(400); echo json_encode(['ok'=>false,'error'=>'업체명 필수']); exit; }
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        http_response_code(400); echo json_encode(['ok'=>false,'error'=>'이메일 형식 오류']); exit;
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
