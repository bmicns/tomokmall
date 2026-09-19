<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';
require_admin();

// ── 필터 파라미터 ──────────────────────────────────────────────
$filterCat    = preg_replace('/[^\w]/', '', $_GET['cat']    ?? '');
$filterRating = $_GET['rating'] ?? '';  // '1', '-1', 'null', ''
$filterFs     = isset($_GET['fs']) ? (int)$_GET['fs'] : -1;
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];

if ($filterCat !== '') {
    $where[]          = 'category_key = :cat';
    $params[':cat']   = $filterCat;
}
if ($filterRating === '1')    { $where[] = 'rating = 1'; }
elseif ($filterRating === '-1') { $where[] = 'rating = -1'; }
elseif ($filterRating === '0')  { $where[] = 'rating IS NULL'; }

if ($filterFs === 1)  { $where[] = 'is_fewshot = 1'; }
elseif ($filterFs === 0) { $where[] = 'is_fewshot = 0'; }

$whereStr = implode(' AND ', $where);

try {
    $totalStmt = db()->prepare("SELECT COUNT(*) FROM chat_logs WHERE {$whereStr}");
    $totalStmt->execute($params);
    $total = (int)$totalStmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT id, session_key, user_id, user_msg, assistant_txt, tools_json,
                category_key, rating, is_fewshot, created_at
         FROM chat_logs
         WHERE {$whereStr}
         ORDER BY id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $logs = $stmt->fetchAll();
} catch (\Throwable $e) {
    $logs  = [];
    $total = 0;
}

// 카테고리 목록 (필터용)
$cats = [];
try {
    $cats = db()->query(
        "SELECT DISTINCT category_key FROM chat_logs
         WHERE category_key IS NOT NULL ORDER BY category_key"
    )->fetchAll(\PDO::FETCH_COLUMN);
} catch (\Throwable $e) {}

$totalPages = max(1, (int)ceil($total / $perPage));

// few-shot 후보 수
$fewshotCount = 0;
try {
    $fewshotCount = (int)db()->query('SELECT COUNT(*) FROM chat_logs WHERE is_fewshot=1')->fetchColumn();
} catch (\Throwable $e) {}

$catLabels = [
    'grating'  => '그레이팅',
    'pe_pipe'  => 'PE배수관',
    'manhole'  => '맨홀·집수정',
    'culvert'  => '수로관',
    'pavement' => '보도블록·경계석',
];

render_admin_header('AI 상담로그', 'chat-logs');
?>
<style>
.log-filters{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.log-filters select,.log-filters a{height:36px;border:1px solid #e8eaed;border-radius:6px;padding:0 12px;font-size:13px;background:#fff;color:#333;text-decoration:none;display:flex;align-items:center;cursor:pointer;outline:none}
.log-filters select:focus{border-color:#004EA1}
.log-filters a.active{background:#004EA1;color:#fff;border-color:#004EA1}
.log-filters .fs-count{background:#e8f5e9;color:#2e7d32;border:1px solid #c8e6c9;border-radius:6px;padding:0 10px;height:36px;font-size:12px;font-weight:700;display:flex;align-items:center}
.log-table{width:100%;border-collapse:collapse;font-size:12px}
.log-table th{background:#f8f9fb;padding:8px 10px;text-align:left;font-weight:600;color:#555;border-bottom:1px solid #e8eaed;white-space:nowrap}
.log-table td{padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:top}
.log-table tr:hover td{background:#fafbfc}
.log-msg{max-width:220px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;cursor:pointer;color:#333}
.log-reply{max-width:260px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#666}
.log-msg.expanded,.log-reply.expanded{white-space:pre-wrap;overflow:visible}
.cat-badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:10px;font-weight:700;background:#e8f0fe;color:#004EA1}
.tools-tag{display:inline-block;padding:1px 5px;border-radius:3px;font-size:10px;background:#fff3e0;color:#e65100;margin-right:2px}
.rate-btn{width:28px;height:28px;border:1px solid #e8eaed;border-radius:5px;background:#fff;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;justify-content:center;transition:all .15s}
.rate-btn:hover{border-color:#004EA1}
.rate-btn.active-good{background:#e8f5e9;border-color:#4caf50;color:#2e7d32}
.rate-btn.active-bad{background:#fce4ec;border-color:#e57373;color:#c62828}
.fs-btn{width:28px;height:28px;border:1px solid #e8eaed;border-radius:5px;background:#fff;cursor:pointer;font-size:13px;display:inline-flex;align-items:center;justify-content:center;transition:all .15s}
.fs-btn.active{background:#fffde7;border-color:#fbc02d;color:#f57f17}
.export-btn{height:34px;padding:0 14px;border:1px solid #004EA1;border-radius:6px;background:#fff;color:#004EA1;font-size:13px;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px}
.export-btn:hover{background:#004EA1;color:#fff}
.page-info{font-size:12px;color:#888;margin-left:auto}
</style>

<div class="page-heading">AI 상담로그</div>

<!-- 필터 + 내보내기 -->
<div class="log-filters">
    <select onchange="applyFilter('cat', this.value)">
        <option value="">전체 카테고리</option>
        <?php foreach ($cats as $c): ?>
        <option value="<?= htmlspecialchars($c, ENT_QUOTES) ?>" <?= $filterCat === $c ? 'selected' : '' ?>>
            <?= htmlspecialchars($catLabels[$c] ?? $c, ENT_QUOTES) ?>
        </option>
        <?php endforeach; ?>
    </select>

    <select onchange="applyFilter('rating', this.value)">
        <option value="">전체 평가</option>
        <option value="1"  <?= $filterRating === '1'  ? 'selected' : '' ?>>👍 좋음</option>
        <option value="-1" <?= $filterRating === '-1' ? 'selected' : '' ?>>👎 나쁨</option>
        <option value="0"  <?= $filterRating === '0'  ? 'selected' : '' ?>>미평가</option>
    </select>

    <select onchange="applyFilter('fs', this.value)">
        <option value="-1" <?= $filterFs < 0 ? 'selected' : '' ?>>전체</option>
        <option value="1"  <?= $filterFs === 1 ? 'selected' : '' ?>>⭐ Few-shot 후보</option>
        <option value="0"  <?= $filterFs === 0 ? 'selected' : '' ?>>일반</option>
    </select>

    <span class="fs-count">⭐ 후보 <?= $fewshotCount ?>건</span>

    <button class="export-btn" onclick="exportFewshot()">
        <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
        Few-shot JSON 내보내기
    </button>

    <span class="page-info">총 <?= number_format($total) ?>건</span>
</div>

<!-- 로그 테이블 -->
<div class="card">
    <table class="log-table">
        <thead>
            <tr>
                <th style="width:50px">ID</th>
                <th style="width:100px">일시</th>
                <th style="width:70px">카테고리</th>
                <th>고객 메시지</th>
                <th>AI 답변</th>
                <th style="width:70px">Tools</th>
                <th style="width:70px">평가</th>
                <th style="width:36px">⭐</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($logs)): ?>
            <tr><td colspan="8" style="text-align:center;padding:32px;color:#aaa">로그가 없습니다.</td></tr>
        <?php else: foreach ($logs as $log):
            $catLabel = $catLabels[$log['category_key'] ?? ''] ?? ($log['category_key'] ?? '');
            $tools    = $log['tools_json'] ? explode(',', $log['tools_json']) : [];
            $isGood   = $log['rating'] === '1'  || $log['rating'] === 1;
            $isBad    = $log['rating'] === '-1' || $log['rating'] === -1;
            $isFs     = (int)$log['is_fewshot'] === 1;
            $dt       = date('m/d H:i', strtotime($log['created_at']));
        ?>
            <tr data-id="<?= $log['id'] ?>">
                <td style="color:#888;font-size:11px"><?= $log['id'] ?></td>
                <td style="color:#888;font-size:11px;white-space:nowrap"><?= $dt ?></td>
                <td><?php if ($catLabel): ?><span class="cat-badge"><?= htmlspecialchars($catLabel, ENT_QUOTES) ?></span><?php endif; ?></td>
                <td>
                    <div class="log-msg" title="클릭하여 전체 보기" onclick="toggleExpand(this)">
                        <?= htmlspecialchars(mb_substr($log['user_msg'] ?? '', 0, 200), ENT_QUOTES) ?>
                    </div>
                </td>
                <td>
                    <div class="log-reply" onclick="toggleExpand(this)">
                        <?= htmlspecialchars(mb_substr($log['assistant_txt'] ?? '(없음)', 0, 200), ENT_QUOTES) ?>
                    </div>
                </td>
                <td>
                    <?php foreach ($tools as $t): ?>
                    <span class="tools-tag"><?= htmlspecialchars($t, ENT_QUOTES) ?></span>
                    <?php endforeach; ?>
                </td>
                <td style="white-space:nowrap">
                    <button class="rate-btn <?= $isGood ? 'active-good' : '' ?>"
                            onclick="rate(<?= $log['id'] ?>, <?= $isGood ? 0 : 1 ?>, this)"
                            title="좋음">👍</button>
                    <button class="rate-btn <?= $isBad ? 'active-bad' : '' ?>"
                            onclick="rate(<?= $log['id'] ?>, <?= $isBad ? 0 : -1 ?>, this)"
                            title="나쁨">👎</button>
                </td>
                <td>
                    <button class="fs-btn <?= $isFs ? 'active' : '' ?>"
                            onclick="toggleFs(<?= $log['id'] ?>, this)"
                            title="Few-shot 후보">⭐</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- 페이지네이션 -->
<?php if ($totalPages > 1): ?>
<div class="pagination" style="margin-top:14px">
    <?php
    $qs = http_build_query(array_filter([
        'cat'    => $filterCat,
        'rating' => $filterRating,
        'fs'     => $filterFs >= 0 ? $filterFs : null,
    ]));
    for ($p = 1; $p <= min($totalPages, 20); $p++):
    ?>
    <a href="?<?= $qs ?>&page=<?= $p ?>"
       class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<script>
function applyFilter(key, val) {
    const url = new URL(location.href);
    if (val === '' || val === '-1') url.searchParams.delete(key);
    else url.searchParams.set(key, val);
    url.searchParams.delete('page');
    location.href = url.toString();
}

function toggleExpand(el) {
    el.classList.toggle('expanded');
}

async function rate(id, rating, clickedBtn) {
    const row = clickedBtn.closest('tr');
    const btns = row.querySelectorAll('.rate-btn');

    const res  = await fetch('/api/admin/chat-logs.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'rate', id, rating }),
    });
    const data = await res.json();
    if (!data.ok) { alert('오류: ' + (data.error || '저장 실패')); return; }

    // UI 갱신
    btns[0].classList.toggle('active-good', rating === 1);
    btns[1].classList.toggle('active-bad',  rating === -1);
    // 버튼 onclick 반전
    btns[0].setAttribute('onclick', `rate(${id}, ${rating === 1 ? 0 : 1}, this)`);
    btns[1].setAttribute('onclick', `rate(${id}, ${rating === -1 ? 0 : -1}, this)`);
}

async function toggleFs(id, btn) {
    const res  = await fetch('/api/admin/chat-logs.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'toggle_fewshot', id }),
    });
    const data = await res.json();
    if (!data.ok) { alert('오류: ' + (data.error || '저장 실패')); return; }
    btn.classList.toggle('active', data.is_fewshot === 1);
}

async function exportFewshot() {
    const res = await fetch('/api/admin/chat-logs.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({ action: 'export_fewshot' }),
    });
    const blob = await res.blob();
    const cd   = res.headers.get('Content-Disposition') || '';
    const fname = cd.match(/filename="([^"]+)"/)?.[1] || 'fewshot.json';
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href = url; a.download = fname; a.click();
    URL.revokeObjectURL(url);
}
</script>

<?php render_admin_footer(); ?>
