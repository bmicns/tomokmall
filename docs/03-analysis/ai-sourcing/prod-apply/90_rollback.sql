-- 운영 법령 근거 반영 되돌리기 — ⚠️ 데이터 변경. 실행 전 사용자 승인 + phpMyAdmin 내보내기(rag_documents) 백업.
-- 필요한 단계만 위에서부터 실행. 사내 문서(source_type='internal', author NULL)는 어느 단계에서도 바뀌지 않음.

-- [A] 20 되돌리기 — 활성화 해제(행은 남김). 먼저 건수 확인:
SELECT COUNT(*) FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND is_active = 1;
UPDATE rag_documents SET is_active = 0, review_status = 'unreviewed', reviewer = NULL, reviewed_at = NULL WHERE author = '검증 조문(2-1단계)';

-- [B] 10 되돌리기 — 등록 행 삭제. 먼저 건수 확인(기대: 10 의 등록 건수):
SELECT author, COUNT(*) FROM rag_documents WHERE author IN ('검증 조문(2-1단계)', 'AI 조사 초안(검색 기반)') GROUP BY author;
DELETE FROM rag_documents WHERE author IN ('검증 조문(2-1단계)', 'AI 조사 초안(검색 기반)');

-- [C] 칸 추가 되돌리기 — database/20261102_rag_documents_legal_columns.sql 머리말의 '되돌리기' SQL 사용
--     (새 칸에 넣은 값이 모두 사라짐. [B] 먼저 실행 권장)
