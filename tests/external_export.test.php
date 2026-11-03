<?php
declare(strict_types=1);
/**
 * 승인 자료 운영 이관(scripts/external/export-approved.php · app/external_export.php) 테스트 — 로컬 MySQL 전용, 외부 접속 없음.
 *
 * 1) 순수 함수: 막힘 사유(권한·도메인·메뉴·연락처·다른 종류 문장·긴 인용·현재 규칙 보류·문장 섞임), 이스케이프, 지문
 * 2) 통합(*_test): '[테스트]' 승인 행 → 내보내기 → 빈 DB(tomokmall_export_test)에 10·20 적용 → 30 지문이 내보낼 때 값과 같음,
 *    20 재실행해도 중복 없음, 90 되돌리기로 0행, 막힌 행이 있으면 SQL 미생성 / --allow-skip 이면 그 행만 빠짐, --expect 불일치 중단.
 *    끝나면 source_key 'test_exp%' 행·임시 폴더·tomokmall_export_test 를 지운다.
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php tests/external_export.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/external_export.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}\n"; }
}
$src = ['key' => 'test_exp', 'domain' => 'm.example', 'permission' => 'facts_if_clear'];
$row = static fn(array $facts, array $o = []) => $o + ['id' => 1, 'source_key' => 'test_exp', 'dedup_key' => sha1('a'), 'name' => '[테스트]스틸그레이팅 SG-1', 'product_type' => '그레이팅',
    'spec_raw' => '폭 300', 'source_url' => 'http://www.m.example/p1', 'review_status' => 'approved', 'ref_price' => null, 'attrs_json' => json_encode(['facts' => $facts], JSON_UNESCAPED_UNICODE)];
$good = '[테스트] 스틸 그레이팅은 미끄럼 방지를 위해 크로스 바를 사용합니다.';

echo "순수 함수\n";
ok('정상 행 막힘 없음', ext_export_issues($row(['features' => [$good]]), $src)['block'] === []);
ok('허락 필요 출처 막힘', (bool) ext_export_issues($row(['features' => [$good]]), ['permission' => 'pending'] + $src)['block']);
ok('등록부 없는 출처 막힘', (bool) ext_export_issues($row(['features' => [$good]]), null)['block']);
ok('도메인 다른 URL → 경고(리디렉션 확인)', (bool) ext_export_issues($row(['features' => [$good]], ['source_url' => 'http://evil.example/p']), $src)['warn']);
ok('승인 아닌 행 막힘', (bool) ext_export_issues($row(['features' => [$good]], ['review_status' => 'pending']), $src)['block']);
ok('메뉴 이름 행 막힘', (bool) ext_export_issues($row(['features' => [$good]], ['name' => '주메뉴 스틸그레이팅']), $src)['block']);
ok('연락처 포함 막힘', (bool) ext_export_issues($row(['features' => [$good . ' 문의 010-1234-5678']]), $src)['block']);
ok('문장 없음 막힘', (bool) ext_export_issues($row([]), $src)['block']);
ok('현재 규칙 보류 문장(홍보) 막힘', (bool) ext_export_issues($row(['features' => ['[테스트] 가장 우수한 그레이팅 제품입니다.']]), $src)['block']);
ok('긴 인용 막힘', (bool) ext_export_issues($row(['features' => ['[테스트] 스틸 그레이팅은 ' . str_repeat('촘촘한 ', 60) . '구조입니다.']]), $src)['block']);
$pipeFact = '[테스트] 흄관은 원심력으로 성형한 콘크리트관입니다.';
ok('다른 종류 제품 문장(그레이팅 행에 흄관 설명) 막힘', (bool) array_filter(ext_export_issues($row(['features' => [$pipeFact]]), $src)['block'], static fn($b) => str_contains($b, '다른 종류')));
ok('같은 계열(보도블록 행에 인터로킹 블록 문장) 허용', ext_fact_type_conflict('인터로킹 블록은 옆 블록과 맞물립니다.', '보도블록') === '');
$owners = [$good => [1 => '그레이팅', 2 => '흄관']];
ok('같은 문장이 다른 종류 행에도 → 막힘', (bool) ext_export_issues($row(['features' => [$good]]), $src, $owners)['block']);
ok('같은 문장이 같은 종류 행에도 → 경고만', ext_export_issues($row(['features' => [$good]]), $src, [$good => [1 => '그레이팅', 2 => '그레이팅']])['block'] === []
    && (bool) ext_export_issues($row(['features' => [$good]]), $src, [$good => [1 => '그레이팅', 2 => '그레이팅']])['warn']);
ok('제조사 자료 참고가 비움', ext_export_row($row(['features' => [$good]], ['ref_price' => '12000']), $src)['ref_price'] === null);
ok('공공데이터(allowed) 참고가 유지', ext_export_row($row(['features' => [$good]], ['ref_price' => '12000']), ['permission' => 'allowed'] + $src)['ref_price'] === 12000);
ok('등록부 aliases 도메인(한글 도메인 이동)은 경고 없음', ext_export_issues($row(['features' => [$good]], ['source_url' => 'http://xn--2i0bw4q0yi42ecvqlc.kr/p']), ['aliases' => ['xn--2i0bw4q0yi42ecvqlc.kr']] + $src)['warn'] === []);
ok("이름의 가격·'PRODUCT' 정리 — 경고 + 이관 값 정리", (bool) ext_export_issues($row(['features' => [$good]], ['name' => 'PRODUCT 21,500']), $src)['warn']
    && ext_export_row($row(['features' => [$good]], ['name' => 'PRODUCT 21,500', 'product_type' => '보도블록']), $src)['name'] === '보도블록'
    && ext_export_row($row(['features' => [$good]], ['name' => 'PRODUCT 투수블록']), $src)['name'] === '투수블록');
ok('치수 숫자(2,000*2,000·1,200mm)는 이름에 그대로', ext_display_name('2,000*2,000 암거') === '2,000*2,000 암거' && ext_display_name('흄관 1,200mm') === '흄관 1,200mm');
ok('이스케이프', ext_sql_value("a'b\\c\n") === "'a\\'b\\\\c\\n'" && ext_sql_value(null) === 'NULL' && ext_sql_value(3) === '3');
ok('지문에 이름 포함(이름이 바뀌면 다름)', ext_export_fingerprint([['source_key' => 'a', 'dedup_key' => '1', 'name' => 'x', 'attrs_json' => 'j']]) !== ext_export_fingerprint([['source_key' => 'a', 'dedup_key' => '1', 'name' => 'y', 'attrs_json' => 'j']]));
ok('지문은 행 순서와 무관', ext_export_fingerprint([['source_key' => 'a', 'dedup_key' => '1', 'attrs_json' => 'x'], ['source_key' => 'b', 'dedup_key' => '2', 'attrs_json' => 'y']])
    === ext_export_fingerprint([['source_key' => 'b', 'dedup_key' => '2', 'attrs_json' => 'y'], ['source_key' => 'a', 'dedup_key' => '1', 'attrs_json' => 'x']]));

$dbName = (string) ($config['db']['name'] ?? '');
$pdo = db();
if (!preg_match('/_test$/', $dbName) || !$pdo || !ext_tables_ready()) {
    echo "통합: SKIP(로컬 테스트 DB 없음)\n\n{$pass} 통과 / {$fail} 실패\n";
    exit($fail ? 1 : 0);
}
echo "통합 ({$dbName})\n";
$pdo->exec("DELETE FROM external_products WHERE source_key LIKE 'test_exp%'");
$others = (int) $pdo->query("SELECT COUNT(*) FROM external_products WHERE review_status = 'approved'")->fetchColumn();
if ($others > 0) { echo "  SKIP: 이 DB 에 다른 승인 행 {$others}개가 있어 내보내기 결과가 섞임(사용자 PC DB 에서는 실행하지 마세요)\n\n{$pass} 통과 / {$fail} 실패\n"; exit($fail ? 1 : 0); }
$tmp = sys_get_temp_dir() . '/ext_export_test_' . getmypid();
@mkdir($tmp, 0777, true);
file_put_contents("{$tmp}/sources.json", json_encode(['sources' => [$src, ['key' => 'test_exp_hold', 'domain' => 'h.example', 'permission' => 'pending']]], JSON_UNESCAPED_UNICODE));
$insert = $pdo->prepare('INSERT INTO external_products (source_key, external_id, dedup_key, name, maker, product_type, spec_raw, thickness_mm, attrs_json, unit, ref_price, source_url, license_note, fetched_at, review_status)
                         VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$facts = static fn(array $f, array $held = []) => json_encode(['facts' => $f, 'facts_held' => $held, 'source_name' => '[테스트]제조', 'reviewed_at' => '2026-10-29'], JSON_UNESCAPED_UNICODE);
$insert->execute(['test_exp', sha1('r1'), '[테스트]스틸그레이팅 SG-1', '[테스트]제조', '그레이팅', '폭 300 / 높이 30', null, $facts(['features' => [$good, "[테스트] 따옴표 '작은' \"큰\" 역슬래시 \\ 포함 문장입니다."]], [['kind' => 'features', 'text' => '[테스트] 가장 우수합니다.', 'reason' => '홍보']]), '개', 15000, 'http://m.example/p1', '테스트', '2026-10-29 06:18:00', 'approved']);
$insert->execute(['test_exp', sha1('r2'), '[테스트]인조화강블록 80T', '[테스트]제조', '인조화강블록', '200*100*80', 80, $facts(['uses' => ['[테스트] 인조화강블록은 보도 포장에 사용합니다.']]), '㎡', null, 'http://m.example/p2', '테스트', '2026-10-29 06:18:00', 'approved']);
$insert->execute(['test_exp', sha1('r4'), 'PRODUCT 21,500', '[테스트]제조', '보도블록', '200*200*60', 60, $facts(['features' => ['[테스트] 보도블록 표면은 오염방지 처리를 했습니다.']]), '㎡', null, 'http://m.example/p4', '테스트', '2026-10-29 06:18:00', 'approved']);
$insert->execute(['test_exp', sha1('r3'), '[테스트]스틸그레이팅 SG-9', '[테스트]제조', '그레이팅', '폭 400', null, $facts(['features' => [$good]]), '개', null, 'http://m.example/p3', '테스트', '2026-10-29 06:18:00', 'pending']);
$env = 'EXT_SOURCES_FILE=' . escapeshellarg("{$tmp}/sources.json") . ' EXT_EXPORT_DIR=' . escapeshellarg("{$tmp}/out");
$run = static function (string $args = '') use ($env): array {
    exec("{$env} " . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/external/export-approved.php') . " {$args} 2>&1", $o, $c);
    clearstatcache();   // 자식 프로세스가 지운 파일을 is_file 이 캐시로 '있음'이라 하지 않게
    return [$c, implode("\n", $o)];
};
[$c, $o] = $run('--expect=3');
ok('정상 내보내기(3행)', $c === 0 && is_file("{$tmp}/out/20_insert_approved.sql"));
[$c2] = $run('--expect=4');
ok('--expect 불일치 중단', $c2 !== 0);
ok('중단 시 이전 실행의 20·30 을 지움(옛 지문 SQL 적용 방지)', !is_file("{$tmp}/out/20_insert_approved.sql") && !is_file("{$tmp}/out/30_post_check_readonly.sql"));
$run('--expect=3');
$fp = preg_match('/fingerprint\s+([0-9a-f]{64})/', (string) @file_get_contents("{$tmp}/out/30_post_check_readonly.sql"), $m) ? $m[1] : '';
ok('사후 확인 SQL 에 지문 기대값', $fp !== '');
ok('매칭·참고가 이관 안 함', !str_contains((string) file_get_contents("{$tmp}/out/20_insert_approved.sql"), 'INSERT INTO external_product_matches') && !str_contains((string) file_get_contents("{$tmp}/out/20_insert_approved.sql"), '15000'));

// 빈 DB 에 적용 → 지문 대조 (운영 적용 모의)
$host = (string) $config['db']['host'];
$pdo->exec('DROP DATABASE IF EXISTS tomokmall_export_test');
$pdo->exec('CREATE DATABASE tomokmall_export_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$p2 = new PDO("mysql:host={$host};dbname=tomokmall_export_test;charset=utf8mb4", (string) $config['db']['user'], (string) $config['db']['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$p2->exec((string) file_get_contents("{$tmp}/out/10_create_tables.sql"));
$p2->exec((string) file_get_contents("{$tmp}/out/20_insert_approved.sql"));
$p2->exec((string) file_get_contents("{$tmp}/out/20_insert_approved.sql"));   // 다시 실행
ok('20 두 번 실행해도 3행', (int) $p2->query('SELECT COUNT(*) FROM external_products')->fetchColumn() === 3);
ok("이관된 이름에 가격 없음('PRODUCT 21,500' → '보도블록')", $p2->query("SELECT COUNT(*) FROM external_products WHERE name = '보도블록'")->fetchColumn() == 1 && $p2->query("SELECT COUNT(*) FROM external_products WHERE name LIKE '%21,500%'")->fetchColumn() == 0);
$p2->exec('SET SESSION group_concat_max_len = 1048576');
$sql = preg_replace('/^SET SESSION[^;]*;\s*/m', '', ext_export_fingerprint_sql());
$got = $p2->query($sql)->fetch(PDO::FETCH_ASSOC);
ok('운영 모의 DB 지문 = 내보낼 때 지문', ($got['fingerprint'] ?? '') === $fp && (int) $got['approved_rows'] === 3);
$orig = $pdo->query("SELECT attrs_json FROM external_products WHERE source_key = 'test_exp' AND dedup_key = '" . sha1('r1') . "'")->fetchColumn();
$copy = $p2->query("SELECT attrs_json FROM external_products WHERE dedup_key = '" . sha1('r1') . "'")->fetchColumn();
ok('따옴표·역슬래시 문장 원문 그대로', $orig === $copy);
foreach (explode(';', (string) preg_replace('/--[^\n]*/', '', (string) file_get_contents("{$tmp}/out/90_rollback.sql"))) as $s) {
    $s = trim($s);
    if ($s !== '' && str_starts_with($s, 'DELETE')) { $p2->exec($s); }
}
ok('되돌리기 후 0행', (int) $p2->query('SELECT COUNT(*) FROM external_products')->fetchColumn() === 0);
$p2 = null;
$pdo->exec('DROP DATABASE tomokmall_export_test');

// 막힌 행: 허락 필요 출처의 승인 행
$insert->execute(['test_exp_hold', sha1('h1'), '[테스트]흄관', '[테스트]보류', '흄관', 'D300', null, $facts(['uses' => ['[테스트] 흄관은 하수관으로 사용합니다.']]), '본', null, 'http://h.example/p1', '테스트', '2026-10-29 06:18:00', 'approved']);
ok('막힘 실행 전: 이전 실행의 20 이 있음', is_file("{$tmp}/out/20_insert_approved.sql"));
[$c3] = $run();
ok('막힌 행 있으면 SQL 미생성(이전 실행의 20·30 도 지움)', $c3 !== 0 && !is_file("{$tmp}/out/20_insert_approved.sql") && !is_file("{$tmp}/out/30_post_check_readonly.sql"));
[$c4] = $run('--allow-skip --expect=3');
ok('--allow-skip 이면 막힌 행만 빠짐', $c4 === 0 && !str_contains((string) file_get_contents("{$tmp}/out/20_insert_approved.sql"), 'test_exp_hold'));

$pdo->exec("DELETE FROM external_products WHERE source_key LIKE 'test_exp%'");
array_map('unlink', glob("{$tmp}/out/*") ?: []);
@rmdir("{$tmp}/out");
array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);
echo "\n{$pass} 통과 / {$fail} 실패\n";
exit($fail ? 1 : 0);
