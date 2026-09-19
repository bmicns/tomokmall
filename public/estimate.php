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
        견적 요청이 접수되었습니다.<br>담당자가 확인 후 영업일 기준 1~2일 내에 연락드립니다.<br>
        <a href="/index.php">← 메인으로 돌아가기</a>
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
            <tr data-pid="<?= (int)$item['product_id'] ?>" data-price="<?= (int)$item['unit_price'] ?>" data-spec="<?= e($sp['spec']) ?>">
                <td><?= e($item['name']) ?></td>
                <td style="font-size:12px;color:#555"><?= e($sp['spec']) ?></td>
                <td>
                    <div class="qty-cell">
                        <input class="qty-input" type="number" value="<?= (int)$item['quantity'] ?>" min="1" max="9999"
                               onchange="updateSubtotal(this)">
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
            <span>합계 (부가세 별도)</span>
            <span id="total-display"><?= $cartTotal > 0 ? number_format($cartTotal).'원' : '담당자 문의' ?></span>
        </div>
        <div class="est-vat-row">
            <span>부가세 (10%)</span>
            <span id="vat-display"><?= $cartTotal > 0 ? number_format((int)round($cartTotal * 0.1)).'원' : '-' ?></span>
        </div>
        <div class="est-final-row">
            <span>최종 결제금액</span>
            <span id="final-display"><?= $cartTotal > 0 ? number_format((int)round($cartTotal * 1.1)).'원' : '담당자 문의' ?></span>
        </div>
        <div style="text-align:right;font-size:12px;color:var(--color-text-secondary);padding:6px 14px 0">※ 배송비는 별도 비용입니다.</div>
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

function toggleDelivery(cb) {
    document.getElementById('delivery-date').disabled = cb.checked;
    document.getElementById('delivery-time').disabled = cb.checked;
}

function updateSubtotal(input) {
    const row   = input.closest('tr');
    const price = parseInt(row.dataset.price) || 0;
    const qty   = Math.max(1, parseInt(input.value) || 1);
    input.value = qty;
    const cell  = row.querySelector('.subtotal-cell');
    if (price > 0) cell.textContent = (price * qty).toLocaleString() + '원';
    recalcTotal();
}

function recalcTotal() {
    let total = 0;
    let hasInquiry = false;
    document.querySelectorAll('#items-body tr').forEach(row => {
        const price = parseInt(row.dataset.price) || 0;
        const qty   = parseInt(row.querySelector('.qty-input')?.value) || 0;
        if (price > 0) total += price * qty;
        else hasInquiry = true;
    });
    const vat   = Math.round(total * 0.1);
    const final = total + vat;
    document.getElementById('total-display').textContent =
        total > 0 ? total.toLocaleString() + '원' + (hasInquiry ? ' + 문의' : '') : '담당자 문의';
    document.getElementById('vat-display').textContent =
        total > 0 ? vat.toLocaleString() + '원' : '-';
    document.getElementById('final-display').textContent =
        total > 0 ? final.toLocaleString() + '원' : '담당자 문의';
}

async function removeRow(btn) {
    const row = btn.closest('tr');
    const pid = parseInt(row.dataset.pid);
    row.remove();
    recalcTotal();
    if (pid) {
        const url = IS_QUICK ? '/api/estimate/quick-items.php' : '/api/cart.php';
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'remove', product_id: pid })
        });
    }
}

async function submitEst(e) {
    e.preventDefault();
    const errBox = document.getElementById('error-box');
    const btn    = document.getElementById('btn-submit');
    errBox.style.display = 'none';

    // 품목 수집
    const items = [];
    document.querySelectorAll('#items-body tr').forEach(row => {
        const pid = parseInt(row.dataset.pid);
        const qty = parseInt(row.querySelector('.qty-input')?.value) || 1;
        if (pid) items.push({ product_id: pid, quantity: qty });
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

    btn.disabled = true;
    btn.textContent = '접수 중...';

    try {
        const r = await fetch('/quote.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body)
        });
        const d = await r.json();
        if (d.ok) {
            document.getElementById('est-form').style.display = 'none';
            document.getElementById('result-box').style.display = 'block';
            document.getElementById('result-box').scrollIntoView({ behavior: 'smooth', block: 'center' });
        } else {
            errBox.textContent = d.error || '오류가 발생했습니다.';
            errBox.style.display = 'block';
            errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
            btn.disabled = false;
            btn.textContent = '담당자에게 신청하기';
        }
    } catch {
        errBox.textContent = '네트워크 오류가 발생했습니다.';
        errBox.style.display = 'block';
        errBox.scrollIntoView({ behavior: 'smooth', block: 'center' });
        btn.disabled = false;
        btn.textContent = '담당자에게 신청하기';
    }
}

function doPrint() {
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
    const rows = document.querySelectorAll('#items-body tr');
    const tbody = document.getElementById('pv-tbody');
    tbody.innerHTML = '';
    let total = 0;
    rows.forEach((row, i) => {
        const name  = row.querySelector('td:first-child')?.textContent.trim() || '';
        const spec  = row.dataset.spec || '-';
        const qty   = parseInt(row.querySelector('.qty-input')?.value) || 0;
        const price = parseInt(row.dataset.price) || 0;
        const sub   = price * qty;
        total += sub;
        tbody.innerHTML += `<tr>
            <td>${i+1}</td>
            <td class="left">${name}</td>
            <td class="left">${spec}</td>
            <td>${qty}</td>
            <td class="right">${price>0?price.toLocaleString()+' 원':'-'}</td>
            <td class="right">${sub>0?sub.toLocaleString()+' 원':'-'}</td>
        </tr>`;
    });
    const vat   = Math.round(total * 0.1);
    const final = total + vat;
    document.getElementById('pv-total').textContent = total > 0 ? total.toLocaleString() + ' 원' : '담당자 문의';
    document.getElementById('pv-vat').textContent   = total > 0 ? vat.toLocaleString() + ' 원' : '-';
    document.getElementById('pv-final').textContent = total > 0 ? final.toLocaleString() + ' 원' : '담당자 문의';
    window.print();
}
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
        <span>견적금액 합계 (부가세 별도)</span>
        <span id="pv-total"><?= $cartTotal > 0 ? number_format($cartTotal).' 원' : '담당자 문의' ?></span>
    </div>
    <div class="pv-vat-row">
        <span>부가세 (10%)</span>
        <span id="pv-vat"><?= $cartTotal > 0 ? number_format((int)round($cartTotal * 0.1)).' 원' : '-' ?></span>
    </div>
    <div class="pv-final-row">
        <span>최종 결제금액 (부가세 포함)</span>
        <span id="pv-final"><?= $cartTotal > 0 ? number_format((int)round($cartTotal * 1.1)).' 원' : '담당자 문의' ?></span>
    </div>
    <div style="text-align:right;font-size:10px;color:#888;padding:4px 8px 0">※ 배송비는 별도 비용입니다.</div>

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
