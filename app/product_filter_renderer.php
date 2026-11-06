<?php
declare(strict_types=1);
/**
 * 상품 목록 좌측 파라메트릭 필터 렌더러 (동적 파세트).
 *  - render_product_filters($active, $q, $categoryId, $sort, $facets)
 *  - product_filters_visible($facets, $active): 표시할 그룹/옵션만 계산(호출측 aside 노출 판단용)
 *  - product_list_url($get, $set): 현재 목록 조건을 유지한 products.php URL(페이지·칩 해제용)
 *  현재 결과 집합에 실제 존재하는 옵션만 개수와 함께 노출한다.
 *  0건 옵션·단일값 수렴(비선택·옵션1개·전체차지) 그룹은 숨긴다 → 결과와 무관한 필터 노출 방지.
 *  재고(instock) 섹션은 항상 표시. 모든 체크박스는 '필터 적용' 버튼으로만 반영된다(즉시 제출 없음).
 *  규격: 결과 카테고리가 같은 규격 이름표(category_spec_axes)를 쓰면 원문 목록 대신 축별 값 버튼(product_filter_axis_groups).
 */

const FLT_MAT_LABELS = [11 => '철근콘크리트', 12 => '콘크리트', 13 => '스틸', 14 => '석재', 15 => 'PE/PVC', 16 => '기타'];
const FLT_SHAPES     = ['사각', '원형', 'U형', '유공', '앵글부착', '인터로킹'];
const FLT_LOADS      = ['중하중', '안전중하중'];

/**
 * 파세트 + 현재 선택 상태 → 실제로 표시할 옵션 구조.
 * 반환: ['mat'=>[{val,cnt,active}], 'shape'=>[...], 'load'=>[...], 'spec'=>[{val,cnt,active}],
 *        'total'=>int, 'any'=>bool]
 */
function product_filters_visible(array $facets, array $active): array
{
    $total = (int) ($facets['total'] ?? 0);

    // 그룹 옵션 선택: cnt>0 또는 이미 선택된(active) 옵션만. 비선택·옵션1개·전체차지면 숨김(좁힐 의미 없음).
    $pick = static function (array $counts, array $sel, array $order) use ($total): array {
        $sel  = array_map('strval', $sel);
        $opts = [];
        foreach ($order as $val) {
            $cnt      = (int) ($counts[$val] ?? $counts[(string) $val] ?? 0);
            $isActive = in_array((string) $val, $sel, true);
            if ($cnt > 0 || $isActive) {
                $opts[] = ['val' => $val, 'cnt' => $cnt, 'active' => $isActive];
            }
        }
        $anyActive = false;
        foreach ($opts as $o) { if ($o['active']) { $anyActive = true; break; } }
        if (!$anyActive && count($opts) === 1 && $total > 0 && $opts[0]['cnt'] >= $total) {
            return []; // 단일값 수렴 → 좁힐 수 없으므로 그룹 숨김
        }
        return $opts;
    };

    $matOpts = $pick($facets['mat'] ?? [], $active['mat'] ?? [], array_keys(FLT_MAT_LABELS));
    $shOpts  = $pick($facets['shape'] ?? [], $active['shape'] ?? [], FLT_SHAPES);
    $ldOpts  = $pick($facets['load'] ?? [], $active['load'] ?? [], FLT_LOADS);

    // 규격: 동적 옵션(카테고리에 실제 존재하는 규격 값). 자연정렬된 키 순서 사용.
    $specCounts = $facets['spec'] ?? [];
    $specOpts   = $pick($specCounts, $active['spec'] ?? [], array_keys($specCounts));
    // 옵션 과다 방지: 최대 40개(선택된 것은 우선 유지)
    if (count($specOpts) > 40) {
        $sel  = array_values(array_filter($specOpts, static fn($o) => $o['active']));
        $rest = array_slice(array_values(array_filter($specOpts, static fn($o) => !$o['active'])), 0, 40 - count($sel));
        $specOpts = array_merge($sel, $rest);
    }

    // 규격 축 모드(카테고리 이름표 category_spec_axes): 규격 원문 목록 대신 축(형식·폭 W·높이 H…)별 값 버튼
    $axGroups = product_filter_axis_groups($facets['axes'] ?? [], $active);
    if ($axGroups) { $specOpts = []; }

    return [
        'mat'       => $matOpts,
        'shape'     => $shOpts,
        'load'      => $ldOpts,
        'spec'      => $specOpts,
        'axes'      => $axGroups,
        'total'     => $total,
        'any'       => (bool) ($matOpts || $shOpts || $ldOpts || $specOpts || $axGroups),
    ];
}

/**
 * 규격 축 파세트 → 필터 그룹 목록. 그룹: ['field'=>GET 이름, 'title'(이름), 'sub'(약자), 'unit'('(mm)'), 'opts'=>[{val,label,cnt,checked,disabled}]]
 *  - 모델 코드(규격 코드 끝 글자 B·C)는 코드가 있을 때만, 이어서 이름표 seq 순(use_filter=0 축 제외)
 *  - 결과 전체에 값이 하나뿐인 축: 선택된 채 고정(누를 수 없음, 제출 안 됨 — 좁힐 게 없다는 표시)
 *  - 다른 선택 때문에 0건이 되는 값: 누를 수 없게(이미 선택한 값은 해제 가능)
 */
function product_filter_axis_groups(array $axes, array $active): array
{
    if (empty($axes['defs'])) { return []; }
    $mk = static function (string $field, string $title, string $sub, string $unit, array $all, array $cnts, array $sel, callable $label): array {
        $sel   = array_map('strval', $sel);
        $fixed = count($all) === 1;
        $opts  = [];
        foreach ($all as $v) {
            $v   = (string) $v;
            $cnt = (int) ($cnts[$v] ?? 0);
            $on  = in_array($v, $sel, true);
            $opts[] = ['val' => $v, 'label' => $label($v), 'cnt' => $cnt, 'checked' => $on || $fixed, 'disabled' => $fixed || ($cnt === 0 && !$on)];
        }
        return ['field' => $field, 'title' => $title, 'sub' => $sub, 'unit' => $unit, 'fixed' => $fixed, 'opts' => $opts];
    };
    $groups = [];
    // 아트보드 '규격 개선안' 기준: 그룹 이름(한글) 옆에 작은 약자, 더 작은 단위 — 폭 W(mm). 모델 코드는 '끝자리 표기'
    if (!empty($axes['type_all'])) {
        $groups[] = $mk('ax_' . SPEC_AXIS_TYPE, '모델 코드', '끝자리 표기', '', $axes['type_all'], $axes['type'] ?? [],
            (array) ($active['ax_type'] ?? []), static fn(string $v): string => $v . '형');
    }
    foreach ($axes['defs'] as $a) {
        $k = (string) $a['axis_key'];
        if (empty($a['use_filter']) || empty($axes['all'][$k])) { continue; }
        $unit = (string) $a['unit'];
        $groups[] = $mk('ax_' . $k, (string) $a['label'], spec_axis_symbol($a), spec_axis_unit_label($a), $axes['all'][$k], $axes['vals'][$k] ?? [],
            (array) ($active['ax'][$k] ?? []), static fn(string $v): string => spec_axis_display((float) $v, $unit));
    }
    return $groups;
}

/**
 * 현재 목록 조건(GET 배열) → /products.php URL.
 *  - 'p'(페이지)는 항상 제거 → 조건이 바뀌면 1페이지부터. 페이지 링크는 $set['p']로 다시 지정.
 *  - $set: 덮어쓸 값. null 또는 빈 배열이면 해당 키 제거(예: ['instock' => null]).
 * 출력 시 e()로 이스케이프할 것.
 */
function product_list_url(array $get, array $set = []): string
{
    unset($get['p']);
    foreach ($set as $k => $v) {
        if ($v === null || $v === []) { unset($get[$k]); } else { $get[$k] = $v; }
    }
    $qs = http_build_query($get, '', '&', PHP_QUERY_RFC3986);
    return '/products.php' . ($qs !== '' ? '?' . $qs : '');
}

/** 적용된(URL 에 반영된) 필터 값 개수 — 접을 수 있는 필터를 펼친 채 시작할지·초기화 버튼을 켤지 판단용
 *  (제목줄 'N개 적용' 표시는 사용자 결정 2026-11-06 으로 없앰) */
function product_filters_applied_count(array $a, bool $inStock = false): int
{
    $n = 0;
    foreach (['mat', 'spec', 'shape', 'load', 'ax_type'] as $k) { $n += count((array) ($a[$k] ?? [])); }
    foreach ((array) ($a['ax'] ?? []) as $vals) { $n += count((array) $vals); }
    return $n + ($inStock ? 1 : 0);
}

/**
 * $keep: 필터 적용·초기화 후에도 유지할 추가 GET 값(예: 작업별 목록의 ['work' => 키])
 * $collapsible: 상황별 자재 목록(‘자재 종류’ 패널이 함께 보이는 화면)의 비로그인·개인회원 — 데스크톱에서도 제목줄
 *               '자재 규격'으로 펼치기/접기(기본 접힘, 적용된 필터가 있으면 펼친 채 시작). 그 밖의 목록·기업회원은 false
 *               (지금처럼 데스크톱은 항상 펼침). 모바일(760px 이하)은 모두 접힌 채 시작.
 *               이 필터만: 초기화는 맨 아래 '필터 적용' 옆 버튼(고른 값이 없으면 비활성),
 *               규격 값 버튼은 누르는 즉시(적용 전) 함께 고른 값·재고 체크와 맞는 상품이 없는 값을 비활성(아트보드 기준).
 */
function render_product_filters(array $a, ?string $q, ?int $categoryId, string $sort = '', ?array $facets = null, bool $inStock = false, array $keep = [], bool $collapsible = false): void
{
    // 하위호환: 파세트 미제공 시 전체 옵션 노출(개수 없음)
    if ($facets === null) {
        $facets = [
            'mat'   => array_fill_keys(array_keys(FLT_MAT_LABELS), 1),
            'shape' => array_fill_keys(FLT_SHAPES, 1),
            'load'  => array_fill_keys(FLT_LOADS, 1),
            'spec'  => [],
            'total' => 0,
        ];
    }
    $v = product_filters_visible($facets, $a);
    // 파세트 그룹이 없어도 재고 섹션은 노출(재고 필터는 결과 집합과 무관하게 항상 의미 있음)

    $base = $q !== null && $q !== '' ? '/products.php?q=' . urlencode($q) : '/products.php?category=' . (int) $categoryId;
    if ($keep) { $base .= '&' . http_build_query($keep); }
    // $cnt: null 이면 개수 표시 없음(규격 원문 목록 — 사용자 결정 2026-11-06)
    $opt  = static function (string $field, $val, string $label, ?int $cnt, bool $active): void { ?>
        <label class="fopt"><input type="checkbox" name="<?= e($field) ?>[]" value="<?= e((string) $val) ?>" <?= $active ? 'checked' : '' ?>><span class="fopt-lbl"><?= e($label) ?></span><?php if ($cnt !== null && $cnt > 0): ?><span class="fcnt"><?= $cnt ?></span><?php endif; ?></label>
    <?php };
    $nApplied = product_filters_applied_count($a, $inStock);
    // 누르는 즉시 비활성(접는 필터의 규격 값 버튼): 축 외 적용 필터만 반영한 값 조합(product_facets 'combos')
    $combos = ($collapsible && $v['axes']) ? ($facets['axes']['combos'] ?? null) : null;
    ?>
    <details class="fltbox<?= $collapsible ? ' is-collapsible' : '' ?>"<?= (!$collapsible || $nApplied > 0) ? ' open' : '' ?>>
      <?php // 접는 필터(상황별 목록)는 위 '자재 종류' 제목과 같은 모양의 '자재 규격' + 화살표(⚙·'펼치기'·'N개 적용' 글자 없음) ?>
      <summary><span class="flt-sum-title"><?= $collapsible ? '자재 규격' : '필터로 좁히기' ?></span><?php if ($collapsible): ?><span class="flt-sum-state" aria-hidden="true"></span><?php endif; ?></summary>
      <form class="flt-body" method="get" action="/products.php"<?= $collapsible ? ' data-applied="' . ($nApplied > 0 ? '1' : '0') . '"' : '' ?><?= $combos ? ' data-live="1"' : '' ?>>
        <?php if ($combos): ?><script type="application/json" class="flt-combos"><?= json_encode($combos, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
        <?php if ($q !== null && $q !== ''): ?>
          <input type="hidden" name="q" value="<?= e($q) ?>">
        <?php elseif ($categoryId): ?>
          <input type="hidden" name="category" value="<?= (int) $categoryId ?>">
        <?php endif; ?>
        <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
        <?php foreach ($keep as $k => $kv): ?><input type="hidden" name="<?= e((string) $k) ?>" value="<?= e((string) $kv) ?>"><?php endforeach; ?>

        <?php if (!$collapsible): ?><div class="flt-top"><b>필터로 좁히기</b><a href="<?= e($base) ?>">초기화</a></div><?php endif; ?>

        <?php if ($v['mat']): ?>
        <div class="fgrp"><b>재질</b>
          <?php foreach ($v['mat'] as $o) { $opt('mat', $o['val'], FLT_MAT_LABELS[$o['val']] ?? (string) $o['val'], $o['cnt'], $o['active']); } ?>
        </div>
        <?php endif; ?>

        <?php if ($v['axes']): ?>
        <div class="fgrp fgrp-axes">
          <?php foreach ($v['axes'] as $g): $gid = 'fax-' . $g['field']; ?>
          <div class="fax" role="group" aria-labelledby="<?= e($gid) ?>">
            <div class="fax-title" id="<?= e($gid) ?>"><?= e($g['title']) ?><?php if ($g['sub'] !== '' || $g['unit'] !== ''): ?> <small><?= e($g['sub']) ?><?php if ($g['unit'] !== ''): ?><span><?= e($g['unit']) ?></span><?php endif; ?></small><?php endif; ?></div>
            <div class="fchips">
              <?php foreach ($g['opts'] as $o): ?>
              <label class="fchip"<?= $g['fixed'] ? ' title="모든 상품이 같은 값"' : '' ?>><input type="checkbox" name="<?= e($g['field']) ?>[]" value="<?= e($o['val']) ?>"<?= $o['checked'] ? ' checked' : '' ?><?= $o['disabled'] ? ' disabled' : '' ?><?= $g['fixed'] ? ' data-fixed="1"' : '' ?>><span class="fchip-box"><?= e($o['label']) ?></span></label>
              <?php endforeach; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($v['spec']): ?>
        <div class="fgrp"><b>규격 <small>(가로×세로×두께 등)</small></b>
          <div class="fgrp-scroll spec-scroll">
          <?php foreach ($v['spec'] as $o) {
              $lbl = str_replace(['*', 'X', 'x'], '×', (string) $o['val']); // 표시용: 곱셈기호 통일
              $opt('spec', $o['val'], $lbl, null, $o['active']);
          } ?>
          </div>
        </div>
        <?php endif; ?>

        <?php if ($v['shape']): ?>
        <div class="fgrp"><b>형상 / 옵션</b>
          <?php foreach ($v['shape'] as $o) { $opt('shape', $o['val'], (string) $o['val'], $o['cnt'], $o['active']); } ?>
        </div>
        <?php endif; ?>

        <?php if ($v['load']): ?>
        <div class="fgrp"><b>하중 등급 <small>(그레이팅류)</small></b>
          <?php foreach ($v['load'] as $o) { $opt('load', $o['val'], (string) $o['val'], $o['cnt'], $o['active']); } ?>
        </div>
        <?php endif; ?>

        <?php if ($v['any']): ?><hr class="flt-div"><?php endif; ?>
        <div class="fgrp fgrp-stock"><b>재고</b>
          <label class="fopt"><input type="checkbox" name="instock" value="1" <?= $inStock ? 'checked' : '' ?>><span class="fopt-lbl">재고 있는 상품만 보기</span></label>
        </div>

        <?php if ($collapsible): ?>
        <?php // 초기화: 맨 위 글자 링크 → 맨 아래 '필터 적용' 옆 보조 버튼(아트보드 기준). 적용·선택한 값이 없으면 비활성 ?>
        <div class="flt-actions">
          <a class="flt-reset" href="<?= e($base) ?>" role="button"<?= $nApplied > 0 ? '' : ' aria-disabled="true" tabindex="-1"' ?>><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 12a9 9 0 1 0 3-6.7"/><path d="M3 4v5h5"/></svg>초기화</a>
          <button type="submit" class="flt-apply">필터 적용</button>
        </div>
        <?php else: ?>
        <button type="submit" class="flt-apply">필터 적용</button>
        <?php endif; ?>
      </form>
    </details>
    <script>
    // 모바일(760px 이하, 필터 제목줄이 보이는 폭): 필터를 접은 상태로 시작 → 상품 목록이 바로 보이게. 제목줄을 누르면 펼침.
    // 데스크톱은 CSS가 제목줄을 숨기고 항상 펼쳐 둔다(open 유지). 적용된 필터는 목록 위 칩으로 표시된다.
    // 비로그인·개인회원(.is-collapsible)은 데스크톱도 제목줄(펼치기/접기)이 보이고, 기본 접힘(서버가 open 을 정함).
    (function () {
        var box = document.currentScript && document.currentScript.previousElementSibling;
        if (box && box.tagName === 'DETAILS' && window.matchMedia && window.matchMedia('(max-width: 760px)').matches) { box.open = false; }
    })();
    </script>
    <script>
    // 선택 중(미적용) 상태 표시: 체크 상태가 적용된 상태(checked 속성)와 다르면 버튼 강조. 제출은 버튼으로만.
    (function () {
        var form = document.querySelector('form.flt-body');
        if (!form) { return; }
        var boxes = form.querySelectorAll('input[type="checkbox"]');
        var btn   = form.querySelector('.flt-apply');
        function sync() {
            var dirty = false;
            for (var i = 0; i < boxes.length; i++) {
                if (boxes[i].checked !== boxes[i].defaultChecked) { dirty = true; break; }
            }
            btn.classList.toggle('is-dirty', dirty);
        }
        form.addEventListener('change', sync);
        window.addEventListener('pageshow', sync); // 뒤로가기(bfcache) 복원 시 재계산
    })();
    </script>
    <?php if ($collapsible): ?>
    <script>
    // 접는 필터(상황별 목록 비로그인·개인회원) 전용
    //  1) 규격 값 버튼: 누르는 즉시(적용 전) 다른 축에서 고른 값·재고 체크와 함께 맞는 상품이 없는 값은 비활성.
    //     같은 축 안은 '또는', 축끼리는 '그리고'(서버 product_facets 와 같은 기준). 이미 고른 값은 해제할 수 있게 둔다.
    //  2) 초기화 버튼: 적용된 필터가 있으면 처음 목록으로 이동(펼친 채 다시 열림), 없으면 고른 값만 지움. 둘 다 없으면 비활성.
    (function () {
        var form = document.querySelector('.fltbox.is-collapsible form.flt-body');
        if (!form) { return; }
        var box = form.parentNode;
        var OPEN_KEY = 'tm-flt-reopen';
        try { if (sessionStorage.getItem(OPEN_KEY) === '1') { sessionStorage.removeItem(OPEN_KEY); box.open = true; } } catch (e) {}

        var stock = form.querySelector('input[name="instock"]');
        var keys = [], rows = [], groups = [];
        var dataEl = form.querySelector('script.flt-combos');
        if (dataEl) {
            try { var d = JSON.parse(dataEl.textContent); keys = d.keys || []; rows = d.rows || []; } catch (e) { keys = []; }
            groups = keys.map(function (k) { return Array.prototype.slice.call(form.querySelectorAll('input[name="ax_' + k + '[]"]')); });
        }
        function live() {
            if (!keys.length) { return; }
            var sel = groups.map(function (ins) {
                return ins.filter(function (i) { return i.checked && !i.hasAttribute('data-fixed'); }).map(function (i) { return i.value; });
            });
            var stockOnly = !!(stock && stock.checked);
            groups.forEach(function (ins, gi) {
                ins.forEach(function (inp) {
                    if (inp.hasAttribute('data-fixed')) { return; }
                    var hit = rows.some(function (r) {
                        if (stockOnly && !r[keys.length]) { return false; }
                        if (String(r[gi]) !== inp.value) { return false; }
                        for (var j = 0; j < keys.length; j++) {
                            if (j !== gi && sel[j].length && sel[j].indexOf(String(r[j])) === -1) { return false; }
                        }
                        return true;
                    });
                    inp.disabled = !hit && !inp.checked;
                    if (inp.disabled) { inp.parentNode.title = '선택한 조건에 맞는 상품 없음'; } else { inp.parentNode.removeAttribute('title'); }
                });
            });
        }

        var reset = form.querySelector('.flt-reset');
        var applied = form.getAttribute('data-applied') === '1';
        function picked() {
            return Array.prototype.some.call(form.querySelectorAll('input[type="checkbox"]'), function (i) { return i.checked && !i.disabled; });
        }
        function syncReset() {
            if (!reset) { return; }
            var off = !applied && !picked();
            if (off) { reset.setAttribute('aria-disabled', 'true'); reset.setAttribute('tabindex', '-1'); }
            else { reset.removeAttribute('aria-disabled'); reset.removeAttribute('tabindex'); }
        }
        if (reset) {
            reset.addEventListener('click', function (ev) {
                if (reset.getAttribute('aria-disabled') === 'true') { ev.preventDefault(); return; }
                if (applied) { try { sessionStorage.setItem(OPEN_KEY, '1'); } catch (e) {} return; }  // 링크로 이동(적용 해제)
                ev.preventDefault();
                Array.prototype.forEach.call(form.querySelectorAll('input[type="checkbox"]'), function (i) { if (!i.hasAttribute('data-fixed')) { i.checked = false; } });
                form.dispatchEvent(new Event('change', { bubbles: true }));
            });
        }
        function syncAll() { live(); syncReset(); }
        form.addEventListener('change', syncAll);
        window.addEventListener('pageshow', syncAll);
        syncAll();
    })();
    </script>
    <?php endif; ?>
    <?php
}
