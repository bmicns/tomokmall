<?php
declare(strict_types=1);
/**
 * AI 상담 모호한·불완전한 질문 테스트 (2026-11-12, 4차) — 목업 OpenAI(127.0.0.1), 로컬 테스트 DB(*_test), 외부 접속 없음.
 *
 * 사용자 지정 8문장: ① '길이 10m짜리 그레이팅' ② '폭은 그대로 하고 길이만 두 배로' ③ '아까 그 제품 말고 더 튼튼한 거'
 *   ④ '차가 다니는데 이 제품 써도 돼?' ⑤ '500짜리 수로관 20m 필요해' ⑥ '앞에서 추천한 것 중 가장 저렴한 제품으로'
 *   ⑦ '폭 400으로 바꾸고 나머지는 그대로' ⑧ '이 제품 대신 비슷한 규격의 다른 제품' + 제외 조건 유지 + 같은 제품 중복 표시 없음.
 * 각 문장을 앞 대화(맥락)와 함께, 두 가지 목업 GPT 로 돌린다:
 *   careless — 모호한 말을 추측해 넣음(10m 를 제품 길이로, 500 을 폭으로, '두 배'를 설치 길이로, 앞 조건·통행·id 를 빠뜨림)
 *   careful  — 도구 설명대로 넘김(GPT 는 직전 표 id 만 앎)
 * 확인: 서버가 모호한 해석을 정하지 않고(확인 질문 근거 clarify·시스템 안내), 분명한 조건만 유지·변경하며, 결과 표가 기준을 지키는지.
 * 실제 GPT 가 확인 질문을 실제로 하는지는 이 테스트로 확인하지 않는다(목업) — scripts/testdb/consult-conditions-live.php(키가 있을 때만).
 * 기록: 상담 키 'ambiguous-test-*' 로 chat_logs 에 쓰고 끝나면 지운다(로컬 테스트 DB만).
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/consult_ambiguous_questions.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$tmp = sys_get_temp_dir() . '/ambiguous_test_' . getmypid();
@mkdir($tmp, 0777, true);
$port = 19700 + getmypid() % 200;
putenv("OPENAI_CHAT_URL=http://127.0.0.1:{$port}/v1/chat/completions");
putenv('OPENAI_API_KEY=test-not-a-key');
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/rag.php';

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true) || !db()) {
    echo "SKIP: 로컬 테스트 DB(*_test)에서만 실행합니다.\n";
    exit(0);
}
if ((int) db()->query("SELECT COUNT(*) FROM products WHERE is_active = 1 AND name = '중하중그레이팅수로용' AND dim_w = 400 AND dim_l = 1000")->fetchColumn() === 0) {
    echo "SKIP: 규격 치수 칸이 채워진 수집 상품이 없는 DB입니다. php scripts/testdb/prodlike-db.php 로 만드세요.\n";
    exit(0);
}
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $detail = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}" . ($detail !== '' ? "  — {$detail}" : '') . "\n"; }
}
file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n);
file_put_contents("$d/req-$n.json", file_get_contents("php://input")); $r = json_decode(file_get_contents("$d/responses.json"), true);
header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
$srv = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $tmp);
usleep(400000);

$say  = static fn(string $t) => ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $t]]]];
$call = static fn(array $args) => ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
    'tool_calls' => [['id' => 'c' . random_int(1, 99999), 'type' => 'function', 'function' => ['name' => 'search_products', 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]]]]]]];
$sids = [];
$dupFree = true;   // 모든 턴: 같은 상품 중복 표시 없음
/** 상담 실행기 — 턴: [결과, GPT 에 돌려준 search_products 결과, 첫 요청의 시스템 프롬프트] */
$session = static function (string $sid) use ($tmp, &$sids, &$dupFree): Closure {
    $sids[] = $sid;
    db()->prepare('DELETE FROM chat_logs WHERE session_key = ?')->execute([$sid]);
    $messages = [];
    return static function (string $user, array $responses) use ($tmp, $sid, &$messages, &$dupFree): array {
        array_map('unlink', glob("{$tmp}/req-*.json") ?: []);
        @unlink("{$tmp}/n");
        file_put_contents("{$tmp}/responses.json", json_encode($responses, JSON_UNESCAPED_UNICODE));
        $send = array_merge($messages, [['role' => 'user', 'content' => $user]]);
        $res = rag_process($send, $sid);
        rag_log_chat($sid, $user, (string) ($res['text'] ?? ''), $res['events'] ?? [], (string) ($res['category'] ?? ''), null);
        $messages = array_merge($send, [['role' => 'assistant', 'content' => (string) ($res['text'] ?? '')]]);
        $n = (int) @file_get_contents("{$tmp}/n");
        $first = json_decode((string) @file_get_contents("{$tmp}/req-1.json"), true) ?: [];
        $last = json_decode((string) @file_get_contents("{$tmp}/req-{$n}.json"), true) ?: [];
        $tool = [];
        foreach (array_reverse($last['messages'] ?? []) as $m) { if (($m['role'] ?? '') === 'tool') { $tool = json_decode((string) $m['content'], true) ?: []; break; } }
        $cards = [];
        foreach ($res['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'product_cards') { $cards = array_merge($cards, $e['products'] ?? []); } }
        $idsT = array_column($cards, 'id');
        $namesT = array_column($cards, 'name');
        if (count($idsT) !== count(array_unique($idsT)) || count($namesT) !== count(array_unique($namesT))) { $dupFree = false; }
        return [$res, $tool, (string) ($first['messages'][0]['content'] ?? ''), $cards];
    };
};
$in    = static fn(array $res): array => (array) ($res['tools'][0]['input'] ?? []);
$fills = static fn(array $res): string => implode(' / ', (array) ($res['tools'][0]['fills'] ?? []));
$ids   = static fn(array $cs): array => array_values(array_map(static fn($c) => (int) $c['id'], $cs));
$j     = static fn($v): string => json_encode($v, JSON_UNESCAPED_UNICODE);
$hint  = '이번 고객 말에서 확인이 필요한 점';
$names = static function (array $idList): array {
    if (!$idList) { return []; }
    $st = db()->prepare('SELECT DISTINCT name FROM products WHERE id IN (' . implode(',', array_fill(0, count($idList), '?')) . ')');
    $st->execute(array_values($idList));
    return $st->fetchAll(PDO::FETCH_COLUMN);
};

try {
    foreach (['careless', 'careful'] as $mode) {
        $cf = $mode === 'careful';
        $k = 0;
        $sid = static function () use (&$k, $mode): string { return 'ambiguous-test-' . $mode . '-' . getmypid() . '-' . (++$k); };
        echo "\n[목업 GPT {$mode}]\n";

        echo " ① '길이 10m짜리 그레이팅 찾아줘.' — 1개 길이로 보기엔 너무 긴 '짜리'\n";
        $t = $session($sid());
        [$r, $o, $sys, $cs] = $t('길이 10m짜리 그레이팅 찾아줘.', [$call($cf ? ['query' => '그레이팅'] : ['query' => '그레이팅', 'product_length_mm' => 10000]), $say('확인 질문')]);
        $i = $in($r);
        ok('검색 입력: 10m 를 제품 길이·설치 길이 어느 쪽에도 넣지 않음', !isset($i['product_length_mm']) && !isset($i['length_m']), $j($i) . ' ' . $fills($r));
        ok('GPT 에 확인 질문 근거(clarify)·시스템 안내 전달', str_contains((string) ($o['clarify'] ?? ''), '설치 구간 총') && str_contains($sys, $hint) && str_contains($sys, '길이 10m짜리'), (string) ($o['clarify'] ?? ''));
        ok('후보 표는 보이되 수량(설치 길이 기준)은 계산하지 않음', $cs !== [] && !array_filter($cs, static fn($c) => isset($c['suggested_qty'])));

        echo " ② '폭은 그대로 하고 길이만 두 배로 해줘.'\n";
        $t = $session($sid());
        $t('폭 500mm, 길이 1m 그레이팅 찾아줘.', [$call(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000]), $say('표')]);
        [$r, $o, $sys, $cs] = $t('폭은 그대로 하고 길이만 두 배로 해줘.', [$call($cf ? ['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 2000] : ['query' => '그레이팅', 'width_mm' => 500, 'length_m' => 2]), $say('결과')]);
        $i = $in($r);
        ok('앞 조건이 제품 1개 길이(1m)뿐 → 제품 길이 2000mm, 폭 500 유지, 설치 길이로 넣지 않음', (float) ($i['product_length_mm'] ?? 0) === 2000.0 && (float) ($i['width_mm'] ?? 0) === 500.0 && !isset($i['length_m']), $j($i) . ' ' . $fills($r));
        ok('폭 500·길이 2000 제품이 없으면 다른 규격을 대신 보이지 않고 없다고 안내', $cs === [] && str_contains((string) ($o['note'] ?? ''), '없습니다'), (string) ($o['note'] ?? ''));
        $t = $session($sid());
        $t('폭 500mm, 길이 1m 그레이팅 찾아줘.', [$call(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000]), $say('표')]);
        $t('설치 구간은 총 20m야.', [$call(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000, 'length_m' => 20]), $say('수량')]);
        [$r, $o, $sys] = $t('폭은 그대로 하고 길이만 두 배로 해줘.', [$call($cf ? ['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000, 'length_m' => 20] : ['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000, 'length_m' => 40]), $say('확인')]);
        $i = $in($r);
        ok('제품 길이·설치 길이가 둘 다 있으면 모호 → 확인 전까지 앞 조건(1000mm·20m) 유지', (float) ($i['product_length_mm'] ?? 0) === 1000.0 && (float) ($i['length_m'] ?? 0) === 20.0, $j($i) . ' ' . $fills($r));
        ok('어느 길이를 바꿀지 확인 질문 근거', str_contains((string) ($o['clarify'] ?? ''), '어느 길이를 바꿀지') && str_contains($sys, $hint), (string) ($o['clarify'] ?? ''));

        echo " ③ '아까 그 제품 말고 더 튼튼한 거.'\n";
        $t = $session($sid());
        [, , , $c0] = $t('그레이팅 수로용 폭 300 찾아줘. 사람만 다녀요.', [$call(['query' => '그레이팅 수로용', 'width_mm' => 300, 'traffic' => 'pedestrian']), $say('표')]);
        [$r, $o, , $cs] = $t('아까 그 제품 말고 더 튼튼한 거.', [$call($cf ? ['query' => '그레이팅 수로용', 'width_mm' => 300, 'exclude_ids' => $ids($c0)] : ['query' => '그레이팅 수로용']), $say('결과')]);
        $i = $in($r);
        ok('앞 표(보행자용) 기준 비교·앞 제품 제외·폭 300 유지', ai_int_list($i['stronger_than_ids'] ?? null) === $ids($c0) && !array_diff($ids($c0), ai_int_list($i['exclude_ids'] ?? null)) && (float) ($i['width_mm'] ?? 0) === 300.0, $j($i));
        ok('결과: 하중 등급이 더 높다고 확인된 제품만(승용차용·대형차용, 근거 표시), 앞 제품 이름 없음', $cs !== [] && !array_filter($cs, static fn($c) => !in_array($c['load_grade'] ?? '', ['승용차용', '대형차용'], true) || ($c['load_basis'] ?? '') === '')
            && !array_intersect(array_column($cs, 'name'), array_column($c0, 'name')), $j(array_map(static fn($c) => [$c['name'], $c['load_grade'] ?? null, $c['load_basis'] ?? null], $cs)));
        ok("안내: '확인된 제품만'(근거 없는 제품을 튼튼하다고 하지 않음)", str_contains((string) ($o['note'] ?? ''), '하중 등급이 높다고 상품명 등급·규격 표기로 확인된 제품만'), (string) ($o['note'] ?? ''));
        $t = $session($sid());
        [, , , $c1] = $t('맨홀 뚜껑 보여줘.', [$call(['query' => '칼라맨홀뚜껑']), $say('표')]);
        [$r, $o, , $cs] = $t('아까 그 제품 말고 더 튼튼한 거.', [$call(['query' => '맨홀 뚜껑']), $say('결과')]);
        ok('앞 제품의 하중 등급을 모르면 비교하지 않고 이유·확인 질문(튼튼하다고 하지 않음)', $c1 !== [] && $cs === [] && ($o['stronger_basis'] ?? '') === 'unknown' && str_contains((string) ($o['note'] ?? ''), '비교할 수 없습니다'), (string) ($o['note'] ?? ''));

        echo " ④ '차가 다니는데 이 제품 써도 돼?'\n";
        $t = $session($sid());
        [, , , $c0] = $t('그레이팅 수로용 폭 300 찾아줘. 사람만 다녀요.', [$call(['query' => '그레이팅 수로용', 'width_mm' => 300, 'traffic' => 'pedestrian']), $say('표')]);
        [$r, $o, , $cs] = $t('차가 다니는데 이 제품 써도 돼?', [$call($cf ? ['query' => '그레이팅 수로용', 'width_mm' => 300, 'traffic' => 'car', 'only_ids' => $ids($c0)] : ['query' => '그레이팅']), $say('판정')]);
        $i = $in($r);
        ok('검색 입력: 통행 car(최신 말이 앞의 보행자 말을 이김) · 지금 표 제품만 판정(only_ids)', ($i['traffic'] ?? '') === 'car' && ai_int_list($i['only_ids'] ?? null) === $ids($c0), $j($i) . ' ' . $fills($r));
        ok('보행자용 제품은 차량 통행에 맞다고 하지 않음: 표에서 빠지고 하중 부족 판정·이유', $cs === [] && ($o['recheck'] ?? []) !== [] && !array_filter((array) $o['recheck'], static fn($x) => $x['status'] !== 'load_lighter') && str_contains((string) ($o['note'] ?? ''), '이번 조건에 맞지 않아'), $j($o['recheck'] ?? null));

        echo " ⑤ '500짜리 수로관 20m 필요해.'\n";
        $t = $session($sid());
        [$r, $o, $sys, $cs] = $t('500짜리 수로관 20m 필요해.', [$call($cf ? ['query' => '수로관', 'spec_mm' => 500, 'length_m' => 20] : ['query' => '수로관', 'width_mm' => 500, 'length_m' => 20]), $say('결과')]);
        $i = $in($r);
        ok('500 을 폭으로 단정하지 않음(spec_mm 500) · 20m 는 설치 총 길이', !isset($i['width_mm']) && (int) ($i['spec_mm'] ?? 0) === 500 && (float) ($i['length_m'] ?? 0) === 20.0, $j($i) . ' ' . $fills($r));
        ok('어느 치수인지 확인 질문 근거·시스템 안내', str_contains((string) ($o['clarify'] ?? ''), '치수 이름 없이') && str_contains($sys, $hint), (string) ($o['clarify'] ?? ''));
        ok('표: 규격에 500mm 가 있는 제품만, 20m 수량(2m → 10본)', $cs !== [] && !array_filter($cs, static fn($c) => !str_contains((string) ($c['size'] ?? ''), '500mm') || ($c['suggested_qty'] ?? 0) !== 10), $j(array_map(static fn($c) => [$c['size'] ?? '', $c['suggested_qty'] ?? null], $cs)));

        echo " ⑥ '앞에서 추천한 것 중 가장 저렴한 제품으로 해줘.'\n";
        $t = $session($sid());
        [, , , $c0] = $t('폭 400mm, 길이 1m 그레이팅 찾아줘.', [$call(['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000]), $say('표')]);
        [, , $sys] = $t('앞에서 추천한 것 중 가장 저렴한 제품으로 해줘.', [$say('설치 길이를 알려 주세요')]);
        $units = array_values(array_unique(array_column($c0, 'unit')));
        ok('판매 단위가 섞이고(' . implode('·', $units) . ') 수량이 없으면 단가로 가장 저렴을 정하지 않음 — 설치 길이를 묻게', count($units) > 1 && str_contains($sys, "단가만으로 '가장 저렴한' 제품을 정할 수 없다") && !str_contains($sys, '(가장 저렴)') && !str_contains($sys, '(단가 가장 저렴'));
        [, , , $c2] = $t('설치 구간은 총 20m야.', [$call(['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20]), $say('수량')]);
        [, , $sys] = $t('앞에서 추천한 것 중 가장 저렴한 제품으로 해줘.', [$say('담겠습니다')]);
        $cheap = $c2 ? array_reduce($c2, static fn($a, $c) => $a === null || ($c['est_cost'] ?? PHP_INT_MAX) < ($a['est_cost'] ?? PHP_INT_MAX) ? $c : $a) : null;
        ok('설치 길이가 정해진 뒤: 같은 20m 기준 예상 자재비(현재 단가 × 수량)로 가장 저렴 표시', $cheap !== null && preg_match('/\[id ' . (int) $cheap['id'] . '\][^\n]*\(가장 저렴\)/u', $sys) === 1 && substr_count($sys, '(가장 저렴)') === 1, $cheap ? $cheap['name'] . ' ' . $cheap['est_cost'] : '');

        echo " ⑦ '폭 400으로 바꾸고 나머지는 그대로.'\n";
        $t = $session($sid());
        $t('폭 500mm, 길이 1m 그레이팅 찾아줘. 차량이 다녀요.', [$call(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000, 'traffic' => 'car']), $say('표')]);
        $t('설치 구간은 총 20m야.', [$call(['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000, 'length_m' => 20, 'traffic' => 'car']), $say('수량')]);
        [$r, $o, $sys, $cs] = $t('폭 400으로 바꾸고 나머지는 그대로.', [$call($cf ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'traffic' => 'car'] : ['query' => '그레이팅', 'width_mm' => 400]), $say('결과')]);
        $i = $in($r);
        ok('폭만 400, 제품 길이 1000·설치 20m·통행 car 유지', (float) ($i['width_mm'] ?? 0) === 400.0 && (float) ($i['product_length_mm'] ?? 0) === 1000.0 && (float) ($i['length_m'] ?? 0) === 20.0 && ($i['traffic'] ?? '') === 'car', $j($i) . ' ' . $fills($r));
        ok('표: 폭 400·길이 1000 승용차용(하중 확인)만, 20개', $cs !== [] && !array_filter($cs, static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), '폭 W 400mm · 길이 L 1000mm') || ($c['load_check'] ?? '') !== 'verified' || ($c['suggested_qty'] ?? 0) !== 20), $j(array_map(static fn($c) => [$c['name'], $c['load_check'] ?? null], $cs)));
        ok('모호한 점 없음 → 확인 질문 근거를 붙이지 않음', ($o['clarify'] ?? '') === '' && !str_contains($sys, $hint));

        echo " ⑧ '이 제품 대신 비슷한 규격의 다른 제품 보여줘.'\n";
        $t = $session($sid());
        [, , , $c0] = $t('중하중 그레이팅 수로용 폭 400 길이 1m 찾아줘.', [$call(['query' => '중하중 그레이팅 수로용', 'width_mm' => 400, 'product_length_mm' => 1000]), $say('표')]);
        [$r, $o, , $cs] = $t('이 제품 대신 비슷한 규격의 다른 제품 보여줘.', [$call($cf ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'exclude_ids' => $ids($c0)] : ['query' => '그레이팅']), $say('결과')]);
        $i = $in($r);
        ok('같은 확인 치수(폭 400·길이 1000) 유지 · 앞 제품 제외', (float) ($i['width_mm'] ?? 0) === 400.0 && (float) ($i['product_length_mm'] ?? 0) === 1000.0 && !array_diff($ids($c0), ai_int_list($i['exclude_ids'] ?? null)), $j($i));
        ok('결과: 폭 400·길이 1000 다른 제품(앞 제품 이름 없음)', $cs !== [] && !array_filter($cs, static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), '폭 W 400mm · 길이 L 1000mm')) && !array_intersect(array_column($cs, 'name'), array_column($c0, 'name')), $j(array_column($cs, 'name')));
        $t = $session($sid());
        [, , , $c0] = $t('그레이팅 보여줘.', [$call(['query' => '그레이팅']), $say('표')]);
        [$r, $o] = $t('이 제품 대신 비슷한 규격의 다른 제품 보여줘.', [$call(['query' => '그레이팅']), $say('결과')]);
        $i = $in($r);
        ok('앞 표 제품마다 치수가 다르면 치수를 정하지 않고 어느 제품 기준인지 확인', !isset($i['width_mm']) && !isset($i['product_length_mm']) && str_contains((string) ($o['clarify'] ?? ''), '어느 제품'), $j($i) . ' ' . (string) ($o['clarify'] ?? ''));

        echo " ⑨ 제외 조건 유지 — '다른 제품' 뒤 조건을 바꿔도 고객이 뺀 제품은 다시 추천하지 않음\n";
        $t = $session($sid());
        [, , , $t1] = $t('그레이팅 추천해줘.', [$call(['query' => '그레이팅']), $say('표')]);
        [, , , $t2] = $t('다른 제품 추천해줘.', [$call(['query' => '그레이팅']), $say('다른 표')]);
        [$r, $o, , $t3] = $t('폭 400으로 찾아줘.', [$call($cf ? ['query' => '그레이팅', 'width_mm' => 400] : ['query' => '그레이팅', 'width_mm' => 400]), $say('결과')]);
        $i = $in($r);
        ok('2번째 표는 1번째 표와 다른 제품', $t2 !== [] && !array_intersect(array_column($t2, 'name'), array_column($t1, 'name')));
        ok('조건을 바꾼 3번째 검색도 고객이 뺀 1번째 표 제품을 계속 제외', !array_diff($ids($t1), ai_int_list($i['exclude_ids'] ?? null)) && str_contains($fills($r), '고객이 앞서 뺀 제품'), $j($i['exclude_ids'] ?? null) . ' ' . $fills($r));
        ok('3번째 표: 폭 400 · 1번째 표 이름 없음', $t3 !== [] && !array_intersect(array_column($t3, 'name'), $names($ids($t1))) && !array_filter($t3, static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), '폭 W 400mm')), $j(array_column($t3, 'name')));
        $t = $session($sid());
        $t('그레이팅 추천해줘.', [$call(['query' => '그레이팅']), $say('표')]);
        $t('다른 제품 추천해줘.', [$call(['query' => '그레이팅']), $say('다른 표')]);
        [$r] = $t('수로관 폭 500 찾아줘.', [$call(['query' => '수로관', 'width_mm' => 500]), $say('결과')]);
        ok('다른 품목(수로관)으로 바꾸면 그레이팅 제외 목록을 가져가지 않음', !isset($in($r)['exclude_ids']), $j($in($r)));
    }
    ok('모든 턴: 한 추천표에 같은 상품(같은 id·같은 이름)이 두 번 나오지 않음', $dupFree);
} finally {
    foreach ($sids as $s) { db()->prepare('DELETE FROM chat_logs WHERE session_key = ?')->execute([$s]); }
    proc_terminate($srv);
    array_map('unlink', glob("{$tmp}/*") ?: []);
    @rmdir($tmp);
}

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
