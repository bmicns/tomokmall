<?php
declare(strict_types=1);
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/ktomok_bulk.php';   // 케이토목 일괄 등록 + 업체(출처) 규칙(app/product_sources.php)
require_once __DIR__ . '/../../../app/syc_publish.php';
require_once __DIR__ . '/../../../app/product_images.php';   // 수정 창 이미지 3칸(썸네일·상세·규격) 경로 확인   // '모든 업체'에서 상용콘크리트·업체 상품을 바꾼 뒤 고객 화면 반영 — syc_publish_std_run

header('Content-Type: application/json; charset=utf-8');

require_admin();

/*
 * 관리자 — 케이토목 상품(products 의 상용콘크리트 반영본이 아닌 행) 목록·등록·수정 API. 화면: /admin-products.php 업체 '케이토목'
 * GET  ?status=&cat_id=&q=&page=          목록(케이토목만 — 출처 syccrt 반영본 제외, 사용자 결정 2026-11-13)
 * GET  ?source=all&…                      '모든 업체'(2026-11-16): 고객용 상품 표 전체(케이토목 + 상용콘크리트·업체 반영본), 행마다 업체 이름(src_name)·저장 위치(store)
 *      → 활성/비활성(bulk_toggle·set_active_filter 에 source:'all')은 케이토목 행은 여기서, 반영본은 원본(syc_products)을 바꾼 뒤 고객 화면 반영
 * POST JSON create / update               제품 1건 등록(비활성, 출처 ktomok) / 수정(케이토목 행만) — 이미지 3칸 image_thumb_url·image_url(상세)·image_spec_url:
 *                                         빈칸 = 비우기, 서버 경로는 파일 확인(파일 올리기는 api/admin/product-image.php)
 * POST multipart action=stage_excel       케이토목 엑셀 올리기 → 검증 결과(app/ktomok_bulk.php)
 * POST JSON {action:'bulk_commit', job}   올린 엑셀로 등록: 새 상품 비활성, 같은 상품(출처ID → 제품명+규격)은 엑셀 칸만 덮어쓰기
 * POST JSON delete / bulk_toggle {ids, is_active}   활성/비활성 바꾸기(케이토목 행만) — 활성 = 고객 화면에 보임(2026-11-16)
 * POST JSON {action:'set_active_filter', filter:{status,cat_id,q}, expect, is_active}  검색 결과 전체(모든 페이지) 활성/비활성
 *      ↑ PRODUCT_ACTIVE_TOGGLE_ENABLED 가 false 면 멈춤
 */
// 케이토목 행 = 상용콘크리트(syccrt)·업체(vendor-<id>) 반영본이 아닌 행 — 반영본은 원본(syc_products)에서 고친다
const KTOMOK_ROWS_WHERE = "(p.external_source IS NULL OR (p.external_source <> 'syccrt' AND p.external_source NOT LIKE 'vendor-%'))";
const KTOMOK_WEBROOT = __DIR__ . '/../..';   // 이미지 경로(/…)를 찾는 웹루트(public)
// 상용콘크리트·업체 반영본(원본 = syc_products, 같은 출처 + 출처ID)
// 업체 연결 상품 줄(사용자 결정 2026-11-17): 출처 = 그 업체 출처 코드 + 출처ID link-<원래 상품 id>, 원본 없이 이 표에만 있음 → 여기서 바로 고친다
const LINK_ROWS_WHERE = "(p.external_id LIKE 'link-%')";
const PUBLISHED_ROWS_WHERE = "((p.external_source = 'syccrt' OR p.external_source LIKE 'vendor-%') AND NOT " . LINK_ROWS_WHERE . ")";
// 이 API 가 products 에서 바로 고치는 행 = 케이토목 행 + 업체 연결 상품 줄
const OWN_ROWS_WHERE = "(" . KTOMOK_ROWS_WHERE . " OR " . LINK_ROWS_WHERE . ")";

/**
 * 저장 뒤 규격 축 새 칸(spec_code·dim_*)을 지금 규격 원문 기준으로 다시 계산(app/spec_axes.php).
 * 실패해도 상품 저장은 이미 끝났으므로 응답은 그대로 — 원인은 서버 로그에만(목록은 규격 원문으로 표시됨).
 */
function admin_product_sync_spec_axes(int $id): void
{
    try {
        spec_axes_sync_product($id);
    } catch (\Throwable $e) {
        error_log('[api/admin/products] 규격 축 갱신 실패 id=' . $id . ': ' . get_class($e));
    }
}

/**
 * 목록 조건(케이토목 행만): 화면 목록과 '검색 결과 전체' 활성/비활성이 같은 조건을 쓴다.
 * @return array{0:string,1:array<string,mixed>} [WHERE 문, 매개변수]
 */
function admin_products_list_where(array $f): array
{
    $catId  = (int) ($f['cat_id'] ?? 0);
    $q      = trim((string) ($f['q'] ?? ''));
    $status = (string) ($f['status'] ?? 'active');
    $where  = [admin_products_is_all($f) ? '1=1' : KTOMOK_ROWS_WHERE];
    $params = [];
    if ($status === 'active') { $where[] = 'p.is_active = 1'; }
    elseif ($status === 'inactive') { $where[] = 'p.is_active = 0'; }   // 'all' → is_active 조건 없음
    if ($catId) { $where[] = 'p.category_id = :cat_id'; $params['cat_id'] = $catId; }
    if ($q !== '') {
        $where[]     = '(p.name LIKE :q OR p.spec LIKE :q2 OR p.brand LIKE :q3)';
        $params['q'] = $params['q2'] = $params['q3'] = "%{$q}%";
    }
    return [implode(' AND ', $where), $params];
}

/**
 * 수정 창 이미지 3칸 → [칸 => 경로|null]. 요청에 없는 칸은 넣지 않는다(그 칸은 그대로 둠).
 * @return array<string,?string>
 */
function admin_products_image_inputs(array $body): array
{
    $out = [];
    foreach (['image_thumb_url' => '썸네일 이미지', 'image_url' => '상세 이미지', 'image_spec_url' => '규격 이미지'] as $col => $label) {
        if (array_key_exists($col, $body)) { $out[$col] = product_image_path_input($body[$col], KTOMOK_WEBROOT, $label); }
    }
    return $out;
}

/** '모든 업체' 보기인가(source = 'all') */
function admin_products_is_all(array $f): bool
{
    return ($f['source'] ?? '') === 'all';
}

/**
 * '모든 업체'에서 반영본(상용콘크리트·업체)을 바꾼 뒤 고객 화면 반영. 실패해도 원본 저장은 끝났으므로 알림만(api/admin/syc-products.php 와 같음)
 * @return array{publish?:array, publish_error?:string}
 */
function admin_products_publish(PDO $pdo): array
{
    try {
        return ['publish' => syc_publish_std_run($pdo, (int) (current_user()['id'] ?? 0) ?: null)];
    } catch (\Throwable $e) {
        error_log('[api/admin/products] 고객 화면 반영 실패: ' . get_class($e) . ': ' . $e->getMessage());
        return ['publish_error' => '고객 화면 반영에 실패했습니다. 원본은 저장됐습니다 — 잠시 후 다시 시도해 주세요.'];
    }
}

/**
 * 활성으로 바꿔도 되는지: 비활성 업체 상품(업체를 먼저 다시 활성으로 — 2026-11-17)과
 * 업체 연결을 삭제(공급 제외)한 업체 상품 줄(품목별 업체정보에서 그 업체를 다시 추가한 뒤)은 막는다. 안 되면 InvalidArgumentException
 */
function admin_products_assert_activatable(PDO $pdo, string $where, array $params): void
{
    product_assert_no_inactive_vendor($pdo, 'products p', $where, $params, 'p.external_source');
    $gone = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE ({$where}) AND " . LINK_ROWS_WHERE . "
                           AND NOT EXISTS (SELECT 1 FROM product_vendors pv JOIN vendors v ON v.id = pv.vendor_id
                                           WHERE " . vendor_source_sql($pdo, 'v') . " = p.external_source AND CONCAT('link-', pv.product_id) = p.external_id)");
    $gone->execute($params);
    $g = (int) $gone->fetchColumn();
    if ($g > 0) { throw new \InvalidArgumentException("업체 연결을 삭제한 상품 {$g}개가 들어 있어 활성으로 바꿀 수 없습니다. 품목별 업체정보에서 그 업체를 다시 추가해 주세요."); }
}

/**
 * 활성/비활성 바꾸기: $where(products p 조건)에 맞는 행 중 케이토목 행은 products 를, ($all 일 때) 반영본은 원본 syc_products 를 바꾼다.
 * 반영본을 products 에서만 바꾸면 다음 고객 화면 반영 때 원본 값으로 되돌아가므로 원본을 바꾼다. @return [바뀐 행 수, 원본을 바꿨는지]
 */
function admin_products_set_active(PDO $pdo, string $where, array $params, int $val, bool $all): array
{
    // 비활성 업체 상품은 활성으로 바꾸지 않음(업체를 먼저 다시 활성으로 — 사용자 결정 2026-11-17, app/product_sources.php)
    if ($val === 1) { admin_products_assert_activatable($pdo, $where, $params); }
    $up = $pdo->prepare("UPDATE products p SET p.is_active = :val WHERE ({$where}) AND " . OWN_ROWS_WHERE);
    $up->execute(['val' => $val] + $params);
    $changed = $up->rowCount();
    $src = 0;
    if ($all) {
        $us = $pdo->prepare("UPDATE syc_products s JOIN products p ON p.external_source = s.external_source AND p.external_id = s.external_id
                             SET s.is_active = :val WHERE ({$where}) AND " . PUBLISHED_ROWS_WHERE);
        $us->execute(['val' => $val] + $params);
        $src = $us->rowCount();
    }
    return [$changed + $src, $src > 0];
}

$method = $_SERVER['REQUEST_METHOD'];

try {
    // GET: 제품 목록
    if ($method === 'GET') {
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $limit  = 20;
        $offset = ($page - 1) * $limit;
        [$whereStr, $params] = admin_products_list_where($_GET);

        $cntSt = db()->prepare("SELECT COUNT(*) AS c FROM products p WHERE {$whereStr}");
        $cntSt->execute($params);
        $total = (int)($cntSt->fetch()['c'] ?? 0);

        // 업체 이름: 케이토목 행 → '케이토목', syccrt → '상용콘크리트', vendor-<id> → 업체 목록 이름(store = 원본 위치)
        $sql = "SELECT p.id, p.name, p.brand, p.spec, p.price_b2b, p.price_b2c, p.cost_price,
                       p.image_url, p.image_thumb_url, p.image_spec_url, p.is_active, p.category_id, p.external_id,
                       c.name AS cat_name,
                       p.external_source,
                       CASE WHEN " . PUBLISHED_ROWS_WHERE . " THEN 'syc' WHEN " . LINK_ROWS_WHERE . " THEN 'link' ELSE 'products' END AS store
                FROM products p
                JOIN categories c ON c.id = p.category_id
                WHERE {$whereStr}
                ORDER BY p.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $st = db()->prepare($sql);
        $st->execute($params);
        $products = $st->fetchAll();
        $vendorNames = [];   // vendor-<id> → 업체 목록 이름(표가 없으면 출처 코드 그대로)
        try {
            foreach (product_sources(db()) as $src) { $vendorNames[$src['code']] = $src['name']; }   // 출처 코드 → 업체 이름(상용콘크리트·케이토목 포함, S25)
        } catch (\PDOException $e) {
            error_log('[api/admin/products] vendors 표를 읽지 못함: ' . $e->getCode());
        }
        foreach ($products as &$pr) {
            // 회원가(읽기 전용 표시, 사용자 결정 2026-11-17 B안): 고객 화면과 같은 계산(app/pricing.php price_bundle) — 저장 값은 그대로
            $pb = price_bundle($pr);
            $pr['price_customer']  = $pb['customer'];
            $pr['price_corporate'] = $pb['corporate'];
            $es = (string) ($pr['external_source'] ?? '');
            $pr['src_name'] = $pr['store'] !== 'products' ? ($vendorNames[$es] ?? $es) : ($vendorNames[$es] ?? $vendorNames[PRODUCT_SOURCE_KTOMOK] ?? '케이토목');
            $pr['src_code'] = $pr['store'] === 'syc' ? $es : PRODUCT_SOURCE_KTOMOK;   // 반영본 수정 = 원본(syc_products)을 출처 + 출처ID 로 찾아 엶
            if ($pr['store'] !== 'syc') { unset($pr['external_id']); }
            unset($pr['external_source']);
        }
        unset($pr);

        echo json_encode(['ok' => true, 'products' => $products, 'total' => $total, 'page' => $page, 'limit' => $limit]);
        exit;
    }

    // POST multipart: 케이토목 엑셀 올리기(검증만, 파일은 웹 밖 임시 폴더) — 등록은 bulk_commit
    if (($_POST['action'] ?? '') === 'stage_excel') {
        $old = $_SESSION['ktomok_bulk']['path'] ?? null;
        if (is_string($old) && str_starts_with($old, syc_bulk_stage_dir() . '/') && is_file($old)) { unlink($old); }
        unset($_SESSION['ktomok_bulk']);
        $f = $_FILES['file'] ?? null;
        if (!is_array($f) || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) { throw new \InvalidArgumentException('파일을 받지 못했습니다. 다시 올려 주세요.'); }
        $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, ['xlsx', 'csv'], true)) { throw new \InvalidArgumentException('올릴 수 있는 파일: xlsx, csv'); }
        if ((int) $f['size'] <= 0 || (int) $f['size'] > SYC_BULK_MAX_EXCEL_BYTES) { throw new \InvalidArgumentException('파일 크기는 ' . (SYC_BULK_MAX_EXCEL_BYTES >> 20) . 'MB 까지입니다.'); }
        $path = syc_bulk_stage_dir() . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!move_uploaded_file((string) $f['tmp_name'], $path)) { throw new \RuntimeException('업로드 파일 이동 실패'); }
        try {
            $r = ktomok_bulk_read_excel($path, $ext, ktomok_bulk_category_ids(db()));
        } catch (\Throwable $e) {
            unlink($path);
            throw $e;
        }
        $_SESSION['ktomok_bulk'] = ['path' => $path, 'ext' => $ext];
        $name = mb_substr((string) $f['name'], 0, 120);
        $iw = syc_bulk_image_warnings($r['rows'], KTOMOK_WEBROOT);   // 서버에서 못 찾은 이미지(등록은 되고 그 이미지만 빈칸)
        echo json_encode(['ok' => true, 'name' => $name, 'rows' => count($r['rows']), 'data_rows' => $r['data_rows'],
                          'error_count' => $r['error_count'], 'errors' => $r['errors'], 'normalize' => null,
                          'image_warnings' => $iw['warnings'], 'image_warning_count' => $iw['count']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // POST: create / update / delete / bulk_toggle / bulk_commit
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    if (!PRODUCT_ACTIVE_TOGGLE_ENABLED && in_array($action, ['delete', 'bulk_toggle', 'set_active_filter'], true)) {
        throw new \InvalidArgumentException(PRODUCT_ACTIVE_TOGGLE_OFF_MSG);
    }

    switch ($action) {
        case 'create':
            $name = trim($body['name'] ?? '');
            if (!$name) throw new \InvalidArgumentException('제품명 필수');
            $catId = (int)($body['category_id'] ?? 0);
            if (!$catId) throw new \InvalidArgumentException('카테고리 필수');

            // 새 상품은 비활성·출처 케이토목(사용자 결정 2026-11-13 — 등록 기본값 비활성)
            $imgs = admin_products_image_inputs($body) + ['image_thumb_url' => null, 'image_url' => null, 'image_spec_url' => null];
            $st = db()->prepare(
                "INSERT INTO products (category_id, name, brand, spec, price_b2b, price_b2c, cost_price, image_url, image_thumb_url, image_spec_url, external_source, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'ktomok', 0)"
            );
            $st->execute([
                $catId,
                $name,
                trim($body['brand'] ?? '') ?: null,
                trim($body['spec']  ?? '') ?: null,
                (int)($body['price_b2b'] ?? 0),
                (int)($body['price_b2c'] ?? 0),
                max(0, (int)($body['cost_price'] ?? 0)),
                $imgs['image_url'], $imgs['image_thumb_url'], $imgs['image_spec_url'],
            ]);
            $newId = (int)db()->lastInsertId();
            admin_product_sync_spec_axes($newId);
            echo json_encode(['ok' => true, 'id' => $newId]);
            break;

        case 'update':
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id 필수');
            $name = trim($body['name'] ?? '');
            if (!$name) throw new \InvalidArgumentException('제품명 필수');

            // 활성 상태는 PRODUCT_ACTIVE_TOGGLE_ENABLED 가 true 일 때만 반영. 케이토목 행만(반영본은 원본 수정 — api/admin/syc-products.php update)
            $activeSql = PRODUCT_ACTIVE_TOGGLE_ENABLED ? ', is_active=?' : '';
            $pdo = db();
            $cur = $pdo->prepare('SELECT p.is_active, p.external_source, p.external_id, (' . LINK_ROWS_WHERE . ') AS is_link FROM products p WHERE p.id = ?');
            $cur->execute([$id]);
            $curRow = $cur->fetch();
            if (PRODUCT_ACTIVE_TOGGLE_ENABLED && $curRow && (int)($body['is_active'] ?? 1) === 1 && !(int)$curRow['is_active']) {
                admin_products_assert_activatable($pdo, 'p.id = :aid', ['aid' => $id]);
            }
            $imgs = admin_products_image_inputs($body);
            $imgSql = implode('', array_map(static fn($c) => ", {$c}=?", array_keys($imgs)));
            $st = db()->prepare(
                "UPDATE products p SET category_id=?, name=?, brand=?, spec=?,
                 price_b2b=?, price_b2c=?, cost_price=?{$imgSql}{$activeSql}
                 WHERE p.id=? AND " . OWN_ROWS_WHERE
            );
            $st->execute([
                (int)($body['category_id'] ?? 0),
                $name,
                trim($body['brand'] ?? '') ?: null,
                trim($body['spec']  ?? '') ?: null,
                (int)($body['price_b2b'] ?? 0),
                (int)($body['price_b2c'] ?? 0),
                max(0, (int)($body['cost_price'] ?? 0)),
                ...array_values($imgs),
                ...(PRODUCT_ACTIVE_TOGGLE_ENABLED ? [(int)($body['is_active'] ?? 1)] : []),
                $id,
            ]);
            // 업체 연결 상품 줄: 매입가 = 그 업체 공급단가(한 값) — 공급단가도 같이 고친다(사용자 결정 2026-11-17)
            if ($curRow && (int)$curRow['is_link'] && preg_match('/^link-(\d+)$/', (string)$curRow['external_id'], $lm)) {
                $pdo->prepare('UPDATE product_vendors pv JOIN vendors v ON v.id = pv.vendor_id SET pv.supply_price=?
                               WHERE ' . vendor_source_sql($pdo, 'v') . ' = ? AND pv.product_id=?')
                    ->execute([max(0, (int)($body['cost_price'] ?? 0)), (string)$curRow['external_source'], (int)$lm[1]]);
            }
            admin_product_sync_spec_axes($id);
            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id 필수');
            db()->prepare("UPDATE products SET is_active=0 WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        case 'bulk_toggle':   // 고른 상품(이 페이지) — 케이토목 행만 바꿈(반영본은 원본에서 바꿔야 다음 반영 때 되돌아가지 않음)
            $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? [])), static fn($v) => $v > 0));
            if (!$ids || count($ids) > 500) throw new \InvalidArgumentException('대상 상품이 없습니다.');
            $val = (int)($body['is_active'] ?? 0) === 1 ? 1 : 0;
            $pdo = db();
            $all = admin_products_is_all($body);
            $ph  = [];
            foreach ($ids as $i => $id) { $ph['id' . $i] = $id; }
            [$changed, $srcChanged] = admin_products_set_active($pdo, 'p.id IN (:' . implode(', :', array_keys($ph)) . ')', $ph, $val, $all);
            echo json_encode(['ok' => true, 'changed' => $changed] + ($srcChanged ? admin_products_publish($pdo) : []), JSON_UNESCAPED_UNICODE);
            break;

        // 검색 결과 전체(여러 페이지) 활성/비활성: 화면의 목록 조건 그대로. 화면이 본 개수(expect)와 지금 개수가 다르면
        // 그사이 목록이 바뀐 것이므로 바꾸지 않고 다시 확인하게 한다(api/admin/syc-products.php 와 같은 방식)
        case 'set_active_filter':
            $filter = is_array($body['filter'] ?? null) ? $body['filter'] : [];
            $expect = (int)($body['expect'] ?? -1);
            $val    = (int)($body['is_active'] ?? 0) === 1 ? 1 : 0;
            [$w, $params] = admin_products_list_where($filter);
            $pdo = db();
            $pdo->beginTransaction();
            $cnt = $pdo->prepare("SELECT COUNT(*) FROM products p WHERE {$w}");
            $cnt->execute($params);
            $now = (int) $cnt->fetchColumn();
            if ($now === 0 || $now !== $expect) {
                $pdo->rollBack();
                throw new \InvalidArgumentException($now === 0 ? '대상 상품이 없습니다.' : "그사이 목록이 바뀌었습니다(지금 {$now}개). 목록을 다시 확인해 주세요.");
            }
            [$changed, $srcChanged] = admin_products_set_active($pdo, $w, $params, $val, admin_products_is_all($filter));
            $pdo->commit();
            echo json_encode(['ok' => true, 'count' => $now, 'changed' => $changed] + ($srcChanged ? admin_products_publish($pdo) : []), JSON_UNESCAPED_UNICODE);
            break;

        case 'bulk_commit':
            $ex = $_SESSION['ktomok_bulk'] ?? null;
            if (!is_array($ex) || !is_file((string) $ex['path'])) { throw new \InvalidArgumentException('엑셀 파일을 먼저 올려 주세요.'); }
            $progress = syc_bulk_stage_dir() . '/progress_' . (preg_match('/^[a-f0-9]{16,32}$/', (string) ($body['job'] ?? '')) ? $body['job'] : 'x') . '.json';
            unset($_SESSION['ktomok_bulk']);
            session_write_close();                 // 진행률 조회(api/admin/syc-products.php?action=progress)가 막히지 않게
            ignore_user_abort(true);
            set_time_limit(300);
            $pdo = db();
            try {
                $r = ktomok_bulk_read_excel((string) $ex['path'], (string) $ex['ext'], ktomok_bulk_category_ids($pdo));
                if ($r['error_count'] > 0) { throw new \InvalidArgumentException('엑셀에 오류가 있는 행이 있어 등록하지 않았습니다. 고친 뒤 다시 올려 주세요.'); }
                $total = count($r['rows']);
                $count = ['insert' => 0, 'update' => 0, 'same' => 0, 'images' => 0, 'images_missing' => 0];
                $ids = []; $found = [];
                file_put_contents($progress, json_encode(['done' => 0, 'total' => $total, 'finished' => false]));
                $pdo->beginTransaction();
                try {
                    foreach ($r['rows'] as $i => $row) {
                        $imgs = [];
                        foreach (KTOMOK_BULK_IMAGE_COLUMNS as $k => $col) {
                            $img = $row['_img'][$k] ?? null;
                            if ($img === null) { continue; }
                            $hit = $found[$img[1]] ??= (syc_bulk_image_resolve($img, KTOMOK_WEBROOT) ?? false);
                            if ($hit === false) { $count['images_missing']++; } else { $imgs[$col] = $hit; $count['images']++; }
                        }
                        [$res, $pid] = ktomok_bulk_save_row($pdo, $row, $r['present'], $imgs);
                        $count[$res]++;
                        $ids[] = $pid;
                        if (($i + 1) % 25 === 0) { file_put_contents($progress, json_encode(['done' => $i + 1, 'total' => $total, 'finished' => false])); }
                    }
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }
            } catch (\Throwable $e) {
                file_put_contents($progress, json_encode(['finished' => true, 'failed' => true]));
                throw $e;
            } finally {
                if (is_file((string) $ex['path'])) { unlink((string) $ex['path']); }
            }
            foreach ($ids as $pid) { admin_product_sync_spec_axes($pid); }   // 규격 축 칸 다시 계산(실패해도 저장은 끝남)
            $result = ['done' => $total, 'total' => $total, 'finished' => true, 'normalized_rows' => 0] + $count;
            file_put_contents($progress, json_encode($result));
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => '알 수 없는 action']);
    }
} catch (\InvalidArgumentException $e) {
    if (db() && db()->inTransaction()) { db()->rollBack(); }   // 검사에서 멈춘 트랜잭션(set_active_filter) 정리
    // 입력 검증 실패: HTTP 200 + ok:false (닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 JSON 문구가 사라짐)
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    // 내부 오류 문구(SQL 등)는 화면에 보내지 않고 서버 로그에만 남긴다
    error_log('[api/admin/products] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
}
