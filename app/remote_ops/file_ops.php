<?php
declare(strict_types=1);
/**
 * 원격 관리 API — 서버 파일 작업 (app/remote_ops/file_ops.php)
 *
 * 경로는 저장소 기준 이름으로 주고받는다(scripts/deploy-files.sh 와 같은 대응):
 *   public/X → 서버 웹루트(/html/X),  app/X → /app/X,  database/X → /database/X   (그 밖의 서버 경로는 다루지 않음)
 * 조회(files.read 범위): files.list · files.read · files.hash · files.manifest · files.lint · files.snapshot
 * 쓰기(files.write 범위, 승인 필요): files.stage(승인 요청 — 내용·해시 고정) → 관리자 승인 → files.deploy(백업→원자적 교체→해시·문법 확인)
 *                                   files.restore(배포 1건 되돌리기 — 원래 승인 유효시간+24시간 안)
 *
 * 차단: 절대경로·`..`·빈 경로·제어문자·역슬래시, 숨김 파일(.env 등, .htaccess 만 예외), 심볼릭 링크(경로 중간 포함),
 *       관리 API 자체 파일·승인 화면 쓰기, 업로드 폴더(public/uploads) 쓰기, 허용 확장자 밖 쓰기, 크기 초과
 */

const ROPS_WRITE_EXT = ['php', 'js', 'css', 'html', 'htm', 'json', 'sql', 'txt', 'md', 'svg', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'ico',
    'woff', 'woff2', 'ttf', 'otf', 'eot', 'csv', 'xml', 'map', 'webmanifest'];
const ROPS_PROTECTED_PATHS = ['app/remote_ops', 'public/api/remote-ops.php', 'public/admin-remote-ops.php'];
const ROPS_NO_WRITE_DIRS   = ['public/uploads'];
const ROPS_LIST_MAX        = 5000;
const ROPS_MANIFEST_MAX    = 10000;

/** 경로 형식 검사(파일시스템 접근 없음). 문제 있으면 이유, 없으면 null */
function rops_path_problem(string $logical): ?string
{
    if ($logical === '' || strlen($logical) > 512) {
        return '경로 길이 1~512';
    }
    if (preg_match('/[\x00-\x1F\x7F\\\\]/', $logical)) {
        return '경로에 제어문자·역슬래시 금지';
    }
    if ($logical[0] === '/' || preg_match('#^[A-Za-z]:#', $logical)) {
        return '절대경로 금지(public/·app/·database/ 로 시작하는 저장소 기준 경로)';
    }
    $segs = explode('/', $logical);
    if (!in_array($segs[0], ['public', 'app', 'database'], true)) {
        return '허용 경로는 public/·app/·database/ 아래뿐';
    }
    foreach (array_slice($segs, 1) as $s) {
        if ($s === '' || $s === '.' || $s === '..') {
            return '경로에 빈 칸·. ·.. 금지';
        }
        if ($s[0] === '.' && $s !== '.htaccess') {
            return '숨김 파일(.env 등)은 다루지 않음';
        }
        if (!mb_check_encoding($s, 'UTF-8')) {
            return '경로는 UTF-8';
        }
    }
    return null;
}

/** 읽기 금지 여부: 업로드 폴더(사용자가 올린 파일 — 개인정보가 있을 수 있고 배포 대상도 아님) */
function rops_path_read_problem(string $logical): ?string
{
    foreach (ROPS_NO_WRITE_DIRS as $d) {
        if ($logical === $d || str_starts_with($logical, $d . '/')) {
            return '업로드 폴더(사용자 파일)는 원격으로 읽지 않음';
        }
    }
    return null;
}

/** 쓰기 금지 여부(형식 검사 통과한 경로 대상) */
function rops_path_write_problem(string $logical): ?string
{
    foreach (ROPS_PROTECTED_PATHS as $pp) {
        if ($logical === $pp || str_starts_with($logical, $pp . '/')) {
            return '관리 API 자체 파일은 원격으로 바꿀 수 없음(FileZilla 로 직접 관리)';
        }
    }
    foreach (ROPS_NO_WRITE_DIRS as $d) {
        if ($logical === $d || str_starts_with($logical, $d . '/')) {
            return '업로드 폴더는 배포 대상이 아님';
        }
    }
    $base = basename($logical);
    if ($base === '.htaccess') {
        return null;
    }
    $ext = strtolower(pathinfo($base, PATHINFO_EXTENSION));
    if ($ext === '' || !in_array($ext, ROPS_WRITE_EXT, true)) {
        return "허용하지 않는 확장자: .{$ext}";
    }
    if (count(explode('/', $logical)) < 2) {
        return '루트 폴더 자체는 쓸 수 없음';
    }
    return null;
}

/**
 * 저장소 기준 경로 → 서버 실제 경로. 경로 중간·끝의 심볼릭 링크와 루트 밖 경로를 거부한다.
 * @return array{logical:string,abs:string,root:string,exists:bool,is_file:bool,is_dir:bool}
 */
function rops_resolve(string $logical): array
{
    if (($why = rops_path_problem($logical) ?? rops_path_read_problem($logical)) !== null) {
        throw new RopsError('bad_path', "{$why}: {$logical}");
    }
    $segs = explode('/', $logical);
    $rootKey = array_shift($segs);
    $root = rops_settings()['roots'][$rootKey] ?? null;
    if ($root === null) {
        throw new RopsError('no_root', "서버에 {$rootKey} 폴더가 없습니다");
    }
    $cur = $root;
    foreach ($segs as $s) {
        $cur .= '/' . $s;
        if (is_link($cur)) {
            throw new RopsError('symlink', "심볼릭 링크는 다루지 않음: {$logical}");
        }
        if (!file_exists($cur)) {
            break;
        }
    }
    $abs = $segs ? $root . '/' . implode('/', $segs) : $root;
    clearstatcache(true, $abs);
    $exists = file_exists($abs);
    if ($exists) {
        $real = realpath($abs);
        if ($real === false || ($real !== $root && !str_starts_with($real, $root . '/'))) {
            throw new RopsError('outside_root', "허용 경로 밖: {$logical}");
        }
    }
    return ['logical' => $logical, 'abs' => $abs, 'root' => $root, 'exists' => $exists, 'is_file' => $exists && is_file($abs), 'is_dir' => $exists && is_dir($abs)];
}

/** 파일시스템 함수 실행 — 경고를 잡아 오류로 바꾼다(오류 숨김 없이 원인 보고) */
function rops_fs(callable $fn, string $what): mixed
{
    $err = null;
    set_error_handler(static function (int $no, string $msg) use (&$err): bool { $err = $msg; return true; });
    try {
        $r = $fn();
    } finally {
        restore_error_handler();
    }
    if ($r === false) {
        throw new RopsError('fs_error', "{$what} 실패" . ($err ? ': ' . preg_replace('#/\S+#', '…', $err) : ''));
    }
    return $r;
}

function rops_eol(string $c): string
{
    $crlf = substr_count($c, "\r\n");
    $lf = substr_count($c, "\n") - $crlf;
    return $crlf && $lf ? 'mixed' : ($crlf ? 'crlf' : ($lf ? 'lf' : 'none'));
}

/** 파일 정보(+해시) */
function rops_file_info(string $abs, string $logical, bool $hash = true): array
{
    $st = stat($abs) ?: [];
    $info = ['path' => $logical, 'type' => is_dir($abs) ? 'dir' : 'file', 'size' => (int) ($st['size'] ?? 0),
        'mtime' => isset($st['mtime']) ? date('c', (int) $st['mtime']) : null, 'perms' => isset($st['mode']) ? substr(sprintf('%o', $st['mode']), -4) : null,
        'writable' => is_writable($abs)];
    if ($hash && $info['type'] === 'file' && $info['size'] <= 16 * 1048576 && is_readable($abs)) {
        $c = (string) file_get_contents($abs);
        $info['sha256'] = hash('sha256', $c);
        $info['sha256_lf'] = hash('sha256', str_replace("\r\n", "\n", $c));
        $info['eol'] = rops_eol($c);
    }
    return $info;
}

/** PHP 문법 검사(서버 PHP 파서). 오류면 메시지, 정상이면 null */
function rops_php_parse_problem(string $code): ?string
{
    try {
        token_get_all($code, TOKEN_PARSE);
        return null;
    } catch (\CompileError $e) {
        return $e->getMessage() . ' (줄 ' . $e->getLine() . ')';
    }
}

function rops_is_php(string $logical): bool
{
    return strtolower(pathinfo($logical, PATHINFO_EXTENSION)) === 'php';
}

// ─────────────────────────────── 조회 ───────────────────────────────

function rops_files_list(array $p): array
{
    $path = (string) ($p['path'] ?? 'public');
    $recursive = !empty($p['recursive']);
    $hash = !empty($p['hashes']);
    $r = rops_resolve($path);
    if (!$r['is_dir']) {
        throw new RopsError('not_dir', "폴더가 아닙니다: {$path}");
    }
    $out = [];
    $walk = static function (string $abs, string $logical) use (&$walk, &$out, $recursive, $hash): void {
        $names = rops_fs(static fn() => scandir($abs), '폴더 읽기');
        foreach ($names as $n) {
            if ($n === '.' || $n === '..' || ($n[0] === '.' && $n !== '.htaccess')) { continue; }
            if (count($out) >= ROPS_LIST_MAX) { return; }
            $a = $abs . '/' . $n;
            $l = $logical . '/' . $n;
            if (rops_path_read_problem($l) !== null) { $out[] = ['path' => $l, 'type' => 'excluded']; continue; }
            if (is_link($a)) { $out[] = ['path' => $l, 'type' => 'link']; continue; }
            $info = rops_file_info($a, $l, $hash);
            $info['write_protected'] = rops_path_write_problem($l) !== null && $info['type'] === 'file';
            $out[] = $info;
            if ($recursive && $info['type'] === 'dir') { $walk($a, $l); }
        }
    };
    $walk($r['abs'], $path);
    return ['result' => ['path' => $path, 'entries' => $out, 'count' => count($out), 'truncated' => count($out) >= ROPS_LIST_MAX],
        'log' => ['target' => $path, 'affected_rows' => count($out)]];
}

function rops_files_read(array $p): array
{
    $path = (string) ($p['path'] ?? '');
    $r = rops_resolve($path);
    if (!$r['is_file']) {
        throw new RopsError('not_found', "파일이 없습니다: {$path}");
    }
    $max = rops_settings()['max_file_bytes'];
    if (filesize($r['abs']) > $max) {
        throw new RopsError('too_large', "파일이 너무 큽니다(최대 {$max}B)");
    }
    $c = (string) rops_fs(static fn() => file_get_contents($r['abs']), '파일 읽기');
    $info = rops_file_info($r['abs'], $path, true);
    return ['result' => $info + ['content_b64' => base64_encode($c)], 'log' => ['target' => $path]];
}

function rops_files_hash(array $p): array
{
    $paths = $p['paths'] ?? [];
    if (!is_array($paths) || $paths === [] || count($paths) > 500) {
        throw new RopsError('bad_params', 'paths: 1~500개');
    }
    $out = [];
    foreach ($paths as $path) {
        $path = (string) $path;
        try {
            $r = rops_resolve($path);
            $out[] = $r['is_file'] ? rops_file_info($r['abs'], $path, true) + ['exists' => true] : ['path' => $path, 'exists' => $r['exists'], 'type' => $r['is_dir'] ? 'dir' : null];
        } catch (RopsError $e) {
            $out[] = ['path' => $path, 'exists' => null, 'error' => $e->getMessage()];
        }
    }
    return ['result' => ['files' => $out], 'log' => ['target' => count($out) . ' files', 'affected_rows' => count($out)]];
}

function rops_files_manifest(array $p): array
{
    $roots = $p['roots'] ?? ['public', 'app', 'database'];
    if (!is_array($roots)) {
        throw new RopsError('bad_params', 'roots 는 목록');
    }
    $out = [];
    $walk = static function (string $abs, string $logical) use (&$walk, &$out): void {
        foreach (rops_fs(static fn() => scandir($abs), '폴더 읽기') as $n) {
            if ($n === '.' || $n === '..' || ($n[0] === '.' && $n !== '.htaccess')) { continue; }
            if (count($out) >= ROPS_MANIFEST_MAX) { return; }
            $a = $abs . '/' . $n;
            $l = $logical . '/' . $n;
            if (is_link($a) || rops_path_read_problem($l) !== null) { continue; }
            if (is_dir($a)) { $walk($a, $l); continue; }
            $i = rops_file_info($a, $l, true);
            $out[] = ['path' => $l, 'size' => $i['size'], 'mtime' => $i['mtime'], 'sha256' => $i['sha256'] ?? null, 'sha256_lf' => $i['sha256_lf'] ?? null];
        }
    };
    foreach ($roots as $root) {
        $r = rops_resolve((string) $root);
        if ($r['is_dir']) { $walk($r['abs'], (string) $root); }
    }
    return ['result' => ['files' => $out, 'count' => count($out), 'truncated' => count($out) >= ROPS_MANIFEST_MAX],
        'log' => ['target' => implode(',', array_map('strval', $roots)), 'affected_rows' => count($out)]];
}

function rops_files_lint(array $p): array
{
    $paths = $p['paths'] ?? [];
    if (!is_array($paths) || $paths === [] || count($paths) > 500) {
        throw new RopsError('bad_params', 'paths: 1~500개');
    }
    $out = [];
    $allOk = true;
    foreach ($paths as $path) {
        $path = (string) $path;
        $r = rops_resolve($path);
        if (!$r['is_file']) { $out[] = ['path' => $path, 'ok' => false, 'error' => '파일 없음']; $allOk = false; continue; }
        if (!rops_is_php($path)) { $out[] = ['path' => $path, 'ok' => true, 'skipped' => 'PHP 아님']; continue; }
        $why = rops_php_parse_problem((string) file_get_contents($r['abs']));
        $allOk = $allOk && $why === null;
        $out[] = ['path' => $path, 'ok' => $why === null, 'error' => $why];
    }
    return ['result' => ['ok' => $allOk, 'php_version' => PHP_VERSION, 'files' => $out,
        'note' => '서버 PHP 파서로 문법만 검사합니다(함수 중복 정의 같은 실행 시 오류는 HTTP 확인으로).'], 'log' => ['target' => count($out) . ' files']];
}

/** 파일 백업(스냅샷) 내용 보기 — 파일 종류만(테이블 덤프는 대량 추출이라 제외) */
function rops_files_snapshot(PDO $pdo, array $p): array
{
    $id = (int) ($p['id'] ?? 0);
    $st = $pdo->prepare("SELECT id, log_id, grant_id, kind, ref, sha256, size_bytes, encoding, body, created_at FROM remote_ops_snapshots
        WHERE id = ? AND kind IN ('file_before','file_after','file_staged')");
    $st->execute([$id]);
    $r = $st->fetch();
    if (!$r) {
        throw new RopsError('snapshot_not_found', "파일 백업을 찾을 수 없음: {$id}");
    }
    $c = rops_unpack($r['encoding'], $r['body']);
    return ['result' => ['id' => (int) $r['id'], 'log_id' => $r['log_id'], 'kind' => $r['kind'], 'path' => $r['ref'], 'sha256' => $r['sha256'],
        'size' => $r['size_bytes'], 'absent' => $r['encoding'] === 'none', 'created_at' => $r['created_at'], 'content_b64' => $c !== null ? base64_encode($c) : null],
        'log' => ['target' => "snapshot:{$id}"]];
}

// ─────────────────────────────── 쓰기 ───────────────────────────────

/** 원자적 교체: 같은 폴더 임시 파일에 쓰고 rename. 권한 유지. opcache 무효화 */
function rops_atomic_write(string $abs, string $content, ?int $perms): void
{
    $dir = dirname($abs);
    if (!is_dir($dir)) {
        rops_fs(static fn() => mkdir($dir, 0755, true) || is_dir($dir), '폴더 만들기');
    }
    $tmp = $dir . '/.rops-tmp-' . bin2hex(random_bytes(6));
    try {
        $n = rops_fs(static fn() => file_put_contents($tmp, $content, LOCK_EX), '임시 파일 쓰기');
        if ($n !== strlen($content)) {
            throw new RopsError('fs_error', '임시 파일 쓰기가 중간에 끊겼습니다(용량 확인)');
        }
        rops_fs(static fn() => chmod($tmp, $perms ?? 0644), '권한 설정');
        rops_fs(static fn() => rename($tmp, $abs), '파일 교체');
    } finally {
        if (file_exists($tmp)) {
            unlink($tmp);
        }
    }
    clearstatcache(true, $abs);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($abs, true);
    }
}

function rops_delete_file(string $abs): void
{
    rops_fs(static fn() => unlink($abs), '파일 삭제');
    clearstatcache(true, $abs);
    if (function_exists('opcache_invalidate')) {
        opcache_invalidate($abs, true);
    }
}

function rops_current_hash(array $r): ?string
{
    return $r['is_file'] ? hash_file('sha256', $r['abs']) : null;
}

/** files.stage: 배포할 파일 내용을 서버에 맡기고 승인 요청 생성(아직 파일은 바뀌지 않음) */
function rops_files_stage(PDO $pdo, array $p): array
{
    $files = $p['files'] ?? [];
    $deletes = $p['delete'] ?? [];
    if (!is_array($files) || !is_array($deletes) || ($files === [] && $deletes === []) || count($files) + count($deletes) > 100) {
        throw new RopsError('bad_params', 'files·delete 합계 1~100개');
    }
    $max = rops_settings()['max_file_bytes'];
    $scopeFiles = [];
    $contents = [];
    $seen = [];
    foreach ($files as $i => $f) {
        $path = (string) ($f['path'] ?? '');
        if (isset($seen[$path])) { throw new RopsError('bad_params', "같은 경로가 두 번: {$path}"); }
        $seen[$path] = true;
        if (($why = rops_path_write_problem($path)) !== null || ($why = rops_path_problem($path)) !== null) {
            throw new RopsError('path_denied', "{$why}: {$path}");
        }
        $r = rops_resolve($path);
        if ($r['is_dir']) { throw new RopsError('bad_path', "폴더입니다: {$path}"); }
        $c = base64_decode((string) ($f['content_b64'] ?? ''), true);
        if ($c === false) { throw new RopsError('bad_params', "files[{$i}].content_b64 가 base64 가 아님"); }
        if (strlen($c) > $max) { throw new RopsError('too_large', "{$path}: 최대 {$max}B"); }
        $sha = hash('sha256', $c);
        if (isset($f['sha256']) && !hash_equals((string) $f['sha256'], $sha)) {
            throw new RopsError('hash_mismatch', "{$path}: 전송 중 내용이 바뀌었습니다(sha256 불일치)");
        }
        $lint = rops_is_php($path) ? rops_php_parse_problem($c) : null;
        if ($lint !== null) {
            throw new RopsError('php_syntax', "{$path}: 서버 PHP " . PHP_VERSION . " 문법 오류 — {$lint}");
        }
        $base = rops_current_hash($r);
        $scopeFiles[] = ['path' => $path, 'sha256' => $sha, 'size' => strlen($c), 'base_sha256' => $base, 'new_file' => $base === null, 'unchanged' => $base === $sha];
        $contents[$path] = $c;
    }
    $scopeDel = [];
    foreach ($deletes as $path) {
        $path = (string) $path;
        if (isset($seen[$path])) { throw new RopsError('bad_params', "같은 경로가 두 번: {$path}"); }
        $seen[$path] = true;
        if (($why = rops_path_write_problem($path)) !== null || ($why = rops_path_problem($path)) !== null) {
            throw new RopsError('path_denied', "{$why}: {$path}");
        }
        $r = rops_resolve($path);
        if (!$r['is_file']) { throw new RopsError('not_found', "삭제할 파일이 서버에 없습니다: {$path}"); }
        $scopeDel[] = ['path' => $path, 'base_sha256' => rops_current_hash($r)];
    }
    $pdo->beginTransaction();
    try {
        $g = rops_grant_create($pdo, ['kind' => 'files', 'mode' => 'staged', 'title' => (string) ($p['title'] ?? ''), 'detail' => $p['detail'] ?? null,
            'scope' => ['files' => $scopeFiles, 'delete' => $scopeDel], 'ttl_minutes' => (int) ($p['ttl_minutes'] ?? 60), 'max_ops' => 1,
            'preview' => ['php_version' => PHP_VERSION, 'files' => count($scopeFiles), 'delete' => count($scopeDel)]]);
        foreach ($contents as $path => $c) {
            rops_snapshot_put($pdo, null, $g['id'], 'file_staged', $path, $c);
        }
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw rops_wrap_pdo($e);
    }
    return ['result' => ['grant' => rops_grant_public($g), 'approve_url' => rops_approve_url($g['code'])],
        'log' => ['grant_id' => $g['id'], 'target' => 'grant:' . $g['code'], 'affected_rows' => count($scopeFiles) + count($scopeDel)]];
}

/**
 * 파일 목록 적용(배포·복구 공통). $items: [{path, content|null(=삭제/없던 파일), expect_sha256|null}]
 * 모두 확인 → 전부 백업 → 차례로 교체 → 실패하면 이미 바꾼 파일을 원래대로 되돌린다.
 * @return list<array> 파일별 결과
 */
function rops_apply_files(PDO $pdo, int $logId, int $grantId, array $items, bool $force): array
{
    $plan = [];
    foreach ($items as $it) {
        $r = rops_resolve($it['path']);
        if ($r['is_dir']) { throw new RopsError('bad_path', "폴더입니다: {$it['path']}"); }
        $cur = rops_current_hash($r);
        if (!$force && $cur !== $it['expect_sha256']) {
            throw new RopsError('server_changed', "서버 파일이 승인(또는 배포) 이후 바뀌었습니다: {$it['path']} — 다시 비교 후 진행하세요",
                ['path' => $it['path'], 'current_sha256' => $cur, 'expected_sha256' => $it['expect_sha256']]);
        }
        if ($it['content'] !== null && rops_is_php($it['path']) && ($why = rops_php_parse_problem($it['content'])) !== null) {
            throw new RopsError('php_syntax', "{$it['path']}: PHP 문법 오류 — {$why}");
        }
        $plan[] = $r + ['content' => $it['content'], 'before' => $r['is_file'] ? (string) file_get_contents($r['abs']) : null,
            'perms' => $r['is_file'] ? (fileperms($r['abs']) & 0777) : null];
    }
    // 백업(변경 전 내용 또는 '없던 파일')
    foreach ($plan as $x) {
        rops_snapshot_put($pdo, $logId, $grantId, 'file_before', $x['logical'], $x['before'], $x['before'] === null);
    }
    $done = [];
    $results = [];
    try {
        foreach ($plan as $x) {
            if ($x['content'] === null) {
                if ($x['before'] !== null) { rops_delete_file($x['abs']); }
                $done[] = $x;
                $results[] = ['path' => $x['logical'], 'action' => $x['before'] !== null ? 'deleted' : 'absent', 'sha256' => null];
                continue;
            }
            rops_atomic_write($x['abs'], $x['content'], $x['perms']);
            $done[] = $x;
            $got = hash_file('sha256', $x['abs']);
            if ($got !== hash('sha256', $x['content'])) {
                throw new RopsError('verify_failed', "{$x['logical']}: 쓴 뒤 다시 읽은 내용이 다릅니다");
            }
            $lint = rops_is_php($x['logical']) ? rops_php_parse_problem((string) file_get_contents($x['abs'])) : null;
            $results[] = ['path' => $x['logical'], 'action' => $x['before'] === null ? 'created' : 'replaced', 'sha256' => $got,
                'before_sha256' => $x['before'] !== null ? hash('sha256', $x['before']) : null, 'php_lint' => rops_is_php($x['logical']) ? ($lint ?? 'ok') : null];
        }
    } catch (\Throwable $e) {
        // 이미 바꾼 파일 되돌리기
        $rb = [];
        foreach (array_reverse($done) as $x) {
            try {
                if ($x['before'] === null) {
                    if (file_exists($x['abs'])) { rops_delete_file($x['abs']); }
                } else {
                    rops_atomic_write($x['abs'], $x['before'], $x['perms']);
                }
                $rb[] = ['path' => $x['logical'], 'restored' => true];
            } catch (\Throwable $e2) {
                $rb[] = ['path' => $x['logical'], 'restored' => false, 'error' => $e2->getMessage()];
            }
        }
        throw new RopsError('apply_failed', '파일 적용 중 실패 → 이미 바꾼 파일은 원래대로 되돌렸습니다: ' . $e->getMessage(), ['rolled_back' => $rb]);
    }
    foreach ($results as $res) {
        // 배포 후 상태는 해시만(내용은 승인 대기 파일·다음 백업에 있음). 삭제된 파일은 해시 없음
        rops_snapshot_put($pdo, $logId, $grantId, 'file_after', $res['path'], null, true, $res['sha256']);
    }
    return $results;
}

/** files.deploy: 승인된(files/staged) 파일 반영 */
function rops_files_deploy(PDO $pdo, array $ctx, array $p): array
{
    $code = (string) ($p['grant'] ?? '');
    $dry = !empty($p['dry_run']);
    $g0 = rops_grant_get($pdo, $code);
    if ($g0 === null || $g0['kind'] !== 'files' || $g0['mode'] !== 'staged') {
        throw new RopsError('grant_not_found', "파일 배포 승인을 찾을 수 없음: {$code}");
    }
    $staged = [];
    foreach (rops_snapshots($pdo, 'grant', $g0['id'], 'file_staged') as $s) {
        $staged[$s['ref']] = $s['content'];
    }
    $items = [];
    foreach ($g0['scope']['files'] ?? [] as $f) {
        $c = $staged[$f['path']] ?? null;
        if ($c === null || !hash_equals($f['sha256'], hash('sha256', $c))) {
            throw new RopsError('grant_corrupt', "승인된 파일 내용을 확인할 수 없습니다: {$f['path']}");
        }
        $items[] = ['path' => $f['path'], 'content' => $c, 'expect_sha256' => $f['base_sha256']];
    }
    foreach ($g0['scope']['delete'] ?? [] as $d) {
        $items[] = ['path' => $d['path'], 'content' => null, 'expect_sha256' => $d['base_sha256']];
    }
    if ($dry) {
        // 확인만: 승인 상태·서버 파일 변동·문법
        if (!$g0['is_live']) {
            throw new RopsError('grant_inactive', "사용할 수 없는 승인입니다(상태 {$g0['status']})");
        }
        $check = [];
        foreach ($items as $it) {
            $r = rops_resolve($it['path']);
            $cur = rops_current_hash($r);
            $check[] = ['path' => $it['path'], 'action' => $it['content'] === null ? 'delete' : ($cur === null ? 'create' : 'replace'),
                'server_unchanged_since_stage' => $cur === $it['expect_sha256']];
        }
        return ['result' => ['dry_run' => true, 'files' => $check], 'log' => ['grant_id' => $g0['id'], 'target' => 'grant:' . $code, 'status' => 'dry_run']];
    }
    // 승인 사용 확정(중복 배포 방지) → 적용
    $pdo->beginTransaction();
    try {
        $g = rops_grant_require($pdo, $code, 'files', 'staged');
        rops_grant_use($pdo, $g, count($items), true);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    $results = rops_apply_files($pdo, $ctx['log_id'], $g['id'], $items, false);
    return ['result' => ['dry_run' => false, 'files' => $results, 'operation' => $ctx['log_id'],
        'next' => '배포한 화면을 HTTP 로 확인하세요(tomok http …). 문제 시 tomok files restore --operation ' . $ctx['log_id']],
        'log' => ['grant_id' => $g['id'], 'target' => implode(',', array_column($results, 'path')), 'affected_rows' => count($results)]];
}

/** files.restore: 배포(또는 복구) 1건을 그 직전 상태로 되돌림 */
function rops_files_restore(PDO $pdo, array $ctx, array $p): array
{
    $opId = (int) ($p['operation'] ?? 0);
    $dry = !empty($p['dry_run']);
    $force = !empty($p['force']);
    $log = rops_log_get($pdo, $opId);
    if ($log === null || !in_array($log['op'], ['files.deploy', 'files.restore'], true) || $log['status'] !== 'ok') {
        throw new RopsError('not_revertible', '되돌릴 수 있는 파일 작업이 아닙니다(성공한 files.deploy/files.restore 만)');
    }
    if ($log['reverted_by'] !== null) {
        throw new RopsError('already_reverted', "이미 되돌린 작업입니다(작업 {$log['reverted_by']})");
    }
    $g0 = $log['grant_id'] !== null ? rops_grant_get_by_id($pdo, (int) $log['grant_id']) : null;
    if ($g0 === null) {
        throw new RopsError('grant_not_found', '원래 승인을 찾을 수 없습니다');
    }
    $before = [];
    foreach (rops_snapshots($pdo, 'log', $opId, 'file_before') as $s) { $before[$s['ref']] = $s; }
    $after = [];
    foreach (rops_snapshots($pdo, 'log', $opId, 'file_after', false) as $s) { $after[$s['ref']] = $s['sha256']; }
    $items = [];
    foreach ($before as $path => $s) {
        $items[] = ['path' => $path, 'content' => $s['absent'] ? null : $s['content'], 'expect_sha256' => $after[$path] ?? null];
    }
    $pdo->beginTransaction();
    try {
        $g = rops_grant_require($pdo, $g0['code'], 'files', null, true);
        $pdo->commit();
    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        throw $e;
    }
    if ($dry) {
        $check = [];
        foreach ($items as $it) {
            $r = rops_resolve($it['path']);
            $check[] = ['path' => $it['path'], 'restore_to' => $it['content'] === null ? 'absent' : hash('sha256', $it['content']),
                'server_unchanged_since_op' => rops_current_hash($r) === $it['expect_sha256']];
        }
        return ['result' => ['dry_run' => true, 'files' => $check], 'log' => ['grant_id' => $g['id'], 'target' => "op:{$opId}", 'revert_of' => $opId, 'status' => 'dry_run']];
    }
    $results = rops_apply_files($pdo, $ctx['log_id'], $g['id'], $items, $force);
    $pdo->prepare('UPDATE remote_ops_log SET reverted_by = ? WHERE id = ?')->execute([$ctx['log_id'], $opId]);
    return ['result' => ['dry_run' => false, 'restored_operation' => $opId, 'files' => $results, 'operation' => $ctx['log_id']],
        'log' => ['grant_id' => $g['id'], 'target' => implode(',', array_column($results, 'path')), 'affected_rows' => count($results), 'revert_of' => $opId]];
}
