-- ─── 업체 상품(syc_products): 상차수량 칸 추가 (사용자 요청 2026-11-24) ─────────────────────────────
-- 왜: 업체 엑셀(동아산업 등)의 상차수량(차 한 대에 싣는 수량, 예 '본/25톤' 기준 10)을 버리지 않고 저장. 일괄 등록 칸 맞추기에서 고를 수 있게.
-- 실행: 사용자가 phpMyAdmin 에서(운영 DB). 아래 ① 확인 → ② 추가 → ③ 확인 순서.
-- 영향: 새 칸 추가만(NULL 허용, 기존 행 값 변화 없음). 가격 칸·가격 계산 입력·키·인덱스·다른 표(products 등) 변경 없음.
-- 코드 순서: 이 파일(DB) 먼저 → 코드 배포(app/syc_bulk.php 가 이 칸에 저장 — 칸이 없을 때 새 코드를 올리면 일괄 등록이 실패함).
-- 되돌리기: ALTER TABLE syc_products DROP COLUMN load_qty;  (칸의 값도 지워짐)

-- ① 확인: 0 이면 아직 없음(1 이면 이미 있으니 ②를 실행하지 않음)
SELECT COUNT(*) AS load_qty_exists FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syc_products' AND COLUMN_NAME = 'load_qty';

-- ② 추가
ALTER TABLE syc_products
    ADD COLUMN load_qty DECIMAL(10,2) NULL COMMENT '상차수량(차 한 대에 싣는 수량, 참고 — 업체 엑셀 값 그대로)' AFTER weight_kg;

-- ③ 확인: load_qty decimal(10,2) 한 줄
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE FROM information_schema.COLUMNS
 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'syc_products' AND COLUMN_NAME = 'load_qty';
