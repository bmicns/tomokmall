<?php
declare(strict_types=1);
if (($_GET['key'] ?? '') !== 'tomok6385!') { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass']);

// 합니다. / 입니다. 뒤 공백을 줄바꿈으로 교체
$pdo->exec("UPDATE categories SET description = REPLACE(description, '합니다. ', '합니다.\n') WHERE description LIKE '%합니다. %'");
$pdo->exec("UPDATE categories SET description = REPLACE(description, '입니다. ', '입니다.\n') WHERE description LIKE '%입니다. %'");
$pdo->exec("UPDATE categories SET description = REPLACE(description, '세요. ',  '세요.\n')  WHERE description LIKE '%세요. %'");

@unlink(__FILE__);
echo "✅ 완료";
