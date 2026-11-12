# CLAUDE.md — 건축자재몰(tomokmall) 개발·유지보수 규칙

이 파일과 `.claude/rules/*.md` 가 이 저장소에서 하는 **모든 개발·유지보수 작업의 기준**이다(세션 시작 때 자동으로 읽힌다 — `paths:` 가 있는 규칙 파일은 해당 파일을 읽을 때).
확신이 없으면 실행하기 전에 질문한다. 결과는 사실대로 보고한다(실패·건너뛴 단계·확인 못 한 부분 포함).

---

## 1. 프로젝트 개요

- 건축자재 쇼핑몰 + AI 상담/검색(RAG) + 견적·주문 + 직원(staff)·관리자(admin) 화면
- 환경: **닷홈 Apache / PHP 8.4 / MySQL 8.0**, 프레임워크 없는 순수 PHP (`declare(strict_types=1)`)
- 운영 주소: `http://tomokmall.dothome.co.kr` (기본 HTTP — 원격 관리 승인 화면은 `https://` 로 접속)

| 경로 | 역할 | 운영 서버 위치 |
|---|---|---|
| `public/` | 웹루트: 페이지(`*.php`), `api/` JSON 엔드포인트, `assets/`(JS·CSS·이미지), `.htaccess` | `/html` |
| `app/` | 공통 로직(웹루트 밖): `bootstrap.php`(세션·.env 로드·`db()`), `config.php`, `auth.php`, 레이아웃·렌더러, `rag.php`, `ai_tools.php`, `pricing.php` 등 | `/app` |
| `database/` | `schema.sql` + 추가 마이그레이션 `*.sql` | `/database` |
| `scripts/` | `deploy.sh`(FTP 배포), `smoke.sh`(스모크), `local-up.sh`/`local-down.sh`(로컬 서버), `db-tools/`(일회성 DB 보정 — 웹루트에 두지 않음), `tomok`(원격 관리 CLI) | 배포 안 함 |
| `tests/` | 단위 테스트(`functions.test.php` 등, DB 불필요) | 배포 안 함 |
| `data/` | 외부 수집 상품 데이터(JSON·import SQL) | 배포 안 함 |
| `docs/` | 기획·설계·분석 문서, `style-guide.md`(UI 기준) | 배포 안 함 |

- 권한: `customer` / `corporate` / `staff` / `admin` — `app/auth.php` 의 `require_login()`, `require_corporate()`, `require_admin()` 등
- 시크릿: 로컬 `.env.local`, 운영 `/app/.env`·`/app/.env.remote-ops` (모두 Git 제외). 키 목록은 `.env.example`

---

## 2. 우선순위 (P0~P3)

| 등급 | 뜻 | 완화 |
|---|---|---|
| **P0 절대규칙** | 되돌리기 어렵거나 사업에 직접 피해를 주는 것 (3장) | 가격 보호(P0-4)는 **승인을 받아도 불가**. 나머지는 **그 작업 1회에 대한 사용자의 명시 승인**으로만 |
| **P1 필수 절차** | 품질·안전을 지키는 기본 절차 (`workflow.md`·`database.md`·`deploy-ops.md`) | 사용자가 그 작업에 한해 명시하면 조정 |
| **P2 품질 기준** | 코드 스타일·UI·반응형·접근성·성능 (`php-backend.md`·`frontend-ui.md`) | 상황에 맞게 판단 |
| **P3 협업 방식** | 토론·답변·보고 형식 (`workflow.md`) | 조정 가능 |

- 충돌하면 **P0 > P1 > 사용자의 현재 요청 > P2 > P3** (= 안전 > 기존 기능 보존 > 사용자 요청 > 코드 품질).
- 토론·대안 제시(P3)는 P0 를 바꾸거나 우회하는 근거가 될 수 없다. 대안은 P0 범위 안에서만 낸다.

---

## 3. P0 절대규칙

- **P0-1 Git** — `main` 에서 직접 개발·커밋하지 않는다(작업 브랜치에서만). `main` 병합·커밋·푸시, PR 생성·병합, `git push --force`·`reset --hard`·히스토리 재작성, 브랜치 삭제, 배포 태그 생성·푸시는 **사용자의 명시 승인 후에만**. 이전 승인은 다음 작업에 이어지지 않는다. 승인 전까지 작업 브랜치를 지우지 않는다.
- **P0-2 운영 배포·서버** — FTP 접속(조회 포함)·업로드, `scripts/deploy.sh`·`deploy-files.sh upload/rollback`, 서버 파일 삭제, 운영 대상 `smoke.sh`, 서버·방화벽·접속 설정 변경은 **그 배포 1회 승인 후에만**. 원격 관리 API 파일 반영은 관리자 화면 승인이 그 승인이다. 시작 전 `docs/DEPLOYMENT.md` 를 읽는다.
- **P0-3 운영 DB** — 접속·구조 조회·`SELECT` 조회·집계는 승인 없이 한다. 데이터 변경·구조 변경·마이그레이션·초기화·대량 추출(덤프·전체 행 내보내기)·외부 전송·`db-tools` 실행·권한(GRANT/REVOKE) 변경은 **작업 단위 승인 후에만**(phpMyAdmin 은 사용자가 직접, 원격 관리 API 는 관리자 화면 승인 범위 안에서만). DB 변경 승인과 파일 변경 승인은 별개다. 기준 상세: `.claude/rules/database.md`.
- **P0-4 가격 보호** (사용자 결정 2026-11-04 · 2026-11-12 정리 — 관리자 승인과 무관하게 유지, 원격 관리 API 서버도 막는다)
  - 대상: 판매가(`price_b2b`·`price_b2c`), 매입가·원가(`cost_price`), 카테고리 마진율(`discount_customer`·`discount_corporate`), 공급가(`supply_price`), 견적·주문·결제 금액, 외부 참고가 등 — **이름에 price·cost 가 들어가거나 discount·margin·markup·fee·amount·amt·tax·vat·surcharge 인 모든 컬럼**. 가격 계산 입력값: `products.category_id`, `categories.parent_id`, `syc_products.category_id`, `syc_categories.parent_id`.
  1. **데이터** — 기존 가격 값과 가격 계산 입력값을 **어떤 경로로도 바꾸지 않는다.** 바꾸는 SQL·스크립트·원격 요청은 **사용자가 요청해도 작성하지도 실행하지도 않는다(예외 없음).** 필요하면 대상·현재 값·원하는 값을 정리해 보고하고, 실제 변경은 사용자가 쇼핑몰의 가격 관리 기능으로 한다.
  2. **구조** — 가격 컬럼·가격 계산 입력 컬럼의 구조 변경, 가격 컬럼이 있는 표의 DROP·TRUNCATE·RENAME·기존 컬럼 변경·PRIMARY/UNIQUE 키·외래키·CHECK·트리거·뷰 추가/삭제, 가격 이름 컬럼 추가 등 **가격에 영향을 주는 구조 변경은 하지 않는다.** 그 표의 비가격 컬럼 추가·일반(UNIQUE 아닌) 인덱스 추가/삭제는 C등급(영향 분석 → 사용자 결정 → 운영은 승인). **새 가격 표·새 가격 컬럼 생성은 미결정** — 필요하면 실행하지 말고 보고한다.
  3. **범위** — 막는 것은 Claude 의 행동이다: 원격 관리 API, phpMyAdmin 용 SQL, `db-tools`·데이터 스크립트, 마이그레이션의 데이터 문장, 백업 복원·되돌리기, 브라우저 자동화로 관리 화면에서 가격 저장 — 운영 DB 와 운영 복제 개발 DB 모두. **관리자·직원의 기존 가격 관리 기능**(목록 `database.md`)은 그대로 유지하고, 가격 보호를 이유로 없애거나 막거나 권한을 줄이지 않는다(사용자 요청 시만).
  4. **코드** — 가격이 저장·계산·표시되는 방식이 바뀌는 수정은 C등급. `app/pricing.php` 는 사용자의 별도 요청 없이 바꾸지 않는다.
  5. 조회·분석은 허용. 막히면(`price_protected`) 실행·우회하지 말고 보고한다. 이 정책의 완화·해제는 사용자의 명시 요청이 있을 때만.
- **P0-5 승인 절차 유지** — 관리자 승인 화면(`/admin-remote-ops.php`)과 작업 단위 승인을 유지한다. 채팅의 "진행해", Claude 가 키 API 로 직접 승인하는 방식으로 대신하지 않는다(2026-11-10 결정).
- **P0-6 확정 결정 유지** — `.claude/rules/data-decisions.md` 의 **P0 확정 결정**(데이터·상품 분류)은 사용자의 새 결정 없이 바꾸지 않는다. 결정과 맞지 않는 요청·발견은 실행하지 말고 충돌을 보고한다. '확인 필요' 항목은 임의로 확정하지도 바꾸지도 않는다.
- **P0-7 시크릿·개인정보** — 비밀번호·API 키·토큰·FTP 계정·개인정보를 코드·문서·커밋 메시지·로그·Git 에 남기지 않고, 프롬프트·외부 서비스로 보내지 않는다. 사용자가 이미지·채팅으로 공유한 값도 옮겨 적지 않는다. 키는 `getenv()` 로만 읽는다. 발견하면 즉시 보고하고 키 교체를 권고한다(히스토리 정리는 승인 후).

---

## 4. 작업 등급 (상세: `.claude/rules/workflow.md`)

| 등급 | 대상 | 진행 |
|---|---|---|
| **A 즉시** | 오타·문구, 원인이 분명한 버그, 이미 합의된 작업의 후속, 기록 문서 | 1~3줄 계획 → 구현 → 검증 → 작업 브랜치 커밋 → 보고 |
| **B 짧은 확인** | 한 화면·한 기능 범위, 되돌리기 쉬운 선택 | 권장안으로 진행, 보고에 다른 방법 한 줄 |
| **C 토론** | DB 구조, 아키텍처, 가격·권한·주문 흐름, 주요 UI/UX, 새 라이브러리·도구, 여러 화면 영향, 데이터·분류 규칙, 규칙 파일 | 대안 2~3개·장단점·권장안 1개 제시 → **사용자 결정 후 실행** |

- 등급이 애매하면 높은 쪽. P0 에 걸리는 작업은 등급과 무관하게 승인 절차를 거친다.

---

## 5. 하위 규칙·상세 문서

| 파일 | 로딩 | 내용 |
|---|---|---|
| `.claude/rules/workflow.md` | 항상 | 작업 절차, 등급별 진행·토론 형식, 테스트, 오류 수정, Git 커밋·푸시, 보고 |
| `.claude/rules/database.md` | 항상 | DB 접근 권한 기준, 변경 규칙, 데이터 생성 제한, 가격 보호 상세, 실제 데이터 개발 |
| `.claude/rules/deploy-ops.md` | 항상 | 배포·FTP, 원격 관리 API 운영, 장애 예방, 로컬 시크릿 주의 |
| `.claude/rules/data-decisions.md` | 항상 | 확정 데이터·분류 결정(P0), 기능 정책 결정, 확인 필요·대기 항목 |
| `.claude/rules/php-backend.md` | PHP 파일을 읽을 때 | 코드 원칙, 백엔드 보안, 성능 |
| `.claude/rules/frontend-ui.md` | 화면 파일을 읽을 때 | UI, 접근성, 반응형 |

| 상세 문서 | 읽는 때 |
|---|---|
| `docs/DEPLOYMENT.md` | 배포·FTP·운영 DB 변경 작업 시작 전(필수) |
| `docs/REMOTE-OPS.md`, `docs/02-design/remote-ops.md` | 원격 관리 API 사용·수정 전 |
| `docs/03-analysis/testdb/README.md` | 운영 복제 개발 DB 복원·재복제 |
| `docs/style-guide.md` | 화면 변경 |
| `docs/02-design/spec-axes.md`, `docs/03-analysis/spec-axes-site-structures.md` | 규격·이름표 작업 |
| `docs/02-design/syc-products.md` 외 `syc-*`·`multi-vendor-*` | 상용콘크리트·일괄 등록·다업체 작업 |
| `docs/03-analysis/work-types-v5.md` | 작업별 추천 자재 |
| `docs/03-analysis/ai-sourcing/legal-sources/*` | 법령 근거·80T 상담 문구 |
| `docs/03-analysis/ai-sourcing/prod-apply/DEPLOY-LOG.md` | 운영 반영 이력·현재 상태 |

---

## 6. 규칙 파일 관리

1. 규칙은 **한 곳에만** 쓰고 다른 곳에서는 링크한다.
2. 파일당 500줄 이내(관리 기준). 실제로는 더 짧게 — CLAUDE.md 120줄 안팎, 규칙 파일 90줄 이내.
3. 규칙에는 현재 유효한 것만 쓴다. 결정 경위·이력은 `docs/` 에 둔다.
4. 사용자 결정이 새로 생기면 근거 문서와 `data-decisions.md` 를 같은 커밋에서 갱신한다.
5. 규칙 파일 변경은 C등급(사용자 결정 후). 새 세션은 기본 브랜치를 받으므로 **main 병합 후 새 세션부터** 적용된다.
6. P0 는 `paths:` 조건이 있는 파일에 두지 않는다(배포·DB 작업은 Bash 로만 실행돼 조건부 규칙이 로딩되지 않음).
