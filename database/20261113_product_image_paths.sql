-- ─── 상품 이미지 경로 3종: 썸네일·상세·규격 이미지(사용자 결정 2026-11-13) ─────────────────────────────────
-- 왜: 일괄 등록 엑셀에 이미지 경로 3칸(썸네일·상세·규격 이미지 url 경로)을 넣고, 서버에 미리 올려 둔 파일을 경로로 찾아 등록한다
--     (이미지 ZIP 업로드는 쓰지 않음). 지금 칸: 케이토목 products.image_url 1개, 상용콘크리트 syc_products 대표·썸네일 2개 → 빠진 칸 추가.
-- 내용(모두 추가 — 기존 칸·행·값 변화 없음, 가격 칸 아님):
--   products.image_thumb_url  썸네일(목록) — 비어 있으면 화면은 image_url(상세 이미지)을 씀
--   products.image_spec_url   규격 이미지(상세 화면 '규격 이미지' 영역)
--   syc_products.image_spec_file  규격 이미지(상용콘크리트·업체 원본) — 대표(image_file)·썸네일(image_thumb_file)은 그대로
-- 칸 대응: 엑셀 '썸네일' → products.image_thumb_url / syc_products.image_thumb_file, '상세' → products.image_url / syc_products.image_file,
--          '규격' → products.image_spec_url / syc_products.image_spec_file
-- 코드 순서: 이 파일(DB) → 코드 배포. 되돌리기:
--   ALTER TABLE products DROP COLUMN image_spec_url, DROP COLUMN image_thumb_url;
--   ALTER TABLE syc_products DROP COLUMN image_spec_file;

ALTER TABLE products
    ADD COLUMN image_thumb_url VARCHAR(255) NULL COMMENT '썸네일(목록) 이미지 경로 — 웹루트 기준 경로(/…) 또는 외부 주소. 비면 image_url' AFTER image_url,
    ADD COLUMN image_spec_url  VARCHAR(255) NULL COMMENT '규격 이미지 경로(상세 화면) — 웹루트 기준 경로(/…) 또는 외부 주소' AFTER image_thumb_url;

ALTER TABLE syc_products
    ADD COLUMN image_spec_file VARCHAR(255) NULL COMMENT '규격 이미지 경로(상세 화면) — 웹루트 기준 경로(/…) 또는 외부 주소' AFTER image_thumb_file;
