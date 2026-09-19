<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/layout.php';

if (!is_logged_in()) {
    redirect('/login.php?redirect=' . urlencode('/my-estimates.php'));
}

$user = current_user();
$uid  = (int) $user['id'];
$pdo  = db();

$sLabels = ['REQUESTED'=>'접수됨','WORKING'=>'작성중','SENT'=>'발송됨','APPROVED'=>'완료','CANCELED'=>'취소'];
$sBadges = ['REQUESTED'=>'badge-req','WORKING'=>'badge-wip','SENT'=>'badge-sent','APPROVED'=>'badge-done','CANCELED'=>'badge-off'];

// 내 견적 목록 (품목 수 포함)
$estimates = [];
try {
    $st = $pdo->prepare(
        "SELECT e.id, e.status, e.created_at,
                COUNT(ei.id) AS item_cnt,
                COALESCE(SUM(ei.quantity * ei.unit_price), 0) AS total_amount
         FROM estimates e
         LEFT JOIN estimate_items ei ON ei.estimate_id = e.id
         WHERE e.user_id = :uid
         GROUP BY e.id, e.status, e.created_at
         ORDER BY e.id DESC
         LIMIT 50"
    );
    $st->execute(['uid' => $uid]);
    $estimates = $st->fetchAll();
} catch (\Throwable $e) {
    // DB 오류 시 빈 목록으로 graceful fallback
    $estimates = [];
}

render_header('요청된 견적');
?>
<style>
.my-wrap{max-width:960px;margin:0 auto;padding:32px 24px 80px}
.my-title{font-size:22px;font-weight:700;margin-bottom:4px}
.my-sub{font-size:13px;color:var(--color-text-secondary);margin-bottom:28px}
.est-list{display:flex;flex-direction:column;gap:10px;}
.est-card{border:1px solid var(--color-border);background:#fff;padding:16px 20px;display:flex;align-items:center;gap:16px;flex-wrap:wrap;}
.est-card-id{font-size:11px;color:#aaa;font-family:monospace;flex-shrink:0;width:70px;}
.est-card-info{flex:1;min-width:0;}
.est-card-date{font-size:13px;font-weight:600;color:var(--color-text-primary);}
.est-card-meta{font-size:12px;color:var(--color-text-secondary);margin-top:2px;}
.est-card-amount{font-size:14px;font-weight:700;color:var(--color-primary);flex-shrink:0;text-align:right;}
.est-card-status{flex-shrink:0;}
.badge-req{background:#fff3cd;color:#856404;padding:3px 9px;font-size:11px;font-weight:600;}
.badge-wip{background:#e8eaed;color:#555;padding:3px 9px;font-size:11px;font-weight:600;}
.badge-sent{background:#d1e7dd;color:#0a6640;padding:3px 9px;font-size:11px;font-weight:600;}
.badge-done{background:#cce5ff;color:#004085;padding:3px 9px;font-size:11px;font-weight:600;}
.badge-off{background:#f8d7da;color:#842029;padding:3px 9px;font-size:11px;font-weight:600;}
.empty-box{padding:48px 0;text-align:center;color:var(--color-text-secondary);font-size:14px;border:1px solid var(--color-border);}
.btn-new{display:inline-flex;align-items:center;gap:6px;margin-top:14px;padding:10px 20px;background:var(--color-primary);color:#fff;font-size:13px;font-weight:600;text-decoration:none;}
</style>

<div class="my-wrap">
    <div class="my-title">요청된 견적</div>
    <p class="my-sub">접수된 견적 요청 내역입니다. 담당자 검토 후 연락드립니다.</p>

    <?php if ($estimates): ?>
    <div class="est-list">
    <?php foreach ($estimates as $e):
        $badge = $sBadges[$e['status']] ?? 'badge-off';
        $label = $sLabels[$e['status']] ?? $e['status'];
        $date  = substr($e['created_at'], 0, 10);
        $total = (int)$e['total_amount'];
        $cnt   = (int)$e['item_cnt'];
    ?>
    <div class="est-card">
        <div class="est-card-id">#<?= str_pad((string)$e['id'], 5, '0', STR_PAD_LEFT) ?></div>
        <div class="est-card-info">
            <div class="est-card-date"><?= e($date) ?> 접수</div>
            <div class="est-card-meta">품목 <?= $cnt ?>종</div>
        </div>
        <div class="est-card-amount"><?= $total > 0 ? number_format($total).'원' : '담당자 문의' ?></div>
        <div class="est-card-status"><span class="<?= $badge ?>"><?= $label ?></span></div>
    </div>
    <?php endforeach; ?>
    </div>
    <?php else: ?>
    <div class="empty-box">
        아직 접수된 견적이 없습니다.<br>
        <a href="/estimate-quick.php" class="btn-new">즉시견적 신청하기</a>
    </div>
    <?php endif; ?>
</div>

<?php render_footer(); ?>
