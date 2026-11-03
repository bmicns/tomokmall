<?php
declare(strict_types=1);
/**
 * 원격 관리 API — 작업 단위 승인(grant) (app/remote_ops/grants.php)
 *
 * 흐름: Claude(CLI)가 승인 요청 생성(pending) → 관리자가 관리자 화면(/admin-remote-ops.php)에서 승인/거절
 *       → 승인된 범위·유효시간 안에서만 쓰기 작업 허용 → 사용량(used_ops·used_rows) 누적 → 만료·회수·사용 완료
 *
 * 종류(kind)·방식(mode) — 운영 DB 변경과 운영 서버 파일 변경의 승인은 서로 다른 grant(kind)로만 처리한다.
 *   db/scope   : 테이블·작업(update/insert/delete)·컬럼·대상 PK·최대 행 수 범위 안에서 구조화 쓰기 여러 번
 *   db/sql     : 승인 화면에 보인 SQL 파일 본문(해시 고정) 1회 실행
 *   files/staged : 승인 화면에 보인 파일 내용(해시 고정) 배포·삭제 1회
 * 되돌리기(revert/restore): 원래 grant 의 유효시간 + 24시간 안이면 같은 grant 로 허용(회수·거절된 grant 는 불가)
 *
 * 금지: 키 API 로 승인(approve)하기 — 승인은 관리자 세션(관리자 화면)에서만 rops_grant_decide() 로
 */

const ROPS_GRANT_ROLLBACK_HOURS = 24;
const ROPS_GRANT_PENDING_HOURS  = 24;

function rops_grant_code(): string
{
    $alpha = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $s = '';
    foreach (str_split(random_bytes(5)) as $b) {
        $s .= $alpha[ord($b) % strlen($alpha)];
    }
    return 'G' . date('ymd') . '-' . $s;
}

/** 만료 처리(승인 후 유효시간 지남 / 승인 대기 24시간 지남) */
function rops_grant_sweep(PDO $pdo): void
{
    $pdo->exec("UPDATE remote_ops_grants SET status = 'expired' WHERE status = 'approved' AND expires_at < NOW()");
    $pdo->exec("UPDATE remote_ops_grants SET status = 'expired' WHERE status = 'pending' AND request_expires_at < NOW()");
}

/**
 * @param array{kind:string,mode:string,title:string,detail?:?string,scope:array,payload?:?string,preview?:?array,ttl_minutes:int,max_ops:int} $g
 */
function rops_grant_create(PDO $pdo, array $g): array
{
    $ttl = max(5, min(1440, (int) $g['ttl_minutes']));
    $maxOps = max(1, min(500, (int) $g['max_ops']));
    $title = trim((string) $g['title']);
    if ($title === '' || mb_strlen($title) > 200) {
        throw new RopsError('bad_params', '승인 요청 제목(title)은 1~200자');
    }
    // 승인 대기 요청이 쌓여 DB 를 채우지 않게(키만으로 만들 수 있는 것이라 상한을 둔다)
    $pending = (int) $pdo->query("SELECT COUNT(*) FROM remote_ops_grants WHERE status = 'pending'")->fetchColumn();
    if ($pending >= 10) {
        throw new RopsError('too_many_pending', '승인 대기 요청이 10건 이상입니다. 관리자 화면에서 정리하거나 grant cancel 로 취소한 뒤 요청하세요');
    }
    for ($try = 0; $try < 5; $try++) {
        $code = rops_grant_code();
        try {
            $st = $pdo->prepare('INSERT INTO remote_ops_grants (code, kind, mode, title, detail, scope_json, payload, preview_json, status, ttl_minutes, max_ops,
                requested_at, request_expires_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, \'pending\', ?, ?, NOW(), NOW() + INTERVAL ' . ROPS_GRANT_PENDING_HOURS . ' HOUR)');
            $st->execute([$code, $g['kind'], $g['mode'], $title, $g['detail'] ?? null, rops_json($g['scope']), $g['payload'] ?? null,
                isset($g['preview']) ? rops_json($g['preview']) : null, $ttl, $maxOps]);
            return rops_grant_get($pdo, $code) ?? throw new RopsError('internal', '승인 요청 저장 확인 실패');
        } catch (\PDOException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) {
                throw $e;
            }
        }
    }
    throw new RopsError('internal', '승인 코드 생성 실패');
}

/** grant 1건(+ DB 시각 기준 판정값). $lock=true 면 트랜잭션 안에서 FOR UPDATE */
function rops_grant_get(PDO $pdo, string $code, bool $lock = false): ?array
{
    if (!preg_match('/^G\d{6}-[A-Z0-9]{5}$/', $code)) {
        return null;
    }
    $st = $pdo->prepare('SELECT g.*, NOW() AS db_now,
            (g.status = \'approved\' AND g.expires_at >= NOW()) AS is_live,
            (g.decided_at IS NOT NULL AND g.status IN (\'approved\',\'used\',\'expired\') AND g.expires_at IS NOT NULL
                AND g.expires_at + INTERVAL ' . ROPS_GRANT_ROLLBACK_HOURS . ' HOUR >= NOW()) AS in_rollback_window
        FROM remote_ops_grants g WHERE g.code = ?' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute([$code]);
    $g = $st->fetch();
    if (!$g) {
        return null;
    }
    $g['id'] = (int) $g['id'];
    $g['scope'] = json_decode((string) $g['scope_json'], true) ?: [];
    $g['preview'] = $g['preview_json'] !== null ? json_decode((string) $g['preview_json'], true) : null;
    $g['is_live'] = (bool) $g['is_live'];
    $g['in_rollback_window'] = (bool) $g['in_rollback_window'];
    foreach (['ttl_minutes', 'max_ops', 'used_ops', 'used_rows'] as $k) {
        $g[$k] = (int) $g[$k];
    }
    return $g;
}

function rops_grant_get_by_id(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT code FROM remote_ops_grants WHERE id = ?');
    $st->execute([$id]);
    $c = $st->fetchColumn();
    return $c === false ? null : rops_grant_get($pdo, (string) $c);
}

/** API 응답용(본문·내부값 제외) */
function rops_grant_public(array $g): array
{
    return [
        'code' => $g['code'], 'kind' => $g['kind'], 'mode' => $g['mode'], 'title' => $g['title'], 'status' => $g['status'],
        'scope' => $g['scope'], 'ttl_minutes' => $g['ttl_minutes'], 'max_ops' => $g['max_ops'], 'used_ops' => $g['used_ops'],
        'used_rows' => $g['used_rows'], 'requested_at' => $g['requested_at'], 'decided_at' => $g['decided_at'],
        'expires_at' => $g['expires_at'], 'decided_note' => $g['decided_note'], 'is_live' => $g['is_live'],
        'in_rollback_window' => $g['in_rollback_window'], 'db_now' => $g['db_now'],
    ];
}

/**
 * 쓰기 작업용 grant 확인(트랜잭션 안에서 호출 — 잠금). 통과하면 grant 반환.
 * @param bool $forRollback true 면 되돌리기 규칙(유효시간+24h, 사용 횟수 무관)
 */
function rops_grant_require(PDO $pdo, string $code, string $kind, ?string $mode, bool $forRollback = false): array
{
    // 만료 판정은 is_live(DB 시각 기준)로 한다. 상태 값 정리(rops_grant_sweep)는 요청 시작 시 트랜잭션 밖에서
    $g = rops_grant_get($pdo, $code, true);
    if ($g === null) {
        throw new RopsError('grant_not_found', "승인을 찾을 수 없음: {$code}");
    }
    if ($g['kind'] !== $kind || ($mode !== null && $g['mode'] !== $mode)) {
        throw new RopsError('grant_mismatch', "이 작업에 쓸 수 없는 승인입니다(승인 종류 {$g['kind']}/{$g['mode']})");
    }
    if ($forRollback) {
        if (!$g['in_rollback_window']) {
            throw new RopsError('grant_inactive', "되돌리기 가능 시간이 지났거나 회수된 승인입니다(상태 {$g['status']}). 새 승인을 요청하세요.");
        }
        return $g;
    }
    if ($g['status'] === 'pending') {
        throw new RopsError('grant_pending', "아직 승인되지 않았습니다: {$code}");
    }
    if (!$g['is_live']) {
        throw new RopsError('grant_inactive', "사용할 수 없는 승인입니다(상태 {$g['status']})");
    }
    if ($g['used_ops'] >= $g['max_ops']) {
        throw new RopsError('grant_exhausted', "승인된 작업 횟수({$g['max_ops']})를 모두 썼습니다");
    }
    return $g;
}

/** 사용량 반영. $finish=true 면 상태를 used 로(1회용 승인) */
function rops_grant_use(PDO $pdo, array $g, int $rows, bool $finish): void
{
    // MySQL UPDATE 는 대입을 왼쪽부터 적용하므로 status 를 먼저 계산(이후 used_ops 증가)
    $st = $pdo->prepare('UPDATE remote_ops_grants SET status = IF(? OR used_ops + 1 >= max_ops, \'used\', status),
        used_ops = used_ops + 1, used_rows = used_rows + ? WHERE id = ?');
    $st->execute([$finish ? 1 : 0, max(0, $rows), $g['id']]);
}

/**
 * 관리자 결정(관리자 화면 전용). $action: approve | reject | revoke
 * @return array grant
 */
function rops_grant_decide(PDO $pdo, string $code, string $action, int $adminId, string $note = ''): array
{
    rops_grant_sweep($pdo);
    $pdo->beginTransaction();
    try {
        $g = rops_grant_get($pdo, $code, true);
        if ($g === null) {
            throw new RopsError('grant_not_found', '승인 요청을 찾을 수 없습니다.');
        }
        $note = function_exists('mb_substr') ? mb_substr(trim($note), 0, 250) : substr(trim($note), 0, 250);
        if ($action === 'approve') {
            if ($g['status'] !== 'pending') {
                throw new RopsError('grant_state', "승인 대기 상태가 아닙니다(현재 {$g['status']}).");
            }
            $st = $pdo->prepare('UPDATE remote_ops_grants SET status = \'approved\', decided_at = NOW(), decided_by = ?, decided_note = ?,
                expires_at = NOW() + INTERVAL ttl_minutes MINUTE WHERE id = ?');
            $st->execute([$adminId, $note, $g['id']]);
        } elseif ($action === 'reject') {
            if ($g['status'] !== 'pending') {
                throw new RopsError('grant_state', "승인 대기 상태가 아닙니다(현재 {$g['status']}).");
            }
            $pdo->prepare('UPDATE remote_ops_grants SET status = \'rejected\', decided_at = NOW(), decided_by = ?, decided_note = ? WHERE id = ?')
                ->execute([$adminId, $note, $g['id']]);
        } elseif ($action === 'revoke') {
            if (!in_array($g['status'], ['approved', 'pending'], true)) {
                throw new RopsError('grant_state', "회수할 수 있는 상태가 아닙니다(현재 {$g['status']}).");
            }
            $pdo->prepare('UPDATE remote_ops_grants SET status = \'revoked\', decided_at = COALESCE(decided_at, NOW()), decided_by = COALESCE(decided_by, ?),
                decided_note = CONCAT(COALESCE(decided_note, \'\'), ?) WHERE id = ?')
                ->execute([$adminId, ' [회수] ' . $note, $g['id']]);
        } else {
            throw new RopsError('bad_params', '알 수 없는 결정');
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
    return rops_grant_get($pdo, $code) ?? $g;
}

/** 사용 중인(승인됨·대기) 승인 전부 회수 — 관리자 화면 '전체 회수' */
function rops_grant_revoke_all(PDO $pdo, int $adminId): int
{
    $st = $pdo->prepare('UPDATE remote_ops_grants SET status = \'revoked\', decided_at = COALESCE(decided_at, NOW()), decided_by = COALESCE(decided_by, ?),
        decided_note = CONCAT(COALESCE(decided_note, \'\'), \' [전체 회수]\') WHERE status IN (\'approved\', \'pending\')');
    $st->execute([$adminId]);
    return $st->rowCount();
}

function rops_grant_list(PDO $pdo, ?string $status = null, int $limit = 30): array
{
    rops_grant_sweep($pdo);
    $limit = max(1, min(200, $limit));
    $sql = 'SELECT code FROM remote_ops_grants' . ($status ? ' WHERE status = ?' : '') . " ORDER BY id DESC LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    $st->execute($status ? [$status] : []);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $code) {
        $g = rops_grant_get($pdo, (string) $code);
        if ($g) {
            $out[] = $g;
        }
    }
    return $out;
}
