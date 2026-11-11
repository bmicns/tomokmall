<?php
declare(strict_types=1);
/**
 * 상용콘크리트 상품 일괄 등록(엑셀 + 이미지 ZIP) — syc_products
 *
 * 책임: 엑셀(xlsx·csv) 행을 syc_products 칸 규칙으로 검증·변환하고, 이미지 ZIP 의 대표·썸네일 파일을 찾아 서버에 저장한 뒤
 *       (external_source, external_id) 기준으로 새로 넣거나 덮어쓴다(사용자 결정 2026-11-11).
 *       - 새 상품은 비활성(is_active 기본 0)으로 들어가고, 이미 있는 상품은 활성 상태를 바꾸지 않는다.
 *       - 가격 칸(price_b2c·price_b2b·cost_price)은 엑셀 값 그대로 저장(빈 칸 0) — 사용자 결정 2026-11-11.
 *       - 이미지: 엑셀에 적힌 파일이 ZIP 에 있을 때만 저장·갱신한다. 썸네일이 없으면 비워 두고(NULL)
 *         화면은 기존 '이미지 없음' 표시를 쓴다. 이미 있는 상품의 이미지는 새 파일이 없으면 그대로 둔다.
 * 금지: 기존 products·categories 변경, 엑셀에 없는 칸 추측, 이미지 아닌 파일 저장(확장자 + 실제 이미지 형식 확인).
 * 입력 형식: 1행 머리글 '한글 이름 [칸 이름]' 또는 '칸 이름'(data/syccrt/syc-products-bulk-test.xlsx '작성법' 시트).
 * 사용: public/api/admin/syc-products.php · 설계 docs/02-design/syc-products.md
 */

require_once __DIR__ . '/external_products.php';   // ext_iter_xlsx · ext_iter_csv (라이브러리 없는 xlsx·csv 읽기)

const SYC_BULK_MAX_ROWS        = 5000;
const SYC_BULK_MAX_EXCEL_BYTES = 20 * 1024 * 1024;
const SYC_BULK_MAX_ZIP_BYTES   = 256 * 1024 * 1024;      // 운영 upload_max_filesize 256M
const SYC_BULK_MAX_IMAGE_BYTES = 20 * 1024 * 1024;       // 이미지 1장(압축 풀었을 때)
const SYC_BULK_MAX_IMAGES      = 10000;
const SYC_BULK_IMAGE_TYPES     = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp', IMAGETYPE_GIF => 'gif'];

/**
 * 엑셀로 받는 syc_products 칸과 값 규칙(database/20261110_syc_products.sql 과 같아야 함 — tests/functions.test.php 가 대조).
 * [종류, 한도]: str=글자 수, dec=[정수 자리, 소수 자리], int=[최소, 최대]
 * 엑셀에 없는 칸: id·category_id(분류코드로 찾음)·spec_dims_src(규격 그대로)·is_active·created_at, 이미지 2칸(파일 저장 뒤 경로)
 */
function syc_bulk_columns(): array
{
    $c = [
        'name' => ['str', 160], 'brand' => ['str', 100], 'spec' => ['str', 180], 'spec_code' => ['str', 30],
    ];
    foreach (['dim_d', 'dim_d_i', 'dim_d_o', 'dim_w', 'dim_w_o', 'dim_w_b', 'dim_w_t', 'dim_h', 'dim_h_o', 'dim_depth', 'dim_l', 'dim_v',
              'dim_t', 'dim_t2', 'dim_t_top', 'dim_t_wall', 'dim_t_bottom', 'dim_t_mid',
              'dim_w1', 'dim_w2', 'dim_h1', 'dim_h2', 'dim_h3', 'dim_h4', 'dim_t1', 'dim_t3', 'dim_t4',
              'dim_l1', 'dim_l2', 'dim_l3', 'dim_l4', 'dim_l5', 'dim_l6', 'dim_d1', 'dim_d2', 'dim_d3', 'dim_d4', 'dim_d5',
              'dim_r1', 'dim_r2', 'dim_c', 'dim_x1', 'dim_x2', 'dim_x3', 'dim_x4',
              'dim_a_w', 'dim_a_l', 'dim_a_t', 'dim_a2_w', 'dim_a2_l', 'dim_a2_t',   // 부속(그레이팅·덮개 등) 치수 — 2026-11-11 추가
              'dim_mesh', 'dim_wire_d'] as $k) {
        $c[$k] = ['dec', [7, 1]];
    }
    return $c + [
        'spec_wire_no' => ['int', [0, 65535]], 'dim_d_inlet' => ['str', 20], 'dim_d_outlet' => ['str', 20],
        'spec_option' => ['str', 160], 'unit' => ['str', 20], 'weight_kg' => ['dec', [6, 2]], 'description' => ['str', 65535],
        'price_b2c' => ['int', [0, 4294967295]], 'price_b2b' => ['int', [0, 4294967295]], 'cost_price' => ['int', [0, 4294967295]],
        'stock_qty' => ['int', [-2147483648, 2147483647]],
        'external_source' => ['str', 40], 'external_id' => ['str', 80], 'source_url' => ['str', 255],
    ];
}

/** 엑셀에서 읽기만 하는 칸(저장 칸이 아님): 분류코드·분류명(참고)·이미지 파일명 2칸 */
const SYC_BULK_META_KEYS = ['category_slug', 'category_name', 'image_file', 'image_thumb_file'];

/** 머리글 한 칸 → 칸 이름. '대표이미지(상세용) [image_file]' 처럼 끝의 [이름] 우선, 없으면 칸 이름 그대로. 모르면 '' */
function syc_bulk_header_key(string $h): string
{
    $h = trim($h);
    $k = preg_match('/\[([a-z0-9_]+)\]\s*$/', $h, $m) ? $m[1] : strtolower($h);
    return (isset(syc_bulk_columns()[$k]) || in_array($k, SYC_BULK_META_KEYS, true)) ? $k : '';
}

/** 머리글 행 → [열 번호 => 칸 이름] (모르는 열 제외). 같은 칸이 두 번 나오면 오류 */
function syc_bulk_header_map(array $head): array
{
    $map = [];
    foreach ($head as $i => $h) {
        $k = syc_bulk_header_key((string) $h);
        if ($k === '') { continue; }
        if (in_array($k, $map, true)) { throw new InvalidArgumentException("머리글 [{$k}] 이 두 번 있습니다."); }
        $map[$i] = $k;
    }
    foreach (['category_slug', 'name'] as $need) {
        if (!in_array($need, $map, true)) { throw new InvalidArgumentException("필수 머리글 [{$need}] 이 없습니다(1행 머리글을 바꾸지 마세요)."); }
    }
    return $map;
}

/**
 * 셀 값 하나를 칸 규칙에 맞게 변환. 빈 칸 → null(가격·재고는 0).
 * @return array{0:mixed,1:?string} [값, 오류 문구]
 */
function syc_bulk_cell(string $key, mixed $raw): array
{
    [$type, $lim] = syc_bulk_columns()[$key];
    $v = trim((string) ($raw ?? ''));
    if ($v === '') {
        return [in_array($key, ['price_b2c', 'price_b2b', 'cost_price', 'stock_qty'], true) ? 0 : null, null];
    }
    if ($type === 'str') {
        return mb_strlen($v) > $lim ? [null, "{$lim}자를 넘습니다"] : [$v, null];
    }
    $n = str_replace([',', ' '], '', $v);
    if (!is_numeric($n)) { return [null, "숫자가 아닙니다('{$v}')"]; }
    if ($type === 'int') {
        if (!preg_match('/^-?\d+(\.0+)?$/', $n)) { return [null, "정수가 아닙니다('{$v}')"]; }
        $i = (int) $n;
        return ($i < $lim[0] || $i > $lim[1]) ? [null, "범위를 벗어났습니다('{$v}')"] : [$i, null];
    }
    [$intDigits, $scale] = $lim;
    $f = round((float) $n, $scale);
    if ($f < 0 || $f >= 10 ** $intDigits) { return [null, "범위를 벗어났습니다('{$v}')"]; }
    return [$f, null];
}

/** 이미지 파일명 칸: ZIP 안 파일 이름(폴더 없이). 경로·이상한 글자는 오류 */
function syc_bulk_image_name(mixed $raw): array
{
    $v = trim((string) ($raw ?? ''));
    if ($v === '') { return [null, null]; }
    if (mb_strlen($v) > 200 || !preg_match('/^[^\/\\\\:*?"<>|]+\.(jpe?g|png|webp|gif)$/iu', $v) || str_starts_with($v, '.')) {
        return [null, "이미지 파일명이 올바르지 않습니다('{$v}' — jpg·png·webp·gif, 폴더 없이 파일 이름만)"];
    }
    return [$v, null];
}

/**
 * 원시 행 하나 검증·변환.
 * @param array<int,string> $map 머리글 지도, @param array<string,int> $catIds 분류코드 → syc_categories.id
 * @return array{row:?array, errors:list<string>, empty:bool}
 */
function syc_bulk_parse_row(array $raw, array $map, array $catIds): array
{
    $cells = [];
    foreach ($map as $i => $k) { $cells[$k] = $raw[$i] ?? ''; }
    if (implode('', array_map(static fn($v) => trim((string) $v), $cells)) === '') {
        return ['row' => null, 'errors' => [], 'empty' => true];
    }
    $row = [];
    $err = [];
    foreach (syc_bulk_columns() as $k => $_) {
        if (!array_key_exists($k, $cells)) { $row[$k] = in_array($k, ['price_b2c', 'price_b2b', 'cost_price', 'stock_qty'], true) ? 0 : null; continue; }
        [$v, $e] = syc_bulk_cell($k, $cells[$k]);
        if ($e !== null) { $err[] = "[{$k}] {$e}"; }
        $row[$k] = $v;
    }
    if (($row['name'] ?? null) === null) { $err[] = '[name] 상품명이 비어 있습니다'; }
    $slug = trim((string) ($cells['category_slug'] ?? ''));
    if ($slug === '') { $err[] = '[category_slug] 분류코드가 비어 있습니다'; }
    elseif (!isset($catIds[$slug])) { $err[] = "[category_slug] 없는 분류코드입니다('{$slug}')"; }
    $row['category_id'] = $catIds[$slug] ?? null;
    $row['spec_dims_src'] = $row['spec'];
    foreach (['image_file', 'image_thumb_file'] as $k) {
        [$v, $e] = syc_bulk_image_name($cells[$k] ?? '');
        if ($e !== null) { $err[] = "[{$k}] {$e}"; }
        $row['_' . $k] = $v;          // ZIP 안 파일명(저장 경로는 등록 때 정함)
    }
    if (($row['external_id'] ?? null) !== null && ($row['external_source'] ?? null) === null) {
        $row['external_source'] = 'syccrt';      // 출처ID 만 있으면 기본 출처
    }
    return ['row' => $err ? null : $row, 'errors' => $err, 'empty' => false];
}

/** 엑셀 원시 행(첫 행 머리글 포함). 확장자로 xlsx·csv 구분 */
function syc_bulk_iter_raw(string $file, string $ext): Generator
{
    return match (strtolower($ext)) {
        'xlsx'       => ext_iter_xlsx($file),
        'csv', 'txt' => ext_iter_csv($file),
        default      => throw new InvalidArgumentException('엑셀 파일은 xlsx 또는 csv 만 올릴 수 있습니다.'),
    };
}

/**
 * 엑셀 전체 검증. 등록 전(미리 확인)과 등록 때 같은 함수를 쓴다.
 * @return array{rows:list<array>, errors:list<string>, error_count:int, data_rows:int, present:list<string>} present = 엑셀에 있는 저장 칸
 */
function syc_bulk_read_excel(string $file, string $ext, array $catIds): array
{
    $map = null;
    $rows = [];
    $errors = [];
    $errCount = 0;
    $line = 0;
    $seen = [];
    foreach (syc_bulk_iter_raw($file, $ext) as $raw) {
        $line++;
        if ($map === null) { $map = syc_bulk_header_map($raw); continue; }
        $r = syc_bulk_parse_row($raw, $map, $catIds);
        if ($r['empty']) { continue; }
        if (count($rows) + $errCount >= SYC_BULK_MAX_ROWS) {
            throw new InvalidArgumentException('한 번에 ' . number_format(SYC_BULK_MAX_ROWS) . '행까지 올릴 수 있습니다.');
        }
        $errs = $r['errors'];
        if ($r['row'] !== null && $r['row']['external_id'] !== null) {
            $key = $r['row']['external_source'] . "\0" . $r['row']['external_id'];
            if (isset($seen[$key])) { $errs[] = "[external_id] {$seen[$key]}행과 출처ID가 같습니다('{$r['row']['external_id']}')"; }
            $seen[$key] = $line;
        }
        if ($errs) {
            $errCount++;
            if (count($errors) < 20) { $errors[] = "{$line}행: " . implode(', ', $errs); }
            continue;
        }
        $rows[] = $r['row'];
    }
    if ($map === null) { throw new InvalidArgumentException('엑셀이 비어 있습니다.'); }
    return ['rows' => $rows, 'errors' => $errors, 'error_count' => $errCount, 'data_rows' => count($rows) + $errCount,
            'present' => array_values(array_intersect(array_keys(syc_bulk_columns()), $map))];
}

/**
 * 이미지 ZIP 목록: [파일 이름(소문자) => ZIP 안 경로]. 폴더 안 파일도 이름으로 찾는다. 이미지 확장자만, __MACOSX·숨김 파일 제외.
 * 압축 풀린 크기 한도로 압축 폭탄을 막는다. 같은 이름이 두 번 있으면 오류.
 */
function syc_bulk_zip_index(ZipArchive $zip): array
{
    $idx = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $st = $zip->statIndex($i);
        if ($st === false) { continue; }
        $path = (string) $st['name'];
        if (str_ends_with($path, '/') || str_starts_with($path, '__MACOSX/')) { continue; }
        $base = basename(str_replace('\\', '/', $path));
        if ($base === '' || str_starts_with($base, '.') || !preg_match('/\.(jpe?g|png|webp|gif)$/i', $base)) { continue; }
        if ((int) $st['size'] > SYC_BULK_MAX_IMAGE_BYTES) {
            throw new InvalidArgumentException("이미지가 너무 큽니다({$base} — 한 장 " . (SYC_BULK_MAX_IMAGE_BYTES >> 20) . 'MB 까지).');
        }
        $k = mb_strtolower($base);
        if (isset($idx[$k])) { throw new InvalidArgumentException("ZIP 안에 같은 이름의 이미지가 두 개 있습니다({$base})."); }
        $idx[$k] = $path;
        if (count($idx) > SYC_BULK_MAX_IMAGES) { throw new InvalidArgumentException('이미지는 ' . number_format(SYC_BULK_MAX_IMAGES) . '장까지 올릴 수 있습니다.'); }
    }
    return $idx;
}

/**
 * ZIP 안 이미지 1장을 웹 폴더에 저장하고 웹 경로를 돌려준다. 실제 이미지 형식을 확인하고 확장자는 형식으로 정한다.
 * 파일 이름은 내용 해시(같은 이미지는 한 번만 저장). 이미지가 아니면 null.
 */
function syc_bulk_store_image(ZipArchive $zip, string $path, string $dir, string $webPrefix): ?string
{
    $bin = $zip->getFromName($path);
    if ($bin === false || $bin === '' || strlen($bin) > SYC_BULK_MAX_IMAGE_BYTES) { return null; }
    $info = getimagesizefromstring($bin);     // 이미지가 아니면 false
    if ($info === false || !isset(SYC_BULK_IMAGE_TYPES[$info[2]])) { return null; }
    $name = substr(hash('sha256', $bin), 0, 24) . '.' . SYC_BULK_IMAGE_TYPES[$info[2]];
    $dest = rtrim($dir, '/') . '/' . $name;
    if (!is_file($dest) && file_put_contents($dest, $bin, LOCK_EX) === false) {
        throw new RuntimeException('이미지를 저장하지 못했습니다.');
    }
    return rtrim($webPrefix, '/') . '/' . $name;
}

/**
 * 한 행 저장: (external_source, external_id) 가 같으면 덮어쓰기(활성 상태는 그대로), 아니면 새로(비활성).
 * 덮어쓸 때는 엑셀에 있는 칸($present)만 바꾸고, 엑셀에 없는 칸은 기존 값을 둔다(일부 칸만 담은 엑셀로 지워지지 않게).
 * 이미지 칸은 새 경로가 있을 때만 바꾼다. @return 'insert'|'update'|'same'
 */
function syc_bulk_save_row(PDO $pdo, array $row, ?string $img, ?string $thumb, array $present): string
{
    static $sqls = [];
    $cols = array_merge(['category_id'], array_keys(syc_bulk_columns()), ['spec_dims_src', 'image_file', 'image_thumb_file']);
    $sig = implode(',', $present);
    if (!isset($sqls[$sig])) {
        $upd = ['category_id = new.category_id'];
        foreach ($present as $c) { $upd[] = "{$c} = new.{$c}"; }
        if (in_array('spec', $present, true)) { $upd[] = 'spec_dims_src = new.spec_dims_src'; }
        foreach (['image_file', 'image_thumb_file'] as $c) { $upd[] = "{$c} = COALESCE(new.{$c}, syc_products.{$c})"; }
        $sqls[$sig] = 'INSERT INTO syc_products (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')'
                    . ' AS new ON DUPLICATE KEY UPDATE ' . implode(', ', $upd);
    }
    $sql = $sqls[$sig];
    $vals = [];
    foreach ($cols as $c) {
        $vals[] = match ($c) { 'image_file' => $img, 'image_thumb_file' => $thumb, default => $row[$c] };
    }
    $st = $pdo->prepare($sql);
    $st->execute($vals);
    return match ($st->rowCount()) { 1 => 'insert', 2 => 'update', default => 'same' };
}

/** 분류코드 → syc_categories.id (품목 단계만 — 대분류에는 상품을 넣지 않음) */
function syc_bulk_category_ids(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, slug FROM syc_categories WHERE parent_id IS NOT NULL') as $r) { $out[(string) $r['slug']] = (int) $r['id']; }
    return $out;
}

/** 임시 업로드 폴더(웹 밖). 하루 지난 파일은 지운다 */
function syc_bulk_stage_dir(): string
{
    $dir = rtrim(sys_get_temp_dir(), '/') . '/tomok_syc_bulk';
    if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('임시 폴더를 만들지 못했습니다.'); }
    foreach (glob($dir . '/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - 86400) { unlink($f); }
    }
    return $dir;
}
