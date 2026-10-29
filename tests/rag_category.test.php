<?php
declare(strict_types=1);
/**
 * 상담 카테고리 감지(rag_detect_category)·블록 종류 확인 구역·포장 블록 미등록 안내 단위 테스트 — DB·OpenAI 불필요.
 *
 * 목적: '블록'만 써도 바닥 포장 문맥이면 보도블록 상담 가이드를 쓰고, 호안·옹벽·속빈·조적 등 쌓는 블록은 보도블록으로 보지 않으며,
 *       종류를 알 수 없으면 가정하지 않고 확인 질문을 하는지. 기존 품목(그레이팅·관·맨홀·수로관) 감지는 그대로인지.
 * 실행: php tests/rag_category.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
require __DIR__ . '/../app/rag.php';
if (!function_exists('parse_spec_field')) {
    // ai_tools 의 두께 판독이 쓰는 bootstrap 함수(DB 없이 실행하기 위한 최소 대체 — 같은 동작)
    function parse_spec_field(string $spec): array {
        $r = ['spec' => ''];
        foreach (explode('|', $spec) as $part) {
            if (preg_match('/^\s*규격:\s*(.+)/u', trim($part), $m)) { $r['spec'] = trim($m[1]); }
        }
        return $r;
    }
}

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$cat = static fn(string ...$turns): string => rag_detect_category(array_map(static fn($t) => ['role' => 'user', 'content' => $t], $turns));
$kind = static fn(string ...$turns): string => rag_block_kind_prompt(array_map(static fn($t) => ['role' => 'user', 'content' => $t], $turns), $cat(...$turns));

echo "[블록 + 바닥 포장 문맥 → 보도블록(pavement)]\n";
foreach ([
    '차가 다니는 곳에 블록 깔려는데 두께 몇 T 써야 돼요?',
    '가게 앞 인도를 차가 가로질러 들어오는데 인도 블록 두께는요?',
    '집 앞 마당에 차 두 대 주차할 건데 블록 두께 몇 T?',
    '아파트 단지 주차장 블록 80T면 되나요?',
    '차가 올라가는 곳에 블록 깔려면 몇 mm 써야 돼요?',
    '사람만 다니는 정원 길에 블록 깔고 싶어요',
    '차도 블록 포장 공사 견적 받고 싶어요',
    '블록 포장 30㎡ 하려고요',
] as $q) {
    ok("pavement: {$q}", $cat($q) === 'pavement');
}

echo "[초보 고객 예시 질문]\n";
$ctx = static fn(string ...$turns): string => rag_road_context(array_map(static fn($t) => ['role' => 'user', 'content' => $t], $turns));
ok("'집 앞에 보도블록 깔면 뭐가 좋아?' → 보도블록, 장소 안내 구역 없음", $cat('집 앞에 보도블록 깔면 뭐가 좋아?') === 'pavement' && $ctx('집 앞에 보도블록 깔면 뭐가 좋아?') === 'none');
ok("'차가 가끔 다니는데 어떤 블록이 괜찮아?' → 보도블록 + 장소 확인(개인 부지/도로)", $cat('차가 가끔 다니는데 어떤 블록이 괜찮아?') === 'pavement' && $ctx('차가 가끔 다니는데 어떤 블록이 괜찮아?') === 'unclear');
ok("'마당 20㎡에 블록을 깔면 몇 개 필요해?' → 보도블록(종류 확인 질문 없음)", $cat('마당 20㎡에 블록을 깔면 몇 개 필요해?') === 'pavement' && $kind('마당 20㎡에 블록을 깔면 몇 개 필요해?') === '');
ok("'물이 잘 빠지는 블록은 어떤 종류야?' → 보도블록(종류 확인 질문 없음)", $cat('물이 잘 빠지는 블록은 어떤 종류야?') === 'pavement' && $kind('물이 잘 빠지는 블록은 어떤 종류야?') === '');
ok("'차가 가끔 지나가는 마당에는 어떤 자재가 적합해?' → 블록 단어 없어도 보도블록", $cat('차가 가끔 지나가는 마당에는 어떤 자재가 적합해?') === 'pavement');
ok("'마당 담장 쌓을 자재' 는 보도블록 아님", $cat('마당 담장 쌓을 자재 추천해 주세요') !== 'pavement');
ok("이어지는 '어떤 제품이 더 저렴하고 적합해?' → 앞 대화로 보도블록 유지", $cat('마당 20㎡에 블록을 깔면 몇 개 필요해?', '어떤 제품이 더 저렴하고 적합해?') === 'pavement');

echo "[쌓는 블록(호안·옹벽·속빈·조적 등) → 보도블록 아님]\n";
foreach ([
    '하천 호안블록 50m 쌓으려고요',
    '옹벽블록으로 화단 쌓을 거예요',
    '속빈콘크리트블록 200장 필요해요',
    '조적블록 담장 쌓기',
    '마당 담장을 블록으로 쌓으려고요',            // 마당(포장 단서)이 있어도 '쌓' 이면 쌓는 블록
] as $q) {
    ok("not pavement: {$q}", $cat($q) !== 'pavement');
}

echo "[종류 불명 '블록' → 가정하지 않고 확인 질문]\n";
ok("'블록 얼마예요?' → 카테고리 없음", $cat('블록 얼마예요?') === '');
ok("'블록 얼마예요?' → 블록 종류 확인 구역", str_contains($kind('블록 얼마예요?'), '=== 블록 종류 확인') && str_contains($kind('블록 얼마예요?'), '보도블록으로 가정하지 말고'));
ok('쌓는 블록은 확인 구역 없음(종류가 분명)', $kind('옹벽블록 쌓을 거예요') === '');
ok('포장 문맥이면 확인 구역 없음(보도블록 가이드 사용)', $kind('마당에 블록 깔려고요') === '');
ok('블록이 아닌 질문은 확인 구역 없음', $kind('PE 배수관 100m') === '');

echo "[기존 품목 감지 유지]\n";
ok('그레이팅', $cat('주차장 배수로 그레이팅 덮개') === 'grating');
ok('PE관', $cat('PE 배수관 300mm 50m') === 'pe_pipe');
ok('맨홀(블록 단어 있어도 맨홀 우선)', $cat('하수도용 맨홀 블록') === 'manhole');
ok('수로관', $cat('U형 수로관 50m 도로 옆') === 'culvert');
ok('보도블록·인터로킹 기존 단어', $cat('보도블록 20㎡') === 'pavement' && $cat('인터로킹 견적') === 'pavement');
ok('관계없는 질문 → 없음', $cat('배송 언제 와요?') === '');

echo "[포장 블록 두께 기준 미충족 안내 ai_paving_unavailable]\n";
$u = ai_paving_unavailable(['인터로킹 블록'], 100);
ok('규격 없음 + 현재 등록 상품 기준 명시', str_contains($u['note'], '규격 100mm 제품이 없습니다(현재 등록 상품 기준)'));
ok('얇은 두께 대체 추천 안 함 + 설계도서 확인·별도 견적', str_contains($u['note'], '대체품으로 권하지 않습니다') && str_contains($u['note'], '설계도서') && str_contains($u['note'], '별도 견적'));
ok('상담 화면 카드 이벤트(spec_unavailable)', ($u['_sse'][0]['type'] ?? '') === 'spec_unavailable' && $u['_sse'][0]['spec_mm'] === 100 && $u['_sse'][0]['item'] === '인터로킹 블록');
$u2 = ai_paving_unavailable(['인터로킹 블록', '점토블럭'], 100);
ok('여러 종류: 안내는 따옴표 목록, 카드는 쉼표 목록', str_contains($u2['note'], "'인터로킹 블록', '점토블럭'은(는)") && $u2['_sse'][0]['item'] === '인터로킹 블록, 점토블럭');
ok('담당자 연락 약속 문구 없음(연락 창구 미구현)', !str_contains($u['note'], '연락드') && !str_contains($u['note'], '담당자가'));

echo "[같은 두께 다른 포장 블록이 있을 때 ai_paving_no_match]\n";
$n = ai_paving_no_match(['보차도블록', '중하중 인터로킹'], 80, ['인터로킹U형블럭', '인조화강블록(투수)']);
ok('같은 두께 상품이 있으면 적합 상품 없음 카드 없음', empty($n['_sse']));
ok('같은 두께 등록 상품명을 alternatives 로 전달(재검색용)', $n['alternatives'] === ['인터로킹U형블럭', '인조화강블록(투수)'] && str_contains($n['note'], '같은 80mm 두께로 등록된 포장 블록: 인터로킹U형블럭'));
ok('개인 마당 등에 맞지 않는 발주처 설계도서·별도 견적 문구 없음', !str_contains($n['note'], '설계도서') && !str_contains($n['note'], '별도 견적'));
$n = ai_paving_no_match(['인터로킹 블록'], 100, []);
ok('같은 두께 포장 블록이 하나도 없으면(100mm) 기존 부재 안내 + 카드', ($n['_sse'][0]['type'] ?? '') === 'spec_unavailable' && str_contains($n['note'], '별도 견적') && !isset($n['alternatives']));

echo "[검색하겠다고만 하고 끝난 답변 감지 rag_is_search_promise]\n";
ok('실제 GPT-4o 답변(공공도로 2턴)', rag_is_search_promise('차도용 블록포장 설계 기준에 맞는 100mm 두께의 블록을 찾아 드릴게요. 잠시만 기다려 주세요.'));
ok('검색해 드리겠습니다', rag_is_search_promise('지금 바로 관련 제품을 검색해 드리겠습니다.'));
ok('질문으로 끝나는 답변은 아님', !rag_is_search_promise('설치 면적이 몇 ㎡인가요? 가로·세로를 알려 주세요.'));
ok('검색 후 안내 문장은 아님', !rag_is_search_promise('원하시는 제품을 선택해 주세요.'));
ok('앞부분에만 약속이 있고 뒤에 다른 안내가 길게 이어지면 아님', !rag_is_search_promise('찾아 드릴게요. ' . str_repeat('차량이 올라가는 곳은 두께를 꼭 확인해 주세요. ', 4)));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
