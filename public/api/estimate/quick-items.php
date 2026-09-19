<?php
declare(strict_types=1);
/**
 * POST /api/estimate/quick-items.php
 * 즉시견적 세션 품목 CRUD
 *
 * action: add | update | remove | get | clear
 *
 * 인증: 기업회원 로그인 필요 (require_corporate)
 * 보안: 단가는 서버 DB 기준 결정 — 클라이언트 단가 신뢰 금지
 * 성능: 품목 조회 시 IN(...) 단일 쿼리로 N+1 방지
 * 세션 키: $_SESSION['quick_items'] = [product_id => quantity]
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

require_corporate();

$body      = json_decode(file_get_contents('php://input'), true) ?? [];
$action    = (string) ($body['action'] ?? '');
$productId = (int) ($body['product_id'] ?? 0);
$quantity  = (int) ($body['quantity']  ?? 0);

$allowedActions = ['add', 'update', 'remove', 'get', 'clear'];
if (!in_array($action, $allowedActions, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '유효하지 않은 action입니다.']);
    exit;
}

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// 세션에서 즉시견적 품목 읽기
$items = $_SESSION['quick_items'] ?? [];  // [product_id => quantity]

// ── action 처리 ─────────────────────────────────────────────────────────────

if ($action === 'clear') {
    $_SESSION['quick_items'] = [];
    $items = [];
} elseif ($action === 'get') {
    // 읽기 전용 — 세션 변경 없음
} elseif ($action === 'remove') {
    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'product_id가 유효하지 않습니다.']);
        exit;
    }
    unset($items[$productId]);
    $_SESSION['quick_items'] = $items;
} elseif ($action === 'add' || $action === 'update') {
    if ($productId <= 0) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'product_id가 유효하지 않습니다.']);
        exit;
    }

    // update action에서 quantity=0이면 삭제
    if ($action === 'update' && $quantity === 0) {
        unset($items[$productId]);
        $_SESSION['quick_items'] = $items;
    } else {
        if ($quantity < 1 || $quantity > 9999) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'quantity는 1~9999 범위여야 합니다.']);
            exit;
        }

        // 상품 존재 + is_active 서버 검증
        $pStmt = $pdo->prepare(
            'SELECT id FROM products WHERE id = :id AND is_active = 1'
        );
        $pStmt->execute(['id' => $productId]);
        if (!$pStmt->fetch()) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => '존재하지 않거나 판매 중단된 상품입니다.']);
            exit;
        }

        $items[$productId] = $quantity;
        $_SESSION['quick_items'] = $items;
    }
}

// ── 현재 품목 목록 조회 + 단가 서버 결정 ────────────────────────────────────

echo json_encode(quick_items_response($pdo, $items), JSON_UNESCAPED_UNICODE);

// ─────────────────────────────────────────────────────────────────────────────

/**
 * quick_items 세션을 DB 조회하여 응답 배열 생성
 * price_b2b > 0 ? price_b2b : price_b2c 서버에서 결정
 *
 * @param array<int,int> $items
 */
function quick_items_response(\PDO $pdo, array $items): array
{
    if (empty($items)) {
        return ['ok' => true, 'items' => [], 'total' => 0];
    }

    $ids = array_map('intval', array_keys($items));
    $ph  = implode(',', array_fill(0, count($ids), '?'));

    $stmt = $pdo->prepare(
        "SELECT id, name, spec, price_b2c, price_b2b
         FROM products
         WHERE id IN ({$ph}) AND is_active = 1"
    );
    $stmt->execute($ids);
    $rows = $stmt->fetchAll();

    $result = [];
    $total  = 0;

    foreach ($rows as $row) {
        $pid       = (int) $row['id'];
        $qty       = (int) ($items[$pid] ?? 0);
        $b2b       = (int) $row['price_b2b'];
        $unitPrice = $b2b > 0 ? $b2b : (int) $row['price_b2c'];
        $subtotal  = $unitPrice * $qty;
        $total    += $subtotal;

        $result[] = [
            'product_id' => $pid,
            'name'       => $row['name'],
            'spec'       => $row['spec'],
            'quantity'   => $qty,
            'unit_price' => $unitPrice,
            'subtotal'   => $subtotal,
        ];
    }

    return ['ok' => true, 'items' => $result, 'total' => $total];
}
