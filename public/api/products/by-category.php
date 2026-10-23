<?php
declare(strict_types=1);
/**
 * GET /api/products/by-category.php?category_id={id}
 * 즉시견적 드롭다운용 — 카테고리별 상품·규격·단가 반환
 *
 * 인증: 불필요 (로그인 상태에 따라 응답 내용 다름)
 * 검증: category_id 양의 정수 + is_active=1 카테고리만 허용
 * 보안: 기업회원만 price_b2b 노출, 미로그인/일반회원은 price_b2c만 반환
 * 성능: 필요한 컬럼만 SELECT, 단일 쿼리
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$categoryId = (int) ($_GET['category_id'] ?? 0);
if ($categoryId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'category_id가 유효하지 않습니다.']);
    exit;
}

$pdo = db();
if (!$pdo) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다.']);
    exit;
}

// 활성화된 카테고리인지 확인
$catStmt = $pdo->prepare('SELECT id FROM categories WHERE id = :id AND is_active = 1');
$catStmt->execute(['id' => $categoryId]);
if (!$catStmt->fetch()) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '유효하지 않은 카테고리입니다.']);
    exit;
}

$stmt = $pdo->prepare(
    'SELECT id, name, spec, price_b2c, price_b2b, stock_qty, image_url
     FROM products
     WHERE category_id = :cid AND is_active = 1
     ORDER BY name, id'
);
$stmt->execute(['cid' => $categoryId]);
$rows = $stmt->fetchAll();

$isCorporate = is_corporate_member();

$products = array_map(function ($row) use ($isCorporate) {
    $item = [
        'id'        => (int)   $row['id'],
        'name'      =>         $row['name'],
        'spec'      =>         $row['spec'],
        'price_b2c' => (int)   $row['price_b2c'],
        'stock_qty' => (int)   $row['stock_qty'],
        'image_url' =>         $row['image_url'] ?? null,
        // 기업회원 메인 표시용(추가 필드 — 기존 호출자 영향 없음): 규격 치수(×)·단위
        'size'      => spec_size_label((string) $row['spec']),
        'unit'      => parse_spec_field((string) $row['spec'])['unit'],
    ];
    if ($isCorporate) {
        // price_b2b = 0이면 price_b2c로 폴백
        $b2b = (int) $row['price_b2b'];
        $item['price_b2b'] = $b2b > 0 ? $b2b : (int) $row['price_b2c'];
    }
    return $item;
}, $rows);

echo json_encode(['ok' => true, 'products' => $products], JSON_UNESCAPED_UNICODE);
