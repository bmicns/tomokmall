<?php
declare(strict_types=1);
/**
 * 원격 관리 API — 공통(설정·DB 연결·오류) (app/remote_ops/core.php)
 *
 * 책임: 설정 파일 읽기(/app/.env.remote-ops, /app/.env), 관리 API 전용 PDO 연결, 공통 오류 형식, 압축 유틸
 * 금지: app/bootstrap.php 포함(세션 시작·여러 모듈 로드 — 배포로 사이트 코드가 깨져도 관리 API 는 살아 있어야 복구 가능),
 *       키·DB 비밀번호를 반환값·로그·오류문구에 넣기
 *
 * DB 접속값은 사이트와 같은 키(DB_HOST/DB_NAME/DB_USER/DB_PASS)·같은 기본값(app/config.php)·같은 PDO 옵션(app/bootstrap.php db())을 쓴다.
 * 우선순위도 bootstrap.php 와 같다: 셸·서버 환경변수 > 파일 값(먼저 읽은 파일 우선).
 */

/** 작업 오류. code 는 기계용(영문), message 는 사람용(한국어). data 는 추가 정보(민감정보 없음). */
final class RopsError extends \RuntimeException
{
    public function __construct(public readonly string $codeName, string $message, public readonly array $data = [])
    {
        parent::__construct($message);
    }
}

/** KEY=VALUE 파일 → 배열 (bootstrap.php load_env_file 과 같은 규칙: # 주석, 양끝 따옴표 제거). 환경변수에는 넣지 않는다. */
function rops_env_parse(string $file): array
{
    $out = [];
    if (!is_file($file) || !is_readable($file)) {
        return $out;
    }
    foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $n => $line) {
        // Windows 메모장이 붙이는 UTF-8 BOM 제거(첫 줄 키 이름이 깨지지 않게)
        $line = trim($n === 0 ? preg_replace('/^\xEF\xBB\xBF/', '', $line) : $line);
        if ($line === '' || $line[0] === '#' || ($eq = strpos($line, '=')) === false) {
            continue;
        }
        $k = trim(substr($line, 0, $eq));
        $v = trim(substr($line, $eq + 1));
        $len = strlen($v);
        if ($len >= 2 && ($v[0] === '"' || $v[0] === "'") && $v[$len - 1] === $v[0]) {
            $v = substr($v, 1, -1);
        }
        if ($k !== '' && !array_key_exists($k, $out)) {
            $out[$k] = $v;
        }
    }
    return $out;
}

/** 경로 루트(실제 경로). 로컬 저장소 경로 이름(public/app/database) → 서버 실제 폴더 */
function rops_roots(string $webroot): array
{
    $app = dirname(__DIR__);
    $roots = ['public' => realpath($webroot) ?: null, 'app' => realpath($app) ?: null, 'database' => realpath(dirname($app) . '/database') ?: null];
    return array_filter($roots, static fn($v) => $v !== null);
}

/**
 * 설정. 키 원문은 'key' 에만 있고, 이 배열을 통째로 출력하는 코드는 두지 않는다.
 */
function rops_settings(?string $webroot = null): array
{
    static $s = null;
    if ($s !== null && $webroot === null) {
        return $s;
    }
    $app = dirname(__DIR__);
    $file = [];
    foreach ([$app . '/.env.remote-ops', $app . '/.env', dirname($app) . '/.env.local'] as $f) {
        $file += rops_env_parse($f);
    }
    $get = static function (string $k, string $def = '') use ($file): string {
        $e = getenv($k);
        if ($e !== false) { return $e; }
        return array_key_exists($k, $file) ? $file[$k] : $def;
    };
    $keyHex = $get('REMOTE_OPS_KEY');
    $key = '';
    if ($keyHex !== '') {
        try { $key = rops_key_bytes($keyHex); } catch (\Throwable $e) { $key = ''; }
    }
    $scopes = array_values(array_filter(array_map('trim', explode(',', $get('REMOTE_OPS_SCOPES', 'probe,db.read,db.write,files.read,files.write')))));
    $s = [
        'enabled'        => $get('REMOTE_OPS_ENABLED', '0') === '1' && $key !== '',
        'key_configured' => $key !== '',
        'kid'            => $get('REMOTE_OPS_KEY_ID', 'k1'),
        'key'            => $key,
        'scopes'         => $scopes,
        'require_enc'    => $get('REMOTE_OPS_REQUIRE_ENC', '1') !== '0',
        'skew'           => max(30, min(900, (int) $get('REMOTE_OPS_CLOCK_SKEW', '300'))),
        'max_file_bytes' => max(1024, min(16 * 1048576, (int) $get('REMOTE_OPS_MAX_FILE_BYTES', (string) (4 * 1048576)))),
        'allowed_ips'    => array_values(array_filter(array_map('trim', explode(',', $get('REMOTE_OPS_ALLOWED_IPS', ''))))),
        'base_url'       => rtrim($get('REMOTE_OPS_BASE_URL', 'https://tomokmall.dothome.co.kr'), '/'),
        'db' => [
            'host' => $get('DB_HOST') ?: 'localhost',
            'name' => $get('DB_NAME') ?: 'tomokmall',
            'user' => $get('DB_USER') ?: 'tomokmall',
            'pass' => $get('DB_PASS'),
        ],
        // 웹루트: 진입점이 알려 준 경로. 없으면 서버 구조(/html) → 로컬 구조(public/) 순
        'roots' => rops_roots($webroot ?? (is_dir(dirname($app) . '/html') ? dirname($app) . '/html' : dirname($app) . '/public')),
    ];
    return $s;
}

/** 관리 API 전용 PDO(사이트 db() 와 같은 옵션 + 여러 문장 실행 끔). 실패 시 RopsError(db_unavailable). */
function rops_pdo(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $d = rops_settings()['db'];
    try {
        $pdo = new PDO("mysql:host={$d['host']};dbname={$d['name']};charset=utf8mb4", $d['user'], $d['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_MULTI_STATEMENTS => false,
            PDO::ATTR_TIMEOUT            => 10,
        ]);
    } catch (\PDOException $e) {
        $code = (int) ($e->errorInfo[1] ?? 0);
        error_log('[remote-ops] DB 접속 실패 (오류 ' . ($code ?: '?') . ')');
        throw new RopsError('db_unavailable', 'DB 접속 실패' . ($code ? " (MySQL 오류 {$code})" : ''));
    }
    try {
        // 잠금 대기가 길어지지 않게(쓰기 작업 중 다른 요청과 충돌 시 10초 후 실패)
        $pdo->exec('SET SESSION innodb_lock_wait_timeout = 10');
    } catch (\Throwable $e) {
        // 권한·버전 차이로 실패해도 기본값으로 계속
    }
    return $pdo;
}

/** 압축(가능하면 gzip) → [인코딩, 바이트] */
function rops_pack(string $data): array
{
    if (function_exists('gzencode')) {
        $z = gzencode($data, 6);
        if ($z !== false) {
            return ['gzip', $z];
        }
    }
    return ['raw', $data];
}

function rops_unpack(string $encoding, ?string $body): ?string
{
    if ($body === null || $encoding === 'none') {
        return null;
    }
    if ($encoding === 'gzip') {
        $d = gzdecode($body);
        if ($d === false) {
            throw new RopsError('snapshot_corrupt', '백업 데이터 압축 해제 실패');
        }
        return $d;
    }
    return $body;
}

/** 오류문구 정리: 길이 제한, 혹시 모를 DB 접속값 제거 */
function rops_clean_message(string $m): string
{
    $d = rops_settings()['db'];
    foreach ([$d['pass'], $d['user'] . '@'] as $secret) {
        if ($secret !== '' && $secret !== '@') {
            $m = str_replace($secret, '***', $m);
        }
    }
    return function_exists('mb_substr') ? mb_substr($m, 0, 900) : substr($m, 0, 900);
}

function rops_now_ms(): int
{
    return (int) floor(microtime(true) * 1000);
}
