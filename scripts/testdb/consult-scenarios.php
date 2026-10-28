<?php
declare(strict_types=1);
/**
 * 실제 LLM 상담 시나리오 테스트(OpenAI 호출) — 공공도로 100mm / 공공 보도 80mm / 사유지 80T 구분 확인용.
 *
 * 책임: 테스트 DB 사전 점검 → rag_process()로 시나리오별 실제 답변을 받아 답변·인용 근거·판정을 출력(사람이 최종 확인).
 * 금지: 운영 DB 사용. DB_NAME 이 '_test' 로 끝나는 로컬 DB 에서만 실행. 근거 활성 상태는 바꾸지 않는다.
 *       (--with-public-road 를 주면 V21·V22 를 실행 중에만 켜고, 중간에 오류가 나도 끝나면 원래 값으로 되돌림 — 테스트 DB 전용)
 * 필요: .env.local 의 OPENAI_API_KEY, scripts/testdb/setup-local-test-db.sh 로 만든 로컬 테스트 DB(SQL 초안 01·02·06·07 적용).
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test php scripts/testdb/consult-scenarios.php [--with-public-road] [--md=결과.md]
 *       --check-only : DB 사전 점검만 하고 OpenAI 는 호출하지 않음
 * 종료 코드: 0 실패 없음(확인 필요는 사람 검토), 1 실행 실패·판정 실패 있음, 2 설정·DB 구성 오류
 * 비용: 시나리오 7개 × OpenAI 호출(도구 호출 포함) — 소량.
 */
if (PHP_SAPI !== 'cli') { exit; }
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/rag.php';

$opt = getopt('', ['with-public-road', 'md:', 'check-only']);
$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName)) {
    fwrite(STDERR, "로컬 테스트 DB(DB_NAME=*_test)에서만 실행합니다. 현재 DB_NAME='{$dbName}'\n");
    exit(2);
}

// ── 사전 점검: 실제로 연결된 DB 와 상담 테스트에 필요한 구조·데이터 ─────────────────────────────
$fail = static function (string $msg): never {
    fwrite(STDERR, "\n[사전 점검 실패] {$msg}\n");
    exit(2);
};
$pdo = db();
if (!$pdo) {
    $fail("DB 에 연결하지 못했습니다(DB_HOST={$config['db']['host']}, DB_NAME={$dbName}, DB_USER={$config['db']['user']}). "
        . "DB 서버 실행 여부와 .env.local 의 DB_USER·DB_PASS 를 확인하세요.");
}
$info = $pdo->query('SELECT VERSION() AS v, DATABASE() AS d, CURRENT_USER() AS u, @@port AS p')->fetch(PDO::FETCH_ASSOC);
echo "사전 점검 — 연결: {$info['v']} · DB {$info['d']} · 사용자 {$info['u']} · 포트 {$info['p']} (호스트 {$config['db']['host']})\n";
try {
    $cols = $pdo->query('SHOW COLUMNS FROM rag_documents')->fetchAll(PDO::FETCH_COLUMN);
} catch (\Throwable $e) {
    $fail("'{$info['d']}' 에 rag_documents 테이블이 없습니다. 테스트 DB 를 다시 만드세요:\n"
        . "  MYSQL_CMD=\"/c/xampp/mysql/bin/mysql.exe -h127.0.0.1 -uroot\" bash scripts/testdb/setup-local-test-db.sh --reset");
}
// 법령 근거 출처 칸 — SQL 초안 01(rag_documents 출처·검토·확인수준 칸)이 추가하는 칸. 기존 9개 칸에는 없다.
$need = ['source_type', 'source_name', 'source_ref', 'source_url', 'effective_date', 'applies_to', 'legal_status', 'author', 'review_status', 'verify_level'];
$missing = array_values(array_diff($need, $cols));
if ($missing) {
    $fail("'{$info['d']}'.rag_documents 에 법령 근거 칸이 없습니다: " . implode(', ', $missing) . "\n"
        . "  → SQL 초안 01(docs/03-analysis/ai-sourcing/sql-drafts/01_…)이 이 DB 에 적용되지 않았습니다.\n"
        . "    설정 스크립트가 만든 DB 와 PHP 가 연결한 DB 가 다른지(위 '연결' 줄의 DB·포트) 확인하고, 같은 DB 로 다시 만드세요:\n"
        . "  MYSQL_CMD=\"/c/xampp/mysql/bin/mysql.exe -h127.0.0.1 -uroot\" TEST_DB_NAME={$info['d']} bash scripts/testdb/setup-local-test-db.sh --reset");
}
$n = static fn(string $sql): int => (int) $pdo->query($sql)->fetchColumn();
$clauses = $n("SELECT COUNT(*) FROM rag_documents WHERE author = '검증 조문(2-1단계)'");
$active  = $n("SELECT COUNT(*) FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND is_active = 1");
$scoped  = $n("SELECT COUNT(*) FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'");
$prod    = $n("SELECT COUNT(*) FROM products WHERE external_source = 'ktomok'");
echo "사전 점검 — 검증 조문 {$clauses}건(활성 {$active}) · 공공도로 범위 근거 {$scoped}건 · 수집 상품 {$prod}건\n";
if ($clauses === 0 || $active === 0) {
    $fail("검증 조문이 없습니다(SQL 초안 06·07 미적용). 설정 스크립트를 --reset 으로 다시 실행하세요.");
}
if (isset($opt['with-public-road']) && $scoped === 0) {
    $fail("--with-public-road 에 필요한 공공도로 범위 근거(V21·V22, tags '@scope:public_road')가 없습니다. 최신 브랜치의 SQL 초안 06 으로 다시 만드세요.");
}
if ($prod === 0) {
    echo "주의: 수집 상품이 0건 — 상품 검색 결과 없이 상담만 확인됩니다.\n";
}
echo "사전 점검 통과\n\n";
if (isset($opt['check-only'])) {
    exit(0);
}
if (($config['openai']['api_key'] ?? '') === '') {
    fwrite(STDERR, "OPENAI_API_KEY 미설정 — .env.local 에 넣은 뒤 실행하세요(키를 채팅·커밋에 넣지 마세요).\n");
    exit(2);
}

$restore = [];
if (isset($opt['with-public-road'])) {
    $restore = $pdo->query("SELECT id, is_active, review_status FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec("UPDATE rag_documents SET is_active = 1, review_status = 'reviewed' WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'");
}

// [기대 장소 판정, 대화(고객 발화), 답변에서 확인할 점]
$scenarios = [
    ['public_road', ['시청에서 발주한 도로 공사인데 차도에 블록 깔려면 두께 몇 mm 써야 해요?'], '100mm 이상 안내 + KDS/KCS 44 50 25 출처, 80T 를 차도용으로 권하지 않음'],
    ['public_road', ['차가 다니는 곳에 블록 깔려는데 두께 몇 T 써야 돼요?', '구청이 발주처인 도로 포장 공사예요'], '두 번째 턴에서 100mm 기준으로 전환'],
    ['public_sidewalk', ['가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요?'], '보도 지침 구분 Ⅱ(4t 이하) 80mm 이상 + 출처, 차도는 별도 기준'],
    ['private', ['집 앞 마당에 차 두 대 주차할 건데 블록 두께 몇 T?'], '기존 80T 안내, 공공도로 출처(44 50 25) 없음'],
    ['private', ['아파트 단지 주차장 블록 80T면 되나요?'], '기존 80T 안내, 공공 출처 없음'],
    ['unclear', ['차가 올라가는 곳에 블록 깔려면 몇 mm 써야 돼요?'], "두께 단정 전에 '개인 부지인지 도로 공사인지' 확인 질문"],
    ['none', ['사람만 다니는 정원 길에 블록 깔고 싶어요'], '두께 기준 질문 없이 일반 안내'],
];

$md = "# 실제 LLM 상담 시나리오 결과 (" . date('Y-m-d H:i') . ", 모델 " . ($config['openai']['model'] ?? '') . ", V21·V22 " . ($restore ? '실행 중 활성' : '현재 상태') . ")\n\n"
    . "- 연결: {$info['v']} · DB {$info['d']} · 검증 조문 {$clauses}건(활성 {$active})\n\n";
$tally = ['통과' => 0, '확인 필요' => 0, '실패' => 0];
try {
    $i = 0;
    foreach ($scenarios as [$expect, $turns, $check]) {
        $i++;
        $msgs = [];
        $res = ['ok' => false, 'text' => '', 'events' => []];
        $errors = [];
        foreach ($turns as $turn) {   // 턴마다 실제로 답을 받아 대화에 이어 붙임 — 한 턴이라도 실패하면 시나리오 실패
            $msgs[] = ['role' => 'user', 'content' => $turn];
            try {
                $res = rag_process($msgs, 'scenario-test-' . $i);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'text' => '', 'events' => [], 'error' => get_class($e) . ': ' . $e->getMessage()];
            }
            if (empty($res['ok'])) {
                $errors[] = (string) ($res['error'] ?? '알 수 없음');
            }
            $msgs[] = ['role' => 'assistant', 'content' => (string) ($res['text'] ?? '')];
        }
        $ctx = rag_road_context($msgs);
        $sources = [];
        foreach ($res['events'] ?? [] as $e) {
            if (($e['type'] ?? '') === 'legal_sources') {
                foreach ($e['sources'] as $s) { $sources[] = $s['name'] . ' ' . $s['ref']; }
            }
        }
        $text = (string) ($res['text'] ?? '');
        $cites44 = (bool) array_filter($sources, static fn($x) => str_contains($x, '44 50 25'));
        // 실패는 통과로 표시하지 않는다: 실행 오류 > 장소 판정 불일치 > 내용 점검
        if ($errors || trim($text) === '') {
            $auto = '실패(실행 오류·답변 없음)';
        } elseif ($ctx !== $expect) {
            $auto = "실패(장소 판정 {$ctx} ≠ 기대 {$expect})";
        } else {
            $auto = match ($expect) {
                'public_road'     => $cites44 && preg_match('/100\s*mm|100T|10\s*cm/u', $text) ? '통과' : '확인 필요',
                'public_sidewalk' => !$cites44 && preg_match('/80\s*mm|80T|8\s*cm/u', $text) ? '통과' : '확인 필요',
                'private'         => !$cites44 ? '통과' : '실패(공공도로 출처 인용)',
                'unclear'         => $cites44 ? '실패(공공도로 출처 인용)' : '확인 필요',   // 장소 확인 질문은 사람이 확인
                default           => !$cites44 ? '통과' : '실패(공공도로 출처 인용)',
            };
        }
        $tally[str_starts_with($auto, '실패') ? '실패' : ($auto === '통과' ? '통과' : '확인 필요')]++;
        $md .= "## {$i}. 기대 {$expect} · 판정 {$ctx} · 자동 점검 {$auto}\n\n- 고객: " . implode(' → ', $turns) . "\n- 확인할 점: {$check}\n- 인용 근거: " . ($sources ? implode(' / ', $sources) : '없음') . "\n\n```\n" . trim($text) . "\n```\n\n";
        if ($errors) {
            $md .= "> 오류: " . implode(' / ', array_unique($errors)) . "\n\n";
        }
    }
} finally {
    foreach ($restore as $r) {   // 중간에 오류가 나도 V21·V22 활성 상태를 원래대로
        $st = $pdo->prepare('UPDATE rag_documents SET is_active = ?, review_status = ? WHERE id = ?');
        $st->execute([(int) $r['is_active'], $r['review_status'], (int) $r['id']]);
    }
}
$md .= "합계: 통과 {$tally['통과']} · 확인 필요 {$tally['확인 필요']} · 실패 {$tally['실패']}"
    . " (자동 점검은 보조 — '확인 필요'·'통과'도 답변 원문을 사람이 확인)\n";
echo $md;
if (isset($opt['md'])) { file_put_contents((string) $opt['md'], $md); }
exit($tally['실패'] > 0 ? 1 : 0);
