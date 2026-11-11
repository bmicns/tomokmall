<?php
declare(strict_types=1);
/**
 * 관리자 — 사이트 보기 출처 전환(관리자 기본 = 상용콘크리트 / 케이토목). 그 관리자 세션에만 적용(사용자 결정 2026-11-11)
 *
 * POST source=syc|ktomok [inactive=0|1(상용콘크리트 미리보기에서 비활성 상품도 보기)] return=돌아갈 주소(사이트 안 경로만)
 * 고객·다른 관리자 화면은 바뀌지 않는다. 저장 위치: $_SESSION['site_source'] (app/syc_catalog.php)
 */
require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/syc_catalog.php';

require_admin();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Location: /admin.php', true, 303);
    exit;
}

$source = (string) ($_POST['source'] ?? '');
if ($source === SYC_SOURCE_SYC) {
    $_SESSION[SYC_SOURCE_SESSION_KEY] = SYC_SOURCE_SYC;
    if (isset($_POST['inactive'])) { $_SESSION['syc_preview_inactive'] = $_POST['inactive'] === '0' ? '0' : '1'; }
} else {
    $_SESSION[SYC_SOURCE_SESSION_KEY] = SYC_SOURCE_KTOMOK;
}

// 돌아갈 주소: 사이트 안 경로만('/x' — '//host'·'/\host'·스킴 금지)
$ret = (string) ($_POST['return'] ?? '');
if (!preg_match('#^/(?![/\\\\])[^\r\n]*$#', $ret)) {
    $ret = $source === SYC_SOURCE_SYC ? '/syc-products.php' : '/index.php';
}
header('Location: ' . $ret, true, 303);
exit;
