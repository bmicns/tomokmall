<?php
declare(strict_types=1);
/**
 * 운영 반영 리허설(scripts/testdb/rehearse-prod-apply.sh) 보조 — 스냅샷·사후 확인 대조·상담 점검. 로컬 *_test DB 전용.
 *
 * 책임: ① snapshot      — 테이블 목록·칸/색인 구성·행 수·CHECKSUM·rag_documents 칸 수·사내 문서 내용 지문(JSON)
 *       ② snap-compare  — 두 스냅샷이 같은지(되돌리기 후 = 적용 전)
 *       ③ legal-check   — prod-apply/30_post_check_readonly.sql 을 줄마다 실행해 끝 주석의 기대값과 대조
 *       ④ ext-check     — 내보내기 30_post_check_readonly.sql 을 실행해 기대 행 수·출처별·지문(내보낼 때 값)과 대조
 *       ⑤ ext-fixture   — 내보내기 원본 DB(*_test)에 '[리허설]' 승인 행을 넣음(운영 자료 아님)
 *       ⑥ probes        — rag_build_prompt()·tool_material_info() 로 상담 근거 점검(OpenAI 호출 없음)
 *       결과는 한 줄씩 'PASS|FAIL<TAB>항목<TAB>내용' 으로 출력(셸이 표로 모음).
 * 금지: 운영 DB 접속(DB_HOST 127.0.0.1/localhost·DB_NAME *_test 만), 공용 tomokmall_test 사용, 확인 SQL 외의 변경(ext-fixture 제외).
 */
if (PHP_SAPI !== 'cli') { exit; }
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$root = dirname(__DIR__, 2);

$mode = $argv[1] ?? '';
$opt = [];
foreach (array_slice($argv, 2) as $a) {
    if (preg_match('/^--([a-z-]+)=(.*)$/s', $a, $m)) { $opt[$m[1]] = $m[2]; }
}
$host = (string) getenv('DB_HOST');
$name = (string) getenv('DB_NAME');
// 로컬 호스트만(포트 지정 '127.0.0.1;port=3308' 허용)
if (!in_array((string) preg_replace('/;port=\d+$/', '', $host), ['127.0.0.1', 'localhost'], true) || !preg_match('/_test$/', $name) || $name === 'tomokmall_test') {
    fwrite(STDERR, "로컬(127.0.0.1/localhost) 리허설 전용 *_test DB 만 허용합니다(공용 tomokmall_test 제외). 현재 '{$host}' / '{$name}'\n");
    exit(2);
}
// --code-root=<폴더>: 상담 점검을 다른 코드(운영 배포 커밋을 git archive 로 푼 폴더)로 — DB 변경이 배포된 코드와도 맞는지
$app = rtrim((string) ($opt['code-root'] ?? $root), '/');
ob_start();
require "{$app}/app/bootstrap.php";
ob_end_clean();
$pdo = db();
if (!$pdo) { fwrite(STDERR, "DB 연결 실패({$name})\n"); exit(2); }
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$fails = 0;
function out(bool $ok, string $item, string $detail = ''): void
{
    global $fails;
    if (!$ok) { $fails++; }
    echo ($ok ? 'PASS' : 'FAIL') . "\t{$item}\t" . str_replace(["\t", "\n"], ' ', $detail) . "\n";
}

/** 스냅샷 — 구조·행 수·내용 지문 */
function snapshot(PDO $pdo): array
{
    $pdo->exec('SET SESSION group_concat_max_len = 16777216');
    $snap = ['tables' => []];
    $tables = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
    $colQ = $pdo->prepare("SELECT GROUP_CONCAT(CONCAT_WS('|', ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, IFNULL(COLUMN_DEFAULT, '<null>'), EXTRA, IFNULL(COLLATION_NAME, ''), COLUMN_COMMENT)
                                  ORDER BY ORDINAL_POSITION SEPARATOR '\n') FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $idxQ = $pdo->prepare("SELECT GROUP_CONCAT(CONCAT_WS('|', INDEX_NAME, SEQ_IN_INDEX, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE) ORDER BY INDEX_NAME, SEQ_IN_INDEX SEPARATOR '\n')
                                  FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    foreach ($tables as $t) {
        $colQ->execute([$t]);
        $idxQ->execute([$t]);
        $cols = (string) $colQ->fetchColumn();
        $snap['tables'][$t] = [
            'columns'  => substr_count($cols, "\n") + ($cols === '' ? 0 : 1),
            'cols_sha' => hash('sha256', $cols),
            'idx_sha'  => hash('sha256', (string) $idxQ->fetchColumn()),
            'rows'     => (int) $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn(),
            'checksum' => (string) ($pdo->query("CHECKSUM TABLE `{$t}`")->fetch(PDO::FETCH_ASSOC)['Checksum'] ?? ''),
        ];
    }
    if (isset($snap['tables']['rag_documents'])) {
        $rows = $pdo->query('SELECT id, title, content, category_key, tags, is_active, sort_order, created_at, updated_at FROM rag_documents ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
        $snap['rag_documents'] = ['columns' => $snap['tables']['rag_documents']['columns'], 'rows' => count($rows),
            'active' => array_sum(array_map(static fn($r) => (int) $r['is_active'], $rows)),
            'content_sha' => hash('sha256', json_encode($rows, JSON_UNESCAPED_UNICODE))];
    }
    return $snap;
}

/** 'SELECT …; -- 주석' 문장 목록 [[sql, 주석(같은 줄 + 뒤 주석 줄)], …] — 줄 단위(30 파일 형식) */
function split_statements(string $file): array
{
    $stmts = [];
    $cur = '';
    foreach (preg_split('/\R/u', $file) as $line) {
        $t = trim($line);
        if ($t === '' ) { continue; }
        if (str_starts_with($t, '--')) {
            if ($cur === '' && $stmts) { $stmts[count($stmts) - 1][1] .= ' ' . ltrim($t, '- '); }
            continue;
        }
        $pos = strrpos($t, ';');
        $after = $pos !== false ? trim(substr($t, $pos + 1)) : '';
        if ($pos !== false && ($after === '' || str_starts_with($after, '--'))) {
            $stmts[] = [trim($cur . ' ' . substr($t, 0, $pos)), ltrim($after, '- ')];
            $cur = '';
        } else {
            $cur .= ' ' . $t;
        }
    }
    return $stmts;
}

function expect_int(string $comment): ?int
{
    return preg_match('/기대\s+(\d+)/u', $comment, $m) ? (int) $m[1] : null;
}

switch ($mode) {
case 'snapshot':
    echo json_encode(snapshot($pdo), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    exit(0);

case 'snap-compare':
    $a = json_decode((string) file_get_contents($opt['before']), true);
    $b = snapshot($pdo);
    $diff = [];
    foreach (array_unique(array_merge(array_keys($a['tables']), array_keys($b['tables']))) as $t) {
        if (!isset($a['tables'][$t])) { $diff[] = "새 테이블 {$t}"; continue; }
        if (!isset($b['tables'][$t])) { $diff[] = "없어진 테이블 {$t}"; continue; }
        foreach ($a['tables'][$t] as $k => $v) {
            if ($b['tables'][$t][$k] !== $v) { $diff[] = "{$t}.{$k}"; }
        }
    }
    out(array_keys($a['tables']) === array_keys($b['tables']), '되돌리기 후 테이블 목록 = 적용 전', count($b['tables']) . '개');
    out(($a['rag_documents']['columns'] ?? -1) === ($b['rag_documents']['columns'] ?? -2), '되돌리기 후 rag_documents 칸 수 = 적용 전', (string) ($b['rag_documents']['columns'] ?? '?'));
    out($a['rag_documents'] === $b['rag_documents'], '되돌리기 후 사내 문서 내용·활성 = 적용 전', ($b['rag_documents']['rows'] ?? '?') . '행 · 활성 ' . ($b['rag_documents']['active'] ?? '?'));
    out($diff === [], '되돌리기 후 전체 스냅샷(구조·색인·행 수·CHECKSUM) = 적용 전', $diff ? implode(', ', $diff) : '차이 없음');
    break;

case 'legal-check':
    // --file=30 --phase=registered|activated --before=snapshot.json --reviewer= --reviewed-on=
    $phase = $opt['phase'] ?? '';
    $before = json_decode((string) file_get_contents($opt['before']), true)['rag_documents'];
    $label = "법령 30 ({$phase})";
    $stmts = split_statements((string) file_get_contents($opt['file']));
    out(count($stmts) >= 10 && !array_filter($stmts, static fn($s) => !preg_match('/^SELECT\s/i', $s[0])), "{$label} 읽기 전용(SELECT 만)", count($stmts) . '문장');
    foreach ($stmts as [$sql, $c]) {
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $alias = array_key_first($rows[0] ?? []) ?? (preg_match('/^SELECT\s+(\w+)\s*,/i', $sql, $m) || preg_match('/AS (\w+)/', $sql, $m) ? $m[1] : '?');
        if ($alias === 'internal_docs') {
            $ok = (int) $rows[0]['internal_docs'] === $before['rows'] && (int) $rows[0]['internal_active'] === $before['active'];
            out($ok, "{$label} internal_docs", "{$rows[0]['internal_docs']}·활성 {$rows[0]['internal_active']} (적용 전 {$before['rows']}·{$before['active']})");
        } elseif ($alias === 'source_type') {
            preg_match_all('/(\w+)=(\d+)/', explode('active:', $c)[0], $mm, PREG_SET_ORDER);
            $want = array_column($mm, 2, 1);
            $wantActive = preg_match('/active:\s*law=(\d+)/', $c, $am) ? (int) $am[1] : -1;
            $got = array_column($rows, null, 'source_type');
            $ok = (int) ($got['internal']['n'] ?? -1) === $before['rows'] && count($got) === count($want) + 1;
            foreach ($want as $k => $n) {
                $act = $k === 'law' ? ($phase === 'activated' ? $wantActive : 0) : 0;
                $ok = $ok && (int) ($got[$k]['n'] ?? -1) === (int) $n && (int) ($got[$k]['active'] ?? -1) === $act;
            }
            out($ok, "{$label} source_type 별", implode(' ', array_map(static fn($r) => "{$r['source_type']}={$r['n']}/{$r['active']}", $rows)) . " (주석: {$c})");
        } elseif ($alias === 'reviewer') {
            $ok = $phase === 'activated'
                ? count($rows) === 1 && $rows[0]['reviewer'] === ($opt['reviewer'] ?? '') && $rows[0]['reviewed_at'] === ($opt['reviewed-on'] ?? '') && (int) $rows[0]['n'] === expect_int(str_replace('n=', '기대 ', (string) strstr($c, 'n=')))
                : $rows === [];
            out($ok, "{$label} reviewer·reviewed_at", $rows ? "{$rows[0]['reviewer']} · {$rows[0]['reviewed_at']} · n={$rows[0]['n']}" : '0행');
        } else {
            $want = expect_int($c);
            if ($alias === 'verified_active' && $phase !== 'activated') { $want = 0; }
            $got = (int) ($rows[0][$alias] ?? -1);
            out($want !== null && $got === $want, "{$label} {$alias}", "{$got} (기대 " . ($want ?? '주석 없음') . ')');
        }
    }
    break;

case 'ext-check':
    // --file=30 --fp=<export-report.md 의 지문> --expect=N
    $want = (int) ($opt['expect'] ?? -1);
    $fp = (string) ($opt['fp'] ?? '');
    $stmts = split_statements((string) file_get_contents($opt['file']));
    out(!array_filter($stmts, static fn($s) => !preg_match('/^(SELECT|SET SESSION group_concat_max_len)\s/i', $s[0])), '제조사 30 읽기 전용(SELECT·SET SESSION 만)', count($stmts) . '문장');
    $seen = [];
    foreach ($stmts as [$sql, $c]) {
        if (stripos($sql, 'SET ') === 0) { $pdo->exec($sql); continue; }
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $alias = array_key_first($rows[0] ?? []) ?? '?';
        if ($alias === 'approved_rows' && array_key_exists('fingerprint', $rows[0])) {
            $cfp = preg_match('/fingerprint\s+([0-9a-f]{64})/', $c, $m) ? $m[1] : '';
            out($cfp !== '' && $cfp === $fp && $rows[0]['fingerprint'] === $fp && (int) $rows[0]['approved_rows'] === $want,
                '제조사 30 지문 = 내보낼 때 지문(export-report.md)', 'DB ' . substr((string) $rows[0]['fingerprint'], 0, 12) . '… · 30 주석 ' . substr($cfp, 0, 12) . '… · 보고서 ' . substr($fp, 0, 12) . "… · {$rows[0]['approved_rows']}행");
            $seen['fp'] = true;
        } elseif ($alias === 'approved_rows') {
            out((int) $rows[0]['approved_rows'] === $want && expect_int($c) === $want, '제조사 30 approved_rows', "{$rows[0]['approved_rows']} (기대 {$want})");
            $seen['rows'] = true;
        } elseif ($alias === 'source_key') {
            preg_match_all('/(\S+):\s*(\d+)/', $c, $mm, PREG_SET_ORDER);
            $wantBy = array_map('intval', array_column($mm, 2, 1));
            $got = array_map('intval', array_column($rows, 'n', 'source_key'));
            out($wantBy !== [] && $got === $wantBy, '제조사 30 출처별 행 수', json_encode($got, JSON_UNESCAPED_UNICODE) . ' (기대 ' . json_encode($wantBy, JSON_UNESCAPED_UNICODE) . ')');
            $seen['src'] = true;
        } else {
            out(expect_int($c) !== null && (int) ($rows[0][$alias] ?? -1) === expect_int($c), "제조사 30 {$alias}", ($rows[0][$alias] ?? '?') . ' (기대 ' . expect_int($c) . ')');
        }
    }
    out(count($seen) === 3, '제조사 30 확인 항목(행 수·출처별·지문) 모두 있음', implode(',', array_keys($seen)));
    break;

case 'ext-fixture':
    // 내보내기 원본 DB(*_test, 운영 아님)에 '[리허설]' 승인 행 3개 + 미승인 1개 — source_key 'rehearsal_fix'
    $ins = $pdo->prepare('INSERT INTO external_products (source_key, external_id, dedup_key, name, maker, product_type, spec_raw, thickness_mm, attrs_json, unit, ref_price, source_url, license_note, fetched_at, review_status)
                          VALUES (?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $f = static fn(array $facts) => json_encode(['facts' => $facts, 'source_name' => '[리허설]제조', 'reviewed_at' => '2026-11-02'], JSON_UNESCAPED_UNICODE);
    $base = ['rehearsal_fix', '[리허설]제조'];
    $ins->execute([$base[0], sha1('reh1'), '[리허설]인터로킹블록 80T', $base[1], '인터로킹블록', '200*100*80', 80,
        $f(['features' => ['[리허설] 인터로킹 블록은 옆 블록과 맞물리도록 만든 포장 블록입니다.', "[리허설] 따옴표 '작은' \"큰\" 역슬래시 \\ 포함 문장입니다."]]), '㎡', 15000, 'http://rehearsal.example/p1', '리허설 고정 자료', '2026-11-02 09:00:00', 'approved']);
    $ins->execute([$base[0], sha1('reh2'), 'PRODUCT 21,500', $base[1], '보도블록', '200*200*60', 60,
        $f(['uses' => ['[리허설] 보도블록 표면은 오염방지 처리를 했습니다.']]), '㎡', null, 'http://rehearsal.example/p2', '리허설 고정 자료', '2026-11-02 09:00:00', 'approved']);
    $ins->execute([$base[0], sha1('reh3'), '[리허설]스틸그레이팅 SG-1', $base[1], '그레이팅', '폭 300', null,
        $f(['features' => ['[리허설] 스틸 그레이팅은 미끄럼 방지를 위해 크로스 바를 사용합니다.']]), '개', null, 'http://rehearsal.example/p3', '리허설 고정 자료', '2026-11-02 09:00:00', 'approved']);
    $ins->execute([$base[0], sha1('reh4'), '[리허설]인터로킹블록 60T', $base[1], '인터로킹블록', '200*100*60', 60,
        $f(['features' => ['[리허설] 미승인 문장 — 이관되면 안 됩니다.']]), '㎡', null, 'http://rehearsal.example/p4', '리허설 고정 자료', '2026-11-02 09:00:00', 'pending']);
    out(true, '제조사 고정 자료(원본 DB) 준비', "승인 3 · 미승인 1 ({$name})");
    break;

case 'probes':
    // --phase=before|ext|registered|activated|rolledback
    require_once "{$app}/app/rag.php";
    require_once "{$app}/app/ai_tools.php";
    $phase = $opt['phase'] ?? '';
    $deployed = $app !== $root;   // 배포 코드: material_info basics 는 아직 없음(새 코드 배포 후 생김)
    $hasLegal = in_array($phase, ['registered', 'activated'], true);
    $hasExt = in_array($phase, ['ext', 'registered', 'activated'], true);
    $sc = require "{$root}/tests/fixtures/prompts/scenarios.php";
    $titles = static fn(array $p) => array_map(static fn($d) => (string) $d['title'], $p['legalDocs']);
    $has = static fn(array $p, string $needle) => (bool) array_filter($p['legalDocs'], static fn($d) => str_contains((string) $d['title'], $needle));
    $scoped = static fn(array $p, string $s) => (bool) array_filter($p['legalDocs'], static fn($d) => in_array($s, rag_legal_doc_scopes($d), true));
    $L = "상담({$phase}" . ($deployed ? ' · 배포 코드 ' . ($opt['code-label'] ?? '') : '') . ')';
    out(rag_documents_has_source_columns() === $hasLegal, "{$L} 근거 칸 인식", $hasLegal ? '칸 있음' : '칸 없음(운영 현재 구조)');
    $p80 = '★차량이 올라가면 두께 80mm 이상(80T) 제품, 사람만 다니면 60mm(60T)도 가능 → [참조 문서] 기준.';

    $s = rag_build_prompt($sc['s3_public_sidewalk']['messages']);
    out($s['roadCtx'] === 'public_sidewalk' && ($phase === 'activated' ? $has($s, '보도 설치 및 관리 지침') && $scoped($s, 'public_sidewalk') : $s['legalDocs'] === []),
        "{$L} 공공 보도", "판정 {$s['roadCtx']} · 근거 " . ($titles($s) ? implode(' / ', array_map(static fn($t) => mb_substr($t, 0, 28), $titles($s))) : '없음'));

    $s = rag_build_prompt($sc['s4_private_yard']['messages']);
    $doc5 = (bool) array_filter($s['ragDocs'], static fn($d) => str_contains((string) $d['title'], '보도블록·경계석 두께') && str_contains((string) $d['content'], '80mm 이상 필수'));
    out($s['roadCtx'] === 'private' && $doc5 && str_contains($s['system'], $p80) && !$scoped($s, 'public_road') && !$scoped($s, 'public_sidewalk'),
        "{$L} 사유지(마당)", "판정 {$s['roadCtx']} · 사내 80T 문서 " . ($doc5 ? '있음' : '없음') . ' · 공공 범위 근거 없음 · 법령 ' . count($s['legalDocs']) . '건');

    $s = rag_build_prompt($sc['s5_apartment_parking']['messages']);
    out($s['roadCtx'] === 'private' && !$scoped($s, 'public_road') && !$scoped($s, 'public_sidewalk') && ($hasLegal && $phase === 'activated' || $s['legalDocs'] === []),
        "{$L} 주차장", "판정 {$s['roadCtx']} · 근거 " . ($titles($s) ? implode(' / ', array_map(static fn($t) => mb_substr($t, 0, 28), $titles($s))) : '없음'));

    foreach (['s1_public_road_80t', 's2_public_road_multi'] as $k) {
        $s = rag_build_prompt($sc[$k]['messages']);
        $bad = $has($s, '44 50 25') || $scoped($s, 'public_road') || str_contains($s['system'], 'KDS 44 50 25') || str_contains($s['system'], 'KCS 44 50 25') || str_contains($s['system'], '100mm');
        out($s['roadCtx'] === 'public_road' && !$bad && str_contains($s['system'], '설계도서'),
            "{$L} 공공도로({$k})", "판정 {$s['roadCtx']} · KDS/KCS 44 50 25 근거 " . ($bad ? '있음' : '없음') . ' · 설계도서 확인 안내');
    }
    if ($hasLegal) {
        $n = (int) db()->query("SELECT COUNT(*) FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND is_active = 1 AND (title LIKE '%KDS 44 50 25%' OR title LIKE '%KCS 44 50 25%')")->fetchColumn();
        out($n === 0, "{$L} V21·V22 비활성", "활성 {$n}");
    }

    if (!empty($opt['real']) && $hasExt) {   // 실제 이관 14행: 승인 출처별 대표 질문이 제조사 원문·출처로 답해지는지, 이름에 가격·쪽 제목이 없는지
        foreach (['스틸 그레이팅' => '가야스틸', '그레이팅 유모차 휠체어' => '가야스틸', '보도블록 오염' => '선로드', '흄관' => '동양'] as $topic => $maker) {
            $r = json_decode(tool_material_info(['topic' => $topic]), true);
            $fs = (array) ($r['facts'] ?? []);
            $okMaker = $fs && !array_filter($fs, static fn($f) => !str_contains((string) $f['maker'], $maker));
            $clean = !array_filter($fs, static fn($f) => preg_match('/PRODUCT|\d{1,3},\d{3}/u', (string) $f['product']));
            $srcOk = (bool) ($r['_sse'][0]['sources'] ?? []) && ($deployed || !empty($r['by_product']));   // 배포 코드(02a98c2)에는 제품별 묶음이 없음(새 코드 배포 후 생김)
            out($okMaker && $clean && $srcOk, "{$L} 실제 자료 '{$topic}'", count($fs) . "문장 · 출처 {$maker} · 제품별 묶음 " . count((array) ($r['by_product'] ?? [])) . ' · 이름 ' . implode('/', array_slice(array_unique(array_column($fs, 'product')), 0, 3)));
        }
        break;
    }
    $mi = json_decode(tool_material_info(['topic' => '인터로킹 블록']), true);
    $facts = (array) ($mi['facts'] ?? []);
    $srcs = (array) ($mi['_sse'][0]['sources'] ?? []);
    $std = array_column((array) ($mi['basics']['standards'] ?? []), 'ref');
    $prods = (array) ($mi['basics']['products'] ?? []);
    if ($hasExt) {
        $fixOnly = $facts && !array_filter($facts, static fn($f) => !str_starts_with((string) $f['text'], '[리허설]') || str_contains((string) $f['text'], '미승인'));
        $quoted = (bool) array_filter($facts, static fn($f) => $f['text'] === "[리허설] 따옴표 '작은' \"큰\" 역슬래시 \\ 포함 문장입니다.");
        $clean = !array_filter($facts, static fn($f) => str_contains((string) $f['product'], '21,500') || str_contains((string) $f['product'], 'PRODUCT'));
        out($fixOnly && $quoted && $clean && $srcs && !array_filter($srcs, static fn($x) => !str_starts_with((string) $x['url'], 'http://rehearsal.example/')),
            "{$L} material_info 제조사 자료", count($facts) . '문장 · 출처 ' . count($srcs) . ' · 따옴표·역슬래시 원문 그대로 · 이름 정리(' . implode('/', array_unique(array_column($facts, 'product'))) . ')');
    } else {
        out($facts === [] && $srcs === [], "{$L} material_info 제조사 자료 없음", '외부 자료 테이블 없음 → 자료 없음 안내');
    }
    $wantStd = $hasLegal;
    if ($deployed) {
        out(!isset($mi['basics']), "{$L} material_info basics 없음(새 코드 배포 후 생김)", isset($mi['basics']) ? 'basics 있음' : '없음');
        break;
    }
    out((in_array('KS F 4419', $std, true) === $wantStd) && $prods !== [] && (!$wantStd || isset($mi['basics_note'])),
        "{$L} material_info basics", 'KS ' . ($std ? implode(',', $std) : '없음') . ' · 등록 상품 ' . count($prods) . '종(' . mb_substr((string) ($prods[0]['name'] ?? ''), 0, 16) . ')');
    break;

default:
    fwrite(STDERR, "사용: php scripts/testdb/rehearse-prod-apply.php snapshot|snap-compare|legal-check|ext-check|ext-fixture|probes [--옵션=값]\n");
    exit(2);
}
exit($fails ? 1 : 0);
