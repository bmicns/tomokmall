<?php
declare(strict_types=1);
/**
 * POST /api/consult/request.php — 상담원 문의 요청 접수 (AI 상담 화면 '상담원 문의 요청' 모달)
 * 본문(JSON): { sid, name, tel, message, agree:true }
 *
 * 인증: 로그인 불필요(비회원 상담도 접수). sid 는 이 브라우저 세션에서 발급·복원한 상담 키만 연결(다른 사람 상담 연결 차단)
 * 검증: 이름 30자·연락처 형식·문의 500자·동의 필수, 세션당 10분 3건
 * 응답: { ok:true, id } | { ok:false, error }
 */

require __DIR__ . '/../../../app/bootstrap.php';
require __DIR__ . '/../../../app/auth.php';
require __DIR__ . '/../../../app/rag.php';
require __DIR__ . '/../../../app/consult.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$fail = static function (int $code, string $msg): never {
    // 오류도 HTTP 200 + ok:false — 닷홈 Apache 가 4xx 본문을 HTML 오류 페이지로 바꿔 문구가 사라지므로(quote.php 와 같은 방식).
    // $code 는 의미 구분용(서버 로그·향후 변경 대비)으로만 남긴다
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $fail(405, '허용되지 않는 메서드입니다.');
}
// JSON 본문만 받는다(다른 사이트의 일반 폼 전송 차단)
if (stripos((string) ($_SERVER['CONTENT_TYPE'] ?? ''), 'application/json') !== 0) {
    $fail(415, '잘못된 요청입니다.');
}
$body = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($body)) {
    $fail(400, '잘못된 요청입니다.');
}

[$err, $clean] = consult_validate_request($body);
if ($err !== null) {
    $fail(422, $err);
}

$sid = preg_replace('/[^a-zA-Z0-9_-]/', '', (string) ($body['sid'] ?? '')) ?? '';
if ($sid !== '' && !rag_chat_key_allowed($sid)) {
    $sid = ''; // 이 브라우저에서 연 상담이 아니면 대화는 연결하지 않고 문의만 접수
}

$pdo = db();
if (!$pdo) {
    $fail(503, '일시적으로 접수하지 못했습니다. 잠시 후 다시 시도해 주세요.');
}
if (!consult_rate_allow(time())) {
    $fail(429, '잠시 후 다시 요청해 주세요. 이미 접수된 문의는 상담원이 확인합니다.');
}

$u = current_user();
try {
    $id = consult_create($pdo, $clean, $sid, $u ? (int) $u['id'] : null);
} catch (\Throwable $e) {
    error_log('[consult] 접수 실패: ' . get_class($e) . ' ' . $e->getCode());
    $fail(500, '일시적으로 접수하지 못했습니다. 잠시 후 다시 시도해 주세요.');
}

echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
