<?php
declare(strict_types=1);
/**
 * 조달청 '나라장터쇼핑몰 품목 등록 내역'(조달데이터허브 보고서 UI-ADOXFA-183R · 공공데이터포털 15142663) → 자재 종류별 공공조달 등록 현황(집계만)
 *
 * 책임: 허브에서 내려받은 파일(UTF-16/UTF-8, 탭·쉼표, 위쪽 '검색조건' 줄 포함)을 읽어 물품식별번호로 중복을 없애고(납품장소마다 같은 품목이 반복),
 *       우리 취급 자재에 해당하는 세부품명별로 품목 수·업체 수·인증 표시 품목 수(KS·단체표준·우수조달·품질보증·환경표지)·등록일 범위를 센다.
 *       결과는 app/procurement_summary.php(배열) — AI 상담이 '공공 조달몰에 등록된 같은 종류 제품' 사실로 쓴다(app/ai_tools.php ai_procurement_summary).
 * 금지: 단가(가격)·업체 사업자등록번호·납품장소·업체 이름을 결과에 넣지 않는다(집계 숫자만). 원본 파일을 저장소에 넣지 않는다(저장소 밖에 두고 경로만 받음).
 *       우리 상품의 인증·조달 등록 여부를 만들지 않는다(다른 회사 품목의 집계일 뿐).
 * 사용: php scripts/external/g2b_summary.php --file=<허브 CSV> [--out=app/procurement_summary.php] [--md=요약.md]
 */
if (PHP_SAPI !== 'cli') { exit; }

/** 세부품명(조달 물품분류) → [키, 상담 표시 이름, 질문·상품명에서 찾을 낱말] — 우리 취급 자재만 */
const G2B_SUMMARY_GROUPS = [
    '원심력철근콘크리트관'       => ['hume_pipe', '원심력 철근콘크리트관(흄관)', ['흄관', '원심력']],
    '철근콘크리트벤치플룸관'     => ['bench_flume', '철근콘크리트 벤치플룸관', ['벤치플룸', '벤치플륨', '벤치']],
    '철근콘크리트용배수로관'     => ['drain_channel', '철근콘크리트 배수로관(수로관)', ['수로관', '배수로관', 'U형', '측구']],
    '조립식철근콘크리트암거블록' => ['culvert_block', '조립식 철근콘크리트 암거블록', ['암거']],
    '콘크리트맨홀블록'           => ['manhole_block', '콘크리트 맨홀블록', ['맨홀블록', '원형맨홀', '사각맨홀', '조립식맨홀', '맨홀']],
    '주철맨홀뚜껑'               => ['manhole_cover', '주철 맨홀뚜껑', ['맨홀뚜껑', '주철뚜껑', '맨홀 뚜껑']],
    '콘크리트호안및옹벽블록'     => ['retaining_block', '콘크리트 호안·옹벽블록', ['호안', '옹벽', '보강토', '식생블록', '식생축조']],
    '보차도용콘크리트블록'       => ['paving_block', '보차도용 콘크리트블록', ['보도블록', '보차도', '인터로킹', '블록포장', '보도블럭']],
    '콘크리트경계블록'           => ['curb_block', '콘크리트 경계블록', ['경계석', '경계블록', '경계블럭']],
    '자연석경계석'               => ['curb_stone', '자연석 경계석', ['경계석', '화강경계']],
    '일반용폴리에틸렌관'         => ['pe_pipe', '일반용 폴리에틸렌관(PE관)', ['PE관', '이중벽관', '다중벽관', '폴리에틸렌관', 'HDPE', '유공관']],
    '비압력용경질폴리염화비닐관' => ['pvc_pipe', '비압력용 경질 PVC관', ['PVC관', 'PVC 관', '경질염화비닐', '경질폴리염화비닐']],
    '압력용경질폴리염화비닐관'   => ['pvc_pressure_pipe', '압력용 경질 PVC관', ['PVC관', 'PVC 관', '수도관']],
    '스틸그레이팅'               => ['steel_grating', '스틸그레이팅', ['그레이팅']],
    '그레이팅덮개'               => ['grating_cover', '그레이팅덮개', ['그레이팅덮개', '그레이팅 덮개']],
    '파형강관'                   => ['corrugated_pipe', '파형강관', ['파형강관']],
    '안전유도블록'               => ['tactile_block', '안전유도블록(점형·선형)', ['점자블록', '점자블럭', '유도블록', '점형블록', '선형블록']],
];

/** 집계할 인증 표시(물품인증유형목록 값 그대로) → 결과 키 */
const G2B_SUMMARY_CERTS = ['KS' => 'ks', '단체표준인증' => 'group_standard', '우수조달물품' => 'excellent', '품질보증조달물품' => 'quality_assured', '환경표지제품' => 'eco_label'];

/** 파일 → UTF-8 줄 배열 (순수 함수에 가까움 — 파일만 읽음). UTF-16(BOM 없음 포함)·UTF-8(BOM) 자동 */
function g2b_read_lines(string $file): array
{
    $raw = (string) file_get_contents($file);
    if (str_starts_with($raw, "\xFF\xFE") || (strlen($raw) > 3 && $raw[1] === "\x00" && $raw[3] === "\x00")) {
        $raw = (string) mb_convert_encoding(str_starts_with($raw, "\xFF\xFE") ? substr($raw, 2) : $raw, 'UTF-8', 'UTF-16LE');
    } elseif (str_starts_with($raw, "\xEF\xBB\xBF")) {
        $raw = substr($raw, 3);
    } elseif (!mb_check_encoding($raw, 'UTF-8')) {
        $raw = (string) mb_convert_encoding($raw, 'UTF-8', 'CP949');
    }
    return preg_split('/\r\n|\n|\r/', $raw) ?: [];
}

/**
 * 줄 배열 → [출력일자, 품목 행(물품식별번호별 1행, 필요한 칸만)] (순수 함수).
 * 머리글은 '계약구분'으로 시작하는 줄(위쪽 '검색조건' 줄은 건너뜀). 단가·사업자등록번호·납품장소 칸은 읽지 않는다.
 */
function g2b_parse_items(array $lines): array
{
    $asOf = '';
    $head = null;
    $items = [];
    foreach ($lines as $line) {
        if ($head === null) {
            if (preg_match('/출력일자\s*:\s*(\d{4}-\d{2}-\d{2})/u', $line, $m)) { $asOf = $m[1]; }
            if (preg_match('/^"?계약구분"?[\t,]/u', $line)) {
                $delim = substr_count($line, "\t") >= substr_count($line, ',') ? "\t" : ',';
                $head = array_map('trim', str_getcsv($line, $delim, '"', ''));
            }
            continue;
        }
        if (trim($line) === '') { continue; }
        $cells = str_getcsv($line, $delim, '"', '');
        if (count($cells) !== count($head)) { continue; }
        $r = array_combine($head, $cells);
        $id = trim((string) ($r['물품식별번호'] ?? ''));
        if ($id === '' || isset($items[$id])) { continue; }
        $items[$id] = [
            'detail'  => trim((string) ($r['세부품명'] ?? '')),
            'name'    => trim((string) ($r['품목명'] ?? '')),
            'maker'   => trim((string) ($r['업체'] ?? '')),   // 업체 수를 세는 데만 — 결과에 이름은 넣지 않음
            'certs'   => array_values(array_filter(array_map('trim', explode(',', (string) ($r['물품인증유형목록'] ?? ''))))),
            'excellent' => strtoupper(trim((string) ($r['우수제품여부'] ?? ''))) === 'Y',
            'registered' => trim((string) ($r['쇼핑몰등록일자'] ?? '')),
        ];
    }
    return [$asOf, array_values($items)];
}

/** 품목 행 → 자재 종류별 집계 (순수 함수). 우리 취급 세부품명만, 품목 수 많은 순 */
function g2b_summarize(array $items, string $asOf): array
{
    $groups = [];
    foreach ($items as $it) {
        $g = G2B_SUMMARY_GROUPS[$it['detail']] ?? null;
        if ($g === null) { continue; }
        [$key, $label, $words] = $g;
        $groups[$key] ??= ['detail' => $it['detail'], 'label' => $label, 'words' => $words, 'items' => 0, 'makers' => [],
                           'certs' => array_fill_keys(array_values(G2B_SUMMARY_CERTS), 0), 'registered_from' => '', 'registered_to' => ''];
        $x = &$groups[$key];
        $x['items']++;
        $x['makers'][$it['maker']] = true;
        foreach (G2B_SUMMARY_CERTS as $name => $k) {
            if ($k === 'excellent' ? ($it['excellent'] || in_array($name, $it['certs'], true)) : in_array($name, $it['certs'], true)) { $x['certs'][$k]++; }
        }
        if (preg_match('/^\d{8}$/', $it['registered'])) {
            $d = substr($it['registered'], 0, 4) . '-' . substr($it['registered'], 4, 2) . '-' . substr($it['registered'], 6, 2);
            $x['registered_from'] = $x['registered_from'] === '' || $d < $x['registered_from'] ? $d : $x['registered_from'];
            $x['registered_to'] = $d > $x['registered_to'] ? $d : $x['registered_to'];
        }
        unset($x);
    }
    foreach ($groups as &$x) { $x['makers'] = count($x['makers']); }
    unset($x);
    uasort($groups, static fn($a, $b) => $b['items'] <=> $a['items']);
    return [
        'source'  => '조달청 조달데이터허브 「나라장터쇼핑몰 품목 등록 내역」(보고서 UI-ADOXFA-183R, 공공데이터포털 15142663 — 이용허락범위 제한 없음)',
        'url'     => 'https://www.data.go.kr/data/15142663/fileData.do',
        'scope'   => '대분류 토목/건축/자재 · 쇼핑몰 계약 해당 품목 — 물품식별번호 기준 중복 제거, 우리 취급 자재 세부품명만',
        'as_of'   => $asOf,
        'note'    => '공공기관 구매용 조달몰에 등록된 다른 회사 품목의 집계 — 토목몰 상품의 인증·조달 등록 여부가 아니다. 가격·업체 이름은 넣지 않음.',
        'groups'  => $groups,
    ];
}

/** 집계 → app 데이터 파일 PHP 원문 (순수 함수) */
function g2b_summary_php(array $summary): string
{
    return "<?php\ndeclare(strict_types=1);\n/**\n * 자재 종류별 공공조달 등록 현황(집계) — 생성: scripts/external/g2b_summary.php (손으로 고치지 않음)\n"
        . " * 출처: {$summary['source']}, 출력일자 {$summary['as_of']}\n * 사용: app/ai_tools.php ai_procurement_summary — 가격·업체 이름 없음\n */\nreturn "
        . var_export($summary, true) . ";\n";
}

/** 집계 → 사람이 읽는 표(마크다운) (순수 함수) */
function g2b_summary_md(array $summary, int $totalItems): string
{
    $md = "| 세부품명(조달) | 품목 | 업체 | KS 표시 | 단체표준인증 | 우수조달 | 품질보증조달 | 환경표지 | 등록일 범위 |\n|---|---:|---:|---:|---:|---:|---:|---:|---|\n";
    foreach ($summary['groups'] as $g) {
        $pct = static fn(int $n) => $g['items'] ? $n . ' (' . round($n * 100 / $g['items']) . '%)' : '0';
        $md .= "| {$g['detail']} | {$g['items']} | {$g['makers']} | {$pct($g['certs']['ks'])} | {$pct($g['certs']['group_standard'])} | {$g['certs']['excellent']} | {$g['certs']['quality_assured']} | {$g['certs']['eco_label']} | {$g['registered_from']}~{$g['registered_to']} |\n";
    }
    return $md . "\n전체 품목(중복 제거) {$totalItems}개 중 위 자재 " . array_sum(array_column($summary['groups'], 'items')) . "개. 출력일자 {$summary['as_of']}.\n";
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === realpath(__FILE__)) {
    $root = dirname(__DIR__, 2);
    $opt = getopt('', ['file:', 'out:', 'md:']);
    $file = (string) ($opt['file'] ?? '');
    if ($file === '' || !is_readable($file)) { fwrite(STDERR, "사용법: --file=<조달데이터허브 CSV> [--out=app/procurement_summary.php] [--md=요약.md]\n"); exit(2); }
    $real = (string) realpath($file);
    if (str_starts_with($real, realpath($root) . '/')) { fwrite(STDERR, "거부: 원본 파일이 저장소 안입니다({$real}) — 가격·사업자등록번호가 들어 있으므로 저장소 밖에 두세요.\n"); exit(2); }
    [$asOf, $items] = g2b_parse_items(g2b_read_lines($file));
    if (!$items) { fwrite(STDERR, "품목 행을 읽지 못했습니다('계약구분' 머리글 줄이 있는 허브 보고서 파일인지 확인).\n"); exit(2); }
    $summary = g2b_summarize($items, $asOf);
    $out = (string) ($opt['out'] ?? "{$root}/app/procurement_summary.php");
    file_put_contents($out, g2b_summary_php($summary));
    $md = g2b_summary_md($summary, count($items));
    if (!empty($opt['md'])) { file_put_contents((string) $opt['md'], $md); }
    echo $md . "저장: {$out}\n";
}
