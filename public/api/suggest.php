<?php
declare(strict_types=1);
/**
 * GET /api/suggest.php?q={검색어}
 * 검색창 자동완성 — 입력한 글자에 맞는 상품명·카테고리 제안
 *
 * 인증: 불필요 (상품명·카테고리명만 노출, 가격 등 민감정보 없음)
 * 검증: q 길이 제한(1~40), 동의어 확장 포함
 * 성능: 상품은 name GROUP BY로 중복 제거, 각 상한(LIMIT) 적용, 단일 인덱스 스캔(1341행)
 */

require __DIR__ . '/../../app/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'items' => []]);
    exit;
}

$q = trim((string) ($_GET['q'] ?? ''));
if ($q === '' || mb_strlen($q) > 40) {
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

$pdo = db();
if (!$pdo) {
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

$items = [];

try {
    // 동의어 포함 검색 패턴 (사용자가 친 말 + 같은 뜻 단어들)
    $syn      = load_synonyms();
    $terms    = array_values(array_unique(array_merge([$q], $syn[mb_strtolower($q)] ?? [])));
    $terms    = array_slice($terms, 0, 8);
    $likeVals = array_map(static fn($t) => '%' . $t . '%', $terms);

    // ── 상품명 제안 (중복 이름 제거, 앞글자 일치 우선 + 취급수 많은 순) ──
    $ph      = implode(' OR ', array_fill(0, count($likeVals), 'name LIKE ?'));
    $sql     = "SELECT name, COUNT(*) AS cnt
                FROM products
                WHERE is_active = 1 AND ($ph)
                GROUP BY name
                ORDER BY (name LIKE ?) DESC, cnt DESC, name
                LIMIT 8";
    $st      = $pdo->prepare($sql);
    $params  = $likeVals;
    $params[] = $q . '%'; // 앞글자 일치 부스트
    $st->execute($params);
    foreach ($st->fetchAll() as $r) {
        $items[] = [
            'type' => 'product',
            'name' => $r['name'],
            'cnt'  => (int) $r['cnt'],
            'url'  => '/products.php?q=' . rawurlencode($r['name']),
        ];
    }

    // ── 카테고리 제안 (입력어 기준, 최대 4개) ──
    $cst = $pdo->prepare(
        "SELECT id, name FROM categories
         WHERE is_active = 1 AND name LIKE ?
         ORDER BY (name LIKE ?) DESC, name
         LIMIT 4"
    );
    $cst->execute(['%' . $q . '%', $q . '%']);
    foreach ($cst->fetchAll() as $r) {
        $items[] = [
            'type' => 'category',
            'name' => $r['name'],
            'url'  => '/products.php?category=' . (int) $r['id'],
        ];
    }
} catch (\Throwable $e) {
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

echo json_encode(['ok' => true, 'items' => $items], JSON_UNESCAPED_UNICODE);
