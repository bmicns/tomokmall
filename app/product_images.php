<?php
declare(strict_types=1);
/**
 * 상품 이미지 낱장 올리기·경로 확인 — 관리자 제품 수정 창(케이토목·상용콘크리트·업체 공통, 사용자 결정 2026-11-16)
 *
 * 책임: 올린 이미지 파일을 웹 폴더 /uploads/products/<업체 코드>/<내용 해시>.<형식> 에 저장하고 웹 경로를 돌려준다.
 *       '경로 직접 입력' 값(서버 경로 /… 또는 외부 주소)을 확인한다. DB 에는 언제나 경로 글자만 저장한다(일괄 등록 경로 칸과 같은 형식).
 * 안전: 확장자가 아니라 **내용**으로 이미지 형식을 확인(jpg·png·webp·gif), 파일 이름은 내용 해시(사용자 파일 이름을 쓰지 않음 —
 *       .php 같은 이름으로 저장될 수 없음), 한 장 PRODUCT_IMAGE_MAX_BYTES 까지. 같은 이미지는 한 번만 저장. 이전 파일은 지우지 않는다
 *       (다른 상품이 같은 파일을 쓸 수 있음). 업로드 폴더 스크립트 실행 차단: public/uploads/.htaccess(사용자 FileZilla 업로드).
 * 사용: public/api/admin/product-image.php(올리기), public/api/admin/products.php·syc-products.php(수정 저장 때 경로 확인)
 */

require_once __DIR__ . '/syc_bulk.php';   // SYC_BULK_IMAGE_TYPES · syc_bulk_image_path · syc_bulk_image_resolve

const PRODUCT_IMAGE_MAX_BYTES = 10 * 1024 * 1024;   // 한 장 10MB
const PRODUCT_IMAGE_WEB_DIR   = '/uploads/products';

/** 업체 코드 → 폴더 이름(ktomok·syccrt·vendor-<id>만). 아니면 InvalidArgumentException. 순수 함수 */
function product_image_dir_name(string $source): string
{
    if (!preg_match('/^(ktomok|syccrt|vendor-[1-9][0-9]{0,9})$/', $source)) { throw new InvalidArgumentException('업체를 다시 선택해 주세요.'); }
    return $source;
}

/**
 * 올린 파일($_FILES 한 항목)을 저장하고 웹 경로(/uploads/products/<업체>/<해시>.<형식>)를 돌려준다.
 * @param string $webroot 웹루트(public) 실제 경로
 */
function product_image_store_upload(array $file, string $source, string $webroot): string
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { throw new InvalidArgumentException('이미지가 너무 큽니다(한 장 ' . (PRODUCT_IMAGE_MAX_BYTES >> 20) . 'MB 까지).'); }
    if ($err !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) { throw new InvalidArgumentException('이미지 파일을 받지 못했습니다. 다시 골라 주세요.'); }
    $tmp = (string) $file['tmp_name'];
    $size = (int) filesize($tmp);
    if ($size <= 0 || $size > PRODUCT_IMAGE_MAX_BYTES) { throw new InvalidArgumentException('이미지가 너무 큽니다(한 장 ' . (PRODUCT_IMAGE_MAX_BYTES >> 20) . 'MB 까지).'); }
    $info = @getimagesize($tmp);   // 이미지가 아니면 false(경고는 내지 않음 — 아래에서 안내)
    if ($info === false || !isset(SYC_BULK_IMAGE_TYPES[$info[2]])) { throw new InvalidArgumentException('jpg·png·webp·gif 이미지만 올릴 수 있습니다.'); }
    $dirName = product_image_dir_name($source);
    $dir = rtrim($webroot, '/') . PRODUCT_IMAGE_WEB_DIR . '/' . $dirName;
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) { throw new RuntimeException('이미지 폴더를 만들지 못했습니다.'); }
    $name = substr((string) hash_file('sha256', $tmp), 0, 24) . '.' . SYC_BULK_IMAGE_TYPES[$info[2]];
    $dest = $dir . '/' . $name;
    if (!is_file($dest) && !move_uploaded_file($tmp, $dest)) { throw new RuntimeException('이미지를 저장하지 못했습니다.'); }
    return PRODUCT_IMAGE_WEB_DIR . '/' . $dirName . '/' . $name;
}

/**
 * 수정 창 '경로' 칸 값 확인: 빈칸 → null(이미지 비우기), 서버 경로(/…) → 웹루트에 실제 이미지가 있어야 함, 외부 주소(http·https) → 그대로.
 * @return ?string 저장할 경로. 잘못되면 InvalidArgumentException(어느 칸인지 $label 로 안내)
 */
function product_image_path_input(mixed $raw, string $webroot, string $label): ?string
{
    [$img, $err] = syc_bulk_image_path($raw);
    if ($err !== null) { throw new InvalidArgumentException("[{$label}] {$err}"); }
    if ($img === null) { return null; }
    if ($img[0] === 'ext') { return $img[1]; }
    if (syc_bulk_image_resolve($img, $webroot) === null) { throw new InvalidArgumentException("[{$label}] 서버에서 이미지를 찾지 못했습니다({$img[1]})."); }
    return $img[1];
}
