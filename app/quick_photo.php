<?php
declare(strict_types=1);
/**
 * 빠른 견적 창 '견적서 사진 첨부' (app/quick_photo.php) — 사용자 결정 2026-11-24 A10(안내·동의 후 그대로 보내기)
 *
 * 책임: 손님이 올린 견적서·자재 목록 사진을 확인(jpg·png, 10MB)하고, OpenAI(gpt-4o)로 품목·규격·수량만 읽어
 *       빠른 견적 창이 찾고 담을 수 있는 글('[견적서 사진에서 읽은 품목]')로 바꾼다.
 * 지키는 것: 사진은 서버에 저장하지 않는다(요청 메모리에서만 쓰고 버림). 이름·상호·전화번호·주소·이메일은 읽지 않게 지시하고,
 *       돌아온 글에서도 전화번호·이메일 모양은 지운다. 손님 동의(consent) 없는 요청은 받지 않는다(호출부).
 * 금지: 가격 읽기·쓰기(사진 속 단가는 쓰지 않음 — 가격은 우리 상품 가격으로만), 사진·원문 저장·로그.
 */

const QUICK_PHOTO_MAX_BYTES = 10 * 1024 * 1024;
const QUICK_PHOTO_MAX_ITEMS = 10;
const QUICK_PHOTO_PREFIX    = '[견적서 사진에서 읽은 품목]';
const QUICK_PHOTO_PROMPT    = "건축·토목 자재 견적서 또는 자재 목록 사진이다. 품목만 읽어 JSON 으로 답한다: "
    . "{\"items\":[{\"name\":\"품명\",\"spec\":\"규격(치수·등급 그대로)\",\"qty\":수량 숫자 또는 null,\"unit\":\"단위\",\"unclear\":읽기 애매하면 true}],\"note\":\"\"}. "
    . "사람 이름·상호·회사명·전화번호·팩스·주소·이메일·사업자번호·계좌·도장은 절대 적지 않는다. 단가·금액·합계도 적지 않는다. "
    . "견적서·자재 목록이 아니면 items 를 빈 배열로 하고 note 에 '자재 목록이 아닙니다'. 품목은 많아도 " . QUICK_PHOTO_MAX_ITEMS . "개까지.";

/**
 * base64(또는 data URL) → 확인된 이미지 [바이트, MIME] 또는 오류 문장 (순수 함수).
 * @return array{ok:bool, bytes?:string, mime?:string, error?:string}
 */
function quick_photo_decode(string $data): array
{
    if (preg_match('#^data:image/[a-z]+;base64,#i', $data)) {
        $data = (string) substr($data, (int) strpos($data, ',') + 1);
    }
    if ($data === '' || strlen($data) > (int) ceil(QUICK_PHOTO_MAX_BYTES * 4 / 3) + 8) {
        return ['ok' => false, 'error' => '사진은 10MB 이하로 올려 주세요.'];
    }
    $bytes = base64_decode($data, true);
    if ($bytes === false || $bytes === '') {
        return ['ok' => false, 'error' => '사진을 읽을 수 없습니다. 다시 올려 주세요.'];
    }
    if (strlen($bytes) > QUICK_PHOTO_MAX_BYTES) {
        return ['ok' => false, 'error' => '사진은 10MB 이하로 올려 주세요.'];
    }
    $info = @getimagesizefromstring($bytes);
    $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
    if (!in_array($mime, ['image/jpeg', 'image/png'], true)) {
        return ['ok' => false, 'error' => 'jpg·png 사진만 올릴 수 있습니다.'];
    }
    return ['ok' => true, 'bytes' => $bytes, 'mime' => $mime];
}

/** 읽은 글에서 개인정보 모양(전화·이메일·사업자번호) 지우기 (순수 함수) */
function quick_photo_scrub(string $s): string
{
    $s = (string) preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/u', '', $s);
    $s = (string) preg_replace('/\b0\d{1,2}[-.\s)]?\d{3,4}[-.\s]?\d{4}\b/u', '', $s);   // 전화·팩스
    $s = (string) preg_replace('/\b\d{3}-\d{2}-\d{5}\b/u', '', $s);                      // 사업자번호
    return trim((string) preg_replace('/\s{2,}/u', ' ', $s));
}

/**
 * OpenAI 가 돌려준 JSON 글 → 빠른 견적 창 질문 글 (순수 함수).
 * @return array{ok:bool, text?:string, count?:int, error?:string}
 */
function quick_photo_items_text(string $json): array
{
    $d = json_decode($json, true);
    $items = is_array($d) && is_array($d['items'] ?? null) ? $d['items'] : null;
    if ($items === null) {
        return ['ok' => false, 'error' => '사진에서 품목을 읽지 못했습니다. 더 밝고 반듯하게 찍어 다시 올려 주세요.'];
    }
    $lines = [];
    foreach ($items as $it) {
        if (!is_array($it) || count($lines) >= QUICK_PHOTO_MAX_ITEMS) { continue; }
        $name = mb_substr(quick_photo_scrub((string) ($it['name'] ?? '')), 0, 60);
        if ($name === '') { continue; }
        $spec = mb_substr(quick_photo_scrub((string) ($it['spec'] ?? '')), 0, 60);
        $qty  = is_numeric($it['qty'] ?? null) && (float) $it['qty'] > 0 ? rtrim(rtrim(number_format((float) $it['qty'], 2, '.', ''), '0'), '.') : '';
        $unit = mb_substr(quick_photo_scrub((string) ($it['unit'] ?? '')), 0, 8);
        $lines[] = (count($lines) + 1) . ') ' . $name . ($spec !== '' ? ' ' . $spec : '')
            . ($qty !== '' ? ' · 수량 ' . $qty . $unit : ' · 수량 모름')
            . (!empty($it['unclear']) ? ' (읽기 애매함)' : '');
    }
    if (!$lines) {
        return ['ok' => false, 'error' => '사진에서 자재 품목을 찾지 못했습니다. 견적서나 자재 목록 사진인지 확인해 주세요.'];
    }
    return ['ok' => true, 'text' => QUICK_PHOTO_PREFIX . "\n" . implode("\n", $lines), 'count' => count($lines)];
}

/**
 * 사진 → 품목 JSON 글 (OpenAI 1회, 사진은 이 요청에만 쓰고 버림). 실패 시 null.
 */
function quick_photo_read(string $bytes, string $mime): ?string
{
    global $config;
    $apiKey = $config['openai']['api_key'] ?? '';
    $url    = $config['openai']['chat_url'] ?? 'https://api.openai.com/v1/chat/completions';
    if ($apiKey === '') { return null; }
    $body = json_encode([
        'model'           => 'gpt-4o',
        'response_format' => ['type' => 'json_object'],
        'max_tokens'      => 800,
        'messages'        => [[
            'role'    => 'user',
            'content' => [
                ['type' => 'text', 'text' => QUICK_PHOTO_PROMPT],
                ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . base64_encode($bytes), 'detail' => 'high']],
            ],
        ]],
    ], JSON_UNESCAPED_UNICODE);
    $raw = rag_curl_post($url, (string) $body, ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
    $d   = $raw === false ? null : json_decode($raw, true);
    $txt = $d['choices'][0]['message']['content'] ?? null;
    return is_string($txt) && $txt !== '' ? $txt : null;
}
