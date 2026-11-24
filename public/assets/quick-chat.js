/**
 * 우측 하단 'AI 빠른 견적' 창 (app/layout.php render_quick_chat, 사용자 결정 2026-11-23 — 지금 AI 상담과 비교용)
 * - 질문 → /api/chat/quick.php (상담 엔진·도구·가격 계산은 AI 상담과 같고 답변만 짧게)
 * - 견적 표(quote_draft)는 서버 계산 그대로 표시. 대화는 이 탭 동안(sessionStorage) 페이지를 옮겨도 이어짐, 이전 대화내용은 로그인하면 서버(다른 탭·기기)
 * - 견적서 사진 첨부(A10, 2026-11-24): 안내·동의 체크 후 사진(긴 변 1600px JPEG 로 줄임)을 보내면 서버가 품목만 읽어 답함.
 *   사진은 브라우저에도 남기지 않음(sessionStorage 에는 읽은 품목 글만)
 * - 케이토목 1분 자동견적 그대로(A11, 2026-11-24): 품목마다 가격·수량·합계·상품 사진·[상품목록][상품보기][도면자료],
 *   부가세·총합계, 오류 안내 + 고객상담 전화. 사용방법, 이전 대화내용(새 대화 전 대화 5개, 이 탭 동안)
 * - 담는 곳 = 창 안 목록(사이트 견적서 아님, 수량 미정 0 허용). '견적서 만들어줘'·[견적 요청하기] 때 견적서로 옮김
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
  const helpBtn = document.getElementById('qc-help-btn');
  const help    = document.getElementById('qc-help');
  const prevBtn = document.getElementById('qc-prev-btn');
  const prevBox = document.getElementById('qc-prev');
  const PHONE = panel ? (panel.dataset.phone || '') : '';
  const PREV_MAX = 5;
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
      if (s && Array.isArray(s.msgs)) { if (!Array.isArray(s.prev)) s.prev = []; return s; }
    } catch (e) {}
    return { sid: '', open: false, msgs: [], prev: [] };
  }
  function save() {
    try {
      sessionStorage.setItem(STORE, JSON.stringify({ sid: state.sid, open: state.open, msgs: state.msgs.slice(-30),
        prev: (state.prev || []).slice(0, PREV_MAX).map(c => ({ sid: c.sid, at: c.at, msgs: c.msgs.slice(-30) })) }));
    } catch (e) {}
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
        + (attach ? '\n견적서·자재 목록 사진을 올려도 됩니다.' : '')
        + '\n정리된 목록으로 견적서를 만들려면 \'견적서 만들어줘\'라고 입력해 주세요.');
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
      if (m.role !== 'user' && !m.error) addNote(wrap);
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
    if (ev.type === 'estimate_moved') renderMoved(ev, wrap);
  }

  // 규격 묶음 글('규격: 300*1000*50 | 중량: 18kg | 단위: 개')에서 규격 값만
  const specOf = s => { const t = String(s || ''); const m = /규격:\s*([^|]+)/.exec(t); return (m ? m[1] : t.split('|')[0]).trim(); };

  // 담지 않고 추천만 한 경우: 제품 이름 링크(최대 3개)
  function renderPicks(products, wrap) {
    if (!products.length || wrap.querySelector('.qc-quote')) return;
    const box = document.createElement('div');
    box.className = 'qc-picks';
    box.innerHTML = '추천 제품: ' + products.slice(0, 3).map(p =>
      `<a href="/product.php?id=${parseInt(p.id, 10)}" target="_blank" rel="noopener">${esc(p.name)}${specOf(p.spec) ? ' ' + esc(specOf(p.spec)) : ''}</a>`).join(', ');
    wrap.appendChild(box);
  }

  // 서버가 준 이미지·자료 주소만(같은 사이트 경로 '/…' 또는 http(s))
  const safeUrl = u => (/^(https?:\/\/|\/(?!\/))/i.test(String(u || '')) ? String(u) : '');

  // 견적 표 — 케이토목 1분 자동견적 구성(A11): 품목마다 상품명·가격·수량·합계·사진·[상품목록][상품보기][도면자료],
  // 아래에 상품 합계·운반비·부가세·총합계 (app/quote_draft.php 값 그대로, 운반비는 예상 운임 — 0원으로 보이지 않음)
  function renderQuote(q, wrap) {
    const items = q.items || [];
    if (!items.length) return;
    const f = q.freight || {};
    const old = wrap.querySelector('.qc-picks');
    if (old) old.remove();
    const rows = items.map(it => {
      const unit = parseInt(it.unit_price, 10) || 0;
      const pid = parseInt(it.product_id, 10) || 0;
      const cat = parseInt(it.category_id, 10) || 0;
      const img = safeUrl(it.image);
      const spec = safeUrl(it.spec_image);
      const btns = [
        cat ? `<a class="qc-q-btn" href="/products.php?category=${cat}" target="_blank" rel="noopener">상품목록</a>` : '',
        pid ? `<a class="qc-q-btn" href="/product.php?id=${pid}" target="_blank" rel="noopener">상품보기</a>` : '',
        spec ? `<a class="qc-q-btn is-doc" href="${esc(spec)}" target="_blank" rel="noopener">도면자료</a>` : '',
      ].join('');
      return `<div class="qc-quote-item">
          <dl class="qc-q-list">
            <div><dt>상품명</dt><dd>${esc(it.name)}${it.spec ? ' ' + esc(it.spec) : ''}</dd></div>
            <div><dt>가격</dt><dd>${unit > 0 ? won(unit) : '가격 문의'}</dd></div>
            <div><dt>수량</dt><dd>${parseInt(it.quantity, 10) || 0}${(parseInt(it.quantity, 10) || 0) === 0 ? ' (미정)' : ''}</dd></div>
            <div><dt>합계</dt><dd>${unit > 0 ? won(it.subtotal) : '문의'}</dd></div>
          </dl>
          ${img ? `<div class="qc-q-img"><img src="${esc(img)}"${safeUrl(it.image_small) && it.image_small !== it.image ? ` data-small="${esc(safeUrl(it.image_small))}"` : ''} alt="${esc(it.name)} 사진" loading="lazy"></div>` : ''}
          ${btns ? `<div class="qc-q-btns">${btns}</div>` : ''}
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
      <button type="button" class="qc-quote-btn">견적 요청하기</button>`;
    box.querySelector('.qc-quote-btn').addEventListener('click', toEstimate);
    // 큰 사진(450×450)이 없으면 원래 작은 사진으로
    box.querySelectorAll('img[data-small]').forEach(im => {
      const back = () => { const u = im.dataset.small; im.removeAttribute('data-small'); if (u) im.src = u; };
      im.addEventListener('error', back, { once: true });
      if (im.complete && im.naturalWidth === 0 && im.currentSrc) back();
    });
    wrap.appendChild(box);
  }

  // '견적서 만들어줘' 결과 — 사이트 견적서로 옮긴 뒤 견적 요청 화면 바로가기
  function renderMoved(ev, wrap) {
    if (wrap.querySelector('.qc-quote')) return;   // 견적 표의 [견적 요청하기]가 같은 일
    const a = document.createElement('a');
    a.className = 'qc-quote-btn qc-moved-btn';
    a.href = safeUrl(ev.url) || '/estimate.php';
    a.textContent = '견적서 보기 · 견적 요청하기';
    wrap.appendChild(a);
  }

  // [견적 요청하기]: 창 안 목록을 사이트 견적서로 옮기고(수량 미정 품목 제외) 견적 요청 화면으로
  async function toEstimate() {
    if (busy) return;
    busy = true;
    let d = null;
    try {
      const r = await fetch('/api/chat/quick.php', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'to_estimate', sid: state.sid }) });
      d = await r.json();
    } catch (e) {}
    busy = false;
    if (d && d.ok) {
      if ((d.skipped || []).length) {
        state.msgs.push({ role: 'ai', text: '수량이 정해지지 않아 견적서로 옮기지 않은 품목: ' + d.skipped.join(', '), error: true });
        save();
      }
      location.href = safeUrl(d.url) || '/estimate.php';
      return;
    }
    state.msgs.push({ role: 'ai', text: (d && d.error) || '견적서로 옮기지 못했습니다. 다시 시도해 주세요.', error: true });
    save(); render();
  }

  // 답변 끝 안내(케이토목처럼 답마다) — 견적 표가 있으면 운반비·전화 상담 안내까지
  function addNote(wrap) {
    const n = document.createElement('p');
    n.className = 'qc-note';
    const hasQuote = !!wrap.querySelector('.qc-quote');
    n.textContent = 'AI 답변에는 오류가 있을 수 있습니다.'
      + (hasQuote ? ' 금액은 예상이며 담당자 확인 후 확정됩니다. 자세한 제품 정보와 운반비는 전화로 상담해 주세요.' : '');
    if (hasQuote && PHONE) {
      const t = document.createElement('span');
      t.className = 'qc-note-tel';
      t.innerHTML = `[고객상담 전화번호: <a href="tel:${esc(PHONE.replace(/[^0-9]/g, ''))}">${esc(PHONE)}</a>]`;
      n.appendChild(t);
    }
    wrap.appendChild(n);
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
    usePhoto(file.files && file.files[0]);
  }

  // 버튼으로 고른 사진·창에 끌어다 놓은 사진 공통
  async function usePhoto(f) {
    if (!f || busy) return;
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
    const prev = (state.prev || []).slice();
    if (state.msgs.some(m => m.role === 'user')) prev.unshift({ sid: state.sid, at: Date.now(), msgs: state.msgs.slice(-30) });
    state = { sid: '', open: true, msgs: [], prev: prev.slice(0, PREV_MAX) };
    photoReset();
    showPrev(false);
    try {
      const r = await fetch('/api/chat/quick.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'new' }) });
      const d = await r.json();
      if (d.ok) state.sid = d.sid;
    } catch (e) {}
    save(); render(); input.focus();
  }

  // ── 이전 대화내용: 로그인하면 서버의 내 빠른 창 대화 5개(다른 탭·기기에서도), 비로그인은 이 탭의 [새 대화] 전 대화 5개 ──
  async function post(payload) {
    const r = await fetch('/api/chat/quick.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) });
    return r.json();
  }
  const timeText = at => {
    const d = new Date(at || Date.now()), now = new Date();
    const hm = `${d.getHours()}:${String(d.getMinutes()).padStart(2, '0')}`;
    return d.toDateString() === now.toDateString() ? hm : `${d.getMonth() + 1}/${d.getDate()} ${hm}`;
  };
  function prevItem(first, at, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'qc-prev-item';
    b.innerHTML = `<span class="qc-prev-q">${esc(String(first || '').split('\n')[0])}</span><span class="qc-prev-at">${esc(timeText(at))}</span>`;
    b.addEventListener('click', onClick);
    prevBox.appendChild(b);
  }
  function prevEmpty(text) {
    const p = document.createElement('p');
    p.className = 'qc-prev-empty';
    p.textContent = text;
    prevBox.appendChild(p);
  }
  async function showPrev(open) {
    if (!prevBtn) return;
    prevBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    prevBox.hidden = !open;
    if (!open) return;
    prevBox.textContent = '';
    prevEmpty('불러오는 중…');
    let d = null;
    try { d = await post({ action: 'history', sid: state.sid }); } catch (e) {}
    if (prevBox.hidden) return;
    prevBox.textContent = '';
    if (d && d.ok && d.server) {
      if (!(d.list || []).length) { prevEmpty('이전 대화가 없습니다. [새 대화]를 누르면 지금 대화가 여기에 남습니다.'); return; }
      d.list.forEach(c => prevItem(c.first, c.at, () => restoreServer(c.sid)));
      return;
    }
    const list = state.prev || [];
    if (!list.length) { prevEmpty('이전 대화가 없습니다. [새 대화]를 누르면 지금 대화가 여기에 남습니다.'); return; }
    list.forEach((c, i) => prevItem((c.msgs.find(m => m.role === 'user') || {}).text || '', c.at, () => restorePrev(i)));
  }

  async function restoreServer(sid) {
    if (busy) return;
    busy = true;
    let d = null;
    try { d = await post({ action: 'load', sid }); } catch (e) {}
    busy = false;
    if (!d || !d.ok) { prevBox.textContent = ''; prevEmpty((d && d.error) || '대화를 불러오지 못했습니다.'); return; }
    state = { sid: d.sid, open: true, msgs: d.msgs || [], prev: state.prev || [] };
    save(); showPrev(false); render(); input.focus();
  }

  function restorePrev(i) {
    if (busy) return;
    const list = (state.prev || []).slice();
    const [c] = list.splice(i, 1);
    if (!c) return;
    if (state.msgs.some(m => m.role === 'user')) list.unshift({ sid: state.sid, at: Date.now(), msgs: state.msgs.slice(-30) });
    state = { sid: c.sid, open: true, msgs: c.msgs, prev: list.slice(0, PREV_MAX) };
    save(); showPrev(false); render(); input.focus();
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
  if (helpBtn && help) {
    helpBtn.addEventListener('click', () => {
      const open = help.hidden;
      help.hidden = !open;
      helpBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
  }
  if (prevBtn && prevBox) prevBtn.addEventListener('click', () => showPrev(prevBox.hidden));
  if (attach && file && photo) {
    attach.addEventListener('click', () => { if (!busy) file.click(); });
    file.addEventListener('change', photoPicked);
    agree.addEventListener('change', () => { pSend.disabled = !agree.checked; });
    pSend.addEventListener('click', photoSend);
    // 끌어다 놓기: 창 어디에 놓아도 사진 첨부(안내·동의는 그대로)
    let dragDepth = 0;
    const isFileDrag = e => Array.from((e.dataTransfer && e.dataTransfer.types) || []).includes('Files');
    panel.addEventListener('dragenter', e => { if (!isFileDrag(e)) return; e.preventDefault(); dragDepth++; panel.classList.add('is-drop'); });
    panel.addEventListener('dragover', e => { if (!isFileDrag(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
    panel.addEventListener('dragleave', e => { if (!isFileDrag(e)) return; if (--dragDepth <= 0) { dragDepth = 0; panel.classList.remove('is-drop'); } });
    panel.addEventListener('drop', e => {
      if (!isFileDrag(e)) return;
      e.preventDefault();
      dragDepth = 0; panel.classList.remove('is-drop');
      usePhoto(e.dataTransfer.files && e.dataTransfer.files[0]);
    });
    document.getElementById('qc-photo-cancel').addEventListener('click', () => { photoReset(); attach.focus(); });
  }

  if (state.open) setOpen(true);
})();
