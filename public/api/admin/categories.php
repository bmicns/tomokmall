<?php
declare(strict_types=1);
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

require_admin();

$method = $_SERVER['REQUEST_METHOD'];

try {
    if ($method === 'GET') {
        $rootId = (int)($_GET['root_id'] ?? 0);

        if (!$rootId) {
            // 대분류(최상위) 카테고리 목록
            $roots = db()->query(
                "SELECT id, name, sort_order, is_active
                 FROM categories
                 WHERE parent_id IS NULL
                 ORDER BY sort_order, id"
            )->fetchAll();
            echo json_encode(['ok' => true, 'roots' => $roots]);
            exit;
        }

        // 특정 대분류 하위 중분류 + 소분류 조회
        $midSt = db()->prepare(
            "SELECT id, name, sort_order, is_active
             FROM categories
             WHERE parent_id = ?
             ORDER BY sort_order, id"
        );
        $midSt->execute([$rootId]);
        $mids = $midSt->fetchAll();

        $result = [];
        foreach ($mids as $mid) {
            $subSt = db()->prepare(
                "SELECT id, name, sort_order, is_active
                 FROM categories
                 WHERE parent_id = ?
                 ORDER BY sort_order, id"
            );
            $subSt->execute([$mid['id']]);
            $result[] = array_merge($mid, ['subs' => $subSt->fetchAll()]);
        }

        echo json_encode(['ok' => true, 'categories' => $result]);
        exit;
    }

    // POST
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';

    switch ($action) {
        case 'create':
            $name     = trim($body['name'] ?? '');
            $parentId = (int)($body['parent_id'] ?? 0);
            $sort     = (int)($body['sort_order'] ?? 0);
            if (!$name)     throw new \InvalidArgumentException('이름 필수');
            if (!$parentId) throw new \InvalidArgumentException('parent_id 필수');
            $st = db()->prepare(
                "INSERT INTO categories (name, parent_id, sort_order, is_active) VALUES (?, ?, ?, 1)"
            );
            $st->execute([$name, $parentId, $sort]);
            echo json_encode(['ok' => true, 'id' => (int)db()->lastInsertId()]);
            break;

        case 'update':
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id 필수');
            $name   = trim($body['name'] ?? '');
            $sort   = (int)($body['sort_order'] ?? 0);
            $active = isset($body['is_active']) ? (int)$body['is_active'] : null;
            if ($name !== '') {
                db()->prepare("UPDATE categories SET name=?, sort_order=? WHERE id=?")
                    ->execute([$name, $sort, $id]);
            }
            if ($active !== null) {
                db()->prepare("UPDATE categories SET is_active=? WHERE id=?")
                    ->execute([$active, $id]);
            }
            echo json_encode(['ok' => true]);
            break;

        case 'toggle':
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id 필수');
            db()->prepare("UPDATE categories SET is_active = 1 - is_active WHERE id=?")
                ->execute([$id]);
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
