-- ─── 사이트 상품 전환 준비: 상용콘크리트 → 기존 상품 표 반영(B 방식, 사용자 결정 2026-11-12) ─────────────────────
-- 왜: 고객 화면(메인·목록·상세·검색·장바구니·견적·주문·AI 상담)은 products·categories·category_spec_axes 를 쓴다.
--     상용콘크리트(syc_*)를 이 표들에 반영하고 케이토목을 숨기면 기존 화면·디자인이 그대로 상용콘크리트를 보여 준다.
-- 내용(모두 추가 — 기존 행·값 변화 없음):
--   1) products: 상용콘크리트 목록 치수 칸 6개(외경폭·외경높이·중간벽두께·h1·기타 x1·x2) + 세부 치수(spec_detail, 상세 화면 전용 JSON)
--   2) category_spec_axes.axis_key 이름 목록에 위 6개 추가(끝에 붙임 — 기존 값 그대로)
--   3) syc_catalog_state: 지금 사이트 상품 출처(ktomok | syc)·마지막 반영 시각
--   4) syc_catalog_hidden: 상용콘크리트로 전환할 때 숨긴 케이토목 카테고리·상품 id(되돌릴 때 정확히 그 행만 다시 보이게)
-- 가격 칸 변경 없음. 코드 순서: 이 파일(DB) → 코드 배포 → 관리자 화면 '사이트에 반영' → '상용콘크리트로 전환'.
-- 되돌리기: DROP TABLE syc_catalog_hidden, syc_catalog_state;
--          ALTER TABLE products DROP COLUMN spec_detail, DROP COLUMN dim_w_o, DROP COLUMN dim_h_o, DROP COLUMN dim_t_mid,
--                               DROP COLUMN dim_h1, DROP COLUMN dim_x1, DROP COLUMN dim_x2;
--          (axis_key 목록은 새 값을 쓰는 행을 지운 뒤 원래 목록으로 MODIFY)

ALTER TABLE products
    ADD COLUMN dim_w_o   DECIMAL(8,1) NULL COMMENT '외경폭·바깥나비 (mm) — 상용콘크리트 원형사각수로관 등' AFTER dim_d_outlet,
    ADD COLUMN dim_h_o   DECIMAL(8,1) NULL COMMENT '외경높이·바깥높이 (mm)' AFTER dim_w_o,
    ADD COLUMN dim_t_mid DECIMAL(8,1) NULL COMMENT '중간벽두께 (mm) — 암거 2련' AFTER dim_h_o,
    ADD COLUMN dim_h1    DECIMAL(8,1) NULL COMMENT '보조 높이 h1 (mm) — 낮은쪽높이·뚜껑높이 등(품목별 이름표)' AFTER dim_t_mid,
    ADD COLUMN dim_x1    DECIMAL(8,1) NULL COMMENT '기타 치수 x1 (mm) — 파형 피치 등(품목별 이름표)' AFTER dim_h1,
    ADD COLUMN dim_x2    DECIMAL(8,1) NULL COMMENT '기타 치수 x2 (mm) — 파형 깊이 등(품목별 이름표)' AFTER dim_x1,
    ADD COLUMN spec_detail JSON NULL COMMENT '세부 치수(상세 화면 전용): [{"label","symbol","value","unit"}…] — 상용콘크리트 반영 때 채움' AFTER spec_dims_src;

ALTER TABLE category_spec_axes
    MODIFY axis_key ENUM('d','d_base','v','d_i','d_o','w','w_b','w_r','w_t','h','h_high','h_low','depth','l','t','t2','t_tb','t_wall','t_top','t_bottom','mesh','wire_d','wire_no','ts','d_connect','d_inlet','d_outlet','skip','none',
                         'w_o','h_o','t_mid','h1','x1','x2')
        COLLATE utf8mb4_unicode_ci NOT NULL
        COMMENT '값을 담을 products 칸(dim_<axis_key>, wire_no 는 spec_wire_no). skip = 숫자 자리만 차지(저장 안 함), none = 숫자 읽지 않음';

CREATE TABLE IF NOT EXISTS syc_catalog_state (
    id            TINYINT UNSIGNED NOT NULL PRIMARY KEY COMMENT '항상 1',
    mode          ENUM('ktomok','syc') NOT NULL DEFAULT 'ktomok' COMMENT '고객 화면 상품 출처',
    switched_at   DATETIME NULL COMMENT '마지막 전환 시각',
    published_at  DATETIME NULL COMMENT '마지막 사이트 반영 시각',
    updated_by    INT UNSIGNED NULL COMMENT '실행한 관리자 users.id'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='사이트 상품 출처 전환 상태(행 없음 = ktomok)';

CREATE TABLE IF NOT EXISTS syc_catalog_hidden (
    entity      ENUM('category','product') NOT NULL,
    ref_id      INT UNSIGNED NOT NULL COMMENT 'categories.id 또는 products.id',
    hidden_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (entity, ref_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='상용콘크리트 전환 때 숨긴 케이토목 행(되돌리기용)';
