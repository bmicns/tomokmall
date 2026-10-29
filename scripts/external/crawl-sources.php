<?php
declare(strict_types=1);
/**
 * 국내 건축·토목자재 제조사 공개 제품 페이지 자동 수집 — 한 번 실행으로 허용된 출처 전부(사용자 PC 또는 네트워크가 허용된 환경)
 *
 * 책임: data/external/sources.json 의 permission 이 'facts_if_clear'(제조사 공개 페이지)인 출처만 대상으로
 *       ① robots.txt 확인(금지 경로는 열지 않음) ② 홈페이지의 이용약관에서 자동수집·무단 복제 금지 문장이 있으면 그 출처는 건너뜀
 *       ③ 시작 URL(sources.json seeds)에서 같은 사이트 제품 페이지를 너비 우선으로 제한 수집(기본 40쪽, 요청 간 2초)
 *       ④ 규격표 행·원문 문장(용도·특징·주의·인증·강도)을 뽑아(app/external_crawl.php) JSON 으로 저장
 *       ⑤ --import 면 기존 가져오기(import.php: 정규화·개인정보 제거·중복 제거·우리 상품 매칭·검증 구역)로 넘기고 보고서를 만든다.
 * 금지: pending(허락 필요)·blocked 출처 접속, 이미지·가격 수집, 원문에 없는 내용 생성, 로그인·장바구니·관리 경로, 빠른 반복 요청,
 *       products·판매가 변경(검증 구역에만, 상담 반영은 사람이 승인한 것만).
 * 사용: php scripts/external/crawl-sources.php [--only=sunroad,88con] [--max-pages=40] [--delay=2000] --import
 *       결과: data/external/crawl/report.md (출처별 상태·쪽수·상품 행·원문 문장·카탈로그 링크·가져오기 결과)
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$root = dirname(__DIR__, 2);
ob_start();
require "{$root}/app/bootstrap.php";
ob_end_clean();
require_once "{$root}/app/ai_tools.php";
require_once "{$root}/app/external_products.php";
require_once "{$root}/app/external_api.php";
require_once "{$root}/app/external_crawl.php";

$opt = getopt('', ['only:', 'max-pages:', 'delay:', 'import']);
$regFile = getenv('EXT_SOURCES_FILE') ?: "{$root}/data/external/sources.json";   // EXT_SOURCES_FILE: 테스트 전용 등록부
$reg = json_decode((string) file_get_contents($regFile), true);
$dir = getenv('EXT_CRAWL_DIR') ?: "{$root}/data/external/crawl";                 // EXT_CRAWL_DIR: 테스트 전용 작업 폴더
@mkdir($dir, 0777, true);
$only = isset($opt['only']) ? array_map('trim', explode(',', (string) $opt['only'])) : null;
$maxPages = max(1, min(200, (int) ($opt['max-pages'] ?? 40)));
$delay = max(1000, (int) ($opt['delay'] ?? 2000));
$ua = 'tomokmall-catalog-reader/1.0 (공개 제품 규격 확인용, robots.txt 준수)';

/** GET — [HTTP 코드(206→200), UTF-8 본문, 최종 URL, Content-Type] */
function crawl_get(string $url, string $ua): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => $ua, CURLOPT_RANGE => '0-1500000']);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    $body = is_string($body) ? $body : '';
    if ($code === 206) { $code = 200; }
    if ($body !== '' && (preg_match('/charset\s*=\s*["\']?(euc-kr|ks_c_5601|cp949)/i', $type . substr($body, 0, 2000)) || !mb_check_encoding($body, 'UTF-8'))) {
        $body = (string) mb_convert_encoding($body, 'UTF-8', 'CP949');
    }
    return [$code, $body, $final, $type];
}

$report = [];
$catalogs = [];
$sources = $reg['sources'] ?? [];
usort($sources, static fn($a, $b) => ($a['crawl_priority'] ?? 99) <=> ($b['crawl_priority'] ?? 99));   // 상담 가치 높은 출처부터
foreach ($sources as $s) {
    if ($only && !in_array($s['key'], $only, true)) { continue; }
    if (($s['permission'] ?? '') !== 'facts_if_clear') {
        if ($only || in_array($s['permission'] ?? '', ['pending', 'blocked'], true) && ($s['domain'] ?? '') !== '') {
            $report[] = [$s['key'], $s['name'], $s['permission'] === 'blocked' ? '수집 안 함(blocked)' : '허락 필요 — 별도 관리(pending)', 0, 0, 0, ''];
        }
        continue;
    }
    echo "== {$s['key']} {$s['name']}\n";
    // ① 홈페이지(https → http) ② robots.txt ③ 약관
    $seeds = array_values(array_unique(array_merge((array) ($s['seeds'] ?? []), ["https://{$s['domain']}/"])));
    [$hc, $home, $homeUrl] = crawl_get("https://{$s['domain']}/", $ua);
    if ($hc !== 200) { [$hc, $home, $homeUrl] = crawl_get("http://{$s['domain']}/", $ua); }
    if ($hc !== 200) { $report[] = [$s['key'], $s['name'], "접속 실패(HTTP {$hc})", 0, 0, 0, '']; continue; }
    $origin = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $homeUrl);
    $seeds = array_map(static fn($u) => preg_replace('#^https?://' . preg_quote($s['domain'], '#') . '#', $origin, $u), $seeds);
    sleep(1);
    [$rc, $robots] = crawl_get("{$origin}/robots.txt", $ua);
    $disallow = ($rc === 200 && stripos($robots, '<html') === false) ? ext_robots_check($robots)['disallow'] : [];
    if (in_array('/', $disallow, true)) { $report[] = [$s['key'], $s['name'], 'robots.txt 전체 수집 금지 — 건너뜀', 0, 0, 0, '']; continue; }
    $termsBan = [];
    if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $home, $m, PREG_SET_ORDER)) {
        $termLinks = [];
        foreach ($m as $a) {
            if (preg_match('/^(javascript:|#)/i', trim($a[1])) || !preg_match('/약관|저작권|agreement|provision|stipulation|terms/iu', strip_tags($a[2]) . ' ' . $a[1])) { continue; }
            $termLinks[] = preg_match('#^https?://#', $a[1]) ? $a[1] : $origin . '/' . ltrim((string) preg_replace('#^\./#', '', html_entity_decode($a[1])), '/');
        }
        foreach (array_slice(array_unique($termLinks), 0, 2) as $t) {
            sleep(1);
            [$tc, $th] = crawl_get($t, $ua);
            if ($tc === 200) { $termsBan = array_merge($termsBan, ext_terms_prohibits(ext_terms_clauses($th))); }
        }
    }
    if ($termsBan) { $report[] = [$s['key'], $s['name'], '이용약관 금지 문장 — 건너뜀: ' . mb_substr($termsBan[0], 0, 60), 0, 0, 0, '']; continue; }

    // ③ 제품 페이지 너비 우선 수집
    $queue = array_map(static fn($u) => [$u, 0], $seeds);
    $seen = [];
    $records = [];
    $pages = 0;
    $factN = 0;
    while ($queue && $pages < $maxPages) {
        [$url, $depth] = array_shift($queue);
        if (isset($seen[$url])) { continue; }
        $seen[$url] = true;
        $path = (string) (parse_url($url, PHP_URL_PATH) ?? '/') . (($q = parse_url($url, PHP_URL_QUERY)) ? '?' . $q : '');
        if (!ext_robots_allows($disallow, $path)) { continue; }
        usleep($delay * 1000);
        [$code, $html, , $ctype] = crawl_get($url, $ua);
        $pages++;
        if ($code !== 200 || ($ctype !== '' && !str_contains(strtolower($ctype), 'html'))) { continue; }
        foreach (ext_page_records($html, $url, $s['name']) as $r) {
            $records[] = $r;
        }
        $factN += array_sum(array_map('count', ext_page_facts(ext_html_blocks($html))));
        if ($depth < 3) {
            [$links, $docs] = ext_page_links($html, $url);
            foreach ($links as $l) { if (!isset($seen[$l])) { $queue[] = [$l, $depth + 1]; } }
            foreach ($docs as $d) { $catalogs[$s['key']][$d] = true; }
        }
    }
    $file = "{$dir}/{$s['key']}-" . date('Ymd-His') . '.json';
    file_put_contents($file, json_encode($records, JSON_UNESCAPED_UNICODE));
    $imp = '';
    if (isset($opt['import']) && $records) {
        $md = "{$dir}/{$s['key']}-import.md";
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/scripts/external/import.php") . ' --source=' . escapeshellarg($s['key'])
            . ' --format=json --license-checked --limit=5000 --file=' . escapeshellarg($file) . ' --md=' . escapeshellarg($md) . ' 2>&1';
        $out = (string) shell_exec($cmd);
        $imp = preg_match('/검증 구역 저장 (\d+)건/u', $out, $mm) ? "저장 {$mm[1]}건" . (preg_match('/유력\(80점 이상\) (\d+)/u', $out, $p1) ? " · 매칭 유력 {$p1[1]}" : '') : '가져오기 실패: ' . mb_substr(trim($out), 0, 80);
    }
    $report[] = [$s['key'], $s['name'], '수집', $pages, count($records), $factN, $imp];
    echo "   쪽 {$pages} · 상품 행 " . count($records) . " · 원문 문장 {$factN}" . ($imp !== '' ? " · {$imp}" : '') . "\n";
}

$md = "# 제조사 공개 자료 자동 수집 결과 (" . date('Y-m-d H:i') . ")\n\n"
    . "- 대상: sources.json permission=facts_if_clear(제조사 공개 제품 페이지). robots.txt 준수, 이용약관 자동수집·무단 복제 금지 문장이 있으면 건너뜀, 요청 간 " . ($delay / 1000) . "초, 출처당 최대 {$maxPages}쪽\n"
    . "- 저장: 규격표 행과 원문 문장(용도·특징·주의·인증·강도)만. 이미지·가격 없음. 상담 반영은 사람이 승인한 것만(import.php --approve)\n\n"
    . "| 출처 | 이름 | 상태 | 쪽 | 상품 행 | 원문 문장 | 가져오기 |\n|---|---|---|---|---|---|---|\n";
foreach ($report as $r) { $md .= '| ' . implode(' | ', array_map(static fn($v) => str_replace('|', '/', (string) $v), $r)) . " |\n"; }
if ($catalogs) {
    $md .= "\n## 카탈로그 파일(PDF·HWP) — 자동으로 읽지 않음(제조사 허락·내용 확인 후 활용)\n\n";
    foreach ($catalogs as $k => $list) { $md .= "- {$k}: " . implode(', ', array_slice(array_keys($list), 0, 5)) . "\n"; }
}
file_put_contents("{$dir}/report.md", $md);
echo "\n보고서: {$dir}/report.md\n";
