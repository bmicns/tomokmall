<?php
declare(strict_types=1);
/**
 * POST /api/chat/quick.php — 우측 하단 'AI 빠른 견적' 창(케이토목식 짧은 상담, 사용자 결정 2026-11-23 — 지금 AI 상담과 비교용)
 *   {action:'ask', sid, msg}  → {ok, sid, text, events}   (대화 저장까지 서버에서 — 페이지를 옮겨도 이어짐)
 *   {action:'new'}            → {ok, sid}                  (새 대화)
 *   {action:'to_estimate', sid} → {ok, moved, skipped, url} (창 안 목록 → 사이트 견적서, [견적 요청하기])
 * 담는 곳(A11, 사용자 결정 2026-11-24): 케이토목처럼 '창 안 목록'(대화마다 세션 'qc_list_<키>', app/cart.php cart_store_key)에만 담고,
 *   수량을 모르면 0(미정)으로 담아 묻는다. '견적서 만들어줘' 또는 [견적 요청하기] 때 사이트 견적서로 옮긴다(수량 0 품목은 빼고).
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

/** '견적서 만들어줘' 류 짧은 말인지(창 안 목록 → 견적서). 다른 요청과 섞인 긴 말은 상담으로 */
function quick_chat_wants_estimate(string $msg): bool
{
    return mb_strlen($msg) <= 25
        && (bool) preg_match('/견적서\s*(를|좀)?\s*(만들|작성|뽑|내\s*줘|내주|발행|보내)|견적\s*요청\s*(해|할|하)/u', $msg);
}

/**
 * 창 안 목록 → 사이트 견적서. 같은 상품은 목록 수량으로 맞춤(cart_merge_quick_list), 수량 0 품목은 빼고 이름을 돌려준다.
 * @return array{moved:int, skipped:list<string>, empty:bool}
 */
function quick_chat_list_to_estimate(string $sid): array
{
    $listKey = 'qc_list_' . $sid;
    cart_store_key($listKey);
    $list = cart_session_read();
    cart_store_key('cart');
    $merged = cart_merge_quick_list(cart_session_read(), $list);
    if ($merged['moved']) {
        cart_session_write($merged['cart']);
    } elseif (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    cart_store_key($listKey);
    $skipped = [];
    if ($merged['skipped']) {
        foreach (cart_fetch_items(array_fill_keys($merged['skipped'], 0)) as $it) {
            $skipped[] = trim($it['name'] . ' ' . parse_spec_field((string) $it['spec'])['spec']);
        }
    }
    return ['moved' => count($merged['moved']), 'skipped' => $skipped, 'empty' => !$list];
}

/** 옮긴 결과 → 안내 글 */
function quick_chat_estimate_text(array $m): string
{
    if ($m['empty']) {
        return '아직 이 창에 담긴 제품이 없습니다. 자재·규격·수량을 먼저 알려 주세요.';
    }
    $skip = $m['skipped'] ? '수량이 정해지지 않아 옮기지 않은 품목: ' . implode(', ', $m['skipped']) . ' — 필요하신 수량을 알려 주세요.' : '';
    if ($m['moved'] === 0) {
        return "수량이 정해지지 않은 품목만 있어 견적서로 옮기지 못했습니다.\n" . $skip;
    }
    return "담아 두신 {$m['moved']}개 품목을 견적서로 옮겼습니다. [견적 요청하기]에서 현장 지역·연락처를 확인해 보내 주시면 담당자가 최종 견적을 드립니다."
        . ($skip !== '' ? "\n" . $skip : '');
}

$body   = json_decode((string) file_get_contents('php://input'), true);
$body   = is_array($body) ? $body : [];
$action = (string) ($body['action'] ?? '');

if ($action === 'new') {
    echo json_encode(['ok' => true, 'sid' => quick_chat_key_issue()]);
    exit;
}
if (!in_array($action, ['ask', 'photo', 'to_estimate'], true)) {
    echo json_encode(['ok' => false, 'error' => '잘못된 요청입니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uid = isset($user['id']) ? (int) $user['id'] : null;
$sid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($body['sid'] ?? '')) ?? '';
if (!str_starts_with($sid, 'qc') || !rag_chat_key_allowed($sid) || !rag_session_writable($sid, $uid)) {
    $sid = quick_chat_key_issue();   // 없거나 이 세션 것이 아니면 새 대화
}
cart_store_key('qc_list_' . $sid);   // 이 창의 담기·빼기·수량은 창 안 목록에만(사이트 견적서는 옮길 때만)

if ($action === 'to_estimate') {
    $m = quick_chat_list_to_estimate($sid);
    echo json_encode($m['moved'] > 0
        ? ['ok' => true, 'sid' => $sid, 'moved' => $m['moved'], 'skipped' => $m['skipped'], 'url' => '/estimate.php']
        : ['ok' => false, 'sid' => $sid, 'error' => quick_chat_estimate_text($m)], JSON_UNESCAPED_UNICODE);
    exit;
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
    $userMsg    = $items['text'];
    $photoItems = $items['items'];
} else {
    $userMsg = trim((string) ($body['msg'] ?? ''));
    if ($userMsg === '' || mb_strlen($userMsg) > QUICK_CHAT_MAX_LEN) {
        echo json_encode(['ok' => false, 'sid' => $sid, 'error' => '질문은 1~' . QUICK_CHAT_MAX_LEN . '자로 적어 주세요.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    set_time_limit(90);
}

$history  = rag_load_session($sid);

// 견적서 사진 — 케이토목처럼 읽은 품목을 바로 목록에 담는다(GPT 없이: 품명+규격이 같은 상품, 사진 수량, 못 찾은 줄은 '확인 필요')
if ($action === 'photo') {
    $pm   = quick_photo_match($photoItems, 'quick_match_find');
    $list = cart_session_read();
    foreach ($pm['add'] as $pid => $q) {
        $list[$pid] = $q > 0 ? $q : ($list[$pid] ?? 0);   // 이미 담긴 상품은 사진 수량으로 맞춤(수량 모름이면 그대로)
    }
    if ($pm['add']) { cart_session_write($list); }
    $region = quote_region_get();
    $text   = quick_photo_reply_text(count($photoItems), count($pm['add']), $pm['missing'], $region !== null);
    $events = $list ? [quote_draft_current($region)] : [];
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $history[] = ['role' => 'user',      'content' => $userMsg];
    $history[] = ['role' => 'assistant', 'content' => $text];
    rag_save_session($sid, array_slice($history, -40));
    rag_log_chat($sid, $userMsg, $text, $events, '', $uid);
    try { $events = quick_chat_quote_media($events); } catch (Throwable $e) { error_log('quick chat media: ' . $e->getMessage()); }
    echo json_encode(['ok' => true, 'sid' => $sid, 'asked' => $userMsg, 'text' => $text, 'events' => $events], JSON_UNESCAPED_UNICODE);
    exit;
}

// '견적서 만들어줘' — 케이토목처럼 모아 둔 목록으로 견적서를 만든다(GPT 없이 바로: 사이트 견적서로 옮기고 견적 표·버튼)
if ($action === 'ask' && quick_chat_wants_estimate($userMsg)) {
    $m    = quick_chat_list_to_estimate($sid);
    $text = quick_chat_estimate_text($m);
    $events = [];
    if (!$m['empty']) {
        $events[] = quote_draft_current(quote_region_get());   // 창 안 목록 기준 표(수량 미정 품목 포함)
        if ($m['moved'] > 0) {
            cart_store_key('cart');
            [$cnt, $tot] = cart_summary(cart_session_read());
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            cart_store_key('qc_list_' . $sid);
            $events[] = ['type' => 'cart_update', 'count' => $cnt, 'total' => $tot];   // 화면의 견적서 표시 갱신
            $events[] = ['type' => 'estimate_moved', 'moved' => $m['moved'], 'url' => '/estimate.php'];
        }
    }
    $history[] = ['role' => 'user',      'content' => $userMsg];
    $history[] = ['role' => 'assistant', 'content' => $text];
    rag_save_session($sid, array_slice($history, -40));
    rag_log_chat($sid, $userMsg, $text, $events, '', $uid);
    try { $events = quick_chat_quote_media($events); } catch (Throwable $e) { error_log('quick chat media: ' . $e->getMessage()); }
    echo json_encode(['ok' => true, 'sid' => $sid, 'text' => $text, 'events' => $events], JSON_UNESCAPED_UNICODE);
    exit;
}

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

// 창에 쓰는 이벤트만(견적 표·추천 제품 이름). 담기는 창 안 목록이라 사이트 견적서 갱신(cart_update)은 보내지 않음
$events = array_values(array_filter($result['events'] ?? [], static fn($e) =>
    in_array($e['type'] ?? '', ['quote_draft', 'product_cards'], true)));
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
