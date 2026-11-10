# 신규 상품 가격 구조 설계 — 상품 일괄 등록(통합 ZIP)

> 상태: **설계안**(2026-11-10). DB 변경 · `app/pricing.php` 변경 · 가격 INSERT · 기존 상품 가격 변경은 하지 않았다.
> 실제 개발(칸 추가 · 가격 조회 함수 · 일괄 등록 API · 최초 INSERT 허용)은 **별도 개발 승인 대상**이다(7장).
> 사용자 결정(2026-11-10): 신규 상품은 상품 단위로 가격을 관리한다. 기존 상품은 기존 계산 정책을 그대로 쓴다. 반올림 1원.

## 1. 현재 구조와 한계(코드 근거)

| 칸 | 지금 뜻 | 근거 |
|---|---|---|
| `products.price_b2c` | 정가 = 비로그인가(저장값). 0 이면 ‘견적문의’ | `app/pricing.php` price_bundle · price_tag_list |
| `products.cost_price` | 원가(매입가). 회원가 계산 기준, 지정 계정(internal)만 표시 | price_bundle |
| `products.price_b2b` | 이름은 기업가지만 **price_bundle 은 쓰지 않음**. 기업 즉시견적 · 관심상품(기업) · 기업 홈 · 카테고리 API 만 사용(0 이면 정가) | `app/cart.php` quick_items_fetch, `app/corp_home.php`, `public/api/estimate/favorites.php` · `quick-items.php`, `public/api/products/by-category.php` |
| `categories.discount_customer` · `discount_corporate` | 이름은 할인율이지만 실제로는 **원가 대비 마진율(%)**, 상위 카테고리 상속, 0~1000 | category_discount_map |
| (없음) | 개인 · 기업 회원가는 **저장하지 않는다** — 화면마다 `round(원가 × (100 + 마진율) / 100)`, 원가 0 이면 정가 | price_bundle |

현재 화면 · 기능별 가격(기업가가 세 갈래):

| 화면 · 기능 | 비로그인 | 개인회원 | 기업 · 직원 · 관리자 |
|---|---|---|---|
| 상품 목록 · 상세 | price_b2c | 원가 × 개인 마진(없으면 정가) | 원가 × 기업 마진(없으면 정가) |
| 장바구니 · 견적 화면 표시, AI 상담 카드(price_member) | price_b2c | min(개인회원가, 정가) | min(기업회원가, 정가) |
| 기업 즉시견적 · 기업 홈 · 관심상품(기업) · 카테고리 API | — | 관심상품: 개인회원가 | **price_b2b**(0 이면 정가) |
| 주문 저장(order_items.unit_price) · 견적 저장(estimate_items.unit_price) | **price_b2c** | **price_b2c** | **price_b2c** |

한계:
1. 상품별 개인회원가를 저장할 칸이 없다 → 유형 B(업체가 준 개인가)를 담을 곳이 없고, 유형 A 의 20% · 10%도 카테고리 마진율과 다르게 줄 수 없다.
2. 카테고리 마진율을 바꾸면 그 카테고리의 모든 상품 회원가가 함께 바뀐다(신규 상품 가격을 고정할 수 없음).
3. 기업가가 경로마다 다르다(원가 × 기업 마진 / price_b2b / price_b2c).
4. 주문 · 견적 저장 단가는 등급과 관계없이 정가다(표시 금액과 저장 금액이 다를 수 있음 — 기존 동작, 이번 설계에서 바꾸지 않음).

## 2. DB 변경안(추가형 — 기존 칸 · 값 불변, 승인 후)

```sql
-- products: 상품별 회원가(신규 상품만 값이 들어감). NULL 이면 지금 방식 그대로.
ALTER TABLE products
  ADD COLUMN price_mode ENUM('legacy','product') NOT NULL DEFAULT 'legacy'
      COMMENT 'legacy=카테고리 마진 계산(기존 상품), product=상품별 저장 가격(일괄 등록 신규 상품)' AFTER cost_price,
  ADD COLUMN price_customer INT UNSIGNED NULL COMMENT '상품별 개인회원가(부가세 별도). price_mode=product 일 때만' AFTER price_mode,
  ADD COLUMN price_corporate INT UNSIGNED NULL COMMENT '상품별 기업회원가(부가세 별도). price_mode=product 일 때만' AFTER price_customer;

-- 가격 출처 기록(추가만 하고 고치지 않음): 어떤 파일 · 유형 · 마진율로 처음 넣었는지
CREATE TABLE product_price_sources (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED NOT NULL,
  import_batch_id INT UNSIGNED NULL COMMENT '일괄 등록 작업 번호(등록 작업 표 — 별도 설계)',
  price_type ENUM('A_cost_only','B_vendor_prices') NOT NULL,
  cost_input INT UNSIGNED NULL, guest_input INT UNSIGNED NULL, customer_input INT UNSIGNED NULL, corporate_input INT UNSIGNED NULL,
  rate_guest TINYINT UNSIGNED NULL, rate_customer TINYINT UNSIGNED NULL, rate_corporate TINYINT UNSIGNED NULL COMMENT '유형 A 만(43·20·10)',
  rounding ENUM('round_1won') NOT NULL DEFAULT 'round_1won',
  vat_basis ENUM('excluded') NOT NULL DEFAULT 'excluded',
  created_by INT UNSIGNED NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_pps_product (product_id), KEY idx_pps_batch (import_batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

- `price_b2b` 를 개인 · 기업가 저장에 재사용하지 않는다: 기존 상품에 이미 값이 있고(수집 데이터는 정가와 같은 값) 경로마다 뜻이 다르다.
- 다만 **신규 상품을 넣을 때 `price_b2b = price_corporate` 도 함께 넣는다** — 조회 함수로 통일하기 전에도 즉시견적 · 관심상품 등 price_b2b 경로가 같은 기업가를 보게 하는 전환기 안전장치.
- 새 칸은 이름에 price 가 들어가므로 원격 관리 API 가격 보호(`rops_is_price_column`)가 자동으로 막는다(원격으로 신규 가격을 넣거나 바꿀 수 없음 — 의도대로).
- 인덱스는 필요 없다(검색 조건이 아님).

## 3. 입력 · 자동 계산 · 저장

| | 유형 A — 원가만 | 유형 B — 원가 + 판매가 3종 |
|---|---|---|
| 엑셀 입력 | cost_price | cost_price · 비로그인 · 개인 · 기업 판매가 |
| 계산 | 비로그인 = round(원가 × 143 / 100), 개인 = round(원가 × 120 / 100), 기업 = round(원가 × 110 / 100) — **현재 price_bundle 과 같은 원가 가산(마크업) 식 · 같은 반올림** | 하지 않음(43 · 20 · 10 재적용 금지) |
| 저장 | price_b2c=비로그인, price_customer=개인, price_corporate=기업, price_b2b=기업, cost_price=원가, price_mode='product' + 출처 1행(마진율 43 · 20 · 10) | 같은 칸에 업체 값 그대로 + 출처 1행(마진율 NULL) |
| 누락 · 오류 | 원가 없음 · 숫자 아님 → 등록 불가 | 판매가 일부 누락 → **다른 유형으로 바꾸지 않고** ‘검토 필요’로 등록(공개 차단). 숫자 아님 · 0 이하 → 등록 불가. 판매가 &lt; 원가, 회원가 &gt; 비로그인가, ‘VAT포함’ 표기 → 검토 필요 |

- 계산은 **등록할 때 한 번** 하고 정수로 저장한다. 이후 카테고리 마진율이나 43 · 20 · 10 정책이 바뀌어도 이미 등록한 신규 상품 가격은 그대로다(관리자 화면에 ‘상품별 가격’ 표시).
- 반올림: 1원 단위, PHP `round()`(0.5 는 0 에서 먼 쪽) — 지금 회원가 계산과 같다. 예) 원가 18,000 → 25,740 / 21,600 / 19,800.
- 부가세: 모든 판매가는 **부가세 별도**로 저장한다(목록 ‘판매가 부가세별도’, 견적에서 10% 가산 `floor((과세대상 + 5) / 10)` — 지금 동작 유지).
- 같은 업체 · 같은 상품코드 재업로드: 새 상품을 만들지 않고 가격도 바꾸지 않는다(차이는 보고만).

## 4. 조회 규칙(하위 호환)

향후 `app/pricing.php` 에 둘 조회 규칙(변경은 별도 승인):

```
price_mode = 'product'  →  비로그인 = price_b2c, 개인 = price_customer, 기업 = price_corporate   (저장값만 사용)
price_mode = 'legacy'   →  지금과 완전히 같은 계산(카테고리 마진 · 정가 대체 · 각 경로의 price_b2b 규칙)
```

- 등급 대응은 그대로: 비회원 → 비로그인가, 개인 → 개인회원가, 기업 · 직원 · 관리자 → 기업회원가, 지정 계정(internal) → 기업가 + 3종 표시.
- ‘회원가가 정가 이상이면 정가만 표시’ 규칙도 그대로(유형 B 에서 생길 수 있어 검증 단계에서 미리 경고).
- `price_mode='product'` 인데 개인 · 기업가가 비어 있으면 **legacy 로 대체하지 않는다** → 그 상품은 공개 조건(가격 완비)을 못 맞춰 공개되지 않는다.

신규 상품의 화면별 일관성:

| 화면 · 기능 | 조회 함수 적용 후 신규 상품 | 적용 전(전환기) |
|---|---|---|
| 목록 · 상세 · 장바구니 표시 · AI 상담 카드 · 견적 화면 표시 | 저장된 3종 | 카테고리 마진 계산(불일치) → **조회 함수 적용 전에는 신규 상품을 공개하지 않는다** |
| 즉시견적 · 기업 홈 · 관심상품(기업) · 카테고리 API | price_corporate | price_b2b = price_corporate 로 넣어 두었으므로 일치 |
| 주문 · 견적 저장 단가 | 지금 규칙 그대로 price_b2c(등급 무관) | 같음 — 바꿀지는 별도 결정(기존 상품 영향) |

## 5. 기존 가격 기능 회귀 위험과 막는 방법

| 위험 | 방지 |
|---|---|
| 조회 함수 변경이 기존 상품 표시 가격을 바꿈 | legacy 분기는 지금 코드를 그대로 호출. 복제 DB 의 기존 상품 전체(비로그인 · 개인 · 기업 계산값) 지문을 변경 전후 비교해 같아야 배포 |
| 칸 추가로 기존 쿼리 오작동 | 추가형 · NULL/기본값 — `SELECT p.*` 는 칸만 늘고, INSERT 는 열 목록을 명시하고 있어 영향 없음. 데모 대체(products_demo_data.php)는 price_mode 가 없으면 legacy 로 취급 |
| 신규 상품이 계산 경로마다 다른 가격 | 조회 함수 적용 + 공개 전 차단(4장), price_b2b 동기화 |
| 반올림 차이 | 계산 식을 price_bundle 과 같은 식으로, 경계값(…×1.43 이 .5 로 끝나는 원가) 단위 테스트 |
| 카테고리 마진 변경이 신규 상품에 안 먹힘(의도) | 관리자 화면 ‘상품별 가격’ 표시 · 안내 |
| 원격 관리로 가격이 바뀜 | 새 칸 · 새 표 모두 이름 규칙으로 자동 보호(price_guard) |

반영 순서(각각 승인): DB 칸 · 표 추가 → 조회 함수 · 테스트 → 일괄 등록 기능 → 첫 등록은 비공개 → 지문 비교 · 화면 확인 → 공개.

## 6. 가격 수정 금지 정책과 ‘신규 상품 최초 INSERT’ 의 구분(제안 — 사용자 승인 필요)

지금 규칙(CLAUDE.md 0장 12번 · 12장): 기존 가격 데이터는 어떤 경로로도 바꾸지 않는다. 원격 관리 API 는 새 행에 가격 값을 넣는 INSERT 도 막는다.

| 구분 | 제안 |
|---|---|
| 기존 상품 가격(원가 · 정가 · price_b2b · 카테고리 마진 · category_id) | **계속 변경 금지** — 일괄 등록 · 재업로드 · 원격 관리 모두 |
| 신규 상품 **최초 INSERT** 가격 | 관리자 화면 ‘상품 일괄 등록’에서 사람이 검증 결과를 보고 ‘상품 등록하기’를 누를 때만 허용. 작업 단위 기록(product_price_sources) 필수. 원격 관리 API · Claude 의 직접 SQL 로는 계속 금지 |
| 신규 상품 등록 후 가격 변경 | 일괄 재업로드로는 바꾸지 않음. 기존 제품 상세 화면(관리자)의 범위 |
| 이 구분을 CLAUDE.md 에 반영 | 정책 변경이므로 사용자의 명시적 요청 후에만 |

## 7. 별도 개발 승인 대상

1. products 칸 3개 + product_price_sources 표 추가(운영 DB 구조 변경)
2. `app/pricing.php` 조회 규칙(price_mode 분기)과 호출부 정리 · 단위 테스트 · 복제 DB 지문 비교
3. 일괄 등록 API 의 신규 상품 최초 INSERT(가격 포함) 허용 범위 — 6장 표
4. CLAUDE.md 가격 정책 문구 보완

## 8. 남은 결정

- 주문 · 견적 저장 단가(지금 정가)를 등급가로 바꿀지 — 기존 상품까지 영향이 있어 이번 범위 밖
- 유형 B 일부 누락: 시안은 ‘검토 필요로 등록 · 공개 차단’. ‘등록 불가’로 할지 확정 필요
- ‘VAT포함’ 가격을 받았을 때 ÷1.1 환산 여부(지금 설계는 자동 환산하지 않고 확인 요청)
- price_mode 칸 대신 ‘개인 · 기업가 NULL 여부’만으로 구분할지(시안은 명시적 칸 권장 — 반쯤 채워진 상태를 막기 쉬움)
