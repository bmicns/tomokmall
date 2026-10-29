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
 *  rag_build_prompt()          — 상담 시스템 프롬프트·근거 묶음 생성(API 호출 없음, rag_process 가 사용)
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
    // ON DUPLICATE KEY UPDATE: user_id 는 이미 설정된 값이 있으면 유지 (COALESCE)
    // 익명 → 로그인 후 재상담 시 user_id 연결
    $stmt = db()->prepare(
        'INSERT INTO ai_sessions (session_key, user_id, messages)
              VALUES (:key, :uid, :msg)
         ON DUPLICATE KEY UPDATE
              user_id    = COALESCE(:uid_upd, user_id),
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
    $sidewalk = preg_match('/(?:인도|보도)(?:에|를|가|위|쪽|\s*포장|\s*블|\s*진입|\s*턱|\s*횡단|\s*가로)|보행로/u', $t) === 1;
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
            . "- 4t 를 넘는 차량이 다니거나 차도 부분이면 공공도로 차도 기준이 따로 있으니 공사 성격을 먼저 확인한다(이 구역에 없는 수치는 말하지 않는다).",
        'unclear' => "\n\n=== 차량 블록 시공 장소 확인 ===\n"
            . "- 차량이 올라가는 블록 시공이지만 장소가 분명하지 않다. 두께가 '충분하다'고 단정하기 전에 먼저 묻는다:\n"
            . "  '개인 부지(마당·주차장·진입로)인가요, 도로(인도·차도) 공사인가요? 발주처 설계도서나 시방서가 있나요?'\n"
            . "- 도로 차도 공사로 확인되면 다음 답변에서 공식 기준을 따로 안내하고(이번 답변에서 수치를 단정하지 않는다), 개인 부지면 기존 안내대로 진행한다.",
        default => '',
    };
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
        CURLOPT_TIMEOUT        => 20,
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
function rag_build_prompt(array $messages): array
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
        . "- 일반 설명에서 가격·강도·법정 기준 같은 구체 숫자를 지어내지 않는다. 숫자는 아래 [참조 문서]에 있는 것만 인용하고, 가격·수량은 검색 결과 표로 보여준다.\n"
        . "- 면적을 모르면 '가로 몇 m × 세로 몇 m인지' 물어보고, 답을 받으면 직접 곱해 area_m2로 넘긴다(예: 5m×4m → 20). 대략값도 괜찮다고 안내한다.\n"
        . "- 자재 종류의 차이·특징·물빠짐·주의사항 질문과, 사용 조건(유모차·휠체어·미끄럼·오염·차량 통행 등)에 맞는 자재를 고르는 질문에는 material_info 를 먼저 호출하고, 돌려준 원문 문장을 '○○(제조사) 자료에 따르면'처럼 그 제조사 제품 설명으로 쉬운 말로 전한다(출처끼리 다르면 단정하지 않음, 공식 기준 수치는 [L번호] 근거만).\n"
        . "  원문에 없는 성능·용도·비교(중장비 통과, 다른 제품도 같다 등)를 덧붙이거나, 한 제조사의 제품 표기를 '이걸 쓰면 된다'는 일반 선택 기준으로 단정하지 않는다. material_info 결과가 비어 있으면 '제조사 자료에 따르면'이라고 말하지 말고, 수치 없는 일반 설명임을 밝힌다.\n"
        . "- 선택지를 비교한 뒤 면적·길이가 확인되면 search_products의 queries로 종류별 제품을 한 표에 모아 보여준다.\n"
        . "  표에는 제품별 필요 수량과 예상 자재비(판매가 기준, 운반·시공비 별도)가 자동 계산되어 차이를 바로 비교할 수 있다.\n"
        . "\n"
        . "=== 배송·납기 안내 ===\n"
        . "고객이 배송·납기·배달·운송·언제 받냐 등을 물어보면 반드시 아래 문구만 출력하세요.\n"
        . "「배송 일정은 견적 요청 후 담당자가 직접 확인하여 연락드립니다. 견적요청하기 버튼을 눌러 신청해 주세요.」\n"
        . "배송비·운임 등 비용 관련 질문도 동일하게 담당자 연락 안내로 처리하세요.\n"
        . "\n"
        . "=== 절대 금지 ===\n"
        . "- 아래 [카테고리별 필수 확인 항목]이 모두 충족되지 않은 상태에서 search_products 호출 금지.\n"
        . "- search_products 호출 후 상품명·규격·가격·설명을 텍스트로 출력 금지. 결과는 화면에 카드로 자동 표시됨.\n"
        . "- search_products 호출 후에는 표에 나온 상품명·규격·가격·수량·금액을 텍스트로 다시 적지 않는다.\n"
        . "  허용: 고르는 기준을 쉬운 말 1~2문장(숫자 없이, 예: '물이 잘 고이는 곳이면 투수 제품이 좋습니다.') + 마지막 줄 '원하시는 제품을 선택해 주세요.'\n"
        . "  표의 상품마다 guide(두께·투수·재질 등 고르는 기준)가 함께 보인다. 고객 상황에 맞는 기준 하나를 골라 쉬운 말로 짚어 준다(guide 에 없는 성능·수치는 덧붙이지 않는다).\n"
        . "  추천 이유는 guide 의 기준으로 말하고, 주의할 점은 고객 조건과 맞지 않는 것만 짚는다(예: 차량이 올라가는데 60mm 제품).\n"
        . "  guide 의 '참고 자료(판매 상품 아님)' 줄은 공공데이터·제조사 자료로 확인한 정보다. 판매 상품이나 가격처럼 말하지 않고 출처와 함께 참고로만 언급한다.\n"
        . "  예외① 고객이 '어떤 게 더 저렴해?'처럼 가격 차이를 물으면 도구 결과 cost_summary 의 상품명·예상 금액·차이만 인용해 1~2문장으로 답한다(cost_summary 가 없으면 금액을 말하지 않는다).\n"
        . "  예외② '몇 개 필요해?'처럼 개수를 물으면 해당 상품 qty_basis(면적→장수 계산 근거)를 인용한다. ㎡ 단위 판매 제품은 ㎡ 로 주문하고 장수는 참고라고 덧붙인다.\n"
        . "  단, 도구 결과에 note 필드가 있으면 그 note 문장을 먼저 그대로 한 줄 전하고, 이어서 '원하시는 제품을 선택해 주세요.'를 붙인다(교정·대안 안내).\n"
        . "- DB에 없는 상품 추천·언급 금지.\n"
        . "- 상품 없이 문의를 받거나 담당자가 따로 연락하는 창구는 없다. '문의 주시면 찾아드리겠습니다'·'연락드리겠습니다' 같은 약속 금지(견적요청하기 버튼 안내는 허용).\n"
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
        . "  필수 ① 설치 장소 + 차량이 올라가는지 (집 앞 마당·진입로/인도/광장/공원/주차장/기타)\n"
        . "  필수 ② 필요 면적(㎡) — 모르면 가로×세로(m)로 받아 계산\n"
        . "  (경계석 필요 여부는 검색 전에 묻지 말고, 블록 표를 보여준 뒤 '테두리 마감용 경계석도 필요하신가요?'로 묻는다)\n"
        . "  ★차량이 올라가면 두께 80mm 이상(80T) 제품, 사람만 다니면 60mm(60T)도 가능 → [참조 문서] 기준.\n"
        . "    차량이 올라가는 곳이면 spec_mm 80, spec_strict true를 함께 넘겨 80T 제품만 표에 나오게 한다(사람만 다니면 spec_mm 생략).\n"
        . "    80T 제품이 없는 종류는 표에서 빠지며, 도구의 note로 안내된다(다른 두께를 대신 권하지 않는다).\n"
        . "  검색어(query) 예: '인터로킹 블록', '인조화강블록', '점토블럭', '잔디블록', '경계석' (면적은 area_m2로, 설치장소는 넣지 말 것)\n"
        . "  종류 비교 예: queries ['인터로킹 블록', '인조화강블록', '점토블럭'], area_m2 20 → 종류별 필요 수량·예상 자재비가 한 표에 표시.\n"
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
        . "- 모든 필수 항목 확인 완료 → search_products 호출. 되묻지 말고 그 턴에서 바로 호출한다.\n"
        . "- '찾아 드릴게요'·'잠시만 기다려 주세요'라고 말만 하고 답변을 끝내지 않는다. 검색할 때는 같은 응답에서 search_products 를 호출한다.\n"
        . "- query에는 제품명/용도 '텍스트'만 넣는다. 숫자(치수·길이·수량)는 query에 절대 넣지 말 것.\n"
        . "- 규격 치수(폭/관경 mm)는 spec_mm, 설치 길이/연장(m)은 length_m, 면적(㎡)은 area_m2 파라미터로 분리 전달.\n"
        . "  → 예: '폭50cm 100m 수로 그레이팅' = query:'그레이팅 수로용 중하중', spec_mm:500, length_m:100.\n"
        . "  → 예: '보도블록 200㎡' = query:'보도블록', area_m2:200.  '경계석 300m' = query:'경계석', length_m:300.\n"
        . "  → length_m/area_m2를 주면 제품별 소요 수량이 카드에 자동 계산·표시된다. 수량(개수)은 query에 넣지 말 것.\n"
        . "- 결과가 없으면(products 빈 배열): 도구가 돌려준 note 문장을 그대로 손님에게 전한다. 절대 그냥 되묻고 끝내지 말 것.\n"
        . "  → note가 없을 때만: '해당 조건의 제품을 찾지 못했습니다. 용도나 재질을 알려주시면 다시 찾아드리겠습니다.' 후 검색어를 바꿔 1회 재시도.\n"
        . "  → 결과에 alternatives(같은 두께로 등록된 다른 포장 블록 이름)가 있으면, 같은 턴에서 그 이름들을 queries 로 같은 spec_mm·spec_strict·area_m2 와 함께 search_products 를 다시 호출해 표를 보여준다(더 얇은 두께로 바꾸지 않는다).\n"
        . "【3단계: 견적 안내】\n"
        . "- 고객이 담기 원하면 add_to_cart 호출. quantity 는 표의 권장 수량(suggested_qty)을 쓰고, 고객이 다른 수량을 말하면 그 수량.\n"
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

    return [
        'system'      => $system,
        'categoryKey' => $categoryKey,
        'ragDocs'     => $ragDocs,
        'legalDocs'   => $legalDocs,
        'roadCtx'     => $roadCtx,
        'scopes'      => $legalIn['scopes'],
    ];
}

/** 답변이 '제품을 찾아/검색해 드리겠다'는 약속으로 끝나는지 (순수 함수) — 도구 호출 없이 끝나면 손님이 결과 없이 기다리게 됨 */
function rag_is_search_promise(string $text): bool
{
    $tail = mb_substr(trim($text), -80);
    return preg_match('/(찾아|검색해|보여|알아봐)\s*(드릴게요|드리겠습니다|드릴께요|볼게요|보겠습니다)|잠시만\s*기다려/u', $tail) === 1;
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
    $prompt      = rag_build_prompt($messages);
    $system      = $prompt['system'];
    $categoryKey = $prompt['categoryKey'];
    $legalDocs   = $prompt['legalDocs'];

    $oaiMessages = array_merge([['role' => 'system', 'content' => $system]], $messages);
    $responseText = '';
    $events       = [];
    $maxIter      = 3;
    $toolUsed     = false;
    $forceSearch  = false;   // '찾아 드릴게요'만 하고 끝난 응답 → 1회 검색 재요청

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

        $noTool = ($finishReason !== 'tool_calls' || empty($toolCalls));
        // 검색하겠다고만 하고 도구 없이 끝난 응답: 손님은 결과 없이 기다리게 된다 → 그 문장은 버리고 search_products 를 1회 강제 호출
        if ($noTool && !$toolUsed && $maxIter === 3 && rag_is_search_promise((string) $content)) {
            $oaiMessages[] = ['role' => 'assistant', 'content' => (string) $content];
            $oaiMessages[] = ['role' => 'system', 'content' => '방금 제품을 찾아 드리겠다고 했다. 지금 search_products 를 호출하고, 결과 안내만 한다(같은 안내 문장 반복 금지).'];
            $forceSearch = true;
            $maxIter++;
            continue;
        }

        if ($content !== '' && $content !== null) {
            $responseText .= ($responseText !== '' ? "\n" : '') . $content;
        }

        if ($noTool) break;

        $toolUsed = true;
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

    // 실제로 인용한 법령 근거만 출처로 표시(지어낸 번호는 버리고 표시는 본문에서 제거)
    $cit = rag_extract_legal_citations($responseText, $legalDocs);
    $responseText = $cit['text'];
    if ($cit['sources']) {
        $events[] = ['type' => 'legal_sources', 'sources' => $cit['sources']];
    }

    return ['ok' => true, 'text' => $responseText, 'events' => $events, 'category' => $categoryKey];
}
