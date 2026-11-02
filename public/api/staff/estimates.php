<?php
declare(strict_types=1);
/**
 * 직원 견적 API
 * GET  /api/staff/estimates.php?id=N  — 견적 상세 (items 포함)
 * POST /api/staff/estimates.php       — 견적 저장/상태변경
 *
 * 오류 응답: 입력 검증 실패(id·견적 없음·발송 불가 상태·action)도 HTTP 200 + {"ok":false,"error":…}
 *           (닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 JSON 문구가 사라짐 — quote.php 와 같은 방식)
 *           405(메서드)·500(예외 — 상세는 서버 로그, 화면에는 일반 문구)·401/403(require_staff)은 상태 코드 유지
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

set_exception_handler(function (\Throwable $e) {
    // 내부 오류 문구(SQL 등)는 화면에 보내지 않고 서버 로그에만 남긴다
    error_log('[api/staff/estimates] ' . get_class($e) . ': ' . $e->getMessage());
    if (!headers_sent()) http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
});

require_staff();

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: 견적 상세 ─────────────────────────────────────────────────────────
if ($method === 'GET') {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) { echo json_encode(['ok'=>false,'error'=>'id 필요']); exit; }

    $est = db()->prepare('SELECT e.*, u.name AS staff_name FROM estimates e LEFT JOIN users u ON u.id = e.staff_id WHERE e.id = :id');
    $est->execute(['id' => $id]);
    $estimate = $est->fetch();
    if (!$estimate) { echo json_encode(['ok'=>false,'error'=>'없음']); exit; }

    $items = db()->prepare(
        'SELECT ei.*, p.name AS product_name, p.spec, p.brand, p.image_url
         FROM estimate_items ei
         JOIN products p ON p.id = ei.product_id
         WHERE ei.estimate_id = :id
         ORDER BY ei.id'
    );
    $items->execute(['id' => $id]);
    $estimate['items'] = $items->fetchAll();

    echo json_encode(['ok' => true, 'data' => $estimate]);
    exit;
}

// ── POST: 견적 저장/상태변경 ────────────────────────────────────────────────
if ($method === 'POST') {
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $id     = (int)($body['id'] ?? 0);
    $action = $body['action'] ?? '';

    if (!$id) { echo json_encode(['ok'=>false,'error'=>'id 필요']); exit; }

    // 견적 존재 확인
    $check = db()->prepare('SELECT id, status FROM estimates WHERE id = :id');
    $check->execute(['id' => $id]);
    $est = $check->fetch();
    if (!$est) { echo json_encode(['ok'=>false,'error'=>'없음']); exit; }

    $staffUser = \current_user();

    // action: save — 수정단가·배송정보 저장
    if ($action === 'save') {
        db()->beginTransaction();
        try {
            // 담당직원 자동 배정 + 상태 WORKING으로
            $newStatus = $est['status'] === 'REQUESTED' ? 'WORKING' : $est['status'];
            $staffMemo = substr(trim($body['staff_memo'] ?? ''), 0, 1000);
            $shippingFee = max(0, (int)($body['shipping_fee'] ?? 0));
            $discountAmt = max(0, (int)($body['discount_amt'] ?? 0));

            db()->prepare(
                'UPDATE estimates SET staff_id=:sid, staff_memo=:memo, status=:st,
                  shipping_fee=:sf, discount_amt=:da WHERE id=:id'
            )->execute([
                'sid'  => $staffUser['id'],
                'memo' => $staffMemo,
                'st'   => $newStatus,
                'sf'   => $shippingFee,
                'da'   => $discountAmt,
                'id'   => $id,
            ]);

            // 품목별 수정단가·파렛트·무게 업데이트
            $itemUpdates = $body['items'] ?? [];
            $upSt = db()->prepare(
                'UPDATE estimate_items SET adjusted_price=:ap, pallet_cnt=:pc, weight_kg=:wk WHERE id=:id AND estimate_id=:eid'
            );
            foreach ($itemUpdates as $item) {
                $upSt->execute([
                    'ap'  => ($item['adjusted_price'] !== '' && $item['adjusted_price'] !== null)
                              ? max(0, (int)$item['adjusted_price']) : null,
                    'pc'  => max(0, (int)($item['pallet_cnt'] ?? 0)),
                    'wk'  => max(0, (int)($item['weight_kg']  ?? 0)),
                    'id'  => (int)$item['id'],
                    'eid' => $id,
                ]);
            }
            db()->commit();
        } catch (\Throwable $e) {
            db()->rollBack();
            throw $e;
        }
        echo json_encode(['ok' => true, 'status' => $newStatus]);
        exit;
    }

    // action: send — 견적 발송 처리 (WORKING → SENT)
    if ($action === 'send') {
        if (!in_array($est['status'], ['WORKING','REQUESTED'], true)) {
            echo json_encode(['ok'=>false,'error'=>'발송할 수 없는 상태입니다.']);
            exit;
        }
        db()->prepare(
            'UPDATE estimates SET status="SENT", sent_at=NOW(), staff_id=:sid WHERE id=:id'
        )->execute(['sid' => $staffUser['id'], 'id' => $id]);
        echo json_encode(['ok' => true, 'status' => 'SENT']);
        exit;
    }

    // action: approve — 견적 완료 처리 (SENT → APPROVED)
    if ($action === 'approve') {
        db()->prepare('UPDATE estimates SET status="APPROVED" WHERE id=:id')->execute(['id'=>$id]);
        echo json_encode(['ok' => true, 'status' => 'APPROVED']);
        exit;
    }

    // action: cancel
    if ($action === 'cancel') {
        db()->prepare('UPDATE estimates SET status="CANCELED" WHERE id=:id')->execute(['id'=>$id]);
        echo json_encode(['ok' => true, 'status' => 'CANCELED']);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => '알 수 없는 action']);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드']);
