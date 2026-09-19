<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';

require_admin();

$db = db();

// ── KPI 수집 ──────────────────────────────────────────────────
$stats = [
    'products'   => (int)($db->query('SELECT COUNT(*) FROM products WHERE is_active=1')->fetchColumn()  ?? 0),
    'users'      => (int)($db->query('SELECT COUNT(*) FROM users')->fetchColumn()                       ?? 0),
    'orders'     => (int)($db->query("SELECT COUNT(*) FROM orders WHERE status NOT IN ('CANCELED')")->fetchColumn() ?? 0),
    'est_new'    => (int)($db->query("SELECT COUNT(*) FROM estimates WHERE status='REQUESTED'")->fetchColumn() ?? 0),
];

// 월 매출
$revSt = $db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status NOT IN ('CANCELED','PENDING') AND created_at >= DATE_FORMAT(NOW(),'%Y-%m-01')");
$monthRevenue = (int)$revSt->fetchColumn();

// 최근 견적 10건
$estimates = $db->query(
    "SELECT e.id, e.customer_name, e.company_name, e.phone, e.status, e.created_at
     FROM estimates e ORDER BY e.id DESC LIMIT 10"
)->fetchAll();

// 최근 주문 5건
$orders = $db->query(
    'SELECT id, customer_name, total_amount, status, created_at
     FROM orders ORDER BY id DESC LIMIT 5'
)->fetchAll();

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

render_admin_header('대시보드', 'dashboard');
?>
<style>
.kpi-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:14px;margin-bottom:24px}
.kpi-card{background:#fff;border-radius:10px;padding:18px 20px;display:flex;align-items:center;gap:14px;border:1px solid #e8eaed}
.kpi-icon{width:44px;height:44px;border-radius:10px;display:flex;align-items:center;justify-content:center;flex-shrink:0}
.kpi-icon.blue{background:#e8f0fe;color:#004EA1}
.kpi-icon.green{background:#e8f5e9;color:#2e7d32}
.kpi-icon.orange{background:#fff3e0;color:#e65100}
.kpi-icon.purple{background:#f3e8ff;color:#6d28d9}
.kpi-icon.red{background:#fce4ec;color:#c62828}
.kpi-val{font-size:26px;font-weight:800;color:#1a2035;line-height:1}
.kpi-label{font-size:11px;color:#888;margin-top:3px}
.quick-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(160px,1fr));gap:10px;margin-bottom:22px}
.quick-card{display:flex;align-items:center;gap:8px;padding:12px 16px;border-radius:8px;font-size:12px;font-weight:600;text-decoration:none;border:1px solid #e8eaed;background:#fff;color:#1a2035;transition:all .15s}
.quick-card:hover{border-color:#004EA1;color:#004EA1;background:#f0f5ff}
.dash-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}
</style>

<div class="page-heading">대시보드</div>

<!-- KPI -->
<div class="kpi-grid">
    <div class="kpi-card">
        <div class="kpi-icon blue">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/></svg>
        </div>
        <div><div class="kpi-val"><?= number_format($stats['products']) ?></div><div class="kpi-label">활성 제품</div></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon purple">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        </div>
        <div><div class="kpi-val"><?= number_format($stats['users']) ?></div><div class="kpi-label">전체 회원</div></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon green">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/></svg>
        </div>
        <div><div class="kpi-val"><?= number_format($stats['orders']) ?></div><div class="kpi-label">전체 주문</div></div>
    </div>
    <div class="kpi-card">
        <div class="kpi-icon orange">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
        </div>
        <div><div class="kpi-val"><?= number_format($stats['est_new']) ?></div><div class="kpi-label">신규 견적 (처리 대기)</div></div>
    </div>
    <div class="kpi-card" style="grid-column:span 1">
        <div class="kpi-icon green">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>
        </div>
        <div><div class="kpi-val" style="font-size:18px"><?= number_format($monthRevenue) ?>원</div><div class="kpi-label">이번 달 매출</div></div>
    </div>
</div>

<!-- 바로가기 -->
<div class="quick-grid">
    <a href="/admin-users.php" class="quick-card">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
        회원관리
    </a>
    <a href="/admin-orders.php" class="quick-card">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="1" y="3" width="15" height="13" rx="1"/><path d="M16 8h4l3 5v3h-7V8z"/></svg>
        주문관리
    </a>
    <a href="/admin-estimates.php" class="quick-card">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14,2 14,8 20,8"/></svg>
        견적관리
    </a>
    <a href="/admin-products.php" class="quick-card">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
        제품 등록
    </a>
    <a href="/admin-stats.php" class="quick-card">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="18" y1="20" x2="18" y2="10"/><line x1="12" y1="20" x2="12" y2="4"/><line x1="6" y1="20" x2="6" y2="14"/></svg>
        통계
    </a>
    <a href="/staff/index.php" class="quick-card">
        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/></svg>
        직원 패널
    </a>
</div>

<!-- 하단 그리드 -->
<div class="dash-grid">
<!-- 최근 견적 -->
<div class="card">
    <div class="card-header">
        <span class="card-title">신규 견적 요청</span>
        <a href="/admin-estimates.php" style="font-size:12px;color:#004EA1;text-decoration:none;font-weight:600">전체 보기 →</a>
    </div>
    <table class="adm-table">
        <thead><tr><th>#</th><th>고객</th><th>회사</th><th>상태</th><th>접수일</th></tr></thead>
        <tbody>
        <?php if ($estimates): foreach ($estimates as $e): ?>
            <tr>
                <td style="color:#888;font-size:12px">#<?= (int)$e['id'] ?></td>
                <td style="font-weight:600"><?= $h($e['customer_name']) ?></td>
                <td style="font-size:12px;color:#888"><?= $h($e['company_name'] ?? '-') ?></td>
                <td>
                    <?php $sc = match($e['status']) {
                        'REQUESTED'=>'badge-requested','WORKING'=>'badge-working','SENT'=>'badge-done',
                        'APPROVED'=>'badge-approved', default=>'badge-pending'
                    }; $sl = ['REQUESTED'=>'접수','WORKING'=>'작성중','SENT'=>'발송','APPROVED'=>'완료','CANCELED'=>'취소']; ?>
                    <span class="badge <?= $sc ?>"><?= $sl[$e['status']] ?? $e['status'] ?></span>
                </td>
                <td style="font-size:11px;color:#aaa"><?= substr($h($e['created_at']),0,10) ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="5" style="text-align:center;padding:20px;color:#aaa">견적 없음</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 최근 주문 -->
<div class="card">
    <div class="card-header">
        <span class="card-title">최근 주문</span>
        <a href="/admin-orders.php" style="font-size:12px;color:#004EA1;text-decoration:none;font-weight:600">전체 보기 →</a>
    </div>
    <table class="adm-table">
        <thead><tr><th>#</th><th>고객</th><th style="text-align:right">금액</th><th>상태</th><th>날짜</th></tr></thead>
        <tbody>
        <?php if ($orders): foreach ($orders as $o): ?>
            <tr>
                <td style="font-family:monospace;font-size:11px;color:#aaa">#<?= str_pad($o['id'],5,'0',STR_PAD_LEFT) ?></td>
                <td style="font-weight:600"><?= $h($o['customer_name']) ?></td>
                <td style="text-align:right;font-size:12px;font-weight:600"><?= number_format((int)$o['total_amount']) ?>원</td>
                <td>
                    <?php $oc = match($o['status']) {
                        'PENDING'=>'badge-pending','PAID'=>'badge-done','PREPARING'=>'badge-pending',
                        'SHIPPED'=>'badge-sent','DELIVERED'=>'badge-approved','CANCELED'=>'badge-off', default=>''
                    }; $ol = ['PENDING'=>'대기','PAID'=>'결제','PREPARING'=>'준비','SHIPPED'=>'배송','DELIVERED'=>'완료','CANCELED'=>'취소']; ?>
                    <span class="badge <?= $oc ?>"><?= $ol[$o['status']] ?? $o['status'] ?></span>
                </td>
                <td style="font-size:11px;color:#aaa"><?= substr($h($o['created_at']),0,10) ?></td>
            </tr>
        <?php endforeach; else: ?>
            <tr><td colspan="5" style="text-align:center;padding:20px;color:#aaa">주문 없음</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</div>

<?php render_admin_footer(); ?>
