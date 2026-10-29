<?php
declare(strict_types=1);
/**
 * 상담 시나리오별 시스템 프롬프트·근거 확인 — OpenAI 호출 없음(과금 없음).
 *
 * 책임: rag_build_prompt()(운영 상담과 같은 함수)로 시나리오 7개의 프롬프트를 만들어 장소 판정·사내 문서·법령 근거·안내 구역을
 *       요약 출력하고, --out 으로 프롬프트 전문을 파일로 남긴다. 상담 답변은 만들지 않는다(모델 검증은 consult-scenarios.php).
 * 금지: 운영 DB 사용(DB_NAME=*_test 만). 근거 활성 상태는 바꾸지 않는다
 *       (--with-public-road: V21·V22 를 실행 중에만 켜고 끝나면 원래 값으로 되돌림).
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test php scripts/testdb/dump-prompts.php [--with-public-road] [--out=폴더] [--md=요약.md]
 * 시나리오: tests/fixtures/prompts/scenarios.php
 */
if (PHP_SAPI !== 'cli') { exit; }
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$root = dirname(__DIR__, 2);
require "{$root}/app/bootstrap.php";
require "{$root}/app/rag.php";

$opt = getopt('', ['with-public-road', 'out:', 'md:']);
$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName)) {
    fwrite(STDERR, "로컬 테스트 DB(DB_NAME=*_test)에서만 실행합니다. 현재 DB_NAME='{$dbName}'\n");
    exit(2);
}
$pdo = db();
if (!$pdo || !rag_documents_has_source_columns()) {
    fwrite(STDERR, "테스트 DB 연결 실패 또는 법령 근거 칸 없음 — scripts/testdb/setup-local-test-db.sh 로 준비하세요.\n");
    exit(2);
}
$scenarios = require "{$root}/tests/fixtures/prompts/scenarios.php";
$out = isset($opt['out']) ? rtrim((string) $opt['out'], '/\\') : null;
if ($out !== null && !is_dir($out)) {
    mkdir($out, 0777, true);
}

$restore = [];
if (isset($opt['with-public-road'])) {
    $restore = $pdo->query("SELECT id, is_active, review_status FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec("UPDATE rag_documents SET is_active = 1, review_status = 'reviewed' WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'");
}
$state = $restore ? 'V21·V22 실행 중 활성' : 'V21·V22 현재 상태';
$md = "# 상담 프롬프트 확인 (OpenAI 호출 없음, {$state}, " . date('Y-m-d H:i') . ")\n\n"
    . "| # | 시나리오 | 장소 판정(기대) | 사내 문서 | 법령·기준 근거 | 장소 안내 구역 | 80T 규칙 |\n|---|---|---|---|---|---|---|\n";
try {
    foreach ($scenarios as $key => $s) {
        $p = rag_build_prompt($s['messages']);
        $sys = $p['system'];
        $legal = array_map(static fn($d) => $d['source_name'] . ' ' . $d['source_ref'] . (rag_legal_doc_scopes($d) ? ' [@' . implode(',', rag_legal_doc_scopes($d)) . ']' : ''), $p['legalDocs']);
        $section = match (true) {
            str_contains($sys, '=== 공공도로 차도 공사 — 공식 기준 별도 안내') => '공공도로(100mm·근거)',
            str_contains($sys, '=== 공공 보도의 차량 출입부') => '공공 보도(4t·80mm 근거)',
            str_contains($sys, '=== 공공 도로 공사 — 두께 단정 금지') => '공공(근거 없음·단정 금지)',
            str_contains($sys, '=== 차량 블록 시공 장소 확인') => '장소 확인 질문',
            default => '없음',
        };
        $has80 = str_contains($sys, '★차량이 올라가면 두께 80mm 이상(80T) 제품, 사람만 다니면 60mm(60T)도 가능 → [참조 문서] 기준.') ? '원문 그대로' : '없음';
        $md .= sprintf("| %s | %s | %s(%s) | %s | %s | %s | %s |\n", $key, $s['title'], $p['roadCtx'], $s['expect'],
            implode(', ', array_map(static fn($d) => (string) $d['id'], $p['ragDocs'])) ?: '-',
            $legal ? implode(' / ', $legal) : '없음', $section, $has80);
        if ($out !== null) {
            file_put_contents("{$out}/{$key}.txt", $sys);
        }
    }
} finally {
    foreach ($restore as $r) {
        $st = $pdo->prepare('UPDATE rag_documents SET is_active = ?, review_status = ? WHERE id = ?');
        $st->execute([(int) $r['is_active'], $r['review_status'], (int) $r['id']]);
    }
}
echo $md;
if (isset($opt['md'])) { file_put_contents((string) $opt['md'], $md); }
