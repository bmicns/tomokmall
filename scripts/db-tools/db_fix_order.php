<?php
declare(strict_types=1);
if (($_GET['key'] ?? '') !== 'tomok6385!') { http_response_code(403); exit('403'); }
$config = require __DIR__ . '/../app/config.php';
$db = $config['db'];
$pdo = new PDO("mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}", $db['user'], $db['pass']);

// 현재 순서 확인
$rows = $pdo->query("SELECT id, name, sort_order FROM categories WHERE is_active=1 AND parent_id IS NULL ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);
echo "현재 순서:\n";
foreach ($rows as $i => $r) {
    echo ($i+1) . ". {$r['name']} (sort_order={$r['sort_order']}, id={$r['id']})\n";
}

// 1번과 5번 sort_order 교환
if (count($rows) >= 5) {
    $a = $rows[0]; // 1번 카드
    $b = $rows[4]; // 5번 카드
    $pdo->prepare("UPDATE categories SET sort_order = ? WHERE id = ?")->execute([$b['sort_order'], $a['id']]);
    $pdo->prepare("UPDATE categories SET sort_order = ? WHERE id = ?")->execute([$a['sort_order'], $b['id']]);
    echo "\n✅ {$a['name']} ↔ {$b['name']} 순서 교환 완료";
} else {
    echo "\n❌ 카테고리 5개 미만";
}
@unlink(__FILE__);
