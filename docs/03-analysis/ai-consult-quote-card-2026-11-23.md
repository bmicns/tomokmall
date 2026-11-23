# AI 상담 견적 카드 (B안) — 2026-11-23

> 사용자 결정 2026-11-23(A8): 대안 B · 운임은 상담에서 배송 지역을 물어 예상 운임까지 · 비회원에게도 표시.
> 설계 문서(대안 비교·권장안): https://claude.ai/artifact/NXmnnC8zbxae9M5drETy2u (케이토목 1분 자동견적과 비교해 강점만 적용 — 방향 1).

## 1. 무엇이 바뀌나
- 추천표에서 고객이 제품을 고르면 AI 가 "이 제품으로 견적서에 담을까요? 배송 지역(시·도)을 알려 주시면 예상 운임까지 계산해 드립니다."처럼 **먼저 묻고**, 고객이 원하면 담는다.
- 담거나 수량을 바꾸거나 빼면 답변 안에 **한 장 견적 카드**가 나온다.
  - 품목별 규격·단가 × 수량 = 소계(단가 0원 = '가격 문의', 금액에서 제외 — O4)
  - 상품 합계, 예상 운임(지역), 부가세 10%, 최종 예상 금액 + '운임 별도'·'문의 품목' 꼬리
  - 안내: "예상 금액입니다. 실제 금액은 담당자 확인 후 확정됩니다." + [견적 요청하기](/estimate.php)
- 배송 지역을 모르면 운임 칸은 "배송 지역을 알려 주시면 계산해 드립니다", 중량 없는 품목이 있으면 "별도 확인", 제주·400km 초과는 "별도 협의" — **운임을 0원으로 보이지 않는다.**
- 상담에서 들은 배송 지역은 세션에 기억해 견적 요청 화면의 배송 지역을 미리 고르고 바로 운임을 계산한다.
- 비회원·개인·기업 모두 카드가 보인다(단가는 견적 요청 화면과 같은 회원 등급별 표시 단가, 비회원 = 판매가).

## 1-1. 되돌리기 스위치 (사용자 요청 2026-11-23)
- `app/features.php` 의 `FEATURE_AI_QUOTE_CARD` 를 `false` 로 바꿔 이 파일 1개만 올리면 **반영 전 동작 그대로** 돌아간다(DB 변경 없음).
  - 끄면: 도구 정의·3단계 규칙 문장·담기/조회 결과가 반영 전과 같고, `update_cart` 없음, 견적 카드 없음, 견적 요청 화면 지역 미리 선택 없음.
  - 테스트: `tests/rag_prompt.test.php`(끈 프롬프트 = 반영 전 문장 7개 시나리오), `tests/quote_draft.test.php`(끈 도구·결과).
- 올리는 방법: Claude 가 원격 관리로 파일 1개 배포(관리자 화면 승인 1번) 또는 사용자가 FileZilla 로 `/app/features.php` 수정.
- 다시 켜기 = `true` 로 같은 방법.
- 운임 API 공용 함수(`freight_cart_lines`)와 상담 화면 카드 표시 코드는 스위치와 관계없이 남는다(끄면 카드 이벤트가 없어 보이지 않음, 운임 API 응답은 반영 전과 같음).

## 2. 계산 기준 (새 계산식 없음)
| 항목 | 함수 | 견적 요청 화면과 |
|---|---|---|
| 단가 | `items_with_display_prices`(app/cart.php) | 같음 |
| 운임 | `freight_cart_lines` + `freight_estimate_lines`(app/freight.php) | 같음 — 운임 API(`public/api/freight-cart.php`)도 같은 `freight_cart_lines` 로 바꿈 |
| 부가세·최종 | `estimate_amount_summary`(app/estimate.php) | 같음 |

가격 계산(`app/pricing.php`)·운임 요율·견적 저장(`estimate_create`)·DB 구조는 바꾸지 않았다.

## 3. 바뀐 파일
- `app/quote_draft.php`(새 파일): `quote_draft_build`(순수), `quote_draft_current`, `quote_region_get/set`
- `app/freight.php`: `freight_region_normalize`(정식 시·도 이름 해석, 시·군 이름은 추측 안 함), `freight_cart_lines`(운임 API 에서 옮김)
- `app/ai_tools.php`: `add_to_cart`·`get_cart` 에 `region`, 새 도구 `update_cart`(수량·빼기), 결과에 `quote`(금액)·`quote_note` + 카드 이벤트 `quote_draft`
- `app/rag.php`: 3단계 견적 안내 규칙, 한 턴에 카드 여러 개면 마지막 하나만(`rag_last_quote_only`), 도구 요약
- `public/assets/chat-ai.js`·`app/chat_renderer.php`: 카드 표시(360px 포함)·이전 상담 복원(그때 금액이라는 안내 한 줄)
- `public/estimate.php`: 배송 지역 미리 선택, `public/api/freight-cart.php`: 공용 함수 사용(응답 그대로)

## 4. 검증 (2026-11-23, 운영 복제 개발 DB 11-23 스냅샷)
- `tests/functions.test.php` 715 통과(지역 해석·카드 금액 계산 22개 추가)
- `tests/quote_draft.test.php`(새) 19 통과 — 카드 운임 = 운임 API, 부가세·최종, 빼기·수량, 모르는 지역, 중량 없음, 견적 요청 화면 지역 미리 선택
- `tests/rag_prompt.test.php` 78 통과(골든 14개에 3단계 규칙 문장 반영)
- 전체 묶음: 이전과 같은 기존 실패만(ai_search 2, consult_ambiguous 2, external_import 2, external_import_crawled 1, favorites 1, external_export·stock_filter 예외)
- 화면: 데스크톱 1280px·모바일 360px 카드 표시, 가로 스크롤 없음. 실제 GPT 응답 확인은 못 함(로컬에 API 키 없음) — 운영 반영 후 상담 몇 건으로 확인 필요

## 5. 남은 위험
- AI 가 고객이 원하기 전에 담거나 말하지 않은 제품을 빼는 경우 — 규칙·도구 설명으로 막았고, 서버는 담기지 않은 상품의 수량 변경을 거부한다.
- 고객이 시·군 이름만 말하면 AI 가 시·도로 바꿔 넣는다(예: 수원 → 경기). '광주시'처럼 헷갈리는 이름은 서버가 받지 않고 다시 묻게 한다.
