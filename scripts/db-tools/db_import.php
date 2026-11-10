<?php
declare(strict_types=1);
// 접근 키는 코드에 두지 않는다 — 환경변수 DB_TOOL_KEY(24자 이상)와 같을 때만 실행, 없으면 항상 거부(.env.example 참고)
$__toolKey = (string) getenv('DB_TOOL_KEY');
if (strlen($__toolKey) < 24 || !hash_equals($__toolKey, (string) ($_GET['key'] ?? ''))) { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = file_get_contents(__DIR__ . '/../database/import.sql');
$stmts = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');
$ok = $err = 0;
foreach ($stmts as $s) { try { $pdo->exec($s); $ok++; } catch (\PDOException $e) { $err++; echo "⚠️ " . $e->getMessage() . "\n"; } }
@unlink(__FILE__);
echo "✅ import.sql — {$ok}개 성공, {$err}개 오류\n완료!\n";
