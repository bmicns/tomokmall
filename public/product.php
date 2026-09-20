<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id || !db()) { header('Location: /index.php'); exit; }

$st = db()->prepare(
    "SELECT p.*,
            c.id AS cat_id, c.name AS cat_name, c.parent_id AS mid_id,
            mid.name AS mid_name, mid.parent_id AS root_id,
            root.name AS root_name
     FROM products p
     JOIN categories c    ON c.id    = p.category_id
     LEFT JOIN categories mid  ON mid.id  = c.parent_id
     LEFT JOIN categories root ON root.id = mid.parent_id
     WHERE p.id = ? AND p.is_active = 1"
);
$st->execute([$id]);
$p = $st->fetch();
if (!$p) { header('Location: /index.php'); exit; }

$sp       = parse_spec_field($p['spec'] ?? '');
$loggedIn = !empty($_SESSION['user']);

// 시공사례 (중분류 기준)
$galleryImgs = [];
if ($p['mid_id'] && db()) {
    $gst = db()->prepare("SELECT image_url, caption FROM category_gallery WHERE category_id = ? ORDER BY sort_order LIMIT 6");
    $gst->execute([(int)$p['mid_id']]);
    $galleryImgs = $gst->fetchAll();
}

// 동일 소분류 다른 규격 (최대 8개)
$others = [];
if (db()) {
    $ost = db()->prepare("SELECT id, name, image_url, spec, price_b2c FROM products WHERE category_id = ? AND id != ? AND is_active = 1 ORDER BY id LIMIT 20");
    $ost->execute([$p['cat_id'], $id]);
    $others = $ost->fetchAll();
}

render_header($p['name']);
?>
<style>
/* ── 상세 래퍼 ─────────────────────────────────────── */
.pd-wrap{max-width:1200px;margin:0 auto;padding:28px 24px 60px}
/* 페이지 타이틀 + 브레드크럼 */
.pd-title-row{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:20px;gap:16px;flex-wrap:wrap}
.pd-page-title{font-size:18px;font-weight:700;color:var(--color-text-primary)}
.pd-page-title span{font-weight:400}
.pd-breadcrumb{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--color-text-secondary);flex-shrink:0}
.pd-breadcrumb a{color:#aaa;text-decoration:none}
.pd-breadcrumb a:hover{color:var(--color-primary)}
/* ── 본문 2열 ─────────────────────────────────────── */
.pd-body{display:grid;grid-template-columns:1fr 420px;gap:0;border:1px solid var(--color-border);border-radius:10px;overflow:hidden}
/* 이미지 영역 */
.pd-img-area{background:#f7f7f7;display:flex;align-items:center;justify-content:center;min-height:420px;padding:32px}
.pd-img-area img{width:100%;max-height:400px;object-fit:contain;display:block}
.pd-img-empty-msg{color:var(--color-text-secondary);font-size:14px}
/* 정보 영역 */
.pd-info-area{border-left:1px solid var(--color-border);padding:32px 28px;display:flex;flex-direction:column;gap:0}
.pd-product-name{font-size:24px;font-weight:400;color:var(--color-text-primary);line-height:1.3;margin-bottom:6px;letter-spacing:-0.5px}
.pd-brand-text{font-size:13px;color:var(--color-text-secondary);margin-bottom:16px}
.pd-divider{border:none;border-top:1px solid var(--color-border);margin:14px 0}
/* 가격 */
.pd-price-row{display:flex;align-items:baseline;gap:8px;margin-bottom:4px}
.pd-price-label{font-size:15px;color:var(--color-text-secondary);font-weight:500;min-width:40px}
.pd-price-val{font-size:40px;font-weight:400;color:var(--color-text-primary);letter-spacing:-1px;line-height:1}
.pd-price-unit{font-size:15px;color:var(--color-text-secondary)}
/* 스펙 */
.pd-spec-rows{display:flex;flex-direction:column;gap:0}
.pd-spec-row{display:flex;gap:12px;padding:7px 0;border-bottom:1px solid var(--color-bg-secondary)}
.pd-spec-row:last-child{border-bottom:none}
.pd-spec-key{font-size:13px;font-weight:700;color:var(--color-text-primary);width:72px;flex-shrink:0}
.pd-spec-val{font-size:13px;color:var(--color-text-secondary)}
/* 제품 설명 */
.pd-desc{font-size:13px;color:#555;line-height:1.8;margin-top:4px}
/* 색상 썸네일 */
.pd-variant-thumbs{display:flex;gap:8px;margin-top:8px;flex-wrap:wrap}
.pd-variant-thumb{width:72px;height:72px;border:1px solid var(--color-border);border-radius:6px;overflow:hidden;cursor:pointer;flex-shrink:0}
.pd-variant-thumb img{width:100%;height:100%;object-fit:cover}
.pd-variant-thumb:hover{border-color:var(--color-primary)}
/* 견적 버튼 */
.pd-btn-row{display:flex;flex-wrap:wrap;gap:10px;margin-top:auto;padding-top:20px}
.pd-login-notice{width:100%;font-size:12px;color:var(--color-text-secondary);text-align:center;padding-top:4px}
.btn-pd-action{flex:1;height:52px;background:#111;color:#fff;border:none;border-radius:0;font-size:15px;font-weight:600;cursor:pointer;letter-spacing:-0.3px}
.btn-pd-action:hover{background:#333}
/* ── 하단 탭 ──────────────────────────────────────── */
.pd-tabs-wrap{margin-top:40px}
.pd-tabs{display:flex;gap:0;width:100%}
.pd-tab-btn{flex:1;height:50px;padding:0;font-size:15px;font-weight:700;border:none;cursor:pointer;border-radius:0;letter-spacing:-0.5px}
.pd-tab-btn.active{background:#111;color:#fff}
.pd-tab-btn:not(.active){background:#eee;color:#777}
.pd-tab-panel{display:none;border:none;padding:28px 0}
.pd-tab-panel.active{display:block}
/* 시공사례 갤러리 */
.gallery-layout{display:grid;grid-template-columns:196px 1fr;gap:16px}
.gallery-thumbs{display:grid;grid-template-columns:repeat(2,1fr);gap:6px;align-content:start}
.gallery-thumb{width:auto;height:88px;border:1px solid var(--color-border);border-radius:0;overflow:hidden;cursor:pointer}
.gallery-thumb img{width:100%;height:100%;object-fit:cover}
.gallery-thumb.active,.gallery-thumb:hover{border-color:#004ea1}
.gallery-main{flex:1;border-radius:8px;overflow:hidden;background:#f7f7f7;min-height:400px;display:flex;align-items:center;justify-content:center}
.gallery-main img{max-width:100%;max-height:500px;object-fit:contain}
/* 다른 규격 그리드 */
.pd-others-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:12px}
.pd-other-card{border:1px solid var(--color-border);border-radius:0;overflow:hidden;cursor:pointer;text-decoration:none;display:block;transition:border-color 0.15s}
.pd-other-card:hover{border-color:#004ea1}
.pd-other-img{aspect-ratio:4/3;background:var(--color-bg-secondary);overflow:hidden}
.pd-other-img img{width:100%;height:100%;object-fit:cover}
.pd-other-img-empty{width:100%;height:100%;display:flex;align-items:center;justify-content:center;font-size:11px;color:var(--color-text-secondary)}
.pd-other-info{padding:10px 12px}
.pd-other-spec{font-size:14px;font-weight:700;color:var(--color-text-primary);line-height:1.4;margin-bottom:4px;word-break:break-all}
.pd-other-name{font-size:11px;color:var(--color-text-secondary);display:-webkit-box;-webkit-line-clamp:1;-webkit-box-orient:vertical;overflow:hidden}
/* 주문/배송 탭 그룹 */
.pd-tabs-wrap2{margin-top:32px}
.pd-tab-panel2{display:none;padding:28px 0}
.pd-tab-panel2.active{display:block}
/* 주문/배송 안내 */
.pd-info-text h4{font-size:15px;font-weight:700;color:var(--color-text-primary);margin:0 0 10px;padding-bottom:6px;border-bottom:2px solid var(--color-border)}
.pd-info-text h4+h4{margin-top:28px}
.pd-info-text ul{margin:0;padding-left:18px;display:flex;flex-direction:column;gap:8px}
.pd-info-text li{font-size:14px;color:var(--color-text-secondary);line-height:1.7}
.pd-info-text li strong{color:var(--color-text-primary);font-weight:600}
@media(max-width:800px){
  .pd-body{grid-template-columns:1fr}
  .pd-img-area{min-height:280px;padding:20px}
  .pd-img-area img{max-height:260px}
  .pd-info-area{border-left:none;border-top:1px solid var(--color-border)}
  .gallery-layout{grid-template-columns:1fr}
  .gallery-thumbs{flex-direction:row;flex-wrap:wrap}
}
</style>

<div class="pd-wrap">
    <!-- 타이틀 + 브레드크럼 -->
    <div class="pd-title-row">
        <div class="pd-page-title"><span>선택품목</span> 상세보기</div>
        <div class="pd-breadcrumb">
            <a href="/index.php">홈</a>
            <?php if ($p['root_name']): ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            <a href="/products.php?category=<?= (int)$p['root_id'] ?>"><?= e($p['root_name']) ?></a>
            <?php endif; ?>
            <?php if ($p['mid_name']): ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            <a href="/products.php?category=<?= (int)$p['mid_id'] ?>"><?= e($p['mid_name']) ?></a>
            <?php endif; ?>
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            <a href="/products.php?category=<?= (int)$p['cat_id'] ?>"><?= e($p['cat_name']) ?></a>
            <svg xmlns="http://www.w3.org/2000/svg" width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="9 18 15 12 9 6"/></svg>
            <span><?= e($p['name']) ?></span>
        </div>
    </div>

    <!-- 본문 -->
    <div class="pd-body">
        <!-- 좌: 대형 이미지 -->
        <div class="pd-img-area">
            <?php if ($p['image_url']): ?>
                <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['name']) ?>" id="main-prod-img">
            <?php else: ?>
                <span class="pd-img-empty-msg">이미지 준비 중</span>
            <?php endif; ?>
        </div>

        <!-- 우: 제품 정보 -->
        <div class="pd-info-area">
            <div class="pd-product-name"><?= e($p['name']) ?></div>
            <?php if ($p['brand']): ?><div class="pd-brand-text"><?= e($p['brand']) ?></div><?php endif; ?>

            <hr class="pd-divider">

            <?php if (!$loggedIn): ?>
            <div class="pd-price-row">
                <span class="pd-price-label">판매가</span>
                <span class="pt-price-lock pd-price-lock" onclick="openAuthModal('login')">로그인 후 공개</span>
            </div>
            <hr class="pd-divider">
            <?php elseif ($p['price_b2c'] > 0): ?>
            <div class="pd-price-row">
                <span class="pd-price-label">판매가</span>
                <span class="pd-price-val"><?= number_format((int)$p['price_b2c']) ?></span>
                <span class="pd-price-unit">원<?= $sp['unit'] ? ' / '.$sp['unit'] : '' ?></span>
            </div>
            <hr class="pd-divider">
            <?php endif; ?>

            <?php if ($sp['spec'] || $sp['unit'] || $sp['weight'] || $sp['load'] || $sp['freight']): ?>
            <div class="pd-spec-rows">
                <?php if ($sp['spec']):   ?><div class="pd-spec-row"><span class="pd-spec-key">규격</span><span class="pd-spec-val"><?= e($sp['spec']) ?></span></div><?php endif; ?>
                <?php if ($sp['unit']):   ?><div class="pd-spec-row"><span class="pd-spec-key">단위</span><span class="pd-spec-val"><?= e($sp['unit']) ?></span></div><?php endif; ?>
                <?php if ($sp['weight']): ?><div class="pd-spec-row"><span class="pd-spec-key">중량</span><span class="pd-spec-val"><?= e($sp['weight']) ?></span></div><?php endif; ?>
                <?php if ($sp['load']):   ?><div class="pd-spec-row"><span class="pd-spec-key">상차수량</span><span class="pd-spec-val"><?= e($sp['load']) ?></span></div><?php endif; ?>
                <?php if ($sp['freight']): ?><div class="pd-spec-row"><span class="pd-spec-key">운반비예상</span><span class="pd-spec-val"><?= e($sp['freight']) ?></span></div><?php endif; ?>
            </div>
            <hr class="pd-divider">
            <?php endif; ?>

            <?php if (!empty($p['description'])): ?>
            <div class="pd-desc"><?= nl2br(e($p['description'])) ?></div>
            <hr class="pd-divider">
            <?php endif; ?>

            <?php if (count($others) > 0): ?>
            <div class="pd-variant-thumbs">
                <?php foreach (array_slice($others, 0, 4) as $o): ?>
                <a href="/product.php?id=<?= (int)$o['id'] ?>" class="pd-variant-thumb" title="<?= e($o['name']) ?>">
                    <?php if ($o['image_url']): ?>
                        <img src="<?= e($o['image_url']) ?>" alt="<?= e($o['name']) ?>">
                    <?php else: ?>
                        <div style="width:100%;height:100%;background:var(--color-bg-secondary);display:flex;align-items:center;justify-content:center;font-size:10px;color:var(--color-text-secondary)">이미지없음</div>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>

            <div class="pd-btn-row">
                <?php if (!$loggedIn): ?>
                <button class="btn-pd-action" onclick="openAuthModal('login')">견적에 넣기</button>
                <button class="btn-pd-action" onclick="openAuthModal('login')">견적 요청</button>
                <div class="pd-login-notice">가격 및 견적은 회원에게만 공개됩니다</div>
                <?php else: ?>
                <button class="btn-pd-action" onclick="addCart(<?= (int)$p['id'] ?>)">견적에 넣기</button>
                <button class="btn-pd-action" onclick="location.href='/estimate.php'">견적 요청</button>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 하단 탭 -->
    <div class="pd-tabs-wrap">
        <div class="pd-tabs">
            <button class="pd-tab-btn active" onclick="showTab('gallery')" id="tab-btn-gallery">시공사례</button>
            <?php if ($others): ?>
            <button class="pd-tab-btn" onclick="showTab('others')" id="tab-btn-others">다른 규격</button>
            <?php endif; ?>
        </div>

        <!-- 시공사례 탭 -->
        <div class="pd-tab-panel active" id="tab-gallery">
            <?php if ($galleryImgs): ?>
            <div class="gallery-layout">
                <div class="gallery-thumbs">
                    <?php foreach ($galleryImgs as $i => $gi): ?>
                    <div class="gallery-thumb<?= $i===0?' active':'' ?>" onclick="setGallery('<?= e($gi['image_url']) ?>',this)">
                        <img src="<?= e($gi['image_url']) ?>" alt="<?= e($gi['caption']) ?>">
                    </div>
                    <?php endforeach; ?>
                </div>
                <div class="gallery-main">
                    <img src="<?= e($galleryImgs[0]['image_url']) ?>" alt="" id="gallery-main-img">
                </div>
            </div>
            <?php else: ?>
            <p style="color:var(--color-text-secondary);font-size:14px;padding:24px 0">시공사례 이미지를 준비 중입니다.</p>
            <?php endif; ?>
        </div>

        <!-- 다른 규격 탭 -->
        <?php if ($others): ?>
        <div class="pd-tab-panel" id="tab-others">
            <div class="pd-others-grid">
                <?php foreach ($others as $o):
                    $osp = parse_spec_field($o['spec'] ?? '');
                ?>
                <a href="/product.php?id=<?= (int)$o['id'] ?>" class="pd-other-card">
                    <div class="pd-other-img">
                        <?php if ($o['image_url']): ?>
                            <img src="<?= e($o['image_url']) ?>" alt="<?= e($o['name']) ?>">
                        <?php else: ?>
                            <div class="pd-other-img-empty">이미지 없음</div>
                        <?php endif; ?>
                    </div>
                    <div class="pd-other-info">
                        <?php if ($osp['spec']): ?><div class="pd-other-spec"><?= str_replace('(', '<br>(', e($osp['spec'])) ?></div><?php endif; ?>
                        <div class="pd-other-name"><?= e($o['name']) ?></div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <!-- 주문/배송 탭 그룹 -->
        <div class="pd-tabs-wrap2">
            <div class="pd-tabs">
                <button class="pd-tab-btn active" onclick="showTab2('notice')" id="tab-btn-notice">주문시 유의사항</button>
                <button class="pd-tab-btn" onclick="showTab2('delivery')" id="tab-btn-delivery">배송/교환안내</button>
            </div>

        <!-- 주문시 유의사항 탭 -->
        <div class="pd-tab-panel2 active" id="tab2-notice">
            <div class="pd-info-text">
                <h4>주문시 유의사항</h4>
                <ul>
                    <li>본 제품은 건설자재 특성상 <strong>최소 주문수량</strong>이 있을 수 있습니다. 주문 전 담당자에게 확인해 주세요.</li>
                    <li>견적 요청 후 담당자가 확인하여 <strong>개별 연락</strong>드립니다. (영업일 기준 1~2일 소요)</li>
                    <li>현장 상황 및 수급 여건에 따라 납기일이 변경될 수 있습니다.</li>
                    <li>규격·수량·납기는 반드시 <strong>사전 협의</strong> 후 발주해 주시기 바랍니다.</li>
                    <li>주문 후 취소 시 기발생 물류비용이 청구될 수 있습니다.</li>
                    <li>현장 계측 오류로 인한 반품·교환은 불가합니다. 수량 확인 후 주문해 주세요.</li>
                </ul>
            </div>
        </div>

        <!-- 배송/교환안내 탭 -->
        <div class="pd-tab-panel2" id="tab2-delivery">
            <div class="pd-info-text">
                <h4>배송 안내</h4>
                <ul>
                    <li>건설자재 특성상 <strong>화물 차량</strong>으로 배송되며, 배송비는 지역·수량·제품에 따라 별도 산정됩니다.</li>
                    <li>도서·산간 지역은 추가 운반비가 발생할 수 있습니다.</li>
                    <li>현장 진입이 어려운 경우 별도 협의가 필요합니다.</li>
                    <li>납기일은 재고 상황 및 생산 일정에 따라 달라질 수 있으며, 담당자가 확인 후 안내드립니다.</li>
                </ul>
                <h4>교환/반품 안내</h4>
                <ul>
                    <li>제품 하자·오배송의 경우 수령일로부터 <strong>7일 이내</strong>에 연락주시면 교환·반품 처리해 드립니다.</li>
                    <li>단순 변심, 현장 사정에 의한 반품은 원칙적으로 불가합니다.</li>
                    <li>반품 시 왕복 운송비 및 하역비는 고객 부담입니다.</li>
                    <li>절단·가공된 제품은 교환·반품이 불가합니다.</li>
                </ul>
            </div>
        </div>
        </div><!-- /.pd-tabs-wrap2 -->
    </div><!-- /.pd-tabs-wrap -->
</div>

<script>
function showTab(name) {
    document.querySelectorAll('#tab-btn-gallery, #tab-btn-others').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.pd-tab-panel').forEach(p => p.classList.remove('active'));
    const btn = document.getElementById('tab-btn-' + name);
    const panel = document.getElementById('tab-' + name);
    if (btn) btn.classList.add('active');
    if (panel) panel.classList.add('active');
}
function showTab2(name) {
    document.querySelectorAll('#tab-btn-notice, #tab-btn-delivery').forEach(b => b.classList.remove('active'));
    document.querySelectorAll('.pd-tab-panel2').forEach(p => p.classList.remove('active'));
    const btn = document.getElementById('tab-btn-' + name);
    const panel = document.getElementById('tab2-' + name);
    if (btn) btn.classList.add('active');
    if (panel) panel.classList.add('active');
}
function setGallery(url, el) {
    document.getElementById('gallery-main-img').src = url;
    document.querySelectorAll('.gallery-thumb').forEach(t => t.classList.remove('active'));
    el.classList.add('active');
}
function addCart(pid) {
    fetch('/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action:'add', product_id: pid, quantity: 1})
    }).then(r => r.json()).then(d => {
        showToast(d.ok ? '견적에 추가했습니다.' : (d.error || '오류가 발생했습니다.'), d.ok);
    });
}
function showToast(msg, ok = true) {
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = `position:fixed;top:50%;left:50%;z-index:9999;
        padding:16px 32px;background:${ok?'#111':'#e53935'};color:#fff;
        font-size:15px;font-weight:600;font-family:var(--font);
        box-shadow:0 6px 24px rgba(0,0,0,.22);
        transform:translate(-50%,-50%) scale(.85);opacity:0;
        transition:transform .2s ease,opacity .2s ease`;
    document.body.appendChild(t);
    requestAnimationFrame(() => {
        t.style.transform = 'translate(-50%,-50%) scale(1)';
        t.style.opacity = '1';
    });
    setTimeout(() => {
        t.style.opacity = '0';
        t.style.transform = 'translate(-50%,-50%) scale(.85)';
        t.addEventListener('transitionend', () => t.remove());
    }, 2200);
}
</script>
<?php render_footer(); ?>
