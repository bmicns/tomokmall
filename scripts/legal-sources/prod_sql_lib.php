<?php
declare(strict_types=1);
/**
 * 운영 rag_documents 법령 근거 반영 SQL 생성 함수 (순수 함수 — DB·네트워크 없음). CLI: build_prod_sql.php
 *
 * 책임: candidates.json·verified_clauses.json → 운영용 등록(전부 비활성·재실행 안전) / 활성화(준비 완료 조문만,
 *       검토자·검토일 필수) / 사후 읽기 전용 확인 / 되돌리기 SQL. 행 값은 로컬에서 검증한 초안 02·06 과 같게 만든다
 *       (build_legal_sql.php·build_verified_sql.php 와 같은 규칙 — tests/legal_prod_sql.test.php 가 초안과 대조).
 * 금지: V21·V22(공공도로 차도 100mm)는 어떤 경우에도 활성화 SQL 에 넣지 않는다. 확인 수준이 원문·API 확인이 아닌
 *       조문, 공식 사이트 최종 확인이 남은 조문, 상담 비활성 조문은 활성화하지 않는다. 미검증 항목을 검증으로 표시하지 않는다.
 *       사내 문서(source_type='internal', 80T 안내 포함)는 건드리지 않는다.
 */

const PROD_AUTHOR_VERIFIED  = '검증 조문(2-1단계)';
const PROD_AUTHOR_CANDIDATE = 'AI 조사 초안(검색 기반)';
const PROD_ACTIVE_LEVELS    = ['original_verified', 'api_text_verified'];

/** 절대 활성화하지 않는 조문 (운영 활성화 SQL 포함) */
const PROD_NEVER_ACTIVATE = [
    'V21' => 'KDS 44 50 25:2026 차도용 블록 최소 100mm(공공도로 차도) — 사내 80T 안내와 나란히 나오므로 활성화·문구 정리는 사용자 결정, 실제 GPT-4o 상담 확인 전 비활성',
    'V22' => 'KCS 44 50 25:2026 차도용 블록 최소 100mm(공공도로 차도) — V21 과 같은 이유로 비활성',
];

/**
 * 공식 사이트 또는 법제처 OPEN API 에서 사용자 최종 확인이 남은 조문 — 확인 전 활성화하지 않는다.
 * what 은 verified_clauses.json 요약과 대조할 항목(새 수치를 만들지 않음).
 * V28~V35(2026-11-04 추가)는 원문 미러로만 확인 — 사용자 PC 에서 law_api_sync.php 공식 API 대조(same·new_version_same·pending)
 * 결과를 api_targets.json api_check 에 기록한 뒤 이 목록에서 뺀다.
 */
const PROD_NEEDS_OFFICIAL_CHECK = [
    'V25' => [
        'url'  => 'https://www.law.go.kr/LSW/flDownload.do?flSeq=164257997',
        'site' => 'law.go.kr',
        'why'  => '원문 대조에 쓴 파일과 현재 API 현행 링크(flSeq=164257997)가 같은 판인지 기록이 없음(2026-10-28 링크 갱신, api-strategy.md 9-1)',
        'what' => '현행 별표 1 의 개정 표시(2024-12-26 이후 개정 여부)와 제3호가목 수치(보도 유효폭 2m·완화 1.2m, 덮개 틈 1cm 이하, 기울기 1/18·좌우 1/25, 연석 25cm 이하, 턱 2cm 이하)가 verified_clauses.json V25 요약과 같은지',
    ],
    'V26' => [
        'url'  => 'https://www.law.go.kr/법령/교통약자의이동편의증진법시행규칙',
        'site' => 'law.go.kr (별표·서식 → 별표 2)',
        'why'  => '별표 2 는 API 감시 별표 목록에 없고 원문 파일 링크(flSeq)도 기록되지 않음 — 현행판 여부를 API 로 대조하지 않음',
        'what' => '현행 별표 2 의 개정 표시(2021-08-27 이후 개정 여부)와 제2호가목 고원식 횡단보도 평탄부 250cm 이상·제7호 말뚝 높이 80~100cm·지름 10~20cm·간격 1.5m 안팎이 V26 요약과 같은지',
    ],
    'V28' => [
        'url'  => 'https://www.law.go.kr/법령/건축법시행규칙/제25조',
        'site' => '법제처 OPEN API(사용자 PC: law_api_sync.php --only=bldg_law,bldg_rule) 또는 law.go.kr',
        'why'  => '법제처 원문 미러로만 확인(2026-11-04) — 공식 API 대조 전',
        'what' => '건축법 제40조제4항·시행규칙 제25조 현행 문구(경사도 1:1.5 이상·높이 1m 이상 옹벽, 2m 이상 콘크리트구조·별표 6 예외, 배수구멍 3㎡마다, 윗가장자리 2m 이내 배수관 주철관·강관·흄관, 성토 0.5m)가 V28 요약과 같은지',
    ],
    'V29' => [
        'url'  => 'https://www.law.go.kr/법령/건축법시행령/제118조',
        'site' => '법제처 OPEN API(사용자 PC: law_api_sync.php --only=bldg_law,bldg_decree) 또는 law.go.kr',
        'why'  => '법제처 원문 미러로만 확인(2026-11-04) — 공식 API 대조 전',
        'what' => '건축법 제83조제1항·시행령 제118조제1항제5호(높이 2m를 넘는 옹벽 또는 담장 — 공작물 축조신고)·제2항이 V29 요약과 같은지',
    ],
    'V30' => [
        'url'  => 'https://www.law.go.kr/법령/국토의계획및이용에관한법률시행령/제53조',
        'site' => '법제처 OPEN API(사용자 PC: law_api_sync.php --only=plan_law,plan_decree) 또는 law.go.kr',
        'why'  => '법제처 원문 미러로만 확인(2026-11-04) — 공식 API 대조 전',
        'what' => '법 제56조제1항제2호·제4항제3호와 시행령 제53조제3호가목(높이·깊이 50cm 이내 누적, 포장 제외)·나목(660㎡)·다목이 V30 요약과 같은지',
    ],
    'V31' => [
        'url'  => 'https://www.law.go.kr/법령/하천법/제33조',
        'site' => '법제처 OPEN API(사용자 PC: law_api_sync.php --only=river_law) 또는 law.go.kr',
        'why'  => '법제처 원문 미러로만 확인(2026-11-04) — 공식 API 대조 전',
        'what' => '제33조제1항 하천점용허가 대상 행위 6가지와 제3항 고려 사항이 V31 요약과 같은지',
    ],
    'V32' => [
        'url'  => 'https://www.law.go.kr/법령/소하천정비법/제14조',
        'site' => '법제처 OPEN API(사용자 PC: law_api_sync.php --only=sriver_law) 또는 law.go.kr',
        'why'  => '법제처 원문 미러로만 확인(2026-11-04) — 공식 API 대조 전',
        'what' => '제14조제1항 허가 대상 행위 7가지와 제6항 20일 통지가 V32 요약과 같은지',
    ],
    'V33' => [
        'url'  => 'https://www.law.go.kr/법령/도로법/제61조',
        'site' => '법제처 OPEN API(사용자 PC: law_api_sync.php --only=road_law,road_decree) 또는 law.go.kr',
        'why'  => '법제처 원문 미러로만 확인(2026-11-04) — 공식 API 대조 전',
        'what' => '도로법 제61조제1항·제52조제1항·제5항, 시행령 제49조(연결허가 대상 도로)·제55조제2호·제3호·제10호가 V33 요약과 같은지',
    ],
    'V34' => [
        'url'  => 'https://www.law.go.kr/법령/산업표준화법/제15조',
        'site' => '법제처 OPEN API(사용자 PC: law_api_sync.php --only=ks_law) 또는 law.go.kr',
        'why'  => '법제처 원문 미러로만 확인(2026-11-04) — 공식 API 대조 전',
        'what' => '제15조제2항·제3항(제품인증표시)·제24조(공공기관 등 한국산업표준 준수)가 V34 요약과 같은지',
    ],
    'V35' => [
        'url'  => 'https://www.law.go.kr/행정규칙/도로안전시설설치및관리지침',
        'site' => '법제처 OPEN API(사용자 PC: law_api_sync.php --only=road_safety) 또는 law.go.kr',
        'why'  => '법제처 행정규칙 원문 미러로만 확인(2026-11-04) — 공식 API 대조 전',
        'what' => '예규 제465호(시행 2026-08-14) 제4편 2. 과속방지턱의 적용 범위·원호형 길이 3.6m·높이 10cm·재료(특수한 경우 고무·플라스틱)·설치 금지 위치가 V35 요약과 같은지',
    ],
];

/** 상담 비활성(운영도 비활성 유지) 사유 — verified_clauses.json active=false 항목 */
const PROD_INACTIVE_REASON = [
    'V04' => '도로 구조 규칙 제29조(포장)는 두께 수치가 없음 — 80T 근거 조사 기록용',
    'V20' => '하수관로 점검 주기(관리청 업무) — 상담 가치 낮음, V10 이 대신',
    'V23' => 'KDS 44 40 20 우수받이 간격 5~30m 가 하수도법 별표 5(10~30m)와 기준별로 달라 혼동 우려',
];

/** SQL 문자열 리터럴 (build_*_sql.php 와 같은 규칙) */
function prod_q(?string $s): string
{
    return $s === null || $s === '' ? 'NULL' : "'" . str_replace(["\\", "'"], ["\\\\", "''"], $s) . "'";
}

/** verified_clauses.json 1행 → [열 => SQL 리터럴] (초안 06 과 같은 값) */
function prod_verified_row(array $r): array
{
    $title = '[법령] ' . $r['doc_name'] . ' ' . $r['article'];
    $content = "요지: {$r['summary']}\n"
        . '적용 조건: ' . implode(' / ', $r['conditions']) . "\n"
        . ($r['not_for'] ? "주의: {$r['not_for']}\n" : '')
        . ($r['ai_guidance'] ? "상담 안내 예: {$r['ai_guidance']}\n" : '')
        . "확인: {$r['verify_source']} · {$r['checked_at']}";
    return [
        'title'          => prod_q(mb_substr($title, 0, 200)),
        'content'        => prod_q($content),
        'category_key'   => prod_q($r['category'] ?: null),
        'tags'           => prod_q(mb_substr($r['tags'], 0, 500)),
        'is_active'      => '0',
        'sort_order'     => '800',
        'source_type'    => "'law'",
        'source_name'    => prod_q(mb_substr($r['doc_name'] . ' (' . $r['doc_kind'] . ')', 0, 200)),
        'source_ref'     => prod_q(mb_substr($r['article'], 0, 100)),
        'source_url'     => prod_q($r['official_url']),
        'effective_date' => prod_q($r['effective']),
        'revised_date'   => prod_q($r['promulgated']),
        'applies_to'     => prod_q(mb_substr($r['applies_to'], 0, 300)),
        'legal_status'   => "'current'",
        'author'         => prod_q(PROD_AUTHOR_VERIFIED),
        'review_status'  => "'unreviewed'",
        'verify_level'   => prod_q($r['verify_level']),
        'verified_by'    => prod_q(mb_substr($r['checked_by'], 0, 100)),
        'verified_at'    => prod_q($r['checked_at']),
    ];
}

/** candidates.json 1행 → [열 => SQL 리터럴] (초안 02 와 같은 값). 지원하지 않는 종류는 null */
function prod_candidate_row(array $r): ?array
{
    $typeMap = ['법령' => 'law', 'KDS' => 'kds', 'KCS' => 'kcs', 'KS' => 'ks'];
    $catMap = [
        'pavement' => '/보도|블록|블럭|경계|점자|평판|연석/u', 'culvert' => '/수로|측구|플룸|플륨|U형/u',
        'manhole' => '/맨홀|집수정|빗물받이/u', 'pe_pipe' => '/관|파이프|PE|PVC|흄/u', 'grating' => '/그레이팅/u',
    ];
    $LVL = ['original_verified' => '상세 원문 검증', 'api_text_verified' => '원문 조문 확인(법제처 OPEN API 대조)', 'metadata_confirmed' => '공식 메타데이터 확인', 'search_candidate' => '검색 후보'];
    $type = $typeMap[$r['type']] ?? null;
    if ($type === null) {
        return null;
    }
    $mats = is_array($r['related_materials']) ? implode(', ', $r['related_materials']) : (string) $r['related_materials'];
    $cat = null;
    foreach ($catMap as $k => $re) {
        if (preg_match($re, $r['official_name'] . ' ' . $mats)) { $cat = $k; break; }
    }
    $repealed = str_contains((string) $r['status'], '폐지');
    $legal = $repealed ? 'repealed' : (in_array($r['verify_level'] ?? '', PROD_ACTIVE_LEVELS, true) && !empty($r['effective_date']) ? 'current' : 'unknown');
    $content = "[{$LVL[$r['verify_level'] ?? 'search_candidate']} — AI 상담 미사용(검토 전)]\n"
        . (!empty($r['verified_summary']) ? "확인된 내용: {$r['verified_summary']}\n" : '')
        . "AI 적용 범위: " . ($r['ai_scope'] ?? '미검토') . "\n"
        . "문서명: {$r['official_name']}\n번호: {$r['ref_no']}\n발행: {$r['issuing_body']}\n관련 자재: {$mats}\n"
        . "적용 범위(검색 기준): {$r['scope_note']}\n시행·개정일(검색 기준, 미확인): " . ($r['effective_or_revised_date'] ?: '확인 안 됨') . "\n"
        . "2-1 판정: " . ($r['verdict_21'] ?? '') . ' — ' . ($r['verdict_reason'] ?? '') . "\n"
        . "검색 근거 신뢰도: {$r['confidence']}\n확인 수준: " . ($r['verify_level'] ?? 'search_candidate') . ' — ' . ($r['verify_note'] ?? '') . "\n"
        . "확인 URL: " . ($r['verified_url'] ?? '없음') . "\n※ 이 칸에는 원문 확인 후 '상담에 필요한 요지'만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).";
    $code  = preg_match('/^(?:KS [A-Z] \d{4}(?:-\d+)?|KDS(?: \d{2}){3}|KCS(?: \d{2}){3})/', (string) $r['ref_no'], $cm) ? $cm[0] : '';
    $title = "[{$r['type']}] " . mb_substr($r['official_name'], 0, 120) . ($code !== '' && !str_contains($r['official_name'], $code) ? " ({$code})" : '');
    $lvl = in_array($r['verify_level'] ?? '', ['original_verified', 'api_text_verified', 'metadata_confirmed', 'search_candidate'], true) ? $r['verify_level'] : 'search_candidate';
    return [
        'title'          => prod_q(mb_substr($title, 0, 200)),
        'content'        => prod_q($content),
        'category_key'   => prod_q($cat),
        'tags'           => prod_q(mb_substr($mats, 0, 500)),
        'is_active'      => '0',
        'sort_order'     => '900',
        'source_type'    => prod_q($type),
        'source_name'    => prod_q(mb_substr($r['official_name'], 0, 200)),
        'source_ref'     => prod_q(mb_substr((string) $r['ref_no'], 0, 100)),
        'source_url'     => prod_q(mb_substr((string) $r['official_url'], 0, 500)),
        'applies_to'     => prod_q(mb_substr((string) $r['scope_note'], 0, 300)),
        'legal_status'   => prod_q($legal),
        'author'         => prod_q(PROD_AUTHOR_CANDIDATE),
        'review_status'  => "'unreviewed'",
        'verify_level'   => prod_q($lvl),
        'verified_by'    => prod_q($r['verified_by'] ?? null),
        'verified_at'    => prod_q($r['verified_at'] ?? null),
        'effective_date' => prod_q($r['effective_date'] ?? null),
        'revised_date'   => prod_q($r['revised_date'] ?? null),
    ];
}

/** 같은 (author, title) 행이 없을 때만 넣는 INSERT — 두 번 실행해도 중복이 생기지 않는다 */
function prod_insert_if_absent(array $row): string
{
    return 'INSERT INTO rag_documents (' . implode(', ', array_keys($row)) . ")\n"
        . 'SELECT ' . implode(', ', $row) . " FROM DUAL\n"
        . ' WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = ' . $row['author'] . ' AND title = ' . $row['title'] . ");\n";
}

/** 조문 제목 (rag_documents.title 과 같은 값, 따옴표 없음) */
function prod_verified_title(array $r): string
{
    return mb_substr('[법령] ' . $r['doc_name'] . ' ' . $r['article'], 0, 200);
}

/**
 * 조문 분류: ready(운영 활성화 대상) / check(공식 사이트 최종 확인 필요) / inactive(비활성 유지).
 * ready = 로컬 상담 대상(active) + 확인 수준 원문·API + V21·V22·최종 확인 대상 아님.
 * @return array{ready:list<array>, check:list<array>, inactive:list<array>}
 */
function prod_classify(array $verified): array
{
    $out = ['ready' => [], 'check' => [], 'inactive' => []];
    foreach ($verified as $r) {
        $id = (string) $r['id'];
        if (isset(PROD_NEVER_ACTIVATE[$id])) {
            $out['inactive'][] = $r + ['_reason' => PROD_NEVER_ACTIVATE[$id]];
        } elseif (empty($r['active'])) {
            $out['inactive'][] = $r + ['_reason' => PROD_INACTIVE_REASON[$id] ?? '로컬 상담 비활성(verified_clauses.json active=false)'];
        } elseif (!in_array($r['verify_level'] ?? '', PROD_ACTIVE_LEVELS, true)) {
            $out['inactive'][] = $r + ['_reason' => '확인 수준이 원문·API 확인이 아님'];
        } elseif (isset(PROD_NEEDS_OFFICIAL_CHECK[$id])) {
            $out['check'][] = $r + PROD_NEEDS_OFFICIAL_CHECK[$id];
        } else {
            $out['ready'][] = $r;
        }
    }
    return $out;
}

/** 검토자·검토일 검증. 문제가 있으면 InvalidArgumentException */
function prod_validate_review(string $reviewer, string $reviewedOn, string $today, string $minDate): void
{
    $reviewer = trim($reviewer);
    if ($reviewer === '' || mb_strlen($reviewer) > 100 || preg_match('/[\x00-\x1F\x7F]/', $reviewer)) {
        throw new InvalidArgumentException('--reviewer= 에 검토한 사람 이름을 넣으세요(1~100자).');
    }
    if (preg_match('/claude|로컬\s*테스트|__|todo|미정/iu', $reviewer)) {
        throw new InvalidArgumentException('운영 검토자는 실제 검토한 사람이어야 합니다(AI·로컬 테스트·자리표시 불가).');
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $reviewedOn, $m) || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        throw new InvalidArgumentException('--reviewed-on= 은 YYYY-MM-DD 형식의 실제 날짜여야 합니다.');
    }
    if ($reviewedOn > $today) {
        throw new InvalidArgumentException("검토일({$reviewedOn})이 오늘({$today})보다 뒤입니다.");
    }
    if ($reviewedOn < $minDate) {
        throw new InvalidArgumentException("검토일({$reviewedOn})이 조문 확인일({$minDate})보다 앞입니다.");
    }
}

/** 10: 등록(전부 비활성, 재실행 안전) */
function prod_register_sql(array $candidates, array $verified): string
{
    $cand = array_values(array_filter(array_map('prod_candidate_row', $candidates)));
    $sql = "-- 운영 rag_documents 법령 근거 등록 — 전부 is_active=0·review_status='unreviewed' (AI 상담에 쓰이지 않음)\n"
        . "-- ⚠️ 운영 실행은 사용자 승인 후 phpMyAdmin 에서. 먼저 database/20261102_rag_documents_legal_columns.sql 적용.\n"
        . "-- 생성: scripts/legal-sources/build_prod_sql.php (원본 candidates.json " . count($cand) . "건 + verified_clauses.json " . count($verified) . "건)\n"
        . "-- 재실행 안전: 같은 (author, title) 행이 있으면 넣지 않음(INSERT … SELECT … WHERE NOT EXISTS). 기존 사내 문서는 건드리지 않음.\n"
        . "-- 기대: 처음 실행 " . (count($cand) + count($verified)) . "행 추가, 두 번째 실행 0행. 확인은 30_post_check_readonly.sql\n\n"
        . "START TRANSACTION;\n\n-- [1] 후보 " . count($cand) . "건 (author = '" . PROD_AUTHOR_CANDIDATE . "', 초안 02 와 같은 값)\n";
    foreach ($cand as $row) {
        $sql .= prod_insert_if_absent($row);
    }
    $sql .= "\n-- [2] 검증 조문 " . count($verified) . "건 (author = '" . PROD_AUTHOR_VERIFIED . "', 초안 06 과 같은 값 · V21·V22 포함 전부 비활성)\n";
    foreach ($verified as $r) {
        $sql .= '-- ' . $r['id'] . "\n" . prod_insert_if_absent(prod_verified_row($r));
    }
    return $sql . "\nCOMMIT;\n";
}

/** 20: 준비 완료 조문만 활성화(검토자·검토일 기록). 인자 오류면 InvalidArgumentException */
function prod_activate_sql(array $verified, string $reviewer, string $reviewedOn, string $today): string
{
    $c = prod_classify($verified);
    $minDate = max(array_map(static fn($r) => (string) $r['checked_at'], $c['ready']));
    prod_validate_review($reviewer, $reviewedOn, $today, $minDate);
    $ids = array_map(static fn($r) => (string) $r['id'], $c['ready']);
    foreach (array_keys(PROD_NEVER_ACTIVATE) as $never) {
        if (in_array($never, $ids, true)) {
            throw new LogicException("{$never} 는 활성화 대상이 될 수 없습니다.");
        }
    }
    $titles = array_map(static fn($r) => '  /* ' . $r['id'] . ' */ ' . prod_q(prod_verified_title($r)), $c['ready']);
    $n = count($ids);
    return "-- 운영 rag_documents 법령 근거 활성화 — 준비 완료 {$n}건만: " . implode(', ', $ids) . "\n"
        . "-- ⚠️ 운영 실행은 사용자 승인 후. 10_register_inactive.sql 적용 후 실행. V21·V22·공식 사이트 확인 대기("
        . implode('·', array_keys(PROD_NEEDS_OFFICIAL_CHECK)) . ")·비활성 조문은 넣지 않음.\n"
        . '-- 검토자 ' . prod_q(trim($reviewer)) . " · 검토일 {$reviewedOn} (build_prod_sql.php --reviewer --reviewed-on 로 생성)\n"
        . "-- UPDATE 1문(원자적). 재실행 안전(같은 값으로 다시 설정). 기대: 처음 실행 '{$n}행이 영향을 받음'.\n"
        . "-- 실행 후 30_post_check_readonly.sql 로 확인. 기대와 다르면 90_rollback.sql [A] 로 되돌림.\n\n"
        . 'UPDATE rag_documents SET is_active = 1, review_status = \'reviewed\', reviewer = ' . prod_q(trim($reviewer)) . ", reviewed_at = '{$reviewedOn}'\n"
        . ' WHERE author = ' . prod_q(PROD_AUTHOR_VERIFIED) . " AND source_type <> 'internal'\n"
        . "   AND verify_level IN ('" . implode("','", PROD_ACTIVE_LEVELS) . "') AND legal_status = 'current'\n"
        . "   AND title IN (\n" . implode(",\n", $titles) . "\n);\n";
}

/** 30: 사후 확인(읽기 전용) — 기대값을 주석으로 */
function prod_post_check_sql(array $candidates, array $verified): string
{
    $cand = array_values(array_filter(array_map('prod_candidate_row', $candidates)));
    $c = prod_classify($verified);
    $A = prod_q(PROD_AUTHOR_VERIFIED);
    $B = prod_q(PROD_AUTHOR_CANDIDATE);
    $byType = [];
    foreach ($cand as $row) {
        $t = trim($row['source_type'], "'");
        $byType[$t] = ($byType[$t] ?? 0) + 1;
    }
    $byType['law'] = ($byType['law'] ?? 0) + count($verified);
    ksort($byType);
    $never = array_map(static fn($r) => prod_q(prod_verified_title($r)), array_values(array_filter($verified, static fn($r) => isset(PROD_NEVER_ACTIVATE[$r['id']]))));
    $notReady = array_map(static fn($r) => prod_q(prod_verified_title($r)), array_merge($c['check'], $c['inactive']));
    $nReady = count($c['ready']);
    $typeNote = implode(', ', array_map(static fn($k, $v) => "{$k}={$v}", array_keys($byType), $byType));
    $cols = "'source_type','source_name','source_ref','source_url','effective_date','revised_date','applies_to','legal_status','author','reviewer','reviewed_at','review_status','verify_level','verified_by','verified_at','updated_by'";
    return "-- 운영 반영 사후 확인 — 읽기 전용(SELECT 만). 각 줄 끝 주석이 기대값. 승인 없이 실행 가능(CLAUDE.md 6장).\n"
        . "-- 기대값은 10·20 적용 후 기준(20 적용 전이면 활성 0).\n\n"
        . "SELECT COUNT(*) AS new_columns FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rag_documents' AND COLUMN_NAME IN ({$cols}); -- 기대 16\n"
        . "SELECT COUNT(*) AS internal_docs, SUM(is_active) AS internal_active FROM rag_documents WHERE source_type = 'internal'; -- 기대: 적용 전 사전 점검 값과 같음(저장소 기준 5건)\n"
        . "SELECT COUNT(*) AS registered_total FROM rag_documents WHERE author IN ({$A}, {$B}); -- 기대 " . (count($cand) + count($verified)) . "\n"
        . "SELECT COUNT(*) AS verified_registered FROM rag_documents WHERE author = {$A}; -- 기대 " . count($verified) . "\n"
        . "SELECT COUNT(*) AS candidate_registered FROM rag_documents WHERE author = {$B}; -- 기대 " . count($cand) . "\n"
        . "SELECT COUNT(*) AS duplicate_titles FROM (SELECT author, title FROM rag_documents WHERE author IN ({$A}, {$B}) GROUP BY author, title HAVING COUNT(*) > 1) d; -- 기대 0\n"
        . "SELECT COUNT(*) AS verified_active FROM rag_documents WHERE author = {$A} AND is_active = 1; -- 기대 {$nReady} (20 적용 전 0)\n"
        . "SELECT COUNT(*) AS candidate_active FROM rag_documents WHERE author = {$B} AND is_active = 1; -- 기대 0\n"
        . "SELECT COUNT(*) AS v21_v22_active FROM rag_documents WHERE author = {$A} AND is_active = 1 AND title IN (" . implode(', ', $never) . "); -- 기대 0\n"
        . "SELECT COUNT(*) AS public_road_active FROM rag_documents WHERE is_active = 1 AND tags LIKE '%@scope:public_road%'; -- 기대 0\n"
        . "SELECT COUNT(*) AS not_ready_active FROM rag_documents WHERE author = {$A} AND is_active = 1 AND title IN (" . implode(', ', $notReady) . "); -- 기대 0\n"
        . "SELECT COUNT(*) AS active_unverified FROM rag_documents WHERE is_active = 1 AND source_type <> 'internal' AND (verify_level NOT IN ('" . implode("','", PROD_ACTIVE_LEVELS) . "') OR verify_level IS NULL OR review_status <> 'reviewed' OR legal_status <> 'current' OR reviewer IS NULL OR reviewed_at IS NULL); -- 기대 0\n"
        . "SELECT source_type, COUNT(*) AS n, SUM(is_active) AS active FROM rag_documents GROUP BY source_type ORDER BY source_type; -- 기대 n: {$typeNote}, internal=사전 점검 값 · active: law={$nReady}, 그 외 0(internal 제외)\n"
        . "SELECT reviewer, reviewed_at, COUNT(*) AS n FROM rag_documents WHERE author = {$A} AND is_active = 1 GROUP BY reviewer, reviewed_at; -- 기대 1행(20 생성 시 넣은 검토자·검토일), n={$nReady}\n";
}

/** 90: 되돌리기 (단계별) */
function prod_rollback_sql(): string
{
    $A = prod_q(PROD_AUTHOR_VERIFIED);
    $B = prod_q(PROD_AUTHOR_CANDIDATE);
    return "-- 운영 법령 근거 반영 되돌리기 — ⚠️ 데이터 변경. 실행 전 사용자 승인 + phpMyAdmin 내보내기(rag_documents) 백업.\n"
        . "-- 필요한 단계만 위에서부터 실행. 사내 문서(source_type='internal', author NULL)는 어느 단계에서도 바뀌지 않음.\n\n"
        . "-- [A] 20 되돌리기 — 활성화 해제(행은 남김). 먼저 건수 확인:\n"
        . "SELECT COUNT(*) FROM rag_documents WHERE author = {$A} AND is_active = 1;\n"
        . "UPDATE rag_documents SET is_active = 0, review_status = 'unreviewed', reviewer = NULL, reviewed_at = NULL WHERE author = {$A};\n\n"
        . "-- [B] 10 되돌리기 — 등록 행 삭제. 먼저 건수 확인(기대: 10 의 등록 건수):\n"
        . "SELECT author, COUNT(*) FROM rag_documents WHERE author IN ({$A}, {$B}) GROUP BY author;\n"
        . "DELETE FROM rag_documents WHERE author IN ({$A}, {$B});\n\n"
        . "-- [C] 칸 추가 되돌리기 — database/20261102_rag_documents_legal_columns.sql 머리말의 '되돌리기' SQL 사용\n"
        . "--     (새 칸에 넣은 값이 모두 사라짐. [B] 먼저 실행 권장)\n";
}
