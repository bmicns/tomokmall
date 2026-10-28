<?php
declare(strict_types=1);
/**
 * 국가법령정보 공동활용 OPEN API(법제처) 클라이언트 — 법령·행정규칙 조문/시행일/별표 수집과 기존 근거 대조.
 *
 * 책임: 요청 URL 생성, XML 응답 파싱(검색·법령 본문·행정규칙 본문·오류), 조문 정규화·해시, 기준값과 비교.
 * 금지: DB 쓰기·근거 자동 활성화(비교 결과는 보고만 — 활성화는 사람 검토 후 SQL 초안으로),
 *       인증값(OC) 출력·로그·저장(OC 는 getenv('LAW_API_OC') 로만 읽는다).
 * 웹 화면·API 에서 호출하지 않는다(현재 사용처: scripts/legal-sources/law_api_sync.php, tests/law_api.test.php).
 */

const LAW_API_BASE = 'https://www.law.go.kr/DRF';
const LAW_API_TIMEOUT = 15;

/** 인증값(OC). 없으면 '' — 키는 .env.local(로컬) 또는 셸 환경변수로만 넣는다. */
function law_api_oc(): string
{
    return trim((string) (getenv('LAW_API_OC') ?: ''));
}

/** lawSearch.do / lawService.do 요청 URL. $params 에 target·query·MST·ID 등. type=XML 고정. */
function law_api_url(string $endpoint, array $params, string $oc): string
{
    if (!in_array($endpoint, ['lawSearch.do', 'lawService.do'], true)) {
        throw new InvalidArgumentException('지원하지 않는 엔드포인트');
    }
    $params = ['OC' => $oc, 'type' => 'XML'] + $params;
    return LAW_API_BASE . '/' . $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

/**
 * HTTP GET. 실패 시 ['ok'=>false,'error'=>사유] — 사유에 URL(OC 포함)을 넣지 않는다.
 * @return array{ok:bool, body?:string, error?:string}
 */
function law_api_get(string $url, int $timeout = LAW_API_TIMEOUT): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl 확장 없음'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => $timeout,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_USERAGENT      => 'tomokmall-legal-sync/1.0',
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_errno($ch);
    curl_close($ch);
    if ($body === false || $err !== 0) {
        return ['ok' => false, 'error' => "연결 실패(curl {$err})"];
    }
    if ($code !== 200) {
        return ['ok' => false, 'error' => "HTTP {$code}"];
    }
    return ['ok' => true, 'body' => (string) $body];
}

/** XML 문자열 → SimpleXMLElement. 외부 엔티티 비활성(XXE 방지). 실패 시 null */
function law_api_xml(string $xml): ?SimpleXMLElement
{
    $xml = trim($xml);
    if ($xml === '' || $xml[0] !== '<') {
        return null;
    }
    $prev = libxml_use_internal_errors(true);
    $doc  = simplexml_load_string($xml, SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    return $doc === false ? null : $doc;
}

/**
 * API 오류 응답 판별 → 사유 문자열, 정상이면 null.
 * 형식: <result>실패</result><msg>…</msg> / <Law>일치하는 … 없습니다</Law> / XML 아님(인증 실패 시 HTML 안내 페이지 등)
 */
function law_api_error(string $xml): ?string
{
    $doc = law_api_xml($xml);
    if ($doc === null || strcasecmp($doc->getName(), 'html') === 0) {
        return 'XML 응답 아님(인증값·등록 IP/도메인 확인 필요)';
    }
    $result = trim((string) ($doc->result ?? ''));
    if ($result !== '' && str_contains($result, '실패')) {
        $msg = trim((string) ($doc->msg ?? ''));
        return '요청 실패' . ($msg !== '' ? ": {$msg}" : '');
    }
    if (preg_match('/일치하는\s*\S*\s*없습니다/u', trim((string) $doc))) {
        return '결과 없음';
    }
    return null;
}

/** 응답·링크 속 인증값(OC=…) 가리기 — 원본 응답을 파일로 남기거나 공유할 때 사용 */
function law_api_redact(string $text): string
{
    return preg_replace('/(\bOC=)[^&"\'<>\s]+/i', '$1***', $text) ?? $text;
}

/** 'YYYYMMDD' → 'YYYY-MM-DD' (형식이 아니면 null) */
function law_api_date(string $ymd): ?string
{
    $ymd = preg_replace('/\D/', '', $ymd) ?? '';
    return strlen($ymd) === 8 ? substr($ymd, 0, 4) . '-' . substr($ymd, 4, 2) . '-' . substr($ymd, 6, 2) : null;
}

/** 첫 번째로 값이 있는 자식 요소 텍스트 */
function law_api_text(SimpleXMLElement $node, array $names): string
{
    foreach ($names as $n) {
        $list = $node->xpath('.//' . $n);
        foreach ($list ?: [] as $v) {
            $s = trim((string) $v);
            if ($s !== '') {
                return $s;
            }
        }
    }
    return '';
}

/** 상대 링크 → https://www.law.go.kr 절대 URL */
function law_api_abs_url(string $v): string
{
    $v = trim($v);
    if ($v === '') {
        return '';
    }
    if (preg_match('#^https?://#', $v)) {
        return $v;
    }
    return 'https://www.law.go.kr' . ($v[0] === '/' ? '' : '/') . $v;
}

/**
 * 검색 결과(target=law → <law>, target=admrul → <admrul>) 정규화.
 * @return array{total:int, items:list<array>}
 */
function law_api_parse_search(string $xml): array
{
    $doc = law_api_xml($xml);
    if ($doc === null) {
        return ['total' => 0, 'items' => []];
    }
    $items = [];
    foreach ($doc->law as $n) {
        $items[] = [
            'target'      => 'law',
            'key'         => trim((string) $n->{'법령일련번호'}),   // 상세 조회 MST
            'id'          => trim((string) $n->{'법령ID'}),
            'name'        => trim((string) $n->{'법령명한글'}),
            'kind'        => trim((string) ($n->{'법령구분명'} ?? '')),
            'status'      => trim((string) $n->{'현행연혁코드'}),
            'promulgated' => law_api_date((string) $n->{'공포일자'}),
            'number'      => trim((string) $n->{'공포번호'}),
            'effective'   => law_api_date((string) $n->{'시행일자'}),
            'revision'    => trim((string) $n->{'제개정구분명'}),
            'ministry'    => trim((string) $n->{'소관부처명'}),
            'link'        => law_api_abs_url((string) $n->{'법령상세링크'}),
        ];
    }
    foreach ($doc->admrul as $n) {
        $items[] = [
            'target'      => 'admrul',
            'key'         => trim((string) $n->{'행정규칙일련번호'}), // 상세 조회 ID
            'id'          => trim((string) $n->{'행정규칙ID'}),
            'name'        => trim((string) $n->{'행정규칙명'}),
            'kind'        => trim((string) $n->{'행정규칙종류'}),
            'status'      => trim((string) $n->{'현행연혁구분'}),
            'promulgated' => law_api_date((string) $n->{'발령일자'}),
            'number'      => trim((string) $n->{'발령번호'}),
            'effective'   => law_api_date((string) $n->{'시행일자'}),
            'revision'    => trim((string) $n->{'제개정구분명'}),
            'ministry'    => trim((string) $n->{'소관부처명'}),
            'link'        => law_api_abs_url((string) $n->{'행정규칙상세링크'}),
        ];
    }
    return ['total' => (int) ($doc->totalCnt ?? count($items)), 'items' => $items];
}

/**
 * 검색 결과에서 이름이 정확히 같은 현행 항목 1건 선택(종류 지정 시 종류도 일치).
 * 현행 표시가 없으면 시행일이 가장 늦은 항목. 없으면 null.
 */
function law_api_pick(array $items, string $name, string $kind = ''): ?array
{
    $norm = static fn(string $s): string => preg_replace('/[\s·ㆍ]/u', '', $s) ?? $s;
    $cands = array_values(array_filter($items, static fn($i) => $norm($i['name']) === $norm($name)
        && ($kind === '' || $i['kind'] === '' || $i['kind'] === $kind)));
    if (!$cands) {
        return null;
    }
    foreach ($cands as $c) {
        if (in_array($c['status'], ['현행', 'Y'], true)) {
            return $c;
        }
    }
    usort($cands, static fn($a, $b) => strcmp((string) $b['effective'], (string) $a['effective']));
    return $cands[0];
}

/** 별표·서식 단위 → [no, branch, kind, title, file_url, pdf_url] (파일 링크 없는 항목 제외) */
function law_api_parse_annexes(SimpleXMLElement $doc): array
{
    $out = [];
    foreach ($doc->xpath('.//별표단위') ?: [] as $i => $n) {
        $file = law_api_abs_url(law_api_text($n, ['별표서식파일링크', '별표파일링크']));
        $pdf  = law_api_abs_url(law_api_text($n, ['별표서식PDF파일링크', '별표PDF파일링크']));
        if ($file === '' && $pdf === '') {
            continue;
        }
        $out[] = [
            'no'       => law_api_text($n, ['별표번호']) ?: (string) ($i + 1),
            'branch'   => law_api_text($n, ['별표가지번호']),
            'kind'     => law_api_text($n, ['별표구분']) ?: '별표',
            'title'    => law_api_text($n, ['별표제목', '별표명']),
            'file_url' => $file,
            'pdf_url'  => $pdf,
        ];
    }
    return $out;
}

/** 조문 번호 키: '3', '3의2' */
function law_api_article_key(string $no, string $branch = ''): string
{
    $no = ltrim(trim($no), '0') ?: '0';
    $branch = ltrim(trim($branch), '0');
    return $branch !== '' ? "{$no}의{$branch}" : $no;
}

/**
 * 법령 본문(lawService.do target=law) 파싱.
 * 조문 텍스트 = 조문내용 + 항·호·목 내용(문서 순서). 장·절 제목(조문여부=전문)은 조문에서 제외.
 * @return array{meta:array, articles:array<string,array{title:string,text:string}>, annexes:list<array>, addenda:list<array>}
 */
function law_api_parse_law_detail(string $xml): array
{
    $doc = law_api_xml($xml);
    $empty = ['meta' => [], 'articles' => [], 'annexes' => [], 'addenda' => []];
    if ($doc === null) {
        return $empty;
    }
    $meta = [
        'name'        => law_api_text($doc, ['법령명_한글', '법령명한글']),
        'id'          => law_api_text($doc, ['법령ID']),
        'kind'        => law_api_text($doc, ['법종구분']),
        'ministry'    => law_api_text($doc, ['소관부처명', '소관부처']),
        'promulgated' => law_api_date(law_api_text($doc, ['공포일자'])),
        'number'      => law_api_text($doc, ['공포번호']),
        'effective'   => law_api_date(law_api_text($doc, ['시행일자'])),
        'revision'    => law_api_text($doc, ['제개정구분명', '제개정구분']),
    ];
    $articles = [];
    foreach ($doc->xpath('.//조문단위') ?: [] as $jo) {
        $flag = trim((string) ($jo->{'조문여부'} ?? ''));
        if ($flag === '전문') {
            continue;
        }
        $key = law_api_article_key((string) $jo->{'조문번호'}, (string) ($jo->{'조문가지번호'} ?? ''));
        $parts = [];
        foreach ($jo->xpath('.//조문내용 | .//항내용 | .//호내용 | .//목내용') ?: [] as $t) {
            $parts[] = trim((string) $t);
        }
        $articles[$key] = ['title' => trim((string) $jo->{'조문제목'}), 'text' => implode("\n", array_filter($parts, 'strlen'))];
    }
    $addenda = [];
    foreach ($doc->xpath('.//부칙단위') ?: [] as $b) {
        $addenda[] = [
            'promulgated' => law_api_date((string) $b->{'부칙공포일자'}),
            'number'      => trim((string) $b->{'부칙공포번호'}),
            'text'        => trim((string) $b->{'부칙내용'}),
        ];
    }
    return ['meta' => $meta, 'articles' => $articles, 'annexes' => law_api_parse_annexes($doc), 'addenda' => $addenda];
}

/**
 * 행정규칙 본문(lawService.do target=admrul, 루트 AdmRulService) 파싱.
 * 조문내용 요소가 '제N조(제목) …' 형식이면 조문 번호로 묶는다.
 * body_in_file=true: 본문이 파일로만 제공되는 경우 — ① 조문내용이 "상단 메뉴 … 버튼" 안내뿐(KDS·KCS 고시),
 *   ② 조문내용이 비어 있고 <첨부파일>(첨부파일명·첨부파일링크 쌍)이 있음(예: 보도 설치 및 관리 지침 — 개정전문 HWP·PDF).
 * attachments: <첨부파일> 의 [name, url] 목록(별표·서식과 별개).
 */
function law_api_parse_admrule_detail(string $xml): array
{
    $doc = law_api_xml($xml);
    $empty = ['meta' => [], 'articles' => [], 'annexes' => [], 'attachments' => [], 'body_in_file' => false];
    if ($doc === null) {
        return $empty;
    }
    $meta = [
        'name'        => law_api_text($doc, ['행정규칙명']),
        'id'          => law_api_text($doc, ['행정규칙ID']),
        'kind'        => law_api_text($doc, ['행정규칙종류', '행정규칙종류명']),
        'ministry'    => law_api_text($doc, ['소관부처명']),
        'promulgated' => law_api_date(law_api_text($doc, ['발령일자'])),
        'number'      => law_api_text($doc, ['발령번호']),
        'effective'   => law_api_date(law_api_text($doc, ['시행일자'])),
        'revision'    => law_api_text($doc, ['제개정구분명']),
        'current'     => law_api_text($doc, ['현행여부']),
    ];
    $articles = [];
    $all = '';
    foreach ($doc->xpath('.//조문내용') ?: [] as $t) {
        $s = trim((string) $t);
        $all .= $s;
        if (preg_match('/^제\s*(\d+)\s*조(?:\s*의\s*(\d+))?\s*(?:\(([^)]*)\))?/u', $s, $m)) {
            $key = law_api_article_key($m[1], $m[2] ?? '');
            $articles[$key] = ['title' => trim($m[3] ?? ''), 'text' => $s];
        }
    }
    // <첨부파일> 아래 첨부파일명·첨부파일링크가 번갈아 나온다 — 순서대로 짝 짓기
    $attachments = [];
    foreach ($doc->xpath('.//첨부파일') ?: [] as $box) {
        $name = null;
        foreach ($box->children() as $child) {
            $tag = $child->getName();
            if ($tag === '첨부파일명') {
                $name = trim((string) $child);
            } elseif ($tag === '첨부파일링크' && $name !== null) {
                $attachments[] = ['name' => $name, 'url' => preg_replace('#^http://#', 'https://', law_api_abs_url(trim((string) $child)))];
                $name = null;
            }
        }
    }
    $bodyInFile = $articles === [] && (preg_match('/상단\s*메뉴.*버튼/us', $all) === 1 || ($all === '' && $attachments !== []));
    return ['meta' => $meta, 'articles' => $articles, 'annexes' => law_api_parse_annexes($doc), 'attachments' => $attachments, 'body_in_file' => $bodyInFile];
}

/**
 * 조문 비교용 정규화: 공백·마크다운 기호(** \ #)·HTML 태그 제거.
 * API XML 과 원문 미러(마크다운)를 같은 기준으로 비교하기 위함. 개정 표시(<개정 …>)는 내용이므로 남긴다.
 */
function law_api_normalize(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('#</?(img|p|br|span|div|table|tr|td|th)\b[^>]*>#iu', '', $text) ?? $text;
    $text = preg_replace('/\*\*|\\\\|^#+/mu', '', $text) ?? $text;
    return preg_replace('/\s+/u', '', $text) ?? $text;
}

/** 정규화 텍스트 해시(sha256 앞 16자) */
function law_api_hash(string $text): string
{
    return substr(hash('sha256', law_api_normalize($text)), 0, 16);
}

/**
 * 원문 미러 마크다운(legalize-kr 형식 '##### 제3조 (제목)')에서 조문 1개 추출. 없으면 null.
 * 다음 조문 또는 장·절 제목(#)에서 끝난다.
 */
function law_api_mirror_article(string $markdown, string $key): ?string
{
    [$no, $branch] = array_pad(explode('의', $key, 2), 2, '');
    $head = '제' . $no . '조' . ($branch !== '' ? '의' . $branch : '');
    $lines = preg_split('/\R/u', $markdown) ?: [];
    $buf = null;
    foreach ($lines as $line) {
        if (preg_match('/^#{1,6}\s/u', $line)) {
            if ($buf !== null) {
                break;
            }
            if (preg_match('/^#{5}\s*' . preg_quote($head, '/') . '(?:\s|\(|$)/u', $line)) {
                $buf = [preg_replace('/^#+\s*/u', '', $line)];
            }
            continue;
        }
        if ($buf !== null) {
            $buf[] = $line;
        }
    }
    return $buf === null ? null : trim(implode("\n", $buf));
}

/**
 * 기준값(원문 미러에서 만든 해시·버전)과 API 결과 비교 → 상태.
 *  same      : 조문 내용·공포번호 일치 → API 원문 대조 완료로 기록 가능(활성 상태는 바꾸지 않음)
 *  new_version_same : 새 판이 시행 중이나 감시 조문 해시는 그대로 → 근거 유지, 기준값만 갱신
 *  amended   : 공포(발령)번호·시행일이 바뀌고 감시 조문도 다름(또는 감시 조문 없음) → 재검토(활성 근거면 비활성 권고)
 *  changed   : 번호는 같은데 조문 내용이 다름 → 형식 차이일 수 있으니 사람 확인
 *  missing   : API 응답에 해당 조문 없음 → 조문 이동·삭제 확인
 *  pending   : 시행일이 오늘 이후(시행 예정 개정) → 시행일 전까지 기존 근거 유지, 시행일에 재검토
 * @param array $baseline ['number'=>, 'effective'=>, 'articles'=>[key=>hash]]
 * @param array $api      law_api_parse_law_detail()/law_api_parse_admrule_detail() 결과
 */
function law_api_compare(array $baseline, array $api, string $today): array
{
    $meta = $api['meta'] ?? [];
    $numOld = ltrim((string) ($baseline['number'] ?? ''), '0');
    $numNew = ltrim((string) ($meta['number'] ?? ''), '0');
    $versionChanged = ($numOld !== '' && $numNew !== '' && $numOld !== $numNew)
        || (($baseline['effective'] ?? '') !== '' && ($meta['effective'] ?? '') !== '' && $baseline['effective'] !== $meta['effective']);
    $future = ($meta['effective'] ?? '') !== '' && $meta['effective'] > $today;
    $articles = [];
    foreach ((array) ($baseline['articles'] ?? []) as $key => $hash) {
        $a = $api['articles'][(string) $key] ?? null;
        if ($a === null) {
            $articles[(string) $key] = ['status' => 'missing', 'api_hash' => null];
            continue;
        }
        $h = law_api_hash($a['text']);
        $articles[(string) $key] = ['status' => $h === $hash ? 'same' : 'changed', 'api_hash' => $h];
    }
    $statuses = array_column($articles, 'status');
    if ($future && $versionChanged) {
        $status = 'pending';
    } elseif ($versionChanged && $statuses !== [] && !array_diff($statuses, ['same'])) {
        $status = 'new_version_same';   // 새 판이 시행됐지만 감시 조문은 그대로 → 기준값(번호·시행일)만 갱신
    } elseif ($versionChanged) {
        $status = 'amended';
    } elseif (in_array('missing', $statuses, true)) {
        $status = 'missing';
    } elseif (in_array('changed', $statuses, true)) {
        $status = 'changed';
    } else {
        $status = 'same';
    }
    return [
        'status'    => $status,
        'version'   => ['baseline' => ['number' => $baseline['number'] ?? '', 'effective' => $baseline['effective'] ?? ''],
                        'api' => ['number' => $meta['number'] ?? '', 'effective' => $meta['effective'] ?? '', 'promulgated' => $meta['promulgated'] ?? '']],
        'articles'  => $articles,
        'annexes'   => count($api['annexes'] ?? []),
    ];
}

/** 비교 상태 → 사람이 할 일(한 줄). 어떤 상태도 자동 활성화하지 않는다. */
function law_api_action(string $status, bool $activeClause): string
{
    return match ($status) {
        'same'    => 'API 원문 대조 일치 — verify_source 에 API 대조일 기록 가능(활성 상태 변경 없음)',
        'pending' => '시행 예정 개정 — 시행일 전까지 기존 근거 유지, 시행일에 개정 조문 재검토',
        'new_version_same' => '새 판 시행 — 감시 조문 내용 동일, 근거 유지·기준값(번호·시행일)만 갱신(build_api_baseline 또는 수동)',
        'amended' => $activeClause ? '개정됨 — 재검토 필요, 검토 전까지 비활성 권고(SQL 초안)' : '개정됨 — 후보 정보(시행일·번호) 갱신 필요',
        'changed' => '조문 내용 차이 — 형식 차이인지 실제 개정인지 사람 확인',
        'missing' => '조문 없음 — 조문 이동·삭제 확인',
        default   => '확인 필요',
    };
}
