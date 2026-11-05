<?php
/**
 * 즉시견적 UI HTML/JS 렌더링 전용
 * 호출자: public/estimate-quick.php
 * 의존: $user (LoginUser), $categories (array)
 * 금지: DB 쿼리, API 호출
 */
require __DIR__ . '/layout.php';
render_header('즉시견적');
?>
<style>
  /* ── 즉시견적 레이아웃: main 오버라이드 ────────────────────── */
  main {
    padding: 0;
    height: calc(100dvh - var(--header-h) - var(--catbar-h));
    min-height: unset;
    display: flex;
    flex-direction: column;
    overflow: hidden;
  }

  /* ── 탭 ─────────────────────────────────────────────────── */
  .tabs{display:flex;border-bottom:1px solid var(--color-border);flex-shrink:0;}
  .tab-btn{padding:10px 18px;font-size:12px;font-family:var(--font);background:none;border:none;color:var(--color-text-secondary);cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-1px;}
  .tab-btn.active{color:var(--color-primary);border-bottom-color:var(--color-primary);}

  /* ── 콘텐츠 영역 ─────────────────────────────────────────── */
  .est-main{flex:1;overflow:hidden;display:flex;flex-direction:column;}
  .tab-content{display:none;padding:16px 20px;overflow-y:auto;}

  /* ── 3단계 칩 선택 흐름 ─────────────────────────────────── */
  .sel-steps{display:flex;flex-direction:column;gap:10px;}
  .step-row{display:flex;align-items:flex-start;gap:10px;}
  .step-label{display:flex;align-items:center;gap:5px;font-size:10px;font-weight:600;color:var(--color-text-secondary);letter-spacing:.3px;white-space:nowrap;padding-top:7px;min-width:48px;}
  .step-num{display:inline-flex;align-items:center;justify-content:center;width:15px;height:15px;background:var(--color-primary);color:#fff;font-size:8px;font-weight:700;border-radius:50%;flex-shrink:0;}
  .cat-chips{display:flex;flex-wrap:wrap;gap:5px;}
  .cat-chip{padding:5px 12px;border:1px solid var(--color-border);background:#fff;font-size:12px;font-family:var(--font);color:var(--color-text-primary);cursor:pointer;line-height:1.4;transition:border-color .1s,background .1s;}
  .cat-chip:hover{border-color:var(--color-primary);background:var(--color-primary);color:#fff;}
  .cat-chip.active{background:var(--color-primary);color:#fff;border-color:var(--color-primary);}
  input[type=number]{background:#EEEEEE;border:1px solid var(--color-border);color:var(--color-text-primary);padding:8px 10px;font-size:12px;font-family:var(--font);outline:none;width:72px;text-align:right;}
  input[type=number]:focus{border-color:var(--color-primary);}
  .btn-add{background:var(--color-primary);color:#fff;border:none;padding:8px 16px;font-size:12px;font-family:var(--font);cursor:pointer;margin-left:6px;}
  .btn-add:hover{background:#3A6BBF;}

  /* ── 메가 패널 ──────────────────────────────────────────── */
  .est-mega-cols{display:flex;flex-wrap:wrap;gap:0 20px;align-items:flex-start;}
  .est-mega-col{min-width:90px;margin-bottom:8px;}
  .est-mega-col-title{font-size:10px;font-weight:700;color:var(--color-text-secondary);letter-spacing:.3px;padding-bottom:5px;border-bottom:1px solid var(--color-border);margin-bottom:5px;white-space:nowrap;}
  .est-mega-item{display:block;font-size:12px;color:var(--color-text-primary);cursor:pointer;padding:3px 0;border:none;background:none;font-family:var(--font);text-align:left;white-space:nowrap;width:100%;}
  .est-mega-item:hover{color:var(--color-primary);}
  .est-mega-item.active{color:var(--color-primary);font-weight:600;}

  /* ── 상품 카드 (5열 그리드) ─────────────────────────────── */
  #prod-cards-list{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;}
  .prod-card{border:1px solid var(--color-border);background:#fff;display:flex;flex-direction:column;overflow:hidden;}
  .prod-card-img{width:100%;aspect-ratio:1;object-fit:cover;background:#f5f5f5;display:block;}
  .prod-card-no-img{width:100%;aspect-ratio:1;background:#f0f0f0;display:flex;align-items:center;justify-content:center;}
  .prod-card-no-img svg{opacity:.25;}
  .prod-card-body{padding:8px;display:flex;flex-direction:column;gap:4px;flex:1;}
  .prod-card-name{font-size:14px;font-weight:600;color:var(--color-text-primary);line-height:1.3;word-break:keep-all;}
  .prod-card-spec{font-size:13px;color:var(--color-text-secondary);line-height:1.3;}
  .prod-card-price{font-size:12px;color:var(--color-primary);font-weight:700;margin-top:2px;}
  .prod-card-was{display:block;font-size:11px;font-weight:400;color:#999;text-decoration:line-through;}
  .prod-card-sale{color:#E12020;}
  .prod-card-actions{display:flex;gap:4px;margin-top:auto;padding-top:6px;}
  .prod-card-qty{flex:1;min-width:0;text-align:right;background:#EEEEEE;border:1px solid var(--color-border);padding:4px 6px;font-size:11px;font-family:var(--font);}
  .btn-add-card{background:var(--color-primary);color:#fff;border:none;padding:4px 8px;font-size:11px;font-family:var(--font);cursor:pointer;white-space:nowrap;flex-shrink:0;}
  .btn-add-card:hover{background:#3A6BBF;}
  .prod-no-result{font-size:12px;color:var(--color-text-secondary);padding:6px 0;grid-column:1/-1;}

  /* ── 즐겨찾기/이력 카드 ──────────────────────────────────── */
  .list-card{border:1px solid var(--color-border);background:var(--color-surface);padding:12px;display:flex;justify-content:space-between;align-items:center;gap:8px;margin-bottom:8px;}
  .list-card-name{font-size:12px;font-weight:500;}
  .list-card-sub{font-size:11px;color:var(--color-text-secondary);margin-top:2px;}
  .btn-sm{font-size:11px;font-family:var(--font);padding:4px 10px;border:1px solid var(--color-border);background:none;color:var(--color-text-primary);cursor:pointer;}
  .btn-sm:hover{border-color:var(--color-primary);color:var(--color-primary);}
  .btn-sm.danger:hover{border-color:#C0392B;color:#C0392B;}

  /* ── 하단 품목 테이블 ────────────────────────────────────── */
  .est-footer{border-top:1px solid var(--color-border);background:var(--color-surface);padding:12px 20px;flex-shrink:0;}
  table{width:100%;border-collapse:collapse;font-size:12px;}
  th,td{padding:8px 10px;border-bottom:1px solid var(--color-border);text-align:left;}
  th{color:var(--color-text-secondary);font-weight:400;font-size:11px;}
  td:nth-child(3),td:nth-child(4),td:nth-child(5){text-align:right;}
  .qty-inp{width:64px;background:#EEEEEE;border:1px solid var(--color-border);color:var(--color-text-primary);padding:2px 6px;font-size:12px;font-family:var(--font);text-align:right;}
  .btn-remove{background:none;border:none;color:var(--color-text-secondary);cursor:pointer;font-size:14px;line-height:1;}
  .btn-remove:hover{color:#C0392B;}
  .total-row{display:flex;justify-content:flex-end;align-items:center;gap:16px;padding:10px 0 0;}
  .total-label{font-size:12px;color:var(--color-text-secondary);}
  .total-amount{font-size:16px;font-weight:600;color:var(--color-primary);}
  .action-row{display:flex;gap:8px;justify-content:flex-end;margin-top:10px;}
  .btn-clear{background:none;border:1px solid var(--color-border);color:var(--color-text-secondary);padding:8px 16px;font-size:12px;font-family:var(--font);cursor:pointer;}
  .btn-submit{background:var(--color-primary);color:#fff;border:none;padding:8px 20px;font-size:13px;font-family:var(--font);font-weight:500;cursor:pointer;}
  .btn-submit:disabled{opacity:.4;cursor:not-allowed;}
  .empty-msg{color:var(--color-text-secondary);font-size:12px;padding:12px 0;}

  /* ── 모바일(640px 이하) ─────────────────────────────────────
     화면 높이 고정 배치(위 선택 영역·아래 품목 표가 한 화면을 나눠 상품 카드가 잘림, 아래 빈 공간)
     → 위에서 아래로 이어지는 문서 흐름. 상품 카드 5열 → 2열, 품목 표 → 품목마다 카드, 터치 크기 키움 */
  @media (max-width: 640px) {
    main{height:auto;overflow:visible;display:block;}
    .est-main{overflow:visible;}
    .tab-content{padding:16px;overflow:visible;}
    .tab-btn{flex:1;min-height:44px;padding:0 8px;font-size:14px;}
    .step-row{flex-direction:column;gap:6px;}
    .step-label{padding-top:0;font-size:12px;}
    .cat-chips{gap:6px;}
    .cat-chip{min-height:36px;padding:0 12px;font-size:13px;}
    #prod-cards-list{grid-template-columns:repeat(2,minmax(0,1fr));}
    .prod-card-price{font-size:14px;}
    .prod-card-qty{height:36px;font-size:16px;}
    .btn-add-card{height:36px;padding:0 10px;font-size:13px;}
    .list-card-name{font-size:14px;}
    .list-card-sub{font-size:12px;}
    .btn-sm{min-height:36px;padding:0 12px;font-size:13px;}
    .est-footer{padding:12px 16px 20px;}
    #items-table,#items-table tbody{display:block;}
    #items-table thead{display:none;}
    #items-table tr{display:grid;grid-template-columns:auto minmax(0,1fr) 44px;grid-template-areas:"name name rm" "spec spec spec" "qty price price" "qty sub sub";align-items:center;gap:2px 12px;padding:12px 0;border-bottom:1px solid var(--color-border);}
    #items-table td{display:block;padding:0;border:0;min-width:0;text-align:left;}
    #items-table td:nth-child(1){grid-area:name;font-size:14px;font-weight:600;overflow-wrap:anywhere;}
    #items-table td:nth-child(2){grid-area:spec;font-size:12px;color:var(--color-text-secondary);overflow-wrap:anywhere;}
    #items-table td:nth-child(3){grid-area:qty;margin-top:6px;}
    #items-table td:nth-child(3)::before{content:'수량';margin-right:8px;font-size:12px;color:var(--color-text-secondary);}
    #items-table td:nth-child(4){grid-area:price;align-self:end;margin-top:6px;text-align:right;font-size:12px;color:var(--color-text-secondary);}
    #items-table td:nth-child(5){grid-area:sub;align-self:start;text-align:right;font-size:15px;font-weight:600;}
    #items-table td:nth-child(4)::before{content:'단가 ';}
    #items-table td:nth-child(5)::before{content:'소계 ';font-size:12px;font-weight:400;color:var(--color-text-secondary);}
    #items-table td:nth-child(6){grid-area:rm;justify-self:end;margin-right:-10px;}
    #items-table .qty-inp{width:64px;height:40px;font-size:16px;text-align:center;}
    .btn-remove{display:inline-flex;align-items:center;justify-content:center;width:44px;height:44px;}
    .total-label{font-size:13px;}
    .total-amount{font-size:18px;}
    .btn-clear,.btn-submit{min-height:44px;font-size:14px;}
    .btn-submit{flex:1;display:inline-flex;align-items:center;justify-content:center;gap:6px;}
  }
</style>

<div class="tabs">
  <button class="tab-btn active" data-tab="new" onclick="showTab('new')">신규 선택</button>
  <button class="tab-btn" data-tab="fav" onclick="showTab('fav')">단골품목</button>
  <button class="tab-btn" data-tab="hist" onclick="showTab('hist')">이전 견적서</button>
</div>

<div class="est-main">
  <!-- 탭1: 신규 선택 -->
  <div class="tab-content" id="tab-new" style="display:block">
    <div class="sel-steps">
      <div class="step-row">
        <div class="step-label">대분류</div>
        <div class="cat-chips" id="lv1-chips"></div>
      </div>
      <div class="step-row" id="lv2-row" style="display:none">
        <div class="step-label">중분류</div>
        <div class="cat-chips" id="lv2-chips"></div>
      </div>
      <div class="step-row" id="lv3-row" style="display:none">
        <div class="step-label">품목</div>
        <div class="cat-chips" id="lv3-chips"></div>
      </div>
    </div>
    <!-- 상품 카드 목록 -->
    <div id="prod-cards-row" style="display:none;margin-top:12px;padding-top:10px;border-top:1px dashed var(--color-border)">
      <div id="prod-cards-list"></div>
    </div>
  </div>

  <!-- 탭2: 단골품목 -->
  <div class="tab-content" id="tab-fav">
    <div id="fav-list"><p class="empty-msg">불러오는 중...</p></div>
  </div>

  <!-- 탭3: 이전 견적서 -->
  <div class="tab-content" id="tab-hist">
    <div id="hist-list"><p class="empty-msg">불러오는 중...</p></div>
  </div>
</div>

<!-- 선택 품목 + 합계 + 제출 -->
<div class="est-footer">
  <table id="items-table">
    <thead><tr><th>상품명</th><th>규격</th><th>수량</th><th>단가</th><th>소계</th><th></th></tr></thead>
    <tbody id="items-body"></tbody>
  </table>
  <div id="empty-items" class="empty-msg" style="text-align:center">선택된 품목이 없습니다.</div>
  <div class="total-row">
    <span class="total-label">합계</span>
    <span class="total-amount" id="total-amount">0원</span>
  </div>
  <div class="action-row">
    <button class="btn-clear" onclick="clearAll()">전체 초기화</button>
    <button class="btn-submit" id="btn-submit" onclick="goEstimate()" disabled>견적서 작성하기 <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg></button>
  </div>
</div>

<script>
// 오류는 200 + ok:false + error 로 온다(닷홈 4xx 본문 교체 회피). 본문이 JSON 이 아니면(서버 오류 페이지 등) {ok:false}
const API = {
  post: async (url, body) => {
    const r = await fetch(url, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
    return r.json().catch(() => ({ok:false}));
  },
  get: async (url) => { const r = await fetch(url); return r.json().catch(() => ({ok:false})); },
  del: async (url, body) => {
    const r = await fetch(url, {method:'DELETE', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
    return r.json().catch(() => ({ok:false}));
  }
};

async function quickItems(action, productId, qty) {
  const body = {action};
  if (productId) body.product_id = +productId;
  if (qty != null) body.quantity = +qty;
  const d = await API.post('/api/estimate/quick-items.php', body);
  if (d.ok) renderItems(d.items, d.total, d.display_total);
  else if (action !== 'get') alert(d.error || '요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.');
  return d;
}

function renderItems(items, total, displayTotal) {
  // 화면 금액은 기업회원가(API display_price·display_subtotal·display_total — 상품 상세와 같은 규칙).
  // unit_price·subtotal·total(기업가 컬럼 price_b2b 값)은 표시하지 않는다 — 견적 저장도 display 단가(app/estimate.php). 필드가 없으면(이전 API·재구매 응답) 기존 값
  items = items.map(it => it.display_price != null ? Object.assign({}, it, {unit_price: it.display_price, subtotal: it.display_subtotal}) : it);
  if (displayTotal != null) total = displayTotal;
  const tbody  = document.getElementById('items-body');
  const emptyEl = document.getElementById('empty-items');
  const submitBtn = document.getElementById('btn-submit');
  tbody.innerHTML = items.map(it => `
    <tr>
      <td>${escHtml(it.name)}</td>
      <td>${escHtml(it.size||'-')}</td>
      <td><input class="qty-inp" type="number" value="${it.quantity}" min="1" max="999"
        onchange="quickItems('update',${it.product_id},this.value)"></td>
      <td>${it.unit_price > 0 ? it.unit_price.toLocaleString() + '원' : '문의'}</td>
      <td>${it.unit_price > 0 ? it.subtotal.toLocaleString() + '원' : '문의'}</td>
      <td><button class="btn-remove" onclick="quickItems('remove',${it.product_id})"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button></td>
    </tr>`).join('');
  document.getElementById('total-amount').textContent = (total||0).toLocaleString() + '원';
  emptyEl.style.display  = items.length ? 'none' : 'block';
  submitBtn.disabled = !items.length;
}

function escHtml(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── 카테고리 3단계 계층 데이터 (DB → PHP → JS) ──────────────
const _catLv1      = <?= json_encode($catLv1,     JSON_UNESCAPED_UNICODE) ?>;
const _catByParent = <?= json_encode($catByParent, JSON_UNESCAPED_UNICODE) ?>;
let _selLv1 = 0, _selLv2 = 0, _selLv3 = 0;

function renderLv1() {
  document.getElementById('lv1-chips').innerHTML = _catLv1.map(c =>
    `<button class="cat-chip${c.id===_selLv1?' active':''}"
      onclick="selectLv1(${c.id})">${escHtml(c.name)}</button>`
  ).join('');
}
function renderLv2() {
  document.getElementById('lv2-chips').innerHTML = (_catByParent[_selLv1]||[]).map(c =>
    `<button class="cat-chip${c.id===_selLv2?' active':''}"
      onclick="selectLv2(${c.id})">${escHtml(c.name)}</button>`
  ).join('');
}
function renderLv3() {
  document.getElementById('lv3-chips').innerHTML = (_catByParent[_selLv2]||[]).map(c =>
    `<button class="cat-chip${c.id===_selLv3?' active':''}"
      onclick="selectLv3(${c.id})">${escHtml(c.name)}</button>`
  ).join('');
}

function selectLv1(id) {
  _selLv1 = id; _selLv2 = 0; _selLv3 = 0;
  renderLv1();
  const lv2 = _catByParent[id] || [];
  document.getElementById('lv2-row').style.display = lv2.length ? 'flex' : 'none';
  if (lv2.length) renderLv2();
  document.getElementById('lv3-row').style.display = 'none';
  resetProds();
}
function selectLv2(id) {
  _selLv2 = id; _selLv3 = 0;
  renderLv2();
  const lv3 = _catByParent[id] || [];
  document.getElementById('lv3-row').style.display = lv3.length ? 'flex' : 'none';
  if (lv3.length) renderLv3();
  resetProds();
  if (!lv3.length) loadProducts(id);
}
function selectLv3(id) {
  _selLv3 = id;
  renderLv3();
  loadProducts(id);
}

// ── 상품 카드 ─────────────────────────────────────────────────

function imgErr(el) {
  const d = document.createElement('div');
  d.className = 'prod-card-no-img';
  d.innerHTML = '<svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
  el.parentNode.replaceChild(d, el);
}

function resetProds() {
  document.getElementById('prod-cards-row').style.display = 'none';
  document.getElementById('prod-cards-list').innerHTML = '';
}

async function loadProducts(catId) {
  resetProds();
  document.getElementById('prod-cards-row').style.display = 'block';
  document.getElementById('prod-cards-list').innerHTML = '<p class="prod-no-result">로딩 중...</p>';
  const d = await API.get(`/api/products/by-category.php?category_id=${catId}`);
  if (d.ok && d.products.length) {
    const noImg = `<div class="prod-card-no-img"><svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#999" stroke-width="1.5"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg></div>`;
    document.getElementById('prod-cards-list').innerHTML = d.products.map(p => {
      // 단가: 기업회원가가 판매가보다 낮으면 [판매가(취소선)]·[기업회원가(빨강)] — 상품 목록과 같은 모양(price_list·price_member 없으면 기존 단가)
      const member = p.price_member != null ? p.price_member : (p.price_b2b || p.price_b2c);
      const priceHtml = !(member > 0) ? '견적문의'
        : (p.price_list > member ? `<del class="prod-card-was">${p.price_list.toLocaleString()}</del><span class="prod-card-sale">${member.toLocaleString()}원</span>` : `${member.toLocaleString()}원`);
      const imgHtml = p.image_url
        ? `<img src="${escHtml(p.image_url)}" class="prod-card-img" alt="" onerror="imgErr(this)">`
        : noImg;
      return `<div class="prod-card">
        ${imgHtml}
        <div class="prod-card-body">
          <div class="prod-card-name">${escHtml(p.name)}</div>
          ${p.size ? `<div class="prod-card-spec">${escHtml(p.size)}</div>` : ''}
          <div class="prod-card-price">${priceHtml}</div>
          <div class="prod-card-actions">
            <input type="number" class="prod-card-qty" value="1" min="1" max="999">
            <button class="btn-add-card" onclick="addProductCard(${p.id},this)">+ 추가</button>
          </div>
        </div>
      </div>`;
    }).join('');
  } else {
    document.getElementById('prod-cards-list').innerHTML = '<p class="prod-no-result">해당 상품이 없습니다.</p>';
  }
}

function addProductCard(pid, btn) {
  const qty = btn.previousElementSibling.value;
  quickItems('add', pid, qty);
  const orig = btn.textContent;
  btn.textContent = '✓ 추가됨'; btn.disabled = true;
  setTimeout(() => { btn.textContent = orig; btn.disabled = false; }, 900);
}

function clearAll() {
  if (!confirm('모든 품목을 초기화하겠습니까?')) return;
  quickItems('clear');
}

function goEstimate() { location.href = '/estimate.php'; }

async function loadFavorites() {
  const el = document.getElementById('fav-list');
  const d  = await API.get('/api/estimate/favorites.php');
  if (!d.ok || !d.favorites.length) { el.innerHTML = '<p class="empty-msg">등록된 단골품목이 없습니다.</p>'; return; }
  el.innerHTML = d.favorites.map(f => `
    <div class="list-card">
      <div><div class="list-card-name">${escHtml(f.name)}${f.size?` <span style="color:var(--color-text-secondary)">${escHtml(f.size)}</span>`:''}</div>
        <div class="list-card-sub">수량 ${f.quantity}개 · ${(f.price_member != null ? f.price_member : f.price_b2b) > 0 ? (f.price_member != null ? f.price_member : f.price_b2b).toLocaleString() + '원' : '견적문의'}</div></div>
      <div style="display:flex;gap:6px">
        <button class="btn-sm" onclick="quickItems('add',${f.product_id},${f.quantity}).then(()=>showTab('new'))">추가</button>
        <button class="btn-sm danger" onclick="removeFav(${f.product_id})">삭제</button>
      </div>
    </div>`).join('');
}

async function removeFav(pid) {
  const d = await API.del('/api/estimate/favorites.php', {product_id: pid});
  if (d.ok) loadFavorites();
  else alert(d.error || '삭제에 실패했습니다.');
}

async function loadHistory() {
  const el = document.getElementById('hist-list');
  const d  = await API.get('/api/mypage/delivery-history.php?limit=10');
  if (!d.ok || !d.items.length) { el.innerHTML = '<p class="empty-msg">납품 완료 이력이 없습니다.</p>'; return; }
  el.innerHTML = d.items.map(h => `
    <div class="list-card">
      <div><div class="list-card-name">${escHtml(h.products_summary)}</div>
        <div class="list-card-sub">납품일 ${h.delivery_completed_at||'미정'}</div></div>
      <button class="btn-sm" onclick="reorder(${h.estimate_id})">재구매 적재</button>
    </div>`).join('');
}

async function reorder(estimateId) {
  const d = await API.post('/api/estimate/reorder.php', {estimate_id: estimateId});
  if (d.ok) { renderItems(d.items, d.total); showTab('new'); alert(`${d.loaded_count}개 품목이 추가되었습니다.`); }
  else alert(d.error || '재구매 적재에 실패했습니다.');
}

function showTab(tab) {
  document.querySelectorAll('.tab-content').forEach(el => el.style.display = 'none');
  document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
  document.getElementById('tab-' + tab).style.display = 'block';
  document.querySelector(`.tab-btn[data-tab="${tab}"]`).classList.add('active');
  if (tab === 'fav')  loadFavorites();
  if (tab === 'hist') loadHistory();
}

window.addEventListener('DOMContentLoaded', () => {
  renderLv1();
  quickItems('get');
});
</script>
<?php render_footer(); ?>
