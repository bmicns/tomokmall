<?php
declare(strict_types=1);
/**
 * GET  /api/chat/history.php?limit={n}   이전 AI 상담 이력 목록
 * POST /api/chat/history.php {"action":"forget"}   비회원 '이 기기 상담 기록 지우기'
 *
 * 인증: 회원 = 로그인 세션(user_id 본인 것만), 비회원 = 이 기기 쿠키 토큰의 해시(guest_hash, A12 — 쿠키가 없으면 빈 목록)
 * 검증: limit 1~10, 다른 사람 상담은 목록에 나오지 않음(상담 번호를 알아도 /chat.php?sid= 복원은 같은 확인)
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/chat_guest.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET' && $method !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$user = current_user();
$pdo  = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

if ($method === 'POST') {
    $body = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($body) || ($body['action'] ?? '') !== 'forget' || $user) {
        echo json_encode(['ok' => false, 'error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'deleted' => chat_guest_forget()]);
    exit;
}

$limit = max(1, min(10, (int) ($_GET['limit'] ?? 5)));
$guest = !$user;
if ($guest) {
    $gh = chat_guest_hash();
    if ($gh === null) {
        echo json_encode(['ok' => true, 'guest' => true, 'sessions' => []]);
        exit;
    }
    // 이 기기 토큰의 비회원 상담만
    $stmt = $pdo->prepare(
        'SELECT session_key, created_at, messages
         FROM ai_sessions
         WHERE user_id IS NULL AND guest_hash = :gh
         ORDER BY updated_at DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':gh', $gh);
} else {
    // user_id 일치 확인으로 타인 이력 접근 차단
    $stmt = $pdo->prepare(
        'SELECT session_key, created_at, messages
         FROM ai_sessions
         WHERE user_id = :uid
         ORDER BY updated_at DESC
         LIMIT :lim'
    );
    $stmt->bindValue(':uid', (int) $user['id'], PDO::PARAM_INT);
}
$stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
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

echo json_encode(['ok' => true, 'guest' => $guest, 'sessions' => $sessions], JSON_UNESCAPED_UNICODE);
