<?php
declare(strict_types=1);
/**
 * 수집 자료 출처별 승인(scripts/external/approve-crawled.php) 통합 테스트 — 로컬 테스트 DB(*_test), 외부 접속 없음.
 *
 * 입력: 수집기 출력 형식의 가상 JSON('[테스트]' 표기) — 사실 문장·홍보 문장·메뉴 목록·다른 문장의 조각·두께 조건 없는 차도 설치 문장, 차도용인데 60mm 행.
 *       끝나면 source_key 'test_appr%' 행·임시 폴더를 지운다.
 * 확인: 보고서만으로는 승인 안 됨, 허락 필요 출처·import.php --approve-source 우회 거부, 승인은 후보 문장만 남기고 보류 문장은 facts_held(사유),
 *       ⚠ 행·승인 문장 없는 행은 검토 대기 유지, material_info 는 후보 문장만, 다시 가져와도 보류 문장이 되살아나지 않음, products 불변.
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/external_approve_crawled.test.php  (MySQL 8.0·MariaDB 모두)
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/external_products.php';

$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName) || !db()) {
    echo "SKIP: 로컬 테스트 DB(*_test)에서만 실행합니다.\n";
    exit(0);
}
$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$pdo = db();
$tmp = sys_get_temp_dir() . '/approve_crawled_test_' . getmypid();
@mkdir($tmp, 0777, true);
$reg = json_decode((string) file_get_contents(__DIR__ . '/../data/external/sources.json'), true);
$reg['sources'] = [['key' => 'test_appr', 'name' => '[테스트]제조', 'domain' => 'm.example', 'permission' => 'facts_if_clear', 'basis' => '테스트', 'conditions' => ['테스트']],
                   ['key' => 'test_appr_hold', 'name' => '[테스트]보류', 'domain' => 'h.example', 'permission' => 'pending', 'basis' => '테스트', 'conditions' => []]];
file_put_contents("{$tmp}/sources.json", json_encode($reg, JSON_UNESCAPED_UNICODE));
$good  = '[테스트] 스틸 그레이팅은 미끄럼 방지를 위해 크로스 바를 사용합니다.';
$promo = '[테스트] 가장 우수한 그레이팅 제품입니다.';
$menu  = '[테스트]주철뚜껑/스틸그레이팅/투수그레이팅/디자인그레이팅';
$frag  = '미끄럼 방지를 위해 크로스 바를 사용합니다.';
$road  = '[테스트] 표층 색상이 다양하며 보·차도 어디에나 설치가 가능합니다.';
$rec = static fn(string $name, string $spec, int $page, array $facts) => ['name' => $name, 'spec' => $spec, 'maker' => '[테스트]제조', 'category' => '', 'note' => '',
    'source_url' => "http://m.example/p{$page}", 'facts' => $facts];
file_put_contents("{$tmp}/test_appr-20261029-000000.json", json_encode([
    $rec('[테스트]스틸그레이팅 SG-1', '폭 300 / 높이 30', 1, ['features' => [$good, $promo, $menu]]),
    $rec('[테스트]스틸그레이팅 SG-2', '폭 400 / 높이 30', 2, ['features' => [$frag]]),
    $rec('[테스트]인조화강블록 차도용', '200*100 / 두께 60', 3, ['uses' => [$road]]),
], JSON_UNESCAPED_UNICODE));
$env = 'EXT_SOURCES_FILE=' . escapeshellarg("{$tmp}/sources.json") . ' EXT_CRAWL_DIR=' . escapeshellarg($tmp);
$run = static function (string $script, string $args = '') use ($env): array {
    exec("{$env} " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . "/../scripts/external/{$script}") . " {$args} 2>&1", $out, $code);
    return [$code, implode("\n", $out)];
};
$rows = static fn() => $pdo->query("SELECT name, review_status, attrs_json FROM external_products WHERE source_key = 'test_appr' ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$facts = static fn(string $topic) => json_encode(json_decode(tool_material_info(['topic' => $topic]), true)['facts'] ?? [], JSON_UNESCAPED_UNICODE);
$prodBefore = $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM);
try {
    [$c0] = $run('import-crawled.php');
    ok('가져오기: 3행 검토 대기', $c0 === 0 && count($rows()) === 3 && !array_filter($rows(), static fn($r) => $r['review_status'] !== 'pending'));

    echo "[승인 후보 보고서 — 자동 승인 없음]\n";
    [$c1, $o1] = $run('approve-crawled.php', '--only=test_appr');
    $md = (string) @file_get_contents("{$tmp}/approval-candidates.md");
    ok('보고서만 실행하면 승인 0건', $c1 === 0 && !array_filter($rows(), static fn($r) => $r['review_status'] !== 'pending'));
    ok('문장 단위 판정: 사실 문장은 후보, 홍보·메뉴 목록·조각·차도 설치 문장은 보류(사유)', str_contains($md, "✅ [특징] {$good}") && str_contains($md, "⏸ [특징] {$promo} ← 홍보")
        && str_contains($md, "{$menu} ← 메뉴") && str_contains($md, "{$frag} ← 다른 문장의 일부") && str_contains($md, "{$road} ← 기준 확인 필요"));
    ok('행 점검: 차도용인데 60mm 행 ⚠, 승인 시 상담 반영 1행', str_contains($md, '차도·차량 표기인데 두께 60mm') && (bool) preg_match('/\| test_appr \[테스트\]제조 \| facts_if_clear \| 3 \| 3·0·0 \| 1\/5 \| 1 \| 1 \|/u', $md));

    $run('approve-crawled.php', "--only=test_appr --exclude='크로스 바'");
    ok('--exclude: 검토자가 뺀 후보 문장은 보류(검토자 제외)', str_contains((string) @file_get_contents("{$tmp}/approval-candidates.md"), "{$good} ← 검토자 제외"));

    echo "[승인 경로 제한]\n";
    [$c2, $o2] = $run('approve-crawled.php', '--approve=test_appr_hold');
    ok('허락 필요(pending) 출처는 승인 거부', $c2 === 2 && str_contains($o2, '승인할 수 없는 출처'));
    [$c3, $o3] = $run('import.php', '--approve-source=test_appr');
    ok('import.php --approve-source 로 문장 검토를 건너뛸 수 없음', $c3 === 2 && str_contains($o3, 'approve-crawled.php') && !array_filter($rows(), static fn($r) => $r['review_status'] !== 'pending'));
    ok('승인 전: material_info 에 이 자료 없음', !str_contains($facts('그레이팅 미끄럼'), '[테스트]'));

    echo "[출처별 승인]\n";
    [$c4, $o4] = $run('approve-crawled.php', '--approve=test_appr');
    $st = array_column($rows(), 'review_status', 'name');
    ok('후보 문장이 있는 행만 승인, 조각만 있는 행·⚠ 행은 검토 대기', $c4 === 0 && $st['[테스트]스틸그레이팅 SG-1'] === 'approved' && $st['[테스트]스틸그레이팅 SG-2'] === 'pending'
        && $st['[테스트]인조화강블록 차도용'] === 'pending' && str_contains($o4, '승인 1행'));
    $a = json_decode((string) array_column($rows(), 'attrs_json', 'name')['[테스트]스틸그레이팅 SG-1'], true);
    ok('승인 행: 후보 문장만 facts, 보류 문장은 facts_held(사유 포함)', $a['facts'] === ['features' => [$good]] && count($a['facts_held']) === 2 && str_contains(json_encode($a['facts_held'], JSON_UNESCAPED_UNICODE), '홍보'));
    $f = $facts('그레이팅 미끄럼');
    ok('승인 후: material_info 는 후보 문장만(홍보·메뉴 문장 없음)', str_contains($f, $good) && !str_contains($f, '가장 우수') && !str_contains($f, '디자인그레이팅'));
    ok('두께 조건 없는 차도 설치 문장은 상담 자료에 없음', !str_contains($facts('인조화강블록 차도'), '어디에나'));

    echo "[다시 가져오기]\n";
    [$c5] = $run('import-crawled.php');
    $a2 = json_decode((string) array_column($rows(), 'attrs_json', 'name')['[테스트]스틸그레이팅 SG-1'], true);
    ok('같은 JSON 을 다시 가져와도 승인 상태·걸러 낸 문장 유지(보류 문장 안 되살아남)', $c5 === 0 && $a2['facts'] === ['features' => [$good]] && array_column($rows(), 'review_status', 'name')['[테스트]스틸그레이팅 SG-1'] === 'approved');
    ok('products 건수·판매가 합계 그대로', $prodBefore === $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM));
} finally {
    $pdo->exec("DELETE m FROM external_product_matches m JOIN external_products e ON e.id = m.external_product_id WHERE e.source_key LIKE 'test_appr%'");
    $pdo->exec("DELETE FROM external_products WHERE source_key LIKE 'test_appr%'");
    exec('rm -rf ' . escapeshellarg($tmp));
}
ok('테스트 행·임시 폴더 정리', (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE source_key LIKE 'test_appr%'")->fetchColumn() === 0 && !is_dir($tmp));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
