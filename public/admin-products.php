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
/* 일괄 등록(상용콘크리트 syc_products) — 디자인 '제품관리(일괄 등록)' */
.btn-outline{display:inline-flex;align-items:center;gap:6px;background:#fff;color:#1a2035;border:1px solid #c5cad3;white-space:nowrap}
#btn-create{white-space:nowrap}
.prod-table-wrap{overflow-x:auto}   /* 좁은 화면: 표만 옆으로 넘김(페이지 전체가 넓어지지 않게) */
#filter-cat,#filter-syc-cat,#filter-q{max-width:100%}   /* 긴 카테고리 이름이 좁은 화면 폭을 넘지 않게 */
@media (max-width:640px){#filter-q,.toolbar select{min-width:0;flex:1 1 140px}}   /* 좁은 화면에서 검색칸·선택칸이 폭을 넘지 않게 */
.btn-outline:hover{border-color:#004EA1;color:#004EA1}
.bk-banner{display:flex;align-items:center;gap:10px;padding:12px 16px;margin-bottom:16px;border:1px solid #b9ddbd;border-radius:8px;background:#f1f8f2;font-size:13px;flex-wrap:wrap}
.bk-banner[hidden]{display:none}
.bk-banner button{margin-left:auto;background:none;border:none;color:#004EA1;font-weight:700;font-size:13px;cursor:pointer;min-height:32px;font-family:inherit}
.bk-box{width:640px;padding:0;display:flex;flex-direction:column}
.bk-h{display:flex;align-items:center;padding:18px 22px;border-bottom:1px solid #e8eaed}
.bk-h h3{margin:0;font-size:17px;font-weight:800}
.bk-x{margin-left:auto;width:36px;height:36px;border:0;border-radius:6px;background:transparent;font-size:22px;line-height:1;color:#666;cursor:pointer}
.bk-x:hover{background:#f0f2f5}
.bk-b{padding:18px 22px;display:flex;flex-direction:column;gap:16px}
.bk-note{font-size:12.5px;line-height:1.6;color:#555}
.bk-fld{display:flex;flex-direction:column;gap:8px}
.bk-fld-h{font-size:13px;font-weight:700}
.bk-fld-h em{font-style:normal;margin-left:4px;font-size:11px;font-weight:700;color:#c62828}
.bk-fld-h small{margin-left:6px;font-size:11.5px;font-weight:600;color:#777}
.bk-drop{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:6px;width:100%;min-height:118px;padding:14px;border:1.5px dashed #c5cad3;border-radius:8px;background:#f8f9fb;cursor:pointer;font-family:inherit;text-align:center}
.bk-drop:hover,.bk-drop.over{border-color:#004EA1;background:#f5f9ff}
.bk-drop-t{font-size:13px;font-weight:600;color:#1a2035}
.bk-drop-btn{display:inline-flex;align-items:center;height:32px;padding:0 12px;border-radius:6px;background:#004EA1;color:#fff;font-size:12.5px;font-weight:600}
.bk-file{display:flex;align-items:center;gap:12px;padding:12px 12px 12px 14px;border:1px solid #e8eaed;border-radius:8px}
.bk-ic{width:38px;height:38px;flex-shrink:0;border-radius:8px;display:inline-flex;align-items:center;justify-content:center;background:#e8f0fe;color:#004EA1}
.bk-file.done .bk-ic{background:#e8f5e9;color:#2e7d32}
.bk-file.err .bk-ic{background:#fce4ec;color:#c62828}
.bk-ft{flex:1;min-width:0;display:flex;flex-direction:column;gap:2px}
.bk-ft b{font-size:13.5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.bk-ft span{font-size:12px;color:#555}
.bk-st{font-size:12.5px;font-weight:700;color:#004EA1;white-space:nowrap}
.bk-fx{width:36px;height:36px;flex-shrink:0;border:0;border-radius:6px;background:transparent;color:#777;font-size:18px;cursor:pointer}
.bk-fx:hover{background:#f0f2f5;color:#c62828}
.bk-bar{height:6px;border-radius:3px;background:#e8f0fe;overflow:hidden}.bk-bar div{height:100%;width:0;background:#004EA1;transition:width .2s}
.bk-errs{margin:0;padding:10px 12px 10px 28px;border-radius:6px;background:#fdf2f2;color:#9b1c1c;font-size:12px;line-height:1.6;max-height:140px;overflow:auto}
.bk-f{display:flex;align-items:center;gap:8px;padding:14px 22px;border-top:1px solid #e8eaed;background:#fafbfc;flex-wrap:wrap}
.bk-hint{flex:1 1 200px;font-size:12px;color:#666}
.bk-f .btn{height:40px;font-size:14px}
.bk-f .btn-primary:disabled{background:#c5cad3;cursor:not-allowed}
.bk-run{padding:28px 22px}
.bk-done{align-items:center;text-align:center;padding:30px 22px 22px}
.bk-done-t{font-size:17px;font-weight:800}
.bk-done-s{font-size:13px;line-height:1.6;color:#555}
.bk-hide{display:none!important}
.syc-bulkbar{display:flex;align-items:center;gap:8px 12px;flex-wrap:wrap;margin:-4px 0 12px;font-size:13px}
.syc-bulkbar label{display:inline-flex;align-items:center;gap:6px;min-height:36px;cursor:pointer}
.syc-bulkbar input,.syc-chk{width:18px;height:18px;cursor:pointer}
.syc-sel{display:inline-flex;align-items:center;justify-content:center;min-width:36px;min-height:36px;cursor:pointer;vertical-align:middle}
.syc-all-link{min-height:36px;padding:0 4px;border:0;background:none;color:#004EA1;font-size:13px;font-weight:600;text-decoration:underline;cursor:pointer;font-family:inherit}
.syc-all-on #syc-sel-count{color:#004EA1;font-weight:700}
.syc-brand{display:block;font-size:11.5px;font-weight:500;color:#888;margin-top:2px}
@media (max-width:640px){.bk-b,.bk-h,.bk-f{padding-left:16px;padding-right:16px}.bk-hint{flex-basis:100%}}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:20px">
    <div class="page-heading" style="margin:0">제품관리</div>
    <div style="display:flex;gap:8px;flex-wrap:wrap">
        <button type="button" class="btn btn-outline" onclick="bkOpen()">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>일괄 등록
        </button>
        <button type="button" class="btn btn-primary" id="btn-create" onclick="openModal()">+ 제품 등록</button>
    </div>
</div>

<!-- 일괄 등록 뒤 안내 -->
<div class="bk-banner" id="bk-banner" role="status" hidden>
    <span id="bk-banner-text"></span>
    <button type="button" onclick="bkShowInactive()">비활성 제품 보기</button>
</div>

<!-- 툴바 -->
<div class="toolbar">
    <select id="filter-source" onchange="sourceChanged()" aria-label="구분">
        <option value="products">기존 제품</option>
        <option value="syc">상용콘크리트</option>
    </select>
    <select id="filter-status" onchange="load(1)" aria-label="상태">
        <option value="active">활성 제품</option>
        <option value="inactive">비활성 제품</option>
        <option value="all">전체</option>
    </select>
    <select id="filter-syc-cat" onchange="load(1)" aria-label="카테고리" class="bk-hide">
        <option value="">전체 카테고리</option>
    </select>
    <select id="filter-cat" onchange="load(1)" aria-label="카테고리">
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

<!-- 상용콘크리트: 여러 상품 한 번에 활성/비활성 -->
<div class="syc-bulkbar bk-hide" id="syc-bulkbar">
    <label><input type="checkbox" id="syc-chk-all" onchange="sycCheckAll(this.checked)">이 페이지 전체 선택</label>
    <span id="syc-sel-count" aria-live="polite">선택 0개</span>
    <button type="button" class="syc-all-link" id="syc-all-btn" onclick="sycSelectAllMatching(!sycAllMatching)" hidden></button>
    <button type="button" class="btn-sm btn-sm-edit" onclick="sycBulk(1)" disabled id="syc-bulk-on">선택 활성으로</button>
    <button type="button" class="btn-sm btn-sm-del" onclick="sycBulk(0)" disabled id="syc-bulk-off">선택 비활성으로</button>
</div>

<!-- 테이블 -->
<div class="card prod-table-wrap">
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

<!-- 일괄 등록 모달: 엑셀(필수) + 이미지 ZIP(선택) → 상용콘크리트 상품(syc_products) -->
<div id="bk-modal" class="modal-overlay">
    <div class="modal-box bk-box" role="dialog" aria-modal="true" aria-labelledby="bk-title">
        <div class="bk-h">
            <h3 id="bk-title">일괄 등록</h3>
            <button type="button" class="bk-x" aria-label="닫기" onclick="bkClose()">×</button>
        </div>
        <div id="bk-form">
            <div class="bk-b">
                <div class="bk-note">엑셀과 이미지를 나눠서 올리면 <strong>상용콘크리트</strong> 상품을 한 번에 등록합니다. 새 상품은 <strong>비활성</strong>으로 저장되며, 상품 목록에서 확인한 뒤 활성으로 바꿀 수 있습니다. 출처ID가 같은 상품은 엑셀 값으로 고칩니다(활성 상태는 그대로).</div>
                <div class="bk-fld">
                    <div class="bk-fld-h">엑셀 파일<em>필수</em></div>
                    <div id="bk-ex"></div>
                </div>
                <div class="bk-fld">
                    <div class="bk-fld-h">이미지 ZIP<small>선택 · 대표·썸네일 이미지</small></div>
                    <div id="bk-zp"></div>
                </div>
            </div>
            <div class="bk-f">
                <span class="bk-hint" id="bk-hint"></span>
                <button type="button" class="btn btn-secondary" onclick="bkClose()">취소</button>
                <button type="button" class="btn btn-primary" id="bk-go" onclick="bkRegister()" disabled>등록하기</button>
            </div>
        </div>
        <div id="bk-running" class="bk-b bk-run bk-hide" role="status">
            <div style="font-size:15px;font-weight:700" id="bk-run-t">등록 중</div>
            <div class="bk-bar" style="height:8px"><div id="bk-run-bar"></div></div>
            <div style="font-size:12.5px;color:#555">이미지 저장 포함 · 창을 닫아도 계속됩니다</div>
        </div>
        <div id="bk-done" class="bk-hide">
            <div class="bk-b bk-done" role="status">
                <svg width="44" height="44" viewBox="0 0 24 24" fill="none" stroke="#2E7D32" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="10"/><polyline points="8 12.5 11 15.5 16 9.5"/></svg>
                <div class="bk-done-t" id="bk-done-t"></div>
                <div class="bk-done-s" id="bk-done-s"></div>
            </div>
            <div class="bk-f" style="justify-content:flex-end;background:transparent">
                <button type="button" class="btn btn-primary" onclick="bkFinish()">확인</button>
            </div>
        </div>
    </div>
</div>
<input type="file" id="bk-ex-input" accept=".xlsx,.csv" class="bk-hide" onchange="bkPicked('ex', this.files[0]); this.value=''">
<input type="file" id="bk-zp-input" accept=".zip" class="bk-hide" onchange="bkPicked('zp', this.files[0]); this.value=''">

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

const SYC_API = '/api/admin/syc-products.php';
function isSyc() { return document.getElementById('filter-source').value === 'syc'; }

let sycCatsLoaded = false;
async function sourceChanged() {
    const syc = isSyc();
    document.getElementById('filter-cat').classList.toggle('bk-hide', syc);
    document.getElementById('filter-syc-cat').classList.toggle('bk-hide', !syc);
    document.getElementById('btn-create').classList.toggle('bk-hide', syc);   // 상용콘크리트는 일괄 등록으로만 넣음
    document.getElementById('syc-bulkbar').classList.toggle('bk-hide', !syc);
    if (syc && !sycCatsLoaded) {
        const d = await fetch(`${SYC_API}?action=categories`).then(r => r.json()).catch(() => ({ok:false}));
        if (d.ok) {
            const groups = {};
            d.categories.forEach(c => (groups[c.group_name] ??= []).push(c));
            document.getElementById('filter-syc-cat').innerHTML = '<option value="">전체 카테고리</option>' +
                Object.entries(groups).map(([g, cs]) => `<optgroup label="${x(g)}">${cs.map(c => `<option value="${c.id}">${x(c.name)}</option>`).join('')}</optgroup>`).join('');
            sycCatsLoaded = true;
        }
    }
    load(1);
}

async function load(page) {
    curPage = page;
    const syc    = isSyc();
    const status = document.getElementById('filter-status').value;
    const catId  = document.getElementById(syc ? 'filter-syc-cat' : 'filter-cat').value;
    const q      = document.getElementById('filter-q').value;
    document.getElementById('prod-tbody').innerHTML = '<tr><td colspan="9" class="empty-row">불러오는 중...</td></tr>';
    const r = await fetch(`${syc ? SYC_API : API}?status=${status}&cat_id=${catId}&q=${encodeURIComponent(q)}&page=${page}`);
    // 오류는 200 + ok:false + error 로 온다(닷홈 4xx 본문 교체 회피). 본문이 JSON 이 아니면 일반 문구
    const d = await r.json().catch(() => ({ok:false, error:'요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'}));
    if (!d.ok) { alert(d.error); return; }
    if (syc) { sycTotal = d.total; sycFilter = {status, cat_id: catId, q}; sycAllMatching = false; renderSyc(d.products); sycSelCount(); } else render(d.products);
    renderPaging(d.total, d.limit, page);
}

// 상용콘크리트 목록: 썸네일이 없으면 기존 빈 이미지 표시(.prod-no-img) 그대로
function renderSyc(rows) {
    const tb = document.getElementById('prod-tbody');
    if (!rows.length) { tb.innerHTML = '<tr><td colspan="9" class="empty-row">제품이 없습니다.</td></tr>'; return; }
    tb.innerHTML = rows.map(p => {
        const active = p.is_active == 1;
        return `<tr>
            <td>${p.image_thumb_file ? `<img src="${x(p.image_thumb_file)}" class="prod-img-thumb" alt="">` : '<span class="prod-no-img"></span>'}</td>
            <td style="font-weight:600;color:#1a2035">${x(p.name)}${p.brand ? `<span class="syc-brand">${x(p.brand)}</span>` : ''}</td>
            <td style="font-size:12px;color:#888">${x(p.cat_name)}</td>
            <td style="font-size:12px;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${x(p.spec)}">${x(p.spec || '-')}</td>
            <td style="font-size:13px">${+p.price_b2b ? Number(p.price_b2b).toLocaleString()+'원' : '-'}</td>
            <td style="font-size:13px">${+p.price_b2c ? Number(p.price_b2c).toLocaleString()+'원' : '-'}</td>
            <td style="font-size:12px">-</td>
            <td><span class="badge ${active?'badge-on':'badge-off'}">${active?'활성':'비활성'}</span></td>
            <td style="white-space:nowrap">
                <label class="syc-sel"><input type="checkbox" class="syc-chk" value="${+p.id}" aria-label="${x(p.name)} ${x(p.spec)} 선택" onchange="sycSelCount()"></label>
                <button class="btn-sm ${active?'btn-sm-del':'btn-sm-edit'}" onclick="sycToggle(${+p.id}, ${active?0:1})">${active?'비활성으로':'활성으로'}</button>
            </td>
        </tr>`;
    }).join('');
}

// 선택: 이 페이지 체크 + '검색 결과 전체 N개 선택'(모든 페이지, 지금 목록 조건 그대로 — 서버가 같은 조건으로 바꿈)
let sycTotal = 0, sycFilter = null, sycAllMatching = false;
function sycSelCount() {
    const all = document.querySelectorAll('.syc-chk');
    const n = document.querySelectorAll('.syc-chk:checked').length;
    const pageAll = all.length > 0 && n === all.length;
    if (!pageAll) sycAllMatching = false;            // 하나라도 해제하면 '전체 선택'도 풀림
    const shown = sycAllMatching ? sycTotal : n;
    document.getElementById('syc-sel-count').textContent = sycAllMatching ? `검색 결과 전체 ${shown.toLocaleString()}개 선택됨` : `선택 ${n}개`;
    document.getElementById('syc-bulkbar').classList.toggle('syc-all-on', sycAllMatching);
    document.getElementById('syc-bulk-on').disabled = document.getElementById('syc-bulk-off').disabled = shown === 0;
    // 상태 드롭다운: 비활성 제품 → '선택 활성으로'만, 활성 제품 → '선택 비활성으로'만, 전체 → 둘 다
    const st = document.getElementById('filter-status').value;
    document.getElementById('syc-bulk-on').hidden  = st === 'active';
    document.getElementById('syc-bulk-off').hidden = st === 'inactive';
    document.getElementById('syc-chk-all').checked = pageAll;
    const btn = document.getElementById('syc-all-btn');
    btn.hidden = !(pageAll && sycTotal > all.length);    // 여러 페이지일 때만
    btn.textContent = sycAllMatching ? '선택 해제' : `검색 결과 전체 ${sycTotal.toLocaleString()}개 선택`;
}
function sycCheckAll(on) {
    document.querySelectorAll('.syc-chk').forEach(c => { c.checked = on; });
    sycSelCount();
}
function sycSelectAllMatching(on) {
    sycAllMatching = on;
    sycCheckAll(on);
}
async function sycBulk(val) {
    const word = val ? '활성' : '비활성';
    if (sycAllMatching) {
        if (!confirm(`검색 결과 전체 ${sycTotal.toLocaleString()}개(모든 페이지)를 ${word}으로 바꿀까요?`)) return;
        const r = await fetch(SYC_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'set_active_filter', filter:sycFilter, expect:sycTotal, is_active:val})});
        const d = await r.json().catch(() => ({ok:false, error:'요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'}));
        if (!d.ok) { alert(d.error); load(curPage); return; }
        load(1);
        return;
    }
    const ids = [...document.querySelectorAll('.syc-chk:checked')].map(c => +c.value);
    if (!ids.length) return;
    if (!confirm(`선택한 ${ids.length}개를 ${word}으로 바꿀까요?`)) return;
    await sycToggle(ids, val);
}

async function sycToggle(id, val) {
    const ids = Array.isArray(id) ? id : [id];
    const r = await fetch(SYC_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'set_active', ids, is_active:val})});
    const d = await r.json().catch(() => ({ok:false, error:'요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'}));
    if (!d.ok) { alert(d.error); return; }
    load(curPage);
}

/* ── 일괄 등록 ── 상태: empty → busy(올리는 중) → done(검증 완료) | err */
const bk = {ex: {state:'empty'}, zp: {state:'empty'}};
const BK_IC = {
    ex: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="8" y1="13" x2="16" y2="13"/><line x1="8" y1="17" x2="16" y2="17"/></svg>',
    zp: '<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" aria-hidden="true"><path d="M21 8v13H3V8"/><rect x="1" y="3" width="22" height="5"/><line x1="10" y1="12" x2="14" y2="12"/></svg>'
};
const BK_LABEL = {ex: ['엑셀 파일(csv, xlsx)을 끌어다 놓거나', '엑셀 파일'], zp: ['이미지 ZIP 파일을 끌어다 놓거나', '이미지 ZIP']};
function bkSize(n) { return n >= 1048576 ? (n/1048576).toFixed(1)+' MB' : Math.max(1, Math.round(n/1024))+' KB'; }

function bkOpen() {
    bk.ex = {state:'empty'}; bk.zp = {state:'empty'};
    ['bk-running','bk-done'].forEach(id => document.getElementById(id).classList.add('bk-hide'));
    document.getElementById('bk-form').classList.remove('bk-hide');
    bkRender();
    document.getElementById('bk-modal').classList.add('open');
}
function bkClose() {
    if (!document.getElementById('bk-running').classList.contains('bk-hide')) { document.getElementById('bk-modal').classList.remove('open'); return; }
    ['ex','zp'].forEach(k => { bk[k].xhr?.abort(); });
    document.getElementById('bk-modal').classList.remove('open');
}

function bkRender() {
    ['ex','zp'].forEach(k => {
        const s = bk[k], el = document.getElementById('bk-' + k);
        if (s.state === 'empty') {
            el.innerHTML = `<button type="button" class="bk-drop" data-k="${k}" onclick="document.getElementById('bk-${k}-input').click()">
                <span style="color:#004EA1">${BK_IC[k]}</span><span class="bk-drop-t">${BK_LABEL[k][0]}</span><span class="bk-drop-btn">파일 선택</span></button>`;
            const drop = el.firstElementChild;
            drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
            drop.addEventListener('dragleave', () => drop.classList.remove('over'));
            drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); bkPicked(k, e.dataTransfer.files[0]); });
            return;
        }
        const busy = s.state === 'busy', cls = s.state === 'done' ? ' done' : (s.state === 'err' ? ' err' : '');
        let sub = bkSize(s.size), st = '';
        if (busy) st = s.pct < 100 ? `업로드 중 ${s.pct}%` : '확인 중…';
        else if (s.state === 'done') sub += k === 'ex' ? ` · ${Number(s.rows).toLocaleString()}행 업로드 완료` : ` · 이미지 파일 ${Number(s.images).toLocaleString()}개 업로드 완료`;
        else sub += ' · ' + s.error;
        el.innerHTML = `<div class="bk-file${cls}"><span class="bk-ic">${BK_IC[k]}</span><span class="bk-ft"><b>${x(s.name)}</b><span>${x(sub)}</span></span>`
            + (st ? `<span class="bk-st">${st}</span>` : '')
            + `<button type="button" class="bk-fx" aria-label="${BK_LABEL[k][1]} ${busy?'업로드 취소':'파일 삭제'}" onclick="bkClear('${k}')">×</button></div>`
            + (busy ? `<div class="bk-bar" role="progressbar" aria-label="${BK_LABEL[k][1]} 업로드 진행률" aria-valuemin="0" aria-valuemax="100" aria-valuenow="${s.pct}"><div style="width:${s.pct}%"></div></div>` : '')
            + (s.errors?.length ? `<ul class="bk-errs">${s.errors.map(e => `<li>${x(e)}</li>`).join('')}${s.errorCount > s.errors.length ? `<li>…외 ${s.errorCount - s.errors.length}행</li>` : ''}</ul>` : '');
    });
    const exOk = bk.ex.state === 'done', zpBusy = bk.zp.state === 'busy', ready = exOk && !zpBusy && bk.zp.state !== 'err';
    let hint = '';
    if (bk.ex.state === 'err') hint = '엑셀 오류를 고친 뒤 다시 올려 주세요.';
    else if (!exOk) hint = '엑셀 파일을 올리면 등록할 수 있습니다.';
    else if (zpBusy) hint = '이미지 업로드가 끝나면 등록할 수 있습니다.';
    else if (bk.zp.state === 'err') hint = '이미지 ZIP 을 다시 올리거나 지워 주세요.';
    else if (bk.zp.state === 'empty') hint = '이미지 없이 등록합니다. 이미지는 나중에 다시 올릴 수 있습니다.';
    document.getElementById('bk-hint').textContent = hint;
    const go = document.getElementById('bk-go');
    go.disabled = !ready;
    go.textContent = exOk ? `${Number(bk.ex.rows).toLocaleString()}건 등록하기` : '등록하기';
}

function bkPicked(k, file) {
    if (!file) return;
    bk[k].xhr?.abort();
    const s = bk[k] = {state:'busy', name:file.name, size:file.size, pct:0};
    const fd = new FormData();
    fd.append('action', k === 'ex' ? 'stage_excel' : 'stage_zip');
    fd.append('file', file);
    const xhr = s.xhr = new XMLHttpRequest();
    xhr.open('POST', SYC_API);
    xhr.upload.onprogress = e => { if (e.lengthComputable && bk[k] === s) { s.pct = Math.floor(e.loaded / e.total * 100); bkRender(); } };
    xhr.onload = () => {
        if (bk[k] !== s) return;
        let d; try { d = JSON.parse(xhr.responseText); } catch (e) { d = {ok:false, error:'요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'}; }
        if (!d.ok) Object.assign(s, {state:'err', error:d.error});
        else if (k === 'ex' && d.error_count > 0) Object.assign(s, {state:'err', error:`오류 ${d.error_count}행`, errors:d.errors, errorCount:d.error_count});
        else Object.assign(s, {state:'done', rows:d.rows, images:d.images});
        bkRender();
    };
    xhr.onerror = () => { if (bk[k] === s) { Object.assign(s, {state:'err', error:'업로드에 실패했습니다.'}); bkRender(); } };
    xhr.send(fd);
    bkRender();
}

function bkClear(k) {
    bk[k].xhr?.abort();
    bk[k] = {state:'empty'};
    fetch(SYC_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'clear', slot: k === 'ex' ? 'excel' : 'zip'})}).catch(() => {});
    bkRender();
}

async function bkRegister() {
    const total = bk.ex.rows;
    const job = Array.from(crypto.getRandomValues(new Uint8Array(12)), b => b.toString(16).padStart(2, '0')).join('');
    document.getElementById('bk-form').classList.add('bk-hide');
    document.getElementById('bk-running').classList.remove('bk-hide');
    const setRun = (done) => {
        document.getElementById('bk-run-t').textContent = `등록 중 — ${Number(done).toLocaleString()} / ${Number(total).toLocaleString()}건`;
        document.getElementById('bk-run-bar').style.width = (total ? Math.floor(done / total * 100) : 0) + '%';
    };
    setRun(0);
    const timer = setInterval(async () => {
        const p = await fetch(`${SYC_API}?action=progress&job=${job}`).then(r => r.json()).catch(() => null);
        if (p?.ok && p.progress && !p.progress.finished) setRun(p.progress.done);
    }, 800);
    const r = await fetch(SYC_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'commit', job})});
    clearInterval(timer);
    const d = await r.json().catch(() => ({ok:false, error:'요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'}));
    document.getElementById('bk-running').classList.add('bk-hide');
    if (!d.ok) {
        alert('등록 실패: ' + d.error);
        bkOpen();
        return;
    }
    const n = d.insert + d.update + d.same;
    document.getElementById('bk-done-t').textContent = `${n.toLocaleString()}건을 등록했습니다`;
    const parts = [`새 상품 ${d.insert.toLocaleString()}건은 <strong style="color:#1a2035">비활성</strong>으로 저장됐습니다.`];
    if (d.update || d.same) parts.push(`이미 있던 상품 ${(d.update + d.same).toLocaleString()}건은 엑셀 값으로 고쳤습니다(활성 상태 그대로).`);
    parts.push(d.with_zip
        ? `이미지 ${d.images.toLocaleString()}장 저장` + (d.images_missing ? ` · 대표 이미지 없음 ${d.images_missing.toLocaleString()}건` : '') + (d.thumbs_missing ? ` · 썸네일 없음 ${d.thumbs_missing.toLocaleString()}건(빈 이미지로 표시)` : '')
        : '이미지 ZIP 없이 등록했습니다(이미 있던 이미지는 그대로, 새 상품은 빈 이미지로 표시).');
    parts.push('제품관리에서 내용을 확인한 뒤 활성으로 바꿔 주세요.');
    document.getElementById('bk-done-s').innerHTML = parts.join('<br>');
    document.getElementById('bk-done').classList.remove('bk-hide');
    bk.last = d;
    if (!document.getElementById('bk-modal').classList.contains('open')) bkFinish();   // 창을 닫은 뒤 끝나면 안내 띠만
}

function bkFinish() {
    document.getElementById('bk-modal').classList.remove('open');
    const d = bk.last;
    if (!d) return;
    const all = d.insert + d.update + d.same;
    document.getElementById('bk-banner-text').innerHTML = all === d.insert
        ? `방금 등록한 <strong>${all.toLocaleString()}건</strong>이 <strong>비활성</strong> 상태로 저장됐습니다.`
        : `방금 등록한 <strong>${all.toLocaleString()}건</strong> 중 새 상품 <strong>${d.insert.toLocaleString()}건</strong>이 <strong>비활성</strong> 상태로 저장됐고, ${(d.update + d.same).toLocaleString()}건은 고쳤습니다(활성 상태 그대로).`;
    document.getElementById('bk-banner').hidden = false;
    bkShowInactive();
}

function bkShowInactive() {
    document.getElementById('filter-source').value = 'syc';
    document.getElementById('filter-status').value = 'inactive';
    sourceChanged();
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

    // 현재 페이지 주변 5개 + 처음/끝 + 이전/다음(… 생략). 이용자 목록과 동일한 윈도우 방식.
    const numBtn = (p) => `<button class="page-btn${p===cur?' active':''}" onclick="load(${p})">${p}</button>`;
    let start = Math.max(1, cur - 2);
    let end   = Math.min(pages, start + 4);
    start = Math.max(1, end - 4);

    let html = `<button class="page-btn page-nav" onclick="load(${cur-1})"${cur<=1?' disabled':''}>이전</button>`;
    if (start > 1) { html += numBtn(1); if (start > 2) html += '<span class="page-gap">…</span>'; }
    for (let p = start; p <= end; p++) html += numBtn(p);
    if (end < pages) { if (end < pages - 1) html += '<span class="page-gap">…</span>'; html += numBtn(pages); }
    html += `<button class="page-btn page-nav" onclick="load(${cur+1})"${cur>=pages?' disabled':''}>다음</button>`;
    el.innerHTML = html;
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
    const d = await r.json().catch(() => ({ok:false, error:'요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'}));
    if (!d.ok) { alert('저장 실패: ' + d.error); return; }
    closeModal();
    load(curPage);
}

async function del(id, name) {
    if (!confirm(`"${name}" 제품을 삭제(비활성화)하시겠습니까?`)) return;
    const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'delete', id})});
    const d = await r.json().catch(() => ({ok:false, error:'요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'}));
    if (!d.ok) { alert('삭제 실패: ' + d.error); return; }
    load(curPage);
}

document.getElementById('prod-modal').addEventListener('click', e => { if (e.target === e.currentTarget) closeModal(); });
load(1);
</script>
<?php render_admin_footer(); ?>
