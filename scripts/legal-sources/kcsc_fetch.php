<?php
declare(strict_types=1);
/**
 * 국가건설기준센터(KCSC) OpenAPI 조회 — 설계기준(KDS)·표준시방서(KCS) 목록·본문을 읽어 상담 근거 후보(조항 요지)를 사람이 확인할 수 있게 보여 준다.
 *
 * 인증키: 환경변수 KCSC_API_KEY 로만 읽는다(클라우드 환경 설정의 환경 변수, 또는 사용자 PC .env.local 의 KCSC_API_KEY=). 출력·저장·커밋 금지(kcsc_mask).
 * 책임: ① --check 키 확인(목록 건수, 잘못된 키의 null 응답 감지) ② --find 이름으로 기준 찾기 ③ --code 본문 절 목록·낱말 검색
 *       원본 응답은 --out 폴더(저장소 밖만)에 저장 — 기준 본문을 저장소에 넣거나 재게시하지 않는다.
 * 금지: 자동으로 verified_clauses.json 에 넣지 않는다(새 근거는 사람이 조항을 읽고 요지만 기록 → 기존 검증·승인 순서).
 * 사용: php scripts/legal-sources/kcsc_fetch.php --check
 *       php scripts/legal-sources/kcsc_fetch.php --find='하수,관로' [--type=KCS] [--out=저장소 밖 폴더]
 *       php scripts/legal-sources/kcsc_fetch.php --code='KCS 61 40 10' [--grep=관경] [--out=저장소 밖 폴더]
 *       --no-dotenv: .env.local 을 읽지 않음(환경변수만)
 */
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__ . '/kcsc_lib.php';
$root = dirname(__DIR__, 2);
$opt = getopt('', ['check', 'find:', 'type:', 'code:', 'grep:', 'out:', 'no-dotenv']);
$fail = static function (string $m, string $key = ''): never { fwrite(STDERR, '[중단] ' . kcsc_mask($m, $key) . "\n"); exit(2); };

// 키: 환경변수 → (없으면) .env.local 의 KCSC_API_KEY= 줄 — 값은 어디에도 출력하지 않는다
$key = trim((string) (getenv('KCSC_API_KEY') ?: ''));
if ($key === '' && !isset($opt['no-dotenv']) && is_readable("{$root}/.env.local")) {
    foreach ((array) file("{$root}/.env.local", FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^(?:\xEF\xBB\xBF)?\s*KCSC_API_KEY\s*=\s*["\']?([^"\'#\s]*)/', (string) $line, $m) && $m[1] !== '') { $key = $m[1]; }
    }
}
if ($key === '') {
    $fail("KCSC_API_KEY 가 없습니다. 클라우드 세션: 환경 설정(세션 제목줄의 클라우드 환경 메뉴 → Edit)에 환경 변수 KCSC_API_KEY 추가 후 새 세션. 사용자 PC: .env.local 에 KCSC_API_KEY= 한 줄(채팅·커밋 금지).");
}
$out = isset($opt['out']) ? rtrim((string) $opt['out'], '/') : '';
if ($out !== '') {
    $abs = str_starts_with($out, '/') ? $out : getcwd() . '/' . $out;
    if (str_starts_with((realpath($abs) ?: $abs) . '/', realpath($root) . '/')) { $fail("출력 폴더가 저장소 안입니다({$abs}) — 기준 본문 원본은 저장소 밖에 둡니다."); }
    if (!is_dir($abs) && !mkdir($abs, 0700, true)) { $fail("출력 폴더를 만들지 못했습니다: {$abs}"); }
    $out = $abs;
}

/** GET → JSON (키는 오류 메시지에서 가림). 429·5xx 는 2·4·8초 뒤 3번까지 다시 */
$get = static function (string $path) use ($key, $fail): mixed {
    for ($i = 0; $i < 4; $i++) {
        $ch = curl_init(kcsc_url($path, $key));
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 90, CURLOPT_CONNECTTIMEOUT => 15, CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_USERAGENT => 'tomokmall-kcsc-reader/1.0 (기준 조항 확인용)', CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body !== false && $code === 200) {
            $data = json_decode((string) $body, true);
            if ($data === null) { $fail("JSON 이 아닌 응답(HTTP 200, " . strlen((string) $body) . "바이트) — 응답 형식이 바뀌었을 수 있습니다: {$path}", $key); }
            return $data;
        }
        if (in_array($code, [401, 403], true)) { $fail("인증키가 거부되었습니다(HTTP {$code}). 키 값·발급 상태를 확인하세요.", $key); }
        if ($i < 3 && ($code === 429 || $code >= 500 || $body === false)) { sleep(2 ** ($i + 1)); continue; }
        $fail("요청 실패(HTTP {$code}" . ($err !== '' ? ", {$err}" : '') . "): {$path}", $key);
    }
    $fail("요청 실패(재시도 초과): {$path}", $key);
};
$save = static function (string $name, mixed $data) use ($out): void {
    if ($out !== '') { file_put_contents("{$out}/{$name}", json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)); }
};

if (isset($opt['check']) || isset($opt['find'])) {
    $list = $get('CodeList');
    if (kcsc_is_null_sentinel($list)) { $fail('키가 틀린 것으로 보입니다 — 목록이 값이 모두 비어 있는 1건으로 왔습니다(KCSC 는 잘못된 키에 오류 대신 이렇게 답함).'); }
    $list = is_array($list) ? array_values(array_filter($list, 'is_array')) : [];
    $save('CodeList.json', $list);
    $types = array_count_values(array_map(static fn($r) => (string) ($r['codeType'] ?? '?'), $list));
    arsort($types);
    echo '목록 ' . count($list) . '건 · 종류 ' . implode(', ', array_map(static fn($t, $n) => "{$t} {$n}", array_keys($types), $types)) . "\n";
    if (isset($opt['find'])) {
        $words = array_map('trim', explode(',', (string) $opt['find']));
        $type = isset($opt['type']) ? strtoupper((string) $opt['type']) : null;
        foreach (kcsc_find($list, $words, $type) as $r) {
            echo "- {$r['display']} {$r['name']} (판 {$r['version']}, 갱신 {$r['updated']})\n";
        }
    }
    exit(0);
}
if (isset($opt['code'])) {
    $tc = kcsc_normalize_code((string) $opt['code']);
    if ($tc === null) { $fail("--code 형식: 'KCS 61 40 10' 또는 'KDS 14 31 10'(6자리, 8자리도 가능)"); }
    $data = $get("CodeViewer/{$tc[0]}/{$tc[1]}");
    $doc = kcsc_document($data);
    if ($doc === null || kcsc_is_null_sentinel($data)) { $fail("본문 없음(또는 키 오류): {$tc[0]} {$tc[1]} — 상위 분류(xx0000)는 본문이 없습니다."); }
    $save("{$tc[0]}-{$tc[1]}.json", $data);
    $secs = kcsc_sections($doc);
    echo "{$tc[0]} {$tc[1]} " . ($doc['name'] ?? '') . ' (판 ' . ($doc['version'] ?? '') . ', 갱신 ' . substr((string) ($doc['updateDate'] ?? ''), 0, 10) . ') · 절 ' . count($secs) . "개\n";
    if (isset($opt['grep'])) {
        foreach (kcsc_grep($secs, (string) $opt['grep']) as $g) { echo "- [{$g['label']}] {$g['title']} … {$g['snippet']}\n"; }
    } else {
        foreach (array_slice($secs, 0, 60) as $s) { if ($s['level'] <= 2) { echo str_repeat('  ', max(0, $s['level'] - 1)) . "- {$s['label']} {$s['title']}\n"; } }
    }
    exit(0);
}
$fail('사용법: --check | --find=낱말,낱말 [--type=KCS] | --code="KCS 61 40 10" [--grep=낱말] [--out=저장소 밖 폴더]');
