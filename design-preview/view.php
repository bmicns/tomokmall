<?php
declare(strict_types=1);

/**
 * 시안을 지정 너비(데스크톱/태블릿/모바일)로 iframe 미리보기 (/design-preview/view?f=slug&w=360)
 * 금지: DB·세션·app/ 로직 사용 금지.
 */

require __DIR__ . '/frames.php';

$slug   = (string) ($_GET['f'] ?? '');
$width  = max(320, min(1920, (int) ($_GET['w'] ?? 1280)));
$frames = array_column(preview_frames(), null, 'slug');

if (!isset($frames[$slug])) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '시안을 찾을 수 없습니다.';
    return;
}
$frame = $frames[$slug];
?>
<!doctype html>
<html lang="ko">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= h($frame['title']) ?> (<?= $width ?>px) — 디자인 미리보기</title>
    <link rel="stylesheet" href="/design-preview/preview.css">
</head>
<body class="dp-shell">
<header class="dp-bar">
    <a class="dp-btn dp-btn-dim" href="/design-preview/">← 목록</a>
    <strong class="dp-bar-title"><?= h($frame['title']) ?></strong>
    <nav class="dp-item-actions" aria-label="미리보기 너비">
        <?php foreach ([1280 => '데스크톱', 768 => '태블릿', 360 => '모바일'] as $w => $label): ?>
            <a class="dp-btn<?= $w === $width ? ' is-active' : '' ?>" href="?f=<?= h($slug) ?>&amp;w=<?= $w ?>"><?= $label ?> <?= $w ?></a>
        <?php endforeach; ?>
    </nav>
</header>
<main class="dp-stage">
    <iframe class="dp-frame" style="width: <?= $width ?>px" src="/design-preview/frames/<?= h($slug) ?>.html" title="<?= h($frame['title']) ?> 미리보기"></iframe>
</main>
</body>
</html>
