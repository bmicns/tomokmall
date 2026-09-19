-- Stage 3: AI 상담 로그 테이블
CREATE TABLE IF NOT EXISTS chat_logs (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    session_key   VARCHAR(64)  NOT NULL,
    user_id       INT          NULL,
    user_msg      TEXT         NOT NULL      COMMENT '고객 메시지',
    assistant_txt TEXT         NULL          COMMENT 'AI 답변 텍스트',
    tools_json    VARCHAR(255) NULL          COMMENT '호출된 tool 이벤트 타입(콤마 구분)',
    events_json   TEXT         NULL          COMMENT 'SSE 이벤트 JSON',
    category_key  VARCHAR(50)  NULL          COMMENT '감지된 카테고리',
    rating        TINYINT      NULL          COMMENT '1=좋음, -1=나쁨',
    is_fewshot    TINYINT(1)   NOT NULL DEFAULT 0 COMMENT '1=few-shot 후보로 선정',
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_session  (session_key),
    INDEX idx_category (category_key),
    INDEX idx_rating   (rating),
    INDEX idx_fewshot  (is_fewshot),
    INDEX idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
