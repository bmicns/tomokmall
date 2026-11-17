<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/staff_layout.php';

require_staff();

// ── 파라미터 ────────────────────────────────────────────────────
$catId = (int)($_GET['cat'] ?? 0);
$q     = trim($_GET['q'] ?? '');
$view  = in_array($_GET['view'] ?? '', ['products', 'vendors'], true) ? $_GET['view'] : 'products';
$stTab = in_array($_GET['st'] ?? '', ['active', 'inactive', 'all'], true) ? $_GET['st'] : 'active';   // 업체 목록 상태 탭

// ── 카테고리 목록 (루트만) ──────────────────────────────────────
$rootCats = db()->query(
    'SELECT id, name FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order, id'
)->fetchAll();

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

// ── view=products: 품목별 업체 목록 ───────────────────────────
$productRows = [];
if ($view === 'products') {
    // 업체 연결로 만든 업체 상품 줄(출처ID link-…)은 품목이 아님 — 원래 상품에서 관리(app/vendors.php)
    $where  = ['p.is_active=1', "(p.external_id IS NULL OR p.external_id NOT LIKE 'link-%')"];
    $params = [];
    if ($catId) {
        $where[]        = '(p.category_id=:cid OR c.parent_id=:cid2)';
        $params['cid']  = $catId;
        $params['cid2'] = $catId;
    }
    if ($q !== '') {
        $where[]     = '(p.name LIKE :q OR p.spec LIKE :q2)';
        $params['q'] = $params['q2'] = "%{$q}%";
    }
    $w = 'WHERE ' . implode(' AND ', $where);

    $st = db()->prepare(
        "SELECT p.id, p.name, p.spec, p.brand, p.price_b2b,
                c.name AS cat_name,
                (SELECT COUNT(*) FROM product_vendors pv JOIN vendors v ON v.id=pv.vendor_id
                 WHERE pv.product_id=p.id AND v.is_active=1) AS vendor_cnt,
                (SELECT COUNT(*) FROM product_vendors pv JOIN vendors v ON v.id=pv.vendor_id
                 WHERE pv.product_id=p.id AND v.is_active=0) AS vendor_off_cnt,
                (SELECT v.name FROM product_vendors pv JOIN vendors v ON v.id=pv.vendor_id
                 WHERE pv.product_id=p.id AND pv.is_primary=1 LIMIT 1) AS primary_vendor,
                (SELECT v.is_active FROM product_vendors pv JOIN vendors v ON v.id=pv.vendor_id
                 WHERE pv.product_id=p.id AND pv.is_primary=1 LIMIT 1) AS primary_active
         FROM products p JOIN categories c ON c.id=p.category_id
         {$w}
         ORDER BY c.sort_order, c.id, p.id
         LIMIT 300"
    );
    $st->execute($params);
    $productRows = $st->fetchAll();
}

// ── view=vendors: 업체 목록 ────────────────────────────────────
$vendorRows = [];
$stCounts   = ['active' => 0, 'inactive' => 0, 'all' => 0];
if ($view === 'vendors') {
    foreach (db()->query('SELECT is_active, COUNT(*) AS n FROM vendors GROUP BY is_active') as $r) {
        $stCounts[(int)$r['is_active'] === 1 ? 'active' : 'inactive'] = (int)$r['n'];
    }
    $stCounts['all'] = $stCounts['active'] + $stCounts['inactive'];
    $where  = $stTab === 'all' ? ['1=1'] : ['v.is_active=' . ($stTab === 'active' ? 1 : 0)];
    $params = [];
    if ($q !== '') {
        $where[]     = '(v.name LIKE :q OR v.contact LIKE :q2 OR v.region LIKE :q3 OR v.phone LIKE :q4)';
        $params['q'] = $params['q2'] = $params['q3'] = $params['q4'] = "%{$q}%";
    }
    $w = 'WHERE ' . implode(' AND ', $where);
    $st = db()->prepare(
        "SELECT v.*,
                (SELECT COUNT(*) FROM product_vendors pv WHERE pv.vendor_id=v.id) AS product_cnt
         FROM vendors v {$w}
         ORDER BY v.id DESC"
    );
    $st->execute($params);
    $vendorRows = $st->fetchAll();
}

render_staff_header('품목별 업체정보', 'vendors');
?>
<style>
.view-toggle{display:flex;gap:0;border:1px solid #e8eaed;border-radius:6px;overflow:hidden;margin-bottom:16px;width:fit-content}
.view-btn{padding:6px 16px;font-size:12px;font-weight:600;background:#fff;color:#888;border:none;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center}
.view-btn.active{background:#003580;color:#fff}
.tag-cnt{background:#e8f0fe;color:#003580;font-size:10px;padding:1px 5px;border-radius:10px;margin-left:4px}
.vendor-chip{display:inline-block;padding:2px 7px;background:#e8f0fe;color:#003580;border-radius:4px;font-size:11px;font-weight:600}
.no-vendor{font-size:11px;color:#ccc}
.vendor-chip.off{background:#f0f0f0;color:#888}
.off-tag{display:inline-block;margin-left:4px;padding:1px 5px;border-radius:3px;font-size:10px;font-weight:600;background:#f0f0f0;color:#666}
.st-tabs{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.st-tab{padding:6px 14px;min-height:32px;box-sizing:border-box;border:1px solid #e8eaed;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;color:#555;background:#fff;display:inline-flex;align-items:center;gap:4px}
.st-tab.on{border-color:#003580;color:#003580;background:#e8f0fe}
.btn-sm-off{background:#fce4ec;color:#c62828}
.btn-sm-on{background:#e8f0fe;color:#003580}
#pv-add-btn:disabled{background:#c5cad3;border-color:#c5cad3;cursor:not-allowed}
.vd-warn{background:#fff8e1;border:1px solid #ffe082;color:#6d4c00;border-radius:6px;padding:8px 10px;font-size:12px;margin-bottom:10px}
.vd-list{margin:0 0 4px;padding-left:18px;font-size:13px;line-height:1.7;color:#333}
.stf-toast{position:fixed;left:50%;bottom:24px;transform:translateX(-50%);background:#263238;color:#fff;padding:10px 16px;border-radius:6px;font-size:13px;z-index:9500;max-width:calc(100% - 32px);box-shadow:0 4px 12px rgba(0,0,0,.2)}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;flex-wrap:wrap;gap:10px">
    <div class="page-heading" style="margin:0">품목별 업체정보</div>
    <?php if ($view === 'vendors'): ?>
    <button class="btn btn-primary" onclick="openVendorModal()">+ 업체 등록</button>
    <?php endif; ?>
</div>

<!-- 뷰 전환 -->
<div class="view-toggle">
    <a href="?view=products&cat=<?= $catId ?>&q=<?= $h($q) ?>" class="view-btn <?= $view==='products'?'active':'' ?>">품목별 보기</a>
    <a href="?view=vendors&q=<?= $h($q) ?>" class="view-btn <?= $view==='vendors'?'active':'' ?>">업체 목록</a>
</div>

<?php if ($view === 'products'): ?>
<!-- 카테고리 필터 -->
<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px">
    <a href="?view=products&q=<?= $h($q) ?>" style="padding:4px 12px;border:1px solid <?= !$catId?'#003580':'#e8eaed' ?>;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;color:<?= !$catId?'#003580':'#555' ?>;background:<?= !$catId?'#e8f0fe':'#fff' ?>">전체</a>
    <?php foreach ($rootCats as $c): ?>
    <a href="?view=products&cat=<?= (int)$c['id'] ?>&q=<?= $h($q) ?>"
       style="padding:4px 12px;border:1px solid <?= $catId===(int)$c['id']?'#003580':'#e8eaed' ?>;border-radius:20px;font-size:12px;font-weight:600;text-decoration:none;color:<?= $catId===(int)$c['id']?'#003580':'#555' ?>;background:<?= $catId===(int)$c['id']?'#e8f0fe':'#fff' ?>">
        <?= $h($c['name']) ?>
    </a>
    <?php endforeach; ?>
</div>
<?php else: ?>
<!-- 업체 상태 탭 -->
<div class="st-tabs" role="tablist" aria-label="업체 상태">
    <?php foreach (['active' => '활성', 'inactive' => '비활성', 'all' => '전체'] as $k => $label): ?>
    <a href="?view=vendors&st=<?= $k ?>&q=<?= $h($q) ?>" class="st-tab <?= $stTab === $k ? 'on' : '' ?>" role="tab" aria-selected="<?= $stTab === $k ? 'true' : 'false' ?>"><?= $label ?> <span class="tag-cnt"><?= (int)$stCounts[$k] ?></span></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 검색 -->
<div class="toolbar">
    <form method="get" style="display:contents">
        <input type="hidden" name="view" value="<?= $h($view) ?>">
        <?php if ($catId && $view==='products'): ?><input type="hidden" name="cat" value="<?= $catId ?>"><?php endif; ?>
        <?php if ($view==='vendors'): ?><input type="hidden" name="st" value="<?= $h($stTab) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= $h($q) ?>" placeholder="<?= $view==='products' ? '품명·규격 검색' : '업체명·담당자·지역·연락처 검색' ?>" aria-label="검색어">
        <button type="submit" class="btn btn-secondary">검색</button>
        <?php if ($q): ?>
        <a href="?view=<?= $h($view) ?><?= $catId && $view==='products' ? '&cat='.$catId : '' ?><?= $view==='vendors' ? '&st='.$h($stTab) : '' ?>" style="font-size:12px;color:#888;text-decoration:none;align-self:center">초기화</a>
        <?php endif; ?>
    </form>
</div>

<?php if ($view === 'products'): ?>
<!-- 품목별 업체 테이블 -->
<div class="card">
    <div class="card-header">
        <span class="card-title">품목별 업체 현황</span>
        <span style="font-size:12px;color:#888"><?= count($productRows) ?>건</span>
    </div>
    <div style="overflow-x:auto">
    <table class="stf-table">
        <thead>
            <tr>
                <th>카테고리</th><th>품명</th><th>규격</th>
                <th style="text-align:right">B2B 단가</th>
                <th>주거래업체</th><th style="text-align:center">업체수</th><th>관리</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($productRows): ?>
        <?php foreach ($productRows as $row): ?>
            <tr>
                <td style="font-size:11px;color:#888"><?= $h($row['cat_name']) ?></td>
                <td style="font-weight:600;max-width:150px"><?= $h($row['name']) ?></td>
                <td style="font-size:11px;color:#666;max-width:120px"><?= $h($row['spec']) ?></td>
                <td style="text-align:right;font-size:12px"><?= number_format((int)$row['price_b2b']) ?>원</td>
                <td>
                    <?php if ($row['primary_vendor']): ?>
                    <span class="vendor-chip<?= (int)$row['primary_active'] ? '' : ' off' ?>"><?= $h($row['primary_vendor']) ?></span><?php if (!(int)$row['primary_active']): ?><span class="off-tag">비활성</span><?php endif; ?>
                    <?php else: ?>
                    <span class="no-vendor">미등록</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:center">
                    <span style="background:#f0f2f5;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">
                        <?= (int)$row['vendor_cnt'] ?>
                    </span>
                    <?php if ((int)$row['vendor_off_cnt']): ?><div style="font-size:10px;color:#999;margin-top:2px">비활성 <?= (int)$row['vendor_off_cnt'] ?></div><?php endif; ?>
                </td>
                <td>
                    <button class="btn-sm btn-sm-view" onclick="openProductVendors(<?= (int)$row['id'] ?>, <?= $h(json_encode((string)$row['name'], JSON_UNESCAPED_UNICODE)) ?>, <?= $h(json_encode((string)($row['spec'] ?? ''), JSON_UNESCAPED_UNICODE)) ?>)">
                        업체 관리
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:#aaa">검색 결과가 없습니다.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php else: ?>
<!-- 업체 목록 -->
<div class="card">
    <div class="card-header">
        <span class="card-title"><?= ['active' => '활성 업체', 'inactive' => '비활성 업체', 'all' => '등록된 업체'][$stTab] ?></span>
        <span style="font-size:12px;color:#888"><?= count($vendorRows) ?>개</span>
    </div>
    <div style="overflow-x:auto">
    <table class="stf-table">
        <thead>
            <tr><th>업체명</th><th>담당자</th><th>연락처</th><th>지역</th><th style="text-align:center">품목수</th><th>상태</th><th>관리</th></tr>
        </thead>
        <tbody>
        <?php if ($vendorRows): ?>
        <?php foreach ($vendorRows as $v): ?>
            <tr>
                <td style="font-weight:600"><?= $h($v['name']) ?></td>
                <td><?= $h($v['contact'] ?? '-') ?></td>
                <td><?= $h($v['phone'] ?? '-') ?></td>
                <td style="font-size:12px;color:#888"><?= $h($v['region'] ?? '-') ?></td>
                <td style="text-align:center">
                    <span style="background:#f0f2f5;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600"><?= (int)$v['product_cnt'] ?></span>
                </td>
                <td>
                    <span class="badge <?= $v['is_active'] ? 'badge-sent' : 'badge-canceled' ?>">
                        <?= $v['is_active'] ? '활성' : '비활성' ?>
                    </span>
                </td>
                <td style="display:flex;gap:4px">
                    <button class="btn-sm btn-sm-view" onclick="editVendor(<?= (int)$v['id'] ?>)">수정</button>
                    <?php if ($v['is_active']): ?>
                    <button class="btn-sm btn-sm-off" onclick="askVendorOff(<?= (int)$v['id'] ?>)">비활성</button>
                    <?php else: ?>
                    <button class="btn-sm btn-sm-on" onclick="setVendorActive(<?= (int)$v['id'] ?>, 1)">재활성</button>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:#aaa"><?php if ($q !== '' || $stTab !== 'active'): ?>조건에 맞는 업체가 없습니다.<?php else: ?>등록된 업체가 없습니다.<br><small>오른쪽 위 + 업체 등록 버튼을 눌러 추가하세요.</small><?php endif; ?></td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

<!-- 업체 등록/수정 모달 -->
<div class="modal-overlay" id="vendor-modal">
    <div class="modal-box" style="width:480px">
        <h3 id="vendor-modal-title">업체 등록</h3>
        <button class="modal-close" onclick="closeModal('vendor-modal')">×</button>
        <input type="hidden" id="vm-id" value="">
        <div class="form-2col">
            <div class="form-field"><label>업체명 *</label><input type="text" id="vm-name" placeholder="(주)코리아토목"></div>
            <div class="form-field"><label>담당자</label><input type="text" id="vm-contact" placeholder="김담당"></div>
        </div>
        <div class="form-2col">
            <div class="form-field"><label>연락처</label><input type="tel" id="vm-phone" placeholder="031-297-9800"></div>
            <div class="form-field"><label>팩스</label><input type="tel" id="vm-fax" placeholder="031-297-9801"></div>
        </div>
        <div class="form-2col">
            <div class="form-field"><label>이메일</label><input type="email" id="vm-email" placeholder="vendor@example.com"></div>
            <div class="form-field"><label>지역(출발지)</label><input type="text" id="vm-region" placeholder="경기 화성시"></div>
        </div>
        <div class="form-field"><label>주소</label><input type="text" id="vm-address" placeholder="경기도 화성시 서부로 63번길 181"></div>
        <div class="form-field"><label>메모</label><textarea id="vm-memo" rows="2" style="border:1px solid #e8eaed;border-radius:6px;padding:7px 10px;font-size:13px;width:100%;resize:vertical;outline:none"></textarea></div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('vendor-modal')">취소</button>
            <button class="btn btn-primary" onclick="saveVendor()">저장</button>
        </div>
    </div>
</div>

<!-- 업체 비활성 확인 -->
<div class="modal-overlay" id="vendor-off-modal" role="dialog" aria-modal="true" aria-labelledby="vo-title">
    <div class="modal-box" style="width:460px">
        <h3 id="vo-title">업체를 비활성으로 바꿀까요?</h3>
        <button class="modal-close" onclick="closeModal('vendor-off-modal')" aria-label="닫기">×</button>
        <input type="hidden" id="vo-id" value="">
        <ul class="vd-list" id="vo-body"></ul>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('vendor-off-modal')">취소</button>
            <button class="btn btn-primary" id="vo-ok" style="background:#c62828;border-color:#c62828" onclick="setVendorActive(parseInt(document.getElementById('vo-id').value, 10), 0)">비활성으로 변경</button>
        </div>
    </div>
</div>

<!-- 품목-업체 연결 모달(시안 '업체관리(After)' — 비활성 표시·주거래 지정·추가 가능한 업체만) -->
<div class="modal-overlay" id="pv-modal" role="dialog" aria-modal="true" aria-labelledby="pv-modal-title">
    <div class="modal-box" style="width:520px">
        <h3 id="pv-modal-title" style="margin-bottom:4px">업체 관리</h3>
        <div id="pv-modal-sub" style="font-size:12.5px;color:#666;margin-bottom:16px"></div>
        <button class="modal-close" onclick="closeModal('pv-modal')" aria-label="닫기">×</button>
        <div id="pv-list" style="min-height:52px"></div>
        <hr style="border:none;border-top:1px solid #e8eaed;margin:14px 0">
        <div style="font-size:12px;font-weight:700;margin-bottom:8px;color:#555">업체 추가</div>
        <div class="form-2col">
            <div class="form-field">
                <label for="pv-vendor-sel">업체 선택</label>
                <select id="pv-vendor-sel" onchange="pvSelChanged()"><option value="">-- 선택 --</option></select>
                <span id="pv-no-opts" style="display:none;font-size:11.5px;color:#888">추가할 수 있는 활성 업체가 없습니다</span>
            </div>
            <div class="form-field">
                <label for="pv-price">공급단가 (원)</label>
                <input type="text" inputmode="numeric" id="pv-price" placeholder="0" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
        </div>
        <div class="form-2col">
            <div class="form-field">
                <label for="pv-lead">리드타임 (일)</label>
                <input type="text" inputmode="numeric" id="pv-lead" placeholder="0" oninput="this.value=this.value.replace(/[^0-9]/g,'')">
            </div>
            <div class="form-field" style="justify-content:flex-end">
                <label style="display:flex;align-items:center;gap:7px;min-height:34px;font-size:12.5px;font-weight:400;color:#444;cursor:pointer">
                    <input type="checkbox" id="pv-primary" style="width:16px;height:16px;margin:0;accent-color:#003580"> <span>주거래 업체</span>
                </label>
            </div>
        </div>
        <p style="font-size:11.5px;color:#666;line-height:1.6;margin:4px 0 0">추가하면 이 업체가 파는 상품이 제품관리에 <b>비활성</b>으로 따로 생깁니다(매입가 = 공급단가, 정가 = 이 상품 정가, 회원가는 분류 마진율로 자동). 관리자가 제품관리 '모든 업체'에서 활성으로 바꾸면 고객 화면에 보입니다.</p>
        <input type="hidden" id="pv-product-id" value="">
        <div class="modal-footer" style="margin-top:6px">
            <button class="btn btn-secondary" onclick="closeModal('pv-modal')">닫기</button>
            <button class="btn btn-primary" id="pv-add-btn" onclick="addProductVendor()" disabled>추가</button>
        </div>
    </div>
</div>

<!-- 공급 제외 확인(연결 한 줄과 그 공급단가·리드타임이 함께 지워짐) -->
<div class="modal-overlay" id="pv-ex-modal" role="alertdialog" aria-modal="true" aria-labelledby="pv-ex-title" style="z-index:9100">
    <div class="modal-box" style="width:460px">
        <h3 id="pv-ex-title" style="font-weight:600;line-height:1.5"></h3>
        <input type="hidden" id="pv-ex-id" value="">
        <ul class="vd-list" id="pv-ex-body"></ul>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeModal('pv-ex-modal')">취소</button>
            <button class="btn btn-primary" id="pv-ex-ok" style="background:#c62828;border-color:#c62828" onclick="removeProductVendor()">공급 제외</button>
        </div>
    </div>
</div>

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); }
function closeModal(id){ document.getElementById(id).classList.remove('open'); }

// ── 업체 등록/수정 ─────────────────────────────────────────────
function openVendorModal(){ clearVendorModal(); openModal('vendor-modal'); }
function clearVendorModal(){
    ['vm-id','vm-name','vm-contact','vm-phone','vm-fax','vm-email','vm-region','vm-address','vm-memo'].forEach(id => {
        const el = document.getElementById(id);
        if(el) el.value = '';
    });
    document.getElementById('vendor-modal-title').textContent = '업체 등록';
}

async function editVendor(id){
    const r = await fetch('/api/staff/vendors.php?id='+id);
    // 오류는 200 + ok:false + error 로 온다(닷홈 4xx 본문 교체 회피). 본문이 JSON 이 아니면 일반 문구
    const d = await r.json().catch(() => null);
    if(!d || !d.ok){ alert('오류: '+((d && d.error) || '요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.')); return; }
    const v = d.data;
    document.getElementById('vm-id').value      = v.id;
    document.getElementById('vm-name').value    = v.name;
    document.getElementById('vm-contact').value = v.contact||'';
    document.getElementById('vm-phone').value   = v.phone||'';
    document.getElementById('vm-fax').value     = v.fax||'';
    document.getElementById('vm-email').value   = v.email||'';
    document.getElementById('vm-region').value  = v.region||'';
    document.getElementById('vm-address').value = v.address||'';
    document.getElementById('vm-memo').value    = v.memo||'';
    document.getElementById('vendor-modal-title').textContent = '업체 수정';
    openModal('vendor-modal');
}

async function saveVendor(){
    const id   = document.getElementById('vm-id').value;
    const name = document.getElementById('vm-name').value.trim();
    if(!name){ alert('업체명을 입력하세요.'); return; }
    const body = {
        id: id ? parseInt(id) : null,
        name, contact: document.getElementById('vm-contact').value,
        phone: document.getElementById('vm-phone').value,
        fax:   document.getElementById('vm-fax').value,
        email: document.getElementById('vm-email').value,
        region:  document.getElementById('vm-region').value,
        address: document.getElementById('vm-address').value,
        memo:    document.getElementById('vm-memo').value,
    };
    const r = await fetch('/api/staff/vendors.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)
    });
    const d = await r.json().catch(() => null);
    if(d && d.ok){ closeModal('vendor-modal'); location.reload(); }
    else alert('오류: '+((d && d.error) || '요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'));
}

// ── 업체 활성/비활성 ───────────────────────────────────────────
// 비활성: 그 업체 상품(제품관리) 전부 비활성 + 고객 화면에서 업체명 빠짐. 재활성: 업체만 — 상품은 제품관리에서 직접 활성으로
function vdToast(msg){
    const t = document.createElement('div');
    t.className = 'stf-toast'; t.setAttribute('role', 'status'); t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(() => t.remove(), 5000);
}
function vdNote(msg){ try { sessionStorage.setItem('vd-note', msg); } catch(e) {} }
(function(){ try { const m = sessionStorage.getItem('vd-note'); if (m) { sessionStorage.removeItem('vd-note'); vdToast(m); } } catch(e) {} })();

async function askVendorOff(id){
    const r = await fetch('/api/staff/vendors.php?impact='+id);
    const d = await r.json().catch(() => null);
    if(!d || !d.ok){ alert('오류: '+((d && d.error) || '요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.')); return; }
    const v = d.data;
    document.getElementById('vo-id').value = v.id;
    document.getElementById('vo-title').textContent = v.name + '을(를) 비활성으로 바꿀까요?';
    const li = [];
    li.push(v.products
        ? `이 업체 상품 <b>${v.products.toLocaleString()}개</b>가 모두 비활성이 되어 고객 화면에서 사라집니다(지금 활성 ${v.products_active.toLocaleString()}개).`
        : '제품관리에 등록된 이 업체 상품은 없습니다.');
    li.push('고객 화면의 상품 업체명에서 이 업체 이름이 빠집니다' + (v.primary_items ? ` (주거래 품목 ${v.primary_items}개 — 품목별 보기에서 주거래를 다시 지정해 주세요).` : '.'));
    li.push(`연결된 품목 ${v.items}개의 공급단가·리드타임 기록은 지워지지 않습니다.`);
    li.push("다시 활성으로 바꿔도 상품은 비활성 그대로입니다. 상품은 제품관리에서 직접 활성으로 바꿔야 합니다.");
    document.getElementById('vo-body').innerHTML = li.map(x => '<li>' + x + '</li>').join('');
    openModal('vendor-off-modal');
}

async function setVendorActive(id, val){
    const ok = document.getElementById('vo-ok');
    if (val === 0 && ok) ok.disabled = true;
    const r = await fetch('/api/staff/vendors.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'set_vendor_active', id, is_active:val})
    });
    const d = await r.json().catch(() => null);
    if (ok) ok.disabled = false;
    if(!d || !d.ok){ alert('오류: '+((d && d.error) || '요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.')); return; }
    vdNote(val === 0
        ? '비활성으로 바꿨습니다' + (d.products_off ? ` · 상품 ${d.products_off.toLocaleString()}개를 비활성으로 바꿨습니다` : '')
        : '다시 활성으로 바꿨습니다' + (d.products ? ` · 상품 ${d.products.toLocaleString()}개는 비활성 그대로입니다 — 제품관리에서 직접 활성으로 바꿔 주세요` : ''));
    location.reload();
}

// ── 품목-업체 연결 ─────────────────────────────────────────────
let pvCur = { id: 0, name: '', links: [] };
async function openProductVendors(productId, productName, productSpec){
    pvCur = { id: productId, name: productName, links: [] };
    document.getElementById('pv-product-id').value = productId;
    document.getElementById('pv-modal-sub').textContent = productName + (productSpec ? ' · ' + productSpec : '');
    ['pv-price','pv-lead'].forEach(id => document.getElementById(id).value = '');
    await loadProductVendors(productId);
    openModal('pv-modal');
}

function pvEsc(v){ return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c])); }
// 업체 추가 목록: 활성 업체 중 아직 연결되지 않은 업체만(이름순). 주거래가 없거나 비활성이면 '주거래 업체'를 미리 체크
async function pvFillVendors(){
    const r = await fetch('/api/staff/vendors.php?list=1');
    const d = await r.json().catch(() => null);
    const linked = new Set(pvCur.links.map(l => String(l.vendor_id)));
    const opts = ((d && d.data) || []).filter(v => !linked.has(String(v.id)));
    const sel = document.getElementById('pv-vendor-sel');
    sel.innerHTML = '<option value="">-- 선택 --</option>' + opts.map(v => `<option value="${parseInt(v.id, 10)}">${pvEsc(v.name)}</option>`).join('');
    document.getElementById('pv-no-opts').style.display = opts.length ? 'none' : '';
    const prim = pvCur.links.find(l => +l.is_primary);
    document.getElementById('pv-primary').checked = !prim || !+prim.vendor_active;
    pvSelChanged();
}
function pvSelChanged(){ document.getElementById('pv-add-btn').disabled = !document.getElementById('pv-vendor-sel').value; }

async function loadProductVendors(productId){
    const r = await fetch('/api/staff/vendors.php?product_id='+productId);
    const d = await r.json().catch(() => null);
    pvCur.links = (d && d.data) || [];
    const list = document.getElementById('pv-list');
    if(!pvCur.links.length){
        list.innerHTML = '<div style="color:#888;font-size:12.5px;padding:14px 0">연결된 업체가 없습니다.</div>';
    } else {
        list.innerHTML = pvCur.links.map(pv => {
            const off = !+pv.vendor_active, prim = +pv.is_primary, id = parseInt(pv.id, 10);
            return `
        <div style="display:flex;align-items:center;gap:8px;min-height:52px;padding:8px 0;border-bottom:1px solid #f3f4f6">
            <span style="flex:1;min-width:0;display:flex;flex-direction:column;gap:3px">
                <span style="display:flex;align-items:center;gap:6px;flex-wrap:wrap">
                    <b style="font-size:13px;font-weight:600;color:${off ? '#999' : 'inherit'}">${pvEsc(pv.vendor_name)}</b>
                    ${prim ? '<span style="display:inline-flex;align-items:center;height:18px;padding:0 6px;border-radius:3px;background:#003580;color:#fff;font-size:10.5px">주거래</span>' : ''}
                    ${off ? '<span class="off-tag" style="margin-left:0">비활성</span>' : ''}
                </span>
                <span style="font-size:11px;color:#888">공급단가: ${parseInt(pv.supply_price||0, 10).toLocaleString()}원 | 리드타임: ${parseInt(pv.lead_days||0, 10)}일</span>
                ${off ? `<span style="font-size:11px;color:#999">고객 화면에 해당 업체는 표시되지 않습니다.${prim ? ' 주거래 업체를 변경해주세요.' : ''}</span>` : ''}
            </span>
            ${!off && !prim ? `<button onclick="setPrimary(${id})" class="btn-sm btn-sm-on" style="height:28px;font-weight:400">주거래로 지정</button>` : ''}
            <button onclick="askRemoveProductVendor(${id})" class="btn-sm btn-sm-off" style="height:28px;font-weight:400">삭제</button>
        </div>`;
        }).join('');
    }
    await pvFillVendors();
}

async function addProductVendor(){
    const productId = pvCur.id;
    const vendorId  = document.getElementById('pv-vendor-sel').value;
    if(!vendorId){ return; }
    const name = document.getElementById('pv-vendor-sel').selectedOptions[0]?.textContent || '';
    const prim = document.getElementById('pv-primary').checked;
    const body = {
        action: 'add_product_vendor',
        product_id:   parseInt(productId, 10),
        vendor_id:    parseInt(vendorId, 10),
        supply_price: parseInt(document.getElementById('pv-price').value, 10)||0,
        lead_days:    parseInt(document.getElementById('pv-lead').value, 10)||0,
        is_primary:   prim ? 1 : 0,
    };
    const r = await fetch('/api/staff/vendors.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)
    });
    const d = await r.json().catch(() => null);
    if(d && d.ok){
        ['pv-price','pv-lead'].forEach(id => document.getElementById(id).value = '');   // 추가 후 입력칸 비움
        await loadProductVendors(productId);
        vdToast(name + '을(를) 연결했습니다' + (prim ? ' (주거래)' : '') + (d.link_created ? ' — 업체 상품이 비활성으로 만들어졌습니다. 제품관리에서 활성으로 바꿔 주세요' : ''));
    }
    else alert('오류: '+((d && d.error) || '요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'));
}

async function setPrimary(pvId){
    const r = await fetch('/api/staff/vendors.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'set_primary', id: pvId})
    });
    const d = await r.json().catch(() => null);
    if(d && d.ok){
        const l = pvCur.links.find(x => +x.id === pvId);
        await loadProductVendors(pvCur.id);
        vdToast('주거래 업체를 ' + (l ? l.vendor_name : '') + '(으)로 바꿨습니다');
    }
    else alert('오류: '+((d && d.error) || '요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'));
}

// 삭제 = 공급 제외: 이 품목과 업체의 연결 한 줄(공급단가·리드타임 포함)을 지우고 그 업체 상품 줄은 비활성(행은 남김). 원래 상품·업체 정보는 그대로
function askRemoveProductVendor(pvId){
    const l = pvCur.links.find(x => +x.id === pvId);
    if (!l) return;
    document.getElementById('pv-ex-id').value = pvId;
    document.getElementById('pv-ex-title').textContent = l.vendor_name + '을(를) ‘' + pvCur.name + '’ 공급 업체에서 제외할까요?';
    const li = [
        `이 품목에 저장된 공급단가(${parseInt(l.supply_price||0, 10).toLocaleString()}원)·리드타임(${parseInt(l.lead_days||0, 10)}일) 기록이 함께 지워지며 되돌릴 수 없습니다.`,
        '이 업체 상품(제품관리의 업체 상품 줄)이 비활성이 되어 고객 화면에서 빠집니다. 상품 줄은 지우지 않습니다.',
        '이미 견적·주문 기록에 있는 이 업체 상품은 기록에 그대로 남고, 고객에게는 회색으로 \'지금은 없는 상품입니다\'로 보입니다.',
    ];
    if (+l.is_primary) li.push('주거래 업체입니다. 제외한 뒤 다른 업체를 주거래로 지정해 주세요.');
    li.push('업체 정보와 이 업체의 다른 품목 연결은 그대로 남습니다.');
    document.getElementById('pv-ex-body').innerHTML = li.map(x => '<li>' + pvEsc(x) + '</li>').join('');
    openModal('pv-ex-modal');
}

async function removeProductVendor(){
    const pvId = parseInt(document.getElementById('pv-ex-id').value, 10);
    const l = pvCur.links.find(x => +x.id === pvId);
    const ok = document.getElementById('pv-ex-ok'); ok.disabled = true;
    const r = await fetch('/api/staff/vendors.php', {
        method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({ action: 'remove_product_vendor', id: pvId })
    });
    const d = await r.json().catch(() => null);
    ok.disabled = false;
    if(d && d.ok){
        closeModal('pv-ex-modal');
        await loadProductVendors(pvCur.id);
        vdToast((l ? l.vendor_name : '업체') + '을(를) 공급 업체에서 제외했습니다' + (l && +l.is_primary ? ' — 주거래 업체를 지정해 주세요' : ''));
    }
    else alert('오류: '+((d && d.error) || '요청을 처리하지 못했습니다. 잠시 후 다시 시도해 주세요.'));
}
</script>

<?php render_staff_footer(); ?>
