<?php
declare(strict_types=1);

/**
 * 디자인 미리보기 목록 페이지 (/design-preview/)
 * 금지: DB·세션·app/ 로직 사용 금지 — 시안 검토 전용.
 */

require __DIR__ . '/frames.php';

$frames = preview_frames();
$widths = ['desktop' => 1280, 'tablet' => 768, 'mobile' => 360];
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>디자인 미리보기 — 토목몰</title>
    <link rel="stylesheet" href="/design-preview/preview.css">
</head>
<body class="dp-shell">
<header class="dp-bar">
    <strong class="dp-bar-title">토목몰 디자인 미리보기</strong>
    <span class="dp-badge">개발 전 UI 검토용 · 실제 서비스와 무관</span>
</header>

<main class="dp-main">
    <?php if (empty($frames)): ?>
        <section class="dp-empty">
            <h1>등록된 시안이 없습니다</h1>
            <p>Figma 프레임 링크를 받으면 <code>design-preview/frames/</code>에 HTML/CSS로 구현해 이 목록에 표시됩니다.</p>
            <p>새 시안은 <code>frames/_template.html</code>을 복사해 <code>frames/화면이름.html</code>(영문 소문자·숫자·하이픈)로 만듭니다.</p>
        </section>
    <?php else: ?>
        <h1 class="dp-heading">시안 목록 <span><?= count($frames) ?>개</span></h1>
        <ul class="dp-list">
            <?php foreach ($frames as $f): ?>
                <li class="dp-item">
                    <a class="dp-item-title" href="/design-preview/frames/<?= h($f['slug']) ?>.html"><?= h($f['title']) ?></a>
                    <div class="dp-item-actions">
                        <?php foreach ($widths as $key => $w): ?>
                            <a class="dp-btn" href="/design-preview/view?f=<?= h($f['slug']) ?>&amp;w=<?= $w ?>"><?= $key === 'desktop' ? '데스크톱' : ($key === 'tablet' ? '태블릿' : '모바일') ?> <?= $w ?></a>
                        <?php endforeach; ?>
                        <?php if ($f['figma'] !== ''): ?>
                            <a class="dp-btn dp-btn-dim" href="<?= h($f['figma']) ?>" target="_blank" rel="noopener">Figma 원본</a>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>
</body>
</html>
