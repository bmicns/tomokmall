<?php
declare(strict_types=1);
/**
 * GET /api/freight-cart.php?dest={시도}&items={product_id}:{수량},{product_id}:{수량},...
 * 견적 화면(여러 품목)의 예상 운임 산출 — 품목 총중량으로 1회 배차(상품별 운임 단순 합산 아님).
 *
 * 인증: 불필요(운임 시세·상품 중량은 민감정보 아님, 읽기 전용 — DB 변경 없음).
 * 검증: dest 는 시도 화이트리스트, items 는 최대 50개·product_id 양의 정수·수량 1~9999·중복 금지.
 * 응답 status: ok | missing_weight(중량 없는 품목 포함 → 금액 미표시) | negotiate(제주·400km 초과 → 별도 협의)
 * 출처: 화물중개 콜업체 공시 시세(신뢰도 中) — 부가세 별도·지게차 상하차·편도 기준 "예상(참고)".
 */

require __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const FREIGHT_CART_MAX_ITEMS = 50;

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'GET only']);
    exit;
}

$dest     = trim((string) ($_GET['dest'] ?? ''));
$rawItems = trim((string) ($_GET['items'] ?? ''));

if (!isset(FREIGHT_SIDO_COORDS[$dest])) {
    echo json_encode(['ok' => false, 'error' => '배송 지역을 확인해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

// items 파싱: "12:2,34:4"
$qtyById = [];
if ($rawItems !== '' && strlen($rawItems) <= 1000) {
    foreach (explode(',', $rawItems) as $pair) {
        if (!preg_match('/^([1-9][0-9]{0,9}):([1-9][0-9]{0,3})$/', trim($pair), $m)) {
            $qtyById = [];
            break;
        }
        $pid = (int) $m[1];
        if (isset($qtyById[$pid])) { $qtyById = []; break; } // 중복 product_id 거부
        $qtyById[$pid] = (int) $m[2];                         // 정규식으로 1~9999 보장
    }
}
if (!$qtyById || count($qtyById) > FREIGHT_CART_MAX_ITEMS) {
    echo json_encode(['ok' => false, 'error' => '견적 품목 정보를 확인해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
if (!$pdo) {
    echo json_encode(['ok' => false, 'error' => 'DB 연결 불가'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    // 상품 중량(spec) + 카테고리 대/중분류(출하권역 판정용) — IN(...) 단일 쿼리
    $ids = array_keys($qtyById);
    $ph  = implode(',', array_fill(0, count($ids), '?'));
    $st  = $pdo->prepare(
        "SELECT p.id, p.name, p.spec,
                mid.name  AS mid_name,
                root.name AS root_name
         FROM products p
         JOIN categories c         ON c.id    = p.category_id
         LEFT JOIN categories mid  ON mid.id  = c.parent_id
         LEFT JOIN categories root ON root.id = mid.parent_id
         WHERE p.id IN ({$ph}) AND p.is_active = 1"
    );
    $st->execute($ids);
    $rows = $st->fetchAll();
} catch (\Throwable $e) {
    error_log('[freight-cart] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => '조회 실패'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (count($rows) !== count($qtyById)) {
    echo json_encode(['ok' => false, 'error' => '판매 중이 아닌 품목이 포함되어 있습니다. 화면을 새로고침해 주세요.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$lines        = [];
$missingNames = [];
foreach ($rows as $p) {
    $unitKg = freight_unit_kg(parse_spec_field((string) $p['spec'])['weight']);
    if ($unitKg <= 0) { $missingNames[] = (string) $p['name']; }
    $lines[] = [
        'unit_kg' => $unitKg,
        'qty'     => $qtyById[(int) $p['id']],
        'origin'  => freight_origin_sido($p['root_name'] ?? null, $p['mid_name'] ?? null),
    ];
}

$est = freight_estimate_lines($lines, $dest);
if ($est['status'] === 'invalid') {
    echo json_encode(['ok' => false, 'error' => '운임을 산출할 수 없습니다.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$out = ['ok' => true, 'status' => $est['status'], 'dest' => $dest];
if ($est['status'] === 'ok') {
    $out += [
        'freight'  => $est['freight'],   // 예상 운임(원, 부가세 별도·지게차 상하차·편도)
        'total_kg' => $est['total_kg'],
        'km'       => $est['km'],
        'trucks'   => $est['trucks'],    // [{ton,count,each,sub}]
        'origins'  => $est['origins'],
    ];
} elseif ($est['status'] === 'missing_weight') {
    $out += ['missing' => $est['missing'], 'missing_names' => $missingNames];
}

echo json_encode($out, JSON_UNESCAPED_UNICODE);
