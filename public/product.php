<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';

$id = (int)($_GET['id'] ?? 0);
if (!$id || !db()) { header('Location: /index.php'); exit; }

$st = db()->prepare(
    "SELECT p.*, " . vendor_names_subquery() . " AS vendor_names,
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

// 관심상품 등록 여부(상세 진입 시 버튼 초기 상태). 저장·중복 방지·50개 제한은 /api/estimate/favorites.php
$isFavorite = false;
$favUserId  = (int) ($_SESSION['user']['id'] ?? 0);
if ($favUserId > 0) {
    try {
        $fst = db()->prepare('SELECT 1 FROM favorite_products WHERE user_id = ? AND product_id = ? LIMIT 1');
        $fst->execute([$favUserId, $id]);
        $isFavorite = (bool) $fst->fetchColumn();
    } catch (\Throwable $e) {
        error_log('product.php favorite_products 조회 실패: ' . $e->getMessage());
    }
}

// 시공사례 (중분류 기준)
// category_gallery 는 database/ 스키마·마이그레이션에 없는 테이블이라 로컬 DB 등에는 없을 수 있다.
// 조회 실패(테이블 없음) 시 상세 전체가 500 이 되지 않도록 상품 목록(products.php)과 같이 '준비 중' 표시로 폴백.
$galleryImgs = [];
if ($p['mid_id'] && db()) {
    try {
        $gst = db()->prepare("SELECT image_url, caption FROM category_gallery WHERE category_id = ? ORDER BY sort_order LIMIT 6");
        $gst->execute([(int)$p['mid_id']]);
        $galleryImgs = $gst->fetchAll();
    } catch (\Throwable $e) {
        error_log('product.php category_gallery 조회 실패: ' . $e->getMessage());
        $galleryImgs = [];
    }
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
/* 상품명 + 관심상품 버튼 한 줄 */
.pd-name-row{display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.pd-name-row .pd-product-name{flex:1;min-width:0;overflow-wrap:anywhere}
/* 관심상품 버튼 — 전역 button:hover(진한 파랑 배경·흰 글자)·button:disabled(회색)가 덮지 않도록 상태별 색을 명시.
   글자 길이가 달라도(추가/추가됨) 크기가 변하지 않게 min-width 고정. focus-visible 은 전역 규칙 유지 */
.pd-fav-btn{flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;min-width:152px;min-height:44px;padding:0 16px;border-radius:4px;font-size:14px;font-weight:600;font-family:var(--font);line-height:1.2;white-space:nowrap;cursor:pointer;transition:none}
.pd-fav-btn, .pd-fav-btn:hover, .pd-fav-btn:disabled{background:#fff;color:var(--color-primary);border:1px solid var(--color-primary)}
.pd-fav-btn.is-added, .pd-fav-btn.is-added:hover, .pd-fav-btn.is-added:disabled{background:#EEF4FC;color:var(--color-primary);border-color:#A9C3E6}
.pd-fav-btn:disabled{cursor:wait}
/* 견적 수량 [ − | 1 | + ] — 전역 button:hover(진한 파랑·흰 글자)·button:disabled(회색 배경·테두리)를 덮도록 상태별로 명시. focus-visible 은 전역 규칙 유지 */
.pd-qty-row{display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.pd-qty-label{font-size:13px;font-weight:700;color:var(--color-text-primary);width:72px;flex-shrink:0}
.pd-qty-stepper{display:inline-flex;align-items:stretch;height:44px;border:1px solid var(--color-border);border-radius:6px;background:#fff;overflow:hidden}
.pd-qty-btn, .pd-qty-btn:hover, .pd-qty-btn:disabled{width:44px;padding:0;border:none;border-radius:0;background:#fff;color:var(--color-text-primary);font-size:18px;font-weight:400;line-height:1;cursor:pointer;transition:none}
.pd-qty-btn:hover{background:var(--color-surface)}
.pd-qty-btn:disabled{color:var(--color-text-disabled);cursor:not-allowed}
.pd-qty-stepper .pd-qty-input, .pd-qty-stepper .pd-qty-input:focus{width:64px;padding:0 4px;border:none;border-left:1px solid var(--color-border);border-right:1px solid var(--color-border);border-radius:0;background:#fff;font-size:15px;font-weight:600;color:var(--color-text-primary);text-align:center;-moz-appearance:textfield;appearance:textfield}
.pd-qty-input::-webkit-outer-spin-button, .pd-qty-input::-webkit-inner-spin-button{-webkit-appearance:none;margin:0}
.pd-qty-unit{font-size:13px;color:var(--color-text-secondary)}
.pd-brand-text{font-size:13px;color:var(--color-text-secondary);margin-bottom:16px}
.pd-badges{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
.pd-bdg{font-size:11.5px;font-weight:700;padding:3px 9px;border-radius:6px}
.pd-bdg-load{background:#EEF1F4;color:#5B6B7B}
.pd-bdg-ks{background:#F0EDFA;color:#6B4FBF}
.pd-bdg-stock{background:#E7F6ED;color:#1F9D57}
.pd-divider{border:none;border-top:1px solid var(--color-border);margin:14px 0}
/* 가격 */
.pd-price-row{display:flex;align-items:baseline;gap:8px;margin-bottom:4px}
.pd-price-label{font-size:15px;color:var(--color-text-secondary);font-weight:500;min-width:40px}
.pd-price-val{font-size:28px;font-weight:600;color:var(--color-text-primary);letter-spacing:-0.5px;line-height:1.2}
/* 어드민/내부(등급별 4줄)는 라벨·금액 모두 작게 + 볼드 제거 */
.pd-price-grade{display:flex;flex-direction:column;gap:2px}
.pd-price-grade .pd-price-label{font-size:13px;font-weight:400}
.pd-price-grade .pd-price-val{font-size:16px;font-weight:400;letter-spacing:0}
.pd-price-grade .pd-price-sale,.pd-price-grade .pd-price-cost{font-weight:400}
.pd-price-unit{font-size:15px;color:var(--color-text-secondary)}
/* 스펙 */
.pd-spec-rows{display:flex;flex-direction:column;gap:0}
.pd-spec-row{display:flex;gap:12px;padding:7px 0;border-bottom:1px solid var(--color-bg-secondary)}
.pd-spec-row:last-child{border-bottom:none}
.pd-spec-key{font-size:13px;font-weight:700;color:var(--color-text-primary);width:72px;flex-shrink:0}
.pd-spec-val{font-size:13px;color:var(--color-text-secondary)}
/* 제품 설명 */
.pd-desc{font-size:13px;color:#555;line-height:1.8;margin-top:4px}
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
  .pd-price-val{font-size:24px}
  .pd-price-grade .pd-price-val{font-size:15px}
  .gallery-layout{grid-template-columns:1fr}
  .gallery-thumbs{flex-direction:row;flex-wrap:wrap}
}
/* 좁은 화면: 상품명이 버튼에 밀려 세로로 길어지지 않도록 버튼을 상품명 아래 줄로 */
@media(max-width:640px){
  .pd-name-row{flex-wrap:wrap;gap:4px 12px}
  .pd-name-row .pd-product-name{flex-basis:100%}
  /* 견적 수량: 라벨·스테퍼·단위가 한 줄에 들어가도록 폭만 줄임(높이 44px 유지) */
  .pd-qty-row{gap:8px}
  .pd-qty-label{width:auto}
  .pd-qty-btn, .pd-qty-btn:hover, .pd-qty-btn:disabled{width:40px}
  .pd-qty-stepper .pd-qty-input, .pd-qty-stepper .pd-qty-input:focus{width:52px}
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
            <div class="pd-name-row">
                <div class="pd-product-name"><?= e($p['name']) ?></div>
                <button type="button" class="pd-fav-btn<?= $isFavorite ? ' is-added' : '' ?>" id="pd-fav-btn"
                        data-pid="<?= (int)$p['id'] ?>" data-logged-in="<?= $loggedIn ? '1' : '0' ?>"><?= $isFavorite ? '관심상품에 추가됨' : '관심상품에 추가' ?></button>
            </div>
            <?php if (!empty($p['description'])): ?>
            <div class="pd-desc"><?= nl2br(e($p['description'])) ?></div>
            <?php endif; ?>
            <?php if ($p['brand']): ?><div class="pd-brand-text"><?= e($p['brand']) ?></div><?php endif; ?>
            <?php
            // 상품명·설명에서 상태 배지 도출
            $pdBadges = [];
            $pdNm = (string) $p['name']; $pdDesc = (string) ($p['description'] ?? '');
            if (mb_strpos($pdNm, '안전중하중') !== false)      $pdBadges[] = ['load', '안전중하중'];
            elseif (mb_strpos($pdNm, '중하중') !== false)       $pdBadges[] = ['load', '중하중'];
            if (mb_stripos($pdNm, 'KS') !== false || mb_strpos($pdDesc, 'KS') !== false) $pdBadges[] = ['ks', 'KS'];
            if ((int) ($p['stock_qty'] ?? 0) > 0)               $pdBadges[] = ['stock', '재고 보유'];
            if ($pdBadges): ?>
            <div class="pd-badges">
                <?php foreach ($pdBadges as $b): ?><span class="pd-bdg pd-bdg-<?= $b[0] ?>"><?= e($b[1]) ?></span><?php endforeach; ?>
            </div>
            <?php endif; ?>

            <hr class="pd-divider">

            <?php if ($p['price_b2c'] > 0):
                $pb = price_bundle($p);
                $tier = price_tier();
                $unit = $sp['unit'] ? ' / '.$sp['unit'] : '';
            ?>
            <?php if ($tier === 'internal'):
                $mk = static fn(int $pr): string => ($r = markup_rate($pr, (int) $pb['cost'])) === null ? '' : ' <span class="pd-price-margin">매입가 대비 ' . ($r >= 0 ? '+' : '') . $r . '%</span>';
            ?>
                <div class="pd-price-grade">
                <div class="pd-price-row"><span class="pd-price-label">판매가</span><span class="pd-price-val"><?= number_format($pb['list']) ?></span><span class="pd-price-unit">원<?= $unit ?> <small>(부가세별도)</small></span><?= $mk($pb['list']) ?></div>
                <div class="pd-price-row"><span class="pd-price-label">개인할인가<?= $pb['rate'] > 0 ? ' ('.$pb['rate'].'%)' : '' ?></span><span class="pd-price-val pd-price-sale"><?= number_format($pb['customer']) ?></span><span class="pd-price-unit">원<?= $unit ?></span><?= $mk($pb['customer']) ?></div>
                <div class="pd-price-row"><span class="pd-price-label">기업할인가</span><span class="pd-price-val pd-price-sale"><?= number_format($pb['corporate']) ?></span><span class="pd-price-unit">원<?= $unit ?></span><?= $mk($pb['corporate']) ?></div>
                <div class="pd-price-row pd-price-internal"><span class="pd-price-label">매입가</span><span class="pd-price-val pd-price-cost"><?= number_format($pb['cost']) ?></span><span class="pd-price-unit">원<?= $unit ?> <small>내부용</small></span></div>
                </div>
            <?php elseif ($tier === 'customer' && $pb['has_customer_discount']): ?>
                <div class="pd-price-row"><span class="pd-price-label">판매가</span><del class="pd-price-was"><?= number_format($pb['list']) ?>원</del></div>
                <div class="pd-price-row"><span class="pd-price-label">회원할인가</span><span class="pd-price-val pd-price-sale"><?= number_format($pb['customer']) ?></span><span class="pd-price-unit">원<?= $unit ?> <small>(부가세별도)</small></span></div>
            <?php elseif ($tier === 'corporate' && $pb['has_corporate_discount']): ?>
                <div class="pd-price-row"><span class="pd-price-label">판매가</span><del class="pd-price-was"><?= number_format($pb['list']) ?>원</del></div>
                <div class="pd-price-row"><span class="pd-price-label">기업회원가</span><span class="pd-price-val pd-price-sale"><?= number_format($pb['corporate']) ?></span><span class="pd-price-unit">원<?= $unit ?> <small>(부가세별도)</small></span></div>
            <?php else: ?>
                <div class="pd-price-row"><span class="pd-price-label">판매가</span><span class="pd-price-val"><?= number_format($pb['list']) ?></span><span class="pd-price-unit">원<?= $unit ?> <small>(부가세별도)</small></span></div>
            <?php endif; ?>
            <hr class="pd-divider">
            <?php else: ?>
            <div class="pd-price-row">
                <span class="pd-price-label">판매가</span>
                <span class="pd-price-val pd-price-ask">견적문의</span>
            </div>
            <hr class="pd-divider">
            <?php endif; ?>

            <?php $vnames = render_vendor_names($p['vendor_names'] ?? null, true); ?>
            <?php if ($vnames !== '' || $sp['spec'] || $sp['unit'] || $sp['weight'] || $sp['load'] || $sp['freight']): ?>
            <div class="pd-spec-rows">
                <?php if ($vnames !== ''): ?><div class="pd-spec-row"><span class="pd-spec-key">공급업체</span><span class="pd-spec-val pd-vendor-val"><?= $vnames ?></span></div><?php endif; ?>
                <?php if ($sp['spec']):   ?><div class="pd-spec-row"><span class="pd-spec-key">규격</span><span class="pd-spec-val"><?= e($sp['spec']) ?></span></div><?php endif; ?>
                <?php if ($sp['unit']):   ?><div class="pd-spec-row"><span class="pd-spec-key">단위</span><span class="pd-spec-val"><?= e($sp['unit']) ?></span></div><?php endif; ?>
                <?php if ($sp['weight']): ?><div class="pd-spec-row"><span class="pd-spec-key">중량</span><span class="pd-spec-val"><?= e($sp['weight']) ?></span></div><?php endif; ?>
                <?php if ($sp['load']):   ?><div class="pd-spec-row"><span class="pd-spec-key">상차수량</span><span class="pd-spec-val"><?= e($sp['load']) ?></span></div><?php endif; ?>
                <?php if ($sp['freight']): ?><div class="pd-spec-row"><span class="pd-spec-key">운반비예상</span><span class="pd-spec-val"><?= e($sp['freight']) ?></span></div><?php endif; ?>
            </div>
            <hr class="pd-divider">
            <?php endif; ?>

            <?php
            // ── 물량·운반 계산기: 단위중량(kg)이 있으면 수량→총중량·권장차량 산출 ──
            $unitKg = 0.0;
            if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*kg/i', (string) ($sp['weight'] ?? ''), $wm)) {
                $unitKg = (float) $wm[1];
            }
            $frUnit = $sp['unit'] ?: '개';
            ?>
            <!-- 견적 수량: '견적에 넣기'로 담을 수량. 운반비 계산기 수량(frQty)과 양방향 동기화. 범위는 장바구니 API와 같은 1~999 -->
            <div class="pd-qty-row">
                <span class="pd-qty-label" id="pdQtyLabel">견적 수량</span>
                <div class="pd-qty-stepper" role="group" aria-labelledby="pdQtyLabel">
                    <button type="button" class="pd-qty-btn" id="pdQtyMinus" aria-label="견적 수량 줄이기" disabled>&minus;</button>
                    <input type="number" class="pd-qty-input" id="pdQty" value="1" min="1" max="999" step="1" inputmode="numeric" aria-label="견적 수량">
                    <button type="button" class="pd-qty-btn" id="pdQtyPlus" aria-label="견적 수량 늘리기">+</button>
                </div>
                <?php if ($sp['unit']): ?><span class="pd-qty-unit"><?= e($sp['unit']) ?></span><?php endif; ?>
            </div>
            <hr class="pd-divider">
            <?php if ($unitKg > 0): ?>
            <?php $sidoList = array_keys(FREIGHT_SIDO_COORDS); ?>
            <div class="pd-freight" data-pid="<?= (int) $p['id'] ?>" data-unitkg="<?= $unitKg ?>">
                <div class="pd-freight-title">🚚 물량·운반비 계산기</div>
                <div class="pd-freight-in">
                    <label for="frQty">수량</label>
                    <input type="number" id="frQty" min="1" max="999" value="1" oninput="calcFreight()">
                    <span class="pd-freight-unit"><?= e($frUnit) ?></span>
                </div>
                <div class="pd-freight-dest">
                    <select id="frDest" onchange="calcFreight()" aria-label="현장(도착) 지역">
                        <option value="">지역 선택</option>
                        <?php foreach ($sidoList as $s): ?><option value="<?= e($s) ?>"><?= e($s) ?></option><?php endforeach; ?>
                    </select>
                    <button type="button" class="pd-freight-btn" onclick="calcFreight()">계산</button>
                </div>
                <div class="pd-freight-out" id="frOut">현장(도착) 지역을 선택하면 예상 운임을 계산합니다.</div>
                <p class="pd-freight-note">단위중량 <?= number_format($unitKg) ?>kg 기준. 운임은 <strong>화물 시세(부가세 별도·지게차 상하차·편도)</strong>로 산출한 <strong>예상치(참고용)</strong>이며, 실제 운반비는 상·하차 방식·거리·차량 수급에 따라 <a href="/estimate.php">견적요청</a> 시 확정됩니다.</p>
            </div>
            <script>
            (function(){
              var box=document.querySelector('.pd-freight'); if(!box) return;
              var pid=box.dataset.pid, uk=parseFloat(box.dataset.unitkg||'0');
              var out=document.getElementById('frOut'), ctrl=null;
              window.calcFreight=function(){
                var q=Math.max(1, parseInt((document.getElementById('frQty').value||'1'),10));
                var dest=document.getElementById('frDest').value;
                var tot=uk*q;
                var totStr = tot>=1000 ? (Math.round(tot/100)/10).toLocaleString()+'톤('+Math.round(tot).toLocaleString()+'kg)' : Math.round(tot).toLocaleString()+'kg';
                if(!dest){ out.innerHTML='총중량 <strong>'+totStr+'</strong> · 현장 지역을 선택하면 예상 운임을 계산합니다.'; return; }
                out.textContent='총중량 '+totStr+' · 운임 계산 중…';
                if(ctrl) ctrl.abort(); ctrl=new AbortController();
                fetch('/api/freight.php?product_id='+encodeURIComponent(pid)+'&dest='+encodeURIComponent(dest)+'&qty='+q,{signal:ctrl.signal})
                  .then(function(r){return r.json();})
                  .then(function(d){
                    if(!d.ok){ out.textContent=d.error||'운임을 계산할 수 없습니다.'; return; }
                    var tr=d.trucks.map(function(t){return t.ton+'톤×'+t.count;}).join(' + ');
                    var html='총중량 <strong>'+totStr+'</strong> · 차량 <strong>'+tr+'</strong> ('+d.origin+'→'+d.dest+' 약 '+Math.round(d.km)+'km)';
                    html+='<div class="pd-freight-cost">예상 운임 <strong>'+d.freight.toLocaleString()+'원</strong> <span>(부가세 별도)</span></div>';
                    if(d.over400) html+='<div class="pd-freight-warn">400km 초과 장거리는 별도 협의가 필요합니다.</div>';
                    out.innerHTML=html;
                  })
                  .catch(function(e){ if(e.name!=='AbortError') out.textContent='운임 계산 중 오류가 발생했습니다.'; });
              };
              calcFreight();
            })();
            </script>
            <hr class="pd-divider">
            <?php endif; ?>

            <div class="pd-btn-row">
                <?php if (!$loggedIn): ?>
                <button class="btn-pd-action" onclick="openAuthModal('login')">견적에 넣기</button>
                <button class="btn-pd-action" onclick="openAuthModal('login')">견적 요청</button>
                <div class="pd-login-notice">견적·주문은 로그인 후 이용 가능합니다 (기업회원가 추가 공개)</div>
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
// 견적 수량(pdQty) ↔ 운반비 계산기 수량(frQty) 양방향 동기화. 범위 1~999(장바구니 API 범위)
// - 루프 방지: 값은 .value 대입으로만 바꾸고(프로그램 대입은 input/change 이벤트를 발생시키지 않음) dispatchEvent 하지 않음
// - 커서 튐 방지: 입력 중(input)에는 상대 칸만 갱신하고, 입력 중인 칸은 확정(change·blur) 때 보정. 999 초과만 즉시 999로
// - 보정: 빈 값·0·음수·숫자 아님 → 1 (기존 견적 수량 정책)
const PD_QTY_MIN = 1, PD_QTY_MAX = 999;
function pdQtyClamp(raw) {
    const n = parseInt(raw, 10);
    return isNaN(n) ? PD_QTY_MIN : Math.min(PD_QTY_MAX, Math.max(PD_QTY_MIN, n));
}
function pdQtyValue() {
    const el = document.getElementById('pdQty');
    return el ? pdQtyClamp(el.value) : PD_QTY_MIN;
}
(function () {
    const input = document.getElementById('pdQty');
    const minus = document.getElementById('pdQtyMinus');
    const plus  = document.getElementById('pdQtyPlus');
    const fr    = document.getElementById('frQty');   // 계산기는 단위중량이 있는 상품에만 있음
    if (!input || !minus || !plus) return;
    function recalc() {
        if (fr && typeof window.calcFreight === 'function') window.calcFreight();   // 기존 계산 로직 그대로 재실행
    }
    function buttons(v) {
        minus.disabled = v <= PD_QTY_MIN;
        plus.disabled  = v >= PD_QTY_MAX;
    }
    // 확정 값 v 를 두 칸에 같이 표시. 계산기가 쓰던 수량(직전 칸 값을 보정한 값)이 바뀐 경우에만 운임 재계산
    // (값이 같은데 다시 계산하면 결과 영역 높이가 잠깐 바뀌어 바로 누른 '견적에 넣기' 클릭이 빗나갈 수 있음)
    function setAll(v) {
        const prevFr = fr ? pdQtyClamp(fr.value) : v;
        input.value = String(v);
        if (fr) fr.value = String(v);
        buttons(v);
        if (prevFr !== v) recalc();
    }
    // 입력 중인 칸(src) → 상대 칸(other) 즉시 반영. 아직 유효하지 않은 값(빈 값·0 등)은 확정 때 보정하므로 상대 칸 유지
    function live(src, other) {
        const n = parseInt(src.value, 10);
        if (isNaN(n) || n < PD_QTY_MIN) return false;
        const v = Math.min(PD_QTY_MAX, n);
        if (n > PD_QTY_MAX) src.value = String(PD_QTY_MAX);
        if (other) other.value = String(v);
        buttons(v);
        return true;
    }
    minus.addEventListener('click', function () { setAll(pdQtyClamp(pdQtyValue() - 1)); });
    plus.addEventListener('click', function () { setAll(pdQtyClamp(pdQtyValue() + 1)); });
    input.addEventListener('input', function () { if (live(input, fr)) recalc(); });
    input.addEventListener('change', function () { setAll(pdQtyClamp(input.value)); });
    if (fr) {
        // 계산기 칸의 운임 재계산은 기존 oninput="calcFreight()"가 먼저 처리. 999 초과로 값이 바뀐 경우만 다시 계산
        fr.addEventListener('input', function () {
            const before = fr.value;
            if (live(fr, input) && fr.value !== before) recalc();
        });
        fr.addEventListener('change', function () { setAll(pdQtyClamp(fr.value)); });
    }
    // 진입 시 두 칸을 같은 값(기본 1)으로 — 뒤로가기 등으로 브라우저가 이전 입력값을 복원한 경우도 견적 수량 기준으로 맞춤
    setAll(pdQtyValue());
})();
function addCart(pid) {
    fetch('/api/cart.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({action:'add', product_id: pid, quantity: pdQtyValue()})
    }).then(r => r.json()).then(d => {
        showToast(d.ok ? '견적에 추가했습니다.' : (d.error || '오류가 발생했습니다.'), d.ok);
    });
}
// 관심상품 추가 — 기존 API 다중 형식({product_ids:[id]})으로 저장: 이미 등록된 상품은 기존 내역(수량) 유지·중복 저장 없음
(function () {
    const btn = document.getElementById('pd-fav-btn');
    if (!btn) return;
    let busy = false;
    function setAdded() {
        btn.classList.add('is-added');
        btn.textContent = '관심상품에 추가됨';
    }
    function askLogin(msg) {
        if (!confirm(msg + '\n로그인하시겠습니까?')) return;
        // 상품 목록 관심상품과 같은 로그인 모달(로그인 성공 시 새로고침 → 등록 여부 다시 표시). 없으면 로그인 페이지로.
        if (typeof window.openAuthModal === 'function') { window.openAuthModal('login'); return; }
        location.href = '/login.php?redirect=' + encodeURIComponent(location.pathname + location.search);
    }
    btn.addEventListener('click', async function () {
        if (btn.getAttribute('data-logged-in') !== '1') { askLogin('관심상품은 로그인 후 이용할 수 있습니다.'); return; }
        if (busy) return;
        busy = true;
        btn.disabled = true;
        try {
            const r = await fetch('/api/estimate/favorites.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                credentials: 'same-origin',
                body: JSON.stringify({product_ids: [parseInt(btn.dataset.pid, 10)]})
            });
            if (r.status === 401) { askLogin('로그인이 만료되었습니다. 관심상품은 저장되지 않았습니다.'); return; }
            let d = null;
            try { d = await r.json(); } catch (e) { d = null; }
            if (!r.ok || !d || !d.ok) {
                showToast((d && d.error) || '관심상품 저장에 실패했습니다. 잠시 후 다시 시도해 주세요.', false);
                return;
            }
            if ((d.added || []).length) {
                setAdded();
                showToast('관심상품에 추가했습니다.');
            } else if ((d.already || []).length) {
                setAdded();
                showToast('이미 관심상품에 등록된 상품입니다.');
            } else {
                const f = (d.failed || [])[0];
                showToast(f && f.reason === 'limit'
                    ? '관심상품은 최대 ' + (d.max || 50) + '개까지 등록할 수 있습니다.'
                    : '판매 중인 상품이 아니어서 추가할 수 없습니다.', false);
            }
        } catch (e) {
            showToast('네트워크 오류로 관심상품을 저장하지 못했습니다. 다시 시도해 주세요.', false);
        } finally {
            busy = false;
            btn.disabled = false;
        }
    });
})();
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
