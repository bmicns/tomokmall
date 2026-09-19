<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/layout.php';

$isCorporate = is_corporate_member();
$quickHref   = $isCorporate ? '/estimate-quick.php' : '/login.php?redirect=' . urlencode('/estimate-quick.php');

$categoryId = isset($_GET['category']) ? max(0, (int) $_GET['category']) : null;
$query      = trim((string) ($_GET['q'] ?? ''));

render_header('홈');

if ($query) {
    // 검색 결과: 상품 목록 (FULLTEXT 토큰화 + 관련도 정렬)
    $products = search_products($query, 60);
    $hlTerms  = search_expand_terms($query);
    ?>
    <div class="content-wrap">
        <div class="list-header">
            <h2 class="list-title">검색결과 <span class="list-sub"><?= e($query) ?></span></h2>
            <span class="list-count"><?= count($products) ?>개</span>
        </div>
        <?php if (empty($products)): ?>
            <div class="empty-state">검색 결과가 없습니다.</div>
        <?php else: ?>
        <div class="product-grid">
            <?php foreach ($products as $p): ?>
                <a class="product-card" href="/product.php?id=<?= (int) $p['id'] ?>">
                    <div class="product-image">
                        <?php if (!empty($p['image_url'])): ?>
                            <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="img-placeholder"><?= e(mb_substr($p['name'], 0, 2)) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="product-card-name"><?= highlight_terms($p['name'], $hlTerms) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

<?php } elseif ($categoryId) {
    // 카테고리 선택: 해당 카테고리 상품 목록
    $products  = fetch_products($categoryId, null);
    $hlTerms   = [];
    $similar   = fetch_related_categories($categoryId, 'SIMILAR');
    $nextSteps = fetch_related_categories($categoryId, 'NEXT_STEP');
    ?>
    <div class="content-wrap">
        <div class="list-header">
            <span class="list-count"><?= count($products) ?>개 상품</span>
        </div>
        <?php if (empty($products)): ?>
            <div class="empty-state">등록된 상품이 없습니다.</div>
        <?php else: ?>
        <div class="product-grid">
            <?php foreach ($products as $p): ?>
                <a class="product-card" href="/product.php?id=<?= (int) $p['id'] ?>">
                    <div class="product-image">
                        <?php if (!empty($p['image_url'])): ?>
                            <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['name']) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="img-placeholder"><?= e(mb_substr($p['name'], 0, 2)) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="product-card-name"><?= highlight_terms($p['name'], $hlTerms) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($similar || $nextSteps): ?>
        <div class="relation-section">
            <?php if ($similar): ?>
                <div class="relation-group">
                    <span class="relation-label">유사품목</span>
                    <?php foreach ($similar as $cat): ?>
                        <a href="/index.php?category=<?= (int) $cat['id'] ?>" class="chip"><?= e($cat['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php if ($nextSteps): ?>
                <div class="relation-group">
                    <span class="relation-label">다음공정</span>
                    <?php foreach ($nextSteps as $cat): ?>
                        <a href="/index.php?category=<?= (int) $cat['id'] ?>" class="chip"><?= e($cat['name']) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>

<?php } else {
    // 홈: 주요품목 (DB home_featured 테이블 → 없으면 기본값)
    $homeItems = [];
    if (db()) {
        try {
            $rows = db()->query("SELECT item_name, description FROM home_featured WHERE is_active=1 ORDER BY sort_order, id LIMIT 8")->fetchAll();
            $homeItems = array_column($rows, 'item_name');
            foreach ($rows as $r) { $homeDataMap[$r['item_name']] = ['image_url' => '', 'description' => $r['description'] ?? '']; }
        } catch (\Throwable $e) {}
    }
    if (empty($homeItems)) {
        $homeItems = ['플륨관','수로관','옹벽블록','블록','벽돌','그레이팅','돌망태','PE이중벽관'];
    }
    $homeDescDefaults = [
        '플륨관'     => "U형·AN형·벤치플륨관 등 측구 및 배수로용\n콘크리트 수로관 전 규격을 공급합니다.",
        '수로관'     => "원심력·벤치·각형수로관 등 현장 조건에\n맞는 수로관 제품을 선택하세요.",
        '옹벽블록'   => "보강토옹벽블록·L형옹벽 등 사면 안정과\n경관을 동시에 확보하는 제품입니다.",
        '블록'       => "인조화강·보강토·호안·잔디블록 등\n다양한 용도의 블록 제품을 공급합니다.",
        '벽돌'       => "시멘트벽돌·적벽돌 등 건축·조경 시공에\n필요한 벽돌 전 규격을 공급합니다.",
        '그레이팅'   => "평판·수로용·U타입 날개형 스틸 그레이팅을\n규격 및 용도별로 공급합니다.",
        '돌망태'     => "원통형·직사각형·매트형 개비온으로\n호안 및 사면 보호 시공에 사용됩니다.",
        'PE이중벽관' => "200mm~500mm 이상 PE이중벽관을\n하수·배수 배관 현장에 공급합니다.",
    ];
    $homeImgDefaults = [
        '플륨관'     => '/assets/images/home-플륨관.png',
        '수로관'     => '/assets/images/home-수로관.png',
        '옹벽블록'   => '/assets/images/home-옹벽블록.png',
        '블록'       => '/assets/images/home-블록.png',
        '그레이팅'   => '/assets/images/home-그레이팅.png',
        'PE이중벽관' => '/assets/images/home-PE이중벽관.png',
    ];
    $homeDataMap = [];
    if (db()) {
        $ph = implode(',', array_fill(0, count($homeItems), '?'));
        try {
            $st = db()->prepare("SELECT name, image_url, description FROM categories WHERE name IN ($ph)");
            $st->execute($homeItems);
            foreach ($st->fetchAll() as $r) { $homeDataMap[$r['name']] = $r; }
            $st2 = db()->prepare("SELECT name, image_url, '' AS description FROM products WHERE name IN ($ph)");
            $st2->execute($homeItems);
            foreach ($st2->fetchAll() as $r) { if (!isset($homeDataMap[$r['name']])) $homeDataMap[$r['name']] = $r; }
        } catch (\Throwable $e) {}
    }
    ?>
    <div class="content-wrap">
        <div class="home-hero">
            <p class="hero-tagline"><strong>현장에서 바로 쓰는 건축자재</strong><br>품목별 단가 조회부터 견적 요청까지 한 번에</p>

            <form class="hero-search-form" method="get" action="/products.php">
                <input type="text" name="q" placeholder="수로관, 암거, 그레이팅..." autocomplete="off" data-suggest>
                <button type="submit">
                    <svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
                    검색
                </button>
            </form>

            <div class="hero-search-tabs">
                <a class="hero-tab" href="/chat.php">💬 일반 AI 상담</a>
                <a class="hero-tab" href="<?= e($quickHref) ?>">⚡ 기업회원 즉시견적</a>
            </div>

        </div>
        <div class="list-header">
            <h2 class="list-title">주요판매목록</h2>
        </div>
        <div class="product-grid">
            <?php foreach ($homeItems as $name): ?>
                <?php $d = $homeDataMap[$name] ?? []; ?>
                <a class="product-card" href="/products.php?q=<?= urlencode($name) ?>">
                    <div class="product-image">
                        <?php $imgSrc = !empty($d['image_url']) ? $d['image_url'] : ($homeImgDefaults[$name] ?? ''); ?>
                        <?php if ($imgSrc): ?>
                            <img src="<?= e($imgSrc) ?>" alt="<?= e($name) ?>" loading="lazy">
                        <?php else: ?>
                            <span class="img-placeholder"><?= e(mb_substr($name, 0, 2)) ?></span>
                        <?php endif; ?>
                    </div>
                    <p class="product-card-name"><?= e($name) ?></p>
                    <?php $desc = !empty($d['description']) ? $d['description'] : ($homeDescDefaults[$name] ?? ''); ?>
                    <?php if ($desc): ?>
                        <p class="product-card-desc"><?= nl2br(e($desc)) ?></p>
                    <?php endif; ?>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
<?php } ?>


<?php render_footer(); ?>
