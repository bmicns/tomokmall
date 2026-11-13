<?php
declare(strict_types=1);
/**
 * 관리자 — 상용콘크리트·업체 목록 업체 상품(syc_products) 목록·일괄 등록·제품 등록 API (화면: /admin-products.php 업체 드롭다운)
 * 업체(source) = 'syccrt'(상용콘크리트) 또는 'vendor-<업체 id>'(app/product_sources.php). 모든 요청은 고른 업체 상품만 보고 바꾼다.
 *
 * GET  ?source=&status=&cat_id=&q=&page=  목록(20개씩)
 * GET  ?action=categories                분류 선택용 카테고리(대분류 › 품목)
 * GET  ?action=progress&job=…            등록 진행률(등록 요청이 처리되는 동안 화면이 읽음)
 * POST multipart action=stage_excel, source  엑셀(xlsx·csv) 올리기 → 검증 결과(행 수·오류) + 자동 정리 미리보기(normalize). 파일은 웹 밖 임시 폴더에 보관
 *      이미지: ZIP 업로드 없음(2026-11-13) — 엑셀 '썸네일·상세·규격 이미지 url 경로' 칸, 서버 경로는 웹루트에서 파일 확인(못 찾으면 그 이미지만 빈칸 + 경고)
 * POST JSON {action:'commit', job, normalize} 올린 엑셀로 등록: 새 상품 비활성, 같은 업체 안에서 같은 상품은 덮어쓰기(활성 상태 유지)
 *                                        — 같은 상품 = 출처ID, 출처ID가 없으면 제품명+규격+옵션(사용자 결정 2026-11-13)
 *                                        normalize(기본 true) = 자동 정리(app/syc_normalize.php) 적용 — 올릴 때 미리보기와 같은 결과
 * POST JSON {action:'create', source, …칸}  제품 1건 등록(비활성). 같은 제품명+규격+옵션이 이미 있으면 거절(고칠 때는 일괄 등록)
 * POST JSON {action:'set_active', ids, is_active} / {action:'delete', id}(비활성화)
 * POST JSON {action:'set_active_filter', filter:{source,status,cat_id,q}, expect, is_active}  검색 결과 전체(모든 페이지) 활성/비활성
 *      ↑ 활성/비활성 바꾸기 3개는 지금 멈춤(PRODUCT_ACTIVE_TOGGLE_ENABLED — 사용자 결정 2026-11-13, 추후 개발)
 * 권한: 관리자만. 오류는 HTTP 200 + ok:false(닷홈이 4xx 본문을 바꿈 — 기존 api/admin/products.php 와 같음)
 * 로직: app/syc_bulk.php
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/syc_bulk.php';

header('Content-Type: application/json; charset=utf-8');

require_admin();

const SYC_WEBROOT = __DIR__ . '/../..';   // 이미지 경로(/…)를 찾는 웹루트(public)

function syc_api_out(array $d): never
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

/** 등록 진행률 파일(임시 폴더). job 은 화면이 만든 16~32자리 16진수 */
function syc_api_progress_file(string $job): string
{
    if (!preg_match('/^[a-f0-9]{16,32}$/', $job)) { throw new InvalidArgumentException('잘못된 작업 번호'); }
    return syc_bulk_stage_dir() . '/progress_' . $job . '.json';
}

/** 올린 파일 1개 받기: 크기·확장자 확인 후 임시 폴더로 옮김. [임시 경로, 원래 이름, 확장자] */
function syc_api_take_upload(array $allowExt, int $maxBytes): array
{
    $f = $_FILES['file'] ?? null;
    if (!is_array($f) || !isset($f['error']) || is_array($f['error'])) { throw new InvalidArgumentException('파일이 없습니다.'); }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) { throw new InvalidArgumentException('파일이 너무 큽니다.'); }
    if ($f['error'] !== UPLOAD_ERR_OK) { throw new InvalidArgumentException('파일을 받지 못했습니다. 다시 올려 주세요.'); }
    $name = (string) $f['name'];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    if (!in_array($ext, $allowExt, true)) { throw new InvalidArgumentException('올릴 수 있는 파일: ' . implode(', ', $allowExt)); }
    if ((int) $f['size'] <= 0 || (int) $f['size'] > $maxBytes) { throw new InvalidArgumentException('파일 크기는 ' . ($maxBytes >> 20) . 'MB 까지입니다.'); }
    $dest = syc_bulk_stage_dir() . '/' . bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file((string) $f['tmp_name'], $dest)) { throw new RuntimeException('업로드 파일 이동 실패'); }
    return [$dest, mb_substr($name, 0, 120), $ext];
}

/** 이전에 올려 둔 임시 파일 지우기(같은 칸에 새 파일을 올리거나 등록을 마쳤을 때) */
function syc_api_drop_stage(string $slot): void
{
    $p = $_SESSION['syc_bulk'][$slot]['path'] ?? null;
    if (is_string($p) && str_starts_with($p, syc_bulk_stage_dir() . '/') && is_file($p)) { unlink($p); }
    unset($_SESSION['syc_bulk'][$slot]);
}

/** 목록 조건(상태·카테고리·검색어) → [WHERE 문, 바인딩 값]. 목록 조회와 '검색 결과 전체' 활성/비활성이 같은 조건을 쓴다 */
function syc_api_list_where(array $f, string $source): array
{
    $catId  = (int) ($f['cat_id'] ?? 0);
    $q      = trim((string) ($f['q'] ?? ''));
    $status = (string) ($f['status'] ?? 'active');
    $where  = ['p.external_source = :src'];
    $params = ['src' => $source];
    if ($status === 'active') { $where[] = 'p.is_active = 1'; }
    elseif ($status === 'inactive') { $where[] = 'p.is_active = 0'; }
    if ($catId) { $where[] = 'p.category_id = :cat_id'; $params['cat_id'] = $catId; }
    if ($q !== '') {
        $where[] = '(p.name LIKE :q OR p.spec LIKE :q2 OR p.spec_code LIKE :q3 OR p.external_id LIKE :q4 OR p.brand LIKE :q5)';
        $params['q'] = $params['q2'] = $params['q3'] = $params['q4'] = $params['q5'] = '%' . $q . '%';
    }
    return [$where ? implode(' AND ', $where) : '1=1', $params];
}

try {
    $pdo = db();
    if (!$pdo) { throw new RuntimeException('DB 연결 없음'); }
    $method = $_SERVER['REQUEST_METHOD'];
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

    if ($method === 'GET') {
        if ($action === 'categories') {
            $rows = $pdo->query('SELECT c.id, c.slug, c.name, g.name AS group_name FROM syc_categories c JOIN syc_categories g ON g.id = c.parent_id
                                 WHERE c.is_active = 1 ORDER BY g.sort_order, c.sort_order, c.id')->fetchAll();
            syc_api_out(['ok' => true, 'categories' => $rows]);
        }
        if ($action === 'progress') {
            $file = syc_api_progress_file((string) ($_GET['job'] ?? ''));
            $p = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            syc_api_out(['ok' => true, 'progress' => is_array($p) ? $p : null]);
        }
        $src    = product_source_syc_or_fail($pdo, (string) ($_GET['source'] ?? ''));
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $limit  = 20;
        [$w, $params] = syc_api_list_where($_GET, $src['code']);
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM syc_products p WHERE {$w}");
        $cnt->execute($params);
        $offset = ($page - 1) * $limit;
        $st = $pdo->prepare("SELECT p.id, p.name, p.brand, p.spec, p.spec_code, p.unit, p.weight_kg, p.price_b2b, p.price_b2c,
                                    p.image_file, p.image_thumb_file, p.is_active, p.external_id, c.name AS cat_name
                             FROM syc_products p JOIN syc_categories c ON c.id = p.category_id
                             WHERE {$w} ORDER BY p.id DESC LIMIT {$limit} OFFSET {$offset}");
        $st->execute($params);
        syc_api_out(['ok' => true, 'products' => $st->fetchAll(), 'total' => (int) $cnt->fetchColumn(), 'page' => $page, 'limit' => $limit]);
    }

    if ($method !== 'POST') { syc_api_out(['ok' => false, 'error' => '지원하지 않는 요청']); }

    if ($action === 'stage_excel') {
        syc_api_drop_stage('excel');
        $src = product_source_syc_or_fail($pdo, (string) ($_POST['source'] ?? ''));
        [$path, $name, $ext] = syc_api_take_upload(['xlsx', 'csv'], SYC_BULK_MAX_EXCEL_BYTES);
        try {
            // 자동 정리 미리보기: 등록 때와 같은 함수·규칙(app/syc_normalize.php) — 화면에 바뀌는 곳을 보여 주고 등록 때 다시 적용
            $r = syc_bulk_read_excel($path, $ext, syc_bulk_category_ids($pdo), true, syc_bulk_category_active($pdo), $src['code']);
        } catch (Throwable $e) {
            unlink($path);
            throw $e;
        }
        $_SESSION['syc_bulk']['excel'] = ['path' => $path, 'ext' => $ext, 'name' => $name, 'rows' => count($r['rows']), 'error_count' => $r['error_count'], 'source' => $src['code']];
        $iw = syc_bulk_image_warnings($r['rows'], SYC_WEBROOT);   // 서버에서 못 찾은 이미지(등록은 되고 그 이미지만 빈칸)
        syc_api_out(['ok' => true, 'name' => $name, 'rows' => count($r['rows']), 'data_rows' => $r['data_rows'],
                     'error_count' => $r['error_count'], 'errors' => $r['errors'], 'normalize' => $r['normalize'],
                     'image_warnings' => $iw['warnings'], 'image_warning_count' => $iw['count']]);
    }

    $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
    $action = (string) ($body['action'] ?? $action);

    if ($action === 'clear') {
        $slot = (string) ($body['slot'] ?? '');
        if ($slot !== 'excel') { throw new InvalidArgumentException('잘못된 요청'); }
        syc_api_drop_stage($slot);
        syc_api_out(['ok' => true]);
    }

    if ($action === 'commit') {
        $ex = $_SESSION['syc_bulk']['excel'] ?? null;
        if (!is_array($ex) || !is_file((string) $ex['path'])) { throw new InvalidArgumentException('엑셀 파일을 먼저 올려 주세요.'); }
        $src = product_source_syc_or_fail($pdo, (string) ($ex['source'] ?? ''));     // 엑셀을 올릴 때 고른 업체
        $progress = syc_api_progress_file((string) ($body['job'] ?? ''));
        unset($_SESSION['syc_bulk']);
        session_write_close();                 // 진행률 조회 요청이 세션 잠금에 막히지 않게
        ignore_user_abort(true);               // 창을 닫아도 등록은 끝까지
        set_time_limit(300);

        try {
            $norm = ($body['normalize'] ?? true) !== false;      // 자동 정리(기본 켬). 창에서 끄면 엑셀 그대로
            $r = syc_bulk_read_excel((string) $ex['path'], (string) $ex['ext'], syc_bulk_category_ids($pdo), $norm, syc_bulk_category_active($pdo), $src['code']);
            if ($r['error_count'] > 0) { throw new InvalidArgumentException('엑셀에 오류가 있는 행이 있어 등록하지 않았습니다. 고친 뒤 다시 올려 주세요.'); }
            // 업체명 = brand(S9): 엑셀에 업체명이 비어 있으면 고른 업체 이름
            if (!in_array('brand', $r['present'], true)) { $r['present'][] = 'brand'; }
            foreach ($r['rows'] as &$br) { $br['brand'] ??= $src['name']; }
            unset($br);
            $total = count($r['rows']);
            $count = ['insert' => 0, 'update' => 0, 'same' => 0, 'images' => 0, 'images_missing' => 0];
            $found = [];   // 경로 => 찾은 값(같은 파일을 여러 번 확인하지 않게)
            file_put_contents($progress, json_encode(['done' => 0, 'total' => $total, 'finished' => false]));
            $pdo->beginTransaction();
            try {
                foreach ($r['rows'] as $i => $row) {
                    // 이미지 경로 3칸 → syc_products 칸. 빈칸·못 찾은 이미지는 null(기존 값 유지)
                    $imgs = [];
                    foreach (SYC_BULK_IMAGE_COLUMNS as $k => $col) {
                        $img = $row['_img'][$k] ?? null;
                        if ($img === null) { continue; }
                        $imgs[$col] = $found[$img[1]] ??= (syc_bulk_image_resolve($img, SYC_WEBROOT) ?? false);
                        if ($imgs[$col] === false) { $imgs[$col] = null; $count['images_missing']++; } else { $count['images']++; }
                    }
                    $row['external_id'] = syc_bulk_resolve_external_id($pdo, $row);   // 같은 업체 안 같은 상품(출처ID → 제품명+규격+옵션)
                    $count[syc_bulk_save_row($pdo, $row, $imgs, $r['present'])]++;
                    if (($i + 1) % 25 === 0) { file_put_contents($progress, json_encode(['done' => $i + 1, 'total' => $total, 'finished' => false])); }
                }
                $pdo->commit();
            } catch (Throwable $e) {
                $pdo->rollBack();
                throw $e;
            }
        } catch (Throwable $e) {
            file_put_contents($progress, json_encode(['finished' => true, 'failed' => true]));
            throw $e;
        } finally {
            if (is_file((string) $ex['path'])) { unlink((string) $ex['path']); }   // 성공·실패 모두 임시 파일 삭제
        }
        $result = ['done' => $total, 'total' => $total, 'finished' => true,
                   'normalized_rows' => $norm ? (int) ($r['normalize']['changed_rows'] ?? 0) : 0] + $count;
        file_put_contents($progress, json_encode($result));
        syc_api_out(['ok' => true] + $result);
    }

    // 제품 1건 등록(비활성). 칸 규칙은 일괄 등록과 같음(syc_bulk_columns). 자동 정리는 하지 않음(품목 단위 규칙이라 일괄 등록에서만)
    if ($action === 'create') {
        $src = product_source_syc_or_fail($pdo, (string) ($body['source'] ?? ''));
        $cols = syc_bulk_columns();
        $row = [];
        foreach (['name', 'brand', 'spec', 'spec_code', 'spec_option', 'unit', 'weight_kg', 'description', 'price_b2c', 'price_b2b', 'cost_price'] as $k) {
            [$v, $e] = syc_bulk_cell($k, $body[$k] ?? '', $cols[$k]);
            if ($e !== null) { throw new InvalidArgumentException("[{$k}] {$e}"); }
            $row[$k] = $v;
        }
        if ($row['name'] === null) { throw new InvalidArgumentException('제품명을 입력해 주세요.'); }
        $catId = (int) ($body['category_id'] ?? 0);
        if (!in_array($catId, syc_bulk_category_ids($pdo), true)) { throw new InvalidArgumentException('카테고리를 선택해 주세요.'); }
        $row['brand'] ??= $src['name'];
        $row['external_source'] = $src['code'];
        $row['external_id'] = null;
        $key = product_match_key($row['name'], $row['spec'], $row['spec_option']);
        $dup = $pdo->prepare('SELECT COUNT(*) FROM syc_products WHERE external_source = ? AND name = ? AND spec <=> ? AND spec_option <=> ?');
        $dup->execute([$src['code'], $row['name'], $row['spec'], $row['spec_option']]);
        if ((int) $dup->fetchColumn() > 0) { throw new InvalidArgumentException('같은 업체에 제품명·규격·옵션이 같은 상품이 이미 있습니다. 고칠 때는 일괄 등록으로 올려 주세요.'); }
        $ins = ['category_id' => $catId, 'spec_dims_src' => $row['spec'], 'external_id' => product_auto_external_id($key), 'is_active' => 0] + $row;
        $pdo->prepare('INSERT INTO syc_products (' . implode(', ', array_keys($ins)) . ') VALUES (' . implode(', ', array_fill(0, count($ins), '?')) . ')')
            ->execute(array_values($ins));
        syc_api_out(['ok' => true, 'id' => (int) $pdo->lastInsertId()]);
    }

    if (!PRODUCT_ACTIVE_TOGGLE_ENABLED && in_array($action, ['set_active', 'set_active_filter', 'delete'], true)) {
        throw new InvalidArgumentException(PRODUCT_ACTIVE_TOGGLE_OFF_MSG);
    }

    if ($action === 'set_active') {
        $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? [])), static fn($v) => $v > 0));
        if (!$ids || count($ids) > 500) { throw new InvalidArgumentException('대상 상품이 없습니다.'); }
        $val = (int) ($body['is_active'] ?? 0) === 1 ? 1 : 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE syc_products SET is_active = ? WHERE id IN ({$ph})")->execute(array_merge([$val], $ids));
        syc_api_out(['ok' => true]);
    }

    // 검색 결과 전체(여러 페이지) 활성/비활성: 화면의 목록 조건 그대로. 화면이 본 개수(expect)와 지금 개수가 다르면
    // 그사이 목록이 바뀐 것이므로 바꾸지 않고 다시 확인하게 한다(의도하지 않은 상품까지 바뀌는 것 방지)
    if ($action === 'set_active_filter') {
        $filter = is_array($body['filter'] ?? null) ? $body['filter'] : [];
        $expect = (int) ($body['expect'] ?? -1);
        $val = (int) ($body['is_active'] ?? 0) === 1 ? 1 : 0;
        [$w, $params] = syc_api_list_where($filter, product_source_syc_or_fail($pdo, (string) ($filter['source'] ?? ''))['code']);
        $pdo->beginTransaction();
        $cnt = $pdo->prepare("SELECT COUNT(*) FROM syc_products p WHERE {$w}");
        $cnt->execute($params);
        $now = (int) $cnt->fetchColumn();
        if ($now === 0 || $now !== $expect) {
            $pdo->rollBack();
            throw new InvalidArgumentException($now === 0 ? '대상 상품이 없습니다.' : "그사이 목록이 바뀌었습니다(지금 {$now}개). 목록을 다시 확인해 주세요.");
        }
        $up = $pdo->prepare("UPDATE syc_products p SET p.is_active = :val WHERE {$w}");
        $up->execute(['val' => $val] + $params);
        $pdo->commit();
        syc_api_out(['ok' => true, 'count' => $now, 'changed' => $up->rowCount()]);
    }

    if ($action === 'delete') {
        $id = (int) ($body['id'] ?? 0);
        if ($id <= 0) { throw new InvalidArgumentException('id 필수'); }
        $pdo->prepare('UPDATE syc_products SET is_active = 0 WHERE id = ?')->execute([$id]);
        syc_api_out(['ok' => true]);
    }

    syc_api_out(['ok' => false, 'error' => '알 수 없는 action']);
} catch (InvalidArgumentException $e) {
    syc_api_out(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[api/admin/syc-products] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    syc_api_out(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.']);
}
