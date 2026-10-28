<?php
declare(strict_types=1);
/**
 * 국가법령정보 OPEN API 로 법령·행정규칙을 받아 기준값(api_targets.json baseline)과 대조 → 보고서.
 *
 * 책임: 검색 → 현행 판 선택 → 본문 조회 → 감시 조문 해시·공포번호·시행일·별표 비교 → 상태와 사람이 할 일 출력.
 * 금지: DB 접속·쓰기, 근거 자동 활성화. 개정·차이가 있으면 '비활성 권고' SQL 초안만 파일로 만든다(첫 줄 안전장치).
 *       인증값(OC)은 getenv('LAW_API_OC') 로만 읽고 출력·저장하지 않는다.
 *
 * 실행:
 *   모의 응답(키 없이):  php scripts/legal-sources/law_api_sync.php --fixtures=tests/fixtures/law_api
 *   실제 API:            LAW_API_OC 를 .env.local 에 넣고 → php scripts/legal-sources/law_api_sync.php
 *   옵션: --targets=api_targets.json  --only=parking_rule,road_struct  --today=YYYY-MM-DD
 *         --md=보고서.md  --json=결과.json  --sql=비활성권고.sql
 * 종료 코드: 0 전부 일치/시행 예정, 3 재검토 필요 항목 있음, 2 설정 오류(키 없음 등)
 */
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__, 2);
require "{$root}/app/law_api.php";

$opt = getopt('', ['fixtures:', 'targets:', 'only:', 'today:', 'md:', 'json:', 'sql:']);
$targetsFile = $opt['targets'] ?? "{$root}/docs/03-analysis/ai-sourcing/legal-sources/api_targets.json";
$clausesFile = dirname($targetsFile) . '/verified_clauses.json';
$today = $opt['today'] ?? date('Y-m-d');
$only = isset($opt['only']) ? array_filter(explode(',', (string) $opt['only'])) : [];
$fixtures = isset($opt['fixtures']) ? rtrim((string) $opt['fixtures'], '/') : null;

// .env.local(로컬 시크릿) — bootstrap 의 세션 시작 없이 키만 읽는다. 이미 설정된 환경변수는 덮지 않음.
if ($fixtures === null && law_api_oc() === '' && is_readable("{$root}/.env.local")) {
    foreach (file("{$root}/.env.local", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*LAW_API_OC\s*=\s*["\']?([^"\'#\s]*)/', $line, $m) && $m[1] !== '') {
            putenv("LAW_API_OC={$m[1]}");
        }
    }
}
$oc = law_api_oc();
if ($fixtures === null && $oc === '') {
    fwrite(STDERR, "LAW_API_OC 미설정 — 국가법령정보 공동활용(open.law.go.kr) 인증값을 .env.local 의 LAW_API_OC= 에 넣거나,\n"
        . "키 없이 확인하려면 --fixtures=tests/fixtures/law_api 로 실행하세요. (키를 채팅·커밋에 넣지 마세요)\n");
    exit(2);
}

$data = json_decode((string) file_get_contents($targetsFile), true);
$clauses = [];
foreach ((array) json_decode((string) @file_get_contents($clausesFile), true) as $c) {
    $clauses[$c['id']] = $c;
}
$index = $fixtures !== null ? (array) json_decode((string) @file_get_contents("{$fixtures}/index.json"), true) : [];

/** 응답 가져오기: 모의 응답이면 index.json 에서 파일, 아니면 실제 API(0.5초 간격) */
$fetch = static function (string $endpoint, array $params) use ($fixtures, $index, $oc): array {
    if ($fixtures !== null) {
        $k = $endpoint === 'lawSearch.do' ? "search:{$params['target']}:{$params['query']}" : "detail:{$params['target']}:" . ($params['MST'] ?? $params['ID'] ?? '');
        $f = $index[$k] ?? null;
        return $f === null ? ['ok' => false, 'error' => '모의 응답 없음'] : ['ok' => true, 'body' => (string) file_get_contents("{$fixtures}/{$f}")];
    }
    usleep(500000);
    return law_api_get(law_api_url($endpoint, $params, $oc));
};

$rows = [];
foreach ($data['documents'] as $doc) {
    if ($only && !in_array($doc['key'], $only, true)) {
        continue;
    }
    $row = ['key' => $doc['key'], 'name' => $doc['name'], 'clauses' => $doc['clauses'], 'status' => 'error', 'note' => ''];
    $res = $fetch('lawSearch.do', ['target' => $doc['target'], 'query' => $doc['name'], 'display' => '20']);
    $err = $res['ok'] ? law_api_error($res['body']) : $res['error'];
    if ($err !== null) {
        $row['status'] = match ($err) { '모의 응답 없음' => 'skipped', '결과 없음' => 'not_found', default => 'error' };
        $row['note'] = "검색: {$err}";
        $rows[] = $row;
        continue;
    }
    $hit = law_api_pick(law_api_parse_search($res['body'])['items'], $doc['name'], $doc['kind']);
    if ($hit === null) {
        $row['status'] = 'not_found';
        $row['note'] = '검색 결과에 같은 이름의 현행 항목 없음(명칭 변경·폐지 확인)';
        $rows[] = $row;
        continue;
    }
    $res = $fetch('lawService.do', ['target' => $doc['target'], $doc['target'] === 'law' ? 'MST' : 'ID' => $hit['key']]);
    $err = $res['ok'] ? law_api_error($res['body']) : $res['error'];
    if ($err !== null) {
        $row['status'] = $err === '모의 응답 없음' ? 'skipped' : 'error';
        $row['note'] = "본문: {$err}";
        $rows[] = $row;
        continue;
    }
    $api = $doc['target'] === 'law' ? law_api_parse_law_detail($res['body']) : law_api_parse_admrule_detail($res['body']);
    $cmp = law_api_compare((array) $doc['baseline'], $api, $today);
    $titles = array_map(static fn($a) => law_api_normalize($a['title']), $api['annexes']);
    $annexMissing = array_values(array_filter($doc['watch_annexes'], static fn($t) => !in_array(law_api_normalize($t), $titles, true)));
    $active = (bool) array_filter($doc['clauses'], static fn($id) => !empty($clauses[$id]['active']));
    $row = array_merge($row, $cmp, [
        'annex_missing' => $annexMissing,
        'annex_links'   => array_values(array_filter($api['annexes'], static fn($a) => in_array(law_api_normalize($a['title']), array_map('law_api_normalize', $doc['watch_annexes']), true))),
        'body_in_file'  => $api['body_in_file'] ?? false,
        'action'        => ($api['body_in_file'] ?? false) && $cmp['status'] === 'same'
            ? '고시 번호·시행일 일치 — 기준 본문은 첨부 파일·KCSC 에서 별도 확인'
            : law_api_action($cmp['status'], $active),
    ]);
    if ($annexMissing) {
        $row['note'] = '감시 별표 제목이 응답에 없음: ' . implode(', ', $annexMissing);
    }
    $rows[] = $row;
}

// 보고서
$label = ['same' => '일치', 'pending' => '시행 예정 개정', 'amended' => '개정됨', 'changed' => '내용 차이', 'missing' => '조문 없음',
          'not_found' => '검색 안 됨', 'error' => '오류', 'skipped' => '건너뜀(모의 응답 없음)'];
$md = "# 법령 API 대조 결과 (" . ($fixtures !== null ? '모의 응답' : '국가법령정보 OPEN API') . ", 기준일 {$today})\n\n"
    . "| 문서 | 상태 | 기준값(미러) 번호·시행일 | API 번호·시행일 | 조문 | 별표 | 연결 근거 | 할 일 |\n|---|---|---|---|---|---|---|---|\n";
foreach ($rows as $r) {
    $arts = isset($r['articles']) ? implode(' ', array_map(static fn($k, $v) => "제{$k}조 " . ($label[$v['status']] ?? $v['status']), array_keys($r['articles']), $r['articles'])) : '';
    $md .= sprintf("| %s | %s | %s | %s | %s | %s | %s | %s |\n",
        $r['name'], $label[$r['status']] ?? $r['status'],
        isset($r['version']) ? "{$r['version']['baseline']['number']} · {$r['version']['baseline']['effective']}" : '',
        isset($r['version']) ? "{$r['version']['api']['number']} · {$r['version']['api']['effective']}" : '',
        $arts ?: (!empty($r['body_in_file']) ? '본문 첨부 파일' : ''),
        isset($r['annexes']) ? ($r['annex_missing'] ? '감시 별표 없음' : "{$r['annexes']}건") : '',
        implode(', ', $r['clauses']) ?: '-',
        trim(($r['action'] ?? '') . ' ' . $r['note']));
}
$counts = array_count_values(array_column($rows, 'status'));
$md .= "\n합계: " . implode(' · ', array_map(static fn($k, $v) => ($label[$k] ?? $k) . " {$v}", array_keys($counts), $counts)) . "\n"
    . "\n※ 자동 활성화 없음. '개정됨·내용 차이·조문 없음'은 사람 검토 후 verified_clauses.json·SQL 초안을 갱신한다.\n";
echo $md;
if (isset($opt['md'])) { file_put_contents((string) $opt['md'], $md); }
if (isset($opt['json'])) { file_put_contents((string) $opt['json'], json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"); }

// 재검토 대상 활성 근거 → 비활성 권고 SQL 초안(실행은 승인 후, 첫 줄 안전장치)
$review = array_filter($rows, static fn($r) => in_array($r['status'], ['amended', 'changed', 'missing'], true));
if (isset($opt['sql'])) {
    $q = static fn(string $s): string => "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
    $sql = "DRAFT_STOP_승인_전_실행_금지;  -- 안전장치: 승인 후 이 줄만 지우고 실행.\n"
        . "-- 생성: scripts/legal-sources/law_api_sync.php ({$today}) — API 대조에서 재검토가 필요한 근거를 비활성·미검토로 되돌림. MySQL 8.0.46 호환.\n";
    foreach ($review as $r) {
        foreach ($r['clauses'] as $id) {
            if (empty($clauses[$id]['active'])) {
                continue;
            }
            $title = mb_substr('[법령] ' . $clauses[$id]['doc_name'] . ' ' . $clauses[$id]['article'], 0, 200);
            $sql .= "-- {$id} {$r['name']}: " . ($label[$r['status']] ?? $r['status']) . "\n"
                . "UPDATE rag_documents SET is_active = 0, review_status = 'unreviewed' WHERE author = '검증 조문(2-1단계)' AND title = " . $q($title) . ";\n";
        }
    }
    file_put_contents((string) $opt['sql'], $sql);
}
exit($review ? 3 : 0);
