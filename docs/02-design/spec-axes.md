# 상품 규격 구조화 — `products` 규격 칸 + `category_spec_axes`

> 상태(2026-11-06): 작업 브랜치 구현·로컬 검증 완료, **운영 미반영**(DB·파일 모두 승인 후).
> 사용자 결정(2026-11-05~06): 기존 `products.spec` 은 그대로 둠 · 괄호 코드는 `spec_code`(규격 코드) · 폭/너비 약자 W · 세로 약자 H ·
> 개거 `280T*260T` = 상하두께 × 벽체두께(두 번째 두께 칸 `dim_t2`), 개거 폭·높이·길이는 m · 개거 두께 약자는 T 지만 화면엔 이름만 ·
> 화면 디자인은 캔버스 '기업회원용 상세화면 — 규격 개선안(After)' 아트보드 기준 · 이름을 모르는 값은 추측하지 않고 묻는다.
> 이름표 확인 목록: [`docs/03-analysis/spec-axes-category-list.md`](../03-analysis/spec-axes-category-list.md)

## 1. 왜

`spec` 의 `규격:` 값(예: `900*800*2000*85T (900B)`)에 치수 4~5개·규격 코드·옵션이 이름 없이 한 문자열로 들어 있어
목록 필터는 문자열 통째(상품마다 1건)만, 표는 원문만 보여 줄 수 있었다. 숫자마다 이름(폭·높이…)을 붙여 따로 저장해
필터를 축별 값 버튼으로, 표를 축별 칸으로 바꾼다.

## 2. DB (`database/20261106_product_spec_axes.sql`, 추가형)

`products` 새 칸(모두 NULL 허용, 숫자는 mm):

| 칸 | 예 (`900*800*2000*85T (900B)`) | 뜻 |
|---|---|---|
| `spec_code` | 900B | 규격 코드(원문 괄호 속) |
| `dim_d` `dim_w` `dim_h` `dim_l` `dim_t` `dim_t2` | – · 900 · 800 · 2000 · 85 · – | 축별 숫자. 칸 이름의 뜻은 카테고리 이름표가 정함 |
| `spec_option` | – | 치수·코드 뒤 나머지(예: `하부구체_KS`, `(5*3)_국산`) |
| `spec_dims_src` | `900*800*2000*85T (900B)` | 값을 채울 때 기준이 된 규격 원문. 지금 원문과 다르면 화면은 원문 사용 |

`category_spec_axes` — 한 행 = "이 카테고리 규격의 `seq` 번째 숫자는 `label`(`symbol`)이고 `dim_<axis_key>` 에 담는다".

| 칸 | 뜻 |
|---|---|
| `category_id` | 붙일 카테고리. **하위 카테고리는 가장 가까운 상위의 이름표를 물려받음** |
| `seq` | 규격 표기에서 몇 번째 숫자인지(1부터) = 화면 칸 순서 |
| `axis_key` | `d` `w` `h` `l` `t` `t2` 또는 `none`(상위 이름표 끊기 — 숫자를 읽지 않음) |
| `label` · `symbol` | 화면 이름·약자(약자가 확인되지 않으면 NULL — 이름만 표시) |
| `show_symbol` | 0 이면 약자를 저장만 하고 화면엔 이름만(예: 개거 상하두께·벽체두께 T) |
| `unit` | 규격 원문·화면 단위 `mm`/`m` (저장은 항상 mm, `m` 이면 ×1000) |
| `use_filter` | 0 이면 표에만(필터 그룹 없음) |

확정해 넣은 이름표(마이그레이션 [4], id·이름이 둘 다 맞을 때만 들어감):

| 카테고리 | 규격 예 | 이름표 |
|---|---|---|
| 24 수로관(벤치3종) | `900*800*2000*85T (900B)` | 폭 W × 높이 H × 길이 L × 두께 T (+규격 코드) |
| 21 개거 | `4.0*1.0*2.0_280T*260T` | 폭 W × 높이 H × 길이 L (m) _ 상하두께 × 벽체두께 (mm, 약자 T·화면엔 이름만) |
| 59 사각맨홀(집수정) | `300*400*600*150T` | 가로 W × 세로 H × 높이 H × 두께 T |
| 61 원형맨홀 | `D900*1200*200T` | 내경 D × 높이 H × 두께 T |

이름표 추가 예(다른 카테고리 — 이름을 확인한 뒤에만):

```sql
INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, unit) VALUES   -- show_symbol 기본 1
  (50, 1, 'd', '내경', 'D', 'mm'), (50, 2, 'l', '길이', 'L', 'mm'), (50, 3, 't', '두께', 'T', 'mm');
-- 중분류에 넣으면 하위 소분류 전체가 물려받는다. 하위 중 숫자를 읽으면 안 되는 곳: (id, 0, 'none', NULL, NULL, 'mm')
```

## 3. 읽는 규칙 (`app/spec_axes.php` `spec_axes_parse` — 맞지 않으면 채우지 않음)

1. `규격:` 값 맨 앞의 숫자 묶음을 `*`·`_` 로 나눠 `seq` 순서로 읽는다. **숫자 개수가 이름표와 다르면 못 읽음.**
2. `D` 접두는 `d` 축만, `T` 접미는 `t`·`t2` 축만, `H` 접미는 `h` 축만. `M` 접미는 미터. 다른 글자가 붙은 숫자(`0RS`, `300용`)는 숫자가 아님.
3. 숫자 묶음 바로 뒤 괄호 코드 `(900B)`·`(M1000C)` → `spec_code`. 그 뒤 나머지 → `spec_option`.
4. 못 읽은 상품은 값을 넣지 않는다 → 그 상품은 화면이 규격 원문을 쓴다. 사람이 넣은 값은 원문이 같은 동안 유지된다.

## 4. 화면 (`public/products.php`, `app/product_filter_renderer.php`, `app/bootstrap.php`)

- **축 모드 조건**: 목록 결과의 카테고리가 **모두 같은 이름표**를 쓸 때만. 이름표가 없거나 섞이면(중분류·검색 등) 지금 화면 그대로.
- **필터**(별도 '규격' 제목 없음): `모델 코드`(작은 글씨 '끝자리 표기', B형·C형 — 코드가 있을 때만) → 이름표 순 축별 값 버튼(개수 표시).
  그룹 이름은 한글 이름, 옆에 작은 약자와 더 작은 단위 — `폭 W(mm)`, 개거 `폭 W(m)`·`상하두께 (mm)`.
  같은 축 안은 '또는', 축끼리는 '그리고'(기존 필터와 같음). 다른 선택 때문에 0건이 되는 값은 누를 수 없음.
  결과 전체에 값이 하나뿐인 축(예: 길이 2,000)은 선택된 채 고정 표시. URL: `ax_w[]=900`, `ax_type[]=B` (값은 mm).
  `필터 적용` 버튼 방식은 기존과 같음. 적용된 필터 칩은 `폭: 600`·`모델 코드: C형`.
- **표**: `규격` 묶음 머리글 아래 작은 회색 글씨로 `모델 코드`(spec_code 900B)·축별 칸(`폭 W`, m 인 축만 `폭 W(m)`)·`옵션`(값 있을 때).
  필터에 고정 표시된 축(값 하나뿐)은 표 칸에서 뺌.
  선택한 필터 값은 파란 굵은 글씨. 채운 기준 원문이 지금과 다른 행은 규격 원문을 묶음 칸 전체에 표시.
  모바일(640px 이하) 카드: `모델 코드 900B · 폭 W 900 · 높이 H 800 · 두께 T 85` 로 이어서 표시.
- **새 칸·표가 없을 때**(마이그레이션 전): `spec_axes_ready()` 가 거짓 → 필터·표·URL 축 조건 모두 무시, 기존 화면 그대로.
- **관리자 상품 저장**(`api/admin/products.php`): 저장 뒤 그 상품의 새 칸을 다시 계산(실패해도 저장은 유지, 로그만).

## 5. 값 채우기 (`scripts/db-tools/spec-axes-fill.php`, CLI)

```bash
php scripts/db-tools/spec-axes-fill.php report                 # 읽기 전용: 카테고리별 채움·그대로·못 읽음 + 못 읽은 상품
php scripts/db-tools/spec-axes-fill.php apply                  # 로컬 *_test DB(127.0.0.1)에만 반영
php scripts/db-tools/spec-axes-fill.php sql --out=<저장소 밖 파일>  # 운영 반영용 UPDATE(각 줄 WHERE id=… AND spec=<원문>)
```

## 6. 로컬 테스트 (운영 복제 DB)

1. `bash scripts/testdb/devdb.sh restore <최신 스냅샷>` (또는 `up` + 재복제) — 운영과 같은 id
2. 마이그레이션 적용: `docker exec -i tomok-devdb sh -c 'MYSQL_PWD="$MYSQL_ROOT_PASSWORD" exec mysql -uroot --default-character-set=utf8mb4 tomokmall_test' < database/20261106_product_spec_axes.sql`
   → [4] 에서 16행이 들어가야 함(`SELECT COUNT(*) FROM category_spec_axes`)
3. `php scripts/db-tools/spec-axes-fill.php report` → `apply`
4. `php -S 127.0.0.1:8080 -t public/` 후 확인: `/products.php?category=24`(수로관), `21`(개거), `59`(사각맨홀), `61`(원형맨홀),
   중분류 `256`·검색(기존 화면 그대로여야 함), 필터 눌러 보기, 모바일 폭

## 7. 운영 반영 순서 (각 단계 승인 필요)

1. DB 구조: 마이그레이션 [1]→[5] (phpMyAdmin 또는 원격 관리 API `db stage-sql` → 관리자 화면 승인). 가격 칸 변경 없음(ADD COLUMN·새 표만)
2. 코드 배포: `app/spec_axes.php`(새 파일) → `app/bootstrap.php`·`app/product_filter_renderer.php`·`public/products.php`·
   `public/api/admin/products.php`·`public/assets/styles.css`. 새 칸이 비어 있어도 화면은 기존과 같음
3. 값 채우기: 운영 복제 DB 에서 `sql --out=…` 로 만든 파일을 승인 후 실행(새 칸만 UPDATE)
4. 확인: 위 4개 카테고리 목록·필터, 중분류·검색 화면이 기존과 같은지

되돌리기: 코드는 이전 커밋 파일로 재배포(새 칸·표가 있어도 옛 코드는 무시). DB 는 마이그레이션 [롤백](새 칸·새 표만 삭제 — 기존 데이터 영향 없음).
`products` 의 DROP COLUMN 은 원격 관리 API 가 막는 작업(가격 보호 표는 ADD 만)이므로 롤백은 phpMyAdmin 에서 한다.
