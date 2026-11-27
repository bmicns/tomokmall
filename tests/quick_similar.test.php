<?php
declare(strict_types=1);
/**
 * 빠른 상담 첫 질문 고정·경계석 두께 기준 제외·유사 제품 첫 문장 테스트 (사용자 요청 2026-11-26) — 실제 OpenAI 호출 없음.
 *
 * 확인:
 *  - quick_first_question: 기존 상담과 같은 필수 항목 질문(폭·설치 길이 줄 구성·차량 / 관경·장소 / 경계석 장소·길이 / 블록 장소·면적), 이미 말한 항목은 빼고,
 *    설치 길이 하나만 말하면 다시 묻지 않고 '한 줄로 계산' 안내, 다 말했으면 null
 *  - 경계석: 프롬프트 [경계석]이 포장 블록 두께(80T) 규칙 아래에 있지 않음 · 검색 입력의 80T·spec_strict 를 서버가 뺌
 *  - rag_similar_lead: 유사 제품 표 답의 첫 문장 고정, GPT 의 '없습니다' 문장 중복 제거, 제품 줄은 그대로
 *  - (로컬 테스트 DB 가 있으면) 경계석 차도 검색이 비지 않음
 * 실행: php tests/quick_similar.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ob_start();
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/rag.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond, $got = null): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}" . ($got !== null ? '  — ' . json_encode($got, JSON_UNESCAPED_UNICODE) : '') . "\n"; }
}
$u  = static fn(string ...$ms): array => array_map(static fn($m) => ['role' => 'user', 'content' => $m], $ms);
$fq = static fn(string $cat, string $m, bool $v = false): ?string => quick_first_question($cat, $u($m), $v);

echo "[빠른 상담 첫 질문 quick_first_question]\n";
$q = $fq('grating', '그레이팅 필요해요');
ok('그레이팅(아무 정보 없음): 폭·설치 길이(줄 구성 예시)·차량 3가지를 기존 상담 문장으로', $q === "알맞은 그레이팅을 고르려면 다음 정보를 알려 주세요.\n\n- 덮을 배수로의 폭(mm)을 알려 주세요. (예: 300mm)\n- " . QUICK_FQ_LENGTH . "\n- 통행하는 차량의 최대 톤수를 알려 주세요. (예: 승용차, 트럭, 버스)", $q);
$q = $fq('grating', '주차장 배수로 그레이팅 20m');
ok("'주차장 … 20m': 폭·차량은 묻고, 길이는 다시 묻지 않고 '20m는 한 줄로 계산' 안내(같은 자리)", $q !== null && str_contains($q, '- 설치 길이 20m는 한 줄로 계산합니다.') && !str_contains($q, QUICK_FQ_LENGTH)
    && strpos($q, '폭(mm)') < strpos($q, '20m는') && strpos($q, '20m는') < strpos($q, '차량의 최대 톤수'), $q);
ok("'주차장'은 장소로 짐작한 차량 조건이라 차량을 묻는다(분명히 말한 것만 앎 — rag.php 가 strong 만 넘김)", rag_traffic_info($u('주차장 배수로 그레이팅 20m'))['strength'] !== 'strong');
ok('그레이팅 폭·줄 구성·차량을 다 말함 → null(GPT 답 그대로)', $fq('grating', '그레이팅 폭 300 10m씩 2줄 승용차', true) === null);
ok('여러 줄을 말하면 길이 안내 줄도 없음', !str_contains((string) $fq('grating', '그레이팅 10m, 15m'), '설치 길이'), $fq('grating', '그레이팅 10m, 15m'));
ok('집수정 덮개는 길이 자재가 아님 → null', $fq('grating', '집수정 그레이팅') === null);
$q = $fq('curb', '경계석 15m');
ok("경계석 15m: 장소·차량 질문 + '15m는 한 줄로 계산'(면적은 묻지 않음)", $q !== null && str_starts_with($q, '알맞은 경계석을') && str_contains($q, '설치 장소와 차량이 닿는 곳인지') && str_contains($q, '15m는 한 줄로') && !str_contains($q, '면적'), $q);
ok('경계석 차도 15m → null(장소·길이 다 말함)', $fq('curb', '차도 경계석 15m') === null);
$q = $fq('pe_pipe', '흄관 300 10m, 15m');
ok("흄관 300 10m, 15m: 설치 장소만 묻고(관경·줄 구성 다 말함) 품명은 고객 말 '흄관'", $q === "알맞은 흄관을 고르려면 다음 정보를 알려 주세요.\n\n- 설치 장소를 알려 주세요. (예: 농경지, 도로 측구, 건물 주변, 도로 횡단)", $q);
ok('농경지 흄관 300 → 설치 길이(줄 구성) 질문만', $fq('pe_pipe', '농경지 흄관 300') === "알맞은 흄관을 고르려면 다음 정보를 알려 주세요.\n\n- " . QUICK_FQ_LENGTH, $fq('pe_pipe', '농경지 흄관 300'));
$q = $fq('pavement', '보도블록 깔고 싶어요');
ok("보도블록: 장소·차량 + 면적(품명 안의 '보도'는 장소가 아님)", $q !== null && str_contains($q, '설치 장소와 차량이 올라가는지') && str_contains($q, '설치 면적(㎡)'), $q);
ok('보도블록 마당 승용차 20㎡ → null', $fq('pavement', '마당 보도블록 20㎡ 승용차', true) === null);
ok('그 밖의 자재(맨홀 등) → null', $fq('manhole', '맨홀 뚜껑') === null && $fq('', '안녕하세요') === null);
ok('질문 답인지(rag_is_question_reply): 알려 주세요·? 는 질문, 담았다는 답은 아님', rag_is_question_reply('폭을 알려 주세요.') && rag_is_question_reply('차량이 다니나요?') && !rag_is_question_reply('목록에 담았습니다.'));

echo "[경계석 — 포장 블록 두께 기준(80T)을 쓰지 않음]\n";
$p = rag_build_prompt($u('경계석 15m', '차도'), [], 'consult')['system'];
$curbAt = mb_strpos($p, '[경계석] 길이로 까는 자재다');
$t80At  = mb_strpos($p, '★차량이 올라가면 두께 80mm 이상(80T) 제품');
ok('프롬프트: 80T 규칙은 [보도블록] 쪽, [경계석]은 그 뒤에 따로(80T 규칙이 경계석 항목 안에 있지 않음)', $curbAt !== false && $t80At !== false && $t80At < $curbAt);
ok('프롬프트: 경계석 검색에 spec_mm·spec_strict 를 넣지 않는다는 규칙', str_contains($p, '경계석 검색에 spec_mm·spec_strict 를 넣지 않는다'));
$ctx = ['explicit' => [], 'traffic' => ['class' => '', 'strength' => ''], 'recent_cond' => []];
[$in, $fills] = rag_prepare_search_input(['query' => '경계석', 'spec_mm' => 80, 'spec_strict' => true, 'length_m' => 15], $ctx);
ok('검색 입력: 경계석 + 80T·spec_strict → 서버가 뺌', !isset($in['spec_mm']) && !isset($in['spec_strict']) && $in['length_m'] === 15, [$in, $fills]);
[$in] = rag_prepare_search_input(['query' => '인터로킹 블록', 'spec_mm' => 80, 'spec_strict' => true, 'area_m2' => 20], $ctx);
ok('포장 블록의 80T 는 그대로(안전 규칙)', ($in['spec_mm'] ?? null) === 80 && !empty($in['spec_strict']));
[$in] = rag_prepare_search_input(['query' => '경계석', 'spec_mm' => 150, 'spec_strict' => true], $ctx);
ok('경계석 규격 치수(150 등 두께 기준이 아닌 값)는 그대로', ($in['spec_mm'] ?? null) === 150);
ai_install_runs_enabled(false);
[$in] = rag_prepare_search_input(['query' => '경계석', 'spec_mm' => 80, 'spec_strict' => true], $ctx);
ok('설치 길이 스위치 끔: 반영 전처럼 그대로', ($in['spec_mm'] ?? null) === 80);
ai_install_runs_enabled(true);

echo "[유사 제품 첫 문장 rag_similar_lead]\n";
$sim = [['lead' => AI_SIMILAR_LEAD, 'detail' => '요청하신 폭 320mm 대신 가장 가까운 폭 300mm 제품입니다.']];
$t = rag_similar_lead("폭 320mm 그레이팅 제품이 현재 등록되어 있지 않습니다.\n\n- 중하중그레이팅수로용: 300×1000, 20개\n\n원하시는 제품을 골라 주세요.", $sim);
ok("첫 문장 '해당 규격의 제품이 없습니다. 대신 아래에 유사한 제품을 추천해드립니다.' + 다른 점 한 줄, GPT 의 '없습니다' 문장은 빠짐, 제품 줄 그대로", str_starts_with($t, AI_SIMILAR_LEAD . "\n요청하신 폭 320mm 대신")
    && !str_contains($t, '등록되어 있지 않습니다') && str_contains($t, '- 중하중그레이팅수로용: 300×1000, 20개') && str_contains($t, '원하시는 제품을 골라 주세요.'), $t);
ok('유사 제품 표가 아니면 그대로', rag_similar_lead('그대로입니다.', []) === '그대로입니다.');
ok('프롬프트: 유사 제품 표 규칙(스위치 켬)', str_contains($p, '=== 맞는 규격이 없을 때 — 유사 제품 표 ==='));
ai_similar_products_enabled(false);
ok('유사 제품 스위치 끔: 프롬프트에 규칙 없음', !str_contains(rag_build_prompt($u('그레이팅'), [], 'consult')['system'], '유사 제품 표 ==='));
ai_similar_products_enabled(true);

if (db() && preg_match('/_test$/', (string) (getenv('DB_NAME') ?: ''))) {
    echo "[로컬 테스트 DB]\n";
    $d = json_decode(ai_tool_dispatch('search_products', ['query' => '경계석', 'length_m' => 15], 'qs-test'), true);
    ok('경계석 15m(두께 기준 없이) 검색 → 표가 있고 권장 수량', !empty($d['products']) && (int) ($d['products'][0]['suggested_qty'] ?? 0) > 0, $d['note'] ?? null);
    $d = json_decode(ai_tool_dispatch('search_products', ['query' => '그레이팅 수로용', 'width_mm' => 320, 'length_m' => 20, 'traffic' => 'car'], 'qs-test'), true);
    ok('그레이팅 폭 320 승용차 → 유사 제품(가장 가까운 폭) 표·수량 20', !empty($d['similar']) && (int) ($d['products'][0]['suggested_qty'] ?? 0) === 20 && str_contains((string) $d['note'], '가장 가까운 폭 300mm'), $d['note'] ?? null);
} else {
    echo "  (로컬 테스트 DB 없음 — DB 확인은 건너뜀)\n";
}

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
ob_end_flush();
exit($fail > 0 ? 1 : 0);
