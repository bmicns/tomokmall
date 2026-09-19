<?php
declare(strict_types=1);
/**
 * GET    /api/sites/orders.php?site_id=N  — 현장에 연결된 견적 목록
 * POST   /api/sites/orders.php            — 견적을 현장에 연결 {site_id, estimate_id}
 * DELETE /api/sites/orders.php            — 연결 해제 {site_id, estimate_id}
 *
 * 인증: 로그인 필요
 * 보안: sites.user_id, estimates.user_id 모두 현재 user_id 확인
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

$user = require_login();
$uid  = (int) $user['id'];
$pdo  = db();

if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: 현장별 견적 목록 ──────────────────────────────────
if ($method === 'GET') {
    $siteId = (int) ($_GET['site_id'] ?? 0);
    if ($siteId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'site_id가 유효하지 않습니다.']);
        exit;
    }

    // 본인 현장 확인
    $chk = $pdo->prepare('SELECT id FROM sites WHERE id = :sid AND user_id = :uid AND is_active = 1');
    $chk->execute(['sid' => $siteId, 'uid' => $uid]);
    if (!$chk->fetch()) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '접근 권한이 없습니다.']);
        exit;
    }

    $st = $pdo->prepare(
        'SELECT e.id, e.status, e.created_at,
                COUNT(ei.id) AS item_cnt,
                COALESCE(SUM(ei.quantity * ei.unit_price), 0) AS total_amount
         FROM site_orders so
         JOIN estimates e ON e.id = so.estimate_id
         LEFT JOIN estimate_items ei ON ei.estimate_id = e.id
         WHERE so.site_id = :sid
         GROUP BY e.id, e.status, e.created_at
         ORDER BY e.id DESC'
    );
    $st->execute(['sid' => $siteId]);

    echo json_encode(['ok' => true, 'orders' => $st->fetchAll()], JSON_UNESCAPED_UNICODE);
    exit;
}

$body    = json_decode(file_get_contents('php://input'), true) ?? [];
$siteId  = (int) ($body['site_id'] ?? 0);
$estId   = (int) ($body['estimate_id'] ?? 0);

if ($siteId <= 0 || $estId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '입력값이 올바르지 않습니다.']);
    exit;
}

// 본인 현장 + 본인 견적 확인
$chkSite = $pdo->prepare('SELECT id FROM sites WHERE id = :sid AND user_id = :uid AND is_active = 1');
$chkSite->execute(['sid' => $siteId, 'uid' => $uid]);
if (!$chkSite->fetch()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '접근 권한이 없습니다.']);
    exit;
}

$chkEst = $pdo->prepare('SELECT id FROM estimates WHERE id = :eid AND user_id = :uid');
$chkEst->execute(['eid' => $estId, 'uid' => $uid]);
if (!$chkEst->fetch()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '접근 권한이 없습니다.']);
    exit;
}

// ── POST: 현장에 견적 연결 ─────────────────────────────────
if ($method === 'POST') {
    $st = $pdo->prepare(
        'INSERT IGNORE INTO site_orders (site_id, estimate_id) VALUES (:sid, :eid)'
    );
    $st->execute(['sid' => $siteId, 'eid' => $estId]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── DELETE: 연결 해제 ──────────────────────────────────────
if ($method === 'DELETE') {
    $st = $pdo->prepare(
        'DELETE FROM site_orders WHERE site_id = :sid AND estimate_id = :eid'
    );
    $st->execute(['sid' => $siteId, 'eid' => $estId]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
