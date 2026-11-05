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

/**
 * 답변 글 형식 정리 — 말풍선은 글자 그대로 보여 주므로 마크다운 기호가 남지 않게.
 * 굵게(**…**)·제목(#) 기호 제거, 문장 끝 콜론('…제품입니다:')은 마침표로. 목록의 '제품명: 설명' 은 그대로.
 * 서버(app/rag.php rag_clean_answer_format)와 같은 규칙 — 이전 상담 복원(서버 정리 전 저장분)에도 적용.
 */
function cleanChatText(text) {
  return String(text ?? '')
    .replace(/\*\*([^*\n]+?)\*\*/g, '$1')
    .replace(/\*\*/g, '')
    .replace(/^[ \t]*#{1,6}[ \t]+/gm, '')
    .replace(/([다요죠])[ \t]*[:：][ \t]*$/gm, '$1.');
}

/** 첫 문장(~니다.) 뒤 줄 내림 */
function formatChatText(text) {
  if (!text) return '';
  // 첫 번째 "니다." 또는 "니다!" 뒤에 줄바꿈이 없으면 추가
  return cleanChatText(text).replace(/^(.*?니다[.!])\s*/, '$1\n\n');
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

    // 단가 0원 = 가격 문의 (견적 요청 페이지와 같은 규칙, 금액에서 제외)
    const subtotal = item.unit_price > 0 ? '₩' + new Intl.NumberFormat('ko-KR').format(item.subtotal) : '가격 문의';

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
            <span class="est-item-subtotal">${subtotal}</span>
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

  const hasInquiry = items.some(it => !(it.unit_price > 0));
  totalEl.textContent = total > 0
    ? '₩' + new Intl.NumberFormat('ko-KR').format(total) + (hasInquiry ? ' + 문의' : '')
    : '담당자 문의';
}

async function cartAction(action, productId, quantity) {
  try {
    const body = { action, product_id: productId };
    if (quantity !== undefined) body.quantity = quantity;
    const r = await fetch('/api/cart.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify(body),
    });
    // 오류는 200 + ok:false + error 로 온다. JSON 이 아니면(서버 오류 페이지 등) 일반 문구
    const d = await r.json().catch(() => null);
    if (!d || !d.ok) alert((d && d.error) || '변경한 내용을 저장하지 못했습니다.');
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
    // 서버에서 가격·판매 상태를 확인하지 못함(조회 실패 등) — 담기지 않았음을 안내
    alert('상품 가격·판매 상태를 확인하지 못해 담지 못했습니다. 잠시 후 다시 시도해 주세요.');
  }
}

/** 비교표 가격 칸: 현재가 대신 표시할 상태 문구 */
const PRICE_STATE_LABEL = {
  inquiry: '가격 문의',
  stopped: '판매 중지',
  changed: '판매 상품 변경',
  unknown: '가격 확인 불가',
};

/** product_cards 이벤트 → 비교 테이블 DOM */
function renderProductCards(products, container) {
  if (!products || products.length === 0) return;

  const wrap = document.createElement('div');
  wrap.className = 'compare-table';

  const fmt = n => new Intl.NumberFormat('ko-KR').format(n);
  const rows = products.map(p => {
    // 가격 상태: 이전 상담 복원 시 서버가 현재가 기준 price_state를 넣음. 실시간 상담은 0원만 '가격 문의'
    const state   = p.price_state || (Number(p.price_b2c) > 0 ? 'ok' : 'inquiry');
    const blocked = state === 'stopped' || state === 'changed';
    const price   = state === 'ok'
      ? '₩' + fmt(p.price_b2c)
      : (PRICE_STATE_LABEL[state] || PRICE_STATE_LABEL.unknown);
    // 단위·예상 비용은 판매가가 있는 상품만(가격 문의·판매 중지·변경 상품에는 금액을 보이지 않음)
    // 'AI 상담 답변 개선(After)' 시안: 예상 비용 한 줄만(최저 표시·최저와의 차이·요약 박스·권장수량 계산식은 표시하지 않음 — 수량·자재비 설명은 답변 글에서)
    const unitHtml = (state === 'ok' && p.unit) ? `<span class="compare-unit">/${escHtml(p.unit)}</span>` : '';
    const costHtml = (state === 'ok' && p.est_cost && p.est_cost > 0)
      ? `<div class="compare-cost">예상 비용 ₩${fmt(p.est_cost)}</div>`
      : '';
    const stateNote = state === 'changed'
      ? '<div class="compare-state-note">상담 이후 상품 규격이 바뀌어 담을 수 없습니다. 다시 상담해 주세요.</div>'
      : '';
    const detailUrl = `/product.php?id=${parseInt(p.id, 10)}`;
    // 사진·제품명 = 상품 상세 링크(새 창)
    const imgHtml = p.image_url
      ? `<a class="compare-img-link" href="${detailUrl}" target="_blank" rel="noopener" title="제품 상세 보기" tabindex="-1" aria-hidden="true"><img class="compare-img" src="${escHtml(p.image_url)}" alt="" loading="lazy" onerror="this.className='compare-img-ph';this.removeAttribute('src')"></a>`
      : `<div class="compare-img-ph"></div>`;
    // 수량은 1로 시작(권장 수량은 답변 글에서 안내) — 고객이 직접 바꿔 담는다
    return `
      <tr data-pid="${p.id}">
        <td>${imgHtml}</td>
        <td>
          <a class="compare-name" href="${detailUrl}" target="_blank" rel="noopener" title="제품 상세 보기">${escHtml(p.name)}</a>
          ${(p.spec_label || p.spec) ? `<div class="compare-spec">${escHtml(p.spec_label || p.spec)}</div>` : ''}
          ${stateNote}
        </td>
        <td class="compare-price${state === 'ok' ? '' : ' is-state'}">${price}${unitHtml}${costHtml}</td>
        <td><input type="number" class="compare-qty" value="1" min="1" max="999" aria-label="${escHtml(p.name)} 수량"${blocked ? ' disabled' : ''}></td>
        <td><button class="btn-compare-add"${blocked ? ' disabled' : ''}>견적추가</button></td>
      </tr>`;
  }).join('');

  wrap.innerHTML = `
    <table>
      <thead><tr>
        <th style="width:68px"></th>
        <th>제품명 / 규격</th>
        <th>가격</th>
        <th style="width:84px">수량</th>
        <th></th>
      </tr></thead>
      <tbody>${rows}</tbody>
    </table>`;

  wrap.querySelectorAll('tr[data-pid]').forEach(row => {
    const pid = parseInt(row.dataset.pid, 10);
    const btn = row.querySelector('.btn-compare-add');
    const qty = row.querySelector('.compare-qty');
    btn.addEventListener('click', () => {
      const q = Math.max(1, parseInt(qty.value, 10) || 1);   // 상한(999)은 장바구니 API 가 검증
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
      // 상품이 추천될 때마다 추천표 바로 아래 '상담원 연결 유도' (consult-request.js)
      if (event.products && event.products.length && typeof renderConsultCta === 'function') {
        renderConsultCta(content);
      }
      break;
    case 'cart_update':
      refreshEstPanel();
      break;
    case 'estimate_created':
      renderEstimateCard(event.estimate_id, content);
      refreshEstPanel();
      break;
    case 'spec_unavailable':
      renderSpecUnavailable(event, content);
      break;
    case 'legal_sources':
      renderLegalSources(event.sources, content);
      break;
    case 'material_sources':
      renderMaterialSources(event.sources, content);
      break;
    case 'basic_sources':
      renderBasicSources(event, content);
      break;
  }
}

/** spec_unavailable 이벤트 → 기준 두께 적합 상품 미등록 안내(대체품 없음, 설계도서 확인·별도 견적) */
function renderSpecUnavailable(event, container) {
  const mm = parseInt(event.spec_mm, 10) || 0;
  const wrap = document.createElement('div');
  wrap.className = 'spec-unavailable';
  wrap.innerHTML = `<div class="spec-unavailable-title">두께 ${mm}mm 포장 블록 — 현재 등록된 적합 상품이 없습니다</div>
    <div class="spec-unavailable-body">${escHtml(event.item || '')} · 기준보다 얇은 제품은 대체로 추천하지 않습니다.<br>발주처 설계도서·시방서의 블록 규격(두께·크기·수량)을 확인해 주세요. 해당 규격은 별도 견적이 필요합니다.</div>`;
  container.appendChild(wrap);
}

/** material_sources 이벤트 → 설명에 쓴 자재 참고 자료(제조사 공개 자료, 승인분)의 출처 — 판매 상품·가격 아님 */
function renderMaterialSources(sources, container) {
  if (!sources || sources.length === 0) return;
  const wrap = document.createElement('div');
  wrap.className = 'legal-sources material-sources';
  const items = sources.slice(0, 5).map(s => {
    const url = /^https?:\/\//.test(s.url || '') ? s.url : '';
    const cat = /^catalog:.*#p(\d+)$/.exec(s.url || '');   // 허락받은 제조사 카탈로그(scripts/external/catalog-extract.php) — 링크 없이 쪽만
    return `<li>${escHtml(s.maker || '')}${url ? ` · <a href="${escHtml(url)}" target="_blank" rel="noopener noreferrer">원문 보기</a>` : ''}${cat ? ` · 제조사 카탈로그 ${escHtml(cat[1])}쪽` : ''}</li>`;
  }).join('');
  wrap.innerHTML = `<div class="legal-sources-title">참고 자료 출처 (제조사 자료 · 판매 상품 아님)</div><ul>${items}</ul>`;
  container.appendChild(wrap);
}

/** basic_sources 이벤트 → 답에 쓴 확인된 기본 정보(국가표준 번호·이름, 토목몰 등록 상품) — 제조사 자료·법령과 구분 */
function renderBasicSources(ev, container) {
  const st = (ev.standards || []).slice(0, 3);
  const pr = (ev.products || []).slice(0, 5);
  const pc = (ev.procurement || []).slice(0, 2);   // 공공조달 등록 현황(조달청 공공데이터 집계) — 다른 회사 품목, 토목몰 상품 인증 아님
  if (!st.length && !pr.length && !pc.length) return;
  const wrap = document.createElement('div');
  wrap.className = 'legal-sources basic-sources';
  const items = st.map(s => {
    const url = /^https?:\/\//.test(s.url || '') ? s.url : '';
    return `<li>${escHtml(s.ref || '')} ${escHtml(s.name || '')} · 국가표준 목록(표준 번호·이름 확인)${url ? ` · <a href="${escHtml(url)}" target="_blank" rel="noopener noreferrer">표준 정보</a>` : ''}</li>`;
  }).concat(pr.map(p => `<li>${escHtml(p.name || '')} · 토목몰 등록 상품${p.count ? ' ' + (parseInt(p.count, 10) || 0) + '개 규격' : ''}</li>`))
    .concat(pc.map(c => `<li>${escHtml(c.name || '')} · 조달청 나라장터 쇼핑몰 품목 등록 내역 ${(parseInt(c.items, 10) || 0).toLocaleString('ko-KR')}개 품목${c.as_of ? ' (' + escHtml(c.as_of) + ' 기준)' : ''} · <a href="https://www.data.go.kr/data/15142663/fileData.do" target="_blank" rel="noopener noreferrer">공공데이터</a></li>`)).join('');
  const kinds = [st.length ? '국가표준 목록' : '', pr.length ? '토목몰 등록 상품' : '', pc.length ? '공공조달 등록 현황' : ''].filter(Boolean).join(' · ');
  wrap.innerHTML = `<div class="legal-sources-title">확인된 기본 정보 (${kinds})</div><ul>${items}</ul>`;
  container.appendChild(wrap);
}

/** legal_sources 이벤트 → 답변이 실제로 인용한 공식 법령 근거(기준명·조항·시행일·적용 조건·원문 링크) */
function renderLegalSources(sources, container) {
  if (!sources || sources.length === 0) return;
  const levelLabel = { original_verified: '공식 원문 검증', api_text_verified: '법제처 원문 조문 확인' };
  const wrap = document.createElement('div');
  wrap.className = 'legal-sources';
  const items = sources.map(s => {
    const url = /^https?:\/\//.test(s.url || '') ? s.url : '';
    return `
      <li class="legal-source">
        <div class="legal-source-head">
          <span class="legal-badge">법령</span>
          <strong>${escHtml(s.name)} ${escHtml(s.ref)}</strong>
        </div>
        <div class="legal-source-meta">${s.effective_date ? '시행 ' + escHtml(s.effective_date) + ' · ' : ''}${escHtml(levelLabel[s.verify_level] || '')}${url ? ` · <a href="${escHtml(url)}" target="_blank" rel="noopener">원문 보기</a>` : ''}</div>
        <div class="legal-source-cond">적용 조건: ${escHtml(s.applies_to)}</div>
      </li>`;
  }).join('');
  wrap.innerHTML = `<div class="legal-sources-title">공식 법령 근거 ${sources.length}건</div><ul>${items}</ul>`;
  container.appendChild(wrap);
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
  let hasEvents = false;

  addMsg('user', msg);
  // 대화가 시작되면 '새 상담' 버튼 활성화
  const btnNew = document.getElementById('btn-new-chat');
  if (btnNew) btnNew.disabled = false;
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
      hasEvents = (data.events || []).length > 0;

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
  await finalize(msg, accText, hasEvents);
}

async function finalize(userMsg, assistantMsg, hasEvents) {
  // 텍스트 없이 상품 추천표·견적 카드만 온 답변도 저장 (카드 내용은 서버 chat_logs에 기록됨)
  if (assistantMsg || hasEvents) {
    try {
      await fetch('/chat.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ sid: window.SID, user: userMsg, assistant: assistantMsg, has_events: !!hasEvents }),
      });
    } catch {}
  }
  isStreaming = false;
  document.getElementById('btn-send').disabled = false;
  document.getElementById('inp').focus();
}

function resetChat() {
  if (!confirm('대화를 초기화할까요?')) return;
  // 새 상담 키를 받도록 ?sid= 없이 다시 열기 (복원한 이전 상담에서도 새 상담 시작)
  location.href = '/chat.php';
}

// 페이지 로드 시 기존 견적 품목 반영
(async () => { await refreshEstPanel(); })();
