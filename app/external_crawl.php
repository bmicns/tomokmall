<?php
declare(strict_types=1);
/**
 * 제조사 공개 제품 페이지 → 사실 정보 추출 (순수 함수, 네트워크 없음)
 *
 * 책임: 제품 페이지 HTML 에서 ① 규격표의 행을 상품 행(품명·규격·재질·강도·KS 등 원문 그대로)으로, ② 본문 문장 중
 *       용도·시공 환경·특징·주의사항·인증·강도 문장을 원문 그대로(최대 200자) 분류하고, ③ 같은 사이트의 제품 페이지 링크를 고른다.
 * 금지: 원문에 없는 내용 생성·요약·추측, 이미지·가격 수집(가격 열은 버림), 개인 연락처 보관(ext_normalize 가 제거).
 * 사용처: scripts/external/crawl-sources.php, tests/external_crawl.test.php
 */

/** 페이지 제목(제품명 후보): h1~h3 첫 글, 없으면 <title> */
function ext_page_title(string $html): string
{
    foreach (['h1', 'h2', 'h3'] as $h) {
        if (preg_match('#<' . $h . '[^>]*>(.*?)</' . $h . '>#isu', $html, $m)) {
            $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8')));
            if ($t !== '' && mb_strlen($t) <= 60) { return $t; }
        }
    }
    return preg_match('#<title[^>]*>(.*?)</title>#isu', $html, $m) ? trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'))) : '';
}

/** 원문 문장 분류: 용도·시공·특징·주의·인증·강도. 해당 없으면 '' */
function ext_fact_kind(string $s): string
{
    return match (true) {
        (bool) preg_match('/주의|유의|금지|하지\s*마|않도록|삼가/u', $s)                         => 'cautions',
        (bool) preg_match('/KS\s*[A-Z]\s*-?\s*\d{4}|KS\s*인증|단체표준|우수조달|성능인증|녹색인증|환경표지/u', $s) => 'certs',
        (bool) preg_match('/강도|MPa|N\/mm|kgf|하중\s*등급|휨|압축/u', $s)                         => 'strength',
        (bool) preg_match('/용도|사용처|적용|적합|설치\s*장소|시공|보도|차도|주차장|광장|공원|조경|하수|배수/u', $s) => 'uses',
        (bool) preg_match('/특징|장점|우수|효과|뛰어|내구|미려|투수|미끄럼|친환경|경제적/u', $s)   => 'features',
        default => '',
    };
}

/** 본문 글 블록 → 분류된 원문 문장 [kind => [문장…]] (같은 문장 중복 제거, 종류별 최대 5개, 200자 이하만) */
function ext_page_facts(array $blocks): array
{
    $out = [];
    $seen = [];
    foreach ($blocks as [$type, $val]) {
        if ($type !== 'text') { continue; }
        foreach (preg_split('/(?<=[.!?。]|다\.)\s+|\s*[·•▶■□◆○●-]\s+/u', $val) ?: [] as $s) {
            $s = trim($s);
            if (mb_strlen($s) < 8 || mb_strlen($s) > 200 || isset($seen[$s])) { continue; }
            $k = ext_fact_kind($s);
            if ($k === '' || count($out[$k] ?? []) >= 5) { continue; }
            $seen[$s] = true;
            $out[$k][] = $s;
        }
    }
    return $out;
}

/** 규격표 머리글 열 역할: name(품명·모델·구분)·spec(치수류)·attr(재질·강도·KS·용도·중량)·skip(가격·단가) */
function ext_spec_col_role(string $h): string
{
    $h = str_replace(' ', '', $h);
    return match (true) {
        (bool) preg_match('/가격|단가|금액|원\)|₩/u', $h)                                            => 'skip',
        (bool) preg_match('/품명|제품명|모델|형식|구분|종류|호칭|품번/u', $h)                         => 'name',
        (bool) preg_match('/규격|치수|크기|size|두께|관경|내경|외경|길이|높이|폭|가로|세로|W|L|H|T\(|D\(/iu', $h) => 'spec',
        (bool) preg_match('/재질|강도|ks|인증|용도|중량|무게|하중|표면|색상|투수/iu', $h)               => 'attr',
        default => '',
    };
}

/**
 * 페이지 → 상품 행 목록(가져오기 표준 키). 규격표 행마다 1개, 규격표가 없고 제목이 우리 취급 종류면 페이지 1개.
 * 모든 행에 그 페이지의 분류 문장(facts)·출처 URL·제조사(출처 이름)를 붙인다.
 */
function ext_page_records(string $html, string $url, string $maker): array
{
    $blocks = ext_html_blocks($html);
    $title = ext_page_title($html);
    $facts = ext_page_facts($blocks);
    $records = [];
    foreach ($blocks as [$type, $rows]) {
        if ($type !== 'table' || count($rows) < 2) { continue; }
        $head = $rows[0];
        $roles = array_map('ext_spec_col_role', $head);
        if (!in_array('spec', $roles, true) || count(array_filter($roles)) < 2) { continue; }   // 치수 열이 있는 표만
        foreach (array_slice($rows, 1) as $r) {
            if (count($r) < 2) { continue; }
            $name = $spec = $note = [];
            foreach ($r as $i => $v) {
                $v = trim((string) $v);
                if ($v === '' || !isset($head[$i])) { continue; }
                match ($roles[$i] ?? '') {
                    'name' => $name[] = $v,
                    'spec' => $spec[] = trim($head[$i]) . ' ' . $v,
                    'attr' => $note[] = trim($head[$i]) . ': ' . $v,
                    default => null,
                };
            }
            if (!$spec) { continue; }
            $nm = $name ? implode(' ', $name) : $title;
            $records[] = ['name' => ($name && $title !== '' && !str_contains(implode(' ', $name), $title) ? $title . ' ' : '') . $nm, 'spec' => implode(' / ', $spec),
                          'maker' => $maker, 'category' => $title, 'note' => implode(' / ', $note), 'source_url' => $url, 'facts' => $facts];
        }
    }
    if (!$records && $title !== '' && ext_product_type($title) !== '' && $facts) {
        preg_match_all('/\d+(?:\.\d+)?\s*[*x×]\s*\d+(?:\.\d+)?(?:\s*[*x×]\s*\d+(?:\.\d+)?)?\s*(?:T|mm)?/u', strip_tags($html), $m);
        $records[] = ['name' => $title, 'spec' => implode(' / ', array_slice(array_unique($m[0]), 0, 6)), 'maker' => $maker, 'category' => $title,
                      'note' => implode(' / ', $facts['certs'] ?? []), 'source_url' => $url, 'facts' => $facts];
    }
    return $records;
}

/**
 * 같은 사이트 링크 고르기 — [제품 페이지 후보 URL 목록, 카탈로그(PDF·HWP) URL 목록]. 이미지·첨부·외부 사이트·로그인·장바구니 제외.
 * 제품 단서(제품·product·item·goods·sub·page_id·uid·pIdx·xcode·규격·카탈로그)가 URL 또는 글에 있는 링크만.
 */
function ext_page_links(string $html, string $pageUrl): array
{
    $p = parse_url($pageUrl);
    $origin = ($p['scheme'] ?? 'http') . '://' . ($p['host'] ?? '') . (isset($p['port']) ? ':' . $p['port'] : '');
    $dirPath = preg_replace('#/[^/]*$#', '/', $p['path'] ?? '/');
    $pages = $docs = [];
    if (!preg_match_all('/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $m, PREG_SET_ORDER)) { return [[], []]; }
    foreach ($m as $a) {
        $href = trim(html_entity_decode($a[1], ENT_QUOTES));
        $text = trim(strip_tags($a[2]));
        if ($href === '' || preg_match('/^(javascript:|mailto:|tel:|#)/i', $href)) { continue; }
        $abs = preg_match('#^https?://#i', $href) ? $href : (str_starts_with($href, '/') ? $origin . $href : $origin . $dirPath . preg_replace('#^\./#', '', $href));
        $abs = (string) preg_replace('/#.*$/', '', $abs);
        $h = parse_url($abs, PHP_URL_HOST);
        if ($h !== ($p['host'] ?? null) || preg_match('/login|logout|member|join|cart|order|mypage|admin|wp-admin|board.*write/i', $abs)) { continue; }
        if (preg_match('/\.(pdf|hwp|hwpx)(\?|$)/i', $abs)) { $docs[$abs] = true; continue; }
        if (preg_match('/\.(jpe?g|png|gif|zip|xlsx?|docx?|pptx?|mp4)(\?|$)/i', $abs)) { continue; }
        if (preg_match('/제품|상품|규격|카탈로그|product|item|goods|sub\d|page_id|uid=|pidx|xcode|catalog|spec|view/iu', $abs . ' ' . $text)) { $pages[$abs] = true; }
    }
    return [array_keys($pages), array_keys($docs)];
}
