<?php
declare(strict_types=1);
/**
 * 상용콘크리트 전환 후(2026-11-12 운영 고객 화면) AI 상담 검색 회귀 테스트 — 로컬 tomokmall_syc_test(scripts/testdb/prodlike-syc.php) 전용, 외부 접속 없음.
 *
 * 운영과 같은 상품 구성(상용콘크리트 704개, 전 상품 가격 0원 = 견적문의, 스틸 그레이팅·PE 배수관 없음)에서:
 *  ① 덮개(그레이팅) 검색: 수로관 본체('그레이팅 높이조절용 측구수로관')를 덮개처럼 추천하지 않고 '판매 중인 덮개 없음'
 *  ② 면적 단위 'm²'(위첨자) → ㎡ 수량 계산
 *  ③ 분류 이름에만 있는 품목: '투수블록' → 인조화강블록 투수/불투수, 'U형 수로관' → U형 플륨, '보도블록' → 보도블록 분류의 블록들
 *  ④ 판매하지 않는 품목(이중벽관·PE 배수관) 안내, ⑤ 부속(소켓)은 본품 대신 나오지 않음
 *  ⑥ 전 상품 가격 문의 → price_inquiry(금액 비교 금지) · 서버가 '가장 저렴' 문장을 뺌
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_syc_test DB_USER=… DB_PASS=… php tests/consult_syc_catalog.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ini_set('session.use_cookies', '0');
ini_set('session.cache_limiter', '');
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/ai_tools.php';
require_once __DIR__ . '/../app/rag.php';

$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_syc_test$/', $dbName) || !db()) {
    echo "SKIP: 상용콘크리트 전환 테스트 DB(*_syc_test)에서만 실행합니다. php scripts/testdb/prodlike-syc.php 로 만들고 DB_NAME=tomokmall_syc_test.\n";
    exit(0);
}
$visible = (int) db()->query("SELECT COUNT(*) FROM products p JOIN categories c ON c.id = p.category_id WHERE p.is_active = 1 AND c.is_active = 1 AND c.slug LIKE 'syc-%'")->fetchColumn();
if ($visible < 500) {
    echo "SKIP: 상용콘크리트 반영 상품이 없습니다({$visible}). php scripts/testdb/prodlike-syc.php\n";
    exit(0);
}
$pass = 0; $fail = 0;
function ok(string $name, bool $cond, string $info = ''): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}" . ($info !== '' ? "  — {$info}" : '') . "\n"; }
}
$s = static fn(array $in): array => json_decode(tool_search_products($in), true) ?: [];
$names = static fn(array $r): array => array_column($r['products'] ?? [], 'name');

echo "[① 덮개(그레이팅) — 단품은 아직 미등록·견적 요청, 수로관 본체를 덮개처럼 추천하지 않음]\n";
$r = $s(['query' => '그레이팅 수로용', 'width_mm' => 500, 'length_m' => 10, 'traffic' => 'pedestrian']);
ok('표 없음 + not_carried', ($r['products'] ?? []) === [] && !empty($r['not_carried']), json_encode($names($r), JSON_UNESCAPED_UNICODE));
ok("안내: 덮개 단품은 아직 미등록 + 견적 요청 + 그레이팅 옵션이 있는 수로관 안내(취급 안 함이라고 하지 않음, 2026-11-13)", str_contains((string) ($r['note'] ?? ''), "'그레이팅 수로용'(덮개) 단품은 사이트에 아직 등록되지 않았습니다. 견적요청하기로 문의해 주시면 확인해 드립니다.") && str_contains((string) ($r['note'] ?? ''), '그레이팅 규격이 옵션으로 표기된 제품') && !str_contains((string) ($r['note'] ?? ''), '판매 중인'), (string) ($r['note'] ?? ''));
$r = $s(['query' => '측구 수로관', 'width_mm' => 300, 'length_m' => 20]);
ok('본체를 찾으면(측구 수로관) 본체 표 — 폭 300 측구수로관, 20m → 10본', in_array('측구수로관', $names($r), true) && (int) (array_values(array_filter($r['products'], static fn($p) => $p['name'] === '측구수로관'))[0]['suggested_qty'] ?? 0) === 10);
$r = $s(['query' => '맨홀 뚜껑', 'traffic' => 'car']);
ok('맨홀 뚜껑은 뚜껑 상품만(조절링 등 부속 없음)', $names($r) !== [] && !array_filter($names($r), static fn($n) => !preg_match('/뚜껑/u', $n)), json_encode($names($r), JSON_UNESCAPED_UNICODE));
ok('차량 통행: 하중 근거 없는 뚜껑은 하중 확인 필요(맞다고 하지 않음)', !array_filter($r['products'] ?? [], static fn($p) => ($p['load_check'] ?? '') === 'verified'));

echo "[② 면적 단위 'm²' → ㎡ 수량]\n";
$r = $s(['query' => '보도블록', 'area_m2' => 30]);
$blk = $r['products'] ?? [];
ok("보도블록 30㎡: 단위 ㎡·수량 30(판매 단위 'm²' 를 면적으로)", $blk !== [] && !array_filter($blk, static fn($p) => ($p['unit'] ?? '') !== '㎡' || (int) ($p['suggested_qty'] ?? 0) !== 30), json_encode(array_map(static fn($p) => [$p['name'], $p['unit'], $p['suggested_qty']], $blk), JSON_UNESCAPED_UNICODE));
ok('ai_unit_label: m²·m2 → ㎡', ai_unit_label('규격: 300*300*60 | 단위: m²') === '㎡' && ai_unit_label('규격: 1 | 단위: m2') === '㎡');

echo "[③ 분류 이름에만 있는 품목]\n";
$r = $s(['query' => '투수블록', 'area_m2' => 30]);
ok("'투수블록' → 인조화강블록(분류 '인조화강블록 투수/불투수')만, 다른 블록 섞이지 않음", $names($r) === ['인조화강블록'], json_encode($names($r), JSON_UNESCAPED_UNICODE));
$r = $s(['query' => 'U형 수로관', 'width_mm' => 310, 'length_m' => 20]);
ok("'U형 수로관' 폭 310 → U형 플륨(볼라드 'U형' 안내 없음)", $names($r) === ['U형 플륨'] && !str_contains((string) ($r['note'] ?? ''), '볼라드'), json_encode([$names($r), $r['note'] ?? ''], JSON_UNESCAPED_UNICODE));
$r = $s(['query' => '보도블록', 'area_m2' => 30]);
ok("'보도블록' → 보도블록 분류의 블록 여러 종(사각보도블록만이 아님)", count($names($r)) >= 3 && in_array('사각보도블록', $names($r), true), json_encode($names($r), JSON_UNESCAPED_UNICODE));
$r = $s(['query' => '암거', 'width_mm' => 1000]);
ok("'암거' 폭 1000 → 암거 1련(내공폭 B 1000)", in_array('암거 1련', $names($r), true), json_encode($names($r), JSON_UNESCAPED_UNICODE));

echo "[④ 판매하지 않는 품목]\n";
$r = $s(['query' => '이중벽관', 'spec_mm' => 300, 'length_m' => 30]);
ok("'이중벽관' → 표 없음 + 사이트에 아직 등록된 제품 없음 + 견적 요청", ($r['products'] ?? []) === [] && str_contains((string) ($r['note'] ?? ''), "'이중벽관'은(는) 사이트에 아직 등록된 제품이 없습니다. 견적요청하기로 문의해 주시면 확인해 드립니다."), (string) ($r['note'] ?? ''));
$r = $s(['query' => 'PE 배수관', 'spec_mm' => 150, 'length_m' => 30]);
ok("'PE 배수관' → '배수관' 아직 미등록 + 견적 요청 + 표는 다른 제품이라고 안내(not_carried)", !empty($r['not_carried']) && str_contains((string) ($r['note'] ?? ''), "'배수관'은(는) 사이트에 아직 등록된 제품이 없습니다") && str_contains((string) ($r['note'] ?? ''), '이름이 비슷한 다른 제품'), (string) ($r['note'] ?? ''));
$r = $s(['query' => '흄관', 'spec_mm' => 300, 'length_m' => 50]);
ok('판매 중인 품목(흄관)에는 그 안내 없음', empty($r['not_carried']) && in_array('흄관(원심력관)', $names($r), true));

echo "[⑤ 부속은 본품 대신 나오지 않음]\n";
$r = $s(['query' => '트렌치', 'width_mm' => 150, 'length_m' => 10]);
ok('트렌치 폭 150 → 알루미늄트렌치 본품, 소켓 없음', $names($r) !== [] && !array_filter($names($r), static fn($n) => str_contains($n, '소켓')), json_encode($names($r), JSON_UNESCAPED_UNICODE));
$r = $s(['query' => '트렌치 소켓', 'width_mm' => 150]);
ok("'소켓'을 직접 찾으면 소켓 표시", (bool) array_filter($names($r), static fn($n) => str_contains($n, '소켓')), json_encode($names($r), JSON_UNESCAPED_UNICODE));

echo "[⑥ 전 상품 가격 문의(0원)]\n";
$r = $s(['query' => '경계석', 'length_m' => 20]);
ok('price_inquiry + price_note(금액 비교 금지) · 예상 자재비 없음 · 수량은 계산', !empty($r['price_inquiry']) && str_contains((string) ($r['price_note'] ?? ''), "'가장 저렴·경제적'으로 비교하지") && !array_filter($r['products'] ?? [], static fn($p) => ($p['est_cost'] ?? null) !== null) && (int) ($r['products'][0]['suggested_qty'] ?? 0) === 20);
ok("가격 문의 표: '가장 저렴·경제적' 문장만 뺌(설명·수량은 둠)", rag_strip_price_claims("화강 경계석 제품입니다.\n\n- 화강 도로 경계석: 도로용입니다. 권장 수량은 20개입니다. 이 제품이 가장 경제적인 선택입니다.\n\n가장 저렴한 제품은 화강 도로 경계석입니다. 원하시는 제품을 선택해 주세요.")
    === "화강 경계석 제품입니다.\n\n- 화강 도로 경계석: 도로용입니다. 권장 수량은 20개입니다.\n\n원하시는 제품을 선택해 주세요.");

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
