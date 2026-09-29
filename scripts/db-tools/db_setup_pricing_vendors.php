<?php
declare(strict_types=1);
/**
 * 운영 반영: ② 등급별 차등 가격 + ① 공급업체(복수) 마이그레이션 & 데모데이터.
 *
 * 사용:  브라우저에서 실행. 기본은 dry-run(변경 없음, 진단만).
 *   확인:  /db-tools/db_setup_pricing_vendors.php?key=tomok-setup-2026
 *   실행:  /db-tools/db_setup_pricing_vendors.php?key=tomok-setup-2026&commit=1
 *
 * ⚠️ 실행 후 반드시 이 파일을 서버에서 삭제할 것(쓰기 스크립트).
 * ⚠️ 멱등: 여러 번 실행해도 안전(IF NOT EXISTS / INSERT IGNORE / 이미 배정된 상품 건너뜀).
 */

// 배치 위치가 달라도(db-tools 또는 웹루트 직하) bootstrap 을 찾도록 후보 경로 탐색.
$__boot = null;
foreach (['/../app/bootstrap.php', '/../../app/bootstrap.php'] as $__p) {
    if (is_file(__DIR__ . $__p)) { $__boot = __DIR__ . $__p; break; }
}
if (!$__boot) { http_response_code(500); exit("bootstrap.php 경로를 찾을 수 없습니다.\n"); }
require $__boot;
header('Content-Type: text/plain; charset=utf-8');

$KEY = 'tomok-setup-2026';
if (($_GET['key'] ?? '') !== $KEY) { http_response_code(403); exit("forbidden\n"); }

$commit = isset($_GET['commit']);
$pdo = db();
if (!$pdo) { exit("DB 연결 실패\n"); }

echo $commit ? "=== 실행 모드 (commit) ===\n\n" : "=== 진단 모드 (dry-run, 변경 없음) ===\n\n";

/* ─── 1. 스키마 ─────────────────────────────────────────── */
// MySQL은 ALTER ... ADD COLUMN IF NOT EXISTS 미지원 → information_schema로 존재 체크 후 추가.
$colExists = function (PDO $pdo, string $table, string $col): bool {
    $st = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $st->execute([$table, $col]);
    return (int) $st->fetchColumn() > 0;
};

echo "[1] 스키마 컬럼/테이블\n";
$addCols = [
    ['categories', 'discount_customer',  "ALTER TABLE categories ADD COLUMN discount_customer TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '개인회원 할인율(%) 하위상속'"],
    ['categories', 'discount_corporate', "ALTER TABLE categories ADD COLUMN discount_corporate TINYINT UNSIGNED NOT NULL DEFAULT 0 COMMENT '기업회원 할인율(%) 하위상속'"],
    ['products',   'cost_price',         "ALTER TABLE products ADD COLUMN cost_price INT UNSIGNED NOT NULL DEFAULT 0 COMMENT '공장도가(원가) 관리자전용'"],
];
foreach ($addCols as [$tbl, $col, $ddl]) {
    if ($colExists($pdo, $tbl, $col)) {
        echo "  이미있음: {$tbl}.{$col}\n";
    } elseif ($commit) {
        $pdo->exec($ddl); echo "  추가됨: {$tbl}.{$col}\n";
    } else {
        echo "  (예정) {$tbl}.{$col} 추가\n";
    }
}

$ddls = [
    "CREATE TABLE IF NOT EXISTS vendors (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        contact VARCHAR(80) NULL, phone VARCHAR(40) NULL, fax VARCHAR(40) NULL,
        email VARCHAR(160) NULL, region VARCHAR(60) NULL, address VARCHAR(255) NULL,
        memo TEXT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_vendors_active (is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS product_vendors (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        product_id INT UNSIGNED NOT NULL, vendor_id INT UNSIGNED NOT NULL,
        supply_price INT UNSIGNED NOT NULL DEFAULT 0, lead_days SMALLINT NOT NULL DEFAULT 0,
        is_primary TINYINT(1) NOT NULL DEFAULT 0, memo VARCHAR(200) NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uk_product_vendor (product_id, vendor_id),
        CONSTRAINT fk_pv_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE,
        CONSTRAINT fk_pv_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];
foreach ($ddls as $ddl) {
    if ($commit) { $pdo->exec($ddl); echo "  OK: " . substr(preg_replace('/\s+/', ' ', $ddl), 0, 60) . "...\n"; }
    else { echo "  (예정) " . substr(preg_replace('/\s+/', ' ', $ddl), 0, 60) . "...\n"; }
}

/* ─── 2. 업체 3~4곳 ─────────────────────────────────────── */
echo "\n[2] 공급업체 등록\n";
$vendors = [
    ['대성산업',        '경기 화성', '031-000-0001'],
    ['한길콘크리트',    '충북 청주', '043-000-0002'],
    ['동보건설자재',    '경기 김포', '031-000-0003'],
    ['삼정산업',        '전북 익산', '063-000-0004'],
];
if ($commit) {
    // vendors.name 에 UNIQUE 제약이 없으므로 INSERT IGNORE 로는 중복을 막지 못한다.
    // → 이름 존재를 확인해 없을 때만 삽입(멱등).
    $find = $pdo->prepare("SELECT id FROM vendors WHERE name = ? LIMIT 1");
    $vs   = $pdo->prepare("INSERT INTO vendors (name, region, phone, is_active) VALUES (?,?,?,1)");
    foreach ($vendors as $v) {
        $find->execute([$v[0]]);
        if (!$find->fetchColumn()) { $vs->execute($v); }
    }
}
$vrows = $commit ? $pdo->query("SELECT id, name FROM vendors ORDER BY id")->fetchAll() : [];
echo $commit ? ("  등록됨: " . implode(', ', array_map(fn($r) => "[{$r['id']}]{$r['name']}", $vrows)) . "\n")
             : ("  (예정) " . implode(', ', array_column($vendors, 0)) . "\n");

/* ─── 3. 상품에 복수 업체 랜덤 배정 (미배정 상품만) ───────── */
echo "\n[3] 상품↔업체 배정 (상품당 1~2곳, 미배정만)\n";
if ($commit && $vrows) {
    // 대량(1000+) 상품을 개별 쿼리로 돌면 PHP 실행시간 초과 위험 → 벌크 INSERT 2회로 처리.
    $vids = array_map('intval', array_column($vrows, 'id'));
    $n = count($vids);
    $elt = implode(',', $vids); // ELT는 1-based

    // 주거래(is_primary=1): 미배정 상품에 id 기반 결정적 배정
    $sqlPrimary =
        "INSERT INTO product_vendors (product_id, vendor_id, is_primary)
         SELECT p.id, ELT((p.id % {$n}) + 1, {$elt}), 1
         FROM products p
         WHERE p.is_active = 1
           AND NOT EXISTS (SELECT 1 FROM product_vendors pv WHERE pv.product_id = p.id)";
    $addedPrimary = $pdo->exec($sqlPrimary);

    // 2번째 업체(is_primary=0): id%3=0 이고 아직 1곳만 배정된 상품에. 같은 업체면 UNIQUE로 무시.
    $sqlSecond =
        "INSERT IGNORE INTO product_vendors (product_id, vendor_id, is_primary)
         SELECT p.id, ELT(((p.id + 1) % {$n}) + 1, {$elt}), 0
         FROM products p
         WHERE p.is_active = 1 AND p.id % 3 = 0
           AND (SELECT COUNT(*) FROM product_vendors pv WHERE pv.product_id = p.id) = 1";
    $addedSecond = $pdo->exec($sqlSecond);

    echo "  주거래 신규배정: {$addedPrimary}행, 2번째업체 추가: {$addedSecond}행\n";
} else {
    $cnt = (int)$pdo->query("SELECT COUNT(*) FROM products WHERE is_active=1")->fetchColumn();
    echo "  (예정) 활성 상품 {$cnt}개에 업체 배정(벌크 INSERT 2회)\n";
}

/* ─── 4. 데모 할인율 + 공장도가 ──────────────────────────── */
echo "\n[4] 데모 개인할인율(대분류) + 공장도가(판매가 70%)\n";
if ($commit) {
    // 대분류(root)마다 5~12% 순환 할인율
    $roots = $pdo->query("SELECT id FROM categories WHERE parent_id IS NULL ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
    $rates = [5, 8, 10, 12];
    $i = 0;
    foreach ($roots as $rid) {
        $rate = $rates[$i % count($rates)]; $i++;
        // 이미 설정된 값은 덮어쓰지 않음(0일 때만 데모값)
        $pdo->prepare("UPDATE categories SET discount_customer=? WHERE id=? AND discount_customer=0")->execute([$rate, (int)$rid]);
    }
    // 공장도가: 아직 0인 상품만 판매가의 70%
    $pdo->exec("UPDATE products SET cost_price = ROUND(price_b2c*0.7) WHERE cost_price=0 AND price_b2c>0 AND is_active=1");
    echo "  대분류 할인율 세팅 + 공장도가(70%) 보정 완료\n";
} else {
    echo "  (예정) 대분류별 5·8·10·12% 순환, 공장도가=판매가×0.7\n";
}

/* ─── 5. ③ 규격/폭 필터 진단: spec 첫숫자 추출 비교 ──────── */
echo "\n[5] 규격(spec) ↔ 폭 첫숫자 추출 진단 (카테고리별 샘플)\n";
$samples = $pdo->query(
    "SELECT r.name AS root, c.name AS cat, p.name AS pname,
            p.spec,
            CAST(REGEXP_SUBSTR(p.spec,'[0-9]+') AS UNSIGNED) AS first_num
     FROM products p
     JOIN categories c ON c.id=p.category_id
     LEFT JOIN categories m ON m.id=c.parent_id
     LEFT JOIN categories r ON r.id=m.parent_id
     WHERE p.is_active=1 AND p.spec IS NOT NULL AND p.spec<>''
     ORDER BY r.name, c.name, p.id
     LIMIT 60"
)->fetchAll();
$curRoot = '';
foreach ($samples as $s) {
    $root = $s['root'] ?? $s['cat'];
    if ($root !== $curRoot) { echo "\n  ▼ {$root}\n"; $curRoot = $root; }
    $spec = preg_replace('/\s+/', ' ', (string)$s['spec']);
    echo "    · " . mb_substr($s['pname'], 0, 16) . " | spec=[{$spec}] → 폭추출={$s['first_num']}\n";
}
echo "\n완료. (③ 필터는 위 spec 패턴을 보고 파싱규칙/필터방식을 대장과 확정 후 구현)\n";
