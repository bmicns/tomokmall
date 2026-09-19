<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';

require_admin();

$leafCats = db()->query(
    "SELECT c.id, c.name, p.name AS mid_name, gp.name AS root_name
     FROM categories c
     JOIN categories p  ON p.id  = c.parent_id
     JOIN categories gp ON gp.id = p.parent_id
     WHERE c.is_active = 1 AND p.parent_id IN (11,12,13,14,15)
     ORDER BY gp.sort_order, p.sort_order, c.sort_order, c.id"
)->fetchAll();
$catGroups = [];
foreach ($leafCats as $c) {
    $catGroups[$c['root_name']][$c['mid_name']][] = $c;
}

render_admin_header('제품관리', 'products');
?>
<style>
.prod-img-thumb{width:38px;height:30px;object-fit:cover;border-radius:4px}
.prod-no-img{width:38px;height:30px;background:#f0f2f5;border-radius:4px;display:inline-block}
.spec-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.spec-grid .sf{display:flex;flex-direction:column;gap:3px}
.spec-grid .sf span{font-size:11px;color:#666;font-weight:600}
.spec-grid .sf input{border:1px solid #e8eaed;border-radius:5px;padding:6px 9px;font-size:12px}
.empty-row{text-align:center;padding:32px;color:#aaa;font-size:13px}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <div class="page-heading" style="margin:0">제품관리</div>
    <button class="btn btn-primary" onclick="openModal()">+ 제품 등록</button>
</div>

<!-- 툴바 -->
<div class="toolbar">
    <select id="filter-status" onchange="load(1)">
        <option value="active">활성 제품</option>
        <option value="inactive">비활성 제품</option>
        <option value="all">전체</option>
    </select>
    <select id="filter-cat" onchange="load(1)">
        <option value="">전체 카테고리</option>
        <?php foreach ($catGroups as $rootName => $mids): ?>
        <optgroup label="<?= htmlspecialchars($rootName, ENT_QUOTES) ?>">
            <?php foreach ($mids as $midName => $subs): ?>
            <?php foreach ($subs as $sub): ?>
            <option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars($midName, ENT_QUOTES) ?> › <?= htmlspecialchars($sub['name'], ENT_QUOTES) ?></option>
            <?php endforeach; ?>
            <?php endforeach; ?>
        </optgroup>
        <?php endforeach; ?>
    </select>
    <input type="text" id="filter-q" placeholder="제품명 검색" oninput="debounce()">
</div>

<!-- 테이블 -->
<div class="card">
    <table class="adm-table">
        <thead>
            <tr>
                <th>이미지</th><th>제품명</th><th>카테고리</th><th>규격</th>
                <th>B2B단가</th><th>B2C단가</th><th>상차수량</th><th>상태</th><th>관리</th>
            </tr>
        </thead>
        <tbody id="prod-tbody">
            <tr><td colspan="9" class="empty-row">불러오는 중...</td></tr>
        </tbody>
    </table>
</div>
<div class="pagination" id="pagination"></div>

<!-- 등록/수정 모달 -->
<div id="prod-modal" class="modal-overlay">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
        </button>
        <h3 id="modal-title">제품 등록</h3>
        <input type="hidden" id="f-id">

        <div class="form-field">
            <label>카테고리 *</label>
            <select id="f-cat">
                <option value="">선택하세요</option>
                <?php foreach ($catGroups as $rootName => $mids): ?>
                <optgroup label="<?= htmlspecialchars($rootName, ENT_QUOTES) ?>">
                    <?php foreach ($mids as $midName => $subs): ?>
                    <?php foreach ($subs as $sub): ?>
                    <option value="<?= (int)$sub['id'] ?>"><?= htmlspecialchars($midName, ENT_QUOTES) ?> › <?= htmlspecialchars($sub['name'], ENT_QUOTES) ?></option>
                    <?php endforeach; ?>
                    <?php endforeach; ?>
                </optgroup>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="form-2col">
            <div class="form-field">
                <label>제품명 *</label>
                <input type="text" id="f-name" placeholder="예) U형수로관 300x300">
            </div>
            <div class="form-field">
                <label>브랜드</label>
                <input type="text" id="f-brand" placeholder="선택사항">
            </div>
        </div>

        <div class="form-field">
            <label>규격 (spec)</label>
            <div class="spec-grid">
                <div class="sf"><span>규격</span><input type="text" id="f-sv" placeholder="예) 300x300"></div>
                <div class="sf"><span>단위</span><input type="text" id="f-su" placeholder="예) 개"></div>
                <div class="sf"><span>중량</span><input type="text" id="f-sw" placeholder="예) 50kg"></div>
                <div class="sf"><span>상차수량</span><input type="text" id="f-sl" placeholder="예) 32"></div>
                <div class="sf"><span>운반비예상</span><input type="text" id="f-sf" placeholder="예) 50,000"></div>
                <div class="sf"><span>설치비예상</span><input type="text" id="f-si" placeholder="예) 100,000"></div>
            </div>
        </div>

        <div class="form-2col">
            <div class="form-field">
                <label>단가 B2B (원)</label>
                <input type="number" id="f-pb2b" placeholder="0" min="0">
            </div>
            <div class="form-field">
                <label>단가 B2C (원)</label>
                <input type="number" id="f-pb2c" placeholder="0" min="0">
            </div>
        </div>

        <div class="form-2col">
            <div class="form-field">
                <label>이미지 URL</label>
                <input type="text" id="f-img" placeholder="https://...">
            </div>
            <div class="form-field" id="f-active-wrap" style="display:none">
                <label>상태</label>
                <select id="f-active">
                    <option value="1">활성</option>
                    <option value="0">비활성</option>
                </select>
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal()">취소</button>
            <button class="btn btn-primary" onclick="save()">저장</button>
        </div>
    </div>
</div>

<script>
const API = '/api/admin/products.php';
let curPage = 1, debTimer;

function debounce() { clearTimeout(debTimer); debTimer = setTimeout(() => load(1), 380); }

async function load(page) {
    curPage = page;
    const status = document.getElementById('filter-status').value;
    const catId  = document.getElementById('filter-cat').value;
    const q      = document.getElementById('filter-q').value;
    document.getElementById('prod-tbody').innerHTML = '<tr><td colspan="9" class="empty-row">불러오는 중...</td></tr>';
    const r = await fetch(`${API}?status=${status}&cat_id=${catId}&q=${encodeURIComponent(q)}&page=${page}`);
    const d = await r.json();
    if (!d.ok) { alert(d.error); return; }
    render(d.products);
    renderPaging(d.total, d.limit, page);
}

function render(rows) {
    const tb = document.getElementById('prod-tbody');
    if (!rows.length) { tb.innerHTML = '<tr><td colspan="9" class="empty-row">제품이 없습니다.</td></tr>'; return; }
    tb.innerHTML = rows.map(p => {
        const sp = parseSpec(p.spec || '');
        const active = p.is_active == 1;
        return `<tr>
            <td>${p.image_url ? `<img src="${x(p.image_url)}" class="prod-img-thumb">` : '<span class="prod-no-img"></span>'}</td>
            <td><a href="/admin-product.php?id=${p.id}" style="color:#004EA1;font-weight:600;text-decoration:none">${x(p.name)}</a></td>
            <td style="font-size:12px;color:#888">${x(p.cat_name)}</td>
            <td style="font-size:12px;max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">${x(sp.spec || p.spec || '-')}</td>
            <td style="font-size:13px">${p.price_b2b ? Number(p.price_b2b).toLocaleString()+'원' : '-'}</td>
            <td style="font-size:13px">${p.price_b2c ? Number(p.price_b2c).toLocaleString()+'원' : '-'}</td>
            <td style="font-size:12px">${x(sp.load || '-')}</td>
            <td><span class="badge ${active?'badge-on':'badge-off'}">${active?'활성':'비활성'}</span></td>
            <td style="white-space:nowrap">
                <button class="btn-sm btn-sm-edit" onclick='edit(${JSON.stringify(p)})'>수정</button>
                <button class="btn-sm btn-sm-del" onclick="del(${p.id},'${x(p.name)}')" style="margin-left:4px">삭제</button>
            </td>
        </tr>`;
    }).join('');
}

function renderPaging(total, limit, cur) {
    const pages = Math.ceil(total / limit);
    const el = document.getElementById('pagination');
    if (pages <= 1) { el.innerHTML = ''; return; }
    el.innerHTML = Array.from({length: pages}, (_, i) =>
        `<button class="page-btn${i+1===cur?' active':''}" onclick="load(${i+1})">${i+1}</button>`
    ).join('');
}

function x(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function parseSpec(str) {
    const r = {spec:'',unit:'',weight:'',load:'',freight:'',install:''};
    (str||'').split('|').forEach(p => {
        const m = p.trim().match(/^(.+?):\s*(.+)$/);
        if (!m) return;
        if (m[1]==='규격')       r.spec    = m[2];
        else if(m[1]==='단위')   r.unit    = m[2];
        else if(m[1]==='중량')   r.weight  = m[2];
        else if(m[1]==='상차수량') r.load   = m[2];
        else if(m[1]==='운반비예상') r.freight = m[2];
        else if(m[1]==='설치비예상') r.install  = m[2];
    });
    return r;
}

function buildSpec() {
    const parts = [];
    const vals = [
        ['규격', 'f-sv'], ['단위', 'f-su'], ['중량', 'f-sw'],
        ['상차수량', 'f-sl'], ['운반비예상', 'f-sf'], ['설치비예상', 'f-si']
    ];
    vals.forEach(([k, id]) => {
        const v = document.getElementById(id).value.trim();
        if (v) parts.push(k + ':' + v);
    });
    return parts.join('|');
}

function openModal(data) {
    document.getElementById('modal-title').textContent = data ? '제품 수정' : '제품 등록';
    document.getElementById('f-id').value    = data?.id ?? '';
    document.getElementById('f-cat').value   = data?.category_id ?? '';
    document.getElementById('f-name').value  = data?.name ?? '';
    document.getElementById('f-brand').value = data?.brand ?? '';
    document.getElementById('f-pb2b').value  = data?.price_b2b ?? '';
    document.getElementById('f-pb2c').value  = data?.price_b2c ?? '';
    document.getElementById('f-img').value   = data?.image_url ?? '';
    document.getElementById('f-active').value = data?.is_active ?? '1';
    document.getElementById('f-active-wrap').style.display = data ? 'block' : 'none';
    const sp = parseSpec(data?.spec ?? '');
    document.getElementById('f-sv').value = sp.spec;
    document.getElementById('f-su').value = sp.unit;
    document.getElementById('f-sw').value = sp.weight;
    document.getElementById('f-sl').value = sp.load;
    document.getElementById('f-sf').value = sp.freight;
    document.getElementById('f-si').value = sp.install;
    document.getElementById('prod-modal').classList.add('open');
}

function edit(p) { openModal(p); }
function closeModal() { document.getElementById('prod-modal').classList.remove('open'); }

async function save() {
    const id = document.getElementById('f-id').value;
    const payload = {
        action:      id ? 'update' : 'create',
        id:          id ? +id : undefined,
        category_id: +document.getElementById('f-cat').value,
        name:        document.getElementById('f-name').value.trim(),
        brand:       document.getElementById('f-brand').value.trim(),
        spec:        buildSpec(),
        price_b2b:   +document.getElementById('f-pb2b').value,
        price_b2c:   +document.getElementById('f-pb2c').value,
        image_url:   document.getElementById('f-img').value.trim(),
        is_active:   +document.getElementById('f-active').value,
    };
    if (!payload.name) { alert('제품명을 입력해 주세요.'); return; }
    if (!payload.category_id) { alert('카테고리를 선택해 주세요.'); return; }
    const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
    const d = await r.json();
    if (!d.ok) { alert('저장 실패: ' + d.error); return; }
    closeModal();
    load(curPage);
}

async function del(id, name) {
    if (!confirm(`"${name}" 제품을 삭제(비활성화)하시겠습니까?`)) return;
    const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'delete', id})});
    const d = await r.json();
    if (!d.ok) { alert('삭제 실패: ' + d.error); return; }
    load(curPage);
}

document.getElementById('prod-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
load(1);
</script>
<?php render_admin_footer(); ?>
