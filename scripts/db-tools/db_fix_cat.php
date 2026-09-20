<?php
declare(strict_types=1);
if (($_GET['key'] ?? '') !== 'tomok6385!') { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass']);
$n = $pdo->exec("UPDATE categories SET is_active = 0 WHERE name = '1련암거'");
@unlink(__FILE__);
echo "✅ {$n}개 카테고리 비활성화 완료";
