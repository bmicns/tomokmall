<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/layout.php';

$user        = current_user();
$isCorporate = is_corporate_member();
$quickHref   = $isCorporate ? '/estimate-quick.php' : '/login.php?redirect=' . urlencode('/estimate-quick.php');

render_header('AI 자재 상담');
?>
<style>
.ai-page      { max-width: 680px; margin: 72px auto; }
.ai-page-title{ font-size: 24px; font-weight: 600; }
.ai-page-sub  { font-size: 15px; color: var(--color-text-secondary); margin-top: 8px 0 36px; }
.ai-cards     { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-top: 32px; }
@media(max-width:520px){ .ai-cards{ grid-template-columns:1fr; } }
.ai-card      { display: flex; flex-direction: column; gap: 10px; padding: 28px 24px;
                border: 1px solid var(--color-border); background: var(--color-surface);
                text-decoration: none; color: var(--color-text-primary); transition: border-color .15s; }
.ai-card:hover{ border-color: var(--color-primary); }
.ai-card-icon { font-size: 28px; line-height: 1; }
.ai-card-title{ font-size: 16px; font-weight: 600; }
.ai-card-desc { font-size: 13px; color: var(--color-text-secondary); line-height: 1.7; }
.ai-card-badge{ display: inline-flex; padding: 3px 8px; font-size: 12px;
                border: 1px solid var(--color-primary); color: var(--color-primary);
                margin-top: 4px; align-self: flex-start; }
</style>

<div class="ai-page">
  <div class="ai-page-title">AI 자재 상담</div>
  <p class="ai-page-sub">상담 방식을 선택해주세요</p>
  <div class="ai-cards">
    <a class="ai-card" href="/chat.php">
      <div class="ai-card-icon">💬</div>
      <div class="ai-card-title">일반 AI 상담</div>
      <div class="ai-card-desc">자연어로 자재를 설명하면<br>AI가 최적 상품을 추천합니다</div>
    </a>
    <a class="ai-card" href="<?= e($quickHref) ?>">
      <div class="ai-card-icon">⚡</div>
      <div class="ai-card-title">기업회원 즉시견적</div>
      <div class="ai-card-desc">상품·규격·수량을 직접 선택해<br>즉시 견적을 작성합니다</div>
      <?php if (!$isCorporate): ?>
      <div class="ai-card-badge">로그인 필요</div>
      <?php endif; ?>
    </a>
  </div>
</div>
<?php render_footer(); ?>
