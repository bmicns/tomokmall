<?php
declare(strict_types=1);
/**
 * 관리자 — 상용콘크리트 사이트 반영·전환 API (화면: /admin-products.php 구분 '상용콘크리트' 의 '사이트 반영' 상자)
 *
 * GET                                  상태: 준비 여부(DB 반영), 지금 출처(ktomok|syc), 마지막 반영·전환 시각, 반영 미리보기(개수)
 * POST JSON {action:'publish'}         원본(syc_*) → 사이트 상품 표(categories·category_spec_axes·products) 반영. 지금 출처 그대로
 * POST JSON {action:'switch', to}      to='syc': 케이토목 숨기고 상용콘크리트 보이기 / to='ktomok': 되돌리기
 * 권한: 관리자만. 오류는 HTTP 200 + ok:false(기존 관리자 API 와 같음). 로직: app/syc_publish.php
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/syc_publish.php';

header('Content-Type: application/json; charset=utf-8');

require_admin();

function syc_cat_out(array $d): never
{
    echo json_encode($d, JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();
    if (!$pdo) { throw new RuntimeException('DB 연결 없음'); }
    if (!syc_tables_ready() || !syc_publish_ready($pdo)) {
        syc_cat_out(['ok' => true, 'ready' => false, 'message' => 'DB 준비 전입니다(database/20261112_catalog_switch.sql 반영 필요).']);
    }
    $user = current_user();
    $uid = isset($user['id']) ? (int) $user['id'] : null;

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $st = $pdo->query('SELECT mode, switched_at, published_at FROM syc_catalog_state WHERE id = 1')->fetch() ?: ['mode' => 'ktomok', 'switched_at' => null, 'published_at' => null];
        $hidden = $pdo->query("SELECT entity, COUNT(*) n FROM syc_catalog_hidden GROUP BY entity")->fetchAll(PDO::FETCH_KEY_PAIR);
        syc_cat_out(['ok' => true, 'ready' => true, 'state' => $st, 'plan' => syc_publish_plan($pdo),
                     'hidden' => ['category' => (int) ($hidden['category'] ?? 0), 'product' => (int) ($hidden['product'] ?? 0)]]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { syc_cat_out(['ok' => false, 'error' => '지원하지 않는 요청']); }

    $body = json_decode((string) file_get_contents('php://input'), true) ?? [];
    $action = (string) ($body['action'] ?? '');
    set_time_limit(300);
    if ($action === 'publish') {
        syc_cat_out(['ok' => true, 'result' => syc_publish_run($pdo, $uid)]);
    }
    if ($action === 'switch') {
        syc_cat_out(['ok' => true, 'result' => syc_catalog_switch($pdo, (string) ($body['to'] ?? ''), $uid)]);
    }
    syc_cat_out(['ok' => false, 'error' => '알 수 없는 action']);
} catch (InvalidArgumentException $e) {
    syc_cat_out(['ok' => false, 'error' => $e->getMessage()]);
} catch (Throwable $e) {
    error_log('[api/admin/syc-catalog] ' . get_class($e) . ': ' . $e->getMessage());
    http_response_code(500);
    syc_cat_out(['ok' => false, 'error' => '서버 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.']);
}
