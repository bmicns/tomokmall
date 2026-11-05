-- 운영 반영 사후 확인 — 읽기 전용(SELECT 만). 각 줄 끝 주석이 기대값. 승인 없이 실행 가능(CLAUDE.md 6장).
-- 기대값은 10·20 적용 후 기준(20 적용 전이면 활성 0).

SELECT COUNT(*) AS new_columns FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rag_documents' AND COLUMN_NAME IN ('source_type','source_name','source_ref','source_url','effective_date','revised_date','applies_to','legal_status','author','reviewer','reviewed_at','review_status','verify_level','verified_by','verified_at','updated_by'); -- 기대 16
SELECT COUNT(*) AS internal_docs, SUM(is_active) AS internal_active FROM rag_documents WHERE source_type = 'internal'; -- 기대: 적용 전 사전 점검 값과 같음(저장소 기준 5건)
SELECT COUNT(*) AS registered_total FROM rag_documents WHERE author IN ('검증 조문(2-1단계)', 'AI 조사 초안(검색 기반)'); -- 기대 77
SELECT COUNT(*) AS verified_registered FROM rag_documents WHERE author = '검증 조문(2-1단계)'; -- 기대 38
SELECT COUNT(*) AS candidate_registered FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)'; -- 기대 39
SELECT COUNT(*) AS duplicate_titles FROM (SELECT author, title FROM rag_documents WHERE author IN ('검증 조문(2-1단계)', 'AI 조사 초안(검색 기반)') GROUP BY author, title HAVING COUNT(*) > 1) d; -- 기대 0
SELECT COUNT(*) AS verified_active FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND is_active = 1; -- 기대 33 (20 적용 전 0)
SELECT COUNT(*) AS candidate_active FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND is_active = 1; -- 기대 0
SELECT COUNT(*) AS v21_v22_active FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND is_active = 1 AND title IN ('[법령] KDS 44 50 25 차도용 블록포장설계 1.2 적용 범위 · 4.8 포장 층별 최소두께(표 4.8-1) · 4.9 표준단면두께(표 4.9-1) · 4.11 줄눈', '[법령] KCS 44 50 25 차도용 블록포장공사 1.1 적용 범위 · 2.1 차도용 블록 · 2.3 받침안정층 모래 · 3.5.7 시공허용 오차'); -- 기대 0
SELECT COUNT(*) AS public_road_active FROM rag_documents WHERE is_active = 1 AND tags LIKE '%@scope:public_road%'; -- 기대 0
SELECT COUNT(*) AS not_ready_active FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND is_active = 1 AND title IN ('[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제29조제1항 (포장)', '[법령] 하수관로 유지관리 기준 [별표 1] 하수관로 시설별 점검 주기 및 내용', '[법령] KDS 44 50 25 차도용 블록포장설계 1.2 적용 범위 · 4.8 포장 층별 최소두께(표 4.8-1) · 4.9 표준단면두께(표 4.9-1) · 4.11 줄눈', '[법령] KCS 44 50 25 차도용 블록포장공사 1.1 적용 범위 · 2.1 차도용 블록 · 2.3 받침안정층 모래 · 3.5.7 시공허용 오차', '[법령] KDS 44 40 20 도시지역 도로배수 4.1 우수받이 · 4.2 우수관거 및 맨홀'); -- 기대 0
SELECT COUNT(*) AS active_unverified FROM rag_documents WHERE is_active = 1 AND source_type <> 'internal' AND (verify_level NOT IN ('original_verified','api_text_verified') OR verify_level IS NULL OR review_status <> 'reviewed' OR legal_status <> 'current' OR reviewer IS NULL OR reviewed_at IS NULL); -- 기대 0
SELECT source_type, COUNT(*) AS n, SUM(is_active) AS active FROM rag_documents GROUP BY source_type ORDER BY source_type; -- 기대 n: kcs=4, kds=5, ks=20, law=48, internal=사전 점검 값 · active: law=33, 그 외 0(internal 제외)
SELECT reviewer, reviewed_at, COUNT(*) AS n FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND is_active = 1 GROUP BY reviewer, reviewed_at; -- 기대 1행(20 생성 시 넣은 검토자·검토일), n=33
