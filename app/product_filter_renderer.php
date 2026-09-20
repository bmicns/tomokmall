<?php
declare(strict_types=1);
/**
 * 상품 목록 좌측 파라메트릭 필터 렌더러.
 * render_product_filters($active, $q, $categoryId, $sort)
 *  - 데스크탑: 좌측 사이드바(항상 펼침), 모바일: <details> 접이식
 *  - 재질(카테고리 루트)·폭(mm)·형상/옵션·하중 등급, GET 폼 제출
 */
function render_product_filters(array $a, ?string $q, ?int $categoryId, string $sort = ''): void
{
    $mats   = [11 => '철근콘크리트', 12 => '콘크리트', 13 => '스틸', 14 => '석재', 15 => 'PE/PVC', 16 => '기타'];
    $shapes = ['사각', '원형', 'U형', '유공', '앵글부착', '인터로킹'];
    $loads  = ['중하중', '안전중하중'];
    $ck = static fn(array $arr, $v): string => in_array((string) $v, array_map('strval', $arr), true) ? 'checked' : '';
    $base = $q !== null && $q !== '' ? '/products.php?q=' . urlencode($q) : '/products.php?category=' . (int) $categoryId;
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

        <div class="flt-top"><b>필터로 좁히기</b><a href="<?= e($base) ?>">초기화</a></div>

        <div class="fgrp"><b>재질</b>
          <?php foreach ($mats as $id => $nm): ?>
            <label class="fopt"><input type="checkbox" name="mat[]" value="<?= $id ?>" <?= $ck($a['mat'] ?? [], $id) ?>><?= e($nm) ?></label>
          <?php endforeach; ?>
        </div>

        <div class="fgrp"><b>폭 (mm)</b>
          <div class="range">
            <input type="number" name="wmin" inputmode="numeric" placeholder="최소" value="<?= !empty($a['wmin']) ? e((string) $a['wmin']) : '' ?>">
            <span>~</span>
            <input type="number" name="wmax" inputmode="numeric" placeholder="최대" value="<?= !empty($a['wmax']) ? e((string) $a['wmax']) : '' ?>">
          </div>
        </div>

        <div class="fgrp"><b>형상 / 옵션</b>
          <?php foreach ($shapes as $s): ?>
            <label class="fopt"><input type="checkbox" name="shape[]" value="<?= e($s) ?>" <?= $ck($a['shape'] ?? [], $s) ?>><?= e($s) ?></label>
          <?php endforeach; ?>
        </div>

        <div class="fgrp"><b>하중 등급 <small>(그레이팅류)</small></b>
          <?php foreach ($loads as $s): ?>
            <label class="fopt"><input type="checkbox" name="load[]" value="<?= e($s) ?>" <?= $ck($a['load'] ?? [], $s) ?>><?= e($s) ?></label>
          <?php endforeach; ?>
        </div>

        <button type="submit" class="flt-apply">필터 적용</button>
      </form>
    </details>
    <?php
}
