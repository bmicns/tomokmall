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
 *        2026-10-29 의도적 변경 반영(6): '참고 자료(판매 상품 아님)' 줄은 판매 상품·가격처럼 말하지 않는다 — 1줄 추가.
 *        2026-10-29 의도적 변경 반영(7): 자재 차이·특징·물빠짐·주의 질문은 material_info(승인된 제조사 공개 자료) 먼저 — 1줄 추가.
 *        2026-10-29 의도적 변경 반영(8, 실제 GPT-4o 자재 설명 결과 반영): material_info 규칙 1줄 → 2줄 — 사용 조건에 맞는 자재 고르기 질문 포함,
 *        제조사 제품 설명으로만 전달·원문 밖 성능 덧붙이기·일반 선택 기준 단정 금지, 결과가 비면 '제조사 자료에 따르면' 금지.
 *        2026-10-29 의도적 변경 반영(9, 실제 GPT-4o 재시험): 같은 줄에 원문 밖 수치('n톤 이상') 금지, KS·표준 번호는 [참조 문서]·material_info 원문만.
 *        2026-10-29 의도적 변경 반영(10, 실제 GPT-4o 재시험 2): material_info 호출 대상에 KS(인증) 질문 추가, 원문 제품명을 넓은 종류로 바꿔 말하기 금지.
 *        2026-10-29 의도적 변경 반영(11, 실제 GPT-4o 재시험 3): 출처 표현 예시 '○○(제조사)'를 maker 이름 그대로 쓰는 예로 바꿈('○○가야스틸'처럼 자리표시가 답에 나옴).
 *        2026-11-02 의도적 변경 반영(12): '근거 구분' 구역 5줄 추가 — 공식 기준([L번호])·제조사 자료·국가표준/등록 규격(basics)·저희 권장 기준을 섞지 않고, 시공 조건 질문은 조건 정리 → 근거별 설명 → 비교표로.
 *        비교는 [L번호] 숫자를 빼고 한다(근거 문서 ID 가 DB 마다 달라 생기던 8건 차이 해소).
 *        2026-11-02 의도적 변경 반영(13, 실제 GPT-4o 핵심 4개 결과): [보도블록] 필수 ①② 확인되면 종류를 되묻지 말고 비교표 1줄(전 시나리오),
 *        사유지(s4·s5) 판정 구역 신설 — 80mm 이상은 '저희 권장 기준'으로, 법적 의무처럼 말하지 않음·공공도로 기준 인용 안 함(80T 문구 3개는 그대로).
 *        2026-11-02 의도적 변경 반영(14, 실제 GPT-4o 3차): 추천 이유에 효과·성능 덧붙이기 금지 1줄(전 시나리오), 사유지 구역 효과 설명 금지 표현 구체화(s4·s5).
 *        2026-11-05 의도적 변경 반영(15, 'AI 상담 답변 개선(After)' 시안·사용자 요청): 검색 결과 뒤 상품·금액 글 금지 규칙 10줄 → '검색 결과 답변 형식' 구역 14줄
 *        (① 조건 정리 ② 제품별 설명 + 권장 수량·예상 자재비(도구 값 그대로) ③ 추천 근거, category_desc·guide 만 설명 재료, ** 굵게·끝 콜론 금지),
 *        가격·수량 숫자 1줄·비교표 안내 1줄·세트 추천 1줄·담기 수량 1줄 문구 수정(전 시나리오 같은 차이 — 근거·장소 안내 구역 변화 없음).
 *        2026-11-05 의도적 변경 반영(16, 설명 자료 실제 데이터 보강): 설명 재료에 weight_kg(등록 규격 판매 단위당 중량)·standard(제품 종류의 국가표준) 추가 — 1줄 수정·2줄 추가.
 *        2026-11-05 의도적 변경 반영(17, KS 적용 범위 사용자 확인 8건): standard 줄에 '사람이 확인한 적용 범위(scope)는 그 문구만' 규칙 덧붙임 — 1줄 수정.
 *        2026-11-05 의도적 변경 반영(18, 공공조달 등록 현황): 근거 구분에 'basics.procurement 의 summary 만, 토목몰 상품 인증·조달 여부로 말하지 않음' 1줄 추가(전 시나리오 같은 차이).
 * 데이터: SQL 초안 01·02·06·07 을 적용한 테스트 DB(검증 조문 38건·활성 33건 — 2026-11-04 V28~V35, 2026-11-05 V36~V38 추가). 다르면 SKIP(기준값은 그 데이터 기준).
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
// 활성 22건(07) 또는 24건(08 — V21·V22 로컬 활성, 사용자 승인 2026-11-02). 'inactive' 기준값은 V21·V22 를 잠시 끄고 만든다(끝나면 원래 값)
$activeNonRoad = $cnt("author = '검증 조문(2-1단계)' AND is_active = 1 AND (tags IS NULL OR tags NOT LIKE '@scope:public_road%')");
if ($cnt("author = '검증 조문(2-1단계)'") !== 38 || $activeNonRoad !== 33) {
    echo "SKIP: 기준값은 검증 조문 38건·(V21·V22 외) 활성 33건 데이터 기준입니다(SQL 초안 06·07 재적용 필요).\n";
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
        if ($state === 'inactive') {
            $pdo->exec("UPDATE rag_documents SET is_active = 0 WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%'");
        }
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
        $norm = static fn(string $x): string => (string) preg_replace('/\[L\d+\]/', '[L#]', $x);   // 근거 문서 ID 는 DB 마다 다름 — 번호만 빼고 비교
        ok("{$key} ({$state}) 시스템 프롬프트 = 분리 전", $g !== '' && $norm($p['system']) === $norm($g));
    }
}
ok('rag_process 가 rag_build_prompt 결과를 그대로 사용', (bool) preg_match('/\$prompt\s*=\s*rag_build_prompt\(\$messages(?:,\s*(?:rag_recent_cards\(\$sid\)|\$recentCards))?\);\s*\$system\s*=\s*\$prompt\[\'system\'\];/', (string) file_get_contents(__DIR__ . '/../app/rag.php')));

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
