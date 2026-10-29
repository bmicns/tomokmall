<?php
declare(strict_types=1);
/**
 * 이미 수집한 JSON 다시 가져오기(scripts/external/import-crawled.php) 통합 테스트 — 로컬 테스트 DB(*_test), 외부 접속 없음.
 *
 * 입력: 수집기 출력 형식의 가상 JSON('[테스트]' 표기) — 머리글 행·규격 숫자 없는 행·취급 종류 아닌 행·중복 행·사이트 공통 문구·저작권 문구·
 *       사내 80T 기준과 충돌하는 문장. 끝나면 source_key 'test_crawled' 행·임시 폴더를 지운다.
 * 확인: DB 접속 실패 진단(포트 지정 안내), 원시 행 ≠ 유효 상품정보(제외 사유·중복 합침), 원본 JSON 불변, 원문 문장 정리,
 *       검증 구역 저장(pending)·재실행해도 늘지 않음, 충돌 문장 ⚠·제외 명령, 승인 전 상담 자료 없음, products 불변, --dry-run 은 저장 안 함.
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/external_import_crawled.test.php  (MySQL 8.0·MariaDB 모두)
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
$tmp = sys_get_temp_dir() . '/import_crawled_test_' . getmypid();
@mkdir($tmp, 0777, true);
$reg = json_decode((string) file_get_contents(__DIR__ . '/../data/external/sources.json'), true);
$reg['sources'] = [['key' => 'test_crawled', 'name' => '[테스트]제조', 'domain' => 'm.example', 'permission' => 'facts_if_clear', 'basis' => '테스트', 'conditions' => ['테스트']],
                   ['key' => 'test_crawled_hold', 'name' => '[테스트]보류', 'domain' => 'h.example', 'permission' => 'pending', 'basis' => '테스트', 'conditions' => []]];
file_put_contents("{$tmp}/sources.json", json_encode($reg, JSON_UNESCAPED_UNICODE));

$common = '[테스트] 모든 제품 페이지에 반복되는 보도 시공 안내 문장입니다.';
$rec = static fn(string $name, string $spec, int $page, array $facts = []) => ['name' => $name, 'spec' => $spec, 'maker' => '[테스트]제조', 'category' => '', 'note' => '',
    'source_url' => "http://m.example/p{$page}", 'facts' => array_merge_recursive(['uses' => [$common]], $facts)];
$raw = [
    $rec('[테스트]인조화강블록 차도용', '규격 200*100 / 두께 80', 1, ['features' => ['[테스트] 투수성 포장재로 빗물이 블록 사이로 스며듭니다.', 'Copyright (c) 테스트 All rights reserved 내구성 우수']]),
    $rec('[테스트]인조화강블록 차도용', '규격 200*100 / 두께 80', 2),                       // 같은 상품(다른 쪽) → 중복 합침
    $rec('[테스트]인조화강블록 보도용', '규격 200*100 / 두께 60', 3, ['uses' => ['[테스트] 주차장 차량 통행 구간에도 두께 60mm 를 사용합니다.']]),   // ⚠ 충돌 문장
    $rec('품명', '규격 200*100', 4),                                                     // 머리글 행
    $rec('[테스트]인조화강블록', '규격 별도 문의', 4),                                      // 규격 숫자 없음
    $rec('[테스트]회사 연혁', '설립 1990', 4),                                             // 취급 종류 아님
];
$src = "{$tmp}/test_crawled-20261029-061800.json";
file_put_contents($src, json_encode($raw, JSON_UNESCAPED_UNICODE));
file_put_contents("{$tmp}/test_crawled-20261001-000000.json", json_encode([$rec('[테스트]예전 수집본', '1*1', 9)], JSON_UNESCAPED_UNICODE));   // 최신 파일만 사용
file_put_contents("{$tmp}/test_crawled_hold-20261029-061800.json", json_encode([$rec('[테스트]인조화강블록 보류', '규격 200*100 / 두께 80', 1)], JSON_UNESCAPED_UNICODE));
$hash = sha1_file($src);
$env = 'EXT_SOURCES_FILE=' . escapeshellarg("{$tmp}/sources.json") . ' EXT_CRAWL_DIR=' . escapeshellarg($tmp);
$run = static function (string $args = '', string $extraEnv = '') use ($env): array {
    exec("{$env} {$extraEnv} " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/external/import-crawled.php') . " {$args} 2>&1", $out, $code);
    return [$code, implode("\n", $out)];
};
$hasTables = static fn() => (int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('external_products', 'external_product_matches')")->fetchColumn() === 2;
$count = static fn() => $hasTables() ? (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE source_key = 'test_crawled'")->fetchColumn() : 0;
$hadTables = $hasTables();   // 예전 테스트 DB(외부 자료 테이블 없음)면 가져오기가 만들어야 함
$prodBefore = $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM);
try {
    echo "[DB 접속 실패 진단]\n";
    [$c0, $o0] = $run('', "DB_HOST='127.0.0.1;port=1'");
    ok('접속 실패: 중단 + DB_HOST 포트 안내·XAMPP 명령', $c0 === 2 && str_contains($o0, "DB_HOST 에 '127.0.0.1;port=1'") && str_contains($o0, 'DB_HOST=127.0.0.1 DB_NAME=tomokmall_test'));

    echo "[--dry-run]\n";
    [$c1, $o1] = $run('--dry-run');
    ok('dry-run: 품질 보고만, DB 저장·테이블 생성 없음', $c1 === 0 && $count() === 0 && str_contains($o1, '--dry-run') && $hasTables() === $hadTables);

    echo "[가져오기·품질]\n";
    [$c2, $o2] = $run();
    $q = (string) @file_get_contents("{$tmp}/quality.md");
    ok('외부 자료 테이블 2개가 없던 DB 면 만들고, 있으면 그대로', $hasTables() && ($hadTables || str_contains($o2, '외부 자료 테이블 2개를 만들었습니다')));
    ok('최신 수집본만 사용', $c2 === 0 && str_contains($q, 'test_crawled-20261029-061800.json') && !str_contains($q, '20261001'));
    ok('원시 6행 → 제외 3(머리글·규격 숫자 없음·취급 종류 아님) → 유효 3 → 중복 1 합침 → 고유 2', (bool) preg_match('/\| 6 \| [^|]*머리글 행 1[^|]*\| 3 \| 1 \| 2 \|/u', $q)
        && str_contains($q, '규격 숫자 없음 1') && str_contains($q, '취급 종류 아님 1'));
    ok('DB 저장 2건(검토 대기), 원본 JSON 불변, 정리본은 clean/ 에 따로', $count() === 2 && sha1_file($src) === $hash && is_file("{$tmp}/clean/test_crawled.json")
        && (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE source_key = 'test_crawled' AND review_status = 'pending'")->fetchColumn() === 2);
    $attrs = implode(' ', $pdo->query("SELECT attrs_json FROM external_products WHERE source_key = 'test_crawled'")->fetchAll(PDO::FETCH_COLUMN));
    ok('허락 필요(pending) 출처는 수집본이 있어도 저장 안 함', str_contains($q, '보류(허락 전 저장 안 함)')
        && (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE source_key = 'test_crawled_hold'")->fetchColumn() === 0);
    ok('원문 문장: 고유 문장은 원문 그대로, 저작권 문구는 제거', str_contains($attrs, '빗물이 블록 사이로 스며듭니다') && !str_contains($attrs, 'Copyright'));
    $rv = (string) @file_get_contents("{$tmp}/review.md");
    $badId = (int) $pdo->query("SELECT id FROM external_products WHERE source_key = 'test_crawled' AND thickness_mm = 60")->fetchColumn();
    ok('검토 목록: 충돌 문장 ⚠ 표시, 승인 명령 + ⚠ 행 제외 명령', str_contains($rv, '두께 60mm 를 사용합니다. ⚠') && str_contains($rv, '--approve-source=test_crawled') && str_contains($rv, "--reject={$badId}`"));

    echo "[재실행·상담 반영]\n";
    [$c3] = $run();
    ok('같은 JSON 을 다시 가져와도 행이 늘지 않음', $c3 === 0 && $count() === 2);
    ok('승인 전: material_info 에 이 자료 없음', !str_contains((string) tool_material_info(['topic' => '인조화강블록 투수']), '[테스트]'));
    ok('products 건수·판매가 합계 그대로', $prodBefore === $pdo->query('SELECT COUNT(*), SUM(price_b2c) FROM products')->fetch(PDO::FETCH_NUM));
} finally {
    if ($hasTables()) {
        $pdo->exec("DELETE m FROM external_product_matches m JOIN external_products e ON e.id = m.external_product_id WHERE e.source_key = 'test_crawled'");
        $pdo->exec("DELETE FROM external_products WHERE source_key = 'test_crawled'");
    }
    exec('rm -rf ' . escapeshellarg($tmp));
}
ok('테스트 행·임시 폴더 정리', $count() === 0 && !is_dir($tmp));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
