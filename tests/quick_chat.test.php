<?php
declare(strict_types=1);
/**
 * 우측 하단 'AI 빠른 견적' 창(사용자 결정 2026-11-23 — 지금 AI 상담과 비교용) 테스트 — 로컬 테스트 DB 전용, 실제 OpenAI 호출 없음(127.0.0.1 목업).
 *
 * 확인:
 *  - 보이는 사람: 스위치 'staff' = 관리자·직원만, 'all' = 모두, 'off' = 아무도, 견적 카드 스위치를 끄면 안 보임
 *  - 프롬프트: 기본 상담은 그대로, 빠른 창은 기본 + '빠른 견적 창 답변 방식' 구역만 덧붙음
 *  - API(/api/chat/quick.php): 비로그인 403, 직원 → 질문 → 검색·담기 도구 실행 → 견적 표(quote_draft) 돌려줌, 상담 키 'qc…', 대화 저장, 200자 초과 거부
 *  - 화면: 직원에게만 버튼·창 출력, AI 상담 화면(chat)에는 없음
 *  - 케이토목처럼 바로 담기(A11): 찾고도 '골라 주세요'로 끝나면 담기 1회 강제, 확인 질문('?')이면 강제 안 함, 견적 표에 사진·상품목록·도면자료 연결
 *  - 창 안 목록(A11 11-24 결정): 담기·수량은 대화별 목록에만, 수량 미정 0, '견적서 만들어줘'·[견적 요청하기] 때 사이트 견적서로(두 배 안 됨)
 *  - 견적서 사진 첨부(A10): 사진 확인(jpg·png·10MB)·개인정보 모양 지우기·품목 글 만들기, 동의 없으면 거부(OpenAI 호출 없음),
 *    사진 → 목업 비전 → 품목 글로 검색·담기 → 견적 표, 사진·개인정보는 대화·상담 로그에 남지 않음
 * 테스트가 만든 행: 로컬 테스트 DB 의 ai_sessions·chat_logs(상담 키 'qc…') — 끝에 지운다.
 * 실행: php tests/quick_chat.test.php (.env.local 의 로컬 _test DB)
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
ob_start();   // CLI 에서 세션을 다시 열어 파일에 저장하려면 출력을 모아 둬야 함
$tmp  = sys_get_temp_dir() . '/quick_chat_test_' . getmypid();
@mkdir($tmp, 0777, true);
$mockPort = 19300 + getmypid() % 300;
require __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/rag.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/quick_photo.php';

$pure = ['pass' => 0, 'fail' => 0];
$pok = static function (string $name, bool $cond) use (&$pure): void {
    $cond ? $pure['pass']++ : $pure['fail']++;
    echo ($cond ? '  ✓ ' : '  ✗ ') . $name . "\n";
};
echo "[견적서 사진 — 순수 함수]\n";
$png1 = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==';
$dec = quick_photo_decode('data:image/png;base64,' . $png1);
$pok('data URL png → 확인됨', $dec['ok'] && $dec['mime'] === 'image/png' && strlen($dec['bytes']) > 0);
$pok('base64 만 와도 됨', quick_photo_decode($png1)['ok']);
$pok('이미지가 아니면 거부', !quick_photo_decode(base64_encode('<?php echo 1; ?>'))['ok']);
$pok('gif 는 거부(jpg·png 만)', !quick_photo_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7')['ok']);
$pok('빈 값·깨진 base64 거부', !quick_photo_decode('')['ok'] && !quick_photo_decode('@@@@')['ok']);
$pok('10MB 넘으면 거부', !quick_photo_decode(str_repeat('A', (int) ceil(QUICK_PHOTO_MAX_BYTES * 4 / 3) + 100))['ok']);
$pok('전화·이메일·사업자번호 지움', quick_photo_scrub('경계석 010-1234-5678 a@b.co 123-45-67890 031)123-4567') === '경계석');
$pok('규격 숫자는 그대로', quick_photo_scrub('300×1000×100 KS') === '300×1000×100 KS');
$it = quick_photo_items_text(json_encode(['items' => [
    ['name' => '도로경계석', 'spec' => '150×170×1000', 'qty' => 30, 'unit' => '개'],
    ['name' => 'U형 측구 문의 02-123-4567', 'spec' => '300', 'qty' => null, 'unit' => '', 'unclear' => true],
    ['name' => '', 'spec' => 'x'],
    ['name' => '그레이팅', 'qty' => 2.5, 'unit' => 'm'],
]], JSON_UNESCAPED_UNICODE));
$pok('품목 글: 머리말 + 번호 줄 3개', $it['ok'] && $it['count'] === 3 && str_starts_with($it['text'], QUICK_PHOTO_PREFIX . "\n1) 도로경계석 150×170×1000 · 수량 30개\n"));
$pok('수량 모름·읽기 애매함·전화번호 지움', str_contains($it['text'], '2) U형 측구 문의 300 · 수량 모름 (읽기 애매함)') && !str_contains($it['text'], '4567'));
$pok('소수 수량', str_contains($it['text'], '3) 그레이팅 · 수량 2.5m'));
$pok('품목 없음·JSON 아님 → 안내', !quick_photo_items_text('{"items":[],"note":"자재 목록이 아닙니다"}')['ok'] && !quick_photo_items_text('nope')['ok']);
$many = quick_photo_items_text(json_encode(['items' => array_fill(0, 15, ['name' => '블록', 'qty' => 1])]));
$pok('최대 ' . QUICK_PHOTO_MAX_ITEMS . '개', $many['count'] === QUICK_PHOTO_MAX_ITEMS);
$pok('프롬프트: 개인정보·가격 읽지 않게', str_contains(QUICK_PHOTO_PROMPT, '전화번호') && str_contains(QUICK_PHOTO_PROMPT, '단가'));
$mg = cart_merge_quick_list([7 => 2, 9 => 1], [7 => 5, 8 => 0, 10 => 3]);
$pok('목록 → 견적서: 같은 상품은 목록 수량으로·수량 0 은 빼고 따로', $mg['cart'] === [7 => 5, 9 => 1, 10 => 3] && $mg['moved'] === [7, 10] && $mg['skipped'] === [8]);
// 품명+규격으로 같은 상품 찾기(app/quick_match.php — 운영 시험 11-24: 사진 6개 중 1개만 담기던 문제, 첫 질문에 바로 담기)
$pok('규격 숫자 읽기', quick_match_nums('D150×6000') === [150.0, 6000.0] && quick_match_nums('300*275*120T') === [300.0, 275.0, 120.0]);
$pok('품명: 같음 3 · 띄어쓰기·괄호만 다름 3 · 품음 2 · 사진 오타 1 · 다름 0',
    quick_match_name_score('사각보도블록', '사각보도블록') === 3 && quick_match_name_score('안전중하중그레이팅 맨홀(집수정)용', '안전중하중그레이팅맨홀(집수정)용') === 3
    && quick_match_name_score('HDPE이중벽관', 'HDPE이중벽관(유공관)') === 2 && quick_match_name_score('그레이팅 높이조절용 축구수로관', '그레이팅 높이조절용 측구수로관') === 1
    && quick_match_name_score('사각보도블록', '점자유도 블록') === 0);
$rows = [
    ['id' => 1, 'name' => '점자유도 블록', 'spec' => '규격: 300*300*60 | 단위: m²'],
    ['id' => 2, 'name' => '사각보도블록', 'spec' => '규격: 300*300*60 | 단위: m²'],
    ['id' => 3, 'name' => 'EX메탈망그레이팅', 'spec' => '규격: 300*1000*50(5*3)_국산 | 단위: 개'],
    ['id' => 4, 'name' => 'EX메탈망그레이팅', 'spec' => '규격: 300*400*50(5*3)_국산 | 단위: 개'],
];
$pok('규격이 앞에서부터 같고 품명이 가장 닮은 상품', (quick_match_pick('사각보도블록', '300×300×60', $rows)['id'] ?? 0) === 2
    && (quick_match_pick('EX메탈망그레이팅', '300×1000×50', $rows)['id'] ?? 0) === 3);
$pok('규격이 다르거나 없으면 고르지 않음(크기 짐작 안 함)', quick_match_pick('EX메탈망그레이팅', '300×1000×75', $rows) === null && quick_match_pick('EX메탈망그레이팅', '', $rows) === null);
$pok('고객 말에서 품명+규격', quick_match_named('그레이팅수로용 300*1000*32 얼마에요?') === ['name' => '그레이팅수로용', 'spec' => '300*1000*32']
    && quick_match_named('안녕하세요. 경계석 150x170x1000 30개요') === ['name' => '경계석', 'spec' => '150x170x1000']
    && quick_match_named('마당 경계석 30m 필요해요') === null);
$pok('규격 숫자 차례 REGEXP', quick_match_spec_regexp([300.0, 1000.0, 32.0]) === '(^|[^0-9.])300[^0-9.]+1000[^0-9.]+32([^0-9.]|$)' && quick_match_spec_regexp([2.5]) === '(^|[^0-9.])2[.]5([^0-9.]|$)');
$g32 = ['id' => 11, 'name' => '그레이팅수로용', 'spec' => '300*1000*32'];
$g50 = ['id' => 12, 'name' => '중하중그레이팅수로용', 'spec' => '300*1000*50(7*4)'];
$pok('바꿔 담기: 같은 크기·닮은 품명의 수량 0 줄만 뺌(다른 크기·다른 품명·자기 자신은 그대로)', quick_match_swap_ids($g50, [$g32, $g50,
        ['id' => 13, 'name' => '그레이팅수로용', 'spec' => '200*1000*32'], ['id' => 14, 'name' => '사각보도블록', 'spec' => '300*1000*60']]) === [11]
    && quick_match_swap_ids(['id' => 15, 'name' => '경계석', 'spec' => ''], [$g32]) === []);
$pok("'같이·추가·둘 다'면 바꿔 담기 안 함", quick_match_keep_both('50mm도 같이 넣어줘') && quick_match_keep_both('둘 다 견적 주세요') && !quick_match_keep_both('아파트 주차장이고 차 다녀요. 20m요'));
$pok("화면 안내 말 빼기('견적서는 화면에 이미 표시되고 있으며' 등 운영 답 8개 표현)",
    quick_reply_tidy("총 20개를 담았습니다.\n\n견적서는 화면에 이미 표시되고 있으며, 운임은 별도로 확인됩니다.") === "총 20개를 담았습니다.\n\n운임은 별도로 확인됩니다."
    && quick_reply_tidy('20개를 담았습니다. 견적서는 화면에 보이니 확인해 주세요.') === '20개를 담았습니다.'
    && quick_reply_tidy('20개를 담았습니다. 견적 카드에 상품과 운임 금액이 표시되어 있습니다.') === '20개를 담았습니다.'
    && quick_reply_tidy('현재 상품 금액은 화면에 보이며, 정확한 운임 계산을 위해 배송 지역(시·도)을 알려 주세요.') === '정확한 운임 계산을 위해 배송 지역(시·도)을 알려 주세요.'
    && quick_reply_tidy('경계석을 견적서에 담았습니다. 견적서에서 확인해 주세요.') === '경계석을 목록에 담았습니다.');
$pok('금액은 글에서 빼고 견적 표에만(케이토목처럼): 마디·문장, 앞 마디는 맺음', quick_reply_tidy('20m 설치 기준으로 20개가 필요하고, 상품 금액은 약 66,000원입니다. 현재 예상 운임은 60,000원이며, 부가세 포함 최종 금액은 약 138,600원입니다. 정확한 견적을 위해 더 필요한 사항이 있으면 말씀해 주세요.')
    === '20m 설치 기준으로 20개가 필요합니다. 정확한 견적을 위해 더 필요한 사항이 있으면 말씀해 주세요.'
    && quick_reply_tidy('상품 합계는 약 13만 원이며, 배송 지역을 알려 주시면 운반비를 계산해 드립니다.') === '배송 지역을 알려 주시면 운반비를 계산해 드립니다.'
    && quick_reply_tidy('6만 6천원입니다.') === '' && quick_reply_tidy('원형 맨홀 D600을 담았습니다.') === '원형 맨홀 D600을 담았습니다.');
$pok('상품 사진: 케이토목 40×40 썸네일 → 같은 파일 450×450(그 밖의 주소는 그대로)', quick_chat_big_image('https://ktomok.com/data/item/10/1060/1581990666/thumb-pcbox_o_1_40x40.jpg') === 'https://ktomok.com/data/item/10/1060/1581990666/thumb-pcbox_o_1_450x450.jpg'
    && quick_chat_big_image('/uploads/products/a/b_40x40.jpg') === '/uploads/products/a/b_40x40.jpg' && quick_chat_big_image('http://www.dacity.co.kr/x/thumb-a_450x450.jpg') === 'http://www.dacity.co.kr/x/thumb-a_450x450.jpg');
$pok('상품 사진: 동아산업 www.dacity.co.kr/data/item/… → 같은 경로 ktomok.com(화면 표시만)', quick_chat_big_image('http://www.dacity.co.kr/data/item/31/3110/1581993551/thumb-hhgw_1_450x450.jpg') === 'https://ktomok.com/data/item/31/3110/1581993551/thumb-hhgw_1_450x450.jpg'
    && quick_chat_big_image('http://dacity.co.kr/data/item/30/3010/1581990436/thumb-sgw_1_450x450.png') === 'https://ktomok.com/data/item/30/3010/1581990436/thumb-sgw_1_450x450.png'
    && quick_chat_big_image('http://www.dacity.co.kr/bbs/x.php') === 'http://www.dacity.co.kr/bbs/x.php');
$pok('답 글 단위는 m 기준(사용자 피드백 11-24): mm → m, 규격 표기·호칭은 그대로', quick_reply_units('조건(폭 150mm, 길이 1000mm)과 설치 길이 10m') === '조건(폭 0.15m, 길이 1m)과 설치 길이 10m'
    && quick_reply_units('높이는 50 mm, 길이 1,500mm') === '높이는 0.05m, 길이 1.5m' && quick_reply_units('300×1000×50mm·300*1000*32mm·D150mm') === '300×1000×50mm·300*1000*32mm·D150mm'
    && quick_reply_tidy('폭 300mm 제품을 담았습니다.') === '폭 0.3m 제품을 담았습니다.');
$pok('설치 길이·면적만 말한 말 읽기(개수·폭·제품 길이·규격 표기·값 둘은 아님)', quick_install_amount('10미터쯤 설치할거야') === ['length_m' => 10.0] && quick_install_amount('20m요') === ['length_m' => 20.0]
    && quick_install_amount('30㎡ 정도 깔 거예요') === ['area_m2' => 30.0] && quick_install_amount('설치 길이 15m') === ['length_m' => 15.0] && quick_install_amount('폭 0.3m 20m') === ['length_m' => 20.0]
    && quick_install_amount('10개요') === null && quick_install_amount('길이 1m 그레이팅') === null && quick_install_amount('개거 1.5*1.5*2.0m') === null && quick_install_amount('10m 30m') === null);
$pok('새 자재 요청인지(자재 이름·추천·가격 질문) — 조건만 답한 말은 아님', quick_match_new_request('도로에 쓸 그레이팅 추천해줘', 'grating') && quick_match_new_request('잔디블록 얼마에요', '')
    && !quick_match_new_request('아파트 주차장이고 차 다녀요. 20m요', '') && !quick_match_new_request('네 차 다녀요', ''));
$pok('수량을 맞출 줄: 수량 미정 줄, 없으면 한 줄뿐일 때만', quick_qty_targets([5 => 0, 6 => 3]) === [5] && quick_qty_targets([6 => 3]) === [6] && quick_qty_targets([6 => 3, 7 => 2]) === []);
$pok('천 단위 쉼표 숫자는 그대로(1,200 → \'1, 200\' 안 됨 — 운영 시험 11-24)', quick_reply_tidy('설치 길이 1,200m, 수량 1,200개를 담았습니다.') === '설치 길이 1,200m, 수량 1,200개를 담았습니다.');
$pok('화면 안내 말이 아니면 그대로(수량·지역 묻기·확인 필요·견적서 만들기 안내)', ($keepTxt = "말씀하신 규격으로 우선 목록에 담았습니다. 차량이 다니는 곳인지 알려 주세요.\n확인 필요:\n경계석 150×170\n'견적서 만들어줘'라고 하시면 견적서로 옮겨 드립니다.") === quick_reply_tidy($keepTxt)
    && quick_reply_tidy('수량 확인과 현장 지역(시·도)을 알려 주시면 화면에 운반비까지 표시해 드립니다.') === '수량 확인과 현장 지역(시·도)을 알려 주시면 화면에 운반비까지 표시해 드립니다.');
$pitems = quick_photo_items(json_encode(['items' => [
    ['name' => '사각보도블록', 'spec' => '300×300×60', 'qty' => 50, 'unit' => 'm²'],
    ['name' => 'EX메탈망그레이팅', 'spec' => '300×1000×50', 'qty' => 2.5, 'unit' => '개'],
    ['name' => '없는자재', 'spec' => '10×10', 'qty' => 3, 'unit' => '개'],
    ['name' => '사각보도블록', 'spec' => '300×300×60', 'qty' => null, 'unit' => '', 'unclear' => true],
]], JSON_UNESCAPED_UNICODE));
$pmatch = quick_photo_match($pitems, static fn($n, $sp) => quick_match_pick($n, $sp, $rows));
$pok('사진 품목 전부 찾아 담기: 사진 수량(소수 올림) · 못 찾은 줄·읽기 애매한 줄은 확인 필요', $pmatch['add'] === [2 => 50, 3 => 3]
    && count($pmatch['missing']) === 2 && str_contains($pmatch['missing'][0], '없는자재') && str_contains($pmatch['missing'][1], '읽기 애매함'));
$ptext = quick_photo_reply_text(4, 2, $pmatch['missing'], false);
$pok('사진 안내 글: 목록에 담음 · 사진 단가 안 씀 · 확인 필요 · 지역 묻기', str_contains($ptext, '4개 품목을 읽어 2개를 이 창의 목록에 담았습니다')
    && str_contains($ptext, '사진 속 단가가 아닌') && str_contains($ptext, "확인 필요") && str_contains($ptext, '현장 지역(시·도)') && !str_contains($ptext, '견적서에 담'));
$pok('운영 기본값: 사진 첨부 켬', FEATURE_AI_QUICK_PHOTO === true && ai_quick_photo_enabled());

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true) || !db() || !function_exists('curl_init')) {
    echo "SKIP: 로컬 테스트 DB(*_test)·curl 에서만 실행합니다.\n";
    ob_end_flush();
    exit(0);
}
$pass = $pure['pass']; $fail = $pure['fail'];
function ok(string $name, bool $cond, $detail = null): void
{
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}" . ($detail !== null ? '  — ' . mb_substr(json_encode($detail, JSON_UNESCAPED_UNICODE), 0, 300) : '') . "\n"; }
}

echo "[보이는 사람 — 기능 스위치]\n";
$staff = ['id' => 1, 'role' => 'staff', 'name' => 's', 'email' => 's@x'];
$admin = ['role' => 'admin'] + $staff;
$cust  = ['role' => 'customer'] + $staff;
ok("'staff': 직원·관리자만", ai_quick_chat_visible($staff, 'staff') && ai_quick_chat_visible($admin) && !ai_quick_chat_visible($cust) && !ai_quick_chat_visible(null));
ok("'all': 비로그인도", ai_quick_chat_visible(null, 'all') && ai_quick_chat_visible($cust));
ok("'off': 아무도", !ai_quick_chat_visible($admin, 'off'));
ai_quick_chat_visible(null, 'all');
ai_quote_card_enabled(false);
ok('견적 카드 스위치를 끄면 안 보임', !ai_quick_chat_visible($admin));
ai_quote_card_enabled(true);
ai_quick_chat_visible(null, FEATURE_AI_QUICK_CHAT);
ok('운영 기본값 = 관리자·직원만', FEATURE_AI_QUICK_CHAT === 'staff');

echo "[프롬프트 — 기본 상담 그대로, 빠른 창은 구역만 덧붙음]\n";
$msgs = [['role' => 'user', 'content' => '마당 경계석 30m 차는 안 다녀요']];
$pc = rag_build_prompt($msgs, [], 'consult')['system'];
$pq = rag_build_prompt($msgs, [], 'quick')['system'];
ok('기본 = style 생략과 같음', $pc === rag_build_prompt($msgs)['system']);
ok('빠른 창 = 기본 + 빠른 견적 창 구역', $pq === $pc . RAG_QUICK_STYLE_PROMPT && !str_contains($pc, '빠른 견적 창 답변 방식'));

echo "[화면 — 버튼·창 출력]\n";
$html = static function (string $mode, ?array $user): string {
    $_SESSION['user'] = $user;
    $GLOBALS['__layout_mode'] = $mode;
    ob_start();
    render_quick_chat();
    return (string) ob_get_clean();
};
ok('직원·일반 화면: 버튼·창·스크립트', str_contains($h = $html('normal', $staff), 'id="qc-fab"') && str_contains($h, '/assets/quick-chat.js') && str_contains($h, 'id="qc-title"') && !str_contains($h, '관리자·직원 시험 중'));   // 머리 안내 줄 뺌(사용자 요청 11-24)
ok('고객·비로그인: 없음', $html('normal', $cust) === '' && $html('home', null) === '');
ok('AI 상담 화면(chat): 없음', $html('chat', $staff) === '');
$_SESSION['user'] = null;

// ── 목업 OpenAI + 내장 서버 ───────────────────────────────────
$pdo = db();
// 로그인 세션의 사용자 id: 직원 계정이 있으면 그 id, 없으면(개인정보 없이 복제한 테스트 DB) [TEST] 계정 id — 역할 'staff' 는 세션에만
$staffId = (int) ($pdo->query("SELECT id FROM users WHERE role IN ('staff','admin') ORDER BY id LIMIT 1")->fetchColumn()
    ?: $pdo->query("SELECT id FROM users ORDER BY id LIMIT 1")->fetchColumn());
$pid = 0;
foreach ($pdo->query("SELECT id, spec FROM products WHERE is_active = 1 AND price_b2c > 0 AND name LIKE '%경계석%' ORDER BY id LIMIT 50")->fetchAll() as $r) {
    if (freight_unit_kg(parse_spec_field((string) $r['spec'])['weight']) > 0) { $pid = (int) $r['id']; break; }
}
if ($staffId <= 0 || $pid <= 0) {
    echo "SKIP(API): 직원 계정 또는 중량 있는 경계석 상품이 없습니다\n";
} else {
    file_put_contents("{$tmp}/router.php", '<?php $d = __DIR__; $n = (int) @file_get_contents("$d/n") + 1; file_put_contents("$d/n", (string) $n);
file_put_contents("$d/req-$n.json", file_get_contents("php://input")); $r = json_decode(file_get_contents("$d/responses.json"), true);
header("Content-Type: application/json"); echo json_encode($r[$n - 1] ?? end($r), JSON_UNESCAPED_UNICODE);');
    $call = static fn(string $name, array $args, int $i) => ['choices' => [['finish_reason' => 'tool_calls', 'message' => ['role' => 'assistant', 'content' => null,
        'tool_calls' => [['id' => "call_{$i}", 'type' => 'function', 'function' => ['name' => $name, 'arguments' => json_encode($args, JSON_UNESCAPED_UNICODE)]]]]]]];
    $say = static fn(string $t) => ['choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => $t]]]];
    file_put_contents("{$tmp}/responses.json", json_encode([
        $call('search_products', ['query' => '경계석', 'length_m' => 30], 1),
        $call('add_to_cart', ['product_id' => $pid, 'quantity' => 30, 'region' => '경기'], 2),
        $say('[테스트] 차가 다니지 않는 마당이라 일반 경계석을 담았습니다.'),
    ], JSON_UNESCAPED_UNICODE));
    $mock = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$mockPort}", "{$tmp}/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p1, $tmp);

    $port = 18000 + random_int(0, 999);
    $env  = ['OPENAI_CHAT_URL' => "http://127.0.0.1:{$mockPort}/v1/chat/completions", 'OPENAI_API_KEY' => 'test-not-a-key'];
    foreach (['DB_HOST', 'DB_NAME', 'DB_USER', 'DB_PASS', 'PATH'] as $k) { $env[$k] = (string) getenv($k); }
    $web = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', realpath(__DIR__ . '/../public')], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2, null, $env);
    register_shutdown_function(static function () use ($mock, $web) { proc_terminate($mock); proc_terminate($web); });
    for ($i = 0; $i < 50; $i++) { if (@fsockopen('127.0.0.1', $port) && @fsockopen('127.0.0.1', $mockPort)) { break; } usleep(100000); }
    $post = static function (array $body, ?string $cookie) use ($port): array {
        $ch = curl_init("http://127.0.0.1:{$port}/api/chat/quick.php");
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 60]);
        if ($cookie !== null) { curl_setopt($ch, CURLOPT_COOKIE, $cookie); }
        $raw = (string) curl_exec($ch);
        $st  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return [$st, json_decode($raw, true)];
    };

    echo "[API — 권한]\n";
    [$st] = $post(['action' => 'new'], null);
    ok('비로그인 → 403', $st === 403);

    // 직원 로그인 세션(로컬 테스트 DB 의 직원 id, 세션 파일에만)
    if (session_status() !== PHP_SESSION_ACTIVE) { session_start(); }
    $_SESSION = ['user' => ['id' => $staffId, 'role' => 'staff', 'name' => '[TEST] 직원', 'email' => 'staff@test.local'], 'cart' => []];
    $cookie = session_name() . '=' . session_id();
    session_write_close();

    echo "[API — 질문 → 검색·담기 → 견적 표]\n";
    [$st, $d] = $post(['action' => 'ask', 'sid' => '', 'msg' => str_repeat('가', 201)], $cookie);
    ok('200자 초과 거부', $st === 200 && ($d['ok'] ?? null) === false);
    [$st, $d] = $post(['action' => 'new'], $cookie);
    $sid = (string) ($d['sid'] ?? '');
    ok("새 대화 키 'qc…'", ($d['ok'] ?? false) && str_starts_with($sid, 'qc') && strlen($sid) === 32);
    [$st, $d] = $post(['action' => 'ask', 'sid' => $sid, 'msg' => '마당 경계석 30m, 차는 안 다녀요. 경기도예요'], $cookie);
    $q = null;
    foreach ($d['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'quote_draft') { $q = $e; } }
    ok('답변 + 같은 상담 키', ($d['ok'] ?? false) && ($d['sid'] ?? '') === $sid && str_contains((string) $d['text'], '[테스트]'), $d);
    ok('견적 표: 담은 상품 30개 · 경기 운임 계산', $q !== null && ($q['items'][0]['product_id'] ?? 0) === $pid && ($q['items'][0]['quantity'] ?? 0) === 30
        && ($q['region'] ?? '') === '경기' && in_array($q['freight']['status'] ?? '', ['ok', 'missing_weight', 'negotiate'], true), $q);
    ok('창에 쓰는 이벤트만(견적 표·추천 제품 — 사이트 견적서 갱신 없음)', !array_diff(array_column($d['events'] ?? [], 'type'), ['quote_draft', 'product_cards']));
    // 세션 다시 읽기(테스트 서버가 쓴 세션 파일)
    $sess = static function () use ($cookie): array {
        session_id(explode('=', $cookie, 2)[1]);
        session_start();
        $v = $_SESSION;
        session_write_close();
        return $v;
    };
    $sv = $sess();
    ok('담은 곳 = 창 안 목록(사이트 견적서는 그대로 비어 있음)', ($sv['qc_list_' . $sid][$pid] ?? 0) === 30 && empty($sv['cart']), ['list' => $sv['qc_list_' . $sid] ?? null, 'cart' => $sv['cart'] ?? null]);
    $req1 = json_decode((string) @file_get_contents("{$tmp}/req-1.json"), true);
    ok('OpenAI 로 보낸 시스템 프롬프트에 빠른 견적 창 구역', str_contains((string) ($req1['messages'][0]['content'] ?? ''), '빠른 견적 창 답변 방식'));
    $saved = $pdo->prepare('SELECT messages FROM ai_sessions WHERE session_key = ?');
    $saved->execute([$sid]);
    $hist = json_decode((string) $saved->fetchColumn(), true) ?: [];
    ok('대화 저장(사용자·AI 2줄)', count($hist) === 2 && ($hist[0]['role'] ?? '') === 'user' && ($hist[1]['role'] ?? '') === 'assistant');
    $logs = $pdo->prepare('SELECT COUNT(*) FROM chat_logs WHERE session_key = ?');
    $logs->execute([$sid]);
    ok('상담 로그 1건(빠른 창 상담은 키로 구분)', (int) $logs->fetchColumn() === 1);
    [$st, $d] = $post(['action' => 'ask', 'sid' => 'qc' . str_repeat('0', 30), 'msg' => '안녕하세요'], $cookie);
    ok('남의(허용 안 된) 키로 물으면 새 키로', ($d['sid'] ?? '') !== 'qc' . str_repeat('0', 30) && str_starts_with((string) ($d['sid'] ?? ''), 'qc'));

    echo "[API — 케이토목처럼 항상 바로 담기(A11, 운영 상담 #260)]\n";
    $mockSeq = static function (array $responses) use ($tmp): void {
        file_put_contents("{$tmp}/responses.json", json_encode($responses, JSON_UNESCAPED_UNICODE));
        @unlink("{$tmp}/n");
        array_map('unlink', glob("{$tmp}/req-*.json") ?: []);
    };
    $mockSeq([
        $call('search_products', ['query' => '경계석'], 21),
        $say('[테스트] 조건에 맞는 경계석입니다. 원하시는 제품을 선택해 주세요.'),
        $call('add_to_cart', ['product_id' => $pid, 'quantity' => 10], 22),
        $say('[테스트] 일반 경계석을 담았습니다.'),
    ]);
    [$st, $d] = $post(['action' => 'new'], $cookie);
    $fsid = (string) ($d['sid'] ?? '');
    [$st, $d] = $post(['action' => 'ask', 'sid' => $fsid, 'msg' => '경계석 10개'], $cookie);
    $r3 = json_decode((string) @file_get_contents("{$tmp}/req-3.json"), true);
    $q = null;
    foreach ($d['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'quote_draft') { $q = $e; } }
    ok("'골라 주세요'로 끝나면 담기 1회 강제(tool_choice add_to_cart)", ($r3['tool_choice']['function']['name'] ?? '') === 'add_to_cart', $r3['tool_choice'] ?? null);
    ok('강제 후 답 = 담은 뒤 답(골라 달라는 글은 버림)', ($d['ok'] ?? false) && str_contains((string) $d['text'], '담았습니다') && !str_contains((string) $d['text'], '선택해 주세요'), $d['text'] ?? null);
    ok('견적 표 + 상품 사진·상품목록 연결(화면 표시용)', $q !== null && array_key_exists('image', $q['items'][0] ?? []) && ($q['items'][0]['category_id'] ?? 0) > 0
        && array_key_exists('spec_image', $q['items'][0]), $q['items'][0] ?? null);
    $mockSeq([
        $call('search_products', ['query' => '그레이팅'], 31),
        $say('[테스트] 차량이 다니는 곳인가요?'),
    ]);
    [$st, $d] = $post(['action' => 'ask', 'sid' => $fsid, 'msg' => '그레이팅 폭 300 20m'], $cookie);
    ok("확인 질문('?')으로 끝나면 강제하지 않음", ($d['ok'] ?? false) && str_contains((string) $d['text'], '차량이 다니는') && !is_file("{$tmp}/req-3.json"), $d);
    ok('기본 AI 상담(consult)은 강제 담기 없음 — 코드상 style 조건', str_contains((string) file_get_contents(__DIR__ . '/../app/rag.php'), "\$style === 'quick' && !\$forcedAdd"));

    echo "[API — 고객이 품명+규격을 말하면 바로 담기 · 같은 상품 다시 담아도 늘지 않음(운영 시험 11-24)]\n";
    $pr = $pdo->query("SELECT name, spec FROM products WHERE id = {$pid}")->fetch();
    $pName = (string) $pr['name'];
    $pSpec = implode('*', array_map(static fn($n) => (string) $n, array_slice(quick_match_nums(quick_match_spec_text((string) $pr['spec'])), 0, 3)));
    $mockSeq([
        $say('[테스트] 설치 장소에 차량이 다니나요?'),
        $call('add_to_cart', ['product_id' => $pid, 'quantity' => 0], 61),
        $say('[테스트] 말씀하신 규격으로 우선 목록에 담았습니다. 차량이 다니나요?'),
    ]);
    [$st, $d] = $post(['action' => 'new'], $cookie);
    $nsid = (string) ($d['sid'] ?? '');
    [$st, $d] = $post(['action' => 'ask', 'sid' => $nsid, 'msg' => "{$pName} {$pSpec} 얼마에요?"], $cookie);
    $forced = false;
    foreach (glob("{$tmp}/req-*.json") ?: [] as $f) {
        $rq = json_decode((string) file_get_contents($f), true);
        if (($rq['tool_choice']['function']['name'] ?? '') === 'add_to_cart' && str_contains((string) json_encode($rq['messages'], JSON_UNESCAPED_UNICODE), "id {$pid}")) { $forced = true; }
    }
    ok("품명+규격을 말하면 묻기 전에 그 상품을 담기 강제(질문 '?'로 끝나도)", $forced && ($sess()['qc_list_' . $nsid][$pid] ?? -1) === 0
        && str_contains((string) ($d['text'] ?? ''), '목록에 담았습니다'), ['text' => $d['text'] ?? null, 'list' => $sess()['qc_list_' . $nsid] ?? null]);
    $broad = $pdo->query("SELECT id, name, spec FROM products WHERE is_active = 1 AND name LIKE '%그레이팅%' AND spec LIKE '%*1000*%' ORDER BY id DESC LIMIT 1")->fetch();
    if ($broad) {
        $bf = quick_match_find('그레이팅', quick_match_spec_text((string) $broad['spec']));
        ok('넓은 품명(그레이팅)이어도 규격 숫자로 먼저 좁혀 찾음(이름 검색 앞 60개 밖이어도)', $bf !== null && quick_match_spec_text((string) $bf['spec']) === quick_match_spec_text((string) $broad['spec']), [$broad['id'], $bf['id'] ?? null]);
    }
    $mockSeq([$call('add_to_cart', ['product_id' => $pid, 'quantity' => 20], 62), $say('[테스트] 20개로 담았습니다.')]);
    $post(['action' => 'ask', 'sid' => $nsid, 'msg' => '20개요'], $cookie);
    $mockSeq([$call('add_to_cart', ['product_id' => $pid, 'quantity' => 20, 'region' => '경기'], 63), $say('[테스트] 경기 운반비를 계산했습니다.')]);
    $post(['action' => 'ask', 'sid' => $nsid, 'msg' => '경기도예요'], $cookie);
    $after = $sess()['qc_list_' . $nsid][$pid] ?? 0;
    $mockSeq([$call('add_to_cart', ['product_id' => $pid, 'quantity' => 0], 64), $say('[테스트] 그대로 두었습니다.')]);
    $post(['action' => 'ask', 'sid' => $nsid, 'msg' => '그대로요'], $cookie);
    ok('같은 상품을 다시 담으면 그 수량으로 맞춤(20 → 40 으로 늘지 않음) · 0 으로 다시 담으면 그대로', $after === 20 && ($sess()['qc_list_' . $nsid][$pid] ?? 0) === 20,
        ['after_region' => $after, 'final' => $sess()['qc_list_' . $nsid][$pid] ?? null]);

    echo "[API — 바꿔 담기: 조건을 듣고 다른 제품을 담으면 수량 0 이던 같은 크기 줄을 뺌(운영 시험 11-24)]\n";
    $sa = $pdo->query("SELECT id FROM products WHERE is_active = 1 AND name = '중하중그레이팅수로용' AND spec LIKE '%300*1000*65%' ORDER BY id LIMIT 1")->fetchColumn();
    $sb = $pdo->query("SELECT id FROM products WHERE is_active = 1 AND name = '안전중하중그레이팅수로용' AND spec LIKE '%300*1000*75%' ORDER BY id LIMIT 1")->fetchColumn();
    if ($sa && $sb) {
        [$sa, $sb] = [(int) $sa, (int) $sb];
        $swapRun = static function (string $msg2, bool $sameTurn = false) use ($mockSeq, $call, $say, $post, $sess, $cookie, $sa, $sb): array {
            [, $d] = $post(['action' => 'new'], $cookie);
            $ssid = (string) ($d['sid'] ?? '');
            if ($sameTurn) {
                $mockSeq([$call('add_to_cart', ['product_id' => $sa, 'quantity' => 0], 71), $call('add_to_cart', ['product_id' => $sb, 'quantity' => 0], 72), $say('[테스트] 두 제품을 목록에 담았습니다.')]);
                [, $d] = $post(['action' => 'ask', 'sid' => $ssid, 'msg' => $msg2], $cookie);
                return [$sess()['qc_list_' . $ssid] ?? [], (string) ($d['text'] ?? '')];
            }
            // 수량을 빠뜨려도 창 안 목록은 0(미정) — 운영 시험 11-24
            $mockSeq([$call('add_to_cart', ['product_id' => $sa], 73), $say('[테스트] 말씀하신 규격으로 우선 목록에 담았습니다. 차량이 다니나요?')]);
            $post(['action' => 'ask', 'sid' => $ssid, 'msg' => '중하중그레이팅수로용 300*1000*65 얼마에요?'], $cookie);
            $mockSeq([$call('add_to_cart', ['product_id' => $sb, 'quantity' => 20], 74), $say('[테스트] 조건에 맞춰 안전중하중으로 바꿔 담았습니다. 견적서는 화면에 이미 표시되고 있으며, 운임은 별도로 확인됩니다.')]);
            [, $d] = $post(['action' => 'ask', 'sid' => $ssid, 'msg' => $msg2], $cookie);
            return [$sess()['qc_list_' . $ssid] ?? [], (string) ($d['text'] ?? '')];
        };
        [$l1, $t1] = $swapRun('아파트 주차장이고 차 다녀요. 20m요');
        ok('수량 없이 담은 줄은 0(미정) · 차량 조건을 듣고 안전중하중을 담으면 0개였던 같은 크기 줄은 빠지고 새 제품 20개만', !isset($l1[$sa]) && ($l1[$sb] ?? 0) === 20, $l1);
        ok("답 글에서 '견적서는 화면에 이미 표시되고 있으며' 빠짐", !str_contains($t1, '화면에') && str_contains($t1, '운임은 별도로 확인됩니다'), $t1);
        [$l2] = $swapRun('75mm 짜리도 같이 넣어줘');
        ok("'같이 넣어줘'면 0개 줄도 그대로", ($l2[$sa] ?? -1) === 0 && ($l2[$sb] ?? 0) === 20, $l2);
        [$l3] = $swapRun('300*1000*65 와 75 얼마예요', true);
        ok('같은 턴에 함께 담은 0개 줄은 빼지 않음', ($l3[$sa] ?? -1) === 0 && ($l3[$sb] ?? -1) === 0, $l3);
        [$l4] = $swapRun('도로에 쓸 그레이팅 추천해줘');
        ok("새 상황('도로에 쓸 그레이팅 추천해줘')이면 앞 상황의 0개 줄도 그대로(사용자 피드백 11-25)", ($l4[$sa] ?? -1) === 0 && ($l4[$sb] ?? 0) === 20, $l4);

        echo "[API — 수량 추천: 설치 길이를 말하면 담긴 제품을 권장 수량으로(사용자 피드백 11-24)]\n";
        [$sq, $sbasis] = tool_cart_suggest_qty($sa, 20.0, 0.0);
        ok('담긴 제품 권장 수량 = 검색 표와 같은 계산(20m ÷ 1m = 20개)', $sq === 20 && str_contains($sbasis, '20m ÷ 1m = 20'), [$sq, $sbasis]);
        [, $d] = $post(['action' => 'new'], $cookie);
        $qsid = (string) ($d['sid'] ?? '');
        $mockSeq([$call('add_to_cart', ['product_id' => $sa], 81), $say('[테스트] 목록에 담았습니다.')]);
        $post(['action' => 'ask', 'sid' => $qsid, 'msg' => '중하중그레이팅수로용 300*1000*65 얼마에요?'], $cookie);
        $reqs = implode('', array_map(static fn($f) => (string) json_encode(json_decode((string) file_get_contents($f), true), JSON_UNESCAPED_UNICODE), glob("{$tmp}/req-*.json") ?: []));
        ok('0개로 담으면 결과에 수량 예시(qty_hint — 10m면 10개)', str_contains($reqs, 'qty_hint') && str_contains($reqs, '10m ÷ 1m = 10'), mb_substr($reqs, 0, 200));
        $mockSeq([$call('update_cart', ['product_id' => $sa, 'length_m' => 20], 82), $say('[테스트] 20m 설치면 1m 제품 20개 정도 필요합니다. 폭 300mm, 길이 1000mm 제품입니다.')]);
        [, $d] = $post(['action' => 'ask', 'sid' => $qsid, 'msg' => '20미터쯤 설치할건데 몇 개 사면 돼?'], $cookie);
        $reqs = implode('', array_map('file_get_contents', glob("{$tmp}/req-*.json") ?: []));
        ok('개수 대신 설치 길이 → 서버가 권장 수량 20개로 맞추고 계산식(qty_basis)을 돌려줌', ($sess()['qc_list_' . $qsid][$sa] ?? 0) === 20 && str_contains($reqs, 'qty_basis'), $sess()['qc_list_' . $qsid] ?? null);
        ok('답 글의 mm 는 m 로(폭 0.3m·길이 1m)', str_contains((string) ($d['text'] ?? ''), '폭 0.3m, 길이 1m') && !str_contains((string) ($d['text'] ?? ''), 'mm'), $d['text'] ?? null);
        echo "[API — 개수 없이 '10미터쯤 설치할거야'만 말해도 권장 수량(사용자 피드백 11-24)]\n";
        $zeroRun = static function (array $seq, string $msg2) use ($mockSeq, $call, $say, $post, $sess, $cookie, $sa): array {
            [, $d] = $post(['action' => 'new'], $cookie);
            $zsid = (string) ($d['sid'] ?? '');
            $mockSeq([$call('add_to_cart', ['product_id' => $sa], 91), $say('[테스트] 목록에 담았습니다.')]);
            $post(['action' => 'ask', 'sid' => $zsid, 'msg' => '중하중그레이팅수로용 300*1000*65 얼마에요?'], $cookie);
            $mockSeq($seq);
            [, $d] = $post(['action' => 'ask', 'sid' => $zsid, 'msg' => $msg2], $cookie);
            return [$sess()['qc_list_' . $zsid] ?? [], (string) ($d['text'] ?? '')];
        };
        [$z1, $zt1] = $zeroRun([$say('[테스트] 10m 설치하시는군요. 필요한 수량을 알려 주세요.'), $call('update_cart', ['product_id' => $sa, 'length_m' => 10], 92), $say('[테스트] 설치 길이 10m이면 10개 정도 필요합니다. 수량을 맞춰 두었습니다.')], '10미터쯤 설치할거야');
        $reqs = implode('', array_map(static fn($f) => (string) json_encode(json_decode((string) file_get_contents($f), true), JSON_UNESCAPED_UNICODE), glob("{$tmp}/req-*.json") ?: []));
        ok('GPT 가 수량을 안 맞추고 답하면 update_cart(length_m 10)를 강제 → 10개', ($z1[$sa] ?? 0) === 10 && str_contains($reqs, '개수 없이 설치 길이 10m만') && str_contains($zt1, '10개 정도'), [$z1, $zt1]);
        [$z2, $zt2] = $zeroRun([$say('[테스트] 10m 설치하시는군요. 몇 개 필요하신지 알려 주세요.'), $say('[테스트] 10m 설치하시는군요. 몇 개 필요하신지 알려 주세요.')], '10미터쯤 설치할거야');
        ok('강제해도 안 맞추면 서버가 맞추고 한 줄을 앞에(개수 묻는 문장은 뺌)', ($z2[$sa] ?? 0) === 10 && str_starts_with($zt2, '설치 길이 10m이면') && str_contains($zt2, '10개 정도') && !str_contains($zt2, '몇 개'), [$z2, $zt2]);
        [$z3] = $zeroRun([$say('[테스트] 알겠습니다.')], '10개요');
        ok("개수를 말하면 서버가 길이로 바꾸지 않음('10개요')", ($z3[$sa] ?? -1) === 0, $z3);
    } else {
        echo "  (건너뜀: 테스트 DB 에 중하중·안전중하중 그레이팅수로용 300*1000 없음)\n";
    }

    echo "[API — 창 안 목록(케이토목식): 수량 미정 0 · '견적서 만들어줘' · [견적 요청하기]]\n";
    $mockSeq([
        $call('search_products', ['query' => '경계석'], 41),
        $call('add_to_cart', ['product_id' => $pid, 'quantity' => 0], 42),
        $say('[테스트] 경계석을 목록에 담았습니다. 필요한 수량을 알려 주세요.'),
    ]);
    [$st, $d] = $post(['action' => 'new'], $cookie);
    $lsid = (string) ($d['sid'] ?? '');
    [$st, $d] = $post(['action' => 'ask', 'sid' => $lsid, 'msg' => '경계석 얼마에요?'], $cookie);
    $q = null;
    foreach ($d['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'quote_draft') { $q = $e; } }
    ok('수량 미정이면 0개로 목록에 담김', ($d['ok'] ?? false) && ($q['items'][0]['quantity'] ?? -1) === 0 && ($q['items'][0]['product_id'] ?? 0) === $pid, $q);
    [$st, $d] = $post(['action' => 'ask', 'sid' => $lsid, 'msg' => '견적서 만들어줘'], $cookie);
    ok("'견적서 만들어줘' + 수량 미정만 → 옮기지 않고 수량을 물음(GPT 호출 없음)", ($d['ok'] ?? false) && str_contains((string) $d['text'], '옮기지 못했습니다')
        && !is_file("{$tmp}/req-4.json") && empty($sess()['cart']), $d['text'] ?? null);
    [$st, $d] = $post(['action' => 'to_estimate', 'sid' => $lsid], $cookie);
    ok('[견적 요청하기] + 수량 미정만 → 거부 안내', ($d['ok'] ?? null) === false && str_contains((string) ($d['error'] ?? ''), '수량'), $d);
    $mockSeq([
        $call('update_cart', ['product_id' => $pid, 'quantity' => 25], 51),
        $say('[테스트] 25개로 바꿨습니다.'),
    ]);
    [$st, $d] = $post(['action' => 'ask', 'sid' => $lsid, 'msg' => '25개로 해 주세요'], $cookie);
    ok('수량 바꾸기도 목록에서', ($sess()['qc_list_' . $lsid][$pid] ?? 0) === 25 && empty($sess()['cart']));
    [$st, $d] = $post(['action' => 'ask', 'sid' => $lsid, 'msg' => '견적서 만들어줘'], $cookie);
    $types = array_column($d['events'] ?? [], 'type');
    $sv = $sess();
    ok("'견적서 만들어줘' → 사이트 견적서로 옮김 + 견적 표·바로가기", ($d['ok'] ?? false) && str_contains((string) $d['text'], '1개 품목을 견적서로 옮겼습니다')
        && ($sv['cart'][$pid] ?? 0) === 25 && in_array('estimate_moved', $types, true) && in_array('quote_draft', $types, true), ['text' => $d['text'] ?? null, 'types' => $types, 'cart' => $sv['cart'] ?? null]);
    [$st, $d] = $post(['action' => 'to_estimate', 'sid' => $lsid], $cookie);
    ok('[견적 요청하기] 다시 눌러도 두 배가 되지 않음(목록 수량으로 맞춤)', ($d['ok'] ?? false) && ($d['url'] ?? '') === '/estimate.php' && ($sess()['cart'][$pid] ?? 0) === 25, $d);

    echo "[API — 견적서 사진 첨부: 읽은 품목을 바로 목록에(GPT 상담 호출 없음)]\n";
    $pr = $pdo->query("SELECT name, spec FROM products WHERE id = {$pid}")->fetch();
    $pName = (string) $pr['name'];
    $pSpec = implode('×', array_map(static fn($n) => (string) $n, array_slice(quick_match_nums(quick_match_spec_text((string) $pr['spec'])), 0, 3)));
    file_put_contents("{$tmp}/responses.json", json_encode([
        $say(json_encode(['items' => [
            ['name' => $pName, 'spec' => $pSpec, 'qty' => 30, 'unit' => '개'],
            ['name' => '홍길동 010-9876-5432 맨홀뚜껑', 'spec' => '', 'qty' => null, 'unit' => '', 'unclear' => true],
        ]], JSON_UNESCAPED_UNICODE)),
    ], JSON_UNESCAPED_UNICODE));
    @unlink("{$tmp}/n");
    array_map('unlink', glob("{$tmp}/req-*.json") ?: []);
    [$st, $d] = $post(['action' => 'new'], $cookie);
    $psid = (string) ($d['sid'] ?? '');
    $img  = 'data:image/png;base64,' . $png1;
    [$st, $d] = $post(['action' => 'photo', 'sid' => $psid, 'image' => $img], $cookie);
    ok('동의 없으면 거부 · OpenAI 호출 없음', ($d['ok'] ?? null) === false && str_contains((string) ($d['error'] ?? ''), '동의') && !is_file("{$tmp}/n"), $d);
    [$st, $d] = $post(['action' => 'photo', 'sid' => $psid, 'image' => base64_encode('not an image'), 'consent' => true], $cookie);
    ok('이미지가 아니면 거부 · OpenAI 호출 없음', ($d['ok'] ?? null) === false && !is_file("{$tmp}/n"), $d);
    [$st, $d] = $post(['action' => 'photo', 'sid' => $psid, 'image' => $img, 'consent' => true], $cookie);
    $q = null;
    foreach ($d['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'quote_draft') { $q = $e; } }
    ok('사진 → 읽은 품목 글(asked) + 담은 결과·확인 필요 안내 + 같은 상담 키', ($d['ok'] ?? false) && ($d['sid'] ?? '') === $psid
        && str_starts_with((string) ($d['asked'] ?? ''), QUICK_PHOTO_PREFIX) && str_contains((string) $d['text'], '1개를 이 창의 목록에 담았습니다')
        && str_contains((string) $d['text'], '확인 필요') && !str_contains((string) $d['text'], '9876'), $d);
    ok('견적 표: 사진 품목 30개(이 대화의 창 안 목록, 사이트 견적서 아님)', $q !== null && ($q['items'][0]['product_id'] ?? 0) === $pid && ($q['items'][0]['quantity'] ?? 0) === 30
        && ($sess()['qc_list_' . $psid][$pid] ?? 0) === 30, $q);
    $v = json_decode((string) @file_get_contents("{$tmp}/req-1.json"), true);
    $vc = $v['messages'][0]['content'] ?? [];
    ok('비전 요청: gpt-4o · 사진 data URL · 품목만 읽는 지시', ($v['model'] ?? '') === 'gpt-4o' && str_starts_with((string) ($vc[1]['image_url']['url'] ?? ''), 'data:image/png;base64,')
        && ($vc[0]['text'] ?? '') === QUICK_PHOTO_PROMPT);
    ok('사진 담기는 GPT 상담을 부르지 않음(OpenAI 1회 = 사진 읽기만)', !is_file("{$tmp}/req-2.json"));
    $saved->execute([$psid]);
    $ph = (string) $saved->fetchColumn();
    $lg = $pdo->prepare('SELECT CONCAT(user_msg, assistant_txt, COALESCE(tools_json, "")) FROM chat_logs WHERE session_key = ?');
    $lg->execute([$psid]);
    $lgText = (string) $lg->fetchColumn();
    ok('대화·상담 로그에 사진·전화번호 없음(품목 글만)', str_contains($ph, '경계석') && !str_contains($ph, 'base64') && !str_contains($ph, '9876')
        && $lgText !== '' && !str_contains($lgText, 'base64') && !str_contains($lgText, '9876'));

    echo "[API — 새 대화마다 배송 지역 · 이전 대화내용(서버, 다른 기기에서도)(사용자 결정 11-24)]\n";
    $sv = $sess();
    ok('배송 지역은 대화마다(qc_region_<키>) — 사이트 공통 칸은 그대로', ($sv['qc_region_' . $sid] ?? '') === '경기' && !isset($sv['quote_region']), array_intersect_key($sv, array_flip(['quote_region', 'qc_region_' . $sid])));
    [, $d] = $post(['action' => 'new'], $cookie);
    $rsid = (string) ($d['sid'] ?? '');
    $mockSeq([$call('add_to_cart', ['product_id' => $pid, 'quantity' => 5], 81), $say('[테스트] 목록에 담았습니다.')]);
    [, $d] = $post(['action' => 'ask', 'sid' => $rsid, 'msg' => '경계석 5개'], $cookie);
    $q = null;
    foreach ($d['events'] ?? [] as $e) { if (($e['type'] ?? '') === 'quote_draft') { $q = $e; } }
    ok('[새 대화]의 견적 표는 앞 대화의 지역(경기) 없이', $q !== null && ($q['region'] ?? null) === null, $q['region'] ?? null);
    [, $d] = $post(['action' => 'history', 'sid' => $rsid], $cookie);
    $hs = array_column($d['list'] ?? [], 'first', 'sid');
    $atSorted = static function (array $a): array { sort($a); return $a; };
    ok('이전 대화내용: 서버의 내 빠른 창 대화 최근 5개(지금 대화 제외, 최근 순)·첫 질문(사진은 \'견적서 사진\')', ($d['server'] ?? false) === true && count($hs) === 5 && !isset($hs[$rsid])
        && !in_array('', $hs, true) && !array_filter($hs, static fn($f) => str_starts_with($f, '[견적서 사진')) && array_column($d['list'], 'at') === array_reverse($atSorted(array_column($d['list'], 'at'))), $d);
    // 다른 기기처럼: 이 세션의 창 안 목록·지역을 지운 뒤 다시 열기
    session_id(explode('=', $cookie, 2)[1]); session_start(); unset($_SESSION['qc_list_' . $sid], $_SESSION['qc_region_' . $sid]); session_write_close();
    [, $d] = $post(['action' => 'load', 'sid' => $sid], $cookie);
    $types = array_merge(...array_map(static fn($m) => array_column($m['events'] ?? [], 'type'), $d['msgs'] ?? [[]]));
    $sv = $sess();
    ok('다시 열기: 질문·답 + 그때 견적 표, 창 안 목록(30개)·지역(경기)도 되살림', ($d['ok'] ?? false) && count($d['msgs'] ?? []) >= 2 && in_array('quote_draft', $types, true)
        && ($sv['qc_list_' . $sid][$pid] ?? 0) === 30 && ($sv['qc_region_' . $sid] ?? '') === '경기', ['msgs' => count($d['msgs'] ?? []), 'list' => $sv['qc_list_' . $sid] ?? null]);
    $pdo->prepare('UPDATE ai_sessions SET user_id = ? WHERE session_key = ?')->execute([$staffId + 100000, $psid]);
    [, $d] = $post(['action' => 'load', 'sid' => $psid], $cookie);
    [, $h2] = $post(['action' => 'history', 'sid' => $rsid], $cookie);
    ok('다른 회원 대화는 목록에도 없고 열 수도 없음', ($d['ok'] ?? true) === false && !in_array($psid, array_column($h2['list'] ?? [], 'sid'), true));
    [, $d] = $post(['action' => 'to_estimate', 'sid' => $sid], $cookie);
    ok('견적서로 옮기면 그 대화의 지역이 견적 요청 화면용 공통 칸으로', ($d['ok'] ?? false) && ($sess()['quote_region'] ?? '') === '경기', $d);

    // 정리: 이 테스트가 만든 상담 행(로컬 테스트 DB)
    $pdo->exec("DELETE FROM chat_logs WHERE session_key LIKE 'qc%'");
    $pdo->exec("DELETE FROM ai_sessions WHERE session_key LIKE 'qc%'");
}
array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);
echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
ob_end_flush();
exit($fail > 0 ? 1 : 0);
