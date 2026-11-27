<?php
declare(strict_types=1);
/**
 * 관리자 — 탈퇴 사유 (사용자 결정 2026-11-27, data-decisions P1)
 * 회원 탈퇴 때 고른 사유·남기실 말씀(회원과 연결 없음, 전화·이메일·사업자번호 모양은 저장 전에 지움 — app/privacy.php).
 * 기한 없이 보관하고 관리자가 이 화면에서 직접 지운다(api/admin/withdraw-reasons.php).
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';
require __DIR__ . '/../app/privacy.php';
require_admin();

$reasonLabels = PRIVACY_WITHDRAW_REASONS;
$roleLabels   = ['customer' => '개인회원', 'corporate' => '기업회원'];

$filterReason = (string) ($_GET['reason'] ?? '');   // '' 전체, '0' 고르지 않음, '1'~'7'
$page         = max(1, (int) ($_GET['page'] ?? 1));
$perPage      = 30;
$offset       = ($page - 1) * $perPage;

$pdo      = db();
$hasTable = $pdo && privacy_table_exists($pdo, 'withdraw_reasons');
$rows     = [];
$total    = 0;
$counts   = [];   // reason_code(0 = 고르지 않음) => 건수
$allCount = 0;

if ($hasTable) {
    $where  = '1=1';
    $params = [];
    if ($filterReason === '0') {
        $where = 'reason_code IS NULL';
    } elseif (ctype_digit($filterReason) && isset($reasonLabels[(int) $filterReason])) {
        $where = 'reason_code = :rc';
        $params['rc'] = (int) $filterReason;
    } else {
        $filterReason = '';
    }
    try {
        foreach ($pdo->query('SELECT COALESCE(reason_code, 0) AS rc, COUNT(*) AS n FROM withdraw_reasons GROUP BY rc')->fetchAll() as $c) {
            $counts[(int) $c['rc']] = (int) $c['n'];
            $allCount += (int) $c['n'];
        }
        $st = $pdo->prepare("SELECT COUNT(*) FROM withdraw_reasons WHERE {$where}");
        $st->execute($params);
        $total = (int) $st->fetchColumn();
        $st = $pdo->prepare(
            "SELECT id, reason_code, memo, member_role, created_on FROM withdraw_reasons
              WHERE {$where} ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
        );
        $st->execute($params);
        $rows = $st->fetchAll();
    } catch (\Throwable $e) {
        error_log('admin-withdraw-reasons: ' . $e->getMessage());
    }
}
$totalPages = max(1, (int) ceil($total / $perPage));
$qs = static fn(array $over): string => '?' . http_build_query(array_filter(
    array_merge(['reason' => $filterReason, 'page' => $page], $over),
    static fn($v) => $v !== '' && $v !== null
));

render_admin_header('탈퇴 사유', 'withdraw-reasons');
?>
<style>
.wr-note{font-size:12px;color:#666;margin:-8px 0 16px;line-height:1.6}
.wr-filters{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:16px}
.wr-filters a{min-height:36px;border:1px solid #e8eaed;border-radius:6px;padding:0 12px;font-size:13px;background:#fff;color:#333;text-decoration:none;display:inline-flex;align-items:center;gap:6px}
.wr-filters a.active{background:#004EA1;color:#fff;border-color:#004EA1}
.wr-filters a b{font-weight:700}
.wr-table{width:100%;border-collapse:collapse;font-size:13px}
.wr-table th{background:#f8f9fb;padding:9px 12px;text-align:left;font-weight:600;color:#555;border-bottom:1px solid #e8eaed;white-space:nowrap}
.wr-table td{padding:9px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
.wr-memo{white-space:pre-wrap;word-break:break-all;color:#333;min-width:200px}
.wr-none{color:#aaa}
.wr-del{min-height:32px;padding:0 12px;border:1px solid #e0e0e0;border-radius:6px;background:#fff;color:#c62828;font-size:12px;cursor:pointer;white-space:nowrap}
.wr-del.confirm{background:#c62828;color:#fff;border-color:#c62828}
.wr-empty{padding:40px 20px;text-align:center;color:#888;font-size:13px}
</style>

<div class="page-heading">탈퇴 사유</div>
<p class="wr-note">회원 탈퇴 때 남긴 사유입니다. 누가 남겼는지는 저장하지 않으며(날짜·회원 종류만), 남기실 말씀의 전화번호·이메일·사업자번호는 저장 전에 지웁니다. 보관 기한은 없고 필요 없으면 여기서 지웁니다.</p>

<?php if (!$hasTable): ?>
<div class="card"><div class="wr-empty">탈퇴 사유 표가 아직 없습니다. <code>database/20261127_withdraw_reasons.sql</code> 을 먼저 반영해 주세요.</div></div>
<?php else: ?>
<nav class="wr-filters" aria-label="사유별 보기">
    <a href="<?= e($qs(['reason' => '', 'page' => null])) ?>" class="<?= $filterReason === '' ? 'active' : '' ?>">전체 <b><?= $allCount ?></b></a>
    <?php foreach ($reasonLabels as $code => $label): ?>
    <a href="<?= e($qs(['reason' => (string) $code, 'page' => null])) ?>" class="<?= $filterReason === (string) $code ? 'active' : '' ?>"><?= e($label) ?> <b><?= $counts[$code] ?? 0 ?></b></a>
    <?php endforeach; ?>
    <a href="<?= e($qs(['reason' => '0', 'page' => null])) ?>" class="<?= $filterReason === '0' ? 'active' : '' ?>">고르지 않음 <b><?= $counts[0] ?? 0 ?></b></a>
</nav>

<div class="card">
<?php if (!$rows): ?>
    <div class="wr-empty">남긴 사유가 없습니다.</div>
<?php else: ?>
    <table class="wr-table">
        <thead><tr><th>날짜</th><th>회원 종류</th><th>사유</th><th>남기실 말씀</th><th>관리</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): $rc = $r['reason_code'] === null ? null : (int) $r['reason_code']; ?>
        <tr id="wr-<?= (int) $r['id'] ?>">
            <td style="white-space:nowrap"><?= e((string) $r['created_on']) ?></td>
            <td style="white-space:nowrap"><?= e($roleLabels[$r['member_role']] ?? (string) $r['member_role']) ?></td>
            <td><?= $rc === null ? '<span class="wr-none">고르지 않음</span>' : e($reasonLabels[$rc] ?? ('#' . $rc)) ?></td>
            <td class="wr-memo"><?= ($r['memo'] ?? '') === '' ? '<span class="wr-none">-</span>' : e((string) $r['memo']) ?></td>
            <td><button type="button" class="wr-del" data-id="<?= (int) $r['id'] ?>">삭제</button></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
<?php endif; ?>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php for ($p = 1; $p <= $totalPages; $p++): ?>
    <a href="<?= e($qs(['page' => $p])) ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>
<?php endif; ?>

<script>
// 삭제: 한 번 누르면 '한 번 더 눌러 삭제'로 바뀌고, 3초 안에 다시 누르면 지움(브라우저 확인 창 없이)
document.querySelectorAll('.wr-del').forEach(function (btn) {
    var timer = null;
    btn.addEventListener('click', async function () {
        if (!btn.classList.contains('confirm')) {
            btn.classList.add('confirm');
            btn.textContent = '한 번 더 눌러 삭제';
            timer = setTimeout(function () { btn.classList.remove('confirm'); btn.textContent = '삭제'; }, 3000);
            return;
        }
        clearTimeout(timer);
        btn.disabled = true;
        try {
            var res = await fetch('/api/admin/withdraw-reasons.php', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({action: 'delete', id: parseInt(btn.dataset.id, 10)})
            });
            var d = await res.json().catch(function () { return null; });
            if (d && d.ok) { location.reload(); return; }
            btn.textContent = (d && d.error) || '삭제하지 못했습니다.';
        } catch (e) { btn.textContent = '네트워크 오류'; }
        btn.disabled = false;
        btn.classList.remove('confirm');
    });
});
</script>
<?php render_admin_footer(); ?>
