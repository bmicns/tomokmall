DRAFT_STOP_승인_전_실행_금지_prod_estimates_check_readonly_결과로_확정한_뒤_database_폴더로_옮겨_사용;
-- [초안 · 운영 미적용] 견적 저장 실패 복구 — 운영 estimates 에 없는 컬럼과 reorder_alerts 테이블만 추가(추가형)
-- 원인: database/alter_estimates_v2.sql 이 MariaDB 전용 'ADD COLUMN IF NOT EXISTS' 라 MySQL 8.0 에서 문법 오류 → 운영 미적용 추정.
--       현재 운영 코드(app/estimate.php)는 user_id·mode·requested_delivery_at 을 넣으므로 견적 접수가 '견적 생성 중 오류'로 실패(로컬 재현).
-- 하지 않는 것: status ENUM·기본값(PENDING) 변경 — 직원·관리자 화면과 불일치 문제가 있어 별도 판단(purchase-flow-2026-10-29.md 3장).
-- 전제: prod_estimates_check_readonly.sql [1] 에서 아래 4개 컬럼이 모두 '없음'일 때. 하나라도 있으면 그 줄을 빼고 사용.
--       [3] 에서 users.id·estimates.id 가 INT UNSIGNED 가 아니면 reorder_alerts 외래 키 형식을 맞춰 수정.
-- 실행 순서(승인 후): phpMyAdmin 에서 estimates 테이블 내보내기(백업) → 아래 실행 → [1] 다시 조회 → 견적 1건 접수 확인
-- 로컬 MySQL 8.0.46 검증(2026-10-30): 실행 성공, 현재 운영 코드(516950f)·배포 코드(main) 모두 견적 접수 성공, 기존 행 영향 없음, 아래 되돌리기 SQL 동작.

ALTER TABLE estimates
    ADD COLUMN user_id               INT UNSIGNED NULL AFTER id,
    ADD COLUMN mode                  ENUM('chat','quick') NOT NULL DEFAULT 'chat' AFTER memo,
    ADD COLUMN requested_delivery_at DATETIME NULL,
    ADD COLUMN delivery_completed_at DATETIME NULL,
    ADD INDEX  idx_estimates_user (user_id);

CREATE TABLE IF NOT EXISTS reorder_alerts (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id     INT UNSIGNED NOT NULL,
    estimate_id INT UNSIGNED NOT NULL,
    alert_after ENUM('1y','2-3y','5y+','unknown') NOT NULL DEFAULT 'unknown',
    alert_at    DATE         NULL,
    is_sent     TINYINT(1)   NOT NULL DEFAULT 0,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_user_estimate (user_id, estimate_id),
    CONSTRAINT fk_alert_user     FOREIGN KEY (user_id)     REFERENCES users(id),
    CONSTRAINT fk_alert_estimate FOREIGN KEY (estimate_id) REFERENCES estimates(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;   -- 정의는 database/add_reorder.sql 과 같음

-- 되돌리기(문제 시, 승인 후): 추가한 것만 제거 — 그 사이 저장된 견적의 user_id·mode·납품 희망일 값은 사라짐(견적 행 자체는 남음)
-- DROP TABLE reorder_alerts;
-- ALTER TABLE estimates DROP INDEX idx_estimates_user, DROP COLUMN user_id, DROP COLUMN mode, DROP COLUMN requested_delivery_at, DROP COLUMN delivery_completed_at;
