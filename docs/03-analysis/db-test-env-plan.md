# 작업별 상세 목록 오류 점검 · 테스트 DB 구축 방안 · 배포 DB 검증 절차

- 작성: 2026-10-23 / 브랜치 `claude/confident-clarke-dncf12`
- 범위: 상세 목록(products.php?category=ID&work=키) 코드 오류 수정, 테스트 DB↔운영 DB 차이 **분석·방안만**
- **운영 DB 접근·복제·수정, 테스트 DB 재구축은 하지 않았다**(사전 승인 대상). 운영 DB는 이 클라우드 환경에서 접속 불가(DEPLOYMENT.md 6장)이므로
  분석 근거는 저장소의 운영 스냅샷(`work-types-v4-categories-245.csv`, 운영 카테고리 245개)과 스키마·임포트 파일이다.
- 운영 사이트는 공개 페이지 3건만 일반 방문자로 조회(GET)해 이 기능이 아직 배포되지 않았음을 확인했다.

---

## 1. 상세 목록 오류

### 1-1. 자재 종류를 바꿔도 목록이 '인조화강블록(투수)'로 고정

| 항목 | 내용 |
|---|---|
| 재현 | DB 연결이 안 되는 환경(로컬 MySQL 미실행, `.env.local` 값 불일치 등)에서 `/products.php?category=69&work=paving` → `category=72&work=paving` |
| 증상 | 좌측 체크는 바뀌는데(자재명은 설정값으로 표시) 우측 10행이 모두 '인조화강블록(투수)' |
| 원인 | `products.php`는 **DB 미연결 시 Figma 데모 데이터**(`app/products_demo_data.php`)를 보여준다. 데모 상품 10건이 전부 '인조화강블록(투수)'라 어떤 카테고리를 눌러도 같은 목록이 나온다. 작업별 목록에도 이 데모 대체가 그대로 적용된 것이 문제 |
| 판정 | 코드 문제(데모 대체가 작업별 목록에 적용됨) + 환경 문제(그 환경에서 DB 연결 실패). DB가 연결된 환경에서는 카테고리별로 정상 조회됨(이전 작업에서 로컬 DB로 확인) |

### 1-2. 필터 패널에 '재고'만 표시

| 원인 | 설명 |
|---|---|
| ① 변수 사용 순서 버그(기존 코드) | `$demoMode`를 151·155행에서 읽고 160행에서 정의 → 항상 '비데모'로 판단(`Undefined variable` 경고). DB 미연결인데도 필터 패널이 그려지고, 파세트는 DB가 없어 비어 있으니 **항상 표시되는 '재고' 섹션만** 남음 |
| ② 설계상 숨김(정상 동작) | DB가 연결돼도 필터는 "현재 결과를 실제로 좁힐 수 있는 그룹만" 보인다(`product_filters_visible`). 한 카테고리 목록은 재질(루트)이 하나라 **재질은 항상 숨김**, 형상/옵션은 상품명에 사각·원형·U형·유공·앵글부착·인터로킹이 있을 때만, 규격은 `spec`의 `규격:` 값이 2종 이상일 때만 표시. 이는 기존 `products.php?category=ID`와 **완전히 같은 동작**이다 |
| ③ 테스트 데이터 차이 | 저장소 데이터로 만든 DB는 카테고리 계층·루트 ID가 운영과 달라 재질 개수가 틀리고, 재고가 전부 0이다(2장) |

### 1-3. 수정 내역 (`public/products.php`, DB 연결 시 동작 변화 없음)

1. `$demoMode` 정의를 파세트·사이드바 판단 **앞으로 이동**(기존 버그 수정, 경고 제거)
2. 작업별 목록은 데모 대체 제외(`&& !$workType`) → 선택 자재와 무관한 상품을 보여주지 않음
3. DB 미연결이면 필터 패널 숨김(`db() !== null`), 빈 목록에 "상품 정보를 불러오지 못했습니다." 안내
4. 작업별 목록에서 카테고리 행을 못 읽으면 제목을 설정의 자재명으로 표시

DB 연결 시: `$demoMode`는 수정 전후 모두 false, `db() !== null`은 true, 새 안내 문구는 DB 미연결일 때만 → **조회·필터·정렬 결과 동일**.
DB 미연결 시 일반 목록·검색: 데모 데이터는 그대로, 필터 패널만 원래 의도대로 숨김.

### 1-4. 검증

| 항목 | 결과 |
|---|---|
| `php -l` (public·app 전체) | 오류 없음 |
| `php tests/functions.test.php` | 105 통과 / 0 실패 (신규 3건: 작업별 목록 필터 = 기존 필터, 4그룹 표시, 차이는 `work` 유지뿐) |
| DB 미연결 재현(수정 전) | 69·72 모두 데모 10행, 필터 '재고'만, `$demoMode` 경고 6건 |
| DB 미연결(수정 후) | 체크 자재 정상, 데모 행 없음·안내 문구, 필터 숨김, 일반 목록·검색은 데모 유지, 경고 0건 |
| 화면 | 1440·360px 확인. 360px 가로 스크롤 34px는 기존 헤더(`header-nav`) 문제(이번 변경과 무관) |
| DB 연결 상태 통합 테스트 | **이번에는 실행하지 않음** — 테스트 DB 재구축이 사전 승인 대상이라 보류(승인 시 `stock_filter`·`favorites_api` 테스트와 화면 확인 재실행) |

---

## 2. 테스트 DB ↔ 운영 DB 차이 분석

"테스트 DB" = 저장소 파일(`database/schema.sql` + 마이그레이션 + `data/ktomok`·`data/dacity` import)로 새로 만든 DB(로컬 Homebrew·Docker 공통).

### 2-1. 스키마

| 구분 | 차이 | 영향 |
|---|---|---|
| MariaDB 전용 문법 | `ADD COLUMN/INDEX IF NOT EXISTS` 22문장(6파일: `add_vendors` 8, `add_ai_sessions` 4, `alter_estimates_v2` 4, `20260604_dacity_columns` 4, `add_ai_orders` 1, `add_pricing` 1). **MySQL 8.0에서는 문법 오류** | 운영(문서상 MySQL 8.0)에 같은 파일을 실행하면 실패. 운영에는 다른 방법으로 반영됐을 가능성 → 운영 실제 구조 확인 필요(`SELECT VERSION()`, `SHOW CREATE TABLE`) |
| 코드가 쓰지만 CREATE 없는 테이블 | `home_featured`(index.php), `category_gallery`(products.php) | 테스트 DB엔 없음 → try/catch로 빈 결과. 운영에 있다면 테스트에서 해당 화면이 다름 |
| 실행 순서 의존 | `local-init.sql`이 `users` 생성 전 INSERT, `add_reorder.sql`이 `users` FK | 순서대로 안 하면 실패. 정해진 적용 순서 문서가 없음 |

### 2-2. 카테고리 ID

| 구분 | 운영(스냅샷) | 저장소로 만든 테스트 DB |
|---|---|---|
| 토목 대분류 루트 | **11~16** (철근콘크리트·콘크리트·스틸·석재·PE/PVC·기타) | `schema.sql`이 ID 없이 INSERT → **1~6** |
| 건축 대분류 | 1~8 | 없음 |
| 전체 | 245개, 최대 ID 281, 3단계(대 14 / 중 51 / 소 180) | ktomok 213개 + dacity 1개, **2단계**(루트 → 품목, 중분류 없음) |
| ID 부여 | 운영 실제 값 | 자동 증가(삽입 순서) → 운영과 다름. 예: 23 = 운영 '벤치플륨관(벤치2종)' / 테스트 '측구수로관A형(앵글미부착)' |

코드에 운영 ID가 고정된 곳: 재질 필터 `FLT_MAT_LABELS`(11~16), 상단 메뉴(`layout.php` `IN (11,12,13,14,15)`), products.php 상위분류 판단(11~15), `app/work_types.php` 대표 자재 **95개 카테고리 ID**.
→ 저장소로 만든 DB에서는 재질 필터·상단 메뉴·작업별 자재 연결이 운영과 다르게 보인다. 운영 스냅샷과 `work_types.php`의 ID·이름은 **전부 일치**(검사 결과 불일치 0).

### 2-3. 상품 연결 관계

| 항목 | 차이 |
|---|---|
| 상품→카테고리 | 테스트는 slug(`ktomok-3425` 등) 기준 자동 ID. 같은 이름 204개 중 **112개가 운영과 다른 대분류** 아래(예: 원심력사각수로관·흄관 → 테스트 '콘크리트제품' / 운영 '철근콘크리트제품'). 이름이 운영에 없는 카테고리 9개 |
| dacity | '1련암거'를 **루트**로 생성(운영은 ID 9, 2단계) |
| 하위 포함 조회 | 목록은 `category_descendants()`로 하위 전체 포함 → 계층이 다르면 같은 카테고리라도 상품 수가 달라짐 |
| category_relations·업체(vendors/product_vendors)·갤러리 | 테스트 데이터 없음 → 유사/공정연결 제품, 업체명 컬럼이 비어 보임 |

### 2-4. 필터 속성

| 필터 | 근거 데이터 | 테스트 DB 상태 |
|---|---|---|
| 재질 | 카테고리 루트 ID(11~16) | 루트가 1~6이라 **집계 0 → 항상 숨김** |
| 규격 | `products.spec`의 `규격: 값` | ktomok 1,452건 모두 있음 / dacity 10건 없음(`중량/단위:` 형식) |
| 형상/옵션·하중 | 상품명 키워드 | 운영과 동일 이름이면 동일 |
| 재고 | `products.stock_qty > 0` | import 전부 0 → '재고 있는 상품만'은 **항상 0건** |

운영의 규격 형식·재고 분포는 이 환경에서 확인 불가 → 2-6의 조회 SQL로 확인 필요.

### 2-5. 운영에서 확인해야 할 미확정 사항

1. DB 엔진·버전(MySQL 8.0인지 MariaDB인지) — 마이그레이션 문법 판단
2. 실제 테이블·컬럼 구조(특히 `products`, `estimates`, `vendors`, `home_featured`, `category_gallery`)
3. 카테고리 245개 스냅샷 이후 변경 여부(ID·이름·활성·부모)
4. 상품 수·카테고리별 분포, `규격:` 형식 비율, 재고 > 0 상품 수

### 2-6. 운영 확인용 조회 SQL (읽기 전용 — **승인 후 사용자가 phpMyAdmin에서 실행**, 결과는 집계값만 공유)

```sql
-- (1) 엔진·버전
SELECT VERSION();
-- (2) 구조 지문: 테이블·컬럼·타입 (개인정보 없음)
SELECT table_name, column_name, column_type, is_nullable, column_key
  FROM information_schema.columns WHERE table_schema = DATABASE()
 ORDER BY table_name, ordinal_position;
-- (3) 카테고리 지문(스냅샷과 비교)
SELECT COUNT(*) AS cnt, MAX(id) AS max_id, SUM(is_active) AS active,
       SUM(CRC32(CONCAT_WS('|', id, name, IFNULL(parent_id, ''), is_active))) AS fp
  FROM categories;
-- (4) 상품 연결·필터 속성 집계
SELECT COUNT(*) AS products, SUM(is_active) AS active,
       SUM(spec LIKE '%규격:%') AS has_spec_label, SUM(stock_qty > 0) AS in_stock,
       COUNT(DISTINCT category_id) AS used_categories,
       SUM(category_id NOT IN (SELECT id FROM categories)) AS orphan
  FROM products;
-- (5) 루트별 상품 수(재질 필터 근거)
WITH RECURSIVE t AS (
  SELECT id, id AS root_id FROM categories WHERE parent_id IS NULL
  UNION ALL
  SELECT c.id, t.root_id FROM categories c JOIN t ON c.parent_id = t.id
)
SELECT t.root_id, COUNT(p.id) AS cnt
  FROM t JOIN products p ON p.category_id = t.id AND p.is_active = 1
 GROUP BY t.root_id ORDER BY t.root_id;
```

---

## 3. 운영과 같은 구조의 별도 테스트 DB 구축 방안 (제안 — 실행은 승인 후)

### 3-1. 원칙

- **완전 분리**: 로컬(Docker 또는 Homebrew)의 별도 DB `tomokmall_test`, 별도 계정·비밀번호. 닷홈 서버에 만들지 않는다. 운영 접속정보를 `.env.local`에 넣지 않는다.
- 기존 안전장치 재사용: 테스트 스크립트(`tests/*_test.php`, `scripts/local-test-accounts.php`)는 **DB 이름 `_test` + 로컬 호스트**일 때만 동작.
- **구조는 운영 그대로, 데이터는 참조성 테이블만**. 개인정보·주문·대화 기록은 가져오지 않는다.
- 비식별화는 **운영에서 내보낼 때 SELECT로 처리**해 원본 개인정보가 운영 밖으로 나오지 않게 한다.

### 3-2. 테이블별 처리

| 처리 | 테이블 |
|---|---|
| 구조 + 데이터 그대로 | `categories`, `category_relations`, `products`, `category_gallery`, `category_consult_guide`, `home_featured`, `rag_documents` |
| 구조 + 비식별 데이터 | `vendors`: `name`·`region`·`is_active`만 유지, `contact`·`phone`·`fax`·`email`·`address`·`memo` → NULL |
| 구조 + 판단 필요 | `product_vendors.supply_price`(매입가, 영업 민감): ① 그대로(로컬 한정) ② NULL — **사용자 결정** |
| 구조만(데이터 제외) | `users`, `estimates`, `estimate_items`, `orders`, `order_items`, `payments`, `shipping_info`, `sites`, `site_orders`, `chat_logs`, `ai_sessions`, `ai_orders`, `favorite_products`, `reorder_alerts`, `reorder_history`, `search_logs` |
| 테스트 계정 | `scripts/local-test-accounts.php --apply`로 `[TEST]` 계정만 생성(값은 `.env.local`) — 승인 후 |

### 3-3. 절차

1. (승인) 사용자가 phpMyAdmin에서 **구조만 내보내기**(전체 테이블, 데이터 없음) → `tomokmall_test`에 적용
2. (승인) 참조성 테이블 **데이터 내보내기**. `vendors`는 아래 SELECT 결과를 SQL로 내보내기

   ```sql
   SELECT id, name, NULL AS contact, NULL AS phone, NULL AS fax, NULL AS email, region,
          NULL AS address, NULL AS memo, is_active, created_at, updated_at FROM vendors;
   ```
3. 로컬에 적용 후 2-6의 (3)~(5)를 테스트 DB에서도 실행해 **운영 결과와 값이 같은지** 비교(카테고리 지문 `fp`·상품 수·재고 수·루트별 개수)
4. 내보낸 파일은 Git·`backup/`에 두지 않고 적용 후 삭제(`.gitignore`로 막혀 있어도 저장 금지)
5. 갱신: 운영 카테고리·상품이 바뀌면 같은 절차로 재구축(승인)

### 3-4. 저장소로 만드는 DB의 개선 제안 (별도 승인 시)

운영 덤프 없이도 구조를 맞추려면: `schema.sql` 루트 6개를 운영 ID(11~16)로 명시, MariaDB 전용 문법을 MySQL 8.0 호환으로 바꾼 **새 마이그레이션 파일** 추가(기존 파일 수정 금지 규칙), `home_featured`·`category_gallery` CREATE 문 추가, 적용 순서 문서화.
단, 운영 카테고리 ID·계층을 저장소 import로 재현하는 것은 불가능에 가까우므로 **3-3(운영 구조·참조 데이터 복제)을 권장**한다.

---

## 4. 배포 안정성 검증

### 4-1. 이번 브랜치(origin/main..HEAD) 점검 결과

| 항목 | 결과 |
|---|---|
| 운영 DB 쓰기 SQL 추가 | 없음 (추가 쿼리는 기존 `work_type_category_info()`의 SELECT만) |
| 마이그레이션(`database/*.sql`) 변경 | 없음 → **코드 배포만으로 동작**, 운영 DB 변경 불필요 |
| 새로 참조하는 테이블·컬럼 | 없음 (`categories`·`products` 기존 컬럼만) |
| 운영 데이터 호환 | 작업별 자재 95개 ID·이름이 운영 스냅샷과 일치. 운영에서 카테고리가 삭제·비활성되면 `work_type_materials()`가 자재 종류 목록에서 제외, 잘못된 `work` 값·카테고리는 일반 목록으로 처리(오류 없음) |
| 남은 위험 | 스냅샷 이후 운영 카테고리 변경 여부 미확인(2-6 (3)으로 확인 권장) |

### 4-2. 배포 전 DB 영향 점검 절차 (신규 `scripts/predeploy-db-check.sh`)

DB·서버에 접속하지 않고 Git 변경만 검사한다.

```bash
bash scripts/predeploy-db-check.sh <운영에 배포된 커밋> HEAD   # 기본: origin/main..HEAD
```

| 검사 | 걸리면 |
|---|---|
| [1] 배포 대상(public·app·database) 변경 파일 목록 | 배포 목록 확인용 |
| [2] 추가된 PHP 줄의 INSERT/UPDATE/DELETE/REPLACE/CREATE/ALTER/DROP/TRUNCATE/RENAME | 쓰기 경로 검토 |
| [3] `database/*.sql` 추가·변경 + MariaDB 전용 문법·DROP/TRUNCATE·WHERE 없는 DELETE/UPDATE | DEPLOYMENT.md 6장 절차(백업·롤백 SQL·승인) |
| [4] 추가된 PHP 줄이 참조하는데 저장소 SQL에 CREATE가 없는 테이블 | 운영 존재 여부 확인 |

종료 코드 0 = DB 영향 없음(코드 배포만), 1 = 보고·승인 필요. 이번 브랜치: **0**.
가짜 커밋(MariaDB 문법·DROP·WHERE 없는 DELETE·PHP INSERT·미정의 테이블)으로 4가지 모두 검출되는 것을 확인했다.

권장 배포 순서(DEPLOYMENT.md 7장 체크리스트에 추가):
1. `php -l` 전체 + `php tests/functions.test.php`
2. `scripts/predeploy-db-check.sh` → 0이면 코드만 배포, 1이면 항목 보고 후 승인
3. (테스트 DB 구축 후) `tomokmall_test`에서 통합 테스트·주요 화면 확인
4. 배포 승인 → `deploy-files.sh` → 스모크

---

## 5. 승인이 필요한 다음 단계

| 작업 | 상태 |
|---|---|
| 2-6 운영 조회 SQL 실행(사용자, phpMyAdmin) | 승인 대기 |
| 3-3 운영 구조·참조 데이터 내보내기 및 `tomokmall_test` 구축 | 승인 대기 |
| `product_vendors.supply_price` 포함 여부 | 결정 필요 |
| 3-4 저장소 스키마 개선(새 마이그레이션) | 제안 |
| `main` 병합·운영 배포 | 하지 않음 |
