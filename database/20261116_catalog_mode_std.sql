-- ─── 고객 화면 상품 출처에 '공통 분류(std)' 추가(2026-11-16 사용자 결정 A안) ─────────────────────────────
-- 왜: 모든 업체 상품을 공통 분류에 함께 보이고 상품별 활성으로 노출(app/syc_publish.php syc_publish_std_apply 가 mode = 'std' 기록).
-- 내용: syc_catalog_state.mode 값 목록에 'std' 추가(끝에 붙임 — 기존 값 그대로). 가격 표 아님.
-- 순서: 이 파일(DDL) → 20261116_std_categories.sql(DML) → 코드 배포 → 관리자가 상용콘크리트 엑셀 다시 일괄 등록(자동 반영)
-- 되돌리기: UPDATE syc_catalog_state SET mode = 'syc' WHERE mode = 'std'; ALTER TABLE syc_catalog_state MODIFY mode ENUM('ktomok','syc') NOT NULL DEFAULT 'ktomok' COMMENT '고객 화면 상품 출처';

ALTER TABLE syc_catalog_state
    MODIFY mode ENUM('ktomok','syc','std') NOT NULL DEFAULT 'ktomok' COMMENT '고객 화면 상품 출처: ktomok·syc(전환)·std(공통 분류 — 모든 업체, 상품별 활성)';
