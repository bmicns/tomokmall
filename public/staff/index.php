<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/staff_layout.php';

$user = require_staff();

// ── 필터 파라미터 ──────────────────────────────────────────────
$tab    = in_array($_GET['tab'] ?? '', ['REQUESTED','WORKING','SENT','APPROVED','CANCELED'], true)
        ? ($_GET['tab'])
        : 'REQUESTED';
$q      = trim($_GET['q'] ?? '');
$page   = max(1, (int)($_GET['page'] ?? 1));
$limit  = 25;
$offset = ($page - 1) * $limit;

// ── DB 상태값 정의 ─────────────────────────────────────────────
$tabLabels = [
    'REQUESTED' => '접수됨',
    'WORKING'   => '작성중',
    'SENT'      => '발송됨',
    'APPROVED'  => '완료',
    'CANCELED'  => '취소',
];

// ── 탭별 카운트 ────────────────────────────────────────────────
$counts = [];
foreach (array_keys($tabLabels) as $s) {
    $st = db()->prepare('SELECT COUNT(*) FROM estimates WHERE status = :s');
    $st->execute(['s' => $s]);
    $counts[$s] = (int)$st->fetchColumn();
}

// ── 목록 쿼리 ──────────────────────────────────────────────────
$where  = ['e.status = :status'];
$params = ['status' => $tab];
if ($q !== '') {
    $where[]     = '(e.customer_name LIKE :q OR e.company_name LIKE :q2 OR e.phone LIKE :q3)';
    $params['q'] = $params['q2'] = $params['q3'] = "%{$q}%";
}
$whereStr = 'WHERE ' . implode(' AND ', $where);

$cntSt = db()->prepare("SELECT COUNT(*) FROM estimates e {$whereStr}");
$cntSt->execute($params);
$total = (int)$cntSt->fetchColumn();

$listSt = db()->prepare(
    "SELECT e.*,
            u.name AS staff_name,
            (SELECT COUNT(*) FROM estimate_items WHERE estimate_id = e.id) AS item_count
     FROM estimates e
     LEFT JOIN users u ON u.id = e.staff_id
     {$whereStr}
     ORDER BY e.id DESC
     LIMIT {$limit} OFFSET {$offset}"
);
$listSt->execute($params);
$estimates = $listSt->fetchAll();
$pages = max(1, (int)ceil($total / $limit));

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);

render_staff_header('견적 목록', 'estimates');
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <div class="page-heading" style="margin:0">견적 목록</div>
    <span style="font-size:13px;color:#888">전체 <?= $total ?>건</span>
</div>

<!-- 탭 -->
<div class="tab-bar">
    <?php foreach ($tabLabels as $key => $label): ?>
    <a href="?tab=<?= $key ?>&q=<?= $h($q) ?>" class="tab-btn <?= $tab === $key ? 'active' : '' ?>" style="text-decoration:none">
        <?= $label ?>
        <span style="font-size:11px;color:<?= $tab === $key ? '#003580' : '#aaa' ?>;margin-left:4px"><?= $counts[$key] ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- 검색 -->
<div class="toolbar">
    <form method="get" style="display:contents">
        <input type="hidden" name="tab" value="<?= $h($tab) ?>">
        <input type="text" name="q" value="<?= $h($q) ?>" placeholder="고객명·회사명·연락처 검색">
        <button type="submit" class="btn btn-secondary">검색</button>
        <?php if ($q): ?>
        <a href="?tab=<?= $h($tab) ?>" style="font-size:12px;color:#888;text-decoration:none;align-self:center">초기화</a>
        <?php endif; ?>
    </form>
</div>

<!-- 테이블 -->
<div class="card">
    <table class="stf-table">
        <thead>
            <tr>
                <th>#</th>
                <th>고객명</th>
                <th>회사명</th>
                <th>연락처</th>
                <th>품목수</th>
                <th>납품지</th>
                <th>담당직원</th>
                <th>상태</th>
                <th>접수일</th>
                <th>작업</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($estimates): ?>
        <?php foreach ($estimates as $e): ?>
            <tr>
                <td style="color:#888;font-size:12px"><?= (int)$e['id'] ?></td>
                <td style="font-weight:600"><?= $h($e['customer_name']) ?></td>
                <td style="color:#666"><?= $h($e['company_name'] ?? '-') ?></td>
                <td><?= $h($e['phone']) ?></td>
                <td style="text-align:center">
                    <span style="background:#f0f2f5;padding:2px 8px;border-radius:4px;font-size:12px;font-weight:600">
                        <?= (int)$e['item_count'] ?>
                    </span>
                </td>
                <td style="font-size:12px;color:#666;max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    <?= $h($e['site_address'] ?? '-') ?>
                </td>
                <td style="font-size:12px;color:#888"><?= $h($e['staff_name'] ?? '미배정') ?></td>
                <td><?= _est_badge($e['status']) ?></td>
                <td style="font-size:12px;color:#888;white-space:nowrap">
                    <?= substr($h($e['created_at']), 0, 10) ?>
                </td>
                <td>
                    <a href="/staff/estimate-detail.php?id=<?= (int)$e['id'] ?>" class="btn-sm btn-sm-view">
                        <?= $tab === 'REQUESTED' ? '처리' : '보기' ?>
                    </a>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="10" style="text-align:center;padding:36px;color:#aaa">해당 견적이 없습니다.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 페이지네이션 -->
<?php if ($pages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a href="?tab=<?= $h($tab) ?>&q=<?= $h($q) ?>&page=<?= $i ?>" class="page-btn <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<?php
function _est_badge(string $status): string
{
    return match($status) {
        'REQUESTED' => '<span class="badge badge-requested">접수됨</span>',
        'WORKING'   => '<span class="badge badge-working">작성중</span>',
        'SENT'      => '<span class="badge badge-sent">발송됨</span>',
        'APPROVED'  => '<span class="badge badge-approved">완료</span>',
        'CANCELED'  => '<span class="badge badge-canceled">취소</span>',
        default     => "<span class=\"badge\">{$status}</span>",
    };
}
render_staff_footer();
