<?php
declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/estimate.php';

require_once __DIR__ . '/../app/cart.php';
require __DIR__ . '/../app/layout.php';

$cart      = cart_session_read();
$cartItems = cart_fetch_items($cart);
$isQuick   = false;
if (empty($cartItems)) {
    $qi = $_SESSION['quick_items'] ?? [];
    if (!empty($qi)) {
        $cartItems = quick_items_fetch($qi);
        $isQuick   = true;
    }
}
[$cartCount, $cartTotal] = cart_summary_from_items($cartItems);
// '문의'(단가 0) 품목 포함 여부 — 금액에서 제외되므로 최종 금액이 확정 금액처럼 보이지 않게 표시
$hasInquiry = false;
foreach ($cartItems as $ci) {
    if ((int) $ci['unit_price'] <= 0) { $hasInquiry = true; break; }
}
$totalText = $cartTotal > 0 ? number_format($cartTotal).'원'.($hasInquiry ? ' + 문의' : '') : '담당자 문의';
$sum0      = estimate_amount_summary($cartTotal, null); // 초기 상태: 배송 지역 미선택 → 운임 미포함
// 수량 상한 = 저장 API 상한(장바구니 api/cart.php·즉시견적 quick-items.php 모두 999)
$qtyMax    = 999;
$user  = current_user();
$nowMin = date('Y-m-d\TH:i');

render_header('견적 요청');
?>
<style>
.est-wrap{max-width:960px;margin:0 auto;padding:32px 24px 80px}
.est-title{font-size:22px;font-weight:700;margin-bottom:4px}
.est-sub{font-size:13px;color:var(--color-text-secondary);margin-bottom:28px}

/* 품목 테이블 */
.est-items{width:100%;border-collapse:collapse;font-size:14px;margin-bottom:0}
.est-items th{background:var(--color-bg-secondary);padding:10px 14px;text-align:left;font-weight:600;border-bottom:2px solid var(--color-border)}
.est-items td{padding:10px 14px;border-bottom:1px solid var(--color-border);vertical-align:middle}
.est-items .num{text-align:right}
.est-items .qty-cell{display:flex;align-items:center;gap:6px;justify-content:center}
.est-items input.qty-input{width:52px;height:30px;border:1px solid var(--color-border);text-align:center;font-size:13px;font-family:var(--font)}
.est-items .remove-btn{background:none;border:none;cursor:pointer;color:var(--color-text-secondary);padding:2px 4px;font-size:16px;line-height:1}
.est-items .remove-btn:hover{color:#e53935}
.est-total-row{display:flex;justify-content:flex-end;align-items:center;gap:16px;padding:14px 14px;border-top:2px solid var(--color-border);background:var(--color-bg-secondary);font-size:15px;font-weight:700}
.est-total-row span:last-child{font-size:20px;color:var(--color-primary)}
.est-vat-row{display:flex;justify-content:flex-end;align-items:center;gap:16px;padding:8px 14px;background:var(--color-bg-secondary);font-size:13px;color:var(--color-text-secondary);border-top:1px solid var(--color-border)}
.est-final-row{display:flex;justify-content:flex-end;align-items:center;gap:16px;padding:14px 14px;background:var(--color-bg-secondary);font-size:15px;font-weight:700;border-top:2px solid var(--color-border);margin-bottom:32px}
.est-final-row span:last-child{font-size:20px;color:var(--color-primary)}
/* 배송 지역·예상 운임 */
.est-freight-row{display:flex;flex-direction:column;align-items:flex-end;gap:6px;padding:12px 14px;background:var(--color-bg-secondary);border-top:1px solid var(--color-border)}
.est-freight-grid{display:grid;grid-template-columns:auto 220px;align-items:center;column-gap:16px;row-gap:8px;font-size:14px;font-weight:700}
.est-freight-grid label{font-weight:700;color:var(--color-text-primary)}
.est-freight-grid select{height:40px;padding:0 10px;border:1px solid var(--color-border);border-radius:0;background:#fff;font-size:13px;font-family:var(--font);color:var(--color-text-primary);cursor:pointer}
.est-freight-grid select:focus{outline:none;border-color:var(--color-primary)}
.est-freight-val{text-align:right;font-size:15px}
.est-freight-val.is-pending{font-size:13px;font-weight:600;color:var(--color-text-secondary)}
.est-freight-note{margin:0;font-size:12px;color:var(--color-text-secondary);text-align:right;line-height:1.5}
.est-vat-row small{font-size:12px;font-weight:400}
.est-final-row .est-amt-suffix{font-size:13px;font-weight:600;color:var(--color-text-secondary)}
.est-final-note{text-align:right;font-size:12px;color:var(--color-text-secondary);padding:6px 14px 0;line-height:1.6}
@media (max-width:600px){
  .est-freight-row{align-items:stretch}
  .est-freight-grid{grid-template-columns:auto minmax(0,1fr)}
  .est-freight-grid select{height:44px;font-size:14px}
  .est-freight-note,.est-final-note{text-align:left}
  .est-final-row{flex-wrap:wrap;gap:4px 16px}
  .est-total-row span:last-child,.est-vat-row span:last-child,.est-final-row span:last-child,.est-freight-val{white-space:nowrap}
  .est-vat-row small{display:block}
}
.est-empty{padding:40px 0;text-align:center;color:var(--color-text-secondary);font-size:14px;border:1px solid var(--color-border);margin-bottom:32px}

/* 폼 */
.est-form-title{font-size:16px;font-weight:700;margin-bottom:16px;padding-bottom:8px;border-bottom:2px solid var(--color-border)}
.form-card{display:flex;flex-direction:column;gap:14px}
.form-card label{display:flex;flex-direction:column;gap:5px;font-size:13px;font-weight:600;color:var(--color-text-secondary)}
.form-card input,.form-card textarea{padding:10px 12px;border:1px solid var(--color-border);font-family:var(--font);font-size:13px;color:var(--color-text-primary);outline:none;background:#fff}
.form-card input:focus,.form-card textarea:focus{border-color:var(--color-primary)}
.form-2col{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.delivery-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.delivery-row input[type=date],.delivery-row input[type=time]{color-scheme:light;border:1px solid var(--color-border);border-radius:0;padding:8px 10px;font-size:13px;font-family:var(--font)}
.undecided-label{display:flex;align-items:center;gap:6px;font-size:12px;color:var(--color-text-secondary);cursor:pointer;white-space:nowrap;font-weight:400}

/* 인쇄 버튼 */
.btn-print{display:inline-flex;align-items:center;gap:6px;height:36px;padding:0 16px;background:#fff;border:1px solid var(--color-border);font-size:13px;font-family:var(--font);cursor:pointer;color:var(--color-text-secondary)}
.btn-print:hover{background:#fff;border-color:var(--color-text-primary);color:var(--color-text-primary)}
/* 인쇄 전용 견적서 */
#print-view{display:none}
@media print{
  @page{margin:1.2cm}
  header,nav,footer,.no-print,.est-wrap{display:none!important}
  #print-view{display:block!important;font-family:'Malgun Gothic','맑은 고딕',sans-serif;color:#000}
}
.pv-title{font-size:38px;font-weight:700;letter-spacing:-2px;text-align:right;padding:8px 0}
.pv-meta{display:flex;justify-content:space-between;font-size:13px;color:#555;margin-bottom:12px}
.pv-parties{display:grid;grid-template-columns:1fr auto;gap:0;align-items:center;margin-bottom:0}
.pv-party{font-size:12px}
.pv-party-row{display:flex;gap:8px;padding:5px 0;overflow:hidden;background:linear-gradient(to right,#eee 50%,transparent 50%) no-repeat bottom/100% 1px}
.pv-party-label{font-weight:700;width:68px;flex-shrink:0;white-space:nowrap}
.pv-party-val{color:#333;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pv-line-thick{border:none;border-top:3px solid #000;margin:10px 0 0}
.pv-line{border:none;border-top:1px solid #aaa;margin:0}
.pv-table{width:100%;border-collapse:collapse;font-size:10px;margin-top:0}
.pv-table th{background:#f7f7f7;padding:5px 7px;font-weight:700;border-bottom:1px solid #aaa;text-align:center}
.pv-table td{padding:5px 7px;border-bottom:1px solid #ddd;text-align:center}
.pv-table td.left{text-align:left;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:200px}
.pv-table td.right{text-align:right;white-space:nowrap}
.pv-total-row{display:flex;justify-content:space-between;align-items:center;padding:12px 8px;border-top:3px solid #000;border-bottom:none;font-size:15px;font-weight:700;margin-top:0}
.pv-vat-row{display:flex;justify-content:space-between;align-items:center;padding:6px 8px;font-size:12px;color:#666;border-bottom:1px solid #bbb}
.pv-final-row{display:flex;justify-content:space-between;align-items:center;padding:12px 8px;font-size:15px;font-weight:700;background:#f4f4f4;border-top:2px solid #000;border-bottom:2px solid #000;margin-bottom:0}
.pv-vat-row small{font-size:10px}
.pv-final-row small{font-size:11px;font-weight:400;color:#555}
.pv-note{text-align:right;font-size:10px;color:#888;padding:4px 8px 0;line-height:1.6}
.pv-footer{display:flex;flex-direction:column;gap:16px;margin-top:20px;font-size:12px;color:#555}
.pv-footer-title{font-size:15px;font-weight:700;color:#222;margin-bottom:6px}
.pv-notice-list{margin:0;padding-left:16px;display:flex;flex-direction:column;gap:3px}

/* 신청 버튼 */
.btn-submit-wrap{margin-top:28px}
.btn-submit{width:100%;height:56px;background:#111;color:#fff;border:none;font-size:16px;font-family:var(--font);font-weight:700;cursor:pointer;letter-spacing:-0.3px}
.btn-submit:hover{background:#333}
.btn-submit:disabled{opacity:.4;cursor:not-allowed}

/* 결과 */
.result-box{padding:32px;border:1px solid #aed581;background:#f9fbe7;font-size:15px;text-align:center;line-height:1.8}
.result-box a{color:var(--color-primary);margin-top:12px;display:inline-block}
.error-box{padding:12px 16px;border:1px solid #ef9a9a;background:#fff5f5;font-size:13px;color:#c62828;margin-top:12px}
</style>

<div class="est-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px">
        <div class="est-title">견적 요청</div>
        <button class="btn-print no-print" onclick="doPrint()">
            <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
            인쇄하기
        </button>
    </div>
    <p class="est-sub">담당자가 확인 후 연락드립니다.</p>

    <div id="result-box" class="result-box" style="display:none">
        견적 요청이 접수되었습니다.<span id="result-no"></span><br>담당자가 확인 후 영업일 기준 1~2일 내에 연락드립니다.<br>
        <?php if ($user): ?><a href="/my-estimates.php">내 견적 보기</a> · <?php endif; ?><a href="/index.php">← 메인으로 돌아가기</a>
    </div>

    <form id="est-form" onsubmit="submitEst(event)">
        <!-- ① 품목 목록 -->
        <?php if ($cartItems): ?>
        <table class="est-items" id="items-table">
            <thead>
                <tr>
                    <th>제품명</th>
                    <th>규격</th>
                    <th style="text-align:center;width:90px">수량</th>
                    <th style="text-align:right;width:110px">단가</th>
                    <th style="text-align:right;width:120px">소계</th>
                    <th style="width:36px"></th>
                </tr>
            </thead>
            <tbody id="items-body">
            <?php foreach ($cartItems as $item): ?>
            <?php $sp = parse_spec_field($item['spec'] ?? ''); ?>
            <tr data-pid="<?= (int)$item['product_id'] ?>" data-price="<?= (int)$item['unit_price'] ?>" data-spec="<?= e($sp['spec']) ?>" data-synced="<?= (int)$item['quantity'] ?>">
                <td><?= e($item['name']) ?></td>
                <td style="font-size:12px;color:#555"><?= e($sp['spec']) ?></td>
                <td>
                    <div class="qty-cell">
                        <input class="qty-input" type="number" value="<?= (int)$item['quantity'] ?>" min="1" max="<?= $qtyMax ?>"
                               aria-label="<?= e($item['name']) ?> 수량" onchange="updateSubtotal(this)">
                    </div>
                </td>
                <td class="num"><?= $item['unit_price'] > 0 ? number_format((int)$item['unit_price']).'원' : '문의' ?></td>
                <td class="num subtotal-cell"><?= $item['unit_price'] > 0 ? number_format((int)$item['subtotal']).'원' : '문의' ?></td>
                <td><button type="button" class="remove-btn" onclick="removeRow(this)" title="삭제">×</button></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="est-total-row">
            <span>상품 합계 (부가세 별도)</span>
            <span id="total-display"><?= $totalText ?></span>
        </div>
        <div class="est-freight-row">
            <div class="est-freight-grid">
                <label for="ship-region">배송 지역</label>
                <select id="ship-region" onchange="refreshFreight(0)">
                    <option value="">지역 선택</option>
                    <?php foreach (array_keys(FREIGHT_SIDO_COORDS) as $sido): ?><option value="<?= e($sido) ?>"><?= e($sido) ?></option><?php endforeach; ?>
                </select>
                <span>예상 운임비</span>
                <span id="freight-display" class="est-freight-val is-pending" aria-live="polite">지역 선택 시 산출</span>
            </div>
            <p id="freight-note" class="est-freight-note">배송 지역 선택에 따라 운임비가 달라집니다.</p>
        </div>
        <div class="est-vat-row">
            <span>부가세 (10%) <small id="vat-basis">(상품 합계 기준, 운임 제외)</small></span>
            <span id="vat-display"><?= $cartTotal > 0 ? number_format($sum0['vat']).'원' : '-' ?></span>
        </div>
        <div class="est-final-row">
            <span>최종 예상 결제금액</span>
            <span><span id="final-display"><?= $cartTotal > 0 ? number_format($sum0['final']).'원' : '담당자 문의' ?></span><small id="final-suffix" class="est-amt-suffix"><?= $cartTotal > 0 ? ' + 운임 별도'.($hasInquiry ? ' + 문의 품목' : '') : '' ?></small></span>
        </div>
        <div id="final-note" class="est-final-note">※ 예상 운임비는 화물 시세 기준 참고 금액(부가세 별도·지게차 상하차·편도)이며, 실제 운임은 담당자 확인 후 확정됩니다.</div>
        <?php else: ?>
        <div class="est-empty">
            견적에 담긴 품목이 없습니다.<br>
            <a href="/index.php" style="color:var(--color-primary);margin-top:8px;display:inline-block">제품 보러가기 →</a>
        </div>
        <?php endif; ?>

        <!-- ② 신청 정보 -->
        <div class="est-form-title">신청 정보</div>
        <div id="error-box" class="error-box" style="display:none"></div>

        <div class="form-card">
            <div class="form-2col">
                <label>담당자명 *
                    <input name="customer_name" required value="<?= e($user['name'] ?? '') ?>" placeholder="홍길동">
                </label>
                <label>연락처 *
                    <input name="phone" required placeholder="010-0000-0000">
                </label>
            </div>
            <div class="form-2col">
                <label>회사명
                    <input name="company_name" value="<?= e($user['company_name'] ?? '') ?>" placeholder="(선택)">
                </label>
                <label>이메일
                    <input type="email" name="email" value="<?= e($user['email'] ?? '') ?>" placeholder="(선택)">
                </label>
            </div>
            <label>현장 주소
                <input name="site_address" placeholder="납품지 주소">
            </label>
            <label>납품 희망 일시
                <div class="delivery-row">
                    <div style="display:flex;gap:6px;flex-shrink:0">
                        <input type="date" id="delivery-date" min="<?= date('Y-m-d') ?>">
                        <input type="time" id="delivery-time" value="09:00">
                    </div>
                    <label class="undecided-label">
                        <input type="checkbox" id="delivery-undecided" onchange="toggleDelivery(this)"> 일시 미정
                    </label>
                </div>
            </label>
            <label>요청사항
                <textarea name="memo" rows="4" placeholder="현장 특이사항, 수량·규격 등 추가 요청사항을 입력해 주세요."></textarea>
            </label>
        </div>

        <div class="btn-submit-wrap">
            <button type="submit" class="btn-submit" id="btn-submit">담당자에게 신청하기</button>
        </div>
    </form>
</div>

<script>
var IS_QUICK = <?= $isQuick ? 'true' : 'false' ?>;
// 수량 저장 API — 화면 수량을 세션에 반영해야 실제 신청 수량과 일치한다(quote.php 는 세션 품목 기준 저장)
var QTY_API = IS_QUICK ? '/api/estimate/quick-items.php' : '/api/cart.php';
var QTY_MAX = <?= $qtyMax ?>;

function toggleDelivery(cb) {
    document.getElementById('delivery-date').disabled = cb.checked;
    document.getElementById('delivery-time').disabled = cb.checked;
}

function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function itemRows() { return Array.from(document.querySelectorAll('#items-body tr')); }
function rowQty(row) { return Math.max(1, parseInt(row.querySelector('.qty-input')?.value) || 1); }

// ── 수량·삭제 저장 (요청 순서 보장: 하나의 체인으로 직렬화) ─────────────────
var syncChain = Promise.resolve();
var syncError = '';

function postItem(body) {
    return fetch(QTY_API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(body)
    }).then(r => r.json().catch(() => ({})).then(d => {
        if (!r.ok || !d.ok) throw new Error(d.error || '변경한 내용을 저장하지 못했습니다.');
        return d;
    }));
}

function showSyncError(msg) {
    const errBox = document.getElementById('error-box');
    errBox.textContent = msg + ' 화면을 새로고침한 뒤 다시 시도해 주세요.';
    errBox.style.display = 'block';
}

function queueQty(row, qty) {
    syncChain = syncChain
        .then(() => postItem({ action: 'update', product_id: parseInt(row.dataset.pid), quantity: qty }))
        .then(() => { row.dataset.synced = String(qty); })
        .catch(e => { syncError = e.message; showSyncError(e.message); });
    return syncChain;
}

function queueRemove(pid) {
    syncChain = syncChain
        .then(() => postItem({ action: 'remove', product_id: pid }))
        .catch(e => { syncError = e.message; showSyncError(e.message); });
    return syncChain;
}

// 신청 직전: 진행 중인 저장을 기다리고, 저장 안 된 수량이 있으면 다시 저장 후 일치 여부 확인
async function ensureQtySynced() {
    syncError = '';
    await syncChain;
    for (const row of itemRows()) {
        const q = rowQty(row);
        if (String(q) !== row.dataset.synced) await queueQty(row, q);
    }
    const unsynced = itemRows().some(row => String(rowQty(row)) !== row.dataset.synced);
    if (unsynced || syncError) {
        return { ok: false, error: (syncError || '변경한 수량을 저장하지 못했습니다.') + ' 화면을 새로고침한 뒤 다시 시도해 주세요.' };
    }
    return { ok: true };
}

function updateSubtotal(input) {
    const row   = input.closest('tr');
    const price = parseInt(row.dataset.price) || 0;
    const qty   = Math.min(QTY_MAX, Math.max(1, parseInt(input.value) || 1));
    input.value = qty;
    const cell  = row.querySelector('.subtotal-cell');
    if (price > 0) cell.textContent = (price * qty).toLocaleString() + '원';
    recalcTotal();
    refreshFreight(300);
    if (String(qty) !== row.dataset.synced) queueQty(row, qty);
}

// ── 예상 운임 (/api/freight-cart.php — 품목 총중량으로 1회 배차) ─────────────
// FR.state: empty(품목 없음) | none(지역 미선택) | loading | ok | missing(중량 없는 품목) | negotiate(제주·장거리) | error
var FR = { state: 'none' };
var frSeq = 0, frCtrl = null, frTimer = null, frPromise = Promise.resolve();

function refreshFreight(delay) {
    const sel  = document.getElementById('ship-region');
    const dest = sel ? sel.value : '';
    const rows = itemRows();
    const seq  = ++frSeq;
    if (frCtrl) frCtrl.abort();
    clearTimeout(frTimer);
    if (!rows.length) { FR = { state: 'empty' }; recalcTotal(); return frPromise = Promise.resolve(); }
    if (!dest)        { FR = { state: 'none' };  recalcTotal(); return frPromise = Promise.resolve(); }
    FR = { state: 'loading', dest };
    recalcTotal();
    const q = rows.map(row => parseInt(row.dataset.pid) + ':' + rowQty(row)).join(',');
    frPromise = new Promise(resolve => {
        frTimer = setTimeout(() => {
            frCtrl = new AbortController();
            fetch('/api/freight-cart.php?dest=' + encodeURIComponent(dest) + '&items=' + encodeURIComponent(q), { signal: frCtrl.signal })
                .then(r => r.json())
                .then(d => {
                    if (seq !== frSeq) return;
                    if (!d.ok)                            FR = { state: 'error', dest, msg: d.error || '' };
                    else if (d.status === 'ok')           FR = { state: 'ok', dest, amount: parseInt(d.freight) || 0, data: d };
                    else if (d.status === 'missing_weight') FR = { state: 'missing', dest, names: d.missing_names || [], count: d.missing || 0 };
                    else                                  FR = { state: 'negotiate', dest };
                })
                .catch(e => { if (e.name !== 'AbortError' && seq === frSeq) FR = { state: 'error', dest, msg: '' }; })
                .finally(() => { if (seq === frSeq) { recalcTotal(); resolve(); } });
        }, delay || 0);
    });
    return frPromise;
}

function fmtWon(n, unit) { return n.toLocaleString() + unit; }

function fmtKg(kg) {
    return kg >= 1000 ? (Math.round(kg / 100) / 10).toLocaleString() + '톤' : Math.round(kg).toLocaleString() + 'kg';
}

// 금액 요약 — app/estimate.php estimate_amount_summary() 와 같은 공식(정수 연산)
// 과세 대상 = 상품 합계 + 예상 운임, 부가세 = round(과세 대상 × 10%), 최종 = 과세 대상 + 부가세
function estSummary(goods, freight) {
    const taxable = Math.max(0, goods) + Math.max(0, freight === null ? 0 : freight);
    const vat     = Math.floor((taxable + 5) / 10);
    return { taxable, vat, final: taxable + vat, freightIncluded: freight !== null };
}

// 화면·인쇄 공용 금액 문구 — 같은 상태(FR + 현재 수량)에서 같은 값을 만든다
function amountTexts(unit) {
    let goods = 0, hasInquiry = false;
    itemRows().forEach(row => {
        const price = parseInt(row.dataset.price) || 0;
        if (price > 0) goods += price * rowQty(row); else hasInquiry = true;
    });
    const fOk = FR.state === 'ok';
    const s   = estSummary(goods, fOk ? FR.amount : null);
    const t   = { region: FR.dest || '미선택', freightPending: !fOk, notes: [] };

    t.total = goods > 0 ? fmtWon(goods, unit) + (hasInquiry ? ' + 문의' : '') : '담당자 문의';
    t.freight = {
        empty:     '-',
        none:      '지역 선택 시 산출',
        loading:   '계산 중…',
        ok:        fOk ? fmtWon(FR.amount, unit) : '',
        missing:   '운임 별도 확인 필요',
        negotiate: '별도 협의',
        error:     '운임 별도 확인 필요'
    }[FR.state];
    if (FR.state === 'ok') {
        const d  = FR.data;
        t.freightNote = (d.origins || []).join('·') + '→' + d.dest + ' 약 ' + Math.round(d.km) + 'km · 총중량 ' + fmtKg(d.total_kg);
    } else if (FR.state === 'missing') {
        const n = FR.names;
        t.freightNote = '중량 정보가 없는 품목(' + (n[0] || '') + (n.length > 1 ? ' 외 ' + (n.length - 1) + '개' : '') + ')이 있어 전체 운임을 산출할 수 없습니다. 담당자 확인 후 안내드립니다.';
    } else if (FR.state === 'negotiate') {
        t.freightNote = '제주 등 육로 운임표로 산출할 수 없는 지역은 담당자와 별도 협의가 필요합니다.';
    } else if (FR.state === 'error') {
        t.freightNote = (FR.msg || '운임을 계산하지 못했습니다.') + ' 담당자 확인 후 안내드립니다.';
    } else {
        t.freightNote = '배송 지역 선택에 따라 운임비가 달라집니다.';
    }

    t.vatBasis = fOk ? '(상품 합계 + 예상 운임비)' : '(상품 합계 기준, 운임 제외)';
    t.vat      = goods > 0 ? fmtWon(s.vat, unit) : '-';
    t.final    = goods > 0 ? fmtWon(s.final, unit) : '담당자 문의';
    t.finalSuffix = goods > 0 ? (fOk ? '' : ' + 운임 별도') + (hasInquiry ? ' + 문의 품목' : '') : '';

    t.notes.push('※ 예상 운임비는 화물 시세 기준 참고 금액(부가세 별도·지게차 상하차·편도)이며, 실제 운임은 담당자 확인 후 확정됩니다.');
    if (goods > 0 && !fOk) t.notes.push('※ 최종 예상 결제금액에 운임이 포함되지 않았습니다.');
    if (hasInquiry)        t.notes.push("※ '문의' 품목은 금액에 포함되지 않았으며, 담당자가 별도로 안내드립니다.");
    return t;
}

function setNotes(el, notes) {
    el.textContent = '';
    notes.forEach(n => { const d = document.createElement('div'); d.textContent = n; el.appendChild(d); });
}

function recalcTotal() {
    if (!document.getElementById('total-display')) return;
    const t = amountTexts('원');
    document.getElementById('total-display').textContent = t.total;
    const fd = document.getElementById('freight-display');
    fd.textContent = t.freight;
    fd.classList.toggle('is-pending', t.freightPending);
    document.getElementById('freight-note').textContent = t.freightNote;
    document.getElementById('vat-basis').textContent    = t.vatBasis;
    document.getElementById('vat-display').textContent  = t.vat;
    document.getElementById('final-display').textContent = t.final;
    document.getElementById('final-suffix').textContent  = t.finalSuffix;
    setNotes(document.getElementById('final-note'), t.notes);
}

function removeRow(btn) {
    const row = btn.closest('tr');
    const pid = parseInt(row.dataset.pid);
    row.remove();
    recalcTotal();
    refreshFreight(0);
    if (pid) queueRemove(pid);
}

async function submitEst(e) {
    e.preventDefault();
    const errBox = document.getElementById('error-box');
    const btn    = document.getElementById('btn-submit');
    errBox.style.display = 'none';

    const failSubmit = (msg) => {
        errBox.textContent = msg;
        errBox.style.display = 'block';
        errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        btn.disabled = false;
        btn.textContent = '담당자에게 신청하기';
    };

    btn.disabled = true;
    btn.textContent = '수량 확인 중...';

    // 화면 수량 = 저장 수량 보장 (서버도 items 와 세션 품목을 다시 대조한다)
    const synced = await ensureQtySynced();
    if (!synced.ok) { failSubmit(synced.error); return; }

    // 품목 수집
    const items = [];
    itemRows().forEach(row => {
        const pid = parseInt(row.dataset.pid);
        if (pid) items.push({ product_id: pid, quantity: rowQty(row) });
    });

    const fd = new FormData(document.getElementById('est-form'));
    const undecided = document.getElementById('delivery-undecided').checked;
    const body = {
        customer_name: fd.get('customer_name'),
        company_name:  fd.get('company_name') || '',
        phone:         fd.get('phone'),
        email:         fd.get('email') || '',
        site_address:  fd.get('site_address') || '',
        memo:          fd.get('memo') || '',
        requested_delivery_at: undecided ? null : (() => { const d = document.getElementById('delivery-date').value; const t = document.getElementById('delivery-time').value; return d ? `${d}T${t||'00:00'}:00` : null; })(),
        delivery_undecided: undecided,
        items,
        mode: <?= $isQuick ? "'quick'" : "'chat'" ?>,
        sid: ''
    };

    btn.textContent = '접수 중...';

    try {
        const r = await fetch('/quote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const d = await r.json();
        if (d.ok) {
            if (d.estimate_id) { document.getElementById('result-no').textContent = ' (접수 번호 ' + parseInt(d.estimate_id, 10) + ')'; }
            document.getElementById('est-form').style.display = 'none';
            document.getElementById('result-box').style.display = 'block';
            document.getElementById('result-box').scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            failSubmit(d.error || '오류가 발생했습니다.');
        }
    } catch {
        failSubmit('네트워크 오류가 발생했습니다.');
    }
}

async function doPrint() {
    // 운임 계산 중이면 결과를 기다린 뒤 인쇄 (화면·인쇄 금액 일치)
    await frPromise;
    // 폼 데이터 → 인쇄 뷰에 반영
    const fd = new FormData(document.getElementById('est-form'));
    document.getElementById('pv-company').textContent  = fd.get('company_name') || '-';
    document.getElementById('pv-buyer').textContent    = fd.get('customer_name') || '-';
    document.getElementById('pv-phone').textContent    = fd.get('phone') || '-';
    document.getElementById('pv-address').textContent  = fd.get('site_address') || '-';
    const d = document.getElementById('delivery-date').value;
    const t = document.getElementById('delivery-time').value;
    document.getElementById('pv-delivery').textContent = d ? `${d} ${t||''}`.trim() : '미정';
    // 품목 동기화
    const tbody = document.getElementById('pv-tbody');
    let html = '';
    itemRows().forEach((row, i) => {
        const name  = row.querySelector('td:first-child')?.textContent.trim() || '';
        const spec  = row.dataset.spec || '-';
        const qty   = rowQty(row);
        const price = parseInt(row.dataset.price) || 0;
        const sub   = price * qty;
        html += `<tr>
            <td>${i+1}</td>
            <td class="left">${esc(name)}</td>
            <td class="left">${esc(spec)}</td>
            <td>${qty}</td>
            <td class="right">${price>0?price.toLocaleString()+' 원':'-'}</td>
            <td class="right">${sub>0?sub.toLocaleString()+' 원':'-'}</td>
        </tr>`;
    });
    tbody.innerHTML = html;
    const a = amountTexts(' 원');
    document.getElementById('pv-total').textContent       = a.total;
    document.getElementById('pv-region').textContent      = a.region;
    document.getElementById('pv-freight').textContent     = a.freight;
    document.getElementById('pv-vat-basis').textContent   = a.vatBasis;
    document.getElementById('pv-vat').textContent         = a.vat;
    document.getElementById('pv-final').textContent       = a.final;
    document.getElementById('pv-final-suffix').textContent = a.finalSuffix;
    setNotes(document.getElementById('pv-note'), a.notes);
    window.print();
}

recalcTotal();
</script>

<!-- 인쇄 전용 견적서 -->
<div id="print-view">
    <div class="pv-meta">
        <span>견적NO: EST-<?= date('Ymd') ?>-<?= str_pad((string)rand(1,999),3,'0',STR_PAD_LEFT) ?></span>
        <span>견적일: <?= date('Y.m.d') ?></span>
    </div>

    <div class="pv-parties">
        <div class="pv-party">
            <div class="pv-party-row"><span class="pv-party-label">회사명</span><span class="pv-party-val" id="pv-company">-</span></div>
            <div class="pv-party-row"><span class="pv-party-label">구매자명</span><span class="pv-party-val" id="pv-buyer">-</span></div>
            <div class="pv-party-row"><span class="pv-party-label">연락처</span><span class="pv-party-val" id="pv-phone">-</span></div>
            <div class="pv-party-row"><span class="pv-party-label">현장주소</span><span class="pv-party-val" id="pv-address">-</span></div>
            <div class="pv-party-row"><span class="pv-party-label">납품희망일</span><span class="pv-party-val" id="pv-delivery">-</span></div>
        </div>
        <div class="pv-title">견&nbsp;&nbsp;적&nbsp;&nbsp;서</div>
    </div>

    <hr class="pv-line-thick">
    <table class="pv-table">
        <thead>
            <tr>
                <th style="width:40px">No</th>
                <th>품목명</th>
                <th style="width:160px">규격</th>
                <th style="width:60px">수량</th>
                <th style="width:110px">단가</th>
                <th style="width:120px">금액</th>
            </tr>
        </thead>
        <tbody id="pv-tbody">
            <?php foreach ($cartItems as $i => $item):
                $osp = parse_spec_field($item['spec'] ?? '');
            ?>
            <tr>
                <td><?= $i+1 ?></td>
                <td class="left"><?= e($item['name']) ?></td>
                <td class="left"><?= e($osp['spec']) ?></td>
                <td><?= (int)$item['quantity'] ?></td>
                <td class="right"><?= $item['unit_price']>0 ? number_format((int)$item['unit_price']).' 원' : '-' ?></td>
                <td class="right"><?= $item['subtotal']>0 ? number_format((int)$item['subtotal']).' 원' : '-' ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="pv-total-row">
        <span>상품 합계 (부가세 별도)</span>
        <span id="pv-total"><?= $cartTotal > 0 ? number_format($cartTotal).' 원'.($hasInquiry ? ' + 문의' : '') : '담당자 문의' ?></span>
    </div>
    <div class="pv-vat-row">
        <span>배송 지역</span>
        <span id="pv-region">미선택</span>
    </div>
    <div class="pv-vat-row">
        <span>예상 운임비 (부가세 별도)</span>
        <span id="pv-freight">지역 선택 시 산출</span>
    </div>
    <div class="pv-vat-row">
        <span>부가세 (10%) <small id="pv-vat-basis">(상품 합계 기준, 운임 제외)</small></span>
        <span id="pv-vat"><?= $cartTotal > 0 ? number_format($sum0['vat']).' 원' : '-' ?></span>
    </div>
    <div class="pv-final-row">
        <span>최종 예상 결제금액 (부가세 포함)</span>
        <span><span id="pv-final"><?= $cartTotal > 0 ? number_format($sum0['final']).' 원' : '담당자 문의' ?></span><small id="pv-final-suffix"><?= $cartTotal > 0 ? ' + 운임 별도'.($hasInquiry ? ' + 문의 품목' : '') : '' ?></small></span>
    </div>
    <div id="pv-note" class="pv-note">※ 예상 운임비는 화물 시세 기준 참고 금액(부가세 별도·지게차 상하차·편도)이며, 실제 운임은 담당자 확인 후 확정됩니다.</div>

    <div class="pv-footer">
        <div style="display:flex;justify-content:space-between;align-items:flex-start">
            <div>
                <div class="pv-footer-title">견적시 유의사항</div>
                <ul class="pv-notice-list">
                    <li>본 견적서의 유효기간은 7일 입니다.</li>
                    <li>발주 전 재고 유무를 다시 확인하여 주시기 바랍니다.</li>
                    <li>발주는 입금 후 진행합니다.</li>
                </ul>
            </div>
            <div style="text-align:left;font-size:12px;color:#333;line-height:1.9;flex-shrink:0;margin-left:20px">
                회사명: <strong>BMI C&amp;S</strong><br>
                대표이사: 김동의<br>
                담당자 연락처: bmicns@gmail.com<br>
                전화번호: 010-8490-9271
            </div>
        </div>
        <div>
            <div class="pv-footer-title">입금은행</div>
            <p style="margin:0;color:#555">기업은행 &nbsp; 000-000000-00-000<br>예금주 BMI C&amp;S</p>
        </div>
    </div>
</div>

<?php render_footer(); ?>
