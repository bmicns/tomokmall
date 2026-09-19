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

    function escapeHtml(s) {
      return s.replace(/[&<>"']/g, function (c) {
        return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
      });
    }
    function escapeReg(s) { return s.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'); }

    // 입력한 검색어(공백 분리)를 결과 이름에서 <mark>로 강조
    function markName(name) {
      var esc = escapeHtml(name);
      var toks = lastQuery.trim().split(/\s+/).filter(function (t) { return t.length >= 1; });
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

    function render() {
      if (!items.length) { close(); return; }
      box.innerHTML = '';
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
        tag.textContent = it.type === 'category' ? '카테고리' : (it.cnt ? it.cnt + '종' : '상품');
        row.appendChild(tag);

        row.addEventListener('mousedown', function (e) { e.preventDefault(); go(it.url); });
        row.addEventListener('mouseenter', function () { active = i; paint(); });
        box.appendChild(row);
      });
      place();
      box.style.display = 'block';
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
          active = -1;
          render();
        })
        .catch(function () { /* abort/네트워크 오류 무시 */ });
    }, 180);

    input.addEventListener('input', function () {
      var q = input.value.trim();
      lastQuery = q;
      if (q.length < 1) { close(); return; }
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
      if (input.value.trim().length >= 1 && items.length) { place(); box.style.display = 'block'; }
    });

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
