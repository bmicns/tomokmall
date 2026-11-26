/**
 * 우측 하단 'AI 빠른 상담' 창 (app/layout.php render_quick_chat, 사용자 결정 2026-11-23 — 지금 AI 상담과 비교용)
 * 화면: 'AI 빠른 상담 창(After)' 시안(2026-11-25 사용자 확정)
 * - [대화] 탭: AI 답은 글만(견적 표는 [견적 목록] 탭에), 첫 화면은 인사 + 질문 예시 칩 + 시작 타일(사진 올리기·이전 대화)
 * - [견적 목록] 탭: 창 안 목록(대화마다 서버 세션) — 수량 −/+·빼기, 배송 지역 17개 시·도, 상품 합계·예상 운반비·부가세·예상 합계.
 *   금액은 모두 서버 계산(quote_draft) 그대로 표시만 한다. [견적 요청하기] = 사이트 견적서로 옮기고 견적 요청 화면으로
 * - 아래에서 올라오는 시트: 도움말 · 견적서 사진 올리기(A10 — 안내를 보고 사진을 고르면 보냄, 긴 변 1600px JPEG 로 줄임,
 *   사진은 브라우저에 남기지 않음) · 이전 대화(로그인하면 서버의 내 대화 5개, 비로그인은 이 탭의 [새 대화] 전 대화 5개)
 * - 대화는 이 탭 동안(sessionStorage) 페이지를 옮겨도 이어짐
 */
(function () {
  'use strict';
  const STORE = 'tomok.quickChat.v1';
  const MAX_LEN = 200;
  const CHIPS = ['주차장 배수로 그레이팅 20m', '마당 경계석 30m, 경기도', '공원 보도블록 500㎡'];
  const PREV_MAX = 5;
  const PHOTO_MAX_BYTES = 10 * 1024 * 1024;
  const PHOTO_MAX_SIDE = 1600;
  const PHOTO_PREFIX = '[견적서 사진에서 읽은 품목]';
  const API = '/api/chat/quick.php';

  const $ = id => document.getElementById(id);
  const fab = $('qc-fab');
  const panel = $('qc-panel');
  if (!fab || !panel) return;
  const body = $('qc-body'), log = $('qc-log'), form = $('qc-form'), input = $('qc-input'), send = $('qc-send');
  const tabChat = $('qc-tab-chat'), tabList = $('qc-tab-list'), badge = $('qc-badge');
  const viewChat = $('qc-view-chat'), viewList = $('qc-view-list'), listBox = $('qc-list');
  const reqBtn = $('qc-request'), toastEl = $('qc-toast');
  const sheet = $('qc-sheet'), sheetTitle = $('qc-sheet-title'), hist = $('qc-hist');
  const file = $('qc-file'), drop = $('qc-drop');
  const PHOTO_ON = panel.dataset.photo === '1' && !!file;
  let REGIONS = [];
  try { REGIONS = JSON.parse(panel.dataset.regions || '[]'); } catch (e) {}

  let state = load();
  let busy = false;
  let quote = null;          // 견적 목록(서버 quote_draft) — 화면에 그대로 표시
  let listSeq = 0;           // 늦게 온 응답이 새 응답을 덮지 않게
  let sheetOpener = null;
  let toastTimer = 0;
  const qtyTimers = {};

  function load() {
    try {
      const s = JSON.parse(sessionStorage.getItem(STORE) || 'null');
      if (s && Array.isArray(s.msgs)) { if (!Array.isArray(s.prev)) s.prev = []; return s; }
    } catch (e) {}
    return { sid: '', open: false, tab: 'chat', msgs: [], prev: [] };
  }
  // 대화 글과 '견적서로 옮김' 바로가기만 남긴다(견적 표는 목록 탭이 서버에서 다시 받음)
  const slim = msgs => msgs.slice(-30).map(m => ({ role: m.role, text: m.text, error: m.error || undefined,
    events: (m.events || []).filter(e => e.type === 'estimate_moved').map(e => ({ type: e.type, url: e.url })) }));
  function save() {
    try {
      sessionStorage.setItem(STORE, JSON.stringify({ sid: state.sid, open: state.open, tab: state.tab, msgs: slim(state.msgs),
        prev: (state.prev || []).slice(0, PREV_MAX).map(c => ({ sid: c.sid, at: c.at, msgs: slim(c.msgs) })) }));
    } catch (e) {}
  }

  const esc = s => String(s ?? '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
  const won = n => new Intl.NumberFormat('ko-KR').format(parseInt(n, 10) || 0) + '원';
  const num = n => new Intl.NumberFormat('ko-KR').format(parseInt(n, 10) || 0);
  // 말풍선은 글자 그대로 — 마크다운 굵게·제목 기호만 정리
  const clean = t => String(t || '').replace(/\*\*(.+?)\*\*/g, '$1').replace(/^#+\s*/gm, '').trim();
  const safeUrl = u => (/^(https?:\/\/|\/(?!\/))/i.test(String(u || '')) ? String(u) : '');

  async function post(payload, opts) {
    const r = await fetch(API, Object.assign({ method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload) }, opts || {}));
    return r.json();
  }

  function toast(text) {
    toastEl.textContent = text;
    toastEl.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastEl.hidden = true; }, 2400);
  }

  // ── 창 열고 닫기 · 탭 ──────────────────────────────
  function setOpen(open) {
    state.open = open;
    panel.hidden = !open;
    fab.setAttribute('aria-expanded', open ? 'true' : 'false');
    save();
    if (open) {
      setTab(state.tab === 'list' ? 'list' : 'chat', false);
      render();
      refreshList();
    } else {
      closeSheet(false);
      fab.focus();
    }
  }

  function setTab(tab, focus) {
    state.tab = tab;
    const isList = tab === 'list';
    tabChat.setAttribute('aria-selected', isList ? 'false' : 'true');
    tabList.setAttribute('aria-selected', isList ? 'true' : 'false');
    tabChat.tabIndex = isList ? -1 : 0;
    tabList.tabIndex = isList ? 0 : -1;
    viewChat.hidden = isList;
    viewList.hidden = !isList;
    save();
    if (isList) { renderList(); refreshList(); }
    if (focus !== false) (isList ? tabList : input).focus();
  }

  // ── 대화 ──────────────────────────────────────────
  function aiRow(text, cls) {
    const row = document.createElement('div');
    row.className = 'qc-a' + (cls ? ' ' + cls : '');
    row.innerHTML = '<span class="qc-ai-badge" aria-hidden="true">AI</span><div class="qc-a-body"><span class="qc-sr">AI 답변: </span><div class="qc-a-text"></div></div>';
    row.querySelector('.qc-a-text').textContent = text;
    log.appendChild(row);
    return row;
  }

  function render() {
    log.textContent = '';
    const hello = aiRow('');
    hello.querySelector('.qc-a-text').innerHTML = '필요한 자재를 편하게 말씀해 주세요. 맞는 제품을 찾아 <b>견적 목록</b>에 담고, 설치 길이·면적을 알려 주시면 수량도 맞춰 드려요.';
    if (!state.msgs.length) {
      const start = document.createElement('div');
      start.innerHTML = '<div class="qc-start-label">이렇게 물어보세요</div><div class="qc-chips"></div>';
      const chips = start.querySelector('.qc-chips');
      CHIPS.forEach(t => {
        const b = document.createElement('button');
        b.type = 'button'; b.className = 'qc-chip'; b.textContent = t;
        b.addEventListener('click', () => ask(t));
        chips.appendChild(b);
      });
      log.appendChild(start);
      const tiles = document.createElement('div');
      tiles.className = 'qc-tiles';
      if (PHOTO_ON) tiles.appendChild(tile('<rect x="3" y="6" width="18" height="13" rx="2"/><circle cx="12" cy="12.5" r="3.2"/><path d="M8.5 6l1.5-2h4l1.5 2"/>', '견적서 사진 올리기', e => openSheet('photo', e.currentTarget)));
      tiles.appendChild(tile('<path d="M3.5 12a8.5 8.5 0 1 0 2.5-6"/><path d="M3.5 4v4.5H8"/><path d="M12 8v4.2l2.8 1.8"/>', '이전 대화 보기', e => openSheet('history', e.currentTarget)));
      log.appendChild(tiles);
    }
    state.msgs.forEach(m => {
      if (m.role === 'user') {
        const u = document.createElement('div');
        u.className = 'qc-u';
        u.innerHTML = '<span class="qc-sr">내 질문: </span>';
        u.appendChild(document.createTextNode(m.text));
        log.appendChild(u);
        return;
      }
      const row = aiRow(clean(m.text), m.error ? 'is-error' : '');
      const moved = (m.events || []).find(e => e.type === 'estimate_moved');
      if (moved) {
        const a = document.createElement('a');
        a.className = 'qc-a-link';
        a.href = safeUrl(moved.url) || '/estimate.php';
        a.textContent = '견적 요청 화면으로 가기';
        row.querySelector('.qc-a-body').appendChild(a);
      }
    });
    body.scrollTop = body.scrollHeight;   // 세로 뒤집힌 칸: 브라우저마다 맨 아래 값이 달라 둘 다 맞춤
    body.scrollTop = 0;
  }

  function tile(paths, label, onClick) {
    const b = document.createElement('button');
    b.type = 'button'; b.className = 'qc-tile';
    b.innerHTML = `<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${paths}</svg><span>${esc(label)}</span>`;
    b.addEventListener('click', onClick);
    return b;
  }

  function setBusy(on) {
    busy = on;
    syncSend();
    const at = $('qc-attach');
    if (at) at.disabled = on;
  }
  function syncSend() { send.disabled = busy || !input.value.trim(); }
  function grow() { input.style.height = '22px'; input.style.height = Math.min(88, input.scrollHeight) + 'px'; }

  // 질문·사진 공통: 서버에 보내고 답 글을 붙이고, 견적 표가 오면 목록 탭을 바꿈
  async function request(payload, userText, typingText, timeoutMs, timeoutText) {
    setBusy(true);
    if (state.tab !== 'chat') setTab('chat', false);
    const mine = { role: 'user', text: userText };
    state.msgs.push(mine);
    save(); render();
    // 기다리는 동안: '답변을 준비하고 있어요.' → '..' → '...' 반복(AI 상담 화면과 같게, 사용자 요청 2026-11-25)
    const typing = aiRow(typingText + '.', 'is-typing');
    typing.setAttribute('role', 'status');
    const typingEl = typing.querySelector('.qc-a-text');
    let dots = 1;
    const waitTimer = setInterval(() => { dots = dots % 3 + 1; typingEl.textContent = typingText + '.'.repeat(dots); }, 500);
    body.scrollTop = 0;
    const before = quote ? (quote.items || []).length : 0;
    let reply;
    try {
      const ctrl = new AbortController();
      const tid = setTimeout(() => ctrl.abort(), timeoutMs);
      const d = await post(Object.assign({ sid: state.sid }, payload), { signal: ctrl.signal });
      clearTimeout(tid);
      if (d.sid) state.sid = d.sid;
      if (d.ok && d.asked) mine.text = '견적서 사진 — 읽은 품목\n' + String(d.asked).replace(PHOTO_PREFIX, '').trim();
      reply = d.ok ? { role: 'ai', text: d.text || '', events: d.events || [] }
                   : { role: 'ai', text: d.error || '오류가 발생했습니다. 다시 시도해 주세요.', error: true };
      const q = (d.events || []).filter(e => e.type === 'quote_draft').pop();
      if (q) {
        quote = q; listSeq++; syncBadge();
        const after = (q.items || []).length;
        if (after > before) toast('견적 목록에 담았어요 (' + after + '개)');
      }
      if ((d.events || []).some(e => e.type === 'cart_update') && typeof refreshEstPanel === 'function') refreshEstPanel();
    } catch (e) {
      reply = { role: 'ai', text: e.name === 'AbortError' ? timeoutText : '오류가 발생했습니다. 다시 시도해 주세요.', error: true };
    }
    clearInterval(waitTimer);
    state.msgs.push(reply);
    save(); render();
    setBusy(false);
    input.focus();
  }

  function ask(text) {
    const msg = String(text || '').trim().slice(0, MAX_LEN);
    if (!msg || busy) return;
    input.value = ''; grow(); syncSend();
    return request({ action: 'ask', msg }, msg, '답변을 준비하고 있어요', 95000, '응답 시간이 초과되었습니다. 더 짧게 질문해 보세요.');
  }

  // ── 견적 목록 ──────────────────────────────────────
  function syncBadge() {
    const n = quote ? (quote.items || []).length : 0;
    badge.textContent = String(n);
    badge.classList.toggle('is-on', n > 0);
    tabList.setAttribute('aria-label', '견적 목록 ' + n + '개');
  }

  async function refreshList() {
    if (!state.sid) { quote = null; syncBadge(); if (state.tab === 'list') renderList(); return; }
    const seq = ++listSeq;
    let d = null;
    try { d = await post({ action: 'list', sid: state.sid }); } catch (e) {}
    if (seq !== listSeq || !d || !d.ok) return;
    if (d.sid) state.sid = d.sid;
    quote = d.quote || null;
    syncBadge();
    if (state.tab === 'list') renderList();
  }

  // 목록을 바꾸는 요청(수량·빼기·지역) — 응답의 견적 표로 다시 그림
  async function listChange(payload, failText) {
    const seq = ++listSeq;
    let d = null;
    try { d = await post(Object.assign({ sid: state.sid }, payload)); } catch (e) {}
    if (d && d.sid) state.sid = d.sid;
    if (d && d.quote && seq === listSeq) { quote = d.quote; syncBadge(); renderList(); }
    if (!d || !d.ok) { toast((d && d.error) || failText); if (!(d && d.quote)) refreshList(); }
    return d;
  }

  const unitOf = it => it.unit || '개';
  const subOf = it => {
    const unit = parseInt(it.unit_price, 10) || 0, qty = parseInt(it.quantity, 10) || 0;
    if (qty <= 0) return { text: '수량 미정', muted: true };
    if (unit <= 0) return { text: '가격 문의', muted: true };
    return { text: won(unit * qty), muted: false };
  };

  function renderList() {
    const keep = document.activeElement && listBox.contains(document.activeElement) ? document.activeElement.dataset.key : '';
    const items = quote ? (quote.items || []) : [];
    reqBtn.disabled = false;
    if (!items.length) {
      listBox.innerHTML = '<div class="qc-empty">아직 담은 제품이 없어요.<br>대화에서 자재를 물어보면 여기에 쌓여요.</div>';
      return;
    }
    const rows = items.map(it => {
      const pid = parseInt(it.product_id, 10) || 0;
      const unit = parseInt(it.unit_price, 10) || 0;
      const qty = parseInt(it.quantity, 10) || 0;
      const s = subOf(it);
      const spec = it.spec ? esc(String(it.spec).replace(/(\d)\s*[*xX]\s*(?=\d)/g, '$1×')) + ' · ' : '';   // 300*1000 → 300×1000(화면만)
      return `<div class="qc-item" data-pid="${pid}">
          <div class="qc-item-top">
            <div class="qc-item-info">
              <div class="qc-item-name" title="${esc(it.name)}">${esc(it.name)}</div>
              <div class="qc-item-meta">${spec}${unit > 0 ? won(unit) + '/' + esc(unitOf(it)) : '가격 문의'}</div>
            </div>
            <button type="button" class="qc-ib qc-del" data-act="del" data-key="del-${pid}" aria-label="${esc(it.name)} 빼기"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg></button>
          </div>
          <div class="qc-item-bottom">
            <div class="qc-stepper" role="group" aria-label="${esc(it.name)} 수량">
              <button type="button" class="qc-step" data-act="minus" data-key="minus-${pid}" aria-label="수량 줄이기"${qty <= 0 ? ' disabled' : ''}>−</button>
              <span class="qc-qty" aria-live="polite">${num(qty)}${esc(unitOf(it))}</span>
              <button type="button" class="qc-step" data-act="plus" data-key="plus-${pid}" aria-label="수량 늘리기"${qty >= 999 ? ' disabled' : ''}>+</button>
            </div>
            <span class="qc-sub${s.muted ? ' is-muted' : ''}">${s.text}</span>
          </div>
        </div>`;
    }).join('');

    const region = quote.region || '';
    const f = quote.freight || {};
    const st = f.status || 'none';
    // 수량 미정(0) 품목이 있으면 서버는 중량을 몰라 운반비를 계산하지 않음 — 수량을 정하라고 안내(계산은 그대로)
    const undecided = st === 'missing_weight' && items.some(it => (parseInt(it.quantity, 10) || 0) <= 0);
    const fText = !region || st === 'none' ? '-' : st === 'ok' ? won(f.amount) : st === 'negotiate' ? '별도 협의' : undecided ? '-' : '별도 확인';   // 수량 미정 품목이 있으면 '-'(11-26 사용자 요청)
    const note = !region ? '현장 시·도를 골라 주세요'
      : st === 'ok' ? region + ' · 예상 운반비 ' + won(f.amount)
      : st === 'negotiate' ? region + ' · 운반비 별도 협의'
      : undecided ? region + ' · 수량을 정하면 운반비가 계산돼요' : region + ' · 운반비 별도 확인';
    const chips = REGIONS.map(r => `<button type="button" class="qc-rg" data-act="region" data-region="${esc(r)}" data-key="rg-${esc(r)}" aria-pressed="${r === region ? 'true' : 'false'}">${esc(r)}</button>`).join('');
    const goods = parseInt(quote.goods_total, 10) || 0;
    const hasFinal = quote.final !== null && quote.final !== undefined;
    listBox.innerHTML = `<div class="qc-items">${rows}</div>
      <div class="qc-region">
        <div class="qc-region-head"><span class="qc-region-title">배송 지역</span><span class="qc-region-note${region ? ' is-on' : ''}">${esc(note)}</span></div>
        <div class="qc-regions" role="group" aria-label="배송 지역 (시·도)">${chips}</div>
      </div>
      <div class="qc-sum">
        <div class="qc-row"><span>상품 합계</span><span>${won(goods)}${quote.has_inquiry ? ' + 문의' : ''}</span></div>
        <div class="qc-row"><span>예상 운반비</span><span>${esc(fText)}</span></div>
        <div class="qc-row"><span>부가세 10%</span><span>${quote.vat === null || quote.vat === undefined ? '-' : won(quote.vat)}</span></div>
        <div class="qc-total"><span>예상 합계</span><span class="qc-total-val">${hasFinal ? won(quote.final) : '담당자 문의'}${quote.has_inquiry && hasFinal ? '<small>+ 가격 문의 품목</small>' : ''}</span></div>
      </div>
      <p class="qc-ship-note">운반비는 화물 시세 기준 <b>예상치(참고용)</b>이며, 실제 금액은 견적요청시 확정됩니다.</p>`;
    if (keep) {
      const el = listBox.querySelector(`[data-key="${CSS.escape(keep)}"]`);
      if (el && !el.disabled) el.focus();
    }
  }

  // 수량 −/+: 화면은 바로 바꾸고, 서버에는 잠깐 모아서(연달아 누르면 마지막 값만) 보냄
  function bumpQty(pid, delta) {
    const it = quote && (quote.items || []).find(x => (parseInt(x.product_id, 10) || 0) === pid);
    if (!it) return;
    const qty = Math.max(0, Math.min(999, (parseInt(it.quantity, 10) || 0) + delta));
    if (qty === (parseInt(it.quantity, 10) || 0)) return;
    it.quantity = qty;
    it.subtotal = (parseInt(it.unit_price, 10) || 0) * qty;
    listSeq++;   // 이 사이에 오는 이전 응답은 버림
    renderList();
    clearTimeout(qtyTimers[pid]);
    qtyTimers[pid] = setTimeout(() => listChange({ action: 'qty', product_id: pid, qty }, '수량을 바꾸지 못했어요. 다시 시도해 주세요.'), 350);
  }

  listBox.addEventListener('click', e => {
    const b = e.target.closest('button[data-act]');
    if (!b || !listBox.contains(b)) return;
    const row = b.closest('[data-pid]');
    const pid = row ? (parseInt(row.dataset.pid, 10) || 0) : 0;
    const act = b.dataset.act;
    if (act === 'plus') bumpQty(pid, 1);
    else if (act === 'minus') bumpQty(pid, -1);
    else if (act === 'del') {
      const it = (quote.items || []).find(x => (parseInt(x.product_id, 10) || 0) === pid);
      clearTimeout(qtyTimers[pid]);
      listChange({ action: 'remove', product_id: pid }, '빼지 못했어요. 다시 시도해 주세요.')
        .then(d => { if (d && d.ok) { toast((it ? it.name : '제품') + ' 뺐어요'); tabList.focus(); } });
    } else if (act === 'region') {
      const r = b.dataset.region;
      const next = r === (quote.region || '') ? '' : r;   // 고른 지역을 다시 누르면 풀림
      listBox.querySelectorAll('.qc-rg').forEach(x => x.setAttribute('aria-pressed', x.dataset.region === next ? 'true' : 'false'));
      listChange({ action: 'region', region: next }, '배송 지역을 바꾸지 못했어요. 다시 시도해 주세요.');
    }
  });

  // [견적 요청하기]: 창 안 목록을 사이트 견적서로 옮기고(수량 미정 품목 제외) 견적 요청 화면으로
  async function toEstimate() {
    if (busy) return;
    if (!quote || !(quote.items || []).some(it => (parseInt(it.quantity, 10) || 0) > 0)) {
      toast('수량이 있는 제품을 먼저 담아 주세요');
      return;
    }
    busy = true; reqBtn.disabled = true;
    let d = null;
    try { d = await post({ action: 'to_estimate', sid: state.sid }); } catch (e) {}
    busy = false; reqBtn.disabled = false;
    if (d && d.ok) {
      if ((d.skipped || []).length) {
        state.msgs.push({ role: 'ai', text: '수량이 정해지지 않아 견적서로 옮기지 않은 품목: ' + d.skipped.join(', '), error: true });
        save();
      }
      location.href = safeUrl(d.url) || '/estimate.php';
      return;
    }
    toast((d && d.error) || '견적서로 옮기지 못했어요. 다시 시도해 주세요.');
  }

  // ── 시트(도움말·사진 올리기·이전 대화) ─────────────────
  function openSheet(name, opener) {
    if (name === 'photo' && !PHOTO_ON) return;
    sheetOpener = opener || document.activeElement;
    let title = '';
    sheet.querySelectorAll('.qc-sheet-part').forEach(p => {
      const on = p.dataset.sheet === name;
      p.hidden = !on;
      if (on) title = p.dataset.title || '';
    });
    sheetTitle.textContent = title;
    sheet.hidden = false;
    sheet.dataset.open = name;
    if (name === 'history') loadHistory();
    $('qc-sheet-close').focus();
  }
  function closeSheet(restore) {
    if (sheet.hidden) return;
    sheet.hidden = true;
    sheet.dataset.open = '';
    if (drop) drop.classList.remove('is-over');
    if (restore !== false && sheetOpener && document.body.contains(sheetOpener)) sheetOpener.focus();
    sheetOpener = null;
  }
  sheet.addEventListener('click', e => { if (e.target === sheet) closeSheet(); });
  $('qc-sheet-close').addEventListener('click', () => closeSheet());

  // 시트 안에서 Tab 이 시트 밖으로 나가지 않게
  sheet.addEventListener('keydown', e => {
    if (e.key !== 'Tab') return;
    const els = Array.from(sheet.querySelectorAll('button, [tabindex="0"]')).filter(el => !el.disabled && el.offsetParent !== null);
    if (!els.length) return;
    const first = els[0], last = els[els.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  // 이전 대화: 로그인하면 서버의 내 빠른 창 대화 5개(다른 탭·기기에서도), 비로그인은 이 탭의 [새 대화] 전 대화 5개
  const timeText = at => {
    const d = new Date(at || Date.now()), now = new Date();
    const h = d.getHours();
    const hm = (h < 12 ? '오전 ' : '오후 ') + ((h % 12) || 12) + ':' + String(d.getMinutes()).padStart(2, '0');
    const y = new Date(now); y.setDate(now.getDate() - 1);
    if (d.toDateString() === now.toDateString()) return '오늘 ' + hm;
    if (d.toDateString() === y.toDateString()) return '어제 ' + hm;
    return (d.getMonth() + 1) + '월 ' + d.getDate() + '일';
  };
  function histItem(first, at, onClick) {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'qc-hist-item';
    b.innerHTML = `<span class="qc-hist-text"><span class="qc-hist-title">${esc(String(first || '').split('\n')[0])}</span><span class="qc-hist-meta">${esc(timeText(at))}</span></span><span class="qc-hist-open" aria-hidden="true">열기</span>`;
    b.addEventListener('click', onClick);
    hist.appendChild(b);
  }
  function histNote(text) {
    hist.textContent = '';
    const p = document.createElement('p');
    p.className = 'qc-hist-empty';
    p.textContent = text;
    hist.appendChild(p);
  }
  const NO_PREV = '이전 대화가 없어요. 새 대화를 시작하면 지금 대화가 여기에 남아요.';
  async function loadHistory() {
    histNote('불러오는 중…');
    let d = null;
    try { d = await post({ action: 'history', sid: state.sid }); } catch (e) {}
    if (sheet.dataset.open !== 'history') return;
    hist.textContent = '';
    if (d && d.ok && d.server) {
      if (!(d.list || []).length) histNote(NO_PREV);
      else d.list.forEach(c => histItem(c.first, c.at, () => restoreServer(c.sid)));
      if (d.guest) guestFoot();
      return;
    }
    const list = state.prev || [];
    if (!list.length) { histNote(NO_PREV); return; }
    list.forEach((c, i) => histItem((c.msgs.find(m => m.role === 'user') || {}).text || '', c.at, () => restorePrev(i)));
  }

  // 비회원: 이 기기(쿠키)에서만 30일 보관 안내 + '이 기기 상담 기록 지우기'(두 번 눌러 확인 — 브라우저 확인 창 없이)
  function guestFoot() {
    const foot = document.createElement('div');
    foot.className = 'qc-hist-foot';
    const p = document.createElement('p');
    p.textContent = '비회원 상담은 이 기기에서만 마지막 대화 후 30일 동안 보여요. 로그인하면 내 계정으로 옮겨져요.';
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'qc-hist-forget';
    b.textContent = '이 기기 상담 기록 지우기';
    b.addEventListener('click', async () => {
      if (!b.dataset.armed) { b.dataset.armed = '1'; b.textContent = '한 번 더 누르면 모두 지워요'; return; }
      b.disabled = true;
      let r = null;
      try { r = await (await fetch('/api/chat/history.php', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ action: 'forget' }) })).json(); } catch (e) {}
      if (!r || !r.ok) { b.disabled = false; b.textContent = '지우지 못했어요. 다시 눌러 주세요'; return; }
      state = { sid: '', open: true, tab: 'chat', msgs: [], prev: [] };
      quote = null; listSeq++; syncBadge(); save(); render();
      histNote('이 기기의 상담 기록을 지웠어요.');
    });
    foot.append(p, b);
    hist.appendChild(foot);
  }

  async function restoreServer(sid) {
    if (busy) return;
    busy = true;
    let d = null;
    try { d = await post({ action: 'load', sid }); } catch (e) {}
    busy = false;
    if (!d || !d.ok) { histNote((d && d.error) || '대화를 불러오지 못했어요.'); return; }
    state = { sid: d.sid, open: true, tab: 'chat', msgs: d.msgs || [], prev: state.prev || [] };
    afterRestore();
  }
  function restorePrev(i) {
    if (busy) return;
    const list = (state.prev || []).slice();
    const [c] = list.splice(i, 1);
    if (!c) return;
    if (state.msgs.some(m => m.role === 'user')) list.unshift({ sid: state.sid, at: Date.now(), msgs: state.msgs.slice(-30) });
    state = { sid: c.sid, open: true, tab: 'chat', msgs: c.msgs, prev: list.slice(0, PREV_MAX) };
    afterRestore();
  }
  function afterRestore() {
    quote = null; syncBadge();
    save(); closeSheet(false); setTab('chat', false); render(); refreshList(); input.focus();
  }

  async function newChat() {
    if (busy) return;
    const prev = (state.prev || []).slice();
    if (state.msgs.some(m => m.role === 'user')) prev.unshift({ sid: state.sid, at: Date.now(), msgs: state.msgs.slice(-30) });
    state = { sid: '', open: true, tab: 'chat', msgs: [], prev: prev.slice(0, PREV_MAX) };
    quote = null; listSeq++; syncBadge();
    closeSheet(false);
    setTab('chat', false);
    render();
    try {
      const d = await post({ action: 'new' });
      if (d.ok) state.sid = d.sid;
    } catch (e) {}
    save(); input.focus();
  }

  // ── 견적서 사진 올리기(A10) ─────────────────────────
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

  // 사진 올리기 시트(안내를 본 상태)에서 고르거나 끌어다 놓은 사진 → 바로 보냄
  async function usePhoto(f) {
    if (!f || busy) return;
    if (file) file.value = '';
    if (!/^image\/(jpeg|png)$/.test(f.type)) { toast('jpg·png 사진만 올릴 수 있어요'); return; }
    if (f.size > PHOTO_MAX_BYTES) { toast('사진은 10MB 이하로 올려 주세요'); return; }
    let image = '';
    try { image = await shrink(f); } catch (e) { toast('사진을 열 수 없어요. 다른 사진으로 올려 주세요'); return; }
    closeSheet(false);
    request({ action: 'photo', image, consent: true }, '견적서 사진을 보냈어요', '사진에서 품목을 읽고 있어요', 150000, '응답 시간이 초과되었습니다. 품목이 적게 보이게 나눠 찍어 다시 올려 주세요.');
  }

  if (PHOTO_ON) {
    const pick = () => { if (!busy) file.click(); };
    $('qc-pick').addEventListener('click', pick);
    drop.addEventListener('click', pick);
    drop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); pick(); } });
    file.addEventListener('change', () => usePhoto(file.files && file.files[0]));
    const at = $('qc-attach');
    if (at) at.addEventListener('click', e => openSheet('photo', e.currentTarget));
    // 끌어다 놓기: 창에 사진을 끌고 오면 안내가 있는 사진 올리기 시트를 열고, 놓으면 보냄
    const isFileDrag = e => Array.from((e.dataTransfer && e.dataTransfer.types) || []).includes('Files');
    panel.addEventListener('dragenter', e => {
      if (!isFileDrag(e)) return;
      e.preventDefault();
      if (sheet.dataset.open !== 'photo' && !busy) openSheet('photo');
      drop.classList.add('is-over');
    });
    panel.addEventListener('dragover', e => { if (!isFileDrag(e)) return; e.preventDefault(); e.dataTransfer.dropEffect = 'copy'; });
    panel.addEventListener('dragleave', e => { if (isFileDrag(e) && !panel.contains(e.relatedTarget)) drop.classList.remove('is-over'); });
    panel.addEventListener('drop', e => {
      if (!isFileDrag(e)) return;
      e.preventDefault();
      drop.classList.remove('is-over');
      if (sheet.dataset.open === 'photo') usePhoto(e.dataTransfer.files && e.dataTransfer.files[0]);
    });
  }

  // ── 이벤트 연결 ────────────────────────────────────
  fab.addEventListener('click', () => setOpen(true));
  $('qc-close').addEventListener('click', () => setOpen(false));
  $('qc-new').addEventListener('click', newChat);
  $('qc-help-btn').addEventListener('click', e => openSheet('help', e.currentTarget));
  $('qc-prev-btn').addEventListener('click', e => openSheet('history', e.currentTarget));
  $('qc-back').addEventListener('click', () => setTab('chat'));
  reqBtn.addEventListener('click', toEstimate);
  tabChat.addEventListener('click', () => setTab('chat'));
  tabList.addEventListener('click', () => setTab('list'));
  [tabChat, tabList].forEach(t => t.addEventListener('keydown', e => {
    if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') { e.preventDefault(); setTab(state.tab === 'list' ? 'chat' : 'list'); (state.tab === 'list' ? tabList : tabChat).focus(); }
  }));
  panel.addEventListener('keydown', e => {
    if (e.key !== 'Escape') return;
    if (!sheet.hidden) closeSheet(); else setOpen(false);
  });
  input.addEventListener('input', () => { grow(); syncSend(); });
  input.addEventListener('keydown', e => {
    if (e.key === 'Enter' && !e.shiftKey && !e.isComposing) { e.preventDefault(); ask(input.value); }
  });
  form.addEventListener('submit', e => { e.preventDefault(); ask(input.value); });

  syncBadge();
  if (state.open) setOpen(true);
})();
