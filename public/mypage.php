<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/layout.php';

if (!is_logged_in()) {
    redirect('/login.php?redirect=' . urlencode('/mypage.php'));
}

$user   = current_user();
$uid    = (int) $user['id'];
$pdo    = db();
$isCorp = in_array($user['role'], ['corporate', 'staff', 'admin'], true);

$sLabels = ['REQUESTED'=>'접수됨','WORKING'=>'작성중','SENT'=>'발송됨','APPROVED'=>'완료','CANCELED'=>'취소','DELIVERED'=>'납품완료'];
$sBadges = ['REQUESTED'=>'badge-req','WORKING'=>'badge-wip','SENT'=>'badge-sent','APPROVED'=>'badge-done','CANCELED'=>'badge-off','DELIVERED'=>'badge-done'];

// 이전주문 목록
$orders = [];
try {
    $st = $pdo->prepare(
        "SELECT e.id, e.status, e.created_at,
                COUNT(ei.id) AS item_cnt,
                COALESCE(SUM(ei.quantity * ei.unit_price), 0) AS total_amount
         FROM estimates e
         LEFT JOIN estimate_items ei ON ei.estimate_id = e.id
         WHERE e.user_id = :uid
         GROUP BY e.id, e.status, e.created_at
         ORDER BY e.id DESC LIMIT 50"
    );
    $st->execute(['uid' => $uid]);
    $orders = $st->fetchAll();
} catch (\Throwable $e) { $orders = []; }

$activeTab = $_GET['tab'] ?? 'info';

render_header('마이페이지');
?>

<div class="mp-wrap">
    <div class="mp-userbar">
        <div>
            <div class="mp-username"><?= e($user['name']) ?></div>
            <div class="mp-userrole"><?= e($user['company_name'] ?? '') ?><?= $user['company_name'] ? ' · ' : '' ?><?= e($user['role']) ?></div>
        </div>
    </div>

    <div class="mp-tabs">
        <button class="mp-tab <?= $activeTab==='info'?'active':'' ?>" onclick="switchTab('info')">정보수정</button>
        <button class="mp-tab <?= $activeTab==='orders'?'active':'' ?>" onclick="switchTab('orders')">이전주문</button>
        <button class="mp-tab <?= $activeTab==='favorites'?'active':'' ?>" onclick="switchTab('favorites')">자주찾는 품목</button>
        <button class="mp-tab <?= $activeTab==='sites'?'active':'' ?>" onclick="switchTab('sites')">현장관리</button>
    </div>

    <!-- ① 정보수정 -->
    <div id="tab-info" class="mp-tab-content" style="<?= $activeTab!=='info'?'display:none':'' ?>">
        <div class="info-form">
            <div class="info-field">
                <label class="info-label">이름 / 담당자명</label>
                <input id="p-name" class="info-input" type="text" value="<?= e($user['name']) ?>" maxlength="80">
            </div>
            <div class="info-field">
                <label class="info-label">회사명</label>
                <input id="p-company" class="info-input" type="text" value="<?= e($user['company_name'] ?? '') ?>" maxlength="120">
            </div>
            <div class="info-field">
                <label class="info-label">전화번호</label>
                <input id="p-phone" class="info-input" type="tel" value="" maxlength="40" placeholder="불러오는 중...">
            </div>
            <div class="info-field">
                <label class="info-label">주소</label>
                <div style="display:flex;gap:6px">
                    <input id="p-zip" class="info-input" type="text" placeholder="우편번호" style="flex:0 0 120px" readonly>
                    <button type="button" onclick="openDaumPost('mypage')" style="padding:0 14px;background:var(--color-primary);color:#fff;border:none;font-size:12px;font-weight:600;cursor:pointer;white-space:nowrap;font-family:var(--font)">주소 찾기</button>
                </div>
            </div>
            <div class="info-field">
                <input id="p-addr" class="info-input" type="text" placeholder="기본주소" readonly>
            </div>
            <div class="info-field">
                <input id="p-addr-detail" class="info-input" type="text" placeholder="상세주소 (동/호수 등)" maxlength="255">
            </div>
            <div class="info-field">
                <label class="info-label">이메일 (변경 불가)</label>
                <input class="info-input" type="email" value="<?= e($user['email']) ?>" disabled style="background:#f5f5f5;color:#999">
            </div>
            <p class="info-msg" id="info-msg"></p>
            <div><button class="info-btn" id="info-btn" onclick="saveProfile()">저장하기</button></div>

            <hr class="info-divider">
            <div class="info-section-title">비밀번호 변경</div>
            <div class="info-field">
                <label class="info-label">현재 비밀번호</label>
                <input id="p-cur-pw" class="info-input" type="password" placeholder="현재 비밀번호">
            </div>
            <div class="info-field">
                <label class="info-label">새 비밀번호 (8자 이상)</label>
                <input id="p-new-pw" class="info-input" type="password" placeholder="새 비밀번호">
            </div>
            <div class="info-field">
                <label class="info-label">새 비밀번호 확인</label>
                <input id="p-new-pw2" class="info-input" type="password" placeholder="새 비밀번호 재입력">
            </div>
            <p class="info-msg" id="pw-msg"></p>
            <div><button class="info-btn" id="pw-btn" onclick="changePw()">비밀번호 변경</button></div>
        </div>
    </div>

    <!-- ② 이전주문 -->
    <div id="tab-orders" class="mp-tab-content" style="<?= $activeTab!=='orders'?'display:none':'' ?>">
        <?php if ($orders): ?>
        <div class="ord-list">
        <?php foreach ($orders as $o):
            $badge = $sBadges[$o['status']] ?? 'badge-off';
            $label = $sLabels[$o['status']] ?? $o['status'];
            $total = (int)$o['total_amount'];
        ?>
        <div class="ord-card">
            <div class="ord-id">#<?= str_pad((string)$o['id'],5,'0',STR_PAD_LEFT) ?></div>
            <div class="ord-info">
                <div class="ord-date"><?= e(substr($o['created_at'],0,10)) ?> 접수</div>
                <div class="ord-meta">품목 <?= (int)$o['item_cnt'] ?>종</div>
            </div>
            <div class="ord-amount"><?= $total>0 ? number_format($total).'원' : '담당자 문의' ?></div>
            <div><span class="<?= $badge ?>"><?= $label ?></span></div>
            <?php if ($isCorp): ?>
            <button class="ord-reorder-btn" onclick="reorder(<?= (int)$o['id'] ?>)">재주문</button>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
        </div>
        <?php else: ?>
        <div class="mp-empty">접수된 주문이 없습니다.</div>
        <?php endif; ?>
    </div>

    <!-- ④ 현장관리 -->
    <div id="tab-sites" class="mp-tab-content" style="<?= $activeTab!=='sites'?'display:none':'' ?>">
        <div class="site-toolbar">
            <button class="site-add-btn" onclick="openSiteForm()">+ 현장 추가</button>
        </div>
        <div id="site-list-wrap"><div class="mp-empty">불러오는 중...</div></div>

        <!-- 현장 추가/수정 폼 -->
        <div id="site-form-wrap" style="display:none">
            <div class="site-form-overlay" onclick="closeSiteForm()"></div>
            <div class="site-form-box">
                <div class="site-form-title" id="site-form-title">현장 추가</div>
                <input type="hidden" id="sf-id" value="">
                <div class="info-field">
                    <label class="info-label">현장명 *</label>
                    <input id="sf-name" class="info-input" type="text" placeholder="예: 00아파트 현장" maxlength="120">
                </div>
                <div class="info-field">
                    <label class="info-label">주소</label>
                    <input id="sf-address" class="info-input" type="text" placeholder="시공 주소 (선택)">
                </div>
                <div class="info-field">
                    <label class="info-label">메모</label>
                    <textarea id="sf-memo" class="info-input" rows="3" placeholder="현장 메모 (선택)" style="resize:vertical;padding:10px 12px"></textarea>
                </div>
                <p class="info-msg" id="sf-msg"></p>
                <div style="display:flex;gap:8px;margin-top:16px">
                    <button class="info-btn" id="sf-btn" onclick="saveSite()">저장</button>
                    <button class="info-btn" onclick="closeSiteForm()" style="background:var(--color-surface);color:var(--color-text-primary);border:1px solid var(--color-border)">취소</button>
                </div>
            </div>
        </div>

        <!-- 현장 상세 (연결된 견적 목록) -->
        <div id="site-detail-wrap" style="display:none">
            <div class="site-detail-header">
                <button class="site-back-btn" onclick="closeSiteDetail()">← 목록으로</button>
                <span id="site-detail-name" class="site-detail-title"></span>
            </div>
            <div id="site-detail-orders"><div class="mp-empty">불러오는 중...</div></div>
            <div style="margin-top:16px">
                <div class="info-label" style="margin-bottom:8px">이전 주문을 현장에 연결</div>
                <div id="site-link-list"></div>
            </div>
        </div>
    </div>

    <!-- ③ 자주찾는 품목 -->
    <div id="tab-favorites" class="mp-tab-content" style="<?= $activeTab!=='favorites'?'display:none':'' ?>">
        <?php if ($isCorp): ?>
        <div id="fav-list-wrap"><div class="mp-empty">불러오는 중...</div></div>
        <a href="/estimate-quick.php" class="btn-to-quick">즉시견적 바로가기 →</a>
        <?php else: ?>
        <div class="mp-empty">기업회원에게 제공되는 기능입니다.</div>
        <?php endif; ?>
    </div>
</div>

<script>
const MP_ORDERS = <?= json_encode(array_map(function($o) {
    return ['id' => (int)$o['id'], 'created_at' => $o['created_at'], 'item_cnt' => (int)$o['item_cnt']];
}, $orders), JSON_UNESCAPED_UNICODE) ?>;
const MP_INIT = {activeTab: '<?= e($activeTab) ?>', isCorp: <?= $isCorp ? 'true' : 'false' ?>};
</script>
<script src="/assets/mypage.js"></script>
<?php render_footer(); ?>
