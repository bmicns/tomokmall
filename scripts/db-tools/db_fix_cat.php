<?php
declare(strict_types=1);
// 접근 키는 코드에 두지 않는다 — 환경변수 DB_TOOL_KEY(24자 이상)와 같을 때만 실행, 없으면 항상 거부(.env.example 참고)
$__toolKey = (string) getenv('DB_TOOL_KEY');
if (strlen($__toolKey) < 24 || !hash_equals($__toolKey, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass']);
$n = $pdo->exec("UPDATE categories SET is_active = 0 WHERE name = '1련암거'");
@unlink(__FILE__);
echo "✅ {$n}개 카테고리 비활성화 완료";
