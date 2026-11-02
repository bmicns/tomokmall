# 남은 작업 (2026-11-02) — AI 상담 크롤링·법령·품질 고도화 세션 이후

> 작업 브랜치 `claude/blissful-turing-8e4xrr`(main `b81f93a` 기준). 이전 정리 [`remaining-work-2026-10-30.md`](remaining-work-2026-10-30.md) 중 A-1·A-3·A-7·A-9(일부)는 이번에 코드로 해결(운영 배포 전).
> 사용자 PC 실행 명령: [`ai-sourcing/user-pc-runbook-2026-11-02.md`](ai-sourcing/user-pc-runbook-2026-11-02.md) · 운영 DB 반영 통합 절차: [`ai-sourcing/prod-apply/APPLY-ORDER.md`](ai-sourcing/prod-apply/APPLY-ORDER.md)(로컬 종단 리허설 `scripts/testdb/rehearse-prod-apply.sh` PASS 181·FAIL 0)
> **4차 갱신(2026-11-02 저녁): 운영 반영 1단계 완료** — AI 상담 코드 5개·제조사 승인 자료 14행·법령 근거 20건(V21·V22 운영 비활성). 기록: [`ai-sourcing/prod-apply/DEPLOY-LOG.md`](ai-sourcing/prod-apply/DEPLOY-LOG.md). 남은 승인: main 병합, 2단계(B+D 22개)·3단계(C 14개) 배포.
> 3차 갱신: **V21·V22 는 로컬 테스트 DB 에서만 활성(사용자 승인 2026-11-02, SQL 초안 08 — 로컬 활성 24건)**, 운영 반영 SQL 은 계속 V21·V22 제외(활성화 20건). 실제 GPT-4o 1~3차 결과·수정: [`ai-sourcing/gpt4o-results-2026-11-02.md`](ai-sourcing/gpt4o-results-2026-11-02.md).
> 2차 갱신(같은 날): 이관 1차 결과(14행·막힘 0) 반영 — 이름 정리·도메인 별칭으로 **이관 SQL 재생성 필요(1차 지문 무효)**. A-5·A-6·주문 API 503 도 해결.

## 승인이 필요한 작업 (순서)

| # | 작업 | 종류 | 준비물 |
|---|---|---|---|
| 1 | 작업 브랜치 → main 병합 | Git 승인 | `git log origin/main..` 기준 커밋 전부 |
| 2 | 코드 배포(닷홈 4xx 문구 1·2단계, 관리자 통계, 자재 기본 정보·근거 구분 프롬프트, 상담 장소 판정 보완, XSS) | 배포 승인 | DB 변경 없이 동작 |
| 3 | 제조사 자료: 운영 `external_products` 테이블 + 승인 14행 | DB 구조·데이터 승인 | 사용자 PC 에서 `export-approved.php --expect=14` → `10`·`20`·`30` |
| 4 | 법령: 근거 칸 마이그레이션 → 66행 비활성 등록 → 검토자·날짜로 `20_activate_ready.sql` 생성(20건) | DB 구조·데이터 승인 | `database/20261102_rag_documents_legal_columns.sql`, `prod-apply/10·30·90` |

## 사용자 확인 (이 환경에서 접속 불가)

- 실제 GPT-4o: `--core`(핵심 4 — 초보 고객 전체 흐름 포함), `--material`(8 — 벤치플룸관 포함) 결과 `.md` 공유
- law.go.kr: V25·V26 별표 현행 여부 → 확인되면 활성화 대상 22건
- 2026-11-12 이후: `law_api_sync.php`·`mirror_recheck.php` 재실행(장애인등편의법 시행판 교체)
- standard.go.kr: KS 16건 최신 개정·적용 범위(`ks-latest-check.csv`) → `import_ks_check.php`
- 재수집(선택): 그린파이프·고려콘크리트(수집기 개선분), 결과 공유 → 문장 단위 승인

## 다음 개발 우선순위

1. GPT-4o 결과 반영 튜닝(근거 구분·전체 흐름·벤치플룸관)과 V21·V22 로컬 활성 판단
2. 승인 자료 확대: 재수집분 검토·승인 → 이관 SQL 재생성(같은 도구)
3. 제조사 이용 허락(카탈로그) 확보 — 토암·88con·경북산업·동아산업(dacity, 벤치플륨관 취급)·부흥콘크리트(벤치플륨관 3종, 도메인 미확인)
4. 후순위 유지: 회원 가격 정책(B-3), 견적 상태 정책(A-2)
