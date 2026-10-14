// mypage.js — 마이페이지 전체 인터랙션
// MP_ORDERS, MP_INIT 는 mypage.php 인라인 <script>로 주입됨

function switchTab(tab) {
    document.querySelectorAll('.mp-tab-content').forEach(function(el){ el.style.display='none'; });
    document.getElementById('tab-'+tab).style.display='';
    document.querySelectorAll('.mp-tab').forEach(function(b){ b.classList.remove('active'); });
    event.target.classList.add('active');
    if (tab==='favorites') loadFavorites();
    if (tab==='sites') loadSites();
}

// ── 프로필 ────────────────────────────────────────────────
(async function() {
    try {
        const r = await fetch('/api/mypage/profile.php');
        const d = await r.json();
        if (d.ok && d.profile) {
            document.getElementById('p-phone').value        = d.profile.phone || '';
            document.getElementById('p-zip').value          = d.profile.address_zip || '';
            document.getElementById('p-addr').value         = d.profile.address || '';
            document.getElementById('p-addr-detail').value  = d.profile.address_detail || '';
        }
    } catch {}
})();

async function saveProfile() {
    const name       = document.getElementById('p-name').value.trim();
    const co         = document.getElementById('p-company').value.trim();
    const phone      = document.getElementById('p-phone').value.trim();
    const zip        = document.getElementById('p-zip').value.trim();
    const addr       = document.getElementById('p-addr').value.trim();
    const addrDetail = document.getElementById('p-addr-detail').value.trim();
    const msg  = document.getElementById('info-msg');
    const btn  = document.getElementById('info-btn');
    if (!name) { showMsg(msg,'err','이름을 입력해주세요.'); return; }
    btn.disabled=true; btn.textContent='저장 중...'; msg.style.display='none';
    try {
        const r = await fetch('/api/mypage/profile.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,company_name:co,phone,address_zip:zip,address:addr,address_detail:addrDetail})});
        const d = await r.json();
        if (d.ok) showMsg(msg,'ok','저장되었습니다.');
        else showMsg(msg,'err',d.error||'저장에 실패했습니다.');
    } catch { showMsg(msg,'err','네트워크 오류가 발생했습니다.'); }
    btn.disabled=false; btn.textContent='저장하기';
}

async function changePw() {
    const cur = document.getElementById('p-cur-pw').value;
    const np  = document.getElementById('p-new-pw').value;
    const np2 = document.getElementById('p-new-pw2').value;
    const msg = document.getElementById('pw-msg');
    const btn = document.getElementById('pw-btn');
    if (!cur||!np) { showMsg(msg,'err','현재/새 비밀번호를 모두 입력해주세요.'); return; }
    if (np.length<8) { showMsg(msg,'err','새 비밀번호는 8자 이상이어야 합니다.'); return; }
    if (np!==np2) { showMsg(msg,'err','새 비밀번호가 일치하지 않습니다.'); return; }
    btn.disabled=true; btn.textContent='변경 중...'; msg.style.display='none';
    const name       = document.getElementById('p-name').value.trim();
    const co         = document.getElementById('p-company').value.trim();
    const phone      = document.getElementById('p-phone').value.trim();
    const zip        = document.getElementById('p-zip').value.trim();
    const addr       = document.getElementById('p-addr').value.trim();
    const addrDetail = document.getElementById('p-addr-detail').value.trim();
    try {
        const r = await fetch('/api/mypage/profile.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({name,company_name:co,phone,address_zip:zip,address:addr,address_detail:addrDetail,current_password:cur,new_password:np})});
        const d = await r.json();
        if (d.ok) { showMsg(msg,'ok','비밀번호가 변경되었습니다.'); ['p-cur-pw','p-new-pw','p-new-pw2'].forEach(function(id){document.getElementById(id).value='';}); }
        else showMsg(msg,'err',d.error||'변경에 실패했습니다.');
    } catch { showMsg(msg,'err','네트워크 오류가 발생했습니다.'); }
    btn.disabled=false; btn.textContent='비밀번호 변경';
}

// ── 자주찾는 품목 ─────────────────────────────────────────
var favsLoaded = false;
async function loadFavorites() {
    if (favsLoaded) return;
    favsLoaded = true;
    const wrap = document.getElementById('fav-list-wrap');
    if (!wrap) return;
    try {
        const r = await fetch('/api/estimate/favorites.php');
        const d = await r.json();
        if (!d.ok || !d.favorites.length) { wrap.innerHTML='<div class="mp-empty">등록된 자주찾는 품목이 없습니다.</div>'; return; }
        wrap.innerHTML = '<div class="fav-list">' + d.favorites.map(function(f){
            return '<div class="fav-card" id="fav-'+f.id+'">'
                +'<div class="fav-info"><div class="fav-name">'+esc(f.name)+'</div><div class="fav-spec">'+esc(f.spec||'')+'</div></div>'
                +'<div class="fav-price">'+(f.price_b2b>0?f.price_b2b.toLocaleString()+'원':'문의')+'</div>'
                +'<button class="fav-del" onclick="delFav('+f.product_id+','+f.id+')" title="삭제">×</button>'
                +'</div>';
        }).join('') + '</div>';
    } catch { wrap.innerHTML='<div class="mp-empty">불러오기에 실패했습니다.</div>'; }
}

async function delFav(pid, fid) {
    try {
        await fetch('/api/estimate/favorites.php',{method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({product_id:pid})});
        const el = document.getElementById('fav-'+fid);
        if (el) el.remove();
    } catch {}
}

// ── 현장관리 ──────────────────────────────────────────────
var sitesLoaded = false;
var currentSiteId = null;

async function loadSites() {
    if (sitesLoaded) return;
    sitesLoaded = true;
    const wrap = document.getElementById('site-list-wrap');
    try {
        const r = await fetch('/api/sites/index.php');
        const d = await r.json();
        if (!d.ok) { wrap.innerHTML = '<div class="mp-empty">불러오기에 실패했습니다.</div>'; return; }
        renderSiteList(d.sites);
    } catch { wrap.innerHTML = '<div class="mp-empty">네트워크 오류가 발생했습니다.</div>'; }
}

function renderSiteList(sites) {
    const wrap = document.getElementById('site-list-wrap');
    if (!sites.length) { wrap.innerHTML = '<div class="mp-empty">등록된 현장이 없습니다.</div>'; return; }
    wrap.innerHTML = '<div class="site-list">' + sites.map(function(s) {
        return '<div class="site-card">'
            + '<div class="site-card-info">'
            + '<div class="site-card-name">' + esc(s.name) + '</div>'
            + (s.address ? '<div class="site-card-addr">' + esc(s.address) + '</div>' : '')
            + '<div class="site-card-stat">견적 ' + s.order_count + '건'
            + (s.total_amount > 0 ? ' · ' + Number(s.total_amount).toLocaleString() + '원' : '') + '</div>'
            + '</div>'
            + '<div class="site-card-actions">'
            + '<button class="site-card-btn" onclick="openSiteDetail(' + s.id + ',\'' + esc(s.name) + '\')">상세</button>'
            + '<button class="site-card-btn" onclick="openSiteForm(' + s.id + ',\'' + esc(s.name) + '\',\'' + esc(s.address||'') + '\',\'' + esc(s.memo||'') + '\')">수정</button>'
            + '<button class="site-card-btn danger" onclick="deleteSite(' + s.id + ')">삭제</button>'
            + '</div>'
            + '</div>';
    }).join('') + '</div>';
}

function openSiteForm(id, name, address, memo) {
    document.getElementById('sf-id').value      = id || '';
    document.getElementById('sf-name').value    = name || '';
    document.getElementById('sf-address').value = address || '';
    document.getElementById('sf-memo').value    = memo || '';
    document.getElementById('sf-msg').style.display = 'none';
    document.getElementById('site-form-title').textContent = id ? '현장 수정' : '현장 추가';
    document.getElementById('sf-btn').textContent = id ? '수정' : '저장';
    document.getElementById('site-form-wrap').style.display = '';
}

function closeSiteForm() {
    document.getElementById('site-form-wrap').style.display = 'none';
}

async function saveSite() {
    const id      = document.getElementById('sf-id').value;
    const name    = document.getElementById('sf-name').value.trim();
    const address = document.getElementById('sf-address').value.trim();
    const memo    = document.getElementById('sf-memo').value.trim();
    const msg     = document.getElementById('sf-msg');
    const btn     = document.getElementById('sf-btn');
    if (!name) { showMsg(msg, 'err', '현장명을 입력해주세요.'); return; }
    btn.disabled = true;
    try {
        const method  = id ? 'PUT' : 'POST';
        const payload = id ? {id: parseInt(id), name, address, memo} : {name, address, memo};
        const r = await fetch('/api/sites/index.php', {
            method, headers: {'Content-Type': 'application/json'}, body: JSON.stringify(payload)
        });
        const d = await r.json();
        if (d.ok) { closeSiteForm(); sitesLoaded = false; loadSites(); }
        else showMsg(msg, 'err', d.error || '저장에 실패했습니다.');
    } catch { showMsg(msg, 'err', '네트워크 오류가 발생했습니다.'); }
    btn.disabled = false;
}

async function deleteSite(id) {
    if (!confirm('현장을 삭제하면 연결된 견적 연결이 모두 해제됩니다. 삭제할까요?')) return;
    try {
        const r = await fetch('/api/sites/index.php', {
            method: 'DELETE', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({id})
        });
        const d = await r.json();
        if (d.ok) { sitesLoaded = false; loadSites(); }
        else alert(d.error || '삭제에 실패했습니다.');
    } catch { alert('네트워크 오류가 발생했습니다.'); }
}

async function openSiteDetail(siteId, siteName) {
    currentSiteId = siteId;
    document.getElementById('site-list-wrap').style.display = 'none';
    document.querySelector('.site-toolbar').style.display = 'none';
    document.getElementById('site-detail-name').textContent = siteName;
    document.getElementById('site-detail-wrap').style.display = '';

    const ordWrap = document.getElementById('site-detail-orders');
    ordWrap.innerHTML = '<div class="mp-empty">불러오는 중...</div>';
    try {
        const r = await fetch('/api/sites/orders.php?site_id=' + siteId);
        const d = await r.json();
        if (!d.ok) { ordWrap.innerHTML = '<div class="mp-empty">불러오기에 실패했습니다.</div>'; return; }
        if (!d.orders.length) {
            ordWrap.innerHTML = '<div class="mp-empty">연결된 견적이 없습니다.</div>';
        } else {
            const sLabels = {REQUESTED:'접수됨',WORKING:'작성중',SENT:'발송됨',APPROVED:'완료',CANCELED:'취소',DELIVERED:'납품완료'};
            ordWrap.innerHTML = '<div class="ord-list">' + d.orders.map(function(o) {
                const total = parseInt(o.total_amount);
                return '<div class="ord-card">'
                    + '<div class="ord-id">#' + String(o.id).padStart(5,'0') + '</div>'
                    + '<div class="ord-info"><div class="ord-date">' + esc(o.created_at.substr(0,10)) + ' 접수</div>'
                    + '<div class="ord-meta">품목 ' + o.item_cnt + '종</div></div>'
                    + '<div class="ord-amount">' + (total > 0 ? total.toLocaleString() + '원' : '담당자 문의') + '</div>'
                    + '<span>' + esc(sLabels[o.status] || o.status) + '</span>'
                    + '<button class="site-card-btn danger" onclick="unlinkOrder(' + siteId + ',' + o.id + ')">연결해제</button>'
                    + '</div>';
            }).join('') + '</div>';
        }
        renderLinkableOrders(siteId);
    } catch { ordWrap.innerHTML = '<div class="mp-empty">네트워크 오류가 발생했습니다.</div>'; }
}

function renderLinkableOrders(siteId) {
    const wrap = document.getElementById('site-link-list');
    wrap.innerHTML = '';
    (MP_ORDERS || []).forEach(function(o) {
        const div = document.createElement('div');
        div.className = 'site-link-item';
        div.dataset.estId = o.id;
        div.innerHTML = '<div class="site-link-info">#' + String(o.id).padStart(5,'0')
            + ' · ' + esc(o.created_at.substr(0,10)) + ' · 품목 ' + o.item_cnt + '종</div>'
            + '<button class="site-link-btn" onclick="linkOrder(' + siteId + ',' + o.id + ',this)">연결</button>';
        wrap.appendChild(div);
    });
    if (!wrap.children.length) wrap.innerHTML = '<div class="mp-empty">연결할 이전 주문이 없습니다.</div>';
}

function closeSiteDetail() {
    currentSiteId = null;
    document.getElementById('site-detail-wrap').style.display = 'none';
    document.getElementById('site-list-wrap').style.display = '';
    document.querySelector('.site-toolbar').style.display = '';
}

async function linkOrder(siteId, estimateId, btn) {
    btn.disabled = true; btn.textContent = '처리 중...';
    try {
        const r = await fetch('/api/sites/orders.php', {
            method: 'POST', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({site_id: siteId, estimate_id: estimateId})
        });
        const d = await r.json();
        if (d.ok) {
            btn.className = 'site-link-btn linked'; btn.textContent = '연결됨';
            openSiteDetail(siteId, document.getElementById('site-detail-name').textContent);
        } else { alert(d.error || '연결에 실패했습니다.'); btn.disabled = false; btn.textContent = '연결'; }
    } catch { alert('네트워크 오류가 발생했습니다.'); btn.disabled = false; btn.textContent = '연결'; }
}

async function unlinkOrder(siteId, estimateId) {
    if (!confirm('연결을 해제할까요?')) return;
    try {
        const r = await fetch('/api/sites/orders.php', {
            method: 'DELETE', headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({site_id: siteId, estimate_id: estimateId})
        });
        const d = await r.json();
        if (d.ok) openSiteDetail(siteId, document.getElementById('site-detail-name').textContent);
        else alert(d.error || '해제에 실패했습니다.');
    } catch { alert('네트워크 오류가 발생했습니다.'); }
}

// ── 재주문 ────────────────────────────────────────────────
async function reorder(estimateId) {
    const btn = event.target;
    const orig = btn.textContent;
    btn.disabled = true; btn.textContent = '처리 중...';
    try {
        const r = await fetch('/api/estimate/reorder.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({estimate_id: estimateId})
        });
        const d = await r.json();
        if (d.ok) { location.href = '/estimate-quick.php'; }
        else { alert(d.error || '재주문에 실패했습니다.'); btn.disabled = false; btn.textContent = orig; }
    } catch { alert('네트워크 오류가 발생했습니다.'); btn.disabled = false; btn.textContent = orig; }
}

// ── 공통 유틸 ─────────────────────────────────────────────
function showMsg(el,type,msg){ el.className='info-msg '+type; el.textContent=msg; el.style.display='block'; }
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

// ── 초기 탭 로드 ──────────────────────────────────────────
if (MP_INIT.activeTab === 'favorites') loadFavorites();
if (MP_INIT.activeTab === 'sites') loadSites();
