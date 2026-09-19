# AI 상담 기능 설계서

> 참조 기획서: `docs/01-plan/features/ai.plan.md` (v2.0)
> 작성일: 2026-06-19
> 버전: v2.0 (일반/기업회원 모드 분리 + 재구매·알림 반영)

---

## 1. 아키텍처 및 컴포넌트 구조

### 1.1 변경 범위 요약

| 구분 | v1.0 (이전) | v2.0 (이번) |
|------|------------|------------|
| 진입점 | `chat.php` 단일 | `ai.php` → 모드 분기 |
| 모드 | 자연어 단일 | 일반(자연어) / 기업회원(즉시견적) 분리 |
| 인증 | 없음(세션만) | 기업회원 로그인 추가 |
| 단가 표시 | price_b2c 항상 노출 | 기업회원만 price_b2b 표시 |
| 재구매 | 없음 | 이전 이력 기반 재견적 |
| 마이페이지 | 없음 | 납품 이력 + 재구매 알림 |

### 1.2 파일 분리 계획

```
public/
├── ai.php                          (신규)  AI 상담 시작 화면 — 모드 선택 라우터 ≤80줄
├── login.php                       (신규)  기업회원 로그인 페이지 ≤80줄
├── estimate-quick.php              (신규)  기업회원 즉시견적 페이지 ≤150줄
├── mypage.php                      (신규)  납품 이력 + 재구매 알림 설정 ≤180줄
├── estimate.php                    (수정)  공통 견적 요청서 — 배송 일시 필드 추가 ≤120줄
├── chat.php                        (수정)  일반 모드 — 이전 상담 이력 섹션 추가 ≤200줄
└── api/
    ├── cart.php                    (유지)  장바구니 CRUD
    ├── estimate.php                (수정)  견적 생성 — requested_delivery_at 추가
    ├── auth/
    │   ├── login.php               (신규)  기업회원 로그인 API ≤60줄
    │   └── logout.php              (신규)  로그아웃 API ≤25줄
    ├── products/
    │   └── by-category.php         (신규)  즉시견적용 카테고리별 상품 조회 ≤60줄
    ├── chat/
    │   └── history.php             (신규)  이전 상담 이력 조회 ≤70줄
    ├── estimate/
    │   ├── quick-items.php         (신규)  즉시견적 품목 CRUD ≤120줄
    │   ├── reorder.php             (신규)  이전 견적서 재사용 → 장바구니 적재 ≤80줄
    │   └── favorites.php           (신규)  단골품목 저장·조회·삭제 ≤100줄
    └── mypage/
        ├── delivery-history.php    (신규)  납품 이력 목록 조회 ≤70줄
        └── delivery-alert.php      (신규)  재구매 알림 설정 저장 ≤70줄

app/
├── bootstrap.php                   (유지)  DB, 공용 유틸
├── auth.php                        (신규)  로그인 상태 헬퍼 ≤80줄
├── rag.php                         (유지)  Claude API 스트리밍 + Tool Use
├── ai_tools.php                    (유지)  Tool Use 정의·핸들러
├── chat_renderer.php               (유지)  일반 모드 채팅 UI
└── quick_estimate_renderer.php     (신규)  즉시견적 UI HTML/JS ≤300줄

database/
├── add_users_auth.sql              (신규)  users 테이블
├── alter_estimates_v2.sql          (신규)  estimates 컬럼 추가
└── add_reorder.sql                 (신규)  reorder_alerts, favorite_products 테이블
```

### 1.3 책임 분리 원칙

| 파일 | 책임 | 포함 금지 |
|------|------|----------|
| `public/ai.php` | 모드 선택 UI + 기업회원 상태 분기 라우팅 | 비즈니스 로직, DB 쿼리 |
| `public/estimate-quick.php` | HTTP 라우팅, 기업회원 인증 확인 | UI HTML, 비즈니스 로직 |
| `app/quick_estimate_renderer.php` | 즉시견적 UI HTML/JS 렌더링 | DB 쿼리, API 호출 |
| `app/auth.php` | 로그인 상태 확인, 기업회원 여부 반환 | UI 렌더링, DB 쿼리 직접 수행 |
| `public/api/auth/login.php` | 로그인 처리, 세션 저장 | UI 렌더링 |
| `public/api/products/by-category.php` | 카테고리별 상품 + price_b2b 반환 | UI 렌더링 |
| `public/api/estimate/quick-items.php` | 즉시견적 세션 품목 CRUD | UI 렌더링 |
| `public/api/estimate/reorder.php` | 이전 견적서 → 세션 카트 적재 | UI 렌더링 |
| `public/api/mypage/delivery-alert.php` | 재구매 알림 설정 저장 | UI 렌더링 |

### 1.4 모드 분기 플로우

```
[ai.php] 상담 시작
    ├─ [일반 모드 선택]
    │       ↓
    │   [chat.php] 자연어 검색
    │       ├─ 비로그인: 자연어 입력 → AI 추천 → 장바구니 → [estimate.php]
    │       └─ 로그인: 위 + 이전 상담 이력 패널 표시 → 재견적 원클릭
    │
    └─ [기업회원 모드 선택]
            ↓
        auth.php::is_corporate_member() ?
            ├─ true  → [estimate-quick.php] 즉시견적
            │               ├─ 신규: 제품/규격/수량 선택 → 누적 → [estimate.php]
            │               └─ 재구매: 이전견적서/단골품목/원클릭 → 카트 자동 적재 → [estimate.php]
            └─ false → [login.php] 기업회원 로그인 유도

[estimate.php] 공통 견적 요청서
    → 품목 자동 포함 + 배송 일시 + 신청인 정보
    → POST /api/estimate.php → 견적 완료
```

---

## 2. 데이터 모델

### 2.1 기존 테이블 수정

#### `products` — price_b2b 컬럼 추가
```sql
ALTER TABLE products
    ADD COLUMN price_b2b INT UNSIGNED NOT NULL DEFAULT 0 AFTER price_b2c;
-- 기업회원 단가. 0이면 price_b2c로 폴백.
```

#### `estimates` — 기업회원·상태·배송 컬럼 추가
```sql
ALTER TABLE estimates
    ADD COLUMN user_id               INT UNSIGNED NULL         AFTER id,
    ADD COLUMN mode                  ENUM('chat','quick')
                                     NOT NULL DEFAULT 'chat'   AFTER memo,
    ADD COLUMN status                ENUM('PENDING','CONFIRMED','DELIVERED','CANCELED')
                                     NOT NULL DEFAULT 'PENDING' AFTER mode,
    ADD COLUMN requested_delivery_at DATETIME NULL              AFTER status,
    ADD COLUMN delivery_completed_at DATETIME NULL              AFTER requested_delivery_at,
    ADD INDEX idx_estimates_user   (user_id),
    ADD INDEX idx_estimates_status (status);
-- user_id NULL = 비회원 견적 허용
```

#### `ai_sessions` — user_id 컬럼 추가 (이전 상담 이력 연결)
```sql
ALTER TABLE ai_sessions
    ADD COLUMN user_id INT UNSIGNED NULL AFTER session_key,
    ADD INDEX idx_ai_sessions_user (user_id);
```

### 2.2 신규 테이블

#### `users` — 기업회원 인증
```sql
CREATE TABLE users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    role          ENUM('customer','corporate','staff','admin')
                  NOT NULL DEFAULT 'customer',
    name          VARCHAR(80)  NOT NULL,
    email         VARCHAR(160) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    phone         VARCHAR(40)  NULL,
    company_name  VARCHAR(120) NULL,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME     NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_role (role)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

#### `reorder_alerts` — 납품 이력별 재구매 알림 설정
```sql
CREATE TABLE reorder_alerts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    estimate_id INT UNSIGNED NOT NULL,
    alert_after ENUM('1y','2-3y','5y+','unknown') NOT NULL DEFAULT 'unknown',
    alert_at    DATE         NULL,
    is_sent     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_estimate (user_id, estimate_id),
    CONSTRAINT fk_alert_user     FOREIGN KEY (user_id)     REFERENCES users(id),
    CONSTRAINT fk_alert_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
-- alert_at: delivery_completed_at + alert_after 기준으로 계산해 저장
```

#### `favorite_products` — 단골품목 (기업회원)
```sql
CREATE TABLE favorite_products (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id    INT UNSIGNED NOT NULL,
    product_id INT UNSIGNED NOT NULL,
    quantity   INT UNSIGNED NOT NULL DEFAULT 1,
    sort_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_product (user_id, product_id),
    CONSTRAINT fk_fav_user    FOREIGN KEY (user_id)    REFERENCES users(id)    ON DELETE CASCADE,
    CONSTRAINT fk_fav_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
```

### 2.3 PHP 타입 정의 (PHPDoc)

```php
// app/auth.php

/**
 * @phpstan-type LoginUser array{
 *     id: int,
 *     role: 'customer'|'corporate'|'staff'|'admin',
 *     name: string,
 *     email: string,
 *     company_name: string|null
 * }
 */

// app/ai_tools.php (기존 유지 + 아래 추가)

/**
 * @phpstan-type FavoriteItem array{
 *     id: int,
 *     product_id: int,
 *     name: string,
 *     spec: string|null,
 *     price_b2b: int,
 *     quantity: int
 * }
 *
 * @phpstan-type DeliveryHistory array{
 *     estimate_id: int,
 *     created_at: string,
 *     delivery_completed_at: string|null,
 *     items: list<array{name: string, quantity: int}>,
 *     alert_after: string|null
 * }
 *
 * @phpstan-type QuickItem array{
 *     product_id: int,
 *     name: string,
 *     spec: string|null,
 *     quantity: int,
 *     unit_price: int,
 *     subtotal: int
 * }
 */
```

---

## 3. API 스펙

### 3.1 인증 API

---

#### `POST /api/auth/login.php`

**역할**: 기업회원 로그인 — 세션에 사용자 정보 저장

**Request Body** (application/json):
```json
{ "email": "hong@example.com", "password": "plain_text" }
```

**Response 성공** (HTTP 200):
```json
{
  "ok": true,
  "user": { "id": 1, "name": "홍길동", "role": "corporate", "company_name": "홍씨건설" }
}
```

**Response 실패** (HTTP 401):
```json
{ "ok": false, "error": "이메일 또는 비밀번호가 올바르지 않습니다." }
```

**서버 검증**:
- email: 형식 검증, 최대 160자
- password: 1~100자, `password_verify()` 사용
- `is_active = 1`인 사용자만 로그인 허용
- 로그인 성공 시 `last_login_at` 갱신 + `session_regenerate(true)`

**인증**: 불필요

---

#### `POST /api/auth/logout.php`

**역할**: 세션 파기

**Request Body**: 없음

**Response** (HTTP 200):
```json
{ "ok": true }
```

**인증**: 불필요 (세션 없어도 정상 처리)

---

### 3.2 상품 조회 API

---

#### `GET /api/products/by-category.php?category_id={id}`

**역할**: 즉시견적 드롭다운 — 카테고리별 상품·규격·단가 반환

**Query Params**:
| 파라미터 | 타입 | 필수 | 설명 |
|---------|------|------|------|
| `category_id` | int | Y | 카테고리 ID |

**Response 성공** (HTTP 200):
```json
{
  "ok": true,
  "products": [
    {
      "id": 5,
      "name": "암거 1련 1200×1200",
      "spec": "1200×1200mm",
      "price_b2c": 35000,
      "price_b2b": 32000,
      "stock_qty": 100
    }
  ]
}
```

**가격 노출 규칙**:
- 기업회원 로그인 시: `price_b2b` 포함 반환 (0이면 price_b2c로 폴백)
- 미로그인 또는 일반회원: `price_b2b` 필드 제외 (price_b2c만 반환)

**서버 검증**:
- `category_id`: 양의 정수, `is_active = 1` 카테고리만 허용

**인증**: 불필요 (로그인 상태에 따라 응답 내용 다름)

---

### 3.3 일반 모드 — 이전 상담 이력

---

#### `GET /api/chat/history.php`

**역할**: 로그인 사용자의 이전 AI 상담 이력 목록 반환

**Query Params**:
| 파라미터 | 타입 | 필수 | 설명 |
|---------|------|------|------|
| `limit` | int | N | 최대 10 (기본 5) |

**Response 성공** (HTTP 200):
```json
{
  "ok": true,
  "sessions": [
    {
      "session_key": "abc123",
      "created_at": "2025-03-12T14:23:00",
      "summary": "흄관 B형 φ600 외 1개",
      "estimate_id": 42
    }
  ]
}
```

**Response 실패** (HTTP 401):
```json
{ "ok": false, "error": "로그인이 필요합니다." }
```

**인증**: 로그인 필요 (`auth.php::require_login()`)

**서버 검증**:
- `limit`: 1~10 범위 제한
- 본인 세션만 조회 (user_id 일치 확인)

---

### 3.4 기업회원 즉시견적 API

---

#### `POST /api/estimate/quick-items.php`

**역할**: 즉시견적 세션 품목 추가·수정·삭제

**Request Body** (application/json):
```json
{
  "action": "add",
  "product_id": 5,
  "quantity": 50
}
```

**action 값**:
| action | 설명 | 추가 파라미터 |
|--------|------|-------------|
| `add` | 품목 추가 | `product_id`, `quantity` |
| `update` | 수량 변경 | `product_id`, `quantity` (0이면 삭제) |
| `remove` | 품목 삭제 | `product_id` |
| `get` | 현재 목록 조회 | 없음 |
| `clear` | 전체 초기화 | 없음 |

**Response 성공** (HTTP 200):
```json
{
  "ok": true,
  "items": [
    {
      "product_id": 5,
      "name": "암거 1련 1200×1200",
      "spec": "1200×1200mm",
      "quantity": 50,
      "unit_price": 32000,
      "subtotal": 1600000
    }
  ],
  "total": 1600000
}
```

**Response 실패** (HTTP 400 / 401):
```json
{ "ok": false, "error": "기업회원 로그인이 필요합니다." }
```

**인증**: 기업회원 로그인 필요

**서버 검증**:
- 기업회원 여부 확인 (`is_corporate_member()`)
- `product_id`: 양의 정수, DB 존재 + is_active = 1 확인
- `quantity`: 1~9999 범위 제한
- `action`: whitelist 검증
- 단가: `price_b2b > 0 ? price_b2b : price_b2c` 서버에서 결정 (클라이언트 단가 신뢰 금지)

---

#### `POST /api/estimate/reorder.php`

**역할**: 이전 견적서 품목을 세션 장바구니로 적재 (재구매 원클릭)

**Request Body** (application/json):
```json
{ "estimate_id": 42 }
```

**Response 성공** (HTTP 200):
```json
{
  "ok": true,
  "loaded_count": 2,
  "items": [ ... ],
  "total": 2155000
}
```

**Response 실패** (HTTP 403):
```json
{ "ok": false, "error": "접근 권한이 없습니다." }
```

**인증**: 로그인 필요

**서버 검증**:
- `estimate_id`: 양의 정수
- `estimates.user_id = 현재 로그인 user_id` 확인 (타인 견적서 접근 차단)
- 각 품목의 `is_active = 1` 재확인 (단종 상품 제외)

---

#### `GET /api/estimate/favorites.php`
#### `POST /api/estimate/favorites.php`
#### `DELETE /api/estimate/favorites.php`

**역할**: 단골품목 조회·추가·삭제

**GET Response** (HTTP 200):
```json
{
  "ok": true,
  "favorites": [
    { "id": 1, "product_id": 5, "name": "암거 1련 1200×1200", "spec": "1200×1200mm", "price_b2b": 32000, "quantity": 50 }
  ]
}
```

**POST Request Body**:
```json
{ "product_id": 5, "quantity": 50 }
```

**POST Response** (HTTP 201):
```json
{ "ok": true, "favorite_id": 1 }
```

**DELETE Request Body**:
```json
{ "product_id": 5 }
```

**DELETE Response** (HTTP 200):
```json
{ "ok": true }
```

**인증**: 기업회원 로그인 필요

**서버 검증**:
- `product_id`: 양의 정수, DB 존재 확인
- `quantity`: 1~9999 범위
- 단골품목 최대 50개 제한 (POST 시 초과 체크)

---

### 3.5 공통 견적 요청서 API (수정)

---

#### `POST /api/estimate.php` (기존 수정)

**역할**: 견적 생성 — v2.0에서 배송 일시·모드 필드 추가

**Request Body** (application/json):
```json
{
  "customer_name": "홍길동",
  "phone": "010-1234-5678",
  "email": "hong@example.com",
  "company_name": "홍씨건설",
  "site_address": "서울시 강남구 테헤란로 1",
  "memo": "현장 특이사항",
  "requested_delivery_at": "2026-07-10T09:00:00",
  "delivery_undecided": false,
  "sid": "abc123"
}
```

**변경 필드**:
| 필드 | 타입 | 변경 내용 |
|------|------|---------|
| `requested_delivery_at` | string\|null | 신규 — ISO 8601 datetime |
| `delivery_undecided` | bool | 신규 — true면 requested_delivery_at 무시 |

**Response 성공** (HTTP 201):
```json
{ "ok": true, "estimate_id": 42, "message": "견적이 생성되었습니다." }
```

**서버 검증 추가**:
- `requested_delivery_at`: ISO 8601 datetime 형식 검증, 현재 시각 이후만 허용
- `delivery_undecided = true`이면 `requested_delivery_at` null로 저장
- 로그인 상태이면 `estimates.user_id` 자동 연결

**인증**: 불필요 (세션 기반, 로그인 시 user_id 자동 연결)

---

### 3.6 마이페이지 API

---

#### `GET /api/mypage/delivery-history.php`

**역할**: 납품 완료 이력 목록 (status = 'DELIVERED')

**Query Params**:
| 파라미터 | 타입 | 필수 | 설명 |
|---------|------|------|------|
| `limit` | int | N | 최대 20 (기본 10) |
| `offset` | int | N | 페이지네이션 오프셋 |

**Response 성공** (HTTP 200):
```json
{
  "ok": true,
  "total": 5,
  "items": [
    {
      "estimate_id": 42,
      "delivery_completed_at": "2025-03-12",
      "products_summary": "흄관 B형 φ600 × 30개 외 1개",
      "alert_after": "1y",
      "alert_at": "2026-03-12"
    }
  ]
}
```

**인증**: 로그인 필요

**서버 검증**:
- `estimates.user_id = 현재 user_id` 필터
- `limit`: 1~20, `offset`: 0 이상

---

#### `POST /api/mypage/delivery-alert.php`

**역할**: 납품 이력별 재구매 알림 시기 설정 저장

**Request Body** (application/json):
```json
{
  "estimate_id": 42,
  "alert_after": "1y"
}
```

**alert_after 허용값**: `"1y"` / `"2-3y"` / `"5y+"` / `"unknown"`

**Response 성공** (HTTP 200):
```json
{ "ok": true, "alert_at": "2026-03-12" }
```

**Response 실패** (HTTP 400):
```json
{ "ok": false, "error": "납품 완료된 건만 알림 설정 가능합니다." }
```

**인증**: 로그인 필요

**서버 검증**:
- `estimate_id`: 양의 정수, 본인 소유 + status='DELIVERED' 확인
- `alert_after`: whitelist 검증
- `alert_at` 계산: `delivery_completed_at + alert_after` (1y=+1년, 2-3y=+2년, 5y+=+5년, unknown=null)

---

## 4. 구현 순서

### Phase 1 — 인증 + DB 기반 (백엔드 우선)

1. **`database/add_users_auth.sql`** — users 테이블 생성
2. **`database/alter_estimates_v2.sql`** — estimates에 user_id, mode, status, 배송 필드 추가
3. **`database/add_reorder.sql`** — reorder_alerts, favorite_products 테이블 생성
4. **`database/alter_products_b2b.sql`** — products.price_b2b 컬럼 추가
5. **`app/auth.php`** — `current_user()`, `is_logged_in()`, `is_corporate_member()`, `require_login()` 함수
6. **`public/api/auth/login.php`** — 로그인 API (password_verify, session_regenerate)
7. **`public/api/auth/logout.php`** — 로그아웃 API

### Phase 2 — AI 상담 시작 화면 + 일반 모드 UI (백엔드 → UI)

8. **`public/api/chat/history.php`** — 이전 상담 이력 API (user_id 기반 ai_sessions 조회)
9. **`public/ai.php`** — 모드 선택 화면 (일반/기업회원 버튼, auth 상태 표시)
10. **`public/login.php`** — 기업회원 로그인 페이지 (POST → api/auth/login.php)
11. **`public/chat.php` 수정** — 로그인 시 이전 상담 이력 패널 렌더링 추가

### Phase 3 — 기업회원 즉시견적 (백엔드 → UI)

12. **`public/api/products/by-category.php`** — 카테고리별 상품 조회 (price_b2b 조건부 노출)
13. **`public/api/estimate/quick-items.php`** — 즉시견적 품목 CRUD API
14. **`public/api/estimate/reorder.php`** — 이전 견적서 재사용 API
15. **`public/api/estimate/favorites.php`** — 단골품목 API
16. **`app/quick_estimate_renderer.php`** — 즉시견적 UI HTML/JS (드롭다운, 누적 목록, 합계)
17. **`public/estimate-quick.php`** — 즉시견적 페이지 (인증 확인 → renderer 포함)

### Phase 4 — 공통 견적 요청서 + 마이페이지

18. **`public/api/estimate.php` 수정** — 배송 일시, user_id 자동 연결 처리 추가
19. **`public/estimate.php` 수정** — 배송 요청 일시 선택 UI 추가
20. **`public/api/mypage/delivery-history.php`** — 납품 이력 API
21. **`public/api/mypage/delivery-alert.php`** — 알림 설정 API
22. **`public/mypage.php`** — 마이페이지 (납품 이력 목록 + 알림 설정 인터랙션)

---

## 5. 절대규칙 준수 체크리스트

```
─────────────────────────────────────────────────
📋 플랫폼 개발 원칙 점검 (설계 단계 v2.0)
─────────────────────────────────────────────────

1. 페이지 500줄 제한
   ✅ public/ai.php                    → ≤80줄  (모드 선택 UI만)
   ✅ public/login.php                 → ≤80줄  (로그인 폼만)
   ✅ public/estimate-quick.php        → ≤150줄 (라우팅·인증 확인만)
   ✅ public/mypage.php                → ≤180줄 (페이지 조합만)
   ✅ public/estimate.php              → ≤120줄 (배송 필드 추가해도 유지)
   ✅ public/chat.php                  → ≤200줄 (이전 설계 기준 유지)
   ✅ app/quick_estimate_renderer.php  → ≤300줄 (UI+JS 전담, 단일 책임 내 허용)
   ✅ 모든 API 파일                    → ≤120줄 (단일 책임 원칙으로 자연 달성)

2. 단일 책임
   ✅ auth.php              = 인증 상태 확인 헬퍼만
   ✅ quick_estimate_renderer.php = UI HTML/JS만, DB 쿼리 없음
   ✅ api/auth/login.php    = 로그인 처리만
   ✅ api/estimate/quick-items.php = 즉시견적 품목 CRUD만
   ✅ api/estimate/reorder.php     = 재사용 로직만
   ✅ api/mypage/*.php      = 마이페이지 각 데이터 전담

3. 서버 검증·보안
   ✅ 로그인 API: password_verify + session_regenerate(true) 강제
   ✅ 비밀번호: 코드·로그 절대 미노출, password_hash(BCRYPT) 저장
   ✅ 단가 신뢰 금지: price_b2b는 클라이언트 값 사용 금지, 서버 DB 기준
   ✅ 기업회원 확인: 단가 노출·즉시견적·단골품목 API 모두 is_corporate_member() 서버 검증
   ✅ 본인 소유 확인: reorder/delivery-history는 estimate.user_id = 현재 user_id 필터 필수
   ✅ 견적 요청서: requested_delivery_at 현재 시각 이후 검증
   ✅ HTML 이스케이프: e() 함수 전 입력값에 적용 (기존 유지)
   ✅ 세션 고정 공격 방지: 로그인 성공 시 session_regenerate(true)

4. 성능 (중복·N+1)
   ✅ quick-items get: product IN (...) 단일 쿼리 (cart_fetch_items 재사용 패턴)
   ✅ delivery-history: estimate_items를 subquery 또는 GROUP_CONCAT으로 한 번에 조회
   ✅ by-category: is_active=1 필터 + 필요한 컬럼만 (id, name, spec, price_b2c, price_b2b, stock_qty)
   ✅ reorder: estimate_items JOIN products 단일 쿼리로 N+1 방지
   ✅ favorites get: user_id 기준 단일 쿼리 + JOIN products

5. 폴더 구조
   ✅ 페이지 진입점: public/ (ai.php, login.php, estimate-quick.php, mypage.php)
   ✅ 비즈니스·인증 로직: app/ (auth.php, rag.php, ai_tools.php)
   ✅ UI 렌더링: app/*_renderer.php (페이지 전용 HTML/JS)
   ✅ API 라우트: public/api/ 도메인 기준 하위 폴더 분리
      (auth/, products/, estimate/, mypage/)
   ✅ DB 마이그레이션: database/ 경로 유지
   ✅ 공용 유틸: app/bootstrap.php (db, e, money, cart_* 함수 유지)

─────────────────────────────────────────────────
위반 항목: 없음 (설계 단계 기준)
─────────────────────────────────────────────────
```

---

## 6. 미결 사항 / 구현 전 확인 필요

| 항목 | 내용 | 결정 필요 |
|------|------|----------|
| AI API | rag.php가 현재 OpenAI 사용 중 — Claude API 전환 여부 | API 키 + 모델 확정 |
| 기업회원 가입 경로 | 관리자가 직접 등록 vs 신청 폼 제공 | 가입 UX 결정 필요 |
| price_b2b 미설정 상품 | price_b2b = 0인 경우 즉시견적 화면에서 price_b2c 폴백 노출 여부 | 노출 정책 확인 |
| 납품 완료 처리 | estimates.status를 'DELIVERED'로 바꾸는 주체 (관리자? 직원?) | 어드민 화면 연동 시 결정 |
| 재구매 알림 발송 | 알림톡/이메일 발송 인프라 없음 — 크론잡 or 수동 발송 | 발송 방식 결정 필요 (Post-MVP 가능) |
| 단골품목 불러오기 흐름 | 즉시견적 화면에서 단골품목 로드 시 기존 목록 덮어쓰기 vs 추가 | UX 확인 필요 |
