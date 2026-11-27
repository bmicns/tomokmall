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

echo "[경계석 장소 먼저 rag_curb_place_question (11-27)]\n";
$q = rag_curb_place_question($u('경계석 15m'), false);
ok("'경계석 15m' → 검색 전에 장소·차량 질문(길이 15m는 한 줄 안내)", $q !== null && str_contains($q, '설치 장소와 차량이 닿는 곳인지') && str_contains($q, '15m는 한 줄로'), $q);
ok("'차도 경계석 15m'·차량을 분명히 말함 → 묻지 않음", rag_curb_place_question($u('차도 경계석 15m'), false) === null && rag_curb_place_question($u('경계석 15m'), true) === null);
ok("'경계석 종류가 뭐가 있어?'(설치 말 없음)·그레이팅과 함께·보도블록과 함께 → 묻지 않음", rag_curb_place_question($u('경계석 종류가 뭐가 있어?'), false) === null
    && rag_curb_place_question($u('경계석 15m랑 그레이팅'), false) === null && rag_curb_place_question($u('보도블록과 경계석 필요해요'), false) === null);
ok("'경계석 필요해요' → 장소·설치 길이 함께 묻기", ($q = rag_curb_place_question($u('경계석 필요해요'), false)) !== null && str_contains($q, '설치 장소') && str_contains($q, QUICK_FQ_LENGTH), $q);

echo "[근거 없는 효과 문장 빼기 rag_strip_unsupported_claims (11-27)]\n";
$t = "차도에 설치할 경계석을 위해 다음과 같은 제품들이 적합합니다.\n\n- 화강 경사 경계석: 폭 250mm, 길이 1m입니다. 권장 수량은 15개입니다. 이 제품은 차량이 지나가는 도로에 적합합니다.\n- 배수경계석: 권장 수량 15개입니다. 이 제품도 차도에 적합한 경계석입니다.\n\n차도용 경계석이므로 이 두 옵션 모두 차량 진입 및 하중을 견딜 수 있는 제품들입니다.";
$r = rag_strip_unsupported_claims($t, false);
ok('운영 시험 답: 차량·도로 적합·하중 견딤 문장 빠짐, 제품 줄의 사실(폭·수량)은 그대로', !str_contains($r, '견딜') && !str_contains($r, '도로에 적합') && !str_contains($r, '차도에 적합한 경계석')
    && str_contains($r, '- 화강 경사 경계석: 폭 250mm, 길이 1m입니다. 권장 수량은 15개입니다.') && str_contains($r, '- 배수경계석: 권장 수량 15개입니다.'), $r);
$r = rag_strip_unsupported_claims('위 제품들은 모두 내경 300mm입니다. KS F 4403(원심력 철근 콘크리트관) 표준 대상 종류이므로 신뢰성이 높습니다. 흄관(A형)이 가장 경제적입니다.', false);
ok("'…종류이므로 신뢰성이 높습니다' → '…종류입니다.'(사실만 남김)", str_contains($r, 'KS F 4403(원심력 철근 콘크리트관) 표준 대상 종류입니다.') && !str_contains($r, '신뢰성'), $r);
ok('하중이 상품 자료로 확인된 표면 차량 적합 문장은 그대로', str_contains(rag_strip_unsupported_claims('중하중 제품이라 승용차 통행에 적합합니다.', true), '적합합니다'));
ok('두께 기준(80T) 문장은 그대로', str_contains(rag_strip_unsupported_claims('차량이 올라가면 두께 80mm 이상(80T) 제품이 적합합니다.', false), '80T'));
$t = "차도에 설치하시는 경계석으로 아래 제품을 추천드립니다.\n\n인조화강경계석은 두께가 120mm로 차량이 올라가는 곳 저희 권장 기준을 충족합니다. 아래 표에서 골라 주세요.";
$r = rag_strip_unsupported_claims($t, false, true);
ok("경계석 상담: '두께 120mm … 권장 기준을 충족합니다'(포장 블록 기준) 문장은 숫자가 있어도 뺌(운영 시험 11-27), 나머지 문장은 그대로",
    !str_contains($r, '권장 기준') && str_contains($r, '아래 표에서 골라 주세요.') && str_contains($r, '추천드립니다.'), $r);
ok('경계석 상담: 80T 이상 기준 문장도 뺌, 규격 사실 문장은 그대로', !str_contains(rag_strip_unsupported_claims('경계석은 80T 이상 기준을 만족합니다. 규격은 120×120×1000입니다.', false, true), '80T')
    && str_contains(rag_strip_unsupported_claims('경계석은 80T 이상 기준을 만족합니다. 규격은 120×120×1000입니다.', false, true), '120×120×1000'));
ok('경계석이 아닌 상담(보도블록)은 두께 기준 문장 그대로', str_contains(rag_strip_unsupported_claims('차량이 올라가면 두께 80mm 이상(80T) 제품이 권장 기준입니다.', false, false), '80T'));
ok('경계석만 이야기한 대화 판단: 경계석+차도 = 경계석만, 보도블록 함께 = 아님', rag_is_curb_only($u('경계석 15m', '차도')) && !rag_is_curb_only($u('경계석이랑 보도블록')));
echo "[표에 없는 치수 빼기 rag_strip_unlisted_dims (11-27)]\n";
$cards = [
    ['name' => '화강 경사 경계석', 'spec' => '규격: 250*300/100 | 단위: 개', 'qty_basis' => '15m ÷ 1m = 15개', 'size' => '폭 W 250mm · 상단 높이 H 300mm · 하단 높이 H 100mm · 길이 L 1m'],
    ['name' => '콘크리트경계석', 'spec' => '규격: 120*120*120(SA) | 단위: 개', 'size' => '상부폭 WT 120mm · 하부폭 WB 120mm · 높이 H 120mm'],
    ['name' => '인조화강경계석', 'spec' => '규격: 120*120*120 | 단위: 개', 'size' => '상부폭 WT 120mm · 하부폭 WB 120mm · 높이 H 120mm'],
];
$t = "차도 경계에 사용할 수 있는 경계석 제품입니다.\n\n- 화강 경사 경계석: 길이 1m, 상단 높이 300mm, 하단 높이 100mm입니다. 권장 수량은 15개입니다.\n- 콘크리트경계석: 길이 1m, 높이 120mm입니다. 가격은 개당 2,541원입니다.\n- 인조화강경계석: 높이 120mm, 길이 1m로, 화강석 느낌을 낸 제품입니다.\n- 콘크리트 경계석: 길이 1m입니다. 가격은 개당 2,541원입니다.\n\n설치 길이 15m 기준입니다. 폭 320mm 대신 폭 300mm 제품입니다.";
$r = rag_strip_unlisted_dims($t, $cards, $u('경계석 15m 폭 320', '차도'));
ok("운영 상담 #380: 120×120×120 경계석에 덧붙인 '길이 1m'만 빠짐(앞·뒤 쉼표, 혼자인 문장)", substr_count($r, '길이 1m') === 1 && str_contains($r, '- 콘크리트경계석: 높이 120mm입니다.')
    && str_contains($r, '- 인조화강경계석: 높이 120mm로, 화강석') && str_contains($r, '- 콘크리트 경계석: 가격은 개당 2,541원입니다.'), $r);
ok('표에 있는 치수(경사 경계석 길이 1m·상단 높이 300mm)·설치 길이·고객이 말한 폭·이름 없는 줄은 그대로', str_contains($r, '- 화강 경사 경계석: 길이 1m, 상단 높이 300mm, 하단 높이 100mm입니다.')
    && str_contains($r, '설치 길이 15m 기준입니다. 폭 320mm 대신 폭 300mm 제품입니다.'), $r);
ok('표가 없으면 그대로', rag_strip_unlisted_dims('- 콘크리트경계석: 길이 1m입니다.', [], []) === '- 콘크리트경계석: 길이 1m입니다.');
ok("규격 숫자가 mm 로 있으면 m 표기도 같은 치수로 봄(1000 = 1m), '0.5m' 처럼 표에 없는 길이는 뺌",
    rag_strip_unlisted_dims('- 중하중그레이팅수로용: 폭 300mm, 길이 1m, 두께 50mm입니다.', [['name' => '중하중그레이팅수로용', 'spec' => '규격: 300*1000*50(7*4)']], []) === '- 중하중그레이팅수로용: 폭 300mm, 길이 1m, 두께 50mm입니다.'
    && rag_strip_unlisted_dims('- 중하중그레이팅수로용: 폭 300mm, 길이 0.5m입니다.', [['name' => '중하중그레이팅수로용', 'spec' => '규격: 300*1000*50(7*4)']], []) === '- 중하중그레이팅수로용: 폭 300mm입니다.');

echo "[줄별 수량 기준 rag_runs_basis_line (11-27)]\n";
$runs = ['runs' => [10.0, 15.0], 'count' => 2];
$cards = [['name' => '흄관(A형)', 'qty_basis' => '10m·15m: 10m 4본 + 15m 6본 = 10본(줄마다 ÷ 2.5m 올림)(줄 끝은 잘라 쓰고 자투리는 다른 줄에 쓰지 않는 기준)'],
          ['name' => '유공흄관', 'qty_basis' => '10m·15m: 10m 4본 + 15m 6본 = 10본(줄마다 ÷ 2.5m 올림)(줄 끝은 잘라 쓰고 자투리는 다른 줄에 쓰지 않는 기준)']];
$r = rag_runs_basis_line("농경지에 설치할 흄관을 추천드립니다.\n\n- 흄관(A형): 권장 수량은 10본입니다.", $cards, $runs);
ok("답에 줄별 기준이 없으면 제품 줄 앞에 '수량 기준(줄별 10m·15m): 10m 4본 + 15m 6본 = 10본'", str_contains($r, "수량 기준(줄별 10m·15m): 10m 4본 + 15m 6본 = 10본(줄마다 ÷ 2.5m 올림)") && strpos($r, '수량 기준') < strpos($r, '- 흄관(A형)'), $r);
ok('이미 줄별 수량을 말했으면 그대로', rag_runs_basis_line('10m 4본 + 15m 6본 = 10본입니다.', $cards, $runs) === '10m 4본 + 15m 6본 = 10본입니다.');
ok('한 줄(줄 구성 없음)이면 그대로', rag_runs_basis_line('권장 수량 10본.', $cards, null) === '권장 수량 10본.');
$r = rag_runs_basis_line('추천드립니다.', [['name' => 'A', 'qty_basis' => '10m·15m: 10m 4본 + 15m 6본 = 10본(줄마다 ÷ 2.5m 올림)'], ['name' => 'B', 'qty_basis' => '10m·15m: 10m 10개 + 15m 15개 = 25개(줄마다 ÷ 1m 올림)']], $runs);
ok('제품 1개 길이가 다르면 제품별로', str_contains($r, 'A 10m 4본') && str_contains($r, 'B 10m 10개'), $r);

echo "[검색 없이 '등록 안 됨' 단정 rag_is_unregistered_claim (11-27)]\n";
ok("운영 시험 답('사이트에 등록되지 않은 상태') → 검색을 강제할 대상", rag_is_unregistered_claim("현재 '그레이팅 수로용' 제품은 사이트에 등록되지 않은 상태입니다.") && !rag_is_unregistered_claim('폭을 알려 주세요.'));

echo "[같은 용도 ai_grating_use·ai_same_use_query·ai_keep_same_use (11-27)]\n";
ok('그레이팅 용도: 수로·배수로 = channel, 맨홀·집수정 = manhole, 모름·둘 다·그레이팅 아님 = 빈 값', ai_grating_use('스틸 그레이팅 배수로') === 'channel' && ai_grating_use('벌집그레이팅 집수정') === 'manhole'
    && ai_grating_use('그레이팅') === '' && ai_grating_use('그레이팅 수로 맨홀') === '' && ai_grating_use('수로관') === '');
ok("같은 용도 검색어: '스틸 그레이팅 수로용' → '그레이팅 수로용', 이미 그 검색어면 null", (ai_same_use_query('스틸 그레이팅 수로용')['query'] ?? '') === '그레이팅 수로용' && ai_same_use_query('그레이팅 수로용') === null && ai_same_use_query('그레이팅') === null);
$res = ai_keep_same_use(['products' => [['id' => 1, 'name' => '중하중그레이팅맨홀(집수정)용'], ['id' => 2, 'name' => '중하중그레이팅수로용'], ['id' => 3, 'name' => '사각맨홀(집수정)']],
    '_sse' => [['type' => 'product_cards', 'products' => [['id' => 1], ['id' => 2], ['id' => 3]]]]], '그레이팅 맨홀');
ok('맨홀 용도: 맨홀·집수정 그레이팅만(수로용·맨홀 본체 빠짐), 화면 카드도 같이', array_column($res['products'], 'id') === [1] && array_column($res['_sse'][0]['products'], 'id') === [1]);

if (db() && preg_match('/_test$/', (string) (getenv('DB_NAME') ?: ''))) {
    echo "[로컬 테스트 DB]\n";
    $d = json_decode(ai_tool_dispatch('search_products', ['query' => '경계석', 'length_m' => 15], 'qs-test'), true);
    ok('경계석 15m(두께 기준 없이) 검색 → 표가 있고 권장 수량', !empty($d['products']) && (int) ($d['products'][0]['suggested_qty'] ?? 0) > 0, $d['note'] ?? null);
    $d = json_decode(ai_tool_dispatch('search_products', ['query' => '그레이팅 수로용', 'width_mm' => 320, 'length_m' => 20, 'traffic' => 'car'], 'qs-test'), true);
    ok('그레이팅 폭 320 승용차 → 유사 제품(가장 가까운 폭) 표·수량 20', !empty($d['similar']) && (int) ($d['products'][0]['suggested_qty'] ?? 0) === 20 && str_contains((string) $d['note'], '가장 가까운 폭 300mm'), $d['note'] ?? null);
    $d = json_decode(ai_tool_dispatch('search_products', ['query' => '벌집그레이팅맨홀', 'width_mm' => 400, 'traffic' => 'car'], 'qs-test'), true);
    ok("이름이 없는 '벌집그레이팅맨홀' → 같은 용도(맨홀·집수정) 그레이팅만 유사 제품 표", !empty($d['similar']) && $d['products'] && !array_filter($d['products'], static fn($c) => !preg_match('/그레이팅/u', $c['name']) || !preg_match('/맨홀|집수정/u', $c['name'] . $c['category_name'])), $d['note'] ?? null);
    $d = json_decode(ai_tool_dispatch('search_products', ['query' => '벌집 그레이팅 맨홀용', 'traffic' => 'car'], 'qs-test'), true);
    ok('맨홀용 그레이팅 검색에 수로용이 섞이지 않음', $d['products'] && !array_filter($d['products'], static fn($c) => !preg_match('/맨홀|집수정/u', $c['name'] . $c['category_name'])), array_column($d['products'] ?? [], 'name'));
} else {
    echo "  (로컬 테스트 DB 없음 — DB 확인은 건너뜀)\n";
}

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
ob_end_flush();
exit($fail > 0 ? 1 : 0);
