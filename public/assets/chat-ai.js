/**
 * AI 채팅 클라이언트 로직
 * 의존: window.SID (chat_renderer.php 인라인 스크립트에서 주입)
 * 방식: AJAX fetch (SSE 아님) — 서버 버퍼링 환경에서 안정적
 */

let isStreaming = false;
let accText     = '';

function setInput(text) {
  document.getElementById('inp').value = text;
  document.getElementById('inp').focus();
}

/** 메시지 행 추가. { bubble, content } 반환 */
function addMsg(role, text) {
  const wrap   = document.getElementById('messages');
  const row    = document.createElement('div');
  row.className = `msg ${role}`;

  const avatar = document.createElement('div');
  avatar.className = 'msg-avatar';
  avatar.textContent = role === 'user' ? '나' : 'AI';

  const content = document.createElement('div');
  content.className = 'msg-content';

  const bubble = document.createElement('div');
  bubble.className = 'msg-bubble';
  if (text) bubble.textContent = text;

  content.appendChild(bubble);
  row.appendChild(avatar);
  row.appendChild(content);
  wrap.appendChild(row);
  wrap.scrollTop = wrap.scrollHeight;
  return { bubble, content };
}

/** 첫 문장(~니다.) 뒤 줄 내림 */
function formatChatText(text) {
  if (!text) return '';
  // 첫 번째 "니다." 또는 "니다!" 뒤에 줄바꿈이 없으면 추가
  return text.replace(/^(.*?니다[.!])\s*/, '$1\n\n');
}

/** XSS 방지용 이스케이프 */
function escHtml(str) {
  return String(str ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

/** 왼쪽 견적 패널 갱신 */
async function refreshEstPanel() {
  try {
    const r = await fetch('/api/cart.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'get' }),
    });
    const d = await r.json();
    if (d.ok) renderEstPanel(d.cart.items || [], d.cart.total || 0);
  } catch {}
}

function renderEstPanel(items, total) {
  const list     = document.getElementById('est-item-list');
  const empty    = document.getElementById('est-empty');
  const totalRow = document.getElementById('est-total-row');
  const totalEl  = document.getElementById('est-total');
  const btn      = document.getElementById('btn-est-req');

  // 기존 품목 행 제거
  list.querySelectorAll('.est-item').forEach(el => el.remove());

  if (!items || items.length === 0) {
    empty.style.display = '';
    totalRow.style.display = 'none';
    btn.classList.add('disabled');
    return;
  }

  empty.style.display = 'none';
  totalRow.style.display = '';
  btn.classList.remove('disabled');

  items.forEach(item => {
    const row = document.createElement('div');
    row.className = 'est-item';

    const subtotal = new Intl.NumberFormat('ko-KR').format(item.subtotal);

    const thumbHtml = item.image_url
      ? `<img src="${escHtml(item.image_url)}" class="est-item-thumb" alt="">`
      : `<div class="est-item-thumb est-item-thumb-ph"></div>`;

    row.innerHTML = `
      <div class="est-item-top">
        ${thumbHtml}
        <div class="est-item-info">
          <div class="est-item-info-left">
            <div class="est-item-name">${escHtml(item.name)}</div>
            ${item.spec ? `<div class="est-item-spec">${escHtml(item.spec)}</div>` : ''}
          </div>
          <div class="est-item-info-right">
            <div class="est-item-qty-wrap">
              <input type="number" class="est-item-qty" value="${item.quantity}" min="1" max="999" data-pid="${item.product_id}">
              <button class="btn-est-remove" data-pid="${item.product_id}" title="삭제">×</button>
            </div>
            <span class="est-item-subtotal">₩${subtotal}</span>
          </div>
        </div>
      </div>
    `;

    row.querySelector('.est-item-qty').addEventListener('change', async (e) => {
      const qty = Math.max(1, Math.min(999, parseInt(e.target.value, 10) || 1));
      await cartAction('update', item.product_id, qty);
    });

    row.querySelector('.btn-est-remove').addEventListener('click', async () => {
      await cartAction('remove', item.product_id);
    });

    list.appendChild(row);
  });

  totalEl.textContent = '₩' + new Intl.NumberFormat('ko-KR').format(total);
}

async function cartAction(action, productId, quantity) {
  try {
    const body = { action, product_id: productId };
    if (quantity !== undefined) body.quantity = quantity;
    await fetch('/api/cart.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(body),
    });
    await refreshEstPanel();
  } catch {}
}

async function clearEstimate() {
  if (!confirm('견적서를 모두 비우시겠습니까?')) return;
  try {
    await fetch('/api/cart.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ action: 'clear' }),
    });
    await refreshEstPanel();
  } catch {}
}

/** POST /api/cart.php — 견적서에 담기 */
async function addToEstimate(productId, qty, btn) {
  btn.disabled    = true;
  btn.textContent = '담는 중…';
  try {
    const res  = await fetch('/api/cart.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ action: 'add', product_id: productId, quantity: qty }),
    });
    const data = await res.json();
    if (data.ok) {
      btn.textContent = '담김 ✓';
      btn.classList.add('added');
      await refreshEstPanel();
    } else {
      btn.disabled    = false;
      btn.textContent = '견적추가';
      alert(data.error || '오류가 발생했습니다.');
    }
  } catch {
    btn.disabled    = false;
    btn.textContent = '견적추가';
  }
}

/** product_cards 이벤트 → 비교 테이블 DOM */
function renderProductCards(products, container) {
  if (!products || products.length === 0) return;

  const wrap = document.createElement('div');
  wrap.className = 'compare-table';

  const rows = products.map(p => {
    const price = new Intl.NumberFormat('ko-KR').format(p.price_b2c);
    const imgHtml = p.image_url
      ? `<img class="compare-img" src="${escHtml(p.image_url)}" alt="" loading="lazy" onerror="this.className='compare-img-ph';this.removeAttribute('src')">`
      : `<div class="compare-img-ph"></div>`;
    const qtyVal = (p.suggested_qty && p.suggested_qty > 0) ? p.suggested_qty : 1;
    const basisHtml = p.qty_basis
      ? `<div class="compare-qtybasis">권장수량 ${p.suggested_qty}: ${escHtml(p.qty_basis)}</div>`
      : '';
    return `
      <tr data-pid="${p.id}">
        <td>${imgHtml}</td>
        <td>
          <div class="compare-name">${escHtml(p.name)}</div>
          ${p.spec ? `<div class="compare-spec">${escHtml(p.spec)}</div>` : ''}
          ${basisHtml}
        </td>
        <td class="compare-price">₩${price}</td>
        <td><input type="number" class="compare-qty" value="${qtyVal}" min="1" max="9999"></td>
        <td><button class="btn-compare-add">견적추가</button></td>
      </tr>`;
  }).join('');

  wrap.innerHTML = `
    <table>
      <thead><tr>
        <th style="width:68px"></th>
        <th>제품명 / 규격</th>
        <th>가격</th>
        <th style="width:54px">수량</th>
        <th></th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>`;

  wrap.querySelectorAll('tr[data-pid]').forEach(row => {
    const pid = parseInt(row.dataset.pid, 10);
    const btn = row.querySelector('.btn-compare-add');
    const qty = row.querySelector('.compare-qty');
    btn.addEventListener('click', () => {
      const q = Math.max(1, Math.min(9999, parseInt(qty.value, 10) || 1));
      addToEstimate(pid, q, btn);
    });
  });

  container.appendChild(wrap);
  document.getElementById('messages').scrollTop = 9999;
}

/** estimate_created 이벤트 → 견적 완료 카드 */
function renderEstimateCard(estimateId, container) {
  const card = document.createElement('div');
  card.className = 'estimate-card';
  card.innerHTML = `
    <div class="estimate-card-icon">✓</div>
    <div>
      <div class="estimate-card-title">견적 접수 완료</div>
      <div class="estimate-card-sub">견적번호 #${parseInt(estimateId, 10)} — 담당자가 연락드립니다.</div>
    </div>
  `;
  container.appendChild(card);
  document.getElementById('messages').scrollTop = 9999;
}

/** SSE 이벤트 처리 (tool 결과 렌더링) */
function processEvent(event, content) {
  switch (event.type) {
    case 'product_cards':
      renderProductCards(event.products, content);
      break;
    case 'cart_update':
      refreshEstPanel();
      break;
    case 'estimate_created':
      renderEstimateCard(event.estimate_id, content);
      refreshEstPanel();
      break;
  }
}

async function send() {
  if (isStreaming) return;
  const inp = document.getElementById('inp');
  const msg = inp.value.trim();
  if (!msg) return;

  inp.value = '';
  document.getElementById('btn-send').disabled = true;
  document.getElementById('hints').style.display = 'none';
  isStreaming = true;
  accText     = '';

  addMsg('user', msg);
  const { bubble, content } = addMsg('assistant', '');

  const cursor = document.createElement('span');
  cursor.className = 'cursor';
  bubble.appendChild(cursor);

  try {
    const controller = new AbortController();
    const tid = setTimeout(() => controller.abort(), 85000);

    const r = await fetch('/chat.php?ask=1', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ sid: window.SID, msg }),
      signal:  controller.signal,
    });
    clearTimeout(tid);

    if (!r.ok) throw new Error('HTTP ' + r.status);
    const data = await r.json();

    cursor.remove();

    if (!data.ok) {
      bubble.textContent = data.error || '오류가 발생했습니다. 다시 시도해 주세요.';
    } else {
      accText = data.text || '';
      bubble.textContent = formatChatText(accText);

      for (const event of (data.events || [])) {
        processEvent(event, content);
      }

      if (!accText && !(data.events || []).length) {
        bubble.textContent = '응답을 받지 못했습니다. 다시 시도해 주세요.';
      }
    }
  } catch (err) {
    cursor.remove();
    if (err.name === 'AbortError') {
      bubble.textContent = '응답 시간이 초과되었습니다. 더 짧게 질문해 보세요.';
    } else {
      bubble.textContent = '오류가 발생했습니다. 다시 시도해 주세요.';
    }
  }

  document.getElementById('messages').scrollTop = 9999;
  await finalize(msg, accText);
}

async function finalize(userMsg, assistantMsg) {
  if (assistantMsg) {
    try {
      await fetch('/chat.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ sid: window.SID, user: userMsg, assistant: assistantMsg }),
      });
    } catch {}
  }
  isStreaming = false;
  document.getElementById('btn-send').disabled = false;
  document.getElementById('inp').focus();
}

function resetChat() {
  if (!confirm('대화를 초기화할까요?')) return;
  location.reload();
}

// 페이지 로드 시 기존 견적 품목 반영
(async () => { await refreshEstPanel(); })();
