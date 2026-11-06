<?php
declare(strict_types=1);
/**
 * 법제처 원문 미러(GitHub legalize-kr) 최신성 재확인 — 감시 조문이 2026-10-27 기준값 이후 바뀌었는지.
 *
 * 책임: api_targets.json 의 문서마다 미러 파일(HEAD 또는 고정 커밋)을 내려받아 머리말(공포·발령번호, 시행일)과
 *       감시 조문(watch_articles)을 뽑고, documents[].baseline(2026-10-27 스냅샷 해시 — verified_clauses.json 조문을
 *       확인한 그 원문)과 law_api_compare() 로 비교해 same/changed 등을 출력한다.
 *       verified_clauses.json 에는 조문 전문이 없고 요약만 있으므로, 조문 비교 기준은 같은 원문의 정규화 해시다.
 *       장애인등편의법(법률)은 시행 예정판 2건(2026-11-12, 2027-05-27)도 따로 비교한다(시행일 이후 재실행용).
 * 금지: 어떤 결과도 DB·JSON 에 반영하지 않는다(출력만). 활성화·확인 수준 변경 없음. 미러는 비공식 재배포본 —
 *       '바뀜'이 나오면 공식 사이트(law.go.kr)에서 사람이 확인한다.
 * 실행: php scripts/legal-sources/mirror_recheck.php [--save=<디렉터리>] [--offline=<디렉터리>] [--today=YYYY-MM-DD]
 *   --save    내려받은 미러 파일을 저장(저장소 밖 임시 폴더 권장)
 *   --offline 네트워크 대신 저장해 둔 파일 사용(--save 로 만든 폴더)
 * 종료 코드: 0 = 모두 same/pending(감시 조문 동일), 1 = 확인 필요 항목 있음, 2 = 내려받기 실패 포함
 */
if (PHP_SAPI !== 'cli') { exit; }
require dirname(__DIR__, 2) . '/app/law_api.php';

const MIRROR_RAW = 'https://raw.githubusercontent.com/legalize-kr/';
/** 미러 저장소 경로 (api_targets.json documents[].key → [저장소, 파일]) — 2026-11-02 확인 */
const MIRROR_PATHS = [
    'parking_rule'  => ['legalize-kr', 'kr/주차장법/시행규칙.md'],
    'disab_law'     => ['legalize-kr', 'kr/장애인ㆍ노인ㆍ임산부등의편의증진보장에관한법률/법률.md'],
    'disab_rule'    => ['legalize-kr', 'kr/장애인ㆍ노인ㆍ임산부등의편의증진보장에관한법률/시행규칙.md'],
    'disab_decree'  => ['legalize-kr', 'kr/장애인ㆍ노인ㆍ임산부등의편의증진보장에관한법률/시행령.md'],
    'mobility_rule' => ['legalize-kr', 'kr/교통약자의이동편의증진법/시행규칙.md'],
    'walk_rule'     => ['legalize-kr', 'kr/보행안전및편의증진에관한법률/시행규칙.md'],
    'sidewalk'      => ['admrule-kr', '국토교통부/_본부/예규/보도 설치 및 관리 지침/본문.md'],
    'road_struct'   => ['legalize-kr', 'kr/도로의구조ㆍ시설기준에관한규칙/국토교통부령.md'],
    'road_conn'     => ['legalize-kr', 'kr/도로와다른시설의연결에관한규칙/국토교통부령.md'],
    'sewer_law'     => ['legalize-kr', 'kr/하수도법/법률.md'],
    'sewer_decree'  => ['legalize-kr', 'kr/하수도법/시행령.md'],
    'sewer_rule'    => ['legalize-kr', 'kr/하수도법/시행규칙.md'],
    'sewer_maint'   => ['admrule-kr', '기후에너지환경부/_본부/고시/하수관로 유지관리 기준/본문.md'],
    'kds61'         => ['admrule-kr', '기후에너지환경부/_본부/고시/하수도설계기준/본문.md'],
    'road_kds'      => ['admrule-kr', '국토교통부/_본부/고시/도로설계기준(KDS 44 00 00) 및 도로공사 표준시방서(KCS 44 00 00)/본문.md'],
    'landscape_kds' => ['admrule-kr', '국토교통부/_본부/고시/조경설계기준(KDS 34 00 00) 및 조경공사 표준시방서(KCS 34 00 00)/본문.md'],
    // 2026-11-04 추가(V28~V35 — 옹벽·담장·성토·하천·도로 점용·KS 표시·과속방지턱)
    'bldg_law'      => ['legalize-kr', 'kr/건축법/법률.md'],
    'bldg_decree'   => ['legalize-kr', 'kr/건축법/시행령.md'],
    'bldg_rule'     => ['legalize-kr', 'kr/건축법/시행규칙.md'],
    'plan_law'      => ['legalize-kr', 'kr/국토의계획및이용에관한법률/법률.md'],
    'plan_decree'   => ['legalize-kr', 'kr/국토의계획및이용에관한법률/시행령.md'],
    'river_law'     => ['legalize-kr', 'kr/하천법/법률.md'],
    'sriver_law'    => ['legalize-kr', 'kr/소하천정비법/법률.md'],
    'road_law'      => ['legalize-kr', 'kr/도로법/법률.md'],
    'road_decree'   => ['legalize-kr', 'kr/도로법/시행령.md'],
    'ks_law'        => ['legalize-kr', 'kr/산업표준화법/법률.md'],
    'road_safety'   => ['admrule-kr', '국토교통부/_본부/예규/도로안전시설 설치 및 관리 지침/본문.md'],
    // 2026-11-05 추가(V37 주차장 종류 정의, V38 하천 수로 기준)
    'parking_law'   => ['legalize-kr', 'kr/주차장법/법률.md'],
    'river_occupy'  => ['admrule-kr', '기후에너지환경부/_본부/고시/하천점용허가 세부기준/본문.md'],
];
/**
 * 시행 예정판 고정 커밋(미러는 커밋 날짜 = 공포일). 미러가 force-push 되면 해시가 바뀌므로 404 이면
 * `git log -- <파일>` 로 다시 찾는다.
 */
const MIRROR_PENDING = [
    'disab_law' => [
        ['number' => '21116', 'effective' => '2026-11-12', 'ref' => '479218824d6a609f144dc6a01fb1dfa58318702e'],
        ['number' => '21697', 'effective' => '2027-05-27', 'ref' => '113a8cce1583c7121f71f7ee342f9e6a1191116c'],
    ],
];

/** 미러 YAML 머리말 key: value (순수 함수) */
function mirror_front(string $md, string $key): string
{
    return preg_match('/^' . preg_quote($key, '/') . ":\s*'?([^'\n]*)'?\s*$/mu", $md, $m) ? trim($m[1]) : '';
}

/** 미러 마크다운 → law_api_compare() 입력 형식 (순수 함수) */
function mirror_to_api(string $md, bool $isLaw, array $watch): array
{
    $articles = [];
    foreach ($watch as $key) {
        $text = law_api_mirror_article($md, (string) $key);
        if ($text !== null) {
            $articles[(string) $key] = ['text' => $text];
        }
    }
    return [
        'meta' => [
            'number'      => mirror_front($md, $isLaw ? '공포번호' : '발령번호'),
            'promulgated' => mirror_front($md, $isLaw ? '공포일자' : '발령일자'),
            'effective'   => mirror_front($md, '시행일자'),
        ],
        'articles' => $articles,
        'annexes'  => [],
    ];
}

function mirror_fetch(string $repo, string $path, string $ref, ?string $offline, ?string $save): ?string
{
    $local = $repo . '/' . $ref . '/' . $path;
    if ($offline !== null) {
        $f = "{$offline}/{$local}";
        return is_readable($f) ? (string) file_get_contents($f) : null;
    }
    $url = MIRROR_RAW . $repo . '/' . $ref . '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => true]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $code !== 200 || !str_starts_with((string) $body, '---')) {
        fwrite(STDERR, "  내려받기 실패({$code}): {$repo} {$ref} {$path}\n");
        return null;
    }
    if ($save !== null) {
        @mkdir(dirname("{$save}/{$local}"), 0777, true);
        file_put_contents("{$save}/{$local}", $body);
    }
    return (string) $body;
}

if (realpath($argv[0] ?? '') !== __FILE__) {
    return; // 테스트에서 함수만 쓸 때
}

$opt = getopt('', ['save:', 'offline:', 'today:', 'targets:']);
$today = (string) ($opt['today'] ?? date('Y-m-d'));
$targetsFile = (string) ($opt['targets'] ?? dirname(__DIR__, 2) . '/docs/03-analysis/ai-sourcing/legal-sources/api_targets.json');
$data = json_decode((string) file_get_contents($targetsFile), true);
if (!is_array($data) || !isset($data['documents'])) {
    fwrite(STDERR, "api_targets.json 형식 오류\n");
    exit(2);
}
// 근거 조문 표시: api_targets 의 clauses + verified_clauses.json 에서 같은 문서명(별표 조문 V13~V27 포함)
$clauseIds = [];
foreach ((array) json_decode((string) file_get_contents(dirname($targetsFile) . '/verified_clauses.json'), true) as $vc) {
    $clauseIds[(string) $vc['doc_name']][] = (string) $vc['id'];
}
$offline = isset($opt['offline']) ? rtrim((string) $opt['offline'], '/') : null;
$save = isset($opt['save']) ? rtrim((string) $opt['save'], '/') : null;

echo "미러 재확인 (기준값: 2026-10-27 스냅샷 · 오늘 {$today})\n";
$needCheck = 0; $failed = 0;
foreach ($data['documents'] as $doc) {
    $key = (string) $doc['key'];
    if (!isset(MIRROR_PATHS[$key])) {
        echo "- {$key}: 미러 경로 미등록 — 건너뜀\n";
        $needCheck++;
        continue;
    }
    [$repo, $path] = MIRROR_PATHS[$key];
    $isLaw = $doc['target'] === 'law';
    $ids = array_values(array_unique(array_merge((array) $doc['clauses'], $clauseIds[(string) $doc['name']] ?? [])));
    sort($ids);
    $clauses = $ids ? implode('·', $ids) : '조문 근거 없음';
    $versions = [['label' => 'HEAD', 'ref' => 'main']];
    foreach (MIRROR_PENDING[$key] ?? [] as $p) {
        $versions[] = ['label' => "시행 예정 제{$p['number']}호({$p['effective']})", 'ref' => $p['ref']];
    }
    foreach ($versions as $v) {
        $md = mirror_fetch($repo, $path, $v['ref'], $offline, $save);
        if ($md === null) {
            echo "- {$doc['name']} [{$v['label']}]: 내려받기 실패\n";
            $failed++;
            continue;
        }
        $api = mirror_to_api($md, $isLaw, (array) $doc['watch_articles']);
        $cmp = law_api_compare((array) $doc['baseline'], $api, $today);
        $arts = [];
        foreach ($cmp['articles'] as $a => $r) {
            $arts[] = "제{$a}조 " . ($r['status'] === 'same' ? '같음' : ($r['status'] === 'changed' ? '바뀜' : '없음'));
        }
        $artStatuses = array_column($cmp['articles'], 'status');
        $artsSame = !array_diff($artStatuses, ['same']);
        if (!in_array($cmp['status'], ['same', 'pending', 'new_version_same'], true) || !$artsSame) {
            $needCheck++;
        }
        printf(
            "- %s [%s] %s: 기준 %s(시행 %s) → 미러 %s(시행 %s) · %s · 근거 %s\n",
            $doc['name'], $v['label'], $cmp['status'],
            $cmp['version']['baseline']['number'], $cmp['version']['baseline']['effective'],
            $cmp['version']['api']['number'], $cmp['version']['api']['effective'],
            $arts ? implode(', ', $arts) : '감시 조문 없음(번호·시행일만)', $clauses
        );
    }
}
echo "결과: 확인 필요 {$needCheck}건 · 내려받기 실패 {$failed}건 (pending = 시행 예정판, 감시 조문이 같으면 근거 유지)\n";
exit($failed > 0 ? 2 : ($needCheck > 0 ? 1 : 0));
