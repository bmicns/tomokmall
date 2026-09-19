<?php
declare(strict_types=1);
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

require_admin();

$method = $_SERVER['REQUEST_METHOD'];

try {
    // GET: 제품 목록
    if ($method === 'GET') {
        $catId  = (int)($_GET['cat_id'] ?? 0);
        $q      = trim($_GET['q'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $status = $_GET['status'] ?? 'active';
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $where  = [];
        $params = [];

        if ($status === 'active') {
            $where[] = 'p.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'p.is_active = 0';
        }
        // 'all' → is_active 조건 없음

        if ($catId) {
            $where[]          = 'p.category_id = :cat_id';
            $params['cat_id'] = $catId;
        }
        if ($q !== '') {
            $where[]     = '(p.name LIKE :q OR p.spec LIKE :q2 OR p.brand LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = "%{$q}%";
        }

        $whereStr = $where ? implode(' AND ', $where) : '1=1';

        $cntSt = db()->prepare("SELECT COUNT(*) AS c FROM products p WHERE {$whereStr}");
        $cntSt->execute($params);
        $total = (int)($cntSt->fetch()['c'] ?? 0);

        $sql = "SELECT p.id, p.name, p.brand, p.spec, p.price_b2b, p.price_b2c,
                       p.image_url, p.is_active, p.category_id,
                       c.name AS cat_name
                FROM products p
                JOIN categories c ON c.id = p.category_id
                WHERE {$whereStr}
                ORDER BY p.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $st = db()->prepare($sql);
        $st->execute($params);
        $products = $st->fetchAll();

        echo json_encode(['ok' => true, 'products' => $products, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        exit;
    }

    // POST: create / update / delete / bulk_toggle
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    switch ($action) {
        case 'create':
            $name = trim($body['name'] ?? '');
            if (!$name) throw new \InvalidArgumentException('제품명 필수');
            $catId = (int)($body['category_id'] ?? 0);
            if (!$catId) throw new \InvalidArgumentException('카테고리 필수');

            $st = db()->prepare(
                "INSERT INTO products (category_id, name, brand, spec, price_b2b, price_b2c, image_url, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, 1)"
            );
            $st->execute([
                $catId,
                $name,
                trim($body['brand'] ?? '') ?: null,
                trim($body['spec']  ?? '') ?: null,
                (int)($body['price_b2b'] ?? 0),
                (int)($body['price_b2c'] ?? 0),
                trim($body['image_url'] ?? '') ?: null,
            ]);
            echo json_encode(['ok' => true, 'id' => (int)db()->lastInsertId()]);
            break;

        case 'update':
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id 필수');
            $name = trim($body['name'] ?? '');
            if (!$name) throw new \InvalidArgumentException('제품명 필수');

            $st = db()->prepare(
                "UPDATE products SET category_id=?, name=?, brand=?, spec=?,
                 price_b2b=?, price_b2c=?, image_url=?, is_active=?
                 WHERE id=?"
            );
            $st->execute([
                (int)($body['category_id'] ?? 0),
                $name,
                trim($body['brand'] ?? '') ?: null,
                trim($body['spec']  ?? '') ?: null,
                (int)($body['price_b2b'] ?? 0),
                (int)($body['price_b2c'] ?? 0),
                trim($body['image_url'] ?? '') ?: null,
                (int)($body['is_active'] ?? 1),
                $id,
            ]);
            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id 필수');
            db()->prepare("UPDATE products SET is_active=0 WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        case 'bulk_toggle':
            $ids = array_map('intval', $body['ids'] ?? []);
            $val = (int)($body['is_active'] ?? 0);
            if (!$ids) throw new \InvalidArgumentException('ids 필수');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("UPDATE products SET is_active={$val} WHERE id IN ({$placeholders})")
                ->execute($ids);
            echo json_encode(['ok' => true]);
            break;

        default:
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => '알 수 없는 action']);
    }
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
