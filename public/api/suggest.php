<?php
declare(strict_types=1);
/**
 * GET /api/suggest.php?q={검색어}
 * 검색창 자동완성 — 입력한 글자에 맞는 상품명·카테고리 제안
 * 응답: 상품 {name, cnt, specs(규격 종류 수 — '규격:' 값 기준), img(첫 사진)},
 *       카테고리 {name, path(최상위 › … › 자기, 전체 깊이)}
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
if (mb_strlen($q) > 40) {
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

$pdo = db();
if (!$pdo) {
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

// ── 빈 입력(포커스): 인기 카테고리 바로가기 ──────────────────────────
//    (최근검색어는 클라이언트 localStorage에서 병합 — 백엔드 무부하)
if ($q === '') {
    $items   = [];
    $popular = [];
    // 인기검색어(집계 상위) — 최근검색어(클라 localStorage)와 함께 표시됨
    try {
        foreach (popular_searches(10) as $term) {
            $popular[] = [
                'type' => 'popular',
                'name' => $term,
                'url'  => '/products.php?q=' . rawurlencode($term),
            ];
        }
    } catch (\Throwable $e) {}
    // 인기 카테고리 바로가기
    try {
        $cst = $pdo->query(
            "SELECT c.id, c.name, COUNT(p.id) AS cnt
             FROM categories c
             JOIN products p ON p.category_id = c.id AND p.is_active = 1
             WHERE c.is_active = 1
             GROUP BY c.id, c.name
             ORDER BY cnt DESC, c.name
             LIMIT 8"
        );
        foreach ($cst->fetchAll() as $r) {
            $items[] = [
                'type' => 'category',
                'name' => $r['name'],
                'url'  => '/products.php?category=' . (int) $r['id'],
            ];
        }
    } catch (\Throwable $e) {}
    echo json_encode(['ok' => true, 'context' => 'empty', 'popular' => $popular, 'items' => $items], JSON_UNESCAPED_UNICODE);
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
                LIMIT 20";
    $st      = $pdo->prepare($sql);
    $params  = $likeVals;
    $params[] = $q . '%'; // 앞글자 일치 부스트
    $st->execute($params);
    $prodRows = $st->fetchAll();

    // 규격 종류 수·사진: 고른 이름들의 상품 행을 한 번에 읽어 PHP에서 계산(spec은 '규격: … | 중량: …' 복합 문자열)
    //   이름 비교 키 = 소문자 + 끝 공백 제거 (GROUP BY name·IN 의 utf8mb4_unicode_ci 비교와 맞춤)
    $nameKey = static fn(string $n): string => mb_strtolower(rtrim($n));
    $specsBy = []; $imgBy = [];
    if ($prodRows) {
        $names = array_column($prodRows, 'name');
        $dst = $pdo->prepare(
            'SELECT name, spec, image_url FROM products
             WHERE is_active = 1 AND name IN (' . implode(',', array_fill(0, count($names), '?')) . ')
             ORDER BY id'
        );
        $dst->execute($names);
        foreach ($dst->fetchAll() as $d) {
            $k = $nameKey((string) $d['name']);
            $specsBy[$k][] = $d['spec'];
            if (!isset($imgBy[$k]) && (string) $d['image_url'] !== '') { $imgBy[$k] = (string) $d['image_url']; }
        }
    }
    foreach ($prodRows as $r) {
        $k = $nameKey((string) $r['name']);
        $items[] = [
            'type'  => 'product',
            'name'  => $r['name'],
            'cnt'   => (int) $r['cnt'],
            'specs' => spec_variant_count($specsBy[$k] ?? []),
            'img'   => $imgBy[$k] ?? '',
            'url'   => '/products.php?q=' . rawurlencode($r['name']),
        ];
    }

    // ── 카테고리 제안 (입력어 기준, 최대 10개) + 전체 분류 경로 ──
    //    활성 카테고리 전체 대상(활성 상품 수로 거르지 않음 — 전체 카테고리 정책)
    $cst = $pdo->prepare(
        "SELECT id, name FROM categories
         WHERE is_active = 1 AND name LIKE ?
         ORDER BY (name LIKE ?) DESC, name
         LIMIT 10"
    );
    $cst->execute(['%' . $q . '%', $q . '%']);
    $catRows = $cst->fetchAll();
    if ($catRows) {
        // 경로용 카테고리 트리(전체 245행 수준) 1회 조회 — 임의 깊이
        $tree = $pdo->query('SELECT id, parent_id, name FROM categories')->fetchAll(PDO::FETCH_UNIQUE);
        foreach ($catRows as $r) {
            $items[] = [
                'type' => 'category',
                'name' => $r['name'],
                'path' => category_path_label((int) $r['id'], $tree),
                'url'  => '/products.php?category=' . (int) $r['id'],
            ];
        }
    }
} catch (\Throwable $e) {
    error_log('[suggest] ' . $e->getMessage());
    echo json_encode(['ok' => true, 'items' => []]);
    exit;
}

// 하이라이트용 확장 용어(입력어 + 동의어) — 드롭다운이 동의어까지 강조
$terms = search_expand_terms($q);

echo json_encode(['ok' => true, 'items' => $items, 'terms' => $terms], JSON_UNESCAPED_UNICODE);
