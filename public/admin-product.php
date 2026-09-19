<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';

require_admin();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /admin-products.php'); exit; }

$product = db()->prepare(
    "SELECT p.*, c.name AS cat_name, p2.name AS mid_name, p3.name AS root_name
     FROM products p
     JOIN categories c  ON c.id  = p.category_id
     LEFT JOIN categories p2 ON p2.id = c.parent_id
     LEFT JOIN categories p3 ON p3.id = p2.parent_id
     WHERE p.id = ?"
);
$product->execute([$id]);
$product = $product->fetch();
if (!$product) { header('Location: /admin-products.php'); exit; }

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

$spec = parse_spec_field($product['spec'] ?? '');
$h    = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES);

render_admin_header('제품 상세', 'products');
?>
<style>
.product-wrap{max-width:860px}
.product-header{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.back-link{display:inline-flex;align-items:center;gap:6px;font-size:13px;color:#888;text-decoration:none}
.back-link:hover{color:#004EA1}
.product-title{font-size:18px;font-weight:800;flex:1;color:#1a2035}
.product-body{display:grid;grid-template-columns:220px 1fr;gap:24px;align-items:start}
.img-box{border:1px solid #e8eaed;border-radius:10px;overflow:hidden;aspect-ratio:4/3;background:#f8f9fb}
.img-box img{width:100%;height:100%;object-fit:cover}
.img-empty{width:100%;height:100%;display:flex;align-items:center;justify-content:center;color:#aaa;font-size:13px}
.form-section{display:flex;flex-direction:column;gap:14px}
.form-field{display:flex;flex-direction:column;gap:4px}
.form-field label{font-size:12px;font-weight:600;color:#666}
.form-field input,.form-field select{border:1px solid #e8eaed;border-radius:6px;padding:8px 10px;font-size:13px;width:100%}
.form-2col{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.spec-grid{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.spec-grid .sf{display:flex;flex-direction:column;gap:3px}
.spec-grid .sf span{font-size:11px;color:#666;font-weight:600}
.spec-grid .sf input{border:1px solid #e8eaed;border-radius:5px;padding:6px 9px;font-size:12px}
.form-actions{display:flex;gap:10px;align-items:center}
.msg-ok{font-size:13px;color:#2e7d32;font-weight:600;display:none}
.msg-err{font-size:13px;color:#c62828;font-weight:600;display:none}
</style>

<div class="product-wrap">
    <div class="product-header">
        <a href="/admin-products.php" class="back-link">
            <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
            목록으로
        </a>
        <div class="product-title"><?= $h($product['name']) ?></div>
        <span class="badge <?= $product['is_active'] ? 'badge-on' : 'badge-off' ?>" id="badge-status">
            <?= $product['is_active'] ? '활성' : '비활성' ?>
        </span>
    </div>

    <div class="product-body">
        <div>
            <div class="img-box" id="img-box">
                <?php if ($product['image_url']): ?>
                    <img src="<?= $h($product['image_url']) ?>" alt="">
                <?php else: ?>
                    <div class="img-empty">이미지 없음</div>
                <?php endif; ?>
            </div>
        </div>

        <div class="form-section">
            <input type="hidden" id="f-id" value="<?= $id ?>">

            <div class="form-field">
                <label>카테고리 *</label>
                <select id="f-cat">
                    <?php foreach ($catGroups as $rootName => $mids): ?>
                    <optgroup label="<?= $h($rootName) ?>">
                        <?php foreach ($mids as $midName => $subs): ?>
                        <?php foreach ($subs as $sub): ?>
                        <option value="<?= (int)$sub['id'] ?>"<?= (int)$sub['id'] === (int)$product['category_id'] ? ' selected' : '' ?>>
                            <?= $h($midName) ?> › <?= $h($sub['name']) ?>
                        </option>
                        <?php endforeach; ?>
                        <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="form-2col">
                <div class="form-field">
                    <label>제품명 *</label>
                    <input type="text" id="f-name" value="<?= $h($product['name']) ?>">
                </div>
                <div class="form-field">
                    <label>브랜드</label>
                    <input type="text" id="f-brand" value="<?= $h($product['brand'] ?? '') ?>">
                </div>
            </div>

            <div class="form-field">
                <label>규격 (spec)</label>
                <div class="spec-grid">
                    <div class="sf"><span>규격</span><input type="text" id="f-sv" value="<?= $h($spec['spec']) ?>" placeholder="예) 300x300"></div>
                    <div class="sf"><span>단위</span><input type="text" id="f-su" value="<?= $h($spec['unit']) ?>" placeholder="예) 개"></div>
                    <div class="sf"><span>중량</span><input type="text" id="f-sw" value="<?= $h($spec['weight']) ?>" placeholder="예) 50kg"></div>
                    <div class="sf"><span>상차수량</span><input type="text" id="f-sl" value="<?= $h($spec['load']) ?>" placeholder="예) 32"></div>
                    <div class="sf"><span>운반비예상</span><input type="text" id="f-sf" value="<?= $h($spec['freight']) ?>" placeholder="예) 50,000"></div>
                    <div class="sf"><span>설치비예상</span><input type="text" id="f-si" value="<?= $h($spec['install']) ?>" placeholder="예) 100,000"></div>
                </div>
            </div>

            <div class="form-2col">
                <div class="form-field">
                    <label>단가 B2B (원)</label>
                    <input type="number" id="f-pb2b" value="<?= (int)$product['price_b2b'] ?>" min="0">
                </div>
                <div class="form-field">
                    <label>단가 B2C (원)</label>
                    <input type="number" id="f-pb2c" value="<?= (int)$product['price_b2c'] ?>" min="0">
                </div>
            </div>

            <div class="form-2col">
                <div class="form-field">
                    <label>이미지 URL</label>
                    <input type="text" id="f-img" value="<?= $h($product['image_url'] ?? '') ?>" oninput="previewImg(this.value)">
                </div>
                <div class="form-field">
                    <label>상태</label>
                    <select id="f-active">
                        <option value="1"<?= $product['is_active'] ? ' selected' : '' ?>>활성</option>
                        <option value="0"<?= !$product['is_active'] ? ' selected' : '' ?>>비활성</option>
                    </select>
                </div>
            </div>

            <div class="form-actions">
                <button class="btn btn-primary" onclick="save()">저장</button>
                <span class="msg-ok" id="msg-ok">✓ 저장됐습니다</span>
                <span class="msg-err" id="msg-err"></span>
                <button class="btn btn-secondary" onclick="del()" style="margin-left:auto;color:#c62828">삭제</button>
            </div>
        </div>
    </div>
</div>

<script>
const API    = '/api/admin/products.php';
const prodId = <?= $id ?>;

function previewImg(url) {
    const box = document.getElementById('img-box');
    box.innerHTML = url
        ? `<img src="${url.replace(/"/g,'&quot;')}" alt="" style="width:100%;height:100%;object-fit:cover">`
        : '<div class="img-empty">이미지 없음</div>';
}

function buildSpec() {
    const vals = [['규격','f-sv'],['단위','f-su'],['중량','f-sw'],['상차수량','f-sl'],['운반비예상','f-sf'],['설치비예상','f-si']];
    return vals.map(([k,id])=>{const v=document.getElementById(id).value.trim();return v?k+':'+v:''}).filter(Boolean).join('|');
}

async function save() {
    const name = document.getElementById('f-name').value.trim();
    if (!name) { showErr('제품명을 입력해 주세요.'); return; }
    const payload = {
        action:'update', id:prodId,
        category_id: +document.getElementById('f-cat').value,
        name, brand: document.getElementById('f-brand').value.trim(),
        spec: buildSpec(),
        price_b2b: +document.getElementById('f-pb2b').value,
        price_b2c: +document.getElementById('f-pb2c').value,
        image_url: document.getElementById('f-img').value.trim(),
        is_active: +document.getElementById('f-active').value,
    };
    const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(payload)});
    const d = await r.json();
    if (!d.ok) { showErr('저장 실패: ' + d.error); return; }
    const badge = document.getElementById('badge-status');
    badge.textContent = payload.is_active ? '활성' : '비활성';
    badge.className = 'badge ' + (payload.is_active ? 'badge-on' : 'badge-off');
    showOk();
}

async function del() {
    const name = document.getElementById('f-name').value || '이 제품';
    if (!confirm(`"${name}" 제품을 삭제(비활성화)하시겠습니까?`)) return;
    const r = await fetch(API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({action:'delete',id:prodId})});
    const d = await r.json();
    if (!d.ok) { showErr('삭제 실패: ' + d.error); return; }
    location.href = '/admin-products.php';
}

function showOk() { const el=document.getElementById('msg-ok'); el.style.display='inline'; setTimeout(()=>el.style.display='none',2500); }
function showErr(msg) { const el=document.getElementById('msg-err'); el.textContent=msg; el.style.display='inline'; setTimeout(()=>el.style.display='none',3000); }
</script>
<?php render_admin_footer(); ?>
