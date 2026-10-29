<?php
declare(strict_types=1);
/**
 * verified_clauses.json(원문 조문 확인된 법령 근거) → rag_documents 등록 SQL 초안 + 로컬 테스트 활성화 SQL.
 *
 * 원칙: 등록은 전부 is_active=0·review_status='unreviewed'(운영에서 실행해도 상담에 쓰이지 않음).
 *       활성화 SQL은 로컬 테스트 DB 전용(검토자='로컬 테스트' — 전문가 검토 아님). MySQL 8.0.46 호환.
 *       조문 전문은 넣지 않고 상담용 요약·조건·출처만 넣는다.
 * 실행: php scripts/legal-sources/build_verified_sql.php [json] [out-dir]
 *   → 06_rag_documents_verified_clauses_DRAFT.sql, 07_LOCAL_TEST_ONLY_activate_verified_clauses.sql
 */
if (PHP_SAPI !== 'cli') { exit; }
$root = dirname(__DIR__, 2);
$src  = $argv[1] ?? "{$root}/docs/03-analysis/ai-sourcing/legal-sources/verified_clauses.json";
$out  = rtrim($argv[2] ?? "{$root}/docs/03-analysis/ai-sourcing/sql-drafts", '/');
$rows = json_decode((string) file_get_contents($src), true) ?: [];
$q = static fn(?string $s): string => $s === null || $s === '' ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
$AUTHOR = '검증 조문(2-1단계)';
$STOP = "DRAFT_STOP_승인_전_실행_금지;  -- 안전장치: 이 줄이 있으면 실행 즉시 문법 오류로 중단됩니다. 승인 후 이 줄만 지우고 실행.\n";

$sql  = "-- ⚠️ 초안 — 운영 실행 금지(승인 전). 01_rag_documents_source_columns_DRAFT.sql 적용 후에만 실행 가능. MySQL 8.0.46 호환.\n" . $STOP;
$sql .= "-- 생성: scripts/legal-sources/build_verified_sql.php · 원본: verified_clauses.json (" . count($rows) . "건, 원문 조문 확인된 법령 근거)\n";
$sql .= "-- 전부 is_active=0, review_status='unreviewed' — 등록만으로는 AI 상담에 쓰이지 않음. 기존 5건·후보 38건은 건드리지 않음(INSERT 만).\n\nSTART TRANSACTION;\n";
$act  = "-- ⚠️ 로컬 테스트 DB(*_test) 전용 — 운영 실행 금지. 검토자 표기는 '로컬 테스트(전문가 검토 아님)'.\n" . $STOP;
$act .= "-- 06 등록분 중 상담 연결 대상(active=true)만 활성화. 생성: scripts/legal-sources/build_verified_sql.php\n";
$actTitles = [];
foreach ($rows as $r) {
    $title = '[법령] ' . $r['doc_name'] . ' ' . $r['article'];
    $content = "요지: {$r['summary']}\n"
        . '적용 조건: ' . implode(' / ', $r['conditions']) . "\n"
        . ($r['not_for'] ? "주의: {$r['not_for']}\n" : '')
        . ($r['ai_guidance'] ? "상담 안내 예: {$r['ai_guidance']}\n" : '')
        . "확인: {$r['verify_source']} · {$r['checked_at']}";
    $sql .= sprintf(
        "INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)\n"
        . "VALUES (%s, %s, %s, %s, 0, 800, 'law', %s, %s, %s, %s, %s, %s, 'current', %s, 'unreviewed', %s, %s, %s);\n",
        $q(mb_substr($title, 0, 200)), $q($content), $q($r['category'] ?: null), $q(mb_substr($r['tags'], 0, 500)),
        $q(mb_substr($r['doc_name'] . ' (' . $r['doc_kind'] . ')', 0, 200)), $q(mb_substr($r['article'], 0, 100)), $q($r['official_url']),
        $q($r['effective']), $q($r['promulgated']), $q(mb_substr($r['applies_to'], 0, 300)), $q($AUTHOR),
        $q($r['verify_level']), $q(mb_substr($r['checked_by'], 0, 100)), $q($r['checked_at'])
    );
    if (!empty($r['active'])) {
        $actTitles[] = $q(mb_substr($title, 0, 200));
    }
}
$sql .= "COMMIT;\n\n-- 롤백: DELETE FROM rag_documents WHERE author = " . $q($AUTHOR) . " AND review_status = 'unreviewed';\n";
$act .= "UPDATE rag_documents SET is_active = 1, review_status = 'reviewed', reviewer = '로컬 테스트(Claude) — 전문가 검토 아님', reviewed_at = CURDATE()\n"
    . " WHERE author = " . $q($AUTHOR) . " AND title IN (\n  " . implode(",\n  ", $actTitles) . "\n);\n"
    . "-- 되돌리기: UPDATE rag_documents SET is_active = 0, review_status = 'unreviewed', reviewer = NULL, reviewed_at = NULL WHERE author = " . $q($AUTHOR) . ";\n";
file_put_contents("{$out}/06_rag_documents_verified_clauses_DRAFT.sql", $sql);
file_put_contents("{$out}/07_LOCAL_TEST_ONLY_activate_verified_clauses.sql", $act);
echo count($rows) . "건 등록 초안, " . count($actTitles) . "건 로컬 활성화\n";
