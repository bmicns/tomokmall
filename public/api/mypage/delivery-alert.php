<?php
declare(strict_types=1);
/**
 * POST /api/mypage/delivery-alert.php
 * 납품 이력별 재구매 알림 시기 설정 저장
 *
 * 인증: 로그인 필요 (require_login)
 * 검증:
 *   - estimate_id 양의 정수 + 본인 소유 + status='DELIVERED' 확인
 *   - alert_after whitelist: 1y | 2-3y | 5y+ | unknown
 * alert_at 계산: delivery_completed_at 기준 (+1년/+2년/+5년/null)
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$user = require_login();

$body       = json_decode(file_get_contents('php://input'), true) ?? [];
$estimateId = (int) ($body['estimate_id'] ?? 0);
$alertAfter = (string) ($body['alert_after'] ?? '');

if ($estimateId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'estimate_id가 유효하지 않습니다.']);
    exit;
}

$allowedAfter = ['1y', '2-3y', '5y+', 'unknown'];
if (!in_array($alertAfter, $allowedAfter, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "alert_after는 " . implode(', ', $allowedAfter) . " 중 하나여야 합니다."]);
    exit;
}

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// 본인 소유 + DELIVERED 상태 확인
$estStmt = $pdo->prepare(
    "SELECT id, delivery_completed_at
     FROM estimates
     WHERE id = :eid AND user_id = :uid AND status = 'DELIVERED'
     LIMIT 1"
);
$estStmt->execute(['eid' => $estimateId, 'uid' => $user['id']]);
$estimate = $estStmt->fetch();

if (!$estimate) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '납품 완료된 건만 알림 설정 가능합니다.']);
    exit;
}

// alert_at 계산: delivery_completed_at + alert_after 기준
$alertAt = null;
if ($estimate['delivery_completed_at'] && $alertAfter !== 'unknown') {
    $base = new \DateTime($estimate['delivery_completed_at']);
    $years = match ($alertAfter) {
        '1y'  => 1,
        '2-3y'=> 2,
        '5y+' => 5,
        default => null,
    };
    if ($years !== null) {
        $base->modify("+{$years} years");
        $alertAt = $base->format('Y-m-d');
    }
}

// INSERT OR UPDATE (동일 estimate_id 재설정 허용)
$upsertStmt = $pdo->prepare(
    'INSERT INTO reorder_alerts (user_id, estimate_id, alert_after, alert_at)
     VALUES (:uid, :eid, :after, :at)
     ON DUPLICATE KEY UPDATE
         alert_after = VALUES(alert_after),
         alert_at    = VALUES(alert_at),
         is_sent     = 0'
);
$upsertStmt->execute([
    'uid'   => $user['id'],
    'eid'   => $estimateId,
    'after' => $alertAfter,
    'at'    => $alertAt,
]);

echo json_encode(['ok' => true, 'alert_at' => $alertAt]);
