<?php
declare(strict_types=1);
/**
 * 관리자 — 상용콘크리트 상품(syc_products) 목록·일괄 등록 API (화면: /admin-products.php 구분 '상용콘크리트')
 *
 * GET  ?status=&cat_id=&q=&page=        목록(20개씩)
 * GET  ?action=categories                분류 선택용 카테고리(대분류 › 품목)
 * GET  ?action=progress&job=…            등록 진행률(등록 요청이 처리되는 동안 화면이 읽음)
 * POST multipart action=stage_excel      엑셀(xlsx·csv) 올리기 → 검증 결과(행 수·오류). 파일은 웹 밖 임시 폴더에 보관
 * POST multipart action=stage_zip        이미지 ZIP 올리기 → 이미지 수
 * POST JSON {action:'commit', job}       올린 엑셀(+ZIP)로 등록: 새 상품 비활성, 같은 출처ID 는 덮어쓰기(활성 상태 유지)
 * POST JSON {action:'set_active', ids, is_active} / {action:'delete', id}(비활성화)
 * 권한: 관리자만. 오류는 HTTP 200 + ok:false(닷홈이 4xx 본문을 바꿈 — 기존 api/admin/products.php 와 같음)
 * 로직: app/syc_bulk.php
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/syc_bulk.php';

header('Content-Type: application/json; charset=utf-8');

require_admin();

const SYC_IMG_WEB_PREFIX = '/uploads/syc';

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

try {
    $pdo = db();
    if (!$pdo) { throw new RuntimeException('DB 연결 없음'); }
    $method = $_SERVER['REQUEST_METHOD'];
    $action = (string) ($_GET['action'] ?? $_POST['action'] ?? '');

    if ($method === 'GET') {
        if ($action === 'categories') {
            $rows = $pdo->query('SELECT c.id, c.name, g.name AS group_name FROM syc_categories c JOIN syc_categories g ON g.id = c.parent_id
                                 WHERE c.is_active = 1 ORDER BY g.sort_order, c.sort_order, c.id')->fetchAll();
            syc_api_out(['ok' => true, 'categories' => $rows]);
        }
        if ($action === 'progress') {
            $file = syc_api_progress_file((string) ($_GET['job'] ?? ''));
            $p = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            syc_api_out(['ok' => true, 'progress' => is_array($p) ? $p : null]);
        }
        $catId  = (int) ($_GET['cat_id'] ?? 0);
        $q      = trim((string) ($_GET['q'] ?? ''));
        $page   = max(1, (int) ($_GET['page'] ?? 1));
        $status = (string) ($_GET['status'] ?? 'active');
        $limit  = 20;
        $where  = [];
        $params = [];
        if ($status === 'active') { $where[] = 'p.is_active = 1'; }
        elseif ($status === 'inactive') { $where[] = 'p.is_active = 0'; }
        if ($catId) { $where[] = 'p.category_id = :cat_id'; $params['cat_id'] = $catId; }
        if ($q !== '') {
            $where[] = '(p.name LIKE :q OR p.spec LIKE :q2 OR p.spec_code LIKE :q3 OR p.external_id LIKE :q4)';
            $params['q'] = $params['q2'] = $params['q3'] = $params['q4'] = '%' . $q . '%';
        }
        $w = $where ? implode(' AND ', $where) : '1=1';
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
        [$path, $name, $ext] = syc_api_take_upload(['xlsx', 'csv'], SYC_BULK_MAX_EXCEL_BYTES);
        try {
            $r = syc_bulk_read_excel($path, $ext, syc_bulk_category_ids($pdo));
        } catch (Throwable $e) {
            unlink($path);
            throw $e;
        }
        $_SESSION['syc_bulk']['excel'] = ['path' => $path, 'ext' => $ext, 'name' => $name, 'rows' => count($r['rows']), 'error_count' => $r['error_count']];
        syc_api_out(['ok' => true, 'name' => $name, 'rows' => count($r['rows']), 'data_rows' => $r['data_rows'],
                     'error_count' => $r['error_count'], 'errors' => $r['errors']]);
    }

    if ($action === 'stage_zip') {
        syc_api_drop_stage('zip');
        [$path, $name] = syc_api_take_upload(['zip'], SYC_BULK_MAX_ZIP_BYTES);
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::RDONLY) !== true) { unlink($path); throw new InvalidArgumentException('ZIP 파일을 열 수 없습니다.'); }
        try {
            $n = count(syc_bulk_zip_index($zip));
        } catch (Throwable $e) {
            $zip->close();
            unlink($path);
            throw $e;
        }
        $zip->close();
        if ($n === 0) { unlink($path); throw new InvalidArgumentException('ZIP 안에 이미지(jpg·png·webp·gif)가 없습니다.'); }
        $_SESSION['syc_bulk']['zip'] = ['path' => $path, 'name' => $name, 'images' => $n];
        syc_api_out(['ok' => true, 'name' => $name, 'images' => $n]);
    }

    $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
    $action = (string) ($body['action'] ?? $action);

    if ($action === 'clear') {
        $slot = (string) ($body['slot'] ?? '');
        if (!in_array($slot, ['excel', 'zip'], true)) { throw new InvalidArgumentException('잘못된 요청'); }
        syc_api_drop_stage($slot);
        syc_api_out(['ok' => true]);
    }

    if ($action === 'commit') {
        $ex = $_SESSION['syc_bulk']['excel'] ?? null;
        $zp = $_SESSION['syc_bulk']['zip'] ?? null;
        if (!is_array($ex) || !is_file((string) $ex['path'])) { throw new InvalidArgumentException('엑셀 파일을 먼저 올려 주세요.'); }
        if ($zp !== null && (!is_array($zp) || !is_file((string) $zp['path']))) { throw new InvalidArgumentException('이미지 ZIP 을 다시 올려 주세요.'); }
        $progress = syc_api_progress_file((string) ($body['job'] ?? ''));
        unset($_SESSION['syc_bulk']);
        session_write_close();                 // 진행률 조회 요청이 세션 잠금에 막히지 않게
        ignore_user_abort(true);               // 창을 닫아도 등록은 끝까지
        set_time_limit(300);

        $zip = null;
        try {
            $r = syc_bulk_read_excel((string) $ex['path'], (string) $ex['ext'], syc_bulk_category_ids($pdo));
            if ($r['error_count'] > 0) { throw new InvalidArgumentException('엑셀에 오류가 있는 행이 있어 등록하지 않았습니다. 고친 뒤 다시 올려 주세요.'); }
            $total = count($r['rows']);
            $idx = [];
            if ($zp !== null) {
                $zip = new ZipArchive();
                if ($zip->open((string) $zp['path'], ZipArchive::RDONLY) !== true) { throw new InvalidArgumentException('ZIP 파일을 열 수 없습니다.'); }
                $idx = syc_bulk_zip_index($zip);
            }
            $imgDir = dirname(__DIR__, 2) . '/uploads/syc';
            if ($zip && !is_dir($imgDir) && !mkdir($imgDir, 0755, true) && !is_dir($imgDir)) { throw new RuntimeException('이미지 폴더를 만들지 못했습니다.'); }

            $count = ['insert' => 0, 'update' => 0, 'same' => 0, 'images' => 0, 'images_missing' => 0, 'thumbs_missing' => 0];
            file_put_contents($progress, json_encode(['done' => 0, 'total' => $total, 'finished' => false]));
            $pdo->beginTransaction();
            try {
                foreach ($r['rows'] as $i => $row) {
                    $paths = [];
                    foreach (['image_file', 'image_thumb_file'] as $k) {
                        $want = $row['_' . $k];
                        $paths[$k] = null;
                        if (!$zip) { continue; }                    // ZIP 없이 등록: 이미지 칸은 그대로(새 상품은 빈 이미지)
                        if ($want === null) {
                            if ($k === 'image_thumb_file') { $count['thumbs_missing']++; }
                            continue;
                        }
                        $at = $idx[mb_strtolower($want)] ?? null;
                        $paths[$k] = ($zip && $at !== null) ? syc_bulk_store_image($zip, $at, $imgDir, SYC_IMG_WEB_PREFIX) : null;
                        if ($paths[$k] !== null) { $count['images']++; }
                        elseif ($k === 'image_file') { $count['images_missing']++; }
                        else { $count['thumbs_missing']++; }
                    }
                    $count[syc_bulk_save_row($pdo, $row, $paths['image_file'], $paths['image_thumb_file'], $r['present'])]++;
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
            $zip?->close();
            foreach ([$ex, $zp] as $s) { if (is_array($s) && is_file((string) $s['path'])) { unlink((string) $s['path']); } }   // 성공·실패 모두 임시 파일 삭제
        }
        $result = ['done' => $total, 'total' => $total, 'finished' => true, 'with_zip' => $zip !== null] + $count;
        file_put_contents($progress, json_encode($result));
        syc_api_out(['ok' => true] + $result);
    }

    if ($action === 'set_active') {
        $ids = array_values(array_filter(array_map('intval', (array) ($body['ids'] ?? [])), static fn($v) => $v > 0));
        if (!$ids || count($ids) > 500) { throw new InvalidArgumentException('대상 상품이 없습니다.'); }
        $val = (int) ($body['is_active'] ?? 0) === 1 ? 1 : 0;
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $pdo->prepare("UPDATE syc_products SET is_active = ? WHERE id IN ({$ph})")->execute(array_merge([$val], $ids));
        syc_api_out(['ok' => true]);
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
