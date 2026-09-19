<?php
declare(strict_types=1);

// 1회성 — AI 3단계 업그레이드 DB 설치 스크립트
define('SETUP_PASS', 'bmicns_ai3_2026');

if (($_GET['key'] ?? '') !== SETUP_PASS) {
    http_response_code(403);
    exit('403 Forbidden — ?key=bmicns_ai3_2026 필요');
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

$files = [
    __DIR__ . '/../database/add_category_consult_guide.sql',
    __DIR__ . '/../database/add_chat_logs.sql',
];

$log = [];

foreach ($files as $file) {
    if (!file_exists($file)) {
        $log[] = ['file' => basename($file), 'status' => 'error', 'msg' => '파일 없음'];
        continue;
    }

    $sql = file_get_contents($file);
    // 단일행 주석(-- ...) 제거 후 세미콜론 기준 분리
    $sql = preg_replace('/^--[^\n]*/m', '', $sql);
    $statements = array_filter(
        array_map('trim', explode(';', $sql)),
        fn($s) => $s !== ''
    );

    $fileOk = true;
    foreach ($statements as $stmt) {
        try {
            $pdo->exec($stmt);
        } catch (\PDOException $e) {
            $log[]  = ['file' => basename($file), 'status' => 'error', 'msg' => $e->getMessage(), 'sql' => mb_substr($stmt, 0, 120)];
            $fileOk = false;
        }
    }
    if ($fileOk) {
        $log[] = ['file' => basename($file), 'status' => 'ok', 'msg' => '완료'];
    }
}

header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-family:monospace;padding:20px">';
echo "<b>AI 3단계 DB 설치 결과</b>\n\n";
foreach ($log as $entry) {
    $icon = $entry['status'] === 'ok' ? '✅' : '❌';
    echo "{$icon} [{$entry['file']}] {$entry['msg']}\n";
    if (isset($entry['sql'])) echo "   SQL: {$entry['sql']}\n";
}
echo "\n<b>완료. 이 파일은 실행 후 서버에서 삭제하세요.</b>";
echo '</pre>';
