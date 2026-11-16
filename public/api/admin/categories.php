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
                "SELECT id, name, sort_order, is_active,
                        COALESCE(discount_customer,0) AS discount_customer,
                        COALESCE(discount_corporate,0) AS discount_corporate
                 FROM categories
                 WHERE parent_id IS NULL
                 ORDER BY sort_order, id"
            )->fetchAll();
            echo json_encode(['ok' => true, 'roots' => $roots]);
            exit;
        }

        // 특정 대분류 하위 중분류 + 소분류 조회
        $midSt = db()->prepare(
            "SELECT id, name, sort_order, is_active,
                    COALESCE(discount_customer,0) AS discount_customer,
                    COALESCE(discount_corporate,0) AS discount_corporate
             FROM categories
             WHERE parent_id = ?
             ORDER BY sort_order, id"
        );
        $midSt->execute([$rootId]);
        $mids = $midSt->fetchAll();

        $result = [];
        foreach ($mids as $mid) {
            $subSt = db()->prepare(
                "SELECT id, name, sort_order, is_active,
                        COALESCE(discount_customer,0) AS discount_customer,
                        COALESCE(discount_corporate,0) AS discount_corporate
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

        // 상위 분류 바꾸기(사용자 결정 2026-11-16 — 옛 대분류 8개를 '기타' 아래 중분류로). 대상 상위는 대분류만, 3단계(대·중·품명)를 넘지 않게.
        // 마진율은 그대로(자기 값이 없으면 새 상위 값 상속 — app/pricing.php category_discount_map). 관리자 기존 가격 관리 기능(parent_id)
        case 'move':
            $id = (int)($body['id'] ?? 0);
            $to = (int)($body['parent_id'] ?? 0);
            if (!$id || !$to || $id === $to) throw new \InvalidArgumentException('옮길 분류와 상위 대분류를 골라 주세요.');
            $dst = db()->prepare('SELECT id, parent_id FROM categories WHERE id = ?');
            $dst->execute([$to]);
            $dstRow = $dst->fetch();
            if (!$dstRow || $dstRow['parent_id'] !== null) throw new \InvalidArgumentException('상위는 대분류만 고를 수 있습니다.');
            $deep = db()->prepare('SELECT COUNT(*) FROM categories c JOIN categories k ON k.parent_id = c.id WHERE c.parent_id = ?');
            $deep->execute([$id]);
            if ((int)$deep->fetchColumn() > 0) throw new \InvalidArgumentException('하위가 2단계 있는 분류는 대분류 아래로 옮길 수 없습니다(3단계 초과).');
            db()->prepare('UPDATE categories SET parent_id = ? WHERE id = ?')->execute([$to, $id]);
            echo json_encode(['ok' => true]);
            break;

        case 'toggle':
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id 필수');
            db()->prepare("UPDATE categories SET is_active = 1 - is_active WHERE id=?")
                ->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        case 'discount':
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id 필수');
            // 개인(discount_customer)·기업(discount_corporate) 매입가 대비 마진율(%) 0~255. 전달된 필드만 갱신.
            $sets = [];
            $params = [];
            $resp = ['ok' => true];
            foreach (['discount_customer', 'discount_corporate'] as $field) {
                if (array_key_exists($field, $body)) {
                    $rate = max(0, min(255, (int)$body[$field]));
                    $sets[] = "{$field}=?";
                    $params[] = $rate;
                    $resp[$field] = $rate;
                }
            }
            if (!$sets) throw new \InvalidArgumentException('마진율 필드 필수');
            $params[] = $id;
            db()->prepare("UPDATE categories SET " . implode(', ', $sets) . " WHERE id=?")
                ->execute($params);
            echo json_encode($resp);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => '알 수 없는 action']);
    }
} catch (\InvalidArgumentException $e) {
    // 입력 검증 실패: HTTP 200 + ok:false (닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 JSON 문구가 사라짐)
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    // 내부 오류 문구(SQL 등)는 화면에 보내지 않고 서버 로그에만 남긴다
    error_log('[api/admin/categories] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
}
