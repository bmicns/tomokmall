<?php
declare(strict_types=1);
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/layout.php';

// 페이지 내림(사용자 결정 2026-11-26): 위젯이 주소·보증금·등기부등본 파일을 브라우저에서 바로 외부(vestra.ai.kr)로 보내고
// 건축자재몰 기능과도 관계없어서 첫 화면으로 보낸다. 메뉴는 layout.php 에서 이미 숨김. 다시 열려면 아래 세 줄을 지운다
// (그때는 개인정보 처리방침에 vestra 제공·위탁 내용을 먼저 넣어야 함 — docs/03-analysis/privacy-withdrawal-2026-11-26.md).
header('Location: /', true, 302);
exit;

render_header('부동산안전');
?>
<style>
.res-page      { max-width: 760px; margin: 56px auto 88px; padding: 0 20px; }
.res-page-title{ font-size: 26px; font-weight: 700; letter-spacing: -.02em; }
.res-page-sub  { font-size: 15px; color: var(--color-text-secondary); margin: 10px 0 0; line-height: 1.7; }
.res-card      { margin-top: 28px; padding: 24px; border: 1px solid var(--color-border);
                 background: var(--color-surface); }
.res-card-h    { font-size: 13px; color: var(--color-text-secondary); font-weight: 700; margin: 0 0 16px; }
.res-note      { margin-top: 20px; padding: 16px 18px; border: 1px solid var(--color-border);
                 border-left: 3px solid var(--color-primary); font-size: 13px;
                 color: var(--color-text-secondary); line-height: 1.7; }
</style>

<div class="res-page">
  <div class="res-page-title">부동산 안전 체크</div>
  <p class="res-page-sub">계약 전, 집의 <strong>권리관계·전세 안전성·시세·세금</strong>을 한 번에 확인하세요.
     상단 탭에서 원하는 기능을 골라 사용할 수 있습니다. (베스트라 분석 엔진 제공)</p>

  <div class="res-card">
    <div class="res-card-h">베스트라 집 체크</div>
    <!-- 베스트라 임베드 위젯 (두 줄) -->
    <div id="vestra-widget" data-features="rights,jeonse,price,tax" data-cta="/chat.php"></div>
    <script src="/vestra-widget.js" async></script>
  </div>

  <p class="res-note">※ <strong>시세조회·전세 안전진단·세금계산</strong>은 국토교통부 실거래가 등 실데이터로 동작합니다. <strong>권리분석</strong>은 등기부등본 PDF(텍스트 PDF)를 올리면 분석합니다.
     본 결과는 참고용으로 법적 효력이 없습니다.</p>
</div>
<?php render_footer(); ?>
