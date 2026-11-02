# 코드 배포 계획 — AI 상담 고도화 (준비 문서, 배포는 승인 후)

> 기준: 운영 마지막 배포 기록 `02a98c2`(2026-10-30). 배포 대상 = `02a98c2..9249818` 의 `app/`·`public/` 변경 파일(아래 41개). 절차·위험은 [`docs/DEPLOYMENT.md`](../../../DEPLOYMENT.md) 5-1장(`scripts/deploy-files.sh`).
> DB 변경([APPLY-ORDER.md](APPLY-ORDER.md))과 순서 무관 — 리허설에서 새 코드 + 옛 DB, 옛 코드(02a98c2) + 새 DB 모두 상담 정상(PASS 181).

## 1. 포함되는 변경

- **AI 상담**: 제조사 승인 자료 제품별 묶음·조건 질문 정확도, 자료 없는 자재는 국가표준·등록 규격으로만(확인된 기본 정보 카드), 근거 구분(공식 기준·제조사 자료·저희 권장 기준), 직전 추천표로 '제일 저렴한 걸로 담아 줘' 처리·동률 안내, 확인 안 된 KS 번호·강도 수치 안내, 검색 오류 문구·중간 추측 글 제거, 사유지·공공 보도 표현 — 실제 GPT-4o 1~3차로 확인·수정
- **닷홈 4xx 오류 문구**: 로그인·회원가입·장바구니·견적·마이페이지·현장·주문·직원·관리자 API 검증 오류 → 200 + ok:false, 화면 호출부
- **화면**: 견적 요청 360px 가로 스크롤(A-5), 견적서 패널 수량칸(A-6)
- **관리자·직원**: 통계 500 오류(A-3), 주문 화면 경고(A-7), 내부 오류 문장 노출 차단, 업체 화면 XSS
- **main 에 이미 있던 미배포 변경**(02a98c2 이후 main): 상품 목록 상단 배너 통일, 메인·상담 폰트 확정값, 상담 말풍선·입력칸 스크롤바, 관리자 로그인 화면 — 운영에 이미 올렸다면 ② 비교에서 '서버 = 기준' 이 아니게 나오므로 기준 커밋을 바꾼다
- 제외: `app/external_export.php`·`app/external_crawl.php`(사용자 PC 수집·이관 명령 전용, 웹에서 쓰지 않음), `database/`(SQL 은 phpMyAdmin 으로 따로), `tests/`·`scripts/`·`docs/`

## 2. 명령 (사용자 PC, Git Bash — ③ 업로드는 승인 후)

```bash
git pull origin claude/blissful-turing-8e4xrr
```

```bash
scripts/deploy-files.sh check 02a98c2 app/ai_tools.php app/chat_renderer.php app/external_products.php app/layout.php app/quick_estimate_renderer.php app/rag.php public/admin-categories.php public/admin-orders.php public/admin-product.php public/admin-products.php public/admin-stats.php public/admin-users.php public/api/admin/categories.php public/api/admin/orders.php public/api/admin/products.php public/api/admin/users.php public/api/auth/login.php public/api/auth/register.php public/api/cart.php public/api/estimate.php public/api/estimate/favorites.php public/api/estimate/quick-items.php public/api/estimate/reorder.php public/api/mypage/profile.php public/api/order.php public/api/products/by-category.php public/api/sites/index.php public/api/sites/orders.php public/api/staff/estimates.php public/api/staff/vendors.php public/assets/chat-ai.js public/assets/corp-home.js public/assets/mypage.js public/assets/styles.css public/estimate.php public/login.php public/product.php public/products.php public/register.php public/staff/estimate-detail.php public/staff/vendors.php 
```

- ② check: 문법·단위 테스트 → 서버 파일 백업 → 서버 파일이 `02a98c2` 버전과 같은지 비교(서버 변경 없음). 다르면 업로드하지 말고 결과 공유.
- ③ `scripts/deploy-files.sh upload backup/<폴더>` — **배포 승인 후**. ⑤ 문제 시 `scripts/deploy-files.sh rollback backup/<폴더>`.

## 3. 배포 후 확인 (운영 화면)

| 확인 | 기대 |
|---|---|
| 잘못된 비밀번호 로그인 | '이메일 또는 비밀번호가 올바르지 않습니다.'(이전: 네트워크 오류) |
| AI 상담 '집 앞에 보도블록 … 차가 지나가도 괜찮나요?' → '마당·승용차·4m×5m' → '제일 저렴한 걸로 담아 주세요' | 80mm 저희 권장 기준 안내 → 4종 비교표(필요 수량·예상 자재비) → 담기 + 같은 금액 제품 안내 |
| AI 상담 '벤치플룸관이 뭐예요?' | 제조사 자료 없음 + 토목몰 등록 규격(DB 반영 후 KS F 4010) — 용도·구조 지어내지 않음 |
| 견적 요청 페이지 휴대폰(360px) | 가로 스크롤 없음, 품목 카드 |
| 관리자 통계 | 500 오류 없이 표시 |
| DB 반영(APPLY-ORDER) 후 '스틸 그레이팅이랑 중하중 차이', '인도 차량 출입 블록 두께' | 가야스틸 출처 카드 / 보도 지침 법령 카드 |
