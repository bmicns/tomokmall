<?php
declare(strict_types=1);
/**
 * 관리자 — Claude 원격 작업 승인 (/admin-remote-ops.php)
 *
 * 책임: Claude(원격 관리 API)가 요청한 작업 단위 승인을 관리자가 보고 승인·거절·회수, 실행 이력 확인
 *       - 파일 배포 요청: 서버가 직접 계산한 '현재 서버 파일 ↔ 배포할 파일' 차이(diff)
 *       - SQL 실행 요청: 실행될 SQL 본문(해시 고정) + 롤백 트랜잭션으로 미리 실행한 영향 행 수
 *       - DB 범위 요청: 테이블·작업·컬럼·대상 행·최대 행 수
 *       DDL·대량 삭제·CASCADE 삭제가 들어 있으면 추가 확인란을 체크해야 승인된다(별도 승인 절차).
 * 권한: 관리자(admin)만. 상태 변경은 POST + CSRF 토큰.
 * 금지: 승인 화면에서 작업 실행(실행은 Claude 가 승인 코드로), 키·비밀번호 표시
 */
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/auth.php';
require __DIR__ . '/../app/admin_layout.php';
require __DIR__ . '/../app/remote_ops/server.php';

require_admin();
rops_settings(__DIR__);
$me = current_user();
$pdo = db();
$csrf = $_SESSION['rops_csrf'] ??= bin2hex(random_bytes(32));

$installed = false;
if ($pdo) {
    try {
        $installed = (bool) $pdo->query("SHOW TABLES LIKE 'remote\\_ops\\_grants'")->fetchColumn();
    } catch (\Throwable $e) {
        error_log('[admin-remote-ops] 설치 확인 실패: ' . get_class($e));
    }
}

/** 파일 배포 요청 각 항목의 현재 서버 상태 + diff */
function rops_admin_file_rows(PDO $pdo, array $g): array
{
    $staged = [];
    foreach (rops_snapshots($pdo, 'grant', $g['id'], 'file_staged') as $s) {
        $staged[$s['ref']] = $s['content'];
    }
    $rows = [];
    foreach ($g['scope']['files'] ?? [] as $f) {
        $cur = null;
        $curHash = null;
        $err = null;
        try {
            $r = rops_resolve($f['path']);
            if ($r['is_file']) {
                $cur = (string) file_get_contents($r['abs']);
                $curHash = hash('sha256', $cur);
            }
        } catch (\Throwable $e) {
            $err = $e->getMessage();
        }
        $new = $staged[$f['path']] ?? '';
        $binary = str_contains($new, "\0") || ($cur !== null && str_contains($cur, "\0"));
        $rows[] = ['path' => $f['path'], 'action' => $f['new_file'] ? '새 파일' : ($f['unchanged'] ? '변경 없음' : '교체'), 'size' => $f['size'],
            'server_same' => $curHash === $f['base_sha256'], 'error' => $err, 'binary' => $binary,
            'diff' => $binary ? null : rops_line_diff($cur ?? '', $new)];
    }
    foreach ($g['scope']['delete'] ?? [] as $d) {
        $curHash = null;
        try {
            $r = rops_resolve($d['path']);
            $curHash = $r['is_file'] ? hash_file('sha256', $r['abs']) : null;
        } catch (\Throwable $e) {
        }
        $rows[] = ['path' => $d['path'], 'action' => '삭제', 'size' => null, 'server_same' => $curHash === $d['base_sha256'], 'error' => null, 'binary' => false, 'diff' => null];
    }
    return $rows;
}

// ── POST: 승인·거절·회수 ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['ok' => false, 'msg' => ''];
    $code = (string) ($_POST['code'] ?? '');
    try {
        if (!$installed) {
            throw new RopsError('not_installed', '관리 테이블이 없습니다.');
        }
        if (!hash_equals($csrf, (string) ($_POST['csrf'] ?? ''))) {
            throw new RopsError('csrf', '화면이 오래되었습니다. 새로고침 후 다시 시도하세요.');
        }
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'revoke_all') {
            $n = rops_grant_revoke_all($pdo, (int) $me['id']);
            $flash = ['ok' => true, 'msg' => "승인 대기·사용 중인 승인 {$n}건을 모두 회수했습니다."];
        } else {
            $g = rops_grant_get($pdo, $code);
            if ($g === null) {
                throw new RopsError('grant_not_found', '승인 요청을 찾을 수 없습니다.');
            }
            if ($action === 'approve') {
                if (!empty($g['scope']['needs_extra_confirm']) && empty($_POST['confirm_extra'])) {
                    throw new RopsError('confirm_required', 'DDL·대량 변경이 포함된 요청입니다. 확인란을 체크해야 승인됩니다.');
                }
                if ($g['kind'] === 'files') {
                    foreach (rops_admin_file_rows($pdo, $g) as $fr) {
                        if (!$fr['server_same']) {
                            throw new RopsError('server_changed', "요청 이후 서버 파일이 바뀌었습니다({$fr['path']}). 거절하고 Claude 에게 다시 비교를 요청하세요.");
                        }
                    }
                }
            }
            if (!in_array($action, ['approve', 'reject', 'revoke'], true)) {
                throw new RopsError('bad_params', '알 수 없는 요청');
            }
            $g = rops_grant_decide($pdo, $code, $action, (int) $me['id'], (string) ($_POST['note'] ?? ''));
            $label = ['approve' => '승인', 'reject' => '거절', 'revoke' => '회수'][$action];
            $flash = ['ok' => true, 'msg' => "{$g['code']} {$label} 완료" . ($action === 'approve' ? " — {$g['expires_at']}까지 유효" : '')];
        }
    } catch (RopsError $e) {
        $flash['msg'] = $e->getMessage();
    } catch (\Throwable $e) {
        error_log('[admin-remote-ops] ' . get_class($e) . ': ' . $e->getMessage());
        $flash['msg'] = '처리 중 오류가 발생했습니다. 잠시 후 다시 시도해 주세요.';
    }
    $_SESSION['rops_flash'] = $flash;
    redirect('/admin-remote-ops.php' . ($code !== '' ? '?grant=' . rawurlencode($code) : ''));
}

$flash = $_SESSION['rops_flash'] ?? null;
unset($_SESSION['rops_flash']);
$focus = (string) ($_GET['grant'] ?? '');

$pending = $active = $recent = $logs = [];
$grantCodes = [];
if ($installed) {
    $pending = rops_grant_list($pdo, 'pending', 50);
    $active = rops_grant_list($pdo, 'approved', 50);
    $recent = array_values(array_filter(rops_grant_list($pdo, null, 40), static fn($g) => !in_array($g['status'], ['pending', 'approved'], true)));
    $logs = rops_log_list($pdo, 40);
    foreach ($pdo->query('SELECT id, code FROM remote_ops_grants ORDER BY id DESC LIMIT 300')->fetchAll() as $r) {
        $grantCodes[(int) $r['id']] = $r['code'];
    }
}

$actionLabel = ['update' => '수정', 'insert' => '추가', 'delete' => '삭제'];
$statusLabel = ['pending' => '승인 대기', 'approved' => '승인됨', 'rejected' => '거절', 'revoked' => '회수', 'used' => '사용 완료', 'expired' => '만료', 'canceled' => '취소'];
$statusBadge = ['pending' => 'badge-pending', 'approved' => 'badge-on', 'used' => 'badge-done', 'rejected' => 'badge-off', 'revoked' => 'badge-off', 'expired' => 'badge-off', 'canceled' => 'badge-off'];

function rops_admin_json(mixed $v): string
{
    return (string) json_encode($v, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}

function rops_admin_diff_html(string $text): string
{
    $out = [];
    foreach (explode("\n", $text) as $l) {
        $c = $l === '' ? '' : ($l[0] === '+' ? 'add' : ($l[0] === '-' ? 'del' : ($l[0] === '@' ? 'hunk' : '')));
        $out[] = '<span class="rops-d-' . $c . '">' . e($l) . '</span>';
    }
    return implode("\n", $out);
}

function rops_admin_card(PDO $pdo, array $g, string $csrf, bool $focus, array $L): void
{
    [$actionLabel, $statusLabel, $statusBadge] = $L;
    $s = $g['scope'];
    $isPending = $g['status'] === 'pending';
    $isLive = $g['status'] === 'approved' && $g['is_live'];
    $kindLabel = $g['kind'] === 'db' ? '운영 DB 변경' : '운영 서버 파일 변경';
    $modeLabel = ['scope' => '범위 안 수정', 'sql' => 'SQL 파일 실행', 'staged' => '파일 배포'][$g['mode']] ?? $g['mode'];
    ?>
    <section class="card rops-card<?= $focus ? ' rops-focus' : '' ?>" id="<?= e($g['code']) ?>">
        <div class="card-header rops-card-head">
            <div>
                <span class="rops-code"><?= e($g['code']) ?></span>
                <span class="badge <?= $g['kind'] === 'db' ? 'rops-b-db' : 'rops-b-files' ?>"><?= e($kindLabel) ?></span>
                <span class="badge rops-b-mode"><?= e($modeLabel) ?></span>
                <span class="badge <?= e($statusBadge[$g['status']] ?? '') ?>"><?= e($statusLabel[$g['status']] ?? $g['status']) ?></span>
                <?php if (!empty($s['needs_extra_confirm'])): ?><span class="badge rops-b-warn">추가 확인 필요</span><?php endif; ?>
            </div>
            <div class="rops-meta">요청 <?= e($g['requested_at']) ?> · 승인 후 <?= (int) $g['ttl_minutes'] ?>분 유효 · 작업 <?= (int) $g['used_ops'] ?>/<?= (int) $g['max_ops'] ?>회
                <?php if ($g['expires_at']): ?> · 만료 <?= e($g['expires_at']) ?><?php endif; ?></div>
        </div>
        <div class="rops-body">
            <h3 class="rops-title"><?= e($g['title']) ?></h3>
            <?php if ($g['detail']): ?><div class="rops-detail"><?= nl2br(e((string) $g['detail'])) ?></div><?php endif; ?>

            <?php if ($g['kind'] === 'db' && $g['mode'] === 'scope'): ?>
                <dl class="rops-dl">
                    <dt>테이블</dt><dd><?= e(implode(', ', $s['tables'] ?? [])) ?></dd>
                    <dt>작업</dt><dd><?= e(implode(', ', array_map(static fn($a) => $actionLabel[$a] ?? $a, $s['actions'] ?? []))) ?></dd>
                    <dt>바꿀 수 있는 컬럼</dt><dd><?= $s['columns'] ? e(rops_admin_json($s['columns'])) : '제한 없음(기본키·인증정보 컬럼 제외)' ?></dd>
                    <dt>대상 행(PK)</dt><dd><?= $s['ids'] ? e(implode(' / ', array_map(static fn($t, $ids) => $t . ': ' . count((array) $ids) . '행', array_keys($s['ids']), $s['ids']))) : '제한 없음(조건으로 지정)' ?></dd>
                    <dt>최대 행 수</dt><dd>1회 <?= (int) ($s['max_rows_per_op'] ?? 0) ?>행 · 전체 <?= (int) ($s['max_rows_total'] ?? 0) ?>행 (사용 <?= (int) $g['used_rows'] ?>)</dd>
                    <?php if (!empty($s['allow_bulk_delete'])): ?><dt>대량 삭제</dt><dd class="rops-warn">허용 요청(<?= ROPS_BULK_DELETE_ROWS ?>행 초과 삭제)</dd><?php endif; ?>
                    <?php if (!empty($s['allow_cascade_delete'])): ?><dt>CASCADE 삭제</dt><dd class="rops-warn">허용 요청(연결된 행 함께 삭제 — 되돌리기 불가)</dd><?php endif; ?>
                </dl>
                <?php foreach ($g['preview']['tables'] ?? [] as $t): if (empty($t['rows'])) { continue; } ?>
                    <div class="rops-sub">대상 행 현재값 — <?= e($t['table']) ?> (찾음 <?= (int) $t['rows_found'] ?> · 없음 <?= (int) $t['rows_missing'] ?>)</div>
                    <div class="rops-scroll"><table class="adm-table"><thead><tr><?php foreach (array_keys($t['rows'][0]) as $c): ?><th><?= e((string) $c) ?></th><?php endforeach; ?></tr></thead><tbody>
                    <?php foreach ($t['rows'] as $r): ?><tr><?php foreach ($r as $v): ?><td><?= e($v === null ? 'NULL' : (string) $v) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
                    </tbody></table></div>
                <?php endforeach; ?>

            <?php elseif ($g['kind'] === 'db' && $g['mode'] === 'sql'):
                $pv = $g['preview'] ?? [];
                $lines = explode("\n", (string) $g['payload']);
                ?>
                <dl class="rops-dl">
                    <dt>문장 수</dt><dd><?= (int) ($s['statements'] ?? 0) ?>개 · <?= number_format((int) ($s['bytes'] ?? 0)) ?>바이트 · 테이블 <?= e(implode(', ', $s['tables'] ?? [])) ?></dd>
                    <dt>구조 변경(DDL)</dt><dd class="<?= !empty($s['has_ddl']) ? 'rops-warn' : '' ?>"><?= !empty($s['has_ddl']) ? '있음 — 미리 실행 불가, 실행 전 대상 테이블 자동 백업, 되돌리기는 백업 복원' : '없음(한 트랜잭션, 실패 시 전체 롤백)' ?></dd>
                    <dt>최대 변경 행</dt><dd><?= (int) ($s['max_rows_total'] ?? 0) ?>행(넘으면 롤백)</dd>
                    <dt>SHA-256</dt><dd class="rops-mono"><?= e((string) ($s['sql_sha256'] ?? '')) ?></dd>
                </dl>
                <?php if (!empty($pv['warnings'])): ?><ul class="rops-warnlist"><?php foreach ($pv['warnings'] as $w): ?><li><?= e((string) $w) ?></li><?php endforeach; ?></ul><?php endif; ?>
                <div class="rops-sub">실행할 SQL<?= count($lines) > 400 ? ' (앞 400줄 / 전체 ' . count($lines) . '줄)' : '' ?></div>
                <pre class="rops-pre"><?= e(implode("\n", array_slice($lines, 0, 400))) ?></pre>
                <?php if (!empty($pv['dry_run'])): ?>
                    <div class="rops-sub">미리 실행 결과(롤백됨) — 합계 <?= (int) $pv['dry_run']['total_rows'] ?>행</div>
                    <div class="rops-scroll"><table class="adm-table"><thead><tr><th>#</th><th>종류</th><th>테이블</th><th>영향 행</th></tr></thead><tbody>
                    <?php foreach ($pv['dry_run']['results'] as $r): ?><tr><td><?= (int) $r['no'] ?></td><td><?= e((string) $r['type']) ?></td><td><?= e(implode(', ', $r['tables'])) ?></td><td><?= (int) $r['rows'] ?></td></tr><?php endforeach; ?>
                    </tbody></table></div>
                    <?php if (!empty($pv['dry_run']['verify_before'])): ?>
                        <details class="rops-details"><summary>변경 전후 확인 조회(@verify)</summary>
                            <pre class="rops-pre"><?= e(mb_substr(rops_admin_json(['전' => $pv['dry_run']['verify_before'], '후' => $pv['dry_run']['verify_after']]), 0, 20000)) ?></pre></details>
                    <?php endif; ?>
                <?php elseif (!empty($pv['note'])): ?><p class="rops-warn"><?= e((string) $pv['note']) ?></p><?php endif; ?>

            <?php elseif ($g['kind'] === 'files'): ?>
                <div class="rops-sub">배포 파일 — 서버 PHP <?= e((string) ($g['preview']['php_version'] ?? '')) ?> 문법 검사 통과</div>
                <?php foreach (rops_admin_file_rows($pdo, $g) as $fr): ?>
                    <div class="rops-file">
                        <div class="rops-file-head">
                            <span class="rops-mono"><?= e($fr['path']) ?></span>
                            <span class="badge <?= $fr['action'] === '삭제' ? 'badge-off' : 'rops-b-mode' ?>"><?= e($fr['action']) ?></span>
                            <?php if ($fr['diff']): ?><span class="rops-plus">+<?= (int) $fr['diff']['added'] ?></span> <span class="rops-minus">−<?= (int) $fr['diff']['removed'] ?></span><?php endif; ?>
                            <?php if ($isPending || $isLive): ?>
                                <?php if ($fr['server_same']): ?><span class="rops-okc">요청 이후 서버 변동 없음</span><?php else: ?><span class="rops-warn">⚠ 요청 이후 서버 파일이 바뀜</span><?php endif; ?>
                            <?php endif; ?>
                        </div>
                        <?php if ($fr['error']): ?><div class="rops-warn"><?= e($fr['error']) ?></div><?php endif; ?>
                        <?php if ($fr['binary']): ?><div class="rops-meta">이진 파일(이미지 등) — 차이 표시 생략, <?= number_format((int) $fr['size']) ?>바이트</div>
                        <?php elseif ($fr['diff'] && ($fr['diff']['eol_only'] || $fr['diff']['identical'])): ?><div class="rops-meta"><?= $fr['diff']['identical'] ? '내용 같음' : '줄바꿈(CRLF/LF)만 다름' ?></div>
                        <?php elseif ($fr['diff']): ?>
                            <details class="rops-details"<?= $fr['diff']['added'] + $fr['diff']['removed'] <= 80 ? ' open' : '' ?>><summary>차이 보기<?= $fr['diff']['approx'] ? ' (변경이 커서 통째 교체로 표시)' : '' ?><?= $fr['diff']['truncated'] ? ' (일부만 표시)' : '' ?></summary>
                                <pre class="rops-pre rops-diff"><?= rops_admin_diff_html($fr['diff']['text']) ?></pre></details>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <?php if ($isPending): ?>
                <form method="post" class="rops-form">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="code" value="<?= e($g['code']) ?>">
                    <label class="rops-label" for="note-<?= e($g['code']) ?>">메모(선택)</label>
                    <input class="rops-input" id="note-<?= e($g['code']) ?>" name="note" maxlength="250" placeholder="예: 10개 가격만 확인함">
                    <?php if (!empty($s['needs_extra_confirm'])): ?>
                        <label class="rops-check"><input type="checkbox" name="confirm_extra" value="1">
                            <span>구조 변경(DDL)·대량 변경·CASCADE 삭제가 포함된 것을 확인했고, 백업·되돌리기 방법을 이해했습니다.</span></label>
                    <?php endif; ?>
                    <div class="rops-actions">
                        <button class="btn btn-primary rops-btn" type="submit" name="action" value="approve">승인 (<?= (int) $g['ttl_minutes'] ?>분 유효)</button>
                        <button class="btn btn-danger rops-btn" type="submit" name="action" value="reject">거절</button>
                    </div>
                </form>
            <?php elseif ($isLive): ?>
                <form method="post" class="rops-form">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                    <input type="hidden" name="code" value="<?= e($g['code']) ?>">
                    <div class="rops-actions"><button class="btn btn-danger rops-btn" type="submit" name="action" value="revoke">승인 회수(즉시 사용 중지)</button></div>
                </form>
            <?php elseif ($g['decided_note']): ?>
                <div class="rops-meta">메모: <?= e((string) $g['decided_note']) ?></div>
            <?php endif; ?>
        </div>
    </section>
    <?php
}

render_admin_header('Claude 원격 작업 승인', 'remote-ops');
?>
<style>
.rops-wrap{display:flex;flex-direction:column;gap:16px;max-width:1100px}
.rops-intro{font-size:13px;color:#555;line-height:1.6;margin-bottom:4px}
.rops-flash{padding:12px 16px;border-radius:8px;font-size:13px;font-weight:600}
.rops-flash.ok{background:#e8f5e9;color:#1b5e20}.rops-flash.err{background:#fce4ec;color:#b71c1c}
.rops-h2{font-size:15px;font-weight:800;margin:8px 0 0}
.rops-card-head{flex-wrap:wrap;gap:8px}
.rops-code{font-family:ui-monospace,Menlo,Consolas,monospace;font-weight:700;margin-right:6px}
.rops-meta{font-size:12px;color:#777}
.rops-body{padding:16px 20px;display:flex;flex-direction:column;gap:10px;font-size:13px}
.rops-title{font-size:15px;font-weight:700}
.rops-detail{color:#444;line-height:1.6;background:#f8f9fb;border-radius:6px;padding:10px 12px}
.rops-dl{display:grid;grid-template-columns:140px 1fr;gap:6px 12px}
.rops-dl dt{color:#666;font-weight:600}.rops-dl dd{word-break:break-all}
.rops-sub{font-weight:700;color:#333;margin-top:6px}
.rops-scroll{overflow-x:auto;border:1px solid #eee;border-radius:6px}
.rops-pre{background:#0f172a;color:#e2e8f0;border-radius:6px;padding:12px;overflow:auto;max-height:480px;font-size:12px;line-height:1.5;white-space:pre}
.rops-diff .rops-d-add{color:#86efac}.rops-diff .rops-d-del{color:#fca5a5}.rops-diff .rops-d-hunk{color:#93c5fd}
.rops-mono{font-family:ui-monospace,Menlo,Consolas,monospace;word-break:break-all}
.rops-file{border:1px solid #eee;border-radius:8px;padding:10px 12px;display:flex;flex-direction:column;gap:6px}
.rops-file-head{display:flex;flex-wrap:wrap;gap:8px;align-items:center}
.rops-plus{color:#2e7d32;font-weight:700}.rops-minus{color:#c62828;font-weight:700}
.rops-okc{color:#2e7d32;font-size:12px}.rops-warn{color:#c62828;font-weight:600}
.rops-warnlist{color:#c62828;padding-left:18px}
.rops-details summary{cursor:pointer;color:#004EA1;font-weight:600;padding:6px 0;min-height:32px}
.rops-form{display:flex;flex-direction:column;gap:8px;border-top:1px solid #eee;padding-top:12px}
.rops-label{font-size:12px;font-weight:600;color:#666}
.rops-input{height:44px;border:1px solid #e8eaed;border-radius:6px;padding:0 12px;font-size:14px;width:100%}
.rops-check{display:flex;gap:10px;align-items:flex-start;background:#fff3e0;border-radius:6px;padding:10px 12px;cursor:pointer;line-height:1.5}
.rops-check input{width:22px;height:22px;flex-shrink:0;margin-top:1px}
.rops-actions{display:flex;gap:10px;flex-wrap:wrap}
.rops-btn{min-height:44px}
.rops-b-db{background:#e3f2fd;color:#0d47a1}.rops-b-files{background:#f3e5f5;color:#6a1b9a}.rops-b-mode{background:#eceff1;color:#37474f}.rops-b-warn{background:#ffebee;color:#c62828}
.rops-focus{outline:3px solid #004EA1;outline-offset:2px}
.rops-empty{padding:16px 20px;color:#888;font-size:13px}
/* 이 화면만: 휴대폰에서 승인하기 쉽게 왼쪽 메뉴(220px 고정)를 위쪽 가로 메뉴로(공통 admin_layout.php 는 바꾸지 않음) */
@media (max-width:800px){
  .adm-layout{flex-direction:column}
  .adm-sidebar{width:100%;height:auto;position:static;flex-direction:row;align-items:center}
  .adm-logo{padding:10px 16px;border-bottom:none;flex-shrink:0}
  .adm-nav{display:flex;padding:0;overflow-x:auto}
  .adm-nav-section,.adm-sidebar-foot{display:none}
  .adm-nav a{white-space:nowrap;padding:0 12px;min-height:44px;border-left:none;border-bottom:3px solid transparent}
  .adm-nav a.active{border-bottom-color:#004EA1}
  .adm-topbar{padding:0 16px}
  .adm-content{padding:16px}
}
@media (max-width:640px){
  .rops-dl{grid-template-columns:1fr}
  .rops-body{padding:12px}
  .rops-actions .rops-btn{flex:1 1 100%}
}
</style>
<div class="rops-wrap">
    <div class="page-heading" style="margin:0">Claude 원격 작업 승인</div>
    <p class="rops-intro">Claude 가 운영 DB·서버 파일을 바꾸려면 여기서 작업 단위로 승인해야 합니다. 승인한 범위·내용·유효시간 안에서만 실행되고, 모든 실행은 아래 이력에 남습니다.
        DB 변경과 파일 변경은 서로 다른 승인입니다. 조회(SELECT·파일 목록)는 승인 없이 가능합니다.</p>
    <?php if ($flash): ?><div class="rops-flash <?= $flash['ok'] ? 'ok' : 'err' ?>" role="status"><?= e((string) $flash['msg']) ?></div><?php endif; ?>

    <?php if (!$pdo): ?>
        <div class="rops-flash err">DB 에 연결할 수 없습니다.</div>
    <?php elseif (!$installed): ?>
        <div class="rops-flash err">관리 테이블이 아직 없습니다. database/20261103_remote_ops.sql 을 phpMyAdmin 에서 실행한 뒤 다시 열어 주세요.</div>
    <?php else: ?>
        <div class="rops-h2">승인 대기 (<?= count($pending) ?>)</div>
        <?php if (!$pending): ?><div class="card rops-empty">승인 대기 중인 요청이 없습니다.</div><?php endif; ?>
        <?php foreach ($pending as $g) { rops_admin_card($pdo, $g, $csrf, $g['code'] === $focus, [$actionLabel, $statusLabel, $statusBadge]); } ?>

        <div class="rops-h2">사용 중인 승인 (<?= count($active) ?>)</div>
        <?php if (!$active): ?><div class="card rops-empty">사용 중인 승인이 없습니다.</div><?php else: ?>
            <form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                <button class="btn btn-danger rops-btn" type="submit" name="action" value="revoke_all" onclick="return confirm('사용 중·대기 중인 승인을 모두 회수할까요?')">전체 회수(모든 원격 쓰기 즉시 중지)</button></form>
        <?php endif; ?>
        <?php foreach ($active as $g) { rops_admin_card($pdo, $g, $csrf, $g['code'] === $focus, [$actionLabel, $statusLabel, $statusBadge]); } ?>

        <div class="rops-h2">최근 실행 이력</div>
        <div class="card"><div class="rops-scroll"><table class="adm-table">
            <thead><tr><th>#</th><th>시각</th><th>작업</th><th>결과</th><th>승인</th><th>대상</th><th>행</th><th>ms</th><th>오류</th></tr></thead><tbody>
            <?php foreach ($logs as $l): ?>
                <tr>
                    <td><?= (int) $l['id'] ?></td><td style="white-space:nowrap"><?= e((string) $l['created_at']) ?></td><td><?= e((string) $l['op']) ?></td>
                    <td><span class="badge <?= ['ok' => 'badge-on', 'dry_run' => 'badge-pending', 'running' => 'badge-pending'][$l['status']] ?? 'badge-off' ?>"><?= e((string) $l['status']) ?></span><?= $l['reverted_by'] ? ' <span class="rops-meta">되돌림 #' . (int) $l['reverted_by'] . '</span>' : '' ?></td>
                    <td><?= $l['grant_id'] ? e($grantCodes[(int) $l['grant_id']] ?? ('#' . $l['grant_id'])) : '' ?></td>
                    <td class="rops-mono"><?= e((string) $l['target']) ?></td><td><?= $l['affected_rows'] !== null ? (int) $l['affected_rows'] : '' ?></td>
                    <td><?= (int) $l['duration_ms'] ?></td><td class="rops-warn" style="font-weight:400"><?= e((string) $l['error_message']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$logs): ?><tr><td colspan="9" class="rops-empty">이력이 없습니다.</td></tr><?php endif; ?>
            </tbody></table></div></div>

        <div class="rops-h2">지난 승인 요청</div>
        <?php $focusRecent = null; foreach ($recent as $g) { if ($g['code'] === $focus) { $focusRecent = $g; } } ?>
        <?php if ($focusRecent) { rops_admin_card($pdo, $focusRecent, $csrf, true, [$actionLabel, $statusLabel, $statusBadge]); } ?>
        <div class="card"><div class="rops-scroll"><table class="adm-table">
            <thead><tr><th>코드</th><th>종류</th><th>제목</th><th>상태</th><th>결정</th><th>만료</th><th>사용</th><th>메모</th></tr></thead><tbody>
            <?php foreach ($recent as $g): ?>
                <tr>
                    <td class="rops-mono"><a href="/admin-remote-ops.php?grant=<?= e(rawurlencode($g['code'])) ?>#<?= e($g['code']) ?>"><?= e($g['code']) ?></a></td>
                    <td><?= e($g['kind'] . '/' . $g['mode']) ?></td><td><?= e($g['title']) ?></td>
                    <td><span class="badge <?= e($statusBadge[$g['status']] ?? '') ?>"><?= e($statusLabel[$g['status']] ?? $g['status']) ?></span></td>
                    <td style="white-space:nowrap"><?= e((string) $g['decided_at']) ?></td><td style="white-space:nowrap"><?= e((string) $g['expires_at']) ?></td>
                    <td><?= (int) $g['used_ops'] ?>/<?= (int) $g['max_ops'] ?>회 · <?= (int) $g['used_rows'] ?>행</td><td><?= e((string) $g['decided_note']) ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!$recent): ?><tr><td colspan="8" class="rops-empty">없습니다.</td></tr><?php endif; ?>
            </tbody></table></div></div>
    <?php endif; ?>
</div>
<?php render_admin_footer(); ?>
