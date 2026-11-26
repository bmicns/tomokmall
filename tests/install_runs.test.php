<?php
declare(strict_types=1);
/**
 * 여러 줄 설치 길이·줄별 수량 테스트 (사용자 결정 2026-11-26 B안) — 실제 OpenAI 호출 없음.
 *
 * 확인:
 *  - 줄 구성 읽기(install_runs_parse): '10m씩 2줄'·'10m, 15m'·'양쪽 20m씩'·'2줄 각 10m'·'총 30m 3줄'(나누지 않음)·한 줄·치수 숫자 제외
 *  - 대화에서 지금 유효한 줄 구성(나중에 한 줄 길이로 고쳐 말하면 풀림)
 *  - 수량: 줄마다 올림(콘크리트·그레이팅·경계석 등) / 합계(PE·PVC 관류·미터 단위) / 줄마다 길이를 모르면 합계 + 확인 안내
 *  - 도구 입력 바로잡기(rag_apply_runs): GPT 가 한 줄 길이·합계만 넘겨도 고객 말대로 runs_m
 *  - 빠른 상담 설치 길이(quick_install_amount): '10m씩 2줄'을 10m 로 읽던 문제
 *  - 질문 방식 공통 구역이 두 상담 프롬프트에 모두 들어감·돌려 묻는 예문 없음·경계석 면적 질문 바로잡기·흄관 = 관류
 *  - (로컬 테스트 DB 가 있으면) 견적 목록 수량 맞춤 → 견적 표 금액 = 단가 × 수량(가격 계산은 그대로)
 * 실행: php tests/install_runs.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ob_start();
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/rag.php';
require_once __DIR__ . '/../app/cart.php';
require_once __DIR__ . '/../app/quote_draft.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, $got = null): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}" . ($got !== null ? '  — ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n"; }
}
$runs = static fn(string $m): ?array => ($r = install_runs_parse($m)) === null ? null : ($r['split_known'] ? $r['runs'] : ['total' => $r['total'], 'count' => $r['count']]);

echo "[줄 구성 읽기 install_runs_parse]\n";
foreach ([
    '10m씩 2줄 설치할거야'          => [10, 10],
    '10m 2줄'                       => [10, 10],
    '10미터씩 두 줄'                => [10, 10],
    '10m, 15m'                      => [10, 15],
    '10m, 15m, 8m 세 군데'          => [10, 15, 8],
    '10m랑 15m 두 줄이요'           => [10, 15],
    '10m 한 줄, 15m 한 줄'          => [10, 15],
    '10m 2줄, 5m 1줄'               => [10, 10, 5],
    '양쪽으로 20m씩'                => [20, 20],
    '양쪽 각 20m'                   => [20, 20],
    '20m씩 양쪽'                    => [20, 20],
    '2줄 각 10m'                    => [10, 10],
    '3줄 10m씩'                     => [10, 10, 10],
    '10.5m씩 3줄'                   => [10.5, 10.5, 10.5],
    '폭 300 그레이팅 10m씩 2줄'     => [10, 10],
] as $msg => $want) {
    ok("'{$msg}' → " . json_encode($want), $runs($msg) == $want, $runs($msg));
}
foreach (['총 30m 3줄' => 3, '3줄 총 30m' => 3, '30m를 3줄로' => 3, '두 줄로 20m 설치' => 2, '합쳐서 30m, 2줄' => 2] as $msg => $c) {
    $r = install_runs_parse($msg);
    ok("'{$msg}' → 줄마다 길이 모름(나누지 않음, 줄 수 {$c})", $r !== null && !$r['split_known'] && $r['runs'] === [] && $r['count'] === $c, $r);
}
foreach (['10m 30m', '20m 설치', '총 20m', '20미터쯤 필요해요', '폭 0.3m, 10m', '2m짜리 수로관 20m 설치', '300×1000 그레이팅 20m', '1m 그레이팅 10개', '폭 500 높이 0.5m'] as $msg) {
    ok("'{$msg}' → 줄 구성 아님(한 줄 계산·치수)", install_runs_parse($msg) === null, install_runs_parse($msg));
}

echo "[대화에서 지금 유효한 줄 구성]\n";
$u = static fn(string ...$t): array => array_map(static fn($x) => ['role' => 'user', 'content' => $x], $t);
$c = install_runs_from_messages($u('그레이팅 폭 300', '10m씩 2줄이요'));
ok('마지막 말의 줄 구성(last)', $c !== null && $c['runs'] === [10.0, 10.0] && $c['last'] === true, $c);
$c = install_runs_from_messages($u('10m씩 2줄이요', '차량 다녀요'));
ok('앞선 말의 줄 구성 유지(last 아님)', $c !== null && $c['runs'] === [10.0, 10.0] && $c['last'] === false, $c);
ok('나중에 한 줄 길이로 고쳐 말하면 풀림', install_runs_from_messages($u('10m씩 2줄', '아 그냥 총 20m 설치예요')) === null);
ok('줄 말이 없으면 null', install_runs_from_messages($u('그레이팅 20m 설치')) === null);

echo "[수량 — 줄마다 올림 / 합계]\n";
$tr  = ['name' => '측구수로관', 'category_name' => '측구수로관', 'spec' => '규격: 300*300*2000 | 단위: 본'];   // 1본 2m(칸 없음 — 최대 치수)
$gr  = ['name' => '그레이팅수로용', 'category_name' => '그레이팅', 'spec' => '규격: 300*1000*32 | 단위: 개'];
$pe  = ['name' => 'PE 이중벽관', 'category_name' => '이중벽관', 'spec' => '규격: D300*6000 | 단위: 본'];
$mU  = ['name' => '유공관', 'category_name' => '배수판', 'spec' => '규격: 100 | 단위: M'];
$set = static function (?array $c): void { install_runs_ctx(null, true); if ($c !== null) { install_runs_ctx($c); } };
$set(null);
ok('한 줄(기존과 같음): 21m ÷ 2m = 11본', ai_estimate_quantity($tr, 21.0, null, null) == [11, '21m ÷ 2m = 11본'], ai_estimate_quantity($tr, 21.0, null, null));
$set(['runs' => [7.0, 7.0, 7.0], 'count' => 3]);
[$q, $b] = ai_estimate_quantity($tr, 21.0, null, null);
ok('7m×3줄 수로관(2m): 줄마다 4본 × 3줄 = 12본(합계 계산 11본보다 1본 많음)', $q === 12 && str_contains($b, '7m×3줄') && str_contains($b, '12본'), [$q, $b]);
$set(['runs' => [10.0, 15.0], 'count' => 2]);
[$q, $b] = ai_estimate_quantity($tr, 25.0, null, null);
ok('10m·15m 수로관: 5본 + 8본 = 13본', $q === 13 && str_contains($b, '10m·15m') && str_contains($b, '5본 + 8본 = 13본'), [$q, $b]);
$set(['runs' => [10.5, 10.5], 'count' => 2]);
ok('10.5m×2줄 그레이팅(1m): 11 + 11 = 22개', ai_estimate_quantity($gr, 21.0, null, null)[0] === 22);
$set(['runs' => [10.0, 10.0], 'count' => 2]);
ok('딱 맞는 길이(10m×2줄 그레이팅) = 합계와 같음 20개', ai_estimate_quantity($gr, 20.0, null, null)[0] === 20);
$set(['runs' => [4.0, 4.0, 4.0], 'count' => 3]);
[$q, $b] = ai_estimate_quantity($pe, 12.0, null, null);
ok('PE 이중벽관(6m) 4m×3줄: 자투리를 이어 써 합계 12m ÷ 6m = 2본', $q === 2 && str_contains($b, '합계로 계산'), [$q, $b]);
$set(['runs' => [10.5, 10.5], 'count' => 2]);
[$q, $b] = ai_estimate_quantity($mU, 21.0, null, null);
ok('미터 단위: 합계 21m → 21M', $q === 21 && str_contains($b, '21M'), [$q, $b]);
$set(['runs' => [], 'count' => 3]);
[$q, $b] = ai_estimate_quantity($tr, 30.0, null, null);
ok('총 30m 3줄(줄마다 길이 모름): 나누지 않고 합계 15본 + 그 사실 밝힘', $q === 15 && str_contains($b, '줄마다 길이 모름'), [$q, $b]);
ok('줄마다 길이 모름 → 수로관은 확인 안내(run_note)', install_runs_split_note(30.0, $tr) !== null);
ok('줄마다 길이 모름 → PE 관은 안내 없음(합계가 정확)', install_runs_split_note(30.0, $pe) === null);
$set(['runs' => [10.0, 10.0], 'count' => 2]);
ok('다른 길이로 부른 계산에는 줄 구성을 쓰지 않음(합계 20m ≠ 50m)', ai_estimate_quantity($gr, 50.0, null, null) == [50, '50m ÷ 1m = 50개']);
ok('줄 기준 문구: 10m×2줄(총 20m)', install_runs_basis_label(20.0) === '10m×2줄(총 20m)', install_runs_basis_label(20.0));
$set(null);
ok("줄 기준 문구: 길이만 답함 → '한 줄 20m 기준'", install_runs_basis_label(20.0) === '한 줄 20m 기준');
ok('면적 단위 상품은 줄 구성과 관계없음', ai_estimate_quantity(['name' => '보도블록', 'spec' => '규격: 200*200*60 | 단위: ㎡'], 0.0, null, 20.0)[0] === 20);

echo "[도구 입력 정리 install_runs_input]\n";
[$in, $c] = install_runs_input(['length_m' => 10, 'runs_m' => [10, 10]]);
ok('runs_m 2줄 → length_m = 합계 20', $in['length_m'] == 20 && $c['runs'] === [10.0, 10.0], [$in, $c]);
[$in, $c] = install_runs_input(['length_m' => 30, 'run_count' => 3]);
ok('run_count 3 + length_m 30 → 줄마다 길이 모름', $c === ['runs' => [], 'count' => 3] && $in['length_m'] == 30);
[$in, $c] = install_runs_input(['length_m' => 20, 'runs_m' => [20]]);
ok('runs_m 한 줄이면 버림(한 줄 계산)', $c === null && !isset($in['runs_m']) && $in['length_m'] == 20);
[$in, $c] = install_runs_input(['runs_m' => ['a', -1, 99999]]);
ok('잘못된 값은 버림', $c === null && !isset($in['runs_m']));

echo "[GPT 검색 입력 바로잡기 rag_apply_runs]\n";
$R = install_runs_from_messages($u('그레이팅 폭 300 10m씩 2줄이요'));
[$in, $f] = rag_apply_runs(['query' => '그레이팅 수로용', 'length_m' => 10], $R, true);
ok('GPT 가 한 줄 길이 10만 넘김 → runs_m [10,10]·length_m 20', $in['length_m'] == 20 && $in['runs_m'] === [10.0, 10.0] && $f, [$in, $f]);
[$in] = rag_apply_runs(['query' => '그레이팅 수로용', 'length_m' => 20], $R, true);
ok('GPT 가 합계 20만 넘김 → runs_m 더함', $in['runs_m'] === [10.0, 10.0]);
[$in] = rag_apply_runs(['query' => '그레이팅 수로용'], $R, true);
ok('GPT 가 길이를 빠뜨림(이번 말) → 채움', ($in['length_m'] ?? 0) == 20);
$R2 = install_runs_from_messages($u('수로 10m씩 2줄', '맨홀 뚜껑도 필요해요'));
[$in, $f] = rag_apply_runs(['query' => '맨홀 뚜껑'], $R2, false);
ok('앞선 말·다른 품목·길이 없음 → 건드리지 않음(맨홀)', !isset($in['length_m']) && $f === []);
[$in] = rag_apply_runs(['query' => '측구 수로관', 'length_m' => 20], $R2, false);
ok('같은 현장 다른 자재(수로관 본체) 같은 길이 → 같은 줄 구성 재사용', $in['runs_m'] === [10.0, 10.0]);
[$in] = rag_apply_runs(['query' => '경계석', 'length_m' => 50], $R2, false);
ok('다른 길이(다른 위치 자재)는 그대로', $in['length_m'] == 50 && !isset($in['runs_m']));
$R3 = install_runs_from_messages($u('총 30m에 3줄이에요'));
[$in] = rag_apply_runs(['query' => '측구 수로관', 'length_m' => 10], $R3, true);
ok("'총 30m 3줄' — GPT 가 10m(나눈 값)로 넘겨도 합계 30·run_count 3(나누지 않음)", $in['length_m'] == 30 && $in['run_count'] === 3 && !isset($in['runs_m']), $in);
[$in] = rag_apply_runs(['product_id' => 5, 'length_m' => 10], $R, true, true);
ok('update_cart(수량 맞춤)도 같은 줄 구성', $in['runs_m'] === [10.0, 10.0] && $in['length_m'] == 20);

echo "[빠른 상담 설치 길이 quick_install_amount]\n";
ok("'10m씩 2줄 설치할거야' → 합계 20 + 줄마다(이전: 10m 로 읽어 수량 절반)", quick_install_amount('10m씩 2줄 설치할거야') == ['length_m' => 20, 'runs_m' => [10, 10]], quick_install_amount('10m씩 2줄 설치할거야'));
ok("'10m, 15m' → 합계 25(이전: 읽지 못함)", quick_install_amount('10m, 15m 두 줄이요') == ['length_m' => 25, 'runs_m' => [10, 15]]);
ok("'총 30m 3줄' → 합계 30 + 줄 수(나누지 않음)", quick_install_amount('총 30m 3줄') == ['length_m' => 30, 'run_count' => 3]);
ok("'20m 설치' → 기존 그대로", quick_install_amount('20m 설치할거야') == ['length_m' => 20]);
ok("빠른 상담 수량 글: '10m×2줄(총 20m)'", quick_install_what(['length_m' => 20, 'runs_m' => [10, 10]]) === '10m×2줄(총 20m)');

echo "[질문 방식 — 공통 대화 지침]\n";
$prompt = rag_build_prompt([['role' => 'user', 'content' => '그레이팅 필요해요']]);
$quick  = rag_build_prompt([['role' => 'user', 'content' => '그레이팅 필요해요']], [], 'quick');
$q1 = '설치 길이(m)를 알려 주세요. 여러 줄을 설치하는 경우 각 줄의 길이를 알려 주세요. (예: 10m씩 2줄 또는 10m, 15m)';
ok('기존 상담 프롬프트에 질문 방식 구역 + 설치 길이 질문 문장', str_contains($prompt['system'], '=== 질문 방식') && str_contains($prompt['system'], $q1));
ok('빠른 상담 프롬프트에도 같은 구역', str_contains($quick['system'], '=== 질문 방식') && str_contains($quick['system'], $q1));
ok("프롬프트에 '~알고 계신가요?' 형식의 질문 예문 없음(금지 설명만)", preg_match_all('/알고 계신가요/u', $prompt['system']) === 1, preg_match_all('/알고 계신가요/u', $prompt['system']));
ok('경계석은 면적이 아니라 설치 길이(필수 항목 분리)', str_contains($prompt['system'], '[경계석] 길이로 까는 자재다'));
ok('흄관 상담 = 관류(관경·설치 길이)', rag_detect_category($u('흄관 300mm 필요해요')) === 'pe_pipe');
ok('경계석만 상담: 면적 질문 → 설치 길이', rag_fix_area_question('필요한 면적(㎡)을 알려 주시면 추천해 드리겠습니다.', rag_is_curb_only($u('화단 경계석 필요해요')) ? 'curb' : 'pavement') === '설치 길이(m)를 알려 주시면 추천해 드리겠습니다.');
ok('블록과 함께면 면적 질문 그대로', rag_is_curb_only($u('보도블록이랑 경계석 필요해요')) === false);

echo "[기능 스위치 FEATURE_AI_INSTALL_RUNS — 끄면 반영 전 그대로]\n";
require_once __DIR__ . '/../app/install_runs_legacy.php';
$allNew = $prompt['system'] . $quick['system'] . rag_build_prompt($u('보도블록 깔고 싶어요'))['system'];
$stale = array_filter(array_keys(INSTALL_RUNS_LEGACY_PROMPT), static fn($k) => !str_contains($allNew, $k));
ok('되돌리기 표의 문장이 모두 지금 프롬프트에 있음(프롬프트를 고치고 표를 안 고친 곳 없음)', $stale === [], array_map(static fn($k) => mb_substr($k, 0, 40), array_values($stale)));
ai_install_runs_enabled(false);
$offP = rag_build_prompt($u('그레이팅 필요해요'), [], 'quick')['system'];
ok('끄면 질문 방식 구역·runs_m 안내 없음', !str_contains($offP, '=== 질문 방식') && !str_contains($offP, 'runs_m') && str_contains($offP, '설치 길이(수로 연장, m)'));
ok('끄면 도구 정의에 runs_m·run_count 없음', !str_contains((string) json_encode(ai_tool_definitions(), JSON_UNESCAPED_UNICODE), 'runs_m'));
ok("끄면 빠른 상담 '10m씩 2줄'은 반영 전처럼 10m", quick_install_amount('10m씩 2줄') == ['length_m' => 10]);
ok('끄면 흄관은 반영 전처럼 기타 자재', rag_detect_category($u('흄관 300mm 필요해요')) === '');
ai_install_runs_enabled(true);

echo "[견적 목록 수량 맞춤 → 견적 표 금액(로컬 테스트 DB)]\n";
$pdo = null;
try { $pdo = db(); } catch (Throwable $e) { $pdo = null; }
if (!$pdo) {
    echo "  · 로컬 테스트 DB 없음 — 건너뜀(하지 못한 확인)\n";
} else {
    // 1개 길이 1m 그레이팅(이름표 길이 L 1000)·단가가 있는 상품 하나
    $row = $pdo->query("SELECT p.id, p.price_b2c FROM products p WHERE p.is_active = 1 AND p.price_b2c > 0 AND p.dim_l = 1000 AND p.name LIKE '%그레이팅%' AND p.spec LIKE '%단위: 개%' ORDER BY p.id LIMIT 1")->fetch();
    $pipe = $pdo->query("SELECT p.id, p.price_b2c, p.dim_l FROM products p WHERE p.is_active = 1 AND p.price_b2c > 0 AND p.dim_l >= 2000 AND (p.name LIKE '%수로관%' OR p.name LIKE '%흄관%') ORDER BY p.id LIMIT 1")->fetch();
    if (!$row || !$pipe) {
        echo "  · 시험할 상품 없음 — 건너뜀\n";
    } else {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $sid = 'irtest' . getmypid();
        cart_session_write([(int) $row['id'] => 0, (int) $pipe['id'] => 0]);
        $r1 = json_decode(ai_tool_dispatch('update_cart', ['product_id' => (int) $row['id'], 'length_m' => 10, 'runs_m' => [10.5, 10.5]], $sid), true);
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $perPipe = (float) $pipe['dim_l'] / 1000;
        $r2 = json_decode(ai_tool_dispatch('update_cart', ['product_id' => (int) $pipe['id'], 'length_m' => 21, 'runs_m' => [7, 7, 7]], $sid), true);
        $wantPipe = 3 * (int) ceil(round(7 / $perPipe, 6));
        ok('그레이팅 10.5m×2줄 → 22개(줄마다 올림), 결과에 run_basis', ($r1['quantity'] ?? 0) === 22 && str_contains((string) ($r1['run_basis'] ?? ''), '10.5m×2줄'), $r1['qty_basis'] ?? $r1);
        ok("수로관·흄관 7m×3줄 → 줄마다 올림 {$wantPipe}", ($r2['quantity'] ?? 0) === $wantPipe, $r2['qty_basis'] ?? $r2);
        if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
        $quote = quote_draft_current(null);
        $rows = []; foreach ((array) ($quote['items'] ?? []) as $it) { $rows[(int) $it['product_id']] = $it; }
        $okSub = isset($rows[(int) $row['id']], $rows[(int) $pipe['id']])
            && $rows[(int) $row['id']]['subtotal'] === $rows[(int) $row['id']]['unit_price'] * 22
            && $rows[(int) $pipe['id']]['subtotal'] === $rows[(int) $pipe['id']]['unit_price'] * $wantPipe;
        ok('견적 표 품목 금액 = 표시 단가 × 바뀐 수량', $okSub, $quote['items'] ?? $quote);
        $goods = array_sum(array_map(static fn($it) => (int) $it['subtotal'], (array) ($quote['items'] ?? [])));
        ok('비회원 표시 단가 = 판매가(price_b2c) 그대로(가격 계산 변경 없음)', isset($rows[(int) $row['id']]) && (int) $rows[(int) $row['id']]['unit_price'] === (int) $row['price_b2c'], [$rows[(int) $row['id']]['unit_price'] ?? null, $row['price_b2c']]);
        ok('상품 합계 = 품목 금액의 합(' . number_format($goods) . '원)', (int) ($quote['goods_total'] ?? -1) === $goods, array_intersect_key($quote, ['goods_total' => 1]));
        ok('최종 예상 금액 = 상품 합계 + 부가세 10%(운임 없음 — 지역 미정)', (int) ($quote['vat'] ?? -1) === (int) round($goods * 0.1) && (int) ($quote['final'] ?? -1) === $goods + (int) $quote['vat'], array_intersect_key($quote, ['goods_total' => 1, 'vat' => 1, 'final' => 1]));
        cart_session_write([]);
        // 기존 AI 상담 검색 표: GPT 가 한 줄 길이 10 만 넘겨도 고객 말('10m씩 2줄')대로 표의 권장 수량·예상 자재비
        $ctx = ['explicit' => rag_explicit_conditions($u('그레이팅 폭 300 보행자용 10m씩 2줄')), 'traffic' => ['class' => '', 'strength' => ''], 'recent_cond' => [],
                'runs' => install_runs_from_messages($u('그레이팅 폭 300 보행자용 10m씩 2줄'))];
        [$in] = rag_prepare_search_input(['query' => '그레이팅 수로용', 'width_mm' => 300, 'length_m' => 10], $ctx);
        $d = json_decode(ai_tool_dispatch('search_products', $in, $sid), true);
        $cards = array_values(array_filter((array) ($d['products'] ?? []), static fn($c) => (int) ($c['suggested_qty'] ?? 0) > 0 && str_contains((string) ($c['qty_basis'] ?? ''), '÷ 1m')));
        ok('검색 표(기존 상담): 1m 제품 10m×2줄 → 권장 20개·run_basis·예상 자재비 = 단가 × 20', $cards && (int) $cards[0]['suggested_qty'] === 20 && ($cards[0]['run_basis'] ?? '') === '10m×2줄(총 20m)' && (int) $cards[0]['est_cost'] === (int) $cards[0]['price_member'] * 20, $cards[0] ?? $d);
        ok('도구가 끝나면 줄 구성이 비워짐(다음 계산에 남지 않음)', install_runs_ctx() === null);
    }
}

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
ob_end_flush();
exit($fail > 0 ? 1 : 0);
