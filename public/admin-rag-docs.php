<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';
require_admin();

$filterCat    = preg_replace('/[^\w]/', '', $_GET['cat'] ?? '');
$filterActive = $_GET['active'] ?? '';
$page         = max(1, (int)($_GET['page'] ?? 1));
$perPage      = 20;
$offset       = ($page - 1) * $perPage;

$where  = ['1=1'];
$params = [];
if ($filterCat !== '') {
    $where[]        = 'category_key = :cat';
    $params[':cat'] = $filterCat;
}
if ($filterActive === '1')  { $where[] = 'is_active = 1'; }
if ($filterActive === '0')  { $where[] = 'is_active = 0'; }
$whereStr = implode(' AND ', $where);

try {
    $total = (int)db()->prepare("SELECT COUNT(*) FROM rag_documents WHERE {$whereStr}")->execute($params)
             ? (int)db()->prepare("SELECT COUNT(*) FROM rag_documents WHERE {$whereStr}")->execute($params) : 0;
    $cntStmt = db()->prepare("SELECT COUNT(*) FROM rag_documents WHERE {$whereStr}");
    $cntStmt->execute($params);
    $total = (int)$cntStmt->fetchColumn();

    $stmt = db()->prepare(
        "SELECT id, title, category_key, tags, is_active, sort_order, updated_at
         FROM rag_documents WHERE {$whereStr}
         ORDER BY sort_order, id DESC
         LIMIT {$perPage} OFFSET {$offset}"
    );
    $stmt->execute($params);
    $docs = $stmt->fetchAll();
} catch (\Throwable $e) {
    $docs  = [];
    $total = 0;
}

$totalPages = max(1, (int)ceil($total / $perPage));

$catLabels = [
    ''         => '공통',
    'grating'  => '그레이팅',
    'pe_pipe'  => 'PE배수관',
    'manhole'  => '맨홀·집수정',
    'culvert'  => '수로관',
    'pavement' => '보도블록·경계석',
];

render_admin_header('RAG 문서 관리', 'rag-docs');
?>
<style>
.toolbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:16px;align-items:center}
.toolbar select,.toolbar input{height:36px;border:1px solid #e8eaed;border-radius:6px;padding:0 12px;font-size:13px;background:#fff;outline:none}
.toolbar select:focus,.toolbar input:focus{border-color:#004EA1}
.doc-title{font-weight:600;color:#1a2035;cursor:pointer}
.doc-title:hover{color:#004EA1;text-decoration:underline}
.doc-tags{font-size:11px;color:#999;margin-top:2px}
.cat-badge{display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:700;background:#e8f0fe;color:#004EA1}
.cat-badge.common{background:#f0f2f5;color:#666}
.toggle-btn{height:26px;padding:0 10px;border-radius:4px;border:1px solid;font-size:11px;font-weight:600;cursor:pointer}
.toggle-btn.on{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}
.toggle-btn.off{background:#fce4ec;color:#c62828;border-color:#ef9a9a}
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:9000;align-items:center;justify-content:center}
.modal-overlay.open{display:flex}
.modal-box{background:#fff;border-radius:12px;padding:28px;width:680px;max-width:96vw;max-height:90vh;overflow-y:auto;position:relative}
.modal-box h3{font-size:16px;font-weight:700;margin-bottom:18px;color:#1a2035}
.modal-close{position:absolute;top:14px;right:16px;background:none;border:none;cursor:pointer;font-size:20px;color:#888;line-height:1}
.field{display:flex;flex-direction:column;gap:4px;margin-bottom:14px}
.field label{font-size:12px;font-weight:600;color:#666}
.field input,.field select,.field textarea{border:1px solid #e8eaed;border-radius:6px;padding:8px 10px;font-size:13px;width:100%;background:#fff;outline:none;font-family:inherit}
.field input:focus,.field select:focus,.field textarea:focus{border-color:#004EA1}
.field textarea{resize:vertical;min-height:200px;line-height:1.6}
.modal-foot{display:flex;justify-content:flex-end;gap:10px;margin-top:18px}
.f2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
</style>

<div class="page-heading">RAG 문서 관리</div>

<div class="toolbar">
    <select onchange="applyFilter('cat', this.value)">
        <option value="">전체 카테고리</option>
        <?php foreach ($catLabels as $k => $v): if ($k === '') continue; ?>
        <option value="<?= $k ?>" <?= $filterCat === $k ? 'selected' : '' ?>><?= $v ?></option>
        <?php endforeach; ?>
    </select>
    <select onchange="applyFilter('active', this.value)">
        <option value="">전체</option>
        <option value="1" <?= $filterActive === '1' ? 'selected' : '' ?>>활성</option>
        <option value="0" <?= $filterActive === '0' ? 'selected' : '' ?>>비활성</option>
    </select>
    <button class="btn btn-primary" onclick="openModal(0)" style="margin-left:auto">+ 문서 추가</button>
    <span style="font-size:12px;color:#888">총 <?= number_format($total) ?>건</span>
</div>

<div class="card">
    <table class="adm-table">
        <thead>
            <tr>
                <th style="width:44px">ID</th>
                <th>제목</th>
                <th style="width:100px">카테고리</th>
                <th style="width:54px">순서</th>
                <th style="width:90px">수정일</th>
                <th style="width:60px">상태</th>
                <th style="width:70px"></th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($docs)): ?>
            <tr><td colspan="7" style="text-align:center;padding:32px;color:#aaa">문서가 없습니다.</td></tr>
        <?php else: foreach ($docs as $doc):
            $catLabel = $catLabels[$doc['category_key'] ?? ''] ?? ($doc['category_key'] ?? '');
            $isCommon = ($doc['category_key'] === null || $doc['category_key'] === '');
            $isActive = (int)$doc['is_active'] === 1;
            $updDt    = date('m/d H:i', strtotime($doc['updated_at']));
        ?>
            <tr id="row-<?= $doc['id'] ?>">
                <td style="color:#888;font-size:11px"><?= $doc['id'] ?></td>
                <td>
                    <div class="doc-title" onclick="openModal(<?= $doc['id'] ?>)"><?= htmlspecialchars($doc['title'], ENT_QUOTES) ?></div>
                    <?php if ($doc['tags']): ?>
                    <div class="doc-tags"><?= htmlspecialchars(mb_substr($doc['tags'], 0, 60), ENT_QUOTES) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <span class="cat-badge <?= $isCommon ? 'common' : '' ?>"><?= htmlspecialchars($catLabel, ENT_QUOTES) ?></span>
                </td>
                <td style="text-align:center;color:#888;font-size:12px"><?= $doc['sort_order'] ?></td>
                <td style="color:#888;font-size:11px;white-space:nowrap"><?= $updDt ?></td>
                <td>
                    <button class="toggle-btn <?= $isActive ? 'on' : 'off' ?>"
                            onclick="toggleActive(<?= $doc['id'] ?>, this)">
                        <?= $isActive ? '활성' : '비활성' ?>
                    </button>
                </td>
                <td style="white-space:nowrap">
                    <button class="btn-sm btn-sm-edit" onclick="openModal(<?= $doc['id'] ?>)">수정</button>
                    <button class="btn-sm btn-sm-del" onclick="delDoc(<?= $doc['id'] ?>)">삭제</button>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<?php if ($totalPages > 1): ?>
<div class="pagination">
    <?php
    $qs = http_build_query(array_filter(['cat' => $filterCat, 'active' => $filterActive]));
    for ($p = 1; $p <= min($totalPages, 20); $p++):
    ?>
    <a href="?<?= $qs ?>&page=<?= $p ?>" class="page-btn <?= $p === $page ? 'active' : '' ?>"><?= $p ?></a>
    <?php endfor; ?>
</div>
<?php endif; ?>

<!-- 편집 모달 -->
<div class="modal-overlay" id="modal">
    <div class="modal-box">
        <button class="modal-close" onclick="closeModal()">×</button>
        <h3 id="modal-title">문서 추가</h3>
        <input type="hidden" id="doc-id" value="0">

        <div class="field">
            <label>제목 <span style="color:#e53935">*</span></label>
            <input type="text" id="doc-title-input" placeholder="예: 그레이팅 하중 등급별 선택 기준">
        </div>

        <div class="f2">
            <div class="field">
                <label>카테고리</label>
                <select id="doc-cat">
                    <?php foreach ($catLabels as $k => $v): ?>
                    <option value="<?= $k ?>"><?= $v ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>표시 순서</label>
                <input type="number" id="doc-sort" value="0" min="0">
            </div>
        </div>

        <div class="field">
            <label>본문 <span style="color:#e53935">*</span> — AI가 이 내용을 참조해 답변합니다</label>
            <textarea id="doc-content" placeholder="선택 기준, 주의사항, 규격 안내 등 전문 지식을 자유롭게 작성하세요."></textarea>
        </div>

        <div class="field">
            <label>검색 태그 <small style="font-weight:400;color:#999">(콤마 구분, 선택)</small></label>
            <input type="text" id="doc-tags" placeholder="예: 중하중,차량,지게차,주차장">
        </div>

        <div class="field">
            <label>상태</label>
            <select id="doc-active">
                <option value="1">활성</option>
                <option value="0">비활성</option>
            </select>
        </div>

        <div class="modal-foot">
            <button class="btn btn-secondary" onclick="closeModal()">취소</button>
            <button class="btn btn-primary" onclick="saveDoc()">저장</button>
        </div>
    </div>
</div>

<script>
const CAT_LABELS = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE) ?>;

function applyFilter(key, val) {
    const url = new URL(location.href);
    if (!val) url.searchParams.delete(key);
    else url.searchParams.set(key, val);
    url.searchParams.delete('page');
    location.href = url.toString();
}

function openModal(id) {
    document.getElementById('doc-id').value = id;
    if (id === 0) {
        document.getElementById('modal-title').textContent = '문서 추가';
        document.getElementById('doc-title-input').value = '';
        document.getElementById('doc-content').value = '';
        document.getElementById('doc-tags').value = '';
        document.getElementById('doc-cat').value = '';
        document.getElementById('doc-sort').value = 0;
        document.getElementById('doc-active').value = 1;
    } else {
        fetch('/api/admin/rag-docs.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'get', id }),
        }).then(r => r.json()).then(data => {
            if (!data.ok) { alert('불러오기 실패'); return; }
            const d = data.doc;
            document.getElementById('modal-title').textContent = '문서 수정';
            document.getElementById('doc-title-input').value = d.title || '';
            document.getElementById('doc-content').value    = d.content || '';
            document.getElementById('doc-tags').value       = d.tags || '';
            document.getElementById('doc-cat').value        = d.category_key || '';
            document.getElementById('doc-sort').value       = d.sort_order || 0;
            document.getElementById('doc-active').value     = d.is_active;
        });
    }
    document.getElementById('modal').classList.add('open');
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}

async function saveDoc() {
    const id      = parseInt(document.getElementById('doc-id').value, 10);
    const title   = document.getElementById('doc-title-input').value.trim();
    const content = document.getElementById('doc-content').value.trim();
    if (!title || !content) { alert('제목과 본문을 입력해 주세요.'); return; }

    const res  = await fetch('/api/admin/rag-docs.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
            action:       'save',
            id,
            title,
            content,
            category_key: document.getElementById('doc-cat').value,
            tags:         document.getElementById('doc-tags').value,
            sort_order:   parseInt(document.getElementById('doc-sort').value, 10) || 0,
            is_active:    parseInt(document.getElementById('doc-active').value, 10),
        }),
    });
    const data = await res.json();
    if (!data.ok) { alert('저장 실패: ' + (data.error || '')); return; }
    closeModal();
    location.reload();
}

async function toggleActive(id, btn) {
    const res  = await fetch('/api/admin/rag-docs.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'toggle_active', id }),
    });
    const data = await res.json();
    if (!data.ok) return;
    btn.textContent = data.is_active ? '활성' : '비활성';
    btn.className   = 'toggle-btn ' + (data.is_active ? 'on' : 'off');
}

async function delDoc(id) {
    if (!confirm('이 문서를 삭제하시겠습니까?')) return;
    const res  = await fetch('/api/admin/rag-docs.php', {
        method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id }),
    });
    const data = await res.json();
    if (data.ok) document.getElementById('row-' + id)?.remove();
    else alert('삭제 실패');
}

document.getElementById('modal').addEventListener('click', (e) => {
    if (e.target === document.getElementById('modal')) closeModal();
});
</script>

<?php render_admin_footer(); ?>
