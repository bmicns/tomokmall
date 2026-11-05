<?php
declare(strict_types=1);
/**
 * 국가건설기준센터 OpenAPI 도우미(scripts/legal-sources/kcsc_lib.php)·조회 도구 안전장치 단위 테스트 — 네트워크·DB 불필요.
 * 응답 예시는 공개 연동 코드 문서(lhs1152-lgtm/kcsc-design-mcp docs/KCSC_API.md)의 형식으로 테스트용으로 만든 가상 값('[테스트]')이다.
 * 실제 응답 형식은 인증키 설정 후 첫 실행(--check, --code)으로 다시 확인한다.
 * 실행: php tests/kcsc_api.test.php
 */
error_reporting(E_ALL & ~E_DEPRECATED);
require_once __DIR__ . '/../scripts/legal-sources/kcsc_lib.php';

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; } else { $fail++; echo "  ✗ {$name}\n"; }
}

echo "[코드·URL·키 가림]\n";
ok('코드 정규화: 띄어쓰기·슬래시·8자리', kcsc_normalize_code('KCS 61 40 10') === ['KCS', '614010'] && kcsc_normalize_code('kds 14 31 10') === ['KDS', '143110']
    && kcsc_normalize_code('KCS/614010') === ['KCS', '614010'] && kcsc_normalize_code('KDS 10101000') === ['KDS', '10101000']);
ok('코드 정규화: 다른 형식은 null', kcsc_normalize_code('KS F 4403') === null && kcsc_normalize_code('KCS 61 40') === null && kcsc_normalize_code('') === null);
$key = 'AbC/123+xyz=';
$url = kcsc_url('CodeViewer/KCS/614010', $key);
ok('URL: 경로 + 쿼리 key(인코딩)', $url === 'https://kcsc.re.kr/OpenApi/CodeViewer/KCS/614010?key=' . rawurlencode($key));
ok('키 가림: 원문·URL 인코딩 모두', !str_contains(kcsc_mask("실패 {$url} 키={$key}", $key), 'AbC') && str_contains(kcsc_mask("키={$key}", $key), '***'));

echo "[응답 해석]\n";
$nullOne = [['no' => 0, 'codeType' => null, 'code' => null, 'fullCode' => null, 'name' => null, 'version' => null, 'updateDate' => null, 'list' => null]];
ok('잘못된 키 응답(모든 값 null 1건) 감지, 정상 목록은 아님', kcsc_is_null_sentinel($nullOne) && kcsc_is_null_sentinel([])
    && !kcsc_is_null_sentinel([['no' => 1, 'codeType' => 'KDS', 'code' => '100000', 'name' => '[테스트]공통설계기준']]));
$list = [
    ['no' => 1, 'codeType' => 'KCS', 'code' => '614010', 'fullCode' => '61401000', 'name' => '[테스트] 하수관로 공사', 'version' => '2024', 'updateDate' => '2024-05-01T10:00:00'],
    ['no' => 2, 'codeType' => 'KDS', 'code' => '614010', 'fullCode' => '61401000', 'name' => '[테스트] 하수관로 설계기준', 'version' => '2022', 'updateDate' => '2022-01-01T00:00:00'],
    ['no' => 3, 'codeType' => 'KCS', 'code' => '513010', 'fullCode' => '51301000', 'name' => '[테스트] 하천 호안 공사', 'version' => '2021', 'updateDate' => '2021-03-03T00:00:00'],
];
$f = kcsc_find($list, ['하수', '관로'], 'KCS');
ok('목록 찾기: 낱말 모두 + 종류', count($f) === 1 && $f[0]['display'] === 'KCS 61 40 10' && $f[0]['updated'] === '2024-05-01' && count(kcsc_find($list, ['하수'])) === 2);
$doc = [['no' => 9, 'codeType' => 'KCS', 'code' => '614010', 'name' => '[테스트] 하수관로 공사', 'version' => '2024', 'list' => [
    ['no' => 2, 'sort' => 2, 'title' => '3.2 관 부설', 'level' => 2, 'label' => '3.2', 'contents' => '<p>관은 <b>기초</b> 위에 부설한다.</p><p>되메우기는 &lt;층다짐&gt; 한다.</p>'],
    ['no' => 1, 'sort' => 1, 'title' => '1. 일반사항', 'level' => 1, 'label' => '1.', 'contents' => '<table><tr><td>관경</td><td>최소 토피</td></tr><tr><td>D300</td><td>1.0 m</td></tr></table><img src="data:image/gif;base64,AAAA">'],
]]];
$d = kcsc_document($doc);
$secs = kcsc_sections($d);
ok('본문: 한 원소 배열 풀기 · sort 순서', ($d['code'] ?? '') === '614010' && array_column($secs, 'label') === ['1.', '3.2']);
ok('평문: 태그 제거·엔티티·표 칸 구분·수식 이미지 표시', str_contains($secs[1]['text'], '되메우기는 <층다짐> 한다.') && str_contains($secs[0]['text'], '관경 | 최소 토피')
    && str_contains($secs[0]['text'], '[수식 이미지]') && !str_contains($secs[0]['text'], 'base64'));
$g = kcsc_grep($secs, '토피');
ok('낱말 검색: 절 번호·제목·앞뒤 글', count($g) === 1 && $g[0]['label'] === '1.' && str_contains($g[0]['snippet'], '최소 토피'));

echo "[조회 도구 안전장치 — 네트워크 전에 멈추는 경우만]\n";
$bin = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/../scripts/legal-sources/kcsc_fetch.php');
exec("env -u KCSC_API_KEY {$bin} --check --no-dotenv 2>&1", $o1, $c1);
ok('키 없음 → 중단·설정 안내(KCSC_API_KEY)', $c1 === 2 && str_contains(implode("\n", $o1), 'KCSC_API_KEY'));
exec('KCSC_API_KEY=' . escapeshellarg('TESTKEY-SHOULD-NOT-PRINT') . " {$bin} --code='KS F 4403' --no-dotenv 2>&1", $o2, $c2);
ok('잘못된 코드 형식 → 중단, 키는 출력에 없음', $c2 === 2 && !str_contains(implode("\n", $o2), 'TESTKEY-SHOULD-NOT-PRINT'));
exec('KCSC_API_KEY=' . escapeshellarg('TESTKEY-SHOULD-NOT-PRINT') . " {$bin} --check --no-dotenv --out=" . escapeshellarg(__DIR__ . '/../docs/kcsc_should_not_exist') . ' 2>&1', $o3, $c3);
ok('저장소 안 출력 폴더 → 중단(본문 원본은 저장소 밖), 폴더 안 만듦', $c3 === 2 && !is_dir(__DIR__ . '/../docs/kcsc_should_not_exist') && !str_contains(implode("\n", $o3), 'TESTKEY'));

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
