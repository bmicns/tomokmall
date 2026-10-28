<?php
declare(strict_types=1);
/**
 * 실제 LLM 상담 시나리오 테스트(OpenAI 호출) — 공공도로 100mm / 공공 보도 80mm / 사유지 80T 구분 확인용.
 *
 * 책임: rag_process()로 시나리오별 실제 답변을 받아 답변·인용 근거·간단한 판정을 출력(사람이 최종 확인).
 * 금지: 운영 DB 사용. DB_NAME 이 '_test' 로 끝나는 로컬 DB 에서만 실행. 근거 활성 상태는 바꾸지 않는다.
 *       (--with-public-road 를 주면 V21·V22 를 실행 중에만 켜고 끝나면 원래 값으로 되돌림 — 테스트 DB 전용)
 * 필요: .env.local 의 OPENAI_API_KEY, SQL 초안 01·02·06·07 을 적용한 로컬 테스트 DB.
 * 실행: php scripts/testdb/consult-scenarios.php [--with-public-road] [--md=결과.md]
 * 비용: 시나리오 7개 × OpenAI 호출(도구 호출 포함) — 소량.
 */
if (PHP_SAPI !== 'cli') { exit; }
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require dirname(__DIR__, 2) . '/app/bootstrap.php';
require dirname(__DIR__, 2) . '/app/rag.php';

$opt = getopt('', ['with-public-road', 'md:']);
$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName)) {
    fwrite(STDERR, "로컬 테스트 DB(DB_NAME=*_test)에서만 실행합니다. 현재 DB_NAME='{$dbName}'\n");
    exit(2);
}
if (($config['openai']['api_key'] ?? '') === '') {
    fwrite(STDERR, "OPENAI_API_KEY 미설정 — .env.local 에 넣은 뒤 실행하세요(키를 채팅·커밋에 넣지 마세요).\n");
    exit(2);
}
$pdo = db();
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

$md = "# 실제 LLM 상담 시나리오 결과 (" . date('Y-m-d H:i') . ", 모델 " . ($config['openai']['model'] ?? '') . ", V21·V22 " . ($restore ? '실행 중 활성' : '현재 상태') . ")\n\n";
$i = 0;
foreach ($scenarios as [$expect, $turns, $check]) {
    $i++;
    $msgs = [];
    $res = ['text' => '', 'events' => []];
    foreach ($turns as $turn) {   // 턴마다 실제로 답을 받아 대화에 이어 붙임
        $msgs[] = ['role' => 'user', 'content' => $turn];
        $res = rag_process($msgs, 'scenario-test-' . $i);
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
    $auto = empty($res['ok']) ? '실행 실패(답변 없음)' : match ($expect) {
        'public_road'     => $cites44 && preg_match('/100\s*mm|100T|10\s*cm/u', $text) ? '통과' : '확인 필요',
        'public_sidewalk' => !$cites44 && preg_match('/80\s*mm|80T|8\s*cm/u', $text) ? '통과' : '확인 필요',
        'private'         => !$cites44 ? '통과' : '실패(공공도로 출처 인용)',
        'unclear'         => !$cites44 && preg_match('/개인|마당|도로|공사|어디/u', $text) ? '통과(사람 확인)' : '확인 필요',
        default           => !$cites44 ? '통과' : '실패',
    };
    $md .= "## {$i}. 기대 {$expect} · 판정 {$ctx} · 자동 점검 {$auto}\n\n- 고객: " . implode(' → ', $turns) . "\n- 확인할 점: {$check}\n- 인용 근거: " . ($sources ? implode(' / ', $sources) : '없음') . "\n\n```\n" . trim($text) . "\n```\n\n";
    if (empty($res['ok'])) {
        $md .= "> 오류: " . ($res['error'] ?? '알 수 없음') . "\n\n";
    }
}
foreach ($restore as $r) {
    $st = $pdo->prepare('UPDATE rag_documents SET is_active = ?, review_status = ? WHERE id = ?');
    $st->execute([(int) $r['is_active'], $r['review_status'], (int) $r['id']]);
}
echo $md;
if (isset($opt['md'])) { file_put_contents((string) $opt['md'], $md); }
