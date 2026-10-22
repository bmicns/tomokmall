<?php
declare(strict_types=1);
/**
 * 운영 DB 연결 실패 원인 진단 (일회용·읽기 전용) — scripts/db-tools/db_conn_diag.php
 *
 * 책임: 웹사이트(PHP)가 DB에 접속하지 못하는 원인을 "분류"만 해서 보여준다.
 * 금지: 비밀번호·접속 정보 원문, PDO 오류 메시지 원문 출력(계정명·호스트 포함 가능) / DB 데이터 조회·변경 / 서버 설정 변경.
 *
 * 사용(사용자 승인 후 1회):
 *   1) 이 파일을 서버 /html 에 "dbdiag-<무작위 16자 이상 영숫자>.php" 이름으로 업로드 (그 외 이름이면 404로 동작 안 함)
 *   2) 브라우저로 1회 열기 → 결과 확인
 *   3) 실행 직후 스스로 삭제를 시도한다. 결과 맨 아래 '파일 자동 삭제'가 실패면 FTP로 즉시 직접 삭제
 * 실행 결과에는 접속 시도 1~2회 외의 부작용이 없다(쿼리는 SELECT 1 만).
 */

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

// 이름 규칙이 맞지 않으면(우연히 남은 사본·추측 접근) 아무것도 하지 않는다
if (!preg_match('/^dbdiag-[A-Za-z0-9]{16,}\.php$/', basename(__FILE__))) {
    http_response_code(404);
    exit;
}

$appDir  = realpath(__DIR__ . '/../app') ?: (__DIR__ . '/../app');
$envFile = $appDir . '/.env';
$yn      = static fn(bool $b): string => $b ? '예' : '아니오';
$out     = [];

// ── 1. PHP 환경 ─────────────────────────────────────────────
$out[] = '[1] PHP 환경';
$out[] = '  PHP 버전: ' . PHP_VERSION;
$out[] = '  pdo_mysql 확장 로드: ' . $yn(extension_loaded('pdo_mysql'));
$out[] = '  mysqli 확장 로드(참고): ' . $yn(extension_loaded('mysqli'));

// ── 2. /app/.env 파일 상태(값은 출력하지 않음) ───────────────────
$out[] = '';
$out[] = '[2] /app/.env 파일';
$exists = is_file($envFile);
$out[] = '  파일 존재: ' . $yn($exists);
$keys  = [];
if ($exists) {
    $out[] = '  읽기 가능: ' . $yn(is_readable($envFile));
    $out[] = '  크기: ' . filesize($envFile) . ' bytes';
    $out[] = '  마지막 수정: ' . date('Y-m-d H:i:s', (int) filemtime($envFile)) . ' (서버 시각)';
    $raw = is_readable($envFile) ? (string) file_get_contents($envFile) : '';
    $out[] = '  UTF-8 BOM(첫 줄 키 인식 실패 원인): ' . $yn(strncmp($raw, "\xEF\xBB\xBF", 3) === 0);
    $out[] = '  Windows 줄바꿈(CRLF, 참고·대부분 무해): ' . $yn(strpos($raw, "\r\n") !== false);
    foreach (preg_split('/\r\n|\n|\r/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) { continue; }
        $k = trim(substr($line, 0, (int) strpos($line, '=')));
        $v = trim(substr($line, (int) strpos($line, '=') + 1));
        $keys[$k] = $v !== '' && $v !== '""' && $v !== "''";
    }
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS'] as $k) {
        $out[] = sprintf('  %-8s 항목: %s', $k, isset($keys[$k]) ? ('있음' . ($keys[$k] ? '(값 있음)' : '(값 비어 있음)')) : '없음');
    }
    $odd = array_filter(array_keys($keys), static fn($k) => !preg_match('/^[A-Z][A-Z0-9_]*$/', $k));
    $out[] = '  형식이 이상한 키 이름 수(공백·BOM·소문자 등): ' . count($odd);
}

// ── 3. 웹사이트가 실제로 쓰는 접속 값(앱과 동일한 로딩 경로) ────────────
require $appDir . '/bootstrap.php';
/** @var array $config */
$db = $config['db'];
$out[] = '';
$out[] = '[3] 웹사이트가 쓰는 접속 값 (bootstrap.php 로딩 결과, 원문 비공개)';
$hostShown = in_array($db['host'], ['localhost', '127.0.0.1'], true) ? $db['host'] : ('기타 값(' . strlen((string) $db['host']) . '자)');
$out[] = '  DB_HOST: ' . $hostShown . (getenv('DB_HOST') === false ? '  ← .env에서 못 읽어 기본값 사용' : '');
$out[] = '  DB_NAME: ' . (getenv('DB_NAME') === false ? '.env에서 못 읽음(기본값 사용)' : '.env 값 사용') . ' / 기본값(tomokmall)과 같음: ' . $yn($db['name'] === 'tomokmall');
$out[] = '  DB_USER: ' . (getenv('DB_USER') === false ? '.env에서 못 읽음(기본값 사용)' : '.env 값 사용') . ' / 기본값(tomokmall)과 같음: ' . $yn($db['user'] === 'tomokmall');
$out[] = '  DB_PASS: ' . ((string) $db['pass'] === '' ? '비어 있음 ← 접속 실패 원인 가능성 큼' : '값 있음(내용 비공개)');

// ── 4. 접속 시도(SELECT 1만) — 오류는 코드·분류만 ─────────────────────
$explain = static function (\Throwable $e): string {
    $msg = $e->getMessage();
    $code = 0;
    if (preg_match('/\[(\d{4})\]/', $msg, $m)) { $code = (int) $m[1]; }
    if (stripos($msg, 'could not find driver') !== false) { return 'PHP에 pdo_mysql 드라이버 없음(PHP 버전/설정 변경 의심)'; }
    $map = [
        1045 => '1045 계정 또는 비밀번호 불일치(Access denied)',
        1044 => '1044 계정은 맞지만 이 DB 사용 권한 없음',
        1049 => '1049 DB 이름이 존재하지 않음',
        2002 => '2002 DB 서버에 연결 불가(호스트·소켓 경로 문제)',
        2005 => '2005 DB 호스트 이름을 찾을 수 없음',
        1040 => '1040 DB 서버 전체 연결 수 초과',
        1203 => '1203 이 계정의 동시 연결 수 초과',
        1226 => '1226 계정 사용량 제한 초과',
        2054 => '2054 인증 방식 불일치(caching_sha2_password 등)',
        2006 => '2006 연결이 끊김(서버 재시작·타임아웃)',
    ];
    return $map[$code] ?? ('기타 오류(코드 ' . ($code ?: 'SQLSTATE ' . (string) $e->getCode()) . ')');
};
$try = static function (string $host) use ($db, $explain): string {
    $t0 = microtime(true);
    try {
        $pdo = new PDO(
            "mysql:host={$host};dbname={$db['name']};charset={$db['charset']}",
            (string) $db['user'], (string) $db['pass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
        );
        $pdo->query('SELECT 1')->fetchColumn();
        return '성공 (' . round((microtime(true) - $t0) * 1000) . 'ms)';
    } catch (\Throwable $e) {
        return '실패 → ' . $explain($e);
    }
};
$out[] = '';
$out[] = '[4] 접속 시도 (SELECT 1만 실행)';
$out[] = '  현재 설정 호스트(' . $hostShown . '): ' . $try((string) $db['host']);
if (in_array($db['host'], ['localhost', '127.0.0.1'], true)) {
    $alt = $db['host'] === 'localhost' ? '127.0.0.1' : 'localhost';
    $out[] = '  비교용 호스트(' . $alt . ', 나머지 값 동일): ' . $try($alt);
}
$out[] = '  웹사이트 db() 결과: ' . (db() ? '정상 연결' : 'null (연결 실패)');

// ── 5. 자기 자신 삭제(일회용) ─────────────────────────────────────
$out[] = '';
$out[] = '[5] 파일 자동 삭제: ' . (@unlink(__FILE__) ? '완료' : '실패 — FTP로 ' . basename(__FILE__) . ' 을 즉시 직접 삭제하세요');

echo implode("\n", $out), "\n";
