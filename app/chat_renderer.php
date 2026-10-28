<?php
/** 채팅 UI HTML 렌더링 전용 — 비즈니스 로직 없음
 *  호출자: public/chat.php (GET 일반 요청)
 *  의존: $sessionKey (string), $_isLoggedIn (bool) — 호출자가 주입
 */
$_sid     = $sessionKey ?? '';
require __DIR__ . '/layout.php';
render_header('AI 자재 상담', 'chat');
?>
<style>
  /* ── 2컬럼 레이아웃 ──────────────────────────────────────── */
  main {
    padding: 0;
    max-width: none;
    height: calc(100dvh - var(--header-h));
    min-height: unset;
    display: grid;
    grid-template-columns: 3fr 7fr;
    gap: 0;
    overflow: hidden;
  }

  /* ── 왼쪽: 견적 패널 ─────────────────────────────────────── */
  .est-panel {
    border-right: 1px solid var(--color-border);
    background: #FAFAFA;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .est-panel-header {
    padding: 10px 16px;
    border-bottom: 1px solid var(--color-border);
    font-size: 12px;
    font-weight: 600;
    color: var(--color-text-primary);
    background: var(--color-surface);
    flex-shrink: 0;
    display: flex;
    align-items: center;
    justify-content: space-between;
  }
  .btn-est-clear {
    background: none; border: none;
    font-size: 11px; color: var(--color-text-secondary);
    cursor: pointer; font-family: var(--font); padding: 0;
  }
  .btn-est-clear:hover { color: #fff; background: #E53E3E; padding: 2px 6px; }
  .est-items {
    flex: 1;
    overflow-y: auto;
    padding: 8px 12px;
  }
  .est-items::-webkit-scrollbar { width: 3px; }
  .est-items::-webkit-scrollbar-thumb { background: var(--color-border); }
  .est-empty {
    color: var(--color-text-secondary);
    font-size: 12px;
    text-align: center;
    padding: 32px 12px;
    line-height: 1.7;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 10px;
  }
  .est-empty-icon { font-size: 28px; color: #D0D0D0; line-height: 1; }
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

  .est-total-row {
    padding: 10px 12px;
    border-top: 2px solid var(--color-border);
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 13px;
    font-weight: 600;
    flex-shrink: 0;
    background: var(--color-surface);
  }
  .est-total-label { color: var(--color-text-secondary); font-size: 11px; }
  .est-total-val { color: var(--color-primary); }

  .est-actions { padding: 10px 12px; flex-shrink: 0; border-top: 1px solid var(--color-border); }
  .btn-est-req {
    display: block; width: 100%;
    background: var(--color-primary); color: #fff;
    border: none; padding: 11px;
    font-size: 13px; font-family: var(--font); font-weight: 500;
    cursor: pointer; text-align: center; text-decoration: none;
    transition: background .15s;
  }
  .btn-est-req:hover { background: #3A6BBF; }
  .btn-est-req.disabled { background: #D0D0D0; color: #999; pointer-events: none; }

  /* ── 오른쪽: 채팅 영역 ───────────────────────────────────── */
  .chat-area {
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }
  .messages {
    flex: 1;
    overflow-y: auto;
    padding: 20px;
    display: flex;
    flex-direction: column;
    gap: 16px;
  }
  .msg-spacer { flex: 1; }
  .messages::-webkit-scrollbar { width: 4px; }
  .messages::-webkit-scrollbar-track { background: transparent; }
  .messages::-webkit-scrollbar-thumb { background: var(--color-border); }

  .msg { display: flex; gap: 10px; max-width: 680px; }
  .msg.user { align-self: flex-end; flex-direction: row-reverse; }
  .msg-avatar {
    width: 28px; height: 28px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    display: flex; align-items: center; justify-content: center;
    font-size: 11px; color: var(--color-text-secondary);
    flex-shrink: 0;
  }
  .msg.user .msg-avatar { background: var(--color-primary); color: #fff; }
  .msg-content { display: flex; flex-direction: column; gap: 8px; flex: 1; min-width: 0; }
  .msg-bubble {
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    padding: 10px 14px;
    font-size: 15px; line-height: 1.6;
    white-space: pre-wrap; word-break: break-word;
    color: #111;
  }
  .msg.user .msg-bubble { background: #EEEEEE; }

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
  .compare-qtybasis { font-size: 11px; color: var(--color-primary); font-weight: 600; margin-top: 3px; }
  .compare-price { font-size: 13px; font-weight: 600; color: var(--color-primary); white-space: nowrap; }
  .compare-unit { font-size: 11px; font-weight: 400; color: var(--color-text-secondary); }
  .compare-cost { font-size: 11px; font-weight: 600; color: var(--color-text-primary); margin-top: 3px; }
  .compare-summary { font-size: 12px; line-height: 1.5; padding: 8px 10px; margin-bottom: 6px; background: var(--color-surface); border: 1px solid var(--color-border); }
  .compare-summary b { color: var(--color-primary); }
  .compare-summary span { display: block; font-size: 11px; color: var(--color-text-secondary); }
  .legal-sources { margin-top: 10px; padding: 10px 12px; border: 1px solid var(--color-border); background: var(--color-surface); font-size: 12px; line-height: 1.55; }
  .legal-sources-title { font-weight: 700; color: var(--color-text-primary); margin-bottom: 6px; }
  .legal-sources ul { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
  .legal-source-head { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; }
  .legal-badge { padding: 1px 7px; border: 1px solid #F0C48A; background: #FFF4E5; color: #7A3E00; font-size: 11px; font-weight: 700; }
  .legal-source-meta, .legal-source-cond { color: var(--color-text-secondary); word-break: keep-all; }
  .legal-source-meta a { color: var(--color-primary); text-decoration: underline; }
  .compare-qty {
    width: 60px; padding: 4px; border: 1px solid var(--color-border);
    font-size: 12px; text-align: center; font-family: var(--font); background: #fff;
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
    border-top: 1px solid var(--color-border);
    background: var(--color-surface);
    padding: 12px 16px; flex-shrink: 0;
  }
  .hint-chips { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
  .hint-chip {
    border: 1px solid #B8CCF0; padding: 5px 12px;
    font-size: 12px; color: #3A6BBF;
    cursor: pointer; background: #EEF4FF; font-family: var(--font);
  }
  .hint-chip:hover,
  .hint-chip:active,
  .hint-chip:focus-visible {
    background: var(--color-primary);
    border-color: var(--color-primary);
    color: #fff;
  }
  .hint-chip:focus { outline: none; }
  .input-row { display: flex; gap: 8px; }
  .input-row textarea {
    flex: 1; background: #EEEEEE;
    border: 1px solid var(--color-border);
    padding: 10px 12px; font-size: 13px; font-family: var(--font);
    color: var(--color-text-primary); resize: none; height: 44px;
    line-height: 1.5; outline: none; transition: border-color .15s;
  }
  .input-row textarea:focus { border-color: var(--color-primary); }
  .input-row textarea::placeholder { color: #BBBBBB; }
  .btn-send {
    background: var(--color-primary); color: #fff; border: none;
    padding: 0 18px; font-size: 13px; font-family: var(--font);
    font-weight: 500; cursor: pointer; flex-shrink: 0;
  }
  .btn-send:disabled { background: #EEEEEE; cursor: not-allowed; }
  .btn-reset {
    background: #F5F5F5; color: var(--color-text-secondary); border: none;
    padding: 0 12px; font-size: 11px; font-family: var(--font);
    cursor: pointer; flex-shrink: 0;
  }
  .btn-reset:hover { color: var(--color-text-primary); }

  /* ── 이전 상담 이력 ──────────────────────────────────────── */
  .history-bar { border-bottom:1px solid var(--color-border);background:#EEEEEE;padding:6px 12px;display:flex;align-items:center;gap:8px;flex-shrink:0;overflow:hidden; }
  .history-bar-label { font-size:10px;color:var(--color-text-secondary);white-space:nowrap;flex-shrink:0; }
  .history-chips { display:flex;gap:6px;overflow-x:auto;flex:1; }
  .history-chips::-webkit-scrollbar { height:0; }
  .history-chip { border:1px solid var(--color-border);padding:3px 8px;font-size:10px;color:var(--color-text-secondary);cursor:pointer;background:transparent;font-family:var(--font);white-space:nowrap;flex-shrink:0; }
  .history-chip:hover { border-color:var(--color-primary);color:var(--color-text-primary); }

  /* ── 채팅 상단 헤더 ──────────────────────────────────────── */
  .chat-header {
    padding: 8px 16px;
    border-bottom: 1px solid var(--color-border);
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: 8px;
    flex-shrink: 0;
    background: var(--color-surface);
  }
  .btn-reset-top {
    background: none; border: 1px solid var(--color-border);
    padding: 4px 10px; font-size: 11px; font-family: var(--font);
    color: var(--color-text-secondary); cursor: pointer;
  }
  .btn-reset-top:hover { color: #fff; background: var(--color-primary); border-color: var(--color-primary); }
  .btn-est-mob {
    display: none;
    align-items: center; gap: 4px;
    background: none; border: 1px solid var(--color-border);
    padding: 4px 10px; font-size: 11px; font-family: var(--font);
    color: var(--color-text-secondary); cursor: pointer;
  }
  .btn-est-mob:hover { color: #3A6BBF; border-color: #B8CCF0; }

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
      border-right: none;
      border-top: 2px solid var(--color-primary);
      box-shadow: 0 -4px 20px rgba(0,0,0,.15);
    }
    .est-panel.open { display: flex; }
    .est-backdrop.open { display: block; }
    .btn-est-mob { display: flex; }
    .input-row textarea { height: 48px; font-size: 16px; }
    .hint-chips { overflow-x: auto; flex-wrap: nowrap; padding-bottom: 2px; }
    .hint-chip { white-space: nowrap; flex-shrink: 0; }
  }

  /* ── 채팅 페이지 푸터 여백 제거 ──────────────────────────── */
  .site-footer { margin-top: 0; }
</style>

<div class="est-backdrop" id="est-backdrop" onclick="toggleEstPanel()"></div>

<!-- ── 왼쪽: 견적 패널 ─────────────────────────────────────────── -->
<div class="est-panel">
  <div class="est-panel-header">
    <span>견적서</span>
    <button class="btn-est-clear" onclick="clearEstimate()">전체 초기화</button>
  </div>
  <div class="est-items" id="est-item-list">
    <div class="est-empty" id="est-empty">
      <span class="est-empty-icon">📋</span>
      <span>담긴 품목이 없습니다.<br>AI 상담 후 상품을 추가해 보세요.</span>
    </div>
  </div>
  <div class="est-total-row" id="est-total-row" style="display:none">
    <span class="est-total-label">합계 (VAT 별도)</span>
    <span class="est-total-val" id="est-total">₩0</span>
  </div>
  <div class="est-actions">
    <a href="/estimate.php" id="btn-est-req" class="btn-est-req disabled">견적요청하기 →</a>
  </div>
</div>

<!-- ── 오른쪽: 채팅 영역 ────────────────────────────────────────── -->
<div class="chat-area">
  <?php if ($_isLoggedIn ?? false): ?>
  <div class="history-bar" id="history-bar" style="display:none">
    <span class="history-bar-label">이전 상담</span>
    <div class="history-chips" id="history-chips"></div>
  </div>
  <?php endif; ?>

  <div class="chat-header">
    <button class="btn-est-mob" id="btn-est-mob" onclick="toggleEstPanel()">📋 견적서</button>
    <button class="btn-reset-top" onclick="resetChat()">대화 초기화</button>
  </div>

  <div class="messages" id="messages">
    <div class="msg-spacer"></div>
    <div class="msg assistant">
      <div class="msg-avatar">AI</div>
      <div class="msg-content">
        <div class="msg-bubble">안녕하세요! 비엠아이 AI 상담사입니다.
필요한 자재를 자유롭게 말씀해 주세요.
설치 장소, 용도, 하중 조건 등을 파악해 최적 상품을 추천해드립니다.</div>
      </div>
    </div>
  </div>

  <div class="input-area">
    <div class="hint-chips" id="hints">
      <button class="hint-chip" onclick="setInput(this.textContent)">집 앞에 보도블록 깔면 뭐가 좋아요?</button>
      <button class="hint-chip" onclick="setInput(this.textContent)">차량 통행 구간 그레이팅</button>
      <button class="hint-chip" onclick="setInput(this.textContent)">주차장 배수 공사</button>
      <button class="hint-chip" onclick="setInput(this.textContent)">농수로 정비 공사</button>
      <button class="hint-chip" onclick="setInput(this.textContent)">인도·공원 보행 구간</button>
      <button class="hint-chip" onclick="setInput(this.textContent)">맨홀·집수정 교체</button>
    </div>
    <div class="input-row">
      <textarea id="inp" placeholder="예: 차량이 다니는 곳에 그레이팅이 필요해요"
        onkeydown="if(event.key==='Enter'&&!event.shiftKey){event.preventDefault();send();}"></textarea>
      <button class="btn-send" id="btn-send" onclick="send()">전송</button>
    </div>
  </div>
</div>

<script>
window.SID = '<?= e($_sid) ?>';
</script>
<script src="/assets/chat-ai.js?v=<?= time() ?>"></script>
<script>
function toggleEstPanel() {
  const panel   = document.querySelector('.est-panel');
  const backdrop = document.getElementById('est-backdrop');
  const btn     = document.getElementById('btn-est-mob');
  const isOpen  = panel.classList.toggle('open');
  if (backdrop) backdrop.classList.toggle('open', isOpen);
  if (btn) btn.innerHTML = isOpen ? '✕ 닫기' : '📋 견적서';
}
</script>
<?php if ($_isLoggedIn ?? false): ?>
<script>
(async () => {
  try {
    const r = await fetch('/api/chat/history.php?limit=8');
    const d = await r.json();
    if (!d.ok || !d.sessions || !d.sessions.length) return;
    const bar = document.getElementById('history-bar');
    const chips = document.getElementById('history-chips');
    d.sessions.forEach(s => {
      const btn = document.createElement('button');
      btn.className = 'history-chip';
      btn.textContent = s.summary || s.created_at?.slice(0,10) || '이전 상담';
      btn.title = s.created_at || '';
      btn.onclick = () => {
        if (s.estimate_id) location.href = '/estimate.php?ref=' + s.estimate_id;
        else if (s.session_key) location.href = '/chat.php?sid=' + encodeURIComponent(s.session_key);
      };
      chips.appendChild(btn);
    });
    bar.style.display = 'flex';
  } catch (_) {}
})();
</script>
<?php endif; ?>
<?php render_footer(); ?>
