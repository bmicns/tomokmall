<?php
/** 채팅 UI HTML 렌더링 전용 — 비즈니스 로직 없음
 *  호출자: public/chat.php (GET 일반 요청)
 *  의존: $sessionKey (string), $_isLoggedIn (bool), $_restored (array, 복원할 이전 상담 메시지),
 *        $_restoredEvents (array, [메시지 인덱스 => chat_logs 이벤트]) — 호출자가 주입
 */
$_sid     = $sessionKey ?? '';
// 복원 이벤트: 화면 렌더러(chat-ai.js processEvent)가 쓰는 상품 추천표·견적 카드만, 필드 형식 고정
$_cleanEvents = static function (array $events): array {
    $out = [];
    foreach ($events as $ev) {
        $type = $ev['type'] ?? '';
        if ($type === 'product_cards' && is_array($ev['products'] ?? null)) {
            $products = [];
            foreach ($ev['products'] as $p) {
                if (!is_array($p) || (int) ($p['id'] ?? 0) <= 0) continue;
                $products[] = [
                    'id'            => (int) $p['id'],
                    'name'          => (string) ($p['name'] ?? ''),
                    'spec'          => (string) ($p['spec'] ?? ''),
                    'price_b2c'     => isset($p['price_b2c']) ? (int) $p['price_b2c'] : null,
                    'price_state'   => in_array($p['price_state'] ?? null, ['ok', 'inquiry', 'stopped', 'changed', 'unknown'], true)
                                       ? $p['price_state'] : 'unknown',
                    'image_url'     => isset($p['image_url']) && $p['image_url'] !== '' ? (string) $p['image_url'] : null,
                    'suggested_qty' => isset($p['suggested_qty']) ? (int) $p['suggested_qty'] : null,
                    'qty_basis'     => isset($p['qty_basis']) && $p['qty_basis'] !== '' ? (string) $p['qty_basis'] : null,
                ];
            }
            if ($products) $out[] = ['type' => 'product_cards', 'products' => $products];
        } elseif ($type === 'estimate_created' && (int) ($ev['estimate_id'] ?? 0) > 0) {
            $out[] = ['type' => 'estimate_created', 'estimate_id' => (int) $ev['estimate_id']];
        }
    }
    return $out;
};
// 복원할 이전 상담: 사용자·AI 텍스트 + AI 답변의 상품 추천표·견적 카드 (history.php 요약 추출과 같은 content 형식 처리)
$_restoredMsgs = [];
foreach (($_restored ?? []) as $_i => $_m) {
    $_role = is_array($_m) ? ($_m['role'] ?? '') : '';
    if ($_role !== 'user' && $_role !== 'assistant') continue;
    $_c = $_m['content'] ?? null;
    $_text = is_string($_c) ? $_c : (is_array($_c) ? (string) ($_c[0]['text'] ?? '') : '');
    $_ev = $_role === 'assistant' ? $_cleanEvents($_restoredEvents[$_i] ?? []) : [];
    if (trim($_text) === '' && !$_ev) continue; // 텍스트 없는 카드 응답은 카드가 있을 때만
    $_restoredMsgs[] = ['role' => $_role, 'text' => $_text, 'events' => $_ev];
}
$_hasRestored = $_restoredMsgs !== [];
require __DIR__ . '/layout.php';
render_header('AI 자재 상담', 'chat');
?>
<style>
  /* ── 2컬럼 레이아웃: 대화(왼쪽 70%) + 견적서(오른쪽 30%) — 'AI 상담(After)' 시안 ── */
  main {
    padding: 0;
    max-width: none;
    height: calc(100dvh - var(--header-h));
    min-height: unset;
    display: grid;
    grid-template-columns: minmax(0, 7fr) minmax(280px, 3fr);
    gap: 0;
    overflow: hidden;
  }

  /* ── 오른쪽: 견적 패널 ───────────────────────────────────── */
  .est-panel {
    border-left: 1px solid #E5E5E5;
    background: var(--color-surface);
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .est-panel-header {
    height: 40px;
    padding: 0 20px;
    border-bottom: 1px solid #E5E5E5;
    font-size: 15px;
    font-weight: 700;
    color: var(--color-text-primary);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .btn-est-clear {
    background: none; border: none;
    font-size: 12.5px; color: var(--color-text-secondary);
    cursor: pointer; font-family: var(--font); padding: 6px 0;
  }
  .btn-est-clear:hover { color: var(--color-text-primary); }
  .est-items {
    flex: 1;
    overflow-y: auto;
    padding: 4px 20px;
  }
  .est-items::-webkit-scrollbar { width: 6px; }
  .est-items::-webkit-scrollbar-thumb { background: #D5DBE3; border-radius: 3px; }
  .est-empty {
    text-align: center;
    padding: 48px 12px 0;
    display: flex;
    flex-direction: column;
    align-items: center;
  }
  .est-empty-icon {
    width: 48px; height: 48px; border-radius: 12px;
    background: var(--color-surface-2); color: #999999;
    display: flex; align-items: center; justify-content: center;
  }
  .est-empty-title { margin-top: 12px; font-size: 14px; font-weight: 600; color: #555555; }
  .est-empty-desc  { margin-top: 4px; font-size: 13px; color: var(--color-text-secondary); }
  .est-item {
    border-bottom: 1px solid var(--color-border);
    padding: 10px 0;
  }
  .est-item-top  { display: flex; gap: 8px; align-items: flex-start; }
  .est-item-thumb {
    width: 50px; height: 50px; flex-shrink: 0;
    object-fit: cover; background: #EEEEEE; display: block;
  }
  .est-item-thumb-ph { background: #E8E8E8; }
  .est-item-info { flex: 1; min-width: 0; display: flex; gap: 6px; align-items: flex-start; }
  .est-item-info-left { flex: 1; min-width: 0; }
  .est-item-info-right { flex-shrink: 0; display: flex; flex-direction: column; align-items: flex-end; gap: 4px; }
  .est-item-name { font-size: 18px; font-weight: 500; line-height: 1.4; }
  .est-item-spec { font-size: 11px; color: var(--color-text-secondary); margin-top: 2px; }
  .est-item-qty-wrap { display: flex; align-items: center; gap: 4px; }
  .est-item-row  { display: flex; align-items: center; justify-content: flex-end; gap: 6px; }
  .est-item-qty-wrap { display: flex; align-items: center; gap: 4px; }
  .est-item-qty {
    width: 44px; padding: 3px 4px;
    border: 1px solid var(--color-border);
    font-size: 12px; text-align: center;
    font-family: var(--font); background: #fff;
  }
  .btn-est-remove {
    background: none; border: none;
    color: var(--color-text-secondary);
    cursor: pointer; font-size: 15px;
    padding: 0 2px; line-height: 1;
  }
  .btn-est-remove:hover { color: #E53E3E; }
  .est-item-subtotal { font-size: 13px; font-weight: 600; color: var(--color-primary); white-space: nowrap; }

  /* 하단: 합계(품목이 있을 때만) + 견적 요청 버튼 */
  .est-actions {
    padding: 14px 20px 16px; flex-shrink: 0;
    border-top: 1px solid #E5E5E5; background: var(--color-surface);
  }
  .est-total-row {
    display: flex;
    justify-content: space-between;
    align-items: baseline;
    margin-bottom: 10px;
  }
  .est-total-label { color: #555555; font-size: 13px; }
  .est-total-label small { font-size: 12px; }
  .est-total-val { color: var(--color-text-primary); font-size: 17px; font-weight: 700; }
  .btn-est-req {
    display: flex; align-items: center; justify-content: center;
    width: 100%; height: 46px; border-radius: 8px;
    background: var(--color-primary); color: #fff;
    border: none;
    font-size: 15px; font-family: var(--font); font-weight: 700;
    cursor: pointer; text-align: center; text-decoration: none;
    transition: background .15s;
  }
  .btn-est-req:hover { background: var(--color-primary-hover); }
  .btn-est-req.disabled { background: var(--color-border); color: #999999; pointer-events: none; cursor: not-allowed; }

  /* ── 왼쪽: 채팅 영역 ─────────────────────────────────────── */
  .chat-area {
    display: flex;
    flex-direction: column;
    overflow: hidden;
    min-width: 0;
    background: var(--color-bg);
  }
  .messages {
    flex: 1;
    overflow-y: auto;
    padding: 24px;
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .messages::-webkit-scrollbar { width: 6px; }
  .messages::-webkit-scrollbar-track { background: transparent; }
  .messages::-webkit-scrollbar-thumb { background: #D5DBE3; border-radius: 3px; }

  /* 첫 인사 말풍선 (대화 시작 전 화면) */
  .msg-greet .msg-avatar {
    width: 30px; height: 30px; border-radius: 4px;
    border-color: var(--color-border); background: #FFFFFF;
    color: var(--color-text-secondary); font-weight: 500;
  }
  .msg-greet .msg-content { display: block; }
  .msg-greet .msg-bubble {
    display: inline-block; max-width: 100%;
    padding: 12px 16px; border-radius: 4px 14px 14px 14px;
    background: var(--color-surface); border-color: var(--color-border);
    font-size: 14.5px; line-height: 1.7; color: var(--color-text-primary);
  }

  .msg { display: flex; gap: 10px; max-width: 680px; }
  .msg.user { align-self: flex-end; flex-direction: row-reverse; }
  .msg-avatar {
    width: 28px; height: 28px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; color: var(--color-text-secondary);
    flex-shrink: 0;
    border-radius: 4px;
  }
  .msg.user .msg-avatar { background: var(--color-primary); color: #fff; }
  .msg-content { display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 0; }
  .msg-bubble {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    padding: 10px 14px;
    border-radius: 4px 14px 14px 14px;   /* 첫 인사 말풍선과 같은 모서리(프로필 쪽 위 모서리만 작게) */
    font-size: 15px; line-height: 1.6;
    white-space: pre-wrap; word-break: break-word;
    color: #111;
  }
  .msg.user .msg-bubble { background: #EEEEEE; border-radius: 14px 4px 14px 14px; }

  /* ── 비교 테이블 ──────────────────────────────────────────── */
  .compare-table { width: 100%; overflow-x: auto; }
  .compare-table table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .compare-table th {
    background: #F5F5F5; padding: 7px 10px; text-align: left;
    font-size: 11px; font-weight: 600; color: var(--color-text-secondary);
    border-bottom: 2px solid var(--color-border); white-space: nowrap;
  }
  .compare-table td { padding: 8px 10px; border-bottom: 1px solid var(--color-border); vertical-align: middle; }
  .compare-img { width: 60px; height: 60px; object-fit: cover; background: #F0F0F0; display: block; flex-shrink: 0; }
  .compare-img-ph { width: 60px; height: 60px; background: #E8E8E8; display: block; }
  .compare-name { font-size: 13px; font-weight: 500; line-height: 1.4; }
  .compare-spec { font-size: 11px; color: var(--color-text-secondary); margin-top: 2px; }
  .compare-detail { font-size: 11px; font-weight: 400; margin-left: 4px; color: var(--color-primary); }
  .compare-guide { list-style: none; margin: 4px 0 0; padding: 0; font-size: 11px; line-height: 1.5; color: var(--color-text-primary); }
  .compare-guide li::before { content: '✓ '; color: var(--color-primary); }
  .compare-qtybasis { font-size: 11px; color: var(--color-primary); font-weight: 600; margin-top: 3px; }
  .compare-price { font-size: 13px; font-weight: 600; color: var(--color-primary); white-space: nowrap; }
  .compare-unit { font-size: 11px; font-weight: 400; color: var(--color-text-secondary); }
  .compare-cost { font-size: 11px; font-weight: 600; color: var(--color-text-primary); margin-top: 3px; }
  .compare-diff { display: block; font-size: 10px; font-weight: 400; color: var(--color-text-secondary); }
  .compare-lowest { display: inline-block; padding: 0 5px; margin-left: 2px; font-size: 10px; font-weight: 700; color: #fff; background: var(--color-primary); }
  .compare-summary { font-size: 12px; line-height: 1.5; padding: 8px 10px; margin-bottom: 6px; background: var(--color-surface); border: 1px solid var(--color-border); }
  .compare-summary b { color: var(--color-primary); }
  .compare-summary span { display: block; font-size: 11px; color: var(--color-text-secondary); }
  .spec-unavailable { margin-top: 10px; padding: 10px 12px; border: 1px solid var(--color-border); border-left: 3px solid var(--color-text-primary); background: var(--color-surface); font-size: 12px; line-height: 1.55; }
  .spec-unavailable-title { font-weight: 700; color: var(--color-text-primary); margin-bottom: 4px; }
  .spec-unavailable-body { color: var(--color-text-secondary, inherit); word-break: keep-all; }
  .legal-sources { margin-top: 10px; padding: 10px 12px; border: 1px solid var(--color-border); background: var(--color-surface); font-size: 12px; line-height: 1.55; }
  .legal-sources-title { font-weight: 700; color: var(--color-text-primary); margin-bottom: 6px; }
  .legal-sources ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  .legal-source-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
  .legal-badge { padding: 1px 7px; border: 1px solid #F0C48A; background: #FFF4E5; color: #7A3E00; font-size: 11px; font-weight: 700; }
  .legal-source-meta, .legal-source-cond { color: var(--color-text-secondary); word-break: keep-all; }
  .legal-source-meta a { color: var(--color-primary); text-decoration: underline; }
  .compare-price.is-state { color: var(--color-text-secondary); font-weight: 500; }
  .compare-state-note { font-size: 11px; color: var(--color-danger); margin-top: 3px; }
  /* 전역 input[type=number](width:100%·padding 8px 12px)가 덮어써 숫자가 안 보이던 문제 방지 — .compare-table 한정 */
  .compare-table input.compare-qty {
    width: 72px; padding: 4px; border: 1px solid var(--color-border);
    font-size: 13px; text-align: center; font-family: var(--font); background: #fff;
  }
  .btn-compare-add {
    background: var(--color-primary); color: #fff; border: none;
    padding: 6px 10px; font-size: 12px; font-family: var(--font); cursor: pointer; white-space: nowrap;
  }
  .btn-compare-add:hover { background: #3A6BBF; }
  .btn-compare-add.added { background: #27AE60; cursor: default; }
  .btn-compare-add:disabled { opacity: .5; cursor: not-allowed; }

  /* ── 상품 카드 ────────────────────────────────────────────── */
  .product-cards {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 8px;
  }
  .product-card {
    border: 1px solid var(--color-border);
    background: var(--color-surface);
    display: flex; flex-direction: column; overflow: hidden;
  }
  .product-card:hover { border-color: var(--color-primary); }
  .product-card-img { width: 100%; height: 110px; object-fit: cover; background: #F5F5F5; display: block; flex-shrink: 0; }
  .product-card-img-ph { width: 100%; height: 110px; background: #E8E8E8; flex-shrink: 0; }
  .product-card-body { padding: 8px 10px; display: flex; flex-direction: column; gap: 3px; flex: 1; text-align: left; }
  .product-card-cat   { font-size: 11px; color: var(--color-text-secondary); }
  .product-card-name  { font-size: 13px; font-weight: 500; line-height: 1.4; }
  .product-card-spec  { font-size: 11px; color: var(--color-text-secondary); }
  .product-card-price { font-size: 13px; font-weight: 600; color: var(--color-primary); margin-top: 2px; }
  .product-card-footer {
    display: flex; gap: 4px; align-items: stretch;
    padding: 6px 8px; border-top: 1px solid var(--color-border);
    background: #FAFAFA;
  }
  .product-card-qty {
    flex: 1; padding: 0 2px;
    border: 1px solid var(--color-border);
    font-size: 12px; text-align: center;
    font-family: var(--font); background: #fff;
    min-width: 0;
  }
  .btn-add-estimate {
    flex: 1; background: var(--color-primary); color: #fff;
    border: none; padding: 8px 6px;
    font-size: 12px; font-family: var(--font); font-weight: 500; cursor: pointer;
    white-space: nowrap;
  }
  .btn-add-estimate:hover    { background: #3A6BBF; }
  .btn-add-estimate:disabled { opacity: .5; cursor: not-allowed; }
  .btn-add-estimate.added    { background: #27AE60; cursor: default; }

  /* ── 견적 완료 카드 ──────────────────────────────────────── */
  .estimate-card {
    border: 1px solid #27AE60; background: #E8F8EF;
    padding: 12px 16px; display: flex; align-items: flex-start; gap: 10px; max-width: 340px;
  }
  .estimate-card-icon  { color: #27AE60; font-size: 16px; line-height: 1; }
  .estimate-card-title { font-size: 13px; font-weight: 500; color: #27AE60; }
  .estimate-card-sub   { font-size: 11px; color: var(--color-text-secondary); margin-top: 2px; }

  /* ── 커서 ────────────────────────────────────────────────── */
  .cursor {
    display: inline-block; width: 2px; height: 14px;
    background: var(--color-primary);
    animation: blink .7s step-end infinite; vertical-align: middle;
  }
  @keyframes blink { 50% { opacity: 0; } }

  /* ── 입력 영역 ───────────────────────────────────────────── */
  .input-area {
    border-top: 1px solid #E5E5E5;
    background: var(--color-surface);
    padding: 12px 24px 16px; flex-shrink: 0;
  }
  /* 자주 찾는 상황: 라벨 + 좌우 이동 버튼, 카드는 가로로 밀어서 이동 */
  .starters { margin-bottom: 10px; }
  .starters-head {
    display: flex; align-items: center; justify-content: space-between;
    gap: 12px; margin-bottom: 8px;
  }
  .starters-label { font-size: 12.5px; font-weight: 700; color: #555555; }
  .starters-arrows { display: inline-flex; gap: 4px; }
  .starters-arrow {
    width: 28px; height: 28px; padding: 0;
    border: 1px solid var(--color-border); border-radius: 6px;
    background: #FFFFFF; color: #555555; cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .starters-arrow:not(:disabled):hover { border-color: var(--color-primary); color: var(--color-primary); }
  .starters-arrow:disabled { background: #FFFFFF; color: #CCCCCC; cursor: default; }
  .hint-chips {
    display: flex; gap: 8px;
    overflow-x: auto; scroll-snap-type: x mandatory; scroll-behavior: smooth;
    scrollbar-width: none;
  }
  .hint-chips::-webkit-scrollbar { display: none; }
  .hint-chip {
    flex: 0 0 auto; scroll-snap-align: start;
    display: flex; align-items: center; min-height: 32px; padding: 5px 12px;
    border: 1px solid #A9C4E4; border-radius: 8px; background: #EEF4FB;
    font-size: 13.5px; color: var(--color-primary); white-space: nowrap;
    cursor: pointer; font-family: var(--font); text-align: left;
    transition: border-color .15s, box-shadow .15s;
  }
  .hint-chip:hover { border-color: var(--color-primary); box-shadow: 0 4px 14px rgba(0,78,161,0.10); }
  .hint-chip:focus { outline: none; }
  .hint-chip:focus-visible { border-color: var(--color-primary); box-shadow: 0 0 0 2px rgba(0,78,161,0.25); }
  .input-row { display: flex; gap: 8px; }
  .input-row textarea {
    flex: 1; min-width: 0; background: var(--color-surface-2);
    border: 1px solid #E5E5E5; border-radius: 8px;
    padding: 12px 14px; font-size: 14.5px; font-family: var(--font);
    color: var(--color-text-primary); resize: none; height: 46px;
    line-height: 1.5; outline: none; transition: border-color .15s;
    /* 한 줄 입력칸: 줄 높이가 칸 안쪽보다 커서 생기던 세로 스크롤바 숨김(Shift+Enter 여러 줄은 커서 따라 스크롤) */
    scrollbar-width: none;
  }
  .input-row textarea::-webkit-scrollbar { display: none; }
  .input-row textarea:focus { border-color: var(--color-primary); }
  .input-row textarea::placeholder { color: #AAAAAA; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
  .btn-send {
    width: 76px; height: 46px; border-radius: 8px;
    background: var(--color-primary); color: #fff; border: none;
    font-size: 14px; font-family: var(--font);
    font-weight: 600; cursor: pointer; flex-shrink: 0;
  }
  .btn-send:not(:disabled):hover { background: var(--color-primary-hover); }
  /* 입력이 비었거나 답변 대기 중이면 회색 (전송 동작은 chat-ai.js 그대로) */
  .btn-send:disabled,
  .input-row textarea:placeholder-shown + .btn-send {
    background: var(--color-border); color: #999999; cursor: not-allowed;
  }
  .btn-reset {
    background: #F5F5F5; color: var(--color-text-secondary); border: none;
    padding: 0 12px; font-size: 11px; font-family: var(--font);
    cursor: pointer; flex-shrink: 0;
  }
  .btn-reset:hover { color: var(--color-text-primary); }

  /* ── 상담 툴바: 이전 상담(로그인 시) + 새 상담 한 줄 ───────── */
  .chat-toolbar {
    position: relative; z-index: 5;
    min-height: 40px; padding: 4px 24px;
    border-bottom: 1px solid #E5E5E5;
    display: flex;
    align-items: center;
    gap: 12px;
    flex-shrink: 0;
    background: var(--color-surface);
  }
  .history-bar { flex: 1; min-width: 0; display: flex; align-items: center; gap: 12px; }
  .history-bar[hidden] { display: none; }
  .history-bar-label { font-size: 12.5px; font-weight: 700; color: #555555; white-space: nowrap; flex-shrink: 0; }
  /* 칩은 최근 5건, 한 줄에 들어가는 만큼만 표시 (나머지는 '전체' 목록) */
  .history-chips {
    flex: 1; min-width: 0; height: 30px;
    display: flex; flex-wrap: wrap; gap: 6px;
    overflow: hidden; white-space: nowrap;
  }
  .history-chip {
    display: block; flex-shrink: 0;
    max-width: 240px; height: 30px; padding: 0 12px; line-height: 28px; white-space: nowrap;
    border: 1px solid var(--color-surface-2); border-radius: 4px;
    background: var(--color-surface-2); color: #555555;
    font-size: 12.5px; font-family: var(--font);
    cursor: pointer; overflow: hidden; text-overflow: ellipsis;
  }
  .history-chip:hover { background: #E5E5E5; color: var(--color-text-primary); }
  .btn-hist-all {
    display: inline-flex; align-items: center; gap: 4px; flex-shrink: 0;
    height: 30px; padding: 0 8px; border: 0; border-radius: 4px;
    background: transparent; color: #555555;
    font-size: 12.5px; font-family: var(--font); cursor: pointer;
  }
  .btn-hist-all[hidden] { display: none; }
  .btn-hist-all:hover { background: var(--color-surface-2); color: var(--color-text-primary); }
  .btn-hist-all svg { transition: transform .15s; }
  .btn-hist-all[aria-expanded="true"] svg { transform: rotate(180deg); }
  .hist-panel {
    position: absolute; top: calc(100% + 6px); right: 24px;
    width: 380px; max-width: calc(100% - 48px);
    background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 8px;
    box-shadow: 0 8px 24px rgba(0,0,0,0.12); overflow: hidden;
  }
  .hist-panel[hidden] { display: none; }
  .hist-panel-head {
    display: flex; align-items: center; justify-content: space-between;
    height: 44px; padding: 0 8px 0 16px; border-bottom: 1px solid #E5E5E5;
    font-size: 13.5px; font-weight: 600;
  }
  .hist-panel-count { font-size: 12px; font-weight: 400; color: var(--color-text-secondary); }
  .hist-panel-close {
    width: 36px; height: 36px; padding: 0; border: 0; background: transparent;
    color: var(--color-text-secondary); cursor: pointer;
    display: inline-flex; align-items: center; justify-content: center;
  }
  .hist-panel-list { max-height: 340px; overflow-y: auto; padding: 6px 0; }
  .hist-panel-item {
    display: flex; align-items: flex-start; justify-content: space-between; gap: 12px;
    width: 100%; min-height: 44px; padding: 10px 16px;
    border: 0; background: #FFFFFF; text-align: left;
    font-family: var(--font); cursor: pointer;
  }
  .hist-panel-item:hover { background: var(--color-surface); }
  .hist-panel-title { font-size: 13.5px; line-height: 1.5; color: var(--color-text-primary); word-break: keep-all; }
  .hist-panel-date  { flex-shrink: 0; font-size: 12px; line-height: 1.7; color: #999999; }

  .chat-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
  /* 새 상담 (기존 '대화 초기화' 동작) — 보조 행동: 파랑 테두리 / 대화 전에는 비활성 */
  .btn-reset-top {
    display: inline-flex; align-items: center; gap: 5px;
    height: 32px; padding: 0 12px;
    border: 1px solid var(--color-primary); border-radius: 4px;
    background: #FFFFFF; color: var(--color-primary);
    font-size: 13px; font-weight: 500; font-family: var(--font); cursor: pointer;
  }
  .btn-reset-top:not(:disabled):hover  { background: #EEF4FB; }
  .btn-reset-top:not(:disabled):active { background: #DCE8F5; }
  .btn-reset-top:disabled { border-color: var(--color-border); background: var(--color-surface); color: var(--color-text-disabled); cursor: not-allowed; }
  .btn-est-mob {
    display: none;
    align-items: center; gap: 4px;
    height: 32px; padding: 0 12px;
    background: #FFFFFF; border: 1px solid var(--color-border); border-radius: 4px;
    font-size: 13px; font-family: var(--font);
    color: #555555; cursor: pointer;
  }
  .btn-est-mob:hover { color: var(--color-primary); border-color: #A9C4E4; }

  /* ── 배경 오버레이 (항상 fixed — 그리드 흐름 제외) ──────── */
  .est-backdrop {
    display: none;
    position: fixed; inset: 0;
    background: rgba(0,0,0,.3); z-index: 199;
  }

  /* ── 모바일 반응형 ───────────────────────────────────────── */
  @media (max-width: 640px) {
    main { grid-template-columns: 1fr; }
    .est-panel {
      display: none;
      position: fixed; bottom: 0; left: 0; right: 0;
      max-height: 60vh;
      z-index: 200;
      border-left: none;
      border-top: 2px solid var(--color-primary);
      box-shadow: 0 -4px 20px rgba(0,0,0,.15);
    }
    .est-panel.open { display: flex; }
    .est-backdrop.open { display: block; }
    .btn-est-mob { display: flex; }
    .chat-toolbar { padding: 4px 16px; gap: 8px; }
    .history-bar { gap: 8px; }
    /* 좁은 화면: 칩 대신 '전체 N' 목록 버튼만 */
    .history-chips { display: none; }
    .btn-hist-all[hidden] { display: inline-flex; }
    .hist-panel { right: 16px; max-width: calc(100% - 32px); }
    .messages { padding: 20px 16px; }
    .input-area { padding: 12px 16px 16px; }
    /* 모바일: 화살표 대신 손가락으로 밀어서 이동, 터치 영역 확대 */
    .starters-arrows { display: none; }
    .hint-chip { min-height: 40px; }
    .input-row textarea { height: 48px; font-size: 16px; }
    .btn-send { width: 64px; height: 48px; }
  }

  /* 비교표: 태블릿(채팅 칸이 좁아짐)·모바일은 가로 스크롤 없이 한 상품을 세로로 — 수량·견적추가 버튼이 화면 안에 보이게 */
  @media (max-width: 900px) {
    .compare-table thead { display: none; }
    .compare-table table, .compare-table tbody { display: block; }
    .compare-table tr[data-pid] { display: grid; grid-template-columns: 60px 1fr auto; grid-template-areas: "img name name" "img price price" "img qty add"; gap: 6px 10px; padding: 10px 0; border-bottom: 1px solid var(--color-border); }
    .compare-table td { padding: 0; border: none; }
    .compare-table td:nth-child(1) { grid-area: img; }
    .compare-table td:nth-child(2) { grid-area: name; }
    .compare-table td:nth-child(3) { grid-area: price; }
    .compare-table td:nth-child(4) { grid-area: qty; }
    .compare-table td:nth-child(5) { grid-area: add; }
    .compare-table input.compare-qty { width: 100%; min-height: 44px; font-size: 16px; }
    .btn-compare-add { min-height: 44px; padding: 0 16px; }
  }

  /* ── 채팅 페이지 푸터 여백 제거 ──────────────────────────── */
  .site-footer { margin-top: 0; }
</style>

<div class="est-backdrop" id="est-backdrop" onclick="toggleEstPanel()"></div>

<!-- ── 왼쪽: 채팅 영역 (70%) ────────────────────────────────────── -->
<section class="chat-area" aria-label="AI 자재 상담">
  <!-- 상담 툴바: 이전 상담(로그인 시) + 새 상담을 한 줄로 -->
  <div class="chat-toolbar">
    <?php if ($_isLoggedIn ?? false): ?>
    <div class="history-bar" id="history-bar" hidden>
      <span class="history-bar-label">이전 상담</span>
      <div class="history-chips" id="history-chips" role="list" aria-label="이전 상담 목록"></div>
      <button type="button" class="btn-hist-all" id="btn-hist-all" aria-expanded="false" aria-controls="hist-panel" onclick="toggleHistPanel()" hidden>
        <span id="hist-all-label">전체</span>
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg>
      </button>
    </div>
    <div class="hist-panel" id="hist-panel" role="dialog" aria-label="이전 상담 전체" hidden>
      <div class="hist-panel-head">
        <span>이전 상담 <span class="hist-panel-count" id="hist-panel-count"></span></span>
        <button type="button" class="hist-panel-close" aria-label="닫기" onclick="toggleHistPanel(false)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg></button>
      </div>
      <div class="hist-panel-list" id="hist-panel-list"></div>
    </div>
    <?php endif; ?>
    <div class="chat-toolbar-actions">
      <button type="button" class="btn-est-mob" id="btn-est-mob" onclick="toggleEstPanel()">📋 견적서</button>
      <button type="button" class="btn-reset-top" id="btn-new-chat" onclick="resetChat()"<?= $_hasRestored ? '' : ' disabled' ?>>새 상담</button>
    </div>
  </div>

  <div class="messages" id="messages">
    <div class="msg assistant msg-greet">
      <div class="msg-avatar" aria-hidden="true">AI</div>
      <div class="msg-content">
        <div class="msg-bubble">안녕하세요! 비엠아이 AI 상담사입니다.
필요한 자재를 자유롭게 말씀해 주세요.
설치 장소, 용도, 하중 조건 등을 파악해 최적 상품을 추천해드립니다.</div>
      </div>
    </div>
    <?php foreach ($_restoredMsgs as $_ri => $_rm): // 이전 상담 복원 (chat-ai.js addMsg와 같은 구조) ?>
    <div class="msg <?= $_rm['role'] ?>" data-restore="<?= (int) $_ri ?>">
      <div class="msg-avatar"><?= $_rm['role'] === 'user' ? '나' : 'AI' ?></div>
      <div class="msg-content">
        <div class="msg-bubble"><?= e($_rm['text']) ?></div>
      </div>
    </div>
    <?php endforeach; ?>
  </div>

  <div class="input-area">
    <!-- 자주 찾는 상황: 누르면 입력창에 채워짐 (기존 동작 유지) -->
    <div class="starters" id="hints"<?= $_hasRestored ? ' style="display:none"' : '' ?>>
      <div class="starters-head">
        <span class="starters-label">자주 찾는 상황</span>
        <span class="starters-arrows">
          <button type="button" class="starters-arrow" id="starters-prev" aria-label="이전 상황 보기" onclick="slideStarters(-1)" disabled><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 18l-6-6 6-6"></path></svg></button>
          <button type="button" class="starters-arrow" id="starters-next" aria-label="다음 상황 보기" onclick="slideStarters(1)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 18l6-6-6-6"></path></svg></button>
        </span>
      </div>
      <div class="hint-chips" id="starters-track">
        <?php foreach ([
            '집 앞에 보도블록 깔면 뭐가 좋아요?', '차량 통행 구간 그레이팅', '주차장 배수 공사', '농수로 정비 공사', '인도·공원 보행 구간', '맨홀·집수정 교체',
            '마당 물빠짐 해결', '집 앞 진입로 포장', '화단·텃밭 테두리', '경사지 흙 무너짐 방지', '집 하수관 연결',
            '가게 앞 배수구 덮개', '창고 바닥 배수',
        ] as $_hint): ?>
        <button type="button" class="hint-chip" onclick="setInput(this.textContent)"><?= e($_hint) ?></button>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="input-row">
      <label for="inp" class="gh-sr-only">메시지</label>
      <textarea id="inp" placeholder="예: 차량이 다니는 곳에 그레이팅이 필요해요"
        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();send();}"></textarea>
      <button type="button" class="btn-send" id="btn-send" onclick="send()">전송</button>
    </div>
  </div>
</section>

<!-- ── 오른쪽: 견적 패널 (30%) ──────────────────────────────────── -->
<aside class="est-panel" aria-label="견적서">
  <div class="est-panel-header">
    <span>견적서</span>
    <button type="button" class="btn-est-clear" onclick="clearEstimate()">전체 비우기</button>
  </div>
  <div class="est-items" id="est-item-list">
    <div class="est-empty" id="est-empty">
      <span class="est-empty-icon" aria-hidden="true">
        <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="5" y="4" width="14" height="17" rx="2"></rect><path d="M9 4V3h6v1M8.5 10h7M8.5 14h7M8.5 18h4"></path></svg>
      </span>
      <p class="est-empty-title">담긴 품목이 없습니다.</p>
      <p class="est-empty-desc">AI 상담 후 상품을 추가해 보세요.</p>
    </div>
  </div>
  <div class="est-actions">
    <div class="est-total-row" id="est-total-row" style="display:none">
      <span class="est-total-label">합계 <small>(VAT 별도)</small></span>
      <span class="est-total-val" id="est-total">₩0</span>
    </div>
    <a href="/estimate.php" id="btn-est-req" class="btn-est-req disabled">견적 요청하기</a>
  </div>
</aside>

<script>
window.SID = '<?= e($_sid) ?>';
</script>
<script src="/assets/chat-ai.js?v=<?= time() ?>"></script>
<script>
<?php if ($_hasRestored): ?>
// 복원한 이전 상담: 답변 문단 정리(formatChatText)·상품 추천표·견적 카드를 실시간 상담과 같은 렌더러로 표시
(function () {
  const events = <?= json_encode(
      array_map(static fn($rm) => $rm['events'], $_restoredMsgs),
      JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
  ) ?>;
  document.querySelectorAll('#messages .msg.assistant[data-restore]').forEach(row => {
    const bubble  = row.querySelector('.msg-bubble');
    const content = row.querySelector('.msg-content');
    if (bubble) bubble.textContent = formatChatText(bubble.textContent);
    (events[parseInt(row.dataset.restore, 10)] || []).forEach(ev => processEvent(ev, content));
  });
  const m = document.getElementById('messages'); if (m) m.scrollTop = m.scrollHeight;
})();
<?php endif; ?>
function toggleEstPanel() {
  const panel   = document.querySelector('.est-panel');
  const backdrop = document.getElementById('est-backdrop');
  const btn     = document.getElementById('btn-est-mob');
  const isOpen  = panel.classList.toggle('open');
  if (backdrop) backdrop.classList.toggle('open', isOpen);
  if (btn) btn.innerHTML = isOpen ? '✕ 닫기' : '📋 견적서';
}

// 자주 찾는 상황: 좌우 버튼으로 약 60%씩 이동, 양 끝에서 버튼 비활성
(function () {
  const track = document.getElementById('starters-track');
  const prev  = document.getElementById('starters-prev');
  const next  = document.getElementById('starters-next');
  if (!track || !prev || !next) return;
  const update = () => {
    prev.disabled = track.scrollLeft <= 2;
    next.disabled = track.scrollLeft + track.clientWidth >= track.scrollWidth - 2;
  };
  window.slideStarters = (dir) => track.scrollBy({ left: dir * track.clientWidth * 0.6, behavior: 'smooth' });
  track.addEventListener('scroll', update, { passive: true });
  window.addEventListener('resize', update);
  update();
})();
</script>
<?php if ($_isLoggedIn ?? false): ?>
<script>
function toggleHistPanel(force) {
  const panel = document.getElementById('hist-panel');
  const btn   = document.getElementById('btn-hist-all');
  if (!panel || !btn) return;
  const open = typeof force === 'boolean' ? force : panel.hidden;
  panel.hidden = !open;
  btn.setAttribute('aria-expanded', open ? 'true' : 'false');
}
document.addEventListener('click', (e) => {
  const panel = document.getElementById('hist-panel');
  if (panel && !panel.hidden && !e.target.closest('#hist-panel, #btn-hist-all')) toggleHistPanel(false);
});
document.addEventListener('keydown', (e) => {
  const panel = document.getElementById('hist-panel');
  if (e.key === 'Escape' && panel && !panel.hidden) toggleHistPanel(false);
});

(async () => {
  try {
    const r = await fetch('/api/chat/history.php?limit=10');
    const d = await r.json();
    if (!d.ok || !d.sessions || !d.sessions.length) return;
    const bar   = document.getElementById('history-bar');
    const chips = document.getElementById('history-chips');
    const list  = document.getElementById('hist-panel-list');
    const RECENT = 5;
    const openSession = (s) => {
      if (s.estimate_id) location.href = '/estimate.php?ref=' + s.estimate_id;
      else if (s.session_key) location.href = '/chat.php?sid=' + encodeURIComponent(s.session_key);
    };
    // 날짜: 오늘 / 어제 / MM.DD
    const fmtDate = (v) => {
      const t = v ? new Date(String(v).replace(' ', 'T')) : null;
      if (!t || isNaN(t)) return '';
      const day = (x) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
      const diff = Math.round((day(new Date()) - day(t)) / 86400000);
      if (diff === 0) return '오늘';
      if (diff === 1) return '어제';
      return String(t.getMonth() + 1).padStart(2, '0') + '.' + String(t.getDate()).padStart(2, '0');
    };
    d.sessions.forEach((s, i) => {
      const title = s.summary || s.created_at?.slice(0,10) || '이전 상담';
      if (i < RECENT) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'history-chip';
        btn.setAttribute('role', 'listitem');
        btn.textContent = title;
        btn.title = title + (s.created_at ? ' · ' + s.created_at : '');
        btn.onclick = () => openSession(s);
        chips.appendChild(btn);
      }
      const item = document.createElement('button');
      item.type = 'button';
      item.className = 'hist-panel-item';
      const t = document.createElement('span');
      t.className = 'hist-panel-title';
      t.textContent = title;
      const dt = document.createElement('span');
      dt.className = 'hist-panel-date';
      dt.textContent = fmtDate(s.created_at);
      item.append(t, dt);
      item.onclick = () => openSession(s);
      list.appendChild(item);
    });
    // 최근 5건보다 많으면 '전체' 목록 버튼 표시 (좁은 화면은 CSS로 항상 표시)
    document.getElementById('hist-all-label').textContent = '전체 ' + d.sessions.length;
    document.getElementById('hist-panel-count').textContent = d.sessions.length + '건';
    if (d.sessions.length > RECENT) document.getElementById('btn-hist-all').hidden = false;
    bar.hidden = false;
  } catch (_) {}
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
