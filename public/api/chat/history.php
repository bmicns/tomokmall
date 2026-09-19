<?php
declare(strict_types=1);
/**
 * GET /api/chat/history.php?limit={n}
 * 로그인 사용자의 이전 AI 상담 이력 목록 반환
 *
 * 인증: 로그인 필요 (require_login)
 * 검증: limit 1~10, user_id 본인 세션만 조회
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$user  = require_login();
$limit = max(1, min(10, (int) ($_GET['limit'] ?? 5)));

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// user_id 일치 확인으로 타인 이력 접근 차단
$stmt = $pdo->prepare(
    'SELECT session_key, created_at, messages
     FROM ai_sessions
     WHERE user_id = :uid
     ORDER BY updated_at DESC
     LIMIT :lim'
);
$stmt->bindValue(':uid', $user['id'], PDO::PARAM_INT);
$stmt->bindValue(':lim', $limit,      PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll();

$sessions = array_map(function ($row) {
    // messages JSON에서 첫 번째 사용자 메시지를 summary로 추출
    $summary = null;
    $msgs = json_decode((string) $row['messages'], true);
    if (is_array($msgs)) {
        foreach ($msgs as $msg) {
            if (is_array($msg) && ($msg['role'] ?? '') === 'user') {
                $content = is_string($msg['content'] ?? null)
                    ? $msg['content']
                    : (is_array($msg['content'] ?? null) ? ($msg['content'][0]['text'] ?? null) : null);
                if ($content) {
                    $summary = mb_substr(strip_tags((string) $content), 0, 60);
                    break;
                }
            }
        }
    }

    return [
        'session_key' => $row['session_key'],
        'created_at'  => $row['created_at'],
        'summary'     => $summary,
        'estimate_id' => null,  // 견적-세션 연결은 Phase 4에서 확장
    ];
}, $rows);

echo json_encode(['ok' => true, 'sessions' => $sessions], JSON_UNESCAPED_UNICODE);
