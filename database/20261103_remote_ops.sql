-- ─── Claude 원격 관리 API 관리 테이블 (2026-11-03) ───────────────────────────
-- 추가형 마이그레이션: 새 테이블 4개만 만든다(기존 테이블·데이터 변경 없음).
-- 실행: 운영 phpMyAdmin 에서 1회(사용자 승인 후). 여러 번 실행해도 안전(IF NOT EXISTS).
--
-- remote_ops_nonces    : 요청 nonce(재전송 차단, 10분 뒤 정리)
-- remote_ops_grants    : 작업 단위 승인(요청 → 관리자 화면 승인 → 범위·유효시간 안에서만 쓰기)
-- remote_ops_log       : 실행 이력(시각·작업·대상·영향 행 수·결과·오류) — 인증정보 없음
-- remote_ops_snapshots : 변경 전후 행, 파일 백업, 승인 대기 파일, 테이블 덤프(gzip)
--
-- 되돌리기(관리 API 를 완전히 없앨 때만, 실행 이력·백업도 함께 사라짐):
--   DROP TABLE remote_ops_snapshots, remote_ops_log, remote_ops_grants, remote_ops_nonces;
-- 확인: SHOW TABLES LIKE 'remote\_ops\_%';

CREATE TABLE IF NOT EXISTS remote_ops_nonces (
    nonce       CHAR(32) CHARACTER SET ascii NOT NULL PRIMARY KEY,
    expires_at  DATETIME NOT NULL,
    INDEX idx_rops_nonces_exp (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_ops_grants (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    code               VARCHAR(20) CHARACTER SET ascii NOT NULL COMMENT '승인 코드(G연월일-5자)',
    kind               ENUM('db','files') NOT NULL COMMENT 'db=운영 DB 변경, files=운영 서버 파일 변경(서로 별도 승인)',
    mode               ENUM('scope','sql','staged') NOT NULL COMMENT 'scope=범위 안 구조화 쓰기, sql=SQL 본문 고정, staged=파일 내용 고정',
    title              VARCHAR(200) NOT NULL,
    detail             MEDIUMTEXT NULL,
    scope_json         MEDIUMTEXT NOT NULL COMMENT '승인 범위(JSON)',
    payload            LONGTEXT NULL COMMENT 'sql 방식: 승인할 SQL 본문',
    preview_json       MEDIUMTEXT NULL COMMENT '요청 시점 미리보기(미리 실행 결과·대상 행)',
    status             ENUM('pending','approved','rejected','revoked','used','expired','canceled') NOT NULL DEFAULT 'pending',
    ttl_minutes        SMALLINT UNSIGNED NOT NULL DEFAULT 60 COMMENT '승인 후 유효시간(분)',
    max_ops            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    used_ops           SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    used_rows          INT UNSIGNED NOT NULL DEFAULT 0,
    requested_at       DATETIME NOT NULL,
    request_expires_at DATETIME NOT NULL COMMENT '승인 대기 만료',
    decided_at         DATETIME NULL,
    decided_by         INT UNSIGNED NULL COMMENT '결정한 관리자 users.id',
    decided_note       VARCHAR(255) NULL,
    expires_at         DATETIME NULL COMMENT '승인 유효 만료',
    UNIQUE KEY uq_rops_grants_code (code),
    INDEX idx_rops_grants_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_ops_log (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    created_at     DATETIME NOT NULL,
    finished_at    DATETIME NULL,
    op             VARCHAR(40) NOT NULL,
    status         ENUM('running','ok','error','denied','dry_run') NOT NULL,
    grant_id       INT UNSIGNED NULL,
    target         VARCHAR(255) NULL,
    affected_rows  INT NULL,
    duration_ms    INT UNSIGNED NULL,
    client_ip      VARCHAR(45) NULL,
    request_json   MEDIUMTEXT NULL COMMENT '요청 요약(파일 본문·인증정보 제외)',
    result_json    MEDIUMTEXT NULL COMMENT '결과 요약(행 데이터 제외)',
    error_code     VARCHAR(40) NULL,
    error_message  VARCHAR(1000) NULL,
    revert_of      BIGINT UNSIGNED NULL COMMENT '이 작업이 되돌린 작업 id',
    reverted_by    BIGINT UNSIGNED NULL COMMENT '이 작업을 되돌린 작업 id',
    INDEX idx_rops_log_created (created_at),
    INDEX idx_rops_log_op (op),
    INDEX idx_rops_log_grant (grant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS remote_ops_snapshots (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    log_id      BIGINT UNSIGNED NULL,
    grant_id    INT UNSIGNED NULL,
    kind        ENUM('rows_before','rows_after','verify_before','verify_after','file_before','file_after','file_staged','table_dump') NOT NULL,
    ref         VARCHAR(255) NOT NULL COMMENT '테이블 이름 또는 파일 경로(public/… app/…)',
    sha256      CHAR(64) CHARACTER SET ascii NULL,
    size_bytes  INT UNSIGNED NULL,
    encoding    ENUM('raw','gzip','none') NOT NULL DEFAULT 'gzip' COMMENT 'none=본문 없음(없던 파일·해시만)',
    body        LONGBLOB NULL,
    created_at  DATETIME NOT NULL,
    INDEX idx_rops_snap_log (log_id),
    INDEX idx_rops_snap_grant (grant_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
