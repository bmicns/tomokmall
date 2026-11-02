# 코드 배포 계획 — 단계별 (준비 문서, 각 단계 배포는 승인 후)

> 기준: 운영 마지막 배포 기록 `02a98c2`. 대상: `02a98c2..` 의 `app/`·`public/` 변경 41개(사용자 PC 수집·이관 전용 `app/external_export.php`·`app/external_crawl.php` 제외).
> 절차: [`docs/DEPLOYMENT.md`](../../../DEPLOYMENT.md) 5-1장 `scripts/deploy-files.sh` — ② `check`(백업·서버 = 02a98c2 확인, 서버 변경 없음) → ③ `upload`(승인 후) → ⑤ `rollback`(문제 시).
> 목표(1단계): **제조사 승인 자료 14행 + 검증 법령 근거를 운영 AI 상담에 반영**. 2·3단계는 AI 상담과 독립.

## 1. 41개 파일 분류

| 묶음 | 파일 | 내용 | 다른 묶음 의존 |
|---|---|---|---|
| **A. AI 상담 필수 (5)** | `app/rag.php` `app/ai_tools.php` `app/external_products.php` `app/chat_renderer.php` `public/assets/chat-ai.js` | 근거 구분·사유지/공공 보도 표현, 제조사 자료 제품별 묶음·조건 문장·이름 정리, 자료 없는 자재는 국가표준·등록 규격으로만(+출처 카드), 직전 추천표 담기·동률 안내, 확인 안 된 KS·강도 수치 안내, 검색 오류·중간 추측 글 제거 / 상담 화면 말풍선·스크롤바·폰트·견적서 수량칸(main 미배포분 포함) | **없음** — 아래 검증 |
| B. 고객 화면 오류 문구 (19) | API 12: `api/auth/login.php` `api/auth/register.php` `api/cart.php` `api/estimate.php` `api/estimate/favorites.php` `api/estimate/quick-items.php` `api/estimate/reorder.php` `api/mypage/profile.php` `api/order.php` `api/products/by-category.php` `api/sites/index.php` `api/sites/orders.php` · 화면 7: `app/layout.php` `app/quick_estimate_renderer.php` `public/login.php` `public/register.php` `public/product.php` `public/assets/mypage.js` `public/assets/corp-home.js` | 닷홈 4xx 본문 교체 대응(200+ok:false) — 로그인·회원가입·장바구니·견적·마이페이지·현장·주문 | API 와 화면 호출부는 **함께**. `layout.php`·`login.php`·`api/auth/login.php` 에 main 미배포 '관리자 로그인 화면'(a8046f6) 포함 → 그 스타일이 든 D 의 `styles.css` 와 같이 |
| C. 관리자·직원 (14) | `public/admin-{categories,orders,product,products,stats,users}.php` `public/api/admin/{categories,orders,products,users}.php` `public/api/staff/{estimates,vendors}.php` `public/staff/{estimate-detail,vendors}.php` | 4xx 문구, 통계 500 수정, 내부 오류 문장 차단, 업체 화면 XSS | 묶음 안에서만 |
| D. 화면 (3) | `public/assets/styles.css` `public/products.php` `public/estimate.php` | main 미배포: 상품 목록 배너(ed96b5e)·폰트(bcf937f)·관리자 로그인 스타일(a8046f6) / 견적 요청 360px(A-5) | `styles.css` ↔ `products.php`(배너), B(관리자 로그인) |

## 2. A 만 먼저 올려도 되는가 — 검증 (2026-11-02, 로컬)

운영 코드 `02a98c2` 에 A 5개만 덮은 상태(worktree)로:

- PHP 문법 전체·JS 문법 통과
- 상담 테스트: consult_journey 6/6 · 실제 답변 재생 15/15 · material_precision 9/9 · rag_process_loop 13/13 · rag_prompt 78/78 · rag_legal 51/51 · rag_road_context 38/38 · ai_search 40/40 · functions 209/209
- (수집·승인 테스트 3종은 사용자 PC 전용 CLI 라이브러리 2개가 없어 실패 → 그 2개를 넣으면 모두 통과 — 웹에는 필요 없음 확인)
- 브라우저(목업 OpenAI, 360·1280px): 비교표·'예상 최저' 2개·담기 후 '같은 예상 자재비의 …' 안내·확인된 기본 정보 카드, 가로 스크롤 없음, JS 오류 없음
- `chat-ai.js` 는 장바구니 API(B)가 옛 형식(4xx)이어도 새 형식(200+ok:false)이어도 동작(비 JSON 일반 문구)
- DB 와의 순서: 리허설(PASS 181) — 새 코드+옛 DB, 옛 코드+새 DB 모두 상담 정상

## 3. 권장 순서

| 단계 | 내용 | 승인 | 확인 |
|---|---|---|---|
| **1-①** | 코드 A 5개 배포 | 배포 승인 | AI 상담: 초보 흐름(비교표·담기·동률 안내), '벤치플룸관이 뭐예요?'(제조사 자료 없음 + 등록 규격) |
| **1-②** | 제조사 자료 DB: `10_create_tables` → `20_insert_approved`(14행) → `30_post_check`(지문 `ee65a7ee…fd64e`) — [APPLY-ORDER.md](APPLY-ORDER.md) | DB 승인 | '스틸 그레이팅이랑 중하중 차이' → 가야스틸 출처 카드, '보도블록 오염' → 선로드 |
| **1-③** | 법령 근거 DB: 칸 추가 → `10`(66행 비활성) → `30` → `20_activate_ready`(20건, V21·V22 제외) → `30` | DB 승인 | '가게 앞 인도를 차가 가로질러…' → 보도 지침 카드, '집 마당 주차' → 주차장법 카드·공공도로 근거 없음, '벤치플룸관' → KS F 4010 |
| 2 | B 19개 + D 3개(같이) | 배포 승인 | 잘못된 비밀번호 로그인 문구, 회원가입·장바구니 수량 1000 문구, 견적 요청 360px, 상품 목록 배너, 관리자 로그인 화면 |
| 3 | C 14개 | 배포 승인 | 관리자 통계, 관리자·직원 화면 오류 문구 |

- 1-① 을 DB 보다 먼저 권장: 옛 코드도 새 DB 를 읽을 수 있지만 제품별 묶음·조건 문장·이름 정리·근거 구분이 없음.
- 각 단계는 독립적으로 되돌릴 수 있다(코드 `rollback`, DB `90_rollback`).

## 4. 명령 (사용자 PC — ③ upload 는 단계별 승인 후)

```bash
git pull origin claude/blissful-turing-8e4xrr
```

1단계(A):

```bash
scripts/deploy-files.sh check 02a98c2 app/rag.php app/ai_tools.php app/external_products.php app/chat_renderer.php public/assets/chat-ai.js
```

2단계(B+D):

```bash
scripts/deploy-files.sh check 02a98c2 public/api/auth/login.php public/api/auth/register.php public/api/cart.php public/api/estimate.php public/api/estimate/favorites.php public/api/estimate/quick-items.php public/api/estimate/reorder.php public/api/mypage/profile.php public/api/order.php public/api/products/by-category.php public/api/sites/index.php public/api/sites/orders.php app/layout.php app/quick_estimate_renderer.php public/login.php public/register.php public/product.php public/assets/mypage.js public/assets/corp-home.js public/assets/styles.css public/products.php public/estimate.php
```

3단계(C):

```bash
scripts/deploy-files.sh check 02a98c2 public/admin-categories.php public/admin-orders.php public/admin-product.php public/admin-products.php public/admin-stats.php public/admin-users.php public/api/admin/categories.php public/api/admin/orders.php public/api/admin/products.php public/api/admin/users.php public/api/staff/estimates.php public/api/staff/vendors.php public/staff/estimate-detail.php public/staff/vendors.php
```

- `check` 가 '서버 ≠ 02a98c2' 로 나오면(그사이 다른 배포) 업로드하지 말고 결과 공유 — 기준 커밋을 다시 정한다.
