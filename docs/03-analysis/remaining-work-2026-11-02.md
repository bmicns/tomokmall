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
| 3 | **1단계 후속(1개)** | `app/ai_tools.php` — 자료 공백 기록(material_gap) | 모니터링 SQL ③ 이 이 뒤부터 쌓임 |
| 4 | **3단계 배포(14개)** | 관리자 통계 500, 관리자·직원 오류 문구·내부 오류 문장 차단, 업체 화면 XSS | |
| 5 | 추가 운영 DB | V25·V26 활성화(공식 확인 후), 새 제조사 승인 자료 이관 | 각각 리허설 후 |

## 3. 사용자 확인·자료 (이 환경은 국내 사이트·OpenAI 접속 불가)

| 작업 | 시기 | 결과물 |
|---|---|---|
| 장애인등편의법 시행판 재대조 | **2026-11-12 이후** | `php scripts/legal-sources/mirror_recheck.php` · `law_api_sync.php` 결과 |
| V25·V26(교통약자법 시행규칙 별표 1·2) law.go.kr 확인 | 수시 | 현행이면 활성화 대상 22건으로 재생성 |
| KS 16건 최신 개정·적용 범위(standard.go.kr) | 수시 | `ks-latest-check.csv` 채움 → `import_ks_check.php` |
| 제조사 이용 허락 요청 | 수시 | [`permission-request-template.md`](ai-sourcing/external-sources/permission-request-template.md) — 동아산업·그린파이프·토암·88con·경북산업·부흥 |
| 그린파이프·고려콘크리트 재수집(선택) | 수시 | `report.md`·`approval-candidates.md` 공유 → 문장 단위 승인 |
| 운영 상담 모니터링(선택) | 며칠 운영 뒤 | `prod-apply/consult-monitor_readonly.sql` 결과 |
| 실제 GPT-4o 5차(선택) | 수시 | 자재 비교 질문 수정분(이름 짐작 금지) 확인 |

## 4. 개발 (승인 없이 진행 가능 — 자료·결과가 오면)

1. 모니터링 결과의 자료 공백 TOP → 수집·허락 요청 우선순위, 상담 안내 보완
2. 새 승인 자료(재수집·허락 카탈로그) → 문장 검토·승인 → 이관 SQL → 실제 자료 리허설 → 운영 반영 승인 요청
3. 11-12 재대조 결과 반영(조문 바뀌면 근거 문구·활성 상태 재검토 — 미검증은 비활성)
4. GPT-4o 결과의 중요 오류(자재·규격·법령·가격) 우선 수정 — 경미한 표현은 자동 점검으로 감시만
5. 외부 자료 매칭(우리 상품 ↔ 제조사 자료): 확정 0 유지 — 같은 제조사 제품이 우리 상품으로 등록될 때 검토

## 5. 이전 목록(10-30)에서 남은 것

| 항목 | 상태 |
|---|---|
| A-2 견적 상태 정책(배송 내역 항상 빈 목록) | 정책 결정 필요 |
| B-3 회원 가격 정책 불일치(정가 vs 회원가) | 후순위 유지(사용자 결정) |
| A-8 저장소 SQL 정리 — `alter_estimates_v2.sql` 운영 실행 금지 표시, 운영에만 있는 `categories.discount_corporate`·`category_gallery` 스키마 문서화 | 미착수(문서 작업) |
| A-10 PHP 8.4 실제 실행 확인 | 운영 1단계 정상 동작으로 부분 확인, 로컬은 8.3 |
| 2단계 범위 밖 4xx: `require_login`·`require_corporate` 의 401·403 | 화면이 상태 코드에 의존 — 그대로 유지(필요 시 별도) |
| `tests/stock_filter.test.php` | 상품이 없는 빈 스키마 DB 에서만 실행(설계상) |

## 6. 완료(참고)

1단계 운영 반영, 닷홈 4xx 문구 수정(코드, 2단계 대기), 관리자 통계·XSS·견적 360px·견적서 수량칸(코드, 2·3단계 대기), 제조사 자료 이관 도구·검증·리허설(실제 14행 PASS 192), 법령 운영 SQL 생성기·리허설, 실제 GPT-4o 1~4차 검토·수정, V21·V22 로컬 활성(운영 비활성).
