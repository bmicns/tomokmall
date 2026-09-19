<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';

require_admin();

// ── 파라미터 ─────────────────────────────────────────────────────
$role  = in_array($_GET['role'] ?? '', ['all','customer','corporate','staff','admin'], true) ? $_GET['role'] : 'all';
$q     = trim($_GET['q'] ?? '');
$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = 30;
$offset= ($page - 1) * $limit;

// ── 탭 카운트 ─────────────────────────────────────────────────────
$roleTabs = ['all'=>'전체','customer'=>'고객','corporate'=>'기업회원','staff'=>'직원','admin'=>'관리자'];
$counts   = [];
foreach (['customer','corporate','staff','admin'] as $r) {
    $st = db()->prepare('SELECT COUNT(*) FROM users WHERE role=:r');
    $st->execute(['r' => $r]);
    $counts[$r] = (int)$st->fetchColumn();
}
$counts['all'] = array_sum($counts);

// ── 목록 쿼리 ─────────────────────────────────────────────────────
$where  = [];
$params = [];
if ($role !== 'all') {
    $where[]     = 'role = :role';
    $params['role'] = $role;
}
if ($q !== '') {
    $where[]     = '(name LIKE :q OR email LIKE :q2 OR phone LIKE :q3 OR company_name LIKE :q4)';
    $params['q'] = $params['q2'] = $params['q3'] = $params['q4'] = "%{$q}%";
}
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$cntSt = db()->prepare("SELECT COUNT(*) FROM users {$whereStr}");
$cntSt->execute($params);
$total = (int)$cntSt->fetchColumn();

$st = db()->prepare(
    "SELECT id, role, name, email, phone, company_name, is_active, last_login_at, created_at
     FROM users {$whereStr}
     ORDER BY id DESC
     LIMIT {$limit} OFFSET {$offset}"
);
$st->execute($params);
$users = $st->fetchAll();
$pages = max(1, (int)ceil($total / $limit));

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
$roleLabels = ['customer'=>'고객','corporate'=>'기업회원','staff'=>'직원','admin'=>'관리자'];
$roleBadges = ['customer'=>'badge-sent','corporate'=>'badge-approved','staff'=>'badge-working','admin'=>'badge-requested'];

render_admin_header('회원관리', 'users');
?>
<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
    <div class="page-heading" style="margin:0">회원관리</div>
    <span style="font-size:13px;color:#888">총 <?= $total ?>명</span>
</div>

<!-- 탭 -->
<div style="display:flex;gap:0;border-bottom:2px solid #e8eaed;margin-bottom:18px">
    <?php foreach ($roleTabs as $key => $label): ?>
    <a href="?role=<?= $key ?>&q=<?= $h($q) ?>"
       style="padding:10px 18px;font-size:13px;font-weight:600;color:<?= $role===$key?'#004EA1':'#888' ?>;text-decoration:none;border-bottom:2px solid <?= $role===$key?'#004EA1':'transparent' ?>;margin-bottom:-2px">
        <?= $label ?>
        <span style="font-size:11px;color:<?= $role===$key?'#004EA1':'#aaa' ?>;margin-left:3px"><?= number_format($counts[$key]) ?></span>
    </a>
    <?php endforeach; ?>
</div>

<!-- 검색 -->
<div class="toolbar">
    <form method="get" style="display:contents">
        <input type="hidden" name="role" value="<?= $h($role) ?>">
        <input type="text" name="q" value="<?= $h($q) ?>" placeholder="이름·이메일·연락처·회사명">
        <button type="submit" class="btn btn-primary">검색</button>
        <?php if ($q): ?>
        <a href="?role=<?= $h($role) ?>" style="font-size:12px;color:#888;text-decoration:none;align-self:center">초기화</a>
        <?php endif; ?>
    </form>
</div>

<!-- 테이블 -->
<div class="card">
    <table class="adm-table">
        <thead>
            <tr>
                <th>#</th><th>이름</th><th>이메일</th><th>연락처</th><th>회사명</th>
                <th>역할</th><th>상태</th><th>최근 로그인</th><th>가입일</th><th>관리</th>
            </tr>
        </thead>
        <tbody>
        <?php if ($users): ?>
        <?php foreach ($users as $u): ?>
            <tr>
                <td style="color:#888;font-size:12px"><?= (int)$u['id'] ?></td>
                <td style="font-weight:600"><?= $h($u['name']) ?></td>
                <td style="font-size:12px;color:#666"><?= $h($u['email']) ?></td>
                <td style="font-size:12px"><?= $h($u['phone'] ?? '-') ?></td>
                <td style="font-size:12px;color:#888"><?= $h($u['company_name'] ?? '-') ?></td>
                <td><span class="badge <?= $roleBadges[$u['role']] ?? '' ?>"><?= $roleLabels[$u['role']] ?? $u['role'] ?></span></td>
                <td>
                    <span class="badge <?= $u['is_active'] ? 'badge-on' : 'badge-off' ?>">
                        <?= $u['is_active'] ? '활성' : '비활성' ?>
                    </span>
                </td>
                <td style="font-size:11px;color:#aaa"><?= $u['last_login_at'] ? substr($h($u['last_login_at']),0,10) : '-' ?></td>
                <td style="font-size:11px;color:#aaa"><?= substr($h($u['created_at']),0,10) ?></td>
                <td style="display:flex;gap:4px">
                    <button class="btn-sm btn-sm-edit" onclick='openEdit(<?= json_encode([
                        "id"=>$u['id'],"role"=>$u['role'],"is_active"=>$u['is_active'],"name"=>$u['name']
                    ]) ?>)'>수정</button>
                    <button class="btn-sm btn-sm-del" onclick="deleteUser(<?= (int)$u['id'] ?>, '<?= addslashes($h($u['name'])) ?>')">삭제</button>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php else: ?>
            <tr><td colspan="10" style="text-align:center;padding:32px;color:#aaa">회원이 없습니다.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- 페이지네이션 -->
<?php if ($pages > 1): ?>
<div class="pagination">
    <?php for ($i = 1; $i <= $pages; $i++): ?>
    <a href="?role=<?= $h($role) ?>&q=<?= $h($q) ?>&page=<?= $i ?>" class="page-btn <?= $i===$page?'active':'' ?>"><?= $i ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<!-- 수정 모달 -->
<div class="modal-overlay" id="edit-modal">
    <div class="modal-box" style="width:380px">
        <h3>회원 정보 수정</h3>
        <button class="modal-close" onclick="closeEdit()">×</button>
        <input type="hidden" id="edit-uid">
        <div style="font-size:13px;font-weight:700;margin-bottom:14px;color:#555" id="edit-name"></div>
        <div class="form-field">
            <label>역할</label>
            <select id="edit-role">
                <option value="customer">고객</option>
                <option value="corporate">기업회원</option>
                <option value="staff">직원</option>
                <option value="admin">관리자</option>
            </select>
        </div>
        <div class="form-field">
            <label>계정 상태</label>
            <select id="edit-active">
                <option value="1">활성</option>
                <option value="0">비활성</option>
            </select>
        </div>
        <div class="modal-footer">
            <button class="btn btn-secondary" onclick="closeEdit()">취소</button>
            <button class="btn btn-primary" onclick="saveUser()">저장</button>
        </div>
    </div>
</div>

<script>
function openEdit(u){
    document.getElementById('edit-uid').value    = u.id;
    document.getElementById('edit-role').value   = u.role;
    document.getElementById('edit-active').value = u.is_active ? '1' : '0';
    document.getElementById('edit-name').textContent = u.name + ' (#' + u.id + ')';
    document.getElementById('edit-modal').classList.add('open');
}
function closeEdit(){ document.getElementById('edit-modal').classList.remove('open'); }

async function deleteUser(id, name){
    if(!confirm(name + ' 회원을 삭제하시겠습니까?\n이 작업은 되돌릴 수 없습니다.')) return;
    const r = await fetch('/api/admin/users.php', {
        method:'DELETE',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id }),
    });
    const d = await r.json();
    if(d.ok){ location.reload(); }
    else alert('오류: ' + d.error);
}

async function saveUser(){
    const id     = parseInt(document.getElementById('edit-uid').value);
    const role   = document.getElementById('edit-role').value;
    const active = parseInt(document.getElementById('edit-active').value);
    const r = await fetch('/api/admin/users.php', {
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ id, role, is_active: active }),
    });
    const d = await r.json();
    if(d.ok){ closeEdit(); location.reload(); }
    else alert('오류: ' + d.error);
}
</script>

<?php render_admin_footer(); ?>
