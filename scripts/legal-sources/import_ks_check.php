<?php
declare(strict_types=1);
/**
 * ks-latest-check.csv(사용자가 e나라표준인증 상세 페이지에서 채운 값) → candidates.json 의 KS 후보에 반영.
 *
 * 책임: 표준번호별 최종 개정·확인일, 고시 상태, 적용 범위 요약, 확인일을 ks_detail 로 기록하고 판정 문구를 갱신.
 * 금지: 빈 칸 추정 채우기(입력 없는 행은 그대로), KS 본문 수치 기록, 자동 활성화. 폐지면 제외 후보로 표시만 한다.
 * 실행: php scripts/legal-sources/import_ks_check.php [csv] [candidates.json] [--dry-run]
 */
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__, 2);
$args = array_values(array_filter(array_slice($argv, 1), static fn($a) => $a !== '--dry-run'));
$dry = in_array('--dry-run', $argv, true);
$csvFile = $args[0] ?? "{$root}/docs/03-analysis/ai-sourcing/legal-sources/ks-latest-check.csv";
$candFile = $args[1] ?? "{$root}/docs/03-analysis/ai-sourcing/legal-sources/candidates.json";

$cands = json_decode((string) file_get_contents($candFile), true);
$fh = fopen($csvFile, 'r');
$head = fgetcsv($fh, null, ',', '"', '');
$done = $skip = $bad = 0;
while (($r = fgetcsv($fh, null, ',', '"', '')) !== false) {
    [$no, $ksNo, , , , $date, $status, $scope, $checked] = array_pad(array_map(static fn($v) => trim((string) $v), $r), 9, '');
    if ($date === '' && $status === '') {
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
                       'checked_at' => $checked ?: date('Y-m-d'), 'source' => 'e나라표준인증 표준 상세(사용자 확인)'];
    if ($status === '폐지') {
        $c['verdict_reason'] = "e나라표준인증: 폐지({$date}) — 제외 검토(사용자 결정)";
    } else {
        $c['verify_level'] = 'metadata_confirmed';
        $c['meta_confirmed'] = true;
        $c['verdict_21'] = '공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위)';
        $c['verified_by'] = '사용자(e나라표준인증 상세 페이지)';
        $c['verified_at'] = $checked ?: date('Y-m-d');
        $c['effective_or_revised_date'] = $date ? "최종 {$status} {$date}" : ($c['effective_or_revised_date'] ?? '');
        $c['verdict_reason'] = "표준명·최종 {$status}" . ($date ? " {$date}" : '') . ($scope ? " · 적용 범위: {$scope}" : '') . ' (본문 수치 미확인 — KS 원문은 유료)';
    }
    unset($c);
    $done++;
}
fclose($fh);
if (!$dry) {
    file_put_contents($candFile, json_encode($cands, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
}
echo "반영 {$done}건 · 빈 행 {$skip}건 · 형식 오류 {$bad}건" . ($dry ? ' (dry-run: 파일 변경 없음)' : '') . "\n";
exit($bad > 0 ? 1 : 0);
