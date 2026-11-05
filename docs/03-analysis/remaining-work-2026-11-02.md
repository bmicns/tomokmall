# 남은 작업 (2026-11-03 기준)

> 작업 브랜치 `claude/blissful-turing-8e4xrr`(main `b81f93a` 대비 커밋 다수). **운영 반영 1단계 완료(2026-11-02)** — AI 상담 코드 5개 · 제조사 승인 자료 14행 · 법령 근거 20건(운영 V21·V22 비활성). 기록: [`ai-sourcing/prod-apply/DEPLOY-LOG.md`](ai-sourcing/prod-apply/DEPLOY-LOG.md).
> 실행 문서: 배포 [`CODE-DEPLOY-PLAN.md`](ai-sourcing/prod-apply/CODE-DEPLOY-PLAN.md) · DB [`APPLY-ORDER.md`](ai-sourcing/prod-apply/APPLY-ORDER.md) · 사용자 PC [`user-pc-runbook-2026-11-02.md`](ai-sourcing/user-pc-runbook-2026-11-02.md) · GPT-4o 결과 [`gpt4o-results-2026-11-02.md`](ai-sourcing/gpt4o-results-2026-11-02.md). 이전 목록: [`remaining-work-2026-10-30.md`](remaining-work-2026-10-30.md).

## 1. 바로 확인 (사용자)

| 작업 | 방법 |
|---|---|
| 1-③ 법령 근거 운영 확인 | DEPLOY-LOG 'SQL 3줄 + 질문 4개' — 활성 20 · 공공도로 0 · 보도 지침 카드 · 공공도로 질문은 100mm 단정 없이 설계도서 안내 |

## 2. 승인이 필요한 작업

| 순서 | 작업 | 내용 | 비고 |
|---|---|---|---|
| ~~1~~ | ~~main 병합~~ | **완료 2026-11-03** `3d0bca1`·`94bde9a` | |
| ~~2~~ | ~~2단계 배포(22개)~~ | **운영 반영 완료 2026-11-03** — 고객 화면 오류 문구·견적 요청 360px·배너·관리자 로그인 | 로그인 필요 화면(마이페이지·현장·재주문)은 사용자 확인 |
| 3 | 1단계 후속(1개) — **후순위(2026-11-03 사용자 결정)** | `app/ai_tools.php` — 자료 공백 기록(material_gap). 배포 시 `scripts/deploy-files.sh check b5e9602 app/ai_tools.php` | 모니터링 SQL ③ 이 이 뒤부터 쌓임 |
| 4 | **3단계 배포(14개)** | 관리자 통계 500, 관리자·직원 오류 문구·내부 오류 문장 차단, 업체 화면 XSS | **실행표 준비 완료** [`STAGE3-RUNBOOK.md`](ai-sourcing/prod-apply/STAGE3-RUNBOOK.md) — PHP 8.4·운영 조합 전/후 비교(2026-11-03). 선택: `app/bootstrap.php` E_STRICT 1줄 동반 |
| 6 | 상담원 연결 유도(카카오톡, 디자인만) | 추천 시점 카카오톡 채널 1:1 상담 블록(개인·기업회원만, 버튼 기능 없음). 11-04 문자 발송 제거·관리자 '상담 요청' 화면 제거(코드 `_archive/consult-request/` 보관) | **실행표 준비** [`consult-request-RUNBOOK.md`](consult-request-RUNBOOK.md) — 3개 파일, 승인 대기 |
| 5 | 추가 운영 DB | V25·V26 활성화(공식 확인 후), 새 제조사 승인 자료 이관 | 각각 리허설 후 |

## 3. 사용자 확인·자료 (2026-11-02 기준 — 2026-11-05 부터 이 환경에서 law.go.kr·standard.go.kr·kcsc.re.kr·data.go.kr 접속 가능)

| 작업 | 시기 | 결과물 |
|---|---|---|
| 장애인등편의법 시행판 재대조 | **2026-11-12 이후** | `php scripts/legal-sources/mirror_recheck.php` · `law_api_sync.php` 결과 |
| ~~V25·V26(교통약자법 시행규칙 별표 1·2) law.go.kr 확인~~ | 완료 2026-11-05 | 공식 파일 직접 대조(Claude) — 활성화 대상 33건(`prod-apply/README.md`) |
| ~~KS 16건 최신 개정·적용 범위(standard.go.kr)~~ | 완료 2026-11-05 | 사용자 캡처 8 + Claude 직접 열람 9 → 확인된 KS 19건 |
| ~~KCSC OpenAPI 인증키 설정~~ | 완료 2026-11-05 | 키 확인 후 기준 원문 조회 → 국가건설기준 근거 V39~V43 추가(`ai-sourcing/legal-sources/additions-2026-11-04.md` 8장) |
| KCSC 기준 판 재확인 | 분기마다(또는 개정 소식 때) | `php scripts/legal-sources/kcsc_fetch.php --recheck` — 바뀌면 해당 근거 재검토 |
| ~~조달청 품목 등록 내역~~ | 완료 2026-11-05 | 사용자 제공 → 자재 종류별 공공조달 등록 현황 상담 연결(`ai-sourcing/external-sources/g2b-procurement-summary-2026-11-05.md`) |
| ~~제조사 이용 허락 요청~~ | — | **제외**(사용자 결정 2026-11-05: 거래 제조사에 직접 연락해 카탈로그를 요청하거나 이용 허락을 받는 작업은 현실적으로 어려워 남은 작업에서 제외) — 템플릿은 참고용으로만 남김 |
| 그린파이프·고려콘크리트 재수집(선택) | 수시 | `report.md`·`approval-candidates.md` 공유 → 문장 단위 승인 |
| 운영 상담 모니터링(선택) | 며칠 운영 뒤 | `prod-apply/consult-monitor_readonly.sql` 결과 |
| 실제 GPT-4o 5차(선택) | 수시 | 자재 비교 질문 수정분(이름 짐작 금지) 확인 |

## 4. 개발 (승인 없이 진행 가능 — 자료·결과가 오면)

1. 모니터링 결과의 자료 공백 TOP → 공개 페이지 수집 우선순위, 상담 안내 보완(제조사 허락 요청은 제외 — 2026-11-05 사용자 결정)
2. 새 승인 자료(공개 페이지 재수집) → 문장 검토·승인 → 이관 SQL → 실제 자료 리허설 → 운영 반영 승인 요청
3. 11-12 재대조 결과 반영(조문 바뀌면 근거 문구·활성 상태 재검토 — 미검증은 비활성)
4. GPT-4o 결과의 중요 오류(자재·규격·법령·가격) 우선 수정 — 경미한 표현은 자동 점검으로 감시만
5. 외부 자료 매칭(우리 상품 ↔ 제조사 자료): 확정 0 유지 — 같은 제조사 제품이 우리 상품으로 등록될 때 검토

## 5. 이전 목록(10-30)에서 남은 것

| 항목 | 상태 |
|---|---|
| A-2 견적 상태 정책(배송 내역 항상 빈 목록) | 정책 결정 필요 |
| B-3 회원 가격 정책 불일치(정가 vs 회원가) | 후순위 유지(사용자 결정) |
| A-8 저장소 SQL 정리 — `alter_estimates_v2.sql` 운영 실행 금지 표시, 운영에만 있는 `categories.discount_corporate`·`category_gallery` 스키마 문서화 | **완료(문서)** [`database-sql-notes-2026-11-03.md`](database-sql-notes-2026-11-03.md) — `category_gallery` 정의(SHOW CREATE TABLE)는 사용자 공유 시 추가 |
| A-10 PHP 8.4 실제 실행 확인 | **완료(2026-11-03)** — php:8.4 컨테이너: 전체 문법·상담 통합 테스트 14종 통과, 주요 화면·관리자·직원 화면. 발견: `E_STRICT` 사용 중단 로그(요청마다) → `bootstrap.php` 수정(배포 대기) |
| 2단계 범위 밖 4xx: `require_login`·`require_corporate` 의 401·403 | 화면이 상태 코드에 의존 — 그대로 유지(필요 시 별도) |
| `tests/stock_filter.test.php` | 상품이 없는 빈 스키마 DB 에서만 실행(설계상) |

## 6. 완료(참고)

1단계 운영 반영, 닷홈 4xx 문구 수정(코드, 2단계 대기), 관리자 통계·XSS·견적 360px·견적서 수량칸(코드, 2·3단계 대기), 제조사 자료 이관 도구·검증·리허설(실제 14행 PASS 192), 법령 운영 SQL 생성기·리허설, 실제 GPT-4o 1~4차 검토·수정, V21·V22 로컬 활성(운영 비활성).
