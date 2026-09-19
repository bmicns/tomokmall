<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/staff_layout.php';

require_staff();

// ── 파라미터 ────────────────────────────────────────────────────
$catId  = (int)($_GET['cat'] ?? 0);
$q      = trim($_GET['q'] ?? '');
$filter = in_array($_GET['filter'] ?? '', ['low', 'all'], true) ? $_GET['filter'] : 'all';

// ── 카테고리 목록 ────────────────────────────────────────────────
$cats = db()->query(
    'SELECT id, name, parent_id FROM categories WHERE is_active=1 ORDER BY sort_order, id'
)->fetchAll();

$rootCats = array_filter($cats, fn($c) => $c['parent_id'] === null);

// ── 제품 조회 ───────────────────────────────────────────────────
$where  = ['p.is_active = 1'];
$params = [];

if ($catId) {
    $where[]         = '(p.category_id = :cid OR c.parent_id = :cid2)';
    $params['cid']   = $catId;
    $params['cid2']  = $catId;
}
if ($q !== '') {
    $where[]     = '(p.name LIKE :q OR p.spec LIKE :q2 OR p.brand LIKE :q3)';
    $params['q'] = $params['q2'] = $params['q3'] = "%{$q}%";
}
if ($filter === 'low') {
    $where[] = 'p.stock_qty <= 5';
}

$whereStr = 'WHERE ' . implode(' AND ', $where);

$products = db()->prepare(
    "SELECT p.id, p.name, p.spec, p.brand, p.stock_qty, p.price_b2b, p.price_b2c,
            c.name AS cat_name, c.parent_id,
            pc.name AS parent_cat_name
     FROM products p
     JOIN categories c ON c.id = p.category_id
     LEFT JOIN categories pc ON pc.id = c.parent_id
     {$whereStr}
     ORDER BY c.sort_order, c.id, p.stock_qty ASC, p.id DESC
     LIMIT 500"
);
$products->execute($params);
$rows = $products->fetchAll();

// ── KPI ────────────────────────────────────────────────────────
$kpiSt = db()->query(
    'SELECT COUNT(*) AS total,
            SUM(CASE WHEN stock_qty <= 0 THEN 1 ELSE 0 END) AS zero,
            SUM(CASE WHEN stock_qty > 0 AND stock_qty <= 5 THEN 1 ELSE 0 END) AS low,
            SUM(CASE WHEN stock_qty > 5 THEN 1 ELSE 0 END) AS ok
     FROM products WHERE is_active=1'
);
$kpi = $kpiSt->fetch();

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

render_staff_header('재고현황', 'inventory');
?>
<style>
.stock-zero{color:#c62828;font-weight:700}
.stock-low{color:#e65100;font-weight:600}
.stock-ok{color:#2e7d32}
.cat-filter{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.cat-btn{padding:5px 12px;border:1px solid #e8eaed;border-radius:20px;font-size:12px;font-weight:600;background:#fff;color:#555;cursor:pointer;text-decoration:none;transition:all .15s}
.cat-btn:hover{border-color:#003580;color:#003580}
.cat-btn.active{background:#003580;color:#fff;border-color:#003580}
.stock-bar-bg{width:80px;height:5px;background:#f0f2f5;border-radius:3px;display:inline-block;vertical-align:middle;margin-left:6px}
.stock-bar-fill{height:100%;border-radius:3px;transition:width .3s}
</style>

<div class="page-heading">재고현황</div>

<!-- KPI 카드 -->
<div class="kpi-row" style="margin-bottom:20px">
    <div class="kpi-card"><div class="kpi-val"><?= (int)$kpi['total'] ?></div><div class="kpi-label">전체 품목</div></div>
    <div class="kpi-card"><div class="kpi-val urgent"><?= (int)$kpi['zero'] ?></div><div class="kpi-label">재고 없음</div></div>
    <div class="kpi-card"><div class="kpi-val" style="color:#e65100"><?= (int)$kpi['low'] ?></div><div class="kpi-label">재고 부족 (1~5)</div></div>
    <div class="kpi-card"><div class="kpi-val" style="color:#2e7d32"><?= (int)$kpi['ok'] ?></div><div class="kpi-label">재고 정상</div></div>
</div>

<!-- 카테고리 필터 -->
<div class="cat-filter">
    <a href="?filter=<?= $h($filter) ?>&q=<?= $h($q) ?>" class="cat-btn <?= !$catId ? 'active' : '' ?>">전체</a>
    <?php foreach ($rootCats as $cat): ?>
    <a href="?cat=<?= (int)$cat['id'] ?>&filter=<?= $h($filter) ?>&q=<?= $h($q) ?>"
       class="cat-btn <?= $catId === (int)$cat['id'] ? 'active' : '' ?>">
        <?= $h($cat['name']) ?>
    </a>
    <?php endforeach; ?>
</div>

<!-- 검색·필터 툴바 -->
<div class="toolbar">
    <form method="get" style="display:contents">
        <?php if ($catId): ?><input type="hidden" name="cat" value="<?= $catId ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= $h($q) ?>" placeholder="품명·규격·브랜드 검색">
        <select name="filter" onchange="this.form.submit()">
            <option value="all" <?= $filter==='all'?'selected':'' ?>>전체 재고</option>
            <option value="low" <?= $filter==='low'?'selected':'' ?>>부족/없음만</option>
        </select>
        <button type="submit" class="btn btn-secondary">검색</button>
        <?php if ($q || $filter !== 'all'): ?>
        <a href="?cat=<?= $catId ?>" style="font-size:12px;color:#888;text-decoration:none;align-self:center">초기화</a>
        <?php endif; ?>
    </form>
</div>

<!-- 재고 테이블 -->
<div class="card">
    <div class="card-header">
        <span class="card-title">품목 목록</span>
        <span style="font-size:12px;color:#888"><?= count($rows) ?>건</span>
    </div>
    <div style="overflow-x:auto">
    <table class="stf-table">
        <thead>
            <tr>
                <th>대분류</th><th>소분류</th><th>품명</th><th>규격</th>
                <th>브랜드</th><th style="text-align:right">B2B 단가</th>
                <th style="text-align:center">재고</th><th>상태</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($rows): ?>
        <?php
        $prevRoot = null;
        foreach ($rows as $row):
            $root = $row['parent_cat_name'] ?: $row['cat_name'];
            $sub  = $row['parent_cat_name'] ? $row['cat_name'] : '';
            $qty  = (int)$row['stock_qty'];
            $statusCls = $qty <= 0 ? 'stock-zero' : ($qty <= 5 ? 'stock-low' : 'stock-ok');
            $statusLbl = $qty <= 0 ? '없음' : ($qty <= 5 ? '부족' : '정상');
            $badgeCls  = $qty <= 0 ? 'badge-low' : ($qty <= 5 ? 'badge badge-working' : 'badge badge-ok');
            // 최대 500개 기준으로 바 너비 계산 (최대 99%)
            $barW = min(99, (int)round($qty / 500 * 99));
            $barColor = $qty <= 0 ? '#ef5350' : ($qty <= 5 ? '#ff9800' : '#4caf50');
        ?>
            <tr>
                <td style="font-size:11px;color:#888"><?= $h($root) ?></td>
                <td style="font-size:11px;color:#888"><?= $h($sub) ?></td>
                <td style="font-weight:600;max-width:150px"><?= $h($row['name']) ?></td>
                <td style="font-size:11px;color:#666;max-width:120px"><?= $h($row['spec']) ?></td>
                <td style="font-size:12px;color:#888"><?= $h($row['brand'] ?? '') ?></td>
                <td style="text-align:right;font-size:12px"><?= number_format((int)$row['price_b2b']) ?>원</td>
                <td style="text-align:center">
                    <span class="<?= $statusCls ?>"><?= number_format($qty) ?></span>
                    <span class="stock-bar-bg">
                        <span class="stock-bar-fill" style="width:<?= $barW ?>%;background:<?= $barColor ?>"></span>
                    </span>
                </td>
                <td>
                    <span class="badge <?= $qty <= 0 ? 'badge-low' : ($qty <= 5 ? 'badge-working' : 'badge-sent') ?>">
                        <?= $statusLbl ?>
                    </span>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:#aaa">검색 결과가 없습니다.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    </div>
</div>

<?php render_staff_footer(); ?>
