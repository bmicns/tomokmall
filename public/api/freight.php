<?php
declare(strict_types=1);
/**
 * GET /api/freight.php?product_id={id}&dest={시도}&qty={수량}
 * 상품 1종의 예상 운임(거리×톤급) 산출.
 *
 * 인증: 불필요(운임 시세는 민감정보 아님). 검증: id/qty 정수, dest는 시도 화이트리스트.
 * 출처: 화물중개 콜업체 공시 시세(신뢰도 中) — 응답에 "예상·참고" 단서 포함.
 */

require __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only']);
    exit;
}

$productId = (int) ($_GET['product_id'] ?? 0);
$dest      = trim((string) ($_GET['dest'] ?? ''));
$qty       = max(1, min(99999, (int) ($_GET['qty'] ?? 1)));

if ($productId <= 0 || !isset(FREIGHT_SIDO_COORDS[$dest])) {
    echo json_encode(['ok' => false, 'error' => '도착 지역과 상품을 확인해 주세요.']);
    exit;
}

$pdo = db();
if (!$pdo) {
    echo json_encode(['ok' => false, 'error' => 'DB 연결 불가']);
    exit;
}

try {
    // 상품 + 카테고리 대/중분류(출하권역 판정용)
    $st = $pdo->prepare(
        'SELECT p.spec, p.name,
                mid.name  AS mid_name,
                root.name AS root_name
         FROM products p
         JOIN categories c        ON c.id   = p.category_id
         LEFT JOIN categories mid ON mid.id = c.parent_id
         LEFT JOIN categories root ON root.id = mid.parent_id
         WHERE p.id = :id AND p.is_active = 1'
    );
    $st->execute([':id' => $productId]);
    $p = $st->fetch();
} catch (\Throwable $e) {
    echo json_encode(['ok' => false, 'error' => '조회 실패']);
    exit;
}

if (!$p) {
    echo json_encode(['ok' => false, 'error' => '상품을 찾을 수 없습니다.']);
    exit;
}

// 단위중량(kg) 추출 (spec의 '중량: 950kg')
$sp     = parse_spec_field((string) $p['spec']);
$unitKg = 0.0;
if (preg_match('/([0-9]+(?:\.[0-9]+)?)\s*kg/i', (string) $sp['weight'], $m)) {
    $unitKg = (float) $m[1];
}
if ($unitKg <= 0) {
    echo json_encode(['ok' => false, 'error' => '이 상품은 중량 정보가 없어 운임을 예상할 수 없습니다. 견적요청 시 안내드립니다.']);
    exit;
}

$origin  = freight_origin_sido($p['root_name'] ?? null, $p['mid_name'] ?? null);
$totalKg = $unitKg * $qty;
$est     = freight_estimate($origin, $dest, $totalKg);

if (!$est['ok']) {
    echo json_encode(['ok' => false, 'error' => $est['error'] ?? '운임 산출 불가']);
    exit;
}

echo json_encode([
    'ok'       => true,
    'origin'   => $origin,
    'dest'     => $dest,
    'km'       => $est['km'],
    'over400'  => $est['over400'],
    'unit_kg'  => $unitKg,
    'qty'      => $qty,
    'total_kg' => $totalKg,
    'trucks'   => $est['trucks'],   // [{ton,count,each,sub}]
    'freight'  => $est['total'],    // 예상 운임(원, 부가세별도·지게차상하차·편도)
], JSON_UNESCAPED_UNICODE);
