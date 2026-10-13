<?php
declare(strict_types=1);
/**
 * 상품 목록 좌측 파라메트릭 필터 렌더러 (동적 파세트).
 *  - render_product_filters($active, $q, $categoryId, $sort, $facets)
 *  - product_filters_visible($facets, $active): 표시할 그룹/옵션만 계산(호출측 aside 노출 판단용)
 *  - product_list_url($get, $set): 현재 목록 조건을 유지한 products.php URL(페이지·칩 해제용)
 *  현재 결과 집합에 실제 존재하는 옵션만 개수와 함께 노출한다.
 *  0건 옵션·단일값 수렴(비선택·옵션1개·전체차지) 그룹은 숨긴다 → 결과와 무관한 필터 노출 방지.
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

    return [
        'mat'       => $matOpts,
        'shape'     => $shOpts,
        'load'      => $ldOpts,
        'spec'      => $specOpts,
        'total'     => $total,
        'any'       => (bool) ($matOpts || $shOpts || $ldOpts || $specOpts),
    ];
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

function render_product_filters(array $a, ?string $q, ?int $categoryId, string $sort = '', ?array $facets = null, bool $inStock = false): void
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
    if (!$v['any']) { return; } // 표시할 필터 없음 → 아무것도 렌더하지 않음

    $base = $q !== null && $q !== '' ? '/products.php?q=' . urlencode($q) : '/products.php?category=' . (int) $categoryId;
    $opt  = static function (string $field, $val, string $label, int $cnt, bool $active): void { ?>
        <label class="fopt"><input type="checkbox" name="<?= e($field) ?>[]" value="<?= e((string) $val) ?>" <?= $active ? 'checked' : '' ?>><span class="fopt-lbl"><?= e($label) ?></span><?php if ($cnt > 0): ?><span class="fcnt"><?= $cnt ?></span><?php endif; ?></label>
    <?php };
    ?>
    <details class="fltbox" open>
      <summary>필터로 좁히기</summary>
      <form class="flt-body" method="get" action="/products.php">
        <?php if ($q !== null && $q !== ''): ?>
          <input type="hidden" name="q" value="<?= e($q) ?>">
        <?php elseif ($categoryId): ?>
          <input type="hidden" name="category" value="<?= (int) $categoryId ?>">
        <?php endif; ?>
        <?php if ($sort !== ''): ?><input type="hidden" name="sort" value="<?= e($sort) ?>"><?php endif; ?>
        <?php if ($inStock): ?><input type="hidden" name="instock" value="1"><?php endif; ?>

        <div class="flt-top"><b>필터로 좁히기</b><a href="<?= e($base) ?>">초기화</a></div>

        <?php if ($v['mat']): ?>
        <div class="fgrp"><b>재질</b>
          <?php foreach ($v['mat'] as $o) { $opt('mat', $o['val'], FLT_MAT_LABELS[$o['val']] ?? (string) $o['val'], $o['cnt'], $o['active']); } ?>
        </div>
        <?php endif; ?>

        <?php if ($v['spec']): ?>
        <div class="fgrp"><b>규격 <small>(가로×세로×두께 등)</small></b>
          <div class="fgrp-scroll spec-scroll">
          <?php foreach ($v['spec'] as $o) {
              $lbl = str_replace(['*', 'X', 'x'], '×', (string) $o['val']); // 표시용: 곱셈기호 통일
              $opt('spec', $o['val'], $lbl, $o['cnt'], $o['active']);
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

        <button type="submit" class="flt-apply">필터 적용</button>
      </form>
    </details>
    <?php
}
