<?php
declare(strict_types=1);
/**
 * POST /api/chat/quick.php — 우측 하단 'AI 빠른 견적' 창(케이토목식 짧은 상담, 사용자 결정 2026-11-23 — 지금 AI 상담과 비교용)
 *   {action:'ask', sid, msg}  → {ok, sid, text, events}   (대화 저장까지 서버에서 — 페이지를 옮겨도 이어짐)
 *   {action:'new'}            → {ok, sid}                  (새 대화)
 *   {action:'photo', sid, image, consent:true} → {ok, sid, asked, text, events}
 *        견적서·자재 목록 사진(jpg·png 10MB, data URL/base64)을 OpenAI 로 품목만 읽어 그 글로 'ask' 와 같게 답한다(A10, 2026-11-24).
 *        손님 동의(consent === true) 없으면 받지 않음. 사진은 저장·로그하지 않고 읽은 품목 글만 대화·상담 로그에 남는다.
 *
 * 인증: 기능 스위치(app/features.php FEATURE_AI_QUICK_CHAT) — 'staff' 면 관리자·직원만, 'off' 면 아무도(403).
 * 검증: msg 1~200자, sid 는 이 브라우저 세션이 발급받은 키만(rag_chat_key_allowed)·다른 회원 상담 쓰기 금지(rag_session_writable).
 * 상담 엔진·도구·가격 계산은 AI 상담(/chat.php)과 같고, 답변 방식만 짧게(rag_process style 'quick').
 * 상담 키는 'qc' 로 시작 — chat_logs 에서 빠른 견적 창 상담을 따로 볼 수 있게(비교용).
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/rag.php';
require __DIR__ . '/../../../app/quick_photo.php';

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

/**
 * 견적 표 품목에 케이토목 1분 자동견적처럼 상품 사진·상품목록(분류)·도면자료(규격 이미지) 연결을 붙인다 — 화면 표시용 읽기만(A11).
 * 가격·수량 값은 건드리지 않는다. 조회가 안 되면 그대로 돌려준다.
 */
function quick_chat_quote_media(array $events): array
{
    $ids = [];
    foreach ($events as $e) {
        if (($e['type'] ?? '') !== 'quote_draft') { continue; }
        foreach ((array) ($e['items'] ?? []) as $it) { $ids[] = (int) ($it['product_id'] ?? 0); }
    }
    $ids = array_values(array_unique(array_filter($ids)));
    $pdo = $ids ? db() : null;
    if (!$pdo) {
        return $events;
    }
    $ph   = implode(',', array_fill(0, count($ids), '?'));
    $cols = $pdo->query('SHOW COLUMNS FROM products')->fetchAll(PDO::FETCH_COLUMN);
    $sel  = ['id', 'category_id', 'image_url'];
    foreach (['image_thumb_url', 'image_spec_url'] as $c) { if (in_array($c, $cols, true)) { $sel[] = $c; } }
    $st = $pdo->prepare('SELECT ' . implode(', ', $sel) . " FROM products WHERE id IN ({$ph})");
    $st->execute($ids);
    $media = [];
    foreach ($st->fetchAll() as $r) {
        $media[(int) $r['id']] = [
            'image'       => (string) (($r['image_url'] ?? '') ?: ($r['image_thumb_url'] ?? '')),   // 케이토목처럼 큰 사진: 상세, 없으면 썸네일(S18)
            'category_id' => (int) ($r['category_id'] ?? 0),
            'spec_image'  => (string) ($r['image_spec_url'] ?? ''),
        ];
    }
    foreach ($events as &$e) {
        if (($e['type'] ?? '') !== 'quote_draft') { continue; }
        foreach ($e['items'] as &$it) {
            $it += $media[(int) ($it['product_id'] ?? 0)] ?? [];
        }
        unset($it);
    }
    unset($e);
    return $events;
}

$body   = json_decode((string) file_get_contents('php://input'), true);
$body   = is_array($body) ? $body : [];
$action = (string) ($body['action'] ?? '');

if ($action === 'new') {
    echo json_encode(['ok' => true, 'sid' => quick_chat_key_issue()]);
    exit;
}
if ($action !== 'ask' && $action !== 'photo') {
    echo json_encode(['ok' => false, 'error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = isset($user['id']) ? (int) $user['id'] : null;
$sid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($body['sid'] ?? '')) ?? '';
if (!str_starts_with($sid, 'qc') || !rag_chat_key_allowed($sid) || !rag_session_writable($sid, $uid)) {
    $sid = quick_chat_key_issue();   // 없거나 이 세션 것이 아니면 새 대화
}

if ($action === 'photo') {
    // 견적서 사진 → 품목 글(사진은 이 요청 메모리에서만 쓰고 버림 — 저장·로그 없음)
    if (!ai_quick_photo_enabled()) {
        echo json_encode(['ok' => false, 'sid' => $sid, 'error' => '사진 첨부는 지금 사용할 수 없습니다.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (($body['consent'] ?? null) !== true) {
        echo json_encode(['ok' => false, 'sid' => $sid, 'error' => '안내를 확인하고 동의에 체크해 주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $img = quick_photo_decode((string) ($body['image'] ?? ''));
    unset($body);
    if (!$img['ok']) {
        echo json_encode(['ok' => false, 'sid' => $sid, 'error' => $img['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(150);
    $read = quick_photo_read($img['bytes'], $img['mime']);
    unset($img);
    if ($read === null) {
        echo json_encode(['ok' => false, 'sid' => $sid, 'error' => '사진을 읽지 못했습니다. 잠시 뒤 다시 올려 주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $items = quick_photo_items_text($read);
    if (!$items['ok']) {
        echo json_encode(['ok' => false, 'sid' => $sid, 'error' => $items['error']], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $userMsg = $items['text'];
} else {
    $userMsg = trim((string) ($body['msg'] ?? ''));
    if ($userMsg === '' || mb_strlen($userMsg) > QUICK_CHAT_MAX_LEN) {
        echo json_encode(['ok' => false, 'sid' => $sid, 'error' => '질문은 1~' . QUICK_CHAT_MAX_LEN . '자로 적어 주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(90);
}

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
try {
    $events = quick_chat_quote_media($events);
} catch (Throwable $e) {
    error_log('quick chat media: ' . $e->getMessage());   // 사진·연결이 없어도 견적 표는 그대로
}
$out = ['ok' => true, 'sid' => $sid, 'text' => (string) ($result['text'] ?? ''), 'events' => $events];
if ($action === 'photo') {
    $out['asked'] = $userMsg;   // 창의 '나' 말풍선에 사진에서 읽은 품목을 보여 줌
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
