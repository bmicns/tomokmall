<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';

require_admin();

render_admin_header('카테고리 관리', 'categories');
?>
<style>
.root-tabs{display:flex;gap:0;border-bottom:2px solid #e8eaed;margin-bottom:20px;flex-wrap:wrap}
.root-tab{padding:9px 20px;font-size:13px;font-weight:600;color:#888;border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px}
.root-tab.active{color:#004EA1;border-bottom-color:#004EA1}
.mid-block{border:1px solid #e8eaed;border-radius:8px;overflow:hidden;margin-bottom:10px}
.mid-row{display:flex;align-items:center;gap:8px;padding:10px 14px;background:#f8f9fb;border-bottom:1px solid #e8eaed}
.mid-name{font-size:14px;font-weight:700;flex:1}
.sub-list{padding:8px 14px;display:flex;flex-direction:column;gap:5px}
.sub-row{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f0f0f0}
.sub-row:last-child{border-bottom:none}
.sub-name{font-size:13px;flex:1;color:#444}
.sort-in{width:52px;height:28px;border:1px solid #e8eaed;border-radius:4px;padding:0 6px;font-size:12px;text-align:center}
.inactive .mid-name,.inactive .sub-name{color:#bbb;text-decoration:line-through}
.btn-xs{height:26px;padding:0 9px;font-size:11px;font-weight:600;border:none;border-radius:4px;cursor:pointer}
.btn-xs-add{background:#e8f0fe;color:#004EA1}
.btn-xs-edit{background:#f0f0f0;color:#555}
.btn-xs-on{background:#e8f5e9;color:#2e7d32}
.btn-xs-off{background:#fce4ec;color:#c62828}
.no-mid{padding:16px;font-size:13px;color:#aaa}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <div class="page-heading" style="margin:0">카테고리 관리</div>
</div>

<div class="root-tabs" id="root-tabs">
    <span style="padding:10px 14px;font-size:13px;color:#aaa">로딩 중...</span>
</div>
<div id="tree-area"><div style="padding:32px;text-align:center;color:#aaa;font-size:13px">대분류를 선택하세요.</div></div>

<!-- 추가/수정 모달 -->
<div id="cat-modal" class="modal-overlay">
    <div class="modal-box" style="width:360px">
        <button class="modal-close" onclick="closeMod()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <h3 id="cm-title">카테고리 추가</h3>
        <input type="hidden" id="cm-id">
        <input type="hidden" id="cm-pid">
        <div class="form-field">
            <label>이름 *</label>
            <input type="text" id="cm-name" placeholder="카테고리 이름">
        </div>
        <div class="form-field">
            <label>정렬 순서</label>
            <input type="number" id="cm-sort" value="0" min="0">
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeMod()">취소</button>
            <button class="btn btn-primary" onclick="saveCat()">저장</button>
        </div>
    </div>
</div>

<script>
const API = '/api/admin/categories.php';
let curRoot = null;

async function loadRoots() {
    const r = await fetch(API);
    const d = await r.json();
    if (!d.ok || !d.roots.length) return;
    const tabs = document.getElementById('root-tabs');
    tabs.innerHTML = d.roots.map(rt =>
        `<button class="root-tab" id="rt-${rt.id}" onclick="selectRoot(${rt.id})">${x(rt.name)}</button>`
    ).join('');
    selectRoot(d.roots[0].id);
}

async function selectRoot(rootId) {
    curRoot = rootId;
    document.querySelectorAll('.root-tab').forEach(t => t.classList.remove('active'));
    const tab = document.getElementById('rt-' + rootId);
    if (tab) tab.classList.add('active');
    document.getElementById('tree-area').innerHTML = '<div style="padding:24px;text-align:center;color:#aaa;font-size:13px">불러오는 중...</div>';
    const r = await fetch(`${API}?root_id=${rootId}`);
    const d = await r.json();
    if (!d.ok) return;
    renderTree(d.categories, rootId);
}

function renderTree(mids, rootId) {
    const area = document.getElementById('tree-area');
    if (!mids.length) {
        area.innerHTML = `<div class="no-mid">중분류가 없습니다.</div>
            <button class="btn btn-primary" style="height:32px;padding:0 14px;font-size:12px;margin-top:8px" onclick="openAdd(${rootId})">+ 중분류 추가</button>`;
        return;
    }
    let html = '';
    mids.forEach(mid => {
        const off = !mid.is_active || mid.is_active == 0;
        html += `<div class="mid-block">
            <div class="mid-row${off?' inactive':''}">
                <span class="mid-name">${x(mid.name)}</span>
                <input class="sort-in" type="number" value="${mid.sort_order}" min="0" onchange="updSort(${mid.id},this.value)" title="정렬">
                <button class="btn-xs btn-xs-add" onclick="openAdd(${mid.id})">+ 소분류</button>
                <button class="btn-xs btn-xs-edit" onclick="openEdit(${mid.id},'${x(mid.name)}',${mid.sort_order})">수정</button>
                <button class="btn-xs ${off?'btn-xs-on':'btn-xs-off'}" onclick="toggle(${mid.id})">${off?'활성화':'비활성'}</button>
            </div>`;
        if (mid.subs && mid.subs.length) {
            html += '<div class="sub-list">';
            mid.subs.forEach(sub => {
                const soff = !sub.is_active || sub.is_active == 0;
                html += `<div class="sub-row${soff?' inactive':''}">
                    <span class="sub-name">↳ ${x(sub.name)}</span>
                    <input class="sort-in" type="number" value="${sub.sort_order}" min="0" onchange="updSort(${sub.id},this.value)" title="정렬">
                    <button class="btn-xs btn-xs-edit" onclick="openEdit(${sub.id},'${x(sub.name)}',${sub.sort_order})">수정</button>
                    <button class="btn-xs ${soff?'btn-xs-on':'btn-xs-off'}" onclick="toggle(${sub.id})">${soff?'활성화':'비활성'}</button>
                </div>`;
            });
            html += '</div>';
        } else {
            html += '<div class="sub-list" style="color:#bbb;font-size:12px;padding:6px 0">소분류 없음</div>';
        }
        html += '</div>';
    });
    html += `<div style="margin-top:10px"><button class="btn btn-secondary" style="height:32px;padding:0 14px;font-size:12px" onclick="openAdd(${rootId})">+ 중분류 추가</button></div>`;
    area.innerHTML = html;
}

async function updSort(id, val) {
    await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'update', id, sort_order:+val})});
}

async function toggle(id) {
    const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'toggle', id})});
    const d = await r.json();
    if (!d.ok) { alert(d.error); return; }
    selectRoot(curRoot);
}

function openAdd(parentId) {
    document.getElementById('cm-title').textContent = '카테고리 추가';
    document.getElementById('cm-id').value   = '';
    document.getElementById('cm-pid').value  = parentId;
    document.getElementById('cm-name').value = '';
    document.getElementById('cm-sort').value = '0';
    document.getElementById('cat-modal').classList.add('open');
    setTimeout(() => document.getElementById('cm-name').focus(), 50);
}

function openEdit(id, name, sort) {
    document.getElementById('cm-title').textContent = '카테고리 수정';
    document.getElementById('cm-id').value   = id;
    document.getElementById('cm-pid').value  = '';
    document.getElementById('cm-name').value = name;
    document.getElementById('cm-sort').value = sort;
    document.getElementById('cat-modal').classList.add('open');
    setTimeout(() => document.getElementById('cm-name').focus(), 50);
}

function closeMod() { document.getElementById('cat-modal').classList.remove('open'); }

async function saveCat() {
    const id  = document.getElementById('cm-id').value;
    const pid = document.getElementById('cm-pid').value;
    const name = document.getElementById('cm-name').value.trim();
    const sort = +document.getElementById('cm-sort').value;
    if (!name) { alert('이름을 입력해 주세요.'); return; }
    const payload = id
        ? {action:'update', id:+id, name, sort_order:sort}
        : {action:'create', parent_id:+pid, name, sort_order:sort};
    const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
    const d = await r.json();
    if (!d.ok) { alert('저장 실패: ' + d.error); return; }
    closeMod();
    selectRoot(curRoot);
}

function x(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

document.getElementById('cat-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeMod(); });
loadRoots();
</script>
<?php render_admin_footer(); ?>
