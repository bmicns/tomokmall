<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';

require_admin();

// ── 파라미터 ─────────────────────────────────────────────────────
$status = in_array($_GET['status'] ?? '', ['','PENDING','PAID','PREPARING','SHIPPED','DELIVERED','CANCELED'], true)
        ? $_GET['status'] : '';
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

$statusLabels = [
    'PENDING'   => '결제대기',
    'PAID'      => '결제완료',
    'PREPARING' => '준비중',
    'SHIPPED'   => '배송중',
    'DELIVERED' => '배송완료',
    'CANCELED'  => '취소',
];
$statusBadges = [
    'PENDING'   => 'badge-pending',
    'PAID'      => 'badge-done',
    'PREPARING' => 'badge-working',
    'SHIPPED'   => 'badge-sent',
    'DELIVERED' => 'badge-approved',
    'CANCELED'  => 'badge-off',
];

// ── 탭 카운트 ──────────────────────────────────────────────────
$counts = ['all' => 0];
foreach (array_keys($statusLabels) as $s) {
    $st = db()->prepare('SELECT COUNT(*) FROM orders WHERE status=:s');
    $st->execute(['s' => $s]);
    $counts[$s]  = (int)$st->fetchColumn();
    $counts['all'] += $counts[$s];
}

// ── 목록 쿼리 ─────────────────────────────────────────────────
$where  = [];
$params = [];
if ($status !== '') {
    $where[]        = 'o.status=:status';
    $params['status'] = $status;
}
if ($q !== '') {
    $where[]     = '(o.customer_name LIKE :q OR o.phone LIKE :q2 OR o.email LIKE :q3)';
    $params['q'] = $params['q2'] = $params['q3'] = "%{$q}%";
}
$whereStr = $where ? 'WHERE '.implode(' AND ', $where) : '';

$cntSt = db()->prepare("SELECT COUNT(*) FROM orders o {$whereStr}");
$cntSt->execute($params);
$total = (int)$cntSt->fetchColumn();

$listSt = db()->prepare(
    "SELECT o.*,
            (SELECT COUNT(*) FROM order_items WHERE order_id=o.id) AS item_count,
            p.status AS pay_status
     FROM orders o
     LEFT JOIN payments p ON p.order_id=o.id
     {$whereStr}
     ORDER BY o.id DESC
     LIMIT {$limit} OFFSET {$offset}"
);
$listSt->execute($params);
$orders = $listSt->fetchAll();
$pages  = max(1, (int)ceil($total / $limit));

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

render_admin_header('주문관리', 'orders');
?>
<style>
.adm-table .badge-working{background:#fff3e0;color:#e65100}
.adm-table .badge-sent{background:#e3f2fd;color:#1565c0}
.adm-table .badge-approved{background:#e8f5e9;color:#2e7d32}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <div class="page-heading" style="margin:0">주문관리</div>
    <span style="font-size:13px;color:#888">총 <?= number_format($total) ?>건</span>
</div>

<!-- 탭 -->
<div style="display:flex;gap:0;border-bottom:2px solid #e8eaed;margin-bottom:18px;overflow-x:auto">
    <a href="?q=<?= $h($q) ?>"
       style="padding:10px 14px;font-size:13px;font-weight:600;color:<?= $status===''?'#004EA1':'#888' ?>;text-decoration:none;border-bottom:2px solid <?= $status===''?'#004EA1':'transparent' ?>;margin-bottom:-2px;white-space:nowrap">
        전체 <span style="font-size:11px;color:<?= $status===''?'#004EA1':'#aaa' ?>"><?= number_format($counts['all']) ?></span>
    </a>
    <?php foreach ($statusLabels as $key => $label): ?>
    <a href="?status=<?= $key ?>&q=<?= $h($q) ?>"
       style="padding:10px 14px;font-size:13px;font-weight:600;color:<?= $status===$key?'#004EA1':'#888' ?>;text-decoration:none;border-bottom:2px solid <?= $status===$key?'#004EA1':'transparent' ?>;margin-bottom:-2px;white-space:nowrap">
        <?= $label ?> <span style="font-size:11px;color:<?= $status===$key?'#004EA1':'#aaa' ?>"><?= number_format($counts[$key]) ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- 검색 -->
<div class="toolbar">
    <form method="get" style="display:contents">
        <?php if ($status): ?><input type="hidden" name="status" value="<?= $h($status) ?>"><?php endif; ?>
        <input type="text" name="q" value="<?= $h($q) ?>" placeholder="고객명·연락처·이메일 검색">
        <button type="submit" class="btn btn-primary">검색</button>
        <?php if ($q): ?>
        <a href="?status=<?= $h($status) ?>" style="font-size:12px;color:#888;text-decoration:none;align-self:center">초기화</a>
        <?php endif; ?>
    </form>
</div>

<!-- 테이블 -->
<div class="card">
    <table class="adm-table">
        <thead>
            <tr>
                <th>주문번호</th><th>고객명</th><th>연락처</th><th>품목수</th>
                <th style="text-align:right">주문금액</th><th>결제</th>
                <th>상태</th><th>주문일</th><th>관리</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($orders): ?>
        <?php foreach ($orders as $o): ?>
            <tr>
                <td style="font-family:monospace;font-size:12px;color:#888">#<?= str_pad($o['id'], 5, '0', STR_PAD_LEFT) ?></td>
                <td style="font-weight:600"><?= $h($o['customer_name']) ?></td>
                <td style="font-size:12px"><?= $h($o['phone']) ?></td>
                <td style="text-align:center">
                    <span style="background:#f0f2f5;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600"><?= (int)$o['item_count'] ?></span>
                </td>
                <td style="text-align:right;font-weight:600"><?= number_format((int)$o['total_amount']) ?>원</td>
                <td style="font-size:12px;color:#888"><?= $h($o['pay_status'] ?? '-') ?></td>
                <td>
                    <span class="badge <?= $statusBadges[$o['status']] ?? '' ?>">
                        <?= $statusLabels[$o['status']] ?? $o['status'] ?>
                    </span>
                </td>
                <td style="font-size:11px;color:#aaa;white-space:nowrap"><?= substr($h($o['created_at']),0,10) ?></td>
                <td>
                    <button class="btn-sm btn-sm-edit" onclick="openStatusModal(<?= (int)$o['id'] ?>, '<?= $h($o['status']) ?>')">
                        상태변경
                    </button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="9" style="text-align:center;padding:32px;color:#aaa">주문이 없습니다.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 페이지네이션 -->
<?php if ($pages > 1): ?>
<div class="pagination">
    <?php for ($i=1; $i<=$pages; $i++): ?>
    <a href="?status=<?= $h($status) ?>&q=<?= $h($q) ?>&page=<?= $i ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<!-- 상태 변경 모달 -->
<div class="modal-overlay" id="status-modal">
    <div class="modal-box" style="width:340px">
        <h3>주문 상태 변경</h3>
        <button class="modal-close" onclick="document.getElementById('status-modal').classList.remove('open')">×</button>
        <input type="hidden" id="sm-order-id">
        <div class="form-field">
            <label>변경할 상태</label>
            <select id="sm-status">
                <option value="PENDING">결제대기</option>
                <option value="PAID">결제완료</option>
                <option value="PREPARING">준비중</option>
                <option value="SHIPPED">배송중</option>
                <option value="DELIVERED">배송완료</option>
                <option value="CANCELED">취소</option>
            </select>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="document.getElementById('status-modal').classList.remove('open')">취소</button>
            <button class="btn btn-primary" onclick="updateOrderStatus()">변경</button>
        </div>
    </div>
</div>

<script>
function openStatusModal(orderId, currentStatus){
    document.getElementById('sm-order-id').value = orderId;
    document.getElementById('sm-status').value   = currentStatus;
    document.getElementById('status-modal').classList.add('open');
}
async function updateOrderStatus(){
    const id     = parseInt(document.getElementById('sm-order-id').value);
    const status = document.getElementById('sm-status').value;
    const r = await fetch('/api/admin/orders.php', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id, status }),
    });
    const d = await r.json();
    if(d.ok){ location.reload(); }
    else alert('오류: ' + d.error);
}
</script>

<?php render_admin_footer(); ?>
