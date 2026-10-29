<?php
declare(strict_types=1);
/**
 * 공공데이터포털(data.go.kr) OpenAPI — 공식 명세 읽기·응답 해석 (순수 함수 + 파일 읽기)
 *
 * 책임: 공식 상세 페이지(HTML, swagger JSON 포함 시)와 참고문서(DOCX)에서 서비스 URL·오퍼레이션·요청변수(필수/옵션·샘플값)·
 *       출력 항목(영문·국문명)·이용허락범위·트래픽을 뽑아 명세 JSON 으로 만든다. API 응답(JSON·XML·오류 응답)에서
 *       결과코드·전체 건수·품목 목록을 꺼내고, 출력 항목의 국문명으로 표준 키(name·spec·maker·price…)를 정한다.
 * 금지: 명세에 없는 파라미터·필드를 추측해 쓰기(호출은 scripts/external/g2b-api.php 가 이 명세로만 만든다), 네트워크 호출(여기선 안 함).
 * 사용처: scripts/external/g2b-api.php, tests/external_api.test.php
 */

/** HTML → 문서 순서의 블록 목록: ['text', 문자열] 또는 ['table', [[셀…]…]] (표 안의 글은 표로만) */
function ext_html_blocks(string $html): array
{
    $doc = new DOMDocument();
    $prev = libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8">' . $html, LIBXML_NOWARNING | LIBXML_NOERROR);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    $xp = new DOMXPath($doc);
    $blocks = [];
    $q = '//table[not(ancestor::table)] | //*[(self::h1 or self::h2 or self::h3 or self::h4 or self::h5 or self::p or self::dt or self::dd or self::li or self::strong or self::label or self::span or self::div[not(*)])'
       . ' and not(ancestor::table)]';
    foreach ($xp->query($q) as $n) {
        if ($n->nodeName === 'table') {
            $rows = [];
            foreach ($xp->query('.//tr', $n) as $tr) {
                $cells = [];
                foreach ($xp->query('./th|./td', $tr) as $td) { $cells[] = trim((string) preg_replace('/\s+/u', ' ', $td->textContent)); }
                if ($cells) { $rows[] = $cells; }
            }
            if ($rows) { $blocks[] = ['table', $rows]; }
        } else {
            $t = trim((string) preg_replace('/\s+/u', ' ', $n->textContent));
            if ($t !== '' && mb_strlen($t) <= 500) { $blocks[] = ['text', $t]; }
        }
    }
    return $blocks;
}

/** DOCX(참고문서) → 문서 순서의 블록 목록 (문단·표). 별도 라이브러리 없이 ZipArchive + DOM */
function ext_docx_blocks(string $file): array
{
    $zip = new ZipArchive();
    if ($zip->open($file) !== true) { throw new RuntimeException("DOCX 를 열 수 없습니다: {$file}"); }
    $xml = $zip->getFromName('word/document.xml');
    $zip->close();
    if ($xml === false) { throw new RuntimeException('DOCX 본문(word/document.xml)이 없습니다.'); }
    $doc = new DOMDocument();
    $doc->loadXML($xml, LIBXML_NONET);
    $xp = new DOMXPath($doc);
    $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
    $text = static fn(DOMNode $n): string => trim((string) preg_replace('/\s+/u', ' ', implode('', array_map(static fn($t) => $t->textContent, iterator_to_array($xp->query('.//w:t', $n))))));
    $blocks = [];
    foreach ($xp->query('//w:body/*') as $n) {
        if ($n->localName === 'p') {
            $t = $text($n);
            if ($t !== '') { $blocks[] = ['text', $t]; }
        } elseif ($n->localName === 'tbl') {
            $rows = [];
            foreach ($xp->query('./w:tr', $n) as $tr) {
                $cells = array_map($text, iterator_to_array($xp->query('./w:tc', $tr)));
                if ($cells) { $rows[] = $cells; }
            }
            if ($rows) { $blocks[] = ['table', $rows]; }
        }
    }
    return $blocks;
}

/** 표가 요청변수·출력항목 표인지 — 머리글 줄에서 영문·국문·구분·샘플·설명 열 위치 (아니면 null) */
function ext_param_table_cols(array $rows): ?array
{
    foreach (array_slice($rows, 0, 3) as $i => $r) {
        $c = ['row' => $i, 'en' => null, 'ko' => null, 'req' => null, 'sample' => null, 'desc' => null];
        foreach ($r as $j => $h) {
            $h = str_replace(' ', '', $h);
            if ($c['en'] === null && preg_match('/항목명\(?영문|^영문|^name$|변수명/u', $h)) { $c['en'] = $j; }
            elseif ($c['ko'] === null && preg_match('/항목명\(?국문|^국문|^항목명$/u', $h)) { $c['ko'] = $j; }
            elseif ($c['req'] === null && preg_match('/항목구분|필수|구분/u', $h)) { $c['req'] = $j; }
            elseif ($c['sample'] === null && preg_match('/샘플|예시|sample/iu', $h)) { $c['sample'] = $j; }
            elseif ($c['desc'] === null && preg_match('/설명|description/iu', $h)) { $c['desc'] = $j; }
        }
        if ($c['en'] !== null && ($c['ko'] !== null || $c['desc'] !== null)) { return $c; }
    }
    return null;
}

/**
 * 블록 목록(HTML·DOCX) → 명세. 오퍼레이션 표시(getXxx — '요청주소'·'상세기능명' 등)가 나오면 그 뒤의 변수 표를 그 오퍼레이션에 붙인다.
 * 표 앞 글에 '요청'이 있으면 요청변수, '응답·출력'이면 출력항목, 둘 다 없으면 순서(첫 표 요청, 다음 표 출력).
 */
function ext_api_spec_from_blocks(array $blocks): array
{
    $spec = ['service_url' => '', 'operations' => [], 'license' => '', 'traffic' => ''];
    $cur = null;
    $hint = '';
    $seen = static function (array &$spec, string $name): string {
        if (!isset($spec['operations'][$name])) { $spec['operations'][$name] = ['name' => $name, 'title' => '', 'url' => '', 'request' => [], 'response' => []]; }
        return $name;
    };
    foreach ($blocks as [$type, $val]) {
        $texts = $type === 'text' ? [$val] : array_merge(...$val);
        foreach ($texts as $t) {
            if (preg_match('#(https?://apis\.data\.go\.kr/\d+/[A-Za-z0-9_]+)(?:/(get[A-Z][A-Za-z0-9_]*))?#', $t, $m)) {
                $spec['service_url'] = $spec['service_url'] ?: $m[1];
                if (!empty($m[2])) { $cur = $seen($spec, $m[2]); $spec['operations'][$cur]['url'] = $m[0]; }
            }
            if ($spec['license'] === '' && preg_match('/이용허락범위\s*[:：]?\s*(.{2,80})/u', $t, $m)) { $spec['license'] = trim($m[1]); }
            if (preg_match('/(개발계정|운영계정)\s*[:：]?\s*(일일\s*트래픽\s*[\d,]+)/u', $t, $m) && !str_contains($spec['traffic'], $m[1])) {
                $spec['traffic'] = trim($spec['traffic'] . ' ' . $m[1] . ' ' . $m[2]);
            }
        }
        if ($type === 'text') {
            if (preg_match('/\b(get[A-Z][A-Za-z0-9_]*)\b/', $val, $m) && !preg_match('/^(get|set)[A-Z]\w*\(/', $val)) {
                $cur = $seen($spec, $m[1]);
                if (preg_match('/^(?:[\d.\s)]*)?([가-힣][^(（]*?)\s*[(（]\s*' . preg_quote($m[1], '/') . '/u', $val, $tm)) { $spec['operations'][$cur]['title'] = trim($tm[1]); }
            }
            if (preg_match('/요청\s*(메시지|변수|파라미터|parameter)|request/iu', $val)) { $hint = 'request'; }
            elseif (preg_match('/(응답|출력)\s*(메시지|결과|항목|변수)|response/iu', $val)) { $hint = 'response'; }
            continue;
        }
        foreach ($val as $r) {   // '상세기능명(영문) | getXxx' 같은 정보 표
            if (count($r) >= 2 && preg_match('/상세기능명|오퍼레이션|operation/iu', $r[0]) && preg_match('/^(get[A-Z][A-Za-z0-9_]*)$/', trim($r[1]), $m)) { $cur = $seen($spec, $m[1]); }
            if (count($r) >= 2 && preg_match('/상세기능명\s*\(?국문/u', $r[0]) && $cur !== null) { $spec['operations'][$cur]['title'] = trim($r[1]); }
        }
        $cols = ext_param_table_cols($val);
        if ($cols === null || $cur === null) { continue; }
        $list = [];
        foreach (array_slice($val, $cols['row'] + 1) as $r) {
            $en = trim((string) ($r[$cols['en']] ?? ''));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $en)) { continue; }
            $req = $cols['req'] !== null ? trim((string) ($r[$cols['req']] ?? '')) : '';
            $list[] = ['name' => $en, 'ko' => $cols['ko'] !== null ? trim((string) ($r[$cols['ko']] ?? '')) : '',
                       'required' => (bool) preg_match('/^(1|필수|Y|required)$/iu', $req), 'sample' => $cols['sample'] !== null ? trim((string) ($r[$cols['sample']] ?? '')) : '',
                       'desc' => $cols['desc'] !== null ? mb_substr(trim((string) ($r[$cols['desc']] ?? '')), 0, 200) : ''];
        }
        if (!$list) { continue; }
        $op = &$spec['operations'][$cur];
        $slot = $hint !== '' ? $hint : ($op['request'] ? 'response' : 'request');
        if ($op[$slot]) { $slot = $slot === 'request' ? 'response' : 'request'; }   // 이미 채워졌으면 다른 쪽
        if (!$op[$slot]) { $op[$slot] = $list; }
        unset($op);
        $hint = '';
    }
    foreach ($spec['operations'] as $name => $op) {
        if ($op['url'] === '' && $spec['service_url'] !== '') { $spec['operations'][$name]['url'] = $spec['service_url'] . '/' . $name; }
    }
    $spec['operations'] = array_values($spec['operations']);
    return $spec;
}

/** HTML 안의 swagger/OpenAPI JSON(있으면) — 'swaggerJson = "…"' 문자열 또는 {"swagger"/"openapi" …} 객체 */
function ext_swagger_from_html(string $html): ?array
{
    if (preg_match('/swaggerJson\s*=\s*([\'"`])(.+?)(?<!\\\\)\1\s*;/s', $html, $m)) {
        // 자바스크립트 문자열 안의 JSON: ① 따옴표 문자열로 풀기(\uXXXX 유지) ② 그대로 JSON ③ 역슬래시 제거 순으로 시도
        $inner = $m[1] === '"' ? json_decode('"' . $m[2] . '"') : json_decode('"' . str_replace(["\\'", '"'], ["'", '\\"'], $m[2]) . '"');
        foreach ([is_string($inner) ? $inner : null, $m[2], stripcslashes($m[2])] as $cand) {
            $j = $cand !== null ? json_decode($cand, true) : null;
            if (is_array($j) && isset($j['paths'])) { return $j; }
        }
    }
    foreach (['{"swagger"', '{"openapi"'] as $start) {
        $p = strpos($html, $start);
        if ($p === false) { continue; }
        $depth = 0;
        $inStr = false;
        for ($i = $p, $n = strlen($html); $i < $n; $i++) {
            $ch = $html[$i];
            if ($inStr) { if ($ch === '\\') { $i++; } elseif ($ch === '"') { $inStr = false; } continue; }
            if ($ch === '"') { $inStr = true; } elseif ($ch === '{') { $depth++; } elseif ($ch === '}' && --$depth === 0) {
                $j = json_decode(substr($html, $p, $i - $p + 1), true);
                return is_array($j) && isset($j['paths']) ? $j : null;
            }
        }
    }
    return null;
}

/** swagger 2.0 / OpenAPI 3 → 명세 (GET 오퍼레이션, 요청변수, 200 응답의 항목 — description 을 국문명으로) */
function ext_api_spec_from_swagger(array $sw): array
{
    $base = isset($sw['host']) ? (($sw['schemes'][0] ?? 'https') . '://' . $sw['host'] . ($sw['basePath'] ?? '')) : (string) ($sw['servers'][0]['url'] ?? '');
    $spec = ['service_url' => rtrim($base, '/'), 'operations' => [], 'license' => '', 'traffic' => ''];
    $props = static function ($schema) use (&$props, $sw): array {
        if (!is_array($schema)) { return []; }
        if (isset($schema['$ref'])) {
            $path = explode('/', ltrim(str_replace('#/', '', $schema['$ref']), '/'));
            $schema = array_reduce($path, static fn($c, $k) => is_array($c) ? ($c[$k] ?? null) : null, $sw);
            return $props($schema);
        }
        $out = [];
        foreach (($schema['properties'] ?? []) as $k => $p) {
            $sub = $props($p['items'] ?? $p);
            if ($sub) { $out = array_merge($out, $sub); }
            elseif (!in_array($k, ['header', 'body', 'items', 'item', 'response'], true)) {
                $out[] = ['name' => (string) $k, 'ko' => trim((string) ($p['description'] ?? $p['title'] ?? '')), 'required' => false, 'sample' => (string) ($p['example'] ?? ''), 'desc' => ''];
            }
        }
        return $out;
    };
    foreach (($sw['paths'] ?? []) as $path => $methods) {
        $g = $methods['get'] ?? null;
        if (!$g) { continue; }
        $name = basename((string) $path);
        $req = [];
        foreach (($g['parameters'] ?? []) as $p) {
            $req[] = ['name' => (string) ($p['name'] ?? ''), 'ko' => trim((string) ($p['description'] ?? '')), 'required' => !empty($p['required']),
                      'sample' => (string) ($p['x-example'] ?? $p['example'] ?? $p['default'] ?? $p['schema']['example'] ?? $p['schema']['default'] ?? ''), 'desc' => ''];
        }
        $r200 = $g['responses']['200'] ?? [];
        $schema = $r200['schema'] ?? ($r200['content']['application/json']['schema'] ?? ($r200['content']['*/*']['schema'] ?? null));
        $spec['operations'][] = ['name' => $name, 'title' => trim((string) ($g['summary'] ?? '')), 'url' => $spec['service_url'] . $path, 'request' => $req, 'response' => $props($schema)];
    }
    return $spec;
}

/** 상세 페이지에서 참고문서·명세 파일 링크(다운로드) — [url => 글] */
function ext_doc_links(string $html, string $pageUrl): array
{
    $base = (string) preg_replace('#^(https?://[^/]+).*$#', '$1', $pageUrl);
    $out = [];
    if (preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $m, PREG_SET_ORDER)) {
        foreach ($m as $a) {
            $href = html_entity_decode($a[1], ENT_QUOTES);
            $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags($a[2])));
            if (preg_match('/fileDownload|atchFileId|\.(docx?|hwpx?|pdf|xlsx?)(\?|$)/i', $href) || preg_match('/참고문서|활용가이드|명세/u', $text)) {
                if (preg_match('/^(javascript:|#)/i', $href)) { continue; }
                $out[str_starts_with($href, 'http') ? $href : $base . '/' . ltrim($href, '/')] = $text;
            }
        }
    }
    return $out;
}

/**
 * API 응답 해석(JSON·XML·공공데이터포털 공통 오류) → ['code','msg','total','items'(list<array>)]
 * 정상 '00', 데이터 없음 '03'. 키 오류·트래픽 초과 등은 code·msg 로 돌려준다(호출 쪽이 중단).
 */
function ext_api_response(string $body): array
{
    $r = ['code' => '', 'msg' => '', 'total' => null, 'items' => []];
    $body = trim($body);
    if ($body === '') { $r['msg'] = '빈 응답'; return $r; }
    if ($body[0] === '{' || $body[0] === '[') {
        $j = json_decode($body, true);
        if (!is_array($j)) { $r['msg'] = 'JSON 해석 실패'; return $r; }
        $find = static function (array $a, string $key) use (&$find) {
            foreach ($a as $k => $v) {
                if ($k === $key) { return $v; }
                if (is_array($v) && ($f = $find($v, $key)) !== null) { return $f; }
            }
            return null;
        };
        $r['code'] = (string) ($find($j, 'resultCode') ?? $find($j, 'returnReasonCode') ?? '');
        $r['msg'] = (string) ($find($j, 'resultMsg') ?? $find($j, 'returnAuthMsg') ?? '');
        $t = $find($j, 'totalCount');
        $r['total'] = $t !== null ? (int) $t : null;
        $items = $find($j, 'items');
        if (is_array($items) && isset($items['item'])) { $items = $items['item']; }
        if (is_array($items)) { $r['items'] = array_is_list($items) ? $items : [$items]; }
        return $r;
    }
    $prev = libxml_use_internal_errors(true);
    $x = simplexml_load_string($body, 'SimpleXMLElement', LIBXML_NONET | LIBXML_NOCDATA);
    libxml_use_internal_errors($prev);
    if ($x === false) { $r['msg'] = 'XML 해석 실패'; return $r; }
    $one = static fn(string $q): ?string => ($n = $x->xpath($q)) ? trim((string) $n[0]) : null;
    $r['code'] = (string) ($one('//header/resultCode') ?? $one('//returnReasonCode') ?? '');
    $r['msg'] = (string) ($one('//header/resultMsg') ?? $one('//returnAuthMsg') ?? $one('//errMsg') ?? '');
    $t = $one('//body/totalCount');
    $r['total'] = $t !== null ? (int) $t : null;
    foreach ($x->xpath('//items/item') ?: [] as $it) {
        $row = [];
        foreach ($it->children() as $k => $v) { $row[(string) $k] = trim((string) $v); }
        $r['items'][] = $row;
    }
    return $r;
}

/**
 * 출력 항목(영문·국문) → 표준 키 매핑. 국문명으로 ext_csv_key(품명·규격·업체명·단가·단위·식별번호·분류명…)를 쓰고,
 * 못 찾은 표준 키만 좁은 규칙으로 보충. 같은 표준 키에 여러 항목이 오면 이어 붙인다(가져오기 때). [영문 => 표준 키]
 */
function ext_api_field_map(array $responseFields): array
{
    $map = [];
    foreach ($responseFields as $f) {
        $k = ext_csv_key(str_replace(' ', '', (string) $f['ko']));
        if ($k !== '') { $map[$f['name']] = $k; }
    }
    $loose = ['name' => '/^(세부)?품명$|^(상품|물품|제품)명$/u', 'spec' => '/규격(명|내용)?$|^모델(명)?$/u', 'maker' => '/(제조|업체|계약업체|공급업체)(명)?$/u',
              'price' => '/(계약)?단가$|^가격$/u', 'unit' => '/^단위(명)?$/u', 'external_id' => '/식별번호$/u', 'category' => '/분류명$/u', 'note' => '/인증/u'];
    foreach ($responseFields as $f) {
        if (isset($map[$f['name']])) { continue; }
        foreach ($loose as $k => $re) {
            $ko = str_replace(' ', '', (string) $f['ko']);
            if ($ko !== '' && preg_match($re, $ko) && ($k === 'note' || !in_array($k, $map, true))) { $map[$f['name']] = $k; break; }
        }
    }
    return $map;
}

/** 명세의 오퍼레이션 고르기 — --op 가 있으면 그것, 없으면 페이지 변수(pageNo·numOfRows)가 있는 첫 오퍼레이션 */
function ext_api_pick_op(array $spec, ?string $name): ?array
{
    foreach ($spec['operations'] as $op) {
        if ($name !== null ? $op['name'] === $name : (ext_api_param($op, 'pageNo') && ext_api_param($op, 'numOfRows'))) { return $op; }
    }
    return null;
}

/** 오퍼레이션 요청변수 찾기(대소문자 무시) — 명세에 있는 이름 그대로 돌려줌 */
function ext_api_param(array $op, string $name): ?array
{
    foreach ($op['request'] as $p) { if (strcasecmp($p['name'], $name) === 0) { return $p; } }
    return null;
}

/**
 * 요청 URL 만들기 — 명세에 있는 변수만. 필수 변수는 샘플값(또는 --param), 페이지 변수는 명세에 있을 때만.
 * 'type'(응답 형식)은 명세 설명·샘플에 json 이 있을 때만 json. [URL, 화면 표시용(키 가림), 빠진 필수 변수]
 */
function ext_api_build_url(array $op, string $key, array $over, int $page, int $rows): array
{
    $keyParam = null;
    $q = [];
    $missing = [];
    foreach ($op['request'] as $p) {
        $n = $p['name'];
        if (preg_match('/^servicekey$/i', $n)) { $keyParam = $n; continue; }
        if (array_key_exists($n, $over)) { $q[$n] = $over[$n]; continue; }
        if (strcasecmp($n, 'pageNo') === 0) { $q[$n] = $page; continue; }
        if (strcasecmp($n, 'numOfRows') === 0) { $q[$n] = $rows; continue; }
        if (strcasecmp($n, 'type') === 0 && preg_match('/json/i', $p['sample'] . ' ' . $p['desc'] . ' ' . $p['ko'])) { $q[$n] = 'json'; continue; }
        if ($p['required']) {
            if ($p['sample'] !== '') { $q[$n] = $p['sample']; } else { $missing[] = $n; }
        }
    }
    if ($keyParam === null) { return ['', '', ['serviceKey(명세에 인증키 변수가 없음)']]; }
    $qs = http_build_query($q);
    $keyPart = $keyParam . '=' . (str_contains($key, '%') ? $key : rawurlencode($key));   // Encoding 키(이미 %xx)면 그대로
    $url = $op['url'] . '?' . $keyPart . ($qs !== '' ? '&' . $qs : '');
    return [$url, $op['url'] . '?' . $keyParam . '=****' . ($qs !== '' ? '&' . $qs : ''), $missing];
}

/** 약관 본문에서 자동수집·복제·저작권 관련 문장만 (순수 함수, 최대 6문장) */
function ext_terms_clauses(string $html): array
{
    $text = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags((string) preg_replace('#<(script|style)[^>]*>.*?</\1>#isu', ' ', $html)), ENT_QUOTES, 'UTF-8')));
    $out = [];
    foreach (preg_split('/(?<=[.。다])\s+/u', $text) ?: [] as $sent) {
        if (preg_match('/자동|수집|크롤|스크랩|로봇|복제|전재|무단|저작권|데이터베이스|2차적/u', $sent) && mb_strlen($sent) <= 300) {
            $out[] = $sent;
        }
        if (count($out) >= 6) { break; }
    }
    return $out;
}

/** robots.txt 의 'User-agent: *' 규칙 판정 (순수 함수) */
function ext_robots_check(string $txt): array
{
    $dis = [];
    $active = false;
    foreach (preg_split('/\r\n|\n|\r/', $txt) ?: [] as $line) {
        $line = trim((string) preg_replace('/#.*/', '', $line));
        if (preg_match('/^user-agent:\s*(.+)$/i', $line, $m)) {
            $active = trim($m[1]) === '*';
        } elseif ($active && preg_match('/^disallow:\s*(.*)$/i', $line, $m) && trim($m[1]) !== '') {
            $dis[] = trim($m[1]);
        }
    }
    $all = in_array('/', $dis, true);
    $shop = (bool) array_filter($dis, static fn($d) => preg_match('#^/(shop/?$|shop/(list|item|search)|products?|goods|item)#i', $d));
    return ['disallow' => $dis, 'verdict' => $all ? '전체 수집 금지' : ($shop ? '상품 경로 일부 금지' : ($dis ? '일부 경로만 금지(상품 경로 제외)' : '금지 규칙 없음'))];
}

/** robots.txt 'User-agent: *' 금지 경로에 걸리는지 (순수 함수) — 금지 경로로 시작하면 금지('*'·'$' 간단 처리) */
function ext_robots_allows(array $disallow, string $path): bool
{
    foreach ($disallow as $d) {
        $re = '#^' . str_replace(['\\*', '\\$'], ['.*', '$'], preg_quote($d, '#')) . '#';
        if (preg_match($re, $path)) { return false; }
    }
    return true;
}

/** 약관 문장 중 자동수집·무단 복제를 금지하는 문장(이메일 무단수집 거부 문구는 제외) — 있으면 수집하지 않는다 */
function ext_terms_prohibits(array $clauses): array
{
    return array_values(array_filter($clauses, static fn($c) => !preg_match('/이메일|전자우편|e-?mail/iu', $c)
        && preg_match('/(자동|프로그램|크롤|로봇|스크랩|스크래핑|기계적).{0,30}(수집|복제|추출)|(무단|허락\s*없이|동의\s*없이|승낙\s*없이).{0,20}(수집|복제|전재|배포)/u', $c)));
}
