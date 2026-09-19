# AI 기능 설계-구현 GAP 분석 (v2.0)

> 참조 설계서: `docs/02-design/features/ai.design.md` (v2.0)
> 분석일: 2026-06-19
> 분석 범위: 설계서 전체 (Phase 1~4 + 플랫폼 절대규칙)
> 이전 분석 대비: GAP-01~04 해소 확인, v2.0 신규 GAP 추가 반영

---

## 1. Match Rate (일치율)

| 영역 | 일치율 | 비고 |
|------|-------|------|
| Phase 1 — 인증 + DB | 97% | alter_products_b2b.sql 불필요 (schema.sql에 기포함) |
| Phase 2 — 일반 모드 AI 상담 | 91% | rag_save_session() user_id 미저장 |
| Phase 3 — 기업회원 즉시견적 | 100% | 전 항목 완전 일치 |
| Phase 4 — 견적 요청서 + 마이페이지 | 75% | quick_items → cart 연결 버그 |
| 플랫폼 절대규칙 준수 | 90% | 단일 책임 1건 위반 |

**종합 일치율: 82%**

> 이전 분석(2026-06-18) 대비 GAP-01(chat.php rag_stream 오호출), GAP-02(SSE 포맷),
> GAP-03/08(order.php 검증 누락), GAP-04(cart 이중 쿼리) 가 모두 해소됨.
> 신규 v2.0 기능에서 quick_items→cart 연결 버그가 치명 GAP로 발견됨.

---

## 2. GAP 목록

### 🔴 CRITICAL (기능 미동작)

---

#### GAP-V2-01: quick_estimate 품목이 estimate_create()에서 읽히지 않음

| 항목 | 내용 |
|------|------|
| 관련 파일 | `app/quick_estimate_renderer.php:149`, `app/ai_tools.php:370`, `public/estimate.php:36-38` |
| 설계 | 기업회원 즉시견적 완료 → `/estimate.php` → `POST /api/estimate.php` → 견적 생성 |
| 구현 불일치 | 즉시견적 품목은 `$_SESSION['quick_items']`에 저장, `estimate_create()`는 `cart_session_read()` → `$_SESSION['cart']` 읽음 |
| 영향 | 기업회원이 즉시견적에서 품목 추가 후 "견적서 작성하기" 클릭 시 "장바구니가 비어있습니다" 오류 발생, 견적 생성 불가 |

```php
// quick_estimate_renderer.php 품목 저장 위치
// POST /api/estimate/quick-items.php
$_SESSION['quick_items'] = $items;   // ← 여기에 저장

// estimate_create() 품목 읽기 위치 (app/ai_tools.php:370)
$cart  = cart_session_read();   // $_SESSION['cart'] 읽음 ← 연결 안 됨
$items = cart_fetch_items($cart);
```

**해결 방향**: `/estimate.php` GET 진입 시 또는 `estimate_create()` 에서
quick 모드 여부 감지 후 `$_SESSION['quick_items']` 도 fallback으로 읽는 로직 추가.
또는 quick-items API에서 `$_SESSION['cart']`도 동시에 갱신.

---

#### GAP-V2-02: rag_save_session()이 user_id를 저장하지 않음

| 항목 | 내용 |
|------|------|
| 관련 파일 | `app/rag.php:152-160`, `public/api/chat/history.php:33-43` |
| 설계 | 로그인 사용자의 채팅 세션에 `user_id` 연결 → 이전 상담 이력 조회 기반 |
| 구현 불일치 | `rag_save_session()`은 `session_key`, `messages`만 INSERT. `user_id` 저장 없음 |
| 영향 | `history.php`가 `WHERE user_id = :uid` 쿼리 시 항상 0건 반환 → 이전 상담 이력 패널 미표시 |

```php
// rag.php:152 — 현재 (불완전)
function rag_save_session(string $key, array $messages): void
{
    $stmt = db()->prepare(
        'INSERT INTO ai_sessions (session_key, messages) VALUES (:key, :msg)
         ON DUPLICATE KEY UPDATE messages = :msg, updated_at = NOW()'
    );   // user_id 없음
}

// 필요한 동작
// INSERT INTO ai_sessions (session_key, user_id, messages) ...
// 로그인 상태이면 $_SESSION['user']['id'] 자동 연결
```

---

### 🟠 MEDIUM (기능 편차·보안 주의)

---

#### GAP-V2-03: estimate_create()에 mode='quick' 미전달

| 항목 | 내용 |
|------|------|
| 관련 파일 | `public/api/estimate.php:30-39`, `app/ai_tools.php:338` |
| 설계 | 즉시견적 경로로 생성된 견적은 `estimates.mode = 'quick'` 저장 |
| 구현 | `api/estimate.php`가 `estimate_create()`에 mode 파라미터를 전달하지 않음 → 기본값 'chat' 사용 |
| 영향 | 모든 견적이 mode='chat'으로 저장. 추후 모드별 통계·필터링 시 데이터 오염 |

---

#### GAP-V2-04: require_corporate() 응답 코드 설계와 불일치

| 항목 | 내용 |
|------|------|
| 관련 파일 | `app/auth.php:74-81`, `docs/02-design/features/ai.design.md §3.4` |
| 설계 | 기업회원 미로그인 시 HTTP 400 또는 401 반환 |
| 구현 | `require_corporate()`는 HTTP 401만 반환. 설계서 §3.4 응답은 `HTTP 400/401`로 구분 명시 |
| 영향 | 작은 차이이나 클라이언트가 응답 코드로 분기 처리 시 예상과 다를 수 있음 |

---

### 🟡 LOW (설계 편차 · 기술 부채)

---

#### GAP-V2-05: api/auth/login.php 줄 수 설계 목표 초과

| 항목 | 내용 |
|------|------|
| 설계 목표 | ≤60줄 |
| 실제 | 88줄 (타이밍 공격 방지 로직 등 보안 코드 추가로 인한 합당한 초과) |
| 평가 | 보안 강화 목적의 초과 — 절대규칙(500줄) 위반 아님. 설계 목표치 수정 권장 |

---

#### GAP-V2-06: public/ai.php, public/login.php 설계 목표 초과

| 항목 | 내용 |
|------|------|
| 설계 목표 | ai.php ≤80줄, login.php ≤80줄 |
| 실제 | ai.php 84줄, login.php 83줄 |
| 평가 | CSS 인라인 포함으로 인한 소폭 초과. 500줄 절대 제한 기준으로 문제 없음 |

---

#### GAP-V2-07: app/ai_tools.php 단일 책임 위반 (이전 분석 GAP-09 유지)

| 항목 | 내용 |
|------|------|
| 관련 파일 | `app/ai_tools.php` (434줄) |
| 설계 의도 | Tool 정의 + Tool 핸들러만 |
| 실제 내용 | Tool 정의/디스패처/핸들러 + 세션 장바구니 유틸(`cart_session_read/write`, `cart_fetch_items`, `cart_summary`) + 견적 공용 로직(`estimate_create`) |
| 영향 | `estimate_create()`가 `api/estimate.php`와 AI Tool 양쪽에서 공유. 이 파일이 커지면 분리 비용이 커짐 |
| 권장 분리 | `app/cart.php` (cart 유틸), `app/estimate.php` (estimate_create) |

---

#### GAP-V2-08: alter_products_b2b.sql 미생성 (설계 Phase 1 step 4)

| 항목 | 내용 |
|------|------|
| 설계 | Phase 1 step 4: `database/alter_products_b2b.sql` 생성 |
| 실제 | 파일 없음. 단, `database/schema.sql:20`에 `price_b2b INT UNSIGNED NOT NULL DEFAULT 0` 이미 포함 |
| 평가 | schema.sql에 기포함되어 기능 문제 없음. 마이그레이션 히스토리 관리 관점에서 문서화 빠짐 |

---

#### GAP-V2-09: tool_state 컬럼 미사용 (이전 분석 GAP-06 유지)

| 항목 | 내용 |
|------|------|
| 설계 | `ai_sessions.tool_state` — 대화 중 중간 상태 저장 |
| 구현 | `database/add_ai_orders.sql`에 컬럼 추가 SQL 존재, 읽기/쓰기 로직 없음 |
| 영향 | 현재 세션 기반으로 대체 운용 중이므로 기능 영향 없음 |

---

### ✅ 이전 분석 대비 해소된 GAP

| 이전 GAP | 내용 | 상태 |
|---------|------|------|
| GAP-01 | chat.php가 rag_stream(구함수) 호출 | **해소** — rag_stream_with_tools() 호출로 수정됨 (`chat.php:35`) |
| GAP-02 | SSE 이벤트 포맷 불일치 | **해소** — GAP-01 해소로 동반 해소 |
| GAP-03 | api/order.php 전화번호 regex 누락 | **해소** — estimate_create()로 통합, regex 검증 포함 |
| GAP-04 | cart_fetch_items() 이중 호출 | **해소** — cart_summary_from_items() 분리, 단일 쿼리로 정상 처리 |
| GAP-08 | api/order.php email 검증 누락 | **해소** — estimate_create()에 FILTER_VALIDATE_EMAIL 포함 |

---

## 3. 플랫폼 절대규칙 위반 점검

```
─────────────────────────────────────────────────
📋 플랫폼 개발 원칙 점검 (v2.0 구현 결과)
─────────────────────────────────────────────────

1. 페이지 500줄 제한
   ✅ public/ai.php                    — 84줄
   ✅ public/login.php                 — 83줄
   ✅ public/chat.php                  — 67줄
   ✅ public/estimate-quick.php        — 24줄
   ✅ public/estimate.php              — 115줄
   ✅ public/mypage.php                — 151줄
   ✅ app/auth.php                     — 83줄
   ✅ app/chat_renderer.php            — 354줄
   ✅ app/quick_estimate_renderer.php  — 259줄 (설계 목표 ≤300줄 이내)
   ✅ app/rag.php                      — 283줄
   ✅ app/ai_tools.php                 — 434줄 (주의 요망, 분리 권장)
   ✅ api/auth/login.php               — 88줄
   ✅ api/auth/logout.php              — 20줄
   ✅ api/products/by-category.php     — 75줄
   ✅ api/estimate/quick-items.php     — 151줄
   ✅ api/estimate/reorder.php         — 103줄
   ✅ api/estimate/favorites.php       — 130줄
   ✅ api/chat/history.php             — 72줄
   ✅ api/mypage/delivery-history.php  — 77줄
   ✅ api/mypage/delivery-alert.php    — 100줄
   ✅ api/cart.php                     — 111줄
   ✅ api/estimate.php                 — 53줄
   ✅ public/assets/chat-ai.js         — 209줄
   → 모든 파일 500줄 미만. 절대규칙 위반 없음.

2. 단일 책임
   ❌ app/ai_tools.php — Tool 정의+핸들러 외에
      cart_session_read/write(), cart_fetch_items(), cart_summary(),
      cart_summary_from_items(), estimate_create() 혼재 (434줄)
      → app/cart.php, app/estimate.php 분리 권장
   ✅ 나머지 파일: UI/로직/API 역할 분리 준수

3. 서버 검증·보안
   ✅ api/auth/login.php: password_verify + session_regenerate(true) + 타이밍 공격 방지
   ✅ api/auth/logout.php: 세션 파기
   ✅ estimate_create(): 전화번호 regex, 이메일 형식, 배송 일시 ISO 8601 + 미래 검증
   ✅ api/products/by-category.php: is_corporate_member() 확인 후 price_b2b 조건부 노출
   ✅ api/estimate/quick-items.php: require_corporate(), product_id DB 재검증, 단가 서버 결정
   ✅ api/estimate/reorder.php: estimates.user_id 본인 소유 확인 (HTTP 403)
   ✅ api/estimate/favorites.php: 최대 50개 제한, product_id DB 존재 확인
   ✅ api/mypage/delivery-alert.php: status='DELIVERED' + 본인 소유 확인, alert_after whitelist
   ✅ XSS 방지: e() 헬퍼 전 입력값 htmlspecialchars 적용
   ⚠️ GAP-V2-01로 인해 quick_items 경로의 견적 생성이 사실상 불가 → 비기능 상태

4. 성능 (중복·N+1)
   ✅ cart_fetch_items(): IN절 단일 쿼리, N+1 방지
   ✅ cart_summary_from_items(): 이미 조회된 items 재사용, DB 재호출 없음
   ✅ api/estimate/reorder.php: estimate_items JOIN products 단일 쿼리
   ✅ api/mypage/delivery-history.php: GROUP_CONCAT으로 품목 요약 단일 쿼리
   ✅ api/estimate/favorites.php: user_id 기준 단일 쿼리 + JOIN products
   ✅ rag_stream_with_tools(): 최대 5회 루프 제한

5. 폴더 구조
   ✅ 페이지 진입점: public/ (ai.php, login.php, estimate-quick.php, mypage.php, estimate.php)
   ✅ 인증 헬퍼: app/auth.php
   ✅ AI 스트리밍: app/rag.php
   ✅ UI 렌더러: app/chat_renderer.php, app/quick_estimate_renderer.php
   ✅ API: public/api/ 도메인 하위폴더 분리 (auth/, products/, estimate/, mypage/)
   ✅ DB 마이그레이션: database/
   ⚠️ app/ai_tools.php: cart 유틸 + estimate 공용 함수가 app/에 혼재
      → 재사용 범위 기준으로 app/cart.php, app/estimate.php 분리 권장

─────────────────────────────────────────────────
위반 항목: 있음
  - 단일 책임: app/ai_tools.php
  - 기능 버그: GAP-V2-01 (quick_items → cart 연결 끊김)
  - 기능 버그: GAP-V2-02 (ai_sessions.user_id 미저장)
─────────────────────────────────────────────────
```

---

## 4. 종합 의견 및 수정 우선순위

### P0 — 즉시 수정 (기능 미동작)

| 우선순위 | 파일 | 수정 내용 |
|---------|------|----------|
| ★★★ | `app/ai_tools.php` (estimate_create) 또는 `public/estimate.php` | quick_items → cart 연결: estimate_create() 내에서 `$_SESSION['cart']` 비어 있으면 `$_SESSION['quick_items']` fallback 읽기, 또는 estimate-quick 완료 시 quick_items를 cart에 복사 후 estimate.php 이동 |
| ★★★ | `app/rag.php:152` (rag_save_session) | 세션에 로그인 user가 있으면 user_id를 ai_sessions에 함께 저장: `INSERT INTO ai_sessions (session_key, user_id, messages) ...` |

---

### P1 — 기능 완성도 (배포 전 권장)

| 우선순위 | 파일 | 수정 내용 |
|---------|------|----------|
| ★★ | `public/api/estimate.php` | 요청 출처 감지 로직 추가하여 mode='quick' 또는 'chat' 구분 전달 |

---

### P2 — 구조 개선 (기술 부채 해소)

| 우선순위 | 항목 | 수정 내용 |
|---------|------|----------|
| ★ | `app/ai_tools.php` 분리 | `cart_session_read/write()`, `cart_fetch_items()`, `cart_summary*()` → `app/cart.php`; `estimate_create()` → `app/estimate.php` |
| ★ | `tool_state` 활용 또는 제거 | 대화형 중간 상태 저장이 필요하면 read/write 구현, 불필요하면 컬럼 드롭 |
| ★ | `database/alter_products_b2b.sql` 문서화 | schema.sql에 포함되어 있으나, 마이그레이션 히스토리 관리를 위해 빈 파일이라도 주석 포함 생성 |

---

### 총평

v2.0 Phase 1·3는 설계서를 충실히 구현했다. 특히 기업회원 인증(bcrypt + session_regenerate + 타이밍 공격 방지), 즉시견적 API(서버 단가 강제, 단골품목 50개 제한, 재구매 이력 권한 검증)는 보안 요건을 모두 충족한다.

치명 버그는 2가지다:
1. **quick_items → cart 미연결**: 기업회원 즉시견적의 핵심 흐름이 마지막 단계에서 끊김
2. **ai_sessions.user_id 미저장**: 로그인 사용자의 이전 상담 이력 패널이 데이터 미연결로 항상 비어 있음

이 두 가지를 먼저 수정하면 v2.0 핵심 기능 전체가 동작 가능한 완성도 높은 구현이다.
