<?php
declare(strict_types=1);
/**
 * 원격 관리 API — 요청 처리(인증·재전송 방지·권한·이력·응답) (app/remote_ops/server.php)
 *
 * 진입점: public/api/remote-ops.php → rops_main(웹루트)
 * 처리 순서: POST 확인 → 사용 설정 확인 → (선택) IP 제한 → 봉투 모양 → 키 ID → 시각(±skew) → 서명(HMAC) → 복호화
 *           → 작업·범위(REMOTE_OPS_SCOPES) 확인 → DB 연결 → nonce 1회 사용 등록(재전송 차단) → 이력 시작 → 작업 → 이력 종료 → 서명·암호화 응답
 * 응답: 항상 HTTP 200 + JSON(닷홈 Apache 가 4xx 본문을 HTML 로 바꾸는 문제 회피). 인증 전 실패는 서명 없는 짧은 오류만.
 * 금지: 키·서명·DB 비밀번호를 응답·이력·서버 로그에 쓰기, 승인(approve) 작업 제공(관리자 화면 전용)
 */

require_once __DIR__ . '/protocol.php';
require_once __DIR__ . '/core.php';
require_once __DIR__ . '/sql_guard.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/grants.php';
require_once __DIR__ . '/db_ops.php';
require_once __DIR__ . '/file_ops.php';
require_once __DIR__ . '/diff.php';

const ROPS_VERSION = '1.0.0';

/** 작업 목록: 이름 → [필요 범위(null=항상), DB 필요 여부] */
function rops_ops(): array
{
    return [
        'probe'            => ['probe', false],
        'db.tables'        => ['db.read', true],
        'db.describe'      => ['db.read', true],
        'db.query'         => ['db.read', true],
        'db.search'        => ['db.read', true],
        'db.verify'        => ['db.read', true],
        'db.update'        => ['db.write', true],
        'db.batch'         => ['db.write', true],
        'db.insert'        => ['db.write', true],
        'db.delete'        => ['db.write', true],
        'db.revert'        => ['db.write', true],
        'db.execute'       => ['db.write', true],
        'db.stage_sql'     => ['db.write', true],
        'db.stage_restore' => ['db.write', true],
        'db.backup'        => ['db.write', true],
        'files.list'       => ['files.read', false],
        'files.read'       => ['files.read', false],
        'files.hash'       => ['files.read', false],
        'files.manifest'   => ['files.read', false],
        'files.lint'       => ['files.read', false],
        'files.snapshot'   => ['files.read', true],
        'files.stage'      => ['files.write', true],
        'files.deploy'     => ['files.write', true],
        'files.restore'    => ['files.write', true],
        'grant.request'    => ['db.write', true],
        'grant.status'     => [null, true],
        'grant.list'       => [null, true],
        'grant.cancel'     => [null, true],
        'log.list'         => [null, true],
        'log.get'          => [null, true],
    ];
}

/** 인증 전 실패 응답(서명 없음, 이유 코드만) */
function rops_plain_fail(string $reason, array $extra = []): never
{
    echo json_encode(['v' => 1, 'ok' => false, 'error' => 'auth_failed', 'reason' => $reason] + $extra, JSON_UNESCAPED_UNICODE);
    exit;
}

function rops_client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function rops_error_status(string $code): string
{
    foreach (['grant_', 'out_of_scope', 'forbidden', 'path_denied', 'scope_denied', 'bulk_delete', 'cascade', 'too_many_rows'] as $p) {
        if (str_starts_with($code, $p)) {
            return 'denied';
        }
    }
    return 'error';
}

function rops_main(string $webroot): void
{
    // 경고·알림이 응답(JSON)에 섞이지 않게(사이트 bootstrap.php 와 같은 설정 — 이 진입점은 bootstrap 을 쓰지 않음)
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    error_reporting(E_ALL & ~E_NOTICE & ~E_DEPRECATED);
    $t0 = rops_now_ms();
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
    header('X-Content-Type-Options: nosniff');
    $cfg = rops_settings($webroot);

    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        rops_plain_fail('method');
    }
    if (!$cfg['enabled']) {
        rops_plain_fail('disabled');
    }
    if ($cfg['allowed_ips'] !== [] && !in_array(rops_client_ip(), $cfg['allowed_ips'], true)) {
        rops_plain_fail('ip_denied');
    }
    $limit = max(8 * 1048576, (int) ($cfg['max_file_bytes'] * 1.4 * 4));
    $raw = (string) file_get_contents('php://input', false, null, 0, $limit + 1);
    if (strlen($raw) > $limit) {
        rops_plain_fail('too_large');
    }
    $env = json_decode($raw, true);
    if (($why = rops_envelope_problem($env)) !== null) {
        rops_plain_fail($why);
    }
    if (!hash_equals($cfg['kid'], $env['kid'])) {
        rops_plain_fail('unknown_key');
    }
    if (abs(time() - $env['ts']) > $cfg['skew']) {
        rops_plain_fail('expired', ['server_time' => time()]);
    }
    try {
        $payload = rops_open($cfg['key'], $env, 'req');
    } catch (RopsProtoError $e) {
        rops_plain_fail($e->getMessage());
    }

    // ── 여기부터는 인증된 요청: 응답도 서명(+요청이 암호화였으면 암호화) ──
    $op = $env['op'];
    $respond = static function (array $body) use ($cfg, $env, $op): never {
        echo json_encode(rops_seal($cfg['key'], 'res', $cfg['kid'], time(), $env['nonce'], $op, $body, $env['enc']), JSON_UNESCAPED_SLASHES);
        exit;
    };
    $fail = static function (string $code, string $msg, array $data = [], ?int $opId = null) use ($respond): never {
        $respond(['ok' => false, 'error' => ['code' => $code, 'message' => $msg, 'data' => $data], 'op_id' => $opId]);
    };

    $ops = rops_ops();
    if (!isset($ops[$op])) {
        $fail('unknown_op', "알 수 없는 작업: {$op}");
    }
    [$needScope, $needDb] = $ops[$op];
    if ($env['enc'] === ROPS_ENC_NONE && $op !== 'probe' && $cfg['require_enc']) {
        $fail('encryption_required', '이 서버는 암호화(aes-256-gcm) 요청만 받습니다');
    }
    if ($needScope !== null && !in_array($needScope, $cfg['scopes'], true)) {
        $fail('scope_denied', "이 키로 허용되지 않은 범위: {$needScope}");
    }
    $params = $payload['params'] ?? [];
    if (!is_array($params)) {
        $fail('bad_params', 'params 는 객체');
    }

    // DB 연결 + nonce 등록(재전송 차단). probe 만은 DB·관리 테이블 없이도 진단 가능(시각 검사로만 보호)
    $pdo = null;
    $dbError = null;
    $installed = false;
    try {
        $pdo = rops_pdo();
        try {
            $pdo->prepare('INSERT INTO remote_ops_nonces (nonce, expires_at) VALUES (?, NOW() + INTERVAL ? SECOND)')
                ->execute([$env['nonce'], 2 * $cfg['skew']]);
            $installed = true;
            // 만료 처리는 트랜잭션 밖에서(쓰기 작업이 롤백돼도 만료 상태는 남게)
            rops_grant_sweep($pdo);
            if (random_int(1, 20) === 1) {
                $pdo->exec('DELETE FROM remote_ops_nonces WHERE expires_at < NOW() LIMIT 1000');
            }
        } catch (\PDOException $e) {
            $no = (int) ($e->errorInfo[1] ?? 0);
            if ($no === 1062) {
                $fail('replay', '이미 사용한 요청입니다(재전송 차단)');
            }
            if ($no !== 1146) {
                throw $e;
            }
            $dbError = '관리 테이블이 없습니다(database/20261103_remote_ops.sql 미실행)';
        }
    } catch (RopsError $e) {
        $dbError = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('[remote-ops] nonce 등록 실패: ' . get_class($e));
        $dbError = 'DB 오류';
    }
    if (!$installed && $op !== 'probe') {
        $fail('not_ready', $dbError ?? 'DB 준비 안 됨');
    }

    $logId = null;
    if ($installed) {
        try {
            $logId = rops_log_start($pdo, $op, $params, rops_client_ip());
        } catch (\Throwable $e) {
            error_log('[remote-ops] 이력 시작 실패: ' . get_class($e));
            if ($op !== 'probe') {
                $fail('log_failed', '실행 이력을 남길 수 없어 작업하지 않았습니다');
            }
        }
    }
    $ctx = ['log_id' => $logId, 'ip' => rops_client_ip()];
    try {
        $out = rops_dispatch($op, $pdo, $ctx, $params, $cfg, $dbError);
        if ($logId !== null) {
            rops_log_finish($pdo, $logId, $out['log']['status'] ?? 'ok', rops_now_ms() - $t0, $out['log'] + ['result' => rops_result_summary($out['result'])]);
        }
        $respond(['ok' => true, 'result' => $out['result'], 'op_id' => $logId, 'server_time' => time()]);
    } catch (RopsError $e) {
        $code = $e->codeName;
        $msg = rops_clean_message($e->getMessage());
        if ($logId !== null) {
            try {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                rops_log_finish($pdo, $logId, rops_error_status($code), rops_now_ms() - $t0, ['error_code' => $code, 'error_message' => $msg, 'result' => $e->data]);
            } catch (\Throwable $e2) {
                error_log('[remote-ops] 이력 종료 실패: ' . get_class($e2));
            }
        }
        $fail($code, $msg, $e->data, $logId);
    } catch (\Throwable $e) {
        error_log('[remote-ops] ' . $op . ' 내부 오류: ' . get_class($e) . ': ' . rops_clean_message($e->getMessage()));
        if ($logId !== null) {
            try {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                rops_log_finish($pdo, $logId, 'error', rops_now_ms() - $t0, ['error_code' => 'internal', 'error_message' => get_class($e) . ': ' . $e->getMessage()]);
            } catch (\Throwable $e2) {
            }
        }
        $fail('internal', '서버 내부 오류: ' . get_class($e) . ' — ' . rops_clean_message($e->getMessage()), [], $logId);
    }
}

/** 이력에 남길 결과 요약(행 데이터·파일 본문 제외) */
function rops_result_summary(mixed $r): mixed
{
    if (!is_array($r)) {
        return $r;
    }
    $out = [];
    foreach ($r as $k => $v) {
        if (in_array($k, ['rows', 'content_b64', 'entries', 'files', 'diff', 'tables', 'verify_before', 'verify_after', 'saved', 'current', 'create_table', 'columns'], true) && is_array($v) || $k === 'content_b64') {
            $out[$k] = is_array($v) ? '(' . count($v) . '개)' : '(생략)';
            continue;
        }
        $out[$k] = $v;
    }
    return $out;
}

function rops_dispatch(string $op, ?PDO $pdo, array $ctx, array $p, array $cfg, ?string $dbError): array
{
    switch ($op) {
        case 'probe':            return rops_probe($pdo, $p, $cfg, $dbError);
        case 'db.tables':        return rops_db_tables($pdo, $p);
        case 'db.describe':      return rops_db_describe($pdo, $p);
        case 'db.query':         return rops_db_query($pdo, $p);
        case 'db.search':        return rops_db_search($pdo, $p);
        case 'db.verify':        return rops_db_verify($pdo, $p);
        case 'db.update':        return rops_db_structured($pdo, $ctx, 'update', $p);
        case 'db.batch':         return rops_db_structured($pdo, $ctx, 'batch', $p);
        case 'db.insert':        return rops_db_structured($pdo, $ctx, 'insert', $p);
        case 'db.delete':        return rops_db_structured($pdo, $ctx, 'delete', $p);
        case 'db.revert':        return rops_db_revert($pdo, $ctx, $p);
        case 'db.execute':       return rops_db_execute($pdo, $ctx, $p);
        case 'db.stage_sql':     return rops_db_stage_sql($pdo, $p);
        case 'db.stage_restore': return rops_db_stage_restore($pdo, $p);
        case 'db.backup':        return rops_db_backup($pdo, $ctx, $p);
        case 'files.list':       return rops_files_list($p);
        case 'files.read':       return rops_files_read($p);
        case 'files.hash':       return rops_files_hash($p);
        case 'files.manifest':   return rops_files_manifest($p);
        case 'files.lint':       return rops_files_lint($p);
        case 'files.snapshot':   return rops_files_snapshot($pdo, $p);
        case 'files.stage':      return rops_files_stage($pdo, $p);
        case 'files.deploy':     return rops_files_deploy($pdo, $ctx, $p);
        case 'files.restore':    return rops_files_restore($pdo, $ctx, $p);
        case 'grant.request':    return rops_grant_request_scope($pdo, $p);
        case 'grant.status':
            $g = rops_grant_get($pdo, (string) ($p['code'] ?? ''));
            if ($g === null) { throw new RopsError('grant_not_found', '승인을 찾을 수 없음'); }
            rops_grant_sweep($pdo);
            $g = rops_grant_get($pdo, $g['code']);
            return ['result' => ['grant' => rops_grant_public($g), 'approve_url' => rops_approve_url($g['code'])], 'log' => ['grant_id' => $g['id'], 'target' => 'grant:' . $g['code']]];
        case 'grant.list':
            $list = array_map('rops_grant_public', rops_grant_list($pdo, isset($p['status']) ? (string) $p['status'] : null, (int) ($p['limit'] ?? 20)));
            return ['result' => ['grants' => $list], 'log' => ['target' => 'grants', 'affected_rows' => count($list)]];
        case 'grant.cancel':
            $g = rops_grant_get($pdo, (string) ($p['code'] ?? ''));
            if ($g === null) { throw new RopsError('grant_not_found', '승인을 찾을 수 없음'); }
            $st = $pdo->prepare("UPDATE remote_ops_grants SET status = 'canceled' WHERE id = ? AND status IN ('pending','approved')");
            $st->execute([$g['id']]);
            return ['result' => ['canceled' => $st->rowCount() === 1, 'grant' => rops_grant_public(rops_grant_get($pdo, $g['code']))], 'log' => ['grant_id' => $g['id'], 'target' => 'grant:' . $g['code']]];
        case 'log.list':
            $rows = rops_log_list($pdo, (int) ($p['limit'] ?? 30), isset($p['op']) ? (string) $p['op'] : null, isset($p['grant_id']) ? (int) $p['grant_id'] : null);
            return ['result' => ['operations' => $rows], 'log' => ['target' => 'log', 'affected_rows' => count($rows)]];
        case 'log.get':
            $l = rops_log_get($pdo, (int) ($p['id'] ?? 0));
            if ($l === null) { throw new RopsError('op_not_found', '작업을 찾을 수 없음'); }
            $l['snapshots'] = array_map(static fn($s) => array_diff_key($s, ['content' => 1]), rops_snapshots($pdo, 'log', (int) $l['id'], null, false));
            return ['result' => ['operation' => $l], 'log' => ['target' => 'op:' . $l['id']]];
    }
    throw new RopsError('unknown_op', "알 수 없는 작업: {$op}");
}

/** grant.request: db/scope 승인 요청(구조화 쓰기용 범위) */
function rops_grant_request_scope(PDO $pdo, array $p): array
{
    $tables = array_values(array_unique(array_map('strval', (array) ($p['tables'] ?? []))));
    $actions = array_values(array_unique(array_map('strval', (array) ($p['actions'] ?? []))));
    if ($tables === [] || count($tables) > 10) {
        throw new RopsError('bad_params', 'tables: 1~10개');
    }
    if ($actions === [] || array_diff($actions, ['update', 'insert', 'delete']) !== []) {
        throw new RopsError('bad_params', 'actions 는 update·insert·delete 중에서');
    }
    $columns = $p['columns'] ?? null;
    $ids = $p['ids'] ?? null;
    $preview = ['tables' => []];
    foreach ($tables as $t) {
        rops_deny_ops_table($t);
        $meta = rops_table_meta($pdo, $t);
        if ($meta['pk'] === []) {
            throw new RopsError('no_primary_key', "기본키가 없는 테이블: {$t}");
        }
        if (is_array($columns) && isset($columns[$t])) {
            rops_scope_columns_check(['columns' => null], $meta, array_map('strval', (array) $columns[$t]), true);
        }
        $info = ['table' => $t, 'pk' => $meta['pk'], 'cascade_children' => rops_cascade_children($pdo, $t)];
        if (is_array($ids) && isset($ids[$t])) {
            $list = array_values((array) $ids[$t]);
            if (count($meta['pk']) !== 1 || $list === [] || count($list) > 5000) {
                throw new RopsError('bad_params', "ids.{$t}: 단일 기본키 테이블, 1~5000개");
            }
            $keys = array_map(static fn($v) => [$meta['pk'][0] => $v], $list);
            $rows = rops_fetch_by_keys($pdo, $meta, $keys);
            $show = array_values(array_unique(array_merge($meta['pk'], array_intersect(['name', 'brand', 'spec'], $meta['names']), (array) ($columns[$t] ?? []))));
            $info['rows_found'] = count($rows);
            $info['rows_missing'] = count($keys) - count($rows);
            $info['rows'] = array_map(static fn($r) => rops_mask_row(array_intersect_key($r, array_flip($show)), $t, false), array_slice(array_values($rows), 0, 200));
        }
        $preview['tables'][] = $info;
    }
    $maxTotal = (int) ($p['max_rows_total'] ?? 0);
    if ($maxTotal < 1 || $maxTotal > 100000) {
        throw new RopsError('bad_params', 'max_rows_total: 1~100000 (승인할 최대 변경 행 수)');
    }
    $scope = [
        'tables' => $tables, 'actions' => $actions,
        'columns' => is_array($columns) ? array_map(static fn($c) => array_values(array_map('strval', (array) $c)), $columns) : null,
        'ids' => is_array($ids) ? $ids : null,
        'max_rows_per_op' => max(1, min($maxTotal, (int) ($p['max_rows_per_op'] ?? $maxTotal))),
        'max_rows_total' => $maxTotal,
        'allow_bulk_delete' => !empty($p['allow_bulk_delete']),
        'allow_cascade_delete' => !empty($p['allow_cascade_delete']),
    ];
    $scope['needs_extra_confirm'] = $scope['allow_bulk_delete'] || $scope['allow_cascade_delete'] || (in_array('delete', $actions, true) && $maxTotal > ROPS_BULK_DELETE_ROWS);
    $g = rops_grant_create($pdo, ['kind' => 'db', 'mode' => 'scope', 'title' => (string) ($p['title'] ?? ''), 'detail' => $p['detail'] ?? null,
        'scope' => $scope, 'preview' => $preview, 'ttl_minutes' => (int) ($p['ttl_minutes'] ?? 60), 'max_ops' => (int) ($p['max_ops'] ?? 20)]);
    return ['result' => ['grant' => rops_grant_public($g), 'preview' => $preview, 'approve_url' => rops_approve_url($g['code'])],
        'log' => ['grant_id' => $g['id'], 'target' => 'grant:' . $g['code']]];
}

/** probe: 서버 환경 진단(키·비밀번호 없음). write_test=true 면 각 루트에 임시 파일을 만들었다 바로 지워 쓰기 가능 여부 확인 */
function rops_probe(?PDO $pdo, array $p, array $cfg, ?string $dbError): array
{
    $ini = static fn(string $k) => ini_get($k) === false ? null : ini_get($k);
    $disabled = array_values(array_filter(array_map('trim', explode(',', (string) ini_get('disable_functions')))));
    $php = [
        'version' => PHP_VERSION, 'sapi' => PHP_SAPI, 'os' => PHP_OS_FAMILY,
        'process_uid' => function_exists('posix_geteuid') ? posix_geteuid() : null, 'script_owner_uid' => getmyuid(),
        'memory_limit' => $ini('memory_limit'), 'max_execution_time' => $ini('max_execution_time'), 'post_max_size' => $ini('post_max_size'),
        'upload_max_filesize' => $ini('upload_max_filesize'), 'open_basedir' => $ini('open_basedir') ?: null,
        'disable_functions' => $disabled, 'opcache' => extension_loaded('Zend OPcache') && filter_var(ini_get('opcache.enable'), FILTER_VALIDATE_BOOLEAN),
        'opcache_validate_timestamps' => ini_get('opcache.validate_timestamps'), 'opcache_revalidate_freq' => ini_get('opcache.revalidate_freq'),
        'extensions' => ['openssl' => extension_loaded('openssl'), 'aes_256_gcm' => rops_gcm_available(), 'zlib' => function_exists('gzencode'),
            'mbstring' => extension_loaded('mbstring'), 'pdo_mysql' => extension_loaded('pdo_mysql'), 'sodium' => extension_loaded('sodium')],
        'temp_dir' => sys_get_temp_dir(), 'temp_writable' => is_writable(sys_get_temp_dir()),
    ];
    $paths = [];
    foreach (['public', 'app', 'database'] as $k) {
        $abs = $cfg['roots'][$k] ?? null;
        $info = ['root' => $k, 'exists' => $abs !== null && is_dir($abs)];
        if ($info['exists']) {
            $st = stat($abs);
            $info += ['writable' => is_writable($abs), 'owner_uid' => $st['uid'] ?? null, 'perms' => substr(sprintf('%o', $st['mode'] ?? 0), -4)];
            if (!empty($p['write_test'])) {
                if (!in_array('files.write', $cfg['scopes'], true)) {
                    throw new RopsError('scope_denied', 'write_test 는 files.write 범위가 필요합니다');
                }
                $tmp = $abs . '/.rops-probe-' . bin2hex(random_bytes(6)) . '.txt';
                $steps = [];
                try {
                    $steps['create'] = file_put_contents($tmp, 'probe') === 5;
                    $steps['read'] = $steps['create'] && file_get_contents($tmp) === 'probe';
                    $steps['rename'] = $steps['create'] && rename($tmp, $tmp . '.2') && rename($tmp . '.2', $tmp);
                    $steps['delete'] = $steps['create'] && unlink($tmp);
                } catch (\Throwable $e) {
                    $steps['error'] = get_class($e);
                } finally {
                    foreach ([$tmp, $tmp . '.2'] as $f) { if (file_exists($f)) { unlink($f); } }
                }
                $info['write_test'] = $steps;
            }
        }
        $paths[] = $info;
    }
    $sample = [];
    foreach (['app/bootstrap.php', 'app/config.php', 'public/index.php'] as $f) {
        try {
            $r = rops_resolve($f);
            $sample[$f] = $r['is_file'] ? ['writable' => is_writable($r['abs']), 'perms' => substr(sprintf('%o', fileperms($r['abs'])), -4), 'owner_uid' => fileowner($r['abs'])] : null;
        } catch (\Throwable $e) {
            $sample[$f] = ['error' => $e->getMessage()];
        }
    }
    $db = ['connected' => $pdo !== null, 'error' => $dbError];
    if ($pdo !== null) {
        $db['version'] = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
        $db['database'] = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
        $db['now'] = (string) $pdo->query('SELECT NOW()')->fetchColumn();
        // SHOW VARIABLES 는 자리표시자를 못 써서 고정 목록 문자열로 조회
        foreach ($pdo->query("SHOW VARIABLES WHERE Variable_name IN ('max_allowed_packet','sql_mode','time_zone','system_time_zone','lower_case_table_names','innodb_lock_wait_timeout')")->fetchAll(PDO::FETCH_NUM) as $v) {
            $db['vars'][$v[0]] = $v[1];
        }
        $st = $pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE 'remote\\_ops\\_%'");
        $db['ops_tables'] = $st->fetchAll(PDO::FETCH_COLUMN);
        try {
            $db['privileges'] = array_map(static fn($g) => preg_replace("/IDENTIFIED BY .*/i", '', (string) $g), $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN));
        } catch (\Throwable $e) {
            $db['privileges'] = null;
        }
    }
    return ['result' => [
        'api_version' => ROPS_VERSION, 'server_time' => time(), 'server_timezone' => date_default_timezone_get(),
        'https' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'client_ip' => rops_client_ip(),
        'config' => ['kid' => $cfg['kid'], 'scopes' => $cfg['scopes'], 'require_enc' => $cfg['require_enc'], 'skew' => $cfg['skew'],
            'max_file_bytes' => $cfg['max_file_bytes'], 'ip_allowlist' => count($cfg['allowed_ips']), 'base_url' => $cfg['base_url']],
        'php' => $php, 'paths' => $paths, 'sample_files' => $sample, 'db' => $db,
    ], 'log' => ['target' => 'probe' . (!empty($p['write_test']) ? '+write_test' : '')]];
}
