<?php
declare(strict_types=1);
/**
 * 원격 관리 API — 실행 이력·백업(스냅샷) 저장 (app/remote_ops/audit.php)
 *
 * 책임: remote_ops_log(작업 1건 = 1행: 시각·작업·대상·영향 행 수·결과·오류)
 *       remote_ops_snapshots(변경 전후 행, 파일 백업, 승인 대기 파일, 테이블 덤프 — gzip)
 * 금지: 인증정보(키·서명·비밀번호)·파일 본문을 이력(request_json/result_json)에 넣기 — 본문은 스냅샷에만
 */

/** 요청 값 요약(이력용): 파일 본문 제거, 긴 문자열 자르기 */
function rops_summarize(mixed $v, int $depth = 0): mixed
{
    if (is_string($v)) {
        return strlen($v) > 2000 ? substr($v, 0, 2000) . '…(' . strlen($v) . 'B)' : $v;
    }
    if (!is_array($v)) {
        return $v;
    }
    if ($depth > 4) {
        return '…';
    }
    $out = [];
    $i = 0;
    foreach ($v as $k => $x) {
        if (in_array($k, ['content_b64', 'content', 'sig', 'key'], true)) {
            $out[$k] = is_string($x) ? '(' . strlen($x) . 'B 생략)' : '(생략)';
            continue;
        }
        if (++$i > 200) {
            $out['…'] = (count($v) - 200) . '개 더';
            break;
        }
        $out[$k] = rops_summarize($x, $depth + 1);
    }
    return $out;
}

function rops_log_start(PDO $pdo, string $op, array $params, ?string $ip): int
{
    $st = $pdo->prepare('INSERT INTO remote_ops_log (created_at, op, status, client_ip, request_json) VALUES (NOW(), ?, \'running\', ?, ?)');
    $st->execute([$op, $ip, rops_json(['params' => rops_summarize($params)])]);
    return (int) $pdo->lastInsertId();
}

/**
 * @param array{grant_id?:?int,target?:?string,affected_rows?:?int,result?:mixed,error_code?:?string,error_message?:?string,revert_of?:?int} $f
 */
function rops_log_finish(PDO $pdo, int $id, string $status, int $durationMs, array $f): void
{
    $st = $pdo->prepare('UPDATE remote_ops_log SET finished_at = NOW(), status = ?, duration_ms = ?, grant_id = ?, target = ?, affected_rows = ?,
        result_json = ?, error_code = ?, error_message = ?, revert_of = ? WHERE id = ?');
    $st->execute([
        $status,
        $durationMs,
        $f['grant_id'] ?? null,
        isset($f['target']) ? substr((string) $f['target'], 0, 255) : null,
        $f['affected_rows'] ?? null,
        array_key_exists('result', $f) ? rops_json(['result' => rops_summarize($f['result'])]) : null,
        $f['error_code'] ?? null,
        isset($f['error_message']) ? rops_clean_message((string) $f['error_message']) : null,
        $f['revert_of'] ?? null,
        $id,
    ]);
}

/**
 * 백업 저장. $content=null + $absent=true 는 '변경 전 파일 없음' 표시.
 * $shaOnly 를 주면 본문 없이 해시만 기록(배포 후 해시 등).
 */
function rops_snapshot_put(PDO $pdo, ?int $logId, ?int $grantId, string $kind, string $ref, ?string $content, bool $absent = false, ?string $shaOnly = null): int
{
    if ($absent || $content === null) {
        $enc = 'none'; $body = null; $sha = $shaOnly; $size = null;
    } else {
        [$enc, $body] = rops_pack($content);
        $sha = hash('sha256', $content);
        $size = strlen($content);
    }
    $st = $pdo->prepare('INSERT INTO remote_ops_snapshots (log_id, grant_id, kind, ref, sha256, size_bytes, encoding, body, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $st->bindValue(1, $logId, $logId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(2, $grantId, $grantId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(3, $kind);
    $st->bindValue(4, substr($ref, 0, 255));
    $st->bindValue(5, $sha);
    $st->bindValue(6, $size, $size === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
    $st->bindValue(7, $enc);
    $st->bindValue(8, $body, $body === null ? PDO::PARAM_NULL : PDO::PARAM_LOB);
    $st->execute();
    return (int) $pdo->lastInsertId();
}

/**
 * @return list<array{id:int,kind:string,ref:string,sha256:?string,size_bytes:?int,absent:bool,content:?string}>
 */
function rops_snapshots(PDO $pdo, string $by, int $id, ?string $kind = null, bool $withContent = true): array
{
    $col = $by === 'grant' ? 'grant_id' : 'log_id';
    $sql = 'SELECT id, kind, ref, sha256, size_bytes, encoding' . ($withContent ? ', body' : '') . " FROM remote_ops_snapshots WHERE {$col} = ?";
    $p = [$id];
    if ($kind !== null) {
        $sql .= ' AND kind = ?';
        $p[] = $kind;
    }
    $st = $pdo->prepare($sql . ' ORDER BY id');
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll() as $r) {
        $out[] = [
            'id' => (int) $r['id'], 'kind' => $r['kind'], 'ref' => $r['ref'], 'sha256' => $r['sha256'],
            'size_bytes' => $r['size_bytes'] !== null ? (int) $r['size_bytes'] : null,
            'absent' => $r['encoding'] === 'none',
            'content' => $withContent ? rops_unpack($r['encoding'], $r['body']) : null,
        ];
    }
    return $out;
}

function rops_log_get(PDO $pdo, int $id): ?array
{
    $st = $pdo->prepare('SELECT * FROM remote_ops_log WHERE id = ?');
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        return null;
    }
    foreach (['request_json', 'result_json'] as $k) {
        $r[$k] = $r[$k] !== null ? json_decode($r[$k], true) : null;
    }
    return $r;
}

/** 이력 목록(본문 없이) */
function rops_log_list(PDO $pdo, int $limit = 30, ?string $op = null, ?int $grantId = null): array
{
    $limit = max(1, min(200, $limit));
    $w = [];
    $p = [];
    if ($op !== null && $op !== '') { $w[] = 'op LIKE ?'; $p[] = str_replace(['%', '_'], ['\\%', '\\_'], $op) . '%'; }
    if ($grantId !== null) { $w[] = 'grant_id = ?'; $p[] = $grantId; }
    $st = $pdo->prepare('SELECT id, created_at, finished_at, op, status, grant_id, target, affected_rows, duration_ms, error_code, error_message, revert_of, reverted_by
        FROM remote_ops_log' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY id DESC LIMIT {$limit}");
    $st->execute($p);
    return $st->fetchAll();
}
