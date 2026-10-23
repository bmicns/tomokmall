<?php
declare(strict_types=1);
/**
 * 운영 복제 테스트 DB 도구 공통 (scripts/testdb/*.php 에서 require)
 *
 * 책임: CLI 전용 확인, 로컬 '_test' DB 접속 가드, phpMyAdmin CSV(blk·결과 JSON) 읽기
 * 금지: 웹 실행, 운영 DB 접속. 이 폴더는 deploy.sh 대상(public/app/database)이 아니다.
 * 데이터 파일(운영 내보내기 CSV)은 저장소 밖(세션 scratchpad 등)에 두고 커밋하지 않는다.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require_once __DIR__ . '/../../app/bootstrap.php';

function tdb_line(string $s = ''): void { echo $s, "\n"; }
function tdb_stop(string $s): never { fwrite(STDERR, "중단: {$s}\n"); exit(1); }

/** 로컬 '_test' DB에만 접속(scripts/local-test-accounts.php 와 같은 기준). 아니면 중단. */
function tdb_test_pdo(): PDO
{
    global $config;
    $host = strtolower((string) ($config['db']['host'] ?? ''));
    $name = (string) ($config['db']['name'] ?? '');
    if (!preg_match('/_test$/', $name) || !in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        tdb_stop("로컬 테스트 DB(DB_NAME=*_test, DB_HOST=127.0.0.1|localhost)가 아닙니다. 현재 DB_HOST={$host} DB_NAME={$name}");
    }
    $pdo = db();
    if (!$pdo) {
        tdb_stop('테스트 DB 접속 실패 — 컨테이너 실행 여부와 DB_USER/DB_PASS 를 확인하세요.');
    }
    if ((string) $pdo->query('SELECT DATABASE()')->fetchColumn() !== $name) {
        tdb_stop('접속된 DB 이름이 설정과 다릅니다.');
    }
    return $pdo;
}

/**
 * phpMyAdmin 결과 CSV(첫 줄 헤더, 열: blk, [cnt,] result) → ['blk' => ['cnt' => ?int, 'data' => JSON 디코드 값]]
 * 소수는 문자열로 유지(JSON_BIGINT_AS_STRING + 정밀도 보존).
 */
function tdb_read_csv(string $path): array
{
    if (!is_file($path)) {
        tdb_stop("파일 없음: {$path}");
    }
    $fh = fopen($path, 'rb');
    $head = fgetcsv($fh, 0, ',', '"', '');
    if ($head && isset($head[0])) {
        $head[0] = preg_replace('/^\xEF\xBB\xBF/', '', $head[0]);   // BOM 제거
    }
    $out = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
        if (count($r) < 2) {
            continue;
        }
        $row  = array_combine(array_slice($head, 0, count($r)), $r);
        $json = (string) ($row['result'] ?? '');
        $out[(string) $row['blk']] = [
            'cnt'  => isset($row['cnt']) && $row['cnt'] !== '' && $row['cnt'] !== 'NULL' ? (int) $row['cnt'] : null,
            'data' => $json === '' || $json === 'NULL' ? null : json_decode($json, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR),
        ];
    }
    fclose($fh);
    return $out;
}
