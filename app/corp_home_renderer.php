<?php
/**
 * 기업회원 메인화면(빠른 견적하기) UI 렌더링 전용
 * 호출자: public/index.php (기업회원 홈 — render_header('홈', 'corp') 이후)
 * 의존: $corpTree (corp_home_category_tree), $corpRegulars (corp_home_regulars), $corpHistory (corp_home_history)
 * 스타일·동작: public/assets/corp-home.css(헤더 corp 모드에서 로드), public/assets/corp-home.js
 * 금지: DB 쿼리, API 호출, 세션 쓰기
 *
 * 동작 API(기존 재사용):
 *  - 상품 목록: GET  /api/products/by-category.php
 *  - 담은 품목: POST /api/estimate/quick-items.php (즉시견적 세션 — 견적서 작성 /estimate.php 와 연결)
 *  - 불러오기:  POST /api/estimate/reorder.php
 */

$corpHomeData = [
    'lv1'      => $corpTree['lv1'],
    'byParent' => $corpTree['byParent'],
    'regulars' => $corpRegulars,
    'history'  => $corpHistory['items'],
    'historyHasMore' => $corpHistory['has_more'],
];
$corpChips = ['수로관', '암거', '그레이팅', '맨홀', 'PE이중벽관'];
$corpChevron = '<svg class="ch-select-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg>';
$docRootCh = $_SERVER['DOCUMENT_ROOT'] ?? '';
$corpJsV   = ($docRootCh ? @filemtime($docRootCh . '/assets/corp-home.js') : false) ?: '1';
?>
<div class="ch">

    <!-- 히어로 + 검색 -->
    <section class="ch-hero">
        <h1 class="ch-hero-title">현장에서 바로 쓰는 건축자재</h1>
        <p class="ch-hero-sub">품목·규격을 찾고 필요한 수량으로 견적을 구성하세요</p>
        <form class="ch-search" role="search" method="get" action="/products.php" data-suggest-anchor>
            <label for="ch-q" class="ch-sr">자재 검색</label>
            <input id="ch-q" type="search" name="q" placeholder="전체 자재 검색 — 품명 또는 규격" autocomplete="off" data-suggest>
            <button type="submit">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" aria-hidden="true"><circle cx="11" cy="11" r="6.5"></circle><path d="M20 20l-4.2-4.2"></path></svg>
                검색
            </button>
        </form>
        <div class="ch-chips">
            <?php foreach ($corpChips as $chip): ?>
            <a href="/products.php?q=<?= urlencode($chip) ?>"><?= e($chip) ?></a>
            <?php endforeach; ?>
        </div>
    </section>

    <h2 class="ch-title">빠른 견적하기</h2>

    <div class="ch-quick">

        <!-- 좌측: 상품 선택 -->
        <section class="ch-panel ch-pick" aria-label="상품 선택">
            <div class="ch-tabs" role="tablist" aria-label="상품 선택 방식">
                <button type="button" role="tab" id="ch-tab-new" class="ch-tab" aria-selected="true" aria-controls="ch-pane-new">신규 선택</button>
                <button type="button" role="tab" id="ch-tab-fav" class="ch-tab" aria-selected="false" aria-controls="ch-pane-fav" tabindex="-1">단골품목</button>
            </div>

            <!-- 신규 선택 탭 -->
            <div id="ch-pane-new" class="ch-pane" role="tabpanel" aria-labelledby="ch-tab-new">
                <div class="ch-sel-grid">
                    <div class="ch-field">
                        <label for="ch-sel1">대분류</label>
                        <div class="ch-select"><select id="ch-sel1"></select><?= $corpChevron ?></div>
                    </div>
                    <div class="ch-field">
                        <label for="ch-sel2">중분류</label>
                        <div class="ch-select"><select id="ch-sel2"></select><?= $corpChevron ?></div>
                    </div>
                    <div class="ch-field">
                        <label for="ch-sel3">품목</label>
                        <div class="ch-select"><select id="ch-sel3"></select><?= $corpChevron ?></div>
                    </div>
                </div>

                <div class="ch-list-head">
                    <h3 class="ch-sub-title">규격별 상품 <span class="ch-count" id="ch-prod-count">총 0개</span></h3>
                    <button type="button" class="ch-btn-flt" id="ch-flt-toggle" aria-expanded="true" aria-controls="ch-flt" hidden>
                        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="M4 6h16M7 12h10M10 18h4"></path></svg>
                        규격 필터
                        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path id="ch-flt-chevron" d="M6 15l6-6 6 6"></path></svg>
                    </button>
                </div>

                <div id="ch-flt" class="ch-flt" hidden>
                    <div class="ch-flt-grid" id="ch-flt-grid"></div>
                    <div class="ch-flt-actions">
                        <button type="button" class="ch-btn-text" id="ch-flt-reset">초기화</button>
                        <button type="button" class="ch-btn-primary" id="ch-flt-apply">적용</button>
                    </div>
                </div>

                <div class="ch-table-wrap">
                    <table class="ch-table ch-table--prod">
                        <caption class="ch-sr">규격별 상품 목록</caption>
                        <colgroup><col class="c-photo"><col><col class="c-unit"><col class="c-price"><col class="c-qty"><col class="c-sel"></colgroup>
                        <thead><tr><th scope="col">사진</th><th scope="col" class="t-left">품명·규격</th><th scope="col">단위</th><th scope="col">단가</th><th scope="col">수량</th><th scope="col">선택</th></tr></thead>
                        <tbody id="ch-prod-body"><tr><td colspan="6" class="ch-empty">불러오는 중...</td></tr></tbody>
                    </table>
                </div>
                <nav class="ch-pager" id="ch-prod-pager" aria-label="상품 페이지"></nav>
            </div>

            <!-- 단골품목 탭 -->
            <div id="ch-pane-fav" class="ch-pane" role="tabpanel" aria-labelledby="ch-tab-fav" hidden>
                <h3 class="ch-sub-title ch-fav-title">단골품목 <span class="ch-count" id="ch-fav-count">총 0개</span></h3>
                <div class="ch-table-wrap">
                    <table class="ch-table ch-table--fav">
                        <caption class="ch-sr">단골품목 목록 (매입횟수 많은 순)</caption>
                        <colgroup><col class="c-photo"><col><col class="c-unit"><col class="c-price"><col class="c-cnt"><col class="c-date"><col class="c-qty"><col class="c-sel"></colgroup>
                        <thead><tr><th scope="col">사진</th><th scope="col" class="t-left">품명·규격</th><th scope="col">단위</th><th scope="col">단가</th><th scope="col">매입횟수</th><th scope="col">최근 매입일</th><th scope="col">수량</th><th scope="col">선택</th></tr></thead>
                        <tbody id="ch-fav-body"></tbody>
                    </table>
                </div>
                <nav class="ch-pager" id="ch-fav-pager" aria-label="단골품목 페이지"></nav>
            </div>
        </section>

        <!-- 우측: 담은 품목 + 이전 견적서 -->
        <div class="ch-side">
            <section class="ch-panel ch-cart" aria-label="담은 품목">
                <h3 class="ch-sub-title">담은 품목 <span class="ch-count ch-count--primary" id="ch-cart-count">총 0개</span></h3>
                <div class="ch-table-wrap ch-table-wrap--cart">
                    <table class="ch-table ch-table--cart">
                        <caption class="ch-sr">담은 품목 목록</caption>
                        <colgroup><col><col class="c-qty"><col class="c-amt"><col class="c-del"></colgroup>
                        <thead><tr><th scope="col" class="t-left"><span class="ch-cart-photo-h">사진</span>품목·규격</th><th scope="col">수량</th><th scope="col">금액</th><th scope="col"><span class="ch-sr">삭제</span></th></tr></thead>
                        <tbody id="ch-cart-body"><tr><td colspan="4" class="ch-empty">불러오는 중...</td></tr></tbody>
                    </table>
                </div>
                <nav class="ch-pager ch-pager--cart" id="ch-cart-pager" aria-label="담은 품목 페이지"></nav>
                <div class="ch-sum">
                    <div>
                        <div class="ch-sum-label">전체 상품 합계</div>
                        <div class="ch-sum-note" id="ch-sum-basis">총 0개 품목 기준</div>
                    </div>
                    <div class="ch-sum-right">
                        <div class="ch-sum-total" id="ch-sum-total">0원</div>
                        <div class="ch-sum-note">부가세·운송비 별도</div>
                    </div>
                </div>
                <a href="/estimate.php" class="ch-btn-submit" id="ch-submit" aria-disabled="true">견적서 작성하기 →</a>
                <p class="ch-submit-note" id="ch-submit-note">다음 화면에서 배송·신청 정보를 입력합니다.</p>
            </section>

            <section class="ch-panel ch-history" aria-label="이전 견적서">
                <h3 class="ch-sub-title ch-history-title">이전 견적서</h3>
                <ul class="ch-history-list" id="ch-history-list"></ul>
                <button type="button" class="ch-btn-more" id="ch-history-more" hidden>
                    이전 견적서 더 보기
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"></path></svg>
                </button>
                <a href="/my-estimates.php" class="ch-btn-more" id="ch-history-all" hidden>전체 견적 내역 보기</a>
            </section>
        </div>
    </div>
</div>
<p class="ch-sr" id="ch-live" aria-live="polite"></p>

<script type="application/json" id="ch-data"><?= json_encode($corpHomeData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<script src="/assets/corp-home.js?v=<?= e((string) $corpJsV) ?>" defer></script>
