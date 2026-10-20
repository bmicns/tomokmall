<?php
declare(strict_types=1);

/**
 * 시안 프레임 목록 — frames/*.html 파일을 읽어 반환.
 * 파일명이 _ 로 시작하면(예: _template.html) 목록에서 제외.
 * 제목은 각 HTML의 <title>, 원본 Figma 링크는 <meta name="figma-url"> 에서 읽는다.
 */

/** @return list<array{slug:string,title:string,figma:string}> */
function preview_frames(): array
{
    $frames = [];
    foreach (glob(__DIR__ . '/frames/*.html') ?: [] as $file) {
        $slug = basename($file, '.html');
        if ($slug[0] === '_' || !preg_match('/^[a-z0-9-]+$/', $slug)) {
            continue;
        }
        $html  = (string) file_get_contents($file, false, null, 0, 4096);
        $title = preg_match('/<title>(.*?)<\/title>/s', $html, $m) ? trim($m[1]) : $slug;
        $figma = preg_match('/<meta\s+name="figma-url"\s+content="([^"]*)"/', $html, $m) ? $m[1] : '';
        $frames[] = ['slug' => $slug, 'title' => html_entity_decode($title, ENT_QUOTES), 'figma' => $figma];
    }
    return $frames;
}

function h(string $v): string
{
    return htmlspecialchars($v, ENT_QUOTES);
}
