<?php
declare(strict_types=1);
/**
 * 차량 블록 시공 장소 판정(rag_road_context)과 판정별 안내 구역 단위 테스트 — DB·OpenAI 불필요.
 *
 * 목적: 사내 80T 안내 문구는 그대로 두고, 공공도로 차도(KDS·KCS 44 50 25 최소 100mm)·공공 보도 차량 출입부(보도 지침 구분 Ⅱ)
 *       질문에만 공식 기준을 따로 안내하며, 장소가 불명확하면 단정하지 않고 확인 질문을 하도록 하는지 검증.
 * 실행: php tests/rag_road_context.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
require __DIR__ . '/../app/rag.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$ctx = static fn(string ...$turns): string => rag_road_context(array_map(static fn($t) => ['role' => 'user', 'content' => $t], $turns));

echo "[공공도로 차도 → public_road]\n";
foreach ([
    '시청에서 발주한 도로 공사인데 차도에 블록 깔려면 두께 몇 mm 써야 해요?',
    '구청 관급 공사로 차도 블록포장 하는데 인터로킹 몇 T가 맞나요?',
    '지방도 차도 포장을 블록으로 바꾸려고 합니다. 차량 통행 많아요',
    '교통량 AADT 1500대 정도 되는 차로에 블록 포장 두께',
    '차도용 블록 몇 mm 이상이어야 하나요? 국도 구간입니다',
] as $q) {
    ok("public_road: {$q}", $ctx($q) === 'public_road');
}
ok('여러 턴: 두께 질문 뒤 "시청 발주처 공사예요" 답변 → public_road',
    $ctx('차가 다니는 곳에 블록 깔려는데 두께 몇 T 써야 돼요?', '구청이 발주처인 도로 포장 공사예요') === 'public_road');

echo "[공공 보도 차량 출입부 → public_sidewalk]\n";
foreach ([
    '가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요?',
    '건물 앞 보도에 차량이 진입하는 구간 포장 블록 추천해 주세요',
] as $q) {
    ok("public_sidewalk: {$q}", $ctx($q) === 'public_sidewalk');
}

echo "[사유지 → private (기존 80T 흐름)]\n";
foreach ([
    '집 앞 마당에 차 두 대 주차할 건데 블록 두께 몇 T?',
    '공장 안 지게차 다니는 곳 블록 포장하려고요',
    '전원주택 진입로에 승용차 다니게 인터로킹 깔 거예요',
    '아파트 단지 주차장 블록 80T면 되나요?',
    '보도블록으로 우리 집 차고 앞 깔면 차가 올라가도 돼요?',   // '보도블록'은 제품명 — 보도(장소) 아님
] as $q) {
    ok("private: {$q}", $ctx($q) === 'private');
}

echo "[불명확 → unclear (장소 확인 질문)]\n";
foreach ([
    '차가 올라가는 곳에 블록 깔려면 몇 mm 써야 돼요?',
    '차량 통행하는 곳 인터로킹 두께 알려주세요',
    '시방서에 차량용 블록이라고만 돼 있는데 몇 T 사면 돼요?',     // 공사 서류만 — 공공·민간 불명
    '구청 도로 옆 우리 집 마당 진입로 블록 두께',                    // 공공·사유 단서가 섞임
] as $q) {
    ok("unclear: {$q}", $ctx($q) === 'unclear');
}

echo "[해당 없음 → none]\n";
foreach ([
    '사람만 다니는 정원 길에 블록 깔고 싶어요',
    '차 도로 옆 배수로 U형 수로관 50m',          // 블록·포장 아님
    '인터로킹 블록 20㎡ 가격 알려주세요',
    '블록 발주하려고요. 내일 배송 되나요? 인도 조건은요?',  // '발주'·'인도' 일상어
] as $q) {
    ok("none: {$q}", $ctx($q) === 'none');
}

echo "[근거 범위]\n";
ok('public_road → 공공도로 근거만 허용', rag_road_scopes('public_road') === ['public_road']);
ok('public_sidewalk → 공공 보도 근거만 허용', rag_road_scopes('public_sidewalk') === ['public_sidewalk']);
ok('private·unclear·none → 범위 근거 없음', rag_road_scopes('private') === [] && rag_road_scopes('unclear') === [] && rag_road_scopes('none') === []);
ok('@scope 태그 읽기', rag_legal_doc_scopes(['tags' => '@scope:public_road,두께,블록']) === ['public_road'] && rag_legal_doc_scopes(['tags' => '볼라드']) === []);
ok('@scope 태그는 점수에 안 씀', rag_legal_score(['tags' => '@scope:public_road'], '@scope:public_road 차도') === 0 && rag_legal_score(['tags' => '@scope:public_road,두께'], '블록 두께') === 3);

echo "[안내 구역]\n";
$p = rag_road_context_prompt('public_road');
ok('공공도로: 100mm·KDS/KCS 44 50 25·설계도서 우선·80T 비권장·spec_mm 100',
    str_contains($p, '최소 100mm') && str_contains($p, 'KDS 44 50 25') && str_contains($p, '설계도서') && str_contains($p, '80T) 제품을 공공도로 차도용으로 권하지 않는다') && str_contains($p, 'spec_mm 100'));
$p = rag_road_context_prompt('public_sidewalk');
ok('공공 보도: 4t 이하 범위·차도는 별도 기준(근거 없는 100mm 수치 없음)', str_contains($p, '4t 이하') && str_contains($p, '차도 기준이 따로') && !str_contains($p, '100mm'));
$p = rag_road_context_prompt('unclear');
ok('불명확: 근거 없는 100mm 수치 없음', !str_contains(rag_road_context_prompt('unclear'), '100mm'));
ok('불명확: 단정 금지 + 장소 확인 질문', str_contains($p, "'충분하다'고 단정하기 전에") && str_contains($p, '개인 부지(마당·주차장·진입로)인가요, 도로(인도·차도) 공사인가요?'));
ok('사유지·해당 없음: 추가 구역 없음(기존 흐름 그대로)', rag_road_context_prompt('private') === '' && rag_road_context_prompt('none') === '');
$p = rag_road_context_prompt('public_road', false);
ok('공공 범위 근거가 없을 때(비활성·운영 미적용): 수치·KDS 인용 없이 80T 단정 금지 + 설계도서 두께 확인', str_contains($p, "'충분하다'고 안내하지 않는다") && str_contains($p, '설계도서') && !str_contains($p, '100mm') && !str_contains($p, 'KDS'));
ok('공공 보도도 근거 없으면 같은 안전 구역', rag_road_context_prompt('public_sidewalk', false) === $p);
ok('불명확·사유지는 근거 유무와 무관', rag_road_context_prompt('unclear', false) === rag_road_context_prompt('unclear') && rag_road_context_prompt('private', false) === '');

echo "[사내 80T 안내 문구 유지]\n";
$src = (string) file_get_contents(__DIR__ . '/../app/rag.php');
ok("rag.php 80T 문구 원문 그대로", str_contains($src, "★차량이 올라가면 두께 80mm 이상(80T) 제품, 사람만 다니면 60mm(60T)도 가능 → [참조 문서] 기준."));
ok("80T 제품 필터 문구 그대로", str_contains($src, "차량이 올라가는 곳이면 spec_mm 80, spec_strict true를 함께 넘겨 80T 제품만 표에 나오게 한다"));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
