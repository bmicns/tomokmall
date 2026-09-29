<?php
/**
 * 기업회원 전용 안내 모달 렌더링 전용
 * 호출자: public/estimate-quick.php (로그인했으나 기업회원 아닌 개인회원)
 * 의존: 없음(정적 안내)
 * 금지: DB 쿼리, API 호출
 */
require __DIR__ . '/layout.php';
render_header('기업회원 전용');
?>
<style>
  .corp-only { min-height: 58vh; display: flex; align-items: center; justify-content: center; padding: 48px 16px; }
  .corp-only-card { max-width: 440px; width: 100%; background: #fff; border: 1px solid #e8eaed; border-radius: 16px; box-shadow: 0 12px 36px rgba(0,0,0,.10); padding: 40px 30px; text-align: center; }
  .corp-only-icon { width: 60px; height: 60px; margin: 0 auto 18px; border-radius: 50%; background: #eef3fb; display: flex; align-items: center; justify-content: center; font-size: 28px; }
  .corp-only-card h2 { font-size: 20px; font-weight: 700; color: #1a1a1a; margin: 0 0 12px; }
  .corp-only-card p { font-size: 14px; color: #666; line-height: 1.7; margin: 0 0 26px; }
  .corp-only-actions { display: flex; gap: 10px; }
  .cob-btn { flex: 1; padding: 13px 0; border-radius: 9px; font-size: 14px; font-weight: 600; text-decoration: none; text-align: center; transition: opacity .15s; }
  .cob-btn:hover { opacity: .88; }
  .cob-ghost { background: #f2f4f7; color: #444; }
  .cob-primary { background: #004EA1; color: #fff; }
</style>
<div class="corp-only">
  <div class="corp-only-card">
    <div class="corp-only-icon">🔒</div>
    <h2>기업회원 전용 기능입니다</h2>
    <p>즉시견적은 <strong>기업회원</strong>만 이용할 수 있습니다.<br>기업회원으로 가입하시면 즉시견적과 전용 단가를 이용하실 수 있습니다.</p>
    <div class="corp-only-actions">
      <a href="/index.php" class="cob-btn cob-ghost">홈으로</a>
      <a href="/register.php" class="cob-btn cob-primary">기업회원 가입</a>
    </div>
  </div>
</div>
<?php render_footer(); ?>
