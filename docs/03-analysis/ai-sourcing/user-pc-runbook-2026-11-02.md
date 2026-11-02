# 사용자 PC 실행 모음 (2026-11-02) — 승인 자료 이관 SQL 생성 · 실제 GPT-4o 시험 · 재수집

> 이 클라우드 환경은 국내 사이트·api.openai.com 이 막혀 있고, 수집 원본·승인 DB(14행)는 사용자 PC 에만 있다. 아래만 사용자 PC 에서 실행하면 된다.
> 공통: Git Bash, 저장소 폴더, `git pull origin claude/blissful-turing-8e4xrr`. DB 는 기존 `tomokmall_test`(승인 14행이 있는 DB).
> 결과 파일은 저장소 밖 또는 `.gitignore` 폴더에 생긴다. 결과 `.md` 만 채팅으로 공유(키·비밀번호 공유 금지).

## 1. 승인 자료 14행 → 운영 이관 SQL 만들기 (운영 DB 는 건드리지 않음)

> **2026-11-02 1차 실행 결과(14행·막힘 0·경고 10) 반영 후 다시 생성 필요.** 가야스틸 경고 2건은 확인된 이동 도메인(스틸그레이팅.kr)이라 등록부 aliases 로 정리, 선로드 'PRODUCT 21,500' 같은 행 이름은 가격·쪽 제목을 빼고 이관(상담에도 가격이 넘어가지 않음). 지문에 이름이 들어가 **1차 지문(692f8233…)은 무효** — `git pull` 후 같은 명령으로 다시 만들고 새 `export-report.md` 의 지문을 운영 확인에 쓴다. 경고는 '같은 문장이 다른 행에도 있음'(상담에서는 한 번만 사용)과 이름 정리 안내만 남아야 정상.

```bash
DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php scripts/external/export-approved.php --expect=14
```

- 결과: `data/external/crawl/export/` — `10_create_tables.sql`(테이블 2개, 추가형) · `20_insert_approved.sql`(14행, 다시 실행해도 중복 없음) · `30_post_check_readonly.sql`(행 수·출처별·지문 기대값) · `90_rollback.sql` · `export-report.md`
- 이관 전 자동 검증(하나라도 걸리면 SQL 을 만들지 않음): 출처 권한(facts_if_clear·allowed 만), 현재 규칙으로 문장 재판정(그 뒤 강화된 규칙에 걸리면 다시 승인 필요), 전화·이메일, 메뉴 글자 행, **다른 종류 제품 설명 문장**(그레이팅 행에 흄관 문장 등), 200자 넘는 인용, **같은 문장이 다른 종류 행에도 있음**. 출처 URL 도메인 차이는 경고만.
- 매칭(우리 상품 연결)은 이관하지 않는다 — 로컬·운영 상품 ID 가 다르고 확정 매칭도 0건. 제조사 참고가는 비운다.
- 막히면: `export-report.md` 의 사유를 보고 ① 규칙 강화로 걸린 문장 → `approve-crawled.php --revoke=출처` 후 `--approve=출처` 로 다시 승인(새 규칙 적용) ② 그래도 남으면 `--allow-skip` 으로 그 행만 빼고 생성(`--expect` 를 남는 수로).
- 공유: `export-report.md` (SQL 파일 자체는 운영 적용 승인 때 사용자가 phpMyAdmin 에서 실행).

운영 적용 순서(승인 후, phpMyAdmin): `10_create_tables.sql` → `20_insert_approved.sql` → `30_post_check_readonly.sql` 결과가 주석의 기대값(행 수·출처별·fingerprint)과 같은지 확인 → 운영 상담에서 '그레이팅 차이'·'유모차 덮개'·'흄관 KS' 질문 확인. 되돌리기: `90_rollback.sql`.

## 2. 실제 GPT-4o 시험 (MySQL 8.0 테스트 DB 필요 — 상품 검색이 운영과 같아야 함)

MySQL 8.0 준비는 [`legal-sources/prompt-simulation-2026-10-29.md`](legal-sources/prompt-simulation-2026-10-29.md) 7-4장(설치 없는 ZIP, 포트 3308) 그대로. 준비된 뒤:

```bash
export DB_HOST='127.0.0.1;port=3308' DB_NAME=tomokmall_test DB_USER=root DB_PASS=
```

```bash
/c/xampp/php/php.exe scripts/testdb/consult-scenarios.php --check-only --core --with-public-road
```

```bash
/c/xampp/php/php.exe scripts/testdb/consult-scenarios.php --core --with-public-road --md=consult-core-2026-11-02.md
```

| 결과 파일 | 내용 | 비용(대략) |
|---|---|---|
| `consult-core-2026-11-02.md` | 핵심 4개(턴마다 부른 도구·결과 요약·확인 안 된 KS·수치 기록, 3턴째는 직전 추천표가 프롬프트에 들어감): 공공도로 차도(100mm·KDS/KCS 44 50 25) · 공공 보도 차량 출입(80mm·보도 지침) · 사유지 마당(80T) · **초보 고객 전체 흐름**('집 앞에 보도블록 … 차가 지나가도 괜찮나요?' → 마당·4m×5m → 제일 저렴한 걸로 견적 담기). V21·V22 는 실행 중에만 켜고 끝나면 비활성으로 되돌림 | 약 0.3달러 |

MySQL 8.0 테스트 DB 에 승인 14행이 없으면(새로 만든 DB) 자재 설명 시험은 XAMPP(승인 14행 있는 DB)에서:

```bash
DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= /c/xampp/php/php.exe scripts/testdb/consult-scenarios.php --material --md=material-after8.md
```

| 결과 파일 | 내용 |
|---|---|
| `material-after8.md` | 자재 설명 8문항(재시험 7 의 7문항 + **벤치플룸관 — 제조사 자료 없을 때 국가표준 번호·이름과 토목몰 등록 규격으로만 설명**, '폐쇄형' 같은 지어낸 구조는 실패) |

- 로컬 모의 시험(이 환경, 실제 GPT 아님)은 통과: `tests/consult_journey.test.php`(전체 흐름 판정), `tests/consult_material_checks.test.php`(자재 판정 23개).
- 공유: 두 결과 `.md`. 공공도로·공공 보도 시나리오가 근거 인용·상품(100mm 없음 카드 / 80T 표)·답변까지 통과하면 V21·V22 로컬 활성화를 판단한다(운영 활성화는 별도 승인).

### 2-1. 결과 분석 기준 — 실패 유형 → 고칠 곳 (결과 .md 의 '부른 도구' 줄에 도구 인자·결과 요약이 같이 기록됨)

| 결과 문서에 보이는 것 | 원인 위치 | 바로 할 수정 |
|---|---|---|
| 공공도로인데 100mm·KDS/KCS 인용 없음 / 사유지에 44 50 25 인용 | 장소 판정(`rag_road_context`)·근거 범위 | 판정 낱말 보완 + `tests/rag_road_context` 회귀 |
| '부른 도구'에 search_products 가 없거나 spec_mm 80·spec_strict 누락 | 프롬프트 [보도블록] 필수 항목·2단계 | 프롬프트 1줄 보완 + 기준값 갱신 |
| 표는 있는데 '필요 수량' 없음(× 없음) | area_m2 미전달 | 면적 계산·전달 규칙 보완 |
| 3턴째 add_to_cart 없음 / 표에 없는 id | 직전 추천표 프롬프트 | 표 구역 문구·담기 규칙 보완 |
| '저희 권장 80T' 를 법정 기준처럼 | 근거 구분 구역 | 표현 규칙 보완(80T 문구 3개는 그대로) |
| 자료 없는 구조·수치(폐쇄형·MPa)·KS 번호 | material_info note·basics, 답 끝 '확인된 자료에 없는' 안내 | 금지 표현·basics 사용 규칙 보완 |
| 실행 오류·빈 답 | API·반복 한도 | `rag_process` 로그·재시도 |

## 3. 재수집(선택 — 수집기 개선분 반영)

이번 수정: 규격표 없이 설명 문장만 있는 제품 페이지도 저장(그린파이프 문장 231개가 저장 0 이던 문제), 쿠키 확인 리디렉션 사이트(고려콘크리트 302) 수집. 허락 필요(pending) 출처는 그대로 건너뜀.

```bash
php scripts/external/crawl-sources.php --only=greenpipe,krcon --max-pages=40
```

```bash
DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php scripts/external/import-crawled.php
```

```bash
DB_HOST=127.0.0.1 DB_NAME=tomokmall_test DB_USER=root DB_PASS= php scripts/external/approve-crawled.php --only=greenpipe,krcon
```

- 결과: `data/external/crawl/report.md`(수집량) · `quality.md` · `approval-candidates.md`(승인 후보 문장) — 공유하면 문장 단위로 검토해 승인 명령을 정리한다(자동 승인 없음).
- 승인 연결(로컬 통합 테스트로 확인 — 설명만 있는 제품 페이지·쿠키 302 사이트 → 가져오기 → 승인 후보 → 승인 → material_info): 보고서를 공유하면 문장 단위로 검토해 `approve-crawled.php --approve=greenpipe[,krcon] --exclude='…'` 명령을 정리한다(자동 승인 없음).
- 승인 후 1번 이관을 다시 만들 때 `--expect` 를 새 승인 행 수로(예: 14 + 새 승인 행). 운영에 이미 14행이 들어간 뒤라면 새 20_insert 는 기존 14행을 건너뛰고(중복 없음) 새 행만 넣는다 — 30 의 승인 행 수·지문 기대값은 전체(기존+새) 기준.
- 새 출처를 늘리면 1번 이관 행 수(`--expect`)가 바뀐다. 이관은 승인 행이 확정된 뒤 한 번에.
