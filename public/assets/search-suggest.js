/**
 * 검색 자동완성 위젯
 * - data-suggest 속성이 붙은 <input>에 자동 연결
 * - 입력 시 /api/suggest.php 조회 → 입력창 바로 아래에 목록 표시
 * - 드롭다운은 <body>에 붙여 폼의 overflow/둥근모서리에 갇히지 않게 한다
 * - 키보드(↑/↓/Enter/Esc)·마우스 지원, 바깥 클릭/스크롤 시 닫힘
 */
(function () {
  'use strict';

  function debounce(fn, ms) {
    var t;
    return function () {
      var args = arguments, self = this;
      clearTimeout(t);
      t = setTimeout(function () { fn.apply(self, args); }, ms);
    };
  }

  function attach(input) {
    if (input.dataset.suggestBound) return;
    input.dataset.suggestBound = '1';
    input.setAttribute('autocomplete', 'off');

    // 드롭다운은 body 직속으로 (폼의 overflow:hidden 회피)
    var box = document.createElement('div');
    box.className = 'ac-box';
    box.setAttribute('role', 'listbox');
    box.style.display = 'none';
    document.body.appendChild(box);

    var items = [];
    var active = -1;
    var controller = null;
    var lastQuery = '';
    var serverTerms = [];          // 서버가 준 확장 용어(동의어 포함) — 강조용
    var RECENT_KEY = 'tomok_recent';

    // ── 최근 검색어 (localStorage, 백엔드 무부하) ──
    function getRecent() {
      try { return JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); }
      catch (e) { return []; }
    }
    function addRecent(term) {
      term = (term || '').trim();
      if (term.length < 1) return;
      try {
        var list = getRecent().filter(function (t) { return t !== term; });
        list.unshift(term);
        localStorage.setItem(RECENT_KEY, JSON.stringify(list.slice(0, 8)));
      } catch (e) { /* 저장 실패 무시 */ }
    }
    function clearRecent() {
      try { localStorage.removeItem(RECENT_KEY); } catch (e) {}
    }

    function escapeHtml(s) {
      return s.replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
    function escapeReg(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

    // 결과 이름 강조: 서버가 준 확장 용어(동의어 포함)를 우선, 없으면 입력어 토큰
    function markName(name) {
      var esc = escapeHtml(name);
      var toks = (serverTerms && serverTerms.length)
        ? serverTerms.slice()
        : lastQuery.trim().split(/\s+/);
      toks = toks.filter(function (t) { return t && t.length >= 1; });
      if (!toks.length) return esc;
      toks.sort(function (a, b) { return b.length - a.length; });
      var pat = new RegExp('(' + toks.map(escapeReg).join('|') + ')', 'gi');
      return esc.replace(pat, '<mark class="hl">$1</mark>');
    }

    function place() {
      var r = input.getBoundingClientRect();
      box.style.top = (r.bottom + window.scrollY) + 'px';
      box.style.left = (r.left + window.scrollX) + 'px';
      box.style.width = r.width + 'px';
    }

    function isOpen() { return box.style.display === 'block'; }

    function close() {
      box.style.display = 'none';
      box.innerHTML = '';
      items = [];
      active = -1;
    }

    function go(url) { window.location.href = url; }

    function tagLabel(it) {
      if (it.type === 'recent')   return '최근';
      if (it.type === 'category') return '카테고리';
      return it.cnt ? it.cnt + '종' : '상품';
    }

    function render(withRecentHeader) {
      if (!items.length) { close(); return; }
      box.innerHTML = '';

      // 최근 검색어 헤더(+지우기) — 빈 입력 컨텍스트에서만
      if (withRecentHeader) {
        var hd = document.createElement('div');
        hd.className = 'ac-head';
        var lbl = document.createElement('span'); lbl.textContent = '최근 검색어';
        var clr = document.createElement('a'); clr.textContent = '지우기'; clr.href = '#'; clr.className = 'ac-clear';
        clr.addEventListener('mousedown', function (e) {
          e.preventDefault(); clearRecent(); showEmpty();
        });
        hd.appendChild(lbl); hd.appendChild(clr);
        box.appendChild(hd);
      }

      items.forEach(function (it, i) {
        var row = document.createElement('a');
        row.className = 'ac-item' + (i === active ? ' ac-active' : '');
        row.href = it.url;
        row.setAttribute('role', 'option');

        var name = document.createElement('span');
        name.className = 'ac-name';
        name.innerHTML = markName(it.name);
        row.appendChild(name);

        var tag = document.createElement('span');
        tag.className = 'ac-tag' + (it.type === 'category' ? ' ac-tag-cat' : '');
        tag.textContent = tagLabel(it);
        row.appendChild(tag);

        row.addEventListener('mousedown', function (e) { e.preventDefault(); go(it.url); });
        row.addEventListener('mouseenter', function () { active = i; paint(); });
        box.appendChild(row);
      });
      place();
      box.style.display = 'block';
    }

    // 빈 입력(포커스): 최근 검색어 + 인기 카테고리
    function showEmpty() {
      serverTerms = [];
      var recent = getRecent().map(function (t) {
        return { type: 'recent', name: t, url: '/products.php?q=' + encodeURIComponent(t) };
      });
      if (controller) controller.abort();
      controller = new AbortController();
      fetch('/api/suggest.php?q=', { signal: controller.signal })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          var cats = (d && d.items) || [];
          items = recent.concat(cats);
          active = -1;
          render(recent.length > 0);
        })
        .catch(function () {
          items = recent;
          active = -1;
          if (items.length) render(recent.length > 0); else close();
        });
    }

    function paint() {
      var rows = box.querySelectorAll('.ac-item');
      for (var i = 0; i < rows.length; i++) {
        rows[i].className = 'ac-item' + (i === active ? ' ac-active' : '');
      }
    }

    var fetchSuggest = debounce(function (q) {
      if (controller) controller.abort();
      controller = new AbortController();
      fetch('/api/suggest.php?q=' + encodeURIComponent(q), { signal: controller.signal })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          items = (d && d.items) || [];
          serverTerms = (d && d.terms) || [];
          active = -1;
          render(false);
        })
        .catch(function () { /* abort/네트워크 오류 무시 */ });
    }, 180);

    input.addEventListener('input', function () {
      var q = input.value.trim();
      lastQuery = q;
      if (q.length < 1) { showEmpty(); return; }
      fetchSuggest(q);
    });

    input.addEventListener('keydown', function (e) {
      if (!isOpen() || !items.length) return;
      if (e.key === 'ArrowDown') {
        e.preventDefault(); active = (active + 1) % items.length; paint();
      } else if (e.key === 'ArrowUp') {
        e.preventDefault(); active = (active - 1 + items.length) % items.length; paint();
      } else if (e.key === 'Enter') {
        if (active >= 0 && items[active]) { e.preventDefault(); go(items[active].url); }
      } else if (e.key === 'Escape') {
        close();
      }
    });

    input.addEventListener('focus', function () {
      var q = input.value.trim();
      if (q.length >= 1) {
        if (items.length) { place(); box.style.display = 'block'; }
      } else {
        showEmpty();  // 빈 입력 → 최근 검색어 + 인기 카테고리
      }
    });

    // 검색 실행 시 입력어를 최근 검색어로 저장(폼 제출)
    if (input.form) {
      input.form.addEventListener('submit', function () {
        addRecent(input.value.trim());
      });
    }

    document.addEventListener('click', function (e) {
      if (e.target !== input && !box.contains(e.target)) close();
    });
    window.addEventListener('resize', function () { if (isOpen()) place(); });
    window.addEventListener('scroll', function () { if (isOpen()) place(); }, true);
  }

  function init() {
    var inputs = document.querySelectorAll('input[data-suggest]');
    for (var i = 0; i < inputs.length; i++) attach(inputs[i]);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
