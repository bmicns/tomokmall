<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/layout.php';

$isCorporate = is_corporate_member();
$quickHref   = $isCorporate ? '/estimate-quick.php' : '/login.php?redirect=' . urlencode('/estimate-quick.php');

$categoryId = isset($_GET['category']) ? max(0, (int) $_GET['category']) : null;
$query      = trim((string) ($_GET['q'] ?? ''));
// 기업회원(corporate/staff/admin) 홈: 확정 디자인 '기업회원용 메인화면'. 비로그인·개인회원 홈은 아래 기존 화면 그대로.
$corpHome   = $isCorporate && !$query && !$categoryId;

// 비로그인·개인회원 홈(검색·카테고리 지정 없음)도 기업회원 홈처럼 대분류 메뉴를 헤더 한 줄에 둔다('home' 모드)
render_header('홈', $corpHome ? 'corp' : (!$query && !$categoryId ? 'home' : 'normal'));

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

<?php } elseif ($corpHome) {
    // 기업회원 홈: 빠른 견적하기(데이터 조회는 app/corp_home.php, UI는 렌더러에 위임)
    require __DIR__ . '/../app/corp_home.php';
    $corpUid      = (int) current_user()['id'];
    $corpTree     = corp_home_category_tree(db() ? fetch_categories() : []);
    $corpRegulars = db() ? corp_home_regulars(db(), $corpUid) : [];
    $corpHistory  = db() ? corp_home_history(db(), $corpUid) : ['items' => [], 'has_more' => false];
    require __DIR__ . '/../app/corp_home_renderer.php';
} elseif ($isCorporate) {
    // ※ 기업회원 홈은 위 $corpHome 분기(확정 디자인)로 바뀌어 현재 이 분기에는 도달하지 않는다(삭제는 승인 후 별도 진행).
    // 홈(기업회원·직원·관리자): 주요품목 (DB home_featured 테이블 → 없으면 기본값)
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

            <div class="hero-chips">
                <?php foreach (['측구수로관','그레이팅','흄관','경계석','PE이중벽관'] as $hc): ?>
                <a href="/products.php?q=<?= urlencode($hc) ?>"><?= e($hc) ?></a>
                <?php endforeach; ?>
            </div>

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
<?php } else {
    // 홈(일반회원·비로그인): 확정 디자인 '일반회원용 메인화면' — 히어로 검색 + 작업 유형 슬라이드(4개씩)·필요 자재 패널 + 주요 자재
    // 작업 유형 12개·대표 자재(카테고리 ID)는 app/work_types.php 설정을 사용 (4개씩 좌우 슬라이드, 3페이지)
    require_once __DIR__ . '/../app/work_types.php';
    $useItems = work_types();
    $wtIds    = [];
    foreach ($useItems as $u) { $wtIds = array_merge($wtIds, array_column($u['materials'], 0)); }
    $wtInfo = work_type_category_info($wtIds);
    foreach ($useItems as $i => $u) {
        $useItems[$i]['needs'] = work_type_materials($u, $wtInfo);
        // 카드 이미지: 설정 이미지 → 없으면 첫 대표 자재 이미지
        $useItems[$i]['card_img']  = $u['img'] ?? ($useItems[$i]['needs'][0]['img'] ?? '');
        $useItems[$i]['card_kind'] = $u['img'] !== null ? $u['img_kind'] : 'product';
    }
    $usePerPage  = 4;
    $usePages    = (int) ceil(count($useItems) / $usePerPage);
    $matItems = [
        ['name' => '플륨관',     'desc' => "물을 흘려보내는\n콘크리트 수로", 'img' => '/assets/images/home-플륨관.png'],
        ['name' => '수로관',     'desc' => "배수로를\n만드는 자재",          'img' => '/assets/images/home-수로관.png'],
        ['name' => '옹벽블록',   'desc' => "흙을 지지하는\n블록",             'img' => '/assets/images/home-옹벽블록.png'],
        ['name' => '블록',       'desc' => '바닥 · 조경 시공',                'img' => '/assets/images/home-블록.png'],
        ['name' => '벽돌',       'desc' => '벽체 · 조경 시공',                'img' => '/assets/images/home-벽돌.png'],
        ['name' => '그레이팅',   'desc' => '배수로 위 금속 덮개',             'img' => '/assets/images/home-그레이팅.png'],
        ['name' => '돌망태',     'desc' => "돌을 채운\n철망 구조물",          'img' => '/assets/images/home-돌망태.png'],
        // 관 사진 왼쪽 끝이 잘린 단면이라 카드 밖으로 밀어 잘린 부분을 가린다(img_class, styles.css .gh-mat-img--bleed)
        ['name' => 'PE이중벽관', 'desc' => '땅속 배수용 관',                  'img' => '/assets/images/home-PE이중벽관.png', 'img_class' => 'gh-mat-img--bleed'],
    ];
    ?>
    <div class="gh-wrap">
        <section class="gh-hero">
            <h1 class="gh-hero-title">현장에서 바로 쓰는 건축자재</h1>
            <p class="gh-hero-sub">품목별 단가 조회부터 견적 요청까지 한 번에</p>

            <form class="gh-search" method="get" action="/products.php" role="search">
                <label for="gh-q" class="gh-sr-only">자재 검색</label>
                <input id="gh-q" type="text" name="q" placeholder="수로관, 암거, 그레이팅..." autocomplete="off" data-suggest>
                <button type="submit">
                    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4.2-4.2"/></svg>
                    검색
                </button>
            </form>

            <div class="gh-chips">
                <?php foreach (['측구수로관','그레이팅','흄관','경계석','PE이중벽관'] as $hc): ?>
                <a href="/products.php?q=<?= urlencode($hc) ?>"><?= e($hc) ?></a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="gh-section gh-use" data-use-carousel data-per-page="<?= $usePerPage ?>">
            <div class="gh-section-head">
                <h2 class="gh-section-title">어떤 작업에 사용할 예정인가요?</h2>
                <div class="gh-section-side">
                    <a class="gh-use-ai" href="/chat.php">해당하는 상황이 없어요 · AI에게 설명하기 →</a>
                    <span class="gh-use-page" data-use-label aria-live="polite">1 / <?= $usePages ?></span>
                </div>
            </div>
            <div class="gh-use-frame">
                <button class="gh-car-btn gh-car-prev" type="button" aria-label="이전 용도" data-use-prev disabled>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M15 5l-7 7 7 7"/></svg>
                </button>
                <button class="gh-car-btn gh-car-next" type="button" aria-label="다음 용도" data-use-next<?= $usePages <= 1 ? ' disabled' : '' ?>>
                    <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M9 5l7 7-7 7"/></svg>
                </button>
                <div class="gh-use-view">
                    <div class="gh-use-track" data-use-track>
                        <?php foreach ($useItems as $i => $u): ?>
                        <button type="button" class="gh-use-card" data-use-idx="<?= $i ?>" aria-expanded="false" aria-controls="gh-need-panel">
                            <?php if ($u['card_img'] !== ''): ?>
                            <img class="<?= $u['card_kind'] === 'product' ? 'is-product' : '' ?>" src="<?= e($u['card_img']) ?>" alt="<?= e($u['img_alt']) ?>" loading="lazy" onerror="this.hidden=true;this.nextElementSibling.hidden=false">
                            <span class="gh-use-noimg" aria-hidden="true" hidden><?= e(mb_substr($u['name'], 0, 2)) ?></span>
                            <?php else: ?>
                            <span class="gh-use-noimg" aria-hidden="true"><?= e(mb_substr($u['name'], 0, 2)) ?></span>
                            <?php endif; ?>
                            <span class="gh-use-label"><?= e($u['name']) ?>
                                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                            </span>
                        </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- 작업별 자재: 접힘(구분선 + ▼) / 펼침(아래 주요 자재 위를 덮는 패널) -->
            <div class="gh-need">
                <div class="gh-need-bar" data-need-bar>
                    <span class="gh-need-line" aria-hidden="true"></span>
                    <button type="button" class="gh-need-pill" data-need-open aria-label="작업별 자재 보기" aria-expanded="false" aria-controls="gh-need-panel">
                        <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 7h16l-8 11z" fill="currentColor"/></svg>
                    </button>
                    <span class="gh-need-tip" aria-hidden="true">작업별 자재 보기</span>
                </div>
                <div class="gh-need-pop" data-need-pop hidden>
                    <div id="gh-need-panel" class="gh-need-panel" role="region" aria-labelledby="gh-need-title">
                        <span class="gh-need-notch" data-need-notch aria-hidden="true"></span>
                        <h3 class="gh-need-title" id="gh-need-title"><span data-need-title></span>에 필요한 자재</h3>
                        <?php foreach ($useItems as $i => $u): ?>
                        <div class="gh-need-grid" data-need-for="<?= $i ?>" hidden>
                            <?php foreach ($u['needs'] as $n): ?>
                            <?= work_type_material_card_html($n) ?>
                            <?php endforeach; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="gh-need-foot">
                        <button type="button" class="gh-need-close" data-need-close aria-controls="gh-need-panel" aria-expanded="true">
                            <svg width="14" height="14" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 17h16L12 6z" fill="currentColor"/></svg>접기
                        </button>
                    </div>
                </div>
            </div>
        </section>

        <section class="gh-section gh-mat">
            <div class="gh-section-head">
                <h2 class="gh-section-title">주요 자재 둘러보기</h2>
                <a class="gh-mat-all" href="#" data-open-gnb>전체 자재 보기 →</a>
            </div>
            <div class="gh-mat-grid">
                <?php foreach ($matItems as $m): ?>
                <a class="gh-mat-card" href="/products.php?q=<?= urlencode($m['name']) ?>">
                    <img src="<?= e($m['img']) ?>" alt="<?= e($m['name']) ?>" loading="lazy"<?= !empty($m['img_class']) ? ' class="' . e($m['img_class']) . '"' : '' ?>>
                    <span class="gh-mat-text">
                        <span class="gh-mat-name"><?= e($m['name']) ?></span>
                        <span class="gh-mat-desc"><?= nl2br(e($m['desc'])) ?></span>
                    </span>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
    <script>
    // 작업(용도) 슬라이드 + 작업별 필요 자재 패널
    // 데스크톱(>1200px): 4개씩 페이지 이동, 패널은 아래 '주요 자재' 위를 덮음 / 그 이하: 가로 스크롤, 패널은 문서 흐름 안에 표시(CSS)
    (function () {
        var root = document.querySelector('[data-use-carousel]');
        if (!root) return;
        var wrap  = root.closest('.gh-wrap');
        var track = root.querySelector('[data-use-track]');
        var cards = Array.prototype.slice.call(track.children);
        var prev  = root.querySelector('[data-use-prev]');
        var next  = root.querySelector('[data-use-next]');
        var label = root.querySelector('[data-use-label]');
        var bar   = root.querySelector('[data-need-bar]');
        var pill  = root.querySelector('[data-need-open]');
        var pop   = root.querySelector('[data-need-pop]');
        var notch = root.querySelector('[data-need-notch]');
        var title = root.querySelector('[data-need-title]');
        var grids = Array.prototype.slice.call(root.querySelectorAll('[data-need-for]'));
        var per   = parseInt(root.getAttribute('data-per-page'), 10) || 4;
        var pages = Math.max(1, Math.ceil(cards.length / per));
        var mq    = window.matchMedia('(min-width: 1201px)');
        var page  = 0;
        var open  = -1;

        // 펼친 패널이 페이지 아래(푸터)를 덮지 않도록 필요한 만큼 여백 확보 (데스크톱 오버레이일 때만)
        function fitWrap() {
            wrap.style.paddingBottom = '';
            if (open < 0 || !mq.matches) return;
            var over = pop.getBoundingClientRect().bottom - wrap.getBoundingClientRect().bottom;
            if (over > 0) wrap.style.paddingBottom = (parseFloat(getComputedStyle(wrap).paddingBottom) + over + 24) + 'px';
        }
        function render() {
            var paged = mq.matches;
            track.style.transform = paged ? 'translateX(calc(' + (-page) + ' * (100% + 12px)))' : '';
            cards.forEach(function (c, i) {
                var hidden = paged && Math.floor(i / per) !== page;
                var on = i === open;
                if (hidden) { c.setAttribute('aria-hidden', 'true'); c.setAttribute('tabindex', '-1'); }
                else { c.removeAttribute('aria-hidden'); c.removeAttribute('tabindex'); }
                c.setAttribute('aria-expanded', on ? 'true' : 'false');
                c.classList.toggle('is-open', on);
                c.querySelector('.gh-use-label path').setAttribute('d', on ? 'M6 15l6-6 6 6' : 'M6 9l6 6 6-6');
            });
            prev.disabled = page <= 0;
            next.disabled = page >= pages - 1;
            label.textContent = (page + 1) + ' / ' + pages;

            var isOpen = open >= 0;
            pop.hidden = !isOpen;
            bar.classList.toggle('is-hidden', isOpen);
            pill.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
            grids.forEach(function (g, i) { g.hidden = i !== open; });
            if (isOpen) {
                title.textContent = cards[open].querySelector('.gh-use-label').textContent.trim();
                var col = open % per;
                notch.style.left = 'calc((100% - 36px) / 4 * ' + (col + 0.5) + ' + ' + (12 * col) + 'px - 8px)';
            }
            fitWrap();
        }
        function setOpen(i) { open = i; bar.classList.remove('is-hint'); render(); }

        cards.forEach(function (c, i) {
            c.addEventListener('click', function () { setOpen(open === i ? -1 : i); });
        });
        pill.addEventListener('click', function () { setOpen(mq.matches ? page * per : 0); });
        ['mouseenter', 'focus'].forEach(function (ev) { pill.addEventListener(ev, function () { bar.classList.add('is-hint'); }); });
        ['mouseleave', 'blur'].forEach(function (ev) { pill.addEventListener(ev, function () { bar.classList.remove('is-hint'); }); });
        root.querySelector('[data-need-close]').addEventListener('click', function () {
            var last = open;
            setOpen(-1);
            if (cards[last]) cards[last].focus();
        });
        prev.addEventListener('click', function () { if (page > 0) { page--; open = -1; render(); } });
        next.addEventListener('click', function () { if (page < pages - 1) { page++; open = -1; render(); } });
        var onMq = function () { page = 0; open = -1; render(); };
        if (mq.addEventListener) mq.addEventListener('change', onMq);
        else if (mq.addListener) mq.addListener(onMq);
        window.addEventListener('resize', fitWrap);
        render();

        // '전체 자재 보기': 전체 목록 페이지가 없어 상단 카테고리 메뉴(GNB)로 포커스를 옮기고 강조
        var allLink = document.querySelector('[data-open-gnb]');
        var gnb = document.querySelector('.gnb-bar');
        var firstTab = gnb && gnb.querySelector('.mega-tab');
        if (allLink && firstTab) {
            allLink.addEventListener('click', function (e) {
                e.preventDefault();
                window.scrollTo({ top: 0, behavior: 'smooth' });
                firstTab.focus({ preventScroll: true });
                gnb.classList.remove('gh-gnb-hint');
                void gnb.offsetWidth;
                gnb.classList.add('gh-gnb-hint');
                setTimeout(function () { gnb.classList.remove('gh-gnb-hint'); }, 1800);
            });
        } else if (allLink) {
            allLink.hidden = true;
        }
    })();
    </script>
<?php } ?>


<?php render_footer(); ?>
