<?php
declare(strict_types=1);
/**
 * 원격 관리 API 순수 함수 단위 테스트 (DB·서버 불필요)
 * 실행: php tests/remote_ops.test.php
 * 대상: 통신 규약(서명·암호화·변조 차단), SQL 검사기(조회 전용·문장 분리·분류), 경로 검사, WHERE 조립, 줄 비교, 가림 규칙
 */
error_reporting(E_ALL);
require __DIR__ . '/../app/remote_ops/protocol.php';
require __DIR__ . '/../app/remote_ops/core.php';
require __DIR__ . '/../app/remote_ops/sql_guard.php';
require __DIR__ . '/../app/remote_ops/file_ops.php';
require __DIR__ . '/../app/remote_ops/diff.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}\n"; }
}
function throws(callable $fn, string $reason = ''): bool
{
    try { $fn(); return false; } catch (\Throwable $e) { return $reason === '' || $e->getMessage() === $reason; }
}

echo "[통신 규약 protocol]\n";
$key = rops_key_bytes(str_repeat('ab', 32));
$other = rops_key_bytes(str_repeat('cd', 32));
$n = rops_new_nonce();
$env = rops_seal($key, 'req', 'k1', 1730600000, $n, 'db.query', ['params' => ['sql' => 'SELECT 1', '한글' => '값']], ROPS_ENC_GCM);
ok('왕복: 서명·암호화 후 같은 평문', rops_open($key, $env, 'req') === ['params' => ['sql' => 'SELECT 1', '한글' => '값']]);
ok('암호문에 평문(SQL)이 보이지 않음', !str_contains(base64_decode($env['ct']), 'SELECT'));
ok('다른 키 → bad_signature', throws(fn() => rops_open($other, $env, 'req'), 'bad_signature'));
ok('방향 바꿈(req→res) → bad_signature', throws(fn() => rops_open($key, $env, 'res'), 'bad_signature'));
foreach (['ts' => 1730600001, 'op' => 'db.update', 'nonce' => rops_new_nonce(), 'kid' => 'k2'] as $f => $v) {
    $t = $env; $t[$f] = $v;
    ok("{$f} 변조 → bad_signature", throws(fn() => rops_open($key, $t, 'req'), 'bad_signature'));
}
$t = $env; $ct = base64_decode($t['ct']); $ct[0] = chr(ord($ct[0]) ^ 1); $t['ct'] = base64_encode($ct);
ok('암호문 1비트 변조 → bad_signature', throws(fn() => rops_open($key, $t, 'req'), 'bad_signature'));
$t = $env; unset($t['sig']);
ok('필드 누락 → bad_request', throws(fn() => rops_open($key, $t, 'req'), 'bad_request'));
$t = $env; $t['ts'] = '1730600000';
ok('ts 문자열 → bad_request', throws(fn() => rops_open($key, $t, 'req'), 'bad_request'));
$t = $env; $t['op'] = 'db.query;drop';
ok('작업 이름 형식 검사', rops_envelope_problem($t) === 'bad_request');
$plain = rops_seal($key, 'res', 'k1', 1730600000, $n, 'probe', ['ok' => true], ROPS_ENC_NONE);
ok('평문(none) 봉투도 서명 검증', rops_open($key, $plain, 'res') === ['ok' => true]);
$t = $plain; $t['ct'] = base64_encode('{"ok":false}');
ok('평문 봉투 내용 변조 → bad_signature', throws(fn() => rops_open($key, $t, 'res'), 'bad_signature'));
ok('짧은 키 거부', throws(fn() => rops_key_bytes('abcd'), 'bad_key_format'));
ok('16진수 아닌 키 거부', throws(fn() => rops_key_bytes(str_repeat('zz', 32)), 'bad_key_format'));
ok('서명키≠암호키(HKDF 분리)', rops_derive($key, 'mac') !== rops_derive($key, 'enc'));

echo "[SQL 문장 분리·지시문]\n";
ok('문자열 안 ; 는 나누지 않음', rops_sql_split("SELECT 'a;b'; SELECT 2;") === ["SELECT 'a;b'", 'SELECT 2']);
ok('주석 안 ; 무시', count(rops_sql_split("-- x; y\nUPDATE t SET a=1 WHERE id=1; /* ; */")) === 1);
ok('역슬래시 따옴표', count(rops_sql_split("INSERT INTO t VALUES ('it\\'s;'); SELECT 1")) === 2);
ok('@verify 지시문', rops_sql_verify_directives("-- @verify SELECT price_b2b FROM products WHERE id IN (1,2);\nUPDATE products SET price_b2b=1 WHERE id=1;") === ['SELECT price_b2b FROM products WHERE id IN (1,2)']);
ok('빈 문장 제거', rops_sql_split(" ; ;\n-- only comment\n") === []);

echo "[SQL 조회 전용 검사 rops_sql_read_problem]\n";
foreach ([
    'SELECT id, name FROM products WHERE name LIKE \'%투수블록%\' LIMIT 10',
    'select count(*) from products',
    'SHOW TABLES',
    'DESCRIBE products',
    'EXPLAIN SELECT * FROM products WHERE id = 1',
    "WITH x AS (SELECT id FROM products) SELECT * FROM x",
    "SELECT REPLACE(name, 'a', 'b') FROM products",
    "SELECT * FROM products WHERE name = 'update; delete'",
    'SELECT TABLE_NAME FROM information_schema.TABLES',
    '(SELECT 1) UNION (SELECT 2)',
] as $sql) {
    ok('허용: ' . mb_substr($sql, 0, 50), rops_sql_read_problem($sql) === null);
}
foreach ([
    'UPDATE products SET price_b2b = 1',
    'DELETE FROM products',
    'SELECT 1; DROP TABLE products',
    'SELECT * FROM products FOR UPDATE',
    'SELECT * FROM products LOCK IN SHARE MODE',
    "SELECT * INTO OUTFILE '/tmp/x' FROM products",
    'SELECT password_hash FROM users',
    'SELECT `password_hash` AS x FROM users',
    'SELECT LOAD_FILE(\'/etc/passwd\')',
    'SELECT SLEEP(10)',
    'SELECT * FROM mysql.user',
    'WITH x AS (SELECT 1) UPDATE products SET name = 1',
    'SHOW GRANTS',
    'EXPLAIN ANALYZE SELECT 1',
    'SET @a = 1',
    "SELECT 1 /*! ; DROP TABLE products */",
    'CALL proc()',
    '',
] as $sql) {
    ok('거부: ' . ($sql === '' ? '(빈 SQL)' : mb_substr($sql, 0, 50)), rops_sql_read_problem($sql) !== null);
}

echo "[SQL 분류 rops_sql_classify]\n";
$c = rops_sql_classify('UPDATE LOW_PRIORITY products SET price_b2b = 1 WHERE id = 3');
ok('UPDATE 대상·WHERE', $c['type'] === 'update' && $c['tables'] === ['products'] && $c['has_where'] && !$c['multi']);
$c = rops_sql_classify('UPDATE products p JOIN categories c ON c.id = p.category_id SET p.price_b2b = 1');
ok('여러 테이블 UPDATE 표시', $c['multi'] && !$c['has_where']);
$c = rops_sql_classify('DELETE FROM `products` WHERE id IN (1,2)');
ok('DELETE 따옴표 테이블', $c['type'] === 'delete' && $c['tables'] === ['products']);
ok('INSERT IGNORE INTO', rops_sql_classify('INSERT IGNORE INTO categories (name) VALUES (\'x\')')['tables'] === ['categories']);
ok('스키마 지정 표시', rops_sql_classify('UPDATE other.products SET a=1 WHERE id=1')['qualified'] === true);
ok('CREATE TABLE IF NOT EXISTS', ($c = rops_sql_classify('CREATE TABLE IF NOT EXISTS t1 (id INT)'))['type'] === 'ddl' && $c['tables'] === ['t1']);
ok('ALTER TABLE', rops_sql_classify('ALTER TABLE products ADD COLUMN x INT')['tables'] === ['products']);
ok('DROP TABLE 여러 개', rops_sql_classify('DROP TABLE IF EXISTS a, b')['tables'] === ['a', 'b']);
ok('RENAME TABLE', rops_sql_classify('RENAME TABLE a TO b, c TO d')['tables'] === ['a', 'b', 'c', 'd']);
ok('CREATE INDEX ON', rops_sql_classify('CREATE UNIQUE INDEX ix ON products (name)')['tables'] === ['products']);
ok('실행 주석 안 SET NAMES 거부', rops_sql_classify('/*!40101 SET NAMES utf8mb4 */')['type'] === 'denied');
foreach (['GRANT ALL ON *.* TO x', 'DROP DATABASE tomokmall', 'CREATE USER x', 'LOAD DATA INFILE \'x\' INTO TABLE t', 'LOCK TABLES t WRITE',
    'START TRANSACTION', 'SET GLOBAL x = 1', 'PREPARE s FROM \'x\'', 'CREATE TRIGGER x', 'CREATE VIEW v AS SELECT 1', 'USE other'] as $sql) {
    ok('금지 문장: ' . $sql, rops_sql_classify($sql)['type'] === 'denied');
}
$plan = rops_sql_plan("-- @verify SELECT id, price_b2b FROM products WHERE id = 1\nUPDATE products SET price_b2b = 10 WHERE id = 1;\nDELETE FROM products;");
ok('계획: WHERE 없는 DELETE 경고', count($plan['warnings']) === 1 && $plan['problems'] === [] && count($plan['verify']) === 1);
$plan = rops_sql_plan("ALTER TABLE products ADD COLUMN z INT;\nGRANT ALL ON *.* TO x;");
ok('계획: DDL 표시 + 금지 문장 문제', $plan['has_ddl'] && count($plan['problems']) === 1);
ok('계획: SELECT INTO OUTFILE 문제', rops_sql_plan("SELECT * FROM products INTO OUTFILE '/tmp/a'")['problems'] !== []);
ok('계획: DELIMITER 거부', rops_sql_plan("DELIMITER //\nCREATE PROCEDURE p() BEGIN END //")['problems'] !== []);

echo "[WHERE 조립 rops_where_compile]\n";
$cols = ['id', 'name', 'brand', 'price_b2b'];
$p = [];
ok('객체형(=, IN, NULL)', rops_where_compile(['id' => [1, 2], 'brand' => '대성', 'name' => null], $cols, $p) === '`id` IN (?,?) AND `brand` = ? AND `name` IS NULL' && $p === [1, 2, '대성']);
$p = [];
ok('목록형(LIKE, BETWEEN)', rops_where_compile([['name', 'like', '%블록%'], ['price_b2b', 'BETWEEN', [1, 9]]], $cols, $p) === '`name` LIKE ? AND `price_b2b` BETWEEN ? AND ?' && $p === ['%블록%', 1, 9]);
$p = [];
ok('없는 컬럼 거부', throws(fn() => rops_where_compile(['nope' => 1], $cols, $p)));
ok('주입 시도 컬럼 거부', throws(fn() => rops_where_compile(['id` = 1 OR 1=1 -- ' => 1], $cols, $p)));
ok('알 수 없는 연산자 거부', throws(fn() => rops_where_compile([['id', 'OR 1=1', 1]], $cols, $p)));
ok('빈 조건 거부', throws(fn() => rops_where_compile([], $cols, $p)));
ok('식별자 따옴표: 잘못된 이름 거부', throws(fn() => rops_qi('a`b')));

echo "[가림 규칙]\n";
ok('인증정보 컬럼', rops_is_secret_column('password_hash') && rops_is_secret_column('API_KEY') && rops_is_secret_column('reset_token') && !rops_is_secret_column('price_b2b'));
ok('개인정보 컬럼', rops_is_pii_column('email') && rops_is_pii_column('phone') && rops_is_pii_column('name', 'users') && !rops_is_pii_column('name', 'products'));

echo "[경로 검사]\n";
foreach (['public/index.php', 'app/bootstrap.php', 'database/x.sql', 'public/.htaccess', 'public/assets/images/home-블록.png', 'public'] as $path) {
    ok("허용 형식: {$path}", rops_path_problem($path) === null);
}
foreach (['../app/.env', 'public/../app/.env', '/html/index.php', 'app/.env', 'app/.env.remote-ops', 'public/.git/config', 'public//x.php',
    'public/./x.php', 'scripts/deploy.sh', 'html/index.php', 'public\\x.php', "public/x\0.php", 'C:/x', 'docs/a.md'] as $path) {
    ok('거부 형식: ' . str_replace("\0", '\\0', $path), rops_path_problem($path) !== null);
}
foreach (['app/remote_ops/server.php', 'app/remote_ops', 'public/api/remote-ops.php', 'public/admin-remote-ops.php', 'public/uploads/a.png',
    'public/shell.phtml', 'public/x.phar', 'app/run.sh', 'public/noext'] as $path) {
    ok("쓰기 금지: {$path}", rops_path_write_problem($path) !== null);
}
foreach (['app/pricing.php', 'public/assets/styles.css', 'public/.htaccess', 'database/20261103_x.sql', 'public/assets/images/a.PNG'] as $path) {
    ok("쓰기 가능: {$path}", rops_path_write_problem($path) === null);
}

ok('업로드 폴더 읽기 금지', rops_path_read_problem('public/uploads/a.pdf') !== null && rops_path_read_problem('public/uploads') !== null && rops_path_read_problem('public/uploadsx/a') === null);

echo "[PHP 문법 검사(서버 파서)]\n";
ok('정상 코드', rops_php_parse_problem("<?php\ndeclare(strict_types=1);\nfunction a(): int { return 1; }\n") === null);
ok('문법 오류 줄 번호', str_contains((string) rops_php_parse_problem("<?php\n\$a = ;\n"), '줄 2'));

echo "[줄 비교 rops_line_diff]\n";
$d = rops_line_diff("a\nb\nc\nd\n", "a\nB\nc\nd\ne\n");
ok('추가·삭제 수', $d['added'] === 2 && $d['removed'] === 1);
ok('통합 diff 형식', str_contains($d['text'], '@@ -1,') && str_contains($d['text'], "\n-b\n+B"));
ok('줄바꿈만 다름', rops_line_diff("a\r\nb\r\n", "a\nb\n")['eol_only'] === true);
ok('같음', rops_line_diff('x', 'x')['identical'] === true);
ok('새 파일(빈 → 내용)', rops_line_diff('', "a\nb")['added'] === 2);
$big = implode("\n", range(1, 3000));
$d = rops_line_diff($big, str_replace("\n1500\n", "\n1500x\n", $big));
ok('큰 파일 가운데 1줄 변경(앞뒤 공통 잘라냄)', $d['added'] === 1 && $d['removed'] === 1 && !$d['approx']);

echo "[설정 파일 파싱]\n";
$tmp = tempnam(sys_get_temp_dir(), 'rops');
file_put_contents($tmp, "# c\nA=1\nB=\"two words\"\nA=dup\n bad line\nC='x=y'\n");
ok('KEY=VALUE·따옴표·첫 값 우선', rops_env_parse($tmp) === ['A' => '1', 'B' => 'two words', 'C' => 'x=y']);
file_put_contents($tmp, "\xEF\xBB\xBFREMOTE_OPS_ENABLED=1\r\nREMOTE_OPS_KEY_ID=k1\r\n");
ok('BOM·CRLF 파일(Windows 메모장)', rops_env_parse($tmp) === ['REMOTE_OPS_ENABLED' => '1', 'REMOTE_OPS_KEY_ID' => 'k1']);
unlink($tmp);

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
