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
 *       --core       : 핵심 3개(공공도로 차도 100mm·차량 진입 보도 80mm·사유지 80T)만, 면적 답변 턴을 붙여 추천 상품 두께까지 점검(비용 절감)
 *       --only=public_road,public_sidewalk : 기대 장소 판정이 이 값인 시나리오만 실행(재테스트 비용 절감)
 *       --skip-product-check : 상품 검색 환경 점검(MySQL 8.0 ngram)이 맞지 않아도 실행 — 상품 추천 점검은 '판정 불가'
 *       --material   : 자재 설명 질문 7개(그레이팅 차이·미끄럼·유모차, 수로관, 흄관 KS, 오염 방지 블록, 차량 마당 80T) — 제조사 자료 승인 전·후에
 *                      각각 실행해(--md=material-before.md / material-after.md) 설명이 어떻게 달라지는지 비교. 근거 활성 상태·승인 상태는 바꾸지 않음
 *       결과 문서에는 턴마다 답변·인용 근거·상품 카드·'적합 상품 없음' 카드를 모두 남긴다.
 * 종료 코드: 0 실패 없음(확인 필요는 사람 검토), 1 실행 실패·판정 실패 있음, 2 설정·DB 구성 오류
 * 비용: 시나리오 7개 × OpenAI 호출(도구 호출 포함) — 소량.
 */
if (PHP_SAPI !== 'cli') { exit; }
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/rag.php';

$opt = getopt('', ['with-public-road', 'md:', 'check-only', 'core', 'only:', 'skip-product-check', 'material']);
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
// 상품 검색 환경 — MariaDB(XAMPP 기본)는 한글 ngram FULLTEXT 가 없어 운영(MySQL 8.0)과 검색 결과가 크게 다르다(예: '인터로킹 블록' 0건)
$probe = $prod > 0 ? (json_decode(tool_search_products(['query' => '인터로킹 블록', 'spec_mm' => 80, 'spec_strict' => true]), true) ?? []) : [];
$searchOk = !empty($probe['products']);
echo "사전 점검 — 상품 검색('인터로킹 블록' 80T): " . ($searchOk ? count($probe['products']) . '건 — 운영과 같은 검색 환경' : '0건') . "\n";
if (!$searchOk) {
    $msg = "이 DB 에서는 '인터로킹 블록' 80T 검색이 0건입니다(등록된 80T 포장 블록 " . count(ai_paving_names_with_thickness(80)) . "종).\n"
        . "  MariaDB 는 한글 검색(ngram)이 운영 MySQL 8.0 과 달라 상품 추천 점검 결과를 믿을 수 없습니다. MySQL 8.0 테스트 DB 로 실행하세요\n"
        . "  (docs/03-analysis/ai-sourcing/legal-sources/prompt-simulation-2026-10-29.md 7장). 상품 점검 없이 답변·근거만 보려면 --skip-product-check";
    if (isset($opt['core']) && !isset($opt['skip-product-check'])) {
        $fail($msg);
    }
    echo "주의: {$msg}\n";
}
echo "사전 점검 통과\n\n";
if (isset($opt['check-only'])) {
    exit(0);
}
if (($config['openai']['api_key'] ?? '') === '') {
    fwrite(STDERR, "OPENAI_API_KEY 미설정 — .env.local 에 넣은 뒤 실행하세요(키를 채팅·커밋에 넣지 마세요).\n");
    exit(2);
}

// ── --material: 자재 설명 질문(제조사 승인 자료 전·후 비교용) — [주제(승인 자료 조회), 고객 질문, 확인할 점, 필수 표현(정규식)] ──
if (isset($opt['material'])) {
    $mat = [
        ['그레이팅', '스틸 그레이팅이랑 중하중 그레이팅은 뭐가 달라요?', '구조 차이를 제조사 자료로 설명 + 출처, 원문에 없는 하중 수치 추측 없음', null],
        ['그레이팅', '유모차나 휠체어가 지나가는 인도 배수로 덮개는 뭘 골라야 해요?', '작은 바퀴 빠짐 방지 제품 설명 + 출처, 토목몰 판매 상품과 구분', null],
        ['그레이팅 미끄럼', '그레이팅 미끄럼 방지는 어떻게 되어 있어요?', '크로스 바·트위스트 바 등 원문 근거 + 출처', null],
        ['수로관', '벤치플룸관이랑 일반 U형 수로관은 뭐가 달라요?', '원문 근거(대형·농배수로 등) + 출처, 없는 수치 추측 없음', null],
        ['흄관', '흄관은 무슨 KS 규격 제품을 쓰면 돼요?', 'KS F 4403 등 원문 표기 + 출처, 공식 기준 단정은 [L] 근거로만', null],
        ['인조화강블록', '보도블록 중에 오염이 덜 타는 제품도 있어요?', '제조사 설명(오염 방지 등)을 제조사 주장으로 전달 + 출처', null],
        ['인조화강블록', '차가 들어오는 집 마당에 인조화강블록 깔아도 되나요?', "차량이면 80mm(80T) 이상 유지, '어디에나 설치 가능' 같은 보류 문장 미사용", '/80\s*(mm|T|㎜)|8\s*cm/u'],
    ];
    $banned = '/어디에나|가장 우수|중국산|보석같이/u';   // 승인에서 보류한 홍보·기준 충돌 문장
    $approved = $n("SELECT COUNT(*) FROM external_products WHERE review_status = 'approved'");
    $md = "# 자재 설명 상담 결과 — 제조사 승인 자료 " . ($approved ? "{$approved}행 승인 후" : '승인 전(0행)') . ' (' . date('Y-m-d H:i') . ', 모델 ' . ($config['openai']['model'] ?? '') . ")

"
        . "- 연결: {$info['v']} · DB {$info['d']} · V21·V22 는 바꾸지 않음(현재 상태)
- 자동 점검은 보조 — 답변 원문을 사람이 확인. 승인 전·후 결과를 나란히 비교

";
    $tally = ['통과' => 0, '확인 필요' => 0, '실패' => 0];
    foreach ($mat as $i => [$topic, $q, $check, $must]) {
        $avail = ext_material_info($topic)['facts'];
        try {
            $res = rag_process([['role' => 'user', 'content' => $q]], 'material-test-' . ($i + 1));
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'text' => '', 'events' => [], 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
        $text = (string) ($res['text'] ?? '');
        $srcs = $cards = [];
        foreach ($res['events'] ?? [] as $e) {
            if (($e['type'] ?? '') === 'material_sources') { foreach ($e['sources'] as $x) { $srcs[] = $x['maker'] . ' ' . $x['url']; } }
            if (($e['type'] ?? '') === 'product_cards') { foreach ($e['products'] ?? [] as $c) { $cards[] = $c['name']; } }
        }
        $auto = match (true) {
            empty($res['ok']) || trim($text) === '' => '실패(실행 오류·답변 없음)',
            (bool) preg_match($banned, $text) => '실패(보류한 홍보·기준 충돌 표현 사용)',
            $must !== null && !preg_match($must, $text) => '실패(필수 안내 없음: 차량 80mm 이상)',
            $avail && !$srcs => '확인 필요(승인 자료가 있는데 material_info 미사용)',
            $srcs && !preg_match('/제조사|자료|출처/u', $text) => '확인 필요(제조사 자료 출처 표시 없음)',
            default => '통과',
        };
        $tally[str_starts_with($auto, '실패') ? '실패' : ($auto === '통과' ? '통과' : '확인 필요')]++;
        $md .= '## ' . ($i + 1) . ". {$q}

- 자동 점검: {$auto}
- 확인할 점: {$check}
- 승인 자료(주제 '{$topic}'): " . count($avail) . '문장'
            . ($avail ? ' — ' . implode(' / ', array_map(static fn($f) => "[{$f['kind']}] " . mb_substr($f['text'], 0, 40) . " ({$f['maker']})", array_slice($avail, 0, 4))) : '') . "
"
            . '- 답변에 쓰인 참고 자료 출처: ' . ($srcs ? implode(', ', array_unique($srcs)) : '없음') . "
- 상품 카드(토목몰): " . ($cards ? implode(', ', array_slice($cards, 0, 5)) : '없음') . "

```
" . trim($text) . "
```

"
            . (empty($res['ok']) ? '> 오류: ' . ($res['error'] ?? '') . "

" : '');
    }
    $md .= "합계: 통과 {$tally['통과']} · 확인 필요 {$tally['확인 필요']} · 실패 {$tally['실패']}
";
    echo $md;
    if (isset($opt['md'])) { file_put_contents((string) $opt['md'], $md); }
    exit($tally['실패'] > 0 ? 1 : 0);
}

$restore = [];
if (isset($opt['with-public-road'])) {
    $restore = $pdo->query("SELECT id, is_active, review_status FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'")->fetchAll(PDO::FETCH_ASSOC);
    $pdo->exec("UPDATE rag_documents SET is_active = 1, review_status = 'reviewed' WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'");
}

// [기대 장소 판정, 대화(고객 발화), 답변에서 확인할 점]
// --core: 핵심 3개 + 면적 답변 턴(상품 검색 유도) — [기대, 대화, 확인할 점, 추천 상품 기대 두께(mm)]
$core = [
    ['public_road', ['시청에서 발주한 도로 공사인데 차도에 블록 깔려면 두께 몇 mm 써야 해요?', '차도 면적은 50㎡ 정도예요. 인터로킹 블록으로 찾아 주세요'], '100mm 이상 안내 + KDS/KCS 44 50 25 출처, 80T 비권장, 100mm 제품만(없으면 적합 상품 없음 안내 — 80T·60T 대체 추천 금지, 설계도서 확인·별도 견적)', 100],
    ['public_sidewalk', ['가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요?', '승용차만 들어오고 면적은 30㎡예요'], '보도 지침 구분 Ⅱ(4t 이하) 80mm 이상 + 출처, 80T 제품', 80],
    ['private', ['집 앞 마당에 차 두 대 주차할 건데 블록 두께 몇 T?', '마당은 6m × 5m 예요'], '기존 80T 안내, 공공도로 출처 없음, 80T 제품', 80],
];
$scenarios = [
    ['public_road', ['시청에서 발주한 도로 공사인데 차도에 블록 깔려면 두께 몇 mm 써야 해요?'], '100mm 이상 안내 + KDS/KCS 44 50 25 출처, 80T 를 차도용으로 권하지 않음'],
    ['public_road', ['차가 다니는 곳에 블록 깔려는데 두께 몇 T 써야 돼요?', '구청이 발주처인 도로 포장 공사예요'], '두 번째 턴에서 100mm 기준으로 전환'],
    ['public_sidewalk', ['가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요?'], '보도 지침 구분 Ⅱ(4t 이하) 80mm 이상 + 출처, 차도는 별도 기준'],
    ['private', ['집 앞 마당에 차 두 대 주차할 건데 블록 두께 몇 T?'], '기존 80T 안내, 공공도로 출처(44 50 25) 없음'],
    ['private', ['아파트 단지 주차장 블록 80T면 되나요?'], '기존 80T 안내, 공공 출처 없음'],
    ['unclear', ['차가 올라가는 곳에 블록 깔려면 몇 mm 써야 돼요?'], "두께 단정 전에 '개인 부지인지 도로 공사인지' 확인 질문"],
    ['none', ['사람만 다니는 정원 길에 블록 깔고 싶어요'], '두께 기준 질문 없이 일반 안내'],
];

if (isset($opt['core'])) {
    $scenarios = $core;
}
if (isset($opt['only'])) {
    $only = array_map('trim', explode(',', (string) $opt['only']));
    $scenarios = array_values(array_filter($scenarios, static fn($sc) => in_array($sc[0], $only, true)));
}
$productCheck = $searchOk;   // false 면 상품 추천 점검은 '판정 불가'
$md = "# 실제 LLM 상담 시나리오 결과 (" . (isset($opt['core']) ? '핵심 3개, ' : '') . date('Y-m-d H:i') . ", 모델 " . ($config['openai']['model'] ?? '') . ", V21·V22 " . ($restore ? '실행 중 활성' : '현재 상태') . ")\n\n"
    . "- 연결: {$info['v']} · DB {$info['d']} · 검증 조문 {$clauses}건(활성 {$active})\n"
    . "- 상품 검색 환경: " . ($searchOk ? "운영과 같음('인터로킹 블록' 80T " . count($probe['products']) . '건)' : "운영과 다름('인터로킹 블록' 80T 0건 — 상품 추천 점검 판정 불가)") . "\n\n";
$tally = ['통과' => 0, '확인 필요' => 0, '실패' => 0];   // '판정 불가'는 확인 필요로 센다
try {
    $i = 0;
    foreach ($scenarios as $sc) {
        [$expect, $turns, $check] = $sc;
        $wantMm = $sc[3] ?? null;   // --core: 추천 상품이 맞아야 하는 두께
        $i++;
        $msgs = [];
        $res = ['ok' => false, 'text' => '', 'events' => []];
        $errors = [];
        $log = [];   // 턴별 [고객, 답변, 인용 근거, 상품 카드, 적합 상품 없음 카드]
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
            $t = ['user' => $turn, 'text' => (string) ($res['text'] ?? ''), 'sources' => [], 'cards' => [], 'unavailable' => false];
            foreach ($res['events'] ?? [] as $e) {
                $type = $e['type'] ?? '';
                if ($type === 'legal_sources') {
                    foreach ($e['sources'] as $s) { $t['sources'][] = $s['name'] . ' ' . $s['ref']; }
                } elseif ($type === 'product_cards') {
                    $t['cards'] = array_merge($t['cards'], $e['products'] ?? []);
                } elseif ($type === 'spec_unavailable') {
                    $t['unavailable'] = true;
                }
            }
            $log[] = $t;
        }
        $ctx = rag_road_context($msgs);
        $last = end($log) ?: ['text' => '', 'cards' => [], 'unavailable' => false];
        // 근거·수치는 모든 턴에서, 추천 상품은 마지막 턴(면적 답변 뒤 검색)에서 본다
        $sources = array_values(array_unique(array_merge(...array_map(static fn($t) => $t['sources'], $log ?: [['sources' => []]]))));
        $allText = implode("\n", array_column($log, 'text'));
        $text = (string) $last['text'];
        $cards = $last['cards'];
        $unavailable = $last['unavailable'];
        $wrongCards = $wantMm === null ? [] : array_values(array_filter($cards, static fn($c) => ai_block_thickness((string) ($c['spec'] ?? '')) !== $wantMm));
        $wantExists = $wantMm !== null && ai_paving_names_with_thickness($wantMm) !== [];   // 그 두께로 등록된 포장 블록이 있는지(DB 종류 무관)
        $cites44 = (bool) array_filter($sources, static fn($x) => str_contains($x, '44 50 25'));
        $citesSidewalk = (bool) array_filter($sources, static fn($x) => str_contains($x, '보도 설치 및 관리 지침'));
        // 실패는 통과로 표시하지 않는다: 실행 오류 > 장소 판정 불일치 > 상품 > 내용 점검
        if ($errors || trim($text) === '') {
            $auto = '실패(실행 오류·답변 없음)';
        } elseif ($ctx !== $expect) {
            $auto = "실패(장소 판정 {$ctx} ≠ 기대 {$expect})";
        } elseif ($wantMm !== null && !$productCheck) {
            $auto = '판정 불가(상품 검색 환경이 운영과 다름 — MySQL 8.0 필요)';
        } elseif ($wrongCards) {
            $auto = "실패(추천 상품 두께가 {$wantMm}mm 가 아님: " . implode(', ', array_map(static fn($c) => $c['name'], array_slice($wrongCards, 0, 3))) . ')';
        } elseif ($unavailable && $wantExists) {
            $auto = "실패({$wantMm}mm 포장 블록이 등록돼 있는데 '적합 상품 없음' 안내)";
        } elseif ($wantMm !== null && !$cards && !$unavailable) {
            $auto = "확인 필요(추천 상품도 '적합 상품 없음' 안내도 없음 — 마지막 턴에서 {$wantMm}mm 검색을 하지 않음)";
        } elseif (in_array($expect, ['private', 'unclear', 'none'], true) && $cites44) {
            $auto = '실패(공공도로 출처 인용)';
        } else {
            $auto = match ($expect) {
                'public_road'     => $cites44 && preg_match('/100\s*mm|100T|10\s*cm/u', $allText) ? '통과' : '확인 필요(KDS/KCS 44 50 25 인용·100mm 안내 원문 확인)',
                'public_sidewalk' => !$cites44 && $citesSidewalk && preg_match('/80\s*mm|80T|8\s*cm/u', $allText) ? '통과' : '확인 필요(보도 지침 인용·80mm 안내 원문 확인)',
                'unclear'         => '확인 필요',   // 장소 확인 질문은 사람이 확인
                default           => '통과',
            };
        }
        $tally[str_starts_with($auto, '실패') ? '실패' : ($auto === '통과' ? '통과' : '확인 필요')]++;
        $md .= "## {$i}. 기대 {$expect} · 판정 {$ctx} · 자동 점검 {$auto}\n\n- 확인할 점: {$check}\n- 인용 근거(전체 턴): " . ($sources ? implode(' / ', $sources) : '없음') . "\n\n";
        foreach ($log as $k => $t) {
            $md .= '### 턴 ' . ($k + 1) . "\n\n- 고객: {$t['user']}\n- 인용 근거: " . ($t['sources'] ? implode(' / ', $t['sources']) : '없음')
                . "\n- 추천 상품: " . ($t['cards'] ? implode(', ', array_map(static fn($c) => $c['name'] . '(' . (ai_block_thickness((string) ($c['spec'] ?? '')) ?? '?') . 'mm)', $t['cards'])) : '없음')
                . ($t['unavailable'] ? " · '현재 등록된 적합 상품 없음' 카드 표시" : '') . "\n\n```\n" . trim($t['text']) . "\n```\n\n";
        }
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
