/**
 * 상담원 연결 유도 · 문의 요청 모달 (AI 상담 화면)
 * 의존: window.SID (chat_renderer.php), #consult-modal 마크업 (chat_renderer.php)
 * 호출: chat-ai.js processEvent('product_cards') → renderConsultCta(content)
 * 서버: POST /api/consult/request.php
 */

/** 상품 추천표 바로 아래 '상담원 연결 유도' 블록 */
function renderConsultCta(container) {
  if (!container || !document.getElementById('consult-modal')) return;
  const sec = document.createElement('section');
  sec.className = 'consult-cta';
  sec.setAttribute('aria-label', '상담원 연결');
  sec.innerHTML = `
    <div class="consult-cta-head">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#004EA1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 13a8 8 0 0 1 16 0"></path><rect x="3" y="13" width="4" height="6" rx="1.5"></rect><rect x="17" y="13" width="4" height="6" rx="1.5"></rect><path d="M19 19c0 1.5-2 2.5-5 2.5"></path></svg>
      <span class="consult-cta-title">어떤 제품이 맞는지 확신이 서지 않으세요?</span>
    </div>
    <p class="consult-cta-desc">상담원 문의를 남겨 주시면&nbsp;담당 상담원이 1:1로 자재 상담을 도와드립니다.</p>
    <button type="button" class="consult-cta-btn" aria-haspopup="dialog">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path></svg>
      <span>상담원 문의 요청</span>
    </button>`;
  sec.querySelector('.consult-cta-btn').addEventListener('click', (e) => openConsultModal(e.currentTarget));
  container.appendChild(sec);
}

(function () {
  const modal = document.getElementById('consult-modal');
  if (!modal) return;
  const form    = modal.querySelector('#cm-form');
  const done    = modal.querySelector('#cm-done-box');
  const fName   = modal.querySelector('#cm-name');
  const fTel    = modal.querySelector('#cm-tel');
  const fMsg    = modal.querySelector('#cm-msg');
  const fAgree  = modal.querySelector('#cm-agree');
  const count   = modal.querySelector('#cm-count');
  const send    = modal.querySelector('#cm-send');
  const err     = modal.querySelector('#cm-err');
  const more    = modal.querySelector('#cm-more');
  const terms   = modal.querySelector('#cm-terms');
  const doneTel = modal.querySelector('#cm-done-tel');
  const defaultName = fName.value;
  let opener = null;
  let sending = false;

  const telOk = (v) => /^0\d{1,2}-?\d{3,4}-?\d{4}$/.test(v.trim());
  const valid = () => fName.value.trim() !== '' && telOk(fTel.value) && fMsg.value.trim() !== '' && fAgree.checked;
  const sync  = () => {
    count.textContent = fMsg.value.length + ' / 500';
    send.disabled = sending || !valid();
  };
  const setTerms = (open) => {
    terms.hidden = !open;
    more.setAttribute('aria-expanded', open ? 'true' : 'false');
    more.querySelector('.cm-more-label').textContent = open ? '닫기' : '자세히 보기';
  };
  const reset = () => {
    form.reset();
    fName.value = defaultName;
    err.hidden = true;
    err.textContent = '';
    form.hidden = false;
    done.hidden = true;
    setTerms(true);
    sync();
  };

  window.openConsultModal = function (trigger) {
    opener = trigger || null;
    reset();
    modal.hidden = false;
    document.body.classList.add('cm-open');
    (fName.value ? fTel : fName).focus();
  };
  const close = () => {
    modal.hidden = true;
    document.body.classList.remove('cm-open');
    if (opener && document.contains(opener)) opener.focus();
  };

  [fName, fTel, fMsg].forEach(el => el.addEventListener('input', sync));
  fAgree.addEventListener('change', sync);
  more.addEventListener('click', () => setTerms(terms.hidden));
  modal.querySelectorAll('[data-cm-close]').forEach(b => b.addEventListener('click', close));
  modal.addEventListener('click', (e) => { if (e.target === modal) close(); });
  modal.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') { e.preventDefault(); close(); return; }
    if (e.key !== 'Tab') return;
    // 모달 안에서만 Tab 이동
    const box = form.hidden ? done : form;
    const f = [...box.querySelectorAll('button, input, textarea, [href]')].filter(el => !el.disabled && el.offsetParent !== null);
    if (!f.length) return;
    const first = f[0], last = f[f.length - 1];
    if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
    else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
  });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    if (sending || !valid()) return;
    sending = true;
    sync();
    send.textContent = '보내는 중…';
    err.hidden = true;
    try {
      const r = await fetch('/api/consult/request.php', {
        method:  'POST',
        headers: { 'Content-Type': 'application/json' },
        body:    JSON.stringify({
          sid: window.SID || '', name: fName.value, tel: fTel.value, message: fMsg.value, agree: fAgree.checked,
        }),
      });
      const d = await r.json().catch(() => null);
      if (d && d.ok) {
        doneTel.textContent = fTel.value.trim();
        form.hidden = true;
        done.hidden = false;
        done.querySelector('button').focus();
      } else {
        err.textContent = (d && d.error) || '일시적으로 접수하지 못했습니다. 잠시 후 다시 시도해 주세요.';
        err.hidden = false;
      }
    } catch {
      err.textContent = '일시적으로 접수하지 못했습니다. 잠시 후 다시 시도해 주세요.';
      err.hidden = false;
    }
    sending = false;
    send.textContent = '문의 요청 보내기';
    sync();
  });
})();
