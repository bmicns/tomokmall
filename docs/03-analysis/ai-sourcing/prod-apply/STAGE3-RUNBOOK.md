# 운영 반영 3단계 실행표 — 관리자·직원 화면 14개

> 상태: **준비 완료, 승인 대기**(업로드는 사용자 승인 후 사용자 PC 에서). DB 변경 없음. 1·2단계·상담원 문의 기능과 독립.
> 서버의 이 14개 파일은 1·2단계에서 건드리지 않았으므로 비교 기준은 **`02a98c2`**. main(`a1f0a6e`) 의 14개 = 작업 브랜치의 14개(동일 확인).

## 1. 무엇이 바뀌나

| 파일 | 바뀌는 것 |
|---|---|
| `public/admin-stats.php` | **통계 화면 500 해결** — 자리표시 SQL 을 `query()` 로 실행하던 2곳을 `prepare()` 로 |
| `public/admin-orders.php` | 상태 필터 없이 열 때 PHP 경고(로그) 제거 |
| `public/api/admin/{categories,orders,products,users}.php` | 입력 오류를 HTTP 200 + `ok:false` 로(닷홈이 4xx 본문을 HTML 로 바꿔 문구가 사라지던 문제), **SQL 오류 문장을 화면에 보내지 않고 서버 로그로** |
| `public/api/staff/{estimates,vendors}.php` | 같음 |
| `public/admin-{categories,product,products,users}.php`, `public/staff/{estimate-detail,vendors}.php` | 위 API 의 `ok:false` 문구 표시, 응답이 깨져도 일반 문구(멈추지 않음) |
| `public/staff/vendors.php` | **업체명 XSS 차단** — 품목별 업체 목록에 업체명을 이스케이프해서 표시 |

## 2. 사전 검증 (2026-11-03, 이 환경 — 운영과 같은 조합, **PHP 8.4**)

운영 조합 = main `a1f0a6e` + `app/ai_tools.php` 는 운영과 같은 `b5e9602`. 14개만 서버 버전(`02a98c2`)/새 버전으로 바꿔 같은 시험을 비교했다(로컬 테스트 DB: 수집 상품 1,452건, `[TEST]` 관리자·직원 계정).

| 시험 | 지금 서버(02a98c2) | 3단계 후 |
|---|---|---|
| 관리자 6개 화면(분류·주문·제품·제품목록·통계·회원) | 통계 **500**(`SQLSTATE[HY093]`), 나머지 200 | **모두 200** |
| 관리자 API 잘못된 입력(회원 역할·없는 주문·분류 이름 없음) | 403 · 400 · 500 | 200 + 문구 |
| 직원 API 잘못된 입력(업체명 없음·견적 id 없음) | 400 · 400 | 200 + 문구 |
| 업체명 `<img src=x onerror=…>` 등록 후 품목별 업체 목록 | **스크립트 실행됨** | 글자로만 표시 |
| 주문 화면 상태 필터 없음 | PHP 경고 로그 | 없음 |
| JS 오류 | 없음 | 없음 |

- 단위·통합 테스트(PHP 8.3·8.4 모두): functions 243 · ai_search 40 · consult_journey 6 · consult_material_checks 23 · consult_replay 15 · material_precision 9 · favorites_api 69 · rag_prompt 78 · rag_legal 51 · rag_process_loop 13 · rag_road_context 38 · rag_category 46 · law_api 67 · legal_prod_sql 37 — 모두 통과.
- 참고(로컬 전용): 로컬 테스트 DB 에는 운영에만 있는 `categories.discount_corporate` 칸이 없어 분류 API 로그에 'Unknown column' 이 남음 — 운영엔 칸이 있어 해당 없음.

## 3. 선택 — `app/bootstrap.php` 1줄 (PHP 8.4 경고 로그)

PHP 8.4 에서 `E_STRICT` 상수가 사용 중단되어 **요청마다** 서버 오류 로그에 `Deprecated: Constant E_STRICT is deprecated` 가 1줄씩 쌓인다(화면 영향 없음, 위 시험에서 25회). 작업 브랜치에서 `& ~E_STRICT` 를 뺐다(PHP 8.0 부터 E_STRICT 는 발생하지 않으므로 동작 동일).
서버의 `bootstrap.php` = `02a98c2` 라 같은 check 로 함께 올릴 수 있다 — 원하면 아래 check 명령 끝에 `app/bootstrap.php` 를 붙인다(main 병합 후).

## 4. 명령 (사용자 PC, Git Bash — 업로드는 승인 후)

```bash
git checkout main
```

```bash
git pull origin main
```

```bash
scripts/deploy-files.sh check 02a98c2 public/admin-categories.php public/admin-orders.php public/admin-product.php public/admin-products.php public/admin-stats.php public/admin-users.php public/api/admin/categories.php public/api/admin/orders.php public/api/admin/products.php public/api/admin/users.php public/api/staff/estimates.php public/api/staff/vendors.php public/staff/estimate-detail.php public/staff/vendors.php
```

- 기대: 단위 테스트 통과 → 14개 백업 → **모두 '동일'**(또는 줄바꿈만 다름) → `check 통과`. '다름'이 하나라도 나오면 멈추고 결과 공유.

```bash
scripts/deploy-files.sh upload backup/<check 가 알려 준 폴더>
```

```bash
bash scripts/smoke.sh
```

## 5. 배포 후 운영 확인 (관리자·직원 계정)

| 확인 | 기대 |
|---|---|
| 관리자 › 통계 | 화면이 열림(이전 500) |
| 관리자 › 주문관리·견적관리·회원관리·제품관리·카테고리 | 이전처럼 열림 |
| 관리자 › 회원관리에서 본인 역할 변경 시도 | '자기 자신은 변경할 수 없습니다.' 문구 |
| 직원 › 업체 관리에서 업체명 비우고 저장 | '업체명 필수' 문구 |
| 직원 › 견적 상세 발송 | 이전처럼 동작 |

## 6. 되돌리기

`scripts/deploy-files.sh rollback backup/<폴더>` — 14개를 백업으로 복구(DB 변경 없음).
