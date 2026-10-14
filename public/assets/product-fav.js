// product-fav.js — 상품 목록 관심상품 다중 추가(선택 모드)
// products.php 에서 $favEnabled 일 때만 로드. 저장: POST /api/estimate/favorites.php {product_ids:[...]}
// 선택 상태는 현재 페이지에서만 유지(저장 전 이동 시 확인, 화면 이동만으로 자동 저장하지 않음).
(function () {
    'use strict';
    var bar = document.getElementById('pt-fav-bar');
    var main = document.querySelector('.pt-list-main');
    var tbody = document.querySelector('.product-table tbody');
    if (!bar || !main || !tbody) { return; }

    var API = '/api/estimate/favorites.php';
    var loggedIn = bar.getAttribute('data-logged-in') === '1';
    var btnStart = document.getElementById('pt-fav-start');
    var btnDone = document.getElementById('pt-fav-done');
    var btnCancel = document.getElementById('pt-fav-cancel');
    var countEl = document.getElementById('pt-fav-count');
    var toastEl = document.getElementById('pt-fav-toast');
    var allBoxes = document.querySelectorAll('.pt-fav-all');
    var mode = false;      // 선택 모드 여부
    var busy = false;      // 저장 중(중복 클릭 방지)
    var leaving = false;   // 사용자가 이동을 확인함 → beforeunload 경고 생략
    var toastTimer = null;

    function boxes() { return tbody.querySelectorAll('input.pt-fav-chk'); }
    function selectedIds() {
        var ids = [];
        boxes().forEach(function (b) { if (b.checked) { ids.push(parseInt(b.value, 10)); } });
        return ids;
    }
    function hasUnsaved() { return mode && selectedIds().length > 0; }

    function sync() {
        var list = boxes();
        var n = 0;
        list.forEach(function (b) {
            if (b.checked) { n++; }
            var tr = b.closest('tr');
            if (tr) { tr.classList.toggle('is-checked', b.checked); }
        });
        countEl.textContent = n + '개 선택됨';
        btnDone.disabled = busy || n === 0;
        allBoxes.forEach(function (a) {
            a.checked = list.length > 0 && n === list.length;
            a.indeterminate = n > 0 && n < list.length;
        });
    }

    function setMode(on) {
        mode = on;
        main.classList.toggle('pt-fav-mode', on);
        btnStart.hidden = on;
        countEl.hidden = !on;
        btnDone.hidden = !on;
        btnCancel.hidden = !on;
        if (!on) { boxes().forEach(function (b) { b.checked = false; }); }
        sync();
    }

    function toast(msg, type, link) {
        clearTimeout(toastTimer);
        toastEl.textContent = msg;
        if (link) {
            var a = document.createElement('a');
            a.href = link.href;
            a.textContent = link.text;
            toastEl.appendChild(document.createTextNode(' '));
            toastEl.appendChild(a);
        }
        toastEl.className = 'pt-fav-toast' + (type === 'error' ? ' is-error' : '');
        toastEl.setAttribute('role', type === 'error' ? 'alert' : 'status');
        toastEl.hidden = false;
        toastTimer = setTimeout(function () { toastEl.hidden = true; }, type === 'error' ? 8000 : 5000);
    }

    function askLogin(msg) {
        if (!confirm(msg + '\n로그인하시겠습니까?')) { return; }
        // 헤더 '로그인'과 같은 로그인 모달(로그인 성공 시 현재 목록 페이지 새로고침). 없으면 로그인 페이지로.
        if (typeof window.openAuthModal === 'function') { window.openAuthModal('login'); return; }
        leaving = true;
        location.href = '/login.php?redirect=' + encodeURIComponent(location.pathname + location.search);
    }

    btnStart.addEventListener('click', function () {
        if (!loggedIn) { askLogin('관심상품은 로그인 후 이용할 수 있습니다.'); return; }
        setMode(true);
    });
    btnCancel.addEventListener('click', function () {
        if (busy) { return; }
        setMode(false);   // 확인 없이 선택 초기화
    });

    tbody.addEventListener('change', function (ev) {
        if (ev.target.classList && ev.target.classList.contains('pt-fav-chk')) { sync(); }
    });
    allBoxes.forEach(function (a) {
        a.addEventListener('change', function () {
            var on = a.checked;
            boxes().forEach(function (b) { b.checked = on; });   // 현재 페이지에 표시된 상품만
            sync();
        });
    });

    function toggleRow(tr) {
        var b = tr.querySelector('input.pt-fav-chk');
        if (b) { b.checked = !b.checked; sync(); }
    }
    // 행 클릭: 선택 모드면 상세 이동 대신 선택 토글(true 반환), 아니면 기존대로 상세 이동(false)
    window.ptFavRowClick = function (ev, tr) {
        if (!mode) { return false; }
        if (!(ev.target.closest && ev.target.closest('.pt-chk'))) { toggleRow(tr); }
        return true;
    };
    // 품명 링크(행 클릭 전파 차단됨): 선택 모드에서는 이동하지 않고 선택 토글
    tbody.addEventListener('click', function (ev) {
        if (!mode) { return; }
        var a = ev.target.closest && ev.target.closest('a[href]');
        if (!a) { return; }
        ev.preventDefault();
        var tr = a.closest('tr');
        if (tr) { toggleRow(tr); }
    }, true);

    // ── 저장 ──────────────────────────────────────────────
    btnDone.addEventListener('click', async function () {
        var ids = selectedIds();
        if (busy || ids.length === 0) { return; }
        busy = true;
        btnCancel.disabled = true;
        btnDone.textContent = '저장 중...';
        sync();
        try {
            var r = await fetch(API, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'same-origin',
                body: JSON.stringify({ product_ids: ids })
            });
            if (r.status === 401) {
                askLogin('로그인이 만료되었습니다. 선택한 상품은 저장되지 않았습니다.');
                return;
            }
            var d = null;
            try { d = await r.json(); } catch (e) { d = null; }
            if (!r.ok || !d || !d.ok) {
                toast((d && d.error) || '관심상품 저장에 실패했습니다. 잠시 후 다시 시도해 주세요.', 'error');
                return;
            }
            applyResult(d);
        } catch (e) {
            toast('네트워크 오류로 관심상품을 저장하지 못했습니다. 다시 시도해 주세요.', 'error');
        } finally {
            busy = false;
            btnCancel.disabled = false;
            btnDone.textContent = '추가하기 완료';
            sync();
        }
    });

    function applyResult(d) {
        var added = d.added || [], already = d.already || [], failed = d.failed || [];
        var okSet = {};
        added.concat(already).forEach(function (id) { okSet[id] = true; });
        var link = { href: '/mypage.php?tab=favorites', text: '마이페이지에서 보기' };

        if (failed.length === 0) {
            var msg = added.length > 0 ? '관심상품 ' + added.length + '개를 추가했습니다.' : '선택한 상품은 이미 관심상품에 있습니다.';
            if (added.length > 0 && already.length > 0) { msg += ' (이미 등록된 ' + already.length + '개는 기존 내역 유지)'; }
            setMode(false);
            toast(msg, 'ok', link);
            return;
        }
        // 부분 성공·전체 실패: 성공 항목은 선택 해제, 실패 항목만 선택 상태로 남겨 재시도 가능
        boxes().forEach(function (b) { if (okSet[parseInt(b.value, 10)]) { b.checked = false; } });
        var limit = failed.filter(function (f) { return f.reason === 'limit'; }).length;
        var invalid = failed.length - limit;
        var parts = [];
        if (limit) { parts.push('최대 ' + (d.max || 50) + '개 초과 ' + limit + '개'); }
        if (invalid) { parts.push('판매 중이 아닌 상품 ' + invalid + '개'); }
        toast('성공 ' + (added.length + already.length) + '개, 실패 ' + failed.length + '개 (' + parts.join(', ') + ')',
              'error', (added.length + already.length) > 0 ? link : null);
    }

    // ── 저장 안 된 선택이 있을 때 화면 이동 확인 ──────────────
    function confirmLeave() {
        if (!hasUnsaved()) { return true; }
        if (confirm('저장하지 않은 관심상품 선택 ' + selectedIds().length + '개가 있습니다.\n이동하면 선택이 초기화됩니다. 이동할까요?')) {
            leaving = true;
            return true;
        }
        return false;
    }
    document.addEventListener('click', function (ev) {
        if (!hasUnsaved() || ev.defaultPrevented || ev.button !== 0 || ev.ctrlKey || ev.metaKey || ev.shiftKey || ev.altKey) { return; }
        var a = ev.target.closest && ev.target.closest('a[href]');
        if (!a || tbody.contains(a)) { return; }
        var href = a.getAttribute('href') || '';
        if (href === '' || href.charAt(0) === '#' || /^javascript:/i.test(href) || a.target === '_blank' || a.hasAttribute('download')) { return; }
        if (!confirmLeave()) { ev.preventDefault(); ev.stopPropagation(); }
    }, true);
    document.addEventListener('submit', function (ev) {
        if (!confirmLeave()) { ev.preventDefault(); ev.stopPropagation(); }
    }, true);
    // 정렬 드롭다운은 onchange 에서 form.submit()(submit 이벤트 없음) → change 단계에서 확인
    document.addEventListener('change', function (ev) {
        var sel = ev.target;
        if (!sel.classList || !sel.classList.contains('pt-sort')) { return; }
        if (!confirmLeave()) {
            ev.stopPropagation();
            for (var i = 0; i < sel.options.length; i++) { sel.options[i].selected = sel.options[i].defaultSelected; }
        }
    }, true);
    // 새로고침·뒤로가기·주소 직접 이동: 브라우저 기본 확인창
    window.addEventListener('beforeunload', function (ev) {
        if (leaving || !hasUnsaved()) { return; }
        ev.preventDefault();
        ev.returnValue = '';
    });
    // 뒤로가기 캐시(bfcache) 복원 시 이전 선택이 남지 않도록 기본 상태로
    window.addEventListener('pageshow', function () {
        leaving = false;
        if (mode || selectedIds().length) { setMode(false); }
    });

    sync();
})();
