<?php
declare(strict_types=1);
/**
 * 국가건설기준센터(KCSC) OpenAPI 도우미 (순수 함수) — 사용: scripts/legal-sources/kcsc_fetch.php, 테스트 tests/kcsc_api.test.php
 *
 * 요청 형식(2026-11-05 기준 — 공식 문서 대신 공개 연동 코드 2곳 sinmb79/KCSC-MCP·lhs1152-lgtm/kcsc-design-mcp 에서 확인, 실제 키로 첫 실행 때 다시 확인):
 *   목록: GET https://kcsc.re.kr/OpenApi/CodeList?key=<키>                 → JSON [{codeType, code(6자리), fullCode, name, version, updateDate, …}]
 *   본문: GET https://kcsc.re.kr/OpenApi/CodeViewer/<KDS|KCS>/<코드>?key=<키> → JSON {…, list:[{label, title, level, sort, contents(HTML)}]} (한 원소 배열로 올 수 있음)
 *   키가 틀리면 HTTP 200 + 모든 값이 null 인 1건이 온다(오류 코드 없음) → kcsc_is_null_sentinel 로 거른다.
 * 금지: 키를 출력·저장·로그에 남기지 않는다(kcsc_mask). 기준 본문을 저장소에 저장·재게시하지 않는다(원본은 저장소 밖 폴더 — 상담 근거는 사람이 확인한 요지만).
 */

const KCSC_BASE = 'https://kcsc.re.kr/OpenApi';
/** 요청 User-Agent — ASCII 만(한글이 들어가면 이 클라우드 환경의 프록시·서버가 HTTP 502 로 답함, 2026-11-05 확인) */
const KCSC_USER_AGENT = 'tomokmall-kcsc-reader/1.0 (standards check)';

/** 'KDS 14 31 10' · 'KCS 61 40 10' · 'KCS/614010' → ['KCS', '614010'] (순수 함수). 형식이 아니면 null */
function kcsc_normalize_code(string $s): ?array
{
    $s = strtoupper(trim($s));
    if (!preg_match('/^(KDS|KCS)\s*[\/\s]?\s*((?:\d\s*){6}(?:(?:\d\s*){2})?)$/', $s, $m)) {
        return null;
    }
    return [$m[1], (string) preg_replace('/\s+/', '', $m[2])];
}

/** 요청 URL (순수 함수) — 키는 쿼리 key 로만 */
function kcsc_url(string $path, string $key): string
{
    return KCSC_BASE . '/' . ltrim($path, '/') . '?key=' . rawurlencode($key);
}

/** 문자열에서 키 값(원문·URL 인코딩)을 *** 로 (순수 함수) — 오류·로그 출력 전에 반드시 */
function kcsc_mask(string $text, string $key): string
{
    if ($key === '') {
        return $text;
    }
    return str_replace([$key, rawurlencode($key), urlencode($key)], '***', $text);
}

/** 키가 틀렸을 때의 '모든 값 null 1건' 응답인지 (순수 함수) */
function kcsc_is_null_sentinel(mixed $data): bool
{
    $first = is_array($data) && array_is_list($data) ? ($data[0] ?? null) : $data;
    if (!is_array($first) || $first === []) {
        return is_array($data) && $data === [];
    }
    return ($first['codeType'] ?? null) === null && ($first['code'] ?? null) === null && ($first['name'] ?? null) === null;
}

/** 본문 응답 → 문서 1건(한 원소 배열이면 풀기) (순수 함수) */
function kcsc_document(mixed $data): ?array
{
    $doc = is_array($data) && array_is_list($data) ? ($data[0] ?? null) : $data;
    return is_array($doc) ? $doc : null;
}

/** HTML → 평문 (순수 함수) — 표 칸은 ' | ', 줄바꿈 정리. 수식 이미지(base64 GIF)는 '[수식 이미지]' 로 */
function kcsc_plain(string $html): string
{
    $t = (string) preg_replace('/<img[^>]*>/i', ' [수식 이미지] ', $html);
    $t = (string) preg_replace('#</(td|th)>#i', ' | ', $t);
    $t = (string) preg_replace('#<br\s*/?>|</(p|tr|li|div)>#i', "\n", $t);
    $t = html_entity_decode(strip_tags($t), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = (string) preg_replace("/[ \t\x{00A0}]+/u", ' ', $t);
    return trim((string) preg_replace("/\n\s*\n+/u", "\n", $t));
}

/** 문서 → 절 목록 [['label','title','level','text'], …] (순수 함수) — sort 순서 */
function kcsc_sections(array $doc): array
{
    $list = is_array($doc['list'] ?? null) ? $doc['list'] : [];
    usort($list, static fn($a, $b) => (int) ($a['sort'] ?? 0) <=> (int) ($b['sort'] ?? 0));
    return array_map(static fn($x) => [
        'label' => trim((string) ($x['label'] ?? '')), 'title' => trim((string) ($x['title'] ?? '')),
        'level' => (int) ($x['level'] ?? 0), 'text' => kcsc_plain((string) ($x['contents'] ?? '')),
    ], $list);
}

/** 절 중 낱말이 들어간 것 (순수 함수) — [['label','title','snippet'], …], 낱말 앞뒤 80자 */
function kcsc_grep(array $sections, string $word, int $limit = 20): array
{
    $out = [];
    foreach ($sections as $s) {
        $hay = $s['title'] . ' ' . $s['text'];
        $p = mb_stripos($hay, $word);
        if ($p === false) {
            continue;
        }
        $out[] = ['label' => $s['label'], 'title' => $s['title'], 'snippet' => trim(mb_substr($hay, max(0, $p - 80), mb_strlen($word) + 160))];
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/** 목록에서 이름에 낱말이 모두 들어간 기준 (순수 함수) — [['type','code','name','version','updated'], …] */
function kcsc_find(array $list, array $words, ?string $type = null): array
{
    $out = [];
    foreach ($list as $r) {
        if (!is_array($r) || ($type !== null && ($r['codeType'] ?? '') !== $type)) {
            continue;
        }
        $name = (string) ($r['name'] ?? '');
        if ($name === '' || array_filter($words, static fn($w) => $w !== '' && mb_stripos($name, $w) === false)) {
            continue;
        }
        $code = (string) ($r['code'] ?? '');
        $out[] = ['type' => (string) ($r['codeType'] ?? ''), 'code' => $code, 'display' => trim(($r['codeType'] ?? '') . ' ' . implode(' ', str_split($code, 2))),
                  'name' => $name, 'version' => (string) ($r['version'] ?? ''), 'updated' => substr((string) ($r['updateDate'] ?? ''), 0, 10)];
    }
    return $out;
}

/**
 * 판·갱신일 재확인 (순수 함수) — 목록(CodeList)과 기준값(api_targets.json kcsc_documents) 비교.
 * status: same(판·갱신일 같음) / updated(판은 같고 갱신일만 바뀜 — 본문 일부 수정 가능) / new_version(판이 바뀜) / missing(목록에 없음)
 */
function kcsc_recheck(array $list, array $targets): array
{
    $idx = [];
    foreach ($list as $r) {
        if (is_array($r) && isset($r['codeType'], $r['code'])) { $idx[$r['codeType'] . '/' . $r['code']] = $r; }
    }
    $out = [];
    foreach ($targets as $t) {
        $r = $idx[$t['type'] . '/' . $t['code']] ?? null;
        $now = ['version' => (string) ($r['version'] ?? ''), 'updated' => substr((string) ($r['updateDate'] ?? ''), 0, 10)];
        $status = $r === null ? 'missing' : ($now['version'] !== (string) $t['version'] ? 'new_version' : ($now['updated'] !== (string) $t['updated'] ? 'updated' : 'same'));
        $out[] = ['display' => $t['type'] . ' ' . implode(' ', str_split((string) $t['code'], 2)), 'name' => (string) $t['name'], 'status' => $status,
                  'base' => ['version' => (string) $t['version'], 'updated' => (string) $t['updated']], 'now' => $now, 'clauses' => (array) ($t['clauses'] ?? [])];
    }
    return $out;
}
