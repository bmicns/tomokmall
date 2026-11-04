<?php
declare(strict_types=1);
/**
 * tomok — Claude 원격 관리 CLI (scripts/remote-ops/tomok.php, 실행: scripts/tomok …)
 *
 * 책임: 운영 서버 원격 관리 API(/api/remote-ops.php)를 HTTPS 로 호출하고 결과를 JSON 으로 출력(Claude 가 읽고 다음 작업 진행)
 *       요청은 HMAC-SHA256 서명 + AES-256-GCM 암호화, 응답은 서명·nonce 확인 후 복호화(가짜 응답 차단)
 * 키: 환경변수 TOMOK_OPS_KEY(64자 16진수) → 없으면 TOMOK_OPS_KEY_FILE → 없으면 .env.local 의 TOMOK_OPS_KEY=
 *     키 ID TOMOK_OPS_KEY_ID(기본 k1), 주소 TOMOK_OPS_URL(기본 https://tomokmall.dothome.co.kr/api/remote-ops.php)
 * 금지: 키 출력·파일 기록(keygen 의 --out 파일 제외), http:// 운영 주소 사용(로컬 127.0.0.1·localhost 만 허용)
 * 종료 코드: 0 성공, 1 작업 오류(서버가 거부·실패), 2 사용법 오류, 3 연결·인증 오류
 *
 * 사용법: scripts/tomok help
 */

require __DIR__ . '/../../app/remote_ops/protocol.php';
require __DIR__ . '/../../app/remote_ops/diff.php';

const TOMOK_DEFAULT_URL = 'https://tomokmall.dothome.co.kr/api/remote-ops.php';
// 로컬 저장소 위치(diff·stage 의 비교 기준). 통합 테스트는 TOMOK_LOCAL_ROOT 로 임시 저장소를 가리킨다
define('TOMOK_ROOT', getenv('TOMOK_LOCAL_ROOT') ?: dirname(__DIR__, 2));

final class TomokExit extends \RuntimeException
{
    public function __construct(public readonly int $exitCode, string $message, public readonly array $data = [])
    {
        parent::__construct($message);
    }
}

function tomok_out(array $data): void
{
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE), "\n";
}

function tomok_usage(): string
{
    return <<<TXT
tomok — Claude 원격 관리 CLI (결과는 JSON)

  진단      tomok probe [--write-test --grant G(승인된 파일 변경 승인)]
  DB 조회   tomok db tables [--exact]
            tomok db describe <테이블>
            tomok db query (--sql "SELECT …" | --file q.sql) [--param 값]… [--limit N] [--reveal-pii]
            tomok db search products|categories <검색어…> [--limit N] [--include-inactive]
  DB 승인   tomok db request --title T --tables products --actions update [--columns products.price_b2b,…]
                  [--ids products:1,2,3] --max-rows N [--max-rows-per-op N] [--ttl 분] [--max-ops N] [--detail D]
                  [--allow-bulk-delete] [--allow-cascade-delete]
            tomok db stage-sql --file x.sql --title T [--max-rows N] [--ttl 분] [--detail D]
            tomok db stage-restore --snapshot ID [--title T]
  DB 쓰기   tomok db update --grant G --table T --set col=값… (--where col=값… | --where-json '[["col","LIKE","%x%"]]')
                  [--set-json '{"col":값}'] [--expect-rows N] [--dry-run]
            tomok db batch  --grant G --file changes.json [--dry-run]   (파일: {"table":"t","rows":[{"key":{"id":1},"set":{…},"expect":{…}}]})
            tomok db insert --grant G --table T --file rows.json [--dry-run]   (파일: [{"col":값},…])
            tomok db delete --grant G --table T (--where … | --where-json …) [--expect-rows N] [--dry-run]
            tomok db execute --grant G [--dry-run]
            tomok db verify --operation ID
            tomok db revert --operation ID [--dry-run] [--force]
            tomok db backup --tables t1,t2
  파일 조회 tomok files list [경로] [--recursive] [--hashes]
            tomok files read <경로> [--out 파일] [--text]
            tomok files hash <경로…>
            tomok files diff <경로…> [--ref HEAD|worktree]
            tomok files manifest [--roots public,app,database] [--ref HEAD] [--all]
            tomok files lint <경로…>
            tomok files snapshot <ID> [--out 파일]
  파일 배포 tomok files stage <경로…> --title T [--delete 경로,…] [--ref HEAD|worktree] [--ttl 분] [--detail D]
            tomok files deploy --grant G [--dry-run] [--check /경로]…
            tomok files restore --operation ID [--dry-run] [--force]
  승인      tomok grant status <코드> | grant wait <코드> [--timeout 초] | grant list [--status S] | grant cancel <코드>
  이력      tomok log list [--limit N] [--op 접두] [--grant-id N] | log get <ID>
  HTTP 확인 tomok http <경로…> [--expect 200] [--marker 문구]
  설치      tomok keygen --out <파일>   (키를 화면에 출력하지 않고 파일에만 씀)

  환경변수: TOMOK_OPS_KEY(또는 TOMOK_OPS_KEY_FILE), TOMOK_OPS_KEY_ID, TOMOK_OPS_URL
TXT;
}

/** 인자 파싱: 위치 인자 + --옵션(값 있음/없음). 같은 옵션 여러 번 → 배열 */
function tomok_args(array $argv, array $flags): array
{
    $pos = [];
    $opt = [];
    for ($i = 0; $i < count($argv); $i++) {
        $a = $argv[$i];
        if (str_starts_with($a, '--')) {
            $name = substr($a, 2);
            $val = true;
            if (str_contains($name, '=')) {
                [$name, $val] = explode('=', $name, 2);
            } elseif (!in_array($name, $flags, true)) {
                if (!isset($argv[$i + 1])) {
                    throw new TomokExit(2, "--{$name} 값이 필요합니다");
                }
                $val = $argv[++$i];
            }
            $opt[$name][] = $val;
        } else {
            $pos[] = $a;
        }
    }
    return [$pos, $opt];
}

function o(array $opt, string $k, mixed $def = null): mixed
{
    return isset($opt[$k]) ? end($opt[$k]) : $def;
}

function oa(array $opt, string $k): array
{
    $out = [];
    foreach ($opt[$k] ?? [] as $v) {
        foreach (explode(',', (string) $v) as $x) {
            if (trim($x) !== '') { $out[] = trim($x); }
        }
    }
    return $out;
}

function need(array $opt, string $k): string
{
    $v = o($opt, $k);
    if ($v === null || $v === true || $v === '') {
        throw new TomokExit(2, "--{$k} 가 필요합니다");
    }
    return (string) $v;
}

/** col=값 → [col, 값] (null/true/false/숫자 자동 변환, 따옴표로 감싸면 문자열 그대로) */
function tomok_kv(string $s): array
{
    $eq = strpos($s, '=');
    if ($eq === false || $eq === 0) {
        throw new TomokExit(2, "컬럼=값 형식이 아닙니다: {$s}");
    }
    $k = substr($s, 0, $eq);
    $v = substr($s, $eq + 1);
    if (preg_match('/^"(.*)"$/s', $v, $m) || preg_match("/^'(.*)'$/s", $v, $m)) {
        return [$k, $m[1]];
    }
    if ($v === 'null' || $v === 'NULL') { return [$k, null]; }
    if (preg_match('/^-?(0|[1-9]\d{0,17})$/', $v)) { return [$k, (int) $v]; }
    if (preg_match('/^-?\d+\.\d+$/', $v)) { return [$k, (float) $v]; }
    return [$k, $v];
}

function tomok_read_file(string $path): string
{
    if (!is_file($path) || !is_readable($path)) {
        throw new TomokExit(2, "파일을 읽을 수 없습니다: {$path}");
    }
    return (string) file_get_contents($path);
}

function tomok_json_file(string $path): array
{
    $d = json_decode(tomok_read_file($path), true);
    if (!is_array($d)) {
        throw new TomokExit(2, "JSON 파일 형식 오류: {$path}");
    }
    return $d;
}

// ─────────────────────────────── 키·전송 ───────────────────────────────

function tomok_key(): array
{
    $hex = getenv('TOMOK_OPS_KEY') ?: '';
    if ($hex === '' && ($f = getenv('TOMOK_OPS_KEY_FILE'))) {
        $hex = trim(tomok_read_file($f));
        if (str_contains($hex, '=')) {
            foreach (preg_split('/\R/', $hex) as $l) { if (str_starts_with(trim($l), 'REMOTE_OPS_KEY=') || str_starts_with(trim($l), 'TOMOK_OPS_KEY=')) { $hex = trim(explode('=', $l, 2)[1], " \"'"); } }
        }
    }
    if ($hex === '' && is_file(TOMOK_ROOT . '/.env.local')) {
        foreach (file(TOMOK_ROOT . '/.env.local', FILE_IGNORE_NEW_LINES) ?: [] as $l) {
            if (str_starts_with($l, 'TOMOK_OPS_KEY=')) { $hex = trim(substr($l, 14), " \"'"); }
        }
    }
    if ($hex === '') {
        throw new TomokExit(3, '키가 없습니다. Claude Code 환경 설정(환경변수)에 TOMOK_OPS_KEY 를 추가한 뒤 새 세션에서 실행하세요(채팅에 붙여넣지 마세요).');
    }
    try {
        $key = rops_key_bytes($hex);
    } catch (RopsProtoError $e) {
        throw new TomokExit(3, 'TOMOK_OPS_KEY 형식 오류(64자 이상 16진수)');
    }
    return [$key, getenv('TOMOK_OPS_KEY_ID') ?: 'k1'];
}

function tomok_url(array $opt): string
{
    $u = (string) (o($opt, 'url') ?? (getenv('TOMOK_OPS_URL') ?: TOMOK_DEFAULT_URL));
    $p = parse_url($u);
    if (!$p || !isset($p['scheme'], $p['host'])) {
        throw new TomokExit(2, "주소 형식 오류: {$u}");
    }
    if ($p['scheme'] !== 'https' && !in_array($p['host'], ['127.0.0.1', 'localhost'], true)) {
        throw new TomokExit(2, 'http:// 는 로컬(127.0.0.1·localhost) 테스트에서만 허용합니다. 운영은 https:// 를 쓰세요.');
    }
    return $u;
}

function tomok_curl(string $url, ?string $body, int $timeout = 120): array
{
    $ch = curl_init($url);
    $hdr = ['Accept: application/json', 'User-Agent: tomok-ops-cli/1.0'];
    if ($body !== null) {
        $hdr[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $hdr, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_TIMEOUT => $timeout,
        CURLOPT_FOLLOWLOCATION => false, CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2]);
    foreach (['SSL_CERT_FILE', 'CURL_CA_BUNDLE', 'REQUESTS_CA_BUNDLE'] as $ev) {
        $ca = getenv($ev);
        if ($ca && is_file($ca)) { curl_setopt($ch, CURLOPT_CAINFO, $ca); break; }
    }
    $t0 = microtime(true);
    $resp = curl_exec($ch);
    $info = curl_getinfo($ch);
    $err = curl_error($ch);
    curl_close($ch);
    return ['body' => $resp === false ? null : (string) $resp, 'status' => (int) ($info['http_code'] ?? 0), 'error' => $err,
        'ms' => (int) round((microtime(true) - $t0) * 1000), 'type' => (string) ($info['content_type'] ?? '')];
}

/** API 호출 → 검증된 응답 평문 {ok, result|error, op_id} */
function tomok_call(array $opt, string $op, array $params): array
{
    [$key, $kid] = tomok_key();
    $url = tomok_url($opt);
    $enc = o($opt, 'plain') === true && $op === 'probe' ? ROPS_ENC_NONE : ROPS_ENC_GCM;
    $nonce = rops_new_nonce();
    $env = rops_seal($key, 'req', $kid, time(), $nonce, $op, ['params' => $params], $enc);
    $r = tomok_curl($url, json_encode($env, JSON_UNESCAPED_SLASHES));
    if ($r['body'] === null) {
        throw new TomokExit(3, "연결 실패: {$r['error']}", ['url' => $url]);
    }
    $d = json_decode($r['body'], true);
    if (!is_array($d)) {
        $snip = trim(preg_replace('/\s+/', ' ', strip_tags(substr($r['body'], 0, 600))));
        throw new TomokExit(3, "JSON 이 아닌 응답(HTTP {$r['status']})", ['body_start' => $snip, 'hint' => $r['status'] === 404 ? '관리 API 파일이 서버에 없습니다(설치 전?)' : null]);
    }
    if (($d['error'] ?? null) === 'auth_failed') {
        $hint = ['disabled' => '서버 /app/.env.remote-ops 에 REMOTE_OPS_ENABLED=1·REMOTE_OPS_KEY 가 없음', 'bad_signature' => '키가 서버와 다름',
            'unknown_key' => 'TOMOK_OPS_KEY_ID 가 서버 REMOTE_OPS_KEY_ID 와 다름', 'expired' => '시계 차이가 큼(server_time 확인)',
            'decrypt_failed' => '암호키 불일치'][$d['reason'] ?? ''] ?? null;
        $extra = ['reason' => $d['reason'] ?? null, 'hint' => $hint];
        if (isset($d['server_time'])) { $extra['clock_skew_sec'] = time() - (int) $d['server_time']; }
        throw new TomokExit(3, '인증 실패: ' . ($d['reason'] ?? '?'), $extra);
    }
    try {
        $plain = rops_open($key, $d, 'res');
    } catch (RopsProtoError $e) {
        throw new TomokExit(3, '응답 검증 실패(' . $e->getMessage() . ') — 응답이 위조·변조되었을 수 있습니다');
    }
    if ($d['nonce'] !== $nonce || $d['op'] !== $op) {
        throw new TomokExit(3, '응답 nonce/작업이 요청과 다릅니다(재사용된 응답 의심)');
    }
    $plain['_http_ms'] = $r['ms'];
    return $plain;
}

/** 호출 + 출력 + 종료코드 */
function tomok_run(array $opt, string $op, array $params, ?callable $post = null): int
{
    $res = tomok_call($opt, $op, $params);
    $out = ['ok' => (bool) ($res['ok'] ?? false), 'op' => $op, 'op_id' => $res['op_id'] ?? null];
    if ($out['ok']) {
        $out['result'] = $post ? $post($res['result']) : $res['result'];
    } else {
        $out['error'] = $res['error'] ?? null;
    }
    tomok_out($out);
    return $out['ok'] ? 0 : 1;
}

// ─────────────────────────────── 로컬 git ───────────────────────────────

function tomok_git(array $args, ?int &$code = null): string
{
    $cmd = 'git -C ' . escapeshellarg(TOMOK_ROOT) . ' ' . implode(' ', array_map('escapeshellarg', $args));
    $p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $out = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $code = proc_close($p);
    return (string) $out;
}

/** 로컬 내용: ref=worktree 면 작업 폴더 파일, 아니면 git 커밋 내용 */
function tomok_local_content(string $path, string $ref): ?string
{
    if ($ref === 'worktree') {
        $f = TOMOK_ROOT . '/' . $path;
        return is_file($f) ? (string) file_get_contents($f) : null;
    }
    $c = 0;
    $out = tomok_git(['show', "{$ref}:{$path}"], $c);
    return $c === 0 ? $out : null;
}

function tomok_php_lint_local(string $content): ?string
{
    $tmp = tempnam(sys_get_temp_dir(), 'tomok');
    try {
        file_put_contents($tmp, $content);
        $out = [];
        $code = 0;
        exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $code);
    } finally {
        unlink($tmp);
    }
    return $code === 0 ? null : trim(str_replace($tmp, '(파일)', implode("\n", $out)));
}

// ─────────────────────────────── 명령 ───────────────────────────────

function tomok_main(array $argv): int
{
    $flags = ['dry-run', 'force', 'reveal-pii', 'recursive', 'hashes', 'exact', 'write-test', 'include-inactive', 'allow-bulk-delete',
        'allow-cascade-delete', 'text', 'all', 'plain', 'help'];
    [$pos, $opt] = tomok_args(array_slice($argv, 1), $flags);
    $g = $pos[0] ?? 'help';
    $c = $pos[1] ?? '';
    $rest = array_slice($pos, 2);
    $dry = o($opt, 'dry-run') === true;

    switch ($g) {
        case 'help':
        case '-h':
            fwrite(STDOUT, tomok_usage() . "\n");
            return 0;
        case 'keygen':
            $out = need($opt, 'out');
            if (file_exists($out)) { throw new TomokExit(2, "이미 있는 파일입니다(덮어쓰지 않음): {$out}"); }
            $kid = (string) (o($opt, 'kid') ?? 'k1');
            $hex = bin2hex(random_bytes(32));
            $old = umask(077);
            file_put_contents($out, "# Claude 원격 관리 API 키 — 운영 서버 /app/.env.remote-ops 로 업로드(FileZilla). Git·채팅에 올리지 마세요.\n"
                . "# 같은 REMOTE_OPS_KEY 값을 Claude Code 환경 설정의 환경변수 TOMOK_OPS_KEY 로 넣습니다.\n"
                . "REMOTE_OPS_ENABLED=1\nREMOTE_OPS_KEY_ID={$kid}\nREMOTE_OPS_KEY={$hex}\n");
            umask($old);
            chmod($out, 0600);
            tomok_out(['ok' => true, 'op' => 'keygen', 'result' => ['file' => realpath($out), 'kid' => $kid, 'note' => '키 값은 출력하지 않았습니다. 파일을 직접 열어 확인하세요.']]);
            return 0;
        case 'probe':
            return tomok_run($opt, 'probe', array_filter(['write_test' => o($opt, 'write-test') === true, 'grant' => o($opt, 'grant')], static fn($v) => $v !== null));
        case 'http':
            return tomok_http(array_slice($pos, 1), $opt);
        case 'grant':
            return tomok_grant($c, $rest, $opt);
        case 'log':
            if ($c === 'list') {
                return tomok_run($opt, 'log.list', array_filter(['limit' => (int) o($opt, 'limit', 30), 'op' => o($opt, 'op'), 'grant_id' => o($opt, 'grant-id') !== null ? (int) o($opt, 'grant-id') : null], static fn($v) => $v !== null));
            }
            if ($c === 'get') {
                return tomok_run($opt, 'log.get', ['id' => (int) ($rest[0] ?? 0)]);
            }
            break;
        case 'db':
            return tomok_db($c, $rest, $opt, $dry);
        case 'files':
            return tomok_files($c, $rest, $opt, $dry);
    }
    throw new TomokExit(2, "알 수 없는 명령: {$g} {$c} (scripts/tomok help)");
}

function tomok_grant(string $c, array $rest, array $opt): int
{
    switch ($c) {
        case 'status':
            return tomok_run($opt, 'grant.status', ['code' => (string) ($rest[0] ?? '')]);
        case 'list':
            return tomok_run($opt, 'grant.list', array_filter(['status' => o($opt, 'status'), 'limit' => (int) o($opt, 'limit', 20)], static fn($v) => $v !== null));
        case 'cancel':
            return tomok_run($opt, 'grant.cancel', ['code' => (string) ($rest[0] ?? '')]);
        case 'wait':
            $code = (string) ($rest[0] ?? '');
            $deadline = time() + (int) o($opt, 'timeout', 900);
            $interval = max(3, (int) o($opt, 'interval', 10));
            do {
                $r = tomok_call($opt, 'grant.status', ['code' => $code]);
                if (!($r['ok'] ?? false)) {
                    tomok_out(['ok' => false, 'op' => 'grant.wait', 'error' => $r['error'] ?? null]);
                    return 1;
                }
                $st = $r['result']['grant']['status'];
                if ($st !== 'pending') {
                    tomok_out(['ok' => $st === 'approved', 'op' => 'grant.wait', 'result' => $r['result']]);
                    return $st === 'approved' ? 0 : 1;
                }
                sleep($interval);
            } while (time() < $deadline);
            tomok_out(['ok' => false, 'op' => 'grant.wait', 'error' => ['code' => 'timeout', 'message' => '승인 대기 시간 초과(아직 pending)'], 'result' => $r['result'] ?? null]);
            return 1;
    }
    throw new TomokExit(2, 'grant status|wait|list|cancel');
}

function tomok_where(array $opt): array
{
    if (($j = o($opt, 'where-json')) !== null) {
        $w = json_decode((string) $j, true);
        if (!is_array($w)) { throw new TomokExit(2, '--where-json 형식 오류'); }
        return $w;
    }
    $w = [];
    foreach ($opt['where'] ?? [] as $s) {
        [$k, $v] = tomok_kv((string) $s);
        $w[$k] = $v;
    }
    if ($w === []) { throw new TomokExit(2, '--where 또는 --where-json 이 필요합니다'); }
    return $w;
}

function tomok_db(string $c, array $rest, array $opt, bool $dry): int
{
    $reveal = o($opt, 'reveal-pii') === true;
    switch ($c) {
        case 'tables':
            return tomok_run($opt, 'db.tables', ['exact_counts' => o($opt, 'exact') === true]);
        case 'describe':
            return tomok_run($opt, 'db.describe', ['table' => (string) ($rest[0] ?? need($opt, 'table'))]);
        case 'query':
            $sql = o($opt, 'sql') ?? (o($opt, 'file') !== null ? tomok_read_file((string) o($opt, 'file')) : null);
            if ($sql === null) { throw new TomokExit(2, '--sql 또는 --file 이 필요합니다'); }
            $params = array_map(static fn($v) => tomok_kv('v=' . $v)[1], $opt['param'] ?? []);
            return tomok_run($opt, 'db.query', ['sql' => (string) $sql, 'params' => $params, 'limit' => (int) o($opt, 'limit', 200), 'reveal_pii' => $reveal],
                static function (array $r): array {
                    // 행을 {컬럼: 값} 으로 바꿔 읽기 쉽게
                    $r['rows'] = array_map(static fn($row) => array_combine($r['columns'], $row), $r['rows']);
                    return $r;
                });
        case 'search':
            $kind = (string) ($rest[0] ?? 'products');
            return tomok_run($opt, 'db.search', ['kind' => $kind, 'q' => implode(' ', array_slice($rest, 1)), 'limit' => (int) o($opt, 'limit', 50),
                'include_inactive' => o($opt, 'include-inactive') === true]);
        case 'request':
            $columns = null;
            foreach (oa($opt, 'columns') as $tc) {
                if (!str_contains($tc, '.')) { throw new TomokExit(2, "--columns 는 테이블.컬럼: {$tc}"); }
                [$t, $col] = explode('.', $tc, 2);
                $columns[$t][] = $col;
            }
            $ids = null;
            foreach ($opt['ids'] ?? [] as $spec) {
                if (!str_contains((string) $spec, ':')) { throw new TomokExit(2, '--ids 는 테이블:1,2,3'); }
                [$t, $list] = explode(':', (string) $spec, 2);
                foreach (explode(',', $list) as $id) { if (trim($id) !== '') { $ids[$t][] = tomok_kv('v=' . trim($id))[1]; } }
            }
            return tomok_run($opt, 'grant.request', array_filter([
                'title' => need($opt, 'title'), 'detail' => o($opt, 'detail'), 'tables' => oa($opt, 'tables'), 'actions' => oa($opt, 'actions'),
                'columns' => $columns, 'ids' => $ids, 'max_rows_total' => (int) need($opt, 'max-rows'),
                'max_rows_per_op' => o($opt, 'max-rows-per-op') !== null ? (int) o($opt, 'max-rows-per-op') : null,
                'ttl_minutes' => (int) o($opt, 'ttl', 60), 'max_ops' => (int) o($opt, 'max-ops', 20),
                'allow_bulk_delete' => o($opt, 'allow-bulk-delete') === true, 'allow_cascade_delete' => o($opt, 'allow-cascade-delete') === true,
            ], static fn($v) => $v !== null));
        case 'stage-sql':
            return tomok_run($opt, 'db.stage_sql', array_filter(['sql' => tomok_read_file(need($opt, 'file')), 'title' => need($opt, 'title'),
                'detail' => o($opt, 'detail'), 'ttl_minutes' => (int) o($opt, 'ttl', 60), 'max_rows' => o($opt, 'max-rows') !== null ? (int) o($opt, 'max-rows') : null],
                static fn($v) => $v !== null));
        case 'stage-restore':
            return tomok_run($opt, 'db.stage_restore', array_filter(['snapshot' => (int) need($opt, 'snapshot'), 'title' => o($opt, 'title'),
                'ttl_minutes' => (int) o($opt, 'ttl', 60), 'max_rows' => o($opt, 'max-rows') !== null ? (int) o($opt, 'max-rows') : null], static fn($v) => $v !== null));
        case 'update':
        case 'delete':
            $p = ['grant' => need($opt, 'grant'), 'table' => need($opt, 'table'), 'where' => tomok_where($opt), 'dry_run' => $dry, 'reveal_pii' => $reveal];
            if ($c === 'update') {
                $set = [];
                foreach ($opt['set'] ?? [] as $s) { [$k, $v] = tomok_kv((string) $s); $set[$k] = $v; }
                if (($j = o($opt, 'set-json')) !== null) {
                    $sj = json_decode((string) $j, true);
                    if (!is_array($sj)) { throw new TomokExit(2, '--set-json 형식 오류'); }
                    $set = $sj + $set;
                }
                if ($set === []) { throw new TomokExit(2, '--set 이 필요합니다'); }
                $p['set'] = $set;
            }
            if (o($opt, 'expect-rows') !== null) { $p['expect_rows'] = (int) o($opt, 'expect-rows'); }
            return tomok_run($opt, "db.{$c}", $p);
        case 'batch':
            $d = tomok_json_file(need($opt, 'file'));
            return tomok_run($opt, 'db.batch', ['grant' => need($opt, 'grant'), 'table' => (string) ($d['table'] ?? o($opt, 'table', '')), 'rows' => $d['rows'] ?? [],
                'dry_run' => $dry, 'reveal_pii' => $reveal]);
        case 'insert':
            $d = tomok_json_file(need($opt, 'file'));
            return tomok_run($opt, 'db.insert', ['grant' => need($opt, 'grant'), 'table' => need($opt, 'table'), 'rows' => array_is_list($d) ? $d : ($d['rows'] ?? []),
                'dry_run' => $dry, 'reveal_pii' => $reveal]);
        case 'execute':
            return tomok_run($opt, 'db.execute', ['grant' => need($opt, 'grant'), 'dry_run' => $dry]);
        case 'verify':
            return tomok_run($opt, 'db.verify', ['operation' => (int) need($opt, 'operation'), 'reveal_pii' => $reveal]);
        case 'revert':
            return tomok_run($opt, 'db.revert', ['operation' => (int) need($opt, 'operation'), 'dry_run' => $dry, 'force' => o($opt, 'force') === true]);
        case 'backup':
            return tomok_run($opt, 'db.backup', ['tables' => oa($opt, 'tables')]);
    }
    throw new TomokExit(2, "알 수 없는 db 명령: {$c}");
}

/** 서버 파일 내용(없으면 null) */
function tomok_server_file(array $opt, string $path): ?array
{
    $r = tomok_call($opt, 'files.read', ['path' => $path]);
    if (!($r['ok'] ?? false)) {
        if (($r['error']['code'] ?? '') === 'not_found') { return null; }
        throw new TomokExit(1, "{$path}: " . ($r['error']['message'] ?? '읽기 실패'), $r['error'] ?? []);
    }
    $r['result']['content'] = base64_decode((string) $r['result']['content_b64']);
    return $r['result'];
}

function tomok_compare(?string $local, ?string $server): string
{
    if ($local === null && $server === null) { return 'missing_both'; }
    if ($local === null) { return 'missing_local'; }
    if ($server === null) { return 'missing_on_server'; }
    if ($local === $server) { return 'same'; }
    if (str_replace("\r\n", "\n", $local) === str_replace("\r\n", "\n", $server)) { return 'eol_only'; }
    return 'differs';
}

function tomok_files(string $c, array $rest, array $opt, bool $dry): int
{
    $ref = (string) o($opt, 'ref', 'HEAD');
    switch ($c) {
        case 'list':
            return tomok_run($opt, 'files.list', ['path' => (string) ($rest[0] ?? 'public'), 'recursive' => o($opt, 'recursive') === true, 'hashes' => o($opt, 'hashes') === true]);
        case 'read':
            $path = (string) ($rest[0] ?? '');
            $res = tomok_call($opt, 'files.read', ['path' => $path]);
            if (!($res['ok'] ?? false)) { tomok_out(['ok' => false, 'op' => 'files.read', 'error' => $res['error'] ?? null]); return 1; }
            $r = $res['result'];
            $content = base64_decode((string) $r['content_b64']);
            unset($r['content_b64']);
            if (($out = o($opt, 'out')) !== null) {
                file_put_contents((string) $out, $content);
                $r['saved_to'] = realpath((string) $out);
            } elseif (o($opt, 'text') === true || mb_check_encoding($content, 'UTF-8')) {
                $r['content'] = $content;
            } else {
                $r['content_b64'] = base64_encode($content);
            }
            tomok_out(['ok' => true, 'op' => 'files.read', 'op_id' => $res['op_id'] ?? null, 'result' => $r]);
            return 0;
        case 'hash':
            return tomok_run($opt, 'files.hash', ['paths' => $rest]);
        case 'lint':
            return tomok_run($opt, 'files.lint', ['paths' => $rest]);
        case 'snapshot':
            $res = tomok_call($opt, 'files.snapshot', ['id' => (int) ($rest[0] ?? 0)]);
            if (!($res['ok'] ?? false)) { tomok_out(['ok' => false, 'op' => 'files.snapshot', 'error' => $res['error'] ?? null]); return 1; }
            $r = $res['result'];
            if (($out = o($opt, 'out')) !== null && $r['content_b64'] !== null) {
                file_put_contents((string) $out, base64_decode($r['content_b64']));
                $r['saved_to'] = realpath((string) $out);
                unset($r['content_b64']);
            }
            tomok_out(['ok' => true, 'op' => 'files.snapshot', 'result' => $r]);
            return 0;
        case 'diff':
            if ($rest === []) { throw new TomokExit(2, 'files diff <경로…>'); }
            $out = [];
            $allSame = true;
            foreach ($rest as $path) {
                $local = tomok_local_content($path, $ref);
                $srv = tomok_server_file($opt, $path);
                $st = tomok_compare($local, $srv['content'] ?? null);
                $row = ['path' => $path, 'status' => $st, 'local_ref' => $ref, 'local_sha256' => $local !== null ? hash('sha256', $local) : null,
                    'server_sha256' => $srv['sha256'] ?? null, 'server_eol' => $srv['eol'] ?? null, 'server_mtime' => $srv['mtime'] ?? null];
                if ($st === 'differs' || $st === 'missing_on_server') {
                    $d = rops_line_diff($srv['content'] ?? '', (string) $local);
                    $row += ['added' => $d['added'], 'removed' => $d['removed'], 'diff' => '--- server/' . $path . "\n+++ local({$ref})/" . $path . "\n" . $d['text']];
                }
                $allSame = $allSame && in_array($st, ['same', 'eol_only'], true);
                $out[] = $row;
            }
            tomok_out(['ok' => true, 'op' => 'files.diff', 'result' => ['all_same' => $allSame, 'files' => $out]]);
            return 0;
        case 'manifest':
            $roots = oa($opt, 'roots') ?: ['public', 'app', 'database'];
            $res = tomok_call($opt, 'files.manifest', ['roots' => $roots]);
            if (!($res['ok'] ?? false)) { tomok_out(['ok' => false, 'op' => 'files.manifest', 'error' => $res['error'] ?? null]); return 1; }
            $server = [];
            foreach ($res['result']['files'] as $f) { $server[$f['path']] = $f; }
            $gitFiles = array_values(array_filter(explode("\0", tomok_git(array_merge(['ls-tree', '-r', '-z', '--name-only', $ref, '--'], $roots)))));
            $rows = [];
            $count = ['same' => 0, 'eol_only' => 0, 'differs' => 0, 'missing_on_server' => 0, 'extra_on_server' => 0];
            foreach ($gitFiles as $p) {
                $local = tomok_local_content($p, $ref);
                $s = $server[$p] ?? null;
                if ($s === null) { $st = 'missing_on_server'; }
                elseif ($local !== null && hash('sha256', $local) === $s['sha256']) { $st = 'same'; }
                elseif ($local !== null && hash('sha256', str_replace("\r\n", "\n", $local)) === $s['sha256_lf']) { $st = 'eol_only'; }
                else { $st = 'differs'; }
                $count[$st]++;
                unset($server[$p]);
                if ($st !== 'same' || o($opt, 'all') === true) { $rows[] = ['path' => $p, 'status' => $st, 'server_mtime' => $s['mtime'] ?? null]; }
            }
            foreach ($server as $p => $s) {
                $count['extra_on_server']++;
                $rows[] = ['path' => $p, 'status' => 'extra_on_server', 'server_mtime' => $s['mtime'], 'size' => $s['size']];
            }
            tomok_out(['ok' => true, 'op' => 'files.manifest', 'result' => ['ref' => $ref, 'commit' => trim(tomok_git(['rev-parse', '--short', $ref])), 'roots' => $roots,
                'counts' => $count, 'server_truncated' => $res['result']['truncated'], 'files' => $rows,
                'note' => 'same=같음, eol_only=줄바꿈만 다름, differs=내용 다름, missing_on_server=서버에 없음, extra_on_server=Git 에 없는 서버 파일(삭제 대상 후보 — 승인 없이 지우지 않음)']]);
            return 0;
        case 'stage':
            $files = [];
            $lintErr = [];
            if ($rest === [] && !isset($opt['delete'])) { throw new TomokExit(2, 'files stage <경로…> --title T'); }
            foreach ($rest as $path) {
                if ($ref !== 'worktree') {
                    $code = 0;
                    tomok_git(['diff', '--quiet', $ref, '--', $path], $code);
                    if ($code !== 0) { throw new TomokExit(2, "{$path}: 작업 폴더에 {$ref} 와 다른(커밋 안 된) 변경이 있습니다. 커밋 후 다시(배포는 커밋 기준)."); }
                }
                $content = tomok_local_content($path, $ref);
                if ($content === null) { throw new TomokExit(2, "{$path}: {$ref} 에 없는 파일"); }
                if (str_ends_with(strtolower($path), '.php') && ($e = tomok_php_lint_local($content)) !== null) { $lintErr[$path] = $e; }
                $files[] = ['path' => $path, 'content_b64' => base64_encode($content), 'sha256' => hash('sha256', $content)];
            }
            if ($lintErr) { throw new TomokExit(1, '로컬 PHP 문법 검사 실패 — 배포 요청하지 않음', $lintErr); }
            $commit = trim(tomok_git(['rev-parse', '--short', $ref === 'worktree' ? 'HEAD' : $ref]));
            $detail = trim((string) (o($opt, 'detail') ?? '') . "\n기준: " . ($ref === 'worktree' ? "작업 폴더(HEAD {$commit} + 미커밋 변경)" : "커밋 {$commit}"));
            return tomok_run($opt, 'files.stage', ['title' => need($opt, 'title'), 'detail' => $detail, 'files' => $files, 'delete' => oa($opt, 'delete'),
                'ttl_minutes' => (int) o($opt, 'ttl', 60)]);
        case 'deploy':
            $code = need($opt, 'grant');
            $res = tomok_call($opt, 'files.deploy', ['grant' => $code, 'dry_run' => $dry]);
            $out = ['ok' => (bool) ($res['ok'] ?? false), 'op' => 'files.deploy', 'op_id' => $res['op_id'] ?? null];
            if (!$out['ok']) { $out['error'] = $res['error'] ?? null; tomok_out($out); return 1; }
            $out['result'] = $res['result'];
            if (!$dry) {
                $php = array_values(array_filter(array_column(array_filter($res['result']['files'], static fn($f) => $f['sha256'] !== null), 'path'), static fn($p) => str_ends_with($p, '.php')));
                if ($php !== []) {
                    $lint = tomok_call($opt, 'files.lint', ['paths' => $php]);
                    $out['post_lint'] = $lint['result'] ?? $lint['error'] ?? null;
                    if (!($lint['result']['ok'] ?? false)) { $out['ok'] = false; }
                }
                if (isset($opt['check'])) {
                    $out['http'] = tomok_http_check(array_map('strval', $opt['check']), $opt);
                    foreach ($out['http'] as $h) { if (!$h['ok']) { $out['ok'] = false; } }
                }
                if (!$out['ok']) { $out['rollback_hint'] = 'scripts/tomok files restore --operation ' . $out['op_id']; }
            }
            tomok_out($out);
            return $out['ok'] ? 0 : 1;
        case 'restore':
            return tomok_run($opt, 'files.restore', ['operation' => (int) need($opt, 'operation'), 'dry_run' => $dry, 'force' => o($opt, 'force') === true]);
    }
    throw new TomokExit(2, "알 수 없는 files 명령: {$c}");
}

/** HTTP 응답 확인(상태 코드·PHP 오류 문구·표시 문구) */
function tomok_http_check(array $paths, array $opt): array
{
    $api = parse_url(tomok_url($opt));
    $base = $api['scheme'] . '://' . $api['host'] . (isset($api['port']) ? ':' . $api['port'] : '');
    $expect = (int) o($opt, 'expect', 200);
    $marker = o($opt, 'marker');
    $out = [];
    foreach ($paths as $p) {
        $url = str_starts_with($p, '/') ? $base . $p : $p;
        $u = parse_url($url);
        if (($u['host'] ?? '') !== $api['host']) { $out[] = ['url' => $url, 'ok' => false, 'error' => '관리 API 와 같은 호스트만 확인합니다']; continue; }
        $r = tomok_curl($url, null, 30);
        $body = (string) $r['body'];
        $phpErr = preg_match('/(Fatal error|Parse error|Warning<\/b>:|Uncaught |Stack trace:)/', $body, $m) ? $m[1] : null;
        $ok = $r['status'] === $expect && $phpErr === null && ($marker === null || str_contains($body, (string) $marker));
        $out[] = ['url' => $url, 'ok' => $ok, 'status' => $r['status'], 'ms' => $r['ms'], 'bytes' => strlen($body), 'php_error' => $phpErr,
            'marker_found' => $marker === null ? null : str_contains($body, (string) $marker), 'error' => $r['error'] ?: null];
    }
    return $out;
}

function tomok_http(array $paths, array $opt): int
{
    if ($paths === []) { throw new TomokExit(2, 'tomok http <경로…>'); }
    $r = tomok_http_check($paths, $opt);
    $ok = !in_array(false, array_column($r, 'ok'), true);
    tomok_out(['ok' => $ok, 'op' => 'http', 'result' => $r]);
    return $ok ? 0 : 1;
}

try {
    exit(tomok_main($argv));
} catch (TomokExit $e) {
    tomok_out(['ok' => false, 'error' => ['code' => 'cli', 'message' => $e->getMessage(), 'data' => $e->data]]);
    exit($e->exitCode);
} catch (\Throwable $e) {
    tomok_out(['ok' => false, 'error' => ['code' => 'cli_internal', 'message' => get_class($e) . ': ' . $e->getMessage()]]);
    exit(3);
}
