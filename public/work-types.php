<?php
declare(strict_types=1);
/**
 * 전체 작업 보기 — 메인페이지 '어떤 작업에 사용할 예정인가요?'의 전체 작업 유형 목록
 * 각 작업의 대표 자재(최대 10개)를 보여주고, 자재 카드는 해당 카테고리 상품 목록(/products.php?category=ID)으로 연결.
 * 설정: app/work_types.php (DB 쓰기 없음, 카테고리 이름·썸네일 조회만)
 */

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/layout.php';
require __DIR__ . '/../app/work_types.php';

$types = work_types();
$ids   = [];
foreach ($types as $t) { $ids = array_merge($ids, array_column($t['materials'], 0)); }
$info = work_type_category_info($ids);

$groups = [
    '주요 작업'    => array_values(array_filter($types, static fn(array $t): bool => !empty($t['main']))),
    '그 밖의 작업' => array_values(array_filter($types, static fn(array $t): bool => empty($t['main']))),
];

render_header('전체 작업 보기');
?>
<div class="gh-wrap wt-page">
    <nav class="wt-crumb" aria-label="위치"><a href="/index.php">홈</a> <span aria-hidden="true">›</span> 전체 작업 보기</nav>
    <h1 class="wt-title">전체 작업 보기</h1>
    <p class="wt-lead">하려는 공사를 고르면 그 작업에 주로 쓰는 자재를 보여드려요. 자재를 누르면 해당 상품 목록으로 이동합니다.</p>

    <?php foreach ($groups as $gName => $gTypes): ?>
    <div class="wt-index">
        <h2 class="wt-index-title"><?= e($gName) ?></h2>
        <ul class="wt-index-list">
            <?php foreach ($gTypes as $t): ?>
            <li><a href="#wt-<?= e($t['key']) ?>"><?= e($t['name']) ?></a></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endforeach; ?>

    <?php foreach ($groups as $gName => $gTypes): ?>
        <?php foreach ($gTypes as $t): ?>
        <?php $mats = work_type_materials($t, $info); ?>
        <section class="wt-section" id="wt-<?= e($t['key']) ?>" aria-labelledby="wt-h-<?= e($t['key']) ?>">
            <div class="wt-section-head">
                <h2 class="wt-section-title" id="wt-h-<?= e($t['key']) ?>"><?= e($t['name']) ?></h2>
                <span class="wt-section-tag"><?= e($gName) ?></span>
            </div>
            <p class="wt-section-sum"><?= e($t['summary']) ?></p>
            <?php if ($mats): ?>
            <div class="gh-need-grid">
                <?php foreach ($mats as $n): ?>
                <?= work_type_material_card_html($n) ?>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p class="wt-empty">현재 표시할 자재가 없습니다.</p>
            <?php endif; ?>
        </section>
        <?php endforeach; ?>
    <?php endforeach; ?>

    <p class="wt-foot"><a class="gh-use-ai" href="/chat.php">해당하는 작업이 없어요 · AI에게 설명하기 →</a></p>
</div>
<?php render_footer(); ?>
