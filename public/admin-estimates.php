<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';

require_admin();

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;
$status = $_GET['status'] ?? '';
$q      = trim($_GET['q'] ?? '');

$statusOpts = ['REQUESTED','WORKING','SENT','APPROVED','CANCELED'];

$where  = [];
$params = [];
if ($status !== '' && in_array($status, $statusOpts, true)) {
    $where[]          = 'e.status = :status';
    $params['status'] = $status;
}
if ($q !== '') {
    $where[]      = '(e.customer_name LIKE :q OR e.company_name LIKE :q2 OR e.phone LIKE :q3)';
    $params['q']  = $params['q2'] = $params['q3'] = "%{$q}%";
}
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cntSt = db()->prepare("SELECT COUNT(*) FROM estimates e {$whereStr}");
$cntSt->execute($params);
$total = (int)$cntSt->fetchColumn();

$st = db()->prepare(
    "SELECT e.*, u.name AS staff_name
     FROM estimates e
     LEFT JOIN users u ON u.id = e.staff_id
     {$whereStr}
     ORDER BY e.id DESC LIMIT {$limit} OFFSET {$offset}"
);
$st->execute($params);
$estimates = $st->fetchAll();

$pages = max(1, (int)ceil($total / $limit));
$h = fn($v) => htmlspecialchars($v ?? '', ENT_QUOTES);

render_admin_header('견적 관리', 'estimates');
?>
<style>
.filter-bar{display:flex;gap:10px;align-items:center;margin-bottom:16px;flex-wrap:wrap}
.filter-bar select,.filter-bar input{height:36px;border:1px solid #e8eaed;border-radius:6px;padding:0 12px;font-size:13px}
.filter-bar input{min-width:220px}
.filter-bar button{height:36px;padding:0 16px;background:#f0f2f5;border:1px solid #e8eaed;border-radius:6px;font-size:13px;cursor:pointer;font-weight:600}
.filter-bar button:hover{background:#e4e6ea}
.count-info{font-size:13px;color:#888;margin-left:auto}
</style>

<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:20px">
    <div class="page-heading" style="margin:0">견적 관리</div>
    <span style="font-size:13px;color:#888">총 <?= $total ?>건</span>
</div>

<!-- 필터 -->
<form method="get" class="filter-bar">
    <select name="status" onchange="this.form.submit()">
        <option value=""<?= $status===''?' selected':'' ?>>전체 상태</option>
        <option value="REQUESTED"<?= $status==='REQUESTED'?' selected':'' ?>>접수됨</option>
        <option value="WORKING"<?= $status==='WORKING'?' selected':'' ?>>작성중</option>
        <option value="SENT"<?= $status==='SENT'?' selected':'' ?>>발송됨</option>
        <option value="APPROVED"<?= $status==='APPROVED'?' selected':'' ?>>완료</option>
        <option value="CANCELED"<?= $status==='CANCELED'?' selected':'' ?>>취소</option>
    </select>
    <input type="text" name="q" value="<?= $h($q) ?>" placeholder="담당자·회사·연락처 검색">
    <button type="submit">검색</button>
    <?php if ($status || $q): ?>
    <a href="/admin-estimates.php" style="font-size:13px;color:#888;text-decoration:none">초기화</a>
    <?php endif; ?>
</form>

<!-- 테이블 -->
<div class="card">
    <table class="adm-table">
        <?php
        $sLabels = ['REQUESTED'=>'접수됨','WORKING'=>'작성중','SENT'=>'발송됨','APPROVED'=>'완료','CANCELED'=>'취소'];
        $sBadges = ['REQUESTED'=>'badge-pending','WORKING'=>'badge-off','SENT'=>'badge-done','APPROVED'=>'badge-on','CANCELED'=>'badge-off'];
        ?>
        <thead>
            <tr>
                <th>#</th><th>고객명</th><th>회사</th><th>연락처</th><th>담당직원</th>
                <th>상태</th><th>접수일</th><th>관리</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($estimates): ?>
        <?php foreach ($estimates as $e): ?>
            <tr>
                <td style="color:#888;font-size:12px">#<?= (int)$e['id'] ?></td>
                <td style="font-weight:600"><?= $h($e['customer_name']) ?></td>
                <td><?= $h($e['company_name']) ?></td>
                <td><?= $h($e['phone']) ?></td>
                <td style="font-size:12px;color:#888"><?= $h($e['staff_name'] ?? '미배정') ?></td>
                <td>
                    <span class="badge <?= $sBadges[$e['status']] ?? 'badge-pending' ?>">
                        <?= $sLabels[$e['status']] ?? $h($e['status']) ?>
                    </span>
                </td>
                <td style="font-size:12px;color:#888;white-space:nowrap"><?= substr($h($e['created_at']),0,10) ?></td>
                <td>
                    <a href="/staff/estimate-detail.php?id=<?= (int)$e['id'] ?>" class="btn-sm btn-sm-edit">보기</a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:#aaa">견적 요청이 없습니다.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 페이지네이션 -->
<?php if ($pages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a href="?page=<?= $i ?>&status=<?= $h($status) ?>&q=<?= $h($q) ?>"
       class="page-btn<?= $i === $page ? ' active' : '' ?>"
       style="text-decoration:none;display:inline-flex;align-items:center;justify-content:center"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php render_admin_footer(); ?>
