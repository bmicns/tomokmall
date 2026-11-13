<?php
declare(strict_types=1);
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/ktomok_bulk.php';   // 케이토목 일괄 등록 + 업체(출처) 규칙(app/product_sources.php)

header('Content-Type: application/json; charset=utf-8');

require_admin();

/*
 * 관리자 — 케이토목 상품(products 의 상용콘크리트 반영본이 아닌 행) 목록·등록·수정 API. 화면: /admin-products.php 업체 '케이토목'
 * GET  ?status=&cat_id=&q=&page=          목록(케이토목만 — 출처 syccrt 반영본 제외, 사용자 결정 2026-11-13)
 * POST JSON create / update               제품 1건 등록(비활성, 출처 ktomok) / 수정(활성 상태는 바꾸지 않음)
 * POST multipart action=stage_excel       케이토목 엑셀 올리기 → 검증 결과(app/ktomok_bulk.php)
 * POST JSON {action:'bulk_commit', job}   올린 엑셀로 등록: 새 상품 비활성, 같은 상품(출처ID → 제품명+규격)은 엑셀 칸만 덮어쓰기
 * POST JSON delete / bulk_toggle          활성/비활성 바꾸기 — 지금 멈춤(PRODUCT_ACTIVE_TOGGLE_ENABLED, 추후 개발)
 */
const KTOMOK_ROWS_WHERE = "(p.external_source IS NULL OR p.external_source <> 'syccrt')";

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

$method = $_SERVER['REQUEST_METHOD'];

try {
    // GET: 제품 목록
    if ($method === 'GET') {
        $catId  = (int)($_GET['cat_id'] ?? 0);
        $q      = trim($_GET['q'] ?? '');
        $page   = max(1, (int)($_GET['page'] ?? 1));
        $status = $_GET['status'] ?? 'active';
        $limit  = 20;
        $offset = ($page - 1) * $limit;

        $where  = [KTOMOK_ROWS_WHERE];
        $params = [];

        if ($status === 'active') {
            $where[] = 'p.is_active = 1';
        } elseif ($status === 'inactive') {
            $where[] = 'p.is_active = 0';
        }
        // 'all' → is_active 조건 없음

        if ($catId) {
            $where[]          = 'p.category_id = :cat_id';
            $params['cat_id'] = $catId;
        }
        if ($q !== '') {
            $where[]     = '(p.name LIKE :q OR p.spec LIKE :q2 OR p.brand LIKE :q3)';
            $params['q'] = $params['q2'] = $params['q3'] = "%{$q}%";
        }

        $whereStr = $where ? implode(' AND ', $where) : '1=1';

        $cntSt = db()->prepare("SELECT COUNT(*) AS c FROM products p WHERE {$whereStr}");
        $cntSt->execute($params);
        $total = (int)($cntSt->fetch()['c'] ?? 0);

        $sql = "SELECT p.id, p.name, p.brand, p.spec, p.price_b2b, p.price_b2c,
                       p.image_url, p.is_active, p.category_id,
                       c.name AS cat_name
                FROM products p
                JOIN categories c ON c.id = p.category_id
                WHERE {$whereStr}
                ORDER BY p.id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $st = db()->prepare($sql);
        $st->execute($params);
        $products = $st->fetchAll();

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
        echo json_encode(['ok' => true, 'name' => $name, 'rows' => count($r['rows']), 'data_rows' => $r['data_rows'],
                          'error_count' => $r['error_count'], 'errors' => $r['errors'], 'normalize' => null], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // POST: create / update / delete / bulk_toggle / bulk_commit
    $body   = json_decode(file_get_contents('php://input'), true) ?? [];
    $action = $body['action'] ?? '';
    if (!PRODUCT_ACTIVE_TOGGLE_ENABLED && in_array($action, ['delete', 'bulk_toggle'], true)) {
        throw new \InvalidArgumentException(PRODUCT_ACTIVE_TOGGLE_OFF_MSG);
    }

    switch ($action) {
        case 'create':
            $name = trim($body['name'] ?? '');
            if (!$name) throw new \InvalidArgumentException('제품명 필수');
            $catId = (int)($body['category_id'] ?? 0);
            if (!$catId) throw new \InvalidArgumentException('카테고리 필수');

            // 새 상품은 비활성·출처 케이토목(사용자 결정 2026-11-13 — 등록 기본값 비활성)
            $st = db()->prepare(
                "INSERT INTO products (category_id, name, brand, spec, price_b2b, price_b2c, cost_price, image_url, external_source, is_active)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'ktomok', 0)"
            );
            $st->execute([
                $catId,
                $name,
                trim($body['brand'] ?? '') ?: null,
                trim($body['spec']  ?? '') ?: null,
                (int)($body['price_b2b'] ?? 0),
                (int)($body['price_b2c'] ?? 0),
                max(0, (int)($body['cost_price'] ?? 0)),
                trim($body['image_url'] ?? '') ?: null,
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

            // 활성 상태는 바꾸지 않음(활성/비활성 변경 기능 멈춤 — PRODUCT_ACTIVE_TOGGLE_ENABLED 가 true 일 때만 반영)
            $activeSql = PRODUCT_ACTIVE_TOGGLE_ENABLED ? ', is_active=?' : '';
            $st = db()->prepare(
                "UPDATE products SET category_id=?, name=?, brand=?, spec=?,
                 price_b2b=?, price_b2c=?, cost_price=?, image_url=?{$activeSql}
                 WHERE id=?"
            );
            $st->execute([
                (int)($body['category_id'] ?? 0),
                $name,
                trim($body['brand'] ?? '') ?: null,
                trim($body['spec']  ?? '') ?: null,
                (int)($body['price_b2b'] ?? 0),
                (int)($body['price_b2c'] ?? 0),
                max(0, (int)($body['cost_price'] ?? 0)),
                trim($body['image_url'] ?? '') ?: null,
                ...(PRODUCT_ACTIVE_TOGGLE_ENABLED ? [(int)($body['is_active'] ?? 1)] : []),
                $id,
            ]);
            admin_product_sync_spec_axes($id);
            echo json_encode(['ok' => true]);
            break;

        case 'delete':
            $id = (int)($body['id'] ?? 0);
            if (!$id) throw new \InvalidArgumentException('id 필수');
            db()->prepare("UPDATE products SET is_active=0 WHERE id=?")->execute([$id]);
            echo json_encode(['ok' => true]);
            break;

        case 'bulk_toggle':
            $ids = array_map('intval', $body['ids'] ?? []);
            $val = (int)($body['is_active'] ?? 0);
            if (!$ids) throw new \InvalidArgumentException('ids 필수');
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            db()->prepare("UPDATE products SET is_active={$val} WHERE id IN ({$placeholders})")
                ->execute($ids);
            echo json_encode(['ok' => true]);
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
                $count = ['insert' => 0, 'update' => 0, 'same' => 0];
                $ids = [];
                file_put_contents($progress, json_encode(['done' => 0, 'total' => $total, 'finished' => false]));
                $pdo->beginTransaction();
                try {
                    foreach ($r['rows'] as $i => $row) {
                        [$res, $pid] = ktomok_bulk_save_row($pdo, $row, $r['present']);
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
            $result = ['done' => $total, 'total' => $total, 'finished' => true, 'with_zip' => false, 'images' => 0, 'images_missing' => 0, 'thumbs_missing' => 0, 'normalized_rows' => 0] + $count;
            file_put_contents($progress, json_encode($result));
            echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE);
            break;

        default:
            echo json_encode(['ok' => false, 'error' => '알 수 없는 action']);
    }
} catch (\InvalidArgumentException $e) {
    // 입력 검증 실패: HTTP 200 + ok:false (닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 JSON 문구가 사라짐)
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (\Throwable $e) {
    // 내부 오류 문구(SQL 등)는 화면에 보내지 않고 서버 로그에만 남긴다
    error_log('[api/admin/products] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
}
