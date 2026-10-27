<?php
declare(strict_types=1);
/**
 * 수집 상품 데이터 품질 검증 (읽기 전용, DB 불필요)
 *
 * 책임: data/<source>/import.sql 의 products INSERT 를 파싱해(운영 DB에 들어가는 형태 그대로)
 *       중복·규격 누락·단위 불일치·비정상 가격·필드 오염·개인정보 패턴·수량 계산 가능 여부를 검사.
 * 금지: DB 접속, 파일 수정, 외부 네트워크. 개인정보 값은 출력하지 않는다(건수·상품 키만).
 *
 * 실행: php scripts/data-quality/check_products.php [repo-root] [out-dir]
 *   기본값: 저장소 루트, docs/03-analysis/ai-sourcing/data-quality
 *   → <out-dir>/report.md, <out-dir>/issues.csv
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

$root = rtrim($argv[1] ?? dirname(__DIR__, 2), '/');
$out  = rtrim($argv[2] ?? "{$root}/docs/03-analysis/ai-sourcing/data-quality", '/');
if ($root === '' || $out === '' || !is_file("{$root}/app/ai_tools.php")) {
    fwrite(STDERR, "사용법: php scripts/data-quality/check_products.php [repo-root] [out-dir]\n");
    exit(1);
}
@mkdir($out, 0777, true);

// 수량 계산은 실제 서비스 함수를 그대로 사용(ai_estimate_quantity, ai_tile_area_m2)
require "{$root}/app/bootstrap.php";
require_once "{$root}/app/ai_tools.php";

/** import.sql 의 products INSERT ... SELECT c.id, '이름', '규격', 설명, b2c, b2b, 재고, 'src', 'extid', 'url', 'img', 1 파싱 */
function parse_import(string $file, string $source): array
{
    $rows = [];
    $sqlStr = "(NULL|'(?:[^'\\\\]|''|\\\\.)*')";
    $num    = '(-?\d+)';
    $re = "/INSERT INTO products \(category_id, name, spec, description, price_b2c, price_b2b, stock_qty, external_source, external_id, source_url, image_url, is_active\) SELECT c\.id, {$sqlStr}, {$sqlStr}, {$sqlStr}, {$num}, {$num}, {$num}, {$sqlStr}, {$sqlStr}, {$sqlStr}, {$sqlStr}, \d+ FROM categories c WHERE c\.slug = '([^']+)'/u";
    $un = static function (string $v): ?string {
        if ($v === 'NULL') { return null; }
        return str_replace(["''", '\\\\'], ["'", '\\'], substr($v, 1, -1));
    };
    foreach (file($file) as $line) {
        if (!preg_match($re, $line, $m)) { continue; }
        $rows[] = [
            'source' => $source, 'name' => $un($m[1]), 'spec' => (string) $un($m[2]), 'description' => $un($m[3]),
            'price_b2c' => (int) $m[4], 'price_b2b' => (int) $m[5],
            'external_id' => (string) $un($m[8]), 'image_url' => (string) $un($m[10]), 'cat_slug' => $m[11],
        ];
    }
    return $rows;
}

function field(string $spec, string $key): ?string
{
    return preg_match('/(?:^|\|)\s*' . preg_quote($key, '/') . ':\s*([^|]*)/u', $spec, $m) ? trim($m[1]) : null;
}

$sources = [];
foreach (['ktomok', 'dacity'] as $s) {
    $f = "{$root}/data/{$s}/import.sql";
    if (is_file($f)) { $sources[$s] = parse_import($f, $s); }
}
// dacity import.sql 은 여러 줄 INSERT(brand 포함)라 위 파서에 안 걸림 → products.json 으로 같은 필드 구성
if (empty($sources['dacity']) && is_file("{$root}/data/dacity/products.json")) {
    $sources['dacity'] = array_map(static fn($p) => [
        'source' => 'dacity', 'name' => (string) $p['name'], 'spec' => (string) $p['spec'], 'description' => $p['description'] ?? null,
        'price_b2c' => (int) $p['priceB2c'], 'price_b2b' => (int) $p['priceB2b'], 'external_id' => (string) $p['externalId'],
        'image_url' => (string) $p['imageUrl'], 'cat_slug' => 'dacity-' . $p['categoryExternalId'],
    ], json_decode((string) file_get_contents("{$root}/data/dacity/products.json"), true) ?: []);
}
$catName = [];
foreach (['ktomok', 'dacity'] as $s) {
    $f = "{$root}/data/{$s}/categories.json";
    if (is_file($f)) {
        foreach (json_decode((string) file_get_contents($f), true) ?: [] as $c) {
            $catName[$c['slug'] ?? ('dacity-' . ($c['externalId'] ?? ''))] = $c['name'] ?? '';
        }
    }
}
$jsonCount = [];
foreach (['ktomok', 'dacity'] as $s) {
    $f = "{$root}/data/{$s}/products.json";
    $jsonCount[$s] = is_file($f) ? count(json_decode((string) file_get_contents($f), true) ?: []) : 0;
}

$issues = [];   // [source, external_id, check, detail]
$add = static function (array $r, string $check, string $detail) use (&$issues): void {
    $issues[] = [$r['source'], $r['external_id'], $r['cat_slug'], $r['name'], $check, $detail];
};
$stat = [];
$inc = static function (string $k, int $n = 1) use (&$stat): void { $stat[$k] = ($stat[$k] ?? 0) + $n; };

$all = array_merge(...array_values($sources));

// ── 1. 개인정보 패턴 (값은 기록하지 않음) ─────────────────────────────
foreach ($all as $r) {
    $text = $r['spec'] . ' ' . ($r['description'] ?? '');
    $hits = [];
    if (preg_match('/01[016789]-?\d{3,4}-?\d{4}/', $text)) { $hits[] = '휴대폰번호'; }
    if (preg_match('/0\d{1,2}-\d{3,4}-\d{4}/', $text))     { $hits[] = '전화번호'; }
    if (preg_match('/[\w.+-]+@[\w-]+\.[\w.]+/', $text))     { $hits[] = '이메일'; }
    if (preg_match('/(시|군|구)\s+\S+(읍|면|동|리|로|길)\s*\d/u', $text)) { $hits[] = '주소'; }
    if ($hits) { $inc('pii'); $add($r, '개인정보 패턴', implode('+', $hits) . ' (값 비공개)'); }
}

// ── 2. 중복 ──────────────────────────────────────────────────────────
$seenKey = []; $seenExt = [];
foreach ($all as $r) {
    $reg = field($r['spec'], '규격') ?? $r['spec'];
    $k = $r['source'] . '|' . $r['cat_slug'] . '|' . $r['name'] . '|' . preg_replace('/\s+/', '', mb_strtolower($reg));
    if (isset($seenKey[$k])) { $inc('dup_same'); $add($r, '중복(같은 출처·카테고리·이름·규격)', '먼저 나온 키: ' . $seenKey[$k]); }
    else { $seenKey[$k] = $r['external_id']; }
    $ek = $r['source'] . '|' . $r['external_id'];
    if (isset($seenExt[$ek])) { $inc('dup_ext'); $add($r, '중복(external_id)', ''); }
    $seenExt[$ek] = true;
}
// 출처 간 중복: dacity 상품명(=규격 문자열)과 ktomok 규격이 같은 경우
$kSpecs = [];
foreach ($sources['ktomok'] ?? [] as $r) { $kSpecs[preg_replace('/\s+/', '', (string) field($r['spec'], '규격'))][] = $r['external_id']; }
foreach ($sources['dacity'] ?? [] as $r) {
    $key = preg_replace('/\s+/', '', $r['name']);
    if (isset($kSpecs[$key])) { $inc('dup_cross'); $add($r, '출처 간 중복 후보(ktomok 규격과 동일)', 'ktomok ' . implode(',', array_slice($kSpecs[$key], 0, 3))); }
}

// ── 3~6. 규격·단위·가격·필드 오염 ─────────────────────────────────────
$byCatUnit = [];
foreach ($all as $r) {
    $reg  = field($r['spec'], '규격');
    $unit = field($r['spec'], '단위');
    if ($reg === null || $reg === '') {
        if ($r['source'] === 'ktomok') { $inc('spec_missing'); $add($r, '규격 누락', ''); }
    } elseif (!preg_match('/\d/', $reg)) {
        $inc('spec_nodim'); $add($r, '규격에 치수 없음', $reg);
    } elseif (preg_match('/^\s*\d+\.\d+\s*\*\s*\d+(\.\d+)?\s*\*\s*\d+(\.\d+)?/', $reg)) {
        // 다른 상품은 mm 표기(400*400*2000)인데 m 표기(0.8*0.8*2.0) → 수량 계산이 길이를 못 읽음
        $inc('spec_meter'); $add($r, '규격 치수 단위 혼재(m 표기)', $reg);
    }
    if ($r['source'] === 'ktomok') {
        if ($unit === null || $unit === '') { $inc('unit_missing'); $add($r, '단위 누락', ''); }
        elseif (preg_match('/^m\s*2$/iu', $unit) || $unit === '㎡') { $inc('unit_area'); }
        if ($unit !== null && preg_match('/^m\s+2$/iu', $unit)) { $inc('unit_m_space_2'); }
    }
    $byCatUnit[$r['cat_slug']][$unit ?? '(없음)'][] = $r;

    if ($r['price_b2c'] <= 0) { $inc('price_zero'); $add($r, '가격 0/없음', 'b2c=' . $r['price_b2c']); }
    if ($r['price_b2b'] > $r['price_b2c'] && $r['price_b2c'] > 0) { $inc('price_b2b_gt'); $add($r, '기업가 > 판매가', $r['price_b2b'] . ' > ' . $r['price_b2c']); }

    foreach (['운반비예상', '설치비예상', '상차수량', '중량'] as $fk) {
        $v = field($r['spec'], $fk);
        if ($v === null || $v === '') { continue; }
        if (preg_match('/\*|구성|주문|대표|주요품목|그레이팅:|파렛트/u', $v)) {
            $inc('field_pollution'); $add($r, "필드 오염({$fk})", '숫자 대신 설명 문장' . (str_ends_with($v, '원') ? ' + 끝에 "원"' : ''));
        }
    }
    if (preg_match('/&(raquo|amp|nbsp|lt|gt|quot);/', $r['spec'] . ($r['description'] ?? ''))) {
        $inc('html_entity'); $add($r, 'HTML 엔티티 잔존', '');
    }
    if (preg_match('/^https?:\/\//', $r['image_url']) && !preg_match('/tomokmall/', $r['image_url'])) { $inc('img_hotlink'); }
}
// 같은 카테고리에 단위가 여럿 (예: 중력식옹벽블록 m2/개)
foreach ($byCatUnit as $slug => $units) {
    if (count($units) > 1) {
        $inc('cat_multi_unit');
        foreach ($units as $u => $rs) { $add($rs[0], '카테고리 내 단위 혼재', ($catName[$slug] ?? $slug) . ': ' . implode(', ', array_map(fn($x) => $x . '(' . count($units[$x]) . ')', array_keys($units)))); break; }
    }
}
// 카테고리·단위별 가격 이상치 (중앙값 대비 5배 초과 / 1/5 미만)
foreach ($byCatUnit as $slug => $units) {
    foreach ($units as $u => $rs) {
        $ps = array_values(array_filter(array_map(fn($x) => $x['price_b2c'], $rs), fn($p) => $p > 0));
        if (count($ps) < 4) { continue; }
        sort($ps);
        $med = $ps[intdiv(count($ps), 2)];
        foreach ($rs as $r) {
            if ($r['price_b2c'] <= 0) { continue; }
            if ($r['price_b2c'] > $med * 5 || $r['price_b2c'] < $med / 5) {
                $inc('price_outlier'); $add($r, '가격 이상치(카테고리 중앙값 대비)', $r['price_b2c'] . ' vs 중앙값 ' . $med);
            }
        }
    }
}

// ── 7. 수량 계산 가능 여부 (실제 서비스 함수) ─────────────────────────
// 맨홀·뚜껑·소켓처럼 '개수로 주문'하는 점 설치물은 면적·길이 계산 대상이 아님(정상)
$pointRe = '/맨홀|집수정|뚜껑|주철|소켓|분기구|캡|집게|사다리|노바시|가로등기초|볼라드|빗물받이|오수받이|홈통|우수통|전원박스|몰드|카스토퍼|방지턱|수목보호|바리케이드|PHC|사다리|고무시트|험프/u';
$calc = ['area' => 0, 'length' => 0, 'point' => 0, 'none' => 0];
$noneByCat = [];
foreach ($sources['ktomok'] ?? [] as $r) {
    [$qa] = ai_estimate_quantity(['spec' => $r['spec']], 0.0, null, 10.0);
    [$ql] = ai_estimate_quantity(['spec' => $r['spec']], 10.0, null, null);
    $cn = $catName[$r['cat_slug']] ?? $r['cat_slug'];
    if ($qa !== null)      { $calc['area']++; }
    elseif ($ql !== null)  { $calc['length']++; }
    elseif (preg_match($pointRe, $cn . ' ' . $r['name'])) { $calc['point']++; }
    else                   { $calc['none']++; $noneByCat[$cn] = ($noneByCat[$cn] ?? 0) + 1; $add($r, '수량 계산 불가(1개 길이·크기 정보 없음)', (string) field($r['spec'], '규격')); }
}
arsort($noneByCat);

// ── 출력 ───────────────────────────────────────────────────────────
$fh = fopen("{$out}/issues.csv", 'w');
fwrite($fh, "\xEF\xBB\xBF");
fputcsv($fh, ['source', 'external_id', 'category_slug', 'name', 'check', 'detail'], ',', '"', '');
foreach ($issues as $i) { fputcsv($fh, $i, ',', '"', ''); }
fclose($fh);

$byCheck = [];
foreach ($issues as $i) { $byCheck[$i[4]] = ($byCheck[$i[4]] ?? 0) + 1; }
arsort($byCheck);

$md  = "# 수집 상품 데이터 품질 검증 결과\n\n";
$md .= "- 실행: " . date('Y-m-d H:i') . " · 대상: 저장소 `data/*/import.sql`(운영 DB 적재용 SQL) · 스크립트: `scripts/data-quality/check_products.php`(읽기 전용)\n";
$md .= "- 개인정보로 보이는 값은 이 보고서·CSV에 옮기지 않았습니다(건수와 상품 키만).\n\n";
$md .= "## 건수\n\n| 출처 | products.json | import.sql 파싱 |\n|---|---:|---:|\n";
foreach ($sources as $s => $rs) { $md .= "| {$s} | {$jsonCount[$s]} | " . count($rs) . " |\n"; }
$md .= "\n## 검사 항목별 건수\n\n| 검사 | 건수 |\n|---|---:|\n";
foreach ($byCheck as $c => $n) { $md .= "| {$c} | {$n} |\n"; }
$md .= "| 외부 이미지 핫링크(image_url이 외부 도메인) | " . ($stat['img_hotlink'] ?? 0) . " |\n";
$md .= "| 단위 표기 'm 2'(공백 포함) | " . ($stat['unit_m_space_2'] ?? 0) . " |\n";
$md .= "\n## 수량 자동 계산 가능 여부 (ktomok, 현재 `ai_estimate_quantity` 기준)\n\n";
$md .= "| 구분 | 건수 |\n|---|---:|\n| 면적(㎡)으로 계산 가능 | {$calc['area']} |\n| 길이(m)로 계산 가능 | {$calc['length']} |\n| 개수 주문 품목(맨홀·뚜껑·소켓 등, 계산 대상 아님) | {$calc['point']} |\n| 계산 필요하나 정보 부족 | {$calc['none']} |\n\n";
$md .= "정보 부족 상위 카테고리:\n\n| 카테고리 | 건수 |\n|---|---:|\n";
foreach (array_slice($noneByCat, 0, 15, true) as $c => $n) { $md .= "| {$c} | {$n} |\n"; }
file_put_contents("{$out}/report.md", $md);

echo $md;
