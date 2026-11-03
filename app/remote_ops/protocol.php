<?php
declare(strict_types=1);
/**
 * 원격 관리 API 통신 규약 (app/remote_ops/protocol.php) — 서버(public/api/remote-ops.php)·CLI(scripts/remote-ops/tomok.php) 공용
 *
 * 책임: 키 파생, 요청·응답 봉투 만들기·검증
 *       - 서명: HMAC-SHA256(서명키, 정규 문자열)  → 위조·변조 차단
 *       - 암호화: AES-256-GCM(암호키, AAD=버전·방향·kid·시각·nonce·작업) → 프록시·로그에 SQL·파일 내용이 보이지 않게
 *       - 시각(ts)·nonce 는 서명 대상 → 재전송 방지는 서버가 ts 허용 범위 + nonce 1회 사용으로 판정
 * 금지: DB·파일 접근, 키 출력·기록(키는 인자로만 받고 반환값·오류문구에 넣지 않는다)
 *
 * 봉투(JSON): {"v":1,"kid":"k1","ts":1730600000,"nonce":"<32 hex>","op":"db.query",
 *              "enc":"aes-256-gcm"|"none","iv":"<b64>","ct":"<b64>","sig":"<b64>"}
 *   요청 평문: {"params":{...}}   응답 평문: {"ok":true,"result":...,"op_id":123} 또는 {"ok":false,"error":{"code":..,"message":..}}
 *   응답의 nonce 는 요청 nonce 를 그대로 돌려준다(응답 바꿔치기·재사용 차단).
 */

const ROPS_PROTO    = 'TOMOK-OPS-1';
const ROPS_ENC_GCM  = 'aes-256-gcm';
const ROPS_ENC_NONE = 'none';

/** 통신 규약 오류(서명 불일치 등). message 는 이유 코드(bad_request·bad_signature …) — 키·평문은 넣지 않는다. */
final class RopsProtoError extends \RuntimeException
{
}

/**
 * 16진수 마스터 키 → 바이트. 64자(32바이트) 이상 16진수만 허용.
 */
function rops_key_bytes(string $hex): string
{
    $hex = trim($hex);
    if (strlen($hex) < 64 || strlen($hex) % 2 !== 0 || !ctype_xdigit($hex)) {
        throw new RopsProtoError('bad_key_format');
    }
    return (string) hex2bin($hex);
}

/** 용도별 하위 키(HKDF-SHA256). 서명키와 암호키를 분리한다. */
function rops_derive(string $master, string $purpose): string
{
    return hash_hkdf('sha256', $master, 32, 'tomok-ops/v1/' . $purpose);
}

/** 서명 대상 정규 문자열. 필드 순서·구분자를 고정한다. */
function rops_canonical(string $dir, string $kid, int $ts, string $nonce, string $op, string $enc, string $iv, string $ct): string
{
    return implode("\n", [ROPS_PROTO, $dir, $kid, (string) $ts, $nonce, $op, $enc, $iv, $ct]);
}

function rops_aad(string $dir, string $kid, int $ts, string $nonce, string $op): string
{
    return implode('|', [ROPS_PROTO, $dir, $kid, (string) $ts, $nonce, $op]);
}

function rops_gcm_available(): bool
{
    return function_exists('openssl_encrypt') && in_array(ROPS_ENC_GCM, array_map('strtolower', openssl_get_cipher_methods()), true);
}

function rops_json(array $data): string
{
    $j = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRESERVE_ZERO_FRACTION);
    if ($j === false) {
        throw new RopsProtoError('json_encode_failed');
    }
    return $j;
}

/**
 * 봉투 만들기.
 * @param string $dir 'req'(요청) | 'res'(응답)
 * @return array<string,mixed>
 */
function rops_seal(string $master, string $dir, string $kid, int $ts, string $nonce, string $op, array $payload, string $enc): array
{
    $plain = rops_json($payload);
    if ($enc === ROPS_ENC_GCM) {
        if (!rops_gcm_available()) {
            throw new RopsProtoError('gcm_unavailable');
        }
        $ivRaw = random_bytes(12);
        $tag   = '';
        $ctRaw = openssl_encrypt($plain, ROPS_ENC_GCM, rops_derive($master, 'enc'), OPENSSL_RAW_DATA, $ivRaw, $tag, rops_aad($dir, $kid, $ts, $nonce, $op), 16);
        if ($ctRaw === false) {
            throw new RopsProtoError('encrypt_failed');
        }
        $iv = base64_encode($ivRaw);
        $ct = base64_encode($ctRaw . $tag);
    } elseif ($enc === ROPS_ENC_NONE) {
        $iv = '';
        $ct = base64_encode($plain);
    } else {
        throw new RopsProtoError('bad_enc');
    }
    $sig = base64_encode(hash_hmac('sha256', rops_canonical($dir, $kid, $ts, $nonce, $op, $enc, $iv, $ct), rops_derive($master, 'mac'), true));
    return ['v' => 1, 'kid' => $kid, 'ts' => $ts, 'nonce' => $nonce, 'op' => $op, 'enc' => $enc, 'iv' => $iv, 'ct' => $ct, 'sig' => $sig];
}

/**
 * 봉투 모양 검사(서명 확인 전). 문제가 있으면 이유 코드, 없으면 null.
 */
function rops_envelope_problem(mixed $e): ?string
{
    if (!is_array($e)) {
        return 'bad_request';
    }
    foreach (['v', 'kid', 'ts', 'nonce', 'op', 'enc', 'iv', 'ct', 'sig'] as $k) {
        if (!array_key_exists($k, $e)) {
            return 'bad_request';
        }
    }
    if ($e['v'] !== 1 || !is_int($e['ts'])) {
        return 'bad_request';
    }
    foreach (['kid', 'nonce', 'op', 'enc', 'iv', 'ct', 'sig'] as $k) {
        if (!is_string($e[$k])) {
            return 'bad_request';
        }
    }
    if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $e['kid'])
        || !preg_match('/^[0-9a-f]{32}$/', $e['nonce'])
        || !preg_match('/^[a-z]+(\.[a-z_]+){0,3}$/', $e['op'])
        || strlen($e['op']) > 40
        || !in_array($e['enc'], [ROPS_ENC_GCM, ROPS_ENC_NONE], true)) {
        return 'bad_request';
    }
    return null;
}

/**
 * 봉투 검증 + 복호화 → 평문 배열.
 * 서명 확인(상수 시간 비교)을 먼저 하고, 통과한 경우에만 복호화한다.
 * @param string $dir 기대 방향 'req' | 'res'
 * @return array<string,mixed>
 */
function rops_open(string $master, array $e, string $dir): array
{
    $p = rops_envelope_problem($e);
    if ($p !== null) {
        throw new RopsProtoError($p);
    }
    $expect = hash_hmac('sha256', rops_canonical($dir, $e['kid'], $e['ts'], $e['nonce'], $e['op'], $e['enc'], $e['iv'], $e['ct']), rops_derive($master, 'mac'), true);
    $given  = base64_decode($e['sig'], true);
    if ($given === false || !hash_equals($expect, $given)) {
        throw new RopsProtoError('bad_signature');
    }
    $ctRaw = base64_decode($e['ct'], true);
    if ($ctRaw === false) {
        throw new RopsProtoError('bad_request');
    }
    if ($e['enc'] === ROPS_ENC_GCM) {
        $ivRaw = base64_decode($e['iv'], true);
        if ($ivRaw === false || strlen($ivRaw) !== 12 || strlen($ctRaw) < 16 || !rops_gcm_available()) {
            throw new RopsProtoError('bad_request');
        }
        $plain = openssl_decrypt(substr($ctRaw, 0, -16), ROPS_ENC_GCM, rops_derive($master, 'enc'), OPENSSL_RAW_DATA, $ivRaw, substr($ctRaw, -16), rops_aad($dir, $e['kid'], $e['ts'], $e['nonce'], $e['op']));
        if ($plain === false) {
            throw new RopsProtoError('decrypt_failed');
        }
    } else {
        $plain = $ctRaw;
    }
    $data = json_decode($plain, true);
    if (!is_array($data)) {
        throw new RopsProtoError('bad_payload');
    }
    return $data;
}

function rops_new_nonce(): string
{
    return bin2hex(random_bytes(16));
}
