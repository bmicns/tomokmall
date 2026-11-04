# 저장소 SQL 정리 메모 (A-8, 2026-11-03)

> 목적: `database/` 의 SQL 중 **운영(MySQL 8.0.46)에 실행하면 안 되는 것**과, 저장소에 만드는 SQL 이 없는데 **운영에만 있는 구조**를 한곳에 적는다.
> 원칙(CLAUDE.md 6장): 기존 마이그레이션 파일은 고치지 않는다 — 이 문서로 표시한다. 운영 DB 변경은 매번 승인 후 사용자가 phpMyAdmin 에서.
> 근거: 운영 구조 점검 [`predeploy/predeploy-check-2026-10-29.md`](predeploy/predeploy-check-2026-10-29.md) 9-2장(필수 33 있음·2 없음 → 20261030 으로 복구), 배포 기록 [`ai-sourcing/prod-apply/DEPLOY-LOG.md`](ai-sourcing/prod-apply/DEPLOY-LOG.md).

## 1. 운영 실행 금지

| 파일 | 이유 | 대신 |
|---|---|---|
| **`database/alter_estimates_v2.sql`** | ① MariaDB 전용 `ADD COLUMN IF NOT EXISTS` — MySQL 8.0 에서 5번째 줄 문법 오류(로컬 확인). ② `status` ENUM 을 `PENDING·CONFIRMED·DELIVERED` 로 확장하고 **기본값을 PENDING 으로 바꾸는** 내용 — 운영 견적 상태(`REQUESTED` 기본) 정책과 충돌(A-2, 정책 결정 전) | 누락 칸은 `20261030_estimates_missing_columns.sql` 로 운영 복구 완료(2026-10-30) |
| `database/local-init.sql` | 로컬 개발용 기본 계정·비밀번호 포함 | 운영 계정은 운영에서 따로 |
| `database/20261029_external_products.sql` | 외부 자재 검증 구역 — **운영 반영은 별도 승인 절차로 이미 진행**(1-② 제조사 자료 14행). 다시 실행할 일 없음 | `prod-apply/APPLY-ORDER.md` |

## 2. MySQL 8.0 에서 그대로 실행되지 않는 파일 (MariaDB 전용 구문)

`ADD COLUMN/KEY/INDEX IF NOT EXISTS`·`DROP INDEX IF EXISTS` 는 MariaDB 전용이라 MySQL 8.0 에서 **문법 오류**로 멈춘다.

| 파일 | 해당 줄 수 | 운영 구조 |
|---|---|---|
| `20260604_dacity_columns.sql` | 5 | 운영 점검 '필수' 범위 밖 — 필요 시 칸 존재를 먼저 조회 |
| `add_ai_orders.sql` | 1 | 운영 점검에서 필요한 칸 있음 |
| `add_ai_sessions.sql` | 3 (+ `DROP INDEX IF EXISTS` 1) | 있음 |
| `add_pricing.sql` | 1 | 있음 |
| `add_vendors.sql` | 8 | 있음 |
| `alter_estimates_v2.sql` | 4 | **실행 금지**(1장) |

- 로컬 테스트 DB 는 `scripts/testdb/setup-local-test-db.sh`·`load-ktomok-docker.sh` 가 이 구문을 지운 사본으로 적용한다(원본은 그대로).
- 새 마이그레이션은 MySQL 8.0 기준으로 쓴다: 칸 추가는 `information_schema` 로 확인 후 추가(`20261102_rag_documents_legal_columns.sql` 방식), 테이블은 `CREATE TABLE IF NOT EXISTS`(`20261103_consult_requests.sql`).

## 3. 저장소에 만드는 SQL 이 없는데 코드가 쓰는 구조

| 구조 | 운영 | 만드는 곳 | 코드 | 없을 때 |
|---|---|---|---|---|
| `categories.discount_customer` · `discount_corporate`(개인·기업 마진율 %) | **있음**(2026-10-30 점검) | SQL 없음 — `scripts/db-tools/db_setup_pricing_vendors.php`(일회성 도구, `TINYINT UNSIGNED NOT NULL DEFAULT 0`) | `app/pricing.php`, `public/api/admin/categories.php`, `public/admin-categories.php` | 가격은 마진 0%(매입가)로 계산, **관리자 분류 API 는 'Unknown column' 서버 오류**(로컬 테스트 DB 에서 확인) |
| `category_gallery`(분류별 사진·설명) | **있음** | 없음 | `public/product.php` | try/catch — 사진 없이 표시 |
| `home_featured`(기업 홈 주요 품목) | **없음** | 없음 | `public/index.php` | try/catch — 기본 품목 표시(정상) |

### 3-1. 사용자 확인 요청 (선택, 읽기 전용 — 승인 불필요)

`category_gallery` 의 정확한 정의를 저장소에 남기려면 phpMyAdmin SQL 탭에서 아래를 실행해 **결과(정의 문장)만** 공유해 주세요. 데이터 값은 나오지 않습니다.

```sql
SHOW CREATE TABLE category_gallery;
```

받으면 `database/` 에 **만들기 전용(IF NOT EXISTS)** 문서용 SQL 로 남기고, 로컬 테스트 DB 구축 스크립트에 추가해 로컬에서도 상품 상세 사진 영역을 확인할 수 있게 한다(운영 변경 없음).

## 4. 운영에 적용된 SQL (기록 기준)

| 일시 | SQL | 결과 |
|---|---|---|
| 2026-10-30 | `20261030_estimates_missing_columns.sql`([A] estimates 칸 4개·인덱스, [B] `reorder_alerts`) | 성공 — 견적 접수·배송 내역 API 복구 |
| 2026-11-02 | `20261029_external_products.sql` + 제조사 자료 14행(`prod-apply/10·20·30`) | 성공(지문 일치) |
| 2026-11-02 | `20261102_rag_documents_legal_columns.sql` + 법령 66행(20건 활성, V21·V22 등 비활성) | 성공 |
| 2026-11-03 | `20261103_consult_requests.sql`(상담원 문의 테이블 2개) | 성공(사용자 보고) |
