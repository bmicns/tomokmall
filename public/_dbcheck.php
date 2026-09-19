<?php
require __DIR__ . '/../app/bootstrap.php';
header('Content-Type: text/plain; charset=utf-8');

// 1. 전체 대/중분류 카테고리 구조
echo "=== 카테고리 구조 (대분류 > 중분류) ===\n";
$rows = db()->query(
    "SELECT r.id AS rid, r.name AS root,
            m.id AS mid, m.name AS mid_name,
            (SELECT COUNT(*) FROM products p WHERE p.category_id = m.id AND p.is_active=1) AS cnt
     FROM categories r
     JOIN categories m ON m.parent_id = r.id
     WHERE r.parent_id IS NULL
     ORDER BY r.id, m.id"
)->fetchAll();
foreach ($rows as $r) {
    echo "  [{$r['rid']}]{$r['root']} > [{$r['mid']}]{$r['mid_name']} ({$r['cnt']}개)\n";
}

// 2. description 비어있는 상품 비율
echo "\n=== description 입력 현황 ===\n";
$total   = db()->query("SELECT COUNT(*) FROM products WHERE is_active=1")->fetchColumn();
$hasdesc = db()->query("SELECT COUNT(*) FROM products WHERE is_active=1 AND description IS NOT NULL AND description <> ''")->fetchColumn();
echo "  전체: {$total}개 | description 있음: {$hasdesc}개 | 비어있음: " . ($total - $hasdesc) . "개\n";

// 3. 카테고리별 대표 상품 샘플 (spec 포함)
echo "\n=== 소분류별 상품 샘플 ===\n";
$cats = db()->query(
    "SELECT c.id, c.name, r.name AS root_name
     FROM categories c
     JOIN categories parent ON parent.id = c.parent_id
     JOIN categories r ON r.id = parent.parent_id
     WHERE c.parent_id IS NOT NULL
       AND (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.is_active=1) > 0
     ORDER BY c.id
     LIMIT 60"
)->fetchAll();

foreach ($cats as $cat) {
    $sample = db()->prepare(
        "SELECT name, spec FROM products WHERE category_id = :id AND is_active=1 ORDER BY id LIMIT 1"
    );
    $sample->execute(['id' => $cat['id']]);
    $p = $sample->fetch();
    echo "  [{$cat['root_name']}>{$cat['name']}] {$p['name']} | {$p['spec']}\n";
}
