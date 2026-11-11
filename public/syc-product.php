<?php
declare(strict_types=1);
/**
 * 상용콘크리트 상품 상세 — 관리자 '상용콘크리트로 보기' 미리보기 전용(사용자 결정 2026-11-11, (가) 단계)
 *
 * 책임: syc_products 1건 — 대표 이미지(없으면 썸네일, 둘 다 없으면 기존 '이미지 준비 중'), 등급별 가격(기존 price_bundle 같은 식),
 *       규격(syc_category_spec_axes 이름표: 주요 치수 = 목록 칸, 세부 치수 = 상세에만), 같은 품목의 다른 규격.
 * 금지: 고객 노출(미리보기가 아니면 홈으로). 견적·장바구니·관심상품은 (나) 단계(이 화면에서 없음).
 * 화면 틀·클래스는 기존 상세(public/product.php)와 같다. 로직: app/syc_catalog.php
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/syc_catalog.php';

if (!syc_preview_on()) { redirect('/index.php'); }
$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0 || !db() || !syc_tables_ready()) { redirect('/syc-products.php'); }

$p = null;
$others = [];
try {
    $st = db()->prepare('SELECT * FROM syc_products WHERE id = ?' . (syc_preview_include_inactive() ? '' : ' AND is_active = 1'));
    $st->execute([$id]);
    $p = $st->fetch() ?: null;
    if ($p) {
        $ost = db()->prepare('SELECT id, name, spec, spec_code, image_thumb_file, image_file, is_active FROM syc_products
                               WHERE category_id = ? AND id <> ?' . (syc_preview_include_inactive() ? '' : ' AND is_active = 1') . ' ORDER BY id LIMIT 24');
        $ost->execute([(int) $p['category_id'], $id]);
        $others = $ost->fetchAll();
    }
} catch (\Throwable $e) {
    error_log('[syc-product] ' . get_class($e) . ': ' . $e->getMessage());
}
if (!$p) { redirect('/syc-products.php'); }

$all   = syc_categories_all();
$cat   = $all[(int) $p['category_id']] ?? null;
$group = ($cat && $cat['parent_id'] !== null) ? ($all[(int) $cat['parent_id']] ?? null) : null;
$axes  = syc_axes_rows()[(int) $p['category_id']] ?? [];
$main = $detail = [];
foreach ($axes as $a) {
    $v = syc_axis_row_value($p, $a['axis_key']);
    if ($v === null) { continue; }
    $row = [spec_axis_title($a), spec_axis_value_display($a, $v) . (in_array($a['axis_key'], SYC_AXIS_TEXT, true) || $a['unit'] === 'no' ? '' : ' ' . $a['unit'])];
    if ($a['in_list']) { $main[] = $row; } else { $detail[] = $row; }
}
$pb   = price_bundle(syc_price_row($p, $all));
$tier = price_tier();
$unitLabel = unit_display_label((string) ($p['unit'] ?? ''));
$unit = $unitLabel !== '' ? ' / ' . $unitLabel : '';
$mainImg = $p['image_file'] ?: $p['image_thumb_file'];

render_header($p['name']);
?>
<style>
.pd-wrap{max-width:1200px;margin:0 auto;padding:28px 24px 60px}
.pd-title-row{display:flex;align-items:baseline;justify-content:space-between;margin-bottom:20px;gap:16px;flex-wrap:wrap}
.pd-page-title{font-size:18px;font-weight:700;color:var(--color-text-primary)}
.pd-page-title span{font-weight:400}
.pd-breadcrumb{display:flex;align-items:center;gap:5px;font-size:11px;color:var(--color-text-secondary);flex-wrap:wrap}
.pd-breadcrumb a{color:#aaa;text-decoration:none}
.pd-breadcrumb a:hover{color:var(--color-primary)}
.pd-body{display:grid;grid-template-columns:1fr 420px;border:1px solid var(--color-border);border-radius:10px;overflow:hidden}
.pd-img-area{background:#f7f7f7;display:flex;align-items:center;justify-content:center;min-height:420px;padding:32px}
.pd-img-area img{width:100%;max-height:400px;object-fit:contain;display:block}
.pd-img-empty-msg{color:var(--color-text-secondary);font-size:14px}
.pd-info-area{border-left:1px solid var(--color-border);padding:32px 28px;display:flex;flex-direction:column;min-width:0}
.pd-product-name{font-size:24px;font-weight:400;color:var(--color-text-primary);line-height:1.3;margin-bottom:6px;letter-spacing:-0.5px;overflow-wrap:anywhere}
.pd-brand-text{font-size:13px;color:var(--color-text-secondary);margin-bottom:8px}
.pd-desc{font-size:13px;color:#555;line-height:1.8;margin-top:4px}
.pd-divider{border:none;border-top:1px solid var(--color-border);margin:14px 0}
.pd-price-row{display:flex;align-items:baseline;gap:8px;margin-bottom:4px;flex-wrap:wrap}
.pd-price-label{font-size:15px;color:var(--color-text-secondary);font-weight:500;min-width:40px}
.pd-price-val{font-size:28px;font-weight:600;color:var(--color-text-primary);letter-spacing:-0.5px;line-height:1.2}
.pd-price-grade{display:flex;flex-direction:column;gap:2px}
.pd-price-grade .pd-price-label{font-size:13px;font-weight:400}
.pd-price-grade .pd-price-val{font-size:16px;font-weight:400;letter-spacing:0}
.pd-price-unit{font-size:15px;color:var(--color-text-secondary)}
.pd-spec-rows{display:flex;flex-direction:column}
.pd-spec-row{display:flex;gap:12px;padding:7px 0;border-bottom:1px solid var(--color-bg-secondary)}
.pd-spec-row:last-child{border-bottom:none}
.pd-spec-key{font-size:13px;font-weight:700;color:var(--color-text-primary);width:96px;flex-shrink:0}
.pd-spec-val{font-size:13px;color:var(--color-text-secondary);min-width:0;overflow-wrap:anywhere}
.syc-dim{margin-top:28px}
.syc-dim h2{font-size:16px;font-weight:700;margin:0 0 10px}
.syc-dim-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));border-top:2px solid var(--color-text-primary)}
.syc-dim-grid div{display:flex;justify-content:space-between;gap:8px;padding:10px 12px;border-bottom:1px solid var(--color-border);font-size:13px}
.syc-dim-grid b{font-weight:600;color:var(--color-text-primary)}
.syc-dim-grid span{color:var(--color-text-secondary);white-space:nowrap}
.syc-others{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px}
.syc-others a{display:flex;flex-direction:column;gap:6px;padding:10px;border:1px solid var(--color-border);border-radius:8px;text-decoration:none;color:var(--color-text-primary);font-size:12.5px;min-height:44px}
.syc-others a:hover{border-color:var(--color-primary)}
.syc-others img{width:100%;height:96px;object-fit:contain;background:#f7f7f7}
.syc-others small{color:var(--color-text-secondary);overflow-wrap:anywhere}
.syc-off{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:4px;background:#FCE4EC;color:#C62828;font-size:11px;font-weight:700;vertical-align:middle}
@media(max-width:800px){.pd-body{grid-template-columns:minmax(0,1fr)}.pd-img-area{min-height:260px;padding:20px}.pd-img-area img{max-height:240px}.pd-info-area{border-left:none;border-top:1px solid var(--color-border)}.pd-price-val{font-size:24px}}
@media(max-width:640px){.pd-wrap{padding-top:20px}.pd-info-area{padding:20px 16px}.pd-product-name{font-size:21px}}
</style>
<div class="pd-wrap">
    <div class="pd-title-row">
        <div class="pd-page-title"><span>선택품목</span> 상세보기</div>
        <nav class="pd-breadcrumb" aria-label="현재 위치">
            <a href="/index.php">홈</a> › <a href="/syc-products.php">상용콘크리트</a>
            <?php if ($group): ?> › <a href="/syc-products.php?category=<?= (int) $group['id'] ?>"><?= e($group['name']) ?></a><?php endif; ?>
            <?php if ($cat): ?> › <a href="/syc-products.php?category=<?= (int) $cat['id'] ?>"><?= e($cat['name']) ?></a><?php endif; ?>
            › <span><?= e($p['name']) ?></span>
        </nav>
    </div>

    <div class="pd-body">
        <div class="pd-img-area">
            <?php if ($mainImg): ?><img src="<?= e($mainImg) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><span class="pd-img-empty-msg">이미지 준비 중</span><?php endif; ?>
        </div>
        <div class="pd-info-area">
            <div class="pd-product-name"><?= e($p['name']) ?><?php if ((int) $p['is_active'] !== 1): ?><span class="syc-off">비활성</span><?php endif; ?></div>
            <?php if ($p['brand']): ?><div class="pd-brand-text"><?= e($p['brand']) ?></div><?php endif; ?>
            <?php if (!empty($p['description'])): ?><div class="pd-desc"><?= nl2br(e($p['description'])) ?></div><?php endif; ?>
            <hr class="pd-divider">

            <?php if ($pb['list'] > 0): ?>
                <?php if ($tier === 'internal'):
                    $mk = static fn(int $pr): string => ($r = markup_rate($pr, (int) $pb['cost'])) === null ? '' : ' <span class="pd-price-margin">매입가 대비 ' . ($r >= 0 ? '+' : '') . $r . '%</span>'; ?>
                <div class="pd-price-grade">
                    <div class="pd-price-row"><span class="pd-price-label">판매가</span><span class="pd-price-val"><?= number_format($pb['list']) ?></span><span class="pd-price-unit">원<?= e($unit) ?> <small>(부가세별도)</small></span><?= $mk($pb['list']) ?></div>
                    <div class="pd-price-row"><span class="pd-price-label">개인할인가<?= $pb['rate'] > 0 ? ' (' . $pb['rate'] . '%)' : '' ?></span><span class="pd-price-val pd-price-sale"><?= number_format($pb['customer']) ?></span><span class="pd-price-unit">원<?= e($unit) ?></span><?= $mk($pb['customer']) ?></div>
                    <div class="pd-price-row"><span class="pd-price-label">기업할인가</span><span class="pd-price-val pd-price-sale"><?= number_format($pb['corporate']) ?></span><span class="pd-price-unit">원<?= e($unit) ?></span><?= $mk($pb['corporate']) ?></div>
                    <div class="pd-price-row pd-price-internal"><span class="pd-price-label">매입가</span><span class="pd-price-val pd-price-cost"><?= number_format($pb['cost']) ?></span><span class="pd-price-unit">원<?= e($unit) ?> <small>내부용</small></span></div>
                </div>
                <?php elseif ($tier === 'customer' && $pb['has_customer_discount']): ?>
                <div class="pd-price-row"><span class="pd-price-label">판매가</span><del class="pd-price-was"><?= number_format($pb['list']) ?>원</del></div>
                <div class="pd-price-row"><span class="pd-price-label">회원할인가</span><span class="pd-price-val pd-price-sale"><?= number_format($pb['customer']) ?></span><span class="pd-price-unit">원<?= e($unit) ?> <small>(부가세별도)</small></span></div>
                <?php elseif ($tier === 'corporate' && $pb['has_corporate_discount']): ?>
                <div class="pd-price-row"><span class="pd-price-label">판매가</span><del class="pd-price-was"><?= number_format($pb['list']) ?>원</del></div>
                <div class="pd-price-row"><span class="pd-price-label">기업회원가</span><span class="pd-price-val pd-price-sale"><?= number_format($pb['corporate']) ?></span><span class="pd-price-unit">원<?= e($unit) ?> <small>(부가세별도)</small></span></div>
                <?php else: ?>
                <div class="pd-price-row"><span class="pd-price-label">판매가</span><span class="pd-price-val"><?= number_format($pb['list']) ?></span><span class="pd-price-unit">원<?= e($unit) ?> <small>(부가세별도)</small></span></div>
                <?php endif; ?>
            <?php else: ?>
                <div class="pd-price-row"><span class="pd-price-label">판매가</span><span class="pd-price-val pd-price-ask">견적문의</span></div>
            <?php endif; ?>
            <hr class="pd-divider">

            <div class="pd-spec-rows">
                <?php if ($p['brand']): ?><div class="pd-spec-row"><span class="pd-spec-key">공급업체</span><span class="pd-spec-val"><?= e($p['brand']) ?></span></div><?php endif; ?>
                <?php if ($p['spec']): ?><div class="pd-spec-row"><span class="pd-spec-key">규격</span><span class="pd-spec-val"><?= e($p['spec']) ?></span></div><?php endif; ?>
                <?php if ($p['spec_code']): ?><div class="pd-spec-row"><span class="pd-spec-key">모델 코드</span><span class="pd-spec-val"><?= e($p['spec_code']) ?></span></div><?php endif; ?>
                <?php if ($p['spec_option']): ?><div class="pd-spec-row"><span class="pd-spec-key">옵션</span><span class="pd-spec-val"><?= e(spec_option_display((string) $p['spec_option'])) ?></span></div><?php endif; ?>
                <?php if ($unitLabel !== ''): ?><div class="pd-spec-row"><span class="pd-spec-key">단위</span><span class="pd-spec-val"><?= e($unitLabel) ?></span></div><?php endif; ?>
                <?php if ($p['weight_kg'] !== null): ?><div class="pd-spec-row"><span class="pd-spec-key">중량</span><span class="pd-spec-val"><?= e(rtrim(rtrim(number_format((float) $p['weight_kg'], 2, '.', ','), '0'), '.')) ?>kg</span></div><?php endif; ?>
            </div>
        </div>
    </div>

    <?php foreach ([['주요 치수', $main], ['세부 치수', $detail]] as [$title, $list]): if (!$list) { continue; } ?>
    <section class="syc-dim" aria-label="<?= e($title) ?>">
        <h2><?= e($title) ?></h2>
        <div class="syc-dim-grid"><?php foreach ($list as [$k, $v]): ?><div><b><?= e($k) ?></b><span><?= e($v) ?></span></div><?php endforeach; ?></div>
    </section>
    <?php endforeach; ?>

    <?php if ($others): ?>
    <section class="syc-dim" aria-label="같은 품목 다른 규격">
        <h2>같은 품목 다른 규격</h2>
        <div class="syc-others">
            <?php foreach ($others as $o): $oi = $o['image_thumb_file'] ?: $o['image_file']; ?>
            <a href="/syc-product.php?id=<?= (int) $o['id'] ?>">
                <?php if ($oi): ?><img src="<?= e($oi) ?>" alt=""><?php endif; ?>
                <span><?= e($o['name']) ?><?php if ((int) $o['is_active'] !== 1): ?><span class="syc-off">비활성</span><?php endif; ?></span>
                <small><?= e((string) $o['spec']) ?></small>
            </a>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>
</div>
<?php render_footer(); ?>
