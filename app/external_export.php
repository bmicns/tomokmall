<?php
declare(strict_types=1);
/**
 * 승인된 외부 자재 자료 → 운영 이관 SQL (순수 함수)
 *
 * 책임: ① 이관 전 행 검증(ext_export_issues — 출처 권한·도메인, 원문 문장 재판정, 개인 연락처, 메뉴 글자, 다른 제품 설명 문장, 긴 인용, 문장 중복·종류 섞임)
 *       ② MySQL 문자열 이스케이프 · 중복 없이 다시 실행해도 되는 INSERT 문 · 운영 사후 확인용 지문(fingerprint)
 * 금지: DB 접속(스크립트 scripts/external/export-approved.php 가 읽어서 넘김), 문장 수정·요약, 매칭(products ID 는 로컬·운영이 다름) 이관.
 */
require_once __DIR__ . '/external_products.php';
require_once __DIR__ . '/external_crawl.php';

const EXT_EXPORT_MAX_FACT_LEN = 200;   // '짧은 원문 문장' 범위 — 넘으면 전문 복제로 보고 이관하지 않음
const EXT_EXPORT_COLUMNS = ['source_key', 'external_id', 'dedup_key', 'name', 'maker', 'product_type', 'spec_raw', 'width_mm', 'length_mm', 'thickness_mm',
    'diameter_mm', 'material', 'attrs_json', 'unit', 'ref_price', 'price_basis', 'source_url', 'license_note', 'fetched_at', 'review_status'];

/** 문장이 설명하는 제품 종류가 행 종류와 다른가 (바닥 포장 블록끼리·같은 계열은 같은 것으로 봄) */
function ext_fact_type_conflict(string $fact, string $rowType): string
{
    $about = ext_fact_subject($fact, '');
    if ($about === '' || $rowType === '') { return ''; }
    $t = ext_product_type($about);
    $family = static fn(string $x): string => match ($x) {
        '인조화강블록', '보도블록', '인터로킹블록', '보차도블록', '점토블록', '잔디블록' => 'paving',
        '흄관', 'PE관', 'PVC관' => 'pipe',
        default => $x,
    };
    return ($t !== '' && $family($t) !== $family($rowType)) ? "다른 종류 제품 설명 문장('{$about}' = {$t}, 행 종류 {$rowType})" : '';
}

/**
 * 이관 전 행 검증 — [ 'block' => [사유…](하나라도 있으면 이관 안 함), 'warn' => [사유…] ]
 * $row: external_products 행(attrs_json 문자열 포함) · $src: sources.json 의 해당 출처 · $factOwners: [문장 => [행ID => 종류]] (전체 승인 행)
 */
function ext_export_issues(array $row, ?array $src, array $factOwners = []): array
{
    $block = $warn = [];
    if ($src === null) { $block[] = '출처 등록부에 없는 source_key'; }
    elseif (!in_array($src['permission'] ?? '', ['facts_if_clear', 'allowed'], true)) { $block[] = "이관할 수 없는 출처 권한({$src['permission']})"; }
    if (($row['review_status'] ?? '') !== 'approved') { $block[] = '승인(approved) 행이 아님'; }
    $host = strtolower((string) parse_url((string) ($row['source_url'] ?? ''), PHP_URL_HOST));
    $dom = strtolower(preg_replace('/^www\./', '', (string) ($src['domain'] ?? '')));
    if ($src !== null && $dom !== '' && ($host === '' || !str_ends_with(preg_replace('/^www\./', '', $host), $dom))) {
        $warn[] = "출처 URL 도메인({$host})이 등록부 도메인({$dom})과 다름 — 홈페이지 이동(리디렉션) 여부 확인";   // 수집기는 같은 호스트만 따라가므로 차이는 리디렉션 — 사람이 확인
    }
    if (ext_is_nav_label((string) ($row['name'] ?? ''))) { $block[] = '행 이름이 사이트 메뉴 글자'; }
    if (($src['permission'] ?? '') !== 'allowed' && ($row['ref_price'] ?? null) !== null && (string) $row['ref_price'] !== '') {
        $warn[] = '제조사 공개 자료의 참고가 — 이관 때 비움(가격은 쓰지 않음)';
    }
    $a = json_decode((string) ($row['attrs_json'] ?? ''), true);
    if (!is_array($a)) { $block[] = 'attrs_json 형식 오류'; $a = []; }
    $facts = array_merge([], ...array_values(array_map('array_values', (array) ($a['facts'] ?? []))));
    if (!$facts) { $block[] = '승인 문장 없음(상담에 쓸 내용 없음)'; }
    $whole = implode(' ', [(string) ($row['name'] ?? ''), (string) ($row['spec_raw'] ?? ''), (string) ($row['attrs_json'] ?? '')]);
    if (ext_scrub_pii($whole)[1] > 0) { $block[] = '전화번호·이메일 포함(개인 연락처 가능)'; }
    foreach ($facts as $t) {
        $t = (string) $t;
        $short = mb_substr($t, 0, 30) . (mb_strlen($t) > 30 ? '…' : '');
        if (($why = ext_fact_review($t, array_map('strval', $facts))) !== '') { $block[] = "현재 규칙으로 보류되는 문장('{$short}' — {$why}) → 다시 승인 필요"; }
        if (mb_strlen($t) > EXT_EXPORT_MAX_FACT_LEN) { $block[] = "긴 인용('{$short}' " . mb_strlen($t) . '자 > ' . EXT_EXPORT_MAX_FACT_LEN . ')'; }
        if (($c = ext_fact_type_conflict($t, (string) ($row['product_type'] ?? ''))) !== '') { $block[] = "{$c} — '{$short}'"; }
        $owners = $factOwners[$t] ?? [];
        unset($owners[(int) ($row['id'] ?? 0)]);
        if ($owners) {
            $types = array_unique(array_filter(array_values($owners)));
            if (array_diff($types, [(string) ($row['product_type'] ?? '')])) { $block[] = "같은 문장이 다른 종류 행에도 있음('{$short}' — " . implode('·', $types) . ')'; }
            else { $warn[] = "같은 문장이 다른 행에도 있음('{$short}', 상담에서는 한 번만 사용)"; }
        }
    }
    return ['block' => array_values(array_unique($block)), 'warn' => array_values(array_unique($warn))];
}

/** [문장 => [행ID => 종류]] — 승인 행 전체의 문장 소유 관계(중복·섞임 판정용) */
function ext_fact_owners(array $rows): array
{
    $own = [];
    foreach ($rows as $r) {
        $a = json_decode((string) ($r['attrs_json'] ?? ''), true) ?: [];
        foreach ((array) ($a['facts'] ?? []) as $list) {
            foreach ((array) $list as $t) { $own[(string) $t][(int) ($r['id'] ?? 0)] = (string) ($r['product_type'] ?? ''); }
        }
    }
    return $own;
}

/** MySQL 문자열 리터럴(mysql_real_escape_string 과 같은 규칙, NO_BACKSLASH_ESCAPES 아닌 기본 모드). null → NULL */
function ext_sql_value(mixed $v): string
{
    if ($v === null) { return 'NULL'; }
    if (is_int($v)) { return (string) $v; }
    return "'" . strtr((string) $v, ["\\" => "\\\\", "\0" => "\\0", "\n" => "\\n", "\r" => "\\r", "'" => "\\'", '"' => '\\"', "\x1a" => "\\Z"]) . "'";
}

/** 이관용 행 값 정리 — 숫자 칸은 정수/NULL, 제조사 공개 자료의 참고가는 비움 */
function ext_export_row(array $row, ?array $src): array
{
    $out = [];
    foreach (EXT_EXPORT_COLUMNS as $c) {
        $v = $row[$c] ?? null;
        if (in_array($c, ['width_mm', 'length_mm', 'thickness_mm', 'diameter_mm', 'ref_price'], true)) { $v = ($v === null || $v === '') ? null : (int) $v; }
        $out[$c] = $v;
    }
    if (($src['permission'] ?? '') !== 'allowed') { $out['ref_price'] = null; $out['price_basis'] = null; }
    return $out;
}

/** 다시 실행해도 중복되지 않는 INSERT (같은 source_key·dedup_key 가 있으면 건너뜀) */
function ext_export_insert_sql(array $r): string
{
    $cols = implode(', ', EXT_EXPORT_COLUMNS);
    $vals = implode(', ', array_map('ext_sql_value', array_values($r)));
    return "INSERT INTO external_products ({$cols})\nSELECT {$vals} FROM DUAL\nWHERE NOT EXISTS (SELECT 1 FROM external_products WHERE source_key = "
        . ext_sql_value($r['source_key']) . ' AND dedup_key = ' . ext_sql_value($r['dedup_key']) . ');';
}

/** 운영에서 같은 값으로 계산할 지문 — SHA2(GROUP_CONCAT(source_key:dedup_key:SHA2(attrs_json) 정렬, '|')) 와 같다 */
function ext_export_fingerprint(array $rows): string
{
    $parts = array_map(static fn($r) => $r['source_key'] . ':' . $r['dedup_key'] . ':' . hash('sha256', (string) $r['attrs_json']), $rows);
    sort($parts, SORT_STRING);
    return hash('sha256', implode('|', $parts));
}

/** 운영에서 지문을 계산하는 SELECT (읽기 전용) */
function ext_export_fingerprint_sql(): string
{
    $k = "CONCAT(source_key, ':', dedup_key, ':', SHA2(attrs_json, 256))";   // 정렬은 바이트 순(PHP sort 와 같게) — 대소문자 무시 정렬과 '_' 순서 차이 방지
    return "SET SESSION group_concat_max_len = 1048576;\n"
        . "SELECT COUNT(*) AS approved_rows, SHA2(GROUP_CONCAT({$k} ORDER BY CAST({$k} AS BINARY) SEPARATOR '|'), 256) AS fingerprint\n"
        . "FROM external_products WHERE review_status = 'approved';";
}
