<?php
declare(strict_types=1);
/**
 * 운영 법령 근거 반영 SQL 생성기(scripts/legal-sources/prod_sql_lib.php·build_prod_sql.php) 단위 테스트 — DB·네트워크 불필요.
 *
 * 확인: V21·V22 는 활성화 SQL 에 없음, 활성화 조문은 전부 '준비 완료' 집합, 등록 SQL 은 재실행 안전 형태(INSERT … WHERE NOT EXISTS,
 *       전부 비활성), 등록 값은 로컬에서 검증한 초안 02·06 과 같음, 검토자·검토일 없으면 생성 거부, 사후 확인은 읽기 전용,
 *       미러 재확인 비교(같음/바뀜).
 * 실행: php tests/legal_prod_sql.test.php
 */
require __DIR__ . '/../scripts/legal-sources/prod_sql_lib.php';
require __DIR__ . '/../scripts/legal-sources/mirror_recheck.php'; // 함수만(직접 실행이 아니면 바로 return)

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$root = dirname(__DIR__);
$src = "{$root}/docs/03-analysis/ai-sourcing/legal-sources";
$verified = json_decode((string) file_get_contents("{$src}/verified_clauses.json"), true);
$candidates = json_decode((string) file_get_contents("{$src}/candidates.json"), true);
$byId = array_column($verified, null, 'id');
$today = '2026-11-02';

echo "[분류]\n";
$c = prod_classify($verified);
$ids = static fn(array $rows): array => array_map(static fn($r) => (string) $r['id'], $rows);
$ready = $ids($c['ready']);
ok('27건 = 준비 + 확인 필요 + 비활성', count($ready) + count($c['check']) + count($c['inactive']) === count($verified) && count($verified) === 27);
ok('V21·V22 는 비활성 유지 집합', in_array('V21', $ids($c['inactive']), true) && in_array('V22', $ids($c['inactive']), true) && !array_intersect(['V21', 'V22'], $ready));
ok('준비 완료는 로컬 상담 대상(active)·확인 수준 원문/API 만', !array_filter($c['ready'], static fn($r) => empty($r['active']) || !in_array($r['verify_level'], PROD_ACTIVE_LEVELS, true)));
ok('공식 사이트 확인 대기(V25·V26)는 준비 집합에 없음', !array_intersect(array_keys(PROD_NEEDS_OFFICIAL_CHECK), $ready) && $ids($c['check']) === array_keys(PROD_NEEDS_OFFICIAL_CHECK));
ok('로컬 비활성(active=false) 조문은 준비 집합에 없음', !array_intersect($ready, array_keys(array_filter($byId, static fn($r) => empty($r['active'])))));
ok('확인 필요 항목마다 확인 URL(law.go.kr)·확인 내용', !array_filter($c['check'], static fn($r) => !str_starts_with($r['url'], 'https://www.law.go.kr/') || $r['what'] === ''));

echo "[활성화 SQL — 20]\n";
$act = prod_activate_sql($verified, '홍길동', '2026-11-02', $today);
$actBody = (string) preg_replace('/^--.*$/m', '', $act); // 실행되는 SQL(머리말 주석 제외, /* ID */ 표시는 남음)
ok('V21·V22 제목·ID 없음(실행 SQL)', !str_contains($act, prod_verified_title($byId['V21'])) && !str_contains($act, prod_verified_title($byId['V22'])) && !preg_match('/\bV2[12]\b/', $actBody));
ok('공공도로 범위(@scope:public_road) 조문 제목 없음', !array_filter($verified, static fn($r) => str_contains($r['tags'], '@scope:public_road') && str_contains($act, prod_verified_title($r))));
preg_match_all("/\/\* (V\d+) \*\/ '((?:[^']|'')*)'/u", $act, $m);
ok('활성화 ID 는 전부 준비 완료 집합(개수 일치)', $m[1] === $ready);
ok('활성화 제목은 각 ID 의 조문 제목', !array_filter(array_keys($m[1]), static fn($i) => str_replace("''", "'", $m[2][$i]) !== prod_verified_title($byId[$m[1][$i]])));
ok('검증 조문(author)·원문/API 확인·현행만 대상', str_contains($act, "WHERE author = '" . PROD_AUTHOR_VERIFIED . "'") && str_contains($act, "verify_level IN ('original_verified','api_text_verified')") && str_contains($act, "legal_status = 'current'"));
ok('검토자·검토일 기록, 후보(author AI 조사 초안) 미포함', str_contains($act, "reviewer = '홍길동', reviewed_at = '2026-11-02'") && !str_contains($act, PROD_AUTHOR_CANDIDATE));
ok('UPDATE 1문만(INSERT·DELETE·DDL 없음)', substr_count($actBody, 'UPDATE ') === 1 && !preg_match('/\b(INSERT|DELETE|DROP|ALTER|TRUNCATE)\b/', $actBody));
$err = static function (string $rv, string $on) use ($verified, $today): bool {
    try { prod_activate_sql($verified, $rv, $on, $today); return false; } catch (InvalidArgumentException) { return true; }
};
ok('검토자 빈 값 거부', $err('', '2026-11-02') && $err('   ', '2026-11-02'));
ok('검토자 AI·로컬 테스트·자리표시 거부', $err('Claude', '2026-11-02') && $err('로컬 테스트', '2026-11-02') && $err('__REVIEWER__', '2026-11-02'));
ok('검토일 형식·실제 날짜·미래·확인일 이전 거부', $err('홍길동', '2026/11/02') && $err('홍길동', '2026-02-30') && $err('홍길동', '2026-11-03') && $err('홍길동', '2026-10-27'));
ok('작은따옴표 검토자도 SQL 문자열로 이스케이프', str_contains(prod_activate_sql($verified, "O'Neil", '2026-11-02', $today), "reviewer = 'O''Neil'"));

echo "[생성기 CLI — 검토자 인자 없으면 거부]\n";
$tmp = sys_get_temp_dir() . '/legal_prod_sql_test_' . getmypid();
@mkdir($tmp);
$run = static function (string $args) use ($root, $tmp): int {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/scripts/legal-sources/build_prod_sql.php") . ' --out=' . escapeshellarg($tmp) . " --today=2026-11-02 {$args} 2>/dev/null", $o, $code);
    return $code;
};
ok('인자 없음 → 종료 코드 2·파일 생성 없음', $run('') === 2 && glob("{$tmp}/*") === []);
ok('--reviewer 만 → 거부', $run("--reviewer='홍길동'") === 2 && !is_file("{$tmp}/20_activate_ready.sql"));
ok('잘못된 검토일 → 거부', $run("--reviewer='홍길동' --reviewed-on=2026-13-01") === 2 && glob("{$tmp}/*") === []);
ok('--no-activation → 10·30·90·README 만', $run('--no-activation') === 0 && is_file("{$tmp}/10_register_inactive.sql") && !is_file("{$tmp}/20_activate_ready.sql") && is_file("{$tmp}/README.md"));
ok('검토자·검토일 → 20 생성', $run("--reviewer='홍길동' --reviewed-on=2026-11-02") === 0 && str_contains((string) @file_get_contents("{$tmp}/20_activate_ready.sql"), "reviewer = '홍길동'"));
array_map('unlink', glob("{$tmp}/*"));
@rmdir($tmp);

echo "[등록 SQL — 10]\n";
$reg = prod_register_sql($candidates, $verified);
$nCand = count(array_filter(array_map('prod_candidate_row', $candidates)));
ok('66건 = 후보 39 + 검증 조문 27', $nCand === 39 && substr_count($reg, 'INSERT INTO rag_documents') === 66);
ok('모든 INSERT 가 INSERT … SELECT … WHERE NOT EXISTS(author, title)', substr_count($reg, "FROM DUAL\n WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = ") === 66 && !str_contains($reg, 'VALUES ('));
ok('UPDATE·DELETE·DDL 없음', !preg_match('/^\s*(UPDATE|DELETE|DROP|ALTER|TRUNCATE|REPLACE)\b/mi', $reg));
$allRows = array_merge(array_values(array_filter(array_map('prod_candidate_row', $candidates))), array_map('prod_verified_row', $verified));
ok('전부 is_active=0·review_status=unreviewed', !array_filter($allRows, static fn($r) => $r['is_active'] !== '0' || $r['review_status'] !== "'unreviewed'"));
$keys = array_map(static fn($r) => $r['author'] . '|' . $r['title'], $allRows);
ok('중복 판단 키(author, title)가 유일', count(array_unique($keys)) === count($keys));
$d06 = (string) file_get_contents("{$root}/docs/03-analysis/ai-sourcing/sql-drafts/06_rag_documents_verified_clauses_DRAFT.sql");
$d02 = (string) file_get_contents("{$root}/docs/03-analysis/ai-sourcing/sql-drafts/02_rag_documents_legal_candidates_DRAFT.sql");
ok('검증 조문 값 = 초안 06(로컬 검증본)과 같음', !array_filter($verified, static fn($r) => !str_contains($d06, 'VALUES (' . implode(', ', prod_verified_row($r)) . ");\n")));
ok('후보 값 = 초안 02(로컬 검증본)와 같음', !array_filter($candidates, static function ($r) use ($d02) { $row = prod_candidate_row($r); return $row !== null && !str_contains($d02, 'VALUES (' . implode(', ', $row) . ");\n"); }));
ok('V21·V22 도 비활성으로 등록', str_contains($reg, "-- V21\n") && str_contains($reg, "-- V22\n"));

echo "[사후 확인·되돌리기 — 30·90]\n";
$post = prod_post_check_sql($candidates, $verified);
$stmts = array_filter(array_map('trim', preg_split('/;\s*(?:--[^\n]*)?\n/', (string) preg_replace('/^--.*$/m', '', $post))));
ok('30 은 SELECT 만', $stmts !== [] && !array_filter($stmts, static fn($s) => !str_starts_with($s, 'SELECT')));
ok('30 기대값: 등록 66·활성 ' . count($ready) . '·V21/V22 활성 0·source_type 별', str_contains($post, '-- 기대 66') && str_contains($post, '-- 기대 ' . count($ready) . ' (20 적용 전 0)') && str_contains($post, 'v21_v22_active') && str_contains($post, 'kcs=4, kds=5, ks=20, law=37'));
$rb = prod_rollback_sql();
ok('90 의 변경문은 법령 근거 행(author)만 대상', preg_match_all('/^(UPDATE|DELETE)[^\n]*$/m', $rb, $rm) === 2 && !array_filter($rm[0], static fn($s) => !str_contains($s, "author")));

echo "[미러 재확인 비교]\n";
$md = "---\n공포번호: '01604'\n시행일자: 2026-07-27\n---\n##### 제3조 (주차장의 주차구획)\n일반형 2.5미터 이상\n##### 제4조 (다음)\n본문\n";
$base = ['number' => '01604', 'effective' => '2026-07-27', 'articles' => ['3' => law_api_hash("제3조 (주차장의 주차구획)\n일반형 2.5미터 이상")]];
ok('같은 조문·같은 판 → same', law_api_compare($base, mirror_to_api($md, true, ['3']), $today)['status'] === 'same');
ok('조문 내용이 바뀌면 → changed', law_api_compare($base, mirror_to_api(str_replace('2.5미터', '2.6미터', $md), true, ['3']), $today)['status'] === 'changed');
$future = str_replace(["'01604'", '2026-07-27'], ["'01700'", '2026-11-12'], $md);
ok('시행 예정 새 판 → pending(감시 조문 같음 표시)', ($r = law_api_compare($base, mirror_to_api($future, true, ['3']), $today))['status'] === 'pending' && $r['articles']['3']['status'] === 'same');
ok('미러 경로가 api_targets.json 문서 전부에 있음', !array_diff(array_column(json_decode((string) file_get_contents("{$src}/api_targets.json"), true)['documents'], 'key'), array_keys(MIRROR_PATHS)));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
