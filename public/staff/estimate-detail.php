<?php
declare(strict_types=1);
require __DIR__ . '/../../app/bootstrap.php';
require __DIR__ . '/../../app/auth.php';
require __DIR__ . '/../../app/staff_layout.php';

$staffUser = require_staff();

$id = (int)($_GET['id'] ?? 0);
if (!$id) { header('Location: /staff/index.php'); exit; }

// ── 견적 상세 조회 ─────────────────────────────────────────────
$est = db()->prepare(
    'SELECT e.*, u.name AS staff_name
     FROM estimates e
     LEFT JOIN users u ON u.id = e.staff_id
     WHERE e.id = :id'
);
$est->execute(['id' => $id]);
$estimate = $est->fetch();
if (!$estimate) { header('Location: /staff/index.php'); exit; }

$itemSt = db()->prepare(
    'SELECT ei.*, p.name AS product_name, p.spec, p.brand, p.price_b2b, p.price_b2c
     FROM estimate_items ei
     JOIN products p ON p.id = ei.product_id
     WHERE ei.estimate_id = :id ORDER BY ei.id'
);
$itemSt->execute(['id' => $id]);
$items = $itemSt->fetchAll();

// 소계 계산
$subtotal = 0;
foreach ($items as $it) {
    $price     = $it['adjusted_price'] ?? $it['unit_price'];
    $subtotal += $price * $it['quantity'];
}
$total = $subtotal + ($estimate['shipping_fee'] ?? 0) - ($estimate['discount_amt'] ?? 0);

$h = fn($v) => htmlspecialchars((string)($v ?? ''), ENT_QUOTES);
$statusLabels = [
    'REQUESTED' => '접수됨', 'WORKING' => '작성중',
    'SENT' => '발송됨', 'APPROVED' => '완료', 'CANCELED' => '취소',
];
$isEditable = in_array($estimate['status'], ['REQUESTED', 'WORKING'], true);

render_staff_header("견적 #{$id} 상세", 'estimates');
?>
<style>
.est-layout{display:grid;grid-template-columns:1fr 290px;gap:20px;align-items:start}
.est-section{margin-bottom:20px}
.est-section-title{font-size:13px;font-weight:700;color:#1a2035;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #e8eaed}
.item-table{width:100%;border-collapse:collapse;font-size:13px}
.item-table th{background:#f8f9fb;padding:8px 10px;text-align:left;font-size:12px;font-weight:600;color:#555;border-bottom:1px solid #e8eaed;white-space:nowrap}
.item-table td{padding:8px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
.item-table tr:last-child td{border-bottom:none}
.price-input{width:90px;border:1px solid #e0e0e0;border-radius:4px;padding:4px 6px;font-size:12px;text-align:right}
.price-input:focus{border-color:#003580;outline:none}
.num-input{width:60px;border:1px solid #e0e0e0;border-radius:4px;padding:4px 6px;font-size:12px;text-align:right}
.side-panel{display:flex;flex-direction:column;gap:14px}
.info-box{background:#fff;border:1px solid #e8eaed;border-radius:10px;padding:16px}
.info-box-title{font-size:12px;font-weight:700;color:#003580;margin-bottom:10px;padding-bottom:6px;border-bottom:1px solid #f0f0f0}
.info-row{display:flex;justify-content:space-between;align-items:flex-start;gap:8px;margin-bottom:6px;font-size:12px}
.info-row:last-child{margin-bottom:0}
.info-label{color:#888;flex-shrink:0;white-space:nowrap}
.info-value{color:#1a2035;font-weight:600;text-align:right;word-break:break-all}
.total-row{display:flex;justify-content:space-between;font-size:13px;margin-bottom:6px;padding:3px 0}
.total-row.grand{font-weight:800;font-size:14px;color:#003580;border-top:1px solid #e8eaed;padding-top:8px;margin-top:4px}
.action-bar{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}
.memo-area{width:100%;border:1px solid #e8eaed;border-radius:6px;padding:8px;font-size:12px;min-height:80px;resize:vertical;outline:none;font-family:inherit}
.memo-area:focus{border-color:#003580}
.fee-field{display:flex;align-items:center;gap:8px;margin-bottom:8px}
.fee-field label{font-size:12px;color:#666;flex-shrink:0;width:60px}
.fee-field input{flex:1;border:1px solid #e8eaed;border-radius:5px;padding:5px 8px;font-size:12px;text-align:right;outline:none}
.fee-field input:focus{border-color:#003580}
</style>

<!-- 상단 헤더 -->
<div style="display:flex;align-items:center;gap:12px;margin-bottom:20px;flex-wrap:wrap">
    <a href="/staff/index.php" style="font-size:13px;color:#888;text-decoration:none">← 목록</a>
    <div class="page-heading" style="margin:0">견적 #<?= $id ?></div>
    <?php
    $badgeClass = match($estimate['status']) {
        'REQUESTED' => 'badge-requested', 'WORKING' => 'badge-working',
        'SENT' => 'badge-sent', 'APPROVED' => 'badge-approved', default => 'badge-canceled'
    };
    ?>
    <span class="badge <?= $badgeClass ?>"><?= $statusLabels[$estimate['status']] ?? $estimate['status'] ?></span>
    <span style="font-size:12px;color:#aaa;margin-left:auto">접수: <?= $h(substr($estimate['created_at'], 0, 10)) ?></span>
</div>

<div class="est-layout">
<!-- 왼쪽: 내용 -->
<div>
    <!-- 구매품목 -->
    <div class="est-section">
        <div class="est-section-title">구매품목 목록</div>
        <div class="card" style="overflow-x:auto">
            <table class="item-table">
                <thead>
                    <tr>
                        <th>품명</th><th>규격</th><th>요청단가</th>
                        <th>수정단가</th><th>수량</th><th>파렛트</th><th>무게(kg)</th><th>소계</th>
                    </tr>
                </thead>
                <tbody id="item-tbody">
                <?php foreach ($items as $idx => $it): ?>
                    <?php
                    $reqPrice  = (int)$it['unit_price'];
                    $adjPrice  = $it['adjusted_price'] !== null ? (int)$it['adjusted_price'] : '';
                    $dispPrice = $it['adjusted_price'] !== null ? (int)$it['adjusted_price'] : $reqPrice;
                    $lineTotal = $dispPrice * (int)$it['quantity'];
                    ?>
                    <tr data-item-id="<?= (int)$it['id'] ?>">
                        <td style="font-weight:600;max-width:140px"><?= $h($it['product_name']) ?></td>
                        <td style="font-size:11px;color:#666;max-width:100px"><?= $h($it['spec']) ?></td>
                        <td style="text-align:right;color:#888"><?= number_format($reqPrice) ?>원</td>
                        <td>
                            <?php if ($isEditable): ?>
                            <input type="number" class="price-input adj-price" name="adj_price[<?= $idx ?>]"
                                   value="<?= $adjPrice ?>" placeholder="<?= number_format($reqPrice) ?>"
                                   data-idx="<?= $idx ?>" min="0">
                            <?php else: ?>
                            <span style="font-size:12px;color:<?= $adjPrice !== '' ? '#003580' : '#aaa' ?>">
                                <?= $adjPrice !== '' ? number_format((int)$adjPrice).'원' : '-' ?>
                            </span>
                            <?php endif; ?>
                        </td>
                        <td style="text-align:center"><?= (int)$it['quantity'] ?>개</td>
                        <td>
                            <?php if ($isEditable): ?>
                            <input type="number" class="num-input" name="pallet[<?= $idx ?>]"
                                   value="<?= (int)$it['pallet_cnt'] ?>" min="0" data-idx="<?= $idx ?>">
                            <?php else: ?>
                            <span style="font-size:12px"><?= (int)$it['pallet_cnt'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($isEditable): ?>
                            <input type="number" class="num-input" name="weight[<?= $idx ?>]"
                                   value="<?= (int)$it['weight_kg'] ?>" min="0" data-idx="<?= $idx ?>">
                            <?php else: ?>
                            <span style="font-size:12px"><?= number_format((int)$it['weight_kg']) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="line-total" style="text-align:right;font-weight:600" data-idx="<?= $idx ?>">
                            <?= number_format($lineTotal) ?>원
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- 배송 요청사항 -->
    <div class="est-section">
        <div class="est-section-title">요청 정보</div>
        <div class="card" style="padding:16px;display:grid;grid-template-columns:1fr 1fr;gap:12px">
            <div>
                <div style="font-size:11px;color:#888;margin-bottom:3px">납품지</div>
                <div style="font-size:13px;font-weight:600"><?= $h($estimate['site_address'] ?? '-') ?></div>
            </div>
            <div>
                <div style="font-size:11px;color:#888;margin-bottom:3px">희망 납품일</div>
                <div style="font-size:13px;font-weight:600"><?= $h($estimate['requested_date'] ?? '-') ?></div>
            </div>
            <?php if ($estimate['memo']): ?>
            <div style="grid-column:span 2">
                <div style="font-size:11px;color:#888;margin-bottom:3px">고객 메모</div>
                <div style="font-size:13px;background:#f8f9fb;padding:10px;border-radius:6px"><?= $h($estimate['memo']) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 직원 메모 -->
    <div class="est-section">
        <div class="est-section-title">직원 메모 (내부용)</div>
        <?php if ($isEditable): ?>
        <textarea class="memo-area" id="staff-memo" placeholder="내부 메모 (고객에게 발송되지 않습니다)"><?= $h($estimate['staff_memo'] ?? '') ?></textarea>
        <?php else: ?>
        <div style="background:#f8f9fb;padding:12px;border-radius:6px;font-size:13px;min-height:60px">
            <?= nl2br($h($estimate['staff_memo'] ?? '')) ?: '<span style="color:#aaa">메모 없음</span>' ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- 오른쪽: 패널 -->
<div class="side-panel">
    <!-- 고객 정보 -->
    <div class="info-box">
        <div class="info-box-title">고객 정보</div>
        <div class="info-row">
            <span class="info-label">고객명</span>
            <span class="info-value"><?= $h($estimate['customer_name']) ?></span>
        </div>
        <div class="info-row">
            <span class="info-label">연락처</span>
            <span class="info-value"><?= $h($estimate['phone']) ?></span>
        </div>
        <?php if ($estimate['company_name']): ?>
        <div class="info-row">
            <span class="info-label">회사명</span>
            <span class="info-value"><?= $h($estimate['company_name']) ?></span>
        </div>
        <?php endif; ?>
        <?php if ($estimate['email']): ?>
        <div class="info-row">
            <span class="info-label">이메일</span>
            <span class="info-value" style="font-size:11px"><?= $h($estimate['email']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- 담당 직원 -->
    <div class="info-box">
        <div class="info-box-title">담당 직원</div>
        <div class="info-row">
            <span class="info-label">담당자</span>
            <span class="info-value"><?= $h($estimate['staff_name'] ?? '미배정') ?></span>
        </div>
    </div>

    <!-- 견적 금액 -->
    <div class="info-box">
        <div class="info-box-title">견적 금액</div>
        <?php if ($isEditable): ?>
        <div class="fee-field">
            <label>운임비</label>
            <input type="number" id="shipping-fee" value="<?= (int)($estimate['shipping_fee'] ?? 0) ?>" min="0">
        </div>
        <div class="fee-field">
            <label>할인금액</label>
            <input type="number" id="discount-amt" value="<?= (int)($estimate['discount_amt'] ?? 0) ?>" min="0">
        </div>
        <?php else: ?>
        <div class="total-row">
            <span style="color:#888">운임비</span>
            <span><?= number_format((int)($estimate['shipping_fee'] ?? 0)) ?>원</span>
        </div>
        <div class="total-row">
            <span style="color:#888">할인금액</span>
            <span style="color:#c62828">-<?= number_format((int)($estimate['discount_amt'] ?? 0)) ?>원</span>
        </div>
        <?php endif; ?>
        <div class="total-row" style="padding-top:8px;border-top:1px solid #e8eaed;margin-top:4px">
            <span style="color:#888">상품 소계</span>
            <span id="subtotal-display"><?= number_format($subtotal) ?>원</span>
        </div>
        <div class="total-row grand">
            <span>합계</span>
            <span id="total-display"><?= number_format($total) ?>원</span>
        </div>
    </div>

    <!-- 액션 버튼 -->
    <div class="info-box" style="padding:14px">
        <?php if ($isEditable): ?>
        <div class="action-bar" style="flex-direction:column">
            <button class="btn btn-secondary" style="width:100%;height:36px" onclick="saveEstimate()">저장</button>
            <button class="btn btn-primary" style="width:100%;height:36px;margin-top:6px" onclick="sendEstimate()">견적서 발송</button>
        </div>
        <?php elseif ($estimate['status'] === 'SENT'): ?>
        <div class="action-bar" style="flex-direction:column">
            <button class="btn btn-success" style="width:100%;height:36px" onclick="changeStatus('approve')">완료 처리</button>
            <button class="btn btn-secondary" style="width:100%;height:36px;margin-top:6px" onclick="changeStatus('cancel')">취소</button>
        </div>
        <?php else: ?>
        <div style="font-size:12px;color:#aaa;text-align:center">이 견적은 수정할 수 없습니다.</div>
        <?php endif; ?>
    </div>
</div>
</div><!-- /.est-layout -->

<script>
const ESTIMATE_ID = <?= $id ?>;

// 品목 데이터 수집
function collectItems() {
    const rows = document.querySelectorAll('#item-tbody tr[data-item-id]');
    return Array.from(rows).map(row => {
        const id      = parseInt(row.dataset.itemId);
        const adjInp  = row.querySelector('.adj-price');
        const palInp  = row.querySelector('input[name^="pallet"]');
        const wgtInp  = row.querySelector('input[name^="weight"]');
        return {
            id,
            adjusted_price: adjInp  ? (adjInp.value  !== '' ? adjInp.value  : null) : null,
            pallet_cnt:     palInp  ? parseInt(palInp.value)  || 0 : 0,
            weight_kg:      wgtInp  ? parseInt(wgtInp.value)  || 0 : 0,
        };
    });
}

async function saveEstimate() {
    const body = {
        id:           ESTIMATE_ID,
        action:       'save',
        staff_memo:   document.getElementById('staff-memo')?.value || '',
        shipping_fee: parseInt(document.getElementById('shipping-fee')?.value) || 0,
        discount_amt: parseInt(document.getElementById('discount-amt')?.value) || 0,
        items:        collectItems(),
    };
    const res = await fetch('/api/staff/estimates.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify(body),
    });
    const data = await res.json();
    if (data.ok) {
        showToast('저장되었습니다.');
        recalcTotal();
    } else {
        alert('저장 실패: ' + data.error);
    }
}

async function sendEstimate() {
    if (!confirm('견적서를 발송 처리하시겠습니까?\n발송 후에는 수정이 불가합니다.')) return;
    await saveEstimate();
    const res = await fetch('/api/staff/estimates.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id: ESTIMATE_ID, action: 'send' }),
    });
    const data = await res.json();
    if (data.ok) {
        showToast('견적서가 발송 처리되었습니다.');
        setTimeout(() => location.href = '/staff/index.php?tab=SENT', 1000);
    } else {
        alert('오류: ' + data.error);
    }
}

async function changeStatus(action) {
    const labels = { approve: '완료 처리', cancel: '취소 처리' };
    if (!confirm(`${labels[action] || action}하시겠습니까?`)) return;
    const res = await fetch('/api/staff/estimates.php', {
        method: 'POST',
        headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ id: ESTIMATE_ID, action }),
    });
    const data = await res.json();
    if (data.ok) {
        showToast('처리되었습니다.');
        setTimeout(() => location.reload(), 800);
    } else {
        alert('오류: ' + data.error);
    }
}

// 합계 재계산
function recalcTotal() {
    const rows = document.querySelectorAll('#item-tbody tr[data-item-id]');
    let subtotal = 0;
    rows.forEach(row => {
        const adjInp = row.querySelector('.adj-price');
        const qty    = parseInt(row.querySelector('td:nth-child(5)')?.textContent) || 0;
        // 원래 단가(요청단가)
        const reqText = row.querySelector('td:nth-child(3)')?.textContent.replace(/[^0-9]/g,'') || '0';
        const price   = adjInp && adjInp.value !== '' ? parseInt(adjInp.value)||0 : parseInt(reqText)||0;
        subtotal += price * qty;
        const lnEl = row.querySelector('.line-total');
        if (lnEl) lnEl.textContent = price.toLocaleString() + '원';
    });
    const sf = parseInt(document.getElementById('shipping-fee')?.value) || 0;
    const da = parseInt(document.getElementById('discount-amt')?.value) || 0;
    const total = subtotal + sf - da;
    const sub = document.getElementById('subtotal-display');
    const tot = document.getElementById('total-display');
    if (sub) sub.textContent = subtotal.toLocaleString() + '원';
    if (tot) tot.textContent = total.toLocaleString() + '원';
}

// 실시간 합계 재계산
document.querySelectorAll('.adj-price, #shipping-fee, #discount-amt').forEach(el => {
    el.addEventListener('input', recalcTotal);
});

function showToast(msg) {
    const t = document.createElement('div');
    t.textContent = msg;
    t.style.cssText = 'position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#1a2035;color:#fff;padding:10px 20px;border-radius:8px;font-size:13px;z-index:9999;pointer-events:none;opacity:1;transition:opacity .4s';
    document.body.appendChild(t);
    setTimeout(() => { t.style.opacity = '0'; setTimeout(() => t.remove(), 400); }, 2000);
}
</script>

<?php render_staff_footer(); ?>
