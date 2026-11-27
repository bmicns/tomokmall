<?php
declare(strict_types=1);
/**
 * 관리자 탈퇴 사유 API (사용자 결정 2026-11-27 — 기한 없이 보관, 관리자가 직접 지움)
 * POST { action:'delete', id:N }  → 그 사유 한 건 삭제
 * 표: withdraw_reasons(회원과 연결 없음 — database/20261127_withdraw_reasons.sql)
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$body   = json_decode(file_get_contents('php://input') ?: '', true);
$body   = is_array($body) ? $body : [];
$action = (string) ($body['action'] ?? '');

if ($action === 'delete') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    try {
        $st = $pdo->prepare('DELETE FROM withdraw_reasons WHERE id = :id');
        $st->execute(['id' => $id]);
        echo json_encode(['ok' => true, 'deleted' => $st->rowCount()], JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        error_log('admin withdraw-reasons: ' . $e->getMessage());
        echo json_encode(['ok' => false, 'error' => '삭제하지 못했습니다.'], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

echo json_encode(['ok' => false, 'error' => '알 수 없는 요청입니다.'], JSON_UNESCAPED_UNICODE);
