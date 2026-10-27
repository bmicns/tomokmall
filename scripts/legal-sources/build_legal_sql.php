<?php
declare(strict_types=1);
/**
 * candidates.json(검색 기반 후보) → rag_documents 등록 SQL 초안 생성.
 * 원칙: 원문 미확인이므로 전부 is_active=0, review_status='unreviewed', legal_status='unknown'(폐지는 'repealed'),
 *       시행일·개정일 칸은 비움(원문 확인 후 입력). 본문에는 법령 '내용'을 쓰지 않고 식별 정보만 기록.
 * 실행: php scripts/legal-sources/build_legal_sql.php docs/03-analysis/ai-sourcing/legal-sources/candidates.json \
 *         > docs/03-analysis/ai-sourcing/sql-drafts/02_rag_documents_legal_candidates_DRAFT.sql
 */
if (PHP_SAPI !== 'cli') { exit; }
$rows = json_decode((string) file_get_contents($argv[1] ?? 'candidates.json'), true) ?: [];
$typeMap = ['법령' => 'law', 'KDS' => 'kds', 'KCS' => 'kcs', 'KS' => 'ks'];
$catMap = [
    'pavement' => '/보도|블록|블럭|경계|점자|평판|연석/u', 'culvert' => '/수로|측구|플룸|플륨|U형/u',
    'manhole' => '/맨홀|집수정|빗물받이/u', 'pe_pipe' => '/관|파이프|PE|PVC|흄/u', 'grating' => '/그레이팅/u',
];
$q = static fn(?string $s): string => $s === null || $s === '' ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
echo "-- ⚠️ 초안 — 운영 실행 금지(승인 전). 01_rag_documents_source_columns_DRAFT.sql 적용 후에만 실행 가능.\n";
echo "DRAFT_STOP_승인_전_실행_금지;  -- 안전장치: 이 줄이 있으면 실행 즉시 문법 오류로 중단됩니다. 승인 후 이 줄만 지우고 실행.\n";
echo "-- 생성: scripts/legal-sources/build_legal_sql.php · 원본: candidates.json (" . count($rows) . "건, 웹 검색 기반 후보 · 원문 미확인)\n";
echo "-- 모든 행 is_active=0 → AI 상담에 쓰이지 않음. 원문 확인·검토 후 관리자 화면에서 칸을 채우고 활성화.\n";
echo "-- 기존 5건(id 1~5)은 건드리지 않음(INSERT 만).\n\nSTART TRANSACTION;\n";
foreach ($rows as $r) {
    $type = $typeMap[$r['type']] ?? null;
    if ($type === null) { continue; }
    $mats = is_array($r['related_materials']) ? implode(', ', $r['related_materials']) : (string) $r['related_materials'];
    $cat = null;
    foreach ($catMap as $k => $re) { if (preg_match($re, $r['official_name'] . ' ' . $mats)) { $cat = $k; break; } }
    $repealed = str_contains((string) $r['status'], '폐지');
    $content = "[원문 미확인 후보 — AI 상담 미사용]\n"
        . "문서명: {$r['official_name']}\n번호: {$r['ref_no']}\n발행: {$r['issuing_body']}\n관련 자재: {$mats}\n"
        . "적용 범위(검색 기준): {$r['scope_note']}\n시행·개정일(검색 기준, 미확인): " . ($r['effective_or_revised_date'] ?: '확인 안 됨') . "\n"
        . "검색 근거 신뢰도: {$r['confidence']}\n※ 이 칸에는 원문 확인 후 '상담에 필요한 요지'만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).";
    $title = "[{$r['type']}] " . mb_substr($r['official_name'], 0, 120) . (preg_match('/^(KS|KDS|KCS)/', (string) $r['ref_no']) ? " ({$r['ref_no']})" : '');
    $refShort = mb_substr((string) $r['ref_no'], 0, 100);
    printf("INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)\n"
        . "VALUES (%s, %s, %s, %s, 0, 900, %s, %s, %s, %s, %s, %s, %s, 'unreviewed');\n",
        $q(mb_substr($title, 0, 200)), $q($content), $q($cat), $q(mb_substr($mats, 0, 500)), $q($type),
        $q(mb_substr($r['official_name'], 0, 200)), $q($refShort), $q(mb_substr((string) $r['official_url'], 0, 500)),
        $q(mb_substr((string) $r['scope_note'], 0, 300)), $q($repealed ? 'repealed' : 'unknown'), $q('AI 조사 초안(검색 기반)'));
}
echo "COMMIT;\n\n-- 롤백: DELETE FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND review_status = 'unreviewed';\n";
