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
