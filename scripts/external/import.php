<?php
declare(strict_types=1);
/**
 * 외부 상품 데이터 가져오기 — 출처 권한 확인 → 정규화·개인정보 제거 → 중복 제거 → 우리 상품 매칭 후보 → 검증 구역 저장 + 보고서
 *
 * 책임: data/external/sources.json 의 permission 을 확인하고, 허락된 출처의 파일(CSV·JSON)만 로컬 테스트 DB 의 검증 구역
 *       (external_products·external_product_matches)에 넣는다. 매칭은 후보(candidate)까지만 — 확정은 사람이 한다(--confirm).
 * 금지: products 변경·등록, 외부 가격을 판매가로 쓰기, 원본 파일·개인 연락처 저장, 운영 DB 사용(DB_NAME *_test 만).
 *       허락 전(pending) 출처는 --dry-run 분석만, blocked 는 거부. 네트워크 수집은 하지 않는다(파일만 읽음).
 *
 * 사용:
 *   php scripts/external/import.php --list-sources
 *   php scripts/external/import.php --source=g2b_mall_items_file --file=내려받은.csv --license-checked [--limit=200] [--md=보고서.md]
 *   php scripts/external/import.php --source=dacity --format=scrape-json --file=data/dacity/products.json --dry-run   (허락 전 출처: 분석만)
 *   php scripts/external/import.php --confirm=외부ID:상품ID [--confirm=…]   (사람이 확인한 매칭만 상담 카드에 사용)
 * 형식: csv(UTF-8·CP949, 쉼표·탭) — 머리글 자동 인식(품명·규격·제조사·단가·단위·물품식별번호 등) / json / scrape-json(기존 수집 스크립트 결과)
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$root = dirname(__DIR__, 2);
require "{$root}/app/bootstrap.php";
require_once "{$root}/app/ai_tools.php";
require_once "{$root}/app/external_products.php";

$opt = getopt('', ['list-sources', 'source:', 'file:', 'format:', 'limit:', 'dry-run', 'license-checked', 'md:', 'confirm:']);
$reg = json_decode((string) file_get_contents(getenv('EXT_SOURCES_FILE') ?: "{$root}/data/external/sources.json"), true);   // EXT_SOURCES_FILE: 테스트 전용 등록부
$sources = array_column($reg['sources'] ?? [], null, 'key');
$die = static function (string $m, int $code = 2): never { fwrite(STDERR, "[중단] {$m}\n"); exit($code); };

if (isset($opt['list-sources'])) {
    foreach ($sources as $k => $s) {
        printf("%-22s %-8s %s\n", $k, $s['permission'], $s['name']);
    }
    exit(0);
}

$dbName = (string) ($config['db']['name'] ?? '');
$needDb = !isset($opt['dry-run']) || isset($opt['confirm']);
if ($needDb && !preg_match('/_test$/', $dbName)) {
    $die("검증 구역 저장은 로컬 테스트 DB(DB_NAME=*_test)에서만 합니다. 현재 '{$dbName}' — 운영 반영은 승인 후 별도 절차.");
}
$pdo = db();
if (!$pdo) { $die('DB 에 연결하지 못했습니다(DB_HOST·DB_NAME·DB_USER·DB_PASS 확인).'); }
if ($needDb && !ext_tables_ready()) {
    $die('external_products 테이블이 없습니다. 테스트 DB 에 database/20261029_external_products.sql 을 적용하세요(setup-local-test-db.sh --reset 이 적용).');
}

// ── 사람이 확인한 매칭 확정 ─────────────────────────────────────────
if (isset($opt['confirm'])) {
    $n = 0;
    foreach ((array) $opt['confirm'] as $pair) {
        if (!preg_match('/^(\d+):(\d+)$/', (string) $pair, $m)) { $die("--confirm 형식은 외부ID:상품ID 입니다: {$pair}"); }
        $st = $pdo->prepare("UPDATE external_product_matches SET status = 'confirmed' WHERE external_product_id = ? AND product_id = ?");
        $st->execute([(int) $m[1], (int) $m[2]]);
        $n += $st->rowCount();
    }
    echo "확정한 매칭 {$n}건\n";
    exit(0);
}

// ── 출처 권한 확인 ─────────────────────────────────────────────────
$key = (string) ($opt['source'] ?? '');
$src = $sources[$key] ?? null;
if (!$src) { $die("등록되지 않은 출처입니다: '{$key}' (--list-sources 로 확인, 새 출처는 data/external/sources.json 에 근거와 함께 추가)"); }
if ($src['permission'] === 'blocked') { $die("'{$key}' 는 사용하지 않는 출처입니다(blocked): {$src['basis']}"); }
if ($src['permission'] !== 'allowed' && !isset($opt['dry-run'])) {
    $die("'{$key}' 는 아직 이용 허락 전입니다(pending): {$src['basis']}\n  허락을 받으면 sources.json 의 permission 을 allowed 로 바꾸고 basis 에 근거를 적으세요. 지금은 --dry-run 분석만 가능합니다.");
}
if ($src['permission'] === 'allowed' && !empty($src['conditions']) && !isset($opt['license-checked']) && !isset($opt['dry-run'])) {
    $die("'{$key}' 이용 조건을 확인한 뒤 --license-checked 를 붙여 실행하세요:\n  - " . implode("\n  - ", $src['conditions']));
}

// ── 파일 읽기 ──────────────────────────────────────────────────────
$file = (string) ($opt['file'] ?? '');
if ($file === '' || !is_file($file)) { $die("--file 이 없습니다: '{$file}'"); }
$format = (string) ($opt['format'] ?? (preg_match('/\.json$/i', $file) ? 'json' : 'csv'));
$limit  = max(1, min(5000, (int) ($opt['limit'] ?? 200)));   // 소규모 시범부터(기본 200행)
$rows = match ($format) {
    'csv'         => ext_read_csv($file),
    'json'        => ext_read_json($file, false),
    'scrape-json' => ext_read_json($file, true),
    default       => $die("지원하지 않는 형식: {$format} (csv·json·scrape-json)"),
};
$total = count($rows);
$rows = array_slice($rows, 0, $limit);

// ── 정규화 → 중복 제거 → 매칭 ─────────────────────────────────────
$norm = array_values(array_filter(array_map(static fn($r) => ext_normalize($r, $key), $rows), static fn($n) => $n['name'] !== ''));
[$uniq, $dupes] = ext_dedup($norm);
$products = $pdo->query('SELECT id, name, spec FROM products WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
$prodNorm = array_map('ext_normalize_product', $products);
$grades = ['probable' => 0, 'review' => 0, 'none' => 0];
foreach ($uniq as &$u) {
    $u['matches'] = ext_best_matches($u, $prodNorm, 3);
    $grades[$u['matches'][0]['grade'] ?? 'none']++;
}
unset($u);
$pii = array_sum(array_column($norm, 'pii_removed'));

// ── 저장(로컬 테스트 DB 검증 구역) ─────────────────────────────────
$saved = 0;
if (!isset($opt['dry-run'])) {
    $attrsOf = static fn(array $u): string => json_encode($u['attrs'] + ['source_name' => $src['name']], JSON_UNESCAPED_UNICODE);
    $ins = $pdo->prepare('INSERT INTO external_products (source_key, external_id, dedup_key, name, maker, product_type, spec_raw, width_mm, length_mm, thickness_mm, diameter_mm,
            material, attrs_json, unit, ref_price, price_basis, source_url, license_note, fetched_at)
        VALUES (:sk, :eid, :dk, :name, :maker, :type, :spec, :w, :l, :t, :d, :mat, :attrs, :unit, :price, :basis, :url, :lic, NOW())
        ON DUPLICATE KEY UPDATE external_id = VALUES(external_id), name = VALUES(name), maker = VALUES(maker), spec_raw = VALUES(spec_raw),
            attrs_json = VALUES(attrs_json), unit = VALUES(unit), ref_price = VALUES(ref_price), source_url = VALUES(source_url), fetched_at = NOW(), id = LAST_INSERT_ID(id)');
    $mIns = $pdo->prepare("INSERT INTO external_product_matches (external_product_id, product_id, score, reasons) VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE score = VALUES(score), reasons = VALUES(reasons)");
    $pdo->beginTransaction();
    foreach ($uniq as $u) {
        $ins->execute(['sk' => $key, 'eid' => $u['external_id'] ?: null, 'dk' => $u['dedup_key'], 'name' => $u['name'], 'maker' => $u['maker'] ?: null,
            'type' => $u['product_type'] ?: null, 'spec' => $u['spec_raw'] ?: null, 'w' => $u['width_mm'], 'l' => $u['length_mm'], 't' => $u['thickness_mm'],
            'd' => $u['diameter_mm'], 'mat' => $u['material'] ?: null, 'attrs' => $attrsOf($u), 'unit' => $u['unit'] ?: null, 'price' => $u['ref_price'],
            'basis' => $u['ref_price'] !== null ? '출처 표기 가격(참고가)' : null, 'url' => $u['source_url'] ?: null, 'lic' => mb_substr((string) $src['basis'], 0, 300)]);
        $eid = (int) $pdo->lastInsertId();
        foreach ($u['matches'] as $m) {
            $mIns->execute([$eid, $m['product_id'], $m['score'], $m['reasons']]);
        }
        $saved++;
    }
    $pdo->commit();
}

// ── 보고서(개인정보·원문 설명은 넣지 않음) ───────────────────────────
$md = "# 외부 상품 가져오기 결과 — {$key} (" . date('Y-m-d H:i') . ')' . (isset($opt['dry-run']) ? ' · 분석만(--dry-run, 저장 안 함)' : '') . "\n\n"
    . "- 출처: {$src['name']} · 권한 {$src['permission']} — {$src['basis']}\n"
    . "- 파일 행 {$total} · 처리 " . count($rows) . " (상한 {$limit}) · 이름 없는 행 제외 후 " . count($norm) . " · 중복 합침 {$dupes} · 고유 " . count($uniq) . "\n"
    . "- 개인 연락처 제거 {$pii}건 · 검증 구역 저장 {$saved}건 · DB {$dbName}\n"
    . "- 우리 상품 매칭: 유력(80점 이상) {$grades['probable']} · 검토(50~79) {$grades['review']} · 없음 {$grades['none']} — 확정은 사람이(--confirm)\n"
    . "- 외부 가격은 참고가로만 보관(판매가 아님). products 는 바꾸지 않음.\n\n"
    . "| 외부 상품 | 규격 | 종류 | 두께·관경 | 재질 | 매칭 후보(점수) |\n|---|---|---|---|---|---|\n";
foreach (array_slice($uniq, 0, 30) as $u) {
    $dim = $u['thickness_mm'] ? "{$u['thickness_mm']}T" : ($u['diameter_mm'] ? "D{$u['diameter_mm']}" : '');
    $mt  = $u['matches'] ? implode(' / ', array_map(static fn($m) => "#{$m['product_id']} {$m['name']}({$m['score']})", array_slice($u['matches'], 0, 2))) : '없음';
    $cell = static fn(string $v): string => str_replace('|', '/', mb_substr($v, 0, 40));
    $md .= "| {$cell($u['name'])} | {$cell($u['spec_raw'])} | {$u['product_type']} | {$dim} | {$u['material']} | {$cell($mt)} |\n";
}
echo $md;
if (isset($opt['md'])) { file_put_contents((string) $opt['md'], $md); }
