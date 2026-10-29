<?php
declare(strict_types=1);
/**
 * 이미 수집한 제조사 JSON(data/external/crawl/<출처>-*.json) 다시 가져오기 — 크롤링 없음
 *
 * 책임: 출처별 최신 JSON 을 읽어 ① 원문 문장 정리(회사 정보·사이트 공통 문구 제거) ② 품질 판정(이름 없음·머리글 행·규격 숫자 없음·
 *       취급 종류 아님) ③ 중복 합침 ④ 기존 가져오기(import.php: 정규화·개인정보 제거·우리 상품 매칭·검증 구역 저장) ⑤ 품질 보고서·승인 검토 목록.
 *       로컬 테스트 DB(*_test)에 외부 자료 테이블이 없으면 추가형 마이그레이션(database/20261029_external_products.sql)을 만든다.
 * 금지: 외부 접속·재수집, 수집 JSON 삭제·수정(정리본은 clean/ 에 따로), 운영 DB(DB_NAME *_test 만), 승인(상담 반영)은 하지 않음 — 사람이 import.php --approve.
 * DB: MariaDB(XAMPP)·MySQL 8.0 모두 가능(FULLTEXT 를 쓰지 않음).
 * 사용: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php scripts/external/import-crawled.php [--only=sunroad,dhconc] [--dry-run]
 *       결과: data/external/crawl/quality.md(출처별 원시·유효·중복·매칭), data/external/crawl/review.md(승인 검토 목록)
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$root = dirname(__DIR__, 2);
ob_start();
require "{$root}/app/bootstrap.php";
$bootMsg = (string) ob_get_clean();
require_once "{$root}/app/ai_tools.php";
require_once "{$root}/app/external_products.php";
require_once "{$root}/app/external_api.php";
require_once "{$root}/app/external_crawl.php";

$opt = getopt('', ['dir:', 'only:', 'dry-run']);
$dir = rtrim((string) ($opt['dir'] ?? (getenv('EXT_CRAWL_DIR') ?: "{$root}/data/external/crawl")), '/\\');
$reg = json_decode((string) file_get_contents(getenv('EXT_SOURCES_FILE') ?: "{$root}/data/external/sources.json"), true);
$only = isset($opt['only']) ? array_map('trim', explode(',', (string) $opt['only'])) : null;
$dry = isset($opt['dry-run']);
$die = static function (string $m): never { fwrite(STDERR, "[중단] {$m}\n"); exit(2); };

// ── DB 연결 진단(오류 2002 등) ─────────────────────────────────────
$dbName = (string) ($config['db']['name'] ?? '');
$pdo = db();
if (!$pdo) {
    $host = (string) ($config['db']['host'] ?? '');
    $die("DB 에 연결하지 못했습니다(DB_HOST='{$host}', DB_NAME='{$dbName}').\n"
        . (str_contains($host, 'port=') ? "  DB_HOST 에 '{$host}' 가 들어 있습니다 — 그 포트의 MySQL 이 꺼져 있으면 오류 2002 입니다.\n" : '')
        . "  XAMPP MariaDB 테스트 DB 를 쓰려면: XAMPP 제어판에서 MySQL Start → 새 Git Bash 창에서\n"
        . "    DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php scripts/external/import-crawled.php\n"
        . '  (이전 창에서 export 한 DB_HOST 가 남아 있으면 unset DB_HOST DB_NAME DB_USER DB_PASS)' . ($bootMsg !== '' ? "\n  " . trim($bootMsg) : ''));
}
if (!preg_match('/_test$/', $dbName)) { $die("로컬 테스트 DB(DB_NAME=*_test)에서만 실행합니다. 현재 '{$dbName}' — 운영 DB 에는 쓰지 않습니다."); }
$ver = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
if (!ext_tables_ready() && !$dry) {   // 추가형 테이블 2개만(CREATE TABLE IF NOT EXISTS) — 기존 테이블·데이터 변경 없음
    foreach (array_filter(array_map('trim', explode(';', (string) preg_replace('/^--.*$/m', '', (string) file_get_contents("{$root}/database/20261029_external_products.sql"))))) as $sql) {
        $pdo->exec($sql);
    }
    echo "테스트 DB 에 외부 자료 테이블 2개를 만들었습니다(external_products·external_product_matches).\n";
}
echo "DB: {$ver} · {$dbName}" . ($dry ? ' · --dry-run(저장 안 함)' : '') . "\n\n";

try {
    $products = $pdo->query('SELECT id, name, spec FROM products WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable) {
    $die("'{$dbName}' 에 products 테이블이 없습니다 — 토목몰 상품이 들어 있는 로컬 테스트 DB 를 지정하세요(매칭 기준).");
}
$prodNorm = array_map('ext_normalize_product', $products);
@mkdir("{$dir}/clean", 0777, true);
$rows = [];
$review = [];
$samples = [];
foreach ($reg['sources'] ?? [] as $s) {
    $key = $s['key'];
    if ($only && !in_array($key, $only, true)) { continue; }
    $files = glob("{$dir}/{$key}-*.json") ?: [];
    if (!$files) { continue; }
    sort($files);
    $file = end($files);   // 출처별 최신 수집본
    $raw = json_decode((string) file_get_contents($file), true) ?: [];
    $factsRaw = count(array_unique(array_merge([], ...array_map(static fn($r) => array_merge([], ...array_values((array) ($r['facts'] ?? []))), $raw))));
    $clean = ext_clean_page_facts($raw);
    $issues = [];
    $valid = [];
    foreach ($clean as $r) {
        $n = ext_normalize($r, $key);
        $why = ext_record_issue($n);
        if ($why !== '') {
            $issues[$why] = ($issues[$why] ?? 0) + 1;
            $nm = mb_substr(trim($n['name'] . ' ' . $n['spec_raw']), 0, 50);
            if (count($samples[$key][$why] ?? []) < 5 && !in_array($nm, $samples[$key][$why] ?? [], true)) { $samples[$key][$why][] = $nm; }   // 과잉 제외 확인용
            continue;
        }
        $valid[] = [$r, $n];
    }
    [$uniq, $dupes] = ext_dedup(array_column($valid, 1));
    $factsClean = [];
    foreach ($valid as [$r]) { foreach ((array) ($r['facts'] ?? []) as $k => $list) { foreach ($list as $t) { $factsClean[$t] = $k; } } }
    $grades = ['probable' => 0, 'review' => 0, 'none' => 0];
    foreach ($uniq as $u) { $grades[ext_best_matches($u, $prodNorm, 1)[0]['grade'] ?? 'none']++; }
    $hold = !in_array($s['permission'] ?? '', ['facts_if_clear', 'allowed'], true);   // 허락 필요·수집 안 함 출처는 수집본이 있어도 저장 안 함
    $saved = $hold ? '보류(허락 전 저장 안 함)' : '-';
    if (!$dry && $valid && !$hold) {
        $cf = "{$dir}/clean/{$key}.json";
        file_put_contents($cf, json_encode(array_column($valid, 0), JSON_UNESCAPED_UNICODE));
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/scripts/external/import.php") . ' --source=' . escapeshellarg($key)
            . ' --format=json --license-checked --limit=5000 --file=' . escapeshellarg($cf) . ' --md=' . escapeshellarg("{$dir}/{$key}-import.md") . ' 2>&1';
        $out = (string) shell_exec($cmd);
        $saved = preg_match('/검증 구역 저장 (\d+)건/u', $out, $m) ? $m[1] : ('실패: ' . mb_substr(trim($out), 0, 60));
    }
    $rows[] = [$key, $s['name'], basename($file), count($raw), $issues, count($valid), $dupes, count($uniq), $factsRaw, count($factsClean), $saved, $grades, $s['permission'] ?? ''];
    // 승인 검토 목록: 출처별 원문 문장(정리 후) + 충돌 표시
    $list = [];
    foreach ($factsClean as $t => $k) { $list[] = ['kind' => $k, 'text' => $t, 'conflict' => ext_fact_conflict($t)]; }
    $review[$key] = ['name' => $s['name'], 'facts' => $list];
}
if (!$rows) { $die("{$dir} 에 출처별 수집 JSON(<출처>-날짜.json)이 없습니다. 수집 폴더 위치를 --dir= 로 지정하세요."); }

// ── 보고서 ─────────────────────────────────────────────────────────
$sum = static fn(int $i) => array_sum(array_column($rows, $i));
$md = "# 수집 자료 품질·가져오기 결과 (" . date('Y-m-d H:i') . ", {$ver} · {$dbName})\n\n"
    . "원시 행은 상품 수가 아니다 — 머리글·규격 없는 행·취급 종류 아닌 행을 빼고, 같은 상품(제조사·종류·이름·치수·규격)을 합친 '고유' 가 유효 상품정보 수다.\n\n"
    . "| 출처 | 파일 | 원시 행 | 제외(사유) | 유효 행 | 중복 합침 | 고유 상품정보 | 원문 문장 정리 전→후 | DB 저장 | 매칭 유력·검토·없음 |\n|---|---|---|---|---|---|---|---|---|---|\n";
foreach ($rows as $r) {
    $iss = $r[4] ? implode(', ', array_map(static fn($k, $v) => "{$k} {$v}", array_keys($r[4]), $r[4])) : '-';
    $md .= "| {$r[0]} {$r[1]} | {$r[2]} | {$r[3]} | {$iss} | {$r[5]} | {$r[6]} | {$r[7]} | {$r[8]}→{$r[9]} | {$r[10]} | {$r[11]['probable']}·{$r[11]['review']}·{$r[11]['none']} |\n";
}
$md .= "| **합계** | | **{$sum(3)}** | | **{$sum(5)}** | **{$sum(6)}** | **{$sum(7)}** | {$sum(8)}→{$sum(9)} | | |\n\n"
    . "- 매칭: 유력(80점 이상) = 같은 종류·이름·치수가 거의 같은 우리 상품 후보, 검토(50~79) = 사람 확인 필요, 없음 = 우리 상품에 없는 규격(참고 지식으로만). 확정은 import.php --confirm.\n"
    . "- 상담 반영: 아직 0건. review.md 를 검토한 뒤 승인한 자료만 material_info·상품 카드 '참고 자료' 에 나온다.\n";
if ($samples) {   // 제외가 맞는지 사람이 확인할 수 있게 사유별 예시(이름·규격 그대로)
    $md .= "\n## 제외 예시(출처·사유별 최대 5개 — 우리 취급 자재가 잘못 빠졌는지 확인)\n\n";
    foreach ($samples as $k => $bySrc) {
        foreach ($bySrc as $why => $names) { $md .= "- {$k} · {$why}: " . implode(' / ', array_map(static fn($x) => str_replace('|', '/', $x), $names)) . "\n"; }
    }
}
file_put_contents("{$dir}/quality.md", $md);
$rv = "# 승인 검토 목록 (" . date('Y-m-d H:i') . ")\n\n원문 그대로의 문장이다(요약·보충 없음). 사실·출처를 확인한 출처만 승인한다. ⚠ 는 공식·사내 기준(차량 80T 등)과 충돌할 수 있는 문장을 자동으로 표시한 것 — 원문을 읽고 충돌이면 그 행을 제외(--reject)한다.\n";
$kindKo = ['uses' => '용도', 'features' => '특징', 'cautions' => '주의', 'certs' => '인증', 'strength' => '강도'];
foreach ($review as $k => $v) {
    $rv .= "\n## {$k} {$v['name']} — 원문 문장 " . count($v['facts']) . "개\n\n";
    foreach ($v['facts'] as $f) { $rv .= '- [' . ($kindKo[$f['kind']] ?? $f['kind']) . '] ' . $f['text'] . ($f['conflict'] !== '' ? " ⚠ {$f['conflict']}" : '') . "\n"; }
}
if (!$dry) {
    $rv .= "\n## 다음 단계(승인은 사람이)\n\n- 출처별 승인 후보 정리: `php scripts/external/approve-crawled.php` → data/external/crawl/approval-candidates.md\n"
        . "- 검토 후 출처별 승인: `php scripts/external/approve-crawled.php --approve=출처` (승인 후보 문장만 상담에 쓰임, ⚠ 행은 검토 대기 유지)\n";
}
file_put_contents("{$dir}/review.md", $rv);
echo $md . "\n보고서: {$dir}/quality.md · 검토 목록: {$dir}/review.md\n";
