<?php
declare(strict_types=1);
/**
 * 상담원 문의 요청 (app/consult.php)
 *
 * 책임: 고객 문의 요청 검증·저장, 관리자 목록·상세 조회, 상태 변경(완료 시 이름·연락처 파기),
 *       메모 기록, 같은 상담 키의 AI 상담 내역(chat_logs) 전체 조회
 * 금지: 화면 출력, 개인정보를 로그에 남기기
 * 테이블: consult_requests, consult_request_events (database/20261103_consult_requests.sql), 읽기만: chat_logs, ai_sessions, users
 */


/** 고객이 동의한 안내문 버전 — 고객 모달의 '개인정보 수집·이용' 문구를 바꾸면 함께 올린다 */
const CONSULT_TERMS_VERSION = '2026-11-03';
const CONSULT_MSG_MAX       = 500;
const CONSULT_NAME_MAX      = 30;
/** 같은 브라우저 세션에서 10분에 3건까지 */
const CONSULT_RATE_WINDOW   = 600;
const CONSULT_RATE_MAX      = 3;

/** 전화번호 숫자만 (순수 함수) */
function consult_phone_digits(string $tel): string
{
    return (string) preg_replace('/\D+/', '', $tel);
}

/** 국내 전화번호(휴대폰·지역번호) 형식인지 — 숫자 9~11자리, 0으로 시작 (순수 함수) */
function consult_valid_phone(string $tel): bool
{
    return preg_match('/^0\d{8,10}$/', consult_phone_digits($tel)) === 1;
}

/** 표시용 하이픈 번호 (순수 함수) — 01012345678 → 010-1234-5678, 0212345678 → 02-1234-5678 */
function consult_format_phone(string $tel): string
{
    $d = consult_phone_digits($tel);
    if (preg_match('/^(02)(\d{3,4})(\d{4})$/', $d, $m) || preg_match('/^(0\d{2})(\d{3,4})(\d{4})$/', $d, $m)) {
        return $m[1] . '-' . $m[2] . '-' . $m[3];
    }
    return $d;
}

/** 상태 키 → [표시명, 배지 class] */
function consult_statuses(): array
{
    return [
        'pending' => ['접수됨', 'badge-pending'],
        'contact' => ['발송됨', 'badge-contact'],
        'closed'  => ['완료',   'badge-closed'],
    ];
}

/**
 * 고객 입력 검증 (순수 함수)
 * @return array{0: ?string, 1: array{name:string, tel:string, message:string}}  [오류 문장 또는 null, 정리된 값]
 */
function consult_validate_request(array $in): array
{
    $name = trim(preg_replace('/\s+/u', ' ', (string) ($in['name'] ?? '')) ?? '');
    $tel  = trim((string) ($in['tel'] ?? ''));
    $msg  = trim(str_replace("\r\n", "\n", (string) ($in['message'] ?? '')));
    $clean = ['name' => $name, 'tel' => consult_format_phone($tel), 'message' => $msg];

    if ($name === '') {
        return ['이름을 입력해 주세요.', $clean];
    }
    if (mb_strlen($name) > CONSULT_NAME_MAX) {
        return ['이름은 ' . CONSULT_NAME_MAX . '자 이내로 입력해 주세요.', $clean];
    }
    if (!preg_match('/^[0-9\-\s]+$/', $tel) || !consult_valid_phone($tel)) {
        return ['연락처를 010-0000-0000 형식으로 입력해 주세요.', $clean];
    }
    if ($msg === '') {
        return ['문의 내용을 입력해 주세요.', $clean];
    }
    if (mb_strlen($msg) > CONSULT_MSG_MAX) {
        return ['문의 내용은 ' . CONSULT_MSG_MAX . '자 이내로 입력해 주세요.', $clean];
    }
    if (($in['agree'] ?? false) !== true) {
        return ['개인정보 수집·이용에 동의해 주세요.', $clean];
    }
    return [null, $clean];
}

/** 세션 단위 접수 횟수 제한 — 허용이면 true 를 돌려주고 시각을 기록 */
function consult_rate_allow(int $now): bool
{
    $times = array_values(array_filter(
        (array) ($_SESSION['consult_req_at'] ?? []),
        static fn($t) => is_int($t) && $t > $now - CONSULT_RATE_WINDOW
    ));
    if (count($times) >= CONSULT_RATE_MAX) {
        $_SESSION['consult_req_at'] = $times;
        return false;
    }
    $times[] = $now;
    $_SESSION['consult_req_at'] = $times;
    return true;
}

/** 문의 요청 저장 + 'created' 이력. 새 요청 ID */
function consult_create(PDO $pdo, array $clean, ?string $sessionKey, ?int $userId): int
{
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare(
            'INSERT INTO consult_requests (session_key, user_id, status, name, tel, message, agreed_at, terms_version)
             VALUES (:sid, :uid, \'pending\', :name, :tel, :msg, NOW(), :ver)'
        );
        $st->execute([
            'sid'  => $sessionKey !== '' ? $sessionKey : null,
            'uid'  => $userId,
            'name' => $clean['name'],
            'tel'  => $clean['tel'],
            'msg'  => $clean['message'],
            'ver'  => CONSULT_TERMS_VERSION,
        ]);
        $id = (int) $pdo->lastInsertId();
        consult_add_event($pdo, $id, 'created', 'pending', null, null, null, null);
        $pdo->commit();
        return $id;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function consult_add_event(PDO $pdo, int $requestId, string $type, ?string $status, ?string $smsType, ?string $result, ?string $detail, ?int $adminId): void
{
    $st = $pdo->prepare(
        'INSERT INTO consult_request_events (request_id, type, status, sms_type, result, detail, admin_id)
         VALUES (:rid, :type, :status, :smst, :result, :detail, :aid)'
    );
    $st->execute([
        'rid' => $requestId, 'type' => $type, 'status' => $status, 'smst' => $smsType,
        'result' => $result, 'detail' => $detail, 'aid' => $adminId,
    ]);
}

/**
 * 목록 검색 조건 (순수 함수) — 이름·연락처(하이픈 무시)·문의 내용, 상태
 * @return array{0:string, 1:array}  [WHERE 절, 바인딩 값]
 */
function consult_list_where(string $status, string $q): array
{
    $where = ['1=1'];
    $params = [];
    if (isset(consult_statuses()[$status])) {
        $where[] = 'status = :status';
        $params['status'] = $status;
    }
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . addcslashes($q, '%_\\') . '%';
        $cond = ['name LIKE :q1', 'message LIKE :q2'];
        $params['q1'] = $like;
        $params['q2'] = $like;
        $digits = consult_phone_digits($q);
        if (strlen($digits) >= 3) {
            $cond[] = 'REPLACE(tel, \'-\', \'\') LIKE :q3';
            $params['q3'] = '%' . $digits . '%';
        }
        $where[] = '(' . implode(' OR ', $cond) . ')';
    }
    return [implode(' AND ', $where), $params];
}

/** 상태별 건수 — ['all'=>N, 'pending'=>N, 'contact'=>N, 'closed'=>N] (검색어 반영) */
function consult_counts(PDO $pdo, string $q): array
{
    [$where, $params] = consult_list_where('', $q);
    $st = $pdo->prepare("SELECT status, COUNT(*) AS n FROM consult_requests WHERE {$where} GROUP BY status");
    $st->execute($params);
    $out = ['all' => 0, 'pending' => 0, 'contact' => 0, 'closed' => 0];
    foreach ($st->fetchAll() as $r) {
        if (isset($out[$r['status']])) {
            $out[$r['status']] = (int) $r['n'];
        }
        $out['all'] += (int) $r['n'];
    }
    return $out;
}

/** 목록 한 페이지 (최신순) */
function consult_list(PDO $pdo, string $status, string $q, int $page, int $perPage): array
{
    [$where, $params] = consult_list_where($status, $q);
    $offset = max(0, ($page - 1) * $perPage);
    $st = $pdo->prepare(
        "SELECT id, status, name, tel, message, created_at
           FROM consult_requests WHERE {$where}
          ORDER BY id DESC LIMIT {$perPage} OFFSET {$offset}"
    );
    $st->execute($params);
    return $st->fetchAll();
}

function consult_get(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM consult_requests WHERE id = :id');
    $st->execute(['id' => $id]);
    $row = $st->fetch();
    return $row ?: null;
}

/** 처리 이력(최신순) — 처리한 관리자 이름 포함 */
function consult_events(PDO $pdo, int $id): array
{
    $st = $pdo->prepare(
        'SELECT e.id, e.type, e.status, e.sms_type, e.result, e.detail, e.created_at, u.name AS admin_name
           FROM consult_request_events e
           LEFT JOIN users u ON u.id = e.admin_id
          WHERE e.request_id = :id
          ORDER BY e.id DESC'
    );
    $st->execute(['id' => $id]);
    return $st->fetchAll();
}

/**
 * 상태 변경. 'closed' 는 이름·연락처를 지우고(파기) 되돌릴 수 없다.
 * @return ?string  오류 문장, 성공이면 null
 */
function consult_set_status(PDO $pdo, int $id, string $status, ?int $adminId): ?string
{
    if (!isset(consult_statuses()[$status])) {
        return '알 수 없는 상태입니다.';
    }
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('SELECT status FROM consult_requests WHERE id = :id FOR UPDATE');
        $st->execute(['id' => $id]);
        $cur = $st->fetchColumn();
        if ($cur === false) {
            $pdo->rollBack();
            return '요청을 찾을 수 없습니다.';
        }
        if ($cur === 'closed') {
            $pdo->rollBack();
            return '완료된 요청은 상태를 바꿀 수 없습니다.';
        }
        if ($cur === $status) {
            $pdo->rollBack();
            return null;
        }
        if ($status === 'closed') {
            $up = $pdo->prepare('UPDATE consult_requests SET status = \'closed\', name = NULL, tel = NULL, pii_destroyed_at = NOW() WHERE id = :id');
        } else {
            $up = $pdo->prepare('UPDATE consult_requests SET status = :s WHERE id = :id');
            $up->bindValue('s', $status);
        }
        $up->bindValue('id', $id, PDO::PARAM_INT);
        $up->execute();
        consult_add_event($pdo, $id, 'status', $status, null, null, null, $adminId);
        $pdo->commit();
        return null;
    } catch (\Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

function consult_save_memo(PDO $pdo, int $id, string $memo): bool
{
    $st = $pdo->prepare('UPDATE consult_requests SET memo = :m, memo_updated_at = NOW() WHERE id = :id');
    $st->execute(['m' => $memo !== '' ? $memo : null, 'id' => $id]);
    return $st->rowCount() > 0 || consult_get($pdo, $id) !== null;
}

/**
 * chat_logs 행 → 화면용 대화 턴 (순수 함수)
 * 상품 추천표는 상담 당시 값(이름·규격·가격·예상 자재비·이미지)만, 그 외 이벤트는 버린다.
 * @param  list<array{user_msg:?string, assistant_txt:?string, events_json:?string, created_at:string}> $rows  id 오름차순
 * @return list<array{at:string, user:string, ai:string, products:list<array>}>
 */
function consult_chat_turns(array $rows): array
{
    $turns = [];
    foreach ($rows as $r) {
        $products = [];
        $events = json_decode((string) ($r['events_json'] ?? ''), true);
        foreach (is_array($events) ? $events : [] as $ev) {
            if (!is_array($ev) || ($ev['type'] ?? '') !== 'product_cards' || !is_array($ev['products'] ?? null)) {
                continue;
            }
            foreach ($ev['products'] as $p) {
                if (!is_array($p) || trim((string) ($p['name'] ?? '')) === '') {
                    continue;
                }
                $img = (string) ($p['image_url'] ?? '');
                $products[] = [
                    'id'       => (int) ($p['id'] ?? 0),
                    'name'     => (string) $p['name'],
                    'spec'     => (string) ($p['spec_label'] ?? $p['spec'] ?? ''),
                    'price'    => isset($p['price_b2c']) ? (int) $p['price_b2c'] : 0,
                    'unit'     => (string) ($p['unit'] ?? ''),
                    'qty'      => isset($p['suggested_qty']) ? (int) $p['suggested_qty'] : 0,
                    'est_cost' => isset($p['est_cost']) ? (int) $p['est_cost'] : 0,
                    'image'    => preg_match('#^(https?://|/)#', $img) && !str_starts_with($img, '//') ? $img : '',
                ];
            }
        }
        $turns[] = [
            'at'       => (string) ($r['created_at'] ?? ''),
            'user'     => (string) ($r['user_msg'] ?? ''),
            'ai'       => (string) ($r['assistant_txt'] ?? ''),
            'products' => $products,
        ];
    }
    return $turns;
}

/**
 * 같은 상담 키의 AI 상담 내역 전체.
 * chat_logs(질문·답변·추천표, 턴마다 1행, 개수 제한 없음)가 기준. 로그가 없으면 ai_sessions.messages(최근 40개)로 대신한다.
 * @return array{turns:list<array>, source:string}
 */
function consult_chat_history(PDO $pdo, ?string $sessionKey): array
{
    if ($sessionKey === null || $sessionKey === '') {
        return ['turns' => [], 'source' => 'none'];
    }
    $st = $pdo->prepare(
        'SELECT user_msg, assistant_txt, events_json, created_at FROM chat_logs WHERE session_key = :k ORDER BY id'
    );
    $st->execute(['k' => $sessionKey]);
    $rows = $st->fetchAll();
    if ($rows) {
        return ['turns' => consult_chat_turns($rows), 'source' => 'chat_logs'];
    }
    $st = $pdo->prepare('SELECT messages, updated_at FROM ai_sessions WHERE session_key = :k');
    $st->execute(['k' => $sessionKey]);
    $row = $st->fetch();
    $msgs = $row ? json_decode((string) $row['messages'], true) : null;
    $turns = [];
    foreach (is_array($msgs) ? $msgs : [] as $m) {
        $c = is_array($m) ? ($m['content'] ?? '') : '';
        $c = is_string($c) ? $c : (is_array($c) ? (string) ($c[0]['text'] ?? '') : '');
        if (($m['role'] ?? '') === 'user') {
            $turns[] = ['at' => '', 'user' => $c, 'ai' => '', 'products' => []];
        } elseif (($m['role'] ?? '') === 'assistant' && $turns) {
            $turns[count($turns) - 1]['ai'] = $c;
        }
    }
    return ['turns' => $turns, 'source' => $turns ? 'ai_sessions' : 'none'];
}

/** 관리자 화면 요청 위조 방지 토큰(세션당 1개) */
function consult_csrf_token(): string
{
    if (empty($_SESSION['consult_csrf']) || !is_string($_SESSION['consult_csrf'])) {
        $_SESSION['consult_csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['consult_csrf'];
}

function consult_csrf_valid(?string $token): bool
{
    return is_string($token) && $token !== '' && isset($_SESSION['consult_csrf'])
        && hash_equals((string) $_SESSION['consult_csrf'], $token);
}

/** '2026-10-30 15:24:11' → '2026.10.30 15:24' (순수 함수) */
function consult_fmt_dt(?string $dt): string
{
    $t = $dt ? strtotime($dt) : false;
    return $t ? date('Y.m.d H:i', $t) : '';
}

/** 목록용 'MM/DD HH:mm' (순수 함수) */
function consult_fmt_short(?string $dt): string
{
    $t = $dt ? strtotime($dt) : false;
    return $t ? date('m/d H:i', $t) : '';
}
