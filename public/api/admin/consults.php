<?php
declare(strict_types=1);
/**
 * 관리자 상담 요청 처리 API (admin-consult.php 에서 호출)
 * POST JSON + 헤더 X-CSRF-Token
 *   { action:'set_status', id, status:'pending'|'contact'|'closed' }   closed = 이름·연락처 파기(되돌릴 수 없음)
 *   { action:'send_sms',   id, text }                                  SMS(90byte)/LMS(2,000byte) 발송 → 처리 이력 기록
 *   { action:'save_memo',  id, memo }
 * 응답: { ok:true } | { ok:false, error, logged? }
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/consult.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$out = static function (int $code, array $body): never {
    // 오류도 HTTP 200 + ok:false — 닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 문구가 사라지므로(quote.php 와 같은 방식).
    // $code 는 의미 구분용(서버 로그·향후 변경 대비)으로만 남긴다
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
};

$admin = current_user();
if (!$admin || $admin['role'] !== 'admin') {
    $out(403, ['ok' => false, 'error' => '관리자만 사용할 수 있습니다. 다시 로그인해 주세요.']);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST'
    || stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    $out(405, ['ok' => false, 'error' => '잘못된 요청입니다.']);
}
if (!consult_csrf_valid($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)) {
    $out(403, ['ok' => false, 'error' => '화면이 오래되었습니다. 새로고침 후 다시 시도해 주세요.']);
}

$body = json_decode((string) file_get_contents('php://input'), true);
$body = is_array($body) ? $body : [];
$action  = (string) ($body['action'] ?? '');
$id      = (int) ($body['id'] ?? 0);
$adminId = (int) $admin['id'];

$pdo = db();
if (!$pdo) {
    $out(503, ['ok' => false, 'error' => '일시적으로 처리하지 못했습니다.']);
}

try {
    $req = $id > 0 ? consult_get($pdo, $id) : null;
    if (!$req) {
        $out(404, ['ok' => false, 'error' => '요청을 찾을 수 없습니다.']);
    }

    if ($action === 'set_status') {
        $err = consult_set_status($pdo, $id, (string) ($body['status'] ?? ''), $adminId);
        $out($err === null ? 200 : 422, $err === null ? ['ok' => true] : ['ok' => false, 'error' => $err]);
    }

    if ($action === 'send_sms') {
        $text = str_replace("\r\n", "\n", trim((string) ($body['text'] ?? '')));
        if ($req['status'] === 'closed' || (string) $req['tel'] === '') {
            $out(422, ['ok' => false, 'error' => '완료되어 연락처가 파기된 요청은 문자를 보낼 수 없습니다.']);
        }
        if (sms_message_type($text) === null) {
            $out(422, ['ok' => false, 'error' => $text === '' ? '보낼 내용을 입력해 주세요.' : '내용이 2,000byte를 넘습니다.']);
        }
        if (!sms_config()['ready']) {
            $out(422, ['ok' => false, 'error' => '문자 발송 설정이 아직 없습니다.']);
        }
        $r = sms_send((string) $req['tel'], $text, sms_config()['sender_name']);
        consult_record_sms($pdo, $id, $text, $r['type'], $r['ok'], preg_replace('/^문자 발송 실패:\s*/u', '', (string) $r['error']), $adminId);
        $out($r['ok'] ? 200 : 502, $r['ok'] ? ['ok' => true, 'type' => $r['type']] : ['ok' => false, 'error' => $r['error'], 'logged' => true]);
    }

    if ($action === 'save_memo') {
        $memo = trim(str_replace("\r\n", "\n", (string) ($body['memo'] ?? '')));
        if (mb_strlen($memo) > 2000) {
            $out(422, ['ok' => false, 'error' => '메모는 2,000자 이내로 입력해 주세요.']);
        }
        consult_save_memo($pdo, $id, $memo);
        $out(200, ['ok' => true]);
    }
} catch (\Throwable $e) {
    error_log('[admin-consults] ' . $action . ' 실패: ' . get_class($e) . ' ' . $e->getCode());
    $out(500, ['ok' => false, 'error' => '처리하지 못했습니다. 잠시 후 다시 시도해 주세요.']);
}

$out(400, ['ok' => false, 'error' => '알 수 없는 요청입니다.']);
