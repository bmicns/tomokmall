<?php
declare(strict_types=1);
/**
 * 운영 rag_documents 법령 근거 반영 SQL 생성 → docs/03-analysis/ai-sourcing/prod-apply/
 *   10_register_inactive.sql  등록(전부 비활성, 재실행 안전)
 *   20_activate_ready.sql     준비 완료 조문만 활성화 — --reviewer·--reviewed-on 필수(없으면 생성 거부)
 *   30_post_check_readonly.sql 사후 확인(읽기 전용, 기대값 주석)
 *   90_rollback.sql           되돌리기
 *   40_update_ks_metadata.sql·41_update_verified_metadata.sql  이미 등록된 행의 확인 정보만 갱신(KS 후보·1단계 검증 조문)
 *   README.md                 조문 분류표·적용 순서 (<!-- manual:start --> ~ <!-- manual:end --> 사이는 손으로 쓴 내용 — 다시 생성해도 유지)
 * 금지: 운영 DB 접속·실행 없음(파일만 만든다). V21·V22 는 활성화 SQL 에 넣지 않는다(prod_sql_lib.php).
 * 실행:
 *   php scripts/legal-sources/build_prod_sql.php --reviewer='검토자 이름' --reviewed-on=YYYY-MM-DD   (전체)
 *   php scripts/legal-sources/build_prod_sql.php --no-activation                                       (20 제외 — 검토 전)
 *   옵션: --out=<디렉터리> --today=YYYY-MM-DD
 */
if (PHP_SAPI !== 'cli') { exit; }
require __DIR__ . '/prod_sql_lib.php';

$root = dirname(__DIR__, 2);
$src  = "{$root}/docs/03-analysis/ai-sourcing/legal-sources";
$opt  = getopt('', ['reviewer:', 'reviewed-on:', 'no-activation', 'out:', 'today:']);
$out  = rtrim((string) ($opt['out'] ?? "{$root}/docs/03-analysis/ai-sourcing/prod-apply"), '/');
$today = (string) ($opt['today'] ?? date('Y-m-d'));
$noActivation = array_key_exists('no-activation', $opt);

$candidates = json_decode((string) file_get_contents("{$src}/candidates.json"), true);
$verified   = json_decode((string) file_get_contents("{$src}/verified_clauses.json"), true);
if (!is_array($candidates) || !is_array($verified) || $verified === []) {
    fwrite(STDERR, "candidates.json / verified_clauses.json 을 읽지 못했습니다.\n");
    exit(1);
}

$activate = null;
if (!$noActivation) {
    if (!isset($opt['reviewer'], $opt['reviewed-on'])) {
        fwrite(STDERR, "거부: 활성화 SQL 에는 --reviewer='검토자 이름' --reviewed-on=YYYY-MM-DD 가 필요합니다(검토 전이면 --no-activation).\n");
        exit(2);
    }
    try {
        $activate = prod_activate_sql($verified, (string) $opt['reviewer'], (string) $opt['reviewed-on'], $today);
    } catch (InvalidArgumentException | LogicException $e) {
        fwrite(STDERR, '거부: ' . $e->getMessage() . "\n");
        exit(2);
    }
}

if (!is_dir($out) && !mkdir($out, 0777, true)) {
    fwrite(STDERR, "출력 폴더를 만들 수 없습니다: {$out}\n");
    exit(1);
}
file_put_contents("{$out}/10_register_inactive.sql", prod_register_sql($candidates, $verified));
file_put_contents("{$out}/30_post_check_readonly.sql", prod_post_check_sql($candidates, $verified));
file_put_contents("{$out}/90_rollback.sql", prod_rollback_sql());
file_put_contents("{$out}/40_update_ks_metadata.sql", prod_ks_metadata_update_sql($candidates));   // 기존 KS 행의 사용자 확인 적용 범위 갱신(2026-11-05)
file_put_contents("{$out}/41_update_verified_metadata.sql", prod_verified_metadata_update_sql($verified));   // 1단계 등록 행(V25·V26)의 확인 정보 갱신(2026-11-05)
if ($activate !== null) {
    file_put_contents("{$out}/20_activate_ready.sql", $activate);
}

// README — 분류표는 생성, manual 구역은 유지
$c = prod_classify($verified);
$cell = static fn(string $s): string => str_replace(['|', "\n"], ['\\|', ' '], $s);
$lvl = ['original_verified' => '공식 원문(사이트·원문 파일)', 'api_text_verified' => '법제처 OPEN API 대조'];
$md  = "# 운영 반영 — 법령·건설기준 근거 (rag_documents)\n\n"
    . "> 생성: `scripts/legal-sources/build_prod_sql.php` (원본 `legal-sources/verified_clauses.json`·`candidates.json`). 운영 DB 실행은 **매 단계 사용자 승인 후 phpMyAdmin 에서**. 코드 배포 불필요(칸이 생기면 기존 코드가 사용).\n\n"
    . "## 적용 순서 (phpMyAdmin)\n\n"
    . "| 단계 | 파일 | 성격 | 기대 |\n|---|---|---|---|\n"
    . "| 0 | `database/20261102_rag_documents_legal_columns.sql` 머리말의 **사전 점검 SELECT** | 읽기 | 버전 8.0.x, 새 칸 0개, 법령 근거 행 0 |\n"
    . "| 1 | 백업: phpMyAdmin → `rag_documents` 내보내기 | — | 파일 보관 |\n"
    . "| 2 | `database/20261102_rag_documents_legal_columns.sql` | **구조 변경 승인** | 칸 16개·색인 2개 추가(재실행 안전) |\n"
    . "| 3 | `10_register_inactive.sql` | **데이터 변경 승인** | " . (count(array_filter(array_map('prod_candidate_row', $candidates))) + count($verified)) . "행 추가, 전부 비활성(재실행 시 0행) |\n"
    . "| 4 | `30_post_check_readonly.sql` | 읽기 | 활성 0 · 등록 건수 일치 |\n"
    . "| 5 | 아래 (ii) 공식 사이트 확인(사용자) | — | 확인되면 `prod_sql_lib.php` 의 `PROD_NEEDS_OFFICIAL_CHECK` 에서 빼고 다시 생성 |\n"
    . "| 6 | `20_activate_ready.sql` 생성: `php scripts/legal-sources/build_prod_sql.php --reviewer='검토자' --reviewed-on=YYYY-MM-DD` → 내용 검토 → 실행 | **데이터 변경 승인** | " . count($c['ready']) . "행 변경 |\n"
    . "| 7 | `30_post_check_readonly.sql` | 읽기 | 각 줄 주석의 기대값 |\n"
    . "| 8 | 운영 상담 확인: 공공도로·공공 보도·사유지(마당)·주차장 질문 | 사용자 | 공공도로는 수치 단정 없이 설계도서 확인, 보도 차량 출입부는 보도 지침, 사유지는 80T 안내 |\n\n"
    . "문제가 있으면 `90_rollback.sql` ([A] 활성화 해제 → [B] 등록 삭제 → [C] 칸 삭제).\n\n"
    . "## (i) 검증 완료 — 활성화 대상 " . count($c['ready']) . "건\n\n| ID | 근거 | 조항 | 확인 방법 | 확인일 |\n|---|---|---|---|---|\n";
foreach ($c['ready'] as $r) {
    $md .= "| {$r['id']} | " . $cell($r['doc_name'] . ' (' . $r['doc_kind'] . ')') . ' | ' . $cell($r['article']) . ' | ' . ($lvl[$r['verify_level']] ?? $r['verify_level']) . " | {$r['checked_at']} |\n";
}
$md .= "\n## (ii) 공식 사이트 최종 확인 필요 — 확인 전 비활성 " . count($c['check']) . "건\n\n| ID | 법령 · 조항 | 확인할 곳 | 확인할 내용 | 이유 |\n|---|---|---|---|---|\n";
foreach ($c['check'] as $r) {
    $md .= "| {$r['id']} | " . $cell($r['doc_name'] . ' ' . $r['article']) . " | {$r['site']}: {$r['url']} | " . $cell($r['what']) . ' | ' . $cell($r['why']) . " |\n";
}
$md .= "\n## (iii) 비활성 유지 " . count($c['inactive']) . "건 (등록만)\n\n| ID | 근거 · 조항 | 이유 |\n|---|---|---|\n";
foreach ($c['inactive'] as $r) {
    $md .= "| {$r['id']} | " . $cell($r['doc_name'] . ' ' . $r['article']) . ' | ' . $cell($r['_reason']) . " |\n";
}
$md .= "\n후보 " . count(array_filter(array_map('prod_candidate_row', $candidates))) . "건(author '" . PROD_AUTHOR_CANDIDATE . "')은 전부 비활성 등록만(활성화 SQL 없음). 사내 문서(80T 안내 3문구 포함)는 변경 없음.\n\n";
$manual = "<!-- manual:start -->\n<!-- manual:end -->\n";
if (is_readable("{$out}/README.md") && preg_match('/<!-- manual:start -->.*?<!-- manual:end -->\n?/su', (string) file_get_contents("{$out}/README.md"), $m)) {
    $manual = rtrim($m[0], "\n") . "\n";
}
file_put_contents("{$out}/README.md", $md . $manual);

echo '생성: 10·30·90·README' . ($activate !== null ? '·20' : ' (20 제외: --no-activation)') . " → {$out}\n";
echo '분류: 활성화 대상 ' . count($c['ready']) . ' · 공식 확인 필요 ' . count($c['check']) . ' · 비활성 유지 ' . count($c['inactive']) . "\n";
