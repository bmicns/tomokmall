<?php
declare(strict_types=1);
/**
 * 수집 자료 승인 후보 정리·출처별 승인 — 로컬 테스트 DB(*_test) 의 external_products(검토 대기)를 출처별로 묶어 검토하고, 사람이 고른 출처만 승인
 *
 * 책임: ① 출처별 원문 문장을 문장 단위로 판정(ext_fact_review: 승인 후보 / 보류 + 사유) ② 행 점검(차도·차량 표기인데 두께 80mm 미만, 치수 판독 이상)
 *       ③ 우리 상품 매칭 후보(50점 이상)를 나란히 보여 주고 다른 점(두께·관경·폭·재질·투수·모델)을 표시 → data/external/crawl/approval-candidates.md
 *       ④ --approve=출처,… : 그 출처의 검토 대기 행 중 승인 후보 문장이 있고 행 점검 ⚠ 가 없는 행만 approved — 원문 문장은 승인 후보만 남기고
 *          보류 문장은 attrs_json.facts_held(사유 포함)로 옮김. material_info 는 approved 행의 facts 만 쓴다.
 * 금지: 자동 승인(--approve 없이 실행하면 보고서만), 문장 수정·요약(원문 그대로 남기거나 뺄 뿐), 운영 DB, products·매칭 확정 변경(확정은 import.php --confirm).
 * DB: MariaDB(XAMPP)·MySQL 8.0 모두 가능.
 * 사용: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php scripts/external/approve-crawled.php                (보고서만)
 *       … php scripts/external/approve-crawled.php --approve=gayasteel,toamconcrete                                         (검토 후 출처별 승인)
 *       --revoke=출처,… : 승인 되돌리기(approved → 검토 대기, facts_held 문장을 facts 로 되돌림) — 승인 전 상담 비교·다시 검토할 때
 *       --exclude='문장 일부|문장 일부' : 승인 후보라도 검토자가 뺄 문장(이 글자가 들어간 문장은 '검토자 제외'로 보류) — 보고서·승인 모두 적용
 *       --dir= 보고서 폴더(기본 data/external/crawl) · --only=출처,… 보고서 대상
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

$opt = getopt('', ['dir:', 'only:', 'approve:', 'exclude:', 'revoke:']);
$dir = rtrim((string) ($opt['dir'] ?? (getenv('EXT_CRAWL_DIR') ?: "{$root}/data/external/crawl")), '/\\');
$reg = json_decode((string) file_get_contents(getenv('EXT_SOURCES_FILE') ?: "{$root}/data/external/sources.json"), true);
$srcs = array_column($reg['sources'] ?? [], null, 'key');
$die = static function (string $m): never { fwrite(STDERR, "[중단] {$m}\n"); exit(2); };
$dbName = (string) ($config['db']['name'] ?? '');
$pdo = db();
if (!$pdo) { $die("DB 에 연결하지 못했습니다(DB_HOST='{$config['db']['host']}'). XAMPP MySQL Start 후 새 창에서 DB_HOST=127.0.0.1 DB_NAME=tomokmall_test … 로 실행하세요."); }
if (!preg_match('/_test$/', $dbName)) { $die("로컬 테스트 DB(DB_NAME=*_test)에서만 실행합니다. 현재 '{$dbName}'"); }
if (!ext_tables_ready()) { $die('외부 자료 테이블이 없습니다 — 먼저 scripts/external/import-crawled.php 로 가져오세요.'); }
$approve = isset($opt['approve']) ? array_values(array_filter(array_map('trim', explode(',', (string) $opt['approve'])))) : [];
foreach ($approve as $k) {
    if (!in_array($srcs[$k]['permission'] ?? '', ['facts_if_clear', 'allowed'], true)) { $die("'{$k}' 는 승인할 수 없는 출처입니다(등록부 권한: " . ($srcs[$k]['permission'] ?? '없음') . ')'); }
}
$only = isset($opt['only']) ? array_map('trim', explode(',', (string) $opt['only'])) : null;
$kindKo = ['uses' => '용도', 'features' => '특징', 'cautions' => '주의', 'certs' => '인증', 'strength' => '강도'];
$blocks = ['인조화강블록', '보도블록', '인터로킹블록', '보차도블록', '점토블록', '점자블록', '잔디블록'];

/** 행 점검 — 문제 없으면 '' */
$rowIssue = static function (array $r) use ($blocks): string {
    $t = $r['thickness_mm'] !== null ? (int) $r['thickness_mm'] : null;
    if (in_array($r['product_type'], $blocks, true) && $t !== null && $t < 80 && preg_match('/(?<!지하)차도|차량|주차/u', $r['name'] . ' ' . $r['spec_raw'])) {
        return "차도·차량 표기인데 두께 {$t}mm — 사내 80T 기준과 충돌";
    }
    if (($t !== null && $t > 400) || (int) $r['width_mm'] > 6000 || (int) $r['length_mm'] > 12000) { return '치수 판독 이상(단위 확인)'; }
    return '';
};
/** 원문 문장 판정(같은 출처 문장끼리 조각 판정) — [text => [kind, 사유]] */
$exclude = array_values(array_filter(array_map('trim', explode('|', (string) ($opt['exclude'] ?? '')))));
$judge = static function (array $rows) use ($exclude): array {
    $all = [];
    foreach ($rows as $r) {
        $a = json_decode((string) $r['attrs_json'], true) ?: [];
        foreach ((array) ($a['facts'] ?? []) as $k => $list) { foreach ((array) $list as $t) { $all[(string) $t] ??= $k; } }
    }
    $texts = array_keys($all);
    $out = [];
    foreach ($all as $t => $k) {
        $why = ext_fact_review($t, $texts);
        if ($why === '' && array_filter($exclude, static fn($x) => mb_strpos($t, $x) !== false)) { $why = '검토자 제외(--exclude)'; }
        $out[$t] = [ext_fact_kind($t) ?: $k, $why];
    }
    return $out;
};

$keys = $pdo->query("SELECT DISTINCT source_key FROM external_products ORDER BY source_key")->fetchAll(PDO::FETCH_COLUMN);
$rowsSt = $pdo->prepare('SELECT id, name, product_type, spec_raw, width_mm, length_mm, thickness_mm, diameter_mm, material, attrs_json, source_url, review_status FROM external_products WHERE source_key = ? ORDER BY id');

// ── 승인(사람이 고른 출처만) ───────────────────────────────────────
// ── 승인 되돌리기(사람이 고른 출처만) — 걸러 낸 문장을 원래대로 합쳐 검토 대기로 ─────────────
if (isset($opt['revoke'])) {
    $back = $pdo->prepare("UPDATE external_products SET attrs_json = ?, review_status = 'pending' WHERE id = ? AND review_status = 'approved'");
    foreach (array_values(array_filter(array_map('trim', explode(',', (string) $opt['revoke'])))) as $k) {
        $rowsSt->execute([$k]);
        $n = 0;
        $pdo->beginTransaction();
        foreach ($rowsSt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['review_status'] !== 'approved') { continue; }
            $a = json_decode((string) $r['attrs_json'], true) ?: [];
            foreach ((array) ($a['facts_held'] ?? []) as $h) {
                if (!in_array($h['text'], $a['facts'][$h['kind']] ?? [], true)) { $a['facts'][$h['kind']][] = $h['text']; }
            }
            unset($a['facts_held'], $a['reviewed_at']);
            $back->execute([json_encode($a, JSON_UNESCAPED_UNICODE), (int) $r['id']]);
            $n += $back->rowCount();
        }
        $pdo->commit();
        echo "{$k}: 승인 되돌림 {$n}행(검토 대기)\n";
    }
    exit(0);
}
if ($approve) {
    $up = $pdo->prepare("UPDATE external_products SET attrs_json = ?, review_status = 'approved' WHERE id = ? AND review_status = 'pending'");
    foreach ($approve as $k) {
        $rowsSt->execute([$k]);
        $rows = $rowsSt->fetchAll(PDO::FETCH_ASSOC);
        $j = $judge($rows);
        $n = $skipNoFact = $skipIssue = 0;
        $pdo->beginTransaction();
        foreach ($rows as $r) {
            if ($r['review_status'] !== 'pending') { continue; }
            if ($rowIssue($r) !== '') { $skipIssue++; continue; }
            $a = json_decode((string) $r['attrs_json'], true) ?: [];
            $keep = $held = [];
            foreach ((array) ($a['facts'] ?? []) as $list) {
                foreach ((array) $list as $t) {
                    [$kind, $why] = $j[(string) $t] ?? ['', '판정 없음'];
                    if ($why === '' && !in_array($t, $keep[$kind] ?? [], true)) { $keep[$kind][] = $t; } elseif ($why !== '') { $held[] = ['kind' => $kind, 'text' => $t, 'reason' => $why]; }
                }
            }
            if (!$keep) { $skipNoFact++; continue; }   // 승인할 문장이 없는 행(규격만)은 검토 대기로 둔다
            $a['facts'] = $keep;
            $a['facts_held'] = $held;
            $a['reviewed_at'] = date('Y-m-d');
            $up->execute([json_encode($a, JSON_UNESCAPED_UNICODE), (int) $r['id']]);
            $n += $up->rowCount();
        }
        $pdo->commit();
        echo "{$k}: 승인 {$n}행 · 승인 문장 없는 행 {$skipNoFact}(검토 대기 유지) · 행 점검 ⚠ {$skipIssue}(검토 대기 유지)\n";
    }
    exit(0);
}

// ── 승인 후보 보고서(읽기만) ───────────────────────────────────────
$prodSt = $pdo->prepare('SELECT m.score, m.reasons, m.status, p.id, p.name, p.spec FROM external_product_matches m JOIN products p ON p.id = m.product_id
                         WHERE m.external_product_id = ? AND m.score >= 50 ORDER BY m.score DESC LIMIT 2');
$sum = [];
$body = '';
foreach ($keys as $k) {
    if ($only && !in_array($k, $only, true)) { continue; }
    $rowsSt->execute([$k]);
    $rows = $rowsSt->fetchAll(PDO::FETCH_ASSOC);
    $j = $judge($rows);
    $status = array_count_values(array_column($rows, 'review_status'));
    $types = array_count_values(array_map(static fn($r) => $r['product_type'] ?: '(없음)', $rows));
    arsort($types);
    $ok = array_filter($j, static fn($v) => $v[1] === '');
    $issues = [];
    $withFact = 0;
    foreach ($rows as $r) {
        if (($w = $rowIssue($r)) !== '') { $issues[] = "{$r['id']} {$r['name']} ({$r['spec_raw']}) — {$w}"; }
        $facts = (array) ((json_decode((string) $r['attrs_json'], true) ?: [])['facts'] ?? []);
        if (array_filter(array_merge([], ...array_values($facts)), static fn($t) => ($j[(string) $t][1] ?? 'x') === '')) { $withFact++; }
    }
    // 매칭 후보 — 다른 점 표시
    $mrows = [];
    $diffN = 0;
    foreach ($rows as $r) {
        $prodSt->execute([(int) $r['id']]);
        foreach ($prodSt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $e = ['material' => (string) $r['material'], 'name' => (string) $r['name']] + ext_dims((string) $r['spec_raw']);
            foreach (ext_dims((string) $r['name']) as $f => $v) { $e[$f] ??= $v; }
            $p = ext_normalize_product($m);
            $d = [];
            foreach (['thickness_mm' => '두께', 'diameter_mm' => '관경', 'width_mm' => '폭', 'length_mm' => '길이', 'dim3_mm' => '높이'] as $f => $lb) {
                if (($e[$f] ?? null) !== null && ($p[$f] ?? null) !== null && $e[$f] !== $p[$f]) { $d[] = "{$lb} {$e[$f]}≠{$p[$f]}"; }
            }
            if ($e['material'] !== '' && $p['material'] !== '' && $e['material'] !== $p['material']) { $d[] = "재질 {$e['material']}≠{$p['material']}"; }
            $em = ext_model_code($e['name']); $pm = ext_model_code($p['name']);
            if ($em !== '' && $pm !== '' && $em !== $pm) { $d[] = "모델 {$em}≠{$pm}"; }
            foreach ([['주철', '스틸', '메탈망', 'FRP', 'PVC', 'PE', '레진'], ['1련', '2련', '3련'], ['경하중', '중하중', '안전'], ['유공', '무공', '침투']] as $grp) {   // 이름 구분어: 둘 다 있는데 다르면 다른 상품
                $ew = array_values(array_filter($grp, static fn($w) => mb_stripos($e['name'], $w) !== false));
                $pw = array_values(array_filter($grp, static fn($w) => mb_stripos($p['name'], $w) !== false));
                if ($ew && $pw && $ew !== $pw) { $d[] = '이름 ' . implode('·', $ew) . '≠' . implode('·', $pw); }
            }
            if ((mb_stripos($e['name'], '몰드') !== false) !== (mb_stripos($p['name'], '몰드') !== false)) { $d[] = '몰드(거푸집) 여부 다름'; }
            $soft = str_contains($m['reasons'], '규격') ? [] : ['규격 표기 다름'];
            $verdict = $d ? '다른 상품 가능성' : ($soft || (int) $m['score'] < 70 ? '확인 필요' : '같은 상품 가능 — 확인 후 확정');
            if ($d) { $diffN++; }
            $cell = static fn(string $v) => str_replace('|', '/', mb_substr(trim($v), 0, 50));
            $mrows[] = '| ' . $cell("{$r['id']} {$r['name']} · {$r['spec_raw']}") . ' | ' . $cell("{$m['id']} {$m['name']} · {$m['spec']}") . " | {$m['score']} | " . ($m['reasons'] ?: '-') . ' | '
                . (($d || $soft) ? implode(', ', array_merge($d, $soft)) : '-') . " | {$verdict} |";
        }
    }
    $perm = $srcs[$k]['permission'] ?? '(등록부에 없음)';
    $sum[] = "| {$k} " . ($srcs[$k]['name'] ?? '') . " | {$perm} | " . count($rows) . ' | ' . ($status['pending'] ?? 0) . '·' . ($status['approved'] ?? 0) . '·' . ($status['rejected'] ?? 0)
        . ' | ' . count($ok) . '/' . count($j) . " | {$withFact} | " . count($issues) . ' | ' . count($mrows) . "({$diffN}) |";
    $body .= "\n## {$k} " . ($srcs[$k]['name'] ?? '') . "\n\n- 재사용: 등록부 권한 `{$perm}` — 사실 정보·짧은 원문 문장 + 출처 URL 만, 이미지·가격·설명 전문 없음, 별도 이용 허락은 받지 않음\n"
        . '- 종류: ' . implode(', ', array_map(static fn($t, $c) => "{$t} {$c}", array_keys(array_slice($types, 0, 8, true)), array_slice($types, 0, 8, true))) . "\n"
        . "- 승인하면: 승인 후보 문장이 있는 {$withFact}행이 상담 참고 자료가 된다(나머지 행은 검토 대기 유지)\n\n### 원문 문장 — 승인 후보 " . count($ok) . "\n\n";
    foreach ($ok as $t => [$kind]) { $body .= "- ✅ [" . ($kindKo[$kind] ?? $kind) . "] {$t}\n"; }
    $body .= "\n### 원문 문장 — 보류 " . (count($j) - count($ok)) . "(상담에 쓰지 않음)\n\n";
    foreach ($j as $t => [$kind, $why]) { if ($why !== '') { $body .= "- ⏸ [" . ($kindKo[$kind] ?? $kind) . "] {$t} ← {$why}\n"; } }
    if ($issues) { $body .= "\n### 행 점검 ⚠ (승인해도 검토 대기 유지)\n\n" . implode("\n", array_map(static fn($x) => "- {$x}", array_slice($issues, 0, 30))) . "\n"; }
    if ($mrows) {
        $body .= "\n### 우리 상품 매칭 후보 " . count($mrows) . "(다른 점 있음 {$diffN}) — 확정은 사람이 `import.php --confirm=외부ID:상품ID`\n\n| 외부 행 | 우리 상품 | 점수 | 일치 근거 | 다른 점 | 판단 |\n|---|---|---|---|---|---|\n"
            . implode("\n", array_slice($mrows, 0, 40)) . "\n";
    }
    $body .= "\n승인 명령(검토 후): `php scripts/external/approve-crawled.php --approve={$k}`\n";
}
$md = "# 승인 후보 — 출처별 검토 (" . date('Y-m-d H:i') . ", {$dbName})\n\n"
    . "자동 승인 없음. 문장은 원문 그대로이며, 규칙으로 '승인 후보'와 '보류(사유)'를 나눴다(규칙은 보조 — 후보도 사람이 읽고 승인).\n"
    . "승인하면 후보 문장만 상담(material_info)에 쓰이고, 보류 문장은 facts_held 로 남아 상담에 나오지 않는다. 토목몰 판매 상품·가격과는 별개(참고 자료).\n\n"
    . "| 출처 | 권한 | 행 | 대기·승인·제외 | 문장 후보/전체 | 승인 시 상담 반영 행 | 행 ⚠ | 매칭 후보(다른 점) |\n|---|---|---|---|---|---|---|---|\n"
    . implode("\n", $sum) . "\n" . $body;
@mkdir($dir, 0777, true);
file_put_contents("{$dir}/approval-candidates.md", $md);
echo implode("\n", $sum) . "\n\n보고서: {$dir}/approval-candidates.md\n";
