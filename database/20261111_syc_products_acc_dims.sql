-- ─── 상용콘크리트 상품: 부속 치수 칸 6개 추가 (사용자 검토 반영 2026-11-11) ─────────────────────────────
-- 왜: 옵션(spec_option)에 '그레이팅 245*995*25' 처럼 부속 치수가 글자로 섞여 있었다. 옵션에는 글자(슬라브 없음·그레이팅·주문품)만 두고,
--     그레이팅·덮개·스틸그레이팅·슬라브·그레이팅뚜껑·받침틀 같은 부속의 치수는 이 칸에 넣는다.
--     칸의 뜻(예: 그레이팅 폭)은 품목별 규격 이름표(syc_category_spec_axes, 키 a_w·a_l·a_t·a2_w·a2_l·a2_t)가 정한다 — 상세 화면에만 보인다.
-- 선행: database/20261110_syc_products.sql
-- 영향: 새 칸 추가만(NULL 허용, 기존 행 값 변화 없음). 가격 칸·기존 표(products 등) 변경 없음.
-- 코드 순서: 이 파일(DB) 먼저 → 코드 배포(app/syc_bulk.php 가 새 칸에 저장).
-- 되돌리기: ALTER TABLE syc_products DROP COLUMN dim_a_w, DROP COLUMN dim_a_l, DROP COLUMN dim_a_t,
--                                    DROP COLUMN dim_a2_w, DROP COLUMN dim_a2_l, DROP COLUMN dim_a2_t;  (칸의 값도 지워짐)

ALTER TABLE syc_products
    ADD COLUMN dim_a_w  DECIMAL(8,1) NULL COMMENT '부속 폭 (mm) — 그레이팅·덮개·받침틀 가로·슬라브 지름 등(품목별 이름표)' AFTER dim_x4,
    ADD COLUMN dim_a_l  DECIMAL(8,1) NULL COMMENT '부속 길이 (mm) — 그레이팅 길이·받침틀 세로 등' AFTER dim_a_w,
    ADD COLUMN dim_a_t  DECIMAL(8,1) NULL COMMENT '부속 두께·높이 (mm) — 그레이팅 두께(높이) 등' AFTER dim_a_l,
    ADD COLUMN dim_a2_w DECIMAL(8,1) NULL COMMENT '두 번째 부속 폭 (mm) — 원심력수로관 그레이팅2 등' AFTER dim_a_t,
    ADD COLUMN dim_a2_l DECIMAL(8,1) NULL COMMENT '두 번째 부속 길이 (mm)' AFTER dim_a2_w,
    ADD COLUMN dim_a2_t DECIMAL(8,1) NULL COMMENT '두 번째 부속 두께 (mm)' AFTER dim_a2_l;
