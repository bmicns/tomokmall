/**
 * 상담원 연결 유도 — 카카오톡 채널 1:1 상담 (AI 상담 화면, 상품 추천 시점)
 * 시안: '상담원 연결 유도 (추천 시점)' 아트보드(카카오톡 채널)
 * 로드: chat_renderer.php — 모든 방문자(비로그인 포함)에게 넣는다(2026-11-09 변경, 이전: 로그인한 개인·기업회원만)
 * 호출: chat-ai.js processEvent('product_cards') → renderConsultCta(content)
 * 기능: 아직 없음 — 버튼을 눌러도 아무 동작 안 함(카카오톡 채널 연결·상담 시간 외 안내는 다음 단계)
 */

/** 상품 추천표 바로 아래 '상담원 연결 유도' 블록 */
function renderConsultCta(container) {
  if (!container) return;
  const sec = document.createElement('section');
  sec.className = 'consult-cta';
  sec.setAttribute('aria-label', '상담원 연결');
  sec.innerHTML = `
    <div class="consult-cta-head">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#004EA1" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M4 13a8 8 0 0 1 16 0"></path><rect x="3" y="13" width="4" height="6" rx="1.5"></rect><rect x="17" y="13" width="4" height="6" rx="1.5"></rect><path d="M19 19c0 1.5-2 2.5-5 2.5"></path></svg>
      <span class="consult-cta-title">어떤 제품이 맞는지 확신이 서지 않으세요?</span>
    </div>
    <p class="consult-cta-desc">카카오톡 채널을 통해 문의를 남겨 주시면 담당자가 1:1로 상담을 도와드립니다.</p>
    <button type="button" class="consult-cta-btn">
      <svg width="16" height="16" viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 3.5c-5 0-9 3.1-9 7 0 2.5 1.7 4.7 4.2 5.9l-.9 3.4c-.1.3.3.6.6.4l4-2.6c.4 0 .7.1 1.1.1 5 0 9-3.1 9-7s-4-7.2-9-7.2z"></path></svg>
      <span>카카오톡 채널 1:1 상담하기</span>
    </button>
    <p class="consult-cta-hours">상담 가능 시간 평일 09:00 ~ 18:00</p>`;
  container.appendChild(sec);
}
