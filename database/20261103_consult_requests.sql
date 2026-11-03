-- ─── 상담원 문의 요청(consult_requests) · 처리 이력(consult_request_events) 테이블 생성 ─────────────
-- 목적: AI 상담에서 상품 추천 뒤 고객이 남기는 '상담원 문의 요청'과, 관리자 '상담 요청' 화면의
--       상태 변경 · 문자 답변 · 메모 · 처리 이력을 저장한다.
--       AI 상담 대화 자체는 저장하지 않는다 — 기존 chat_logs(같은 session_key) 를 그대로 읽어 관리자 상세에 전체 표시.
-- 사용 코드: app/consult.php, public/api/consult/request.php, public/admin-consults.php,
--           public/admin-consult.php, public/api/admin/consults.php
-- 성격: 추가형(additive). 기존 테이블·데이터 변경 없음. IF NOT EXISTS 라 이미 있으면 아무 일도 하지 않음.
--       외래키는 두지 않는다(운영 users.id 타입 차이로 생성이 실패하지 않게 — 연결은 코드에서 확인).
-- 개인정보: name·tel 은 상태를 '완료(closed)'로 바꾸는 순간 NULL 로 지우고 pii_destroyed_at 을 남긴다(코드).
--           문자 이력(consult_request_events.detail)에는 받는 번호를 저장하지 않는다.
-- 실행: 사용자 승인 후 phpMyAdmin 에서 [1] 사전 확인 → [2] 생성 → [3] 확인 순서로. (코드 배포보다 먼저)
-- 되돌리기: [4] (테이블 삭제 — 쌓인 문의 요청이 모두 사라지므로 백업 후에만)

-- [1] 사전 확인(읽기 전용) ------------------------------------------------------
-- 0 이면 생성 필요, 2 이면 이미 있음([2] 생략)
SELECT COUNT(*) AS consult_tables_exist
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('consult_requests', 'consult_request_events');

-- [2] 생성 ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS consult_requests (
    id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key      VARCHAR(64)  NULL                COMMENT 'AI 상담 키(chat_logs.session_key·ai_sessions.session_key)',
    user_id          INT UNSIGNED NULL                COMMENT '로그인 회원이면 users.id, 비회원 NULL',
    status           VARCHAR(10)  NOT NULL DEFAULT 'pending' COMMENT 'pending=접수됨, contact=발송됨, closed=완료(개인정보 파기)',
    name             VARCHAR(50)  NULL                COMMENT '고객 이름 — 완료 시 NULL(파기)',
    tel              VARCHAR(20)  NULL                COMMENT '고객 연락처(010-0000-0000) — 완료 시 NULL(파기)',
    message          TEXT         NOT NULL            COMMENT '문의 내용(최대 500자)',
    agreed_at        DATETIME     NOT NULL            COMMENT '개인정보 수집·이용 동의 일시',
    terms_version    VARCHAR(20)  NOT NULL            COMMENT '동의한 안내문 버전',
    memo             TEXT         NULL                COMMENT '관리자 처리 메모(고객에게 보이지 않음)',
    memo_updated_at  DATETIME     NULL,
    pii_destroyed_at DATETIME     NULL                COMMENT '이름·연락처 파기 일시',
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_consult_status  (status, id),
    INDEX idx_consult_created (created_at),
    INDEX idx_consult_session (session_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS consult_request_events (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    request_id  INT UNSIGNED NOT NULL              COMMENT 'consult_requests.id',
    type        VARCHAR(10)  NOT NULL              COMMENT 'created=고객 요청, status=상태 변경, sms=문자 발송',
    status      VARCHAR(10)  NULL                  COMMENT '이 이벤트 뒤의 상태(created·status)',
    sms_type    VARCHAR(5)   NULL                  COMMENT 'SMS / LMS',
    result      VARCHAR(10)  NULL                  COMMENT 'sms: ok / fail',
    detail      TEXT         NULL                  COMMENT 'sms: 보낸 내용(받는 번호 없음) · 실패 시 사유',
    admin_id    INT UNSIGNED NULL                  COMMENT '처리한 관리자 users.id(고객 요청은 NULL)',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_consult_ev_req (request_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- [3] 확인(읽기 전용) ------------------------------------------------------------
-- 2 가 나와야 한다
SELECT COUNT(*) AS consult_tables_exist
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('consult_requests', 'consult_request_events');

-- [4] 되돌리기(필요할 때만 — 백업 후, 별도 승인) -----------------------------------
-- DROP TABLE IF EXISTS consult_request_events;
-- DROP TABLE IF EXISTS consult_requests;
