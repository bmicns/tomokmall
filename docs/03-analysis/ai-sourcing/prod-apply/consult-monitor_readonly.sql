-- 운영 AI 상담 모니터링 (읽기 전용 — SELECT 만, phpMyAdmin SQL 탭)
-- 목적: 1단계 반영(제조사 자료 14행·법령 근거 20건) 뒤 실제 상담에서 출처·근거가 얼마나 쓰이는지, 어떤 자재에 확인된 자료가 없는지 → 다음 수집·검증 우선순위.
-- 개인정보: 고객 질문 원문(user_msg)·회원 정보는 조회하지 않는다. 자료 공백 주제는 AI 가 정한 자재 주제(연락처 모양 제거)만.
-- 기간: 아래 '2026-11-02' 를 보고 싶은 시작일로 바꾼다. MySQL 8.0(JSON_TABLE) 필요.
-- 자료 공백(material_gap)은 그 기록을 남기는 코드가 운영에 올라간 뒤부터 쌓인다(작업 브랜치 2026-11-02 이후 커밋, 미배포).

-- ① 기간 전체: 상담 턴 수와 카드·출처 사용 비율
SELECT COUNT(*)                                                         AS turns,
       SUM(tools_json LIKE '%product_cards%')                           AS with_product_table,
       SUM(tools_json LIKE '%material_sources%')                        AS with_maker_source,
       SUM(tools_json LIKE '%legal_sources%')                           AS with_legal_source,
       SUM(tools_json LIKE '%basic_sources%')                           AS with_basic_source,
       SUM(tools_json LIKE '%material_gap%')                            AS with_material_gap,
       SUM(tools_json LIKE '%cart_update%')                             AS with_cart_add,
       SUM(assistant_txt LIKE '%확인된 자료에 없는 번호·수치%')            AS unverified_number_note,
       SUM(assistant_txt LIKE '%같은 예상 자재비의%')                       AS tie_note
  FROM chat_logs
 WHERE created_at >= '2026-11-02';

-- ② 날짜별 추이
SELECT DATE(created_at) AS day, COUNT(*) AS turns,
       SUM(tools_json LIKE '%material_sources%') AS maker_src, SUM(tools_json LIKE '%legal_sources%') AS legal_src,
       SUM(tools_json LIKE '%material_gap%') AS gap, SUM(tools_json LIKE '%cart_update%') AS cart_add
  FROM chat_logs
 WHERE created_at >= '2026-11-02'
 GROUP BY DATE(created_at) ORDER BY day;

-- ③ 확인된 제조사 자료가 없었던 자재 주제 TOP 30 → 다음 수집·카탈로그 요청 우선순위
SELECT g.topic, COUNT(*) AS n
  FROM chat_logs c,
       JSON_TABLE(c.events_json, '$[*]' COLUMNS(type VARCHAR(40) PATH '$.type', topic VARCHAR(120) PATH '$.topic')) g
 WHERE c.created_at >= '2026-11-02' AND c.events_json IS NOT NULL AND JSON_VALID(c.events_json) AND g.type = 'material_gap'
 GROUP BY g.topic ORDER BY n DESC LIMIT 30;

-- ④ 답에 실제로 인용된 법령 근거(출처 카드) 순위
SELECT CONCAT(s.name, ' ', s.ref) AS legal_source, COUNT(*) AS n
  FROM chat_logs c,
       JSON_TABLE(c.events_json, '$[*]' COLUMNS(type VARCHAR(40) PATH '$.type',
                  NESTED PATH '$.sources[*]' COLUMNS(name VARCHAR(200) PATH '$.name', ref VARCHAR(200) PATH '$.ref'))) s
 WHERE c.created_at >= '2026-11-02' AND c.events_json IS NOT NULL AND JSON_VALID(c.events_json) AND s.type = 'legal_sources'
 GROUP BY legal_source ORDER BY n DESC LIMIT 30;

-- ⑤ 답에 쓰인 제조사 자료 출처(제조사별)
SELECT s.maker, COUNT(*) AS n
  FROM chat_logs c,
       JSON_TABLE(c.events_json, '$[*]' COLUMNS(type VARCHAR(40) PATH '$.type',
                  NESTED PATH '$.sources[*]' COLUMNS(maker VARCHAR(120) PATH '$.maker'))) s
 WHERE c.created_at >= '2026-11-02' AND c.events_json IS NOT NULL AND JSON_VALID(c.events_json) AND s.type = 'material_sources'
 GROUP BY s.maker ORDER BY n DESC;

-- ⑥ 상담 분류(category_key)별 턴 수·근거 사용
SELECT COALESCE(category_key, '(없음)') AS category, COUNT(*) AS turns,
       SUM(tools_json LIKE '%legal_sources%') AS legal_src, SUM(tools_json LIKE '%material_sources%') AS maker_src, SUM(tools_json LIKE '%product_cards%') AS tables
  FROM chat_logs
 WHERE created_at >= '2026-11-02'
 GROUP BY category ORDER BY turns DESC;
