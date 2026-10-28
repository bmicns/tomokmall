<?php
declare(strict_types=1);
/**
 * 국가법령정보 OPEN API 클라이언트(app/law_api.php) 단위 테스트 — 네트워크·DB·인증키 불필요.
 *
 * 데이터: tests/fixtures/law_api/*.xml (모의 응답 — 응답 형식만 API 와 같고, 조문 텍스트는 원문 미러에서 생성)
 * 확인: URL 생성(OC 위치), 오류 응답 판별, 검색·법령·행정규칙 본문 파싱, 별표 링크, 미러 마크다운과의 정규화 일치,
 *       기준값 비교 상태(일치·개정·시행 예정·내용 차이·조문 없음), 자동 활성화 없음.
 * 실행: php tests/law_api.test.php
 */
require __DIR__ . '/../app/law_api.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$fx = static fn(string $f): string => (string) file_get_contents(__DIR__ . '/fixtures/law_api/' . $f);

echo "[요청 URL · 인증값]\n";
$url = law_api_url('lawSearch.do', ['target' => 'law', 'query' => '주차장법 시행규칙'], 'testoc');
ok('기본 주소 + type=XML + OC', str_starts_with($url, 'https://www.law.go.kr/DRF/lawSearch.do?OC=testoc&type=XML&target=law'));
ok('검색어 URL 인코딩', str_contains($url, 'query=' . rawurlencode('주차장법 시행규칙')));
$bad = false;
try { law_api_url('../admin.do', [], 'x'); } catch (InvalidArgumentException) { $bad = true; }
ok('허용 외 엔드포인트 거부', $bad);
putenv('LAW_API_OC');
ok('인증값 미설정이면 빈 문자열', law_api_oc() === '');
putenv('LAW_API_OC= abc ');
ok('인증값은 환경변수에서만(앞뒤 공백 제거)', law_api_oc() === 'abc');
putenv('LAW_API_OC');

echo "[오류 응답 판별]\n";
ok('<result>실패</result> → 요청 실패: msg', law_api_error($fx('error_result.xml')) === '요청 실패: MST not found');
ok('일치하는 행정규칙 없음 → 결과 없음', law_api_error($fx('admrul_no_result.xml')) === '결과 없음');
ok('XML 아님(인증 실패 안내 HTML) → 인증 확인 안내', str_contains((string) law_api_error($fx('not_xml.html')), '인증값'));
ok('정상 응답 → null', law_api_error($fx('law_search_parking.xml')) === null && law_api_error($fx('law_detail_parking_rule.xml')) === null);
ok('빈 응답 → 오류', law_api_error('') !== null);

echo "[검색 결과 파싱 · 선택]\n";
$s = law_api_parse_search($fx('law_search_parking.xml'));
ok('법령 3건', $s['total'] === 3 && count($s['items']) === 3);
$pick = law_api_pick($s['items'], '주차장법 시행규칙');
ok('이름 정확 일치 항목 선택(주차장법·시행령 제외)', ($pick['key'] ?? '') === '288421' && $pick['kind'] === '국토교통부령');
ok('공포·시행일 날짜 변환', $pick['promulgated'] === '2026-07-27' && $pick['effective'] === '2026-07-27' && $pick['number'] === '01604');
ok('상세 링크 절대 주소', str_starts_with($pick['link'], 'https://www.law.go.kr/DRF/lawService.do'));
ok('가운뎃점·공백 차이 무시', law_api_pick($s['items'], '주차장법시행규칙') !== null);
ok('없는 이름 → null', law_api_pick($s['items'], '주차장법 시행세칙') === null);
ok('종류가 다르면 제외', law_api_pick($s['items'], '주차장법', '대통령령') === null);
$a = law_api_parse_search($fx('admrul_search_road_kds.xml'));
$ap = law_api_pick($a['items'], '도로설계기준(KDS 44 00 00) 및 도로공사 표준시방서(KCS 44 00 00)', '고시');
ok('행정규칙 검색 → 고시 1건(발령번호·시행일)', ($ap['target'] ?? '') === 'admrul' && $ap['number'] === '2023-907' && $ap['effective'] === '2023-01-12');

echo "[법령 본문 파싱]\n";
$d = law_api_parse_law_detail($fx('law_detail_parking_rule.xml'));
ok('기본정보(법령명·종류·공포번호·시행일)', $d['meta']['name'] === '주차장법 시행규칙' && $d['meta']['kind'] === '국토교통부령' && $d['meta']['number'] === '01604' && $d['meta']['effective'] === '2026-07-27');
ok('장 제목(조문여부=전문)은 조문에서 제외', !isset($d['articles']['1']) && array_map('strval', array_keys($d['articles'])) === ['3', '11']);
ok('제3조 제목·항·호 내용 포함', $d['articles']['3']['title'] === '주차장의 주차구획' && str_contains($d['articles']['3']['text'], '일반형 │2.5미터 이상│5.0미터 이상'));
ok('별표 링크(파일·PDF 절대 주소)', count($d['annexes']) === 3 && str_starts_with($d['annexes'][0]['file_url'], 'https://www.law.go.kr/LSW/flDownload.do') && $d['annexes'][0]['pdf_url'] !== '');
ok('부칙 공포번호', ($d['addenda'][0]['number'] ?? '') === '01604');
ok('잘못된 XML → 빈 결과', law_api_parse_law_detail('<x')['articles'] === []);
ok('조문 키(가지번호)', law_api_article_key('0003', '02') === '3의2' && law_api_article_key('11') === '11');

echo "[행정규칙 본문 파싱]\n";
$r = law_api_parse_admrule_detail($fx('admrul_detail_sewer_maint.xml'));
ok('고시 기본정보(발령번호·시행일·현행)', $r['meta']['number'] === '2025-165' && $r['meta']['effective'] === '2025-10-01' && $r['meta']['current'] === 'Y');
ok('조문 번호로 묶음(장 제목 제외)', array_map('strval', array_keys($r['articles'])) === ['3', '5', '6'] && $r['articles']['6']['title'] !== '');
ok('본문이 조문이면 body_in_file=false', $r['body_in_file'] === false);
$k = law_api_parse_admrule_detail($fx('admrul_detail_road_kds.xml'));
ok('KDS·KCS 고시: 본문이 첨부 파일 안내뿐 → body_in_file=true', $k['body_in_file'] === true && $k['articles'] === []);
ok('KDS·KCS 고시: 발령번호·시행일은 수집', $k['meta']['number'] === '2023-907' && $k['meta']['effective'] === '2023-01-12');
$sw = law_api_parse_admrule_detail($fx('admrul_detail_sidewalk.xml'));
ok('보도 지침(실제 응답): 빈 조문내용 + 첨부파일 → body_in_file=true', $sw['body_in_file'] === true && $sw['articles'] === []);
ok('보도 지침: 첨부파일명·링크 2쌍(HWP·PDF), https 로 정규화', count($sw['attachments']) === 2 && $sw['attachments'][1]['name'] === '보도설치 및 관리지침 개정전문.pdf' && str_starts_with($sw['attachments'][1]['url'], 'https://law.go.kr/flDownload.do?flSeq=169457241'));
ok('보도 지침: 발령번호 321·시행 2021-07-23', $sw['meta']['number'] === '321' && $sw['meta']['effective'] === '2021-07-23');
ok('조문이 있는 고시는 첨부가 없어도 body_in_file=false', law_api_parse_admrule_detail($fx('admrul_detail_sewer_maint.xml'))['attachments'] === []);

echo "[원문 미러 대조 — 정규화]\n";
$mirror = "# 주차장법 시행규칙\n## 제1장 총칙\n##### 제3조 (주차장의 주차구획)\n\n**①** 법 제6조제1항에 따른 주차장의 주차단위구획은 다음 각 호와 같다. <개정 2012.7.2>\n\n  1\\. 평행주차형식의 경우\n##### 제3조의2 (다른 조)\n내용\n";
ok('미러에서 조문 추출(다음 조문 전까지)', law_api_mirror_article($mirror, '3') === "제3조 (주차장의 주차구획)\n\n**①** 법 제6조제1항에 따른 주차장의 주차단위구획은 다음 각 호와 같다. <개정 2012.7.2>\n\n  1\\. 평행주차형식의 경우");
ok('가지 조문 구분(제3조 ≠ 제3조의2)', law_api_mirror_article($mirror, '3의2') === "제3조의2 (다른 조)\n내용");
ok('없는 조문 → null', law_api_mirror_article($mirror, '4') === null);
ok('마크다운 기호·공백 차이 무시, 개정 표시는 유지',
    law_api_hash("제3조(주차장의 주차구획)\n① 법 제6조제1항에 따른 주차장의 주차단위구획은 다음 각 호와 같다. <개정 2012.7.2>\n1. 평행주차형식의 경우")
    === law_api_hash((string) law_api_mirror_article($mirror, '3')));
ok('수치가 바뀌면 해시 다름', law_api_hash('일반형 2.5미터 이상') !== law_api_hash('일반형 2.6미터 이상'));
// 픽스처(API 형식)와 같은 내용의 미러 조문은 해시가 같아야 함 — 기준값 생성(build_api_baseline)과 동기화(law_api_sync)의 전제
$md = "##### 제11조 (부설주차장의 구조ㆍ설비기준)\n" . preg_replace('/^제11조\(부설주차장의 구조ㆍ설비기준\)\s*/u', '', $d['articles']['11']['text']);
ok('API 형식 조문 ↔ 같은 내용의 미러 조문 해시 일치', law_api_hash($d['articles']['11']['text']) === law_api_hash((string) law_api_mirror_article($md, '11')));

echo "[기준값 비교 · 조치]\n";
$base = ['number' => '01604', 'effective' => '2026-07-27', 'articles' => [
    '3' => law_api_hash($d['articles']['3']['text']), '11' => law_api_hash($d['articles']['11']['text'])]];
$c = law_api_compare($base, $d, '2026-10-28');
ok('같은 판·같은 조문 → same', $c['status'] === 'same' && $c['articles']['3']['status'] === 'same' && $c['annexes'] === 3);
$amXml = str_replace(['<공포번호>01604</공포번호>', '<시행일자>20260727</시행일자>', '일반형 │2.5미터 이상'], ['<공포번호>01700</공포번호>', '<시행일자>20261201</시행일자>', '일반형 │2.6미터 이상'], $fx('law_detail_parking_rule.xml'));
$am = law_api_parse_law_detail($amXml);
$c = law_api_compare($base, $am, '2026-10-28');
ok('번호·시행일 변경 + 시행일 미래 → pending(시행 예정)', $c['status'] === 'pending' && $c['articles']['3']['status'] === 'changed' && $c['articles']['11']['status'] === 'same');
$c = law_api_compare($base, $am, '2026-12-01');
ok('시행일 도래 → amended(재검토)', $c['status'] === 'amended');
$nv = law_api_parse_law_detail(str_replace(['<공포번호>01604</공포번호>', '<시행일자>20260727</시행일자>'], ['<공포번호>01700</공포번호>', '<시행일자>20261201</시행일자>'], $fx('law_detail_parking_rule.xml')));
ok('새 판 시행 + 감시 조문 동일 → new_version_same(근거 유지·기준값 갱신)', law_api_compare($base, $nv, '2026-12-01')['status'] === 'new_version_same' && str_contains(law_api_action('new_version_same', true), '근거 유지'));
ok('새 판이 아직 시행 전이면 조문이 같아도 pending', law_api_compare($base, $nv, '2026-10-28')['status'] === 'pending');
$ch = law_api_parse_law_detail(str_replace('일반형 │2.5미터 이상', '일반형 │2.6미터 이상', $fx('law_detail_parking_rule.xml')));
ok('번호 같고 조문만 다름 → changed', law_api_compare($base, $ch, '2026-10-28')['status'] === 'changed');
$base2 = $base; $base2['articles']['12'] = 'deadbeefdeadbeef';
ok('기준 조문이 응답에 없음 → missing', law_api_compare($base2, $d, '2026-10-28')['status'] === 'missing');
ok('공포번호 앞자리 0 차이는 같은 판', law_api_compare(['number' => '1604'] + $base, $d, '2026-10-28')['status'] === 'same');
ok('조치 문구: 활성 근거 개정 → 비활성 권고', str_contains(law_api_action('amended', true), '비활성 권고'));
ok('조치 문구: 어떤 상태도 활성화 지시 없음', !array_filter(['same', 'pending', 'amended', 'changed', 'missing'], static fn($s) => preg_match('/활성화(?!\s*없)|is_active\s*=\s*1/u', law_api_action($s, true))));

echo "[동기화 스크립트 — 모의 응답]\n";
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/law_api_test_' . getmypid();
@mkdir($tmp);
putenv('LAW_API_OC'); // 자식 프로세스에도 키 없음(Windows·Linux 공통). --no-dotenv 로 .env.local 도 읽지 않아 실제 API 호출 없음
$run = static function (string $targets, string $extra = '') use ($root): array {
    $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/scripts/legal-sources/law_api_sync.php")
        . ' --fixtures=' . escapeshellarg("{$root}/tests/fixtures/law_api") . ' --targets=' . escapeshellarg($targets) . " --today=2026-10-28 --no-dotenv {$extra} 2>&1";
    exec($cmd, $out, $code);
    return [implode("\n", $out), $code];
};
$targets = json_decode((string) file_get_contents("{$root}/docs/03-analysis/ai-sourcing/legal-sources/api_targets.json"), true);
copy("{$root}/docs/03-analysis/ai-sourcing/legal-sources/verified_clauses.json", "{$tmp}/verified_clauses.json");
file_put_contents("{$tmp}/api_targets.json", json_encode($targets, JSON_UNESCAPED_UNICODE));
[$out, $code] = $run("{$tmp}/api_targets.json", '--only=parking_rule,road_struct,sewer_maint,road_kds');
ok('저장소 기준값(미러) ↔ API 형식 응답: 감시 조문 8개 일치, 종료 코드 0', $code === 0 && substr_count($out, '| 일치 |') === 4 && !str_contains($out, '| 내용 차이 |'));
ok('KDS·KCS 고시는 번호·시행일만 대조(본문 첨부 파일 안내)', str_contains($out, '본문 첨부 파일') && str_contains($out, 'KCSC'));
ok('API 미반영 새 고시(제2026-528호)를 이전 등록본(제2023-907호)과 따로 표시', str_contains($out, 'API 미반영 새 고시: 제2026-528호(2026-10-02 고시) — API 는 제2023-907호 표시'));
[$out, $code] = $run("{$tmp}/api_targets.json", '--only=disab_law');
ok('시행 예정 개정 재대조 일정 표(제21116호 2026-11-12, 현재 시행판 유지)', str_contains($out, '시행 예정 개정 — 재대조 일정') && str_contains($out, '| 제21116호 | 2026-11-12 | 대기(현재 시행판 유지)'));
$t2 = $targets;
foreach ($t2['documents'] as &$doc) {
    if ($doc['key'] === 'parking_rule') { $doc['baseline']['number'] = '01500'; $doc['baseline']['effective'] = '2025-01-01'; $doc['baseline']['articles']['3'] = 'deadbeefdeadbeef'; }
}
unset($doc);
file_put_contents("{$tmp}/api_targets.json", json_encode($t2, JSON_UNESCAPED_UNICODE));
[$out, $code] = $run("{$tmp}/api_targets.json", "--only=parking_rule --sql={$tmp}/review.sql");
$sqlDraft = (string) @file_get_contents("{$tmp}/review.sql");
ok('기준보다 새 판이 현행 + 감시 조문 다름 → 개정됨, 종료 코드 3', $code === 3 && str_contains($out, '개정됨') && str_contains($out, '비활성 권고'));
ok('비활성 권고 SQL 초안: 안전장치 첫 줄 + 활성 근거 V01·V02 만 UPDATE(is_active=0)', str_starts_with($sqlDraft, 'DRAFT_STOP_') && substr_count($sqlDraft, 'UPDATE rag_documents SET is_active = 0') === 2 && !preg_match('/is_active\s*=\s*1/', $sqlDraft));
[$out, $code] = $run("{$tmp}/api_targets.json", '--only=landscape_kds');
ok('검색 결과 없음 → 검색 안 됨', str_contains($out, '검색 안 됨'));
[$out, $code] = $run("{$tmp}/api_targets.json", '--only=sidewalk');
ok('보도 지침(실제 응답 구조): 본문 첨부 파일 인식 + 개정전문 PDF 링크 출력', $code === 0 && str_contains($out, '본문은 첨부 파일(2개') && str_contains($out, 'https://law.go.kr/flDownload.do?flSeq=169457241'));
exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg("{$root}/scripts/legal-sources/law_api_sync.php") . ' --no-dotenv --targets=' . escapeshellarg("{$tmp}/api_targets.json") . ' 2>&1', $o2, $c2);
ok('키 없이 실제 API 모드 → 설정 안내 후 종료 코드 2(네트워크 호출 없음)', $c2 === 2 && str_contains(implode("\n", $o2), 'LAW_API_OC 미설정'));
[$out, $code] = $run("{$tmp}/api_targets.json", '--search=' . escapeshellarg('주차장법 시행규칙') . ' --target=law');
ok('검색 목록 모드: 이름이 다른 항목까지 번호·시행일 표(대조 없음)', $code === 0 && str_contains($out, '| 주차장법 시행령 | 대통령령 | 35800 |') && !str_contains($out, '기준값'));
$t3 = $targets;
foreach ($t3['documents'] as &$doc) {
    if ($doc['key'] === 'parking_rule') { $doc['watch_annexes'] = ['완화구간의 설치 기준[제6조제1항제5호사목2) 관련]']; }
}
unset($doc);
file_put_contents("{$tmp}/api_targets.json", json_encode($t3, JSON_UNESCAPED_UNICODE));
@mkdir("{$tmp}/raw");
[$out, $code] = $run("{$tmp}/api_targets.json", '--only=parking_rule --raw-dir=' . escapeshellarg("{$tmp}/raw"));
ok('감시 별표 원문 파일 링크(PDF) 목록 출력', str_contains($out, '감시 별표 원문 파일') && str_contains($out, 'https://www.law.go.kr/LSW/flDownload.do?flSeq=167566761'));
$raw = implode('', array_map('file_get_contents', glob("{$tmp}/raw/*.xml") ?: []));
ok('원본 응답 저장 시 링크 속 OC 값 가림', $raw !== '' && str_contains($raw, 'OC=***') && !preg_match('/OC=(?!\*\*\*)/', $raw));
ok('OC 가리기 함수', law_api_redact('a?OC=myid&target=law "OC=x"') === 'a?OC=***&target=law "OC=***"');
array_map('unlink', glob("{$tmp}/raw/*") ?: []);
@rmdir("{$tmp}/raw");
array_map('unlink', glob("{$tmp}/*") ?: []);
@rmdir($tmp);

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
