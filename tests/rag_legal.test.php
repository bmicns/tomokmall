<?php
declare(strict_types=1);
/**
 * 공식 법령 근거 상담 연동 통합 테스트 — 로컬 테스트 DB 전용(목 데이터 없음).
 *
 * 데이터: SQL 초안 01·02·06·07 (scripts/testdb/load-legal-docs.sh) — 후보 39건(비활성) + 원문 조문 확인 근거 23건(로컬 활성 18건)
 *  - DB 이름이 '_test'로 끝나고 호스트가 로컬일 때만 실행(아니면 SKIP). 활성/비활성 전환 테스트 후 원상 복구.
 *  - OpenAI 호출 없음: 근거 검색 → 프롬프트 구역 → 답변의 [L번호] 처리까지 rag_process 와 같은 함수로 검증.
 * 확인: 검증된 근거만 검색, 비검증 후보·비활성·비현행 제외, 사내 문서 검색은 기존 그대로, 80T 질문에 법령 출처 미연결.
 * 실행 예: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=... DB_PASS=... php tests/rag_legal.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';

require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/rag.php';

$dbName = (string) ($config['db']['name'] ?? '');
$dbHost = (string) ($config['db']['host'] ?? '');
if (!preg_match('/_test$/', $dbName) || !in_array($dbHost, ['127.0.0.1', 'localhost', 'mysql'], true)) {
    echo "SKIP: 로컬 테스트 DB(DB_NAME=*_test, DB_HOST=127.0.0.1|localhost|mysql)에서만 실행합니다. 현재 DB_NAME='{$dbName}'\n";
    exit(0);
}
if (!db() || !rag_documents_has_source_columns()) {
    echo "SKIP: rag_documents 출처 칸이 없습니다. bash scripts/testdb/load-legal-docs.sh 로 준비하세요.\n";
    exit(0);
}

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
/** 근거 검색 결과의 조항(source_ref) 목록 */
function refs(string $msg): array {
    $cat = rag_detect_category([['role' => 'user', 'content' => $msg]]);
    return array_map(static fn($d) => $d['source_name'] . ' ' . $d['source_ref'], rag_search_legal_documents($msg, $cat, 2));
}
function hasRef(array $refs, string $needle): bool {
    foreach ($refs as $r) { if (str_contains($r, $needle)) { return true; } }
    return false;
}
$pdo = db();
$cnt = static fn(string $where): int => (int) $pdo->query("SELECT COUNT(*) FROM rag_documents WHERE {$where}")->fetchColumn();

echo "[데이터 상태]\n";
ok('기존 사내 문서 5건 유지', $cnt("source_type = 'internal' AND id <= 5") === 5);
ok('후보 39건 전부 비활성', $cnt("author = 'AI 조사 초안(검색 기반)' AND is_active = 1") === 0 && $cnt("author = 'AI 조사 초안(검색 기반)'") === 39);
ok('검증 조문 23건 등록 · 로컬 활성 18건', $cnt("author = '검증 조문(2-1단계)'") === 23 && $cnt("author = '검증 조문(2-1단계)' AND is_active = 1") === 18);
ok('KDS·KCS 44 50 25(차도용 블록 100mm)·KDS 44 40 20 은 등록만(비활성)', $cnt("author = '검증 조문(2-1단계)' AND (source_name LIKE 'KDS 44 50 25%' OR source_name LIKE 'KCS 44 50 25%' OR source_name LIKE 'KDS 44 40 20%') AND is_active = 0") === 3);

echo "[상담 시나리오별 근거 검색]\n";
$r = refs('집 앞 마당에 차 두 대 주차할 공간을 블록으로 깔려면 몇 ㎡ 필요해요?');
ok('주차 공간 → 주차장법 시행규칙 제3조', hasRef($r, '주차장법 시행규칙') && hasRef($r, '제3조'));
$r = refs('차도 옆 경계석 높이 기준이 있나요?');
ok('경계석 높이 → 도로 구조 규칙 제16조', hasRef($r, '제16조'));
$r = refs('집 하수관을 공공하수도에 연결하려면 뭘 해야 하나요?');
ok('하수관 연결 → 하수도법 배수설비(제27조 또는 시행규칙 제23조)', hasRef($r, '제27조') || hasRef($r, '제23조'));
$r = refs('가게 앞 빗물받이에서 냄새가 나서 덮개를 씌우고 싶어요');
ok('빗물받이 덮개 → 하수관로 유지관리 기준', hasRef($r, '하수관로 유지관리 기준'));
$r = refs('국도 옆 공장 진입로 연결 공사에 들어갈 측구 규격 알려주세요');
ok('국도 진입로 연결 → 도로 연결 규칙 제9조', hasRef($r, '도로와 다른 시설의 연결에 관한 규칙'));
$r = refs('차 못 들어오게 볼라드 설치하려고요');
ok('볼라드 → 보행안전법 시행규칙 제5조', hasRef($r, '보행안전'));
$r = refs('점자블록 설치 기준 알려주세요');
ok('점자블록 → 장애인등편의법 제7조(대상시설)', hasRef($r, '편의증진'));

$r = refs('볼라드 간격은 얼마나 띄워야 해요?');
ok('볼라드 간격 → 보행안전법 시행규칙 별표 1 제10호', hasRef($r, '[별표 1] 제10호'));
$r = refs('과속방지턱 높이 규격이 있나요?');
ok('과속방지턱 → 보행안전법 시행규칙 별표 1 제1호다목', hasRef($r, '제1호다목'));
$r = refs('점자블록 돌출점 높이 규격 알려주세요');
ok('점자블록 규격 → 장애인등편의법 시행규칙 별표 1 제16호', hasRef($r, '제16호'));
$r = refs('장애인 주차구역 크기는 얼마예요?');
ok('장애인 주차구역 → 별표 1 제4호(3.3×5m)', hasRef($r, '제4호'));
$r = refs('휠체어 경사로 기울기 기준이 궁금해요');
ok('경사로 → 별표 1 제12호', hasRef($r, '제12호'));
$r = refs('맨홀뚜껑 재질은 어떤 걸로 해야 하나요?');
ok('맨홀뚜껑 → 하수도법 시행규칙 별표 5', hasRef($r, '[별표 5]'));
$r = refs('그레이팅 틈 간격 기준 있나요?');
ok('그레이팅 틈 → 접근로 기준(별표 1 제1호, 2cm 이하)', hasRef($r, '[별표 1] 제1호'));

echo "[80T 정책 — 법령 출처 미연결]\n";
$r = refs('차가 올라가는 진입로 보도블록 두께는 몇 mm여야 하나요?');
ok('블록 두께 질문에 법령 근거 없음(제29조 포장은 비활성)', !hasRef($r, '제29조'));
ok('블록 두께 질문 결과에 주차·연석 외 두께 근거 없음', !array_filter($r, static fn($x) => str_contains($x, '포장')));
foreach (['차도용 블록 두께 100mm 이상이어야 하나요?', '차가 다니는 진입로 블록 몇 T 써야 돼요?', '주차장 블록 80T면 되나요?'] as $q) {
    ok("두께 질문에 KDS·KCS 44 50 25(비활성) 미연결: {$q}", !array_filter(refs($q), static fn($x) => str_contains($x, '44 50 25')));
}
$docs = rag_search_documents('보도블록 두께', 'pavement', 2);
ok('사내 문서 검색은 기존대로 5번 문서', array_column($docs, 'id') === [5]);
ok('사내 문서 검색에 법령 문서가 섞이지 않음', !array_filter($docs, static fn($d) => ($d['id'] ?? 0) > 5));

echo "[검증 필터]\n";
$all = $pdo->query("SELECT id FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)'")->fetchAll(PDO::FETCH_COLUMN);
$hit = [];
foreach (['주차 공간', '경계석 높이', '점자블록', '하수관 연결', '빗물받이 덮개', '측구', '맨홀', '흄관', 'PE관', '인터로킹'] as $q) {
    foreach (rag_search_legal_documents($q, '', 5) as $d) { $hit[] = (int) $d['id']; }
}
ok('검색 후보·메타데이터만 확인된 후보는 상담에 안 나옴', !array_intersect($hit, array_map('intval', $all)));
$v01 = (int) $pdo->query("SELECT id FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND source_ref LIKE '제3조%'")->fetchColumn();
$pdo->exec("UPDATE rag_documents SET is_active = 0 WHERE id = {$v01}");
ok('비활성으로 바꾸면 검색 제외', !in_array($v01, array_map(static fn($d) => (int) $d['id'], rag_search_legal_documents('주차 공간 2대', 'pavement', 5)), true));
$pdo->exec("UPDATE rag_documents SET is_active = 1, legal_status = 'unknown' WHERE id = {$v01}");
ok('현행 확인이 없으면 검색 제외', !in_array($v01, array_map(static fn($d) => (int) $d['id'], rag_search_legal_documents('주차 공간 2대', 'pavement', 5)), true));
$pdo->exec("UPDATE rag_documents SET legal_status = 'current' WHERE id = {$v01}");
ok('원상 복구 후 다시 검색', in_array($v01, array_map(static fn($d) => (int) $d['id'], rag_search_legal_documents('주차 공간 2대', 'pavement', 5)), true));

echo "[프롬프트 구역 · 답변 출처 처리]\n";
$legal = rag_search_legal_documents('집 앞 마당 주차 공간 2대', 'pavement', 2);
$sec = rag_legal_prompt_section($legal);
ok('프롬프트에 [L번호]·적용 조건·시행일 포함', str_contains($sec, "[L{$v01}]") && str_contains($sec, '적용 조건:') && str_contains($sec, '시행 2026-07-27'));
ok('프롬프트에 조건부 인용 규칙 포함', str_contains($sec, '조건부 기준을 모든 고객의 의무처럼 말하지 않는다'));
$answer = "일반형 주차 1칸은 너비 2.5m·길이 5.0m 이상입니다[L{$v01}]. 블록은 80T를 권해 드려요[L99999].";
$cit = rag_extract_legal_citations($answer, $legal);
ok('표시는 본문에서 제거', !str_contains($cit['text'], '[L'));
ok('실제 넣은 근거만 출처(지어낸 L99999 버림)', array_column($cit['sources'], 'id') === [$v01]);
$s0 = $cit['sources'][0] ?? [];
ok('출처에 기준명·조항·시행일·적용 조건·URL', ($s0['name'] ?? '') !== '' && str_starts_with((string) ($s0['ref'] ?? ''), '제3조') && ($s0['effective_date'] ?? '') === '2026-07-27' && ($s0['applies_to'] ?? '') !== '' && str_starts_with((string) ($s0['url'] ?? ''), 'https://www.law.go.kr/'));
$cit = rag_extract_legal_citations('블록은 80T를 권해 드려요.', $legal);
ok('표시 없는 답변에는 출처 없음(허위 출처 금지)', $cit['sources'] === []);

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
