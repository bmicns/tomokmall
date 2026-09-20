<?php
declare(strict_types=1);

define('SETUP_PASS', 'bmicns_ngram_2026');

if (($_GET['key'] ?? '') !== SETUP_PASS) {
    http_response_code(403);
    exit('403 Forbidden — ?key=bmicns_ngram_2026 필요');
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

$log = [];

// ── ngram_token_size 확인 ─────────────────────────────────────
try {
    $row = $pdo->query("SHOW VARIABLES LIKE 'ngram_token_size'")->fetch();
    $log[] = ['status' => 'info', 'msg' => "ngram_token_size = " . ($row['Value'] ?? '?') . " (2이면 2글자 검색 가능)"];
} catch (\PDOException $e) {
    $log[] = ['status' => 'warn', 'msg' => "ngram_token_size 확인 실패: " . $e->getMessage()];
}

// ── 공통: FULLTEXT 인덱스 재구성 함수 ────────────────────────
function rebuild_fulltext(PDO $pdo, string $table, array $cols, string $newIdx, array &$log): void
{
    // 기존 FULLTEXT 인덱스 모두 삭제
    try {
        $stmt = $pdo->query(
            "SELECT INDEX_NAME FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = '{$table}'
               AND INDEX_TYPE = 'FULLTEXT'
             GROUP BY INDEX_NAME"
        );
        $existing = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($existing as $idx) {
            $pdo->exec("ALTER TABLE `{$table}` DROP INDEX `{$idx}`");
            $log[] = ['status' => 'ok', 'msg' => "[{$table}] 기존 인덱스 '{$idx}' 삭제"];
        }
    } catch (\PDOException $e) {
        $log[] = ['status' => 'warn', 'msg' => "[{$table}] 인덱스 삭제 실패: " . $e->getMessage()];
    }

    // ngram FULLTEXT 인덱스 생성
    $colList = '`' . implode('`, `', $cols) . '`';
    try {
        $pdo->exec(
            "ALTER TABLE `{$table}`
             ADD FULLTEXT INDEX `{$newIdx}` ({$colList}) WITH PARSER ngram"
        );
        $log[] = ['status' => 'ok', 'msg' => "[{$table}] ngram FULLTEXT 인덱스 생성 완료 ({$colList})"];
    } catch (\PDOException $e) {
        $log[] = ['status' => 'error', 'msg' => "[{$table}] 인덱스 생성 실패: " . $e->getMessage()];
    }
}

rebuild_fulltext($pdo, 'products',      ['name', 'spec', 'description'], 'idx_ngram_products',  $log);
rebuild_fulltext($pdo, 'rag_documents', ['title', 'content', 'tags'],    'idx_ngram_rag_docs',  $log);

// category_consult_guide 는 FULLTEXT 없으니 스킵

header('Content-Type: text/html; charset=utf-8');
echo '<pre style="font-family:monospace;padding:20px;line-height:1.8">';
echo "<b>ngram FULLTEXT 재구성 결과</b>\n\n";
foreach ($log as $entry) {
    $icon = match($entry['status']) {
        'ok'   => '✅',
        'warn' => '⚠️ ',
        'info' => 'ℹ️ ',
        default => '❌',
    };
    echo "{$icon} {$entry['msg']}\n";
}
echo "\n<b>완료. 이 파일은 삭제하세요.</b>";
echo '</pre>';
