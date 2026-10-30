-- 견적 접수 실패 복구 — 운영 estimates 에 없는 컬럼 4개 + reorder_alerts 테이블 추가 (추가형 · MySQL 8.0 문법)
--
-- 원인: database/alter_estimates_v2.sql 이 MariaDB 전용 문법(컬럼 추가에 '존재하지 않을 때만' 조건)이라 MySQL 8.0 에서 문법 오류 → 운영 미적용.
--       현재 운영 코드(app/estimate.php)는 user_id·mode·requested_delivery_at 을 넣어 견적 접수가 '견적 생성 중 오류'로 실패한다.
-- 운영 확인(2026-10-30, 사용자 phpMyAdmin): estimates 0건, user_id·mode·requested_delivery_at·delivery_completed_at 없음, status 기본값 REQUESTED,
--       reorder_alerts 없음(마이페이지 배송 내역 API 500).
-- 하지 않는 것: status ENUM·기본값 변경(REQUESTED 유지), 데이터 INSERT·UPDATE·DELETE, 다른 테이블 변경.
--
-- 실행(승인 후, 사용자가 phpMyAdmin 에서): 절차·검증은 docs/03-analysis/predeploy/predeploy-check-2026-10-29.md 10장.
--   ① estimates 테이블 내보내기(백업) ② docs/03-analysis/predeploy/estimates_fix_check_readonly.sql [사전] 확인
--   ③ 아래 [A] 실행 → [사후-A] 확인 ④ 아래 [B] 실행 → [사후-B] 확인
-- 로컬 검증(MySQL 8.0.46, 운영과 같은 estimates·users 구조 사본, 2026-10-30): [A]·[B] 성공, 견적 행 0건 그대로,
--   현재 운영 코드(516950f)·배포 예정 코드(main) 모두 견적 접수 성공(status 는 REQUESTED), 배송 내역 API 200, 되돌리기 성공.

-- [A] 견적 접수 복구 (필수) — 견적 0건이라 기존 데이터 영향 없음
ALTER TABLE estimates
    ADD COLUMN user_id               INT UNSIGNED NULL AFTER id,
    ADD COLUMN mode                  ENUM('chat','quick') NOT NULL DEFAULT 'chat' AFTER memo,
    ADD COLUMN requested_delivery_at DATETIME NULL,
    ADD COLUMN delivery_completed_at DATETIME NULL,
    ADD INDEX  idx_estimates_user (user_id);

-- [B] 마이페이지 배송 내역 API 오류 복구 — 정의는 database/add_reorder.sql 과 같음(빈 테이블)
--     [사전] 확인에서 users.id·estimates.id 가 'int unsigned', 두 테이블이 InnoDB 일 때만 실행(다르면 외래 키 생성 오류 → 아무것도 만들어지지 않음)
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 되돌리기(문제 시, 승인 후 — 실행하면 견적 접수가 다시 실패한다):
--   [B] DROP TABLE reorder_alerts;
--   [A] ALTER TABLE estimates DROP INDEX idx_estimates_user, DROP COLUMN user_id, DROP COLUMN mode, DROP COLUMN requested_delivery_at, DROP COLUMN delivery_completed_at;
--   (복구 후 접수된 견적이 있으면 그 행의 user_id·mode·납품 희망일 값은 사라지고 견적 행 자체는 남는다)
