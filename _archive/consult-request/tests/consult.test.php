<?php
declare(strict_types=1);
/**
 * 보관용(_archive/consult-request) 상담 요청 순수 함수 테스트 — DB 불필요.
 * 실행: php _archive/consult-request/tests/consult.test.php
 * (2026-11-04 tests/functions.test.php 에서 옮김. 기능을 되살리면 다시 functions.test.php 로)
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../app/consult.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
function eq(string $name, $a, $b): void {
    ok($name . '  =>  ' . json_encode($a, JSON_UNESCAPED_UNICODE), $a == $b);
}

echo "[상담 요청: 전화번호 consult_valid_phone / consult_format_phone]\n";
ok('휴대폰·지역번호 형식', consult_valid_phone('010-1234-5678') && consult_valid_phone('01012345678') && consult_valid_phone('02-123-4567') && consult_valid_phone('031-1234-5678'));
ok('잘못된 번호', !consult_valid_phone('1234') && !consult_valid_phone('10-1234-5678') && !consult_valid_phone('010-1234-56789'));
eq('하이픈 정리 010', consult_format_phone('01012345678'), '010-1234-5678');
eq('하이픈 정리 02', consult_format_phone('0212345678'), '02-1234-5678');
eq('하이픈 정리 031(3자리 국번)', consult_format_phone('0311234567'), '031-123-4567');

echo "[상담 요청: 입력 검증 consult_validate_request]\n";
$okIn = ['name' => ' 홍  길동 ', 'tel' => '01012345678', 'message' => " 문의\r\n내용 ", 'agree' => true];
[$e1, $c1] = consult_validate_request($okIn);
ok('정상 → 오류 없음·이름 공백 정리·번호 하이픈·CRLF 정리', $e1 === null && $c1 === ['name' => '홍 길동', 'tel' => '010-1234-5678', 'message' => "문의\n내용"]);
ok('이름 없음', consult_validate_request(['name' => ' '] + $okIn)[0] === '이름을 입력해 주세요.');
ok('이름 31자', consult_validate_request(['name' => str_repeat('가', 31)] + $okIn)[0] !== null);
ok('연락처 형식 오류(문자 섞임)', consult_validate_request(['tel' => '010-abcd-5678'] + $okIn)[0] !== null && consult_validate_request(['tel' => '123'] + $okIn)[0] !== null);
ok('문의 내용 없음·501자', consult_validate_request(['message' => '  '] + $okIn)[0] !== null && consult_validate_request(['message' => str_repeat('가', 501)] + $okIn)[0] !== null);
ok('500자는 통과', consult_validate_request(['message' => str_repeat('가', 500)] + $okIn)[0] === null);
ok('동의 안 함·문자열 "true" 는 거절', consult_validate_request(['agree' => false] + $okIn)[0] !== null && consult_validate_request(['agree' => 'true'] + $okIn)[0] !== null);

echo "[상담 요청: 접수 횟수 제한 consult_rate_allow]\n";
$_SESSION['consult_req_at'] = [];
ok('10분에 3건까지, 4번째 거절', consult_rate_allow(1000) && consult_rate_allow(1001) && consult_rate_allow(1002) && !consult_rate_allow(1003));
ok('10분 지나면 다시 허용', consult_rate_allow(1000 + CONSULT_RATE_WINDOW + 1));
$_SESSION['consult_req_at'] = [];

echo "[상담 요청: 목록 검색 조건 consult_list_where]\n";
[$w, $pp] = consult_list_where('pending', '');
ok('상태만', $w === '1=1 AND status = :status' && $pp === ['status' => 'pending']);
[$w, $pp] = consult_list_where('없는상태', '');
ok('알 수 없는 상태는 무시', $w === '1=1' && $pp === []);
[$w, $pp] = consult_list_where('', '010-1234');
ok('연락처 검색은 하이픈 무시(숫자 3자리 이상)', str_contains($w, "REPLACE(tel, '-', '') LIKE :q3") && $pp['q3'] === '%0101234%' && $pp['q1'] === '%010-1234%');
[$w, $pp] = consult_list_where('', '50%_');
ok('LIKE 특수문자 이스케이프·짧은 숫자는 연락처 검색 안 함', $pp['q1'] === '%50\%\_%' && !isset($pp['q3']));

echo "[상담 요청: AI 상담 내역 consult_chat_turns]\n";
$turns = consult_chat_turns([
    ['user_msg' => '집 앞 보도블록', 'assistant_txt' => '사람만 다니나요?', 'events_json' => null, 'created_at' => '2026-11-03 10:00:00'],
    ['user_msg' => '5m x 4m', 'assistant_txt' => '', 'created_at' => '2026-11-03 10:01:00', 'events_json' => json_encode([
        ['type' => 'product_cards', 'products' => [
            ['id' => 402, 'name' => '인터로킹I2형블럭', 'spec' => '규격: 199*99*60T', 'spec_label' => '199×99×60T_회색', 'price_b2c' => 8500, 'unit' => '㎡', 'suggested_qty' => 20, 'est_cost' => 170000, 'image_url' => '/uploads/a.jpg'],
            ['id' => 9, 'name' => 'X', 'image_url' => 'javascript:alert(1)'],
            ['id' => 10, 'name' => 'Y', 'image_url' => '//evil.example/a.png'],
            ['name' => ''],
        ]],
        ['type' => 'cart_update'],
    ], JSON_UNESCAPED_UNICODE)],
]);
ok('턴 2개·텍스트 그대로', count($turns) === 2 && $turns[0]['user'] === '집 앞 보도블록' && $turns[0]['products'] === []);
ok('추천표: 표시용 규격·가격·예상 자재비·이미지', $turns[1]['products'][0] === ['id' => 402, 'name' => '인터로킹I2형블럭', 'spec' => '199×99×60T_회색', 'price' => 8500, 'unit' => '㎡', 'qty' => 20, 'est_cost' => 170000, 'image' => '/uploads/a.jpg']);
ok('이름 없는 상품 제외·javascript:·// 이미지 주소 차단', count($turns[1]['products']) === 3 && $turns[1]['products'][1]['image'] === '' && $turns[1]['products'][2]['image'] === '');

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
