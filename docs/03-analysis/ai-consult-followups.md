# AI 상담 — 별도 개선 과제 (기록용)

> 작성: 2026-10-28, 브랜치 `ui/ai-consulting` 작업 중 확인된 항목.
> **이 문서는 기록만 한다. 아래 항목은 사용자(운영자) 승인 없이 기능을 변경하지 않는다.**

## 1. 회원 가격 정책 불일치 (변경 금지 — 승인 필요)

같은 세션 장바구니(`$_SESSION['cart']`)가 화면에 따라 다른 단가로 계산된다.

| 가격 기준 | 사용하는 곳 |
|---|---|
| 일반 판매가 `price_b2c` (`cart_fetch_items()`) | AI 상담 비교표, AI 상담 견적서 패널(`api/cart.php`), 견적 요청(`public/estimate.php`), 견적 저장 단가(`estimate_create()`), 주문(`api/order.php`), AI 도구 `get_cart` |
| 회원 등급가 `effective_unit_price()` | 상품 목록·상세 회원가 표시(`price_tag_list`), 장바구니 페이지(`public/cart.php`), 이전 견적 재사용(`api/estimate/reorder.php`), 단골품목(개인) |
| 기업가 `price_b2b` (`quick_items_fetch()`) | 기업회원 즉시견적 |

- 영향: 로그인 회원은 장바구니 페이지에서 회원가 합계를 보지만, AI 상담 견적서·견적 요청·주문은 정가로 계산·저장된다.
- 개선 방향(안): `cart_fetch_items()` 단가를 `effective_unit_price()` 기준으로 통일. 견적·주문 금액 전체에 영향 → 별도 승인 후 진행.

## 2. 0원 상품 주문·견적 (현 정책 유지 결정)

- 운영자 결정(2026-10-28): 0원 품목은 추후 DB에서 가격을 수정할 예정이므로 **결제·견적을 막지 않고 0원으로 합산되는 현재 동작을 유지**한다.
- 현재 동작: `api/order.php`·`estimate_create()`는 0원 품목을 단가 0으로 저장·합산.
- 표시: AI 상담 비교표 '가격 문의', AI 상담 견적서 패널 '가격 문의'·합계 '+ 문의'.

## 3. 0원 표시 문구 (현 상태 유지 결정)

- 화면마다 문구가 다르다: AI 상담 '가격 문의' / 상품 목록·상세 '견적문의' / 견적 요청 페이지 '문의'.
- 운영자 결정(2026-10-28): 일괄 통일하지 않고 그대로 둔다.

## 4. 이전 상담 목록 10건 제한 (현 상태 유지 결정)

- `public/api/chat/history.php`의 `limit` 최대 10. 화면 '전체 N'은 실제 전체 개수가 아니라 API가 돌려준 개수(최대 10).
- 이전 상담 UI 재설계 방향이 정해진 뒤 개선(전체 개수 `COUNT(*)`, `offset` 페이지네이션, '더보기').

## 5. 기타 확인 사항

- AI 상담 견적서 패널 수량칸(`.est-item-qty`)도 전역 `input[type=number]` 규칙 때문에 폭이 넓게 보임(비교표 수량칸과 같은 원인).
- 모바일에서 비교표는 표 안에서 가로 스크롤.
- 이전 상담 규격 비교는 공백만 정리한 문자열 비교 → 규격 오타 수정만으로도 '판매 상품 변경'으로 담기 차단(안전 쪽 판단).
- `ai_sessions.user_id`가 NULL인 과거 로그인 상담은 `chat_logs.user_id`로 추정 가능. 데이터는 변경하지 않음.
  확인용(읽기 전용): `SELECT COUNT(DISTINCT s.session_key) FROM ai_sessions s JOIN chat_logs l ON l.session_key = s.session_key WHERE s.user_id IS NULL AND l.user_id IS NOT NULL;`
