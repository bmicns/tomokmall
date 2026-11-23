<?php
declare(strict_types=1);
/**
 * POST /api/chat/quick.php — 우측 하단 'AI 빠른 견적' 창(케이토목식 짧은 상담, 사용자 결정 2026-11-23 — 지금 AI 상담과 비교용)
 *   {action:'ask', sid, msg}  → {ok, sid, text, events}   (대화 저장까지 서버에서 — 페이지를 옮겨도 이어짐)
 *   {action:'new'}            → {ok, sid}                  (새 대화)
 *
 * 인증: 기능 스위치(app/features.php FEATURE_AI_QUICK_CHAT) — 'staff' 면 관리자·직원만, 'off' 면 아무도(403).
 * 검증: msg 1~200자, sid 는 이 브라우저 세션이 발급받은 키만(rag_chat_key_allowed)·다른 회원 상담 쓰기 금지(rag_session_writable).
 * 상담 엔진·도구·가격 계산은 AI 상담(/chat.php)과 같고, 답변 방식만 짧게(rag_process style 'quick').
 * 상담 키는 'qc' 로 시작 — chat_logs 에서 빠른 견적 창 상담을 따로 볼 수 있게(비교용).
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/rag.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const QUICK_CHAT_MAX_LEN = 200;

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}
$user = current_user();
if (!ai_quick_chat_visible($user)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => '사용할 수 없는 기능입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** 빠른 견적 창 상담 키 발급('qc' + 30자리) — 이 브라우저 세션에 허용 등록 */
function quick_chat_key_issue(): string
{
    $key = 'qc' . bin2hex(random_bytes(15));
    rag_chat_key_allow($key);
    return $key;
}

$body   = json_decode((string) file_get_contents('php://input'), true);
$body   = is_array($body) ? $body : [];
$action = (string) ($body['action'] ?? '');

if ($action === 'new') {
    echo json_encode(['ok' => true, 'sid' => quick_chat_key_issue()]);
    exit;
}
if ($action !== 'ask') {
    echo json_encode(['ok' => false, 'error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userMsg = trim((string) ($body['msg'] ?? ''));
if ($userMsg === '' || mb_strlen($userMsg) > QUICK_CHAT_MAX_LEN) {
    echo json_encode(['ok' => false, 'error' => '질문은 1~' . QUICK_CHAT_MAX_LEN . '자로 적어 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}
$uid = isset($user['id']) ? (int) $user['id'] : null;
$sid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($body['sid'] ?? '')) ?? '';
if (!str_starts_with($sid, 'qc') || !rag_chat_key_allowed($sid) || !rag_session_writable($sid, $uid)) {
    $sid = quick_chat_key_issue();   // 없거나 이 세션 것이 아니면 새 대화
}

set_time_limit(90);

$history  = rag_load_session($sid);
$messages = array_values(array_filter($history, static fn($m) =>
    !(is_array($m) && ($m['role'] ?? '') === 'assistant' && trim((string) ($m['content'] ?? '')) === '')));
if (count($messages) > 20) {
    $messages = array_slice($messages, -20);
}
$messages[] = ['role' => 'user', 'content' => $userMsg];

$result = rag_process($messages, $sid, 'quick');
if (!($result['ok'] ?? false)) {
    echo json_encode(['ok' => false, 'sid' => $sid, 'error' => $result['error'] ?? '답변을 만들지 못했습니다. 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 대화 저장(창은 페이지마다 다시 열리므로 서버에 남김) + 상담 로그 — /chat.php 와 같은 표
$history[] = ['role' => 'user',      'content' => $userMsg];
$history[] = ['role' => 'assistant', 'content' => (string) ($result['text'] ?? '')];
if (count($history) > 40) {
    $history = array_slice($history, -40);
}
rag_save_session($sid, $history);
rag_log_chat($sid, $userMsg, (string) ($result['text'] ?? ''), $result['events'] ?? [], (string) ($result['category'] ?? ''), $uid);

// 창에 쓰는 이벤트만(견적 표·장바구니·추천 제품 이름)
$events = array_values(array_filter($result['events'] ?? [], static fn($e) =>
    in_array($e['type'] ?? '', ['quote_draft', 'cart_update', 'product_cards'], true)));
echo json_encode(['ok' => true, 'sid' => $sid, 'text' => (string) ($result['text'] ?? ''), 'events' => $events], JSON_UNESCAPED_UNICODE);
