<?php
declare(strict_types=1);
/**
 * GET  /api/sites/index.php      — 내 현장 목록 조회
 * POST /api/sites/index.php      — 현장 생성
 * PUT  /api/sites/index.php      — 현장 수정 {id, name, address, memo}
 * DELETE /api/sites/index.php    — 현장 삭제(비활성화) {id}
 *
 * 인증: 로그인 필요
 * 보안: 모든 쓰기 작업에서 sites.user_id = 현재 user_id 확인
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

// ── GET: 내 현장 목록 ──────────────────────────────────────
if ($method === 'GET') {
    $st = $pdo->prepare(
        'SELECT id, name, address, memo, created_at
         FROM sites
         WHERE user_id = :uid AND is_active = 1
         ORDER BY id DESC'
    );
    $st->execute(['uid' => $uid]);
    $sites = $st->fetchAll();

    foreach ($sites as &$s) {
        // 현장별 견적 건수 + 합계 금액
        $st2 = $pdo->prepare(
            'SELECT COUNT(so.estimate_id) AS cnt,
                    COALESCE(SUM(ei.quantity * ei.unit_price), 0) AS total
             FROM site_orders so
             JOIN estimates e ON e.id = so.estimate_id
             LEFT JOIN estimate_items ei ON ei.estimate_id = so.estimate_id
             WHERE so.site_id = :sid'
        );
        $st2->execute(['sid' => $s['id']]);
        $stat = $st2->fetch();
        $s['order_count'] = (int) ($stat['cnt'] ?? 0);
        $s['total_amount'] = (int) ($stat['total'] ?? 0);
    }
    unset($s);

    echo json_encode(['ok' => true, 'sites' => $sites], JSON_UNESCAPED_UNICODE);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?? [];

// ── POST: 현장 생성 ────────────────────────────────────────
if ($method === 'POST') {
    $name    = trim((string) ($body['name'] ?? ''));
    $address = trim((string) ($body['address'] ?? ''));
    $memo    = trim((string) ($body['memo'] ?? ''));

    if ($name === '' || mb_strlen($name) > 120) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '현장명을 입력해주세요. (최대 120자)']);
        exit;
    }

    $st = $pdo->prepare(
        'INSERT INTO sites (user_id, name, address, memo) VALUES (:uid, :name, :addr, :memo)'
    );
    $st->execute(['uid' => $uid, 'name' => $name, 'addr' => $address ?: null, 'memo' => $memo ?: null]);

    echo json_encode(['ok' => true, 'id' => (int) $pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── PUT: 현장 수정 ─────────────────────────────────────────
if ($method === 'PUT') {
    $id      = (int) ($body['id'] ?? 0);
    $name    = trim((string) ($body['name'] ?? ''));
    $address = trim((string) ($body['address'] ?? ''));
    $memo    = trim((string) ($body['memo'] ?? ''));

    if ($id <= 0 || $name === '' || mb_strlen($name) > 120) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => '입력값이 올바르지 않습니다.']);
        exit;
    }

    $st = $pdo->prepare(
        'UPDATE sites SET name = :name, address = :addr, memo = :memo
         WHERE id = :id AND user_id = :uid AND is_active = 1'
    );
    $st->execute(['name' => $name, 'addr' => $address ?: null, 'memo' => $memo ?: null, 'id' => $id, 'uid' => $uid]);

    if ($st->rowCount() === 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '수정할 수 없습니다.']);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

// ── DELETE: 현장 비활성화 ──────────────────────────────────
if ($method === 'DELETE') {
    $id = (int) ($body['id'] ?? 0);

    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'id가 유효하지 않습니다.']);
        exit;
    }

    $st = $pdo->prepare(
        'UPDATE sites SET is_active = 0 WHERE id = :id AND user_id = :uid'
    );
    $st->execute(['id' => $id, 'uid' => $uid]);

    if ($st->rowCount() === 0) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => '삭제할 수 없습니다.']);
        exit;
    }

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(405);
echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
