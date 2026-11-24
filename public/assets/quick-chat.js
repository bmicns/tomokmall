/**
 * 우측 하단 'AI 빠른 견적' 창 (app/layout.php render_quick_chat, 사용자 결정 2026-11-23 — 지금 AI 상담과 비교용)
 * - 질문 → /api/chat/quick.php (상담 엔진·도구·가격 계산은 AI 상담과 같고 답변만 짧게)
 * - 견적 표(quote_draft)는 서버 계산 그대로 표시. 대화는 이 탭 동안(sessionStorage) 페이지를 옮겨도 이어짐
 * - 견적서 사진 첨부(A10, 2026-11-24): 안내·동의 체크 후 사진(긴 변 1600px JPEG 로 줄임)을 보내면 서버가 품목만 읽어 답함.
 *   사진은 브라우저에도 남기지 않음(sessionStorage 에는 읽은 품목 글만)
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
  const attach = document.getElementById('qc-attach');   // 사진 첨부 스위치가 꺼져 있으면 없음
  const file   = document.getElementById('qc-file');
  const photo  = document.getElementById('qc-photo');
  const thumb  = document.getElementById('qc-photo-thumb');
  const agree  = document.getElementById('qc-photo-agree');
  const pSend  = document.getElementById('qc-photo-send');
  const PHOTO_MAX_BYTES = 10 * 1024 * 1024;
  const PHOTO_MAX_SIDE = 1600;
  const PHOTO_PREFIX = '[견적서 사진에서 읽은 품목]';
  let photoData = '';
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
      const intro = addBubble('ai', '현장·자재·규격·수량을 한 번에 적어 주세요. 알맞은 제품을 골라 견적에 바로 담고, 현장 지역(시·도)을 알려 주시면 운반비까지 계산해 드립니다.'
        + (attach ? '\n견적서·자재 목록 사진을 올려도 됩니다.' : ''));
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

  // 질문·사진 공통: 서버에 보내고 답 말풍선·견적 표를 붙임. userText = '나' 말풍선(사진은 읽은 품목으로 바뀜)
  async function request(payload, userText, typingText, timeoutMs, timeoutText) {
    busy = true; send.disabled = true; if (attach) attach.disabled = true;
    const mine = { role: 'user', text: userText };
    state.msgs.push(mine);
    save(); render();
    const typing = addBubble('ai', typingText);
    typing.querySelector('.qc-bubble').classList.add('qc-typing');
    body.scrollTop = body.scrollHeight;
    let reply;
    try {
      const ctrl = new AbortController();
      const tid = setTimeout(() => ctrl.abort(), timeoutMs);
      const r = await fetch('/api/chat/quick.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(Object.assign({ sid: state.sid }, payload)), signal: ctrl.signal,
      });
      clearTimeout(tid);
      const d = await r.json();
      if (d.sid) state.sid = d.sid;
      if (d.ok && d.asked) mine.text = '견적서 사진 — 읽은 품목\n' + String(d.asked).replace(PHOTO_PREFIX, '').trim();
      reply = d.ok ? { role: 'ai', text: d.text || '', events: d.events || [] }
                   : { role: 'ai', text: d.error || '오류가 발생했습니다. 다시 시도해 주세요.', error: true };
      if ((d.events || []).some(e => e.type === 'cart_update') && typeof refreshEstPanel === 'function') refreshEstPanel();
    } catch (e) {
      reply = { role: 'ai', text: e.name === 'AbortError' ? timeoutText : '오류가 발생했습니다. 다시 시도해 주세요.', error: true };
    }
    state.msgs.push(reply);
    save(); render();
    busy = false; send.disabled = false; if (attach) attach.disabled = false;
    input.focus();
  }

  function ask(text) {
    const msg = String(text || '').trim().slice(0, MAX_LEN);
    if (!msg || busy) return;
    input.value = ''; updateCount();
    return request({ action: 'ask', msg }, msg, '견적을 계산하고 있습니다…', 95000, '응답 시간이 초과되었습니다. 더 짧게 질문해 보세요.');
  }

  // ── 견적서 사진 첨부 ──────────────────────────────
  function photoReset() {
    photoData = '';
    if (!photo) return;
    photo.hidden = true;
    thumb.removeAttribute('src');
    agree.checked = false;
    pSend.disabled = true;
    file.value = '';
  }

  function photoNotice(text) {
    state.msgs.push({ role: 'ai', text, error: true });
    save(); render();
  }

  // 긴 변 1600px JPEG 로 줄임(흰 바탕 — png 투명 부분이 검게 되지 않게)
  function shrink(f) {
    return new Promise((resolve, reject) => {
      const url = URL.createObjectURL(f);
      const img = new Image();
      img.onload = () => {
        const k = Math.min(1, PHOTO_MAX_SIDE / Math.max(img.naturalWidth, img.naturalHeight));
        const c = document.createElement('canvas');
        c.width = Math.max(1, Math.round(img.naturalWidth * k));
        c.height = Math.max(1, Math.round(img.naturalHeight * k));
        const g = c.getContext('2d');
        g.fillStyle = '#fff'; g.fillRect(0, 0, c.width, c.height);
        g.drawImage(img, 0, 0, c.width, c.height);
        URL.revokeObjectURL(url);
        resolve(c.toDataURL('image/jpeg', 0.85));
      };
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('decode')); };
      img.src = url;
    });
  }

  async function photoPicked() {
    const f = file.files && file.files[0];
    if (!f) return;
    if (!/^image\/(jpeg|png)$/.test(f.type)) { photoReset(); photoNotice('jpg·png 사진만 올릴 수 있습니다.'); return; }
    if (f.size > PHOTO_MAX_BYTES) { photoReset(); photoNotice('사진은 10MB 이하로 올려 주세요.'); return; }
    try {
      photoData = await shrink(f);
    } catch (e) {
      photoReset(); photoNotice('사진을 열 수 없습니다. 다른 사진으로 올려 주세요.'); return;
    }
    thumb.src = photoData;
    agree.checked = false;
    pSend.disabled = true;
    photo.hidden = false;
    agree.focus();
  }

  function photoSend() {
    if (!photoData || !agree.checked || busy) return;
    const image = photoData;
    photoReset();
    request({ action: 'photo', image, consent: true }, '견적서 사진을 보냈습니다', '사진에서 품목을 읽고 견적을 계산하고 있습니다…', 150000, '응답 시간이 초과되었습니다. 품목이 적게 보이게 나눠 찍어 다시 올려 주세요.');
  }

  async function newChat() {
    if (busy) return;
    state = { sid: '', open: true, msgs: [] };
    photoReset();
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
  if (attach && file && photo) {
    attach.addEventListener('click', () => { if (!busy) file.click(); });
    file.addEventListener('change', photoPicked);
    agree.addEventListener('change', () => { pSend.disabled = !agree.checked; });
    pSend.addEventListener('click', photoSend);
    document.getElementById('qc-photo-cancel').addEventListener('click', () => { photoReset(); attach.focus(); });
  }

  if (state.open) setOpen(true);
})();
