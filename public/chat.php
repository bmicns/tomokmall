<?php
declare(strict_types=1);
/**
 * AI 상담 채팅 라우터
 *
 * GET  /chat.php?stream=1&sid=...&msg=... → SSE 스트리밍 (Tool Use 응답 포함)
 * POST /chat.php                           → 대화 턴 저장 (JSON)
 * GET  /chat.php                           → 채팅 UI 렌더링 (?sid= 이전 상담 복원)
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

    // 세션 사용자 ID (rag_process가 session_write_close 전 캡처)
    $logUserId = null;
    $su = $_SESSION['user'] ?? null;
    if (is_array($su) && isset($su['id'])) $logUserId = (int)$su['id'];

    if (!$sessionKey || !$userMsg || !rag_chat_key_allowed($sessionKey)
        || !rag_session_writable($sessionKey, $logUserId)) {
        echo json_encode(['ok' => false, 'error' => '잘못된 요청입니다.']);
        exit;
    }

    set_time_limit(90);

    $messages = rag_load_session($sessionKey);
    // 텍스트 없는 카드 응답(빈 assistant)은 AI 문맥에서 제외 — 빈 메시지 전송 방지
    $messages = array_values(array_filter($messages, static fn($m) =>
        !(is_array($m) && ($m['role'] ?? '') === 'assistant' && trim((string) ($m['content'] ?? '')) === '')));
    if (count($messages) > 20) {
        $messages = array_slice($messages, -20);
    }
    $messages[] = ['role' => 'user', 'content' => $userMsg];

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
    // 텍스트 없이 상품 추천표·견적 카드만 온 답변도 저장 (카드는 chat_logs.events_json에 이미 기록됨)
    $hasEvents = !empty($body['has_events']);
    $su        = $_SESSION['user'] ?? null;
    $saveUid   = (is_array($su) && isset($su['id'])) ? (int) $su['id'] : null;

    if ($sid && (!rag_chat_key_allowed($sid) || !rag_session_writable($sid, $saveUid))) {
        $sid = '';
    }

    $canSave = $sid && $user && ($assistant !== '' || $hasEvents);
    if ($canSave) {
        $messages   = rag_load_session($sid);
        $messages[] = ['role' => 'user',      'content' => $user];
        $messages[] = ['role' => 'assistant', 'content' => $assistant];

        if (count($messages) > 40) {
            $messages = array_slice($messages, -40);
        }

        rag_save_session($sid, $messages);
    }

    header('Content-Type: application/json');
    echo json_encode(['ok' => (bool) $canSave]);
    exit;
}

// ── 채팅 UI ───────────────────────────────────────────────────────────────
// ?sid= : 이전 상담 복원 (로그인 본인 상담만). 그 외에는 상담마다 새 키 발급
$_isLoggedIn = is_logged_in();
$_restored   = [];
$_restoredEvents = [];
$sessionKey  = '';
$_reqSid     = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($_GET['sid'] ?? ''));
if ($_reqSid !== '' && $_isLoggedIn) {
    $_owned = rag_load_owned_session($_reqSid, (int) current_user()['id']);
    if ($_owned !== null) {
        $sessionKey = $_reqSid;
        $_restored  = $_owned;
        $_restoredEvents = rag_load_turn_events($_reqSid, (int) current_user()['id'], $_owned);
        // 상품 추천표: 가격·판매 상태만 현재 기준(견적서와 같은 규칙), 나머지는 상담 당시 그대로
        $_restoredEvents = rag_apply_current_prices(
            $_restoredEvents,
            rag_current_product_prices(rag_event_product_ids($_restoredEvents))
        );
        rag_chat_key_allow($sessionKey);
    }
}
if ($sessionKey === '') {
    $sessionKey = rag_chat_key_issue();
}
require __DIR__ . '/../app/chat_renderer.php';
