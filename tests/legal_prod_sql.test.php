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
$today = '2026-11-05';   // V36(2026-11-05 확인)이 활성화 준비 — 검토일은 확인일 이후여야 함

echo "[분류]\n";
$c = prod_classify($verified);
$ids = static fn(array $rows): array => array_map(static fn($r) => (string) $r['id'], $rows);
$ready = $ids($c['ready']);
ok('43건 = 준비 + 확인 필요 + 비활성', count($ready) + count($c['check']) + count($c['inactive']) === count($verified) && count($verified) === 43);
ok('V21·V22 는 비활성 유지 집합', in_array('V21', $ids($c['inactive']), true) && in_array('V22', $ids($c['inactive']), true) && !array_intersect(['V21', 'V22'], $ready));
ok('준비 완료는 로컬 상담 대상(active)·확인 수준 원문/API 만', !array_filter($c['ready'], static fn($r) => empty($r['active']) || !in_array($r['verify_level'], PROD_ACTIVE_LEVELS, true)));
ok('공식 사이트 확인 대기 목록(PROD_NEEDS_OFFICIAL_CHECK)은 준비 집합에 없음', !array_intersect(array_keys(PROD_NEEDS_OFFICIAL_CHECK), $ready) && $ids($c['check']) === array_keys(PROD_NEEDS_OFFICIAL_CHECK));
ok('로컬 비활성(active=false) 조문은 준비 집합에 없음', !array_intersect($ready, array_keys(array_filter($byId, static fn($r) => empty($r['active'])))));
ok('확인 필요 항목마다 확인 URL(law.go.kr)·확인 내용', !array_filter($c['check'], static fn($r) => !str_starts_with($r['url'], 'https://www.law.go.kr/') || $r['what'] === ''));

echo "[활성화 SQL — 20]\n";
$act = prod_activate_sql($verified, '홍길동', '2026-11-05', $today);
$actBody = (string) preg_replace('/^--.*$/m', '', $act); // 실행되는 SQL(머리말 주석 제외, /* ID */ 표시는 남음)
ok('V21·V22 제목·ID 없음(실행 SQL)', !str_contains($act, prod_verified_title($byId['V21'])) && !str_contains($act, prod_verified_title($byId['V22'])) && !preg_match('/\bV2[12]\b/', $actBody));
ok('공공도로 범위(@scope:public_road) 조문 제목 없음', !array_filter($verified, static fn($r) => str_contains($r['tags'], '@scope:public_road') && str_contains($act, prod_verified_title($r))));
preg_match_all("/\/\* (V\d+) \*\/ '((?:[^']|'')*)'/u", $act, $m);
ok('활성화 ID 는 전부 준비 완료 집합(개수 일치)', $m[1] === $ready);
ok('활성화 제목은 각 ID 의 조문 제목', !array_filter(array_keys($m[1]), static fn($i) => str_replace("''", "'", $m[2][$i]) !== prod_verified_title($byId[$m[1][$i]])));
ok('검증 조문(author)·원문/API 확인·현행만 대상', str_contains($act, "WHERE author = '" . PROD_AUTHOR_VERIFIED . "'") && str_contains($act, "verify_level IN ('original_verified','api_text_verified')") && str_contains($act, "legal_status = 'current'"));
ok('검토자·검토일 기록, 후보(author AI 조사 초안) 미포함', str_contains($act, "reviewer = '홍길동', reviewed_at = '2026-11-05'") && !str_contains($act, PROD_AUTHOR_CANDIDATE));
ok('UPDATE 1문만(INSERT·DELETE·DDL 없음)', substr_count($actBody, 'UPDATE ') === 1 && !preg_match('/\b(INSERT|DELETE|DROP|ALTER|TRUNCATE)\b/', $actBody));
$err = static function (string $rv, string $on) use ($verified, $today): bool {
    try { prod_activate_sql($verified, $rv, $on, $today); return false; } catch (InvalidArgumentException) { return true; }
};
ok('검토자 빈 값 거부', $err('', '2026-11-05') && $err('   ', '2026-11-05'));
ok('검토자 AI·로컬 테스트·자리표시 거부', $err('Claude', '2026-11-05') && $err('로컬 테스트', '2026-11-05') && $err('__REVIEWER__', '2026-11-05'));
ok('검토일 형식·실제 날짜·미래·확인일 이전 거부', $err('홍길동', '2026/11/05') && $err('홍길동', '2026-02-30') && $err('홍길동', '2026-11-06') && $err('홍길동', '2026-11-04'));
ok('작은따옴표 검토자도 SQL 문자열로 이스케이프', str_contains(prod_activate_sql($verified, "O'Neil", '2026-11-05', $today), "reviewer = 'O''Neil'"));

echo "[생성기 CLI — 검토자 인자 없으면 거부]\n";
$tmp = sys_get_temp_dir() . '/legal_prod_sql_test_' . getmypid();
@mkdir($tmp);
$run = static function (string $args) use ($root, $tmp): int {
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/scripts/legal-sources/build_prod_sql.php") . ' --out=' . escapeshellarg($tmp) . " --today=2026-11-05 {$args} 2>/dev/null", $o, $code);
    return $code;
};
ok('인자 없음 → 종료 코드 2·파일 생성 없음', $run('') === 2 && glob("{$tmp}/*") === []);
ok('--reviewer 만 → 거부', $run("--reviewer='홍길동'") === 2 && !is_file("{$tmp}/20_activate_ready.sql"));
ok('잘못된 검토일 → 거부', $run("--reviewer='홍길동' --reviewed-on=2026-13-01") === 2 && glob("{$tmp}/*") === []);
ok('--no-activation → 10·30·90·README 만', $run('--no-activation') === 0 && is_file("{$tmp}/10_register_inactive.sql") && !is_file("{$tmp}/20_activate_ready.sql") && is_file("{$tmp}/README.md"));
ok('검토자·검토일 → 20 생성', $run("--reviewer='홍길동' --reviewed-on=2026-11-05") === 0 && str_contains((string) @file_get_contents("{$tmp}/20_activate_ready.sql"), "reviewer = '홍길동'"));
array_map('unlink', glob("{$tmp}/*"));
@rmdir($tmp);

echo "[등록 SQL — 10]\n";
$reg = prod_register_sql($candidates, $verified);
$nCand = count(array_filter(array_map('prod_candidate_row', $candidates)));
ok('82건 = 후보 39 + 검증 조문 43', $nCand === 39 && substr_count($reg, 'INSERT INTO rag_documents') === 82);
ok('모든 INSERT 가 INSERT … SELECT … WHERE NOT EXISTS(author, title)', substr_count($reg, "FROM DUAL\n WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = ") === 82 && !str_contains($reg, 'VALUES ('));
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
ok('30 기대값: 등록 82·활성 ' . count($ready) . '·V21/V22 활성 0·source_type 별', str_contains($post, '-- 기대 82') && str_contains($post, '-- 기대 ' . count($ready) . ' (20 적용 전 0)') && str_contains($post, 'v21_v22_active') && str_contains($post, 'kcs=4, kds=5, ks=20, law=53'));
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

echo "[2차 보완 V28~V35 (2026-11-04)]\n";
$new = array_values(array_filter($verified, static fn($r) => (int) substr((string) $r['id'], 1) >= 28 && (int) substr((string) $r['id'], 1) <= 35));
$newIds = array_map(static fn($r) => (string) $r['id'], $new);
ok('8건(V28~V35)', $newIds === ['V28', 'V29', 'V30', 'V31', 'V32', 'V33', 'V34', 'V35']);
ok('2026-11-05 공식 API 대조 후 — 전부 활성화 준비 집합·활성화 SQL 에 포함', !array_diff($newIds, $ready) && !array_intersect($newIds, $ids($c['check'])) && !array_filter($newIds, static fn($id) => !str_contains($act, "/* {$id} */")));
ok('확인 수준 원문 미러 조문(api_text_verified)·확인일(공식 대조일)·적용 범위·주의·출처 URL 있음', !array_filter($new, static fn($r) => $r['verify_level'] !== 'api_text_verified' || $r['checked_at'] !== '2026-11-05'
    || !str_contains($r['verify_source'], '2026-11-05 법제처 OPEN API 공식 대조') || str_contains($r['verify_source'], '직접 대조 전')
    || trim($r['applies_to']) === '' || trim($r['not_for']) === '' || !str_starts_with($r['official_url'], 'https://www.law.go.kr/') || !$r['conditions']));
ok('적용 범위 표시(@scope) 없음 — 공공도로 차도 판정과 무관하게 태그로만 연결', !array_filter($new, static fn($r) => str_contains($r['tags'], '@')));
$targets = json_decode((string) file_get_contents("{$src}/api_targets.json"), true)['documents'];
$watched = array_merge(...array_map(static fn($d) => (array) $d['clauses'], $targets));
ok('새 조문 전부 API 감시 문서(api_targets.json clauses)에 연결', !array_diff($newIds, $watched));
ok('새 감시 문서 기준값은 현재 시행판(시행일 2026-11-04 이전)·감시 조문 해시 있음', !array_filter($targets, static fn($d) => array_intersect((array) $d['clauses'], $newIds)
    && ($d['baseline']['effective'] > '2026-11-04' || ($d['target'] === 'law' && count((array) $d['baseline']['articles']) !== count($d['watch_articles'])))));
// 태그 점수(app/rag.php rag_legal_score 와 같은 규칙: 태그가 고객 문장에 들어 있으면 +3) — 질문별로 기대 조문이 걸리고, 무관한 질문에는 안 걸림
require_once __DIR__ . '/../app/rag.php';
$hits = static function (string $q) use ($new): array {
    return array_values(array_map(static fn($r) => (string) $r['id'], array_filter($new, static fn($r) => rag_legal_score($r, $q) >= 3)));
};
ok('옹벽 질문 → V28·V29', !array_diff(['V28', 'V29'], $hits('마당에 보강토블록으로 옹벽을 2.5m 쌓으려고 해요')));
ok('담장 2m 질문 → V29', in_array('V29', $hits('블록으로 담장 높이 2m 넘게 쌓아도 되나요?'), true));
ok('마당 흙 돋우기 질문 → V30', in_array('V30', $hits('마당을 높이려고 흙을 돋우려는데 얼마나 해도 돼요?'), true));
ok('개울 호안 질문 → V31·V32', !array_diff(['V31', 'V32'], $hits('집 옆 개울에 호안블록 쌓고 싶어요')));
ok('집 앞 인도 배수로 질문 → V33', in_array('V33', $hits('집 앞 인도에 배수로를 놓으려면 어떻게 해요?'), true));
ok('KS 질문 → V34', in_array('V34', $hits('KS 인증 제품이 뭐가 달라요?'), true));
ok('고무 방지턱 질문 → V35', in_array('V35', $hits('고무 방지턱 도로에 설치해도 되나요?'), true));
ok('무관한 질문(그레이팅 하중·PE관 관경·물가)에는 안 걸림', $hits('주차장 배수로 그레이팅 중하중 몇 개 필요해요?') === [] && $hits('PE 이중벽관 300mm 50m 필요해요') === [] && $hits('요즘 물가가 올라서 가격이 궁금해요') === []);

echo "[KS 적용 범위 갱신 — 40 (사용자 확인 2026-11-05)]\n";
$ks40 = prod_ks_metadata_update_sql($candidates);
$ks40Body = (string) preg_replace('/^--.*$/m', '', $ks40);
$confirmed = array_values(array_filter($candidates, static fn($c) => ($c['type'] ?? '') === 'KS' && !empty($c['ks_detail'])));
$byClaudeKs = array_values(array_filter($confirmed, static fn($c) => str_contains((string) ($c['verified_by'] ?? ''), 'Claude')));
ok('확인된 KS 19건 = 사용자 캡처 10(기존 2 + 2026-11-05 8) + Claude 직접 열람 9(2026-11-05) — 적용 범위에 확인 표시', count($confirmed) === 19
    && count(array_filter($confirmed, static fn($c) => str_contains((string) $c['scope_note'], '사용자 확인'))) === 10
    && count($byClaudeKs) === 9 && !array_filter($byClaudeKs, static fn($c) => !str_contains((string) $c['scope_note'], '공식 상세 확인 2026-11-05') || ($c['ks_detail']['source'] ?? '') !== 'e나라표준인증 표준 상세(Claude 직접 열람)'));
$byRef9 = array_column($byClaudeKs, null, 'ref_no');
ok('Claude 확인분(V25·V26·KS 9) 사용자 인정 기록(2026-11-05)', count(array_filter($byClaudeKs, static fn($c) => ($c['ks_detail']['user_accepted'] ?? '') === '2026-11-05' && str_contains((string) $c['verified_by'], '사용자 인정 2026-11-05'))) === 9
    && str_contains($byId['V25']['verify_source'], '2026-11-05 사용자 인정') && str_contains($byId['V26']['verify_source'], '2026-11-05 사용자 인정') && mb_strlen($byId['V25']['checked_by']) <= 100);
ok('Claude 확인 9건: 등록 제목(official_name)은 그대로 — 운영 행과 title 로 맞춤(공식 표준명 차이는 적용 범위에 표시)', ($byRef9['KS M 3401']['official_name'] ?? '') === '수도용 경질 폴리염화비닐관'
    && str_contains($byRef9['KS M 3401']['scope_note'] ?? '', '압력용 경질 폴리염화비닐관') && isset($byRef9['KS F 4417'], $byRef9['KS L 4201']));
ok('40 은 UPDATE 19문 + 확인 SELECT 만(INSERT·DELETE·DDL 없음)', substr_count($ks40Body, 'UPDATE rag_documents SET') === 19 && !preg_match('/\b(INSERT|DELETE|DROP|ALTER|TRUNCATE|REPLACE)\b/', $ks40Body));
ok('40 은 KS 후보 행만(author·source_type·title) — 활성·검토 상태 칸은 바꾸지 않음', substr_count($ks40Body, "WHERE author = '" . PROD_AUTHOR_CANDIDATE . "' AND source_type = 'ks' AND title = ") === 19
    && !preg_match('/\b(is_active|review_status|reviewer|reviewed_at)\s*=/', $ks40Body));
ok('40 확인 SELECT 는 두 확인 표시 모두 센다(기대 19)', str_contains($ks40, "applies_to LIKE '%공식 상세 확인%'") && str_contains($ks40, '기대 19'));
ok('40 의 값 = 초안 02(로컬 검증본)의 같은 행 값(적용 범위)', !array_filter($confirmed, static fn($c) => !str_contains($ks40, 'applies_to = ' . prod_candidate_row($c)['applies_to']) || !str_contains($d02, prod_candidate_row($c)['applies_to'])));
ok('KS D 4040 출처는 한국표준협회 KSSN(사용자 캡처)', str_contains((string) array_values(array_filter($confirmed, static fn($c) => $c['ref_no'] === 'KS D 4040'))[0]['scope_note'], 'KSSN'));

echo "[3차 보완 V36~V38 (2026-11-05) — 용어 정의·하천 수로]\n";
$new3 = array_values(array_filter($verified, static fn($r) => (int) substr((string) $r['id'], 1) >= 36 && (int) substr((string) $r['id'], 1) <= 38));
ok('3건(V36~V38)·확인일 2026-11-05·조문 미러 확인', array_column($new3, 'id') === ['V36', 'V37', 'V38'] && !array_filter($new3, static fn($r) => $r['checked_at'] !== '2026-11-05' || $r['verify_level'] !== 'api_text_verified'));
ok('V36~V38 모두 활성화 준비(V36 은 2026-10-28, V37·V38 은 2026-11-05 공식 API 대조 일치)', !array_diff(['V36', 'V37', 'V38'], $ready));


echo "[4차 보완 V39~V43 — 국가건설기준(KCSC OpenAPI 원문)]\n";
$new4 = array_values(array_filter($verified, static fn($r) => (int) substr((string) $r['id'], 1) >= 39));
ok('5건(V39~V43)·KDS·원문 확인(original_verified)·확인일 2026-11-05·KCSC OpenAPI 출처·적용 범위·주의 있음', array_column($new4, 'id') === ['V39', 'V40', 'V41', 'V42', 'V43']
    && !array_filter($new4, static fn($r) => $r['doc_type'] !== 'KDS' || $r['verify_level'] !== 'original_verified' || $r['checked_at'] !== '2026-11-05'
        || !str_contains($r['verify_source'], '국가건설기준센터 OpenAPI') || trim($r['applies_to']) === '' || trim($r['not_for']) === '' || !$r['conditions'] || !str_starts_with($r['official_url'], 'https://www.kcsc.re.kr')));
ok('V39~V43 은 활성화 준비 집합(활성화 SQL 포함)', !array_diff(array_column($new4, 'id'), $ready) && !array_filter(array_column($new4, 'id'), static fn($id) => !str_contains($act, "/* {$id} */")));
// 실제 선택 규칙(app/rag.php rag_search_legal_documents: 점수 3 이상, 상위 2, 동점은 오래된 조문)으로 활성 조문 전체에서 고름
$pick = static function (string $q) use ($verified): array {
    $sc = [];
    foreach ($verified as $r) {
        if (empty($r['active']) || str_contains((string) $r['tags'], '@scope')) { continue; }
        $s = rag_legal_score(['tags' => $r['tags'], 'category_key' => $r['category']], $q);
        if ($s >= 3) { $sc[] = [$s, (int) substr((string) $r['id'], 1), (string) $r['id']]; }
    }
    usort($sc, static fn($a, $b) => [$b[0], $a[1]] <=> [$a[0], $b[1]]);
    return array_column(array_slice($sc, 0, 2), 2);
};
ok('보강토블록 옹벽 질문 → V39(보강토옹벽 기준)·V28(건축법 옹벽)', $pick('마당에 보강토블록으로 옹벽을 2.5m 쌓으려고 해요') === ['V39', 'V28']);
ok('보강토 없는 옹벽 질문은 그대로 V28·V29', $pick('마당에 옹벽을 2.5m 쌓으려고 해요') === ['V28', 'V29']);
ok('조경석 쌓기 → V40, 격자블록·흙 쓸림 → V41', $pick('조경석 쌓기로 화단 만들려고 해요') === ['V40'] && $pick('경사면 흙이 쓸려 내려가서 격자블록 쓰려고요')[0] === 'V41');
ok('흄관 모래 기초·PE관 흙 두께 → V42', $pick('흄관 묻을 때 모래 기초 해야 하나요?') === ['V42'] && $pick('PE관을 마당에 묻으려는데 흙 두께는?') === ['V42']);
ok('오수받이 크기 → V43 먼저, 마당 배수관 관경 → V08(법령)·V43', $pick('오수받이 크기는 어떻게 정해요?')[0] === 'V43' && $pick('마당 배수관 몇 mm 관을 써야 해요?') === ['V08', 'V43']);
ok('무관 질문에는 새 조문 안 걸림(보도블록 모래 기초·담장 블록 수량·경사 주차장·PE관 수량)', !array_intersect(['V39', 'V40', 'V41', 'V42', 'V43'], array_merge(
    $pick('보도블록 모래 기초 두께는 얼마나 해요?'), $pick('담장 블록 쌓기 몇 장 필요해요?'), $pick('경사면 주차장에 블록 깔기'), $pick('PE 이중벽관 300mm 50m 필요해요'))));
ok('V40 한계높이는 허용 높이가 아님을 조건·주의에 명시', str_contains(implode(' ', $byId['V40']['conditions']), '허용 높이가 아님') && str_contains($byId['V40']['not_for'], '쌓아도 된다'));
ok('V23(도시지역 도로배수)은 2026 판 KCSC 원문 일치 기록 + 비활성 유지', str_contains($byId['V23']['verify_source'], 'KDS 444020 판 2026') && empty($byId['V23']['active']));

echo "[공식 API 대조 2회(2026-11-05-b) — 새 문서 13개]\n";
$byKey = array_column($targets, null, 'key');
$new13 = ['bldg_law', 'bldg_decree', 'bldg_rule', 'plan_law', 'plan_decree', 'river_law', 'sriver_law', 'road_law', 'road_decree', 'ks_law', 'road_safety', 'parking_law', 'river_occupy'];
ok('13개 문서 api_check: 일치 8 · 새 판(감시 조문 동일) 5, 보고서 b', count(array_filter($new13, static fn($k) => ($byKey[$k]['api_check']['report'] ?? '') === 'api-sync-report-2026-11-05-b.md')) === 13
    && count(array_filter($new13, static fn($k) => $byKey[$k]['api_check']['status'] === 'same')) === 8
    && count(array_filter($new13, static fn($k) => $byKey[$k]['api_check']['status'] === 'new_version_same')) === 5);
$nv = array_values(array_filter($new13, static fn($k) => $byKey[$k]['api_check']['status'] === 'new_version_same'));
ok('새 판 5개: 기준값 번호·시행일 = 공식 현행판, 이전 값은 baseline_prev, 감시 조문 해시 유지', $nv === ['bldg_law', 'plan_law', 'plan_decree', 'road_law', 'road_decree']
    && !array_filter($nv, static fn($k) => $byKey[$k]['baseline']['number'] !== $byKey[$k]['api_check']['api_number'] || $byKey[$k]['baseline']['effective'] !== $byKey[$k]['api_check']['api_effective']
        || empty($byKey[$k]['baseline_prev']['number']) || count((array) $byKey[$k]['baseline']['articles']) !== count($byKey[$k]['watch_articles'])));
ok('갱신한 기준값으로 같은 API 결과를 다시 비교하면 same(새 판 표시가 다시 나오지 않음)', !array_filter($nv, static function ($k) use ($byKey, $today) {
    $b = $byKey[$k]['baseline'];
    $api = ['meta' => ['number' => $byKey[$k]['api_check']['api_number'], 'effective' => $byKey[$k]['api_check']['api_effective']], 'articles' => []];
    return law_api_compare(['number' => $b['number'], 'effective' => $b['effective'], 'articles' => []], $api, $today)['status'] !== 'same';
}));
ok('V35(지침 — API 는 번호·시행일만): 공식 첨부 PDF 본문 대조 기록', str_contains((string) ($byKey['road_safety']['api_check']['body_check']['source'] ?? ''), 'law.go.kr') && str_contains($byId['V35']['verify_source'], '공식 첨부 PDF'));
ok('V30·V33 문서 표기 = 공식 현행판(시행령 36587·도로법 21480, 시행 2026-09-18)', $byId['V30']['doc_kind'] === '대통령령 제36587호' && $byId['V30']['effective'] === '2026-09-18'
    && $byId['V33']['doc_kind'] === '법률 제21480호' && $byId['V33']['effective'] === '2026-09-18');
ok('V28 별표 6 은 석축 옹벽 기준(공식 별표 PDF) — 미확보 표시 없음', !array_filter($byId['V28']['conditions'], static fn($t) => str_contains($t, '미확보')) && isset($byId['V28']['values']['별표6_석축_멧쌓기_경사도']));
ok('V25·V26: law.go.kr 공식 별표 파일 재대조(개정 표시 그대로) 기록, 별표 2 를 API 감시 별표에 추가', str_contains($byId['V25']['verify_source'], '2026-11-05 공식 파일 재대조') && str_contains($byId['V25']['verify_source'], '개정 2024. 12. 26.')
    && str_contains($byId['V26']['verify_source'], '2026-11-05 공식 파일 재대조') && str_contains($byId['V26']['verify_source'], '개정 2021. 8. 27.')
    && in_array('보행안전시설물의 구조 시설기준(제9조 관련)', $byKey['mobility_rule']['watch_annexes'], true) && !array_diff(['V25', 'V26'], $byKey['mobility_rule']['clauses']));
$m41 = prod_verified_metadata_update_sql($verified);
$m41Body = (string) preg_replace('/^--.*$/m', '', $m41);
ok('41: 1단계 등록 행 V25·V26 확인 정보만 UPDATE 2문(활성·검토 칸·INSERT·DELETE·DDL 없음)', substr_count($m41Body, 'UPDATE rag_documents SET content = ') === 2
    && !preg_match('/\b(is_active|review_status|reviewer|reviewed_at)\s*=|\b(INSERT|DELETE|DROP|ALTER|TRUNCATE|REPLACE)\b/', $m41Body)
    && str_contains($m41, prod_verified_row($byId['V26'])['title']) && str_contains($m41, "flSeq=161304041"));
ok('41 의 content 는 10·06 과 같은 생성 규칙(같은 행 값)', str_contains($m41, prod_verified_row($byId['V25'])['content']) && str_contains(prod_register_sql($candidates, $verified), prod_verified_row($byId['V25'])['content']));
$threw = false;
try { prod_verified_metadata_update_sql($verified, ['V99']); } catch (InvalidArgumentException) { $threw = true; }
ok('41: 없는 조문 ID → 예외', $threw);
ok('공식 확인 대기 0건 — 활성화 대상 38건(1단계 20 + V25·V26 + V28~V38 + V39~V43)', PROD_NEEDS_OFFICIAL_CHECK === [] && $c['check'] === [] && count($ready) === 38
    && !array_diff(['V25', 'V26', 'V28', 'V29', 'V30', 'V31', 'V32', 'V33', 'V34', 'V35', 'V36', 'V37', 'V38'], $ready) && !array_intersect(['V04', 'V20', 'V21', 'V22', 'V23'], $ready));
$hits3 = static fn(string $q): array => array_values(array_map(static fn($r) => (string) $r['id'], array_filter($new3, static fn($r) => rag_legal_score($r, $q) >= 3)));
ok('오수·우수 질문 → V36', in_array('V36', $hits3('오수관이랑 빗물관을 따로 묻어야 하나요? 분류식이 뭐예요?'), true));
ok('부설주차장 질문 → V37', in_array('V37', $hits3('우리 집 마당 주차 공간도 부설주차장인가요?'), true));
ok('개울로 배수 질문 → V38', in_array('V38', $hits3('마당 배수로를 집 옆 개울로 연결해도 되나요?'), true));
ok('일반 자재 질문에는 안 걸림', $hits3('PE 이중벽관 300mm 50m 필요해요') === [] && $hits3('인터로킹 블록 20㎡ 주차장') === [] && $hits3('그레이팅 수로용 중하중') === []);

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
