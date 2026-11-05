<?php
declare(strict_types=1);
/**
 * AI 상담 배수관 주의 안내(rag_drain_notice_keys / rag_drain_notice_prompt) 단위 테스트 — DB·OpenAI 불필요.
 *
 * 목적: 옹벽 배수관(건축법 시행규칙 제25조제4호)·공공 도로 횡단배수관(KDS 44 40 15 최소관경) 안내는
 *       화면이 아니라 AI 상담 답변에서만, 해당 상황일 때만 붙는지 검증(사용자 결정 2026-11-05).
 *       기존 프롬프트 기준값 시나리오(tests/fixtures/prompts/scenarios.php)에서는 켜지지 않아야 기준값이 그대로 유지된다.
 * 실행: php tests/rag_drain_notice.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
require __DIR__ . '/../app/rag.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$keys = static fn(string ...$turns): array => rag_drain_notice_keys(array_map(static fn($t) => ['role' => 'user', 'content' => $t], $turns));

echo "[옹벽 배수관 → wall_drain]\n";
foreach ([
    '보강토 옹벽 뒤에 PE 유공관 묻을 건데 어떤 거 써요?',
    '옹벽 쌓으면서 뒤쪽에 배수관 넣으려고요',
    '블록 옹벽 뒤에 물이 고여서 물빼기 하려고 해요',
    '석축 위쪽 마당에 이중벽관 묻어도 되나요',
] as $q) {
    ok("wall_drain: {$q}", in_array('wall_drain', $keys($q), true));
}
ok('여러 턴: 옹벽 상담 뒤 "유공관도 필요해요" → wall_drain', in_array('wall_drain', $keys('경사지에 옹벽 블록 쌓으려고요', '뒤에 유공관도 필요해요'), true));

echo "[도로 횡단배수관 → cross_drain]\n";
foreach ([
    '마을 앞 도로를 가로질러 배수관을 묻어야 해요',
    '차도 밑으로 파형강관 넣으려는데 관경 몇 mm 써야 하나요',
    '횡배수관으로 쓸 파형강관 추천해 주세요',
    '도로 횡단 배수 공사에 흄관 쓰려고요',
] as $q) {
    ok("cross_drain: {$q}", in_array('cross_drain', $keys($q), true));
}

echo "[해당 없음 → 안내 없음]\n";
foreach ([
    '마당에 배수로 놓고 그레이팅 덮으려고요',          // 배수로(수로) — 횡단·옹벽 아님
    '집 앞 진입로 밑 배수로 수로관 추천',               // 도로 없음
    '옹벽 블록 몇 개 필요해요? 길이 10m 높이 1m',       // 옹벽이지만 배수 문맥 없음
    '농경지에 유공관 묻어서 물 빼려고요',               // 지하배수지만 옹벽·도로 없음
    '도로 공사 차도에 블록 포장 두께',                  // 도로지만 배수관 없음
] as $q) {
    ok("없음: {$q}", $keys($q) === []);
}
ok('assistant 발화의 낱말은 보지 않음', rag_drain_notice_keys([
    ['role' => 'assistant', 'content' => '옹벽 뒤 배수관이나 도로를 가로지르는 배수관인가요?'],
    ['role' => 'user', 'content' => '아니요 그냥 마당 배수로예요'],
]) === []);

echo "[기존 프롬프트 기준값 시나리오 7개 → 안내 없음(기준값 유지)]\n";
$scenarios = require __DIR__ . '/fixtures/prompts/scenarios.php';
foreach ($scenarios as $k => $s) {
    ok("{$k}: 안내 없음", rag_drain_notice_keys($s['messages']) === [] && rag_drain_notice_prompt(rag_drain_notice_keys($s['messages'])) === '');
}

echo "[안내 구역 문구]\n";
ok('해당 없으면 빈 문자열', rag_drain_notice_prompt([]) === '');
$w = rag_drain_notice_prompt(['wall_drain']);
ok('옹벽: 사용자 확정 문구 그대로(제25조·2m·설계도면)', str_contains($w, '옹벽 배수에는 현장 설계에 따라 유공관 등의 배수 자재를 사용할 수 있습니다.')
    && str_contains($w, '옹벽 상단에서 대지 안쪽 2m 이내에 매설하는 배수관은 「건축법 시행규칙」 제25조의 재질 제한이 적용될 수 있습니다.')
    && str_contains($w, '설치 위치와 설계도면을 확인한 후 적합한 배수관을 선택해 주세요.') && !str_contains($w, 'KDS 44 40 15'));
$c = rag_drain_notice_prompt(['cross_drain']);
ok('횡단: KDS 44 40 15·1,000mm·800mm, 공공 도로 조건부', str_contains($c, '국가건설기준(KDS 44 40 15)상 관경 1,000mm 이상(부득이한 경우 800mm)')
    && str_contains($c, '공공 도로') && str_contains($c, '설계도면의 관경을 확인해 주세요') && !str_contains($c, '제25조'));
$both = rag_drain_notice_prompt(['wall_drain', 'cross_drain']);
ok('둘 다: 공통 규칙 1번 + 두 안내', substr_count($both, '=== 배수관 설치 위치·관경 주의 안내') === 1 && str_contains($both, '[옹벽 배수관]') && str_contains($both, '[도로 횡단배수관]'));
ok('추천 유지·단정 금지·[L번호] 안 붙임 규칙', str_contains($both, '상품 추천·검색을 멈추거나 빼지 않는다') && str_contains($both, '의무·불법으로 단정하지 않는다')
    && str_contains($both, '[L번호]는 붙이지 않고'));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail ? 1 : 0);
