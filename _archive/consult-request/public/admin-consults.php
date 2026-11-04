<?php
declare(strict_types=1);
/**
 * 관리자 — 상담 요청 목록 ('상담 요청 - 메인 화면' 시안)
 * GET ?status=pending|contact|closed&q=검색어&page=N
 * 행을 누르면 상세(admin-consult.php?id=N)
 * 50~60대 이용자를 고려해 이 화면의 표·탭·검색은 글자·행 간격을 키움(시안 그대로, 이 화면에만 적용)
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';
require __DIR__ . '/../app/consult.php';
require_admin();

$statuses = consult_statuses();
$status   = (string) ($_GET['status'] ?? '');
$status   = isset($statuses[$status]) ? $status : '';
$q        = mb_substr(trim((string) ($_GET['q'] ?? '')), 0, 50);
$page     = max(1, (int) ($_GET['page'] ?? 1));
$perPage  = 10;

$rows   = [];
$counts = ['all' => 0, 'pending' => 0, 'contact' => 0, 'closed' => 0];
$dbError = false;
try {
    $pdo = db();
    if (!$pdo) {
        throw new RuntimeException('db');
    }
    $counts     = consult_counts($pdo, $q);
    $total      = $status === '' ? $counts['all'] : $counts[$status];
    $totalPages = max(1, (int) ceil($total / $perPage));
    $page       = min($page, $totalPages);
    $rows       = consult_list($pdo, $status, $q, $page, $perPage);
} catch (\Throwable $e) {
    error_log('[admin-consults] 목록 조회 실패: ' . get_class($e));
    $dbError    = true;
    $totalPages = 1;
}

$link = static function (array $over) use ($status, $q, $page): string {
    $p = array_merge(['status' => $status, 'q' => $q, 'page' => $page], $over);
    $p = array_filter($p, static fn($v) => $v !== '' && $v !== 1 && $v !== null);
    return '/admin-consults.php' . ($p ? '?' . http_build_query($p) : '');
};

render_admin_header('상담 요청', 'consults');
?>
<style>
.cs-list .page-heading{font-size:26px;margin-bottom:20px}
.cs-tabs{display:flex;gap:6px;margin-bottom:12px;flex-wrap:wrap}
.cs-tab{height:44px;padding:0 18px;border-radius:6px;border:1px solid #e8eaed;background:#fff;color:#444;font-size:16px;font-weight:600;display:inline-flex;align-items:center;gap:6px;text-decoration:none}
.cs-tab .n{font-size:15px;font-weight:700;color:#888}
.cs-tab.pending .n{color:#e65100}
.cs-tab[aria-current="page"]{border-color:#004EA1;background:#004EA1;color:#fff}
.cs-tab[aria-current="page"] .n{color:#fff}
.cs-search{position:relative;display:inline-flex;align-items:center;max-width:100%}
.cs-search svg{position:absolute;left:14px;pointer-events:none}
.cs-list .toolbar input[type=search]{width:380px;max-width:100%;height:44px;padding:0 14px 0 42px;font-size:16px;font-family:inherit;color:#1a2035}
.cs-table-wrap{overflow-x:auto}
.cs-list .adm-table{font-size:18px;min-width:860px;table-layout:fixed}
.cs-list .adm-table th{padding:13px 16px;font-size:16px}
.cs-list .adm-table td{padding:15px 16px;height:66px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.cs-list .adm-table tbody tr{cursor:pointer}
.cs-list .adm-table tr.is-pending td{background:#FFFAF3}
.cs-list .adm-table tr:hover td{background:#f3f6fa}
.cs-list .adm-table td.msg{overflow:hidden;text-overflow:ellipsis}
.cs-list .adm-table td.pii-gone{color:#bbb}
.cs-list .adm-table a.cs-id{color:#888;text-decoration:none}
.cs-list .adm-table a.cs-id:focus-visible{outline:2px solid #004EA1;outline-offset:2px}
.cs-list .badge{padding:4px 10px;font-size:15px;white-space:nowrap}
.badge-contact{background:#e8f0fe;color:#004EA1}
.badge-closed{background:#f0f2f5;color:#777}
.cs-empty{padding:48px 20px;text-align:center;color:#888;font-size:16px}
.cs-pages{display:flex;gap:5px;justify-content:center;align-items:center;margin-top:16px;flex-wrap:wrap}
.cs-pages .page-btn{min-width:40px;height:40px;padding:0 8px;font-size:16px;display:inline-flex;align-items:center;justify-content:center;text-decoration:none}
.cs-pages .page-btn.nav{padding:0 14px}
.cs-pages .page-btn.off{opacity:.4;cursor:default}
.cs-alert{padding:14px 18px;border-radius:8px;background:#fce4ec;color:#c62828;font-size:14px;margin-bottom:16px}
</style>
<div class="cs-list">
  <h1 class="page-heading">상담 요청</h1>

  <?php if ($dbError): ?>
  <div class="cs-alert" role="alert">상담 요청을 불러오지 못했습니다. DB에 상담 요청 테이블(database/20261103_consult_requests.sql)이 반영됐는지 확인해 주세요.</div>
  <?php endif; ?>

  <nav class="cs-tabs" aria-label="상태">
    <?php foreach (['' => ['전체', $counts['all']], 'pending' => ['접수됨', $counts['pending']], 'contact' => ['발송됨', $counts['contact']], 'closed' => ['완료', $counts['closed']]] as $k => [$label, $n]): ?>
    <a class="cs-tab <?= $k ?>" href="<?= e($link(['status' => $k, 'page' => 1])) ?>"<?= $status === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?><span class="n"><?= (int) $n ?></span></a>
    <?php endforeach; ?>
  </nav>

  <form class="toolbar" method="get" action="/admin-consults.php" role="search">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= e($status) ?>"><?php endif; ?>
    <div class="cs-search">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#888888" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="11" cy="11" r="7"></circle><line x1="16.5" y1="16.5" x2="21" y2="21"></line></svg>
      <input type="search" name="q" value="<?= e($q) ?>" aria-label="검색" placeholder="이름 · 연락처 · 문의 내용 검색">
    </div>
  </form>

  <div class="card cs-table-wrap">
    <table class="adm-table">
      <thead>
        <tr><th style="width:80px">ID</th><th style="width:150px">접수일시</th><th style="width:120px">상태</th><th style="width:124px">이름</th><th style="width:184px">연락처</th><th>문의 내용</th></tr>
      </thead>
      <tbody>
        <?php foreach ($rows as $r):
            $st   = $statuses[$r['status']] ?? $statuses['pending'];
            $gone = $r['status'] === 'closed';
            $href = '/admin-consult.php?id=' . (int) $r['id'];
        ?>
        <tr class="<?= $r['status'] === 'pending' ? 'is-pending' : '' ?>" data-href="<?= e($href) ?>">
          <td><a class="cs-id" href="<?= e($href) ?>"><?= (int) $r['id'] ?></a></td>
          <td style="color:#666"><?= e(consult_fmt_short($r['created_at'])) ?></td>
          <td><span class="badge <?= e($st[1]) ?>"><?= e($st[0]) ?></span></td>
          <td class="<?= $gone ? 'pii-gone' : '' ?>"<?= $gone ? '' : ' title="' . e((string) $r['name']) . '"' ?>><?= $gone ? '파기됨' : e((string) $r['name']) ?></td>
          <td class="<?= $gone ? 'pii-gone' : '' ?>" style="font-variant-numeric:tabular-nums"><?= $gone ? '파기됨' : e((string) $r['tel']) ?></td>
          <td class="msg" title="<?= e((string) $r['message']) ?>"><?= e(preg_replace('/\s+/u', ' ', (string) $r['message'])) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows && !$dbError): ?>
        <tr><td colspan="6" class="cs-empty" style="cursor:default"><?= $q !== '' ? '검색 결과가 없습니다.' : '접수된 상담 요청이 없습니다.' ?></td></tr>
        <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php
  $first = $page <= 1;
  $last  = $page >= $totalPages;
  $win   = 5;
  $start = max(1, min($page - intdiv($win, 2), $totalPages - $win + 1));
  $end   = min($totalPages, $start + $win - 1);
  $navBtn = static function (bool $off, string $href, string $label, string $aria = '') : string {
      $a = $aria !== '' ? ' aria-label="' . e($aria) . '"' : '';
      return $off
          ? '<span class="page-btn nav off" aria-disabled="true"' . $a . '>' . $label . '</span>'
          : '<a class="page-btn nav" href="' . e($href) . '"' . $a . '>' . $label . '</a>';
  };
  ?>
  <nav class="cs-pages" aria-label="페이지">
    <?= $navBtn($first, $link(['page' => 1]), '« 처음', '첫 페이지') ?>
    <?= $navBtn($first, $link(['page' => $page - 1]), '‹ 이전') ?>
    <?php for ($n = $start; $n <= $end; $n++): ?>
    <a class="page-btn<?= $n === $page ? ' active' : '' ?>" href="<?= e($link(['page' => $n])) ?>"<?= $n === $page ? ' aria-current="page"' : '' ?>><?= $n ?></a>
    <?php endfor; ?>
    <?= $navBtn($last, $link(['page' => $page + 1]), '다음 ›') ?>
    <?= $navBtn($last, $link(['page' => $totalPages]), '끝 »', '마지막 페이지') ?>
  </nav>
</div>
<script>
// 행 어디를 눌러도 상세로 (키보드는 ID 링크로 이동)
document.querySelectorAll('.cs-list tr[data-href]').forEach(tr => {
  tr.addEventListener('click', (e) => {
    if (e.target.closest('a')) return;
    location.href = tr.dataset.href;
  });
});
</script>
<?php render_admin_footer(); ?>
