<?php
declare(strict_types=1);
/**
 * RAG (Retrieval-Augmented Generation) 핸들러
 *
 * 함수 목록:
 *  rag_retrieve()              — MySQL FULLTEXT 상품 검색
 *  rag_build_context()         — 검색 결과 → Claude 컨텍스트 문자열
 *  rag_stream()                — Claude 스트리밍 (기존, Tool Use 없음)
 *  rag_stream_with_tools()     — Claude 스트리밍 + Tool Use 루프 (신규)
 *  rag_load_session()          — AI 대화 이력 DB 로드
 *  rag_save_session()          — AI 대화 이력 DB 저장
 *  rag_search_legal_documents() — 검증된 공식 법령 근거 검색(출처·조항·적용 조건 포함)
 *  rag_extract_legal_citations() — AI 답변의 [L번호] 표시 → 실제 인용한 근거만 추출
 *  rag_road_context()          — 차량 블록 시공 장소 판정(공공도로 차도/공공 보도 차량 출입부/사유지/불명확)
 *  rag_road_context_prompt()   — 판정별 별도 안내 구역(사내 80T 안내 문구는 그대로 두고 공식 기준을 맥락별로 분리)
 *  rag_drain_notice_keys()     — 배수관 설치 위치·관경 주의 상황 판정(옹벽 배수관 / 공공 도로 횡단배수관)
 *  rag_drain_notice_prompt()   — 그 상황에서만 붙는 주의 안내 구역(문구는 사용자 확정, 원문 확인한 법령·기준)
 *  rag_build_prompt()          — 상담 시스템 프롬프트·근거 묶음 생성(API 호출 없음, rag_process 가 사용)
 *  rag_traffic_context()       — 고객이 말한 통행 조건(보행자/승용차/대형차) — 그레이팅 하중 등급 검색 보완
 *  rag_traffic_info()          — 통행 조건 + 확실성(분명한 말 strong / 장소로 짐작 weak) + 마지막 말에 새로 말했는지
 *  rag_wants_alternative()     — '다른 제품'·'A 말고'·'다시 추천'·'제외해줘' 요청인지 — 직전 추천표 상품 제외 검색 보완
 *  rag_wants_cheaper()         — '좀 더 저렴한 제품' 요청인지 — 직전 표와 비교할 수 있을 때만 금액 기준(rag_cheaper_bound)
 *  rag_explicit_conditions()   — 고객이 분명히 말한 폭·제품 1개 길이·설치 총 길이(말하지 않은 조건은 만들지 않음)
 *  rag_apply_explicit()        — GPT 검색 입력의 칸 혼동(제품 길이↔설치 길이, 폭→spec_mm)·빠진 조건을 고객 말로 바로잡기
 *  rag_prepare_search_input()  — search_products 입력 준비(통행·조건 유지·제외·더 저렴한·직전 표 재확인) — 바꾼 내용은 도구 기록(fills)
 */

require_once __DIR__ . '/ai_tools.php';

/**
 * MySQL FULLTEXT 검색으로 관련 상품 최대 $limit개 반환
 */
function rag_retrieve(string $query, int $limit = 15): array
{
    // 키워드 추출: 특수문자 제거, 공백 분리
    $keywords = preg_split('/\s+/', preg_replace('/[^\w가-힣\s]/u', ' ', $query));
    $keywords = array_values(array_filter($keywords, fn($k) => mb_strlen($k) >= 2));

    if (empty($keywords)) {
        // 키워드 없으면 최신 상품 반환
        return db()->query("SELECT p.*, c.name AS category_name
            FROM products p JOIN categories c ON c.id = p.category_id
            WHERE p.is_active = 1 ORDER BY p.id DESC LIMIT {$limit}")->fetchAll();
    }

    // 1단계: FULLTEXT AND (모든 키워드 필수)
    $ftAnd = implode(' ', array_map(fn($k) => "+{$k}", $keywords));
    try {
        $stmt = db()->prepare(
            "SELECT p.*, c.name AS category_name,
                    MATCH(p.name, p.spec, p.description) AGAINST(:ft1 IN BOOLEAN MODE) AS score
             FROM products p
             JOIN categories c ON c.id = p.category_id
             WHERE p.is_active = 1
               AND MATCH(p.name, p.spec, p.description) AGAINST(:ft2 IN BOOLEAN MODE)
             ORDER BY score DESC
             LIMIT :lim"
        );
        $stmt->bindValue(':ft1', $ftAnd, PDO::PARAM_STR);
        $stmt->bindValue(':ft2', $ftAnd, PDO::PARAM_STR);
        $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll();
    } catch (\Throwable $e) {
        $rows = [];
    }

    // 2단계: FULLTEXT OR (키워드 하나라도 포함)
    if (empty($rows)) {
        $ftOr = implode(' ', $keywords);
        try {
            $stmt = db()->prepare(
                "SELECT p.*, c.name AS category_name,
                        MATCH(p.name, p.spec, p.description) AGAINST(:ft1 IN BOOLEAN MODE) AS score
                 FROM products p
                 JOIN categories c ON c.id = p.category_id
                 WHERE p.is_active = 1
                   AND MATCH(p.name, p.spec, p.description) AGAINST(:ft2 IN BOOLEAN MODE)
                 ORDER BY score DESC
                 LIMIT :lim"
            );
            $stmt->bindValue(':ft1', $ftOr, PDO::PARAM_STR);
            $stmt->bindValue(':ft2', $ftOr, PDO::PARAM_STR);
            $stmt->bindValue(':lim', $limit, PDO::PARAM_INT);
            $stmt->execute();
            $rows = $stmt->fetchAll();
        } catch (\Throwable $e) {
            $rows = [];
        }
    }

    // LIKE 폴백 제거: 관련도 낮은 결과 방지 — 검색 실패 시 빈 배열 반환

    return $rows;
}

/**
 * 상품 목록을 Claude 컨텍스트 문자열로 변환
 */
function rag_build_context(array $products): string
{
    if (empty($products)) {
        return '현재 데이터베이스에 관련 상품이 없습니다.';
    }

    $lines = ["[참조 가능한 상품 목록 - {$products[0]['category_name']} 등 " . count($products) . "개]"];

    foreach ($products as $i => $p) {
        $line = sprintf(
            "%d. [%s] %s | 규격: %s | 가격: %s원",
            $i + 1,
            $p['category_name'],
            $p['name'],
            $p['spec'] ?: '–',
            number_format((int) $p['price_b2c'])
        );
        if ($p['description']) {
            $line .= " | 설명: " . mb_substr($p['description'], 0, 80);
        }
        $lines[] = $line;
    }

    return implode("\n", $lines);
}

/**
 * OpenAI API 호출 (스트리밍, Tool Use 없음 — 폴백용)
 */
function rag_stream(array $messages, string $context): void
{
    global $config;
    $apiKey = $config['openai']['api_key'] ?? '';
    $model  = $config['openai']['model']   ?? 'gpt-4o-mini';
    if (!$apiKey) {
        echo "data: " . json_encode(['type' => 'error', 'message' => 'API 키가 설정되지 않았습니다.']) . "\n\n";
        return;
    }

    $system = "당신은 비엠아이 건설자재 전문 상담사입니다.\n고객이 자재를 찾을 때 설치 조건을 파악해 최적 상품을 추천합니다.\n반드시 한국어로 답변하세요.\n\n{$context}";
    $oaiMessages = array_merge(
        [['role' => 'system', 'content' => $system]],
        $messages
    );

    $body = json_encode(['model' => $model, 'stream' => true, 'messages' => $oaiMessages]);
    $ch = curl_init('https://api.openai.com/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
        CURLOPT_WRITEFUNCTION  => function ($curl, $data) {
            foreach (explode("\n", $data) as $line) {
                $line = trim($line);
                if ($line === 'data: [DONE]') { echo "data: " . json_encode(['type' => 'done']) . "\n\n"; ob_flush(); flush(); continue; }
                if (!str_starts_with($line, 'data: ')) continue;
                $json = json_decode(substr($line, 6), true);
                $text = $json['choices'][0]['delta']['content'] ?? '';
                if ($text !== '') { echo "data: " . json_encode(['type' => 'text', 'text' => $text]) . "\n\n"; ob_flush(); flush(); }
            }
            return strlen($data);
        },
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_TIMEOUT        => 60,
    ]);
    curl_exec($ch);
    if (curl_errno($ch)) { echo "data: " . json_encode(['type' => 'error', 'message' => curl_error($ch)]) . "\n\n"; ob_flush(); flush(); }
    curl_close($ch);
}

/**
 * 세션 저장/불러오기
 */
function rag_load_session(string $key): array
{
    $stmt = db()->prepare('SELECT messages FROM ai_sessions WHERE session_key = :key');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();
    return $row ? (json_decode($row['messages'], true) ?? []) : [];
}

/**
 * 로그인 사용자 본인의 상담만 불러오기 (이전 상담 복원용)
 * 반환: messages 배열, 없거나 본인 것이 아니면 null
 */
function rag_load_owned_session(string $key, int $userId): ?array
{
    $pdo = db();
    if (!$pdo || $key === '') {
        return null;
    }
    $stmt = $pdo->prepare('SELECT messages FROM ai_sessions WHERE session_key = :key AND user_id = :uid');
    $stmt->execute(['key' => $key, 'uid' => $userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $messages = json_decode((string) $row['messages'], true);
    return is_array($messages) ? $messages : [];
}

/**
 * 상담 키 — 상담 1건마다 새로 발급 (PHP 세션 ID와 분리해 '새 상담'이 별도 이력으로 남도록)
 * 이 브라우저 세션에서 발급·복원한 키만 대화 요청·저장에 사용할 수 있다.
 */
function rag_chat_key_issue(): string
{
    $key = bin2hex(random_bytes(16));
    rag_chat_key_allow($key);
    return $key;
}

function rag_chat_key_allow(string $key): void
{
    $keys   = array_values(array_diff($_SESSION['chat_keys'] ?? [], [$key]));
    $keys[] = $key;
    $_SESSION['chat_keys'] = array_slice($keys, -30);
}

function rag_chat_key_allowed(string $key): bool
{
    if ($key === '') {
        return false;
    }
    // 배포 전 열어 둔 화면(세션 ID를 키로 쓰던 방식)도 계속 동작
    return in_array($key, $_SESSION['chat_keys'] ?? [], true) || $key === session_id();
}

/**
 * 상담 쓰기 권한 — 새 상담, 비로그인(user_id NULL) 상담, 본인 상담만 허용
 * (다른 회원 소유 상담에 이어 쓰거나 저장하지 못하게)
 */
function rag_session_writable(string $key, ?int $userId): bool
{
    $pdo = db();
    if (!$pdo) {
        return true; // DB 없으면 저장 자체가 불가 — 기존 동작에 맡김
    }
    $stmt = $pdo->prepare('SELECT user_id FROM ai_sessions WHERE session_key = :key');
    $stmt->execute(['key' => $key]);
    $row = $stmt->fetch();
    if (!$row || $row['user_id'] === null) {
        return true;
    }
    return $userId !== null && (int) $row['user_id'] === $userId;
}

/**
 * 이전 상담 복원용 — chat_logs.events_json(상품 추천표·견적 카드)을 대화의 AI 답변 위치에 맞춰 반환
 * ai_sessions.messages 의 사용자 질문과 chat_logs 행(같은 상담 키)을 뒤에서부터 짝지음
 * (저장 시 앞부분이 잘리는 40개 제한·저장 실패 턴이 있어도 최근 대화 기준으로 정확히 맞추기 위해).
 * 짝이 없는 질문(로그 도입 전·로그 실패)은 이벤트 없이 텍스트만 복원. 로그 한 행은 한 번만 사용.
 *
 * @return array<int, list<array>>  [messages 의 assistant 인덱스 => events]
 */
function rag_load_turn_events(string $key, int $userId, array $messages): array
{
    $pdo = db();
    if (!$pdo || $key === '') {
        return [];
    }
    try {
        $stmt = $pdo->prepare(
            'SELECT user_msg, events_json FROM chat_logs
              WHERE session_key = :key AND (user_id = :uid OR user_id IS NULL)
              ORDER BY id'
        );
        $stmt->execute(['key' => $key, 'uid' => $userId]);
        $logs = $stmt->fetchAll();
    } catch (\Throwable $e) {
        return []; // chat_logs 없음 등 — 텍스트만 복원
    }
    return rag_pair_turn_events($messages, $logs);
}

/**
 * 대화(messages)와 로그 행(user_msg, events_json)을 뒤에서부터 짝지어 AI 답변 인덱스별 이벤트 반환 (DB 불필요)
 *
 * @param  list<array{user_msg:string, events_json:?string}> $logs  id 오름차순
 * @return array<int, list<array>>
 */
function rag_pair_turn_events(array $messages, array $logs): array
{
    $result = [];
    $k      = count($logs) - 1;
    for ($i = count($messages) - 1; $i >= 0 && $k >= 0; $i--) {
        $m = $messages[$i];
        if (!is_array($m) || ($m['role'] ?? '') !== 'user' || !is_string($m['content'] ?? null)) {
            continue;
        }
        // chat.php 질문 엔드포인트와 같은 방식으로 자른 질문(최대 1000자)으로 비교
        $q = trim(mb_substr(trim($m['content']), 0, 1000));
        for ($x = $k; $x >= 0; $x--) {
            if ((string) $logs[$x]['user_msg'] !== $q) {
                continue;
            }
            $k      = $x - 1;
            $events = json_decode((string) ($logs[$x]['events_json'] ?? ''), true);
            $next   = $messages[$i + 1] ?? null;
            if (is_array($events) && $events && is_array($next) && ($next['role'] ?? '') === 'assistant') {
                $result[$i + 1] = array_values(array_filter($events, 'is_array'));
            }
            break;
        }
    }
    return $result;
}

/**
 * 이전 상담 복원용 현재 판매가 일괄 조회 — 견적서와 같은 규칙(cart_fetch_items: price_b2c, 판매 중 상품만)
 *
 * @param  list<int> $ids
 * @return array<int, array{price:int, spec:string, member?:int}>|null  [상품ID => 현재가·규격·회원 표시 단가], 조회 실패 시 null
 *         member: 로그인 등급의 표시 단가(app/cart.php display_unit_price) — 추천표 화면용(회원은 할인가)
 */
function rag_current_product_prices(array $ids): ?array
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($i) => $i > 0)));
    if (!$ids) {
        return [];
    }
    try {
        if (!db()) {
            return null;
        }
        $items = cart_fetch_items(array_fill_keys($ids, 1));
    } catch (\Throwable $e) {
        error_log('[chat] 이전 상담 현재가 조회 실패: ' . get_class($e));
        return null;
    }
    $member = display_price_map(array_column($items, 'product_id'));
    $map = [];
    foreach ($items as $it) {
        $pid = (int) $it['product_id'];
        $map[$pid] = ['price' => (int) $it['unit_price'], 'spec' => (string) ($it['spec'] ?? '')];
        if (isset($member[$pid])) { $map[$pid]['member'] = $member[$pid]['price']; }
    }
    return $map;
}

/** 복원 이벤트(상품 추천표)에 나온 상품 ID 목록 */
function rag_event_product_ids(array $eventsByMsg): array
{
    $ids = [];
    foreach ($eventsByMsg as $events) {
        foreach ((array) $events as $ev) {
            if (is_array($ev) && ($ev['type'] ?? '') === 'product_cards' && is_array($ev['products'] ?? null)) {
                foreach ($ev['products'] as $p) {
                    if (is_array($p) && (int) ($p['id'] ?? 0) > 0) {
                        $ids[] = (int) $p['id'];
                    }
                }
            }
        }
    }
    return array_values(array_unique($ids));
}

/**
 * 복원 화면용 사본에 현재 판매가·판매 상태 반영 (DB 불필요 — chat_logs 원본은 변경하지 않음)
 * 상품명·규격·이미지·추천 근거·권장 수량은 상담 당시 값 유지, 가격(price_b2c)과 price_state만 바꾼다.
 *   ok       현재 판매가
 *   inquiry  현재가 0원(가격 문의)
 *   stopped  판매 중지·삭제(현재 판매 상품에 없음)
 *   changed  현재 규격이 상담 당시와 달라 다른 상품일 수 있음 → 담기 막음
 *   unknown  현재가 조회 실패 → 상담 당시 가격을 현재가처럼 보이지 않게 가격 숨김
 *
 * @param array<int, list<array>>                          $eventsByMsg
 * @param array<int, array{price:int, spec:string}>|null   $current  rag_current_product_prices() 결과
 */
function rag_apply_current_prices(array $eventsByMsg, ?array $current): array
{
    $norm = static fn($s): string => (string) preg_replace('/\s+/u', ' ', trim((string) $s));
    foreach ($eventsByMsg as $mi => $events) {
        foreach ((array) $events as $ei => $ev) {
            if (!is_array($ev) || ($ev['type'] ?? '') !== 'product_cards' || !is_array($ev['products'] ?? null)) {
                continue;
            }
            foreach ($ev['products'] as $pi => $p) {
                if (!is_array($p)) {
                    continue;
                }
                $id  = (int) ($p['id'] ?? 0);
                $cur = $current[$id] ?? null;
                if ($current === null) {
                    [$state, $price] = ['unknown', null];
                } elseif ($cur === null) {
                    [$state, $price] = ['stopped', null];
                } elseif ($norm($cur['spec']) !== $norm($p['spec'] ?? '')) {
                    [$state, $price] = ['changed', null];
                } elseif ($cur['price'] <= 0) {
                    [$state, $price] = ['inquiry', 0];
                } else {
                    [$state, $price] = ['ok', $cur['price']];
                }
                $eventsByMsg[$mi][$ei]['products'][$pi]['price_b2c']   = $price;
                $eventsByMsg[$mi][$ei]['products'][$pi]['price_state'] = $state;
                // 화면 표시 단가(회원은 할인가): 현재 기준으로 다시 — 상담 당시 값·다른 등급 값이 남지 않게
                unset($eventsByMsg[$mi][$ei]['products'][$pi]['price_member']);
                if ($state === 'ok' && isset($cur['member'])) {
                    $eventsByMsg[$mi][$ei]['products'][$pi]['price_member'] = (int) $cur['member'];
                }
            }
            // 예상 자재비는 상담 당시 판매가 × 권장 수량 → 현재가로 다시 계산(금액을 보이지 않는 상태면 지움)하고 최저 표시도 다시
            if (array_filter($ev['products'], static fn($p) => is_array($p) && array_key_exists('est_cost', $p))) {
                $cards = $eventsByMsg[$mi][$ei]['products'];
                foreach ($cards as &$c) {
                    if (!is_array($c) || !array_key_exists('est_cost', $c)) { continue; }
                    unset($c['cost_lowest'], $c['cost_diff']);
                    $q = (int) ($c['suggested_qty'] ?? 0);
                    $c['est_cost'] = ($c['price_state'] === 'ok' && $q > 0) ? $q * (int) ($c['price_member'] ?? $c['price_b2c']) : null;   // 로그인 등급 단가 기준
                }
                unset($c);
                $eventsByMsg[$mi][$ei]['products'] = ai_cost_summary($cards)[0];
            }
        }
    }
    return $eventsByMsg;
}

function rag_save_session(string $key, array $messages): void
{
    // 로그인 상태이면 user_id 자동 연결 (GAP-V2-02 해소)
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $userId = null;
    $sessionUser = $_SESSION['user'] ?? null;
    if (is_array($sessionUser) && isset($sessionUser['id'])) {
        $userId = (int) $sessionUser['id'];
    }

    $json = json_encode($messages, JSON_UNESCAPED_UNICODE);
    // ON DUPLICATE KEY UPDATE: user_id 는 이미 설정된 값이 있으면 유지 (COALESCE — 기존 소유자 우선)
    // 익명(NULL) → 로그인 후 재상담 시에만 user_id 연결
    $stmt = db()->prepare(
        'INSERT INTO ai_sessions (session_key, user_id, messages)
              VALUES (:key, :uid, :msg)
         ON DUPLICATE KEY UPDATE
              user_id    = COALESCE(user_id, :uid_upd),
              messages   = :msg_upd,
              updated_at = NOW()'
    );
    $stmt->execute([
        'key'     => $key,
        'uid'     => $userId,
        'msg'     => $json,
        'uid_upd' => $userId,
        'msg_upd' => $json,
    ]);
}

/**
 * 대화 키워드 기반 카테고리 자동 감지 (Stage 2)
 * @return string category_key or ''
 */
function rag_detect_category(array $messages): string
{
    $text = '';
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'user') {
            $text .= ($m['content'] ?? '') . ' ';
        }
    }
    $text = mb_strtolower($text);

    // '배수관 뚜껑'(2026-11-09 운영 상담: 식당 주방 배수관 뚜껑 → PE배수관 상담으로 분류) 처럼 배수 시설 위 덮개도 그레이팅 상담
    if (preg_match('/그레이팅|(측구|배수로|배수관|트렌치)\s*(뚜껑|덮개|커버)/u', $text)) return 'grating';
    if (preg_match('/pe\s*배수관|우수관|하수관|배수관|pe\s*관/u', $text))   return 'pe_pipe';
    if (preg_match('/맨홀|집수정|우물통/u', $text))                         return 'manhole';
    if (preg_match('/수로관|농수로|u\s*형|ㅠ\s*형/u', $text))               return 'culvert';
    if (preg_match('/보도블[록럭]|경계석|인터로킹|포장블[록럭]|보차도|인조화강블|점토블[록럭]|투수블[록럭]|잔디블[록럭]/u', $text)) return 'pavement';
    // '블록' 만 쓴 경우: 바닥 포장 문맥(깔기·마당·인도·차도·두께 등)이 있고 쌓는 블록(호안·옹벽·속빈·조적 등)이 아닐 때만 보도블록 상담
    if (rag_is_paving_block_text($text)) return 'pavement';
    if (rag_is_paving_place_text($text)) return 'pavement';

    return '';
}

/** '블록·블럭' 이 쌓는 용도(호안·옹벽·속빈·조적·축조·담장 등)를 가리키는지 (순수 함수) */
function rag_is_structural_block_text(string $text): bool
{
    return preg_match('/호안|옹벽|속빈|조적|축조|담장|벽체|화단\s*쌓|쌓[는아으기을]|쌓다/u', $text) === 1;
}

/** '블록·블럭' 이 바닥 포장용으로 쓰인 문맥인지 (순수 함수) — 포장 문맥 단서가 있고 쌓는 블록이 아닐 때만 true */
function rag_is_paving_block_text(string $text): bool
{
    if (preg_match('/블[록럭]/u', $text) !== 1 || rag_is_structural_block_text($text)) {
        return false;
    }
    return preg_match('/깔[고기아려면을]|깔다|포장|바닥|마당|주차|진입로|인도|보도|차도|도로|광장|공원|정원|산책로|길에|길을|두께|\d+\s*t\b|\d+\s*mm|㎡|m2|제곱미터|평\s|차가|차량|차를|물이\s*(잘\s*)?빠|물\s*빠짐|빗물|투수/u', $text) === 1;
}

/** '블록' 단어 없이 바닥 포장 자재를 묻는 초보 표현(예: '차가 가끔 지나가는 마당에는 어떤 자재가 적합해?') (순수 함수) */
function rag_is_paving_place_text(string $text): bool
{
    return preg_match('/마당|진입로|주차\s*공간|앞마당|뒷마당|정원\s*길|산책로/u', $text) === 1
        && preg_match('/깔[고기아려면을]|깔다|포장|바닥|자재|재료|차가|차량/u', $text) === 1
        && !rag_is_structural_block_text($text);
}

/**
 * '블록' 이라고만 해서 종류를 알 수 없을 때(포장 문맥·쌓는 블록 단서 모두 없음) 확인 질문 구역 (순수 함수).
 * 보도블록으로 가정하지 않고 바닥 포장용인지 쌓는 용도인지 먼저 묻게 한다. 해당 없으면 빈 문자열.
 */
function rag_block_kind_prompt(array $messages, string $categoryKey): string
{
    if ($categoryKey !== '') {
        return '';
    }
    $text = '';
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'user') {
            $text .= ($m['content'] ?? '') . ' ';
        }
    }
    $text = mb_strtolower($text);
    if (preg_match('/블[록럭]/u', $text) !== 1 || rag_is_structural_block_text($text) || rag_is_paving_block_text($text)) {
        return '';
    }
    return "\n\n=== 블록 종류 확인 ===\n"
        . "- 고객이 '블록'이라고만 해서 종류를 알 수 없다. 보도블록으로 가정하지 말고 먼저 묻는다:\n"
        . "  '바닥에 까는 포장용(보도블록·인터로킹 등)인가요, 담장·옹벽·화단처럼 쌓는 용도인가요? 설치 장소도 알려 주세요.'";
}

/**
 * RAG 문서 DB 검색 — 사용자 메시지와 관련된 문서 반환
 */
function rag_search_documents(string $userMsg, string $categoryKey = '', int $limit = 2): array
{
    if (trim($userMsg) === '') return [];

    $keywords = preg_split('/\s+/', preg_replace('/[^\w가-힣\s]/u', ' ', $userMsg));
    $keywords = array_values(array_filter($keywords, fn($k) => mb_strlen($k) >= 2));
    if (empty($keywords)) return [];

    // ngram 파서 — 와일드카드 불필요, 단어 필수 매칭
    $ftQuery = implode(' ', array_map(fn($k) => "+{$k}", $keywords));

    try {
        $catCond = $categoryKey !== ''
            ? 'AND (category_key = :cat OR category_key IS NULL)'
            : '';
        // 출처 칸(source_type)이 있는 DB에서는 사내 문서만 — 법령·기준 문서는 rag_search_legal_documents()의 검증 필터로만 사용
        if (rag_documents_has_source_columns()) {
            $catCond .= " AND source_type = 'internal'";
        }
        $params = [':ft1' => $ftQuery, ':ft2' => $ftQuery];
        if ($categoryKey !== '') $params[':cat'] = $categoryKey;

        $stmt = db()->prepare(
            "SELECT id, title, content, category_key
             FROM rag_documents
             WHERE is_active = 1
               AND MATCH(title, content, tags) AGAINST(:ft1 IN BOOLEAN MODE)
               {$catCond}
             ORDER BY MATCH(title, content, tags) AGAINST(:ft2 IN BOOLEAN MODE) DESC
             LIMIT {$limit}"
        );
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // FULLTEXT 결과 없고 카테고리 있으면 카테고리 문서 폴백
        if (empty($rows) && $categoryKey !== '') {
            $stmt = db()->prepare(
                "SELECT id, title, content, category_key
                 FROM rag_documents
                 WHERE is_active = 1 AND category_key = :cat"
                 . (rag_documents_has_source_columns() ? " AND source_type = 'internal'" : '') . "
                 ORDER BY sort_order, id LIMIT {$limit}"
            );
            $stmt->execute([':cat' => $categoryKey]);
            $rows = $stmt->fetchAll();
        }

        return $rows;
    } catch (\Throwable $e) {
        return [];
    }
}

/** 상담에 쓸 수 있는 법령·기준 문서의 확인 수준 (상세 원문 검증 / 법제처 원문 조문 확인) */
const RAG_LEGAL_VERIFY_LEVELS = ['original_verified', 'api_text_verified'];

/** rag_documents 에 출처·검토 칸(마이그레이션 01)이 있는지. 운영처럼 칸이 없으면 법령 근거 기능은 꺼진다. */
function rag_documents_has_source_columns(): bool
{
    static $has = null;
    if ($has !== null) {
        return $has;
    }
    try {
        db()->query('SELECT source_type, verify_level, review_status, legal_status FROM rag_documents LIMIT 0');
        $has = true;
    } catch (\Throwable $e) {
        $has = false;
    }
    return $has;
}

/**
 * 법령 근거 문서 점수 (순수 함수). 태그(쉼표 구분)가 고객 문장에 들어 있으면 +3, 카테고리 일치 +1(태그가 하나 이상 맞을 때만).
 */
function rag_legal_score(array $doc, string $text, string $categoryKey = ''): int
{
    $t = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? '');
    $score = 0;
    foreach (explode(',', (string) ($doc['tags'] ?? '')) as $tag) {
        $tag = mb_strtolower(trim($tag));
        if (str_starts_with($tag, '@')) {
            continue; // '@scope:…' 는 적용 범위 표시(점수에 쓰지 않음)
        }
        if (mb_strlen($tag) >= 2 && mb_strpos($t, $tag) !== false) {
            $score += 3;
        }
    }
    if ($score > 0 && $categoryKey !== '' && ($doc['category_key'] ?? '') === $categoryKey) {
        $score += 1;
    }
    return $score;
}

/** 근거 문서의 적용 범위 표시(tags 의 '@scope:이름') 목록 (순수 함수) */
function rag_legal_doc_scopes(array $doc): array
{
    preg_match_all('/@scope:([a-z_]+)/', (string) ($doc['tags'] ?? ''), $m);
    return $m[1];
}

/**
 * 검증된 공식 법령·기준 근거 검색.
 * 조건: 활성 + source_type<>'internal' + 확인 수준(RAG_LEGAL_VERIFY_LEVELS) + 검토 완료 + 현행.
 * 적용 범위가 표시된 근거('@scope:public_road' 등)는 $scopes 에 그 범위가 있을 때만 후보가 된다
 * (예: 공공도로 차도 기준 KDS·KCS 44 50 25 는 공공도로 질문에서만).
 * 출처 칸이 없는 DB(운영 미적용)에서는 빈 배열.
 */
function rag_search_legal_documents(string $text, string $categoryKey = '', int $limit = 2, array $scopes = []): array
{
    if (trim($text) === '' || !rag_documents_has_source_columns()) {
        return [];
    }
    try {
        $in = implode(',', array_fill(0, count(RAG_LEGAL_VERIFY_LEVELS), '?'));
        $stmt = db()->prepare(
            "SELECT id, title, content, category_key, tags, source_type, source_name, source_ref, source_url,
                    effective_date, applies_to, verify_level
             FROM rag_documents
             WHERE is_active = 1 AND source_type <> 'internal'
               AND verify_level IN ({$in}) AND review_status = 'reviewed' AND legal_status = 'current'"
        );
        $stmt->execute(RAG_LEGAL_VERIFY_LEVELS);
        $rows = $stmt->fetchAll();
    } catch (\Throwable $e) {
        return [];
    }
    $scored = [];
    foreach ($rows as $r) {
        $docScopes = rag_legal_doc_scopes($r);
        if ($docScopes && !array_intersect($docScopes, $scopes)) {
            continue;
        }
        $sc = rag_legal_score($r, $text, $categoryKey);
        if ($sc >= 3) {
            $scored[] = [$sc, (int) $r['id'], $r];
        }
    }
    usort($scored, static fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
    return array_map(static fn($x) => $x[2], array_slice($scored, 0, $limit));
}

/** 시스템 프롬프트에 넣을 법령 근거 구역 (순수 함수). 근거가 없으면 빈 문자열. */
function rag_legal_prompt_section(array $legalDocs): string
{
    if (!$legalDocs) {
        return '';
    }
    $out = "\n\n=== 공식 법령 근거 (원문 조문 확인됨 · 적용 조건 필수) ===\n"
        . "- 아래 근거는 적용 조건이 있다. 고객 상황이 조건에 맞을 때만 인용하고, 확실하지 않으면 '~인 경우' 형태로 조건을 함께 말한다.\n"
        . "- 근거를 사용한 문장 끝에 해당 표시를 그대로 붙인다(예: [L12]). 표시는 화면에서 출처 카드로 바뀐다.\n"
        . "- 이 목록에 없는 법령·조항·수치는 인용하지 않는다. [참조 문서](사내 기준) 내용에는 [L번호]를 붙이지 않는다.\n"
        . "- 조건부 기준을 모든 고객의 의무처럼 말하지 않는다.";
    foreach ($legalDocs as $d) {
        $out .= "\n\n[L" . (int) $d['id'] . "] " . $d['source_name'] . ' ' . $d['source_ref']
            . ($d['effective_date'] ? ' (시행 ' . $d['effective_date'] . ')' : '')
            . "\n적용 조건: " . $d['applies_to']
            . "\n" . $d['content'];
    }
    return $out;
}

/**
 * 차량이 올라가는 블록 시공의 장소 판정 (순수 함수). 고객 발화(user)만 본다.
 *  public_road    : 공공도로(도로법 도로) 차도 공사 — 발주·관급·국도/지방도/시도·군도·구도·차도 포장 등
 *  public_sidewalk: 공공 보도(인도)를 차량이 가로질러 출입하는 곳 — 보도 설치 및 관리 지침 구분 Ⅱ
 *  private        : 사유지(마당·주택·주차장·공장·단지 등) — 사내 80T 안내 그대로
 *  unclear        : 차량 + 블록/포장인데 장소 단서가 없거나 공공·사유가 섞임 → 장소를 먼저 확인
 *  none           : 차량 블록 시공 질문이 아님
 * 판정은 안내 구역·근거 범위를 고르는 데만 쓰고, 법적 해당 여부를 확정하지 않는다.
 */
function rag_road_context(array $messages): string
{
    $text = '';
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'user') {
            $text .= ' ' . ($m['content'] ?? '');
        }
    }
    $t = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? '');
    $t = preg_replace('/보도\s*블[록럭]/u', '블록', $t) ?? $t; // '보도블록'은 제품명 — 장소(보도)로 보지 않음
    $vehicle = preg_match('/차량|차가|차를|자동차|승용차|트럭|화물차|덤프|지게차|레미콘|버스|차도|차로|진출입|진입로|주차|80t|100t|차량용/u', $t) === 1;
    $block   = preg_match('/블[록럭]|인터로킹|포장|점토|화강|두께|\d+\s*(?:mm|t)\b/u', $t) === 1;
    if (!$vehicle || !$block) {
        return 'none';
    }
    // '발주'(자재 주문)·'시도'(시도하다)·'인도'(물건 인도)처럼 흔한 말과 겹치지 않게 좁게 잡는다
    $public   = preg_match('/도로\s*공사|도로\s*포장|차도\s*포장|관급|발주처|발주청|공공\s*발주|도로법|공공\s*도로|국도|지방도|광역시도|특별시도|(?:시도|군도|구도)\s*\d|도로관리청|시청|구청|군청|도청|지자체|교통량|aadt|차도용\s*블|차도에|차도\s*블/u', $t) === 1;
    $formal   = preg_match('/설계\s*도서|시방서|kds|kcs|입찰/u', $t) === 1; // 공사 서류 — 공공·민간 모두 있음(장소 확인 필요)
    // '보도 위'·'인도 쪽'처럼 띄어 쓴 경우도 장소로 본다(조사 에·를·가는 붙여 쓸 때만)
    $sidewalk = preg_match('/(?:인도|보도)(?:에|를|가|\s*위|\s*쪽|\s*포장|\s*블|\s*진입|\s*턱|\s*횡단|\s*가로)|보행로/u', $t) === 1;
    $private  = preg_match('/마당|집\s*앞|우리\s*집|주택|전원|사유지|개인|공장|창고|단지|아파트|빌라|상가\s*(?:안|내)|건물\s*안|캠핑|펜션|농가|축사|차고|주차장|진입로/u', $t) === 1;
    if ($public && !$private) {
        return 'public_road';
    }
    if ($formal && !$private && !$sidewalk) {
        return 'unclear';
    }
    if ($sidewalk && !$private && !$public) {
        return 'public_sidewalk';
    }
    if ($private && !$public && !$sidewalk) {
        return 'private';
    }
    return 'unclear';
}

/**
 * 근거 검색 입력 (순수 함수): 장소 판정, 허용 범위, 검색 문장.
 * 공공 범위 질문은 앞선 고객 발화(장소·두께 질문)까지 합쳐 찾는다(답변 턴에 '두께'가 없을 수 있음). 그 외는 마지막 발화만(기존 동작).
 * @return array{ctx:string, scopes:array, text:string}
 */
function rag_legal_inputs(array $messages, string $lastUserMsg): array
{
    $ctx = rag_road_context($messages);
    $scopes = rag_road_scopes($ctx);
    $text = $scopes
        ? implode(' ', array_map(static fn($m) => (string) ($m['content'] ?? ''), array_filter($messages, static fn($m) => ($m['role'] ?? '') === 'user')))
        : $lastUserMsg;
    return ['ctx' => $ctx, 'scopes' => $scopes, 'text' => $text];
}

/** 장소 판정 → 근거 검색에 허용할 적용 범위 (순수 함수) */
function rag_road_scopes(string $ctx): array
{
    return match ($ctx) {
        'public_road'     => ['public_road'],
        'public_sidewalk' => ['public_sidewalk'],
        default           => [],
    };
}

/**
 * 장소 판정별 별도 안내 구역 (순수 함수). 사내 기준 80T 안내 문구는 바꾸지 않고, 공공도로·공공 보도 질문에만
 * 공식 기준을 따로 안내한다. 사유지·해당 없음은 빈 문자열(기존 흐름 그대로).
 */
function rag_road_context_prompt(string $ctx, bool $hasScopedDocs = true): string
{
    // 공공 범위 근거가 프롬프트에 없을 때(비활성·운영 미적용): 수치·출처 없이 '사내 80T 로 충분하다'고만 하지 않게 한다
    if (!$hasScopedDocs && in_array($ctx, ['public_road', 'public_sidewalk'], true)) {
        return "\n\n=== 공공 도로 공사 — 두께 단정 금지 ===\n"
            . "- 고객 질문이 도로(차도·보도) 공사로 보인다. 공공 도로는 발주처 설계도서·국가건설기준을 따르므로 사내 80T 기준을 '충분하다'고 안내하지 않는다.\n"
            . "- 설계도서·시방서에 적힌 블록 두께를 먼저 물어보고, 그 규격으로 검색한다. 확인되지 않은 법정 수치는 말하지 않는다.";
    }
    return match ($ctx) {
        'public_road' => "\n\n=== 공공도로 차도 공사 — 공식 기준 별도 안내 (사내 80T 기준과 구분) ===\n"
            . "- 고객 질문이 도로법상 도로(국도·지방도·시도·군도·구도 등)의 차도에 블록을 시공하는 공사로 보인다.\n"
            . "- 이 경우 [참조 문서]의 사내 기준(80T)만으로 두께를 안내하지 않는다. 아래 공식 기준 KDS 44 50 25·KCS 44 50 25 의 '차도용 블록 최소 100mm(교통량·설계 결과에 따라 상향, 표준단면 100mm/120mm)'를 [L번호]와 함께 안내한다.\n"
            . "- 80mm(80T) 제품을 공공도로 차도용으로 권하지 않는다. 제품 검색은 spec_mm 100, spec_strict true 로 한다.\n"
            . "- 맞는 제품이 없으면 도구 note 대로 '현재 등록된 적합 상품이 없다'고 분명히 알리고, 80T·60T 등 기준보다 얇은 제품을 대체품으로 권하지 않는다. 발주처 설계도서의 규격 확인과 별도 견적이 필요하다고 안내한다.\n"
            . "- 최종 두께·단면은 발주처 설계도서·특기시방서가 우선이라고 함께 안내한다. 공식 기준에 없는 내용은 덧붙이지 않는다.",
        'public_sidewalk' => "\n\n=== 공공 보도의 차량 출입부 — 공식 기준 별도 안내 ===\n"
            . "- 고객 질문이 도로의 보도(인도)를 차량이 가로질러 출입하는 곳의 포장으로 보인다.\n"
            . "- 아래 공식 근거(보도 설치 및 관리 지침)가 있으면 그 범위(최대 적재량 4t 이하 차량이 보도를 가로질러 통행하는 보도)와 함께 [L번호]로 안내한다.\n"
            . "- 4t 를 넘는 차량이 다니거나 차도 부분이면 공공도로 차도 기준이 따로 있으니 공사 성격을 먼저 확인한다(이 구역에 없는 수치는 말하지 않는다).\n"
            . "- 이 경우 80mm 는 공식 기준(보도 지침)이다. 상품을 추천할 때도 '저희 권장 기준'이라고 하지 말고 '보도 지침 기준(80mm 이상)'이라고 말한다.",
        'private' => "\n\n=== 개인 부지(마당·진입로·주차장) — 저희 권장 기준으로 안내 ===\n"
            . "- 개인 부지 블록 두께는 저희가 확인한 공식 기준(법령·KDS)에 수치가 없다. 차량이 올라가는 곳의 '80mm 이상'은 '저희 권장 기준'이라고 밝혀 말하고, 법적 의무·반드시 지켜야 하는 기준처럼 말하지 않는다.\n"
            . "- 공공도로 차도 기준(KDS·KCS)은 인용하지 않는다. '균열을 방지'·'오래 사용 보장'·'하중을 잘 견딘다' 같은 효과 설명을 붙이지 않는다(시공·지반 상태에 따라 다름).",
        'unclear' => "\n\n=== 차량 블록 시공 장소 확인 ===\n"
            . "- 차량이 올라가는 블록 시공이지만 장소가 분명하지 않다. 두께가 '충분하다'고 단정하기 전에 먼저 묻는다:\n"
            . "  '개인 부지(마당·주차장·진입로)인가요, 도로(인도·차도) 공사인가요? 발주처 설계도서나 시방서가 있나요?'\n"
            . "- 도로 차도 공사로 확인되면 다음 답변에서 공식 기준을 따로 안내하고(이번 답변에서 수치를 단정하지 않는다), 개인 부지면 기존 안내대로 진행한다.",
        default => '',
    };
}

/**
 * 배수관 설치 위치·관경 주의 상황 판정 (순수 함수). 고객 발화(user)만 본다.
 *  wall_drain : 옹벽(보강토·석축 포함) 뒤·주변에 배수관을 묻는 상황 — 건축법 시행규칙 제25조제4호(옹벽 상단 안쪽 2m 이내 배수관 재질)
 *  cross_drain: 도로를 가로질러 배수관을 묻는 상황 — KDS 44 40 15:2026 횡단배수관 최소관경(1,000mm, 부득이 800mm)
 * 화면(메인 작업 패널·상품 목록)에는 표시하지 않고 AI 상담 답변에서만 안내한다(사용자 결정 2026-11-05, docs/03-analysis/work-types-v5.md 3-4·3-8).
 * 판정은 안내를 붙일지 고르는 데만 쓰고, 법적 적용 여부를 확정하지 않는다(답변에서 조건부로 말함).
 * @return list<string>
 */
function rag_drain_notice_keys(array $messages): array
{
    $text = '';
    foreach ($messages as $m) {
        if (($m['role'] ?? '') === 'user') {
            $text .= ' ' . ($m['content'] ?? '');
        }
    }
    $t = mb_strtolower(preg_replace('/\s+/u', ' ', $text) ?? '');
    $pipe = preg_match('/배수\s*관|유공\s*관|이중\s*벽\s*관|주름\s*관|pe\s*관|흄\s*관|파형\s*강관|맹암거|배수\s*파이프|관로|수로\s*관|우수\s*관|하수\s*관/u', $t) === 1;
    $keys = [];
    // 옹벽 + (배수관 또는 옹벽 배수·물빼기 문맥)
    if (preg_match('/옹벽|보강토|석축/u', $t) === 1
        && ($pipe || preg_match('/배수|물\s*빼|물\s*빠|물이\s*고/u', $t) === 1)) {
        $keys[] = 'wall_drain';
    }
    // '횡배수관·횡단 배수'를 직접 말했거나, 도로 + 가로지르기 + 배수관
    $crossWord = preg_match('/횡\s*배수|횡단\s*배수|횡단\s*관|횡단\s*수로/u', $t) === 1;
    $road      = preg_match('/도로|차도|찻길|국도|지방도|군도|도로법/u', $t) === 1;
    $across    = preg_match('/가로질|가로\s*질러|횡단|건너|밑으로|아래로|밑에\s*(?:묻|매설|놓)|아래에\s*(?:묻|매설|놓)/u', $t) === 1;
    if ($crossWord || ($road && $across && $pipe)) {
        $keys[] = 'cross_drain';
    }
    return $keys;
}

/**
 * 배수관 주의 안내 구역 (순수 함수). 해당 상황이 없으면 빈 문자열(기존 프롬프트 그대로).
 * 안내 문구는 사용자 확정 문장이고, 근거는 원문 확인한 건축법 시행규칙 제25조제4호·KDS 44 40 15:2026 이다.
 * 법령 근거 DB([L번호]) 밖의 고정 안내라 출처 카드는 붙지 않는다.
 */
function rag_drain_notice_prompt(array $keys): string
{
    if (!$keys) {
        return '';
    }
    $out = "\n\n=== 배수관 설치 위치·관경 주의 안내 (확인된 안내 문구) ===\n"
        . "- 아래 안내는 원문을 확인한 법령·기준을 바탕으로 정한 문구다. 고객 상황이 조건에 맞으면 답변 끝에 한 번만, 문구를 거의 그대로 전한다.\n"
        . "- 위 '근거 구분' 규칙의 예외로, 이 문구 안의 법령·기준 이름과 수치는 그대로 써도 된다. [L번호]는 붙이지 않고, 이 구역에 없는 법령·조항·수치는 덧붙이지 않는다.\n"
        . "- 이 안내 때문에 상품 추천·검색을 멈추거나 빼지 않는다. '적용될 수 있습니다'·'요구될 수 있습니다' 표현을 지키고 의무·불법으로 단정하지 않는다.\n"
        . "- 같은 대화에서 이미 전한 안내는 고객이 다시 묻지 않는 한 되풀이하지 않는다.";
    if (in_array('wall_drain', $keys, true)) {
        $out .= "\n\n[옹벽 배수관] 조건: 옹벽(보강토·블록·석축 옹벽 포함) 뒤나 위쪽 땅에 배수관(유공관·PE관 등)을 묻는 경우.\n"
            . "안내: \"옹벽 배수에는 현장 설계에 따라 유공관 등의 배수 자재를 사용할 수 있습니다. 다만 옹벽 상단에서 대지 안쪽 2m 이내에 매설하는 배수관은 「건축법 시행규칙」 제25조의 재질 제한이 적용될 수 있습니다. 설치 위치와 설계도면을 확인한 후 적합한 배수관을 선택해 주세요.\"";
    }
    if (in_array('cross_drain', $keys, true)) {
        $out .= "\n\n[도로 횡단배수관] 조건: 공공 도로(국도·지방도·시도·군도 등 도로법상 도로)를 가로질러 배수관을 묻는 경우. 개인 마당·진입로·사유 농로처럼 공공 도로가 아닌 것이 분명하면 이 안내를 하지 않고, 불분명하면 '공공 도로를 가로지르는 경우'라는 조건을 붙여 말한다.\n"
            . "안내: \"공공 도로를 가로지르는 횡단배수관은 국가건설기준(KDS 44 40 15)상 관경 1,000mm 이상(부득이한 경우 800mm)이 요구될 수 있습니다. 설계도면의 관경을 확인해 주세요.\"\n"
            . "- 판매 상품 규격이 이 관경보다 작아도 '쓸 수 없다'고 단정하지 말고, 설계도면의 관경을 먼저 확인하도록 안내한다.";
    }
    return $out;
}

/**
 * AI 답변의 [L번호] 표시 처리 (순수 함수).
 * 이번 질문에 넣은 근거 번호만 인정하고(지어낸 번호는 버림) 표시는 본문에서 지운다.
 *
 * @param array<int,array> $legalDocs 이번 질문에 넣은 근거(id 키 무관)
 * @return array{text:string, sources:list<array>}
 */
function rag_extract_legal_citations(string $text, array $legalDocs): array
{
    $byId = [];
    foreach ($legalDocs as $d) {
        $byId[(int) $d['id']] = $d;
    }
    $cited = [];
    if (preg_match_all('/\[L(\d+)\]/', $text, $m)) {
        foreach ($m[1] as $id) {
            $id = (int) $id;
            if (isset($byId[$id]) && !in_array($id, $cited, true)) {
                $cited[] = $id;
            }
        }
    }
    $clean = preg_replace('/ ?\[L\d+\]/', '', $text) ?? $text;
    $sources = array_map(static function (int $id) use ($byId): array {
        $d = $byId[$id];
        return [
            'id'             => $id,
            'kind'           => (string) $d['source_type'],
            'name'           => (string) $d['source_name'],
            'ref'            => (string) $d['source_ref'],
            'effective_date' => $d['effective_date'] ? (string) $d['effective_date'] : null,
            'applies_to'     => (string) $d['applies_to'],
            'url'            => (string) ($d['source_url'] ?? ''),
            'verify_level'   => (string) $d['verify_level'],
        ];
    }, $cited);
    return ['text' => $clean, 'sources' => $sources];
}

/**
 * DB에서 카테고리별 상담 가이드 로드 (Stage 2)
 */
function rag_load_consult_guide(string $categoryKey): string
{
    if ($categoryKey === '') return '';
    try {
        $stmt = db()->prepare(
            'SELECT guide_text FROM category_consult_guide
             WHERE category_key = :key AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['key' => $categoryKey]);
        $row = $stmt->fetch();
        return $row['guide_text'] ?? '';
    } catch (\Throwable $e) {
        return '';
    }
}

/**
 * 채팅 로그 DB 기록 (Stage 3)
 */
function rag_log_chat(
    string $sid,
    string $userMsg,
    string $assistantText,
    array  $events,
    string $categoryKey,
    ?int   $userId = null
): void {
    try {
        $toolsCalled = null;
        if (!empty($events)) {
            $types = array_unique(array_column($events, 'type'));
            $toolsCalled = mb_substr(implode(',', $types), 0, 255);
        }
        $eventsJson = empty($events) ? null : json_encode($events, JSON_UNESCAPED_UNICODE);

        $stmt = db()->prepare(
            'INSERT INTO chat_logs
                (session_key, user_id, user_msg, assistant_txt, tools_json, events_json, category_key)
             VALUES (:sid, :uid, :umsg, :atxt, :tools, :events, :cat)'
        );
        $stmt->execute([
            'sid'    => $sid,
            'uid'    => $userId,
            'umsg'   => mb_substr($userMsg, 0, 2000),
            'atxt'   => mb_substr($assistantText, 0, 4000),
            'tools'  => $toolsCalled,
            'events' => $eventsJson,
            'cat'    => $categoryKey ?: null,
        ]);
    } catch (\Throwable $e) {
        // 로깅 실패는 무시
    }
}

/**
 * OpenAI API POST — 단순 curl (20초 타임아웃)
 * 반환: 응답 JSON 문자열, 실패 시 false
 */
function rag_curl_post(string $url, string $body, array $headers): string|false
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT        => 45,   // 도구 결과가 붙은 GPT-4o 응답은 20초를 넘길 때가 있다(2026-10-29 실제 시험 1건 시간 초과)
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno) return false;
    return $raw;
}

/**
 * 상담 시스템 프롬프트와 이번 질문의 근거 묶음 생성 — API 호출 없음.
 * rag_process() 가 이 결과를 그대로 OpenAI 에 보낸다. 프롬프트 검증(scripts/testdb/dump-prompts.php)·테스트에서도 같은 함수를 쓴다.
 * @return array{system:string, categoryKey:string, ragDocs:array, legalDocs:array, roadCtx:string, scopes:array}
 */
function rag_build_prompt(array $messages, array $recentCards = []): array
{
    // Stage 2: 카테고리 자동 감지 + DB 가이드 로드
    $categoryKey = rag_detect_category($messages);
    $guideText   = rag_load_consult_guide($categoryKey);

    // RAG 문서 검색 — 마지막 사용자 메시지 기준
    $lastUserMsg = '';
    foreach (array_reverse($messages) as $m) {
        if (($m['role'] ?? '') === 'user') { $lastUserMsg = $m['content'] ?? ''; break; }
    }
    $ragDocs = rag_search_documents($lastUserMsg, $categoryKey, 2);
    // 차량 블록 시공 장소 판정 — 공공도로·공공 보도 질문에만 해당 범위의 공식 근거를 허용
    $legalIn = rag_legal_inputs($messages, $lastUserMsg);
    $roadCtx = $legalIn['ctx'];
    // 검증된 공식 법령 근거(출처 칸이 없는 운영 DB에서는 빈 배열 → 기존 동작 그대로)
    $legalDocs = rag_search_legal_documents($legalIn['text'], $categoryKey, 2, $legalIn['scopes']);

    $system = "당신은 비엠아이 건설자재 전문 상담사입니다. 항상 한국어로만 답변하세요.\n"
        . "\n"
        . "=== 친절 응대 원칙 (건축자재를 잘 모르는 개인 고객 기준) ===\n"
        . "- 고객 대부분은 자재를 처음 사보는 개인이다. 전문용어는 괄호로 쉽게 풀어 쓴다. 예: '투수블록(빗물이 스며드는 블록)'.\n"
        . "- '~하면 뭐가 좋아?', '어떤 게 나아?' 같은 일반 질문에는 되묻기 전에 먼저 답한다:\n"
        . "  쓸 만한 선택지 2~4가지를 '- 이름: 장점 / 주의점' 형식으로 한 줄씩 비교하고, 그다음 추천에 꼭 필요한 질문 1~2개만 한다.\n"
        . "- 일반 설명에서 가격·강도·법정 기준 같은 구체 숫자를 지어내지 않는다. 숫자는 아래 [참조 문서]에 있는 것만 인용하고, 가격·수량·자재비는 search_products 결과 값만 쓴다.\n"
        . "- 면적을 모르면 '가로 몇 m × 세로 몇 m인지' 물어보고, 답을 받으면 직접 곱해 area_m2로 넘긴다(예: 5m×4m → 20). 대략값도 괜찮다고 안내한다.\n"
        . "- 자재 종류의 차이·특징·물빠짐·주의사항·KS(인증) 질문과, 사용 조건(유모차·휠체어·미끄럼·오염·차량 통행 등)에 맞는 자재를 고르는 질문에는 material_info 를 먼저 호출하고, 돌려준 원문 문장을 그 문장의 maker(제조사 이름)를 그대로 밝혀(예: maker 가 'A산업'이면 'A산업 자료에 따르면') 그 제조사 제품 설명으로 쉬운 말로 전한다(출처끼리 다르면 단정하지 않음, 공식 기준 수치는 [L번호] 근거만).\n"
        . "  원문 문장의 제품명(예: '안전 중하중 그레이팅')을 더 넓은 종류('중하중 그레이팅' 전체)로 바꿔 말하지 않는다. 원문에 없는 성능·용도·비교·수치(중장비 통과, 'n톤 이상', 다른 제품도 같다 등)를 덧붙이거나, 한 제조사의 제품 표기를 '이걸 쓰면 된다'는 일반 선택 기준으로 단정하지 않는다. KS·표준 번호는 [참조 문서]·material_info 원문에 있는 것만 쓴다. material_info 결과가 비어 있으면 '제조사 자료에 따르면'이라고 말하지 말고, 수치 없는 일반 설명임을 밝힌다.\n"
        . "- 제품·자재를 권할 때는 근거를 문장 앞에 먼저 밝힌다: '(근거)이므로 (제품)을 권합니다' 형태. 예: '식당 주방은 차량이 다니지 않는 보행자 구역이므로, 보행자용 그레이팅수로용 제품을 고려하시면 좋습니다.'\n"
        . "  근거는 고객이 말한 조건(장소·차량 통행·치수·용도)과 아래 '근거 구분'의 출처(공식 기준 [L번호] / 제조사 자료 / 저희 권장 기준 / 상품명 하중 등급·등록 규격) 중 실제로 있는 것만 쓴다. 근거를 지어내지 않고, 근거 없이 '~을 고려하시면 좋습니다'·'~이 적합합니다'로만 말하지 않는다.\n"
        . "- 선택지를 비교한 뒤 면적·길이가 확인되면 search_products의 queries로 종류별 제품을 한 표에 모아 보여준다.\n"
        . "  도구가 제품별 권장 수량(suggested_qty)과 예상 자재비(est_cost, " . (price_basis_label() !== '' ? price_basis_label() . ' 기준·' : '') . "운반·시공비 별도)를 계산해 준다. 그 비교는 답변 글에서 아래 [검색 결과 답변 형식]대로 한다.\n"
        // 로그인 회원: 표·도구 금액이 할인 적용 금액 — 그 금액 그대로 말하되 '회원가'·'판매가' 같은 이름은 붙이지 않게(비회원 프롬프트는 기존 그대로)
        . (price_basis_label() === ''
            ? "- 이 고객은 로그인 회원이다. 표·도구 결과의 금액(price_member·예상 자재비·cost_summary·직전 추천표·장바구니)은 할인이 적용된 금액이다. 금액은 그 값 그대로 말하고 '회원가'·'판매가' 같은 가격 이름을 붙이지 않는다. 판매가(price_b2c)는 말하지 않는다.\n"
            : '')
        . "\n"
        . "=== 근거 구분 (출처 종류를 섞지 않는다) ===\n"
        . "- 공식 기준: 아래 '공식 법령 근거'에 [L번호]로 들어온 법령·KDS·KCS 조항만. 기준 이름과 함께 말하고 [L번호]를 붙인다. 들어오지 않았으면 '공식 기준에 따르면'이라고 말하지 않는다.\n"
        . "- 제조사 자료: material_info 의 원문 문장(facts)만, 'maker 자료에 따르면'으로. 국가표준·등록 규격: material_info 의 basics(표준 번호·이름, 토목몰 등록 상품 규격)만, 요구 수치를 덧붙이지 않는다.\n"
        . "- 공공조달 등록 현황: 도구 결과 basics.procurement 의 summary 만, '조달청 품목 등록 내역에 따르면'으로 한 문장. 다른 회사 품목의 집계이므로 토목몰 상품이 KS 인증·조달 등록 제품이라고 말하지 않는다.\n"
        . "- 저희 권장 기준: [참조 문서](사내 기준)의 내용은 '저희 권장 기준'으로 말하고 법정 의무처럼 말하지 않는다.\n"
        . "- 시공 조건 질문(예: '집 앞에 블록 깔면 차 지나가도 돼요?')은 ① 조건을 쉬운 말로 정리 ② 위 근거를 종류별로 한두 문장씩 ③ 필요한 확인(면적 등)을 물어 비교표(필요 수량·예상 자재비)로 이어 간다.\n"
        . "\n"
        . "=== 배송·납기 안내 ===\n"
        . "고객이 배송·납기·배달·운송·언제 받냐 등을 물어보면 반드시 아래 문구만 출력하세요.\n"
        . "「배송 일정은 견적 요청 후 담당자가 직접 확인하여 연락드립니다. 견적요청하기 버튼을 눌러 신청해 주세요.」\n"
        . "배송비·운임 등 비용 관련 질문도 동일하게 담당자 연락 안내로 처리하세요.\n"
        . "\n"
        . "=== 절대 금지 ===\n"
        . "- 아래 [카테고리별 필수 확인 항목]이 모두 충족되지 않은 상태에서 search_products 호출 금지.\n"
        . "- search_products 결과를 표 순서대로 상품명·규격·가격만 나열하지 않는다(답변 글은 아래 [검색 결과 답변 형식]).\n"
        . "- DB에 없는 상품 추천·언급 금지.\n"
        . "- 상품 없이 문의를 받거나 담당자가 따로 연락하는 창구는 없다. '문의 주시면 찾아드리겠습니다'·'연락드리겠습니다' 같은 약속 금지(견적요청하기 버튼 안내는 허용).\n"
        . "- '[도구 호출]', '[search_products(...)]' 같은 tool 호출 사실을 텍스트로 언급 금지.\n"
        . "\n"
        . "=== 검색 결과 답변 형식 (search_products 호출 후) ===\n"
        . "- 검색 결과는 화면에 비교표(사진·제품명·규격·" . (price_basis_label() ?: '가격') . "·예상 비용·수량·견적추가)로 자동 표시된다. 답변 글은 표가 보여 주지 않는 자재 설명·제품 간 비교·추천 근거를 맡는다.\n"
        . "- 순서는 이것만: ① 첫 문장 — 고객 조건(장소·차량·면적 등)을 짧게 정리하고 '…제품입니다.'로 맺는다.\n"
        . "  ② 제품마다 한 줄씩 '- 제품명: 설명. 권장 수량은 N개이며, 예상 자재비는 약 X원입니다.' — 설명은 그 자재가 무엇이고 어디에 쓰는지, 함께 나온 다른 제품과 무엇이 다른지 1~2문장.\n"
        . "  ③ 마지막 문단 — 근거를 먼저 밝히고(고객 조건 + 공식 기준 [L번호] / 제조사 자료 / 저희 권장 기준 / 등록 규격) 그 근거로 어떤 제품을 추천하는지 1~2문장('…이므로 …을 추천합니다'), 이어서 '원하시는 제품을 선택해 주세요.'\n"
        . "- 설명 재료는 도구 결과 제품별 category_desc(토목몰 자재 분류 설명)·guide(규격에서 읽은 고르는 기준)·규격 표기·weight_kg·standard 와 [참조 문서]·material_info 원문뿐이다. 여기에 없는 용도·성능·수치는 지어내지 않는다.\n"
        . "  weight_kg 는 등록 규격의 판매 단위(unit — 본·개·㎡ 등) 1개당 중량이다. 단위와 함께 그대로 쓴다(예: '흄관 1본 194kg', '인터로킹 블록 1㎡당 164kg'). 무게로 강도·내구성을 짐작해 말하지 않는다.\n"
        . "  standard 는 그 제품 종류가 대상인 국가표준 번호·이름이다. 'KS F 4403(원심력 철근 콘크리트관) 표준 대상 종류'처럼만 말하고, 이 상품이 KS 인증을 받았다고 말하지 않는다(인증 여부는 상품명 표기·제조사 자료로만). 도구 결과 basics.standards 에 scope(사람이 표준 상세 화면에서 확인한 적용 범위)가 있으면 '이 표준은 ~을 대상으로 한다'처럼 그 문구만 쓴다.\n"
        . "  guide 는 화면에 보이지 않으므로 쉬운 말로 풀어 설명에 넣는다. 주의할 점은 고객 조건과 맞지 않는 것만 짚는다(예: 차량이 올라가는데 60mm 제품).\n"
        . "- 수량·금액: 권장 수량은 suggested_qty(단위 포함), 예상 자재비는 est_cost 를 그대로 쓴다(직접 계산·반올림·합계 금지, 예: '약 1,074,000원'). 계산식(qty_basis)은 고객이 계산 방법을 물을 때만 쓴다.\n"
        . "  est_cost 가 없으면 수량·금액을 말하지 말고 '면적(또는 길이)을 알려 주시면 수량과 자재비를 계산해 드립니다'라고 한다. 판매가가 0원인 제품은 '가격 문의'라고 한다. ㎡ 단위 판매 제품은 ㎡ 로 주문하고 장수는 참고라고 덧붙인다.\n"
        . "- 효과·성능을 덧붙이지 않는다(예: '강도 우수'·'튼튼'·'견고'·'균열 방지'·'오래 사용 보장'·'불투수형은 비가 많이 오는 곳에 적합'). 투수·불투수는 guide 문구 그대로만 말한다.\n"
        . "- guide 의 '참고 자료(판매 상품 아님)' 줄은 공공데이터·제조사 자료로 확인한 정보다. 판매 상품이나 가격처럼 말하지 않고 출처와 함께 참고로만 언급한다.\n"
        . "- 고객이 '어떤 게 더 저렴해?'처럼 가격 차이를 물으면 도구 결과 cost_summary 의 상품명·예상 금액·차이만 인용해 1~2문장으로 답한다(cost_summary 가 없으면 금액을 말하지 않는다).\n"
        . "- 도구 결과에 note 필드가 있으면 그 note 문장을 첫 문장 다음에 그대로 한 줄 전한다(교정·대안 안내).\n"
        . "- 추천 순서: 고객 조건(하중 등급·규격·용도)을 만족하는 제품 중 예상 자재비(없으면 단가)가 가장 낮은 제품을 먼저 권한다(표도 그 순서). 더 비싼 제품은 고객 조건에 그 등급·기능이 꼭 필요한 이유가 있을 때만 그 이유와 함께 권한다.\n"
        . "- 고객 조건보다 높은 등급(예: 사람만 다니는 주방·실내·인도에 차량용 중하중·안전중하중)을 '적합하다'·'적절하다'고 말하지 않는다. 도구가 높은 등급만 있다고 알려 주면(note·over_spec_options) 그대로 전하고, 고객이 원한다고 할 때만 그 제품을 찾는다.\n"
        . "- 도구 결과에 missing_terms 가 있으면 그 말(예: 날개형)이 들어간 제품은 이번 조건에서 찾지 못한 것이다. note 대로 먼저 알리고, 표의 제품을 그 제품이라고 말하지 않는다.\n"
        . "- 제품 치수는 결과의 size(상품 규격 이름표대로 읽은 폭·길이·높이 등)로 말한다. 규격 숫자의 순서로 폭·길이를 짐작하지 않는다. dim_check 가 unverified 인 제품은 그 치수가 확인되지 않았다고 말하고 정확히 맞는 제품이라고 하지 않는다.\n"
        . "- 차량 통행 조건에서 load_check 가 unverified 인 제품은 '하중 확인 필요'라고 말하고 차량 통행에 적합하다고 하지 않는다(load_check verified 제품을 먼저 권한다). 결과에 recheck 가 있으면 note 대로 앞서 추천한 제품이 새 조건에 맞는지 알린다.\n"
        . "- 결과에 clarify 가 있으면 표 안내 뒤에 그 내용대로 한 문장 확인 질문을 한다(서버가 모호하다고 본 고객 말 — 추측해 조건을 정하지 않는다). '더 튼튼한' 제품은 load_grade(확인된 하중 등급)로만 비교해 말한다.\n"
        . "- 글 형식: 마크다운 기호(굵게 **…**, 제목 #)를 쓰지 않는다. 문장 끝을 콜론(:)으로 맺지 않는다(목록의 '제품명: 설명'은 괜찮다).\n"
        . "\n"
        . "=== 카테고리별 필수 확인 항목 (모두 확인 후 search_products 호출) ===\n"
        . "\n"
        . "[그레이팅]\n"
        . "  필수 ① 설치 장소 (도로/주차장/공장/인도/식당 주방·실내/기타)\n"
        . "  필수 ② 차량 통행 여부 + 최대 차량 톤수 (보행자 전용이면 '없음')\n"
        . "    설치 장소로 알 수 있으면 다시 묻지 않는다: 식당 주방·화장실·실내·베란다·인도·공원 → 보행자 전용, 주차장·도로 → 차량.\n"
        . "  필수 ③ 필요 면적(㎡) 또는 수량(개), 또는 수로 연장(m)\n"
        . "  ★용도어 정밀화: 수로·배수로·측구 위 덮개면 반드시 '수로용', 집수정·맨홀 뚜껑이면 '맨홀'을 검색어에 포함(섞임 방지).\n"
        . "  ★하중 등급(카탈로그 3단계, 과잉 등급 금지): 보행자 전용→'그레이팅수로용', 승용차·SUV 통행→'중하중', 트럭·버스 등 대형차→'안전중하중'.\n"
        . "    search_products 에 traffic 을 함께 넘긴다(보행자 전용 pedestrian / 승용차·SUV car / 트럭·버스·지게차 등 대형차 truck). 조건과 다른 하중 등급은 표에서 빠진다.\n"
        . "  검색어(query) 예: '그레이팅 수로용 중하중', '그레이팅 맨홀 집수정 중하중', '그레이팅 수로용'\n"
        . "\n"
        . "[PE배수관 / 우수관 / 하수관]\n"
        . "  필수 ① 설치 장소 (농경지/도로측구/건물주변/기타)\n"
        . "  필수 ② 관경(mm) — 모르면 용도 설명 후 추천\n"
        . "  필수 ③ 필요 연장(m)\n"
        . "  검색어(query) 예: 'PE 배수관', '이중벽관', '우수관', '하수관' (관경은 spec_mm, 연장은 length_m로).\n"
        . "  ※ query에 'PE배수관'처럼 붙여쓰지 말고 'PE 배수관'으로 띄운다. 설치장소(농경지·도로측구 등)는 query에 넣지 않는다.\n"
        . "\n"
        . "[맨홀 / 집수정]\n"
        . "  필수 ① 용도 (우수/오수/통신/전력/기타)\n"
        . "  필수 ② 차량 통행 여부 + 최대 차량 톤수\n"
        . "  필수 ③ 규격(호칭 또는 내경mm) 또는 수량\n"
        . "  검색어(query) 예: '맨홀 우수 중하중', '집수정 우수' (톤수·호칭 숫자는 query에 넣지 말 것)\n"
        . "\n"
        . "[수로관 / 농수로]\n"
        . "  필수 ① 총 연장(m)  ② 폭(mm) 또는 호칭\n"
        . "  - 폭을 알면 형태(U형/BOX/원형)는 되묻지 말고 폭 기준으로 바로 후보를 제시(형태가 섞여 나오면 고르게 안내).\n"
        . "  검색어(query) 예: '측구 수로관', 'U형 수로관', '사각 수로관', '환경 수로관' (폭=width_mm, 호칭 등 그 밖의 규격=spec_mm, 연장=length_m)\n"
        . "  ★'수로를 만든다/설치'면 본체(수로관)와 덮개(그레이팅수로용)를 세트로 함께 추천 → 아래 [세트 추천] 규칙.\n"
        . "\n"
        . "[보도블록 / 경계석 / 인터로킹]\n"
        . "  필수 ① 설치 장소 + 차량이 올라가는지 (집 앞 마당·진입로/인도/광장/공원/주차장/기타)\n"
        . "  필수 ② 필요 면적(㎡) — 모르면 가로×세로(m)로 받아 계산\n"
        . "  (경계석 필요 여부는 검색 전에 묻지 말고, 블록 표를 보여준 뒤 '테두리 마감용 경계석도 필요하신가요?'로 묻는다)\n"
        . "  ★차량이 올라가면 두께 80mm 이상(80T) 제품, 사람만 다니면 60mm(60T)도 가능 → [참조 문서] 기준.\n"
        . "    차량이 올라가는 곳이면 spec_mm 80, spec_strict true를 함께 넘겨 80T 제품만 표에 나오게 한다(사람만 다니면 spec_mm 생략).\n"
        . "    80T 제품이 없는 종류는 표에서 빠지며, 도구의 note로 안내된다(다른 두께를 대신 권하지 않는다).\n"
        . "  검색어(query) 예: '인터로킹 블록', '인조화강블록', '점토블럭', '잔디블록', '경계석' (면적은 area_m2로, 설치장소는 넣지 말 것)\n"
        . "  종류 비교 예: queries ['인터로킹 블록', '인조화강블록', '점토블럭'], area_m2 20 → 종류별 필요 수량·예상 자재비가 한 표에 표시.\n"
        . "  ★필수 ①②가 확인되면(예: 집 앞 마당·승용차·4m×5m) 블록 종류를 되묻지 말고 같은 턴에 위 queries 로 비교표를 바로 보여 준다(종류는 표를 보고 고르게).\n"
        . "\n"
        . "[기타 자재]\n"
        . "  필수 ① 용도·설치 환경 ② 필요 수량·규모\n"
        . "  검색어 구성 예: 자재명 + 규격 + 용도\n"
        . "\n"
        . "=== 상담 흐름 ===\n"
        . "【1단계: 정보 수집】\n"
        . "- 고객 요청에서 카테고리를 파악한다.\n"
        . "- 해당 카테고리의 필수 항목 중 미확인 항목을 한 번에 2~3개씩 질문한다.\n"
        . "- 모든 필수 항목이 확인될 때까지 search_products 호출 금지.\n"
        . "- 불명확한 답변은 재질문한다. 예: '승용차인가요, 화물차도 있나요?'\n"
        . "- ★이미 확인한 정보(폭·연장·하중·설치장소 등)는 다른 품목을 추천할 때 다시 묻지 말고 그대로 재사용한다.\n"
        . "【세트 추천 (여러 품목이 함께 필요한 요청)】\n"
        . "- '수로 설치/제작', '덮개 포함', '수로관+그레이팅' 처럼 한 공사에 여러 품목이 필요하면, 한 턴에서 search_products를 품목별로 여러 번 호출해 함께 제시한다.\n"
        . "  예) 폭500·50m 차량통행 수로 → ①본체: query '측구 수로관', width_mm 500, length_m 50 (약 25본)  ②덮개: query '그레이팅 수로용 중하중', width_mm 500, length_m 50 (약 50개).\n"
        . "- 본체(2m/본)와 덮개(1m/개)는 단위가 달라 수량이 다르게 나오는 게 정상이다. 둘 다 카드로 보여준 뒤 [검색 결과 답변 형식]대로 본체·덮개를 각각 설명한다(단위가 달라 수량이 다르다고 짚어 준다).\n"
        . "【2단계: 검색】\n"
        . "- 모든 필수 항목 확인 완료 → search_products 호출. 되묻지 말고 그 턴에서 바로 호출한다.\n"
        . "- '찾아 드릴게요'·'잠시만 기다려 주세요'라고 말만 하고 답변을 끝내지 않는다. 검색할 때는 같은 응답에서 search_products 를 호출한다.\n"
        . "- query에는 제품명/용도 '텍스트'만 넣는다. 숫자(치수·길이·수량)는 query에 절대 넣지 말 것.\n"
        . "- 폭(너비·가로 mm)은 width_mm, 제품 1개의 길이 치수(mm)는 product_length_mm, 그 밖의 규격 치수(관경·포장 블록 두께 mm)는 spec_mm, 설치 총 길이/연장(m)은 length_m, 면적(㎡)은 area_m2 파라미터로 분리 전달.\n"
        . "  '폭 500mm 길이 10m'처럼 m 단위 길이는 대개 설치 총 길이(length_m)다. 제품 1개 길이로 말한 것이 분명할 때만 product_length_mm.\n"
        . "  고객이 조건 하나만 바꾸면('폭은 400으로 바꾸고 길이는 그대로') 바꾼 값만 새로 넣고, 바꾸지 않은 조건(폭·제품 길이·설치 길이·통행)은 앞 검색 값 그대로 다시 넘긴다.\n"
        . "  길이가 제품 1개 길이인지 설치 총 길이인지, 숫자가 폭인지 다른 치수인지 말에 없으면 추측해 넣지 말고 확인 질문을 한다('500짜리 수로관' → spec_mm 500 으로 찾고 어느 치수인지 묻는다).\n"
        . "  → 예: '폭50cm 100m 수로 그레이팅' = query:'그레이팅 수로용 중하중', width_mm:500, length_m:100.\n"
        . "  → 예: '보도블록 200㎡' = query:'보도블록', area_m2:200.  '경계석 300m' = query:'경계석', length_m:300.\n"
        . "  → length_m/area_m2를 주면 제품별 소요 수량이 카드에 자동 계산·표시된다. 수량(개수)은 query에 넣지 말 것.\n"
        . "- 결과가 없으면(products 빈 배열): 도구가 돌려준 note 문장을 그대로 손님에게 전한다. 절대 그냥 되묻고 끝내지 말 것.\n"
        . "  → note가 없을 때만: '해당 조건의 제품을 찾지 못했습니다. 용도나 재질을 알려주시면 다시 찾아드리겠습니다.' 후 검색어를 바꿔 1회 재시도.\n"
        . "  → 결과에 alternatives(같은 두께로 등록된 다른 포장 블록 이름)가 있으면, 같은 턴에서 그 이름들을 queries 로 같은 spec_mm·spec_strict·area_m2 와 함께 search_products 를 다시 호출해 표를 보여준다(더 얇은 두께로 바꾸지 않는다).\n"
        . "【3단계: 견적 안내】\n"
        . "- 고객이 담기 원하면 add_to_cart 호출. quantity 는 답변에서 안내한 권장 수량(suggested_qty)을 쓰고, 고객이 다른 수량을 말하면 그 수량.\n"
        . "- 완료 후: '견적요청하기 버튼을 눌러 신청해 주세요.' 한 줄만 출력.\n"
        . "- 연관 제품이 더 필요할 것 같으면 추가 검색 제안.";

    // Stage 2: 카테고리별 DB 가이드 동적 주입
    if ($guideText !== '') {
        $system .= "\n\n=== 현재 상담 품목 가이드 ===\n" . $guideText;
    }

    // RAG 문서 주입
    if (!empty($ragDocs)) {
        $system .= "\n\n=== 참조 문서 (답변 시 반드시 활용) ===";
        foreach ($ragDocs as $doc) {
            $system .= "\n\n[" . $doc['title'] . "]\n" . $doc['content'];
        }
    }
    $system .= rag_block_kind_prompt($messages, $categoryKey);
    $scopedHit = (bool) array_filter($legalDocs, static fn($d) => array_intersect(rag_legal_doc_scopes($d), $legalIn['scopes']));
    $system .= rag_road_context_prompt($roadCtx, $scopedHit);
    $system .= rag_legal_prompt_section($legalDocs);
    $system .= rag_drain_notice_prompt(rag_drain_notice_keys($messages)); // 옹벽 배수관·도로 횡단배수관 주의(해당 상황만, 없으면 빈 문자열)
    $system .= rag_recent_cards_prompt($recentCards, price_basis_label());   // 이 상담에서 고객 화면에 보인 직전 추천표(담기·비교 질문용) — 없으면 빈 문자열(기존과 같음)

    return [
        'system'      => $system,
        'categoryKey' => $categoryKey,
        'ragDocs'     => $ragDocs,
        'legalDocs'   => $legalDocs,
        'roadCtx'     => $roadCtx,
        'scopes'      => $legalIn['scopes'],
    ];
}

/**
 * 이 상담(session_key)에서 마지막으로 고객 화면에 보인 상품 추천표 — chat_logs.events_json 의 최근 product_cards, 판매가·판매 상태는 현재 기준.
 * 다음 턴에 '제일 저렴한 걸로 담아 줘'·'두 번째 거 20㎡' 처럼 표를 가리키면 GPT 가 표의 실제 상품 ID 로 담게 한다(대화 기록에는 표가 글로 남지 않음).
 * @return list<array{id:int,name:string,spec:string,price:?int,qty:?int,unit:string}>
 */
function rag_recent_cards(string $sid): array
{
    if ($sid === '' || !db()) { return []; }
    try {
        $st = db()->prepare("SELECT events_json FROM chat_logs WHERE session_key = ? AND events_json LIKE '%product_cards%' ORDER BY id DESC LIMIT 1");
        $st->execute([$sid]);
        $ev = json_decode((string) $st->fetchColumn(), true) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
    $cards = [];
    foreach ($ev as $e) {
        if (($e['type'] ?? '') === 'product_cards') { $cards = array_merge($cards, (array) ($e['products'] ?? [])); }
    }
    $cards = array_slice($cards, 0, 12);
    $current = rag_current_product_prices(array_column($cards, 'id'));
    $out = [];
    foreach ($cards as $c) {
        $id = (int) ($c['id'] ?? 0);
        if ($id <= 0) { continue; }
        $now = $current[$id] ?? null;
        $out[] = ['id' => $id, 'name' => (string) ($c['name'] ?? ''), 'spec' => (string) ($c['spec_label'] ?? '') ?: (string) ($c['spec'] ?? ''),
                  // 현재 로그인 등급 단가(회원은 할인가, 비회원은 판매가) — 화면 추천표와 같은 금액
                  'price' => $current === null ? null : ($now !== null && $now['price'] > 0 ? (int) ($now['member'] ?? $now['price']) : null),
                  'qty' => isset($c['suggested_qty']) ? (int) $c['suggested_qty'] : null, 'unit' => (string) ($c['unit'] ?? '')];
    }
    return $out;
}

/**
 * 이 상담에서 고객 화면에 보인 모든 추천표의 상품 id(최근 표부터, 최대 $limit 개) — '다른 제품'·'앞에 추천한 제품은 제외' 요청 때 뺄 목록.
 * 직전 표만 빼면 두 표 전에 보인 제품이 다시 '새 추천'처럼 나온다(2026-11-10). 조회 실패 시 빈 배열.
 */
function rag_session_shown_ids(string $sid, int $limit = 30): array
{
    if ($sid === '' || !db()) { return []; }
    try {
        $st = db()->prepare("SELECT events_json FROM chat_logs WHERE session_key = ? AND events_json LIKE '%product_cards%' ORDER BY id DESC LIMIT 20");
        $st->execute([$sid]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (\Throwable $e) {
        return [];
    }
    $ids = [];
    foreach ($rows as $json) {
        foreach ((array) (json_decode((string) $json, true) ?: []) as $e) {
            if (($e['type'] ?? '') !== 'product_cards') { continue; }
            foreach ((array) ($e['products'] ?? []) as $p) {
                $id = (int) ($p['id'] ?? 0);
                if ($id > 0) { $ids[$id] = true; }
            }
        }
        if (count($ids) >= $limit) { break; }
    }
    return array_slice(array_keys($ids), 0, $limit);
}

/** 이 상담에서 마지막으로 보인 추천표의 검색 조건(ai_search_cond — product_cards 의 cond). 없으면 [] */
function rag_recent_cond(string $sid): array
{
    if ($sid === '' || !db()) { return []; }
    try {
        $st = db()->prepare("SELECT events_json FROM chat_logs WHERE session_key = ? AND events_json LIKE '%product_cards%' ORDER BY id DESC LIMIT 1");
        $st->execute([$sid]);
        $ev = json_decode((string) $st->fetchColumn(), true) ?: [];
    } catch (\Throwable $e) {
        return [];
    }
    foreach (array_reverse((array) $ev) as $e) {
        if (($e['type'] ?? '') === 'product_cards' && is_array($e['cond'] ?? null)) { return $e['cond']; }
    }
    return [];
}

/**
 * search_products 입력 준비 (순수 함수, 2026-11-11) — GPT 가 준 값에 고객이 분명히 말한 조건과 대화 흐름을 더한다. [입력, 바꾼 내용 목록].
 *  ① 통행: 분명한 말(rag_traffic_info strong)은 GPT 값보다 우선, 장소로 짐작한 값(weak)은 빠졌을 때만
 *  ② 폭·제품 길이·설치 길이: rag_apply_explicit(같은 품목이면 바꾸지 않은 조건 유지)
 *  ③ '다른 제품'·'제외'·'더 저렴한' → 이 상담에서 보인 제품 제외, '더 저렴한' → 직전 표와 비교할 수 있을 때만 금액 기준(rag_cheaper_bound)
 *  ④ 수량·최종 비교 질문·설치 길이만 새로 말함 → 직전 표 제품만 다시 확인(only_ids), 차량 조건을 새로 말함 → 앞서 추천한 제품 재확인(recheck_ids)
 *  제외(exclude_ids)·재확인(recheck_ids)은 GPT 가 넘긴 id 에 서버 목록을 더한다(GPT 는 직전 표만 알아 그 전 표 제품이 빠짐).
 * @param array{explicit:array, traffic:array, recent_cards:array, recent_cond:array, exclude:list<int>, cheaper:bool, only_recent:bool, recheck:bool, recheck_ids?:list<int>, reshow?:bool} $ctx
 */
function rag_prepare_search_input(array $input, array $ctx): array
{
    $fills = [];
    $clarify = [];
    $ti = $ctx['traffic'] ?? ['class' => '', 'strength' => ''];
    $gt = ai_traffic_value($input['traffic'] ?? null);
    if ($ti['class'] !== '' && $ti['strength'] === 'strong' && $gt !== '' && $gt !== $ti['class']) {
        $input['traffic'] = $ti['class'];
        $fills[] = "traffic {$gt} → {$ti['class']}(고객이 분명히 말한 통행 조건)";
    } elseif ($ti['class'] !== '' && $gt === '') {
        $input['traffic'] = $ti['class'];
        $fills[] = "traffic {$ti['class']} 채움(" . ($ti['strength'] === 'strong' ? '고객 말' : '장소로 짐작') . ')';
    }
    $recentCond = $ctx['recent_cond'] ?? [];
    $prevFam = rag_product_family((string) ($recentCond['query'] ?? ''));
    $fam = rag_product_family((string) ($input['query'] ?? ''));
    $sameFamily = $recentCond === [] || $prevFam === '' || $fam === '' || $fam === $prevFam;
    $explicit = $ctx['explicit'] ?? [];
    [$input, $f2] = rag_apply_explicit($input, $explicit, $sameFamily);
    $fills = array_merge($fills, $f2);
    [$input, $f3, $c3] = rag_apply_ambiguity($input, $explicit);
    $fills = array_merge($fills, $f3);
    $clarify = array_merge($clarify, $c3);
    // '비슷한 규격의 다른 제품' → 앞 표 제품의 확인된 치수(구조화 치수 칸)로. 표의 제품마다 치수가 다르면 확인 질문
    $sim = $ctx['similar_dims'] ?? [];
    if ($sim) {
        if (!empty($sim['ambiguous'])) {
            if (ai_parse_mm($input['width_mm'] ?? null) === null && ai_parse_mm($input['product_length_mm'] ?? null) === null) {
                $clarify[] = '앞 표의 제품마다 치수가 달라 어느 제품과 비슷한 규격을 원하는지 말에 없습니다. 어느 제품 기준인지 확인 질문을 한다.';
            }
        } else {
            $set = [];
            foreach (['w' => 'width_mm', 'l' => 'product_length_mm'] as $a => $k) {
                if (isset($sim[$a]) && ai_parse_mm($input[$k] ?? null) === null) { $input[$k] = $sim[$a]; $set[] = ($a === 'w' ? '폭 ' : '길이 ') . spec_axis_num($sim[$a]) . 'mm'; }
            }
            if ($set) { $fills[] = '비슷한 규격: 앞 제품의 확인된 치수(' . implode('·', $set) . ')'; }
        }
    }
    $recentIds = array_values(array_filter(array_map(static fn($c) => (int) ($c['id'] ?? 0), $ctx['recent_cards'] ?? [])));
    if ($ctx['exclude'] ?? []) {   // '다른 제품'·'앞에 보여준 제품은 빼고' → 이 상담에서 보인 제품 전체(GPT 는 직전 표만 앎 — 넘긴 id 에 더함)
        $given = ai_int_list($input['exclude_ids'] ?? null);
        $merged = array_slice(array_values(array_unique(array_merge($given, array_map('intval', $ctx['exclude'])))), 0, 30);
        if ($merged !== $given) {
            $input['exclude_ids'] = $merged;
            $fills[] = 'exclude_ids ' . ($given ? 'GPT 값에 더해 ' : '') . count($merged) . '개(이 상담에서 보인 제품)';
        }
    }
    if (!empty($ctx['cheaper']) && (int) ($input['max_est_cost'] ?? 0) <= 0 && (int) ($input['max_unit_price'] ?? 0) <= 0) {
        $b = rag_cheaper_bound($ctx['recent_cards'] ?? [], $recentCond, $input);
        $input += $b;
        $why = ['no_table' => '직전 표 없음', 'condition' => '직전 표와 조건이 다름', 'unit' => '직전 표 판매 단위가 섞임', 'no_basis' => '비교할 금액 기준 없음'];
        $fills[] = isset($b['max_est_cost']) ? "max_est_cost {$b['max_est_cost']}(직전 표 최저 예상 자재비)" : (isset($b['max_unit_price']) ? "max_unit_price {$b['max_unit_price']}/{$b['price_unit']}(직전 표 최저 단가, 같은 규격)" : '금액 기준 없음(' . ($why[$b['cheaper_incomparable']] ?? '비교 불가') . ')');
    }
    if (!empty($ctx['only_recent']) && $recentIds && $sameFamily && ai_int_list($input['only_ids'] ?? null) === [] && ai_int_list($input['exclude_ids'] ?? null) === []) {
        $input['only_ids'] = $recentIds;
        $fills[] = 'only_ids 직전 표 ' . count($recentIds) . '개(수량·비교·적합 여부는 직전 추천 제품으로)';
    }
    // 고객이 앞서 뺀 제품(직전 표가 '다른 제품' 요청으로 나온 표)은 같은 품목 검색에서 계속 뺀다(4차 — 제외 조건 유지)
    $kept = ai_int_list($recentCond['exclude_ids'] ?? null);
    if ($kept && $sameFamily && empty($ctx['exclude']) && empty($ctx['reshow']) && ai_int_list($input['only_ids'] ?? null) === []) {
        $given = ai_int_list($input['exclude_ids'] ?? null);
        $merged = array_slice(array_values(array_unique(array_merge($given, $kept))), 0, 30);
        if ($merged !== $given) {
            $input['exclude_ids'] = $merged;
            $fills[] = 'exclude_ids 유지 ' . count($kept) . '개(고객이 앞서 뺀 제품)';
        }
    }
    if (!empty($ctx['stronger']) && $recentIds && ai_int_list($input['only_ids'] ?? null) === []) {   // '더 튼튼한 거' → 앞 제품보다 하중 등급이 높다고 확인된 제품만(도구가 판정)
        $input['stronger_than_ids'] = $recentIds;
        $fills[] = 'stronger_than_ids 직전 표 ' . count($recentIds) . '개(확인된 하중 등급이 더 높은 제품만)';
    }
    $checkIds = array_values(array_map('intval', $ctx['recheck_ids'] ?? [])) ?: $recentIds;   // '앞에 추천했던 제품들' → 이 상담에서 보인 제품, 아니면 직전 표
    if (!empty($ctx['recheck']) && $checkIds && ai_int_list($input['only_ids'] ?? null) === []) {
        $given = ai_int_list($input['recheck_ids'] ?? null);
        $merged = array_slice(array_values(array_unique(array_merge($given, $checkIds))), 0, 12);
        if ($merged !== $given) {
            $input['recheck_ids'] = $merged;
            $fills[] = 'recheck_ids ' . ($given ? 'GPT 값에 더해 ' : '') . (($ctx['recheck_ids'] ?? []) ? '이 상담에서 보인 제품 ' : '직전 표 ') . count($merged) . '개(새 통행 조건으로 다시 확인)';
        }
    }
    return [$input, $fills, implode(' ', array_values(array_unique($clarify)))];
}

/**
 * 고객 말이 모호한 조건 처리 (순수 함수, 2026-11-12) — [입력, 바꾼 내용, 확인 질문 근거]. 서버가 해석을 정하지 않고 GPT 가 확인 질문을 하게 한다.
 *  piece_too_long  '길이 10m짜리 그레이팅': 1개 길이로도 설치 길이로도 넣지 않음(GPT 가 넣었으면 뺌) → 어느 길이인지 확인
 *  length_unclear  '길이는 10m': GPT 해석(설치 길이·제품 길이)은 그대로 두고, 그 기준을 밝히고 맞는지 확인
 *  relative_*      '길이만 두 배'인데 대상이 둘(제품 길이·설치 길이) 또는 없음: 확인 전까지 앞 조건 그대로(GPT 가 바꾼 값은 되돌림)
 *  unlabeled       '500짜리 수로관': GPT 가 폭(width_mm)·제품 길이로 넣었으면 규격 숫자(spec_mm — 어느 자리든)로 바꾸고 어느 치수인지 확인
 */
function rag_apply_ambiguity(array $input, array $explicit): array
{
    $fills = [];
    $clarify = [];
    $c = $explicit['cond'] ?? [];
    $near = static fn($a, $b, float $tol): bool => $a !== null && abs((float) $a - (float) $b) < $tol;
    foreach ($explicit['ambiguous'] ?? [] as $a) {
        $txt = (string) ($a['text'] ?? '');
        if ($a['kind'] === 'piece_too_long') {
            $v = (float) $a['value_m'];
            foreach (['product_length_mm' => $v * 1000, 'length_m' => $v] as $k => $want) {
                if ($near(isset($input[$k]) ? ($k === 'length_m' ? (float) $input[$k] : ai_parse_mm($input[$k])) : null, $want, $k === 'length_m' ? 0.001 : 0.5)) {
                    unset($input[$k]);
                    $fills[] = "{$k} 뺌('{$txt}' — 1개 길이인지 설치 길이인지 말에 없음)";
                }
            }
            $clarify[] = "고객이 '{$txt}'라고 했지만 1개 길이 " . spec_axis_num($v) . "m 제품은 등록 규격에 거의 없고, 설치 구간 총 " . spec_axis_num($v) . "m 인지 말에 없습니다. 길이 조건 없이 찾은 후보만 보이고, 설치 구간 총 길이인지 제품 1개 길이인지 확인 질문을 한다(확인 전에는 수량을 말하지 않음).";
        } elseif ($a['kind'] === 'length_unclear') {
            $v = (float) $a['value_m'];
            $used = $near(isset($input['length_m']) ? (float) $input['length_m'] : null, $v, 0.001) ? '설치 구간 총 길이' : ($near(ai_parse_mm($input['product_length_mm'] ?? null), $v * 1000, 0.5) ? '제품 1개 길이' : '');
            $clarify[] = "고객이 말한 '{$txt}'가 설치 구간 총 길이인지 제품 1개 길이인지 말에 없습니다. "
                . ($used !== '' ? "이번 검색은 {$used}로 했으니 답에 그 기준을 밝히고 맞는지 한 문장으로 확인한다." : '추측해 넣지 말고 확인 질문을 한다.');
        } elseif ($a['kind'] === 'unit_unclear') {
            $v = (float) $a['value_m'] * 1000;   // 말한 숫자 그대로(mm 로 읽은 값)
            $wGot = ai_parse_mm($input['width_mm'] ?? null);
            if ($wGot !== null && !isset($c['width_mm']) && ($near($wGot, $v, 0.5) || $near($wGot, $v * 10, 0.5))) {
                unset($input['width_mm']);
                $fills[] = "width_mm 뺌('{$txt}' — 단위가 말에 없음)";
            }
            $clarify[] = "고객이 '{$txt}'라고 했지만 단위가 없습니다(" . spec_axis_num($v) . 'mm 인지 ' . spec_axis_num($v) . 'cm 인지 — 등록 폭은 대부분 100mm 이상). 폭 조건 없이 찾은 후보만 보이고 단위를 확인 질문한다.';
        } elseif ($a['kind'] === 'relative_vague') {
            $keys = $a['dim'] === 'width' ? ['width_mm'] : ($a['dim'] === 'length' ? ['product_length_mm', 'length_m'] : ['width_mm', 'product_length_mm']);
            foreach ($keys as $k) {
                $cur = $k === 'length_m' ? ((float) ($input[$k] ?? 0) ?: null) : ai_parse_mm($input[$k] ?? null);
                if ($cur === null) { continue; }
                if (!isset($c[$k])) {
                    unset($input[$k]);
                    $fills[] = "{$k} 뺌('{$txt}' — 얼마나 바꿀지 말에 없음)";
                } elseif (!$near($cur, $c[$k], $k === 'length_m' ? 0.001 : 0.5)) {
                    $input[$k] = $c[$k];
                    $fills[] = "{$k} 확인 전까지 앞 조건 " . spec_axis_num($c[$k]) . ($k === 'length_m' ? 'm' : 'mm') . " 유지('{$txt}')";
                }
            }
            $clarify[] = "고객이 '{$txt}'라고 했지만 얼마나(몇 mm) 바꿀지 말에 없습니다. 앞 조건은 그대로 두고, 원하는 치수를 확인 질문한다(등록된 규격 몇 가지를 예로 들 수 있음).";
        } elseif (in_array($a['kind'], ['relative_length', 'relative_width'], true)) {
            $keys = $a['kind'] === 'relative_width' ? ['width_mm'] : ['product_length_mm', 'length_m'];
            foreach ($keys as $k) {
                $cur = $k === 'length_m' ? ((float) ($input[$k] ?? 0) ?: null) : ai_parse_mm($input[$k] ?? null);
                if ($cur === null) { continue; }
                if (!isset($c[$k])) {
                    unset($input[$k]);
                    $fills[] = "{$k} 뺌('{$txt}' — 바꿀 앞 조건이 정해지지 않음)";
                } elseif (!$near($cur, $c[$k], $k === 'length_m' ? 0.001 : 0.5)) {
                    $input[$k] = $c[$k];
                    $fills[] = "{$k} 확인 전까지 앞 조건 " . spec_axis_num($c[$k]) . ($k === 'length_m' ? 'm' : 'mm') . " 유지('{$txt}')";
                }
            }
            $clarify[] = $a['kind'] === 'relative_width'
                ? "고객이 '{$txt}'라고 했지만 앞서 말한 폭이 없어 바꿀 기준이 없습니다. 원하는 폭을 확인 질문한다."
                : "고객이 '{$txt}'라고 했지만 " . (isset($c['product_length_mm'], $c['length_m']) ? '제품 1개 길이(' . spec_axis_num($c['product_length_mm']) . 'mm)와 설치 총 길이(' . spec_axis_num($c['length_m']) . 'm) 중 어느 것인지' : '바꿀 길이(제품 1개 길이·설치 총 길이)가 앞에 없어 무엇을') . ' 말에 없습니다. 확인 전까지 앞 조건 그대로 두고, 어느 길이를 바꿀지 확인 질문을 한다.';
        }
    }
    $unl = $explicit['unlabeled'] ?? [];
    if ($unl) {
        foreach ($unl as $v) {
            foreach (['width_mm', 'product_length_mm'] as $k) {
                $labeled = $k === 'width_mm' ? array_filter($explicit['widths'] ?? [], static fn($w) => abs($w - $v) < 0.5) : (isset($c[$k]) && abs($c[$k] - $v) < 0.5);
                if (!$labeled && $near(ai_parse_mm($input[$k] ?? null), $v, 0.5)) {
                    unset($input[$k]);
                    if ((float) ($input['spec_mm'] ?? 0) <= 0) { $input['spec_mm'] = $v; }
                    $fills[] = "{$k} " . spec_axis_num($v) . " → spec_mm(고객이 치수 이름 없이 말함)";
                }
            }
        }
        $nums = implode('·', array_map('spec_axis_num', $unl));
        $clarify[] = "고객이 치수 이름 없이 '{$nums}'라고 했습니다. 폭인지 깊이·높이 등 다른 치수인지 말에 없으니 표의 size 로 제품마다 {$nums}mm 가 어느 치수인지 밝히고, 어느 치수를 말한 것인지 확인 질문을 한다(폭이라고 단정하지 않음).";
    }
    return [$input, $fills, $clarify];
}

/** 이번 고객 말에서 확인이 필요한 점 (순수 함수) — GPT 가 검색 전에 볼 시스템 안내(도구를 부르지 않아도 확인 질문하게). 없으면 '' */
function rag_clarify_hint(array $explicit): string
{
    $lines = [];
    foreach ($explicit['ambiguous'] ?? [] as $a) {
        $lines[] = match ($a['kind']) {
            'piece_too_long', 'length_unclear' => "- '{$a['text']}': 제품 1개 길이인지 설치 구간 총 길이인지 말에 없다. 추측해 정하지 말고 확인 질문을 한다(설치 총 길이로 보고 계산했다면 그 기준을 밝히고 맞는지 묻는다).",
            'relative_length' => "- '{$a['text']}': 바꿀 길이(제품 1개 길이·설치 총 길이)가 하나로 정해지지 않는다. 앞 조건은 그대로 두고 어느 길이인지 확인한다.",
            'unit_unclear' => "- '{$a['text']}': 단위(mm·cm)가 말에 없다. 추측하지 말고 단위를 확인한다.",
            'relative_vague' => "- '{$a['text']}': 얼마나 바꿀지 말에 없다. 임의의 치수를 정하지 말고 원하는 치수를 확인한다.",
            default => "- '{$a['text']}': 바꿀 기준 값이 없다. 원하는 값을 확인한다.",
        };
    }
    if ($explicit['unlabeled'] ?? []) {
        $lines[] = "- '" . implode('·', array_map('spec_axis_num', $explicit['unlabeled'])) . "': 치수 이름(폭·깊이·높이·직경) 없이 말한 숫자다. 폭으로 단정하지 말고 spec_mm 으로 찾은 뒤 어느 치수인지 확인한다.";
    }
    return $lines ? "\n\n=== 이번 고객 말에서 확인이 필요한 점(서버 판정) ===\n" . implode("\n", $lines) : '';
}

/** 직전 추천표 프롬프트 구역 (순수 함수) — 표 순서·ID·현재 단가·권장 수량·예상 자재비(현재가 × 권장 수량)와 가장 저렴한 상품 표시
 *  $priceLabel: 금액 이름(비회원 '판매가', 회원은 '' — 이름 없이 '단가'·'가격' — price_basis_label()) */
function rag_recent_cards_prompt(array $cards, string $priceLabel = '판매가'): string
{
    if (!$cards) { return ''; }
    $priced = array_filter($cards, static fn($c) => $c['price'] !== null && $c['qty'] !== null);
    $min = $priced ? min(array_map(static fn($c) => $c['price'] * $c['qty'], $priced)) : null;
    // 예상 자재비가 하나도 없으면(설치 길이·면적 없음) 같은 판매 단위일 때만 단가로 '가장 저렴'을 정한다(개·M 이 섞이면 정하지 않음, 2026-11-12)
    $withPrice = array_filter($cards, static fn($c) => $c['price'] !== null);
    $units = array_values(array_unique(array_map(static fn($c) => (string) $c['unit'], $withPrice)));
    $minUnit = (!$priced && count($withPrice) >= 2 && count($units) === 1) ? min(array_map(static fn($c) => (int) $c['price'], $withPrice)) : null;
    $lines = [];
    foreach (array_values($cards) as $i => $c) {
        $est = ($c['price'] !== null && $c['qty'] !== null) ? $c['price'] * $c['qty'] : null;
        $lines[] = ($i + 1) . ". [id {$c['id']}] {$c['name']}" . ($c['spec'] !== '' ? " {$c['spec']}" : '')
            . ' · ' . ($priceLabel !== '' ? $priceLabel : '단가') . ' ' . ($c['price'] !== null ? number_format($c['price']) . '원' . ($c['unit'] !== '' ? "/{$c['unit']}" : '') : '문의·판매 중지(담기 불가)')
            . ($minUnit !== null && $c['price'] === $minUnit ? ' (단가 가장 저렴, 같은 판매 단위)' : '')
            . ($c['qty'] !== null ? " · 권장 수량 {$c['qty']}" : '') . ($est !== null ? ' · 예상 자재비 ' . number_format($est) . '원' . ($est === $min ? ' (가장 저렴)' : '') : '');
    }
    $noEst = array_values(array_filter($withPrice, static fn($c) => $c['qty'] === null));
    $basisLine = '';
    if (!$priced && count($withPrice) >= 2 && count($units) > 1) {
        $basisLine = "\n- 이 표는 판매 단위가 서로 다르고(" . implode('·', $units) . ") 권장 수량이 없어 단가만으로 '가장 저렴한' 제품을 정할 수 없다. 고객이 가장 저렴한 것을 고르면 설치 길이·면적을 물어 예상 자재비로 비교한 뒤 정한다.";
    } elseif ($priced && $noEst) {
        $basisLine = "\n- 권장 수량이 없는 " . implode(', ', array_map(static fn($c) => $c['name'], array_slice($noEst, 0, 3))) . "은(는) 예상 자재비를 계산할 수 없어 '가장 저렴' 비교에 넣지 않았다.";
    }
    return "\n\n=== 직전 추천표 (이 상담에서 고객 화면에 보인 상품 · 현재 " . ($priceLabel !== '' ? $priceLabel : '가격') . " 기준) ===\n" . implode("\n", $lines)
        . "\n- 고객이 표의 상품을 가리켜(예: '제일 저렴한 걸로', '두 번째 거', '이걸로') 담아 달라고 하면 다시 검색하지 말고 이 표의 id 로 add_to_cart 를 호출한다(quantity 는 권장 수량, 고객이 말한 수량이 있으면 그 수량, 1~999).\n"
        . "- 이 표에 없는 id 를 만들지 않는다. " . ($priceLabel !== '' ? $priceLabel . '가' : '가격이') . " '문의·판매 중지'인 상품은 담지 말고 그 사실을 알린다. 조건(면적·두께 등)이 바뀌면 search_products 로 다시 찾는다.\n"
        . "- 고객이 '다른 제품', 'A 말고', '다시 추천', '앞에 추천한 제품은 제외'처럼 이 표와 다른 제품을 원하면 search_products 에 exclude_ids(이 표에서 뺄 id)를 넣어 다시 찾는다(같은 상품명의 다른 규격도 빠진다). 결과가 비면 note 대로 '조건에 맞는 다른 제품이 없다'고 말하고, 새 제품을 추천한 것처럼 말하지 않는다.\n"
        . "- 고객이 '좀 더 저렴한 제품'을 원하면 같은 조건(설치 길이·면적 포함)으로 exclude_ids 와 max_est_cost(이 표의 가장 낮은 예상 자재비)를 넣어 찾는다. 표에 이미 더 싼 제품이 있으면 그 제품을 먼저 알려 준다. 규격·수량·단위가 달라 예상 자재비가 없는 제품은 금액을 비교해 더 싸다고 말하지 않는다.\n"
        . "- 설치 길이·면적이 새로 정해져 '몇 개 필요해?'라고 묻거나 이 표의 제품을 최종 비교하면 only_ids(이 표의 id)와 새 length_m·area_m2 로 search_products 를 불러 도구가 계산한 수량·예상 자재비로 답한다(직접 계산 금지).\n"
        . "- 차량 통행처럼 조건이 새로 생기면 새 조건으로 검색하면서 recheck_ids(이 표의 id)를 함께 넘겨, 앞서 추천한 제품이 새 조건에 맞는지(recheck) 알린다. 조건에 맞지 않게 된 제품을 계속 권하지 않는다."
        . (substr_count(implode("\n", $lines), '(가장 저렴)') > 1 ? "\n- '(가장 저렴)'이 여러 개다(같은 금액). '제일 저렴한 것'을 담을 때는 표에서 먼저 나온 제품을 담고, 같은 금액의 다른 제품(이름)도 있다고 한 문장으로 알린다." : '')
        . $basisLine;
}

/**
 * 고객이 말한 통행 조건 (순수 함수) — 그레이팅 하중 등급(search_products traffic)을 GPT 가 넘기지 않았을 때 서버가 채우는 값.
 * 고객 발화(user)만 본다. 안전 쪽으로: 차량 단서가 한 번이라도 있으면 차량(대형차 단서면 truck, 아니면 car), 차량 단서 없이
 * 보행자 단서(주방·실내·인도·보행자 전용·'차는 안 다녀요' 등)만 있으면 pedestrian, 둘 다 없으면 ''(필터 안 함).
 * 2026-11-09 운영 상담: '식당 주방'·'보행자 전용으로' 라고 했는데 대형차용 안전중하중 그레이팅을 추천.
 */
function rag_traffic_context(array $messages): string
{
    return rag_traffic_info($messages)['class'];
}

/**
 * 고객이 말한 통행 조건과 그 확실성 (순수 함수, 2026-11-11) — ['class' => pedestrian·car·truck·'', 'strength' => strong·weak·'', 'last_strong' => bool].
 *  strong(분명한 말): 차량 종류·'차량이 다녀요'·'차량 통행'·톤수 / '사람만'·'보행자 전용'·'차는 안 다녀요'. 고객 말 순서대로 나중 말이 이긴다.
 *  weak(장소로 짐작): 도로·주차장·진입로 → 차량, 주방·실내·인도·공원 → 보행자. 분명한 말이 없을 때만, 차량 쪽을 먼저(안전 쪽).
 *  last_strong: 마지막 고객 말에 분명한 통행 조건이 있음(새로 말한 조건 — 앞서 추천한 제품을 다시 확인할 때 씀).
 * 서버는 strong 이면 GPT 값을 고객 값으로 바로잡고, weak 이면 GPT 가 빠뜨렸을 때만 채운다.
 */
function rag_traffic_info(array $messages): array
{
    $neg = '/(차량|자동차|승용차|트럭|화물차|차)\s*(은|는|이|가|도)?\s*(통행\s*(은|이)?\s*)?(없|안\s*(다니|다녀|들어|지나|올라|와)|못\s*(다니|다녀|들어|지나)|다니지\s*않|들어오지\s*않|지나가지\s*않|안\s*됨|불가|금지)/u';
    $freq = '(?:(?:가끔|간혹|종종|이따금|드물게|자주|많이|조금|거의\s*안|가끔씩)\s*)?';   // '차는 가끔 다녀요'·'차가 거의 안 다녀요'
    $strong = '';
    $weakVehicle = false;
    $weakPed = false;
    $lastStrong = false;
    $users = array_values(array_filter($messages, static fn($m) => ($m['role'] ?? '') === 'user'));
    foreach ($users as $i => $m) {
        $t = mb_strtolower((string) ($m['content'] ?? ''));
        $msgStrong = '';
        if (preg_match($neg, $t)) { $msgStrong = 'pedestrian'; }
        $t = (string) preg_replace($neg, ' ', $t);
        $truck = (bool) preg_match('/트럭|화물차|덤프|대형\s*차|버스|지게차|중장비|레미콘|탑차|트레일러|굴삭기|포크레인/u', $t);
        $tons = preg_match_all('/(\d+(?:\.\d+)?)\s*톤/u', $t, $tm) ? array_map('floatval', $tm[1]) : [];
        foreach ($tons as $ton) { if ($ton >= 3) { $truck = true; } }
        if ($truck) {
            $msgStrong = 'truck';
        } elseif ($tons || preg_match('/승용차|자동차|자가용|suv|택시|오토바이|차량\s*(이|도|은|는)?\s*' . $freq . '(다니|다녀|지나|들어|올라|통행|출입)|차량\s*통행|차\s*(가|도|는|들이)?\s*' . $freq . '(다니|다녀|지나|들어|올라|통행|출입)/u', $t)) {
            $msgStrong = 'car';   // '가끔'·'거의 안'이라도 차량이 다니면 차량(안전 쪽)
        } elseif ($msgStrong === '' && preg_match('/사람만|사람\s*만\s*다|보행자\s*(전용|만)|보행\s*전용|도보\s*전용|(사람|보행자)\s*(이랑|하고|과|와)\s*(자전거|유모차|휠체어)\s*만/u', $t)) {
            $msgStrong = 'pedestrian';
        }
        if ($msgStrong !== '') {
            $strong = $msgStrong;
            $lastStrong = $i === count($users) - 1;
        }
        if (preg_match('/차량|주차|차도|(?<!보)도로|진입로/u', $t)) { $weakVehicle = true; }
        if (preg_match('/보행|도보|주방|부엌|식당|화장실|욕실|실내|베란다|발코니|옥상|인도|보도(?!\s*블)|공원|산책로|놀이터|정원|복도|매장\s*안|가게\s*안|집\s*안|건물\s*안/u', $t)) { $weakPed = true; }
    }
    if ($strong !== '') {
        return ['class' => $strong, 'strength' => 'strong', 'last_strong' => $lastStrong];
    }
    if ($weakVehicle) {
        return ['class' => 'car', 'strength' => 'weak', 'last_strong' => false];
    }
    return ['class' => $weakPed ? 'pedestrian' : '', 'strength' => $weakPed ? 'weak' : '', 'last_strong' => false];
}

/** 품목 이름에 붙은 길이를 제품 1개 길이로 볼 최대값(mm) — 관 6m 까지. 더 길면('길이 10m 그레이팅') 설치 길이일 수 있어 서버가 정하지 않는다 */
const RAG_PRODUCT_LENGTH_MAX_MM = 6000;
/** 관이 아닌 품목(그레이팅·수로관·경계석 등)의 1개 길이로 볼 최대값(mm) — 등록 규격 대부분 1~2.5m. 넘으면 설치 길이일 수 있어 확인 질문 */
const RAG_PRODUCT_LENGTH_MAX_MM_GENERAL = 3000;

/**
 * 고객 말에서 분명히 확인되는 검색 조건 (순수 함수, 2026-11-11 · 2026-11-12 모호한 말 구분 추가) — 서버가 GPT 검색 입력을 바로잡을 때만 쓴다(말하지 않은 조건은 만들지 않음).
 *  width_mm          '폭 500mm'·'폭은 400으로'·'50센테 폭'·'W500'(단위 없으면 mm)
 *  product_length_mm 제품 1개 길이 — '길이 1m 그레이팅'·'길이는 2m인 제품'·'제품 길이 1000'·'길이 2m짜리'·'L1000'
 *  length_m          설치 총 길이 — '설치 구간이 총 20m'·'총 20m'·'연장 50m'·'20m 구간'·'20m 설치'·'20m 필요해'
 *  상대 변경         '길이만 두 배로'·'폭을 절반으로' — 앞서 말한 값이 하나로 정해질 때만 곱한다(제품 길이와 설치 길이가 둘 다 있거나 없으면 모호)
 * 고객 말 순서대로 읽어 나중 값이 이긴다('폭은 400으로 바꾸고' → 400). last: 마지막 고객 말에서 말한 칸. widths: 말한 폭 전부.
 * 마지막 고객 말만 따로 본다(확인 질문 근거):
 *  ambiguous  어느 길이인지 말에 없음(length_unclear: '길이는 10m'·'10미터길이에') · 1개 길이로 보기엔 너무 긴 '짜리'(piece_too_long: '길이 10m짜리 그레이팅')
 *             · 상대 변경 대상이 모호(relative_length: 제품 길이·설치 길이가 둘 다 있거나 없음)
 *  unlabeled  치수 이름 없이 말한 숫자(mm) — '500짜리 수로관'(폭인지 깊이인지 말에 없음)
 * @return array{cond: array<string,float>, released: array<string,bool>, last: list<string>, widths: list<float>,
 *               ambiguous: list<array{kind:string, text:string, value_m?:float, factor?:float}>, unlabeled: list<float>}
 */
function rag_explicit_conditions(array $messages): array
{
    $num   = '(\d+(?:\.\d+)?)';
    $uAll  = '\s*(mm|㎜|밀리|cm|㎝|센티|센치|센테|미터|m)?';
    $uM    = '\s*(미터|m)';
    $noun  = '(?:제품|자재|그레이팅|수로관|덮개|뚜껑|트렌치|경계석|블록|블럭|플륨관|커버|관)';
    $pats = [
        'width_mm'          => ['/(?:폭|너비)\s*(?:은|는|이|가|:)?\s*(?:약\s*)?' . $num . $uAll . '/u', '/' . $num . $uAll . '\s*(?:짜리\s*)?(?:폭|너비)/u',
                                '/(?<![a-z])w\s*=?\s*' . $num . $uAll . '/iu'],   // W500(규격 표기 — 폭)
        'product_length_mm' => ['/(?:제품|1\s*개|한\s*개|개당|하나|낱개)\s*(?:의\s*)?길이\s*(?:는|가|이|:)?\s*(?:약\s*)?' . $num . $uAll . '/u',
                                '/길이\s*(?:는|가|이|:)?\s*(?:약\s*)?' . $num . $uAll . '\s*(?:인|짜리|의|되는)?\s*' . $noun . '/u',
                                '/길이\s*(?:는|가|이|:)?\s*' . $num . $uAll . '\s*짜리/u',
                                '/(?<![a-z])l\s*=?\s*' . $num . $uAll . '(?![a-z])/iu',   // L1000(규격 표기 — 길이)
                                '/(?<![\d.])' . $num . $uM . '\s*짜리/u',   // '1m짜리 그레이팅'·'2미터짜리 수로관'(1개 길이 — 길면 확인)
                                // '폭 400 길이 1m'처럼 폭과 붙여 말한 길이 = 제품 크기(3m 이하만 — '폭 500이고 길이는 10m'는 설치 길이일 수 있어 확인)
                                '/(?:폭|너비)\s*(?:은|는|이|가|:)?\s*\d+(?:\.\d+)?\s*(?:mm|㎜|cm|㎝|센티|센치|센테|미터|m)?\s*(?:이고|에|,|·|x|×|\*)?\s*길이\s*(?:는|가|이|:)?\s*(?:약\s*)?' . $num . $uAll . '/u'],
        'length_m'          => ['/(?:설치|시공)\s*(?:구간|길이|연장|거리)?\s*(?:이|은|는|가|:)?\s*(?:총\s*)?(?:약\s*)?' . $num . $uM . '/u',
                                '/(?:총|전체)\s*(?:길이|연장|구간|거리)?\s*(?:이|은|는|가|:)?\s*(?:약\s*)?' . $num . $uM . '/u',
                                '/(?:구간|연장)\s*(?:이|은|는|가|:)?\s*(?:총\s*)?(?:약\s*)?' . $num . $uM . '/u',
                                '/' . $num . $uM . '\s*(?:구간|연장)/u',
                                '/' . $num . $uM . '\s*(?:정도|쯤|가량|내외)?\s*(?:를|을|가|이)?\s*(?:설치|시공|깔|놓|필요|들어)/u'],
    ];
    $rel = ['width_mm' => '/(?:폭|너비)\s*(?:은|는|조건은)?\s*(?:상관\s*(?:없|없이)|무관|아무거나)/u', 'product_length_mm' => '/(?:제품\s*)?길이\s*(?:은|는|조건은)?\s*(?:상관\s*(?:없|없이)|무관|아무거나)/u'];
    $mulRe = '/(폭|너비|길이)\s*(?:만|을|를|은|는|도)?\s*(?:(두|세|네|\d+(?:\.\d+)?)\s*배|(절반|반\s*으로))/u';
    $factor = static fn(string $a, string $b): float => $b !== '' ? 0.5 : (float) (['두' => 2, '세' => 3, '네' => 4][$a] ?? (float) $a);
    $cond = $released = [];
    $widths = [];
    $last = [];
    $ambiguous = [];
    $unlabeled = [];
    $users = array_values(array_filter($messages, static fn($m) => ($m['role'] ?? '') === 'user'));
    foreach ($users as $i => $m) {
        $t = (string) ($m['content'] ?? '');
        $isLast = $i === count($users) - 1;
        // 품목 이름에 붙은 길이를 1개 길이로 볼 최대값 — 관(흄관·PE관 등)은 6m, 그 밖(그레이팅·수로관·경계석 등)은 3m
        $maxPiece = preg_match('/흄관|이중벽관|다중벽관|pe\s*관|배수관|하수관|우수관|파이프|관경/iu', $t) ? RAG_PRODUCT_LENGTH_MAX_MM : RAG_PRODUCT_LENGTH_MAX_MM_GENERAL;
        $hits = [];   // [offset, key, value, text]
        $spans = [];  // 칸을 정한 말의 위치(모호한 말 찾기에서 뺌)
        foreach ($pats as $k => $list) {
            foreach ($list as $re) {
                if (!preg_match_all($re, $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { continue; }
                foreach ($mm as $x) {
                    $n = (float) $x[1][0];
                    $u = mb_strtolower((string) ($x[2][0] ?? ''));
                    $v = match ($k) {
                        'width_mm'          => ai_parse_mm($x[1][0] . $u),
                        'product_length_mm' => $u === '' ? ($n <= 10 && !preg_match('/^l/iu', $x[0][0]) ? round($n * 1000, 1) : round($n, 1)) : ai_parse_mm($x[1][0] . $u),
                        'length_m'          => $n > 0 ? $n : null,
                    };
                    if ($k === 'product_length_mm' && preg_match('/^(폭|너비)/u', $x[0][0]) && ($v === null || $v > RAG_PRODUCT_LENGTH_MAX_MM_GENERAL)) { continue; }   // 폭과 붙인 긴 길이는 칸을 정하지 않음(모호한 길이로)
                    if ($k === 'width_mm' && $u === '' && $n < 100) {   // '폭 30'(단위 없음) — 30mm 인지 30cm 인지 말에 없음(등록 폭은 대부분 100mm 이상) → 확인
                        $spans[] = [$x[0][1], $x[0][1] + strlen($x[0][0])];
                        if ($isLast) { $ambiguous[] = ['kind' => 'unit_unclear', 'text' => trim($x[0][0]), 'value_m' => $n / 1000]; }
                        continue;
                    }
                    $spans[] = [$x[0][1], $x[0][1] + strlen($x[0][0])];
                    // 품목 이름에 붙은 긴 길이('길이 10m짜리 그레이팅')는 1개 길이인지 설치 길이인지 말로 확인되지 않음 → 넣지 않고 확인 질문 근거로
                    if ($k === 'product_length_mm' && $v !== null && $v > $maxPiece && !preg_match('/제품|개|개당|하나|낱개/u', mb_substr($x[0][0], 0, 6))) {
                        if ($isLast) { $ambiguous[] = ['kind' => 'piece_too_long', 'text' => trim($x[0][0]), 'value_m' => round($v / 1000, 3)]; }
                        continue;
                    }
                    if ($v !== null && $v > 0) { $hits[] = [$x[0][1], $k, (float) $v, $x[0][0]]; }
                }
            }
        }
        foreach ($rel as $k => $re) {
            if (preg_match($re, $t, $rm, PREG_OFFSET_CAPTURE)) { $hits[] = [$rm[0][1], $k, null, $rm[0][0]]; }
        }
        if (preg_match_all($mulRe, $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($mm as $x) {
                $hits[] = [$x[0][1], in_array($x[1][0], ['폭', '너비'], true) ? 'mul:width' : 'mul:length', $factor((string) ($x[2][0] ?? ''), (string) ($x[3][0] ?? '')), $x[0][0]];
                $spans[] = [$x[0][1], $x[0][1] + strlen($x[0][0])];
            }
        }
        usort($hits, static fn($a, $b) => $a[0] <=> $b[0]);
        foreach ($hits as [, $k, $v, $txt]) {
            if (str_starts_with($k, 'mul:')) {   // 상대 변경: 대상이 하나로 정해질 때만
                $onlyOne = (isset($cond['product_length_mm']) xor isset($cond['length_m']));
                $target = $k === 'mul:width' ? (isset($cond['width_mm']) ? 'width_mm' : '')
                    : ($onlyOne ? (isset($cond['product_length_mm']) ? 'product_length_mm' : 'length_m') : '');
                if ($target === '') {
                    if ($isLast) { $ambiguous[] = ['kind' => $k === 'mul:width' ? 'relative_width' : 'relative_length', 'text' => trim($txt), 'factor' => $v]; }
                    continue;
                }
                $cond[$target] = round($cond[$target] * $v, 3);
                if ($target === 'width_mm') { $widths[] = $cond[$target]; }
                if ($isLast) { $last[$target] = true; }
                continue;
            }
            if ($v === null) { unset($cond[$k]); $released[$k] = true; }
            else { $cond[$k] = $v; unset($released[$k]); if ($k === 'width_mm') { $widths[] = $v; } }
            if ($isLast) { $last[$k] = true; }
        }
        if (!$isLast) { continue; }
        $free = static function (int $off) use ($spans): bool {
            foreach ($spans as [$a, $b]) { if ($off >= $a && $off < $b) { return false; } }
            return true;
        };
        // 어느 길이인지 말에 없는 길이('길이는 10m', '10미터길이에')
        foreach (['/길이\s*(?:는|가|이|:)?\s*(?:약\s*)?' . $num . $uM . '/u', '/' . $num . $uM . '\s*길이/u'] as $re) {
            if (!preg_match_all($re, $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) { continue; }
            foreach ($mm as $x) {
                if ($free($x[1][1])) { $ambiguous[] = ['kind' => 'length_unclear', 'text' => trim($x[0][0]), 'value_m' => (float) $x[1][0]]; }
            }
        }
        // 치수 이름 없이 말한 숫자('500짜리 수로관') — 앞에 폭·높이·직경 같은 이름이나 D·Ø·W 같은 표기가 없을 때만
        if (preg_match_all('/(?<![\d.])(\d{2,4})\s*(mm|㎜|밀리|cm|㎝|센티|센치|센테)?\s*(?:짜리|인\s*' . $noun . '|' . $noun . ')/u', $t, $mm, PREG_SET_ORDER | PREG_OFFSET_CAPTURE)) {
            foreach ($mm as $x) {
                $before = substr($t, max(0, $x[1][1] - 24), min(24, $x[1][1]));
                if (!$free($x[1][1]) || preg_match('/(폭|너비|길이|높이|두께|깊이|직경|지름|내경|외경|관경|호칭|규격|[a-zA-Zφ∅Ø])\s*(?:은|는|이|가|:|=)?\s*(?:약\s*)?$/u', $before)) { continue; }
                $unlabeled[] = (float) $x[1][0] * (preg_match('/^(cm|㎝|센티|센치|센테)$/u', (string) ($x[2][0] ?? '')) ? 10 : 1);   // '50센치짜리' → 500mm
            }
        }
        // 기준 없는 상대 변경('좀 더 넓은 거'·'조금 더 짧은 걸로') — 얼마나 바꿀지 말에 없음 → 앞 조건 그대로 두고 확인
        if (preg_match('/(?:좀|조금|약간|살짝|훨씬)?\s*더\s*(넓|좁|길|짧|크|작|두껍|얇)(?:은|게|었|던|ㄴ|운)/u', $t, $vm)) {
            $ambiguous[] = ['kind' => 'relative_vague', 'text' => trim($vm[0]), 'dim' => in_array($vm[1], ['넓', '좁'], true) ? 'width' : (in_array($vm[1], ['길', '짧'], true) ? 'length' : 'size')];
        }
    }
    $seen = [];
    $ambiguous = array_values(array_filter($ambiguous, static function (array $a) use (&$seen): bool {   // 같은 말이 여러 형태에 걸리면 한 번만
        $key = $a['kind'] . '|' . ($a['value_m'] ?? $a['factor'] ?? '');
        if (isset($seen[$key])) { return false; }
        $seen[$key] = true;
        return true;
    }));
    return ['cond' => $cond, 'released' => $released, 'last' => array_keys($last), 'widths' => $widths, 'ambiguous' => $ambiguous, 'unlabeled' => array_values(array_unique($unlabeled))];
}

/** 검색어의 품목 종류 (순수 함수) — 조건 유지(이전 표와 같은 품목인지) 판단용. 모르면 '' */
function rag_product_family(string $query): string
{
    $q = str_replace(' ', '', $query);
    foreach (['그레이팅' => '그레이팅', '트렌치' => '그레이팅', '덮개' => '그레이팅', '뚜껑' => '그레이팅', '수로관' => '수로관', '플륨관' => '수로관',
              '맨홀' => '맨홀', '집수정' => '맨홀', '경계석' => '경계석', '블록' => '블록', '블럭' => '블록', '흄관' => '관', '배수관' => '관', '이중벽관' => '관', '다중벽관' => '관'] as $k => $f) {
        if (str_contains($q, $k)) { return $f; }
    }
    return '';
}

/**
 * GPT 검색 입력을 고객이 분명히 말한 조건으로 바로잡기 (순수 함수, 2026-11-11) — [입력, 바꾼 내용 목록].
 *  ① 칸을 잘못 넣은 값: 고객이 제품 길이로 말한 값을 설치 길이(length_m)로(또는 반대로) 넘기면 옮기고, 폭·제품 길이로 말한 숫자를 spec_mm 으로 넘기면 그 칸으로.
 *  ② 마지막 고객 말의 조건: GPT 가 빠뜨렸거나 다르게 넘겼으면 고객 값으로('폭은 400으로 바꾸고' → 400).
 *  ③ 앞선 말의 조건: GPT 가 빠뜨렸을 때만, 같은 품목 검색일 때만 채운다('길이는 그대로' — 바꾸지 않은 조건 유지).
 *  ④ '상관없어'로 지운 조건은 뺀다. 고객이 말하지 않은 조건은 만들지 않는다.
 */
function rag_apply_explicit(array $input, array $explicit, bool $sameFamily): array
{
    $c = $explicit['cond'] ?? [];
    $fills = [];
    $mm = static fn($v): ?float => ai_parse_mm($v);
    $gil = (float) ($input['length_m'] ?? 0);
    $gpl = $mm($input['product_length_mm'] ?? null);
    if (isset($c['product_length_mm']) && $gil > 0 && abs($gil * 1000 - $c['product_length_mm']) < 0.5 && !(isset($c['length_m']) && abs($c['length_m'] - $gil) < 0.001)) {
        unset($input['length_m']);
        $fills[] = "length_m {$gil}m → 제품 길이(고객: 제품 1개 길이 " . spec_axis_num($c['product_length_mm']) . 'mm)';
        if ($gpl === null) { $input['product_length_mm'] = $c['product_length_mm']; }
    }
    if (isset($c['length_m']) && $gpl !== null && abs($gpl - $c['length_m'] * 1000) < 0.5 && !(isset($c['product_length_mm']) && abs($c['product_length_mm'] - $gpl) < 0.5)) {
        unset($input['product_length_mm']);
        $fills[] = 'product_length_mm ' . spec_axis_num($gpl) . "mm → 설치 길이(고객: 설치 총 길이 {$c['length_m']}m)";
        if ((float) ($input['length_m'] ?? 0) <= 0) { $input['length_m'] = $c['length_m']; }
    }
    $gs = (float) ($input['spec_mm'] ?? 0);
    if ($gs > 0 && empty($input['spec_strict'])) {
        $asWidth = array_filter($explicit['widths'] ?? [], static fn($w) => abs($w - $gs) < 0.5);
        if ($asWidth || (isset($c['product_length_mm']) && abs($c['product_length_mm'] - $gs) < 0.5)) {
            unset($input['spec_mm']);
            $fills[] = "spec_mm {$gs} → " . ($asWidth ? '폭' : '제품 길이') . '(고객이 그 치수로 말함)';
            if (!$asWidth && $mm($input['product_length_mm'] ?? null) === null) { $input['product_length_mm'] = $c['product_length_mm']; }
            if ($asWidth && $mm($input['width_mm'] ?? null) === null) { $input['width_mm'] = $c['width_mm'] ?? $gs; }
        }
    }
    foreach (['width_mm' => '폭', 'product_length_mm' => '제품 길이', 'length_m' => '설치 길이'] as $k => $label) {
        if (!empty($explicit['released'][$k]) && isset($input[$k])) {
            unset($input[$k]);
            $fills[] = "{$label} 조건 뺌(고객: 상관없음)";
            continue;
        }
        if (!isset($c[$k])) { continue; }
        $inLast = in_array($k, $explicit['last'] ?? [], true);
        $cur = $k === 'length_m' ? ((float) ($input[$k] ?? 0) ?: null) : $mm($input[$k] ?? null);
        $unit = $k === 'length_m' ? 'm' : 'mm';
        if ($cur === null && ($inLast || $sameFamily)) {
            $input[$k] = $c[$k];
            $fills[] = "{$label} " . spec_axis_num($c[$k]) . "{$unit} " . ($inLast ? '반영(고객 말)' : '유지(앞서 고객이 말한 조건)');
        } elseif ($cur !== null && $inLast && abs($cur - $c[$k]) > ($k === 'length_m' ? 0.001 : 0.5)) {
            $fills[] = "{$label} " . spec_axis_num($cur) . "{$unit} → " . spec_axis_num($c[$k]) . "{$unit}(고객이 마지막에 말한 값)";
            $input[$k] = $c[$k];
        }
    }
    return [$input, $fills];
}

/** 수량을 묻는 말인지 (순수 함수) — '몇 개 필요해?'·'수량'·'얼마나 사야' */
function rag_wants_quantity(string $msg): bool
{
    return preg_match('/몇\s*(개|장|본|조|m|미터|ea)|수량|얼마나\s*(필요|들|사야)|몇\s*개나/iu', $msg) === 1;
}

/** 앞서 추천·표시한 제품을 가리키는 말인지 (순수 함수) — '앞에 추천했던 제품들'·'아까 보여준 거'·'처음 추천한 제품' */
function rag_wants_previous(string $msg): bool
{
    return preg_match('/(앞에서?|앞서|아까|이전에?|전에|처음에?)\s*(추천|보여|봤|본|나온|나왔|말한|알려)/u', $msg) === 1;
}

/** '아까 그거 다시 보여줘'·'제외했던 거 다시 보여줘' — 앞서 뺀(제외한) 제품을 다시 보길 원하는 말인지 (순수 함수). '다시 추천'(다른 제품)은 아님 */
function rag_wants_reshow(string $msg): bool
{
    return preg_match('/다시\s*(보여|볼\s*수|보고\s*싶|띄워)|(제외|뺐|뺀|빼)(했던|던|한|었던)?\s*(거|것|제품|걸).{0,8}(다시|보여|포함)/u', $msg) === 1;
}

/** '더 튼튼한 거'·'더 강한 제품'·'하중이 더 큰' — 앞 제품보다 하중 등급이 높은 제품을 원하는 말인지 (순수 함수) */
function rag_wants_stronger(string $msg): bool
{
    return preg_match('/(더|좀\s*더|훨씬|조금\s*더)\s*(튼튼|강한|강하|단단|견고|하중이?\s*(큰|높은|센|강한))|하중\s*(등급)?\s*(이|가)?\s*더\s*(높은|큰|센|강한)|하중\s*등급이?\s*(높은|큰)/u', $msg) === 1;
}

/** '비슷한 규격의 다른 제품'·'같은 크기로' — 앞 표 제품과 같은 치수를 원하는 말인지 (순수 함수) */
function rag_wants_similar(string $msg): bool
{
    return preg_match('/(비슷한|같은|동일한)\s*(규격|크기|사이즈|치수)/u', $msg) === 1;
}

/** '이 제품 써도 돼?'·'이거 차량 다니는데 괜찮아?' — 지금 표의 제품이 조건에 맞는지 묻는 말인지 (순수 함수) */
function rag_wants_suitability(string $msg): bool
{
    return preg_match('/(이|그|저)\s*(제품|거|것|걸|자재)|이걸|이거|그거/u', $msg) === 1
        && preg_match('/(써도|사용해도|설치해도|깔아도|놓아도)\s*(돼|되|될|괜찮)|(괜찮|적합|맞)(아|나|을까|한가|해|은가|는지|나요|습니까)/u', $msg) === 1
        && !rag_wants_previous($msg);
}

/**
 * 앞 표 제품들의 공통 확인 치수 (순수 함수) — '비슷한 규격' 기준. 구조화 치수 칸(채운 기준 원문 = 지금 규격)만 쓴다(숫자 자리로 추측하지 않음).
 * 모두 같은 폭·길이면 ['w' => …, 'l' => …](있는 것만), 제품마다 다르면 ['ambiguous' => true], 확인된 치수가 없으면 [].
 */
function rag_common_dims(array $rows): array
{
    $vals = ['w' => [], 'l' => []];
    foreach ($rows as $r) {
        if (!spec_axes_row_fresh($r)) { continue; }
        foreach (['w' => 'dim_w', 'l' => 'dim_l'] as $a => $col) {
            if (($r[$col] ?? null) !== null && $r[$col] !== '') { $vals[$a][] = (float) $r[$col]; }
        }
    }
    $out = [];
    foreach ($vals as $a => $vs) {
        $u = array_values(array_unique($vs));
        if (count($u) > 1) { return ['ambiguous' => true]; }
        if ($u) { $out[$a] = $u[0]; }
    }
    return $out;
}

/** 표의 제품끼리 비교를 원하는 말인지 (순수 함수) — '비교해줘'·'뭐가 나아?'·'어느 게 좋아?' */
function rag_wants_compare(string $msg): bool
{
    return preg_match('/비교|뭐가\s*(더\s*)?(나아|좋아|낫)|어느\s*(게|것|쪽|제품)/u', $msg) === 1;
}

/**
 * '더 저렴한 제품'의 금액 기준 (순수 함수, 2026-11-11) — 직전 표와 비교할 수 있을 때만 기준을 준다(조건이 바뀐 뒤 옛 금액을 다시 쓰지 않게).
 *  같은 설치 길이·면적·폭·제품 길이·통행 조건이고 직전 표에 예상 자재비가 있으면 ['max_est_cost' => 최저 예상 자재비]
 *  설치 길이·면적이 없고 폭·제품 길이가 같으면(같은 규격) 같은 판매 단위의 최저 단가 ['max_unit_price', 'price_unit']
 *  그 밖에는 ['cheaper_incomparable' => 이유] — 금액 기준 없이 이번 조건에서 낮은 금액 순. 이유: no_table(직전 표·조건 기록 없음) ·
 *  condition(폭·제품 길이·설치 길이·면적·통행이 바뀜) · unit(설치 길이 없이 판매 단위가 섞임 — 개·M) · no_basis(같은 조건이지만 비교할 금액·규격 기준 없음)
 */
function rag_cheaper_bound(array $recentCards, array $recentCond, array $input): array
{
    $same = static function (string $k) use ($recentCond, $input): bool {
        $a = $recentCond[$k] ?? null;
        $b = $input[$k] ?? null;
        if ($a === null || $a === '' || (is_numeric($a) && (float) $a == 0.0)) { $a = null; }
        if ($b === null || $b === '' || (is_numeric($b) && (float) $b == 0.0)) { $b = null; }
        return ($a === null && $b === null) || ($a !== null && $b !== null && (is_numeric($a) ? abs((float) $a - (float) $b) < 0.5 : (string) $a === (string) $b));
    };
    if (!$recentCards || !$recentCond) {
        return ['cheaper_incomparable' => 'no_table'];
    }
    $sameSize = $same('width_mm') && $same('product_length_mm') && $same('spec_mm') && $same('traffic');
    if (!$sameSize || !$same('length_m') || !$same('area_m2')) {
        return ['cheaper_incomparable' => 'condition'];
    }
    if (isset($recentCond['length_m']) || isset($recentCond['area_m2'])) {
        $est = rag_recent_min_est($recentCards);
        return $est > 0 ? ['max_est_cost' => $est] : ['cheaper_incomparable' => 'no_basis'];
    }
    if (!isset($recentCond['width_mm'], $recentCond['product_length_mm'])) {
        return ['cheaper_incomparable' => 'no_basis'];
    }
    $byUnit = [];
    foreach ($recentCards as $rc) {
        if (($rc['price'] ?? null) !== null && (string) ($rc['unit'] ?? '') !== '') { $byUnit[(string) $rc['unit']][] = (int) $rc['price']; }
    }
    if (count($byUnit) !== 1) {
        return ['cheaper_incomparable' => $byUnit ? 'unit' : 'no_basis'];
    }
    $u = (string) array_key_first($byUnit);
    return ['max_unit_price' => min($byUnit[$u]), 'price_unit' => $u];
}

/**
 * 고객이 직전 추천표와 '다른 제품'을 원하는지 (순수 함수) — 'A 말고', '다른 자재 추천', '다시 추천', '딴 거', '앞에 추천한 제품은 제외해줘',
 * '좀 더 저렴한 제품'(rag_wants_cheaper). '다른 규격·다른 수량'처럼 같은 제품의 조건만 바꾸는 말, '저렴한 제품부터'(순서)는 해당하지 않는다.
 */
function rag_wants_alternative(string $msg): bool
{
    return preg_match('/(?<!하지|담지|넣지|사지|하지 |담지 |넣지 |사지 )말고|다른\s*(제품|자재|상품|거|것|걸|종류|모델|브랜드|추천|대안|그레이팅)|딴\s*(거|것|걸|제품)|다시\s*추천|재\s*추천|바꿔\s*(서\s*)?추천|대안|대체\s*(품|제품|자재)|(제품|자재|상품|거|것|건|걸|표)[은는을를]?\s*(빼고|빼\s*줘|빼\s*주세요|제외)/u', $msg) === 1
        || rag_wants_cheaper($msg);
}

/** 고객이 직전 추천표보다 '더 저렴한 제품'을 원하는지 (순수 함수) — '좀 더 저렴한 제품', '더 싼 거 없어?', '저렴한 거 있어?'. '저렴한 제품부터'(정렬)는 아님 */
function rag_wants_cheaper(string $msg): bool
{
    return preg_match('/(더|좀\s*더|훨씬|조금\s*더)\s*(저렴|싼|싸|값싼)|(저렴|(?<!비)싼|값싼)\s*(한\s*)?(거|것|걸|제품|자재)?\s*(는\s*)?(없|있)|가격(이|을)?\s*(더\s*)?(낮은|낮춘)/u', $msg) === 1;
}

/** 직전 추천표의 가장 낮은 예상 자재비(현재 단가 × 권장 수량, 비교 가능한 것만) — '더 저렴한 제품' 기준. 없으면 0 (순수 함수) */
function rag_recent_min_est(array $recentCards): int
{
    $est = array_filter(array_map(static fn($c) => ($c['price'] ?? null) !== null && ($c['qty'] ?? null) !== null ? (int) $c['price'] * (int) $c['qty'] : null, $recentCards), static fn($v) => $v !== null && $v > 0);
    return $est ? (int) min($est) : 0;
}

/**
 * 동률 최저가 안내 (순수 함수) — 담은 상품이 예상 자재비 최저 동률(2개 이상) 중 하나이고 답에 '같은 금액' 언급이 없으면 다른 동률 상품 이름을 알리는 한 줄.
 * $turnCards: 이번 턴 검색 카드(cost_lowest 표시), $recent: 직전 추천표(rag_recent_cards — price·qty 로 예상 금액 계산). 해당 없으면 ''.
 */
function rag_tie_note(string $text, array $addedIds, array $turnCards, array $recent): string
{
    if (!$addedIds || preg_match('/같은\s*(예상\s*자재비|금액|가격)|동일한\s*(금액|가격)|가격이\s*같|금액이\s*같|자재비가\s*같/u', $text)) { return ''; }
    $tied = [];
    $low = array_values(array_filter($turnCards, static fn($c) => !empty($c['cost_lowest'])));
    if (count($low) > 1) {
        foreach ($low as $c) { $tied[(int) $c['id']] = (string) $c['name']; }
    } else {
        $est = [];
        foreach ($recent as $c) { if ($c['price'] !== null && $c['qty'] !== null) { $est[(int) $c['id']] = [$c['price'] * $c['qty'], (string) $c['name']]; } }
        if ($est) {
            $min = min(array_column($est, 0));
            foreach ($est as $id => [$v, $n]) { if ($v === $min) { $tied[$id] = $n; } }
        }
    }
    if (count($tied) < 2) { return ''; }
    foreach ($addedIds as $id) {
        if (isset($tied[$id])) {
            $others = array_values(array_unique(array_diff_key($tied, [$id => true])));
            $others = array_values(array_diff($others, [$tied[$id]])) ?: $others;   // 같은 이름(색상만 다름)이면 이름 그대로
            return "\n\n참고: 같은 예상 자재비의 " . implode(', ', $others) . '도 있습니다. 바꾸시려면 말씀해 주세요.';
        }
    }
    return '';
}

/**
 * 확인되지 않은 KS 번호·강도 수치 표시 (순수 함수) — 답에 나온 'KS F 4009'·'24MPa' 같은 번호·수치가 확인된 글($known: 프롬프트·도구 결과·고객 발화)에 없으면
 * 본문은 그대로 두고 끝에 '확인된 자료에 없는 번호' 안내 1줄을 붙인다(GPT 가 비슷한 번호를 지어내는 경우 고객이 그대로 믿지 않게).
 * @return array{text:string, unknown:list<string>}
 */
function rag_guard_unverified_ks(string $text, string $known): array
{
    $norm = static fn(string $x): string => strtoupper((string) preg_replace('/[\s\-]+/u', '', $x));
    $k = $norm($known);
    preg_match_all('/KS\s*([A-Z])\s*-?\s*(\d{4})(?:\s*-\s*(\d{1,2}))?/u', $text, $m, PREG_SET_ORDER);
    $unknown = [];
    foreach ($m as $x) {
        $ref = "KS {$x[1]} {$x[2]}" . (isset($x[3]) && $x[3] !== '' ? "-{$x[3]}" : '');
        if (!str_contains($k, $norm("KS{$x[1]}{$x[2]}"))) { $unknown[$ref] = true; }
    }
    // 강도 수치(24MPa·240kgf/㎠·N/㎟)도 같은 방식 — 제품 사양 수치를 일반 지식으로 지어내는 경우
    preg_match_all('/(\d+(?:\.\d+)?)\s*(MPa|N\/㎟|N\/mm2|N\/mm²|kgf\/㎠|kgf\/cm2)/iu', $text, $m2, PREG_SET_ORDER);
    foreach ($m2 as $x) {
        if (!preg_match('/(?<![\d.])' . preg_quote($x[1], '/') . '\s*' . preg_quote($x[2], '/') . '/iu', $known)) { $unknown[$x[1] . $x[2]] = true; }
    }
    $unknown = array_keys($unknown);
    if (!$unknown) { return ['text' => $text, 'unknown' => []]; }
    return ['text' => rtrim($text) . "\n\n※ 답변의 " . implode(', ', $unknown) . ' 은(는) 확인된 자료에 없는 번호·수치입니다. 정확한 규격·인증·강도는 제조사 자료로 확인해 주세요.', 'unknown' => $unknown];
}

/**
 * 답에 실제로 쓴 기본 정보만 고르기 (순수 함수) — 국가표준은 번호(띄어쓰기 무시)가, 등록 상품은 이름이 답에 있을 때만,
 * 공공조달 등록 현황은 답에 '조달'이 있을 때 그 자재 이름(괄호 앞)도 답에 있으면(없으면 첫 항목만).
 * @param list<array{standards:list<array>, products:list<array>, procurement?:list<array>}> $basicsList material_info·search_products 결과의 basics 들
 * @return array{standards:list<array>, products:list<array>, procurement:list<array>}
 */
function rag_basic_sources_used(string $text, array $basicsList): array
{
    $flat = strtoupper((string) preg_replace('/\s+/u', '', $text));
    $out = ['standards' => [], 'products' => [], 'procurement' => []];
    $seen = [];
    $procAll = [];
    foreach ($basicsList as $b) {
        foreach ($b['procurement'] ?? [] as $pc) {
            $procAll[(string) ($pc['name'] ?? '')] ??= $pc;
        }
        foreach ($b['standards'] ?? [] as $st) {
            $k = strtoupper((string) preg_replace('/\s+/u', '', (string) ($st['ref'] ?? '')));
            if ($k !== '' && !isset($seen[$k]) && str_contains($flat, $k)) {
                $seen[$k] = true;
                $out['standards'][] = ['ref' => (string) $st['ref'], 'name' => (string) ($st['name'] ?? ''), 'url' => (string) ($st['url'] ?? '')];
            }
        }
        foreach ($b['products'] ?? [] as $p) {
            $k = strtoupper((string) preg_replace('/\s+/u', '', (string) ($p['name'] ?? '')));
            if ($k !== '' && !isset($seen[$k]) && str_contains($flat, $k)) {
                $seen[$k] = true;
                $out['products'][] = ['name' => (string) $p['name'], 'count' => (int) ($p['count'] ?? 0)];
            }
        }
    }
    if ($procAll && str_contains($flat, '조달')) {
        $named = array_values(array_filter($procAll, static function ($pc) use ($flat) {
            $base = strtoupper((string) preg_replace('/\s+|\(.*$/u', '', (string) ($pc['name'] ?? '')));
            return $base !== '' && str_contains($flat, $base);
        }));
        foreach (array_slice($named ?: [reset($procAll)], 0, 2) as $pc) {
            $out['procurement'][] = ['name' => (string) $pc['name'], 'items' => (int) ($pc['items'] ?? 0), 'as_of' => (string) ($pc['as_of'] ?? '')];
        }
    }
    return $out;
}

/** 도구 결과 요약 (순수 함수) — 시험 결과 문서에 남겨 실제 GPT 답과 도구가 준 근거를 바로 대조하기 위함 */
function rag_tool_summary(string $name, array $d): string
{
    if ($name === 'search_products') {
        $cards = $d['products'] ?? [];
        $cs = $d['cost_summary'] ?? null;
        return '상품 ' . count($cards) . '건' . ($cards ? '(' . implode(', ', array_map(static fn($c) => trim(($c['name'] ?? '') . ' ' . (preg_match('/규격:\s*([^|]+)/u', (string) ($c['spec'] ?? ''), $mm) ? trim($mm[1]) : '')) . (isset($c['suggested_qty']) ? " ×{$c['suggested_qty']}" : '') . (isset($c['est_cost']) ? ' ≈' . number_format((int) $c['est_cost']) . '원' : ''), array_slice($cards, 0, 4))) . ')' : '')
            . (!empty($d['note']) ? ' · note: ' . mb_substr((string) $d['note'], 0, 60) : '') . (!empty($d['alternatives']) ? ' · alternatives: ' . implode(', ', (array) $d['alternatives']) : '')
            . ($cs ? ' · cost_summary 있음' : '');
    }
    if ($name === 'material_info') {
        $b = $d['basics'] ?? ['standards' => [], 'products' => []];
        return '제조사 문장 ' . count($d['facts'] ?? []) . '개' . (($d['facts'] ?? []) ? '(' . implode(', ', array_unique(array_column($d['facts'], 'maker'))) . ')' : '')
            . ' · 국가표준 ' . (implode(', ', array_column($b['standards'] ?? [], 'ref')) ?: '없음') . ' · 등록 상품 ' . count($b['products'] ?? []) . '종'
            . (!empty($b['procurement']) ? ' · 조달 현황 ' . implode(', ', array_column($b['procurement'], 'name')) : '');
    }
    if ($name === 'add_to_cart') {
        return !empty($d['success']) || !empty($d['ok']) ? '담기 성공' : '담기 실패: ' . mb_substr((string) ($d['error'] ?? json_encode($d, JSON_UNESCAPED_UNICODE)), 0, 60);
    }
    return mb_substr(json_encode($d, JSON_UNESCAPED_UNICODE) ?: '', 0, 80);
}

/** 답변이 '제품을 찾아/검색해 드리겠다'는 약속으로 끝나는지 (순수 함수) — 도구 호출 없이 끝나면 손님이 결과 없이 기다리게 됨 */
function rag_is_search_promise(string $text): bool
{
    $tail = mb_substr(trim($text), -80);
    if (preg_match('/(주시면|주신다면|알려\s*주세요|말씀해\s*주세요)[^.!?]{0,40}(드릴게요|드리겠습니다|드릴께요|볼게요|보겠습니다)\s*[.!]?\s*$/u', $tail)) {
        return false;   // '면적을 알려 주시면 찾아 드리겠습니다' — 고객 답을 기다리는 조건부 안내(지금 검색할 수 없음)
    }
    return preg_match('/(찾아|검색해|보여|알아봐)\s*(드릴게요|드리겠습니다|드릴께요|볼게요|보겠습니다)|잠시만\s*기다려/u', $tail) === 1;
}

/**
 * 답변 글 형식 정리 (순수 함수) — 상담 말풍선은 글자 그대로 표시하므로 마크다운 기호가 남지 않게.
 * 굵게(**…**)·제목(#) 기호 제거, 문장 끝 콜론('…제품입니다:')은 마침표로. 목록의 '- 제품명: 설명' 처럼 줄 중간 콜론은 그대로.
 * 화면(public/assets/chat-ai.js cleanChatText)과 같은 규칙.
 */
function rag_clean_answer_format(string $text): string
{
    $t = (string) preg_replace('/\*\*([^*\n]+?)\*\*/u', '$1', $text);
    $t = str_replace('**', '', $t);
    $t = (string) preg_replace('/^[ \t]*#{1,6}[ \t]+/mu', '', $t);
    return (string) preg_replace('/([다요죠])[ \t]*[:：][ \t]*$/mu', '$1.', $t);
}

/** 상품 카드가 없는 답의 끝 '원하시는 제품을 선택해 주세요.' 제거 (순수 함수) — 고를 카드가 없는데 선택하라는 안내가 붙지 않게 */
function rag_strip_dangling_choice(string $text, array $events): string
{
    if (in_array('product_cards', array_column($events, 'type'), true)) {
        return $text;
    }
    $out = rtrim((string) preg_replace('/\s*원하시는\s*제품을\s*선택해\s*주세요\.?\s*$/u', '', $text));
    return $out !== '' ? $out : $text;   // 그 문장뿐이면 빈 답변이 되지 않게 그대로
}

/**
 * OpenAI Tool Use 루프 — AJAX 응답용 (SSE 없음)
 *
 * @return array{ok:bool, text:string, events:list<array>, error?:string}
 */
function rag_process(array $messages, string $sid): array
{
    global $config;

    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $apiKey = $config['openai']['api_key'] ?? '';
    $model  = $config['openai']['model']   ?? 'gpt-4o-mini';
    if (!$apiKey) {
        return ['ok' => false, 'text' => '', 'events' => [], 'error' => 'API 키가 설정되지 않았습니다.'];
    }

    // 프롬프트·근거 생성(rag_build_prompt) — 내용은 분리 전과 동일
    $recentCards = rag_recent_cards($sid);
    $prompt      = rag_build_prompt($messages, $recentCards);
    $system      = $prompt['system'];
    $categoryKey = $prompt['categoryKey'];
    $legalDocs   = $prompt['legalDocs'];

    $explicit    = rag_explicit_conditions($messages);
    $system     .= rag_clarify_hint($explicit);   // 이번 고객 말이 모호하면(어느 길이인지·어느 치수인지) 확인 질문 근거 — 해당 턴에만 붙음
    $oaiMessages = array_merge([['role' => 'system', 'content' => $system]], $messages);
    $responseText = '';
    $events       = [];
    $maxIter      = 3;
    $toolUsed     = false;
    $forceSearch  = false;   // '찾아 드릴게요'만 하고 끝난 응답 → 1회 검색 재요청
    $chatUrl      = $config['openai']['chat_url'] ?? 'https://api.openai.com/v1/chat/completions';
    $answered     = false;   // 도구 없이 끝난(최종 답을 받은) 응답이 있었는지
    $promised     = '';      // 검색 약속 문장(강제 검색 재요청 때 버림 — 도구가 안 불리면 되살림)
    $toolLog      = [];      // 부른 도구 이름·인자·결과 요약(시험 결과 기록용 — chat.php 는 화면에 보내지 않음)
    $turnCards    = [];      // 이번 턴 검색 결과 카드(동률 최저가 안내용)
    $preToolText  = '';      // 도구 호출과 함께 온 중간 글(최종 답이 없을 때만 사용)
    $basicsSeen   = [];      // material_info·search_products 가 준 확인된 기본 정보(국가표준·등록 상품) — 답에 실제로 쓴 것만 출처 카드로
    $known        = $system . ' ' . implode(' ', array_map(static fn($m) => (string) ($m['content'] ?? ''), $messages));   // 답에 나올 수 있는 확인된 글(KS 번호 대조용)
    // 검색 입력 보완(GPT 가 빠뜨려도 서버가 지킴): 고객이 말한 통행 조건(하중 등급), '다른 제품' 요청이면 직전 추천표 상품 제외
    $lastUser = '';
    foreach (array_reverse($messages) as $m) {
        if (($m['role'] ?? '') === 'user') { $lastUser = (string) ($m['content'] ?? ''); break; }
    }
    $wantsAlt    = $recentCards && rag_wants_alternative($lastUser);
    $wantsCheap  = $recentCards && rag_wants_cheaper($lastUser);
    $trafficInfo = rag_traffic_info($messages);
    $recentCond  = $recentCards ? rag_recent_cond($sid) : [];
    $searchCtx   = [
        'explicit'     => $explicit,
        'traffic'      => $trafficInfo,
        'recent_cards' => $recentCards,
        'recent_cond'  => $recentCond,
        'exclude'      => $wantsAlt ? (rag_session_shown_ids($sid) ?: array_column($recentCards, 'id')) : [],   // 이 상담에서 보인 제품 전체(최근 30개)
        'cheaper'      => $wantsCheap,
        // 수량·최종 비교, 설치 길이만 새로 말함('설치 구간은 총 20m야'), '이 제품 써도 돼?' → 직전 표 제품으로(앞서 뺀 제품을 다시 꺼내지 않게)
        'only_recent'  => $recentCards && !$wantsAlt && (rag_wants_quantity($lastUser) || rag_wants_compare($lastUser) || $explicit['last'] === ['length_m'] || rag_wants_suitability($lastUser)),
        'stronger'     => $recentCards && rag_wants_stronger($lastUser),   // '더 튼튼한 거' → 확인된 하중 등급이 더 높은 제품만
        'reshow'       => !$wantsAlt && rag_wants_reshow($lastUser),   // '아까 그거 다시 보여줘' → 앞서 뺀 제품 제외를 풀어 줌(고객의 최신 요청 우선)
        'similar_dims' => $recentCards && rag_wants_similar($lastUser) ? rag_common_dims(ai_rows_by_ids(array_column($recentCards, 'id'))) : [],
        'recheck'      => $recentCards && !$wantsAlt && $trafficInfo['last_strong'] && in_array($trafficInfo['class'], ['car', 'truck'], true),   // 차량 조건을 이번에 말함 → 앞서 보인 제품 재확인
        'recheck_ids'  => $recentCards && rag_wants_previous($lastUser) ? array_slice(rag_session_shown_ids($sid), 0, 12) : [],   // '앞에 추천했던 제품들' → 직전 표만이 아니라 이 상담에서 보인 제품
    ];

    for ($iter = 0; $iter < $maxIter; $iter++) {
        $body = json_encode([
            'model'       => $model,
            'stream'      => false,
            'tools'       => ai_tool_definitions(),
            'tool_choice' => $forceSearch ? ['type' => 'function', 'function' => ['name' => 'search_products']] : 'auto',
            'messages'    => $oaiMessages,
        ]);
        $forceSearch = false;

        $raw = rag_curl_post(
            $chatUrl,
            $body,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]
        );
        if ($raw === false) {
            return ['ok' => false, 'text' => $responseText, 'events' => $events, 'tools' => $toolLog,
                    'error' => 'API 응답 시간이 초과되었습니다. 다시 시도해 주세요.'];
        }

        $resp = json_decode($raw, true);
        if (!$resp || isset($resp['error'])) {
            $errMsg = $resp['error']['message'] ?? '알 수 없는 오류가 발생했습니다.';
            return ['ok' => false, 'text' => $responseText, 'events' => $events, 'tools' => $toolLog, 'error' => $errMsg];
        }

        $choice       = $resp['choices'][0]     ?? [];
        $message      = $choice['message']       ?? [];
        $finishReason = $choice['finish_reason'] ?? 'stop';
        $content      = $message['content']      ?? '';
        $toolCalls    = $message['tool_calls']   ?? [];

        $noTool = empty($toolCalls);   // 도구를 지정해 강제로 부르면 finish_reason 이 'stop' 으로 온다 — 도구 호출이 있으면 실행
        if ($noTool && $promised !== '' && ($content === '' || $content === null)) {
            $content = $promised;   // 강제 검색에도 도구를 안 부르면 버렸던 안내 문장이라도 돌려준다(빈 답변 방지)
        }
        $promised = '';
        // 검색하겠다고만 하고 도구 없이 끝난 응답: 손님은 결과 없이 기다리게 된다 → 그 문장은 버리고 search_products 를 1회 강제 호출
        if ($noTool && !$toolUsed && $maxIter === 3 && rag_is_search_promise((string) $content)) {
            $promised = (string) $content;
            $oaiMessages[] = ['role' => 'assistant', 'content' => (string) $content];
            $oaiMessages[] = ['role' => 'system', 'content' => '방금 제품을 찾아 드리겠다고 했다. 지금 search_products 를 호출하고, 결과 안내만 한다(같은 안내 문장 반복 금지).'];
            $forceSearch = true;
            $maxIter++;
            continue;
        }

        // 도구 호출과 함께 온 글('찾아볼게요·잠시만요', 검색 전 추측성 종류 설명, '오류가 발생' 등)은 최종 답이 오면 버린다 — 손님에게는 도구 결과를 본 뒤의 답만.
        // 최종 답이 끝내 비면 이 글을 대신 쓴다(빈 답변 방지).
        if ($content !== '' && $content !== null) {
            if ($noTool) {
                $responseText .= ($responseText !== '' ? "\n" : '') . $content;
            } else {
                $preToolText .= ($preToolText !== '' ? "\n" : '') . $content;
            }
        }

        if ($noTool) { $answered = true; break; }

        $toolUsed = true;
        $oaiMessages[] = $message;

        foreach ($toolCalls as $tc) {
            $input    = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
            $rawInput = $input;   // GPT 가 실제로 넘긴 값(시험·검증용 기록 — 서버 보완 전)
            $fills    = [];
            $clarify  = '';
            if (($tc['function']['name'] ?? '') === 'search_products' && is_array($input)) {
                [$input, $fills, $clarify] = rag_prepare_search_input($input, $searchCtx);
            }
            $output   = ai_tool_dispatch($tc['function']['name'] ?? '', $input, $sid);
            $toolData = json_decode($output, true) ?? [];
            if ($clarify !== '') { $toolData['clarify'] = $clarify; }   // 모호한 고객 말 — GPT 가 확인 질문을 하게(서버가 해석을 정하지 않음)
            $toolLog[] = ['name' => (string) ($tc['function']['name'] ?? ''), 'input' => $input, 'raw_input' => $rawInput, 'fills' => $fills, 'clarify' => $clarify,
                          'result_ids' => array_values(array_map(static fn($p) => (int) ($p['id'] ?? 0), (array) ($toolData['products'] ?? []))),
                          'summary' => rag_tool_summary((string) ($tc['function']['name'] ?? ''), $toolData)];
            $known   .= ' ' . $output;
            if (in_array($tc['function']['name'] ?? '', ['material_info', 'search_products'], true) && !empty($toolData['basics'])) { $basicsSeen[] = $toolData['basics']; }   // 검색 결과의 국가표준도 답에 쓰면 출처 카드
            if (($tc['function']['name'] ?? '') === 'search_products') { $turnCards = array_merge($turnCards, (array) ($toolData['products'] ?? [])); }

            foreach ($toolData['_sse'] ?? [] as $sseEvent) {
                $events[] = $sseEvent;
            }
            unset($toolData['_sse']);

            $oaiMessages[] = [
                'role'         => 'tool',
                'tool_call_id' => $tc['id'],
                'content'      => json_encode($toolData, JSON_UNESCAPED_UNICODE),
            ];
        }
    }

    // 도구 호출만 하다 반복 한도에 닿으면 최종 답 없이 끝난다(빈 답변) → 도구 없이 답만 1회 더 요청
    if (!$answered) {
        $raw = rag_curl_post($chatUrl, json_encode(['model' => $model, 'stream' => false, 'tools' => ai_tool_definitions(), 'tool_choice' => 'none', 'messages' => $oaiMessages]),
            ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]);
        $final = $raw === false ? null : json_decode($raw, true);
        $content = (string) ($final['choices'][0]['message']['content'] ?? '');
        if ($content !== '') {
            $responseText .= ($responseText !== '' ? "\n" : '') . $content;
        } elseif ($responseText === '' && $preToolText === '') {
            return ['ok' => false, 'text' => '', 'events' => $events, 'tools' => $toolLog, 'error' => '답변을 만들지 못했습니다. 다시 시도해 주세요.'];
        }
    }

    if (trim($responseText) === '' && $preToolText !== '') {
        $responseText = $preToolText;
    }
    // 실제로 인용한 법령 근거만 출처로 표시(지어낸 번호는 버리고 표시는 본문에서 제거)
    $cit = rag_extract_legal_citations($responseText, $legalDocs);
    $responseText = rag_strip_dangling_choice(rag_clean_answer_format($cit['text']), $events);   // ** 굵게·끝 콜론 정리 — 화면·저장·로그 모두 정리된 글
    if ($cit['sources']) {
        $events[] = ['type' => 'legal_sources', 'sources' => $cit['sources']];
    }
    // '제일 저렴한 것'을 담았는데 같은 금액 제품이 또 있으면 그 사실을 한 줄로(실제 GPT-4o 3차까지 안내 누락 — 프롬프트만으로는 일정하지 않음)
    $added = array_map(static fn($t) => (int) ($t['input']['product_id'] ?? 0), array_values(array_filter($toolLog, static fn($t) => $t['name'] === 'add_to_cart')));
    $responseText .= rag_tie_note($responseText, $added, $turnCards, $recentCards);
    $basicSrc = rag_basic_sources_used($responseText, $basicsSeen);
    if ($basicSrc['standards'] || $basicSrc['products'] || $basicSrc['procurement']) {
        $events[] = ['type' => 'basic_sources'] + $basicSrc;
    }
    // 확인된 자료(프롬프트·도구 결과·고객 발화)에 없는 KS 번호 → 답 끝에 확인 필요 안내(본문은 고치지 않음)
    $ks = rag_guard_unverified_ks($responseText, $known);
    $responseText = $ks['text'];

    return ['ok' => true, 'text' => $responseText, 'events' => $events, 'category' => $categoryKey, 'tools' => $toolLog, 'unverified_ks' => $ks['unknown']];
}
