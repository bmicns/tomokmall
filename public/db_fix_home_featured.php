<?php
declare(strict_types=1);
if (($_GET['key'] ?? '') !== 'tomok6385!') { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass']);

$pdo->exec("CREATE TABLE IF NOT EXISTS home_featured (
    id         TINYINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_name  VARCHAR(100) NOT NULL,
    sort_order TINYINT UNSIGNED DEFAULT 0,
    is_active  TINYINT(1) DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// 기존 데이터 있으면 건너뜀
if ((int) $pdo->query("SELECT COUNT(*) FROM home_featured")->fetchColumn() === 0) {
    $items = ['플륨관','수로관','옹벽블록','블록','벽돌','그레이팅','돌망태','PE이중벽관'];
    $st = $pdo->prepare("INSERT INTO home_featured (item_name, sort_order) VALUES (?, ?)");
    foreach ($items as $i => $name) {
        $st->execute([$name, $i]);
    }
}

echo "✅ home_featured 테이블 준비 완료";
