<?php
declare(strict_types=1);
/**
 * AI 상담 채팅 라우터
 *
 * GET  /chat.php?stream=1&sid=...&msg=... → SSE 스트리밍 (Tool Use 응답 포함)
 * POST /chat.php                           → 대화 턴 저장 (JSON)
 * GET  /chat.php                           → 채팅 UI 렌더링
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/rag.php';

// ── AJAX AI 응답 엔드포인트 ──────────────────────────────────────────────
if (isset($_GET['ask']) && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $body       = json_decode(file_get_contents('php://input'), true) ?? [];
    $sessionKey = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['sid'] ?? '');
    $userMsg    = trim(mb_substr($body['msg'] ?? '', 0, 1000));

    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');

    if (!$sessionKey || !$userMsg) {
        echo json_encode(['ok' => false, 'error' => '잘못된 요청입니다.']);
        exit;
    }

    set_time_limit(90);

    $messages = rag_load_session($sessionKey);
    if (count($messages) > 20) {
        $messages = array_slice($messages, -20);
    }
    $messages[] = ['role' => 'user', 'content' => $userMsg];

    // 세션 사용자 ID (rag_process가 session_write_close 전 캡처)
    $logUserId = null;
    $su = $_SESSION['user'] ?? null;
    if (is_array($su) && isset($su['id'])) $logUserId = (int)$su['id'];

    $result = rag_process($messages, $sessionKey);
    echo json_encode($result, JSON_UNESCAPED_UNICODE);

    // Stage 3: 상담 로그 기록
    if ($result['ok'] ?? false) {
        rag_log_chat(
            $sessionKey,
            $userMsg,
            $result['text']     ?? '',
            $result['events']   ?? [],
            $result['category'] ?? '',
            $logUserId
        );
    }
    exit;
}

// ── 대화 턴 저장 엔드포인트 ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true) ?? [];
    $sid       = preg_replace('/[^a-zA-Z0-9_-]/', '', $body['sid'] ?? '');
    $user      = trim($body['user'] ?? '');
    $assistant = trim($body['assistant'] ?? '');

    if ($sid && $user && $assistant) {
        $messages   = rag_load_session($sid);
        $messages[] = ['role' => 'user',      'content' => $user];
        $messages[] = ['role' => 'assistant', 'content' => $assistant];

        if (count($messages) > 40) {
            $messages = array_slice($messages, -40);
        }

        rag_save_session($sid, $messages);
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => (bool) ($sid && $user && $assistant)]);
    exit;
}

// ── 채팅 UI ───────────────────────────────────────────────────────────────
$sessionKey  = session_id() ?: bin2hex(random_bytes(16));
$_isLoggedIn = is_logged_in();
require __DIR__ . '/../app/chat_renderer.php';
