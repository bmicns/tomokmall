<?php
declare(strict_types=1);
/**
 * 관리자 — 상품 이미지 낱장 올리기(제품 수정 창의 썸네일·상세·규격 이미지 칸, 사용자 결정 2026-11-16)
 * POST multipart {file, source}  → {ok, path}  path = /uploads/products/<업체>/<내용 해시>.<형식>
 *   저장만 하고 상품에는 아직 연결하지 않는다 — 수정 창 [저장] 때 그 경로가 상품 이미지 칸에 들어간다(app/product_images.php).
 * 권한: 관리자만. 오류는 HTTP 200 + ok:false(닷홈이 4xx 본문을 바꿈 — api/admin/products.php 와 같음)
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/product_images.php';

header('Content-Type: application/json; charset=utf-8');

require_admin();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') { throw new InvalidArgumentException('지원하지 않는 요청'); }
    if (!isset($_FILES['file']) || !is_array($_FILES['file'])) { throw new InvalidArgumentException('이미지 파일을 골라 주세요.'); }
    $path = product_image_store_upload($_FILES['file'], (string) ($_POST['source'] ?? ''), __DIR__ . '/../..');
    echo json_encode(['ok' => true, 'path' => $path], JSON_UNESCAPED_UNICODE);
} catch (InvalidArgumentException $e) {
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[api/admin/product-image] ' . get_class($e) . ': ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => '이미지를 저장하지 못했습니다. 잠시 후 다시 시도해 주세요.'], JSON_UNESCAPED_UNICODE);
}
