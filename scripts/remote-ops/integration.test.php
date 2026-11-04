<?php
declare(strict_types=1);
/**
 * 원격 관리 API 통합 테스트 본체 — scripts/remote-ops/test-local.sh 가 준비한 로컬 사본·테스트 DB 에서만 실행
 * (직접 실행 금지: 환경변수 ROPS_T_* 가 없으면 멈춘다. DB 는 *_test + 127.0.0.1 만 허용)
 *
 * 확인 항목: 인증 실패(키·키ID·시각·재전송·변조·메서드·암호화 강제·범위), 진단, DB 조회·검색·가림,
 *            승인 흐름(관리자 화면 로그인·CSRF·추가 확인), 범위 쓰기·전후 비교·검증·되돌리기, 잘못된 SQL, 트랜잭션 롤백,
 *            SQL 파일(미리 실행·실행·DDL 자동 백업·복원 요청), 파일 목록·읽기·경로 차단·심볼릭 링크, 배포·HTTP 확인·복구,
 *            배포 중 실패 시 자동 원복, 서버 파일 변동 감지, 관리 API 자체 파일 보호
 */
error_reporting(E_ALL);
require __DIR__ . '/../../app/remote_ops/protocol.php';

foreach (['ROPS_T_URL', 'ROPS_T_KEY', 'ROPS_T_KID', 'ROPS_T_SITE', 'ROPS_T_LOCAL', 'ROPS_T_DB_DSN', 'ROPS_T_DB_USER', 'ROPS_T_DB_PASS', 'ROPS_T_ADMIN_EMAIL', 'ROPS_T_ADMIN_PW', 'ROPS_T_BASE', 'ROPS_T_WORK'] as $k) {
    if (getenv($k) === false) { fwrite(STDERR, "test-local.sh 로 실행하세요({$k} 없음)\n"); exit(2); }
}
$DSN = getenv('ROPS_T_DB_DSN');
if (!preg_match('/host=127\.0\.0\.1;.*dbname=[a-z0-9_]+_test;/', $DSN)) { fwrite(STDERR, "테스트 DB(127.0.0.1, *_test)만 허용\n"); exit(2); }
$URL = getenv('ROPS_T_URL'); $BASE = getenv('ROPS_T_BASE'); $SITE = getenv('ROPS_T_SITE'); $LOCAL = getenv('ROPS_T_LOCAL'); $WORK = getenv('ROPS_T_WORK');
$KEYHEX = getenv('ROPS_T_KEY'); $KID = getenv('ROPS_T_KID');
$pdo = new PDO($DSN, getenv('ROPS_T_DB_USER'), getenv('ROPS_T_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, mixed $info = null): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else { $fail++; echo "  ✗ {$name}\n"; if ($info !== null) { echo '      ' . substr(json_encode($info, JSON_UNESCAPED_UNICODE), 0, 1500) . "\n"; } }
}
function q(string $sql, array $p = []): array { global $pdo; $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(); }
function q1(string $sql, array $p = []): mixed { $r = q($sql, $p); return $r ? array_values($r[0])[0] : null; }

/** CLI 실행 → [종료코드, JSON] */
function cli(array $args, array $env = []): array
{
    global $URL, $KEYHEX, $KID, $LOCAL;
    $envAll = array_merge(getenv(), ['TOMOK_OPS_URL' => $URL, 'TOMOK_OPS_KEY' => $KEYHEX, 'TOMOK_OPS_KEY_ID' => $KID, 'TOMOK_LOCAL_ROOT' => $LOCAL], $env);
    $envAll = array_filter($envAll, static fn($v) => $v !== null && $v !== false);
    $cmd = 'php ' . escapeshellarg(__DIR__ . '/tomok.php') . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $envAll);
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    $code = proc_close($p);
    $j = json_decode((string) $out, true);
    if (!is_array($j)) { $j = ['_raw' => substr((string) $out . $err, 0, 2000)]; }
    return [$code, $j];
}

/** 봉투 직접 전송(변조·재전송 시험용) */
function raw_post(string $body, string $method = 'POST'): array
{
    global $URL;
    $ch = curl_init($URL);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json']]);
    $r = (string) curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'json' => json_decode($r, true), 'raw' => $r];
}
function envelope(string $op, array $params, array $over = [], string $enc = ROPS_ENC_GCM): array
{
    global $KEYHEX, $KID;
    $e = rops_seal(rops_key_bytes($KEYHEX), 'req', $over['kid'] ?? $KID, $over['ts'] ?? time(), $over['nonce'] ?? rops_new_nonce(), $op, ['params' => $params], $enc);
    return $e;
}
function open_res(array $j): array { global $KEYHEX; return rops_open(rops_key_bytes($KEYHEX), $j, 'res'); }

// ── 관리자 화면(세션·CSRF) ──
$JAR = $WORK . '/cookies.txt';
function web(string $path, ?array $form = null, ?string $json = null, bool $useJar = true): array
{
    global $BASE, $JAR;
    $ch = curl_init($BASE . $path);
    $o = [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_HEADER => true];
    if ($useJar) { $o[CURLOPT_COOKIEJAR] = $JAR; $o[CURLOPT_COOKIEFILE] = $JAR; }
    if ($form !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = http_build_query($form); }
    if ($json !== null) { $o[CURLOPT_POST] = true; $o[CURLOPT_POSTFIELDS] = $json; $o[CURLOPT_HTTPHEADER] = ['Content-Type: application/json']; }
    curl_setopt_array($ch, $o);
    $r = (string) curl_exec($ch);
    $hs = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return ['status' => $code, 'headers' => substr($r, 0, $hs), 'body' => substr($r, $hs)];
}
function admin_csrf(string $code = ''): string
{
    $r = web('/admin-remote-ops.php' . ($code ? '?grant=' . rawurlencode($code) : ''));
    preg_match('/name="csrf" value="([0-9a-f]{64})"/', $r['body'], $m);
    return $m[1] ?? '';
}
function admin_decide(string $code, string $action, bool $confirm = false): string
{
    $form = ['csrf' => admin_csrf($code), 'code' => $code, 'action' => $action, 'note' => '[TEST] 통합 테스트'];
    if ($confirm) { $form['confirm_extra'] = '1'; }
    web('/admin-remote-ops.php', $form);
    $page = web('/admin-remote-ops.php?grant=' . rawurlencode($code))['body'];
    preg_match('/<div class="rops-flash (ok|err)"[^>]*>(.*?)<\/div>/s', $page, $m);
    return ($m[1] ?? '?') . ':' . html_entity_decode(trim($m[2] ?? ''));
}
function grant_status(string $code): string { return (string) q1('SELECT status FROM remote_ops_grants WHERE code = ?', [$code]); }

echo "[1. 인증·재전송 방지]\n";
[$c, $j] = cli(['probe'], ['TOMOK_OPS_KEY' => str_repeat('0', 64)]);
ok('다른 키 → 인증 실패(bad_signature), 종료코드 3', $c === 3 && ($j['error']['data']['reason'] ?? '') === 'bad_signature', $j);
[$c, $j] = cli(['probe'], ['TOMOK_OPS_KEY_ID' => 'zz']);
ok('다른 키 ID → unknown_key', ($j['error']['data']['reason'] ?? '') === 'unknown_key', $j);
[$c, $j] = cli(['probe'], ['TOMOK_OPS_KEY' => '']);
ok('키 없음 → CLI 가 요청 전 중단', $c === 3 && str_contains($j['error']['message'] ?? '', '키가 없습니다'), $j);
$r = raw_post(json_encode(envelope('probe', [], ['ts' => time() - 3600])));
ok('1시간 전 시각 → expired + server_time', ($r['json']['reason'] ?? '') === 'expired' && isset($r['json']['server_time']), $r['json']);
$e = envelope('db.tables', []);
$r1 = raw_post(json_encode($e));
$r2 = raw_post(json_encode($e));
ok('같은 요청 첫 번째는 성공', (open_res($r1['json'])['ok'] ?? false) === true);
ok('같은 요청 재전송 → replay 거부', (open_res($r2['json'])['error']['code'] ?? '') === 'replay', open_res($r2['json']));
$e = envelope('db.tables', []); $ct = base64_decode($e['ct']); $ct[3] = chr(ord($ct[3]) ^ 0xff); $e['ct'] = base64_encode($ct);
ok('암호문 변조 → bad_signature', (raw_post(json_encode($e))['json']['reason'] ?? '') === 'bad_signature');
ok('GET 요청 → method 거부(HTTP 200 + JSON)', ($r = raw_post('', 'GET'))['status'] === 200 && ($r['json']['reason'] ?? '') === 'method');
ok('JSON 아닌 본문 → bad_request', (raw_post('SELECT * FROM users')['json']['reason'] ?? '') === 'bad_request');
$r = raw_post(json_encode(envelope('db.tables', [], [], ROPS_ENC_NONE)));
ok('평문(none) 요청은 probe 외 거부 → encryption_required', (open_res($r['json'])['error']['code'] ?? '') === 'encryption_required');
ok('응답에 키·DB 비밀번호 없음', !str_contains($r1['raw'], $KEYHEX) && !str_contains($r1['raw'], getenv('ROPS_T_DB_PASS')));
$cfgFile = $SITE . '/app/.env.remote-ops';
$cfgOrig = file_get_contents($cfgFile);
file_put_contents($cfgFile, $cfgOrig . "REMOTE_OPS_SCOPES=probe,db.read,files.read\n");
[$c, $j] = cli(['db', 'request', '--title', 'x', '--tables', 'products', '--actions', 'update', '--max-rows', '1']);
ok('범위 제한(쓰기 없음) 키 → scope_denied', ($j['error']['code'] ?? '') === 'scope_denied', $j);
file_put_contents($cfgFile, str_replace('REMOTE_OPS_ENABLED=1', 'REMOTE_OPS_ENABLED=0', $cfgOrig));
[$c, $j] = cli(['probe']);
ok('사용 중지(REMOTE_OPS_ENABLED=0) → disabled', ($j['error']['data']['reason'] ?? '') === 'disabled', $j);
file_put_contents($cfgFile, $cfgOrig);

echo "[2. 진단 probe]\n";
[$c, $j] = cli(['probe']);
$pr = $j['result'] ?? [];
ok('probe 성공·PHP·DB·관리 테이블 4개·가격 보호 테이블 표시', $c === 0 && ($pr['db']['connected'] ?? false) && count($pr['db']['ops_tables'] ?? []) === 4
    && in_array('products', $pr['db']['price_protection']['price_tables'] ?? [], true), $j);
ok('probe 결과에 키 없음', !str_contains(json_encode($j), $KEYHEX));
[$c, $j] = cli(['probe', '--write-test']);
ok('쓰기 시험(임시 파일)은 승인 없이 거부(서버 파일 변경)', ($j['error']['code'] ?? '') === 'grant_inactive' && glob($SITE . '/public/.rops-probe-*') === [], $j);

echo "[3. DB 조회]\n";
[$c, $j] = cli(['db', 'tables']);
ok('db tables: products·remote_ops_log 포함', in_array('products', array_column($j['result']['tables'] ?? [], 'name'), true) && in_array('remote_ops_log', array_column($j['result']['tables'] ?? [], 'name'), true), $j);
[$c, $j] = cli(['db', 'describe', 'products']);
ok('db describe: 기본키 id, CASCADE 자식 product_vendors', ($j['result']['primary_key'] ?? null) === ['id'] && in_array('product_vendors', $j['result']['referenced_by_cascade'] ?? [], true), $j);
[$c, $j] = cli(['db', 'query', '--sql', 'SELECT id, name, price_b2b FROM products WHERE brand LIKE ? ORDER BY id', '--param', '%대성산업%']);
ok('db query 바인딩 조회 3행', $c === 0 && ($j['result']['row_count'] ?? 0) === 3 && $j['result']['rows'][0]['id'] === 1001, $j);
[$c, $j] = cli(['db', 'query', '--sql', 'SELECT id FROM products', '--limit', '2']);
ok('행 수 제한 → truncated', ($j['result']['truncated'] ?? false) === true && $j['result']['row_count'] === 2, $j);
[$c, $j] = cli(['db', 'query', '--sql', "UPDATE products SET price_b2b = 1 WHERE id = 1001"]);
ok('조회 API 로 UPDATE → sql_not_allowed', $c === 1 && ($j['error']['code'] ?? '') === 'sql_not_allowed', $j);
ok('거부된 UPDATE 는 실행 안 됨(독립 SQL 확인)', (int) q1('SELECT price_b2b FROM products WHERE id = 1001') === 10000);
[$c, $j] = cli(['db', 'query', '--sql', 'SELEC id FROM products']);
ok('잘못된 SQL → 조회 검사 거부', $c === 1 && in_array($j['error']['code'] ?? '', ['sql_not_allowed', 'sql_error'], true), $j);
[$c, $j] = cli(['db', 'query', '--sql', 'SELECT nope_column FROM products']);
ok('없는 컬럼 → sql_error(MySQL 오류 문구)', ($j['error']['code'] ?? '') === 'sql_error' && str_contains($j['error']['message'] ?? '', 'nope_column'), $j);
[$c, $j] = cli(['db', 'query', '--sql', 'SELECT password_hash FROM users']);
ok('인증정보 컬럼 조회 거부', ($j['error']['code'] ?? '') === 'sql_not_allowed', $j);
[$c, $j] = cli(['db', 'query', '--sql', 'SELECT * FROM users ORDER BY id']);
ok('인증정보 컬럼이 있는 테이블의 SELECT * → 거부', ($j['error']['code'] ?? '') === 'sql_not_allowed', $j);
[$c, $j] = cli(['db', 'query', '--sql', 'SELECT 1,2,3,4,5,6,7,8,9,10,11 FROM DUAL WHERE FALSE UNION ALL SELECT * FROM users']);
ok('[검토 #2] UNION 으로 이름 바꿔 password_hash 빼내기 → 거부', ($j['error']['code'] ?? '') === 'sql_not_allowed' && !str_contains(json_encode($j), '$2y$'), $j);
[$c, $j] = cli(['db', 'query', '--sql', 'SHOW CREATE USER CURRENT_USER()']);
ok('[검토 #3] SHOW CREATE USER(DB 계정 인증 해시) → 거부', ($j['error']['code'] ?? '') === 'sql_not_allowed', $j);
[$c, $j] = cli(['db', 'query', '--sql', 'SELECT id, name, email, phone, role FROM users ORDER BY id']);
$u = $j['result']['rows'][0] ?? [];
ok('users 컬럼 지정 조회: 이메일·전화·이름 [PII]', ($u['email'] ?? '') === '[PII]' && ($u['phone'] ?? '') === '[PII]' && ($u['name'] ?? '') === '[PII]' && ($u['role'] ?? '') === 'admin', $u);
[$c, $j] = cli(['db', 'query', '--sql', 'SELECT email FROM users ORDER BY id', '--reveal-pii']);
ok('--reveal-pii 일 때만 개인정보 표시', str_ends_with((string) ($j['result']['rows'][0]['email'] ?? ''), '@tomokmall.test'), $j);
[$c, $j] = cli(['db', 'search', 'products', '대성산업', '투수블록']);
ok('상품 검색 "대성산업 투수블록" → 1001~1003 + 업체명', array_column($j['result']['rows'] ?? [], 'id') === [1001, 1002, 1003] && str_contains((string) $j['result']['rows'][0]['vendors'], '대성산업'), $j);
[$c, $j] = cli(['db', 'search', 'categories', '콘크리트']);
ok('카테고리 검색 + 경로', ($j['result']['row_count'] ?? 0) >= 1 && isset($j['result']['rows'][0]['path']), $j);

echo "[4. DB 범위 승인 + 구조화 쓰기]\n";
// 고정값(가상 데이터): stock_qty 1001=50, 1002=20, 1003=0, 1004=10, 1005=5 / price_b2b 1001=10000 … (가격은 아래에서 '안 바뀜'만 확인)
// 시작 시점에 있던 행만 비교(테스트 중 새로 추가된 행은 '기존 가격 데이터'가 아니므로 제외)
$P_MAX = (int) q1('SELECT MAX(id) FROM products');
$C_MAX = (int) q1('SELECT MAX(id) FROM categories');
$priceSnap = static fn(): string => (string) q1("SELECT GROUP_CONCAT(CONCAT_WS(':', id, price_b2c, price_b2b, cost_price, category_id) ORDER BY id) FROM products WHERE id <= {$P_MAX}")
    . '|' . (string) q1("SELECT GROUP_CONCAT(CONCAT_WS(':', id, discount_customer, discount_corporate, COALESCE(parent_id, 0)) ORDER BY id) FROM categories WHERE id <= {$C_MAX}")
    . '|' . (string) q1("SELECT GROUP_CONCAT(CONCAT_WS(':', product_id, vendor_id, supply_price) ORDER BY product_id, vendor_id) FROM product_vendors");
$PRICES0 = $priceSnap();
[$c, $j] = cli(['db', 'request', '--title', '[TEST] 대성산업 투수블록 재고 수정', '--tables', 'products', '--actions', 'update', '--columns', 'products.stock_qty',
    '--ids', 'products:1001,1002,1003', '--max-rows', '3', '--ttl', '30', '--detail', '테스트']);
$G1 = $j['result']['grant']['code'] ?? '';
ok('승인 요청 생성(pending) + 대상 행 미리보기 3행 + 가격 보호 컬럼 표시', $G1 !== '' && $j['result']['grant']['status'] === 'pending' && ($j['result']['preview']['tables'][0]['rows_found'] ?? 0) === 3
    && in_array('price_b2b', $j['result']['preview']['tables'][0]['price_locked_columns'] ?? [], true) && in_array('category_id', $j['result']['preview']['tables'][0]['price_locked_columns'] ?? [], true), $j);
[$c, $j] = cli(['db', 'update', '--grant', $G1, '--table', 'products', '--set', 'stock_qty=51', '--where', 'id=1001']);
ok('승인 전 쓰기 → grant_pending 거부', ($j['error']['code'] ?? '') === 'grant_pending', $j);
// 관리자 화면: 로그인 전/관리자
$r = web('/admin-remote-ops.php');
ok('비로그인 관리자 화면 → 로그인으로 이동(302)', $r['status'] === 302 && str_contains($r['headers'], 'login.php'));
$r = web('/api/auth/login.php', null, json_encode(['email' => getenv('ROPS_T_ADMIN_EMAIL'), 'password' => getenv('ROPS_T_ADMIN_PW'), 'admin_only' => true]));
ok('[TEST] 관리자 로그인', str_contains($r['body'], '"ok":true'), $r['body']);
$page = web('/admin-remote-ops.php?grant=' . $G1)['body'];
ok('승인 화면에 요청·대상 행·가격 보호 안내 표시', str_contains($page, $G1) && str_contains($page, '[TEST] 투수블록 200x100') && str_contains($page, '승인 대기')
    && str_contains($page, '가격 보호(변경 불가)') && str_contains($page, '여기서 승인해도 서버가 변경을 막습니다'));
web('/admin-remote-ops.php', ['csrf' => 'bad', 'code' => $G1, 'action' => 'approve']);
ok('CSRF 토큰 틀리면 승인 안 됨', grant_status($G1) === 'pending');
$msg = admin_decide($G1, 'approve');
ok('관리자 승인 → approved', grant_status($G1) === 'approved' && str_starts_with($msg, 'ok:'), $msg);
[$c, $j] = cli(['grant', 'status', $G1]);
ok('grant status: approved·is_live', ($j['result']['grant']['status'] ?? '') === 'approved' && $j['result']['grant']['is_live'] === true, $j);
[$c, $j] = cli(['db', 'update', '--grant', $G1, '--table', 'products', '--set', 'stock_qty=51', '--where', 'id=1001', '--dry-run']);
ok('드라이런: 변경 1행 미리보기', ($j['result']['dry_run'] ?? false) === true && ($j['result']['diff'][0]['columns']['stock_qty'] ?? null) === [50, 51], $j);
ok('드라이런 후 실제 값 그대로(독립 SQL)', (int) q1('SELECT stock_qty FROM products WHERE id = 1001') === 50);
[$c, $j] = cli(['db', 'update', '--grant', $G1, '--table', 'products', '--set', 'name=바꿈', '--where', 'id=1001']);
ok('승인 범위 밖 컬럼(name) → out_of_scope', ($j['error']['code'] ?? '') === 'out_of_scope', $j);
[$c, $j] = cli(['db', 'update', '--grant', $G1, '--table', 'products', '--set', 'stock_qty=1', '--where', 'id=1004']);
ok('승인 범위 밖 행(1004) → out_of_scope', ($j['error']['code'] ?? '') === 'out_of_scope', $j);
[$c, $j] = cli(['db', 'update', '--grant', $G1, '--table', 'categories', '--set', 'name=x', '--where', 'id=1']);
ok('승인 범위 밖 테이블 → out_of_scope', ($j['error']['code'] ?? '') === 'out_of_scope', $j);
[$c, $j] = cli(['db', 'update', '--grant', $G1, '--table', 'products', '--set', 'stock_qty=51', '--where', 'id=1001']);
$OP_UPD = (int) ($j['op_id'] ?? 0);
ok('실제 수정 1행 + 전후 비교', $c === 0 && ($j['result']['changed_rows'] ?? 0) === 1 && ($j['result']['diff'][0]['columns']['stock_qty'] ?? null) === [50, 51], $j);
ok('수정 반영(독립 SQL)', (int) q1('SELECT stock_qty FROM products WHERE id = 1001') === 51);
[$c, $j] = cli(['db', 'verify', '--operation', (string) $OP_UPD]);
ok('db verify: 현재값 = 변경 후 값', ($j['result']['matches'] ?? false) === true, $j);
// 일괄 수정(행마다 다른 값) + 예상값 불일치 → 전체 롤백
file_put_contents($WORK . '/batch-bad.json', json_encode(['table' => 'products', 'rows' => [
    ['key' => ['id' => 1002], 'set' => ['stock_qty' => 21], 'expect' => ['stock_qty' => 20]],
    ['key' => ['id' => 1003], 'set' => ['stock_qty' => 1], 'expect' => ['stock_qty' => 99999]],
]]));
[$c, $j] = cli(['db', 'batch', '--grant', $G1, '--file', $WORK . '/batch-bad.json']);
ok('일괄 수정: 예상값 불일치 → expect_mismatch', ($j['error']['code'] ?? '') === 'expect_mismatch', $j);
ok('일괄 수정 실패 시 앞 행도 반영 안 됨(트랜잭션 롤백)', (int) q1('SELECT stock_qty FROM products WHERE id = 1002') === 20);
file_put_contents($WORK . '/batch.json', json_encode(['table' => 'products', 'rows' => [
    ['key' => ['id' => 1002], 'set' => ['stock_qty' => 21], 'expect' => ['stock_qty' => 20]],
    ['key' => ['id' => 1003], 'set' => ['stock_qty' => 1], 'expect' => ['stock_qty' => 0]],
]]));
[$c, $j] = cli(['db', 'batch', '--grant', $G1, '--file', $WORK . '/batch.json']);
$OP_BATCH = (int) ($j['op_id'] ?? 0);
ok('일괄 수정 2행(서로 다른 값)', ($j['result']['changed_rows'] ?? 0) === 2 && q1('SELECT GROUP_CONCAT(stock_qty ORDER BY id) FROM products WHERE id IN (1002,1003)') === '21,1', $j);
[$c, $j] = cli(['db', 'update', '--grant', $G1, '--table', 'products', '--set', 'stock_qty=1', '--where', 'id=1001']);
ok('누적 최대 행 수(3) 초과 → too_many_rows', ($j['error']['code'] ?? '') === 'too_many_rows', $j);
// 되돌리기
$pdo->exec('UPDATE products SET stock_qty = 22 WHERE id = 1002');
[$c, $j] = cli(['db', 'revert', '--operation', (string) $OP_BATCH]);
ok('작업 뒤 값이 바뀐 행이 있으면 되돌리기 거부(revert_conflict)', ($j['error']['code'] ?? '') === 'revert_conflict', $j);
$pdo->exec('UPDATE products SET stock_qty = 21 WHERE id = 1002');
[$c, $j] = cli(['db', 'revert', '--operation', (string) $OP_BATCH]);
ok('되돌리기 성공 → 원래 값(20,0)', $c === 0 && q1('SELECT GROUP_CONCAT(stock_qty ORDER BY id) FROM products WHERE id IN (1002,1003)') === '20,0', $j);
[$c, $j] = cli(['db', 'revert', '--operation', (string) $OP_BATCH]);
ok('같은 작업 두 번 되돌리기 거부', ($j['error']['code'] ?? '') === 'already_reverted', $j);
[$c, $j] = cli(['db', 'revert', '--operation', (string) $OP_UPD]);
ok('단건 수정 되돌리기 → 50', $c === 0 && (int) q1('SELECT stock_qty FROM products WHERE id = 1001') === 50, $j);

echo "[4-1. 가격 데이터 보호(승인해도 차단)]\n";
foreach (['products.price_b2b', 'products.price_b2c', 'products.cost_price', 'products.category_id'] as $col) {
    [$t, $cname] = explode('.', $col);
    [$c, $j] = cli(['db', 'request', '--title', 'x', '--tables', $t, '--actions', 'update', '--columns', $col, '--max-rows', '1']);
    ok("가격 컬럼 수정 승인 요청 자체 거부: {$col}", ($j['error']['code'] ?? '') === 'price_protected' && str_contains($j['error']['message'] ?? '', '사용자에게 보고'), $j);
}
[$c, $j] = cli(['db', 'request', '--title', 'x', '--tables', 'categories', '--actions', 'update', '--columns', 'categories.discount_corporate', '--max-rows', '1']);
ok('카테고리 마진율 수정 요청 거부', ($j['error']['code'] ?? '') === 'price_protected', $j);
[$c, $j] = cli(['db', 'request', '--title', 'x', '--tables', 'categories', '--actions', 'update', '--columns', 'categories.parent_id', '--max-rows', '1']);
ok('카테고리 상위(마진 상속) 변경 요청 거부', ($j['error']['code'] ?? '') === 'price_protected', $j);
foreach (['products', 'categories', 'product_vendors', 'vendors'] as $t) {
    [$c, $j] = cli(['db', 'request', '--title', 'x', '--tables', $t, '--actions', 'delete', '--max-rows', '1']);
    ok("삭제 요청 거부(가격 테이블·가격 테이블로 번짐): {$t}", ($j['error']['code'] ?? '') === 'price_protected', $j);
}
// 컬럼 제한 없는 승인을 받아도 실행 단계에서 가격 컬럼은 막힘
[$c, $j] = cli(['db', 'request', '--title', '[TEST] 상품 정보(컬럼 제한 없음)', '--tables', 'products,categories', '--actions', 'update,insert', '--max-rows', '10']);
$GP = $j['result']['grant']['code'] ?? '';
admin_decide($GP, 'approve');
foreach (['price_b2b=1', 'price_b2c=1', 'cost_price=1', 'category_id=3'] as $set) {
    [$c, $j] = cli(['db', 'update', '--grant', $GP, '--table', 'products', '--set', $set, '--where', 'id=1001']);
    ok("승인 범위 안이어도 가격 쓰기 차단: {$set}", ($j['error']['code'] ?? '') === 'price_protected', $j);
}
[$c, $j] = cli(['db', 'update', '--grant', $GP, '--table', 'products', '--set', 'name=[TEST] 이름바꿈', '--set', 'price_b2b=1', '--where', 'id=1001', '--dry-run']);
ok('다른 컬럼과 함께 넣은 가격 변경도 차단(드라이런 포함)', ($j['error']['code'] ?? '') === 'price_protected', $j);
[$c, $j] = cli(['db', 'update', '--grant', $GP, '--table', 'categories', '--set', 'discount_customer=9', '--where', 'id=2']);
ok('마진율 쓰기 차단', ($j['error']['code'] ?? '') === 'price_protected', $j);
file_put_contents($WORK . '/batch-price.json', json_encode(['table' => 'products', 'rows' => [
    ['key' => ['id' => 1002], 'set' => ['stock_qty' => 21]], ['key' => ['id' => 1003], 'set' => ['price_b2b' => 1]]]]));
[$c, $j] = cli(['db', 'batch', '--grant', $GP, '--file', $WORK . '/batch-price.json']);
ok('일괄 수정 중 한 행에 가격 → 전체 차단(앞 행도 안 바뀜)', ($j['error']['code'] ?? '') === 'price_protected' && (int) q1('SELECT stock_qty FROM products WHERE id = 1002') === 20, $j);
file_put_contents($WORK . '/p-ins.json', json_encode([['category_id' => 2, 'name' => '[TEST] 새 상품', 'price_b2c' => 5000]]));
[$c, $j] = cli(['db', 'insert', '--grant', $GP, '--table', 'products', '--file', $WORK . '/p-ins.json']);
ok('새 상품에 가격 넣기 차단', ($j['error']['code'] ?? '') === 'price_protected' && (int) q1("SELECT COUNT(*) FROM products WHERE name = '[TEST] 새 상품'") === 0, $j);
file_put_contents($WORK . '/p-ins2.json', json_encode([['category_id' => 2, 'name' => '[TEST] 새 상품(가격 없음)', 'brand' => '[TEST]']]));
[$c, $j] = cli(['db', 'insert', '--grant', $GP, '--table', 'products', '--file', $WORK . '/p-ins2.json']);
$OP_PINS = (int) ($j['op_id'] ?? 0);
ok('가격 없이 새 상품 추가는 허용(기본값 0 — 가격은 사람이 입력)', $c === 0 && (int) q1("SELECT price_b2b FROM products WHERE name = '[TEST] 새 상품(가격 없음)'") === 0, $j);
[$c, $j] = cli(['db', 'revert', '--operation', (string) $OP_PINS]);
ok('방금 추가한 행 되돌리기(삭제)는 허용', $c === 0 && (int) q1("SELECT COUNT(*) FROM products WHERE name = '[TEST] 새 상품(가격 없음)'") === 0, $j);
$pdo->exec("CREATE TRIGGER rops_t_vendors BEFORE UPDATE ON vendors FOR EACH ROW SET NEW.memo = NEW.memo");
[$c, $j] = cli(['db', 'request', '--title', 'x', '--tables', 'vendors', '--actions', 'update', '--max-rows', '1']);
ok('트리거가 있는 테이블 쓰기 요청 거부(다른 데이터·가격이 함께 바뀔 수 있음)', ($j['error']['code'] ?? '') === 'price_protected' && str_contains($j['error']['message'] ?? '', '트리거'), $j);
$pdo->exec('DROP TRIGGER rops_t_vendors');
ok('가격 데이터 전체(상품 가격·카테고리·마진율·공급가) 변동 없음(독립 SQL)', $priceSnap() === $PRICES0);
cli(['grant', 'cancel', $GP]);

// 삭제·추가 범위(가격 없는 테이블)
[$c, $j] = cli(['db', 'request', '--title', '[TEST] 삭제·추가', '--tables', 'search_logs', '--actions', 'delete,insert', '--max-rows', '5']);
$G2 = $j['result']['grant']['code'] ?? '';
ok('삭제·추가 승인 요청(가격 없는 테이블)', $G2 !== '', $j);
admin_decide($G2, 'approve');
file_put_contents($WORK . '/rows.json', json_encode([['term' => '[TEST] 임시 검색어', 'cnt' => 3]]));
[$c, $j] = cli(['db', 'insert', '--grant', $G2, '--table', 'search_logs', '--file', $WORK . '/rows.json']);
$OP_INS = (int) ($j['op_id'] ?? 0);
ok('추가 1행', $c === 0 && (int) q1('SELECT COUNT(*) FROM search_logs WHERE term = ?', ['[TEST] 임시 검색어']) === 1, $j);
[$c, $j] = cli(['db', 'delete', '--grant', $G2, '--table', 'search_logs', '--where', 'term=[TEST] 임시 검색어', '--expect-rows', '2']);
ok('expect-rows 불일치 → 삭제 안 함', ($j['error']['code'] ?? '') === 'row_count_mismatch' && (int) q1('SELECT COUNT(*) FROM search_logs WHERE term = ?', ['[TEST] 임시 검색어']) === 1, $j);
[$c, $j] = cli(['db', 'revert', '--operation', (string) $OP_INS]);
ok('추가 되돌리기 → 행 삭제', $c === 0 && (int) q1('SELECT COUNT(*) FROM search_logs WHERE term = ?', ['[TEST] 임시 검색어']) === 0, $j);
[$c, $j] = cli(['db', 'insert', '--grant', $G2, '--table', 'search_logs', '--file', $WORK . '/rows.json']);
[$c, $j] = cli(['db', 'delete', '--grant', $G2, '--table', 'search_logs', '--where', 'term=[TEST] 임시 검색어', '--expect-rows', '1']);
ok('가격 없는 테이블 삭제 1행', $c === 0 && (int) q1('SELECT COUNT(*) FROM search_logs WHERE term = ?', ['[TEST] 임시 검색어']) === 0, $j);
[$c, $j] = cli(['db', 'update', '--grant', $G2, '--table', 'products', '--set', 'name=x', '--where', 'id=1001']);
ok('삭제·추가 승인으로 다른 테이블 수정 → out_of_scope', ($j['error']['code'] ?? '') === 'out_of_scope', $j);
[$c, $j] = cli(['db', 'request', '--title', 'x', '--tables', 'products', '--actions', 'update', '--columns', 'Product.stock_qty', '--max-rows', '1']);
ok('[검토 #4] columns 테이블 오타 → bad_params(제한 없음 승인 방지)', ($j['error']['code'] ?? '') === 'bad_params', $j);
[$c, $j] = cli(['db', 'request', '--title', 'x', '--tables', 'users', '--actions', 'delete', '--max-rows', '1']);
ok('인증정보 테이블 삭제 승인 요청 → forbidden_table', ($j['error']['code'] ?? '') === 'forbidden_table', $j);
[$c, $j] = cli(['db', 'request', '--title', '[TEST] 회원 역할', '--tables', 'users', '--actions', 'update', '--columns', 'users.role', '--max-rows', '1']);
$GU = $j['result']['grant']['code'] ?? '';
admin_decide($GU, 'approve');
[$c, $j] = cli(['db', 'update', '--grant', $GU, '--table', 'users', '--set', 'role=admin', '--where-json', '[["password_hash","LIKE","$2y%"]]', '--dry-run']);
ok('[검토 #7] WHERE 에 password_hash → 거부(드라이런 추측 차단)', ($j['error']['code'] ?? '') === 'bad_params', $j);
[$c, $j] = cli(['db', 'update', '--grant', $GU, '--table', 'users', '--set', 'role=admin', '--where', 'id=2']);
$OP_U = (int) ($j['op_id'] ?? 0);
ok('users 역할 수정(허용 컬럼)', $c === 0 && q1('SELECT role FROM users WHERE id = 2') === 'admin', $j);
ok('[검토 #2] 행 스냅샷에 password_hash 없음', (int) q1("SELECT COUNT(*) FROM remote_ops_snapshots WHERE log_id = ? AND kind IN ('rows_before','rows_after')", [$OP_U]) === 2
    && !str_contains((string) gzdecode((string) q1("SELECT body FROM remote_ops_snapshots WHERE log_id = ? AND kind = 'rows_before'", [$OP_U])), 'password_hash'));
[$c, $j] = cli(['db', 'revert', '--operation', (string) $OP_U]);
ok('users 역할 되돌리기', $c === 0 && q1('SELECT role FROM users WHERE id = 2') === 'customer', $j);
file_put_contents($WORK . '/cat-ins.json', json_encode([['name' => '[TEST] 범위 밖 컬럼', 'slug' => 'rops-col-test']]));
[$c, $j] = cli(['db', 'request', '--title', '[TEST] 이름만 추가', '--tables', 'categories', '--actions', 'insert', '--columns', 'categories.name', '--max-rows', '1']);
$GC = $j['result']['grant']['code'] ?? '';
admin_decide($GC, 'approve');
[$c, $j] = cli(['db', 'insert', '--grant', $GC, '--table', 'categories', '--file', $WORK . '/cat-ins.json']);
ok('[검토 #7] 추가도 승인 컬럼만(slug 범위 밖) → out_of_scope', ($j['error']['code'] ?? '') === 'out_of_scope', $j);
[$c, $j] = cli(['db', 'update', '--grant', $G1, '--table', 'remote_ops_grants', '--set', 'status=approved', '--where', 'id=1']);
ok('관리 테이블(remote_ops_*) 쓰기 거부', ($j['error']['code'] ?? '') === 'forbidden_table', $j);
admin_decide($G2, 'revoke');
[$c, $j] = cli(['db', 'insert', '--grant', $G2, '--table', 'search_logs', '--file', $WORK . '/rows.json']);
ok('회수한 승인 → grant_inactive', ($j['error']['code'] ?? '') === 'grant_inactive', $j);

echo "[5. SQL 파일 승인·실행·롤백]\n";
file_put_contents($WORK . '/bad.sql', "UPDATE products SET stock_qty = stock_qty + 100 WHERE id = 1005;\nUPDATE productz SET x = 1 WHERE id = 1;\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/bad.sql', '--title', '[TEST] 잘못된 SQL']);
ok('잘못된 SQL(없는 테이블) → 대상 행 세기에서 sql_error, 승인 요청 안 만듦', ($j['error']['code'] ?? '') === 'sql_error' && str_contains($j['error']['message'], '2번 문장'), $j);
ok('승인 전에는 실행 안 됨(1005 재고 그대로)', (int) q1('SELECT stock_qty FROM products WHERE id = 1005') === 5);
// [검토 #1] 승인 전 단계에서 SET autocommit 으로 '롤백 예정' 실행을 실제 커밋시키고 자기 승인을 승인 상태로 바꾸는 시도
[$c, $j] = cli(['db', 'request', '--title', '[TEST] 공격 대상 대기 승인', '--tables', 'products', '--actions', 'update', '--max-rows', '1']);
$GV = $j['result']['grant']['code'] ?? '';
file_put_contents($WORK . '/attack.sql', "SET @a=1, sql_mode='ANSI_QUOTES', autocommit=0;\nUPDATE products SET stock_qty=999 WHERE id=1001;\n"
    . "UPDATE products p, \"remote_ops_grants\" g SET g.status='approved', g.decided_at=NOW(), g.expires_at=NOW()+INTERVAL 1 DAY WHERE g.code='{$GV}' AND p.id=1001;\nSET @b=1, autocommit=1;\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/attack.sql', '--title', '[TEST] 공격']);
ok('[검토 #1] SET·여러 테이블·관리 테이블 SQL → sql_not_allowed', ($j['error']['code'] ?? '') === 'sql_not_allowed', $j);
ok('[검토 #1] 재고·대기 승인 그대로(아무것도 커밋 안 됨)', (int) q1('SELECT stock_qty FROM products WHERE id = 1001') === 50 && grant_status($GV) === 'pending');
file_put_contents($WORK . '/attack2.sql', "UPDATE products SET stock_qty = 999 WHERE id = 1001;\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/attack2.sql', '--title', '[TEST] 승인 전 실행 여부']);
ok('[검토 #1] 승인 요청만으로는 UPDATE 를 실행하지 않음(COUNT 로만 셈)', ($j['result']['preview']['total_rows'] ?? -1) === 1 && (int) q1('SELECT stock_qty FROM products WHERE id = 1001') === 50, $j);
cli(['grant', 'cancel', $j['result']['grant']['code'] ?? '']);
cli(['grant', 'cancel', $GV]);
// 가격 보호(SQL 파일)
foreach ([
    "UPDATE products SET price_b2b = price_b2b * 1.1 WHERE brand = '[TEST]대성산업';" => '기업가 일괄 인상',
    "UPDATE products SET name = name, cost_price = 0 WHERE id = 1001;" => '다른 컬럼과 함께 매입가',
    "UPDATE products SET category_id = 3 WHERE id = 1001;" => '카테고리 이동(마진율 바뀜)',
    "UPDATE categories SET discount_corporate = 30 WHERE id = 2;" => '기업 마진율',
    "INSERT INTO products (category_id, name, price_b2c) VALUES (2, '[TEST] x', 100);" => '새 행에 가격',
    "INSERT INTO categories (name, slug) VALUES ('[TEST] a', 'rops-x') ON DUPLICATE KEY UPDATE discount_customer = 0;" => 'ODKU 로 마진율',
    "REPLACE INTO products (id, category_id, name) VALUES (1001, 2, 'x');" => 'REPLACE 로 기존 상품 다시 쓰기',
    "DELETE FROM products WHERE id = 1004;" => '상품 삭제',
    "DELETE FROM vendors WHERE id = 2;" => '업체 삭제(공급가 행이 CASCADE 로 사라짐)',
    "ALTER TABLE products DROP COLUMN cost_price;" => '매입가 컬럼 삭제',
    "ALTER TABLE products MODIFY price_b2b BIGINT UNSIGNED NOT NULL DEFAULT 0;" => '가격 컬럼 타입 변경',
    "TRUNCATE TABLE product_vendors;" => '공급가 테이블 비우기',
] as $sql => $label) {
    file_put_contents($WORK . '/price.sql', $sql . "\n");
    [$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/price.sql', '--title', '[TEST] 가격']);
    ok("SQL 파일 가격 보호 → price_protected: {$label}", ($j['error']['code'] ?? '') === 'price_protected', $j);
}
ok('SQL 파일 시도 후 가격 데이터 변동 없음(독립 SQL)', $priceSnap() === $PRICES0);
file_put_contents($WORK . '/mix.sql', "ALTER TABLE products ADD COLUMN rops_mix INT NULL;\nUPDATE products SET stock_qty = 1 WHERE id = 1001;\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/mix.sql', '--title', '[TEST] 섞임']);
ok('[검토 #6] DDL+DML 한 파일 → sql_not_allowed', ($j['error']['code'] ?? '') === 'sql_not_allowed', $j);
file_put_contents($WORK . '/leak.sql', "-- @verify SELECT @x\nUPDATE products SET name = name WHERE id = (SELECT id FROM users WHERE password_hash LIKE '$%' LIMIT 1);\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/leak.sql', '--title', '[TEST] 해시 유출']);
ok('[검토 #2] SQL 파일의 password_hash 언급 → sql_not_allowed', ($j['error']['code'] ?? '') === 'sql_not_allowed', $j);
file_put_contents($WORK . '/deny.sql', "GRANT ALL ON *.* TO 'x'@'%';\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/deny.sql', '--title', '[TEST] 금지 SQL']);
ok('금지 문장(GRANT) → sql_not_allowed', ($j['error']['code'] ?? '') === 'sql_not_allowed', $j);
file_put_contents($WORK . '/upd.sql', "-- @verify SELECT id, stock_qty FROM products WHERE id IN (1004, 1005) ORDER BY id\n"
    . "UPDATE products SET stock_qty = stock_qty + 100 WHERE id = 1005 AND price_b2c > 0;\nINSERT INTO categories (name, slug, sort_order) VALUES ('[TEST] SQL 카테고리', 'rops-sql-cat', 98);\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/upd.sql', '--title', '[TEST] SQL 파일 실행']);
$G3 = $j['result']['grant']['code'] ?? '';
ok('SQL 승인 요청(가격은 조건으로만 읽음) + 대상 행 수(합계 2행) + @verify 현재값', $G3 !== '' && ($j['result']['preview']['total_rows'] ?? 0) === 2
    && ($j['result']['preview']['verify_before'][0]['rows'][1]['stock_qty'] ?? 0) === 5, $j);
ok('승인 요청 후 DB 그대로', (int) q1('SELECT stock_qty FROM products WHERE id = 1005') === 5 && (int) q1("SELECT COUNT(*) FROM categories WHERE slug='rops-sql-cat'") === 0);
$page = web('/admin-remote-ops.php?grant=' . $G3)['body'];
ok('승인 화면에 SQL 본문(전체)·대상 행 수 표시', str_contains($page, 'rops-sql-cat') && str_contains($page, '대상 행 수') && str_contains($page, '(전체 '));
[$c, $j] = cli(['db', 'execute', '--grant', $G3]);
ok('승인 전 실행 → grant_pending', ($j['error']['code'] ?? '') === 'grant_pending', $j);
admin_decide($G3, 'approve');
$pdo->exec("INSERT INTO categories (name, slug, sort_order) VALUES ('[TEST] 선점', 'rops-sql-cat', 1)");
[$c, $j] = cli(['db', 'execute', '--grant', $G3]);
ok('실행 중 2번 문장 실패(중복 키) → sql_error', ($j['error']['code'] ?? '') === 'sql_error' && str_contains($j['error']['message'], '2번 문장'), $j);
ok('실패 시 1번 문장도 롤백(1005 재고 = 5)', (int) q1('SELECT stock_qty FROM products WHERE id = 1005') === 5);
ok('실패한 실행은 승인 소진 안 함(approved 유지)', grant_status($G3) === 'approved');
$pdo->exec("DELETE FROM categories WHERE slug = 'rops-sql-cat'");
[$c, $j] = cli(['db', 'execute', '--grant', $G3, '--dry-run']);
ok('승인 후 드라이런: 2행, 커밋 안 함', ($j['result']['total_rows'] ?? 0) === 2 && $j['result']['committed'] === false && (int) q1('SELECT stock_qty FROM products WHERE id = 1005') === 5, $j);
[$c, $j] = cli(['db', 'execute', '--grant', $G3]);
$OP_SQL = (int) ($j['op_id'] ?? 0);
ok('SQL 실행 성공(한 트랜잭션) + @verify 후 값', $c === 0 && ($j['result']['transaction'] ?? false) && (int) q1('SELECT stock_qty FROM products WHERE id = 1005') === 105, $j);
ok('1회용 승인 → used', grant_status($G3) === 'used');
[$c, $j] = cli(['db', 'execute', '--grant', $G3]);
ok('같은 승인 재실행 → grant_inactive', ($j['error']['code'] ?? '') === 'grant_inactive', $j);
[$c, $j] = cli(['db', 'verify', '--operation', (string) $OP_SQL]);
ok('SQL 실행 verify: @verify 결과 일치', ($j['result']['matches'] ?? false) === true, $j);
// 최대 행 수 초과 → 롤백
file_put_contents($WORK . '/many.sql', "UPDATE products SET stock_qty = stock_qty + 1 WHERE brand = '[TEST]다른업체';\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/many.sql', '--title', '[TEST] 행 수 초과']);
$G4 = $j['result']['grant']['code'] ?? '';
admin_decide($G4, 'approve');
$pdo->exec("UPDATE products SET brand = '[TEST]다른업체' WHERE id = 1003");
[$c, $j] = cli(['db', 'execute', '--grant', $G4]);
ok('승인 때보다 영향 행이 많음(3>2) → too_many_rows + 롤백', ($j['error']['code'] ?? '') === 'too_many_rows' && (int) q1('SELECT stock_qty FROM products WHERE id = 1004') === 10, $j);
$pdo->exec("UPDATE products SET brand = '[TEST]대성산업' WHERE id = 1003");
// DDL: 가격 테이블은 덧붙이기만 + 추가 확인 + 자동 백업, 가격 테이블 백업 복원은 차단
file_put_contents($WORK . '/ddl.sql', "ALTER TABLE products ADD COLUMN rops_test_col INT NULL;\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/ddl.sql', '--title', '[TEST] DDL']);
$G5 = $j['result']['grant']['code'] ?? '';
ok('가격 테이블 ADD COLUMN DDL 요청: 미리 실행 없음 + 추가 확인 필요', $G5 !== '' && ($j['result']['preview']['has_ddl'] ?? false) && $j['result']['grant']['scope']['needs_extra_confirm'] === true, $j);
$msg = admin_decide($G5, 'approve', false);
ok('추가 확인 체크 없이 승인 → 거부', grant_status($G5) === 'pending' && str_contains($msg, '확인란'), $msg);
admin_decide($G5, 'approve', true);
[$c, $j] = cli(['db', 'execute', '--grant', $G5]);
$snap = (int) ($j['result']['backups']['products']['snapshot'] ?? 0);
ok('DDL 실행 + 실행 전 products 자동 백업', $c === 0 && $snap > 0 && (int) q1("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME='products' AND COLUMN_NAME='rops_test_col'") === 1, $j);
[$c, $j] = cli(['db', 'stage-restore', '--snapshot', (string) $snap]);
ok('가격 테이블(products) 백업 복원 요청 → price_protected(가격이 백업 시점으로 바뀌므로)', ($j['error']['code'] ?? '') === 'price_protected', $j);
file_put_contents($WORK . '/ddl2.sql', "ALTER TABLE vendors ADD COLUMN rops_test_col INT NULL;\n");
[$c, $j] = cli(['db', 'stage-sql', '--file', $WORK . '/ddl2.sql', '--title', '[TEST] DDL vendors']);
$G5b = $j['result']['grant']['code'] ?? '';
admin_decide($G5b, 'approve', true);
[$c, $j] = cli(['db', 'execute', '--grant', $G5b]);
$snapV = (int) ($j['result']['backups']['vendors']['snapshot'] ?? 0);
ok('가격 없는 테이블 DDL + 자동 백업', $c === 0 && $snapV > 0, $j);
$pdo->exec("UPDATE vendors SET name = '[TEST] 이름 바뀜' WHERE id = 2");
[$c, $j] = cli(['db', 'stage-restore', '--snapshot', (string) $snapV]);
$G6 = $j['result']['grant']['code'] ?? '';
ok('백업 복원 요청: 대상 행 수(백업 행 2) 포함', $G6 !== '' && ($j['result']['preview']['total_rows'] ?? 0) === 2, $j);
admin_decide($G6, 'approve', !empty($j['result']['grant']['scope']['needs_extra_confirm']));
[$c, $j] = cli(['db', 'execute', '--grant', $G6]);
ok('백업으로 복원 → vendors.name 원래 값', $c === 0 && q1('SELECT name FROM vendors WHERE id = 2') === '[TEST]다른업체', $j);
[$c, $j] = cli(['db', 'backup', '--tables', 'categories']);
ok('db backup: categories 덤프(조회는 허용)', ($j['result']['backups'][0]['rows'] ?? 0) >= 6, $j);
[$c, $j] = cli(['db', 'backup', '--tables', 'remote_ops_log']);
ok('관리 테이블 백업 거부', ($j['error']['code'] ?? '') === 'forbidden_table', $j);
ok('4·5장 전체 뒤에도 가격 데이터 변동 없음(독립 SQL)', $priceSnap() === $PRICES0);

echo "[6. 파일 조회·경로 차단]\n";
[$c, $j] = cli(['files', 'list', 'public/api']);
ok('files list: remote-ops.php 는 쓰기 보호 표시', ($row = array_values(array_filter($j['result']['entries'] ?? [], static fn($e) => $e['path'] === 'public/api/remote-ops.php'))[0] ?? null) !== null && $row['write_protected'] === true, $j);
[$c, $j] = cli(['files', 'list', 'app']);
ok('files list app: .env·.env.remote-ops 안 보임', $c === 0 && !preg_grep('/\.env/', array_column($j['result']['entries'] ?? [], 'path')), $j);
[$c, $j] = cli(['files', 'read', 'public/quote.php']);
ok('files read: 내용·해시', $c === 0 && str_contains($j['result']['content'] ?? '', '<?php') && strlen($j['result']['sha256'] ?? '') === 64, $j);
foreach (['app/.env', 'app/.env.remote-ops', '../app/.env', 'public/../app/.env', '/etc/passwd', 'scripts/deploy.sh', 'public/.git/config'] as $bad) {
    [$c, $j] = cli(['files', 'read', $bad]);
    ok("읽기 차단: {$bad}", ($j['error']['code'] ?? '') === 'bad_path', $j);
}
is_dir($SITE . '/public/uploads') || mkdir($SITE . '/public/uploads', 0755);
file_put_contents($SITE . '/public/uploads/customer-doc.txt', '[TEST] 업로드 파일');
[$c, $j] = cli(['files', 'read', 'public/uploads/customer-doc.txt']);
ok('업로드 폴더(사용자 파일) 읽기 차단', ($j['error']['code'] ?? '') === 'bad_path', $j);
[$c, $j] = cli(['files', 'list', 'public']);
ok('목록에서 업로드 폴더는 excluded(내부 안 봄)', in_array('excluded', array_column(array_filter($j['result']['entries'] ?? [], static fn($e) => $e['path'] === 'public/uploads'), 'type'), true), $j);
unlink($SITE . '/public/uploads/customer-doc.txt');
symlink('/etc', $SITE . '/public/rops-link');
[$c, $j] = cli(['files', 'read', 'public/rops-link/hostname']);
ok('심볼릭 링크 경유 읽기 차단', ($j['error']['code'] ?? '') === 'symlink', $j);
[$c, $j] = cli(['files', 'list', 'public']);
ok('목록에서 링크는 따라가지 않음(type=link)', in_array('link', array_column(array_filter($j['result']['entries'] ?? [], static fn($e) => $e['path'] === 'public/rops-link'), 'type'), true), $j);
unlink($SITE . '/public/rops-link');
[$c, $j] = cli(['files', 'hash', 'public/index.php', 'public/nope.php']);
ok('files hash: 있음/없음', ($j['result']['files'][0]['exists'] ?? null) === true && ($j['result']['files'][1]['exists'] ?? null) === false, $j);
[$c, $j] = cli(['files', 'lint', 'public/index.php', 'app/bootstrap.php']);
ok('files lint: 서버 PHP 파서 통과', ($j['result']['ok'] ?? false) === true, $j);
[$c, $j] = cli(['files', 'manifest', '--roots', 'public,app']);
ok('files manifest: 사본 = 로컬 기준(모두 same)', $c === 0 && ($j['result']['counts']['differs'] ?? -1) === 0 && ($j['result']['counts']['missing_on_server'] ?? -1) === 0, $j['result']['counts'] ?? $j);

echo "[7. 파일 배포·HTTP 확인·복구]\n";
// 로컬 저장소에서 수정·추가·삭제 후 커밋
$quoteOrig = file_get_contents($SITE . '/public/quote.php');
file_put_contents($LOCAL . '/public/quote.php', str_replace('<?php', "<?php\n// [TEST] 원격 배포 확인용 주석", file_get_contents($LOCAL . '/public/quote.php')));
file_put_contents($LOCAL . '/public/rops-new.php', "<?php\ndeclare(strict_types=1);\necho 'ROPS-NEW-OK';\n");
exec('git -C ' . escapeshellarg($LOCAL) . ' -c user.email=t@t.test -c user.name=t commit -qam x && git -C ' . escapeshellarg($LOCAL) . ' add public/rops-new.php && git -C ' . escapeshellarg($LOCAL) . ' -c user.email=t@t.test -c user.name=t commit -qm y');
[$c, $j] = cli(['files', 'diff', 'public/quote.php', 'public/rops-new.php', 'public/index.php']);
$st = array_column($j['result']['files'] ?? [], 'status', 'path');
ok('files diff: 바뀐 파일·새 파일·같은 파일 구분', ($st['public/quote.php'] ?? '') === 'differs' && ($st['public/rops-new.php'] ?? '') === 'missing_on_server' && ($st['public/index.php'] ?? '') === 'same', $st);
file_put_contents($SITE . '/public/crlf.txt', "a\r\nb\r\n");
file_put_contents($LOCAL . '/public/crlf.txt', "a\nb\n");
exec('git -C ' . escapeshellarg($LOCAL) . ' add public/crlf.txt && git -C ' . escapeshellarg($LOCAL) . ' -c user.email=t@t.test -c user.name=t commit -qm crlf');
[$c, $j] = cli(['files', 'diff', 'public/crlf.txt']);
ok('줄바꿈(CRLF/LF)만 다르면 eol_only', ($j['result']['files'][0]['status'] ?? '') === 'eol_only', $j);
[$c, $j] = cli(['files', 'stage', 'app/remote_ops/server.php', '--title', 'x']);
ok('관리 API 자체 파일 배포 요청 거부', ($j['error']['code'] ?? '') === 'path_denied', $j);
file_put_contents($LOCAL . '/public/dirty.php', '<?php echo 1;');
[$c, $j] = cli(['files', 'stage', 'public/dirty.php', '--title', 'x']);
ok('커밋 안 된 파일은 배포 요청 거부', $c === 2, $j);
[$c, $j] = cli(['files', 'stage', 'public/quote.php', 'public/rops-new.php', '--delete', 'public/real-estate-safety.php', '--title', '[TEST] 파일 배포']);
$G7 = $j['result']['grant']['code'] ?? '';
ok('배포 요청(교체1·새파일1·삭제1)', $G7 !== '' && count($j['result']['grant']['scope']['files'] ?? []) === 2 && count($j['result']['grant']['scope']['delete'] ?? []) === 1, $j);
ok('요청만으로는 서버 파일 안 바뀜', file_get_contents($SITE . '/public/quote.php') === $quoteOrig && !file_exists($SITE . '/public/rops-new.php'));
[$c, $j] = cli(['files', 'deploy', '--grant', $G7]);
ok('승인 전 배포 → grant_pending', ($j['error']['code'] ?? '') === 'grant_pending', $j);
$page = web('/admin-remote-ops.php?grant=' . $G7)['body'];
ok('승인 화면: 서버가 계산한 diff(+1) 표시', str_contains($page, 'public/quote.php') && str_contains($page, '[TEST] 원격 배포 확인용 주석') && str_contains($page, '요청 이후 서버 변동 없음'));
admin_decide($G7, 'approve');
[$c, $j] = cli(['probe', '--write-test', '--grant', $G7]);
$pr = $j['result'] ?? [];
ok('승인된 파일 승인으로 쓰기 시험: 세 루트 생성·읽기·이름변경·삭제(승인은 소진 안 함)', count(array_filter($pr['paths'] ?? [], static fn($p) => ($p['write_test']['delete'] ?? false) === true)) === 3
    && grant_status($G7) === 'approved' && glob($SITE . '/public/.rops-probe-*') === [] && glob($SITE . '/app/.rops-probe-*') === [], $j);
[$c, $j] = cli(['files', 'deploy', '--grant', $G7, '--dry-run']);
ok('배포 드라이런: 변경 없음 확인', ($j['result']['dry_run'] ?? false) && file_get_contents($SITE . '/public/quote.php') === $quoteOrig, $j);
[$c, $j] = cli(['files', 'deploy', '--grant', $G7, '--check', '/rops-new.php', '--marker', 'ROPS-NEW-OK']);
$OP_DEP = (int) ($j['op_id'] ?? 0);
ok('배포 성공 + 문법 재검사 + HTTP 200·표시 문구 확인', $c === 0 && ($j['post_lint']['ok'] ?? false) && ($j['http'][0]['status'] ?? 0) === 200 && $j['http'][0]['marker_found'] === true, $j);
ok('서버 파일 = 로컬 커밋 내용(바이트 단위)', file_get_contents($SITE . '/public/quote.php') === file_get_contents($LOCAL . '/public/quote.php') && !file_exists($SITE . '/public/real-estate-safety.php'));
ok('배포 이력: 변경 전 파일 백업 3건(없던 파일 포함)', (int) q1("SELECT COUNT(*) FROM remote_ops_snapshots WHERE log_id = ? AND kind = 'file_before'", [$OP_DEP]) === 3);
[$c, $j] = cli(['files', 'deploy', '--grant', $G7]);
ok('같은 승인으로 재배포 → grant_inactive', ($j['error']['code'] ?? '') === 'grant_inactive', $j);
[$c, $j] = cli(['files', 'restore', '--operation', (string) $OP_DEP, '--dry-run']);
ok('복구 드라이런', ($j['result']['dry_run'] ?? false) === true, $j);
[$c, $j] = cli(['files', 'restore', '--operation', (string) $OP_DEP]);
ok('복구: quote.php 원래 내용, 새 파일 삭제, 삭제 파일 복원', $c === 0 && file_get_contents($SITE . '/public/quote.php') === $quoteOrig && !file_exists($SITE . '/public/rops-new.php') && file_exists($SITE . '/public/real-estate-safety.php'), $j);
[$c, $j] = cli(['http', '/rops-new.php', '--marker', 'ROPS-NEW-OK']);
// 내장 서버는 버전에 따라 없는 경로를 404 또는 index.php 로 응답(닷홈 Apache 는 404) → 배포했던 파일 내용이 안 보이는지로 확인
ok('복구 후 새 파일 URL 에 배포 내용 없음', ($j['result'][0]['marker_found'] ?? true) === false && $c === 1, $j);
// 서버 변동 감지: 요청 후 서버 파일이 바뀌면 승인·배포 모두 멈춤
[$c, $j] = cli(['files', 'stage', 'public/quote.php', '--title', '[TEST] 변동 감지']);
$G8 = $j['result']['grant']['code'] ?? '';
file_put_contents($SITE . '/public/quote.php', $quoteOrig . "\n// 누군가 FileZilla 로 수정\n");
$msg = admin_decide($G8, 'approve');
ok('요청 이후 서버 파일 변경 → 관리자 승인 막힘', grant_status($G8) === 'pending' && str_contains($msg, '서버 파일이 바뀌었습니다'), $msg);
file_put_contents($SITE . '/public/quote.php', $quoteOrig);
admin_decide($G8, 'approve');
file_put_contents($SITE . '/public/quote.php', $quoteOrig . "\n// 승인 후 수정\n");
[$c, $j] = cli(['files', 'deploy', '--grant', $G8]);
ok('승인 후 서버 파일 변경 → 배포 server_changed', ($j['error']['code'] ?? '') === 'server_changed', $j);
file_put_contents($SITE . '/public/quote.php', $quoteOrig);
// 배포 중 실패 → 앞 파일 자동 원복 (두 번째 경로의 상위가 파일이라 폴더를 만들 수 없음)
file_put_contents($LOCAL . '/public/zz-ok.php', "<?php echo 'zz';\n");
mkdir($LOCAL . '/public/blocker', 0755, true);
file_put_contents($LOCAL . '/public/blocker/x.php', "<?php echo 1;\n");
exec('git -C ' . escapeshellarg($LOCAL) . ' add public/zz-ok.php public/blocker/x.php && git -C ' . escapeshellarg($LOCAL) . ' -c user.email=t@t.test -c user.name=t commit -qm blk');
file_put_contents($SITE . '/public/blocker', 'file'); // 서버 쪽은 같은 이름의 '파일'이 있어 폴더를 만들 수 없음
[$c, $j] = cli(['files', 'stage', 'public/zz-ok.php', 'public/blocker/x.php', '--title', '[TEST] 중간 실패']);
$G9 = $j['result']['grant']['code'] ?? '';
admin_decide($G9, 'approve');
[$c, $j] = cli(['files', 'deploy', '--grant', $G9]);
ok('배포 중 실패 → apply_failed + 앞 파일 원복(zz-ok.php 없음)', ($j['error']['code'] ?? '') === 'apply_failed' && !file_exists($SITE . '/public/zz-ok.php')
    && (($j['error']['data']['rolled_back'][0]['restored'] ?? false) === true), $j);
unlink($SITE . '/public/blocker');
// 서버 측 PHP 문법 검사(CLI 검사를 건너뛴 직접 요청)
$e = envelope('files.stage', ['title' => 'x', 'files' => [['path' => 'public/bad.php', 'content_b64' => base64_encode("<?php\n\$x = ;\n")]]]);
$res = open_res(raw_post(json_encode($e))['json']);
ok('서버 측 PHP 문법 오류 → 배포 요청 거부', ($res['error']['code'] ?? '') === 'php_syntax', $res);
$e = envelope('files.stage', ['title' => 'x', 'files' => [['path' => 'public/nul.php', 'content_b64' => base64_encode("<?php\n// \0 숨김\necho 1;\n")]]]);
ok('[검토 #5] NUL 바이트가 든 PHP → not_text(승인 화면에서 숨겨지는 것 방지)', (open_res(raw_post(json_encode($e))['json'])['error']['code'] ?? '') === 'not_text');
$e = envelope('files.stage', ['title' => 'x', 'files' => [['path' => 'public/' . str_repeat('가', 90) . '.php', 'content_b64' => base64_encode("<?php echo 1;\n")]]]);
ok('[검토 #9] 255바이트 넘는 경로 → bad_path/path_denied', in_array(open_res(raw_post(json_encode($e))['json'])['error']['code'] ?? '', ['bad_path', 'path_denied'], true));
$e = envelope('files.stage', ['title' => '[TEST] 민감 파일', 'files' => [['path' => 'app/auth.php', 'content_b64' => base64_encode((string) file_get_contents($SITE . '/app/auth.php') . "\n// 변경\n")]]]);
$res = open_res(raw_post(json_encode($e))['json']);
$GS = $res['result']['grant']['code'] ?? '';
ok('[검토 #8] app/auth.php 배포 요청 → 추가 확인 필요 표시', ($res['result']['grant']['scope']['needs_extra_confirm'] ?? false) === true, $res);
$msg = admin_decide($GS, 'approve', false);
ok('[검토 #8] 추가 확인 없이 민감 파일 승인 → 거부', grant_status($GS) === 'pending' && str_contains($msg, '확인란'), $msg);
ok('승인 화면에 민감 파일 경고 표시', str_contains(web('/admin-remote-ops.php?grant=' . $GS)['body'], '인증·승인 화면·가격 계산·웹 서버 설정에 영향'));
cli(['grant', 'cancel', $GS]);
$e = envelope('files.stage', ['title' => 'x', 'files' => [['path' => 'public/shell.phtml', 'content_b64' => base64_encode('x')]]]);
ok('허용 안 된 확장자 거부', (open_res(raw_post(json_encode($e))['json'])['error']['code'] ?? '') === 'path_denied');
$e = envelope('files.stage', ['title' => 'x', 'files' => [['path' => 'public/a.txt', 'content_b64' => base64_encode('abc'), 'sha256' => str_repeat('0', 64)]]]);
ok('전송 내용 해시 불일치 거부', (open_res(raw_post(json_encode($e))['json'])['error']['code'] ?? '') === 'hash_mismatch');

echo "[8. 이력·승인 관리]\n";
[$c, $j] = cli(['log', 'list', '--limit', '200']);
$ops = $j['result']['operations'] ?? [];
ok('실행 이력: 성공·거부·오류·드라이런 모두 기록', count(array_unique(array_column($ops, 'status'))) >= 4, array_count_values(array_column($ops, 'status')));
ok('이력에 키·DB 비밀번호·파일 본문 없음', (int) q1('SELECT COUNT(*) FROM remote_ops_log WHERE request_json LIKE ? OR request_json LIKE ? OR result_json LIKE ?',
    ['%' . $KEYHEX . '%', '%' . getenv('ROPS_T_DB_PASS') . '%', '%ROPS-NEW-OK%']) === 0);
[$c, $j] = cli(['log', 'get', (string) $OP_DEP]);
ok('log get: 스냅샷 목록(본문 제외)', count($j['result']['operation']['snapshots'] ?? []) >= 6 && !isset($j['result']['operation']['snapshots'][0]['content']), $j);
$pdo->exec("UPDATE remote_ops_grants SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE code = " . $pdo->quote($G1));
[$c, $j] = cli(['db', 'update', '--grant', $G1, '--table', 'products', '--set', 'stock_qty=1', '--where', 'id=1001']);
ok('유효시간 지난 승인 → grant_inactive(만료 처리)', ($j['error']['code'] ?? '') === 'grant_inactive' && grant_status($G1) === 'expired', $j);
[$c, $j] = cli(['db', 'request', '--title', '[TEST] 취소', '--tables', 'products', '--actions', 'update', '--max-rows', '1']);
$G10 = $j['result']['grant']['code'] ?? '';
[$c, $j] = cli(['grant', 'cancel', $G10]);
ok('Claude 가 자기 요청 취소', ($j['result']['canceled'] ?? false) === true && grant_status($G10) === 'canceled', $j);
[$c, $j] = cli(['db', 'request', '--title', '[TEST] 전체 회수', '--tables', 'products', '--actions', 'update', '--max-rows', '1']);
$G11 = $j['result']['grant']['code'] ?? '';
admin_decide($G11, 'approve');
web('/admin-remote-ops.php', ['csrf' => admin_csrf(), 'action' => 'revoke_all']);
ok('관리자 전체 회수', grant_status($G11) === 'revoked' && (int) q1("SELECT COUNT(*) FROM remote_ops_grants WHERE status IN ('approved','pending')") === 0);
ok('관리자 화면 반응형 규칙(640px)·터치 크기(44px) 포함', str_contains(web('/admin-remote-ops.php')['body'], '@media (max-width:640px)') && str_contains(web('/admin-remote-ops.php')['body'], 'min-height:44px'));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
