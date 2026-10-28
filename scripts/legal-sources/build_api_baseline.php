<?php
declare(strict_types=1);
/**
 * api_targets.json 의 documents[].baseline(대조 기준값) 생성 — 법제처 원문 미러(legalize-kr) 마크다운에서 계산.
 *
 * 기준값: 공포(발령)번호·공포일·시행일, 감시 조문별 정규화 해시(law_api_hash), 감시 별표 제목 존재 여부.
 * 조문 전문은 저장하지 않는다(해시만). 미러는 비공식 재배포본이므로 기준값 출처를 'mirror' 로 표시하고,
 * 인증키 발급 후 law_api_sync.php 로 공식 API 응답과 대조한다.
 * 실행: php scripts/legal-sources/build_api_baseline.php <미러 디렉터리> [api_targets.json]
 *   미러 디렉터리 아래에 documents[].mirror_file 경로(예: legalize/kr/parking_rule.md)가 있어야 한다.
 */
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__, 2) . '/app/law_api.php';

$mirrorDir = rtrim((string) ($argv[1] ?? ''), '/');
$file = $argv[2] ?? dirname(__DIR__, 2) . '/docs/03-analysis/ai-sourcing/legal-sources/api_targets.json';
if ($mirrorDir === '' || !is_dir($mirrorDir)) {
    fwrite(STDERR, "사용법: php build_api_baseline.php <미러 디렉터리> [api_targets.json]\n");
    exit(2);
}
$data = json_decode((string) file_get_contents($file), true);
if (!is_array($data) || !isset($data['documents'])) {
    fwrite(STDERR, "api_targets.json 형식 오류\n");
    exit(1);
}

/** 미러 YAML 머리말에서 단순 key: value 읽기 */
$front = static function (string $md, string $key): string {
    return preg_match('/^' . preg_quote($key, '/') . ":\s*'?([^'\n]*)'?\s*$/mu", $md, $m) ? trim($m[1]) : '';
};

$errors = 0;
foreach ($data['documents'] as &$doc) {
    $path = "{$mirrorDir}/{$doc['mirror_file']}";
    if (!is_readable($path)) {
        fwrite(STDERR, "미러 파일 없음: {$doc['mirror_file']}\n");
        $errors++;
        continue;
    }
    $md = (string) file_get_contents($path);
    $isLaw = $doc['target'] === 'law';
    $articles = [];
    foreach ($doc['watch_articles'] as $key) {
        $text = law_api_mirror_article($md, (string) $key);
        if ($text === null) {
            fwrite(STDERR, "{$doc['key']}: 제{$key}조를 미러에서 찾지 못함\n");
            $errors++;
            continue;
        }
        $articles[(string) $key] = law_api_hash($text);
    }
    $annexes = [];
    foreach ($doc['watch_annexes'] as $title) {
        $annexes[$title] = str_contains(law_api_normalize($md), law_api_normalize($title));
    }
    $doc['baseline'] = [
        'source'      => 'mirror',
        'source_note' => $isLaw ? 'legalize-kr/legalize-kr (법제처 OPEN API 원문 미러, 2026-10-27 스냅샷)' : 'legalize-kr/admrule-kr (법제처 OPEN API 행정규칙 원문 미러, 2026-10-27 스냅샷)',
        'number'      => $front($md, $isLaw ? '공포번호' : '발령번호'),
        'promulgated' => $front($md, $isLaw ? '공포일자' : '발령일자'),
        'effective'   => $front($md, '시행일자'),
        'mst'         => $front($md, $isLaw ? '법령MST' : '행정규칙일련번호'),
        'articles'    => $articles,
        'annexes'     => $annexes,
    ];
}
unset($doc);
file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'documents ' . count($data['documents']) . "건 기준값 갱신 · 오류 {$errors}건\n";
exit($errors > 0 ? 1 : 0);
