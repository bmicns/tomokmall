/*!
 * VESTRA 집 체크 — 임베드 위젯 (대한민국 기준, 데모 빌드)
 * 사용법 (현지 회사 홈페이지에 두 줄):
 *   <div id="vestra-widget" data-brand="토목몰" data-features="rights,jeonse,price,tax"></div>
 *   <script src="https://.../vestra-widget.js" async></script>
 *
 * data-features : 노출할 주요기능 선택 (쉼표구분). 생략 시 전체.
 *   rights=권리분석 · jeonse=전세 안전진단 · price=시세조회 · tax=세금계산
 * data-brand    : 결과 하단 문의 버튼에 들어갈 회사명
 * data-accent   : 강조색 (기본 #0d7a6e)
 * data-cta      : 문의 버튼 링크
 *
 * - Shadow DOM 으로 호스트 사이트 CSS 와 완전 격리
 * - 외부 의존성 0, 네트워크 호출 0 (데모: 샘플 데이터)
 * - 중복 초기화 방지
 */
(function () {
  "use strict";
  if (window.__vestraWidgetLoaded) return;
  window.__vestraWidgetLoaded = true;

  var FEATURES = {
    rights: { label: '권리분석', hint: '등기부 기반 위험도' },
    jeonse: { label: '전세 안전진단', hint: '깡통전세·전세가율' },
    price:  { label: '시세조회', hint: '실거래 기반 추정' },
    tax:    { label: '세금계산', hint: '취득세 간이계산' }
  };
  var ORDER = ['rights', 'jeonse', 'price', 'tax'];

  function init() {
    var mounts = document.querySelectorAll('#vestra-widget, [data-vestra-widget]');
    if (!mounts.length) { console.warn('[VESTRA] mount element 없음'); return; }
    mounts.forEach(mountWidget);
  }

  function mountWidget(mount) {
    if (mount.getAttribute('data-vestra-ready') === '1') return;
    mount.setAttribute('data-vestra-ready', '1');

    var feats = (mount.getAttribute('data-features') || ORDER.join(','))
      .split(',').map(function (s) { return s.trim(); })
      .filter(function (s) { return FEATURES[s]; });
    if (!feats.length) feats = ORDER.slice();

    var cfg = {
      feats: feats,
      brand: mount.getAttribute('data-brand') || '',
      accent: mount.getAttribute('data-accent') || '#0d7a6e',
      cta: mount.getAttribute('data-cta') || '',
      api: (mount.getAttribute('data-api') || 'https://vestra.ai.kr').replace(/\/$/, '') // 베스트라 API 베이스
    };

    var root = mount.attachShadow ? mount.attachShadow({ mode: 'open' }) : mount;
    root.innerHTML = template(cfg);
    wire(root, cfg);
  }

  function template(cfg) {
    var a = cfg.accent, adk = shade(a, -14);
    var tabs = cfg.feats.map(function (f, i) {
      return '<button class="tab' + (i === 0 ? ' on' : '') + '" data-f="' + f + '">' + FEATURES[f].label + '</button>';
    }).join('');

    return (
'<style>' +
':host{all:initial}' +
'*{box-sizing:border-box;font-family:-apple-system,"Apple SD Gothic Neo","Malgun Gothic","Helvetica Neue",Arial,sans-serif}' +
'.vw{max-width:580px;border:1px solid #d4ded9;border-radius:14px;overflow:hidden;background:#fff;color:#141b26;box-shadow:0 6px 24px -14px rgba(0,0,0,.35);line-height:1.5}' +
'.hd{background:linear-gradient(100deg,' + a + ',' + adk + ');color:#fff;padding:15px 18px;display:flex;align-items:center;justify-content:space-between;gap:12px}' +
'.bd-brand{display:flex;align-items:center;gap:9px;font-weight:800;font-size:15px}' +
'.mk{width:22px;height:22px;background:rgba(255,255,255,.2);border-radius:6px;display:grid;place-items:center;font-size:13px;font-weight:800}' +
'.bd-brand small{display:block;font-weight:500;font-size:10px;opacity:.85}' +
'.pw{font-size:10px;letter-spacing:.06em;opacity:.82}' +
'.tabs{display:flex;gap:2px;padding:10px 12px 0;background:#f7faf9;border-bottom:1px solid #e2e6ec;overflow-x:auto}' +
'.tab{flex:0 0 auto;font-size:13px;font-weight:700;color:#5c6575;background:none;border:0;border-bottom:2px solid transparent;padding:9px 12px;cursor:pointer;white-space:nowrap}' +
'.tab.on{color:' + adk + ';border-bottom-color:' + a + '}' +
'.bd{padding:18px}' +
'.lb{font-size:12.5px;font-weight:700;margin:0 0 8px}' +
'.row{display:flex;gap:8px}' +
'.in{flex:1;min-width:0;padding:11px 13px;border:1.5px solid #e2e6ec;border-radius:9px;font-size:14px;outline:none}' +
'.in:focus{border-color:' + a + '}' +
'.btn{padding:11px 18px;background:' + a + ';color:#fff;border:0;border-radius:9px;font-weight:700;font-size:14px;cursor:pointer;white-space:nowrap}' +
'.btn:hover{background:' + adk + '}' +
'.btn:disabled{opacity:.6;cursor:default}' +
'.sub{display:flex;gap:8px;margin-top:8px}' +
'.hint{font-size:11px;color:#8a93a3;margin:8px 0 0}' +
'.samples{display:flex;flex-wrap:wrap;gap:6px;margin-top:9px}' +
'.chip{font-size:11px;color:' + a + ';background:' + tint(a) + ';border:0;padding:5px 10px;border-radius:20px;cursor:pointer}' +
'.res{margin-top:16px;border-top:1px solid #e2e6ec;padding-top:16px}' +
'.res[hidden]{display:none}' +
'.rtop{display:flex;align-items:center;gap:14px;margin-bottom:14px}' +
'.grade{width:52px;height:52px;border-radius:13px;display:grid;place-items:center;font-size:25px;font-weight:800;color:#fff;flex:0 0 auto}' +
'.ra{font-weight:800;font-size:14px;word-break:break-word}' +
'.rv{font-size:20px;font-weight:800;color:' + adk + ';margin-top:2px}' +
'.rt{font-size:12px;color:#5c6575;margin-top:2px}' +
'.grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}' +
'.m{border:1px solid #e2e6ec;border-radius:10px;padding:10px 12px}' +
'.mk2{font-size:11px;color:#8a93a3;font-weight:700}' +
'.mv{font-size:14px;font-weight:700;margin-top:4px}' +
'.lo{color:#15803d}.md{color:#b45309}.hi{color:#b91c1c}' +
'.flags{list-style:none;margin:12px 0 0;padding:0;font-size:12.5px}' +
'.flags li{display:flex;gap:8px;align-items:flex-start;padding:5px 0}' +
'.flags .dot{width:7px;height:7px;border-radius:50%;margin-top:5px;flex:0 0 auto}' +
'.cta{width:100%;margin-top:14px;padding:11px;background:#1f3a5f;color:#fff;border:0;border-radius:9px;font-weight:700;font-size:13px;cursor:pointer;text-decoration:none;display:block;text-align:center}' +
'.disc{font-size:10px;color:#8a93a3;margin:10px 0 0}' +
'.opts{display:flex;flex-wrap:wrap;gap:12px;margin-top:10px;align-items:center}' +
'.opt{font-size:12px;color:#5c6575;display:inline-flex;align-items:center;gap:5px;cursor:pointer}' +
'.sel{padding:5px 8px;border:1px solid #e2e6ec;border-radius:6px;font-size:12px;font-family:inherit}' +
'input[type=file].in{padding:9px 10px;font-size:12.5px}' +
'.sp{display:inline-block;width:14px;height:14px;border:2px solid rgba(255,255,255,.4);border-top-color:#fff;border-radius:50%;animation:vsp .7s linear infinite;vertical-align:-2px;margin-right:6px}' +
'@keyframes vsp{to{transform:rotate(360deg)}}' +
'@media(max-width:440px){.grid{grid-template-columns:1fr}.row{flex-direction:column}}' +
'</style>' +
'<div class="vw">' +
  '<div class="hd">' +
    '<div class="bd-brand"><span class="mk">V</span><div>베스트라 집 체크<small>부동산 안심 분석</small></div></div>' +
    '<span class="pw">임베드됨</span>' +
  '</div>' +
  '<div class="tabs">' + tabs + '</div>' +
  '<div class="bd" id="v-panel"></div>' +
'</div>'
    );
  }

  // ---- 탭별 입력 패널 ----
  function panelHtml(f) {
    var sample =
      '<p class="hint">샘플 주소:</p><div class="samples">' +
      '<button class="chip" data-a="서울 강남구 역삼동 737">역삼동</button>' +
      '<button class="chip" data-a="경기 성남시 분당구 정자동 178">분당 정자동</button>' +
      '<button class="chip" data-a="서울 마포구 합정동 411">합정동</button>' +
      '</div>';
    var body;
    if (f === 'jeonse') {
      body = '<p class="lb">주소와 전세보증금을 입력하세요</p>' +
        '<div class="row"><input class="in" id="v-addr" type="text" placeholder="예: 서울 마포구 합정동 411" autocomplete="off"></div>' +
        '<div class="sub"><input class="in" id="v-extra" type="number" inputmode="numeric" placeholder="전세보증금 (만원) 예: 45000">' +
        '<button class="btn" id="v-go">진단</button></div>' + sample;
    } else if (f === 'tax') {
      body = '<p class="lb">매매가와 조건을 입력하세요 (취득세)</p>' +
        '<div class="row"><input class="in" id="v-extra" type="number" inputmode="numeric" placeholder="매매가 (만원) 예: 90000">' +
        '<button class="btn" id="v-go">계산</button></div>' +
        '<div class="opts">' +
          '<label class="opt">주택수 <select class="sel" id="v-hc"><option value="1">1주택</option><option value="2">2주택</option><option value="3">3주택+</option></select></label>' +
          '<label class="opt"><input type="checkbox" id="v-adj"> 조정대상지역</label>' +
          '<label class="opt"><input type="checkbox" id="v-first"> 생애최초</label>' +
        '</div>';
    } else if (f === 'rights') {
      body = '<p class="lb">등기부등본 PDF를 올려주세요</p>' +
        '<div class="row"><input class="in" id="v-file" type="file" accept="application/pdf">' +
        '<button class="btn" id="v-go">분석</button></div>' +
        '<p class="hint">인터넷등기소에서 발급한 <b>텍스트 PDF</b> · 스캔본·이미지는 미지원 · 파일은 저장하지 않습니다</p>';
    } else { // price
      body = '<p class="lb">주소를 입력하세요</p>' +
        '<div class="row"><input class="in" id="v-addr" type="text" placeholder="예: 서울 강남구 역삼동 737" autocomplete="off">' +
        '<button class="btn" id="v-go">분석</button></div>' + sample;
    }
    return body + '<div class="res" id="v-res" hidden></div>';
  }

  function wire(root, cfg) {
    var tabsEl = root.querySelectorAll('.tab');
    var cur = cfg.feats[0];

    function show(f) {
      cur = f;
      tabsEl.forEach(function (t) { t.classList.toggle('on', t.getAttribute('data-f') === f); });
      root.getElementById('v-panel').innerHTML = panelHtml(f);
      bindPanel(root, cfg, f);
    }
    tabsEl.forEach(function (t) {
      t.addEventListener('click', function () { show(t.getAttribute('data-f')); });
    });
    show(cur);
  }

  function bindPanel(root, cfg, f) {
    var $ = function (id) { return root.getElementById(id); };
    var go = $('v-go'), res = $('v-res');
    var addr = $('v-addr'), extra = $('v-extra');

    root.querySelectorAll('.chip').forEach(function (c) {
      c.addEventListener('click', function () { if (addr) { addr.value = c.getAttribute('data-a'); addr.focus(); } });
    });

    function run() {
      var file = $('v-file');
      if (f === 'tax') { if (!valNum(extra)) { extra.focus(); return; } }
      else if (f === 'rights') { if (!file || !file.files || !file.files[0]) { return; } }
      else { if (!addr || !addr.value.trim()) { addr && addr.focus(); return; } }
      if (f === 'jeonse' && !valNum(extra)) { extra.focus(); return; }

      var lbl = go.textContent;
      var base = cfg.api;

      function liveGet(url, renderer) {
        go.disabled = true; go.innerHTML = '<span class="sp"></span>';
        fetch(url)
          .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (x) { res.innerHTML = x.ok ? renderer(x.d, cfg) : errHtml(x.d && x.d.error); res.hidden = false; })
          .catch(function () { res.innerHTML = errHtml('네트워크 오류로 조회하지 못했습니다.'); res.hidden = false; })
          .then(function () { go.disabled = false; go.textContent = lbl; });
      }

      if (f === 'price') {
        liveGet(base + '/api/embed/price?address=' + encodeURIComponent(addr.value.trim()) + '&type=apartment', renderPriceLive);
      } else if (f === 'jeonse') {
        liveGet(base + '/api/embed/jeonse-safety?address=' + encodeURIComponent(addr.value.trim()) + '&deposit=' + encodeURIComponent(extra.value) + '&type=apartment', renderJeonseLive);
      } else if (f === 'tax') {
        var hc = $('v-hc'), adj = $('v-adj'), fst = $('v-first');
        liveGet(base + '/api/embed/tax?price=' + encodeURIComponent(extra.value) +
          '&houseCount=' + (hc ? hc.value : '1') +
          '&adjusted=' + (adj && adj.checked ? '1' : '0') +
          '&firstHome=' + (fst && fst.checked ? '1' : '0'), renderTaxLive);
      } else if (f === 'rights') {
        var fd = new FormData(); fd.append('file', file.files[0]);
        go.disabled = true; go.innerHTML = '<span class="sp"></span>';
        fetch(base + '/api/embed/rights', { method: 'POST', body: fd })
          .then(function (r) { return r.json().then(function (d) { return { ok: r.ok, d: d }; }); })
          .then(function (x) { res.innerHTML = x.ok ? renderRightsLive(x.d, cfg) : errHtml(x.d && x.d.error); res.hidden = false; })
          .catch(function () { res.innerHTML = errHtml('네트워크 오류로 분석하지 못했습니다.'); res.hidden = false; })
          .then(function () { go.disabled = false; go.textContent = lbl; });
      }
    }
    go.addEventListener('click', run);
    [addr, extra].forEach(function (el) { if (el) el.addEventListener('keydown', function (e) { if (e.key === 'Enter') run(); }); });
  }

  // ---- 전세 안전진단 (실데이터) ----
  function renderJeonseLive(d, cfg) {
    var cls = (d.risk && d.risk.level) || 'md';
    var colors = { lo: '#15803d', md: '#b45309', hi: '#b91c1c' };
    var label = cls === 'lo' ? '양호' : cls === 'hi' ? '위험' : '주의';
    return head([label, colors[cls]], d.address, '전세가율 ' + d.jeonseRatio + '%') +
      grid([
        ['추정 시세', won(d.estimatedPrice), 'lo'],
        ['전세보증금', won(d.deposit), 'md'],
        ['전세가율', d.jeonseRatio + '%', cls],
        ['깡통전세 위험', (d.risk && d.risk.label) || '-', cls]
      ]) +
      (d.note ? flagsHtml([[cls, d.note]]) : '') +
      ctaHtml(cfg) +
      '<p class="disc">국토교통부 실거래가 기반 · 지역 평균 전세가율 ' + (d.marketJeonseRatio != null ? d.marketJeonseRatio + '%' : '-') + ' · 매매 실거래 ' + d.saleTransactionCount + '건</p>';
  }

  // ---- 취득세 (실계산) ----
  function renderTaxLive(d, cfg) {
    var cond = (d.houseCount >= 2 ? d.houseCount + '주택' : '1주택') + (d.isAdjusted ? ' · 조정지역' : '') + (d.isFirstHome ? ' · 생애최초' : '');
    var ratePct = d.rate != null ? (Math.round(d.rate * 1000) / 10) + '%' : '-';
    return head(['세금', cfg.accent], '매매가 ' + won(d.price), '취득세 합계 ' + won(d.totalTax)) +
      grid([
        ['적용 세율', ratePct, 'lo'],
        ['취득세', won(d.tax), 'md'],
        ['지방교육세', won(d.localEduTax), 'lo'],
        ['농어촌특별세', won(d.specialTax || 0), 'lo']
      ]) +
      '<p class="disc">' + esc(cond) + (d.label ? ' · ' + esc(d.label) : '') + ' 기준. 실제 세액은 감면·요건에 따라 달라질 수 있습니다.</p>';
  }

  // ---- 권리분석 (등기부 실파싱) ----
  function renderRightsLive(d, cfg) {
    var gcolors = { A: '#15803d', B: '#2e7d4f', C: '#b45309', D: '#c2410c', F: '#b91c1c' };
    var sev = { low: 'lo', medium: 'md', high: 'hi', critical: 'hi' };
    var flags = (d.factors || []).slice(0, 6).map(function (fa) {
      return [sev[fa.severity] || 'md', (fa.category ? '[' + fa.category + '] ' : '') + fa.description];
    });
    return head([d.grade || '-', gcolors[d.grade] || '#5c6575'], d.gradeLabel || '권리분석', '위험도 ' + (d.totalScore != null ? d.totalScore + '점' : '-')) +
      grid([
        ['종합 등급', (d.grade || '-') + ' · ' + (d.gradeLabel || ''), gCls(d.grade)],
        ['근저당 비율', d.mortgageRatio != null ? Math.round(d.mortgageRatio) + '%' : '-', d.mortgageRatio >= 60 ? 'hi' : d.mortgageRatio >= 40 ? 'md' : 'lo']
      ]) +
      (flags.length ? flagsHtml(flags) : '') +
      (d.summary ? '<p class="disc">' + esc(d.summary) + '</p>' : '') +
      ctaHtml(cfg) +
      '<p class="disc">등기부 원문 기반 분석 · 개인정보는 저장하지 않습니다.</p>';
  }
  function gCls(grade) { return grade === 'A' || grade === 'B' ? 'lo' : grade === 'C' ? 'md' : 'hi'; }

  // ---- 조각 ----
  function head(g, title, value) {
    return '<div class="rtop"><div class="grade" style="background:' + g[1] + '">' + g[0] + '</div>' +
      '<div style="flex:1;min-width:0"><div class="ra">' + esc(title) + '</div><div class="rv">' + esc(value) + '</div></div></div>';
  }
  function grid(items) {
    return '<div class="grid">' + items.map(function (it) {
      return '<div class="m"><div class="mk2">' + esc(it[0]) + '</div><div class="mv ' + (it[2] || '') + '">' + esc(it[1]) + '</div></div>';
    }).join('') + '</div>';
  }
  function flagsHtml(flags) {
    var col = { lo: '#15803d', md: '#b45309', hi: '#b91c1c' };
    return '<ul class="flags">' + flags.map(function (fl) {
      return '<li><span class="dot" style="background:' + col[fl[0]] + '"></span><span>' + esc(fl[1]) + '</span></li>';
    }).join('') + '</ul>';
  }
  function ctaHtml(cfg) {
    var label = cfg.brand ? (esc(cfg.brand) + '에 문의하기 →') : '전문가 상담 문의 →';
    if (cfg.cta) return '<a class="cta" href="' + esc(cfg.cta) + '" target="_blank" rel="noopener">' + label + '</a>';
    return '<button class="cta" type="button">' + label + '</button>';
  }
  function disc() { return '<p class="disc">본 결과는 시연용 샘플 데이터입니다. 실제 서비스에서는 등기부·실거래가 등 실데이터로 분석합니다. 참고용이며 법적 효력이 없습니다.</p>'; }

  // 시세조회 — 베스트라 실데이터(국토부 실거래가) 결과 렌더
  function renderPriceLive(d, cfg) {
    var trend = d.trend ? ((d.trend.up ? '▲ ' : '▼ ') + d.trend.pct + '% ' + (d.trend.up ? '상승' : '하락')) : '데이터 부족';
    var trendCls = d.trend ? (d.trend.up ? 'lo' : 'hi') : '';
    return head(['시세', cfg.accent], d.address, won(d.estimatedPrice)) +
      grid([
        ['최근 평균 실거래가', won(d.avgPrice), 'lo'],
        ['1년 추이', trend, trendCls],
        ['㎡당 평균가', d.perArea ? won(d.perArea) : '—', ''],
        ['거래 건수', d.transactionCount + '건 · ' + (d.period || ''), 'md']
      ]) +
      ctaHtml(cfg) +
      '<p class="disc">국토교통부 실거래가 기반 · 신뢰도 ' + (d.confidence || 0) + '% · 실거래 ' + d.transactionCount + '건 분석</p>';
  }
  function errHtml(msg) {
    return '<div style="padding:18px 2px;color:#b45309;font-size:13.5px;line-height:1.6">' + esc(msg || '조회에 실패했습니다.') + '</div>';
  }

  // ---- utils ----
  function valNum(el) { return el && el.value !== '' && +el.value > 0; }
  function won(n) {
    n = Math.round(n);
    if (n >= 100000000) { var eok = Math.floor(n / 100000000), man = Math.round((n % 100000000) / 10000); return eok + '억' + (man ? ' ' + man.toLocaleString() + '만' : '') + '원'; }
    if (n >= 10000) return Math.round(n / 10000).toLocaleString() + '만원';
    return n.toLocaleString() + '원';
  }
  function fnv(s) { var h = 2166136261; for (var i = 0; i < s.length; i++) { h ^= s.charCodeAt(i); h = (h + ((h << 1) + (h << 4) + (h << 7) + (h << 8) + (h << 24))) >>> 0; } return h >>> 0; }
  function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }
  function tint(hex) { var c = parse(hex); return 'rgba(' + c[0] + ',' + c[1] + ',' + c[2] + ',0.12)'; }
  function shade(hex, pct) { var c = parse(hex); return 'rgb(' + c.map(function (v) { return Math.max(0, Math.min(255, v + Math.round(255 * pct / 100))); }).join(',') + ')'; }
  function parse(hex) { hex = hex.replace('#', ''); if (hex.length === 3) hex = hex.split('').map(function (x) { return x + x; }).join(''); return [parseInt(hex.substr(0, 2), 16) || 13, parseInt(hex.substr(2, 2), 16) || 122, parseInt(hex.substr(4, 2), 16) || 110]; }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();
