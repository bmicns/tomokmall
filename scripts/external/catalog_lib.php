<?php
declare(strict_types=1);
/**
 * 카탈로그 글(PDF 에서 뽑은 글) → 수집기 레코드 (순수 함수). 사용: scripts/external/catalog-extract.php
 * 레코드 형식은 수집기(ext_page_records)와 같다: name, spec, maker, category, note, source_url, facts.
 * 원문을 고쳐 쓰지 않는다(문장 분류만), 가격 줄은 버린다.
 */

/** 쪽 나누기 (순수 함수) — pdftotext 의 쪽 구분(\f) */
function catalog_pages(string $text): array
{
    return array_values(array_filter(array_map('trim', explode("\f", str_replace("\r", '', $text))), static fn($p) => $p !== ''));
}

/** 제품 제목 줄 (순수 함수) — 자재 종류가 읽히는 40자 이하 줄 중 첫 줄. 치수만 있는 줄·가격 줄은 제외 */
function catalog_heading(array $lines): string
{
    foreach ($lines as $l) {
        $l = trim((string) preg_replace('/\s{2,}/u', ' ', $l));
        if ($l === '' || mb_strlen($l) > 40 || preg_match('/^[\d\s.*x××\/-]+$/u', $l) || catalog_is_price($l)) { continue; }
        if (ext_product_type(str_replace(['플룸', '블럭'], ['플륨', '블록'], $l)) !== '') { return $l; }
    }
    return '';
}

/** 가격 줄인지 (순수 함수) — 원·단가·가격·₩ */
function catalog_is_price(string $l): bool
{
    return preg_match('/[\d,]+\s*원(?![가-힣])|단가|가격|₩|\bprice\b/iu', $l) === 1;
}

/** 표 행 칸 나누기 (순수 함수) — pdftotext -layout 의 공백 2칸 이상·탭 */
function catalog_cells(string $l): array
{
    return array_values(array_filter(array_map('trim', preg_split('/\s{2,}|\t/u', trim($l)) ?: []), static fn($c) => $c !== ''));
}

/** 치수 표기 칸인지 (순수 함수) — 200x200·D300·60mm·80T */
function catalog_is_dim(string $c): bool
{
    return preg_match('/\d+(?:\.\d+)?\s*[*x×]\s*\d+|(?:^|[^a-z])[DΦø]\s*\d{2,4}|\d{2,4}\s*(?:mm|T)\b/iu', $c) === 1;
}

/**
 * 규격 줄 (순수 함수) — 칸이 2개 이상이고 치수 표기가 있는 표 행. [[첫 칸(이름), [치수 칸…], [기타 칸…]], …]
 * 바로 위의 치수 없는 여러 칸 행을 머리글로 보고, 칸 수가 같으면 칸마다 머리글을 붙인다('두께 60mm', '용도: 보도').
 * 가격 칸(머리글이 단가·가격이거나 값이 ○○원)은 뺀다. 글 한 줄(칸 1개)이 나오면 머리글을 잊는다(다음 표).
 */
function catalog_spec_rows(array $lines): array
{
    $out = [];
    $head = [];
    foreach ($lines as $l) {
        $cells = catalog_cells($l);
        if (count($cells) < 2) {
            if (trim($l) !== '') { $head = []; }
            continue;
        }
        if (catalog_is_price($l) && count($cells) < 3) { continue; }
        if (!array_filter($cells, static fn($c) => !catalog_is_price($c) && catalog_is_dim($c))) { $head = $cells; continue; }   // 치수 없는 여러 칸 행 = 머리글 후보
        $aligned = count($head) === count($cells);
        $first = preg_match('/\d+(?:\.\d+)?\s*[*x×]\s*\d+/u', $cells[0]) ? '' : $cells[0];
        $dims = [];
        $notes = [];
        foreach ($cells as $i => $c) {
            if ($i === 0 && $first !== '') { continue; }   // 첫 칸은 이름(호칭·구분)
            if (catalog_is_price($c) || $aligned && catalog_is_price($head[$i])) { continue; }
            $h = $aligned ? $head[$i] : '';
            if (catalog_is_dim($c)) { $dims[] = ($h !== '' && !str_contains($c, $h) ? "{$h} " : '') . $c; }
            elseif ($h !== '') { $notes[] = "{$h}: {$c}"; }
        }
        if (!$dims && $first !== '' && catalog_is_dim($first)) { $dims[] = $first; }   // 치수가 첫 칸(호칭 D300)뿐인 행
        if (!$dims) { continue; }
        $out[] = [$first, $dims, $notes];
    }
    return $out;
}

/** 쪽 글 → 문단 블록(줄바꿈으로 끊긴 문장 잇기) (순수 함수) — ext_page_facts 입력 형식 [['text', 문단], …] */
function catalog_blocks(array $lines): array
{
    $blocks = [];
    $buf = [];
    foreach ($lines as $l) {
        $t = trim((string) preg_replace('/\s{2,}/u', ' ', $l));
        if ($t === '' || preg_match('/\S\s{2,}\S/u', $l)) {   // 빈 줄·표 행(칸 구분)에서 문단 끊기
            if ($buf) { $blocks[] = ['text', implode(' ', $buf)]; $buf = []; }
            continue;
        }
        if (catalog_is_price($t)) { continue; }
        $buf[] = $t;
    }
    if ($buf) { $blocks[] = ['text', implode(' ', $buf)]; }
    return $blocks;
}

/**
 * 카탈로그 글 → 수집기 레코드 (순수 함수).
 * 제목이 있는 쪽만: 규격 줄마다 1건(이름 = 제목 + 첫 칸, 규격 = 머리글 붙은 치수 칸, 비고 = 기타 칸), 규격 줄이 없고 원문 문장이 있으면
 * 쪽당 1건(규격 = 쪽의 치수 표기). 쪽의 원문 문장(facts)은 그 쪽 레코드에 모두 붙는다. 출처 = <카탈로그 파일명>#p<쪽>.
 */
function catalog_records(string $text, string $maker, string $sourceBase): array
{
    $records = [];
    foreach (catalog_pages($text) as $i => $page) {
        $lines = preg_split('/\n/u', $page) ?: [];
        $title = catalog_heading($lines);
        if ($title === '') { continue; }
        $facts = ext_page_facts(catalog_blocks($lines));
        $url = $sourceBase . '#p' . ($i + 1);
        $rows = catalog_spec_rows($lines);
        foreach ($rows as [$first, $dims, $notes]) {
            $name = $first !== '' && !str_contains($title, $first) ? "{$title} {$first}" : $title;
            $records[] = ['name' => $name, 'spec' => implode(' / ', $dims), 'maker' => $maker, 'category' => $title, 'note' => implode(' / ', $notes), 'source_url' => $url, 'facts' => $facts];
        }
        if (!$rows && $facts) {
            preg_match_all('/\d+(?:\.\d+)?\s*[*x×]\s*\d+(?:\.\d+)?(?:\s*[*x×]\s*\d+(?:\.\d+)?)?\s*(?:T|mm)?/u', $page, $m);
            $records[] = ['name' => $title, 'spec' => implode(' / ', array_slice(array_values(array_unique(array_map('trim', $m[0]))), 0, 6)), 'maker' => $maker,
                          'category' => $title, 'note' => '', 'source_url' => $url, 'facts' => $facts];
        }
    }
    return $records;
}
