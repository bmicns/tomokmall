# 상용콘크리트 테스트 데이터 생성 도구 (로컬 전용 — 배포 안 함)

`data/syccrt/products.json`(정리된 테스트 데이터, 704행) → 일괄 등록 테스트 파일을 다시 만든다.
실제 업체 데이터가 아니다(카탈로그 판독본 + 임시값). 운영 DB 에 직접 넣지 않는다 — 운영은 관리자 '일괄 등록' 으로만.

```bash
# 1) 엑셀(상용콘크리트) + 로컬 적재 SQL — 카테고리 마이그레이션 3개(쉼표)를 읽어 분류명·숨긴 카테고리 반영
python3 -I scripts/syc-data/build_outputs.py data/syccrt/products.json \
  database/20261110_syc_categories.sql,database/20261111_syc_categories_split.sql,database/20261111_syc_categories_merge.sql \
  data/syccrt/import.sql data/syccrt/syc-products-bulk-test.xlsx
# 2) 두 번째 업체([TEST] 테스트콘크리트) 엑셀 — 업체·출처·출처ID·이미지 파일명만 바꿈
python3 -I scripts/syc-data/make_b.py data/syccrt/syc-products-bulk-test.xlsx data/syccrt/syc-products-bulk-test-b.xlsx
# 3) 임시 이미지 ZIP(대표 800px + 썸네일 200px, 'TEST IMAGE' 표시) — 저장소 밖에 만든다(약 30MB, Git 에 넣지 않음)
python3 -I scripts/syc-data/build_images.py data/syccrt/products.json /tmp/syc-products-images-test.zip database/20261110_syc_categories.sql
#    두 번째 업체용: 4번째 인자에 업체 표시 글자(띠 색 다름), 출처ID 는 tc- 로 바뀐 엑셀과 맞춰 products.json 을 바꿔 쓴다
```

- `data/syccrt/syc-products-bulk-test-raw.xlsx` = **예전 업체 표기 원본**(자동 정리 전). 자동 정리(app/syc_normalize.php) 결과가 products.json 과 같아야 한다 — `tests/functions.test.php` 가 대조.
- 테스트 데이터를 고칠 때: products.json 을 고치고 1)·2) 다시 실행 → `php tests/functions.test.php`.

## 표준 분류 매핑 대조표(2026-11-12)

`build_category_map.py <입력 폴더> <엑셀> <md>` — 상용콘크리트 품목 → 표준 분류(categories) 추천표. 판단(추천 표준 분류·신뢰도·근거)은 스크립트 안 `M` 에 있다.
입력 폴더의 JSON 은 운영 읽기 전용 조회 결과(`scripts/tomok db query`): `std_cats.json`(categories id·parent·name·slug·상품 수),
`std_axes.json`(category_spec_axes 분류별 칸), `syc_items.json`(syc_categories 품목·상품 수·상품명·목록 칸). 결과: `data/mapping/syc-std-category-map.xlsx`, `docs/02-design/syc-std-category-map.md`.

## 치수 칸 맞춤표·다업체 DB SQL 초안(2026-11-12)

입력 폴더에 운영 읽기 전용 조회 JSON 을 더 둔다: `std_prods.json`(매핑 대상 표준 품목의 케이토목 상품 치수), `std_axes_full.json`·`syc_axes_full.json`(이름표 전체), `syc_units.json`.
```bash
python3 -I scripts/syc-data/axis_match.py <입력> scripts/syc-data/build_category_map.py            # 값으로 칸 대응 근거 → <입력>/axis_match.json
python3 -I scripts/syc-data/build_axis_map.py <입력> scripts/syc-data/build_category_map.py \
  data/mapping/syc-std-axis-map.xlsx docs/02-design/syc-std-axis-map.md data/mapping/syc-std-axis-map.json   # 판단(O)은 스크립트 안
python3 -I scripts/syc-data/build_multivendor_sql.py <입력> scripts/syc-data/build_category_map.py data/mapping/syc-std-axis-map.json docs/02-design/multi-vendor-sql
```
