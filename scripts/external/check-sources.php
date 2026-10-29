<?php
declare(strict_types=1);
/**
 * 외부 출처 수집 가능성 재확인 — robots.txt 와 홈페이지의 이용약관 링크만 읽는다(사용자 PC에서 실행).
 *
 * 책임: data/external/sources.json 의 도메인마다 /robots.txt 를 받아 전체 차단·상품 경로 차단 여부를 판정하고,
 *       홈페이지에서 '약관·이용약관·저작권·개인정보' 링크를 찾아 보고서에 적는다. 약관 내용 판단은 사람이 한다.
 * 금지: 상품 목록·상세 수집, 로그인, 빠른 반복 요청(도메인당 2회, 요청 간 1초 대기), sources.json 자동 변경.
 * 사용: php scripts/external/check-sources.php [--only=ktomok,dacity] [--terms] [--md=docs/03-analysis/ai-sourcing/external-sources/source-check.md]
 *       --terms: 찾은 이용약관 페이지(출처당 최대 2개)를 열어 자동수집·복제·저작권 관련 문장만 뽑아 보고서에 붙인다(판단은 사람이).
 */
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__, 2);
$opt = getopt('', ['only:', 'md:', 'terms']);
$reg = json_decode((string) file_get_contents(getenv('EXT_SOURCES_FILE') ?: "{$root}/data/external/sources.json"), true);   // EXT_SOURCES_FILE: 테스트 전용 등록부
$only = isset($opt['only']) ? array_map('trim', explode(',', (string) $opt['only'])) : null;
$ua = 'tomokmall-source-check/1.0 (robots.txt and terms link check only)';
$terms = [];

/** GET 1회 — [HTTP 코드, 본문(최대 300KB), 최종 URL]. 실패 시 코드 0 */
function check_get(string $url, string $ua): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => 12, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_USERAGENT => $ua, CURLOPT_RANGE => '0-300000']);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($code === 206) { $code = 200; }   // Range 요청에 대한 정상 부분 응답(206) — 이전 판은 이를 '없음'으로 잘못 판정했다
    if (is_string($body) && !mb_check_encoding($body, 'UTF-8')) { $body = (string) mb_convert_encoding($body, 'UTF-8', 'CP949'); }   // 국내 사이트 EUC-KR
    $final = (string) curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    curl_close($ch);
    return [$code, is_string($body) ? $body : '', $final];
}

/** 약관 본문에서 자동수집·복제·저작권 관련 문장만 (순수 함수, 최대 6문장) */
function check_terms_clauses(string $html): array
{
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(script|style)[^>]*>.*?</\1>#isu', ' ', $html)), ENT_QUOTES, 'UTF-8')));
    $out = [];
    foreach (preg_split('/(?<=[.。다])\s+/u', $text) ?: [] as $sent) {
        if (preg_match('/자동|수집|크롤|스크랩|로봇|복제|전재|무단|저작권|데이터베이스|2차적/u', $sent) && mb_strlen($sent) <= 300) {
            $out[] = $sent;
        }
        if (count($out) >= 6) { break; }
    }
    return $out;
}

/** robots.txt 의 'User-agent: *' 규칙 판정 (순수 함수) */
function check_robots(string $txt): array
{
    $dis = [];
    $active = false;
    foreach (preg_split('/\r\n|\n|\r/', $txt) ?: [] as $line) {
        $line = trim((string) preg_replace('/#.*/', '', $line));
        if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
            $active = trim($m[1]) === '*';
        } elseif ($active && preg_match('/^disallow:\s*(.*)$/i', $line, $m) && trim($m[1]) !== '') {
            $dis[] = trim($m[1]);
        }
    }
    $all = in_array('/', $dis, true);
    $shop = (bool) array_filter($dis, static fn($d) => preg_match('#^/(shop/?$|shop/(list|item|search)|products?|goods|item)#i', $d));
    return ['disallow' => $dis, 'verdict' => $all ? '전체 수집 금지' : ($shop ? '상품 경로 일부 금지' : ($dis ? '일부 경로만 금지(상품 경로 제외)' : '금지 규칙 없음'))];
}

$md = "# 외부 출처 수집 가능성 재확인 (" . date('Y-m-d H:i') . ")\n\n"
    . "robots.txt 와 이용약관 링크만 확인했다. **robots.txt 에 금지가 없어도 약관·저작권(DB 제작자 권리)·제휴 여부는 사람이 판단**한다.\n\n"
    . "| 출처 | 권한(등록부) | robots.txt | 판정 | 약관·정책 링크 |\n|---|---|---|---|---|\n";
foreach ($reg['sources'] ?? [] as $s) {
    $dom = (string) ($s['domain'] ?? '');
    if ($dom === '' || ($only && !in_array($s['key'], $only, true))) { continue; }
    [$code, $body] = check_get("https://{$dom}/robots.txt", $ua);
    if ($code === 0) { [$code, $body] = check_get("http://{$dom}/robots.txt", $ua); }
    $rb = ($code === 200 && stripos($body, '<html') === false) ? check_robots($body) : null;
    sleep(1);
    [$hc, $html, $final] = check_get("https://{$dom}/", $ua);
    if ($hc === 0) { [$hc, $html, $final] = check_get("http://{$dom}/", $ua); }
    $links = [];
    if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $a) {
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($a[2])));
            $raw = trim((string) preg_replace('/\s+/u', '', $a[1]));
            if (preg_match('/^(javascript:|#|mailto:)/i', $raw)) { continue; }   // 팝업 스크립트 링크는 열 수 없음
            if (preg_match('/약관|저작권|개인정보|이메일무단|정책|agreement|provision|policy|terms|stipulation/iu', $text . ' ' . $raw)) {
                $base = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $final);
                $href = str_starts_with($raw, 'http') ? $raw : $base . '/' . ltrim((string) preg_replace('#^\./#', '', $raw), '/');
                $links[$href] = mb_substr($text !== '' ? $text : $raw, 0, 20);
            }
        }
    }
    $robotsCell = $code === 0 ? '접속 실패' : ($rb ? "있음(HTTP {$code})" : "없음(HTTP {$code})");
    $verdict = $rb ? $rb['verdict'] . ($rb['disallow'] ? ' — ' . implode(' ', array_slice($rb['disallow'], 0, 6)) : '') : ($code === 0 ? '확인 불가' : 'robots.txt 없음 — 약관으로 판단');
    $linkCell = $links ? implode('<br>', array_map(static fn($h, $t) => "[{$t}]({$h})", array_keys(array_slice($links, 0, 4, true)), array_slice($links, 0, 4, true))) : ($hc === 0 ? '홈페이지 접속 실패' : '찾지 못함');
    $md .= "| {$s['key']} {$dom} | {$s['permission']} | {$robotsCell} | {$verdict} | {$linkCell} |\n";
    if (isset($opt['terms'])) {
        foreach (array_slice(array_keys(array_filter($links, static fn($t, $h) => preg_match('/약관|저작권|agreement|provision|terms|stipulation/iu', $t . $h), ARRAY_FILTER_USE_BOTH)), 0, 2) as $h) {
            sleep(1);
            [$tc, $th] = check_get($h, $ua);
            $cl = $tc === 200 ? check_terms_clauses($th) : [];
            $terms[] = "### {$s['key']} — {$h} (HTTP {$tc})\n\n" . ($cl ? '- ' . implode("\n- ", $cl) : '- 자동수집·복제·저작권 관련 문장을 찾지 못함(직접 열람 필요)') . "\n";
        }
    }
    echo "{$s['key']}: robots {$robotsCell} · {$verdict}\n";
    sleep(1);
}
if (!empty($terms)) {
    $md .= "\n## 이용약관 관련 문장 (자동 추출 — 판단은 사람이)\n\n" . implode("\n", $terms);
}
$md .= "\n다음: 약관 링크를 열어 자동수집·복제 금지 조항을 확인하고, 허락(제휴·카탈로그 제공)을 받은 출처만 data/external/sources.json 의 permission 을 allowed 로 바꾼다(근거를 basis 에 기록).\n";
if (isset($opt['md'])) { file_put_contents((string) $opt['md'], $md); echo "보고서: {$opt['md']}\n"; }
