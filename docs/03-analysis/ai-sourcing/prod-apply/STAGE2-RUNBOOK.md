# 운영 반영 2단계 실행표 — 고객 화면 오류 문구(19) + 화면(3) = 22개 파일

> 상태: **운영 반영 완료 2026-11-03**(사용자 업로드, 확인 결과는 [DEPLOY-LOG.md](DEPLOY-LOG.md)). main 병합 완료 `3d0bca1` — 이제 **main 에서** 배포한다.
> DB 변경 없음. 1단계(AI 상담 5개)와 독립. 서버의 이 22개 파일은 1단계에서 건드리지 않았으므로 비교 기준은 그대로 `02a98c2`.

## 1. 무엇이 바뀌나

| 묶음 | 파일 | 고객에게 보이는 변화 |
|---|---|---|
| 오류 문구(API 12) | `public/api/auth/login.php` `public/api/auth/register.php` `public/api/cart.php` `public/api/estimate.php` `public/api/estimate/favorites.php` `public/api/estimate/quick-items.php` `public/api/estimate/reorder.php` `public/api/mypage/profile.php` `public/api/order.php` `public/api/products/by-category.php` `public/api/sites/index.php` `public/api/sites/orders.php` | 닷홈이 4xx 응답 본문을 바꿔 사라지던 문구가 보임 — 잘못된 비밀번호('네트워크 오류' → '이메일 또는 비밀번호가 올바르지 않습니다.'), 중복 이메일, 수량 범위, 마이페이지·현장·즐겨찾기·재주문 문구. DB 연결 실패는 빈 500 대신 503 문구 |
| 오류 문구(화면 7) | `app/layout.php` `app/quick_estimate_renderer.php` `public/login.php` `public/register.php` `public/product.php` `public/assets/mypage.js` `public/assets/corp-home.js` | 위 API 의 `ok:false` 문구 표시, 응답이 깨져도 일반 문구(오류로 멈추지 않음). 즐겨찾기 삭제 실패 시 카드가 사라지지 않음 |
| 화면(3) | `public/assets/styles.css` `public/products.php` `public/estimate.php` | 견적 요청 휴대폰(360px) 가로 스크롤 제거·품목 카드, main 미배포분: 상품 목록 상단 배너 통일·메인 폰트 확정값·관리자 로그인 화면(관리자는 /admin.php 로 이동) |

- `layout.php`·`login.php`·`api/auth/login.php` 에 main 미배포 '관리자 로그인 화면'이 들어 있고 그 스타일이 `styles.css` 에 있어 **22개를 한 번에** 올린다.
- 1단계로 올라간 `chat-ai.js`(AI 상담 견적추가·견적서 수량)는 이 API 들이 옛 형식이든 새 형식이든 동작 — 2단계 뒤 AI 상담 '견적추가 1,000개' 같은 문구도 정확해진다.

## 2. 사전 검증 (2026-11-03, 이 환경 — 운영과 같은 조합)

운영 코드 `02a98c2` + 1단계 5개(`b5e9602`) + 이 22개(main)를 합친 상태로:

- PHP·JS 문법 전체 통과, 단위 테스트 209/209(DB 연결·미연결), favorites_api 69/69, ai_search 40/40, consult_journey 6/6, rag_process_loop 13/13, rag_prompt 78/78
- 브라우저: 잘못된 비밀번호 → '이메일 또는 비밀번호가 올바르지 않습니다.' · 회원가입 짧은 비밀번호 → '비밀번호는 8자 이상이어야 합니다.' · 장바구니 수량 1000 → HTTP 200 + '수량은 1~999 범위여야 합니다.' · 견적 요청 360px 가로 스크롤 없음 · 메인·상품 목록·상품 상세·AI 상담·로그인·회원가입·견적 요청 360/1280px 모두 가로 스크롤 없음·JS 오류 없음 · 관리자 로그인 화면 있음
- 실제 회원 로그인 성공·회원가입 완료·주문은 운영에서 확인 필요(테스트 계정)

## 3. 명령 (사용자 PC, Git Bash — 업로드는 승인 후)

```bash
git checkout main
```

```bash
git pull origin main
```

```bash
scripts/deploy-files.sh check 02a98c2 public/api/auth/login.php public/api/auth/register.php public/api/cart.php public/api/estimate.php public/api/estimate/favorites.php public/api/estimate/quick-items.php public/api/estimate/reorder.php public/api/mypage/profile.php public/api/order.php public/api/products/by-category.php public/api/sites/index.php public/api/sites/orders.php app/layout.php app/quick_estimate_renderer.php public/login.php public/register.php public/product.php public/assets/mypage.js public/assets/corp-home.js public/assets/styles.css public/products.php public/estimate.php
```

- 기대: 단위 테스트 통과 → 22개 백업 → **모두 '서버 = 02a98c2'(또는 줄바꿈만 다름)**. 하나라도 '다름'이면 멈추고 결과 공유(그 파일만 운영에서 따로 고쳐졌을 수 있음).

```bash
scripts/deploy-files.sh upload backup/<check 가 알려 준 폴더>
```

```bash
bash scripts/smoke.sh
```

## 4. 배포 후 운영 확인

| 확인 | 기대 |
|---|---|
| 로그인 — 틀린 비밀번호 | '이메일 또는 비밀번호가 올바르지 않습니다.' |
| 로그인 — 테스트 계정 정상 로그인 | 로그인됨, 관리자 계정은 /admin.php 로 이동 |
| 회원가입 — 이미 있는 이메일 | 중복 이메일 문구 |
| 상품 상세 '견적 담기' | 담김 토스트 / 오류 시 문구 |
| AI 상담 비교표 '견적추가' 수량 1000 | '수량은 1~999 범위여야 합니다.' |
| 견적 요청 페이지 휴대폰 | 가로 스크롤 없음, 품목 카드 |
| 상품 목록 | 상단 배너 표시 |
| 마이페이지(기업회원) 즐겨찾기·현장·재주문 | 정상, 실패 시 문구 |

되돌리기: `scripts/deploy-files.sh rollback backup/<같은 폴더>`(`복구` 입력) — 22개를 배포 전으로.

## 5. 함께 올릴지 정할 것 (별도 승인)

- **1단계 후속 `app/ai_tools.php`**(자료 공백 기록 material_gap) — 운영 서버의 이 파일은 1단계 버전(`b5e9602`)이라 비교 기준이 다르다. 따로: `scripts/deploy-files.sh check b5e9602 app/ai_tools.php` → upload.
- 3단계(관리자·직원 14개)는 2단계 확인 뒤.
