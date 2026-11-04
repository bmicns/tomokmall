<?php
declare(strict_types=1);
/**
 * 원격 관리 API — 줄 단위 비교(diff) (app/remote_ops/diff.php)
 *
 * 책임: 승인 화면에서 '서버 현재 파일 ↔ 승인 대기 파일'의 실제 차이를 서버가 직접 계산해 보여 주기(통합 diff 형식)
 * 방식: 앞뒤 공통 줄을 잘라 낸 뒤 가운데만 LCS(최장 공통 부분열). 가운데가 너무 크면 '통째로 교체'로 표시(정확하지만 최소가 아님)
 * 금지: 파일·DB 접근(순수 함수)
 */

/**
 * @return array{text:string,added:int,removed:int,eol_only:bool,identical:bool,approx:bool,truncated:bool}
 */
function rops_line_diff(string $old, string $new, int $context = 3, int $maxCells = 250000, int $maxOutLines = 1500): array
{
    $res = ['text' => '', 'added' => 0, 'removed' => 0, 'eol_only' => false, 'identical' => $old === $new, 'approx' => false, 'truncated' => false];
    if ($res['identical']) {
        return $res;
    }
    $o = str_replace("\r\n", "\n", $old);
    $n = str_replace("\r\n", "\n", $new);
    if ($o === $n) {
        $res['eol_only'] = true;
        return $res;
    }
    $a = $o === '' ? [] : explode("\n", $o);
    $b = $n === '' ? [] : explode("\n", $n);
    $na = count($a);
    $nb = count($b);
    $pre = 0;
    while ($pre < $na && $pre < $nb && $a[$pre] === $b[$pre]) { $pre++; }
    $suf = 0;
    while ($suf < $na - $pre && $suf < $nb - $pre && $a[$na - 1 - $suf] === $b[$nb - 1 - $suf]) { $suf++; }
    $ma = array_slice($a, $pre, $na - $pre - $suf);
    $mb = array_slice($b, $pre, $nb - $pre - $suf);
    $x = count($ma);
    $y = count($mb);

    // ops: [' '|'-'|'+', 줄]
    $ops = [];
    for ($i = 0; $i < $pre; $i++) { $ops[] = [' ', $a[$i]]; }
    if ($x > 0 && $y > 0 && ($x + 1) * ($y + 1) <= $maxCells) {
        $w = $y + 1;
        $L = new \SplFixedArray(($x + 1) * $w);
        for ($i = $x - 1; $i >= 0; $i--) {
            for ($j = $y - 1; $j >= 0; $j--) {
                $L[$i * $w + $j] = $ma[$i] === $mb[$j] ? (int) $L[($i + 1) * $w + $j + 1] + 1
                    : max((int) $L[($i + 1) * $w + $j], (int) $L[$i * $w + $j + 1]);
            }
        }
        $i = 0; $j = 0;
        while ($i < $x && $j < $y) {
            if ($ma[$i] === $mb[$j]) { $ops[] = [' ', $ma[$i]]; $i++; $j++; }
            elseif ((int) $L[($i + 1) * $w + $j] >= (int) $L[$i * $w + $j + 1]) { $ops[] = ['-', $ma[$i]]; $i++; }
            else { $ops[] = ['+', $mb[$j]]; $j++; }
        }
        for (; $i < $x; $i++) { $ops[] = ['-', $ma[$i]]; }
        for (; $j < $y; $j++) { $ops[] = ['+', $mb[$j]]; }
    } else {
        $res['approx'] = $x > 0 && $y > 0;
        foreach ($ma as $l) { $ops[] = ['-', $l]; }
        foreach ($mb as $l) { $ops[] = ['+', $l]; }
    }
    for ($i = $na - $suf; $i < $na; $i++) { $ops[] = [' ', $a[$i]]; }

    // 통합 diff 묶음(hunk)
    $total = count($ops);
    $changed = [];
    foreach ($ops as $k => $op) {
        if ($op[0] !== ' ') { $changed[] = $k; }
        if ($op[0] === '+') { $res['added']++; } elseif ($op[0] === '-') { $res['removed']++; }
    }
    $out = [];
    $k = 0;
    $cnt = count($changed);
    while ($k < $cnt) {
        $start = max(0, $changed[$k] - $context);
        $end = $changed[$k];
        while ($k + 1 < $cnt && $changed[$k + 1] - $end <= 2 * $context) { $k++; $end = $changed[$k]; }
        $end = min($total - 1, $end + $context);
        // 줄 번호 계산
        $la = 1; $lb = 1;
        for ($q = 0; $q < $start; $q++) { if ($ops[$q][0] !== '+') { $la++; } if ($ops[$q][0] !== '-') { $lb++; } }
        $ca = 0; $cb = 0;
        for ($q = $start; $q <= $end; $q++) { if ($ops[$q][0] !== '+') { $ca++; } if ($ops[$q][0] !== '-') { $cb++; } }
        $out[] = "@@ -{$la},{$ca} +{$lb},{$cb} @@";
        for ($q = $start; $q <= $end; $q++) {
            $out[] = $ops[$q][0] . $ops[$q][1];
            if (count($out) >= $maxOutLines) { $res['truncated'] = true; break 2; }
        }
        $k++;
    }
    $res['text'] = implode("\n", $out);
    return $res;
}
