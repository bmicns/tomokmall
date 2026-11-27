<?php
declare(strict_types=1);

/**
 * 빠른 상담 창 — 필수 정보를 묻는 첫 질문을 기존 AI 상담(/chat.php)과 같은 형식으로 고정 (사용자 요청 2026-11-26).
 *
 * GPT 가 도구 없이 질문만 했을 때(빠른 상담은 '한 줄만 묻기' 라 설치 길이 줄 구성·폭 예시가 빠지던 문제) 그 질문을 이 문장으로 바꾼다:
 *   "알맞은 그레이팅을 고르려면 다음 정보를 알려 주세요.\n\n- 덮을 배수로의 폭(mm)을 알려 주세요. (예: 300mm)\n- 설치 길이(m)…\n- 통행하는 차량…"
 * 고객이 이미 말한 항목은 빼고(중복 질문 없음 — A13 질문 방식), 빠진 항목이 없으면 null(GPT 답 그대로).
 * 설치 길이를 하나만 말했으면 다시 묻지 않고 '한 줄로 계산' 안내만 한 줄 붙인다(A13 — 길이만 답하면 한 줄 기준).
 * 가격·검색·담기에는 관여하지 않는다(질문 글만). 스위치: FEATURE_AI_INSTALL_RUNS(끄면 쓰지 않음 — rag.php).
 */

require_once __DIR__ . '/install_runs.php';

/** 설치 길이 질문 문장(A13 — RAG_QUESTION_STYLE_PROMPT 의 [설치 길이 질문]과 같은 문장) */
const QUICK_FQ_LENGTH = '설치 길이(m)를 알려 주세요. 여러 줄을 설치하는 경우 각 줄의 길이를 알려 주세요. (예: 10m씩 2줄 또는 10m, 15m)';

/**
 * 자재별 필수 항목 — [이름, 그 항목을 묻는 문장]. 순서 = 질문 순서(기존 상담 [필수 확인 항목]과 같은 내용).
 * @var array<string, array{0:string, 1:list<array{0:string,1:string}>}>
 */
const QUICK_FQ_ITEMS = [
    'grating'  => ['그레이팅', [['width', '덮을 배수로의 폭(mm)을 알려 주세요. (예: 300mm)'], ['length', QUICK_FQ_LENGTH], ['vehicle', '통행하는 차량의 최대 톤수를 알려 주세요. (예: 승용차, 트럭, 버스)']]],
    'culvert'  => ['수로관', [['width', '수로관의 폭(mm)을 알려 주세요. (예: 300mm)'], ['length', QUICK_FQ_LENGTH]]],
    'pe_pipe'  => ['관', [['place', '설치 장소를 알려 주세요. (예: 농경지, 도로 측구, 건물 주변, 도로 횡단)'], ['width', '관경(mm)을 알려 주세요. (예: 300mm)'], ['length', QUICK_FQ_LENGTH]]],
    'curb'     => ['경계석', [['place', '설치 장소와 차량이 닿는 곳인지 알려 주세요. (예: 차도·주차장 경계 / 인도·화단·마당 테두리)'], ['length', QUICK_FQ_LENGTH]]],
    'pavement' => ['블록', [['place', '설치 장소와 차량이 올라가는지 알려 주세요. (예: 집 앞 마당·주차장 / 인도·공원)'], ['area', '설치 면적(㎡)을 알려 주세요. 모르시면 가로·세로 길이(m)를 알려 주세요. (예: 5m × 4m)']]],
];

/** 고객 말들 이어 붙이기 (순수 함수) */
function quick_fq_user_text(array $messages): string
{
    $t = '';
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'user') { $t .= ' ' . (string) ($m['content'] ?? ''); }
    }
    return trim($t);
}

/** 폭·관경 숫자를 말했는지 (순수 함수) — 길이(m)·개수·톤·면적 숫자는 빼고 남는 50 이상 숫자, 또는 '폭 300'·'300mm'·'300×1000' */
function quick_fq_has_width(string $t): bool
{
    if (preg_match('/(폭|너비|관경|내경|지름|호칭|φ|Φ|d)\s*\d/iu', $t) === 1 || preg_match('/\d\s*(mm|㎜|미리)/iu', $t) === 1 || preg_match('/\d\s*[×x*]\s*\d/u', $t) === 1) {
        return true;
    }
    $rest = preg_replace('/\d+(?:\.\d+)?\s*(?:m(?!m)|미터|메타|개|본|줄|톤|t\b|평|㎡|m2|제곱|kg|원|%|층|대|곳|군데|일|월|년|시)/iu', ' ', $t) ?? $t;
    return preg_match('/(?<![\d.])(\d{2,4})(?![\d.])/u', $rest, $m) === 1 && (int) $m[1] >= 50;
}

/** 설치 길이를 말했는지 → null(안 말함) | ['single'=>float] | ['runs'=>…] (순수 함수) */
function quick_fq_length(string $t): ?array
{
    if (($r = install_runs_parse($t)) !== null) { return ['runs' => $r]; }
    if (preg_match('/(\d+(?:\.\d+)?)\s*(?:m(?!m|2|²)|미터|메타)/iu', $t, $m) === 1 && (float) $m[1] > 0) { return ['single' => (float) $m[1]]; }
    return null;
}

/** 설치 장소를 말했는지 (순수 함수) */
function quick_fq_has_place(string $t): bool
{
    $t = preg_replace('/보도블[록럭]|보차도블[록럭]|배수로\s*그레이팅/u', ' ', $t) ?? $t;   // 품명 안의 '보도'는 장소가 아님
    return preg_match('/차도|도로|주차장|인도|보도|화단|마당|정원|테두리|공원|광장|농경지|논|밭|측구|건물|횡단|공장|단지|아파트|주택|진입로|학교|하천|옹벽|배수로|축사|창고/u', $t) === 1;
}

/** 면적을 말했는지 (순수 함수) */
function quick_fq_has_area(string $t): bool
{
    return preg_match('/㎡|m2|m²|제곱|평(?![가-힣])|\d\s*m?\s*[×x*]\s*\d/iu', $t) === 1;
}

/**
 * 빠른 상담 첫 질문(고정 문장) — 빠진 필수 항목이 없거나 해당 자재가 아니면 null (순수 함수).
 * @param string $category rag_detect_category() 값 + 'curb'(경계석만 상담)
 * @param bool   $vehicleKnown 차량 통행 조건을 고객이 분명히 말함(rag_traffic_info strength 'strong' — '주차장'처럼 장소로 짐작한 것은 아님)
 */
function quick_first_question(string $category, array $messages, bool $vehicleKnown): ?string
{
    if (!isset(QUICK_FQ_ITEMS[$category])) { return null; }
    $t = quick_fq_user_text($messages);
    if ($category === 'grating' && preg_match('/집수정|맨홀|우물통/u', $t) === 1) { return null; }   // 집수정 위 덮개는 가로×세로 규격·수량(길이 자재가 아님)
    [$name, $items] = QUICK_FQ_ITEMS[$category];
    if (preg_match('/흄관|이중벽관|다중벽관|파형강관|유공관|우수관|하수관|배수관|보도블[록럭]|인터로킹\s*블[록럭]|점토블[록럭]|잔디블[록럭]|U형\s*수로관|측구\s*수로관/iu', $t, $nm) === 1
        && in_array($category, ['pe_pipe', 'pavement', 'culvert'], true)) {
        $name = (string) preg_replace('/\s+/u', ' ', $nm[0]);   // 고객이 말한 품명 그대로('흄관'·'보도블록')
    }
    $len   = quick_fq_length($t);
    $lines = [];
    $asked = 0;
    foreach ($items as [$key, $q]) {
        $known = match ($key) {
            'width'   => quick_fq_has_width($t),
            'length'  => $len !== null,
            'vehicle' => $vehicleKnown,
            'place'   => quick_fq_has_place($t) || ($category === 'curb' && $vehicleKnown),
            'area'    => quick_fq_has_area($t),
            default   => true,
        };
        if (!$known) {
            $lines[] = '- ' . $q;
            $asked++;
        } elseif ($key === 'length' && isset($len['single'])) {   // 길이 하나만 말함 → 다시 묻지 않고 한 줄로 계산한다는 안내만(같은 자리에)
            $lines[] = '- 설치 길이 ' . spec_axis_num($len['single']) . 'm는 한 줄로 계산합니다. 여러 줄이면 각 줄의 길이를 알려 주세요. (예: 10m씩 2줄 또는 10m, 15m)';
        }
    }
    if ($asked === 0) { return null; }
    return "알맞은 {$name}" . (preg_match('/[가-힣]$/u', $name) === 1 && ((mb_ord(mb_substr($name, -1)) - 0xAC00) % 28) !== 0 ? '을' : '를')
        . " 고르려면 다음 정보를 알려 주세요.\n\n" . implode("\n", $lines);
}
