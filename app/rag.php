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

    if (preg_match('/그레이팅|측구\s*커버|배수로\s*덮개/u', $text))        return 'grating';
    if (preg_match('/pe\s*배수관|우수관|하수관|배수관|pe\s*관/u', $text))   return 'pe_pipe';
    if (preg_match('/맨홀|집수정|우물통/u', $text))                         return 'manhole';
    if (preg_match('/수로관|농수로|u\s*형|ㅠ\s*형/u', $text))               return 'culvert';
    if (preg_match('/보도블록|경계석|인터로킹|포장블록|보차도/u', $text))    return 'pavement';

    return '';
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
                 WHERE is_active = 1 AND category_key = :cat
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
        CURLOPT_TIMEOUT        => 20,
    ]);
    $raw   = curl_exec($ch);
    $errno = curl_errno($ch);
    curl_close($ch);
    if ($errno) return false;
    return $raw;
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

    // Stage 2: 카테고리 자동 감지 + DB 가이드 로드
    $categoryKey = rag_detect_category($messages);
    $guideText   = rag_load_consult_guide($categoryKey);

    // RAG 문서 검색 — 마지막 사용자 메시지 기준
    $lastUserMsg = '';
    foreach (array_reverse($messages) as $m) {
        if (($m['role'] ?? '') === 'user') { $lastUserMsg = $m['content'] ?? ''; break; }
    }
    $ragDocs = rag_search_documents($lastUserMsg, $categoryKey, 2);

    $system = "당신은 비엠아이 건설자재 전문 상담사입니다. 항상 한국어로만 답변하세요.\n"
        . "\n"
        . "=== 배송·납기 안내 ===\n"
        . "고객이 배송·납기·배달·운송·언제 받냐 등을 물어보면 반드시 아래 문구만 출력하세요.\n"
        . "「배송 일정은 견적 요청 후 담당자가 직접 확인하여 연락드립니다. 견적요청하기 버튼을 눌러 신청해 주세요.」\n"
        . "배송비·운임 등 비용 관련 질문도 동일하게 담당자 연락 안내로 처리하세요.\n"
        . "\n"
        . "=== 절대 금지 ===\n"
        . "- 아래 [카테고리별 필수 확인 항목]이 모두 충족되지 않은 상태에서 search_products 호출 금지.\n"
        . "- search_products 호출 후 상품명·규격·가격·설명을 텍스트로 출력 금지. 결과는 화면에 카드로 자동 표시됨.\n"
        . "- search_products 호출 후 출력 허용 텍스트: '원하시는 제품을 선택해 주세요.' 이 한 줄뿐.\n"
        . "  단, 도구 결과에 note 필드가 있으면 그 note 문장을 먼저 그대로 한 줄 전하고, 이어서 '원하시는 제품을 선택해 주세요.'를 붙인다(교정·대안 안내).\n"
        . "- DB에 없는 상품 추천·언급 금지.\n"
        . "- '[도구 호출]', '[search_products(...)]' 같은 tool 호출 사실을 텍스트로 언급 금지.\n"
        . "\n"
        . "=== 카테고리별 필수 확인 항목 (모두 확인 후 search_products 호출) ===\n"
        . "\n"
        . "[그레이팅]\n"
        . "  필수 ① 설치 장소 (도로/주차장/공장/인도/기타)\n"
        . "  필수 ② 차량 통행 여부 + 최대 차량 톤수 (보행자 전용이면 '없음')\n"
        . "  필수 ③ 필요 면적(㎡) 또는 수량(개), 또는 수로 연장(m)\n"
        . "  ★용도어 정밀화: 수로·배수로·측구 위 덮개면 반드시 '수로용', 집수정·맨홀 뚜껑이면 '맨홀'을 검색어에 포함(섞임 방지).\n"
        . "  ★하중 등급(카탈로그 3단계, 과잉 등급 금지): 보행자 전용→'그레이팅수로용', 승용차·SUV 통행→'중하중', 트럭·버스 등 대형차→'안전중하중'.\n"
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
        . "  검색어(query) 예: '측구 수로관', 'U형 수로관', '사각 수로관', '환경 수로관' (규격=spec_mm, 연장=length_m)\n"
        . "  ★'수로를 만든다/설치'면 본체(수로관)와 덮개(그레이팅수로용)를 세트로 함께 추천 → 아래 [세트 추천] 규칙.\n"
        . "\n"
        . "[보도블록 / 경계석 / 인터로킹]\n"
        . "  필수 ① 설치 장소 (인도/광장/공원/주차장/기타)\n"
        . "  필수 ② 필요 면적(㎡)\n"
        . "  필수 ③ 경계석 동시 필요 여부\n"
        . "  검색어(query) 예: '인터로킹 보도블록', '경계석 화강암', '보도블록' (면적은 area_m2로, 설치장소는 넣지 말 것)\n"
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
        . "  예) 폭500·50m 차량통행 수로 → ①본체: query '측구 수로관', spec_mm 500, length_m 50 (약 25본)  ②덮개: query '그레이팅 수로용 중하중', spec_mm 500, length_m 50 (약 50개).\n"
        . "- 본체(2m/본)와 덮개(1m/개)는 단위가 달라 수량이 다르게 나오는 게 정상이다. 둘 다 카드로 보여준 뒤 '원하시는 제품을 선택해 주세요.' 만 출력.\n"
        . "【2단계: 검색】\n"
        . "- 모든 필수 항목 확인 완료 → search_products 호출.\n"
        . "- query에는 제품명/용도 '텍스트'만 넣는다. 숫자(치수·길이·수량)는 query에 절대 넣지 말 것.\n"
        . "- 규격 치수(폭/관경 mm)는 spec_mm, 설치 길이/연장(m)은 length_m, 면적(㎡)은 area_m2 파라미터로 분리 전달.\n"
        . "  → 예: '폭50cm 100m 수로 그레이팅' = query:'그레이팅 수로용 중하중', spec_mm:500, length_m:100.\n"
        . "  → 예: '보도블록 200㎡' = query:'보도블록', area_m2:200.  '경계석 300m' = query:'경계석', length_m:300.\n"
        . "  → length_m/area_m2를 주면 제품별 소요 수량이 카드에 자동 계산·표시된다. 수량(개수)은 query에 넣지 말 것.\n"
        . "- 결과가 없으면(products 빈 배열): 도구가 돌려준 note 문장을 그대로 손님에게 전한다. 절대 그냥 되묻고 끝내지 말 것.\n"
        . "  → note가 없을 때만: '해당 조건의 제품을 찾지 못했습니다. 용도나 재질을 알려주시면 다시 찾아드리겠습니다.' 후 검색어를 바꿔 1회 재시도.\n"
        . "【3단계: 견적 안내】\n"
        . "- 고객이 담기 원하면 add_to_cart 호출.\n"
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

    $oaiMessages = array_merge([['role' => 'system', 'content' => $system]], $messages);
    $responseText = '';
    $events       = [];

    for ($iter = 0; $iter < 3; $iter++) {
        $body = json_encode([
            'model'       => $model,
            'stream'      => false,
            'tools'       => ai_tool_definitions(),
            'tool_choice' => 'auto',
            'messages'    => $oaiMessages,
        ]);

        $raw = rag_curl_post(
            'https://api.openai.com/v1/chat/completions',
            $body,
            ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey]
        );
        if ($raw === false) {
            return ['ok' => false, 'text' => $responseText, 'events' => $events,
                    'error' => 'API 응답 시간이 초과되었습니다. 다시 시도해 주세요.'];
        }

        $resp = json_decode($raw, true);
        if (!$resp || isset($resp['error'])) {
            $errMsg = $resp['error']['message'] ?? '알 수 없는 오류가 발생했습니다.';
            return ['ok' => false, 'text' => $responseText, 'events' => $events, 'error' => $errMsg];
        }

        $choice       = $resp['choices'][0]     ?? [];
        $message      = $choice['message']       ?? [];
        $finishReason = $choice['finish_reason'] ?? 'stop';
        $content      = $message['content']      ?? '';
        $toolCalls    = $message['tool_calls']   ?? [];

        if ($content !== '' && $content !== null) {
            $responseText .= $content;
        }

        if ($finishReason !== 'tool_calls' || empty($toolCalls)) break;

        $oaiMessages[] = $message;

        foreach ($toolCalls as $tc) {
            $input    = json_decode($tc['function']['arguments'] ?? '{}', true) ?? [];
            $output   = ai_tool_dispatch($tc['function']['name'] ?? '', $input, $sid);
            $toolData = json_decode($output, true) ?? [];

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

    return ['ok' => true, 'text' => $responseText, 'events' => $events, 'category' => $categoryKey];
}
