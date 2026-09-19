<?php
declare(strict_types=1);

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/layout.php';

$categoryId = isset($_GET['category']) ? max(0, (int) $_GET['category']) : null;
$query      = trim((string) ($_GET['q'] ?? ''));
$search     = trim((string) ($_GET['s'] ?? ''));

if (!$query && !$categoryId) {
    redirect('/index.php');
}

// 검색 페이지네이션
$perPage     = 60;
$page        = max(1, (int) ($_GET['p'] ?? 1));
$searchTotal = 0;

// 검색어 하이라이트 대상(키워드+동의어). 카테고리 브라우즈는 강조 안 함.
$hlTerms = ($query && !$categoryId) ? search_expand_terms($query) : [];

$products  = [];
$similar   = [];
$nextSteps = [];
$category  = null;
$relatedImgs = [];

if (db()) {
    // 1. 카테고리 먼저 확인 (products 조회 전)
    if ($categoryId) {
        try {
            $st = db()->prepare('SELECT * FROM categories WHERE id = ?');
            $st->execute([$categoryId]);
            $category = $st->fetch() ?: null;
        } catch (\Throwable $e) {}
    } elseif ($query) {
        try {
            // '철근콘크리트' → '철근콘크리트제품' 폴백 포함
            $st = db()->prepare('SELECT * FROM categories WHERE name = ? OR name = ? LIMIT 1');
            $st->execute([$query, $query . '제품']);
            $category = $st->fetch() ?: null;
            if ($category) { $categoryId = (int) $category['id']; }
        } catch (\Throwable $e) {}
    }

    // 2. 카테고리 확인 후 상품 조회
    if ($categoryId) {
        // 카테고리 브라우즈 (하위 카테고리 포함)
        try { $products = fetch_products($categoryId, null); } catch (\Throwable $e) {}
    } elseif ($query) {
        // 키워드 검색: FULLTEXT 토큰화 + 관련도 정렬 + 페이지네이션
        try { $products = search_products($query, $perPage, ($page - 1) * $perPage, $searchTotal); } catch (\Throwable $e) {}
    }

    if ($categoryId) {
        try { $similar   = fetch_related_categories($categoryId, 'SIMILAR');   } catch (\Throwable $e) {}
        try { $nextSteps = fetch_related_categories($categoryId, 'NEXT_STEP'); } catch (\Throwable $e) {}
    }

    $allRelCats = array_merge($similar, $nextSteps);
    if ($allRelCats) {
        $ids = array_column($allRelCats, 'id');
        $ph  = implode(',', array_fill(0, count($ids), '?'));
        try {
            $st = db()->prepare("SELECT category_id, image_url FROM products WHERE category_id IN ($ph) AND image_url IS NOT NULL AND image_url != '' GROUP BY category_id");
            $st->execute($ids);
            foreach ($st->fetchAll() as $r) {
                $relatedImgs[(int) $r['category_id']] = $r['image_url'];
            }
        } catch (\Throwable $e) {}
    }
}

// 시공사례 갤러리 (소분류면 부모 중분류 기준으로 조회)
$galleryImgs = [];
if ($categoryId && db()) {
    try {
        $galleryCatId = $categoryId;
        if ($category && !empty($category['parent_id']) && !in_array((int)$category['parent_id'], [11,12,13,14,15])) {
            $galleryCatId = (int)$category['parent_id'];
        }
        $st = db()->prepare("SELECT image_url, caption FROM category_gallery WHERE category_id = ? ORDER BY sort_order LIMIT 3");
        $st->execute([$galleryCatId]);
        $galleryImgs = $st->fetchAll();
    } catch (\Throwable $e) {}
}

// 인-테이블 검색 필터
if ($search && $products) {
    $needle = mb_strtolower($search);
    $products = array_values(array_filter($products, function ($p) use ($needle) {
        return mb_strpos(mb_strtolower(($p['name'] ?? '') . ($p['spec'] ?? '') . ($p['brand'] ?? '') . ($p['description'] ?? '')), $needle) !== false;
    }));
}

$pageTitle = $category['name'] ?? $query ?: '상품 목록';
$loggedIn  = !empty($_SESSION['user']);

// ── Figma 하드코딩 데모 데이터 (DB 미연결 시에만) ──────────
// ⚠️ 검색 결과 0건을 데모로 표시하면 "엉뚱한 제품"으로 보이므로,
//    DB가 연결된 경우에는 절대 데모 모드로 넘어가지 않는다.
$demoMode = empty($products) && !db();

// 색상 → hex 맵
$colorHex = [
    '회색'   => '#888888',
    '검정'   => '#333333',
    '흰색'   => '#eeeeee',
    '오렌지' => '#e07020',
    '아이보리'=> '#f5ead5',
    '갈색'   => '#7b4f28',
    '적색'   => '#cc2020',
    '커피'   => '#6f4e37',
    '화색'   => '#c0b8a8',
];

// 데모 상품 10행
$demoProducts = [
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'유입4구/유출1구 사각몸통','colors'=>['회색','검정','흰색','오렌지','아이보리'],'price'=>8500,'weight'=>120,'load'=>204,'freight'=>1500,'desc'=>'보차도블록 인조화강석 패턴의 투수형 블록으로 우수한 배수 성능을 제공합니다.'],
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'가정용','colors'=>['갈색'],'price'=>8800,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'주거지역 보도 시공에 적합한 가정용 규격입니다.'],
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'고강도콘크리트_KS점형','colors'=>['검정'],'price'=>8800,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'KS인증 점형 블록. 장애인 보행 안전을 위한 점자 패턴 적용.'],
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'고강도콘크리트_KS 논슬립 점형','colors'=>['회색'],'price'=>8800,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'미끄럼 방지 논슬립 표면 처리된 KS 인증 점형 블록입니다.'],
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'컬러보도','colors'=>['회색','검정','흰색','적색','갈색','오렌지','아이보리'],'price'=>9500,'weight'=>120,'load'=>216,'freight'=>1400,'desc'=>'다양한 색상으로 도시 미관을 향상시키는 컬러 보도블록.'],
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['회색','검정','흰색','커피','오렌지','아이보리'],'price'=>9000,'weight'=>128,'load'=>216,'freight'=>1400,'desc'=>'표준 규격의 인조화강 투수블록. 범용 보도 시공용.'],
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['오렌지'],'price'=>9200,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'오렌지 단색 마감. 상업지구 보도 포장에 적합.'],
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['흰색'],'price'=>9200,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'밝은 흰색 계열. 공원·광장 등 개방형 공간 포장재.'],
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['아이보리'],'price'=>9400,'weight'=>128,'load'=>218.4,'freight'=>1400,'desc'=>'아이보리 톤으로 자연스럽고 따뜻한 분위기 연출.'],
    ['name'=>'인조화강블록(투수)','brand'=>'','spec'=>'200*200*60T','specinfo'=>'200*200*60T','colors'=>['회색','검정','흰색','갈색','오렌지','아이보리'],'price'=>10000,'weight'=>125,'load'=>204,'freight'=>1500,'desc'=>'프리미엄 라인. 고급 주거단지·상업시설 보도 시공용.'],
];

// 데모 관련 제품
$demoSimilar = [
    ['name'=>'안전중하중그레이팅',        'img'=>'/assets/images/products-r1.png'],
    ['name'=>'인터로킹U형',               'img'=>'/assets/images/products-r2.png'],
    ['name'=>'인조화강블록(불투수)',       'img'=>'/assets/images/products-r3.png'],
    ['name'=>'다칼라블랜딩블록',           'img'=>'/assets/images/products-r4.png'],
    ['name'=>'안전중하중그레이팅맨홀(집..)', 'img'=>'/assets/images/products-r5.png'],
    ['name'=>'녹지경계블록',              'img'=>'/assets/images/products-r6.png'],
    ['name'=>'볼라드',                    'img'=>'/assets/images/products-r7.png'],
];
$demoNextSteps = [
    ['name'=>'인터로킹U형(보도블록)',       'img'=>'/assets/images/products-r1.png'],
    ['name'=>'장애인사각블록(점자블록)',    'img'=>'/assets/images/products-r2.png'],
    ['name'=>'화강경계도로',               'img'=>'/assets/images/products-r3.png'],
    ['name'=>'인조화강경계석',             'img'=>'/assets/images/products-r4.png'],
    ['name'=>'콘크리트경계석',             'img'=>'/assets/images/products-r8.png'],
    ['name'=>'녹지경계블록',              'img'=>'/assets/images/products-r9.png'],
    ['name'=>'볼라드',                    'img'=>'/assets/images/products-r10.png'],
];

render_header($pageTitle);
?>
<div class="content-wrap">

    <!-- ── 카테고리 상단 정보 ─────────────────────────────── -->
    <?php
    $hasCatSection = $category || $similar || $nextSteps || $demoMode;
    if ($hasCatSection):
        $catImg  = $category['image_url'] ?? '';
        // 대표이미지 없으면 해당 카테고리 첫 번째 제품 이미지 사용
        if (!$catImg && db() && $categoryId) {
            try {
                $ps = db()->prepare("SELECT image_url FROM products WHERE category_id = ? AND image_url IS NOT NULL AND image_url != '' AND is_active = 1 ORDER BY id LIMIT 1");
                $ps->execute([$categoryId]);
                $catImg = $ps->fetchColumn() ?: '';
            } catch (\Throwable $e) {}
        }
        $catName = $pageTitle;
        $catDesc = $category['description'] ?? '';
        // 소분류면 부모(중분류) 설명 사용
        if (!$catDesc && !empty($category['parent_id']) && !in_array((int)$category['parent_id'], [11,12,13,14,15]) && db()) {
            try {
                $ps = db()->prepare('SELECT description FROM categories WHERE id = ?');
                $ps->execute([(int)$category['parent_id']]);
                $catDesc = $ps->fetchColumn() ?: '';
            } catch (\Throwable $e) {}
        }

        $showSimilar   = $demoMode ? $demoSimilar   : $similar;
        $showNextSteps = $demoMode ? $demoNextSteps : $nextSteps;
    ?>
    <div class="pt-top-section">
        <?php if ($catImg): ?>
        <img src="<?= e($catImg) ?>" alt="<?= e($catName) ?>" class="pt-cat-img">
        <?php endif; ?>
        <div class="pt-cat-meta">
            <p class="pt-cat-name"><?= e($catName) ?></p>
            <?php if ($catDesc): ?>
                <p class="pt-cat-desc"><?= e($catDesc) ?></p>
            <?php endif; ?>
        </div>

        <?php if ($galleryImgs): ?>
        <div class="pt-gallery">
            <p class="pt-gallery-label">시공사례</p>
            <div class="pt-gallery-thumbs">
                <?php foreach ($galleryImgs as $gi): ?>
                <button class="pt-gallery-thumb" onclick="openGallery('<?= e($gi['image_url']) ?>','<?= e($gi['caption']) ?>')" type="button">
                    <img src="<?= e($gi['image_url']) ?>" alt="<?= e($gi['caption']) ?>">
                    <span class="pt-gallery-zoom"><svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg></span>
                </button>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($showSimilar || $showNextSteps):
            // 관련 제품 목록을 2개 서브컬럼(각 최대 4개)으로 렌더링하는 클로저
            $renderRelCols = function(array $items, string $label) use ($demoMode, $relatedImgs): void {
                $col1 = array_slice($items, 0, 4);
                $col2 = array_slice($items, 4, 4);
                $makeItem = function(array $cat) use ($demoMode, $relatedImgs): array {
                    if ($demoMode) {
                        return ['img' => $cat['img'], 'name' => $cat['name'], 'href' => '/products.php?q=' . urlencode($cat['name'])];
                    }
                    return ['img' => $relatedImgs[(int)$cat['id']] ?? null, 'name' => $cat['name'], 'href' => '/products.php?category=' . (int)$cat['id']];
                };
                ?>
                <div class="pt-related-col">
                    <p class="pt-related-label"><?= e($label) ?></p>
                    <div class="pt-related-subcols">
                        <ul class="pt-related-list">
                            <?php foreach ($col1 as $cat): $r = $makeItem($cat); ?>
                            <li>
                                <?php if ($r['img']): ?><img src="<?= e($r['img']) ?>" alt="" class="pt-rel-img"><?php else: ?><span class="pt-rel-dot"></span><?php endif; ?>
                                <a href="<?= e($r['href']) ?>"><?= e($r['name']) ?></a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if ($col2): ?>
                        <ul class="pt-related-list pt-related-list-right">
                            <?php foreach ($col2 as $cat): $r = $makeItem($cat); ?>
                            <li>
                                <?php if ($r['img']): ?><img src="<?= e($r['img']) ?>" alt="" class="pt-rel-img"><?php else: ?><span class="pt-rel-dot"></span><?php endif; ?>
                                <a href="<?= e($r['href']) ?>"><?= e($r['name']) ?></a>
                            </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php endif; ?>
                    </div>
                </div>
                <?php
            };
        ?>
        <div class="pt-related-wrap">
            <?php if ($showSimilar):   $renderRelCols($showSimilar,   '동종(유사)제품'); endif; ?>
            <?php if ($showNextSteps): $renderRelCols($showNextSteps, '공정연결제품');   endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- ── 품목보기 헤더 + 검색 ──────────────────────────── -->
    <div class="pt-table-header">
        <h3 class="pt-table-title">
            품목보기
            <?php if ($query && !$categoryId && !$demoMode): ?>
                <span class="pt-search-count">'<?= e($query) ?>' 검색결과 <strong><?= number_format($searchTotal) ?></strong>개</span>
            <?php endif; ?>
        </h3>
        <form class="pt-search-form" method="get" action="/products.php">
            <?php if ($categoryId): ?>
                <input type="hidden" name="category" value="<?= $categoryId ?>">
            <?php elseif ($query): ?>
                <input type="hidden" name="q" value="<?= e($query) ?>">
            <?php endif; ?>
            <input type="text" name="s" value="<?= e($search) ?>" placeholder="목록검색">
            <button type="submit">검색</button>
        </form>
    </div>

    <!-- ── 상품 테이블 ──────────────────────────────────────── -->
    <?php
    $tableRows = $demoMode ? $demoProducts : $products;

    // DB 모드: spec 미리 파싱 + 유효 컬럼 파악
    $parsedSpecs = [];
    $hasUnit = $hasSpec = $hasWeight = $hasLoad = false;
    if (!$demoMode) {
        foreach ($products as $i => $p) {
            $sp = parse_spec_field($p['spec'] ?? '');
            $parsedSpecs[$i] = $sp;
            if ($sp['unit'])   $hasUnit   = true;
            if ($sp['spec'])   $hasSpec   = true;
            if ($sp['weight']) $hasWeight = true;
            if ($sp['load'])   $hasLoad   = true;
        }
    }

    if (empty($tableRows)):
    ?>
        <div class="empty-state">
            <?php if ($query): ?>
                <p class="empty-title">'<strong><?= e($query) ?></strong>'에 대한 검색 결과가 없습니다.</p>
                <p class="empty-hint">검색어의 띄어쓰기를 확인하거나, 더 짧은 키워드로 검색해 보세요.</p>
                <div class="empty-suggest">
                    <?php foreach (['측구', '그레이팅', '수로관', '경계석', '맨홀', '볼라드'] as $sg): ?>
                        <a class="empty-chip" href="/products.php?q=<?= urlencode($sg) ?>"><?= e($sg) ?></a>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                검색 결과가 없습니다.
            <?php endif; ?>
        </div>
    <?php else: ?>
    <div class="product-table-wrap">
        <table class="product-table">
            <thead>
                <tr>
                    <th class="pt-col-photo">사진</th>
                    <th class="pt-col-name">품명<span>직생제외</span></th>
                    <?php if ($demoMode || $hasUnit):    ?><th class="pt-col-unit">단위</th><?php endif; ?>
                    <?php if ($demoMode || $hasSpec):    ?><th class="pt-col-spec">규격<span>가로*세로*두께</span></th><?php endif; ?>
                    <?php if ($demoMode):                ?><th class="pt-col-specinfo">규격외정보</th><?php endif; ?>
                    <?php if ($demoMode):                ?><th class="pt-col-color">색상</th><?php endif; ?>
                    <th class="pt-col-price">판매가<span>부가세별도</span></th>
                    <?php if ($demoMode || $hasWeight):  ?><th class="pt-col-weight">중량<span>kg/m²</span></th><?php endif; ?>
                    <?php if ($demoMode || $hasLoad):    ?><th class="pt-col-load">상차수량<span>본/25톤</span></th><?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php if ($demoMode): ?>
                    <?php foreach ($demoProducts as $p): ?>
                    <tr>
                        <td class="pt-col-photo">
                            <img src="/assets/images/products-prod.png" alt="<?= e($p['name']) ?>">
                        </td>
                        <td class="pt-col-name">
                            <span class="pt-name-text"><?= e($p['name']) ?></span>
                            <?php if ($p['brand']): ?>
                                <small><?= e($p['brand']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="pt-col-unit">m²</td>
                        <td class="pt-col-spec"><?= e($p['spec']) ?></td>
                        <td class="pt-col-specinfo"><?= e($p['specinfo']) ?></td>
                        <td class="pt-col-color">
                            <div class="pt-color-dots">
                                <?php foreach ($p['colors'] as $cname):
                                    $hex = $colorHex[$cname] ?? '#ccc';
                                ?>
                                <span class="pt-color-dot" style="background:<?= $hex ?>" title="<?= e($cname) ?>"></span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <td class="pt-col-price">
                            <?php if ($loggedIn): ?>
                            <strong class="pt-price-val"><?= number_format($p['price']) ?></strong>
                            <?php else: ?>
                            <span class="pt-price-lock" onclick="openAuthModal('login')">로그인 후 공개</span>
                            <?php endif; ?>
                        </td>
                        <td class="pt-col-weight"><?= $p['weight'] ?></td>
                        <td class="pt-col-load"><?= $p['load'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <?php foreach ($products as $i => $p): ?>
                    <?php $sp = $parsedSpecs[$i]; ?>
                    <tr class="pt-prod-row" onclick="location.href='/product.php?id=<?= (int)$p['id'] ?>'" style="cursor:pointer">
                        <td class="pt-col-photo">
                            <?php if (!empty($p['image_url'])): ?>
                                <img src="<?= e($p['image_url']) ?>" alt="<?= e($p['name']) ?>">
                            <?php else: ?>
                                <span class="pt-placeholder"><?= e(mb_substr($p['name'], 0, 2)) ?></span>
                            <?php endif; ?>
                        </td>
                        <td class="pt-col-name"><span class="pt-name-text"><?= $hlTerms ? highlight_terms($p['name'], $hlTerms) : e($p['name']) ?></span></td>
                        <?php if ($hasUnit):    ?><td class="pt-col-unit"><?= e($sp['unit']) ?></td><?php endif; ?>
                        <?php if ($hasSpec):    ?><td class="pt-col-spec"><?= $hlTerms ? highlight_terms($sp['spec'], $hlTerms) : e($sp['spec']) ?></td><?php endif; ?>
                        <td class="pt-col-price">
                            <?php if (!$loggedIn): ?>
                            <span class="pt-price-lock" onclick="openAuthModal('login')">로그인 후 공개</span>
                            <?php elseif ($p['price_b2c'] > 0): ?>
                            <strong class="pt-price-val"><?= number_format($p['price_b2c']) ?></strong>
                            <?php else: ?>–<?php endif; ?>
                        </td>
                        <?php if ($hasWeight):  ?><td class="pt-col-weight"><?= e($sp['weight']) ?></td><?php endif; ?>
                        <?php if ($hasLoad):    ?><td class="pt-col-load"><?= e($sp['load']) ?></td><?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
    // ── 검색 결과 페이지네이션 ──────────────────────────────
    if ($query && !$categoryId && $searchTotal > $perPage):
        $totalPages = (int) ceil($searchTotal / $perPage);
        $qenc  = urlencode($query);
        $mkUrl = fn(int $pg): string => "/products.php?q={$qenc}&p={$pg}";
        $start = max(1, $page - 2);
        $end   = min($totalPages, $start + 4);
        $start = max(1, $end - 4);
    ?>
    <nav class="pt-pagination" aria-label="검색 결과 페이지">
        <?php if ($page > 1): ?><a class="pt-page" href="<?= e($mkUrl($page - 1)) ?>">이전</a><?php endif; ?>
        <?php if ($start > 1): ?>
            <a class="pt-page" href="<?= e($mkUrl(1)) ?>">1</a>
            <?php if ($start > 2): ?><span class="pt-page-gap">…</span><?php endif; ?>
        <?php endif; ?>
        <?php for ($pg = $start; $pg <= $end; $pg++): ?>
            <a class="pt-page<?= $pg === $page ? ' pt-page-cur' : '' ?>" href="<?= e($mkUrl($pg)) ?>"><?= $pg ?></a>
        <?php endfor; ?>
        <?php if ($end < $totalPages): ?>
            <?php if ($end < $totalPages - 1): ?><span class="pt-page-gap">…</span><?php endif; ?>
            <a class="pt-page" href="<?= e($mkUrl($totalPages)) ?>"><?= $totalPages ?></a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?><a class="pt-page" href="<?= e($mkUrl($page + 1)) ?>">다음</a><?php endif; ?>
    </nav>
    <?php endif; /* 페이지네이션 */ ?>
    <?php endif; /* empty($tableRows) */ ?>

</div>

<!-- 시공사례 확대보기 모달 -->
<div id="gallery-modal" class="modal-overlay" style="display:none" onclick="if(event.target===this)this.style.display='none'">
    <div class="gallery-modal-inner">
        <button class="gallery-modal-close" onclick="document.getElementById('gallery-modal').style.display='none'" type="button"><svg xmlns="http://www.w3.org/2000/svg" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></button>
        <img id="gallery-modal-img" src="" alt="" class="gallery-modal-img">
        <p id="gallery-modal-cap" class="gallery-modal-cap"></p>
    </div>
</div>
<script>
function openGallery(url, cap) {
    document.getElementById('gallery-modal-img').src = url;
    document.getElementById('gallery-modal-cap').textContent = cap;
    document.getElementById('gallery-modal').style.display = 'flex';
}
</script>

<?php render_footer(); ?>
