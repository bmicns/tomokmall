<?php
declare(strict_types=1);
/**
 * 비회원 AI 상담 이전 기록 (사용자 결정 2026-11-26, data-decisions A12 · docs/03-analysis/guest-chat-history-2026-11-26.md)
 *
 * 쿠키 `tm_guest` 에는 무작위 토큰만, DB(ai_sessions.guest_hash)에는 그 토큰의 SHA-256 해시만 둔다(대화 내용은 서버에만).
 * 기록을 열 때마다 주인 확인: 회원 = user_id, 비회원 = user_id IS NULL AND guest_hash = 지금 쿠키 해시. 상담 번호(sid)만으로는 열리지 않는다.
 * 보관: 비회원 대화는 마지막 대화 후 30일, chat_logs 는 1년(저장할 때 가끔 정리 — 닷홈 예약 작업이 확실하지 않아서).
 * 로그인하면 이 기기의 비회원 대화를 묻지 않고 회원 계정으로 옮기고 토큰을 지운다. 로그아웃 때도 토큰을 지운다.
 * guest_hash 칸이 없으면(DB 반영 전) 모든 함수가 지금 동작 그대로(비회원 기록 없음).
 *
 * 함수:
 *  chat_guest_ready()        — ai_sessions.guest_hash 칸이 있는지(요청마다 한 번 조회)
 *  chat_guest_token()        — 쿠키 토큰 읽기(형식 검사), $create 면 없을 때 새로 발급, 있으면 30일 연장
 *  chat_guest_hash()         — 지금 방문자의 토큰 해시(없으면 null)
 *  chat_guest_forget_cookie()— 토큰 쿠키 지우기
 *  chat_guest_claim()        — 로그인 때 이 기기의 비회원 대화를 회원 계정으로 옮기기
 *  chat_guest_forget()       — '이 기기 상담 기록 지우기': 이 토큰의 비회원 대화 삭제 + 쿠키 삭제
 *  chat_guest_purge()        — 보관 기간이 지난 비회원 대화(30일)·상담 로그(1년) 정리(가끔, 한 번에 일부)
 */

const CHAT_GUEST_COOKIE    = 'tm_guest';
const CHAT_GUEST_DAYS      = 30;    // 쿠키 기한 = 비회원 대화 보관 기간(마지막 대화 기준)
const CHAT_LOG_KEEP_DAYS   = 365;   // chat_logs 보관(관리자 분석용)
const CHAT_GUEST_PURGE_PCT = 1;     // 저장 100번에 1번쯤 정리
const CHAT_GUEST_PURGE_MAX = 200;   // 한 번에 지우는 최대 행

/** 토큰 형식: random_bytes(32) 의 base64url(43자) */
function chat_guest_token_valid(string $t): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_-]{43}$/', $t);
}

function chat_guest_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    $pdo = db();
    if (!$pdo) {
        return $ready = false;
    }
    try {
        $st = $pdo->query("SHOW COLUMNS FROM ai_sessions LIKE 'guest_hash'");
        $ready = $st !== false && $st->fetch() !== false;
    } catch (\Throwable $e) {
        $ready = false;
    }
    return $ready;
}

function chat_guest_set_cookie(string $value, int $expires): void
{
    if (headers_sent()) {
        return;
    }
    setcookie(CHAT_GUEST_COOKIE, $value, [
        'expires'  => $expires,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),   // bootstrap.php 세션 쿠키와 같은 규칙(https 일 때만)
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

/** 비회원 토큰 — 쿠키 값이 형식에 맞을 때만. $create 면 없을 때 새로 만들고, 있으면 기한을 다시 30일로. */
function chat_guest_token(bool $create = false): ?string
{
    static $issued = null;   // 이 요청에서 새로 만든 토큰(쿠키는 다음 요청부터 오므로)
    $t = $issued ?? (string) ($_COOKIE[CHAT_GUEST_COOKIE] ?? '');
    if (!chat_guest_token_valid($t)) {
        if (!$create) {
            return null;
        }
        $t = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }
    if ($create) {
        $issued = $t;
        $_COOKIE[CHAT_GUEST_COOKIE] = $t;
        chat_guest_set_cookie($t, time() + CHAT_GUEST_DAYS * 86400);
    }
    return $t;
}

function chat_guest_hash_of(string $token): string
{
    return hash('sha256', $token);
}

/** 지금 방문자의 토큰 해시. 칸이 없거나 토큰이 없으면 null */
function chat_guest_hash(bool $create = false): ?string
{
    if (!chat_guest_ready()) {
        return null;
    }
    $t = chat_guest_token($create);
    return $t === null ? null : chat_guest_hash_of($t);
}

function chat_guest_forget_cookie(): void
{
    unset($_COOKIE[CHAT_GUEST_COOKIE]);
    chat_guest_set_cookie('', time() - 3600);
}

/**
 * 로그인 때 — 이 기기 토큰의 비회원 대화를 회원 계정으로 옮김(사용자 결정: 묻지 않고 옮김). 같은 상담의 chat_logs 도 회원으로.
 * @return int 옮긴 대화 수
 */
function chat_guest_claim(int $userId): int
{
    $h = chat_guest_hash();
    if ($userId <= 0 || $h === null) {
        if ($userId > 0) { chat_guest_forget_cookie(); }
        return 0;
    }
    $pdo = db();
    $moved = 0;
    try {
        $st = $pdo->prepare('SELECT session_key FROM ai_sessions WHERE guest_hash = :h AND user_id IS NULL');
        $st->execute(['h' => $h]);
        $keys = array_map('strval', $st->fetchAll(PDO::FETCH_COLUMN));
        if ($keys) {
            $pdo->beginTransaction();
            $up = $pdo->prepare('UPDATE ai_sessions SET user_id = :uid, guest_hash = NULL WHERE guest_hash = :h AND user_id IS NULL');
            $up->execute(['uid' => $userId, 'h' => $h]);
            $moved = $up->rowCount();
            $in = implode(',', array_fill(0, count($keys), '?'));
            try {
                $pdo->prepare("UPDATE chat_logs SET user_id = ? WHERE user_id IS NULL AND session_key IN ($in)")
                    ->execute(array_merge([$userId], $keys));
            } catch (\Throwable $e) {
                error_log('chat guest claim logs: ' . $e->getMessage());   // chat_logs 가 없어도 대화는 옮김
            }
            $pdo->commit();
        }
    } catch (\Throwable $e) {
        if ($pdo && $pdo->inTransaction()) { $pdo->rollBack(); }
        error_log('chat guest claim: ' . $e->getMessage());   // 로그인은 그대로 성공
    }
    chat_guest_forget_cookie();
    return $moved;
}

/** '이 기기 상담 기록 지우기' — 이 토큰의 비회원 대화 삭제(상담 로그는 보관 기간까지 남김) + 쿠키 삭제 */
function chat_guest_forget(): int
{
    $h = chat_guest_hash();
    $n = 0;
    if ($h !== null) {
        try {
            $st = db()->prepare('DELETE FROM ai_sessions WHERE guest_hash = :h AND user_id IS NULL');
            $st->execute(['h' => $h]);
            $n = $st->rowCount();
        } catch (\Throwable $e) {
            error_log('chat guest forget: ' . $e->getMessage());
        }
    }
    chat_guest_forget_cookie();
    return $n;
}

/** 보관 기간 지난 기록 정리 — $force 가 아니면 저장 100번에 1번쯤, 한 번에 최대 200행씩 */
function chat_guest_purge(bool $force = false): array
{
    $done = ['sessions' => 0, 'logs' => 0];
    if (!chat_guest_ready() || (!$force && random_int(1, 100) > CHAT_GUEST_PURGE_PCT)) {
        return $done;
    }
    $pdo = db();
    try {
        $st = $pdo->prepare('DELETE FROM ai_sessions WHERE user_id IS NULL AND updated_at < (NOW() - INTERVAL ' . CHAT_GUEST_DAYS . ' DAY) LIMIT ' . CHAT_GUEST_PURGE_MAX);
        $st->execute();
        $done['sessions'] = $st->rowCount();
        $st = $pdo->prepare('DELETE FROM chat_logs WHERE created_at < (NOW() - INTERVAL ' . CHAT_LOG_KEEP_DAYS . ' DAY) LIMIT ' . CHAT_GUEST_PURGE_MAX);
        $st->execute();
        $done['logs'] = $st->rowCount();
    } catch (\Throwable $e) {
        error_log('chat guest purge: ' . $e->getMessage());
    }
    return $done;
}
