<?php
declare(strict_types=1);
/**
 * 케이토목 상품 일괄 등록(엑셀) — 고객용 상품 표 products 의 케이토목 행(상용콘크리트 반영본 제외)
 *
 * 책임: 엑셀 행을 검증해 케이토목 상품을 새로 넣거나 덮어쓴다(사용자 결정 2026-11-13).
 *   - 같은 상품 = 출처ID(케이토목 출처 ktomok 의 external_id), 출처ID가 없으면 제품명 + 규격(원문). 같은 업체(케이토목) 안에서만 찾는다.
 *   - 새 상품은 비활성(is_active 0, 출처 ktomok). 덮어쓸 때는 엑셀에 있는 칸만 바꾸고 활성 상태는 그대로.
 *   - 규격 칸은 기존 형식 '규격: … | 중량: …kg | 단위: …' 로 합쳐 저장(화면이 이 줄을 나눠 씀) → 규격 축 칸 다시 계산(spec_axes_sync_product).
 *   - 가격 칸(price_b2c·price_b2b·cost_price)은 엑셀 값(관리자 가격 관리 기능 — CLAUDE.md P0-4-3 유지 목록)
 * 금지: 상용콘크리트 반영본(출처 syccrt)·다른 업체 상품 변경, 엑셀에 없는 칸 추측.
 * 이미지: 엑셀 '썸네일·상세·규격 이미지 url 경로' 칸(app/syc_bulk.php 와 같은 검사) → image_thumb_url·image_url·image_spec_url.
 *         서버 경로(/…)는 웹루트에 파일이 있을 때만, 외부 주소는 그대로. 빈칸·못 찾은 이미지는 기존 값 유지.
 * 사용: public/api/admin/products.php(action=stage_excel·bulk_commit) · 엑셀 읽기·칸 변환은 app/syc_bulk.php 재사용
 */

require_once __DIR__ . '/syc_bulk.php';

/** 엑셀로 받는 칸과 값 규칙(products 표 크기와 같게). [종류, 한도] — syc_bulk_cell 규칙 */
function ktomok_bulk_columns(): array
{
    return [
        'name' => ['str', 160], 'brand' => ['str', 100], 'spec' => ['str', 160], 'unit' => ['str', 20], 'weight_kg' => ['dec', [6, 2]],
        'description' => ['str', 65535], 'price_b2c' => ['int', [0, 4294967295]], 'price_b2b' => ['int', [0, 4294967295]],
        'cost_price' => ['int', [0, 4294967295]], 'external_id' => ['str', 80],
    ];
}
const KTOMOK_BULK_META_KEYS = ['category_slug', 'category_name', 'image_thumb_url', 'image_detail_url', 'image_spec_url'];
/** 엑셀 이미지 경로 3칸 → products 칸 */
const KTOMOK_BULK_IMAGE_COLUMNS = ['image_thumb_url' => 'image_thumb_url', 'image_detail_url' => 'image_url', 'image_spec_url' => 'image_spec_url'];
const KTOMOK_BULK_PRICE_KEYS = ['price_b2c', 'price_b2b', 'cost_price'];

/** 저장된 규격 줄 → 조각. '규격: A | 중량: 3,640kg | 단위: 본' → spec·weight·unit·rest. 이름표가 없으면 통째로 규격. 순수 함수 */
function ktomok_spec_parts(?string $text): array
{
    $out = ['spec' => null, 'weight' => null, 'unit' => null, 'rest' => [], 'plain' => false];
    $text = trim((string) $text);
    if ($text === '') { return $out; }
    $map = ['규격' => 'spec', '중량' => 'weight', '단위' => 'unit'];
    $found = false;
    foreach (preg_split('/\s*\|\s*/u', $text) ?: [] as $part) {
        if (preg_match('/^(규격|중량|단위)\s*:\s*(.*)$/u', $part, $m)) { $out[$map[$m[1]]] = trim($m[2]) === '' ? null : trim($m[2]); $found = true; }
        elseif ($part !== '') { $out['rest'][] = $part; }
    }
    if (!$found) { return ['spec' => $text, 'weight' => null, 'unit' => null, 'rest' => [], 'plain' => true]; }   // 이름표 없는 옛 규격('900 x 1800 x 50mm')
    return $out;
}

/** 조각 → 저장 형식 '규격: … | 중량: … | 단위: …'(빈 조각 뺌, 그 밖의 조각은 뒤에). 이름표 없던 규격에 규격만 있으면 원래대로(이름표 안 붙임). 순수 함수 */
function ktomok_spec_compose(array $p): string
{
    if (!empty($p['plain']) && trim((string) ($p['weight'] ?? '')) === '' && trim((string) ($p['unit'] ?? '')) === '' && empty($p['rest'])) {
        return trim((string) ($p['spec'] ?? ''));
    }
    $parts = [];
    foreach (['spec' => '규격', 'weight' => '중량', 'unit' => '단위'] as $k => $label) {
        $v = trim((string) ($p[$k] ?? ''));
        if ($v !== '') { $parts[] = "{$label}: {$v}"; }
    }
    return implode(' | ', array_merge($parts, $p['rest'] ?? []));
}

/** 중량 숫자 → 규격 줄 글자('3,640kg', '12.5kg'). 순수 함수 */
function ktomok_weight_text(?float $w): ?string
{
    if ($w === null) { return null; }
    return (floor($w) === $w ? number_format($w) : rtrim(rtrim(number_format($w, 2), '0'), '.')) . 'kg';
}

/**
 * 엑셀 전체 검증. @param array<string,int> $catIds 분류코드 → categories.id(케이토목 품목)
 * @return array{rows:list<array>, errors:list<string>, error_count:int, data_rows:int, present:list<string>}
 */
function ktomok_bulk_read_excel(string $file, string $ext, array $catIds): array
{
    $cols = ktomok_bulk_columns();
    $map = null; $rows = []; $errors = []; $errCount = 0; $line = 0; $seen = [];
    foreach (syc_bulk_iter_raw($file, $ext) as $raw) {
        $line++;
        if ($map === null) {
            $map = syc_bulk_header_map($raw, $cols, KTOMOK_BULK_META_KEYS);   // 필수: category_slug·name
            continue;
        }
        $cells = [];
        foreach ($map as $i => $k) { $cells[$k] = $raw[$i] ?? ''; }
        if (implode('', array_map(static fn($v) => trim((string) $v), $cells)) === '') { continue; }
        if (count($rows) + $errCount >= SYC_BULK_MAX_ROWS) { throw new InvalidArgumentException('한 번에 ' . number_format(SYC_BULK_MAX_ROWS) . '행까지 올릴 수 있습니다.'); }
        $row = []; $err = [];
        foreach ($cols as $k => $rule) {
            if (!array_key_exists($k, $cells)) { continue; }
            [$v, $e] = syc_bulk_cell($k, $cells[$k], $rule);
            if ($e !== null) { $err[] = "[{$k}] {$e}"; }
            $row[$k] = $v;
        }
        if (($row['name'] ?? null) === null) { $err[] = '[name] 상품명이 비어 있습니다'; }
        $slug = trim((string) ($cells['category_slug'] ?? ''));
        if ($slug === '') { $err[] = '[category_slug] 분류코드가 비어 있습니다'; }
        elseif (!isset($catIds[$slug])) { $err[] = "[category_slug] 케이토목 분류코드가 아닙니다('{$slug}')"; }
        $row['category_id'] = $catIds[$slug] ?? null;
        $row['_img'] = [];
        foreach (array_keys(KTOMOK_BULK_IMAGE_COLUMNS) as $k) {
            [$v, $e] = syc_bulk_image_path($cells[$k] ?? '');
            if ($e !== null) { $err[] = "[{$k}] {$e}"; }
            $row['_img'][$k] = $v;
        }
        $key = ($row['external_id'] ?? null) !== null ? 'id:' . $row['external_id'] : 'nk:' . product_match_key($row['name'] ?? '', $row['spec'] ?? '', null);
        if (!$err && isset($seen[$key])) { $err[] = "{$seen[$key]}행과 같은 상품입니다(출처ID 또는 제품명·규격)"; }
        $seen[$key] = $line;
        if ($err) {
            $errCount++;
            if (count($errors) < 20) { $errors[] = "{$line}행: " . implode(', ', $err); }
            continue;
        }
        $rows[] = $row;
    }
    if ($map === null) { throw new InvalidArgumentException('엑셀이 비어 있습니다.'); }
    $present = array_values(array_intersect(array_keys($cols), $map));
    return ['rows' => $rows, 'errors' => $errors, 'error_count' => $errCount, 'data_rows' => count($rows) + $errCount, 'present' => $present];
}

/** 케이토목 분류코드 → categories.id: 상용콘크리트(syc-)가 아니고 하위 분류가 없는 카테고리(상품이 붙는 단계) */
function ktomok_bulk_category_ids(PDO $pdo): array
{
    $out = [];
    $sql = "SELECT c.id, c.slug FROM categories c
            WHERE c.parent_id IS NOT NULL AND c.slug IS NOT NULL AND c.slug <> '' AND c.slug NOT LIKE 'syc-%'
              AND NOT EXISTS (SELECT 1 FROM categories k WHERE k.parent_id = c.id)";
    foreach ($pdo->query($sql) as $r) { $out[(string) $r['slug']] = (int) $r['id']; }
    return $out;
}

/**
 * 같은 상품 찾기(케이토목 안): 출처ID → 출처 ktomok 의 external_id. 없으면 제품명이 같고 규격(원문 조각)이 같은 행.
 * 여럿이면 InvalidArgumentException. @return 기존 행(id·spec) 또는 null
 */
function ktomok_bulk_find(PDO $pdo, array $row): ?array
{
    if (($row['external_id'] ?? null) !== null) {   // 케이토목 행(출처 ktomok·dacity·없음) 안에서 출처ID
        $st = $pdo->prepare("SELECT id, spec FROM products WHERE (external_source IS NULL OR external_source <> 'syccrt') AND external_id = ? LIMIT 2");
        $st->execute([$row['external_id']]);
        $hit = $st->fetchAll();
    } else {
        $st = $pdo->prepare("SELECT id, spec FROM products WHERE (external_source IS NULL OR external_source <> 'syccrt') AND name = ?");
        $st->execute([$row['name']]);
        $want = product_match_key('', $row['spec'] ?? '', null);
        $hit = array_values(array_filter($st->fetchAll(), static fn($e) => product_match_key('', ktomok_spec_parts($e['spec'])['spec'] ?? '', null) === $want));
    }
    if (count($hit) > 1) {
        throw new InvalidArgumentException("케이토목에 같은 제품명·규격 상품이 여러 개라 덮어쓸 상품을 정할 수 없습니다('{$row['name']}' " . ($row['spec'] ?? '') . '). 엑셀에 출처ID를 넣어 주세요.');
    }
    return $hit[0] ?? null;
}

/**
 * 한 행 저장. 덮어쓸 때는 엑셀에 있는 칸($present)만, 규격 줄은 기존 줄에 엑셀의 규격·중량·단위만 바꿔 다시 합침.
 * @param array<string,string> $imgs products 이미지 칸 => 찾은 경로(빈칸·못 찾은 칸은 넣지 않음 → 기존 값 유지)
 * @return array{0:'insert'|'update', 1:int} [결과, 상품 id]
 */
function ktomok_bulk_save_row(PDO $pdo, array $row, array $present, array $imgs = []): array
{
    $old = ktomok_bulk_find($pdo, $row);
    $parts = ktomok_spec_parts($old['spec'] ?? null);
    // 규격·중량·단위는 규격 줄 안에만(케이토목 방식 — unit·weight_kg 칸은 쓰지 않음). 빈칸이면 기존 조각 그대로(지우지 않음)
    if (in_array('spec', $present, true) && $row['spec'] !== null) { $parts['spec'] = $row['spec']; }
    if (in_array('weight_kg', $present, true) && $row['weight_kg'] !== null) { $parts['weight'] = ktomok_weight_text((float) $row['weight_kg']); }
    if (in_array('unit', $present, true) && $row['unit'] !== null) { $parts['unit'] = $row['unit']; }
    $spec = ktomok_spec_compose($parts);
    if (mb_strlen($spec) > 180) { throw new InvalidArgumentException("규격·중량·단위를 합친 글자가 180자를 넘습니다('{$row['name']}')."); }

    $set = ['category_id' => $row['category_id'], 'spec' => $spec === '' ? null : $spec];
    foreach ($present as $c) {
        if (in_array($c, ['spec', 'external_id', 'unit', 'weight_kg'], true)) { continue; }
        $set[$c] = $row[$c];
    }
    $set += $imgs;
    if ($old !== null) {
        $pdo->prepare('UPDATE products SET ' . implode(', ', array_map(static fn($c) => "{$c} = ?", array_keys($set))) . ' WHERE id = ?')
            ->execute([...array_values($set), (int) $old['id']]);
        return ['update', (int) $old['id']];
    }
    foreach (KTOMOK_BULK_PRICE_KEYS as $c) { $set[$c] ??= 0; }
    $set += ['external_source' => PRODUCT_SOURCE_KTOMOK, 'external_id' => $row['external_id'] ?? product_auto_external_id(product_match_key($row['name'], $row['spec'] ?? '', null)), 'is_active' => 0];
    $pdo->prepare('INSERT INTO products (' . implode(', ', array_keys($set)) . ') VALUES (' . implode(', ', array_fill(0, count($set), '?')) . ')')
        ->execute(array_values($set));
    return ['insert', (int) $pdo->lastInsertId()];
}
