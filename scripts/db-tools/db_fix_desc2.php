<?php
declare(strict_types=1);
// 접근 키는 코드에 두지 않는다 — 환경변수 DB_TOOL_KEY(24자 이상)와 같을 때만 실행, 없으면 항상 거부(.env.example 참고)
$__toolKey = (string) getenv('DB_TOOL_KEY');
if (strlen($__toolKey) < 24 || !hash_equals($__toolKey, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass']);

// 합니다. / 입니다. 뒤 공백을 줄바꿈으로 교체
$pdo->exec("UPDATE categories SET description = REPLACE(description, '합니다. ', '합니다.\n') WHERE description LIKE '%합니다. %'");
$pdo->exec("UPDATE categories SET description = REPLACE(description, '입니다. ', '입니다.\n') WHERE description LIKE '%입니다. %'");
$pdo->exec("UPDATE categories SET description = REPLACE(description, '세요. ',  '세요.\n')  WHERE description LIKE '%세요. %'");

@unlink(__FILE__);
echo "✅ 완료";
