# AI 상담 근거·데이터 확보 조사 (2026-10-27)

AI 상담 신뢰도 개선(공식 근거 · 상품 데이터 · 수량·비용 비교)을 위한 조사 결과와 검증 도구 모음.
**운영 DB·서버에는 아무것도 반영하지 않았다.** SQL은 모두 초안이며, 실행 시 첫 줄 안전장치(`DRAFT_STOP_…`)에서 문법 오류로 멈춘다.

## 파일

| 경로 | 내용 | 상태 |
|---|---|---|
| `ai-search-verify.md` | AI 상품 검색 결함 3건 수정 전/후 결과(실제 수집 상품 1,452건, 테스트 DB) | 검증 완료 |
| `data-quality/` | 수집 상품 1,462건 품질 검사 보고서(`report.md`), 문제 목록(`issues.csv`, 개인정보 값 없음), 정제 계획 | 저장소 파일 기준 |
| `legal-sources/` | 법령·KDS·KCS·KS 39건 판정(`candidates.json`), 원문 조문 확인 근거 12건(`verified_clauses.json`), 2-1단계 결과(`2-1-status.md`), 검증 계획(`verification-plan.md`) | **상세 원문 검증 1 · 원문 조문 확인 3 · 메타데이터 13 · 검색 후보 20 · 제외 2 · 로컬 상담 연결 11** |
| `legal-sources/api-strategy.md` · `api_targets.json` | 2-1 병행 전략: 39건 API 지원/별도 수집 분류(API 17 · 별도 22), 국가법령정보 OPEN API 대조 대상 16문서·감시 조문 25개, 기존 방식 수집 일괄 갱신 | 실제 API 대조 완료(16문서 일치) · 원문 파일 검증 반영(조항 근거 23건·로컬 활성 18건) |
| `api-application-guide.md` | 신청할 API·인증키 목록, 신청 절차, 키 보관 위치 | 국가법령정보 OPEN API 신청 완료 |
| `external-sources/` | 국내 자재 사이트·제조사 15곳 조사(`sites.json`), 공공데이터 후보, 외부 데이터 검증 구역 설계(`staging-design.md`) | 모두 수집 보류 |
| `sourcing-status.dc.html` | 관리자 '근거·데이터 확보 현황' 화면 시안(디자인 캔버스 형식) | 시안 |
| `sql-drafts/01_…` | `rag_documents` 출처·검토·확인수준 칸 추가 (추가형, MySQL 8.0.46 테스트 DB 검증) | 초안 · 운영 미적용 |
| `sql-drafts/02_…` | 38건 등록 — 전부 `is_active=0`, `review_status='unreviewed'`, `verify_level` 기록 | 초안 |
| `sql-drafts/06_…` | 원문 조문 확인 근거 12건 등록(전부 비활성) | 초안 · 운영 미적용 |
| `sql-drafts/07_…` | **로컬 테스트 DB 전용** 활성화(11건) — 운영 실행 금지 | 테스트 전용 |
| `sql-drafts/03_…` | 저장소 파일 기준 개인정보 102건 정리 (운영 조회 0건 → 실행 보류) | 초안 |
| `sql-drafts/05_…` | 운영 읽기 전용 점검(개인정보 다른 위치·형식 + 상품 품질 건수) | SELECT 전용 |

도구(배포 대상 아님): `scripts/data-quality/check_products.php`, `scripts/legal-sources/build_legal_sql.php`, `scripts/legal-sources/law_api_sync.php`·`build_api_baseline.php`(법령 API 대조),
`scripts/testdb/load-ktomok-docker.sh`, `scripts/testdb/verify-ai-search.php`, 테스트 `tests/ai_search.test.php`, `tests/law_api.test.php`(모의 응답). 법령 API 클라이언트 `app/law_api.php` 는 웹 화면에서 호출하지 않는다.

## 운영 DB 확인 결과 (사용자 조회, 2026-10-27)

- 버전 **MySQL 8.0.46** → 앞으로 마이그레이션은 이 버전 호환 구문만 사용(`ADD COLUMN IF NOT EXISTS` 금지)
- `products.spec` 개인정보 정규식 조회 0건(2회 확인) → 운영 데이터는 저장소 `data/ktomok/import.sql` 과 다름. 품질 건수는 `05_prod_readonly_checks.sql` 로 운영 기준 재확인 필요
- 참고: 기존 마이그레이션 `database/add_ai_sessions.sql`, `database/add_vendors.sql`, `database/20260604_dacity_columns.sql` 은
  `ADD COLUMN IF NOT EXISTS` 때문에 MySQL 8.0.46 테스트 DB에서 **문법 오류**가 난다(기존 파일은 수정하지 않음 — 재적용 시 주의)

## 공식 법령·건설기준 원문 접근 조건 (과제 3)

검증 결과(2026-10-28, 2-1단계): 공식 사이트가 차단되어 법제처 Open API 원문 미러(GitHub legalize-kr)로 조문을 확인했다. 상세 원문 검증 1 · 원문 조문 확인 3 · 공식 메타데이터 13 · 검색 후보 20 · 제외 2. 조항 단위 근거 12건(로컬 활성 11건)을 상담 검색·출처 표시와 연결해 로컬 테스트 완료. 80T 문구는 사용자 결정으로 유지(공식 출처 미연결). 상세: `legal-sources/2-1-status.md`.

| 막힌 지점 | 내용 | 해결 방법 |
|---|---|---|
| 클라우드 작업 환경의 네트워크 정책 | 컨테이너에서 `law.go.kr`, `open.law.go.kr`, `kcsc.re.kr`, `standard.go.kr`, `kssn.net`, `molit.go.kr`, `data.go.kr` 접속 시 프록시가 차단(403). 웹 페이지 열기 도구도 주소 조회 실패. 웹 검색(제목·요약)만 가능 | 환경 설정 → Network access 의 허용 도메인에 위 7개 추가 |
| 국가법령정보 공동활용 OPEN API | 2차 자료 기준: 회원가입 → API 항목별 신청 → 호출 서버 공인 IP/도메인 등록 → 인증값 `OC`, 승인 1~2일. 호출 한도·약관은 미확인 | 사용자가 신청. 키는 `.env` 에만 |
| 국가건설기준(KDS·KCS) | 국가건설기준센터(kcsc.re.kr) 제공 방식·이용 조건 미확인. 국토부 고시 시 molit.go.kr 에 개정 전문 첨부(검색 확인) | 도메인 허용 후 원문·고시일 확인 |
| KS 원문 | standard.go.kr 에서 표준명·적용범위·이력 열람, 원문은 한국표준협회(kssn.net) 유료 보안 PDF(검색 확인) | 원문은 RAG·재게시 대상 아님 → 번호·표준명·적용범위만 기록. 이용 범위는 KSA 문의 |

문서 1건 검증 절차(원문 접근 가능해진 뒤):
1. 공식 원문 URL 열람 → 문서명·조문/기준번호 일치 확인
2. 시행일·최종 개정일을 원문에서 기록(`effective_date`, `revised_date`)
3. 현행/폐지 확인(`legal_status`) — 폐지·대체된 규정은 비활성 유지
4. 적용 대상 기록(`applies_to`) — 예: 도로관리청 보도, 공공하수도. 개인 마당·사유지 공사에 확정 근거로 쓰지 않음
5. 상담에 필요한 **요지만** 본문에 작성(조문 전문·KS 본문 복사 금지)
6. 확인자·확인일과 함께 `verify_level='original_verified'` 기록
7. 검토자·검토일 기록 후 `review_status='reviewed'` → 그때만 활성화(법령 근거 표시 기능·마이그레이션 운영 적용은 별도 승인)
