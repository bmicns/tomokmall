<?php
declare(strict_types=1);
/**
 * 관리자 AI 상담로그 API
 * POST { action:'rate', id:N, rating:1|-1|0 }
 * POST { action:'toggle_fewshot', id:N }
 * POST { action:'export_fewshot' }  → JSON 다운로드
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');

$body   = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $body['action'] ?? '';

// ── 평가 저장 ────────────────────────────────────────────────────
if ($action === 'rate') {
    $id     = (int) ($body['id']     ?? 0);
    $rating = (int) ($body['rating'] ?? 0);
    if ($id <= 0 || !in_array($rating, [1, -1, 0], true)) {
        echo json_encode(['ok' => false, 'error' => '잘못된 요청']);
        exit;
    }
    $ratingVal = $rating === 0 ? null : $rating;
    $stmt = db()->prepare('UPDATE chat_logs SET rating = :r WHERE id = :id');
    $stmt->execute(['r' => $ratingVal, 'id' => $id]);
    echo json_encode(['ok' => true]);
    exit;
}

// ── few-shot 후보 토글 ───────────────────────────────────────────
if ($action === 'toggle_fewshot') {
    $id = (int) ($body['id'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'error' => '잘못된 ID']); exit; }
    $stmt = db()->prepare(
        'UPDATE chat_logs SET is_fewshot = 1 - is_fewshot WHERE id = :id'
    );
    $stmt->execute(['id' => $id]);
    $row = db()->prepare('SELECT is_fewshot FROM chat_logs WHERE id = :id');
    $row->execute(['id' => $id]);
    $val = (int) $row->fetchColumn();
    echo json_encode(['ok' => true, 'is_fewshot' => $val]);
    exit;
}

// ── few-shot 후보 JSON 내보내기 ──────────────────────────────────
if ($action === 'export_fewshot') {
    $stmt = db()->query(
        'SELECT id, category_key, user_msg, assistant_txt, tools_json, created_at
         FROM chat_logs
         WHERE is_fewshot = 1
         ORDER BY category_key, id'
    );
    $rows = $stmt->fetchAll();

    header('Content-Type: application/json; charset=utf-8');
    header('Content-Disposition: attachment; filename="fewshot_' . date('Ymd_His') . '.json"');
    echo json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

echo json_encode(['ok' => false, 'error' => '알 수 없는 action']);
