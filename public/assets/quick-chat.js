/**
 * 우측 하단 'AI 빠른 견적' 창 (app/layout.php render_quick_chat, 사용자 결정 2026-11-23 — 지금 AI 상담과 비교용)
 * - 질문 → /api/chat/quick.php (상담 엔진·도구·가격 계산은 AI 상담과 같고 답변만 짧게)
 * - 견적 표(quote_draft)는 서버 계산 그대로 표시. 대화는 이 탭 동안(sessionStorage) 페이지를 옮겨도 이어짐
 */
(function () {
  'use strict';
  const STORE = 'tomok.quickChat.v1';
  const MAX_LEN = 200;
  const EXAMPLES = [
    '아파트 주차장 배수로 그레이팅 폭 300, 20m, 차가 다녀요',
    '마당 경계석 30m, 차는 안 다녀요. 경기도예요',
  ];

  const fab   = document.getElementById('qc-fab');
  const panel = document.getElementById('qc-panel');
  const body  = document.getElementById('qc-body');
  const form  = document.getElementById('qc-form');
  const input = document.getElementById('qc-input');
  const count = document.getElementById('qc-count');
  const send  = document.getElementById('qc-send');
  if (!fab || !panel) return;

  let state = load();
  let busy = false;

  function load() {
    try {
      const s = JSON.parse(sessionStorage.getItem(STORE) || 'null');
      if (s && Array.isArray(s.msgs)) return s;
    } catch (e) {}
    return { sid: '', open: false, msgs: [] };
  }
  function save() {
    try { sessionStorage.setItem(STORE, JSON.stringify({ sid: state.sid, open: state.open, msgs: state.msgs.slice(-30) })); } catch (e) {}
  }

  function esc(str) {
    return String(str ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  }
  const won = n => new Intl.NumberFormat('ko-KR').format(parseInt(n, 10) || 0) + '원';
  // 말풍선은 글자 그대로 — 마크다운 굵게·제목 기호만 정리
  const clean = t => String(t || '').replace(/\*\*(.+?)\*\*/g, '$1').replace(/^#+\s*/gm, '').trim();

  function setOpen(open) {
    state.open = open;
    panel.hidden = !open;
    fab.setAttribute('aria-expanded', open ? 'true' : 'false');
    save();
    if (open) {
      render();
      setTimeout(() => input.focus(), 0);
    } else {
      fab.focus();
    }
  }

  function render() {
    body.textContent = '';
    if (!state.msgs.length) {
      const intro = addBubble('ai', '현장·자재·규격·수량을 한 번에 적어 주세요. 알맞은 제품을 골라 견적에 바로 담고, 현장 지역(시·도)을 알려 주시면 운반비까지 계산해 드립니다.');
      const ex = document.createElement('div');
      ex.className = 'qc-examples';
      EXAMPLES.forEach(t => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'qc-example'; b.textContent = t;
        b.addEventListener('click', () => ask(t));
        ex.appendChild(b);
      });
      intro.appendChild(ex);
    }
    state.msgs.forEach(m => {
      const wrap = addBubble(m.role, m.text, m.error);
      (m.events || []).forEach(ev => renderEvent(ev, wrap));
    });
    body.scrollTop = body.scrollHeight;
  }

  function addBubble(role, text, isError) {
    const wrap = document.createElement('div');
    wrap.className = 'qc-msg ' + (role === 'user' ? 'user' : 'ai');
    const who = document.createElement('div');
    who.className = 'qc-who';
    who.textContent = role === 'user' ? '나' : 'AI 답변';
    const bubble = document.createElement('div');
    bubble.className = 'qc-bubble' + (isError ? ' is-error' : '');
    bubble.textContent = role === 'user' ? text : clean(text);
    wrap.appendChild(who);
    wrap.appendChild(bubble);
    body.appendChild(wrap);
    return wrap;
  }

  function renderEvent(ev, wrap) {
    if (ev.type === 'quote_draft') renderQuote(ev, wrap);
    if (ev.type === 'product_cards') renderPicks(ev.products || [], wrap);
  }

  // 담지 않고 추천만 한 경우: 제품 이름 링크(최대 3개)
  function renderPicks(products, wrap) {
    if (!products.length || wrap.querySelector('.qc-quote')) return;
    const box = document.createElement('div');
    box.className = 'qc-picks';
    box.innerHTML = '추천 제품: ' + products.slice(0, 3).map(p =>
      `<a href="/product.php?id=${parseInt(p.id, 10)}" target="_blank" rel="noopener">${esc(p.name)}${p.spec ? ' ' + esc(p.spec) : ''}</a>`).join(', ');
    wrap.appendChild(box);
  }

  // 견적 표 — 품목(상품명·단가·수량) + 운반비·부가세·총합계 (app/quote_draft.php 값 그대로)
  function renderQuote(q, wrap) {
    const items = q.items || [];
    if (!items.length) return;
    const f = q.freight || {};
    const old = wrap.querySelector('.qc-picks');
    if (old) old.remove();
    const rows = items.map(it => {
      const unit = parseInt(it.unit_price, 10) || 0;
      return `<div class="qc-quote-item">
          <div class="qc-quote-name">${esc(it.name)}${it.spec ? ' ' + esc(it.spec) : ''}</div>
          <div class="qc-quote-line"><span>단가 ${unit > 0 ? won(unit) : '가격 문의'} · 수량 ${parseInt(it.quantity, 10) || 0}</span><b>${unit > 0 ? won(it.subtotal) : '문의'}</b></div>
          ${it.product_id ? `<a class="qc-quote-link" href="/product.php?id=${parseInt(it.product_id, 10)}" target="_blank" rel="noopener">상품보기</a>` : ''}
        </div>`;
    }).join('');
    const goods = parseInt(q.goods_total, 10) || 0;
    const fOk = f.status === 'ok';
    const box = document.createElement('div');
    box.className = 'qc-quote';
    box.innerHTML = `${rows}
      <div class="qc-quote-sum">
        <div class="qc-quote-row"><span>상품 합계</span><span>${goods > 0 ? won(goods) + (q.has_inquiry ? ' + 문의' : '') : '담당자 문의'}</span></div>
        <div class="qc-quote-row"><span>운반비${q.region ? ' (' + esc(q.region) + ')' : ''}</span><span class="${fOk ? '' : 'is-pending'}">${esc(f.text || '')}</span></div>
        <div class="qc-quote-row"><span>부가세</span><span>${q.vat === null || q.vat === undefined ? '-' : won(q.vat)}</span></div>
        <div class="qc-quote-row qc-quote-total"><span>총합계</span>
          <span class="qc-quote-val">${q.final === null || q.final === undefined ? '담당자 문의' : won(q.final)}${q.final_suffix ? `<small>${esc(q.final_suffix.replace(/^\s*\+\s*/, '+ '))}</small>` : ''}</span></div>
      </div>
      <a class="qc-quote-btn" href="/estimate.php">견적 요청하기</a>`;
    wrap.appendChild(box);
  }

  async function ask(text) {
    const msg = String(text || '').trim().slice(0, MAX_LEN);
    if (!msg || busy) return;
    busy = true; send.disabled = true;
    input.value = ''; updateCount();
    state.msgs.push({ role: 'user', text: msg });
    save(); render();
    const typing = addBubble('ai', '견적을 계산하고 있습니다…');
    typing.querySelector('.qc-bubble').classList.add('qc-typing');
    body.scrollTop = body.scrollHeight;
    let reply;
    try {
      const ctrl = new AbortController();
      const tid = setTimeout(() => ctrl.abort(), 95000);
      const r = await fetch('/api/chat/quick.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'ask', sid: state.sid, msg }), signal: ctrl.signal,
      });
      clearTimeout(tid);
      const d = await r.json();
      if (d.sid) state.sid = d.sid;
      reply = d.ok ? { role: 'ai', text: d.text || '', events: d.events || [] }
                   : { role: 'ai', text: d.error || '오류가 발생했습니다. 다시 시도해 주세요.', error: true };
      if ((d.events || []).some(e => e.type === 'cart_update') && typeof refreshEstPanel === 'function') refreshEstPanel();
    } catch (e) {
      reply = { role: 'ai', text: e.name === 'AbortError' ? '응답 시간이 초과되었습니다. 더 짧게 질문해 보세요.' : '오류가 발생했습니다. 다시 시도해 주세요.', error: true };
    }
    state.msgs.push(reply);
    save(); render();
    busy = false; send.disabled = false;
    input.focus();
  }

  async function newChat() {
    if (busy) return;
    state = { sid: '', open: true, msgs: [] };
    try {
      const r = await fetch('/api/chat/quick.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'new' }) });
      const d = await r.json();
      if (d.ok) state.sid = d.sid;
    } catch (e) {}
    save(); render(); input.focus();
  }

  function updateCount() { count.textContent = input.value.length + ' / ' + MAX_LEN; }

  fab.addEventListener('click', () => setOpen(true));
  document.getElementById('qc-close').addEventListener('click', () => setOpen(false));
  document.getElementById('qc-new').addEventListener('click', newChat);
  panel.addEventListener('keydown', e => { if (e.key === 'Escape') setOpen(false); });
  input.addEventListener('input', updateCount);
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(input.value); }
  });
  form.addEventListener('submit', e => { e.preventDefault(); ask(input.value); });

  if (state.open) setOpen(true);
})();
