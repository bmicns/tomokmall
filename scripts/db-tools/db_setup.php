<?php
declare(strict_types=1);

// 1회성 DB 임포트 스크립트 — 실행 후 삭제됨
define('SETUP_PASS', 'tomok6385!');

if (($_GET['key'] ?? '') !== SETUP_PASS) {
    http_response_code(403);
    exit('403 Forbidden');
}

$config = require __DIR__ . '/../app/config.php';
$db     = $config['db'];
$dsn    = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";

try {
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (\PDOException $e) {
    exit('DB 연결 실패: ' . $e->getMessage());
}

// spec 컬럼이 VARCHAR(180)으로 생성된 경우 TEXT로 확장 (이미 TEXT면 무시됨)
try { $pdo->exec("ALTER TABLE products MODIFY COLUMN spec TEXT NULL"); } catch (\PDOException $e) { /* 무시 */ }

$files = [
    __DIR__ . '/../database/schema.sql',
    __DIR__ . '/../database/add_ai_sessions.sql',
    __DIR__ . '/../data/ktomok/import.sql',
];

$log = [];

foreach ($files as $file) {
    if (!file_exists($file)) {
        $log[] = "❌ 파일 없음: " . basename($file);
        continue;
    }

    $sql = file_get_contents($file);

    // 세미콜론 기준으로 구문 분리
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== '' && !preg_match('/^--/', $s)
    );

    $ok  = 0;
    $err = 0;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
            $ok++;
        } catch (\PDOException $e) {
            // 이미 존재하는 테이블/컬럼/키는 무시
            $code = (int) $e->getCode();
            if (in_array($code, [1050, 1060, 1061, 1062])) {
                $ok++;
            } else {
                $err++;
                $log[] = "  ⚠️ " . $e->getMessage();
            }
        }
    }

    $log[] = "✅ " . basename($file) . " — {$ok}개 성공" . ($err > 0 ? ", {$err}개 오류" : "");
}

// 자체 삭제
@unlink(__FILE__);
$log[] = "🗑️ db_setup.php 삭제 완료";

header('Content-Type: text/plain; charset=utf-8');
echo implode("\n", $log) . "\n\nDB 설정 완료!\n";
