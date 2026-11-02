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
 *       --core       : 핵심 4개(공공도로 차도 100mm·차량 진입 보도 80mm·사유지 80T·초보 고객 전체 흐름 '집 앞 보도블록 차 지나가도?' → 면적 → 견적 담기)만,
 *                      면적 답변 턴을 붙여 추천 상품 두께·필요 수량·견적 담기까지 점검(비용 절감)
 *       --only=public_road,public_sidewalk : 기대 장소 판정이 이 값인 시나리오만 실행(재테스트 비용 절감)
 *       --skip-product-check : 상품 검색 환경 점검(MySQL 8.0 ngram)이 맞지 않아도 실행 — 상품 추천 점검은 '판정 불가'
 *       --material   : 자재 설명 질문 10개(벤치플룸관·경계석·PE 이중벽관 포함)(그레이팅 차이·미끄럼·유모차, 수로관, 흄관 KS, 오염 방지 블록, 차량 마당 80T) — 제조사 자료 승인 전·후에
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
// 근거 없이 덧붙이는 효과·성능 표현(실제 GPT-4o 3차: '강도 우수'·'균열을 방지'·'오랜 사용을 보장'·불투수형 '비가 많이 오는 곳에 적합'·'중장비')
const RAG_EFFECT_CLAIM_RE = '/강도\s*(가\s*)?우수|튼튼|견고|균열[^.\n]{0,12}(방지|막|없)|오랜\s*사용을\s*보장|수명을\s*보장|불투수[^.\n]{0,30}비가?\s*많이\s*오는|하중을\s*잘\s*견딜|중장비/u';
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
    // [주제, 질문, 확인할 점, 필수 표현, 참고 자료를 쓴 답에서 나오면 안 되는 표현(원문 밖 확대 해석), 승인 자료를 써야 하는 질문인지, 승인 자료가 있으면 답에 있어야 할 핵심]
    // 나오면 안 되는 표현·핵심은 2026-10-29 실제 GPT-4o 결과(승인 전·후)에서 발견한 문제로 정했다.
    $mat = [
        ['그레이팅', '스틸 그레이팅이랑 중하중 그레이팅은 뭐가 달라요?', '구조 차이를 제조사 자료로 설명 + 출처, 원문에 없는 하중 수치·성능 추측 없음', null, '/중장비|(?<!안전 )중하중\s*그레이팅[^\n]{0,120}(유모차|휠체어|작은\s*바퀴)|스틸\s*그레이팅은[^.\n]{0,30}오물|(?<!보다 )(?<!보다)더\s*무거운\s*차량|스틸\s*그레이팅(은|는|:|도|\s*역시)[^\n]{0,40}ㄷ\s*형강|차량이?\s*통행하는\s*(구역|곳)에\s*적합/u', true, null],
        ['그레이팅 유모차 휠체어', '유모차나 휠체어가 지나가는 인도 배수로 덮개는 뭘 골라야 해요?', '작은 바퀴 빠짐 방지 제품(안전 중하중 그레이팅) 설명 + 출처, 토목몰 판매 상품과 구분', null, null, true, '/작은\s*바퀴|바퀴가?\s*빠지|안전\s*중하중/u'],
        ['그레이팅 미끄럼', '그레이팅 미끄럼 방지는 어떻게 되어 있어요?', '크로스 바·트위스트 바 등 원문 근거 + 출처', null, null, true, null],
        ['수로관', '벤치플룸관이랑 일반 U형 수로관은 뭐가 달라요?', '원문이 말하는 제품을 바꿔 말하지 않음(대형인 것은 벤치플룸관이 아님), 없는 비교 추측 없음', null, '/벤치플룸관은\s*(대형|크)|U형[^.\n]{0,30}(동일|같습니다)/u', true, null],
        ['흄관', '흄관은 무슨 KS 규격 제품을 쓰면 돼요?', 'KS F 4403 은 제조사 제품 표기로 전달, B형 등 한 제조사 표기를 선택 기준으로 단정하지 않음, 자료에 없는 KS 번호 금지', null, '/B형[^.\n]{0,40}(사용하면|쓰면)\s*됩니다|KS\s*F\s*4403[^.\n]{0,30}(사용하는\s*것이\s*적합|(사용하|쓰|사용하시|쓰시)면\s*됩니다)/u', true, null],
        ['보도블록 오염', '보도블록 중에 오염이 덜 타는 제품도 있어요?', '제조사 설명(오염 방지 등)을 제조사 주장으로 전달 + 출처, 자료 없는 특성 추측 없음', null, null, true, null],
        ['인조화강블록', '차가 들어오는 집 마당에 인조화강블록 깔아도 되나요?', "차량이면 80mm(80T) 이상 유지, '어디에나 설치 가능' 같은 보류 문장 미사용(두께 질문이라 제조사 자료는 없어도 됨)", '/80\s*(mm|T|㎜)|8\s*cm/u', null, false, null],
        ['벤치플룸관 U형 수로관', '벤치플룸관이 뭐예요? 어떤 규격이 있어요?', '제조사 자료 없음을 밝히고 국가표준 번호·이름(KS F 4010)·토목몰 등록 규격으로만 설명, 구조(폐쇄형 등)·용도 추측 없음', null, null, false, null],
        ['경계석', '경계석은 어떤 종류가 있고 뭐가 달라요?', '제조사 자료 없음을 밝히고 국가표준(KS F 4006 콘크리트 경계 블록)·토목몰 등록 상품 이름·규격으로만, 강도·용도 추측 없음', null, null, false, null],
        ['PE 이중벽관 유공관', 'PE 이중벽관이랑 유공관은 뭐가 달라요?', '등록 상품 이름(유공관 등)과 국가표준(KS M 3500-1) 범위로만, 자료에 없는 구조·용도 단정 없음', null, null, false, null],
    ];
    $banned = '/어디에나|가장 우수|중국산|보석같이/u';   // 승인에서 보류한 홍보·기준 충돌 문장
    $approved = $n("SELECT COUNT(*) FROM external_products WHERE review_status = 'approved'");
    $md = "# 자재 설명 상담 결과 — 제조사 승인 자료 " . ($approved ? "{$approved}행 승인 후" : '승인 전(0행)') . ' (' . date('Y-m-d H:i') . ', 모델 ' . ($config['openai']['model'] ?? '') . ")\n\n"
        . "- 연결: {$info['v']} · DB {$info['d']} · V21·V22 는 바꾸지 않음(현재 상태)\n- 자동 점검은 보조 — 답변 원문을 사람이 확인. 승인 전·후 결과를 나란히 비교\n\n";
    $tally = ['통과' => 0, '확인 필요' => 0, '실패' => 0];
    foreach ($mat as $i => [$topic, $q, $check, $must, $wrong, $needMat, $core]) {
        $avail = ext_material_info($topic)['facts'];
        try {
            $res = rag_process([['role' => 'user', 'content' => $q]], 'material-test-' . ($i + 1));
        } catch (\Throwable $e) {
            $res = ['ok' => false, 'text' => '', 'events' => [], 'error' => get_class($e) . ': ' . $e->getMessage()];
        }
        $text = (string) ($res['text'] ?? '');
        $srcs = $cards = $makers = $legal = [];
        $used = '';   // 이번 답에 실제로 돌려준 참고 자료·법령 근거 글(수치·KS 번호 대조용)
        foreach ($res['events'] ?? [] as $e) {
            if (($e['type'] ?? '') === 'material_sources') { foreach ($e['sources'] as $x) { $srcs[] = $x['maker'] . ' ' . $x['url']; $makers[] = (string) preg_replace('/\(주\)|㈜|주식회사|\s/u', '', $x['maker']); } }
            if (($e['type'] ?? '') === 'product_cards') { foreach ($e['products'] ?? [] as $c) { $cards[] = $c['name']; } }
            if (($e['type'] ?? '') === 'legal_sources') { foreach ($e['sources'] as $x) { $legal[] = $x['name'] . ' ' . $x['ref']; } }
        }
        $calls = array_map(static fn($t) => $t['name'] . '(' . json_encode($t['input'], JSON_UNESCAPED_UNICODE) . ')' . (($t['summary'] ?? '') !== '' ? ' → ' . $t['summary'] : ''), $res['tools'] ?? []);
        $basicNames = [];   // material_info 가 돌려준 확인된 기본 정보(국가표준 번호, 토목몰 등록 상품 이름)
        foreach ($res['tools'] ?? [] as $t) {
            if ($t['name'] !== 'material_info') { continue; }
            $tp = (string) ($t['input']['topic'] ?? '');
            $used .= ' ' . implode(' ', array_column(ext_material_info($tp)['facts'], 'text'));
            $b = ai_material_basics($tp);
            $used .= ' ' . implode(' ', array_column($b['standards'], 'ref'));
            $basicNames = array_merge($basicNames, array_column($b['standards'], 'ref'), array_column($b['products'], 'name'));
        }
        $nameCore = static fn(string $x): string => str_replace(' ', '', (string) preg_replace('/\([^)]*\)/u', '', $x));   // 'PE이중벽관1종(유공관)' → 'PE이중벽관1종' — 괄호 부분 없이 써도 사용으로 인정
        $usedBasics = (bool) array_filter($basicNames, static fn($x) => $x !== '' && (str_contains($text, $x) || str_contains(str_replace(' ', '', $text), str_replace(' ', '', $x))
            || ($nameCore($x) !== '' && mb_strlen($nameCore($x)) >= 4 && str_contains(str_replace(' ', '', $text), $nameCore($x)))));
        $used .= ' ' . implode(' ', $legal);
        $flat = str_replace(['*', '#'], '', $text);   // 굵게·제목 표시 제거 후 표현 점검
        $plain = (string) preg_replace('/\(주\)|㈜|주식회사|\s/u', '', $text);
        $askedMat = in_array('material_info', array_column($res['tools'] ?? [], 'name'), true);
        $cited = (bool) preg_match('/제조사|자료|출처/u', $text) || array_filter(array_unique($makers), static fn($m) => $m !== '' && str_contains($plain, $m));
        // 자료에 없는 하중 수치(톤·kN)·KS 번호 — 원문·법령 근거에 없으면 지어낸 것
        preg_match_all('/(\d+(?:\.\d+)?)\s*(톤|ton|kN)/u', $text, $tons);
        $badTon = array_values(array_filter($tons[0], static fn($x) => !str_contains($used, $x)));
        preg_match_all('/KS\s*[A-Z]\s*-?\s*(\d{4})/u', $text, $ks);
        $badKs = array_values(array_unique(array_filter($ks[1], static fn($x) => !preg_match('/KS\s*[A-Z]\s*-?\s*' . $x . '/u', $used))));   // 답 끝 '확인 안 된 번호' 안내에 같은 번호가 다시 나와도 한 번만
        $auto = match (true) {
            empty($res['ok']) || trim($text) === '' => '실패(실행 오류·답변 없음)',
            (bool) preg_match($banned, $flat) => '실패(보류한 홍보·기준 충돌 표현 사용)',
            (bool) preg_match('/○○|◯◯/u', $text) => '실패(자리표시 ○○ 를 제조사 이름 대신 그대로 씀)',
            (bool) preg_match('/search_products|material_info|add_to_cart|get_cart|create_estimate/u', $text) => '실패(내부 도구 이름 노출)',
            $must !== null && !preg_match($must, $flat) => '실패(필수 안내 없음: 차량 80mm 이상)',
            $srcs && $wrong !== null && (bool) preg_match($wrong, $flat) => '실패(원문 밖 확대 해석: ' . (preg_match($wrong, $flat, $mm) ? $mm[0] : '') . ')',
            !$srcs && (bool) preg_match('/제조사\s*자료에\s*따르면/u', $text) => "실패(참고 자료가 없는데 '제조사 자료에 따르면' 표현)",
            (bool) $badTon => '실패(자료에 없는 하중 수치: ' . implode(', ', $badTon) . ')',
            (bool) $badKs => '실패(자료에 없는 KS 번호: KS F ' . implode(', ', $badKs) . ')',
            !$srcs && (bool) preg_match('/폐쇄형|밀폐형/u', $flat) && !str_contains($used, '폐쇄') => '실패(자료에 없는 구조 설명: 폐쇄형)',
            (bool) preg_match('/KS\s*[A-Z]\s*-?\s*\d{4}[^.\n]{0,25}(에\s*따라|에\s*맞게|기준으로)\s*(제조|생산|만들)|(번호|표준|규격)에\s*맞는\s*제품을\s*(선택|고르)|선택하는\s*것이\s*바람직/u', $flat, $km) => '실패(국가표준·KS 를 적합 단정 또는 선택 기준으로 권함: ' . $km[0] . ')',
            (bool) preg_match('/(폭|높이|길이|두께)\s*\((cm|mm|m)\)/u', $flat) => '실패(등록 규격 숫자를 폭·높이·단위로 풀어 씀 — 표기 그대로 써야 함)',
            $needMat && $avail && !$srcs => '확인 필요(승인 자료가 있는데 material_info 미사용)',
            $srcs && !$cited => '확인 필요(제조사 자료 출처 표시 없음)',
            $askedMat && !$srcs && !preg_match('/일반적인\s*(설명|특징|내용|정보|차이|기준)|일반\s*설명|자료[^.\n]{0,10}없|확보되지|확인되지/u', $text) => '확인 필요(참고 자료가 없는데 일반 설명이라고 밝히지 않음)',
            !$srcs && $askedMat && ($inv = array_values(array_filter(['하천', '호수', '농업', '농경지', '농수로', '지하차도', '운동장', '퇴적물', '청소가'], static fn($w) => str_contains($flat, $w) && !str_contains($used . ' ' . implode(' ', $basicNames), $w)))) => '확인 필요(제조사 자료가 없는데 자료에 없는 용도·장점 설명: ' . implode(', ', $inv) . ')',
            (bool) preg_match(RAG_EFFECT_CLAIM_RE, $flat, $em) => '확인 필요(근거 없는 효과·성능 표현: ' . $em[0] . ')',
            (bool) preg_match('/직접\s*검색|검색해\s*(보셔야|보세요|보시기)/u', $text) => '확인 필요(고객에게 추가 검색을 하라고 함)',
            $askedMat && !$srcs && $basicNames && !$usedBasics => '확인 필요(제조사 자료가 없는데 확인된 기본 정보(국가표준·등록 규격)를 쓰지 않음)',
            $core !== null && $srcs && !preg_match($core, $text) => '확인 필요(승인 자료의 핵심 문장 미사용)',
            default => '통과',
        };
        $tally[str_starts_with($auto, '실패') ? '실패' : ($auto === '통과' ? '통과' : '확인 필요')]++;
        $md .= '## ' . ($i + 1) . ". {$q}\n\n- 자동 점검: {$auto}\n- 확인할 점: {$check}\n- 승인 자료(주제 '{$topic}'): " . count($avail) . '문장'
            . ($avail ? ' — ' . implode(' / ', array_map(static fn($f) => "[{$f['kind']}] " . mb_substr($f['text'], 0, 40) . " ({$f['maker']})", array_slice($avail, 0, 4))) : '') . "\n"
            . '- 부른 도구: ' . ($calls ? implode(' → ', $calls) : '없음') . "\n"
            . '- 답변에 쓰인 참고 자료 출처: ' . ($srcs ? implode(', ', array_unique($srcs)) : '없음') . "\n- 상품 카드(토목몰): " . ($cards ? implode(', ', array_slice($cards, 0, 5)) : '없음') . "\n\n```\n" . trim($text) . "\n```\n\n"
            . (empty($res['ok']) ? '> 오류: ' . ($res['error'] ?? '') . "\n\n" : '');
    }
    $md .= "합계: 통과 {$tally['통과']} · 확인 필요 {$tally['확인 필요']} · 실패 {$tally['실패']}\n";
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
// --core: 핵심 4개(3 + 초보 고객 전체 흐름) + 면적 답변 턴(상품 검색 유도) — [기대, 대화, 확인할 점, 추천 상품 기대 두께(mm)]
$core = [
    ['public_road', ['시청에서 발주한 도로 공사인데 차도에 블록 깔려면 두께 몇 mm 써야 해요?', '차도 면적은 50㎡ 정도예요. 인터로킹 블록으로 찾아 주세요'], '100mm 이상 안내 + KDS/KCS 44 50 25 출처, 80T 비권장, 100mm 제품만(없으면 적합 상품 없음 안내 — 80T·60T 대체 추천 금지, 설계도서 확인·별도 견적)', 100],
    ['public_sidewalk', ['가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요?', '승용차만 들어오고 면적은 30㎡예요'], '보도 지침 구분 Ⅱ(4t 이하) 80mm 이상 + 출처, 80T 제품', 80],
    ['private', ['집 앞 마당에 차 두 대 주차할 건데 블록 두께 몇 T?', '마당은 6m × 5m 예요'], '기존 80T 안내, 공공도로 출처 없음, 80T 제품', 80],
    // 초보 고객 전체 흐름(2026-11-02 목표 예시): 조건 이해 → 근거 구분(저희 권장 80T, 공공도로 출처 없음) → 면적별 수량·예상 비용 비교표 → 견적 담기
    ['private', ['집 앞에 보도블록을 깔려고 하는데 차가 지나가도 괜찮나요?', '집 앞 마당이고 승용차만 다녀요. 가로 4m 세로 5m 정도예요', '그중에 제일 저렴한 걸로 견적에 담아 주세요'],
        '80T 이상 안내를 저희 권장 기준으로(법정 의무처럼 말하지 않음), 80T 비교표(필요 수량·예상 자재비), 3턴째 add_to_cart 호출·견적요청하기 안내', 80, true],
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
$md = "# 실제 LLM 상담 시나리오 결과 (" . (isset($opt['core']) ? '핵심 ' . count($core) . '개, ' : '') . date('Y-m-d H:i') . ", 모델 " . ($config['openai']['model'] ?? '') . ", V21·V22 " . ($restore ? '실행 중 활성' : '현재 상태') . ")\n\n"
    . "- 연결: {$info['v']} · DB {$info['d']} · 검증 조문 {$clauses}건(활성 {$active})\n"
    . "- 상품 검색 환경: " . ($searchOk ? "운영과 같음('인터로킹 블록' 80T " . count($probe['products']) . '건)' : "운영과 다름('인터로킹 블록' 80T 0건 — 상품 추천 점검 판정 불가)") . "\n\n";
$tally = ['통과' => 0, '확인 필요' => 0, '실패' => 0];   // '판정 불가'는 확인 필요로 센다
$runSid = 'scenario-test-' . date('His') . '-';   // 실행마다 새 상담 키(이전 실행의 추천표가 섞이지 않게) — 끝나면 기록 삭제
try {
    $i = 0;
    foreach ($scenarios as $sc) {
        [$expect, $turns, $check] = $sc;
        $wantMm = $sc[3] ?? null;   // --core: 추천 상품이 맞아야 하는 두께
        $wantCart = $sc[4] ?? false;   // 마지막 턴에서 견적 담기(add_to_cart)까지 이어져야 하는지
        $i++;
        $msgs = [];
        $res = ['ok' => false, 'text' => '', 'events' => []];
        $errors = [];
        $log = [];   // 턴별 [고객, 답변, 인용 근거, 상품 카드, 적합 상품 없음 카드]
        foreach ($turns as $turn) {   // 턴마다 실제로 답을 받아 대화에 이어 붙임 — 한 턴이라도 실패하면 시나리오 실패
            $msgs[] = ['role' => 'user', 'content' => $turn];
            try {
                $res = rag_process($msgs, $runSid . $i);
            } catch (\Throwable $e) {
                $res = ['ok' => false, 'text' => '', 'events' => [], 'error' => get_class($e) . ': ' . $e->getMessage()];
            }
            if (empty($res['ok'])) {
                $errors[] = (string) ($res['error'] ?? '알 수 없음');
            }
            $msgs[] = ['role' => 'assistant', 'content' => (string) ($res['text'] ?? '')];
            if (!empty($res['ok'])) {   // 운영 chat.php 처럼 턴을 기록 → 다음 턴 프롬프트에 직전 추천표(담기·비교용)가 들어감
                rag_log_chat($runSid . $i, $turn, (string) ($res['text'] ?? ''), $res['events'] ?? [], (string) ($res['category'] ?? ''));
            }
            $t = ['user' => $turn, 'text' => (string) ($res['text'] ?? ''), 'sources' => [], 'cards' => [], 'unavailable' => false, 'tools' => array_column($res['tools'] ?? [], 'name'),
                  'calls' => array_map(static fn($c) => $c['name'] . '(' . json_encode($c['input'], JSON_UNESCAPED_UNICODE) . ')' . (($c['summary'] ?? '') !== '' ? ' → ' . $c['summary'] : ''), $res['tools'] ?? []),
                  'unverified_ks' => $res['unverified_ks'] ?? []];
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
        if ($wantCart) {   // 담기 턴에는 표가 없으므로 표가 나온 마지막 턴 기준
            foreach (array_reverse($log) as $t) { if ($t['cards'] || $t['unavailable']) { $cards = $t['cards']; $unavailable = $t['unavailable']; break; } }
        }
        $cartOk = !$wantCart || in_array('add_to_cart', $last['tools'] ?? [], true);
        $tableBeforeCart = !$wantCart || (bool) array_filter(array_slice($log, 0, -1), static fn($t) => $t['cards'] !== []);   // 담기 전에 고객이 비교표를 봤는지(2026-11-02 실제: 2턴째 종류만 되물음)
        $costShown = (bool) array_filter($cards, static fn($c) => ($c['suggested_qty'] ?? null) !== null);
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
        } elseif ($wantCart && !$cartOk) {
            $auto = '실패(마지막 턴에서 견적 담기(add_to_cart)를 하지 않음)';
        } elseif ($wantCart && !$tableBeforeCart) {
            $auto = '확인 필요(면적까지 받은 턴에 비교표 없이 되물음 — 고객이 표를 보기 전에 담김)';
        } elseif ($wantCart && !$costShown) {
            $auto = '확인 필요(비교표에 면적 기준 필요 수량이 없음)';
        } elseif ($wantCart && ($tie = array_filter($cards, static fn($c) => !empty($c['cost_lowest']))) && count($tie) > 1 && !preg_match('/같은\s*(예상\s*자재비|금액|가격)|동일한\s*(금액|가격)|가격이\s*같|금액이\s*같|자재비가\s*같/u', (string) $last['text'])) {
            $auto = '확인 필요(최저가 동률 상품이 여럿인데 담을 때 같은 금액 제품을 알리지 않음)';
        } elseif ($expect === 'public_sidewalk' && str_contains($allText, '저희 권장')) {
            $auto = "확인 필요(공공 보도의 80mm 공식 기준(보도 지침)을 '저희 권장 기준'으로 말함)";
        } elseif ($wantCart && preg_match('/법(적|정)\s*(의무|기준)[^.\n]{0,20}80|80[^.\n]{0,20}법(적|정)/u', $allText)) {
            $auto = '확인 필요(저희 권장 80T 를 법정 기준처럼 말함)';
        } elseif ($expect === 'private' && preg_match('/80\s*(mm|T|㎜)/u', $allText) && !str_contains($allText, '권장')) {
            $auto = "확인 필요(사유지 80mm 를 '저희 권장 기준'으로 밝히지 않음)";
        } elseif (preg_match(RAG_EFFECT_CLAIM_RE, $allText, $em)) {
            $auto = '확인 필요(근거 없는 효과·성능 표현: ' . $em[0] . ')';
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
                . ($t['unavailable'] ? " · '현재 등록된 적합 상품 없음' 카드 표시" : '')
                . "\n- 부른 도구: " . ($t['calls'] ? implode(' → ', $t['calls']) : '없음')
                . ($t['unverified_ks'] ? "\n- 확인 안 된 KS 번호(답 끝 안내 붙임): " . implode(', ', $t['unverified_ks']) : '') . "\n\n```\n" . trim($t['text']) . "\n```\n\n";
        }
        if ($errors) {
            $md .= "> 오류: " . implode(' / ', array_unique($errors)) . "\n\n";
        }
    }
} finally {
    $pdo->prepare('DELETE FROM chat_logs WHERE session_key LIKE ?')->execute([$runSid . '%']);
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
