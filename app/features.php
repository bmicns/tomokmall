<?php
declare(strict_types=1);
/**
 * 기능 스위치 (app/features.php) — 새 기능을 운영에 반영한 뒤 아쉬우면 이 파일의 값만 false 로 바꿔
 * 반영 전 동작으로 바로 되돌린다(파일 1개 원격 배포 또는 FileZilla 업로드, DB 변경 없음). 사용자 요청 2026-11-23.
 *
 * 금지: 시크릿·환경별 값 넣기(.env 사용). 스위치를 끈 상태에서 기존 동작과 달라지는 코드를 넣지 않는다.
 */

// AI 상담 견적 카드(A8, B안) — false: 반영 전처럼(담기 전 묻기·배송 지역·update_cart·견적 카드·견적 요청 화면 지역 미리 선택 모두 없음)
const FEATURE_AI_QUOTE_CARD = true;

/**
 * AI 상담 견적 카드 켜짐 여부. 테스트는 $set 으로 이 요청 동안만 바꿀 수 있다.
 */
function ai_quote_card_enabled(?bool $set = null): bool
{
    static $override = null;
    if ($set !== null) { $override = $set; }
    return $override ?? FEATURE_AI_QUOTE_CARD;
}

// 우측 하단 'AI 빠른 견적' 창(케이토목 1분 자동견적과 비슷한 짧은 상담 — 지금 AI 상담과 비교용, 사용자 결정 2026-11-23)
// 'off' = 안 보임 | 'staff' = 관리자·직원만 | 'all' = 모든 방문자. 견적 카드 스위치(FEATURE_AI_QUOTE_CARD)가 꺼져 있으면 보이지 않는다.
// 2026-11-26 사용자 결정: 기존 AI 상담과 함께 쓰기로 — 비로그인·개인·기업회원 모두에게(되돌리려면 'staff')
const FEATURE_AI_QUICK_CHAT = 'all';

/**
 * 이 사용자에게 우측 하단 빠른 견적 창을 보여 줄지. 테스트는 $setMode 로 이 요청 동안만 바꿀 수 있다.
 */
function ai_quick_chat_visible(?array $user, ?string $setMode = null): bool
{
    static $override = null;
    if ($setMode !== null) { $override = $setMode; }
    $mode = $override ?? FEATURE_AI_QUICK_CHAT;
    if (!ai_quote_card_enabled()) { return false; }
    return match ($mode) {
        'all'   => true,
        'staff' => in_array((string) ($user['role'] ?? ''), ['staff', 'admin'], true),
        default => false,
    };
}

// 빠른 견적 창 '견적서 사진 첨부'(A10, 사용자 결정 2026-11-24 — 안내·동의 후 사진을 OpenAI 로 보내 품목만 읽음, 사진 저장 안 함)
// false: 창에 사진 첨부 버튼이 안 보이고 서버도 사진을 받지 않는다(글 질문은 그대로)
const FEATURE_AI_QUICK_PHOTO = true;

/**
 * 빠른 견적 창 사진 첨부 켜짐 여부. 테스트는 $set 으로 이 요청 동안만 바꿀 수 있다.
 */
function ai_quick_photo_enabled(?bool $set = null): bool
{
    static $override = null;
    if ($set !== null) { $override = $set; }
    return $override ?? FEATURE_AI_QUICK_PHOTO;
}
