<?php
declare(strict_types=1);

/**
 * 디자인 검토용 미리보기 서버 라우터 (PHP 내장 서버 전용)
 *
 * 실행: bash scripts/design-preview.sh
 *       (= php -S 0.0.0.0:8090 design-preview/router.php)
 *
 * 책임: /design-preview/ 아래 시안 HTML·CSS 제공, /assets/* 는 public/assets 를 읽기 전용으로 제공.
 * 금지: DB 접속·세션·app/ 로직 사용 금지. 이 폴더는 deploy.sh 업로드 대상(public/app/database)이 아니므로
 *       운영 서버에 올라가지 않는다. public/ 로 옮기지 말 것.
 */

$root   = __DIR__;
$public = realpath(__DIR__ . '/../public');
$path   = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));

/** 지정 폴더 안의 실제 파일만 반환(경로 이탈 차단). 없으면 null */
function preview_resolve(string $base, string $rel): ?string
{
    $file = realpath($base . '/' . ltrim($rel, '/'));
    if ($file === false || !is_file($file) || !str_starts_with($file, $base . DIRECTORY_SEPARATOR)) {
        return null;
    }
    return $file;
}

function preview_send(string $file): void
{
    $types = [
        'html' => 'text/html; charset=utf-8', 'css' => 'text/css; charset=utf-8',
        'js' => 'application/javascript; charset=utf-8', 'svg' => 'image/svg+xml',
        'png' => 'image/png', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'webp' => 'image/webp',
        'gif' => 'image/gif', 'woff2' => 'font/woff2', 'woff' => 'font/woff',
    ];
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if (!isset($types[$ext])) {
        http_response_code(404);
        return;
    }
    header('Content-Type: ' . $types[$ext]);
    header('Cache-Control: no-store');   // 시안 수정 즉시 반영
    readfile($file);
}

if ($path === '/' || $path === '/design-preview') {
    header('Location: /design-preview/');
    return true;
}

// 미리보기 목록·뷰어
if ($path === '/design-preview/') {
    require $root . '/index.php';
    return true;
}
if ($path === '/design-preview/view') {
    require $root . '/view.php';
    return true;
}

// 시안 정적 파일 (frames/*.html, preview.css, images 등). PHP 파일은 직접 실행하지 않는다.
if (str_starts_with($path, '/design-preview/')) {
    $file = preview_resolve($root, substr($path, strlen('/design-preview/')));
    if ($file !== null && !str_ends_with($file, '.php')) {
        preview_send($file);
        return true;
    }
}

// 기존 이미지·폰트·CSS 재사용 (읽기 전용)
if (str_starts_with($path, '/assets/') && $public !== false) {
    $file = preview_resolve($public, $path);
    if ($file !== null) {
        preview_send($file);
        return true;
    }
}

http_response_code(404);
header('Content-Type: text/plain; charset=utf-8');
echo '404 — 디자인 미리보기 서버에는 시안 페이지만 있습니다. 실제 사이트는 scripts/local-up.sh 로 실행하세요.';
return true;
