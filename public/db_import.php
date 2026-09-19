<?php
declare(strict_types=1);
if (($_GET['key'] ?? '') !== 'tomok6385!') { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$sql = file_get_contents(__DIR__ . '/../database/import.sql');
$stmts = array_filter(array_map('trim', explode(';', $sql)), fn($s) => $s !== '');
$ok = $err = 0;
foreach ($stmts as $s) { try { $pdo->exec($s); $ok++; } catch (\PDOException $e) { $err++; echo "⚠️ " . $e->getMessage() . "\n"; } }
@unlink(__FILE__);
echo "✅ import.sql — {$ok}개 성공, {$err}개 오류\n완료!\n";
