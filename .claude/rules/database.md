# 데이터베이스·가격 보호·실제 데이터 개발 (P0 상세·P1)

> 원칙은 `CLAUDE.md` P0-3(운영 DB)·P0-4(가격 보호). 운영 DB 변경 절차는 `docs/DEPLOYMENT.md` 6장, 원격 관리는 `docs/REMOTE-OPS.md`.

## 1. DB 접근 권한 기준 (모든 작업 공통)
**승인 없이 허용 (읽기 전용)**: DB 접속·연결 확인 / 구조 조회(`SHOW TABLES`, `SHOW CREATE TABLE`, `DESCRIBE`, `information_schema`) / `SELECT` 조회·집계(`COUNT`·`SUM` 등) / 재고 등 개발에 필요한 읽기 전용 확인·분석

**반드시 사전 승인**: `INSERT`·`UPDATE`·`DELETE`·`REPLACE` / `CREATE`·`ALTER`·`DROP`·`TRUNCATE`·`RENAME` / 마이그레이션·초기화·`db-tools` 실행 / 운영 데이터 대량 추출(덤프·전체 행 내보내기)·외부 전송 / `GRANT`·`REVOKE`, 서버·방화벽·접속 설정 / 부작용 가능한 `CALL`·`SELECT ... FOR UPDATE`·잠금 등

**조회 시 지킬 점**
- 집계 위주로, 상세 행은 필요한 컬럼·건수만(`LIMIT`). 원격 관리 API 로는 대량 추출(덤프 내려받기 등)을 하지 않는다.
- 개인정보(이름·연락처·주소·이메일)는 꼭 필요할 때만 조회하고 결과를 문서·로그·커밋에 남기지 않는다. 접속 인증정보는 출력·기록하지 않는다.
- 접속이 안 되면 서버 설정·방화벽·계정 권한을 바꿔 우회하지 않는다. 원인을 보고하고 phpMyAdmin 용 조회 SQL 을 안내한다.
- 읽기인지 변경인지 애매한 쿼리는 승인 필요 작업으로 취급한다.

## 2. 변경 작업 규칙
- 운영 변경 SQL 은 파일로 작성해 제시하고, 실행은 승인 후(사용자가 phpMyAdmin 에서 직접, 또는 원격 관리 API 로 관리자 화면 승인 후).
- 스키마 변경은 `database/` 에 새 마이그레이션 `YYYYMMDD_설명.sql` 로 추가한다(기존 파일 수정 안 함). 가능한 한 **추가형** — 삭제·이름 변경·타입 축소는 데이터 손실 위험을 설명하고 별도 승인.
- 위험한 변경(`DROP`, `TRUNCATE`, `WHERE` 없는 `UPDATE`/`DELETE`, `ALTER ... DROP`): ① 변경 전 **백업**(phpMyAdmin 내보내기 또는 `mysqldump`) 확인 ② 영향 행 수 `SELECT COUNT(*)` ③ **롤백 SQL** 준비.
- 코드가 새 컬럼을 쓰면 **DB 반영 → 코드 배포** 순서. 컬럼이 없을 때 코드가 깨지는지 확인한다.
- `scripts/db-tools/*.php` 는 일회성 보정 도구 — 웹루트에 올리지 않고 실행은 승인 후에만.
- 주문·견적·회원 데이터는 삭제보다 상태 변경(소프트 삭제)을 먼저 검토한다.

## 3. 데이터 생성·구조 변경 제한
- 실제 데이터가 없거나 구조가 불명확하면 **임의의 데이터를 만들지 않는다**(값을 추측해 채우거나 하드코딩하지 않음). 이름을 모르는 값은 묻는다.
- 기존 테이블·컬럼·데이터 구조를 사용자 승인 없이 만들거나 고치거나 지우지 않는다. 구조 변경이 필요하면 현재 구조와 필요성을 분석해 보고하고 승인 후 진행(C등급). 필요 없으면 기존 구조를 최대한 활용한다.
- 테스트용 가상 데이터는 사전 승인 후, 실제 데이터와 **명확히 구분**(로컬 DB 전용, `[TEST]` 등 식별 표시)한다. 운영 DB 에는 넣지 않는다.
- **임시값 데이터**(상용콘크리트 `description` 이 `[임시값]` 으로 시작하는 행 등, 사용자 결정 2026-11-11)는 실제 상품 데이터와 구분해 표시를 유지한다. Claude 는 임시값·테스트 데이터를 운영에 넣거나 노출을 넓히는 작업(적재·반영·전환·활성화)을 **자동으로 하지 않는다** — 매번 사용자의 명시 결정이 필요하다.
- DB 미연결 시 데모 대체(`app/products_demo_data.php` 등)는 운영 장애 대비 동작이므로 임의로 지우지 않는다.

## 4. 가격 보호 상세 (P0-4)
- **대상 컬럼(운영 확인 2026-11-04 + 이후 표)**: `products.price_b2c·price_b2b·cost_price`, `categories.discount_customer·discount_corporate`, `product_vendors.supply_price`, `estimates.shipping_fee·discount_amt`, `estimate_items.unit_price·adjusted_price`, `order_items.unit_price`, `orders.total_amount`, `payments.amount`, `external_products.ref_price·price_basis`, `syc_products.price_b2c·price_b2b·cost_price`, `syc_categories.discount_customer·discount_corporate` 및 이름 규칙(P0-4)에 맞는 모든 컬럼.
- **가격 계산 입력**: `products.category_id`(적용 마진율), `categories.parent_id`(마진율 상속), `syc_products.category_id`·`syc_categories.parent_id`(상용콘크리트 같은 규칙, `app/syc_catalog.php`). 회원 역할(`users.role`)은 가격 등급을 정하지만 회원 관리 기능이라 보호 대상이 아니다.
- **서버 차단**(`app/remote_ops/price_guard.php`): 가격 컬럼 쓰기(UPDATE·INSERT 새 행·REPLACE·ON DUPLICATE KEY UPDATE·SQL 파일), 가격 계산 입력 변경, 가격 표 행 삭제·CASCADE, 가격 표 DDL(ADD COLUMN·ADD/DROP INDEX 외), 트리거 표 쓰기·저장 함수 호출, 가격 표 백업 복원·가격 되돌리기. 다른 컬럼 요청에 가격 컬럼이 섞이면 요청 전체를 막는다(일부만 실행 안 함).
- **규칙은 서버 차단보다 엄격하다.** 서버가 통과시켜도 P0-4 에 걸리면 하지 않는다. 알려진 빈틈(개선 과제, 2026-11-12 기록 — **이번에는 수정하지 않음**, 수정 시 C등급 + 관리 API 파일은 사용자 FileZilla 업로드):
  - **G1** 가격 표에 가격 이름 컬럼 추가(`ADD COLUMN price_vat …`)를 허용함
  - **G2** 가격 표의 `ADD UNIQUE`·`DROP INDEX`(UNIQUE 포함)를 허용함 — 공급가(`public/api/staff/vendors.php`)·일괄 등록(`app/syc_bulk.php`)·사이트 반영(`app/syc_publish.php`, `categories.slug`)·외부 수집(`scripts/external/import.php`)이 UNIQUE 키 기반 `ON DUPLICATE KEY UPDATE` 에 기대므로 가격 행 중복·덮어쓰기로 이어질 수 있음
  - **G3** `DROP INDEX … ON <가격 표>` 문장형도 허용함(G2 와 같은 영향)
  - **G4** `syc_products.category_id`·`syc_categories.parent_id` 가 보호 목록(`ROPS_PRICE_INPUT_COLUMNS`)에 없음
- **기존 가격 관리 기능(유지 — P0-4-3)**

| 기능 | 파일(권한) | 바꾸는 가격 값 |
|---|---|---|
| 관리자 상품 관리 | `public/api/admin/products.php` (admin) | `price_b2b`·`price_b2c`·`cost_price`·`category_id` |
| 관리자 카테고리 | `public/api/admin/categories.php` (admin) | 마진율·`parent_id` |
| 직원 업체 공급가 | `public/api/staff/vendors.php` (staff) | `supply_price` |
| 직원 견적 조정 | `public/api/staff/estimates.php` (staff) | `adjusted_price`·`shipping_fee`·`discount_amt` |
| 주문·결제 저장 | `public/api/order.php` | 주문·결제 금액(새 행) |
| 상용콘크리트·업체 일괄 등록·제품 등록 | `app/syc_bulk.php`, `public/api/admin/syc-products.php` (admin) | `syc_products` 가격 칸(덮어쓸 때도 엑셀 값, 업체별 — 11-13) |
| 케이토목 일괄 등록 | `app/ktomok_bulk.php`, `public/api/admin/products.php` (admin) | `products` 가격 3칸·`category_id`(엑셀 값, 11-13) |
| 상용콘크리트 사이트 반영·전환 | `app/syc_publish.php`, `public/api/admin/syc-catalog.php` (admin) | `products` 가격·`category_id`, `categories` 마진율·`parent_id`(원본 그대로 복사) |

- 위 기능의 가격 저장 경로를 시험할 때는 별도 `_test` DB 나 `[TEST]` 행에서만 하고, 끝나면 재적재한다(운영 복제 DB 의 가격은 운영 지문 대조 기준).

## 5. 실제 데이터 기반 개발·검증 (상세: `docs/03-analysis/testdb/README.md` 11장)
1. **적용 대상** — 상품·카테고리·가격·재고·업체·회원(등급·권한)·견적·주문 등 DB 데이터에 따라 결과가 달라지는 기능은 **처음부터 실제 데이터로** 개발·검증한다.
2. **원천** — 운영 DB 에 직접 붙어 개발하지 않는다(운영 DB 는 서버 내부 전용, 클라우드 실행 환경은 3306 차단). 운영 데이터를 내보내 **로컬 테스트 DB**(`tomokmall_test`, `127.0.0.1`·`localhost` 만)에 적재해 쓴다. 내보내기는 대량 추출이므로 **매번 승인 후 사용자가 phpMyAdmin 에서** 한다(`10_refresh_checks.sql`·`02_prod_export_selects.sql`·선택 `03_prod_export_pricing.sql` → `bash scripts/testdb/devdb.sh refresh …`).
3. **최신성** — 작업 시작 때 복제본 기준일·대상 테이블을 보고하고, 그 뒤 관련 운영 데이터·구조가 바뀌었으면 재복제를 요청한다.
4. **개인정보** — 개인정보(이름·연락처·주소·이메일·비밀번호 해시)와 매입가·업체 연락처는 복제하지 않거나 NULL·0·가명으로. 로그인 확인은 `[TEST]` 계정(`scripts/local-test-accounts.php`).
5. **파일** — 내보낸 CSV·덤프·생성 DDL·테스트 DB 비밀번호는 저장소·운영 서버·`database/` 에 두지 않는다(세션 scratchpad 등, 작업 후 삭제). `.env.local`·`.devdb/` 값은 커밋·출력하지 않는다.
6. **즉시 검증** — 로컬 서버를 복제 DB 에 연결해 화면을 직접 열어 보고(권한별·기기별), 기대값은 **독립 SQL** 로 계산해 대조한다(`scripts/testdb/verify-*.php`). 복제 DB 를 바꾸는 테스트는 별도 `_test` DB 에서 하거나 끝난 뒤 재적재한다.
7. **데모·목업은 예외** — 실제 데이터 연결이 불가능할 때만 임시로 쓰고, 보고에 "데모 데이터로 확인함"과 확인 못 한 항목을 구분한다.
8. **연결이 안 될 때** — 원인(컨테이너, `.env.local`, 복제본 없음, 네트워크 등)을 확인하고 연결 환경 구축 방법을 먼저 보고한다. 운영 인증정보로 우회하지 않는다.
9. **클라우드 세션** — 컨테이너가 매번 새로 만들어진다. 시작 때 `bash scripts/testdb/devdb.sh status`, 비어 있으면 최신 스냅샷(`tomokmall_devdb_*.sql.gz`) 첨부를 요청해 `devdb.sh restore <파일>`(컨테이너·`.env.local`·`[TEST]` 계정까지 자동). 오래됐거나 없으면 재복제 요청.
