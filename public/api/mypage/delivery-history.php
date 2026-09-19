<?php
declare(strict_types=1);
/**
 * GET /api/mypage/delivery-history.php?limit={n}&offset={n}
 * 납품 완료 이력 목록 (status = 'DELIVERED')
 *
 * 인증: 로그인 필요 (require_login)
 * 검증: estimates.user_id = 현재 user_id 필터, limit 1~20, offset 0 이상
 * 성능: GROUP_CONCAT으로 품목 요약 단일 쿼리 — N+1 방지
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$user   = require_login();
$limit  = max(1, min(20, (int) ($_GET['limit']  ?? 10)));
$offset = max(0,          (int) ($_GET['offset'] ?? 0));

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// 전체 건수 조회
$cntStmt = $pdo->prepare(
    "SELECT COUNT(*) FROM estimates WHERE user_id = :uid AND status = 'DELIVERED'"
);
$cntStmt->execute(['uid' => $user['id']]);
$total = (int) $cntStmt->fetchColumn();

// 납품 이력 + 품목 요약 단일 쿼리
// GROUP_CONCAT으로 "상품명 × 수량" 형태 요약 생성
$stmt = $pdo->prepare(
    "SELECT e.id AS estimate_id,
            DATE(e.delivery_completed_at) AS delivery_completed_at,
            GROUP_CONCAT(
                CONCAT(p.name, ' × ', ei.quantity, '개')
                ORDER BY ei.id SEPARATOR ', '
            ) AS products_summary,
            ra.alert_after,
            DATE(ra.alert_at) AS alert_at
     FROM estimates e
     JOIN estimate_items ei ON ei.estimate_id = e.id
     JOIN products p        ON p.id = ei.product_id
     LEFT JOIN reorder_alerts ra ON ra.estimate_id = e.id AND ra.user_id = :uid2
     WHERE e.user_id = :uid AND e.status = 'DELIVERED'
     GROUP BY e.id, e.delivery_completed_at, ra.alert_after, ra.alert_at
     ORDER BY e.delivery_completed_at DESC
     LIMIT :lim OFFSET :off"
);
$stmt->bindValue(':uid',  $user['id'], PDO::PARAM_INT);
$stmt->bindValue(':uid2', $user['id'], PDO::PARAM_INT);
$stmt->bindValue(':lim',  $limit,      PDO::PARAM_INT);
$stmt->bindValue(':off',  $offset,     PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$items = array_map(fn($r) => [
    'estimate_id'            => (int)  $r['estimate_id'],
    'delivery_completed_at'  =>        $r['delivery_completed_at'],
    'products_summary'       =>        $r['products_summary'],
    'alert_after'            =>        $r['alert_after'],
    'alert_at'               =>        $r['alert_at'],
], $rows);

echo json_encode(['ok' => true, 'total' => $total, 'items' => $items], JSON_UNESCAPED_UNICODE);
