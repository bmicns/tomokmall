<?php
declare(strict_types=1);
/**
 * AI 상담 견적 카드(B안, 사용자 결정 2026-11-23 A8) 통합 테스트 — 로컬 테스트 DB(운영 복제) 전용, DB 쓰기 없음(세션만).
 *
 * 확인:
 *  - add_to_cart(region)·get_cart(region)·update_cart(수량·빼기)가 견적 카드(quote_draft)를 돌려주고 금액은 서버 계산
 *  - 카드의 예상 운임 = 견적 요청 화면 운임 API(/api/freight-cart.php) 결과, 부가세·최종 = estimate_amount_summary
 *  - 배송 지역: 정식 이름 해석·세션 기억, 시·군 이름은 오류 문장(추측 안 함), 지역 없으면 운임 0원이 아니라 '알려 주시면'
 *  - 중량 없는 품목이 있으면 운임 별도, 비회원도 카드(판매가 단가)
 *  - 견적 요청 화면이 상담에서 들은 배송 지역을 미리 고름
 *
 * 실행: php tests/quote_draft.test.php  (.env.local 의 로컬 _test DB 사용)
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ob_start();   // 출력을 모아 두어야 CLI 에서도 세션을 다시 열고 파일에 저장할 수 있음(견적 요청 화면 대조)

require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true)) {
    echo "SKIP: 로컬 테스트 DB(DB_NAME=*_test)에서만 실행합니다. 현재 DB_NAME='{$dbName}'\n";
    exit(0);
}
if (!db() || !function_exists('curl_init')) {
    echo "SKIP: 테스트 DB 접속 실패 또는 curl 확장 없음\n";
    exit(0);
}

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, $detail = null): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}" . ($detail !== null ? '  — ' . json_encode($detail, JSON_UNESCAPED_UNICODE) : '') . "\n"; }
}
function sse_quote(array $d): ?array
{
    foreach ($d['_sse'] ?? [] as $ev) { if (($ev['type'] ?? '') === 'quote_draft') { return $ev; } }
    return null;
}

// 판매 중·가격 있음·중량 있음 상품 2개, 중량 없는 상품 1개 (운영 복제 데이터 — 읽기만)
$pdo  = db();
$rows = $pdo->query("SELECT id, spec FROM products WHERE is_active = 1 AND price_b2c > 0 ORDER BY id LIMIT 3000")->fetchAll();
$withKg = []; $noKg = null;
foreach ($rows as $r) {
    $kg = freight_unit_kg(parse_spec_field((string) $r['spec'])['weight']);
    if ($kg > 0 && count($withKg) < 2) { $withKg[] = (int) $r['id']; }
    if ($kg <= 0 && $noKg === null) { $noKg = (int) $r['id']; }
}
if (count($withKg) < 2 || $noKg === null) {
    echo "SKIP: 중량 있는 판매 상품 2개·중량 없는 상품 1개가 필요합니다\n";
    exit(0);
}
[$a, $b] = $withKg;

// ── 내장 서버(운임 API·견적 요청 화면 대조) ───────────────────
$port = 18000 + random_int(0, 999);
$env  = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'PATH'] as $k) { $env[$k] = (string) getenv($k); }
$proc = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', realpath(__DIR__ . '/../public')],
    [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
    $pipes, null, $env
);
register_shutdown_function(static function () use ($proc) { proc_terminate($proc); });
$base = "http://127.0.0.1:{$port}";
for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port)) { break; } usleep(100000); }
function http_get(string $url, ?string $cookie = null): string
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20]);
    if ($cookie !== null) { curl_setopt($ch, CURLOPT_COOKIE, $cookie); }
    $raw = (string) curl_exec($ch);
    curl_close($ch);
    return $raw;
}

$_SESSION = [];
$_SESSION['user'] = null;   // 비회원

echo "[담기 — 지역 없이]\n";
$d = json_decode(tool_add_to_cart(['product_id' => $a, 'quantity' => 3], 'test'), true);
$q = sse_quote($d);
ok('담기 성공 + 견적 카드 이벤트', !empty($d['ok']) && $q !== null && count($q['items']) === 1);
ok('지역 없음: 운임 0원 아님(알려 주시면)', ($q['freight']['status'] ?? '') === 'none' && str_contains($q['freight']['text'], '알려 주시면'));
ok('지역 없음: AI 안내에 시·도 묻기', str_contains((string) ($d['quote_note'] ?? ''), '시·도'));
$goods = (int) $q['goods_total'];
ok('상품 합계 = 단가 × 3 (비회원 판매가)', $goods === (int) $q['items'][0]['unit_price'] * 3 && $goods > 0);
ok('부가세·최종 = estimate_amount_summary(운임 제외)', $q['vat'] === estimate_amount_summary($goods, null)['vat'] && $q['final'] === estimate_amount_summary($goods, null)['final'] && $q['final_suffix'] === ' + 운임 별도');

echo "[담기 — 정식 지역 이름]\n";
$d = json_decode(tool_add_to_cart(['product_id' => $b, 'quantity' => 2, 'region' => '충청남도'], 'test'), true);
$q = sse_quote($d);
ok('충청남도 → 충남, 세션에 기억', ($q['region'] ?? '') === '충남' && quote_region_get() === '충남');
ok('카드 품목 2개', count($q['items']) === 2);
$api = json_decode(http_get("{$base}/api/freight-cart.php?dest=" . rawurlencode('충남') . "&items={$a}:3,{$b}:2"), true);
if (($api['status'] ?? '') === 'ok') {
    ok('예상 운임 = 견적 요청 화면 운임 API', ($q['freight']['status'] ?? '') === 'ok' && $q['freight']['amount'] === (int) $api['freight'], [$q['freight'], $api['freight'] ?? null]);
    $s = estimate_amount_summary((int) $q['goods_total'], (int) $api['freight']);
    ok('부가세·최종 = (상품 + 운임) 기준', $q['vat'] === $s['vat'] && $q['final'] === $s['final'] && $q['final_suffix'] === '');
} else {
    ok('운임 API 와 같은 상태(' . ($api['status'] ?? '오류') . ')', ($q['freight']['status'] ?? '') === ($api['status'] ?? 'error'), [$q['freight'], $api]);
}
ok('AI 결과 quote = 카드 금액', ($d['quote']['final'] ?? null) === $q['final'] && ($d['quote']['goods_total'] ?? null) === $q['goods_total']);

echo "[조회 — 모르는 지역]\n";
$d = json_decode(tool_get_cart('test', ['region' => '수원']), true);
ok('시·군 이름은 오류 문장(추측 안 함), 이전 지역 유지', isset($d['region_error']) && (sse_quote($d)['region'] ?? '') === '충남');

echo "[수량 바꾸기·빼기]\n";
$d = json_decode(tool_update_cart(['product_id' => $b, 'quantity' => 5], 'test'), true);
$q = sse_quote($d);
$qtyB = null; foreach ($q['items'] as $it) { if ($it['product_id'] === $b) { $qtyB = $it['quantity']; } }
ok('수량 5로', !empty($d['ok']) && $qtyB === 5 && cart_session_read()[$b] === 5);
$d = json_decode(tool_update_cart(['product_id' => $b, 'quantity' => 0], 'test'), true);
ok('빼기(0) → 1개 남음', !empty($d['removed']) && count(sse_quote($d)['items']) === 1 && !isset(cart_session_read()[$b]));
$d = json_decode(tool_update_cart(['product_id' => $b, 'quantity' => 1], 'test'), true);
ok('담기지 않은 상품은 바꾸지 않음', isset($d['error']) && !isset(cart_session_read()[$b]));
$d = json_decode(tool_update_cart(['product_id' => $a, 'quantity' => 1000], 'test'), true);
ok('수량 범위 밖 거부', isset($d['error']) && cart_session_read()[$a] === 3);

echo "[중량 없는 품목]\n";
$d = json_decode(tool_add_to_cart(['product_id' => $noKg, 'quantity' => 1], 'test'), true);
$q = sse_quote($d);
ok('운임 별도 확인(0원 아님), 최종에 운임 없음', ($q['freight']['status'] ?? '') === 'missing_weight' && $q['final_suffix'] !== '' && str_contains($q['final_suffix'], '운임 별도'));

echo "[견적 요청 화면 — 상담 배송 지역 미리 선택]\n";
cart_session_write([$a => 3]);   // 세션 저장(쓰기 후 잠금 해제)
$sid  = session_id();
$name = session_name();
$html = http_get("{$base}/estimate.php", "{$name}={$sid}");
ok('충남 선택됨', (bool) preg_match('/<option value="충남" selected>/u', $html));
ok('다른 지역은 선택 안 됨', !preg_match('/<option value="서울" selected>/u', $html));
ok('미리 고른 지역이면 바로 운임 계산', str_contains($html, "getElementById('ship-region')?.value) refreshFreight(0)"));

echo "[스위치 끔(app/features.php FEATURE_AI_QUOTE_CARD=false) = 반영 전 동작]\n";
ai_quote_card_enabled(false);
$defs = [];
foreach (ai_tool_definitions() as $t) { $defs[$t['function']['name']] = $t['function']; }
ok('도구: update_cart 없음, 담기·조회 설명·입력 반영 전과 같음',
    !isset($defs['update_cart']) && $defs['add_to_cart']['description'] === '고객이 선택한 상품을 견적서에 담습니다.'
    && array_keys($defs['add_to_cart']['parameters']['properties']) === ['product_id', 'quantity']
    && $defs['get_cart']['description'] === '현재 견적서에 담긴 상품 목록과 합계를 조회합니다.'
    && json_encode($defs['get_cart']['parameters']) === '{"type":"object","properties":{}}');
$d = json_decode(tool_add_to_cart(['product_id' => $b, 'quantity' => 1, 'region' => '서울'], 'test'), true);
ok('담기 결과: 견적 카드·금액 없음(장바구니 갱신만), 지역 저장 안 함',
    !empty($d['ok']) && !isset($d['quote']) && count($d['_sse']) === 1 && $d['_sse'][0]['type'] === 'cart_update' && quote_region_get() === '충남');
$d = json_decode(tool_get_cart('test', ['region' => '서울']), true);
ok('조회 결과: items·count·total 만', array_keys($d) === ['items', 'count', 'total']);
$d = json_decode(ai_tool_dispatch('update_cart', ['product_id' => $b, 'quantity' => 0], 'test'), true);
ok('update_cart 는 알 수 없는 도구', isset($d['error']) && isset(cart_session_read()[$b]));
ai_quote_card_enabled(true);

session_start();
$_SESSION = [];
session_write_close();
echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
ob_end_flush();
exit($fail > 0 ? 1 : 0);
