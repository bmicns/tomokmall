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
 * 오류 응답: 입력 검증 실패(category_id·비활성 카테고리)도 HTTP 200 + {"ok":false,"error":…}
 *           (닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 JSON 문구가 사라짐 — quote.php 와 같은 방식)
 *           405(메서드)·503(DB 연결 실패)은 상태 코드 유지
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/cart.php';   // display_unit_price (화면 표시용 회원가)

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '허용되지 않는 메서드입니다.']);
    exit;
}

$categoryId = (int) ($_GET['category_id'] ?? 0);
if ($categoryId <= 0) {
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
$catStmt = $pdo->prepare('SELECT id, slug FROM categories WHERE id = :id AND is_active = 1');
$catStmt->execute(['id' => $categoryId]);
$cat = $catStmt->fetch();
if (!$cat) {
    echo json_encode(['ok' => false, 'error' => '유효하지 않은 카테고리입니다.']);
    exit;
}

// 상용콘크리트 반영 카테고리(app/syc_publish.php): 규격 필터를 이름표(폭 W·높이 H…)와 치수 칸 값으로 — 규격 원문 끝에 예전 코드가 붙어 있어
// 글자 나누기로는 이름을 못 붙임(추가 필드 axes·ax·opt, 케이토목 카테고리는 응답 그대로)
$sycAxes = (str_starts_with((string) ($cat['slug'] ?? ''), 'syc-') && spec_axes_ready()) ? spec_axes_for_category($categoryId) : [];
$axCols  = $sycAxes ? ', ' . implode(', ', array_unique(array_merge(SPEC_AXES_NEW_COLUMNS, spec_axes_extra_ready() ? SPEC_AXES_EXTRA_COLUMNS : []))) : '';

$stmt = $pdo->prepare(
    'SELECT id, category_id, name, spec, price_b2c, price_b2b, cost_price, stock_qty, image_url' . $axCols . '
     FROM products
     WHERE category_id = :cid AND is_active = 1
     ORDER BY name, id'
);
$stmt->execute(['cid' => $categoryId]);
$rows = $stmt->fetchAll();

$isCorporate = is_corporate_member();

$products = array_map(function ($row) use ($isCorporate, $sycAxes) {
    $item = [
        'id'        => (int)   $row['id'],
        'name'      =>         $row['name'],
        'spec'      =>         $row['spec'],
        'price_b2c' => (int)   $row['price_b2c'],
        'stock_qty' => (int)   $row['stock_qty'],
        'image_url' =>         $row['image_url'] ?? null,
        // 기업회원 메인 표시용(추가 필드 — 기존 호출자 영향 없음): 규격 치수(×)·단위
        'size'      => spec_size_label((string) $row['spec']),
        'unit'      => unit_display_label(parse_spec_field((string) $row['spec'])['unit']),
        // 화면 표시 가격(추가 필드): 판매가와 로그인 등급의 회원가(상품 상세와 같은 규칙, 0=견적문의). 매입가는 내보내지 않음
        'price_list'   => (int) $row['price_b2c'],
        'price_member' => display_unit_price($row),
    ];
    if ($sycAxes) {
        $item['ax'] = [];
        foreach ($sycAxes as $a) {
            $v = spec_axes_row_value($row, (string) $a['axis_key']);
            if ($v !== null) { $item['ax'][$a['axis_key']] = spec_axis_value_display($a, $v); }
        }
        if (trim((string) ($row['spec_code'] ?? '')) !== '') { $item['ax']['_code'] = (string) $row['spec_code']; }
        $item['opt'] = spec_option_display((string) ($row['spec_option'] ?? ''));
    }
    if ($isCorporate) {
        // price_b2b = 0이면 price_b2c로 폴백
        $b2b = (int) $row['price_b2b'];
        $item['price_b2b'] = $b2b > 0 ? $b2b : (int) $row['price_b2c'];
    }
    return $item;
}, $rows);

$out = ['ok' => true, 'products' => $products];
if ($sycAxes) {
    $out['axes'] = [['key' => '_code', 'label' => '모델 코드']];
    foreach ($sycAxes as $a) {
        $unit = spec_axis_unit_label($a);
        $out['axes'][] = ['key' => (string) $a['axis_key'], 'label' => spec_axis_title($a) . ($unit !== '' ? ' ' . $unit : '')];
    }
}
echo json_encode($out, JSON_UNESCAPED_UNICODE);
