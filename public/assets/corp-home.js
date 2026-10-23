// 기업회원 메인화면(빠른 견적하기) — app/corp_home_renderer.php 에서만 로드
// 데이터: #ch-data(JSON: 카테고리 트리·단골품목·이전 견적서)
// API: GET /api/products/by-category.php, POST /api/estimate/quick-items.php, POST /api/estimate/reorder.php
(function () {
  'use strict';

  var dataEl = document.getElementById('ch-data');
  if (!dataEl) return;
  var DATA = {};
  try { DATA = JSON.parse(dataEl.textContent || '{}'); } catch (e) { DATA = {}; }

  var PROD_PER_PAGE = 10, FAV_PER_PAGE = 10, CART_PER_PAGE = 5;
  var HISTORY_FIRST = 2, HISTORY_STEP = 5;
  var QTY_MAX = 9999; // api/estimate/quick-items.php 수량 상한과 동일

  var byParent = DATA.byParent || {};
  var regulars = Array.isArray(DATA.regulars) ? DATA.regulars : [];
  var history  = Array.isArray(DATA.history) ? DATA.history : [];

  var $ = function (id) { return document.getElementById(id); };
  var live = $('ch-live');

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function won(n) { return Number(n || 0).toLocaleString('ko-KR'); }
  function announce(msg) { if (live) { live.textContent = ''; setTimeout(function () { live.textContent = msg; }, 30); } }
  // 수량 입력값 → 1~QTY_MAX 정수, 아니면 null
  function toQty(v) {
    var s = String(v == null ? '' : v).replace(/[^0-9]/g, '');
    if (s === '') return null;
    var n = parseInt(s, 10);
    return n >= 1 && n <= QTY_MAX ? n : null;
  }
  function natCmp(a, b) { return String(a).localeCompare(String(b), 'ko', { numeric: true }); }

  var ICON_X = '<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"></path></svg>';
  var ICON_PREV = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"></path></svg>';
  var ICON_NEXT = '<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"></path></svg>';

  function thumbHtml(url) {
    return url
      ? '<img class="ch-thumb" src="' + esc(url) + '" alt="" loading="lazy">'
      : '<span class="ch-thumb ch-thumb--ph">이미지 없음</span>';
  }
  function priceHtml(price) {
    return price > 0 ? esc(won(price)) + '원' : '<span class="ch-price--ask">견적문의</span>';
  }

  // ── 페이지 이동 ─────────────────────────────────────────────
  function renderPager(nav, page, pages, onGo) {
    if (pages <= 1) { nav.innerHTML = ''; return; }
    var start = Math.max(1, Math.min(page - 2, pages - 4));
    var end = Math.min(pages, start + 4);
    var h = '<button type="button" class="ch-page ch-page--nav" data-go="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>' + ICON_PREV + '이전</button>';
    for (var i = start; i <= end; i++) {
      h += '<button type="button" class="ch-page ch-page--num" data-go="' + i + '"' + (i === page ? ' aria-current="page"' : '') + ' aria-label="' + i + '페이지">' + i + '</button>';
    }
    h += '<button type="button" class="ch-page ch-page--nav" data-go="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>다음' + ICON_NEXT + '</button>';
    nav.innerHTML = h;
    nav.onclick = function (e) {
      var b = e.target.closest('[data-go]');
      if (!b || b.disabled || b.hasAttribute('aria-current')) return;
      onGo(parseInt(b.getAttribute('data-go'), 10));
    };
  }

  // ── 탭(신규 선택 / 단골품목) ────────────────────────────────
  var tabs = [$('ch-tab-new'), $('ch-tab-fav')];
  function showTab(idx, focus) {
    tabs.forEach(function (t, i) {
      var on = i === idx;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      $(t.getAttribute('aria-controls')).hidden = !on;
    });
    if (focus) tabs[idx].focus();
  }
  tabs.forEach(function (t, i) {
    t.addEventListener('click', function () { showTab(i, false); });
    t.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowRight' || e.key === 'ArrowLeft') {
        e.preventDefault();
        showTab((i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length, true);
      }
    });
  });

  // ── 분류 선택(대분류 → 중분류 → 품목) ────────────────────────
  var sel1 = $('ch-sel1'), sel2 = $('ch-sel2'), sel3 = $('ch-sel3');
  function fillSelect(sel, list, emptyText) {
    if (!list.length) {
      sel.innerHTML = '<option value="">' + esc(emptyText) + '</option>';
      sel.disabled = true;
      return;
    }
    sel.innerHTML = list.map(function (c) { return '<option value="' + c.id + '">' + esc(c.name) + '</option>'; }).join('');
    sel.disabled = false;
  }
  function onLv1() {
    fillSelect(sel2, byParent[sel1.value] || [], '중분류 없음');
    onLv2();
  }
  function onLv2() {
    var subs = byParent[sel2.value] || [];
    fillSelect(sel3, subs, sel2.value ? '세부 품목 없음' : '품목 없음');
    // 세부 품목이 없는 중분류(예: 1련암거)는 중분류 자체의 상품을 보여준다
    loadProducts(subs.length ? sel3.value : sel2.value);
  }
  sel1.addEventListener('change', onLv1);
  sel2.addEventListener('change', onLv2);
  sel3.addEventListener('change', function () { loadProducts(sel3.value); });

  // ── 규격별 상품 + 규격 필터 ─────────────────────────────────
  var products = [], filtered = [], prodPage = 1, fltDims = [], loadSeq = 0;
  var prodBody = $('ch-prod-body'), prodCount = $('ch-prod-count');
  var fltToggle = $('ch-flt-toggle'), fltBox = $('ch-flt'), fltGrid = $('ch-flt-grid');

  // 규격 치수 분해: '200×150×2000×35T_국산' → dims ['200','150','2000','35T'], opt '국산'
  function parseSize(size) {
    var s = String(size || '');
    var u = s.indexOf('_');
    var base = u >= 0 ? s.slice(0, u) : s;
    return {
      dims: base.split('×').map(function (x) { return x.trim(); }).filter(Boolean),
      opt: u >= 0 ? s.slice(u + 1).trim() : ''
    };
  }

  // 실제 상품 규격에서 필터 항목 생성(값이 2개 이상인 치수만). 폭·높이·길이·두께 명칭은
  // 모든 상품이 '숫자×숫자×숫자×숫자T' 형식(플륨관·수로관류)일 때만 붙이고, 그 외는 '치수 n'.
  function buildFilters() {
    var parsed = products.map(function (p) { return parseSize(p.size); });
    var n = parsed.reduce(function (m, x) { return Math.max(m, x.dims.length); }, 0);
    var whlt = parsed.length > 0 && parsed.every(function (x) { return x.dims.length === 4 && /^\d[\d.]*T$/i.test(x.dims[3]); });
    var names = ['폭 (mm)', '높이 (mm)', '길이 (mm)', '두께 (T)'];
    fltDims = [];
    for (var i = 0; i < n; i++) {
      var vals = {};
      parsed.forEach(function (x) { if (x.dims[i]) vals[x.dims[i]] = 1; });
      var list = Object.keys(vals).sort(natCmp);
      if (list.length < 2) continue;
      var allD = list.every(function (v) { return /^D/i.test(v); });
      var allT = list.every(function (v) { return /T$/i.test(v); });
      var label = whlt ? names[i] : allD ? '관경 (D)' : allT ? '두께 (T)' : '치수 ' + (i + 1);
      fltDims.push({ key: 'd' + i, label: label, values: list, pick: (function (idx) { return function (x) { return x.dims[idx] || ''; }; })(i) });
    }
    var opts = {};
    parsed.forEach(function (x) { if (x.opt) opts[x.opt] = 1; });
    var optList = Object.keys(opts).sort(natCmp);
    if (optList.length >= 2 || (optList.length === 1 && parsed.some(function (x) { return !x.opt; }))) {
      fltDims.push({ key: 'opt', label: '옵션', values: optList, pick: function (x) { return x.opt; } });
    }
    fltGrid.innerHTML = fltDims.map(function (f) {
      var id = 'ch-flt-' + f.key;
      return '<div class="ch-field"><label for="' + id + '">' + esc(f.label) + '</label><div class="ch-select">' +
        '<select id="' + id + '" data-flt="' + f.key + '"><option value="">전체</option>' +
        f.values.map(function (v) { return '<option value="' + esc(v) + '">' + esc(v) + '</option>'; }).join('') +
        '</select><svg class="ch-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg></div></div>';
    }).join('');
    fltToggle.hidden = fltDims.length === 0;
    fltBox.hidden = fltDims.length === 0 || fltToggle.getAttribute('aria-expanded') !== 'true';
  }

  function applyFilters() {
    var conds = fltDims.map(function (f) {
      var el = fltGrid.querySelector('[data-flt="' + f.key + '"]');
      return { f: f, v: el ? el.value : '' };
    }).filter(function (c) { return c.v !== ''; });
    filtered = products.filter(function (p) {
      var x = parseSize(p.size);
      return conds.every(function (c) { return c.f.pick(x) === c.v; });
    });
    prodPage = 1;
    renderProducts();
  }

  fltToggle.addEventListener('click', function () {
    var open = fltToggle.getAttribute('aria-expanded') !== 'true';
    fltToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    $('ch-flt-chevron').setAttribute('d', open ? 'M6 15l6-6 6 6' : 'M6 9l6 6 6-6');
    fltBox.hidden = !open;
  });
  $('ch-flt-apply').addEventListener('click', function () {
    applyFilters();
    announce('규격 필터를 적용했습니다. ' + filtered.length + '개 상품');
  });
  $('ch-flt-reset').addEventListener('click', function () {
    fltGrid.querySelectorAll('select').forEach(function (s) { s.value = ''; });
    applyFilters();
    announce('규격 필터를 초기화했습니다.');
  });

  function qtyCell(pid, qty, label) {
    return '<input class="ch-qty" type="text" inputmode="numeric" maxlength="4" value="' + qty + '" data-qty="' + pid + '" aria-label="' + esc(label) + ' 수량">';
  }
  function addCell(pid, label) {
    return '<button type="button" class="ch-btn-add" data-add="' + pid + '" aria-label="' + esc(label) + ' 담기">+ 담기</button>';
  }

  function renderProducts() {
    prodCount.textContent = '총 ' + filtered.length + '개';
    var pages = Math.max(1, Math.ceil(filtered.length / PROD_PER_PAGE));
    prodPage = Math.min(Math.max(1, prodPage), pages);
    var rows = filtered.slice((prodPage - 1) * PROD_PER_PAGE, prodPage * PROD_PER_PAGE);
    prodBody.innerHTML = rows.length ? rows.map(function (p) {
      var label = p.name + (p.size ? ' ' + p.size : '');
      return '<tr>' +
        '<td>' + thumbHtml(p.image_url) + '</td>' +
        '<td class="ch-name-cell"><div class="ch-name" title="' + esc(p.name) + '">' + esc(p.name) + '</div><div class="ch-spec" title="' + esc(p.size) + '">' + esc(p.size || '-') + '</div></td>' +
        '<td class="ch-unit">' + esc(p.unit || '-') + '</td>' +
        '<td class="ch-price">' + priceHtml(p.price) + '</td>' +
        '<td>' + qtyCell(p.id, 1, label) + '</td>' +
        '<td>' + addCell(p.id, label) + '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="6" class="ch-empty">' + (products.length ? '조건에 맞는 상품이 없습니다. 규격 필터를 바꿔 보세요.' : '등록된 상품이 없습니다.') + '</td></tr>';
    renderPager($('ch-prod-pager'), prodPage, pages, function (p) { prodPage = p; renderProducts(); });
  }

  function loadProducts(catId) {
    var seq = ++loadSeq;
    products = []; filtered = [];
    prodCount.textContent = '총 0개';
    $('ch-prod-pager').innerHTML = '';
    if (!catId) {
      buildFilters();
      prodBody.innerHTML = '<tr><td colspan="6" class="ch-empty">품목을 선택해 주세요.</td></tr>';
      return;
    }
    prodBody.innerHTML = '<tr><td colspan="6" class="ch-empty">불러오는 중...</td></tr>';
    fetch('/api/products/by-category.php?category_id=' + encodeURIComponent(catId))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (seq !== loadSeq) return; // 그 사이 다른 품목을 선택함
        if (!d || !d.ok) throw new Error((d && d.error) || 'load');
        products = (d.products || []).map(function (p) {
          return {
            id: p.id, name: p.name, size: p.size || '', unit: p.unit || '',
            price: p.price_b2b != null ? p.price_b2b : p.price_b2c, image_url: p.image_url || ''
          };
        });
        buildFilters();
        applyFilters();
      })
      .catch(function () {
        if (seq !== loadSeq) return;
        buildFilters();
        prodBody.innerHTML = '<tr><td colspan="6" class="ch-empty">상품을 불러오지 못했습니다. 잠시 후 다시 시도해 주세요.</td></tr>';
      });
  }

  // ── 단골품목 ───────────────────────────────────────────────
  var favPage = 1;
  function renderFavorites() {
    $('ch-fav-count').textContent = '총 ' + regulars.length + '개';
    var pages = Math.max(1, Math.ceil(regulars.length / FAV_PER_PAGE));
    favPage = Math.min(Math.max(1, favPage), pages);
    var rows = regulars.slice((favPage - 1) * FAV_PER_PAGE, favPage * FAV_PER_PAGE);
    $('ch-fav-body').innerHTML = rows.length ? rows.map(function (p) {
      var label = p.name + (p.size ? ' ' + p.size : '');
      return '<tr>' +
        '<td>' + thumbHtml(p.image_url) + '</td>' +
        '<td class="ch-name-cell"><div class="ch-name" title="' + esc(p.name) + '">' + esc(p.name) + '</div><div class="ch-spec" title="' + esc(p.size) + '">' + esc(p.size || '-') + '</div></td>' +
        '<td class="ch-unit">' + esc(p.unit || '-') + '</td>' +
        '<td class="ch-price">' + priceHtml(p.price) + '</td>' +
        '<td>' + esc(p.buy_count) + '회</td>' +
        '<td>' + esc(p.last_buy || '-') + '</td>' +
        '<td>' + qtyCell(p.product_id, p.quantity || 1, label) + '</td>' +
        '<td>' + addCell(p.product_id, label) + '</td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="8" class="ch-empty">등록된 단골품목이 없습니다. 상품 목록·상세에서 관심상품으로 추가하면 여기에 표시됩니다.</td></tr>';
    renderPager($('ch-fav-pager'), favPage, pages, function (p) { favPage = p; renderFavorites(); });
  }

  // ── 담은 품목(즉시견적 세션) ─────────────────────────────────
  var cart = [], cartPage = 1;
  var pendingQty = {}, saveChain = {}; // 담기: 저장 대기 중인 누적 수량(상품별)·저장 순서 보장용
  var cartBody = $('ch-cart-body'), submitBtn = $('ch-submit');

  function quickItems(body) {
    return fetch('/api/estimate/quick-items.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(body)
    }).then(function (r) {
      return r.json().catch(function () { return { ok: false }; }).then(function (d) {
        if (d && d.ok) { cart = d.items || []; renderCart(); return d; }
        throw new Error((d && d.error) || '요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.');
      });
    });
  }

  function renderCart() {
    // 저장 대기 중인 수량을 화면에 먼저 반영(서버 응답 후 같은 값으로 확정됨)
    var view = cart.map(function (it) {
      var q = pendingQty[it.product_id];
      return q != null ? Object.assign({}, it, { quantity: q, subtotal: it.unit_price * q }) : it;
    });
    var viewTotal = view.reduce(function (sum, it) { return sum + (it.subtotal || 0); }, 0);
    var n = view.length;
    $('ch-cart-count').textContent = '총 ' + n + '개';
    $('ch-sum-basis').textContent = '총 ' + n + '개 품목 기준';
    var hasAsk = view.some(function (it) { return !(it.unit_price > 0); });
    $('ch-sum-total').textContent = won(viewTotal) + '원' + (hasAsk ? ' + 문의' : '');
    submitBtn.setAttribute('aria-disabled', n ? 'false' : 'true');

    var pages = Math.max(1, Math.ceil(n / CART_PER_PAGE));
    cartPage = Math.min(Math.max(1, cartPage), pages);
    var rows = view.slice((cartPage - 1) * CART_PER_PAGE, cartPage * CART_PER_PAGE);
    cartBody.innerHTML = rows.length ? rows.map(function (it) {
      var size = it.size || '';
      var label = it.name + (size ? ' ' + size : '');
      return '<tr data-cart-row="' + it.product_id + '">' +
        '<td class="t-left"><div class="ch-cart-item">' + thumbHtml(it.image_url) +
          '<div><div class="ch-name" title="' + esc(it.name) + '">' + esc(it.name) + '</div><div class="ch-spec" title="' + esc(size) + '">' + esc(size || '-') + '</div></div></div></td>' +
        '<td><input class="ch-qty" type="text" inputmode="numeric" maxlength="4" value="' + it.quantity + '" data-cart-qty="' + it.product_id + '" data-prev="' + it.quantity + '" aria-label="' + esc(label) + ' 수량"></td>' +
        '<td class="ch-amt">' + (it.unit_price > 0 ? esc(won(it.subtotal)) + '원' : '문의') + '</td>' +
        '<td><button type="button" class="ch-btn-del" data-del="' + it.product_id + '" aria-label="' + esc(label) + ' 삭제">' + ICON_X + '</button></td>' +
      '</tr>';
    }).join('') : '<tr><td colspan="4" class="ch-empty">담은 품목이 없습니다.<br>왼쪽 목록에서 수량을 입력하고 담아 주세요.</td></tr>';
    renderPager($('ch-cart-pager'), cartPage, pages, function (p) { cartPage = p; renderCart(); });
  }

  // 방금 담은 품목이 있는 페이지로 이동해 행을 잠시 강조
  function showCartItem(pid) {
    var idx = -1;
    cart.forEach(function (it, i) { if (it.product_id === pid) idx = i; });
    if (idx < 0) return;
    var page = Math.floor(idx / CART_PER_PAGE) + 1;
    if (page !== cartPage) { cartPage = page; renderCart(); }
    var row = cartBody.querySelector('[data-cart-row="' + pid + '"]');
    if (row) {
      row.classList.remove('is-added');
      void row.offsetWidth; // 연속 클릭 시 강조 다시 시작
      row.classList.add('is-added');
    }
  }

  // 숫자만 입력(최대 4자리)
  document.querySelector('.ch').addEventListener('input', function (e) {
    var t = e.target;
    if (t.classList && t.classList.contains('ch-qty')) {
      var v = t.value.replace(/[^0-9]/g, '').slice(0, 4);
      if (v !== t.value) t.value = v;
    }
  });

  // + 담기 (신규 선택·단골품목 공통)
  function onAddClick(e) {
    var btn = e.target.closest('[data-add]');
    if (!btn) return;
    var pid = parseInt(btn.getAttribute('data-add'), 10);
    var input = btn.closest('tr').querySelector('[data-qty]');
    var qty = toQty(input && input.value);
    if (qty === null) {
      alert('수량은 1~' + won(QTY_MAX) + ' 사이의 숫자로 입력해 주세요.');
      if (input) input.focus();
      return;
    }
    // 클릭마다 기존 수량에 입력 수량을 더한다(기본 1 → 클릭마다 +1). 화면 수량은 즉시 올리고,
    // 서버 저장은 상품별로 순서대로 보낸다(빠르게 여러 번 눌러도 누락 없음).
    // quick-items.php 의 add 는 수량을 '교체'하므로(기존 즉시견적 페이지 호환) 누적 합계를 update 로 보낸다.
    var inCart = cart.filter(function (it) { return it.product_id === pid; })[0];
    var base = pendingQty[pid] != null ? pendingQty[pid] : (inCart ? inCart.quantity : 0);
    var total = base + qty;
    if (total > QTY_MAX) {
      alert('담은 수량이 최대 ' + won(QTY_MAX) + '개를 넘습니다. (현재 ' + won(base) + '개 담김)');
      return;
    }
    pendingQty[pid] = total;
    if (inCart) { renderCart(); showCartItem(pid); }
    announce('담은 품목 수량 ' + won(total) + '개');
    saveChain[pid] = (saveChain[pid] || Promise.resolve())
      .then(function () {
        if (pendingQty[pid] !== total) return; // 그 사이 더 눌렀으면 마지막 합계만 저장
        return quickItems({ action: 'update', product_id: pid, quantity: total }).then(function () {
          if (pendingQty[pid] === total) { delete pendingQty[pid]; renderCart(); showCartItem(pid); }
        });
      })
      .catch(function (err) {
        delete pendingQty[pid];
        renderCart();
        alert(err.message);
      });
  }
  prodBody.addEventListener('click', onAddClick);
  $('ch-fav-body').addEventListener('click', onAddClick);

  // 담은 품목 수량 변경·삭제
  cartBody.addEventListener('change', function (e) {
    var t = e.target;
    if (!t.hasAttribute('data-cart-qty')) return;
    var qty = toQty(t.value);
    if (qty === null) {
      alert('수량은 1~' + won(QTY_MAX) + ' 사이의 숫자로 입력해 주세요. 품목을 빼려면 X 버튼을 누르세요.');
      t.value = t.getAttribute('data-prev');
      return;
    }
    delete pendingQty[parseInt(t.getAttribute('data-cart-qty'), 10)];
    quickItems({ action: 'update', product_id: parseInt(t.getAttribute('data-cart-qty'), 10), quantity: qty })
      .catch(function (err) { t.value = t.getAttribute('data-prev'); alert(err.message); });
  });
  cartBody.addEventListener('click', function (e) {
    var b = e.target.closest('[data-del]');
    if (!b) return;
    b.disabled = true;
    delete pendingQty[parseInt(b.getAttribute('data-del'), 10)];
    quickItems({ action: 'remove', product_id: parseInt(b.getAttribute('data-del'), 10) })
      .then(function () { announce('담은 품목에서 삭제했습니다.'); })
      .catch(function (err) { b.disabled = false; alert(err.message); });
  });

  submitBtn.addEventListener('click', function (e) {
    if (!cart.length) {
      e.preventDefault();
      announce('담은 품목이 없습니다. 상품을 먼저 담아 주세요.');
      alert('담은 품목이 없습니다. 상품을 먼저 담아 주세요.');
    }
  });

  // ── 이전 견적서 ────────────────────────────────────────────
  var historyShown = HISTORY_FIRST;
  function renderHistory() {
    var list = $('ch-history-list');
    var rows = history.slice(0, historyShown);
    list.innerHTML = rows.length ? rows.map(function (h) {
      return '<li class="ch-history-item"><div>' +
        '<div class="ch-history-name">' + esc(h.date) + ' · ' + esc(h.title) + '</div>' +
        '<div class="ch-history-sub">' + esc(h.summary) + '</div></div>' +
        '<button type="button" class="ch-btn-load" data-load="' + h.estimate_id + '"' + (h.item_count > 0 ? '' : ' disabled') +
        ' aria-label="' + esc(h.date + ' ' + h.title) + ' 불러오기">불러오기</button></li>';
    }).join('') : '<li class="ch-history-empty">이전 견적서가 없습니다.</li>';
    $('ch-history-more').hidden = history.length <= historyShown;
    $('ch-history-all').hidden = !(DATA.historyHasMore && history.length <= historyShown);
  }
  $('ch-history-more').addEventListener('click', function () { historyShown += HISTORY_STEP; renderHistory(); });
  $('ch-history-list').addEventListener('click', function (e) {
    var b = e.target.closest('[data-load]');
    if (!b || b.disabled) return;
    if (cart.length && !confirm('담은 품목이 이 견적서의 품목으로 바뀝니다. 불러올까요?')) return;
    b.disabled = true;
    fetch('/api/estimate/reorder.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ estimate_id: parseInt(b.getAttribute('data-load'), 10) })
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d || !d.ok) throw new Error((d && d.error) || '불러오기에 실패했습니다.');
        cartPage = 1;
        // 표시용 필드(사진·규격)·단가 기준을 맞추기 위해 담은 품목을 다시 조회
        return quickItems({ action: 'get' }).then(function () { announce(d.loaded_count + '개 품목을 불러왔습니다.'); });
      })
      .catch(function (err) { alert(err.message || '불러오기에 실패했습니다.'); })
      .then(function () { b.disabled = false; });
  });

  // ── 초기화 ────────────────────────────────────────────────
  fillSelect(sel1, DATA.lv1 || [], '분류 없음');
  onLv1();
  renderFavorites();
  renderHistory();
  quickItems({ action: 'get' }).catch(function () {
    cartBody.innerHTML = '<tr><td colspan="4" class="ch-empty">담은 품목을 불러오지 못했습니다. 새로고침해 주세요.</td></tr>';
  });
})();
