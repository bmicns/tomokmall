<?php
declare(strict_types=1);
/**
 * 상담 프롬프트 생성(rag_build_prompt) 회귀 테스트 — 로컬 테스트 DB 전용, OpenAI 호출 없음.
 *
 * 기준값: tests/fixtures/prompts/golden/*.txt — 프롬프트 생성 함수 분리 전 rag_process()가 OpenAI 로 보낸 시스템 프롬프트
 *        (시나리오 7개 × V21·V22 비활성/활성). 분리 후 같은 시나리오의 rag_build_prompt() 결과가 글자 하나까지 같아야 한다.
 *        2026-10-29 의도적 변경 반영: s3(공공 보도)·s6(불명확) 안내 구역에서 근거 없이 적힌 '최소 100mm' 문장 1줄씩 수정
 *        (분리 자체의 전후 동일성은 수정 전 기준값으로 14건 모두 일치 확인 — 요청 본문 전체 바이트 비교).
 *        2026-10-29 의도적 변경 반영(2): '블록'만 쓴 바닥 포장 문맥도 보도블록으로 감지 → s2~s7 에 보도블록 상담 가이드·사내 문서 추가,
 *        공공도로 활성(s1·s2) 구역에 '적합 상품 없으면 얇은 제품 대체 금지·설계도서 확인·별도 견적' 1줄 추가. 그 밖의 문장은 그대로.
 *        2026-10-29 의도적 변경 반영(3, 실제 GPT-4o 결과 반영): 공통 규칙 4줄 — 처리할 수 없는 문의·연락 약속 금지,
 *        필수 항목 확인 후 되묻지 말고 검색, '찾아 드릴게요'만 하고 끝내지 않기, alternatives(같은 두께 등록 블록)로 재검색.
 *        2026-10-29 의도적 변경 반영(4, 구매 전환): 카드 guide(고르는 기준) 활용 1줄, add_to_cart 수량 = 권장 수량(suggested_qty) 1줄 수정.
 *        2026-10-29 의도적 변경 반영(5, 초보 고객 친절성): 추천 이유·주의사항 1줄, 가격 차이 질문은 cost_summary 만 인용, 개수 질문은 qty_basis 인용 — 3줄 추가.
 * 데이터: SQL 초안 01·02·06·07 을 적용한 테스트 DB(검증 조문 27건·활성 22건). 다르면 SKIP(기준값은 그 데이터 기준).
 * 프롬프트를 의도적으로 바꿨다면: php scripts/testdb/dump-prompts.php --out=… 로 확인 후 기준값을 갱신한다.
 * 실행: DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=… DB_PASS=… php tests/rag_prompt.test.php
 */
error_reporting(E_ALL & ~E_WARNING & ~E_DEPRECATED & ~E_NOTICE);
$_SERVER['HTTPS'] = $_SERVER['HTTPS'] ?? '';
require __DIR__ . '/../app/bootstrap.php';
require __DIR__ . '/../app/rag.php';

$dbName = (string) ($config['db']['name'] ?? '');
if (!preg_match('/_test$/', $dbName) || !db() || !rag_documents_has_source_columns()) {
    echo "SKIP: 법령 근거 칸이 있는 로컬 테스트 DB(*_test)에서만 실행합니다.\n";
    exit(0);
}
$pdo = db();
$cnt = static fn(string $w): int => (int) $pdo->query("SELECT COUNT(*) FROM rag_documents WHERE {$w}")->fetchColumn();
if ($cnt("author = '검증 조문(2-1단계)'") !== 27 || $cnt("author = '검증 조문(2-1단계)' AND is_active = 1") !== 22) {
    echo "SKIP: 기준값은 검증 조문 27건·활성 22건 데이터 기준입니다(SQL 초안 06·07 재적용 필요).\n";
    exit(0);
}

$pass = 0; $fail = 0;
function ok(string $name, bool $cond): void {
    global $pass, $fail;
    if ($cond) { $pass++; echo "  ✓ {$name}\n"; }
    else       { $fail++; echo "  ✗ {$name}\n"; }
}
$scenarios = require __DIR__ . '/fixtures/prompts/scenarios.php';
$golden = __DIR__ . '/fixtures/prompts/golden';
$rule80 = '★차량이 올라가면 두께 80mm 이상(80T) 제품, 사람만 다니면 60mm(60T)도 가능 → [참조 문서] 기준.';

$scoped = $pdo->query("SELECT id, is_active, review_status FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'")->fetchAll(PDO::FETCH_ASSOC);
$prompts = [];
try {
    foreach (['inactive', 'active'] as $state) {
        if ($state === 'active') {
            $pdo->exec("UPDATE rag_documents SET is_active = 1, review_status = 'reviewed' WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'");
        }
        foreach ($scenarios as $key => $s) {
            $prompts[$state][$key] = rag_build_prompt($s['messages']);
        }
    }
} finally {
    foreach ($scoped as $r) {
        $st = $pdo->prepare('UPDATE rag_documents SET is_active = ?, review_status = ? WHERE id = ?');
        $st->execute([(int) $r['is_active'], $r['review_status'], (int) $r['id']]);
    }
}

echo "[분리 전후 동일성 — 기준값과 글자 단위 비교]\n";
foreach ($prompts as $state => $byKey) {
    foreach ($byKey as $key => $p) {
        $g = (string) @file_get_contents("{$golden}/{$key}_{$state}.txt");
        ok("{$key} ({$state}) 시스템 프롬프트 = 분리 전", $g !== '' && $p['system'] === $g);
    }
}
ok('rag_process 가 rag_build_prompt 결과를 그대로 사용', (bool) preg_match('/\$prompt\s*=\s*rag_build_prompt\(\$messages\);\s*\$system\s*=\s*\$prompt\[\'system\'\];/', (string) file_get_contents(__DIR__ . '/../app/rag.php')));

echo "[시나리오별 내용 — V21·V22 비활성(현재 상태)]\n";
$P = $prompts['inactive'];
$hasLegal = static fn(array $p, string $n): bool => (bool) array_filter($p['legalDocs'], static fn($d) => str_contains($d['source_name'] . $d['source_ref'], $n));
foreach ($scenarios as $key => $s) {
    ok("{$key}: 장소 판정 = {$s['expect']}", $P[$key]['roadCtx'] === $s['expect']);
    ok("{$key}: 사내 80T 규칙 원문 그대로 포함", str_contains($P[$key]['system'], $rule80));
    ok("{$key}: 비활성 V21·V22(44 50 25) 근거 없음", !$hasLegal($P[$key], '44 50 25'));
}
foreach (['s1_public_road_80t', 's2_public_road_multi'] as $k) {
    ok("{$k}: 공공도로 근거 없으면 '두께 단정 금지' 구역(수치 없음)", str_contains($P[$k]['system'], '=== 공공 도로 공사 — 두께 단정 금지') && !str_contains($P[$k]['system'], '=== 공공도로 차도 공사 — 공식 기준'));
}
ok('s3 공공 보도: 보도 설치 및 관리 지침(구분 Ⅱ) 근거 + 보도 안내 구역', $hasLegal($P['s3_public_sidewalk'], '보도 설치 및 관리 지침') && str_contains($P['s3_public_sidewalk']['system'], '=== 공공 보도의 차량 출입부'));
foreach (['s4_private_yard', 's5_apartment_parking', 's6_unclear', 's7_garden_path'] as $k) {
    ok("{$k}: 공공 범위 근거(보도 지침·44 50 25) 없음", !$hasLegal($P[$k], '보도 설치 및 관리 지침') && !$hasLegal($P[$k], '44 50 25'));
}
ok('s4·s5 사유지: 장소 안내 구역 없음(기존 80T 흐름)', !str_contains($P['s4_private_yard']['system'], '=== 공공') && !str_contains($P['s5_apartment_parking']['system'], '=== 공공') && !str_contains($P['s4_private_yard']['system'], '=== 차량 블록 시공 장소 확인'));
ok('s6 불명확: 장소 확인 질문 구역', str_contains($P['s6_unclear']['system'], '=== 차량 블록 시공 장소 확인'));
ok('s7 정원길: 장소 안내 구역 없음', !str_contains($P['s7_garden_path']['system'], '=== 차량 블록') && !str_contains($P['s7_garden_path']['system'], '=== 공공'));

echo "[시나리오별 내용 — V21·V22 활성(실제 GPT-4o 테스트 조건)]\n";
$A = $prompts['active'];
foreach (['s1_public_road_80t', 's2_public_road_multi'] as $k) {
    ok("{$k}: KDS/KCS 44 50 25 근거 + 공공도로 100mm 구역 + [L번호]", $hasLegal($A[$k], '44 50 25') && str_contains($A[$k]['system'], '=== 공공도로 차도 공사 — 공식 기준 별도 안내') && preg_match('/\[L\d+\] K[DC]S 44 50 25/u', $A[$k]['system']) === 1);
    ok("{$k}: 100mm 안내 + 80T 차도용 비권장 + spec_mm 100", str_contains($A[$k]['system'], '최소 100mm') && str_contains($A[$k]['system'], '권하지 않는다') && str_contains($A[$k]['system'], 'spec_mm 100'));
}
foreach (['s3_public_sidewalk', 's4_private_yard', 's5_apartment_parking', 's6_unclear', 's7_garden_path'] as $k) {
    ok("{$k}: 활성이어도 공공도로 근거(44 50 25) 없음", !$hasLegal($A[$k], '44 50 25'));
}
foreach (['s1_public_road_80t', 's2_public_road_multi'] as $k) {
    ok("{$k}: 적합 상품 없으면 얇은 두께 대체 추천 금지·설계도서 확인·별도 견적", str_contains($A[$k]['system'], '80T·60T 등 기준보다 얇은 제품을 대체품으로 권하지 않는다') && str_contains($A[$k]['system'], '별도 견적'));
}

foreach ($scenarios as $key => $s) {
    ok("{$key}: 검색 약속만 하고 끝내기 금지 + alternatives 재검색 + 문의·연락 약속 금지 규칙", str_contains($P[$key]['system'], "'찾아 드릴게요'·'잠시만 기다려 주세요'라고 말만 하고 답변을 끝내지 않는다")
        && str_contains($P[$key]['system'], '결과에 alternatives') && str_contains($P[$key]['system'], "'문의 주시면 찾아드리겠습니다'·'연락드리겠습니다' 같은 약속 금지"));
}

echo "[카테고리 감지 — '블록' 바닥 포장 문맥]\n";
foreach ($scenarios as $key => $s) {
    ok("{$key}: 보도블록 카테고리 + 상담 가이드", $P[$key]['categoryKey'] === 'pavement' && str_contains($P[$key]['system'], '【보도블록·경계석 추가 가이드】'));
    ok("{$key}: 블록 종류 확인 구역 없음(포장 문맥이 분명)", !str_contains($P[$key]['system'], '=== 블록 종류 확인'));
}

echo "\n결과: {$pass} 통과 / {$fail} 실패\n";
exit($fail > 0 ? 1 : 0);
