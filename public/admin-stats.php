<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';

require_admin();

// ── 기간 파라미터 ─────────────────────────────────────────────────
$period = in_array($_GET['period'] ?? '', ['7d','30d','90d','1y'], true) ? $_GET['period'] : '30d';
$dateMap = ['7d'=>7, '30d'=>30, '90d'=>90, '1y'=>365];
$days    = $dateMap[$period];
$dateFrom= date('Y-m-d', strtotime("-{$days} days"));

// ── 종합 KPI ──────────────────────────────────────────────────────
$kpiOrders = db()->prepare(
    'SELECT COUNT(*) AS cnt, COALESCE(SUM(total_amount),0) AS revenue
     FROM orders WHERE status NOT IN ("CANCELED","PENDING") AND created_at >= :d'
);
$kpiOrders->execute(['d' => $dateFrom]);
$kpiO = $kpiOrders->fetch();

$kpiUsers = db()->prepare('SELECT COUNT(*) AS cnt FROM users WHERE created_at >= :d');
$kpiUsers->execute(['d' => $dateFrom]);
$kpiU = $kpiUsers->fetch();

$kpiEst = db()->prepare('SELECT COUNT(*) AS cnt FROM estimates WHERE created_at >= :d');
$kpiEst->execute(['d' => $dateFrom]);
$kpiE = $kpiEst->fetch();

// ── 월별 주문 추이 (최근 12개월) ──────────────────────────────────
$monthlySt = db()->query(
    "SELECT DATE_FORMAT(created_at,'%Y-%m') AS ym,
            COUNT(*) AS cnt,
            COALESCE(SUM(total_amount),0) AS revenue
     FROM orders
     WHERE status NOT IN ('CANCELED','PENDING')
       AND created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
     GROUP BY ym
     ORDER BY ym"
);
$monthly = $monthlySt->fetchAll();

// ── 상태별 주문 분포 ──────────────────────────────────────────────
$statusSt = db()->query(
    "SELECT status, COUNT(*) AS cnt
     FROM orders
     WHERE created_at >= :d
     GROUP BY status"
);
$statusSt->execute(['d' => $dateFrom]);
$statusDist = $statusSt->fetchAll(\PDO::FETCH_KEY_PAIR);

// ── 인기 제품 TOP 10 ──────────────────────────────────────────────
$topProds = db()->prepare(
    "SELECT p.name, p.spec, SUM(oi.quantity) AS total_qty, SUM(oi.quantity * oi.unit_price) AS total_sales
     FROM order_items oi
     JOIN products p ON p.id=oi.product_id
     JOIN orders o ON o.id=oi.order_id
     WHERE o.status NOT IN ('CANCELED','PENDING') AND o.created_at >= :d
     GROUP BY oi.product_id, p.name, p.spec
     ORDER BY total_qty DESC
     LIMIT 10"
);
$topProds->execute(['d' => $dateFrom]);
$topProducts = $topProds->fetchAll();

// ── 견적 상태별 분포 ─────────────────────────────────────────────
$estSt = db()->query(
    "SELECT status, COUNT(*) AS cnt FROM estimates
     WHERE created_at >= :d GROUP BY status"
);
$estSt->execute(['d' => $dateFrom]);
$estDist = $estSt->fetchAll();

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

render_admin_header('통계', 'stats');
?>
<style>
.period-btn{display:inline-block;padding:5px 12px;border:1px solid #e8eaed;border-radius:5px;font-size:12px;font-weight:600;text-decoration:none;color:#555;background:#fff;margin-right:4px}
.period-btn.active{background:#004EA1;color:#fff;border-color:#004EA1}
.stats-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;margin-bottom:20px}
.chart-bar-row{display:flex;align-items:center;gap:8px;margin-bottom:6px;font-size:12px}
.chart-bar-label{width:100px;flex-shrink:0;color:#555;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chart-bar-bg{flex:1;height:18px;background:#f0f2f5;border-radius:3px;overflow:hidden}
.chart-bar-fill{height:100%;background:#004EA1;border-radius:3px;transition:width .5s}
.chart-bar-val{width:80px;text-align:right;color:#1a2035;font-weight:600}
.monthly-chart{display:flex;align-items:flex-end;gap:3px;height:120px;padding-bottom:4px}
.month-bar-wrap{flex:1;display:flex;flex-direction:column;align-items:center;gap:2px}
.month-bar{width:100%;background:#004EA1;border-radius:3px 3px 0 0;min-height:2px;transition:height .5s}
.month-label{font-size:9px;color:#aaa;white-space:nowrap}
</style>

<!-- 기간 선택 -->
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:10px">
    <div class="page-heading" style="margin:0">통계</div>
    <div>
        <?php foreach (['7d'=>'7일','30d'=>'30일','90d'=>'90일','1y'=>'1년'] as $k=>$l): ?>
        <a href="?period=<?= $k ?>" class="period-btn <?= $period===$k?'active':'' ?>"><?= $l ?></a>
        <?php endforeach; ?>
    </div>
</div>

<!-- KPI -->
<div class="kpi-grid" style="margin-bottom:24px">
    <div class="kpi-card">
        <div class="kpi-icon blue">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/></svg>
        </div>
        <div><div class="kpi-val"><?= number_format((int)$kpiO['cnt']) ?></div><div class="kpi-label">완료 주문</div></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon green">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div><div class="kpi-val"><?= number_format((int)$kpiO['revenue']) ?>원</div><div class="kpi-label">매출</div></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon purple">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        </div>
        <div><div class="kpi-val"><?= number_format((int)$kpiU['cnt']) ?></div><div class="kpi-label">신규 회원</div></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon orange">
            <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
        </div>
        <div><div class="kpi-val"><?= number_format((int)$kpiE['cnt']) ?></div><div class="kpi-label">견적 요청</div></div>
    </div>
</div>

<div class="stats-grid">
    <!-- 월별 추이 -->
    <div class="card" style="overflow:hidden">
        <div class="card-header"><span class="card-title">월별 매출 추이 (최근 12개월)</span></div>
        <div style="padding:16px">
            <?php if ($monthly): ?>
            <?php $maxRev = max(array_column($monthly, 'revenue') ?: [1]); ?>
            <div class="monthly-chart">
                <?php foreach ($monthly as $m): ?>
                <?php $h_pct = $maxRev > 0 ? round((int)$m['revenue'] / $maxRev * 100) : 0; ?>
                <div class="month-bar-wrap" title="<?= $m['ym'] ?>: <?= number_format((int)$m['revenue']) ?>원">
                    <div class="month-bar" style="height:<?= $h_pct ?>%"></div>
                    <div class="month-label"><?= substr($m['ym'], 5) ?>월</div>
                </div>
                <?php endforeach; ?>
            </div>
            <div style="margin-top:12px">
                <?php foreach ($monthly as $m): ?>
                <div class="chart-bar-row" style="margin-bottom:4px">
                    <div class="chart-bar-label"><?= $h($m['ym']) ?></div>
                    <div style="flex:1;font-size:12px;color:#555"><?= number_format((int)$m['cnt']) ?>건</div>
                    <div style="font-size:12px;font-weight:600;color:#004EA1"><?= number_format((int)$m['revenue']) ?>원</div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div style="text-align:center;padding:24px;color:#aaa;font-size:13px">주문 데이터가 없습니다.</div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 인기 제품 -->
    <div class="card" style="overflow:hidden">
        <div class="card-header"><span class="card-title">인기 제품 TOP 10</span></div>
        <div style="padding:16px">
            <?php if ($topProducts): ?>
            <?php $maxQty = max(array_column($topProducts, 'total_qty') ?: [1]); ?>
            <?php foreach ($topProducts as $i => $p): ?>
            <div class="chart-bar-row">
                <div class="chart-bar-label" title="<?= $h($p['name']) ?>"><?= $h(mb_substr($p['name'],0,10)) ?></div>
                <div class="chart-bar-bg">
                    <div class="chart-bar-fill" style="width:<?= round((int)$p['total_qty']/$maxQty*100) ?>%"></div>
                </div>
                <div class="chart-bar-val"><?= number_format((int)$p['total_qty']) ?>개</div>
            </div>
            <?php endforeach; ?>
            <?php else: ?>
            <div style="text-align:center;padding:24px;color:#aaa;font-size:13px">데이터 없음</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 주문 상태 분포 + 견적 분포 -->
<div class="stats-grid">
    <div class="card">
        <div class="card-header"><span class="card-title">주문 상태 분포</span></div>
        <div style="padding:16px">
            <?php
            $stLabels = ['PENDING'=>'결제대기','PAID'=>'결제완료','PREPARING'=>'준비중','SHIPPED'=>'배송중','DELIVERED'=>'배송완료','CANCELED'=>'취소'];
            $stColors = ['PENDING'=>'#ff9800','PAID'=>'#4caf50','PREPARING'=>'#2196f3','SHIPPED'=>'#9c27b0','DELIVERED'=>'#009688','CANCELED'=>'#ef5350'];
            $totalOrds = array_sum(array_values($statusDist)) ?: 1;
            foreach ($stLabels as $k => $l):
                $cnt = (int)($statusDist[$k] ?? 0);
                $pct = round($cnt / $totalOrds * 100);
            ?>
            <div class="chart-bar-row">
                <div class="chart-bar-label"><?= $l ?></div>
                <div class="chart-bar-bg">
                    <div class="chart-bar-fill" style="width:<?= $pct ?>%;background:<?= $stColors[$k] ?>"></div>
                </div>
                <div class="chart-bar-val"><?= number_format($cnt) ?>건</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="card">
        <div class="card-header"><span class="card-title">견적 상태 분포</span></div>
        <div style="padding:16px">
            <?php
            $eLabels  = ['REQUESTED'=>'접수됨','WORKING'=>'작성중','SENT'=>'발송됨','APPROVED'=>'완료','CANCELED'=>'취소'];
            $eColors  = ['REQUESTED'=>'#2196f3','WORKING'=>'#ff9800','SENT'=>'#4caf50','APPROVED'=>'#9c27b0','CANCELED'=>'#ef5350'];
            $eMap     = [];
            foreach ($estDist as $ed) $eMap[$ed['status']] = (int)$ed['cnt'];
            $totalEst = array_sum(array_values($eMap)) ?: 1;
            foreach ($eLabels as $k => $l):
                $cnt = $eMap[$k] ?? 0;
                $pct = round($cnt / $totalEst * 100);
            ?>
            <div class="chart-bar-row">
                <div class="chart-bar-label"><?= $l ?></div>
                <div class="chart-bar-bg">
                    <div class="chart-bar-fill" style="width:<?= $pct ?>%;background:<?= $eColors[$k] ?>"></div>
                </div>
                <div class="chart-bar-val"><?= number_format($cnt) ?>건</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php render_admin_footer(); ?>
