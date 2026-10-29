<?php
declare(strict_types=1);
/**
 * 조달청_나라장터쇼핑몰 품목정보 서비스(공공데이터포털 15129471) — 공식 명세 확인 → 시험 호출 → 자동 수집 → 기존 가져오기(정규화·매칭)
 *
 * 책임: ① --discover: 공식 상세 페이지와 참고문서(DOCX)를 내려받아 서비스 URL·오퍼레이션·요청변수·출력항목·이용허락범위·트래픽을
 *          data/external/g2b/spec.json 에 기록(명세에서 확인된 것만). ② --probe: 명세의 필수 변수(샘플값)로 1회 호출해 응답 구조 확인.
 *       ③ --collect: 페이지 단위 자동 수집(호출 상한·간격·이어받기) → 출력항목 국문명으로 표준 키 매핑 → JSON 저장 →
 *          --import 면 scripts/external/import.php(정규화·중복 제거·우리 상품 매칭·검증 구역)로 넘김.
 * 금지: 명세에 없는 파라미터 추측(명세 파일이 없거나 해당 변수가 없으면 중단), 인증키를 저장소·로그·보고서에 남기기
 *       (키는 .env.local 의 DATA_GO_KR_SERVICE_KEY 만, 화면에는 가림), 호출 상한 초과, products·판매가 변경(참고 자료로만 보관).
 * 사용(사용자 PC, 저장소 폴더):
 *   php scripts/external/g2b-api.php --discover                     # 공식 명세 확인(인증키 불필요)
 *   php scripts/external/g2b-api.php --discover --doc=참고문서.docx  # 페이지에서 참고문서를 못 받으면 직접 받은 파일로
 *   php scripts/external/g2b-api.php --probe [--op=오퍼레이션]       # 1회 시험 호출
 *   php scripts/external/g2b-api.php --collect [--op=…] [--max-calls=20] [--rows=100] [--param 변수=값] --import
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$root = dirname(__DIR__, 2);
ob_start();
require "{$root}/app/bootstrap.php";   // .env.local 로드(DATA_GO_KR_SERVICE_KEY)
ob_end_clean();
require_once "{$root}/app/ai_tools.php";
require_once "{$root}/app/external_products.php";
require_once "{$root}/app/external_api.php";

$opt = getopt('', ['discover', 'doc:', 'page-html:', 'probe', 'collect', 'op:', 'max-calls:', 'rows:', 'delay:', 'param:', 'import', 'md:', 'limit:', 'license-checked']);
$dir = getenv('EXT_G2B_DIR') ?: "{$root}/data/external/g2b";   // EXT_G2B_DIR: 테스트 전용 작업 폴더
$pageUrl = getenv('EXT_G2B_PAGE_URL') ?: 'https://www.data.go.kr/data/15129471/openapi.do';   // EXT_G2B_PAGE_URL: 테스트 전용
@mkdir("{$dir}/raw", 0777, true);
@mkdir("{$dir}/collected", 0777, true);
$specFile = "{$dir}/spec.json";
$ua = 'tomokmall-g2b-collector/1.0 (official OpenAPI client)';
$die = static function (string $m, int $code = 2): never { fwrite(STDERR, "[중단] {$m}\n"); exit($code); };

/** GET — [HTTP 코드, 본문]. 키가 들어간 URL 은 출력하지 않는다 */
function g2b_get(string $url, string $ua, int $timeout = 30): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3,
        CURLOPT_TIMEOUT => $timeout, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_USERAGENT => $ua]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, is_string($body) ? $body : ''];
}

// ── ① 공식 명세 확인 ─────────────────────────────────────────────
if (isset($opt['discover'])) {
    $html = '';
    if (isset($opt['page-html'])) {
        $html = (string) file_get_contents((string) $opt['page-html']);
    } else {
        [$code, $html] = g2b_get($pageUrl, $ua);
        if ($code !== 200 || $html === '') { $die("공식 상세 페이지를 받지 못했습니다(HTTP {$code}): {$pageUrl}"); }
    }
    file_put_contents("{$dir}/raw/openapi-page.html", $html);
    $blocks = ext_html_blocks($html);
    $pageSpec = ext_api_spec_from_blocks($blocks);
    $sw = ext_swagger_from_html($html);
    $cands = [];
    if ($sw) { $cands['swagger(상세 페이지)'] = ext_api_spec_from_swagger($sw); }
    $cands['상세 페이지 표'] = $pageSpec;
    $docs = isset($opt['doc']) ? [(string) $opt['doc'] => '사용자 제공'] : [];
    foreach (array_slice(ext_doc_links($html, $pageUrl), 0, 3, true) as $u => $t) {
        sleep(1);
        [$dc, $bin] = g2b_get($u, $ua, 60);
        if ($dc !== 200 || $bin === '') { echo "참고문서 받기 실패(HTTP {$dc}): {$t}\n"; continue; }
        $f = "{$dir}/raw/doc-" . substr(sha1($u), 0, 8) . (str_starts_with($bin, "PK") ? '.zip' : '.bin');
        file_put_contents($f, $bin);
        $docs[$f] = $t;
    }
    foreach ($docs as $f => $t) {
        $zip = new ZipArchive();
        if (!is_file($f) || $zip->open($f) !== true) { echo "참고문서 '{$t}' — DOCX 가 아님(HWP·PDF 면 한글/워드에서 DOCX 로 저장해 --doc= 로 다시 실행)\n"; continue; }
        $isDocx = $zip->locateName('word/document.xml') !== false;
        $zip->close();
        if (!$isDocx) { echo "참고문서 '{$t}' — DOCX 가 아님(--doc= 로 DOCX 를 주세요)\n"; continue; }
        $cands["참고문서({$t})"] = ext_api_spec_from_blocks(ext_docx_blocks($f));
    }
    // 가장 완전한 명세(요청·출력이 모두 있는 오퍼레이션이 많은 것) 선택, 이용허락범위·트래픽은 상세 페이지 글에서 보충
    $score = static fn(array $s): int => count(array_filter($s['operations'], static fn($o) => $o['request'] && $o['response']));
    uasort($cands, static fn($a, $b) => $score($b) <=> $score($a));
    $from = (string) array_key_first($cands);
    $spec = $cands[$from];
    $spec['license'] = $spec['license'] ?: $pageSpec['license'];
    $spec['traffic'] = $spec['traffic'] ?: $pageSpec['traffic'];
    $spec['service_url'] = $spec['service_url'] ?: $pageSpec['service_url'];
    $spec += ['page_url' => $pageUrl, 'checked_at' => date('Y-m-d H:i'), 'evidence' => $from];
    if ($score($spec) === 0) {
        file_put_contents($specFile . '.incomplete.json', json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $die("공식 명세에서 요청변수·출력항목 표를 찾지 못했습니다. 상세 페이지의 '참고문서'를 내려받아(DOCX) 다시 실행:\n"
            . "  php scripts/external/g2b-api.php --discover --doc=참고문서.docx\n  (HWP 면 한글에서 '다른 이름으로 저장 → DOCX')");
    }
    file_put_contents($specFile, json_encode($spec, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "명세 근거: {$from} · 서비스 URL: " . ($spec['service_url'] ?: '(없음)') . "\n이용허락범위: " . ($spec['license'] ?: '(페이지에서 못 찾음 — 직접 확인)')
        . "\n트래픽: " . ($spec['traffic'] ?: '(페이지에서 못 찾음 — 활용신청 화면에서 확인)') . "\n\n";
    foreach ($spec['operations'] as $op) {
        $map = ext_api_field_map($op['response']);
        $req = implode(', ', array_map(static fn($p) => $p['name'] . ($p['required'] ? '*' : ''), $op['request']));
        printf("- %s %s\n  요청변수(*필수): %s\n  출력항목 %d개 · 표준 키 매핑: %s\n", $op['name'], $op['title'], $req ?: '(없음)', count($op['response']),
            $map ? implode(', ', array_map(static fn($k, $v) => "{$k}→{$v}", array_keys($map), $map)) : '(없음)');
    }
    $pick = ext_api_pick_op($spec, null);
    echo "\n" . ($pick ? "페이지 단위 수집 가능: {$pick['name']}(pageNo·numOfRows 있음)" : '페이지 변수(pageNo·numOfRows)가 있는 오퍼레이션을 찾지 못함 — --op 로 지정') . "\n저장: {$specFile}\n";
    exit(0);
}

// ── ②·③ 공통: 명세·인증키 ────────────────────────────────────────
if (!is_file($specFile)) { $die("공식 명세가 없습니다. 먼저: php scripts/external/g2b-api.php --discover"); }
$spec = json_decode((string) file_get_contents($specFile), true);
$op = ext_api_pick_op($spec, isset($opt['op']) ? (string) $opt['op'] : null);
if (!$op) { $die('명세에서 오퍼레이션을 찾지 못했습니다(--op 이름 확인): ' . implode(', ', array_column($spec['operations'], 'name'))); }
if (getenv('EXT_G2B_API_BASE')) {   // 테스트 전용: 목업 서버로 보냄
    $op['url'] = (string) preg_replace('#^https?://apis\.data\.go\.kr#', (string) getenv('EXT_G2B_API_BASE'), $op['url']);
}
$key = (string) getenv('DATA_GO_KR_SERVICE_KEY');
if ($key === '') { $die('.env.local 에 DATA_GO_KR_SERVICE_KEY=(공공데이터포털 일반 인증키) 를 넣어 주세요. 키를 채팅·커밋에 넣지 마세요.'); }
$over = [];
foreach ((array) ($opt['param'] ?? []) as $kv) {
    [$k, $v] = array_pad(explode('=', (string) $kv, 2), 2, '');
    if (!ext_api_param($op, $k)) { $die("--param {$k} 는 명세({$op['name']})에 없는 변수입니다(추측 변수는 보내지 않음)."); }
    $over[$k] = $v;
}
$rows = max(1, min(1000, (int) ($opt['rows'] ?? 100)));
$map = ext_api_field_map($op['response']);

// ── ② 시험 호출 1회 ─────────────────────────────────────────────
if (isset($opt['probe'])) {
    [$url, $shown, $missing] = ext_api_build_url($op, $key, $over, 1, min($rows, 10));
    if ($missing) { $die('명세의 필수 변수에 샘플값이 없어 보낼 수 없습니다: ' . implode(', ', $missing) . " — --param 변수=값 으로 지정"); }
    echo "요청: {$shown}\n";
    [$code, $body] = g2b_get($url, $ua);
    file_put_contents("{$dir}/raw/probe-{$op['name']}.txt", $body);
    $r = ext_api_response($body);
    echo "HTTP {$code} · 결과코드 " . ($r['code'] ?: '-') . ' ' . $r['msg'] . ' · 전체 ' . ($r['total'] ?? '-') . '건 · 이번 ' . count($r['items']) . "건\n";
    if ($r['items']) {
        $fields = array_keys($r['items'][0]);
        $known = array_column($op['response'], 'name');
        echo '응답 항목: ' . implode(', ', $fields) . "\n명세에 없는 항목: " . (implode(', ', array_diff($fields, $known)) ?: '없음') . "\n";
        $std = [];
        foreach ($r['items'][0] as $k => $v) { if (isset($map[$k])) { $std[$map[$k]] = mb_substr((string) $v, 0, 40); } }
        echo '표준 키로 본 첫 품목: ' . json_encode($std, JSON_UNESCAPED_UNICODE) . "\n";
    }
    exit(in_array($r['code'], ['00', '0', '03'], true) || ($r['code'] === '' && $r['items']) ? 0 : 1);
}

// ── ③ 자동 수집(이어받기) ────────────────────────────────────────
if (isset($opt['collect'])) {
    if (!in_array('name', $map, true)) { $die('출력항목에서 품명에 해당하는 항목을 찾지 못했습니다 — --discover 결과의 매핑을 확인해 주세요.'); }
    // 이용허락범위: 공식 페이지에 '제한 없음'이면 자동 수집, 아니면 사람이 확인(--license-checked)
    if (!preg_match('/제한\s*없음/u', (string) ($spec['license'] ?? '')) && !isset($opt['license-checked'])) {
        $die('공식 페이지의 이용허락범위가 \'' . ($spec['license'] ?: '확인 안 됨') . '\' 입니다. 직접 확인한 뒤 --license-checked 를 붙여 실행하세요.');
    }
    $maxCalls = max(1, min(500, (int) ($opt['max-calls'] ?? 20)));   // 개발계정 일일 트래픽 안에서(기본 20회)
    $delay = max(500, (int) ($opt['delay'] ?? 1000));
    $stateFile = "{$dir}/state.json";
    $state = is_file($stateFile) ? (json_decode((string) file_get_contents($stateFile), true) ?: []) : [];
    $sk = $op['name'] . '|' . http_build_query($over);
    $page = (int) ($state[$sk]['next_page'] ?? 1);
    $out = [];
    $calls = 0;
    $stop = '';
    $total = $state[$sk]['total'] ?? null;
    while ($calls < $maxCalls) {
        [$url, , $missing] = ext_api_build_url($op, $key, $over, $page, $rows);
        if ($missing) { $stop = '필수 변수 샘플값 없음: ' . implode(', ', $missing); break; }
        [$code, $body] = g2b_get($url, $ua);
        $calls++;
        $r = ext_api_response($body);
        if ($r['code'] !== '' && !in_array($r['code'], ['00', '0'], true)) { $stop = "결과코드 {$r['code']} {$r['msg']}"; break; }
        if ($code !== 200) { $stop = "HTTP {$code}"; break; }
        $total = $r['total'] ?? $total;
        foreach ($r['items'] as $it) {
            $row = [];
            foreach ($it as $k => $v) {
                if (!isset($map[$k]) || trim((string) $v) === '') { continue; }
                $row[$map[$k]] = isset($row[$map[$k]]) ? $row[$map[$k]] . ' ' . $v : (string) $v;
            }
            if ($row) { $out[] = $row; }
        }
        $page++;
        if (!$r['items'] || ($total !== null && ($page - 1) * $rows >= $total)) { $stop = '마지막 페이지'; $page = 1; break; }
        usleep($delay * 1000);
    }
    $state[$sk] = ['next_page' => $page, 'total' => $total, 'updated' => date('Y-m-d H:i')];
    file_put_contents($stateFile, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    $file = "{$dir}/collected/" . date('Ymd-His') . "-{$op['name']}.json";
    file_put_contents($file, json_encode($out, JSON_UNESCAPED_UNICODE));
    echo "호출 {$calls}회 · 받은 품목 " . count($out) . "건 · 전체 " . ($total ?? '-') . "건 · 다음 시작 페이지 {$page}" . ($stop !== '' ? " · 멈춘 이유: {$stop}" : " · 호출 상한({$maxCalls}) 도달 — 다시 실행하면 이어받음") . "\n저장: {$file}\n";
    if (isset($opt['import']) && $out) {
        $md = (string) ($opt['md'] ?? "{$dir}/import-report.md");
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/scripts/external/import.php") . ' --source=' . escapeshellarg(getenv('EXT_G2B_SOURCE') ?: 'g2b_mall_api') . ' --format=json --license-checked'
            . ' --limit=' . (int) ($opt['limit'] ?? 5000) . ' --file=' . escapeshellarg($file) . ' --md=' . escapeshellarg($md);
        passthru($cmd, $rc);
        exit($rc);
    }
    exit($stop !== '' && !in_array($stop, ['마지막 페이지'], true) && !str_starts_with($stop, '결과코드 03') ? 1 : 0);
}

fwrite(STDERR, "사용: --discover | --probe | --collect [--import] (파일 머리말 참고)\n");
exit(2);
