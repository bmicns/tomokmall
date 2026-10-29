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

/** 사이트 이동용 제목('주메뉴'·'전체메뉴'·'본문 바로가기' 등)으로 시작하는지 (순수 함수) — 제품명이 아님 */
function ext_is_nav_label(string $t): bool
{
    return (bool) preg_match('/^\s*((주|메인|전체|서브|하위|빠른|퀵|상단|하단)?\s*메뉴|본문\s*바로\s*가기|바로\s*가기|사이트\s*맵|skip\s*nav|navigation|menu)(\s|$)/iu', $t);
}

/** 페이지 제목(제품명 후보): h1~h3 첫 글(사이트 이동용 제목은 건너뜀), 없으면 <title> */
function ext_page_title(string $html): string
{
    foreach (['h1', 'h2', 'h3'] as $h) {
        preg_match_all('#<' . $h . '[^>]*>(.*?)</' . $h . '>#isu', $html, $all);
        foreach ($all[1] as $inner) {
            $t = trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($inner), ENT_QUOTES, 'UTF-8')));
            if ($t !== '' && mb_strlen($t) <= 60 && !ext_is_nav_label($t)) { return $t; }
        }
    }
    return preg_match('#<title[^>]*>(.*?)</title>#isu', $html, $m) ? trim((string) preg_replace('/\s+/u', ' ', html_entity_decode(strip_tags($m[1]), ENT_QUOTES, 'UTF-8'))) : '';
}

/** 원문 문장 분류: 용도·시공·특징·주의·인증·강도. 해당 없으면 '' */
function ext_fact_kind(string $s): string
{
    return match (true) {
        (bool) preg_match('/주의|유의|금지|하지\s*마|않도록(?!\s*(제작|설계|만든|만들))|삼가/u', $s) => 'cautions',   // '빠지지 않도록 제작' 은 특징
        (bool) preg_match('/KS\s*[A-Z]\s*-?\s*\d{4}|KS\s*인증|단체표준|우수조달|성능인증|녹색인증|환경표지/u', $s) => 'certs',
        (bool) preg_match('/강도|MPa|N\/mm|kgf|하중\s*등급|휨|압축/u', $s)                         => 'strength',
        (bool) preg_match('/용도|사용처|적용|적합|설치\s*장소|시공|보도|차도|주차장|광장|공원|조경|하수|배수/u', $s) => 'uses',
        (bool) preg_match('/특징|장점|우수|효과|뛰어|내구|미려|투수|미끄럼|친환경|경제적|안전/u', $s) => 'features',
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

/**
 * 수집 행 품질 판정 (순수 함수) — 정규화 행 기준. 유효하면 '', 아니면 사유.
 * 원시 행 수를 상품 수로 보지 않기 위해: 이름 없음·머리글 반복·규격 숫자 없음·이름 비정상·취급 종류 아님을 걸러낸다.
 */
function ext_record_issue(array $n): string
{
    $name = trim((string) $n['name']);
    return match (true) {
        $name === '' => '이름 없음',
        (bool) preg_match('/^(구분|품명|제품명|규격|합계|소계|비고|단위|번호|no\.?|순번|항목)$/iu', $name) => '머리글 행',
        mb_strlen($name) > 80 => '이름 비정상(긴 글)',
        !preg_match('/\d/', (string) $n['spec_raw']) => '규격 숫자 없음',
        $n['product_type'] === '' => '취급 종류 아님',
        default => '',
    };
}

/** 원문 문장 정리 (순수 함수) — 회사 정보·저작권·연락처 같은 공통 문구, 출처 페이지 절반 이상에 반복되는 문장(사이트 공통 문구) 제거 */
function ext_clean_page_facts(array $records): array
{
    $pages = [];
    foreach ($records as $r) {
        foreach ((array) ($r['facts'] ?? []) as $list) {
            foreach ((array) $list as $t) { $pages[$t][$r['source_url'] ?? ''] = true; }
        }
    }
    $nPages = max(1, count(array_unique(array_column($records, 'source_url'))));
    $boiler = static fn(string $t): bool => (bool) preg_match('/copyright|all\s*rights|대표\s*(이사|자)?\s*[:：]|주소\s*[:：]|tel|fax|사업자|개인정보|회사\s*소개|오시는\s*길|e-?mail|고객센터|무단\s*전재/iu', $t);
    foreach ($records as &$r) {
        foreach ((array) ($r['facts'] ?? []) as $k => $list) {
            $r['facts'][$k] = array_values(array_filter((array) $list, static fn($t) => !$boiler($t) && !($nPages >= 4 && count($pages[$t] ?? []) >= max(4, (int) ceil($nPages * 0.5)))));
            if (!$r['facts'][$k]) { unset($r['facts'][$k]); }
        }
    }
    unset($r);
    return $records;
}

/** 공식 기준·사내 기준과 충돌할 수 있는 문장 표시 (순수 함수) — 차도·차량용인데 두께가 80mm 미만, 두께 조건 없이 차도 설치 가능. 표시된 문장은 승인 검토 때 확인 */
function ext_fact_conflict(string $t): string
{
    if (preg_match('/((?<!지하)차도|차량|주차)/u', $t) && preg_match('/(\d{2,3})\s*(mm|T)\b/u', $t, $m) && (int) $m[1] < 80 && (int) $m[1] >= 30) {
        return "차량용인데 두께 {$m[1]}mm 표기 — 사내 80T·공식 기준과 충돌 가능";
    }
    if (preg_match('/(?<!지하)차도/u', $t) && preg_match('/(설치|시공|사용|포장)[^.]{0,12}(가능|할\s*수)/u', $t) && !preg_match('/\d{2,3}\s*(mm|T)\b/u', $t)) {
        return '두께 조건 없이 차도 설치 가능 표기 — 차량 80T 이상(공공 차도 100mm) 기준과 함께 확인';
    }
    return '';
}

/**
 * 원문 문장 단위 검토 (순수 함수) — 상담에 쓸 승인 후보면 '', 보류면 사유. 문장은 고치지 않는다(원문 그대로 쓰거나 빼거나).
 * $others: 같은 출처의 다른 문장(긴 문장의 조각 판정용).
 * 보류: 기준 충돌 가능(ext_fact_conflict) / 제목·시공사례 / 메뉴·제품 목록(서술 없음) / 다른 문장의 조각 / 홍보·최상급·비교 표현 / 가격·타사 언급 / 회사 소개·현장명 / 주어 없는 비교 문장.
 */
function ext_fact_review(string $t, array $others = []): string
{
    $t = trim($t);
    $c = ext_fact_conflict($t);
    $spec = (bool) preg_match('/KS\s*[A-Z]\s*-?\s*\d{4}|\d\s*(MPa|N\/㎟|N\/mm|kgf)/u', $t);   // 표준 번호·강도 수치는 서술이 없어도 사실 정보
    foreach ($others as $o) {
        if ($o !== $t && mb_strlen($o) > mb_strlen($t) && mb_strpos($o, $t) !== false) { $sub = true; break; }
    }
    return match (true) {
        $c !== '' => "기준 확인 필요: {$c}",
        (bool) preg_match('/^[◇▣■□◆○●▶★※]|(시공\s*사례|개요|과정|규격)$|^\(.*\)$/u', $t) => '제목·시공사례(내용 없음)',
        isset($sub) => '다른 문장의 일부(중복)',
        (bool) preg_match('/가장|최고|최상|획기적|훌륭|뛰어난|보석|유럽풍|어디에나|어떠한[^.]{0,10}에도|연출|분위기|경관|경쾌|개성/u', $t) => '홍보·최상급 표현(근거 확인 불가)',
        (bool) preg_match('/저렴|가격|단가|원\)|중국산|타사/u', $t) => '가격·타사 언급',
        (bool) preg_match('/에서는[^.]{0,30}(제작|판매|생산)|전문적으로|[가-힣]+구\s+[가-힣]+(아파트|단지|공원)/u', $t) => '회사 소개·현장명',
        // 'ㄷ형강 하부를 보강하여 일반 중하중 그레이팅 보다 더 무거운 차량…'·'기존 벤치플룸관에 비해 대형' — 무엇이(주어) 없이 다른 제품과 비교 → 상담에서 다른 제품 설명으로 옮겨짐
        (bool) preg_match('/보다\s*(더|훨씬)|에\s*비해/u', $t) && !preg_match('/^[^.]*?[가-힣A-Za-z0-9)](은|는)\s[^.]*?(보다\s*(더|훨씬)|에\s*비해)/u', $t) => '비교 대상 제품 불명확(주어 없는 비교 문장)',
        !$spec && !preg_match('/(니다|이다|한다|된다|있다|없다|있음|없음|함|됨|임|음|요망)[.!]?(\s*★[^.]*)?$/u', $t)
            && (mb_strlen($t) < 20 || count(preg_split('/[\/,]/u', $t)) >= 4 || !preg_match('/[가-힣](은|는|이|가|을|를|에|에서|으로|로|와|과|도)\s|[가-힣]며[, ]/u', $t)) => '메뉴·제품명 목록(서술 없음)',
        default => '',
    };
}
