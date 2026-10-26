/**
 * 검색 자동완성 위젯
 * - data-suggest 속성이 붙은 <input>에 자동 연결 (헤더 검색·홈 검색·기업회원 메인 검색 공통)
 * - 입력 전(포커스): 최근 검색어(칩, localStorage) + 인기 검색어(순위)
 * - 입력 중: /api/suggest.php 조회 → 카테고리 / 상품 2단 목록 (좁은 폭은 1단)
 * - 드롭다운은 <body>에 붙여 폼의 overflow/둥근모서리에 갇히지 않게 한다
 *   폭·위치 기준: 입력칸을 감싼 [data-suggest-anchor] 요소(없으면 입력칸 자신)
 * - 키보드(↑/↓/Enter/Esc)·마우스 지원, 바깥 클릭·상단 대분류 메뉴 hover 시 닫힘
 * - 헤더 검색 줄 열기/닫기: window.tomokHeaderSearch (layout.php의 '검색' 버튼)
 */
(function () {
  'use strict';

  var RECENT_KEY = 'tomok_recent';
  var RECENT_MAX = 8;
  var uid = 0;

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
  }

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function escapeReg(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

  // ── 최근 검색어 (localStorage, 백엔드 무부하) ──
  function getRecent() {
    try {
      var v = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]');
      return Array.isArray(v) ? v.filter(function (t) { return typeof t === 'string' && t; }) : [];
    } catch (e) { return []; }
  }
  function saveRecent(list) {
    try { localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, RECENT_MAX))); } catch (e) { /* 저장 실패 무시 */ }
  }
  function addRecent(term) {
    term = (term || '').trim();
    if (!term) return;
    saveRecent([term].concat(getRecent().filter(function (t) { return t !== term; })));
  }
  function removeRecent(term) { saveRecent(getRecent().filter(function (t) { return t !== term; })); }
  function clearRecent() { try { localStorage.removeItem(RECENT_KEY); } catch (e) {} }

  function searchUrl(term) { return '/products.php?q=' + encodeURIComponent(term); }

  var ICON_X = '<svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>';
  var ICON_FOLDER = '<svg class="ac-ico" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/></svg>';
  var CHEV_DOWN = 'M6 9l6 6 6-6', CHEV_UP = 'M6 15l6-6 6 6';

  function attach(input) {
    if (input.dataset.suggestBound) return;
    input.dataset.suggestBound = '1';
    input.setAttribute('autocomplete', 'off');

    var anchor = input.closest('[data-suggest-anchor]') || input;
    var boxId = 'ac-box-' + (++uid);

    // 드롭다운은 body 직속으로 (폼의 overflow:hidden 회피)
    var box = document.createElement('div');
    box.className = 'ac-box';
    box.id = boxId;
    box.setAttribute('role', 'listbox');
    box.setAttribute('aria-label', '검색어 추천');
    box.style.display = 'none';
    document.body.appendChild(box);

    input.setAttribute('role', 'combobox');
    input.setAttribute('aria-autocomplete', 'list');
    input.setAttribute('aria-controls', boxId);
    input.setAttribute('aria-expanded', 'false');

    var opts = [];                 // 키보드 이동 대상(.ac-opt) — 화면 순서대로
    var active = -1;
    var controller = null;
    var lastQuery = '';
    var serverTerms = [];          // 서버가 준 확장 용어(동의어 포함) — 강조용
    var hasResult = false;         // 입력 중 결과가 그려져 있는지(포커스 복귀 시 재표시)
    var recentOpen = false;        // 최근 검색어 펼치기 상태

    // 결과 이름 강조: 서버가 준 확장 용어(동의어 포함)를 우선, 없으면 입력어 토큰
    function markName(name) {
      var esc = escapeHtml(name);
      var toks = (serverTerms && serverTerms.length) ? serverTerms.slice() : lastQuery.trim().split(/\s+/);
      toks = toks.filter(function (t) { return t && t.length >= 1; });
      if (!toks.length) return esc;
      toks.sort(function (a, b) { return b.length - a.length; });
      var pat = new RegExp('(' + toks.map(function (t) { return escapeReg(escapeHtml(t)); }).join('|') + ')', 'gi');
      return esc.replace(pat, '<mark class="hl">$1</mark>');
    }

    function place() {
      var r = anchor.getBoundingClientRect();
      box.style.top = (r.bottom + window.scrollY + 12) + 'px';
      box.style.left = (r.left + window.scrollX) + 'px';
      box.style.width = r.width + 'px';
      box.classList.toggle('ac-narrow', r.width < 520);  // 좁은 입력칸: 카테고리/상품 1단
    }

    function isOpen() { return box.style.display === 'block'; }

    function show() {
      place();
      box.style.display = 'block';
      input.setAttribute('aria-expanded', 'true');
    }

    function close() {
      box.style.display = 'none';
      box.innerHTML = '';
      opts = [];
      active = -1;
      hasResult = false;
      input.setAttribute('aria-expanded', 'false');
      input.removeAttribute('aria-activedescendant');
    }

    function go(url, term) {
      if (term) addRecent(term);
      window.location.href = url;
    }

    function paint() {
      for (var i = 0; i < opts.length; i++) {
        var on = i === active;
        opts[i].classList.toggle('ac-active', on);
        opts[i].setAttribute('aria-selected', on ? 'true' : 'false');
      }
      if (active >= 0 && opts[active]) {
        input.setAttribute('aria-activedescendant', opts[active].id);
        if (opts[active].scrollIntoView) opts[active].scrollIntoView({ block: 'nearest' });
      } else {
        input.removeAttribute('aria-activedescendant');
      }
    }

    // 렌더 후 .ac-opt(이동 대상) 연결: 마우스 클릭·hover
    function bindOpts() {
      opts = Array.prototype.slice.call(box.querySelectorAll('.ac-opt'));
      active = -1;
      opts.forEach(function (el, i) {
        el.id = boxId + '-o' + i;
        el.setAttribute('role', 'option');
        el.setAttribute('aria-selected', 'false');
        el.addEventListener('mousedown', function (e) { e.preventDefault(); go(el.getAttribute('href'), el.dataset.term); });
        el.addEventListener('mouseenter', function () { active = i; paint(); });
      });
    }

    // 버튼 클릭 시 입력칸 포커스가 빠져 목록이 닫히지 않게 mousedown 기본동작 차단
    function onPress(el, fn) {
      el.addEventListener('mousedown', function (e) { e.preventDefault(); });
      // 다시 그리면 눌린 버튼이 문서에서 빠져 '바깥 클릭'으로 오인되므로 전파 차단
      el.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); fn(); });
    }

    // ── 입력 전: 최근 검색어 → 인기 검색어 ──
    function renderHome(popular) {
      var recent = getRecent();
      var h = '<div class="ac-recent">'
        + '<div class="ac-sec-head"><span class="ac-sec-title">최근 검색어</span>';
      if (recent.length) {
        h += '<span class="ac-sec-actions">'
          + '<button type="button" class="ac-link ac-toggle" aria-expanded="' + (recentOpen ? 'true' : 'false') + '" hidden>'
          + '<span>' + (recentOpen ? '접기' : '펼치기') + '</span>'
          + '<svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="' + (recentOpen ? CHEV_UP : CHEV_DOWN) + '"/></svg>'
          + '</button>'
          + '<button type="button" class="ac-link ac-link-muted ac-clear-all">전체 삭제</button>'
          + '</span>';
      }
      h += '</div><div class="ac-chips">';
      recent.forEach(function (t) {
        h += '<span class="ac-chip">'
          + '<a class="ac-chip-label ac-opt" href="' + escapeHtml(searchUrl(t)) + '" data-term="' + escapeHtml(t) + '">' + escapeHtml(t) + '</a>'
          + '<button type="button" class="ac-chip-x" data-term="' + escapeHtml(t) + '" aria-label="' + escapeHtml(t) + ' 삭제">' + ICON_X + '</button>'
          + '</span>';
      });
      if (!recent.length) h += '<span class="ac-empty-inline">최근 검색어가 없습니다.</span>';
      h += '</div></div>';

      if (popular.length) {
        h += '<div class="ac-popular">'
          + '<div class="ac-sec-head"><span class="ac-sec-title">인기 검색어</span><span class="ac-sec-note">누적 기준</span></div>'
          + '<ol class="ac-rank-list">';
        popular.forEach(function (it, i) {
          h += '<li><a class="ac-rank-row ac-opt" href="' + escapeHtml(it.url || searchUrl(it.name)) + '" data-term="' + escapeHtml(it.name) + '">'
            + '<span class="ac-rank' + (i < 3 ? ' is-top' : '') + '">' + (i + 1) + '</span>'
            + '<span class="ac-rank-name">' + escapeHtml(it.name) + '</span></a></li>';
        });
        h += '</ol></div>';
      }

      box.className = 'ac-box ac-mode-home';
      box.innerHTML = h;
      bindOpts();
      show();

      // 최근 검색어 칩: 1줄을 넘을 때만 펼치기/접기 표시
      var chips = box.querySelector('.ac-chips');
      var toggle = box.querySelector('.ac-toggle');
      if (chips && toggle) {
        chips.classList.remove('is-clamped');
        var over = chips.scrollHeight > 40;
        toggle.hidden = !over;
        chips.classList.toggle('is-clamped', over && !recentOpen);
        onPress(toggle, function () { recentOpen = !recentOpen; renderHome(popular); });
      }
      var clr = box.querySelector('.ac-clear-all');
      if (clr) onPress(clr, function () { clearRecent(); recentOpen = false; renderHome(popular); });
      Array.prototype.forEach.call(box.querySelectorAll('.ac-chip-x'), function (b) {
        onPress(b, function () { removeRecent(b.dataset.term); renderHome(popular); });
      });
    }

    function showEmpty() {
      serverTerms = [];
      hasResult = false;
      if (controller) controller.abort();
      controller = new AbortController();
      fetch('/api/suggest.php?q=', { signal: controller.signal })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (input.value.trim() !== '' || document.activeElement !== input) return;
          renderHome((d && d.popular) || []);
        })
        .catch(function (e) {
          if (e && e.name === 'AbortError') return;
          if (input.value.trim() === '' && document.activeElement === input) renderHome([]);
        });
    }

    // ── 입력 중: 카테고리 / 상품 2단 ──
    function renderSuggest(items) {
      var cats = items.filter(function (it) { return it.type === 'category'; });
      var prods = items.filter(function (it) { return it.type !== 'category'; });
      var h;
      if (!cats.length && !prods.length) {
        h = '<div class="ac-nohit"><p class="ac-nohit-title">‘' + escapeHtml(lastQuery) + '’와 일치하는 자재를 찾지 못했어요.</p>'
          + '<p class="ac-nohit-sub">철자를 확인하거나 다른 검색어로 찾아보세요.</p></div>';
      } else {
        h = '<div class="ac-cols"><div class="ac-col">'
          + '<div class="ac-col-head">카테고리 <span class="ac-count">' + cats.length + '</span></div><div class="ac-col-list">';
        cats.forEach(function (it) {
          var path = it.path && it.path !== it.name ? '<span class="ac-path">' + escapeHtml(it.path) + '</span>' : '';
          h += '<a class="ac-hit ac-hit-cat ac-opt" href="' + escapeHtml(it.url) + '">' + ICON_FOLDER
            + '<span class="ac-hit-text"><span class="ac-hit-name">' + markName(it.name) + '</span>' + path + '</span></a>';
        });
        if (!cats.length) h += '<p class="ac-col-empty">일치하는 카테고리가 없어요.</p>';
        h += '</div></div><div class="ac-col">'
          + '<div class="ac-col-head">상품 <span class="ac-count">' + prods.length + '</span></div><div class="ac-col-list">';
        prods.forEach(function (it) {
          var img = it.img
            ? '<img class="ac-thumb" src="' + escapeHtml(it.img) + '" alt="" loading="lazy">'
            : '<span class="ac-thumb ac-thumb-empty" aria-hidden="true"></span>';
          var specs = it.specs > 0 ? '<span class="ac-specs">규격 ' + (it.specs | 0) + '종</span>' : '';
          h += '<a class="ac-hit ac-opt" href="' + escapeHtml(it.url) + '" data-term="' + escapeHtml(it.name) + '">' + img
            + '<span class="ac-hit-name ac-grow">' + markName(it.name) + '</span>' + specs + '</a>';
        });
        if (!prods.length) h += '<p class="ac-col-empty">일치하는 상품이 없어요.</p>';
        h += '</div></div></div>';
      }
      box.className = 'ac-box ac-mode-suggest';
      box.innerHTML = h;
      bindOpts();
      hasResult = true;
      show();
    }

    var fetchSuggest = debounce(function (q) {
      if (controller) controller.abort();
      controller = new AbortController();
      fetch('/api/suggest.php?q=' + encodeURIComponent(q), { signal: controller.signal })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          if (input.value.trim() !== q) return;  // 그 사이 입력이 바뀜
          serverTerms = (d && d.terms) || [];
          renderSuggest((d && d.items) || []);
        })
        .catch(function () { /* abort/네트워크 오류 무시 */ });
    }, 180);

    input.addEventListener('input', function () {
      var q = input.value.trim();
      lastQuery = q;
      input.dispatchEvent(new CustomEvent('suggest:change', { bubbles: true }));
      if (q.length < 1) { showEmpty(); return; }
      fetchSuggest(q);
    });

    input.addEventListener('keydown', function (e) {
      if (e.key === 'Escape') {
        if (isOpen()) { close(); e.stopPropagation(); }
        input.dispatchEvent(new CustomEvent('suggest:escape', { bubbles: true }));
        return;
      }
      if (!isOpen() || !opts.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault(); active = (active + 1) % opts.length; paint();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault(); active = active <= 0 ? opts.length - 1 : active - 1; paint();
      } else if (e.key === 'Enter') {
        if (active >= 0 && opts[active]) { e.preventDefault(); go(opts[active].getAttribute('href'), opts[active].dataset.term); }
      }
    });

    input.addEventListener('focus', function () {
      if (input.value.trim().length >= 1) {
        if (hasResult && box.innerHTML) show();
        else { lastQuery = input.value.trim(); fetchSuggest(lastQuery); }
      } else {
        showEmpty();  // 빈 입력 → 최근 검색어 + 인기 검색어
      }
    });

    // 검색 실행 시 입력어를 최근 검색어로 저장(폼 제출)
    if (input.form) {
      input.form.addEventListener('submit', function () { addRecent(input.value.trim()); });
    }

    // 외부(헤더 X 버튼 등)에서 닫기
    input.addEventListener('suggest:close', close);

    document.addEventListener('click', function (e) {
      if (e.target !== input && !box.contains(e.target) && !anchor.contains(e.target)) close();
    });
    // 상단 대분류 메뉴(.mega-item)에 마우스를 올리면 닫음 — 목록이 펼쳐지는 메가패널 위를 덮지 않게
    document.addEventListener('mouseover', function (e) {
      if (isOpen() && e.target.closest && e.target.closest('.mega-item')) close();
    });
    window.addEventListener('resize', function () { if (isOpen()) place(); });
    window.addEventListener('scroll', function (e) {
      if (isOpen() && !box.contains(e.target)) place();
    }, true);
  }

  // ── 헤더 검색 줄 (열기/닫기, X 버튼, 뒤 화면 어둡게) ──
  function initHeaderSearch() {
    var wrap = document.getElementById('header-search-wrap');
    if (!wrap) return;
    var input = document.getElementById('header-search-input');
    var dim = document.getElementById('header-search-dim');
    var xBtn = wrap.querySelector('.header-search-x');
    var toggles = document.querySelectorAll('[aria-controls="header-search-wrap"]');

    function setOpen(on) {
      wrap.classList.toggle('open', on);
      if (dim) dim.hidden = !on;
      for (var i = 0; i < toggles.length; i++) toggles[i].setAttribute('aria-expanded', on ? 'true' : 'false');
      if (on) { if (input) input.focus(); }
      else if (input) { input.dispatchEvent(new CustomEvent('suggest:close')); input.blur(); }
    }
    // X 버튼: 글자가 있으면 지우고, 비어 있으면 검색 줄 닫기
    function syncX() {
      if (!xBtn || !input) return;
      xBtn.setAttribute('aria-label', input.value ? '검색어 지우기' : '검색 닫기');
    }

    window.tomokHeaderSearch = {
      open: function () { setOpen(true); },
      close: function () { setOpen(false); },
      toggle: function () { setOpen(!wrap.classList.contains('open')); }
    };

    if (dim) dim.addEventListener('click', function () { setOpen(false); });
    if (input) {
      input.addEventListener('suggest:change', syncX);
      input.addEventListener('suggest:escape', function () { setOpen(false); });
    }
    if (xBtn && input) {
      xBtn.addEventListener('mousedown', function (e) { e.preventDefault(); });
      xBtn.addEventListener('click', function () {
        if (input.value) {
          input.value = '';
          input.dispatchEvent(new Event('input', { bubbles: true }));
          input.focus();
        } else {
          setOpen(false);
        }
      });
    }
    syncX();
  }

  function init() {
    var inputs = document.querySelectorAll('input[data-suggest]');
    for (var i = 0; i < inputs.length; i++) attach(inputs[i]);
    initHeaderSearch();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
