<?php
declare(strict_types=1);
/**
 * 문자(SMS·LMS) 발송 (app/sms.php)
 *
 * 책임: 문자 길이(byte) 계산·종류 판정, 발송 업체 API 호출(솔라피 · 알리고), 결과를 같은 형식으로 돌려주기
 * 금지: DB 접근, 화면 출력, 키·비밀번호·받는 번호를 로그에 남기기
 *
 * 설정(운영 /app/.env · 로컬 .env.local — 값은 커밋 금지):
 *   SMS_PROVIDER     solapi | aligo
 *   SMS_API_KEY      발송 업체 API 키
 *   SMS_API_SECRET   (솔라피) API Secret
 *   SMS_USER_ID      (알리고) 아이디
 *   SMS_SENDER       발신번호 — 발송 업체에 사전 등록한 번호(숫자·하이픈)
 *   SMS_SENDER_NAME  관리자 화면 '보내는 사람' 표시명(선택, 기본 비엠아이토목건축자재)
 *   SMS_TEST_MODE    (알리고) Y 면 testmode_yn=Y — 실제로 보내지 않고 접수만 확인(선택)
 *   SMS_API_URL      테스트 전용: 로컬 목업 서버(http://127.0.0.1:…)만 허용 — 그 외 값은 무시
 *
 * 길이 규칙(국내 이통사 기준): 한글 등 2byte · 영문/숫자 1byte, 90byte 이하 SMS, 그 위 LMS(최대 2,000byte)
 */

const SMS_MAX_SMS_BYTES = 90;
const SMS_MAX_LMS_BYTES = 2000;
const SMS_DEFAULT_SENDER_NAME = '비엠아이토목건축자재';

/** 문자 길이(byte) — ASCII 1, 그 외(한글·기호) 2 (순수 함수, 관리자 화면 JS 와 같은 규칙) */
function sms_byte_length(string $text): int
{
    $text = str_replace("\r\n", "\n", $text);
    $n = 0;
    foreach (mb_str_split($text) as $ch) {
        $n += strlen($ch) === 1 ? 1 : 2;
    }
    return $n;
}

/** 문자 종류: 'SMS' · 'LMS', 비었거나 2,000byte 초과면 null (순수 함수) */
function sms_message_type(string $text): ?string
{
    if (trim($text) === '') {
        return null;
    }
    $b = sms_byte_length($text);
    if ($b > SMS_MAX_LMS_BYTES) {
        return null;
    }
    return $b > SMS_MAX_SMS_BYTES ? 'LMS' : 'SMS';
}

/** 전화번호 숫자만 (순수 함수) */
function sms_digits(string $tel): string
{
    return (string) preg_replace('/\D+/', '', $tel);
}

/** 국내 전화번호(휴대폰·지역번호) 형식인지 — 숫자 9~11자리, 0으로 시작 (순수 함수) */
function sms_valid_phone(string $tel): bool
{
    return preg_match('/^0\d{8,10}$/', sms_digits($tel)) === 1;
}

/** 표시용 하이픈 번호 (순수 함수) — 01012345678 → 010-1234-5678, 0212345678 → 02-1234-5678 */
function sms_format_phone(string $tel): string
{
    $d = sms_digits($tel);
    if (preg_match('/^(02)(\d{3,4})(\d{4})$/', $d, $m) || preg_match('/^(0\d{2})(\d{3,4})(\d{4})$/', $d, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return $d;
}

/**
 * 발송 설정 (환경변수). ready=false 면 missing 에 빠진 키 이름(값 아님)
 * @return array{provider:string, ready:bool, missing:list<string>, sender:string, sender_name:string, test_mode:bool}
 */
function sms_config(): array
{
    $provider = strtolower(trim((string) getenv('SMS_PROVIDER')));
    $need = match ($provider) {
        'solapi' => ['SMS_API_KEY', 'SMS_API_SECRET', 'SMS_SENDER'],
        'aligo'  => ['SMS_API_KEY', 'SMS_USER_ID', 'SMS_SENDER'],
        default  => ['SMS_PROVIDER'],
    };
    $missing = array_values(array_filter($need, static fn($k) => trim((string) getenv($k)) === ''));
    $name = trim((string) getenv('SMS_SENDER_NAME'));
    return [
        'provider'    => in_array($provider, ['solapi', 'aligo'], true) ? $provider : '',
        'ready'       => $missing === [],
        'missing'     => $missing,
        'sender'      => sms_format_phone((string) getenv('SMS_SENDER')),
        'sender_name' => $name !== '' ? $name : SMS_DEFAULT_SENDER_NAME,
        'test_mode'   => strtoupper(trim((string) getenv('SMS_TEST_MODE'))) === 'Y',
    ];
}

/** 테스트 전용 API 주소 — 로컬 목업(http://127.0.0.1:포트/…)만, 아니면 null */
function sms_test_url(): ?string
{
    $u = (string) getenv('SMS_API_URL');
    return preg_match('#^http://127\.0\.0\.1:\d+/#', $u) ? $u : null;
}

/** 솔라피 HMAC-SHA256 인증 헤더 값 (순수 함수 — date·salt 를 넣으면 같은 결과) */
function sms_solapi_auth_header(string $apiKey, string $apiSecret, string $date, string $salt): string
{
    $sig = hash_hmac('sha256', $date . $salt, $apiSecret);
    return 'HMAC-SHA256 apiKey=' . $apiKey . ', date=' . $date . ', salt=' . $salt . ', signature=' . $sig;
}

/**
 * 솔라피 발송 본문 (순수 함수) — POST /messages/v4/send-many/detail, 공식 SDK(solapi/solapi-php)와 같은 형식
 * {"messages":[{to, from, text, type, subject?}], "allowDuplicates":false}
 */
function sms_solapi_body(string $to, string $from, string $text, string $type, string $subject): array
{
    $msg = ['to' => sms_digits($to), 'from' => sms_digits($from), 'text' => $text, 'type' => $type];
    if ($type === 'LMS' && $subject !== '') {
        $msg['subject'] = mb_substr($subject, 0, 20);
    }
    return ['messages' => [$msg], 'allowDuplicates' => false];
}

/** 알리고 발송 요청 값 (순수 함수) */
function sms_aligo_fields(string $apiKey, string $userId, string $to, string $from, string $text, string $type, string $subject, bool $testMode): array
{
    $f = [
        'key'      => $apiKey,
        'user_id'  => $userId,
        'sender'   => sms_digits($from),
        'receiver' => sms_digits($to),
        'msg'      => $text,
        'msg_type' => $type,
    ];
    if ($type === 'LMS' && $subject !== '') {
        $f['title'] = mb_substr($subject, 0, 20);
    }
    if ($testMode) {
        $f['testmode_yn'] = 'Y';
    }
    return $f;
}

/**
 * 발송 결과 해석 (순수 함수) — 업체 응답(HTTP 코드·본문)을 같은 형식으로
 * @return array{ok:bool, error:?string, code:string}
 */
function sms_parse_response(string $provider, int $http, string $raw): array
{
    $d = json_decode($raw, true);
    if ($provider === 'solapi') {
        // send-many/detail(공식 SDK 기준): 접수 성공 = HTTP 200 + failedMessageList 비어 있음(groupInfo 있음).
        // 접수 거절된 메시지는 failedMessageList[].statusCode/statusMessage(발신번호 미등록 등).
        // 요청 자체 오류: 4xx + errorCode/errorMessage(서명·키·잔액 등).
        if ($http === 200 && is_array($d) && isset($d['groupInfo'])) {
            $failed = is_array($d['failedMessageList'] ?? null) ? $d['failedMessageList'] : [];
            if ($failed === []) {
                return ['ok' => true, 'error' => null, 'code' => ''];
            }
            $f = is_array($failed[0]) ? $failed[0] : [];
            return ['ok' => false, 'error' => (string) ($f['statusMessage'] ?? '') ?: '발송 접수 거절', 'code' => (string) ($f['statusCode'] ?? '')];
        }
        $code = is_array($d) ? (string) ($d['errorCode'] ?? '') : '';
        $msg  = is_array($d) ? (string) ($d['errorMessage'] ?? '') : '';
        return ['ok' => false, 'error' => $msg !== '' ? $msg : '발송 업체 응답 오류(HTTP ' . $http . ')', 'code' => $code];
    }
    if ($provider === 'aligo') {
        // 성공: result_code 1(숫자 또는 문자열). 실패: 음수 코드 + message
        $code = is_array($d) ? (string) ($d['result_code'] ?? '') : '';
        if ($http === 200 && $code === '1') {
            return ['ok' => true, 'error' => null, 'code' => $code];
        }
        $msg = is_array($d) ? (string) ($d['message'] ?? '') : '';
        return ['ok' => false, 'error' => $msg !== '' ? $msg : '발송 업체 응답 오류(HTTP ' . $http . ')', 'code' => $code];
    }
    return ['ok' => false, 'error' => '문자 발송 설정이 없습니다.', 'code' => ''];
}

/**
 * 문자 1건 발송
 * @return array{ok:bool, error:?string, type:?string}  error 는 관리자 화면에 보여도 되는 문장(키·번호 없음)
 */
function sms_send(string $to, string $text, string $subject = ''): array
{
    $text = str_replace("\r\n", "\n", $text);
    $type = sms_message_type($text);
    if ($type === null) {
        return ['ok' => false, 'error' => trim($text) === '' ? '보낼 내용을 입력해 주세요.' : '내용이 2,000byte를 넘습니다.', 'type' => null];
    }
    if (!sms_valid_phone($to)) {
        return ['ok' => false, 'error' => '받는 번호 형식이 올바르지 않습니다.', 'type' => $type];
    }
    $cfg = sms_config();
    if (!$cfg['ready']) {
        error_log('[sms] 발송 설정 없음: ' . implode(', ', $cfg['missing']));
        return ['ok' => false, 'error' => '문자 발송 설정이 아직 없습니다. 관리자에게 문의해 주세요.', 'type' => $type];
    }
    $from = (string) getenv('SMS_SENDER');
    $key  = trim((string) getenv('SMS_API_KEY'));
    $test = sms_test_url();

    if ($cfg['provider'] === 'solapi') {
        $date = gmdate('Y-m-d\TH:i:s\Z');
        $salt = bin2hex(random_bytes(16));
        $url  = $test ?? 'https://api.solapi.com/messages/v4/send-many/detail';
        $body = json_encode(sms_solapi_body($to, $from, $text, $type, $subject), JSON_UNESCAPED_UNICODE);
        $headers = [
            'Content-Type: application/json; charset=utf-8',
            'Authorization: ' . sms_solapi_auth_header($key, trim((string) getenv('SMS_API_SECRET')), $date, $salt),
        ];
    } else {
        $url  = $test ?? 'https://apis.aligo.in/send/';
        $body = http_build_query(sms_aligo_fields($key, trim((string) getenv('SMS_USER_ID')), $to, $from, $text, $type, $subject, $cfg['test_mode']));
        $headers = ['Content-Type: application/x-www-form-urlencoded; charset=utf-8'];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 15,
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    $http  = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno || !is_string($raw)) {
        error_log('[sms] ' . $cfg['provider'] . ' 접속 실패(curl ' . $errno . ')');
        return ['ok' => false, 'error' => '문자 발송 서버에 연결하지 못했습니다. 잠시 후 다시 시도해 주세요.', 'type' => $type];
    }
    $r = sms_parse_response($cfg['provider'], $http, $raw);
    if (!$r['ok']) {
        error_log('[sms] ' . $cfg['provider'] . ' 발송 실패(HTTP ' . $http . ', 코드 ' . $r['code'] . ')');
    }
    return ['ok' => $r['ok'], 'error' => $r['ok'] ? null : '문자 발송 실패: ' . mb_substr((string) $r['error'], 0, 120), 'type' => $type];
}
