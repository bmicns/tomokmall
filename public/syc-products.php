<?php
declare(strict_types=1);
/**
 * 상용콘크리트 품목 목록 — 관리자 '상용콘크리트로 보기' 미리보기 전용(사용자 결정 2026-11-11, (가) 단계)
 *
 * 책임: syc_categories 카테고리별 syc_products 목록. 규격은 syc_category_spec_axes 이름표대로 '규격' 머리글 아래 칸을 나누고,
 *       왼쪽에 규격 필터(위)·카테고리(아래). 치수 칸 구성이 다른 상품은 표를 나눔(syc_list_groups). 가격은 기존 계산(price_tag_list → price_bundle)에 상용콘크리트 마진율을 넣어 같은 식으로.
 * 금지: 고객 노출(미리보기가 아니면 홈으로), products·categories 조회·변경.
 * 화면 틀·클래스는 기존 목록(public/products.php)과 같다. 로직: app/syc_catalog.php
 * URL: ?category=<syc_categories.id>&s=<목록검색>&sort=&ax_<키>[]=값  (모델 코드 필터 없음)
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/product_filter_renderer.php';
require_once __DIR__ . '/../app/syc_catalog.php';

if (!syc_preview_on()) { redirect('/index.php'); }

$categoryId = max(0, (int) ($_GET['category'] ?? 0));
$search     = mb_substr(trim((string) ($_GET['s'] ?? '')), 0, 60);
$sort       = in_array($_GET['sort'] ?? '', ['price_asc', 'price_desc', 'newest'], true) ? (string) $_GET['sort'] : '';
$f          = syc_filter_input($_GET);
$f['ax_code'] = [];   // 왼쪽 필터에 모델 코드 그룹 없음(사용자 결정 2026-11-11) — 예전 주소의 ax_code 도 무시
$ready      = db() !== null && syc_tables_ready();

$all = $tree = $rows = $shown = $axes = [];
$facets = ['defs' => []];
$category = $group = null;
if ($ready) {
    try {
        $all  = syc_categories_all();
        $tree = syc_category_tree($all);
        if ($categoryId && isset($all[$categoryId])) {
            $category = $all[$categoryId];
            $group = $category['parent_id'] !== null ? ($all[(int) $category['parent_id']] ?? null) : null;
        } else {
            $categoryId = 0;
        }
        if ($categoryId || $search !== '') {
            $rows = syc_fetch_products($categoryId ? syc_category_ids($categoryId, $all) : [], $search, $sort, syc_preview_include_inactive());
            $axes = syc_axes_common(array_map(static fn($p) => (int) $p['category_id'], $rows), syc_axes_rows());
            $facets = syc_facets($rows, $axes, $f);
            $shown = array_values(array_filter($rows, static fn($p) => syc_row_matches($p, $f)));
        }
    } catch (\Throwable $e) {
        error_log('[syc-products] ' . get_class($e) . ': ' . $e->getMessage());
        $ready = false;
    }
}
$isInternal = price_tier() === 'internal';
$pageTitle  = $category['name'] ?? ($search !== '' ? $search : '상용콘크리트 제품');

// 표 칸: 목록 이름표 중 값이 있는 칸(필터에 하나뿐인 값이면 필터에 고정 표시되므로 표에서 뺌 — 기존 목록과 같은 규칙)
$axisCols = [];
foreach ($axes as $a) {
    $n = 0; $set = [];
    foreach ($rows as $p) { $v = syc_axis_row_value($p, $a['axis_key']); if ($v !== null) { $set[$v] = 1; } }
    $n = count($set);
    if ($n === 0 || ($n === 1 && $a['use_filter'])) { continue; }
    $axisCols[] = $a;
}
// 표 나누기: 치수 칸 구성이 다른 상품은 따로 표(서로 없는 치수가 빈칸으로 보이지 않게 — syc_list_groups)
$tables = [];
foreach (syc_list_groups($shown, $axisCols) as $g) {
    $t = $g + ['hasCode' => false, 'hasOpt' => false, 'hasUnit' => false, 'hasWeight' => false];
    foreach ($g['rows'] as $p) {
        if ((string) ($p['spec_code'] ?? '') !== '')   { $t['hasCode'] = true; }
        if ((string) ($p['spec_option'] ?? '') !== '') { $t['hasOpt'] = true; }
        if ((string) ($p['unit'] ?? '') !== '')        { $t['hasUnit'] = true; }
        if ($p['weight_kg'] !== null)                  { $t['hasWeight'] = true; }
    }
    $t['span'] = $axes ? count($g['axes']) + ($t['hasCode'] ? 1 : 0) + ($t['hasOpt'] ? 1 : 0) : 0;
    $tables[] = $t;
}

// 필터 그룹(기존 함수 — 값 버튼 모양·고정 값 처리 같음). 모델 코드 그룹은 빼고 치수 칸만(사용자 결정 2026-11-11)
$fgroups = $facets['defs'] ? product_filter_axis_groups(['type_all' => [], 'type' => []] + $facets, $f) : [];
$keepGet = array_filter(['category' => $categoryId ?: null, 's' => $search !== '' ? $search : null, 'sort' => $sort !== '' ? $sort : null]);

// 적용된 필터 칩
$chips = [];
$defByKey = array_column($axes, null, 'axis_key');
foreach ($f['ax'] as $k => $vals) {
    foreach ($vals as $v) {
        $d = $defByKey[$k] ?? ['axis_key' => $k, 'label' => '규격', 'unit' => 'mm'];
        $chips[] = [(string) $d['label'], spec_axis_value_display($d, $v), syc_list_url($_GET, ['ax_' . $k => array_values(array_diff($vals, [$v]))])];
    }
}
foreach ($f['ax_code'] as $v) { $chips[] = ['모델 코드', $v, syc_list_url($_GET, ['ax_code' => array_values(array_diff($f['ax_code'], [$v]))])]; }

render_header($pageTitle);
?>
<style>
.syc-cat-box{margin-bottom:12px}
.syc-cat-sum{display:none}
@media(max-width:760px){
  .syc-cat-sum{display:flex;align-items:center;min-height:44px;padding:0 14px;border:1px solid var(--color-border);border-radius:8px;background:#fff;font-size:14px;font-weight:700;cursor:pointer}
  .syc-cat-box[open] .syc-cat-sum{border-radius:8px 8px 0 0;border-bottom:0}
  .syc-cat-box .syc-cat-nav{border-radius:0 0 8px 8px}
  .syc-cat-box .syc-cat-nav h2{display:none}
}
.syc-cat-nav{border:1px solid var(--color-border);border-radius:8px;background:#fff;padding:12px 14px}
.syc-cat-nav h2{font-size:14px;font-weight:700;margin:0 0 8px}
.syc-cat-nav details{border-top:1px solid var(--color-bg-secondary)}
.syc-cat-nav details:first-of-type{border-top:0}
.syc-cat-nav summary{cursor:pointer;padding:9px 0;font-size:13px;font-weight:600;list-style-position:inside;min-height:24px}
.syc-cat-nav ul{list-style:none;margin:0 0 8px;padding:0 0 0 10px;display:flex;flex-direction:column;gap:2px}
.syc-cat-nav a{display:block;padding:6px 6px;border-radius:4px;font-size:12.5px;color:var(--color-text-secondary);text-decoration:none;line-height:1.4}
.syc-cat-nav a:hover{background:#F5F9FF;color:var(--color-primary)}
.syc-cat-nav a.is-on{background:#E8F0FE;color:#004EA1;font-weight:700}
.syc-index{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:12px}
.syc-index section{border:1px solid var(--color-border);border-radius:8px;padding:14px 16px;background:#fff}
.syc-index h3{font-size:15px;margin:0 0 8px}
.syc-index ul{margin:0;padding-left:16px;font-size:13px;line-height:1.9}
.syc-index a{color:var(--color-text-primary);text-decoration:none}
.syc-index a:hover{color:var(--color-primary);text-decoration:underline}
.syc-off{display:inline-block;margin-left:6px;padding:1px 6px;border-radius:4px;background:#FCE4EC;color:#C62828;font-size:11px;font-weight:700;vertical-align:middle}
.syc-weight{white-space:nowrap}
/* 품명: 길면 말줄임 대신 줄바꿈(최대 3줄 — 사진 칸 높이 안이라 행 높이는 그대로) */
.syc-list .pt-col-name .pt-name-text{white-space:normal;word-break:keep-all;overflow-wrap:anywhere;line-height:1.4;display:-webkit-box;-webkit-box-orient:vertical;-webkit-line-clamp:3;overflow:hidden}
/* 칸 구성이 다른 상품끼리 나눈 표 */
.syc-grp-title{display:flex;align-items:baseline;gap:8px;margin:22px 0 8px;font-size:15px;font-weight:700;color:var(--color-text-primary)}
.syc-grp-title:first-child{margin-top:4px}
.syc-grp-title small{font-size:12px;font-weight:400;color:var(--color-text-secondary)}
</style>
<div class="content-wrap">
    <section class="ut-banner" aria-label="목록 상단 배너">
        <div class="ut-banner-text">
            <nav class="ut-banner-path" aria-label="현재 위치"><a href="/index.php">홈</a> › <a href="/syc-products.php">상용콘크리트</a><?php if ($group): ?> › <a href="/syc-products.php?category=<?= (int) $group['id'] ?>"><?= e($group['name']) ?></a><?php endif; ?></nav>
            <h1 class="ut-banner-title"><?= e($pageTitle) ?></h1>
            <p class="ut-banner-sub">상용콘크리트(주) 제품 — 규격을 품목별 치수 항목으로 나눠 보여 줍니다.</p>
        </div>
    </section>

<?php if (!$ready): ?>
    <div class="empty-state"><p class="empty-title">상용콘크리트 제품 정보를 불러오지 못했습니다.</p><p class="empty-hint">상용콘크리트 표(카테고리·상품·규격 이름표)가 DB 에 있는지 확인해 주세요.</p></div>
<?php else: ?>
    <div class="pt-layout">
      <aside class="pt-filter">
        <?php if ($fgroups): ?>
        <details class="fltbox" open>
          <summary><span class="flt-sum-title">필터로 좁히기</span></summary>
          <form class="flt-body" method="get" action="/syc-products.php">
            <?php foreach ($keepGet as $k => $v): ?><input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>"><?php endforeach; ?>
            <div class="flt-top"><b>필터로 좁히기</b><a href="<?= e(syc_list_url($keepGet)) ?>">초기화</a></div>
            <div class="fgrp fgrp-axes">
              <?php foreach ($fgroups as $g): $gid = 'fax-' . $g['field']; ?>
              <div class="fax" role="group" aria-labelledby="<?= e($gid) ?>">
                <div class="fax-title" id="<?= e($gid) ?>"><?= e($g['title']) ?><?php if ($g['sub'] !== '' || $g['unit'] !== ''): ?> <small><?= e($g['sub']) ?><?php if ($g['unit'] !== ''): ?><span><?= e($g['unit']) ?></span><?php endif; ?></small><?php endif; ?></div>
                <div class="fchips">
                  <?php foreach ($g['opts'] as $o): ?>
                  <label class="fchip"<?= $g['fixed'] ? ' title="모든 상품이 같은 값"' : '' ?>><input type="checkbox" name="<?= e($g['field']) ?>[]" value="<?= e($o['val']) ?>"<?= $o['checked'] ? ' checked' : '' ?><?= $o['disabled'] ? ' disabled' : '' ?><?= $g['fixed'] ? ' data-fixed="1"' : '' ?>><span class="fchip-box"><?= e($o['label']) ?></span></label>
                  <?php endforeach; ?>
                </div>
              </div>
              <?php endforeach; ?>
            </div>
            <button type="submit" class="flt-apply">필터 적용</button>
          </form>
        </details>
        <?php endif; ?>
        <details class="syc-cat-box" open>
        <summary class="syc-cat-sum">상용콘크리트 카테고리</summary>
        <nav class="syc-cat-nav" aria-labelledby="syc-cat-title">
          <h2 id="syc-cat-title">상용콘크리트 카테고리</h2>
          <?php foreach ($tree as $gid => $g): $open = $categoryId === $gid || ($category && (int) $category['parent_id'] === $gid); ?>
          <details<?= $open ? ' open' : '' ?>>
            <summary><?= e($g['group']['name']) ?></summary>
            <ul>
              <li><a href="/syc-products.php?category=<?= (int) $gid ?>"<?= $categoryId === $gid ? ' class="is-on" aria-current="page"' : '' ?>>전체 보기</a></li>
              <?php foreach ($g['items'] as $it): $on = $categoryId === (int) $it['id']; ?>
              <li><a href="/syc-products.php?category=<?= (int) $it['id'] ?>"<?= $on ? ' class="is-on" aria-current="page"' : '' ?>><?= e($it['name']) ?></a></li>
              <?php endforeach; ?>
            </ul>
          </details>
          <?php endforeach; ?>
        </nav>
        </details>
        <script>
        // 모바일(760px 이하): 카테고리·필터를 접은 채 시작 → 상품 목록이 바로 보이게(기존 목록 필터와 같은 방식). 제목줄을 누르면 펼침
        (function () {
            if (!window.matchMedia || !window.matchMedia('(max-width: 760px)').matches) { return; }
            document.querySelectorAll('.syc-cat-box, .pt-filter .fltbox').forEach(function (d) { d.open = false; });
        })();
        </script>
      </aside>

      <div class="pt-list-main">
      <?php if (!$categoryId && $search === ''): ?>
        <div class="pt-table-header"><div class="pt-head-left"><h3 class="pt-table-title">상용콘크리트 카테고리</h3></div>
          <div class="pt-head-right">
            <form class="pt-search-form" method="get" action="/syc-products.php"><input type="text" name="s" value="" placeholder="상용콘크리트 검색" aria-label="상용콘크리트 검색"><button type="submit">검색</button></form>
          </div>
        </div>
        <div class="syc-index">
          <?php foreach ($tree as $gid => $g): ?>
          <section><h3><a href="/syc-products.php?category=<?= (int) $gid ?>"><?= e($g['group']['name']) ?></a></h3>
            <ul><?php foreach ($g['items'] as $it): ?><li><a href="/syc-products.php?category=<?= (int) $it['id'] ?>"><?= e($it['name']) ?></a></li><?php endforeach; ?></ul>
          </section>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="pt-table-header">
          <div class="pt-head-left">
            <h3 class="pt-table-title">품목보기 <span class="pt-search-count"><?php if ($search !== ''): ?>'<?= e($search) ?>' <?php endif; ?>검색결과 <strong><?= number_format(count($shown)) ?></strong>개</span></h3>
          </div>
          <div class="pt-head-right">
            <form method="get" action="/syc-products.php" class="pt-sort-form">
              <?php foreach ($_GET as $k => $v): if ($k === 'sort') continue;
                  if (is_array($v)) { foreach ($v as $vv): ?><input type="hidden" name="<?= e((string) $k) ?>[]" value="<?= e((string) $vv) ?>"><?php endforeach; }
                  else { ?><input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $v) ?>"><?php }
              endforeach; ?>
              <select name="sort" class="pt-sort" onchange="this.form.submit()" aria-label="정렬">
                <option value="">기본순</option>
                <option value="price_asc"<?= $sort === 'price_asc' ? ' selected' : '' ?>>낮은가격순</option>
                <option value="price_desc"<?= $sort === 'price_desc' ? ' selected' : '' ?>>높은가격순</option>
                <option value="newest"<?= $sort === 'newest' ? ' selected' : '' ?>>최신순</option>
              </select>
            </form>
            <form class="pt-search-form" method="get" action="/syc-products.php">
              <?php if ($categoryId): ?><input type="hidden" name="category" value="<?= $categoryId ?>"><?php endif; ?>
              <input type="text" name="s" value="<?= e($search) ?>" placeholder="목록검색" aria-label="목록검색"><button type="submit">검색</button>
            </form>
          </div>
        </div>
        <div class="pt-active-flt">
          <?php if ($chips): ?>
          <div class="pt-chips" role="list" aria-label="적용된 필터" tabindex="0">
            <?php foreach ($chips as $c): ?><span class="pt-chip" role="listitem"><span class="pt-chip-txt"><?= e($c[0]) ?>: <?= e((string) $c[1]) ?></span><a class="pt-chip-x" href="<?= e($c[2]) ?>" title="이 필터 해제" aria-label="<?= e($c[0]) ?>: <?= e((string) $c[1]) ?> 필터 해제">×</a></span><?php endforeach; ?>
          </div>
          <?php else: ?><span class="pt-flt-none">적용된 필터: 없음</span><?php endif; ?>
        </div>

        <?php if (!$shown): ?>
        <div class="empty-state"><p class="empty-title">조건에 맞는 상품이 없습니다.</p>
          <?php if (!syc_preview_include_inactive()): ?><p class="empty-hint">일괄 등록한 상품은 비활성으로 저장됩니다. 위 띠에서 '비활성도 보기'를 누르면 함께 보입니다.</p><?php endif; ?>
        </div>
        <?php else: ?>
        <div class="syc-list">
        <?php foreach ($tables as $t): $axisMode = $t['span'] > 0; $axRs = $axisMode ? ' rowspan="2"' : ''; ?>
        <?php if ($t['title'] !== ''): ?><h4 class="syc-grp-title"><?= e($t['title']) ?> <small><?= number_format(count($t['rows'])) ?>개</small></h4><?php endif; ?>
        <div class="product-table-wrap">
          <table class="product-table">
            <thead>
              <tr>
                <th class="pt-col-photo"<?= $axRs ?>>사진</th>
                <th class="pt-col-name"<?= $axRs ?>>품명</th>
                <th class="pt-col-vendor"<?= $axRs ?>>업체</th>
                <?php if ($t['hasUnit']): ?><th class="pt-col-unit"<?= $axRs ?>>단위</th><?php endif; ?>
                <?php if ($axisMode): ?><th class="pt-col-specgrp" colspan="<?= $t['span'] ?>">규격</th><?php else: ?><th class="pt-col-spec">규격</th><?php endif; ?>
                <?php if ($isInternal): ?><th class="pt-col-cost"<?= $axRs ?>>매입가<span>내부용</span></th><?php endif; ?>
                <th class="pt-col-price"<?= $axRs ?>>판매가<span>부가세별도</span></th>
                <?php if ($t['hasWeight']): ?><th class="pt-col-weight"<?= $axRs ?>>중량<span>kg</span></th><?php endif; ?>
              </tr>
              <?php if ($axisMode): ?>
              <tr class="pt-head-ax">
                <?php if ($t['hasCode']): ?><th class="pt-col-ax">모델 코드</th><?php endif; ?>
                <?php foreach ($t['axes'] as $a): ?><th class="pt-col-ax"><?= e(spec_axis_title($a)) ?></th><?php endforeach; ?>
                <?php if ($t['hasOpt']): ?><th class="pt-col-ax pt-col-axopt">옵션</th><?php endif; ?>
              </tr>
              <?php endif; ?>
            </thead>
            <tbody>
              <?php foreach ($t['rows'] as $p): $pp = syc_price_row($p, $all); $href = '/syc-product.php?id=' . (int) $p['id']; ?>
              <tr class="pt-prod-row" onclick="location.href='<?= e($href) ?>'" style="cursor:pointer">
                <td class="pt-col-photo">
                  <?php $img = $p['image_thumb_file'] ?: $p['image_file']; if ($img): ?><img src="<?= e($img) ?>" alt="<?= e($p['name']) ?>"><?php else: ?><span class="pt-placeholder"><?= e(mb_substr($p['name'], 0, 2)) ?></span><?php endif; ?>
                </td>
                <td class="pt-col-name"><a class="pt-name-text" href="<?= e($href) ?>" onclick="event.stopPropagation()" title="<?= e($p['name']) ?>"><?= e($p['name']) ?></a><?php if ((int) $p['is_active'] !== 1): ?><span class="syc-off">비활성</span><?php endif; ?></td>
                <td class="pt-col-vendor"><?= $p['brand'] ? e($p['brand']) : '<span class="pt-vendor-none">-</span>' ?></td>
                <?php if ($t['hasUnit']): ?><td class="pt-col-unit"><?= e(unit_display_label((string) ($p['unit'] ?? ''))) ?></td><?php endif; ?>
                <?php if ($axisMode): ?>
                  <?php if ($t['hasCode']): $hit = in_array((string) $p['spec_code'], $f['ax_code'], true); ?><td class="pt-col-ax pt-col-axcode<?= $hit ? ' is-hit' : '' ?>" data-label="모델 코드"><?= e((string) ($p['spec_code'] ?? '')) ?></td><?php endif; ?>
                  <?php foreach ($t['axes'] as $a): $ak = $a['axis_key']; $av = syc_axis_row_value($p, $ak); ?><td class="pt-col-ax<?= ($av !== null && in_array($av, $f['ax'][$ak] ?? [], true)) ? ' is-hit' : '' ?>" data-label="<?= e(spec_axis_title($a)) ?>"><?= $av !== null ? e(spec_axis_value_display($a, $av)) : '' ?></td><?php endforeach; ?>
                  <?php if ($t['hasOpt']): ?><td class="pt-col-ax pt-col-axopt" data-label="옵션"><?= e(spec_option_display((string) ($p['spec_option'] ?? ''))) ?></td><?php endif; ?>
                <?php else: ?><td class="pt-col-spec"><?= e((string) ($p['spec'] ?? '')) ?></td><?php endif; ?>
                <?php if ($isInternal): ?><td class="pt-col-cost"><?= price_cost_cell($pp) ?></td><?php endif; ?>
                <td class="pt-col-price"><?= price_tag_list($pp) ?></td>
                <?php if ($t['hasWeight']): ?><td class="pt-col-weight syc-weight"><?= $p['weight_kg'] !== null ? e(rtrim(rtrim(number_format((float) $p['weight_kg'], 2, '.', ','), '0'), '.')) . 'kg' : '' ?></td><?php endif; ?>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
      <?php endif; ?>
      </div>
    </div>
<?php endif; ?>
</div>
<?php render_footer(); ?>
