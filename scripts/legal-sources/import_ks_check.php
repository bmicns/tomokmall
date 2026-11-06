<?php
declare(strict_types=1);
/**
 * ks-latest-check.csv(사용자가 e나라표준인증 상세 페이지에서 채운 값) → candidates.json 의 KS 후보에 반영.
 *
 * 책임: 표준번호별 최종 개정·확인일, 고시 상태, 적용 범위 요약, 확인일을 ks_detail 로 기록하고 판정 문구를 갱신.
 * 금지: 빈 칸 추정 채우기(입력 없는 행은 그대로), KS 본문 수치 기록, 자동 활성화. 폐지면 제외 후보로 표시만 한다.
 * 실행: php scripts/legal-sources/import_ks_check.php [csv] [candidates.json] [--dry-run] [--only='KS F 4001,KS F 4005'] [--by=claude]
 *   --only : 이 표준번호 행만 반영(이미 반영한 행의 손으로 고친 값 — 예: KS D 4040 KSSN 출처 — 을 다시 덮어쓰지 않게)
 *   --by=claude : 확인자를 'Claude(e나라표준인증 상세 페이지 직접 열람)' 로, 적용 범위 표시를 '공식 상세 확인' 으로(기본: 사용자 — '사용자 확인')
 * 적용 범위가 있고 scope_note 에 확인 표시가 아직 없으면 scope_note 를 '<요약> (e나라표준인증 적용 범위, <표시> <확인일>)' 로 바꾼다
 * (app/ai_tools.php ai_ks_confirmed_scope 가 이 표시가 있는 적용 범위만 상담에 쓴다).
 */
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__, 2);
$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== '--dry-run' && !str_starts_with($a, '--only=') && !str_starts_with($a, '--by=')));
$dry = in_array('--dry-run', $argv, true);
$opt = getopt('', ['only:', 'by:', 'dry-run']);
$only = isset($opt['only']) ? array_map('trim', explode(',', (string) $opt['only'])) : null;
$byClaude = ($opt['by'] ?? '') === 'claude';
$mark = $byClaude ? '공식 상세 확인' : '사용자 확인';
$source = $byClaude ? 'e나라표준인증 표준 상세(Claude 직접 열람)' : 'e나라표준인증 표준 상세(사용자 확인)';
$who = $byClaude ? 'Claude(e나라표준인증 상세 페이지 직접 열람)' : '사용자(e나라표준인증 상세 페이지)';
$csvFile = $args[0] ?? "{$root}/docs/03-analysis/ai-sourcing/legal-sources/ks-latest-check.csv";
$candFile = $args[1] ?? "{$root}/docs/03-analysis/ai-sourcing/legal-sources/candidates.json";

$cands = json_decode((string) file_get_contents($candFile), true);
$fh = fopen($csvFile, 'r');
$head = fgetcsv($fh, null, ',', '"', '');
$done = $skip = $bad = 0;
while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
    [$no, $ksNo, , , , $date, $status, $scope, $checked] = array_pad(array_map(static fn($v) => trim((string) $v), $r), 9, '');
    if ($date === '' && $status === '' || $only !== null && !in_array($ksNo, $only, true)) {
        $skip++;
        continue;
    }
    $i = (int) $no - 1;
    if (!isset($cands[$i]) || ($cands[$i]['ref_no'] ?? '') !== $ksNo
        || ($date !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date))
        || ($status !== '' && !in_array($status, ['제정', '개정', '확인', '폐지'], true))) {
        fwrite(STDERR, "형식 오류(건너뜀): {$no} {$ksNo}\n");
        $bad++;
        continue;
    }
    $c = &$cands[$i];
    $c['ks_detail'] = ['revised_or_confirmed' => $date ?: null, 'status' => $status ?: null, 'scope' => $scope ?: null,
                       'checked_at' => $checked ?: date('Y-m-d'), 'source' => $source];
    if ($status === '폐지') {
        $c['verdict_reason'] = "e나라표준인증: 폐지({$date}) — 제외 검토(사용자 결정)";
    } else {
        $c['verify_level'] = 'metadata_confirmed';
        $c['meta_confirmed'] = true;
        $c['verdict_21'] = '공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위)';
        $c['verified_by'] = $who;
        if ($scope !== '' && !preg_match('/(사용자 확인|공식 상세 확인)/u', (string) ($c['scope_note'] ?? ''))) {
            $c['scope_note'] = "{$scope} (e나라표준인증 적용 범위, {$mark} " . ($checked ?: date('Y-m-d')) . ')';
        }
        $c['verified_at'] = $checked ?: date('Y-m-d');
        $c['effective_or_revised_date'] = $date ? "최종 {$status} {$date}" : ($c['effective_or_revised_date'] ?? '');
        $c['verdict_reason'] = "표준명·최종 {$status}" . ($date ? " {$date}" : '') . ($scope ? " · 적용 범위: {$scope}" : '') . ' (본문 수치 미확인 — KS 원문은 유료)';
    }
    unset($c);
    $done++;
}
fclose($fh);
if (!$dry) {
    $json = json_encode($cands, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    $json = (string) preg_replace_callback('/^( +)/m', static fn($m) => str_repeat(' ', intdiv(strlen($m[1]), 2)), $json);   // 파일 형식(들여쓰기 2칸) 유지 — 바뀐 항목만 diff 에 나오게
    file_put_contents($candFile, $json . "\n");
}
echo "반영 {$done}건 · 빈 행 {$skip}건 · 형식 오류 {$bad}건" . ($dry ? ' (dry-run: 파일 변경 없음)' : '') . "\n";
exit($bad > 0 ? 1 : 0);
