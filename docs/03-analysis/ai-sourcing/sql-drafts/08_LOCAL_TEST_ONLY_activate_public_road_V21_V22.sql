-- [로컬 테스트 DB 전용] V21·V22(KDS·KCS 44 50 25 공공도로 차도 블록 최소 100mm) 활성화
-- 근거: 실제 GPT-4o 핵심 시나리오 1~3차(2026-11-02, 사용자 PC) — 공공도로 질문에서 KDS 44 50 25 인용·100/120mm·'적합 상품 없음' 안내가 맞게 나옴.
-- 승인: 사용자 2026-11-02 '로컬 테스트 DB 에서만 활성화 승인, 운영 DB 는 계속 비활성'.
-- 금지: 운영 DB 실행. 운영 반영 SQL(prod-apply/20_activate_ready.sql, scripts/legal-sources/prod_sql_lib.php)은 V21·V22 를 활성화하지 않는다.
-- 적용: scripts/testdb/setup-local-test-db.sh 가 07 다음에 적용. 되돌리기: 아래 UPDATE 의 is_active = 0.

-- 안전장치: DB 이름이 *_test 가 아니면 오류로 중단(여러 행 하위 질의 오류를 일부러 냄)
DO IF(DATABASE() LIKE '%\_test', 0, (SELECT TABLE_NAME FROM information_schema.TABLES));

UPDATE rag_documents
   SET is_active = 1, review_status = 'reviewed'
 WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%';

SELECT COUNT(*) AS public_road_active FROM rag_documents
 WHERE author = '검증 조문(2-1단계)' AND tags LIKE '@scope:public_road%' AND is_active = 1;   -- 기대 2
