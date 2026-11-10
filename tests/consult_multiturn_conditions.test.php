<?php
declare(strict_types=1);
/**
 * AI 상담 연속 대화 조건 유지·복합 조건 테스트 (2026-11-11) — 목업 OpenAI(127.0.0.1), 로컬 테스트 DB(*_test), 외부 접속 없음.
 *
 * 흐름 A(사용자 요청 복합 문장 8개를 한 상담으로): 폭 500·길이 1m 그레이팅 → 차량 통행 → 폭만 400 → 설치 20m 몇 개 → 앞 제품 빼고 → 더 저렴한
 *   → 폭 500·길이 2m 수로관 → 저렴해도 차량 통행에 안 맞으면 제외.
 * 흐름 E(10단계): 조건 제시 → 추천 → 치수 변경 → 이전 제품 제외 → 더 저렴한 → 설치 총 길이 → 수량 → 차량 조건 추가 → 앞서 추천한 제품 재검토 → 최종 비교.
 * 각 흐름을 두 가지 목업 GPT 로 돌린다:
 *   careless — 실제 GPT 가 할 수 있는 실수(폭을 spec_mm 으로, 제품 길이 1m 를 설치 길이 length_m 1 로, 설치 20m 를 제품 길이 20000 으로,
 *              바꾼 뒤에도 옛 폭, 통행·제외·금액 기준·직전 표 id 를 빠뜨림)
 *   careful  — 도구 설명대로 바르게 넘김(GPT 는 직전 표 id 만 앎)
 * 확인: 서버가 바로잡은 검색 입력(도구 기록 input·fills)과 실제 DB 검색 결과(추천표 카드·GPT 에 돌려준 note·recheck)가 두 경우 모두 기준을 지키는지.
 *   폭·길이·높이 혼동 없음 / 제품 1개 길이 ≠ 설치 총 길이 / 바꾸지 않은 조건 유지 / 이 상담에서 보인 제품 제외 / 더 저렴한 = 같은 조건에서만 /
 *   조건에 안 맞으면 가격이 싸도 표에 없음 / 없으면 없다고 / 하중은 확인됨(상품명 등급·규격 표기)과 확인 필요를 나눔.
 * 실제 GPT 가 이 값을 스스로 넘기는지는 이 테스트로 확인하지 않는다(목업) — tests/consult_gpt_live.test.php(키가 있을 때만).
 * 기록: 테스트 상담 키 'multiturn-test-*' 로 chat_logs 에 쓰고 끝나면 지운다(로컬 테스트 DB만).
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/consult_multiturn_conditions.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
$tmp = sys_get_temp_dir() . '/multiturn_test_' . getmypid();
@mkdir($tmp, 0777, true);
$port = 19560 + getmypid() % 250;
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
$need = (int) db()->query("SELECT COUNT(*) FROM products WHERE is_active = 1 AND name = '중하중그레이팅수로용' AND dim_w = 400 AND dim_l = 1000")->fetchColumn();
if ($need === 0) {
    echo "SKIP: 규격 치수 칸이 채워진 수집 상품(중하중그레이팅수로용 W400 L1000)이 없는 DB입니다. php scripts/testdb/prodlike-db.php 로 만드세요.\n";
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
$call = static fn(array $args, int $i) => ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
    'tool_calls' => [['id' => "m{$i}", 'type' => 'function', 'function' => ['name' => 'search_products', 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]]]]]]];
$sids = [];
/** 한 상담 실행기 — [턴 함수]. 턴: 목업 응답을 정해 rag_process 실행 → chat.php 처럼 로그·대화 저장 → [결과, GPT 에 돌려준 search_products 결과] */
$session = static function (string $sid) use ($tmp, &$sids): Closure {
    $sids[] = $sid;
    $messages = [];
    return static function (string $user, array $responses) use ($tmp, $sid, &$messages): array {
        array_map('unlink', glob("{$tmp}/req-*.json") ?: []);
        @unlink("{$tmp}/n");
        file_put_contents("{$tmp}/responses.json", json_encode($responses, JSON_UNESCAPED_UNICODE));
        $send = array_merge($messages, [['role' => 'user', 'content' => $user]]);
        $res = rag_process($send, $sid);
        rag_log_chat($sid, $user, (string) ($res['text'] ?? ''), $res['events'] ?? [], (string) ($res['category'] ?? ''), null);
        $messages = array_merge($send, [['role' => 'assistant', 'content' => (string) ($res['text'] ?? '')]]);
        $tool = [];
        $n = (int) @file_get_contents("{$tmp}/n");
        $last = json_decode((string) @file_get_contents("{$tmp}/req-{$n}.json"), true) ?: [];
        foreach (array_reverse($last['messages'] ?? []) as $m) { if (($m['role'] ?? '') === 'tool') { $tool = json_decode((string) $m['content'], true) ?: []; break; } }
        return [$res, $tool];
    };
};
$cards = static function (array $res): array {
    $out = [];
    foreach ($res['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'product_cards') { $out = array_merge($out, $e['products'] ?? []); } }
    return $out;
};
$in    = static fn(array $res): array => (array) (($res['tools'][0]['input'] ?? []));   // 서버가 바로잡은 뒤 실제로 검색한 입력
$fills = static fn(array $res): string => implode(' / ', (array) ($res['tools'][0]['fills'] ?? []));
$ids   = static fn(array $cs): array => array_values(array_map(static fn($c) => (int) $c['id'], $cs));
$sizes = static fn(array $cs): array => array_map(static fn($c) => (string) ($c['size'] ?? ''), $cs);
$allSize = static fn(array $cs, string $prefix): bool => $cs !== [] && !array_filter($cs, static fn($c) => !str_starts_with((string) ($c['size'] ?? ''), $prefix));
$names = static function (array $idList): array {
    if (!$idList) { return []; }
    $st = db()->prepare('SELECT DISTINCT name FROM products WHERE id IN (' . implode(',', array_fill(0, count($idList), '?')) . ')');
    $st->execute(array_values($idList));
    return $st->fetchAll(PDO::FETCH_COLUMN);
};
$rank = static fn(array $c): int => AI_LOAD_RANK[ai_load_class_of(['name' => (string) $c['name'], 'spec' => (string) ($c['spec'] ?? '')])] ?? 0;
$clean = static function () use (&$sids): void { foreach ($sids as $s) { db()->prepare('DELETE FROM chat_logs WHERE session_key = ?')->execute([$s]); } };
$j = static fn($v): string => json_encode($v, JSON_UNESCAPED_UNICODE);

try {
    foreach (['careless', 'careful'] as $mode) {
        $careful = $mode === 'careful';
        // ───────────────────────── 흐름 A: 복합 문장 8개 ─────────────────────────
        echo "\n[흐름 A · 목업 GPT {$mode}] 복합 조건 8문장\n";
        $t = $session("multiturn-test-a-{$mode}-" . getmypid());
        $shown = [];

        echo " 1) '폭 500mm, 길이 1m 그레이팅 찾아줘.'\n";
        [$r1, $o1] = $t('폭 500mm, 길이 1m 그레이팅 찾아줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000] : ['query' => '그레이팅', 'spec_mm' => 500, 'length_m' => 1], 1), $say('폭 500mm·길이 1m 그레이팅입니다.')]);
        $c1 = $cards($r1); $shown = array_merge($shown, $ids($c1));
        $i1 = $in($r1);
        ok('검색 입력: 폭 500 · 제품 1개 길이 1000mm · 설치 길이 없음(1m 를 설치 길이로 쓰지 않음)', (float) ($i1['width_mm'] ?? 0) === 500.0 && (float) ($i1['product_length_mm'] ?? 0) === 1000.0 && !isset($i1['length_m']) && !isset($i1['spec_mm']), $j($i1) . ' ' . $fills($r1));
        ok('추천표: 모두 폭 W 500mm · 길이 L 1000mm(높이·다른 치수와 혼동 없음)', $allSize($c1, '폭 W 500mm · 길이 L 1000mm'), $j($sizes($c1)));
        ok('수량 없음(설치 길이를 말하지 않음)', !array_filter($c1, static fn($c) => isset($c['suggested_qty'])));

        echo " 2) '차량이 다니는 곳에 설치할 수 있는 제품으로 추천해줘.'\n";
        [$r2, $o2] = $t('차량이 다니는 곳에 설치할 수 있는 제품으로 추천해줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000, 'traffic' => 'car', 'recheck_ids' => $ids($c1)] : ['query' => '그레이팅'], 2), $say('차량 통행 조건으로 확인했습니다.')]);
        $c2 = $cards($r2); $shown = array_merge($shown, $ids($c2));
        $i2 = $in($r2);
        ok('검색 입력: 통행 car · 폭 500·제품 길이 1000 유지', ($i2['traffic'] ?? '') === 'car' && (float) ($i2['width_mm'] ?? 0) === 500.0 && (float) ($i2['product_length_mm'] ?? 0) === 1000.0, $j($i2));
        ok('표에 보행자용(하중 부족) 없음 — 가격이 싸도', !array_filter($c2, static fn($c) => $rank($c) === 1));
        ok('표에 하중 확인된 제품만 맞다고 표시(확인 필요는 load_note)', !array_filter($c2, static fn($c) => ($c['load_check'] ?? '') !== 'verified' && ($c['load_check'] ?? '') !== 'unverified'));
        $rk2 = array_column((array) ($o2['recheck'] ?? []), 'status', 'name');
        ok('앞서 보인 1)표 재확인: EX메탈망그레이팅 하중 부족, 안전중하중 과잉', ($rk2['EX메탈망그레이팅'] ?? '') === 'load_lighter' && ($rk2['안전중하중그레이팅수로용'] ?? '') === 'load_heavier', $j($o2['recheck'] ?? null));
        ok('폭 500·길이 1m 승용차용이 없으면 없다고(높은 등급만 있음) 안내', $c2 !== [] || str_contains((string) ($o2['note'] ?? ''), '승용차용'), (string) ($o2['note'] ?? ''));

        echo " 3) '폭은 400으로 바꾸고 길이는 그대로 해줘.'\n";
        [$r3, $o3] = $t('폭은 400으로 바꾸고 길이는 그대로 해줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car'] : ['query' => '그레이팅', 'width_mm' => 500], 3), $say('폭 400mm 로 다시 찾았습니다.')]);
        $c3 = $cards($r3); $shown = array_merge($shown, $ids($c3));
        $i3 = $in($r3);
        ok('검색 입력: 폭 400(옛 폭 500 아님) · 제품 길이 1000 유지 · 통행 car 유지', (float) ($i3['width_mm'] ?? 0) === 400.0 && (float) ($i3['product_length_mm'] ?? 0) === 1000.0 && ($i3['traffic'] ?? '') === 'car', $j($i3) . ' ' . $fills($r3));
        ok('추천표: 폭 W 400mm · 길이 L 1000mm 승용차용(중하중, 상품명 등급으로 확인)', $allSize($c3, '폭 W 400mm · 길이 L 1000mm') && !array_filter($c3, static fn($c) => ($c['load_check'] ?? '') !== 'verified'), $j(array_map(static fn($c) => $c['name'] . ' ' . ($c['load_check'] ?? ''), $c3)));

        echo " 4) '설치 구간이 총 20m야. 몇 개 필요해?'\n";
        [$r4, $o4] = $t('설치 구간이 총 20m야. 몇 개 필요해?', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car', 'length_m' => 20, 'only_ids' => $ids($c3)] : ['query' => '그레이팅', 'product_length_mm' => 20000], 4), $say('20개 필요합니다.')]);
        $c4 = $cards($r4);
        $i4 = $in($r4);
        ok('검색 입력: 설치 길이 20m(제품 길이 20000 아님) · 제품 길이 1000 · 직전 표 제품만(only_ids)', (float) ($i4['length_m'] ?? 0) === 20.0 && (float) ($i4['product_length_mm'] ?? 0) === 1000.0 && ai_int_list($i4['only_ids'] ?? null) === $ids($c3), $j($i4) . ' ' . $fills($r4));
        ok('수량: 제품 1개 길이 1m 로 20m → 20개, 예상 자재비 = 단가 × 20', $ids($c4) === $ids($c3) && !array_filter($c4, static fn($c) => ($c['suggested_qty'] ?? 0) !== 20 || ($c['est_cost'] ?? 0) !== (int) $c['price_member'] * 20), $j(array_map(static fn($c) => [$c['name'], $c['suggested_qty'] ?? null, $c['est_cost'] ?? null], $c4)));

        echo " 5) '앞에 보여준 제품은 빼고 다른 제품 추천해줘.'\n";
        [$r5, $o5] = $t('앞에 보여준 제품은 빼고 다른 제품 추천해줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car', 'length_m' => 20, 'exclude_ids' => $ids($c4)] : ['query' => '그레이팅'], 5), $say('다른 제품을 확인했습니다.')]);
        $c5 = $cards($r5); $shown = array_merge($shown, $ids($c5));
        $i5 = $in($r5);
        ok('제외: 이 상담에서 보인 제품 전체(1)~4)표)', !array_diff(array_unique($shown), ai_int_list($i5['exclude_ids'] ?? null)), $j($i5['exclude_ids'] ?? null));
        ok('조건 유지: 폭 400·제품 길이 1000·설치 20m·통행 car', (float) ($i5['width_mm'] ?? 0) === 400.0 && (float) ($i5['product_length_mm'] ?? 0) === 1000.0 && (float) ($i5['length_m'] ?? 0) === 20.0 && ($i5['traffic'] ?? '') === 'car', $j($i5));
        $shownNames = $names(array_values(array_unique(array_diff($shown, $ids($c5)))));
        ok('앞서 보인 제품(같은 이름 다른 규격 포함)을 새 추천처럼 다시 보이지 않음', !array_intersect(array_column($c5, 'name'), $shownNames), $j(array_column($c5, 'name')));
        ok('조건에 맞는 다른 제품이 없으면 없다고 안내', $c5 !== [] || preg_match('/없습니다/u', (string) ($o5['note'] ?? '')) === 1, (string) ($o5['note'] ?? ''));

        echo " 6) '조건은 그대로 두고 좀 더 저렴한 제품으로 찾아줘.'\n";
        $minEst = rag_recent_min_est(array_map(static fn($c) => ['price' => $c['price_member'] ?? null, 'qty' => $c['suggested_qty'] ?? null], $c5 ?: $c4));
        [$r6, $o6] = $t('조건은 그대로 두고 좀 더 저렴한 제품으로 찾아줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'traffic' => 'car', 'length_m' => 20, 'exclude_ids' => $ids($c5 ?: $c4), 'max_est_cost' => $minEst] : ['query' => '그레이팅'], 6), $say('더 저렴한 제품을 확인했습니다.')]);
        $c6 = $cards($r6);
        $i6 = $in($r6);
        ok('금액 기준: 같은 조건(설치 20m)의 직전 표 최저 예상 자재비', (int) ($i6['max_est_cost'] ?? 0) === $minEst && $minEst > 0, $j($i6) . ' 기준 ' . $minEst);
        ok('조건 유지·제외 유지', (float) ($i6['width_mm'] ?? 0) === 400.0 && ($i6['traffic'] ?? '') === 'car' && !array_diff(array_unique($shown), ai_int_list($i6['exclude_ids'] ?? null)), $j($i6));
        ok('더 저렴한 제품: 기준보다 싸고 조건에 맞는 것만, 없으면 없다고', $c6 ? !array_filter($c6, static fn($c) => ($c['est_cost'] ?? PHP_INT_MAX) >= $minEst || ($c['load_check'] ?? '') !== 'verified') : preg_match('/없습니다/u', (string) ($o6['note'] ?? '')) === 1, (string) ($o6['note'] ?? '') . ' ' . $j($ids($c6)));

        echo " 7) '폭 500인 수로관인데 길이는 2m인 제품 찾아줘.'\n";
        [$r7, $o7] = $t('폭 500인 수로관인데 길이는 2m인 제품 찾아줘.', [$call($careful ? ['query' => '수로관', 'width_mm' => 500, 'product_length_mm' => 2000, 'traffic' => 'car'] : ['query' => '수로관', 'spec_mm' => 500, 'length_m' => 2], 7), $say('수로관을 찾았습니다.')]);
        $c7 = $cards($r7);
        $i7 = $in($r7);
        ok('검색 입력: 수로관 폭 500 · 제품 길이 2000(설치 길이 2m 아님) · 그레이팅의 설치 20m 를 가져오지 않음', ($i7['query'] ?? '') === '수로관' && (float) ($i7['width_mm'] ?? 0) === 500.0 && (float) ($i7['product_length_mm'] ?? 0) === 2000.0 && !isset($i7['length_m']) && !isset($i7['spec_mm']), $j($i7) . ' ' . $fills($r7));
        ok('추천표: 모두 폭 W 500mm 이고 길이 L 2000mm(수로관 폭×깊이×길이 — 깊이 500 과 혼동 없음)', $c7 !== [] && !array_filter($c7, static fn($c) => !str_starts_with((string) $c['size'], '폭 W 500mm') || !str_contains((string) $c['size'], '길이 L 2000mm')), $j($sizes($c7)));
        ok('수로관 하중: 상품 정보에 근거가 없어 모두 하중 확인 필요(차량 통행에 맞다고 하지 않음)', $c7 !== [] && !array_filter($c7, static fn($c) => ($c['load_check'] ?? '') !== 'unverified' || !str_contains((string) ($c['load_note'] ?? ''), '하중 확인 필요')), $j(array_map(static fn($c) => $c['name'] . ':' . ($c['load_check'] ?? '-'), $c7)));
        ok("안내: '차량 통행 하중이 확인된 제품은 없습니다'", str_contains((string) ($o7['note'] ?? ''), '차량 통행 하중이 상품 정보로 확인된 제품은 없습니다'), (string) ($o7['note'] ?? ''));

        echo " 8) '가격이 저렴해도 차량 통행에 맞지 않으면 제외해줘.'\n";
        [$r8, $o8] = $t('가격이 저렴해도 차량 통행에 맞지 않으면 제외해줘.', [$call($careful ? ['query' => '수로관', 'width_mm' => 500, 'product_length_mm' => 2000, 'traffic' => 'car', 'recheck_ids' => $ids($c7)] : ['query' => '수로관'], 8), $say('차량 통행 기준으로 다시 확인했습니다.')]);
        $c8 = $cards($r8);
        $i8 = $in($r8);
        ok('검색 입력: 통행 car · 폭 500 · 제품 길이 2000 유지 · 앞 표 재확인', ($i8['traffic'] ?? '') === 'car' && (float) ($i8['width_mm'] ?? 0) === 500.0 && (float) ($i8['product_length_mm'] ?? 0) === 2000.0 && array_intersect($ids($c7), ai_int_list($i8['recheck_ids'] ?? null)) === $ids($c7), $j($i8));
        ok('재확인 결과: 앞 표 수로관은 모두 하중 확인 필요(맞음으로 바꾸지 않음)', ($o8['recheck'] ?? []) !== [] && !array_filter((array) $o8['recheck'], static fn($x) => $x['status'] === 'ok'), $j($o8['recheck'] ?? null));
        ok('표: 하중 확인된 제품처럼 보이는 제품 없음 · 보행자용 없음', !array_filter($c8, static fn($c) => ($c['load_check'] ?? '') === 'verified' || $rank($c) === 1));
        ok('GPT 에 돌려준 안내: 확인된 제품 없음 + 확인 필요 이유', str_contains((string) ($o8['note'] ?? ''), '확인된 제품은 없습니다') && str_contains((string) ($o8['note'] ?? ''), '확인이 필요합니다'), (string) ($o8['note'] ?? ''));

        // ───────────────────────── 흐름 E: 10단계 연속 상담 ─────────────────────────
        echo "\n[흐름 E · 목업 GPT {$mode}] 10단계 연속 상담\n";
        $t = $session("multiturn-test-e-{$mode}-" . getmypid());
        $shown = [];

        echo " 1) 조건 제시 '마당 배수로에 놓을 그레이팅을 찾고 있어요. 폭 500mm, 길이 1m 제품이요.'\n";
        [$e1] = $t('마당 배수로에 놓을 그레이팅을 찾고 있어요. 폭 500mm, 길이 1m 제품이요.', [$say('폭 500mm·길이 1m 그레이팅으로 찾아 볼까요? 차량이 다니는지도 알려 주세요.')]);
        ok('조건만 말한 턴: 검색 없음(목업)', ($e1['tools'] ?? []) === []);

        echo " 2) 추천 '그 조건으로 추천해줘.'\n";
        [$e2, $q2] = $t('그 조건으로 추천해줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000] : ['query' => '그레이팅'], 21), $say('추천 제품입니다.')]);
        $d2 = $cards($e2); $shown = array_merge($shown, $ids($d2));
        $j2 = $in($e2);
        ok('앞 턴에 말한 폭 500·제품 길이 1000 을 검색에 반영(GPT 가 빠뜨려도)', (float) ($j2['width_mm'] ?? 0) === 500.0 && (float) ($j2['product_length_mm'] ?? 0) === 1000.0 && !isset($j2['traffic']), $j($j2) . ' ' . $fills($e2));
        ok('추천표: 폭 W 500mm · 길이 L 1000mm, 낮은 금액 순', $allSize($d2, '폭 W 500mm · 길이 L 1000mm') && array_column($d2, 'price_member') === (function ($p) { sort($p); return $p; })(array_column($d2, 'price_member')), $j(array_map(static fn($c) => [$c['name'], $c['price_member']], $d2)));
        ok("통행을 말하지 않음 → 통행 조건을 만들지 않음('마당'으로 짐작 안 함)", !isset($j2['traffic']));

        echo " 3) 치수 변경 '폭은 400으로 바꿔 줘.'\n";
        [$e3] = $t('폭은 400으로 바꿔 줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000] : ['query' => '그레이팅', 'width_mm' => 500, 'product_length_mm' => 1000], 22), $say('폭 400mm 제품입니다.')]);
        $d3 = $cards($e3); $shown = array_merge($shown, $ids($d3));
        $j3 = $in($e3);
        ok('폭만 400, 제품 길이 1000 유지', (float) ($j3['width_mm'] ?? 0) === 400.0 && (float) ($j3['product_length_mm'] ?? 0) === 1000.0, $j($j3) . ' ' . $fills($e3));
        ok('추천표: 모두 폭 W 400mm · 길이 L 1000mm(폭 500 제품 없음)', $allSize($d3, '폭 W 400mm · 길이 L 1000mm'), $j($sizes($d3)));

        echo " 4) 이전 제품 제외 '앞에 보여준 제품은 빼고 다른 제품 추천해줘.'\n";
        [$e4, $q4] = $t('앞에 보여준 제품은 빼고 다른 제품 추천해줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'exclude_ids' => $ids($d3)] : ['query' => '그레이팅'], 23), $say('다른 제품입니다.')]);
        $d4 = $cards($e4);
        $j4 = $in($e4);
        ok('제외: 2)·3) 표 제품 전체(GPT 가 직전 표 id 만 넘겨도)', !array_diff(array_unique($shown), ai_int_list($j4['exclude_ids'] ?? null)), $j($j4['exclude_ids'] ?? null));
        ok('조건 유지: 폭 400·제품 길이 1000', (float) ($j4['width_mm'] ?? 0) === 400.0 && (float) ($j4['product_length_mm'] ?? 0) === 1000.0, $j($j4));
        $nm = $names(array_values(array_unique($shown)));
        ok('앞서 보인 제품(같은 이름 포함)이 다시 나오지 않고, 나오면 폭 400·길이 1000 만', !array_intersect(array_column($d4, 'name'), $nm) && ($d4 === [] || $allSize($d4, '폭 W 400mm · 길이 L 1000mm')), $j(array_map(static fn($c) => [$c['name'], $c['size']], $d4)));
        ok('없으면 없다고 안내', $d4 !== [] || preg_match('/없습니다/u', (string) ($q4['note'] ?? '')) === 1, (string) ($q4['note'] ?? ''));
        $shown = array_merge($shown, $ids($d4));

        echo " 5) 더 저렴한 '조건은 그대로 두고 좀 더 저렴한 제품으로 찾아줘.'\n";
        $base5 = $d4 ?: $d3;
        $units = array_values(array_unique(array_map(static fn($c) => (string) $c['unit'], $base5)));
        [$e5, $q5] = $t('조건은 그대로 두고 좀 더 저렴한 제품으로 찾아줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'exclude_ids' => $ids($base5)] : ['query' => '그레이팅'], 24), $say('더 저렴한 제품을 확인했습니다.')]);
        $d5 = $cards($e5);
        $j5 = $in($e5);
        $minP = $base5 ? min(array_map(static fn($c) => (int) $c['price_member'], $base5)) : 0;
        if (count($units) === 1) {
            ok("금액 기준: 설치 길이가 없어 같은 규격·같은 판매 단위({$units[0]})의 직전 표 최저 단가", (int) ($j5['max_unit_price'] ?? 0) === $minP && ($j5['price_unit'] ?? '') === $units[0], $j($j5));
            ok('더 저렴한 제품: 기준 단가보다 싸고 같은 단위·같은 규격만, 없으면 없다고', $d5 ? !array_filter($d5, static fn($c) => (int) $c['price_member'] >= $minP || (string) $c['unit'] !== $units[0] || !str_starts_with((string) $c['size'], '폭 W 400mm · 길이 L 1000mm')) : preg_match('/없습니다/u', (string) ($q5['note'] ?? '')) === 1, (string) ($q5['note'] ?? '') . ' ' . $j($ids($d5)));
        } else {
            ok('직전 표 판매 단위가 섞여(' . implode('·', $units) . ') 금액 비교 불가 → 옛 금액 기준을 쓰지 않고 이유를 안내', !isset($j5['max_est_cost']) && !isset($j5['max_unit_price']) && ($j5['cheaper_incomparable'] ?? '') === 'unit'
                && ($d5 === [] || str_contains((string) ($q5['note'] ?? ''), '판매 단위가 서로 달라')), $j($j5) . ' ' . (string) ($q5['note'] ?? ''));
        }
        ok('조건 유지·제외 유지(폭 400·길이 1000·앞서 보인 제품)', (float) ($j5['width_mm'] ?? 0) === 400.0 && (float) ($j5['product_length_mm'] ?? 0) === 1000.0 && !array_diff(array_unique($shown), ai_int_list($j5['exclude_ids'] ?? null)), $j($j5));
        $shown = array_merge($shown, $ids($d5));
        $cur = $d5 ?: ($d4 ?: $d3);   // 지금 고객 화면의 마지막 추천표

        echo " 6) 설치 총 길이 '설치 구간은 총 20m야.'\n";
        [$e6] = $t('설치 구간은 총 20m야.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'only_ids' => $ids($cur)] : ['query' => '그레이팅', 'product_length_mm' => 20000], 25), $say('20m 기준으로 계산했습니다.')]);
        $d6 = $cards($e6);
        $j6 = $in($e6);
        ok('설치 길이 20m(제품 길이 20000 아님)·제품 길이 1000 유지·지금 표 제품만(앞서 뺀 제품을 다시 꺼내지 않음)', (float) ($j6['length_m'] ?? 0) === 20.0 && (float) ($j6['product_length_mm'] ?? 0) === 1000.0 && ai_int_list($j6['only_ids'] ?? null) === $ids($cur), $j($j6) . ' ' . $fills($e6));
        ok('표: 지금 표 제품 그대로, 권장 수량 20', $ids($d6) === $ids($cur) && !array_filter($d6, static fn($c) => ($c['suggested_qty'] ?? 0) !== 20), $j(array_map(static fn($c) => [$c['name'], $c['suggested_qty'] ?? null], $d6)));

        echo " 7) 수량 계산 '그럼 몇 개 필요해?'\n";
        [$e7] = $t('그럼 몇 개 필요해?', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'only_ids' => $ids($d6)] : ['query' => '그레이팅'], 26), $say('20개 필요합니다.')]);
        $d7 = $cards($e7);
        $j7 = $in($e7);
        ok('수량 질문: 직전 표 제품·설치 20m 유지', ai_int_list($j7['only_ids'] ?? null) === $ids($d6) && (float) ($j7['length_m'] ?? 0) === 20.0 && (float) ($j7['width_mm'] ?? 0) === 400.0, $j($j7) . ' ' . $fills($e7));
        ok('수량 20개·예상 자재비 = 현재 단가 × 20(도구 계산)', $d7 !== [] && !array_filter($d7, static fn($c) => ($c['suggested_qty'] ?? 0) !== 20 || ($c['est_cost'] ?? 0) !== (int) $c['price_member'] * 20), $j(array_map(static fn($c) => [$c['name'], $c['suggested_qty'] ?? null, $c['est_cost'] ?? null], $d7)));

        echo " 8) 차량 조건 추가 '아 그리고 차량이 다니는 곳이야.'\n";
        [$e8, $q8] = $t('아 그리고 차량이 다니는 곳이야.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'traffic' => 'car', 'recheck_ids' => $ids($d7)] : ['query' => '그레이팅'], 27), $say('차량 통행 조건으로 다시 찾았습니다.')]);
        $d8 = $cards($e8);
        $j8 = $in($e8);
        ok('검색 입력: 통행 car 추가, 폭 400·제품 길이 1000·설치 20m 유지', ($j8['traffic'] ?? '') === 'car' && (float) ($j8['width_mm'] ?? 0) === 400.0 && (float) ($j8['product_length_mm'] ?? 0) === 1000.0 && (float) ($j8['length_m'] ?? 0) === 20.0, $j($j8) . ' ' . $fills($e8));
        ok('직전 표(7) 제품 재확인 결과가 GPT 에 전달', array_column((array) ($q8['recheck'] ?? []), 'id') !== [] && !array_diff($ids($d7), array_column((array) ($q8['recheck'] ?? []), 'id')), $j($q8['recheck'] ?? null));
        ok('재확인: 보행자용(하중 등급이 상품명으로 확인된 보행자 그레이팅)은 하중 부족으로 표시', !array_filter((array) ($q8['recheck'] ?? []), static fn($x) => ai_grating_load_class((string) $x['name']) === 'pedestrian' && $x['status'] !== 'load_lighter'));
        ok('새 표: 승용차용(상품명 등급 확인)만, 보행자용·과잉 등급 없음, 수량 20', $d8 !== [] && !array_filter($d8, static fn($c) => ($c['load_check'] ?? '') !== 'verified' || ($c['suggested_qty'] ?? 0) !== 20) && $allSize($d8, '폭 W 400mm · 길이 L 1000mm'), $j(array_map(static fn($c) => [$c['name'], $c['load_check'] ?? null], $d8)));

        echo " 9) 기존 추천 재검토 '앞에 추천했던 제품들은 차량 통행에 맞아?'\n";
        [$e9, $q9] = $t('앞에 추천했던 제품들은 차량 통행에 맞아?', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'traffic' => 'car', 'recheck_ids' => $ids($d8)] : ['query' => '그레이팅'], 28), $say('앞서 추천한 제품을 다시 확인했습니다.')]);
        $d9 = $cards($e9);
        $j9 = $in($e9);
        $rk9 = (array) ($q9['recheck'] ?? []);
        ok('재검토 범위: 직전 표만이 아니라 이 상담에서 보인 제품(2)·3)표 포함, 최대 12개)', count($rk9) >= min(12, count(array_unique($shown))) && array_intersect($ids($d3), array_column($rk9, 'id')) !== [], $j(array_column($rk9, 'id')) . ' / ' . $fills($e9));
        ok('재검토: 폭이 다른 2)표 제품은 치수 다름, 보행자용은 하중 부족 — 맞다고(ok) 하지 않음', !array_filter($rk9, static fn($x) => $x['status'] === 'ok' && (ai_grating_load_class((string) $x['name']) !== 'car')), $j(array_map(static fn($x) => $x['name'] . ':' . $x['status'], $rk9)));
        ok('재검토 안내 note: 맞지 않게 된 제품과 이유', str_contains((string) ($q9['note'] ?? ''), '이번 조건에 맞지 않아'), (string) ($q9['note'] ?? ''));
        ok('조건 유지(폭 400·길이 1000·20m·car)', (float) ($j9['width_mm'] ?? 0) === 400.0 && (float) ($j9['length_m'] ?? 0) === 20.0 && ($j9['traffic'] ?? '') === 'car', $j($j9));

        echo " 10) 최종 비교 '최종적으로 비교해줘.'\n";
        $last = $d9 ?: $d8;
        [$e10, $q10] = $t('최종적으로 비교해줘.', [$call($careful ? ['query' => '그레이팅', 'width_mm' => 400, 'product_length_mm' => 1000, 'length_m' => 20, 'traffic' => 'car', 'only_ids' => $ids($last)] : ['query' => '그레이팅'], 29), $say('최종 비교입니다.')]);
        $d10 = $cards($e10);
        $j10 = $in($e10);
        ok('최종 비교: 직전 표 제품만(only_ids)·조건 유지', ai_int_list($j10['only_ids'] ?? null) === $ids($last) && ($j10['traffic'] ?? '') === 'car' && (float) ($j10['length_m'] ?? 0) === 20.0 && (float) ($j10['width_mm'] ?? 0) === 400.0, $j($j10) . ' ' . $fills($e10));
        ok('최종 표: 차량 통행 하중 확인된 제품·폭 400·길이 1000·20개·예상 자재비 = 현재 단가 × 20', $d10 !== [] && $allSize($d10, '폭 W 400mm · 길이 L 1000mm') && !array_filter($d10, static fn($c) => ($c['load_check'] ?? '') !== 'verified' || ($c['suggested_qty'] ?? 0) !== 20 || ($c['est_cost'] ?? 0) !== (int) $c['price_member'] * 20), $j(array_map(static fn($c) => [$c['name'], $c['est_cost'] ?? null, $c['load_check'] ?? null], $d10)));
        ok('최종 표에 앞서 하중 부족·치수 다름으로 판정된 제품 없음', !array_intersect($ids($d10), array_column(array_filter($rk9, static fn($x) => in_array($x['status'], ['load_lighter', 'dim_mismatch'], true)), 'id')));
    }
} finally {
    $clean();
    proc_terminate($srv);
    array_map('unlink', glob("{$tmp}/*") ?: []);
    @rmdir($tmp);
}

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
