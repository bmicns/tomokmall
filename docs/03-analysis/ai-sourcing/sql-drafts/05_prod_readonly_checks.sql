-- 운영 DB 읽기 전용 점검 (SELECT 만 · 데이터 변경 없음) — MySQL 8.0.46 에서 검증
-- 목적: ① products.spec 외 다른 위치·형식의 개인정보 의심 데이터 ② 상품 품질 문제를 '건수'로만 확인
-- 결과에는 값이 나오지 않습니다(건수만). 결과 표를 그대로 전달해 주세요.
-- 실행: phpMyAdmin 에서 운영 DB 를 선택한 뒤 [1] [2] [3] 을 각각 실행 (각각 결과 표 1개)
-- 참고: 고객 입력 영역(상담 로그·세션)은 고객이 직접 적은 연락처가 있을 수 있어 0건이 아니어도 정상일 수 있음 → 보관 기간 정책 판단용

-- [1] 개인정보 의심 패턴 (영역 × 패턴별 건수)
SELECT '상품' AS 영역, 'products.name/brand/spec/description' AS 위치, '휴대폰번호' AS 패턴, COUNT(*) AS 건수 FROM products WHERE name REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}' OR brand REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}' OR spec REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}' OR description REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}'
UNION ALL
SELECT '상품' AS 영역, 'products.name/brand/spec/description' AS 위치, '유선전화' AS 패턴, COUNT(*) AS 건수 FROM products WHERE name REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}' OR brand REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}' OR spec REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}' OR description REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}'
UNION ALL
SELECT '상품' AS 영역, 'products.name/brand/spec/description' AS 위치, '이메일' AS 패턴, COUNT(*) AS 건수 FROM products WHERE name REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}' OR brand REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}' OR spec REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}' OR description REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}'
UNION ALL
SELECT '상품' AS 영역, 'products.name/brand/spec/description' AS 위치, '주민등록번호 형식' AS 패턴, COUNT(*) AS 건수 FROM products WHERE name REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}' OR brand REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}' OR spec REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}' OR description REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}'
UNION ALL
SELECT '상품' AS 영역, 'products.name/brand/spec/description' AS 위치, '도로명·지번 주소' AS 패턴, COUNT(*) AS 건수 FROM products WHERE name REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+' OR brand REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+' OR spec REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+' OR description REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+'
UNION ALL
SELECT '상품' AS 영역, 'products.name/brand/spec/description' AS 위치, '담당자 직함+이름(참고·오탐 가능)' AS 패턴, COUNT(*) AS 건수 FROM products WHERE name REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)' OR brand REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)' OR spec REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)' OR description REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)'
UNION ALL
SELECT '카테고리' AS 영역, 'categories.name' AS 위치, '휴대폰번호' AS 패턴, COUNT(*) AS 건수 FROM categories WHERE name REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}'
UNION ALL
SELECT '카테고리' AS 영역, 'categories.name' AS 위치, '유선전화' AS 패턴, COUNT(*) AS 건수 FROM categories WHERE name REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}'
UNION ALL
SELECT '카테고리' AS 영역, 'categories.name' AS 위치, '이메일' AS 패턴, COUNT(*) AS 건수 FROM categories WHERE name REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}'
UNION ALL
SELECT '카테고리' AS 영역, 'categories.name' AS 위치, '주민등록번호 형식' AS 패턴, COUNT(*) AS 건수 FROM categories WHERE name REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}'
UNION ALL
SELECT '카테고리' AS 영역, 'categories.name' AS 위치, '도로명·지번 주소' AS 패턴, COUNT(*) AS 건수 FROM categories WHERE name REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+'
UNION ALL
SELECT '카테고리' AS 영역, 'categories.name' AS 위치, '담당자 직함+이름(참고·오탐 가능)' AS 패턴, COUNT(*) AS 건수 FROM categories WHERE name REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)'
UNION ALL
SELECT '기준 문서' AS 영역, 'rag_documents.title/content/tags' AS 위치, '휴대폰번호' AS 패턴, COUNT(*) AS 건수 FROM rag_documents WHERE title REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}' OR content REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}' OR tags REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}'
UNION ALL
SELECT '기준 문서' AS 영역, 'rag_documents.title/content/tags' AS 위치, '유선전화' AS 패턴, COUNT(*) AS 건수 FROM rag_documents WHERE title REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}' OR content REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}' OR tags REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}'
UNION ALL
SELECT '기준 문서' AS 영역, 'rag_documents.title/content/tags' AS 위치, '이메일' AS 패턴, COUNT(*) AS 건수 FROM rag_documents WHERE title REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}' OR content REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}' OR tags REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}'
UNION ALL
SELECT '기준 문서' AS 영역, 'rag_documents.title/content/tags' AS 위치, '주민등록번호 형식' AS 패턴, COUNT(*) AS 건수 FROM rag_documents WHERE title REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}' OR content REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}' OR tags REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}'
UNION ALL
SELECT '기준 문서' AS 영역, 'rag_documents.title/content/tags' AS 위치, '도로명·지번 주소' AS 패턴, COUNT(*) AS 건수 FROM rag_documents WHERE title REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+' OR content REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+' OR tags REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+'
UNION ALL
SELECT '기준 문서' AS 영역, 'rag_documents.title/content/tags' AS 위치, '담당자 직함+이름(참고·오탐 가능)' AS 패턴, COUNT(*) AS 건수 FROM rag_documents WHERE title REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)' OR content REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)' OR tags REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)'
UNION ALL
SELECT '상담 가이드' AS 영역, 'category_consult_guide.title/guide_text' AS 위치, '휴대폰번호' AS 패턴, COUNT(*) AS 건수 FROM category_consult_guide WHERE title REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}' OR guide_text REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}'
UNION ALL
SELECT '상담 가이드' AS 영역, 'category_consult_guide.title/guide_text' AS 위치, '유선전화' AS 패턴, COUNT(*) AS 건수 FROM category_consult_guide WHERE title REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}' OR guide_text REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}'
UNION ALL
SELECT '상담 가이드' AS 영역, 'category_consult_guide.title/guide_text' AS 위치, '이메일' AS 패턴, COUNT(*) AS 건수 FROM category_consult_guide WHERE title REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}' OR guide_text REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}'
UNION ALL
SELECT '상담 가이드' AS 영역, 'category_consult_guide.title/guide_text' AS 위치, '주민등록번호 형식' AS 패턴, COUNT(*) AS 건수 FROM category_consult_guide WHERE title REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}' OR guide_text REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}'
UNION ALL
SELECT '상담 가이드' AS 영역, 'category_consult_guide.title/guide_text' AS 위치, '도로명·지번 주소' AS 패턴, COUNT(*) AS 건수 FROM category_consult_guide WHERE title REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+' OR guide_text REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+'
UNION ALL
SELECT '상담 가이드' AS 영역, 'category_consult_guide.title/guide_text' AS 위치, '담당자 직함+이름(참고·오탐 가능)' AS 패턴, COUNT(*) AS 건수 FROM category_consult_guide WHERE title REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)' OR guide_text REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)'
UNION ALL
SELECT '인기 검색어' AS 영역, 'search_logs.term' AS 위치, '휴대폰번호' AS 패턴, COUNT(*) AS 건수 FROM search_logs WHERE term REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}'
UNION ALL
SELECT '인기 검색어' AS 영역, 'search_logs.term' AS 위치, '유선전화' AS 패턴, COUNT(*) AS 건수 FROM search_logs WHERE term REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}'
UNION ALL
SELECT '인기 검색어' AS 영역, 'search_logs.term' AS 위치, '이메일' AS 패턴, COUNT(*) AS 건수 FROM search_logs WHERE term REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}'
UNION ALL
SELECT '인기 검색어' AS 영역, 'search_logs.term' AS 위치, '주민등록번호 형식' AS 패턴, COUNT(*) AS 건수 FROM search_logs WHERE term REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}'
UNION ALL
SELECT '인기 검색어' AS 영역, 'search_logs.term' AS 위치, '도로명·지번 주소' AS 패턴, COUNT(*) AS 건수 FROM search_logs WHERE term REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+'
UNION ALL
SELECT '인기 검색어' AS 영역, 'search_logs.term' AS 위치, '담당자 직함+이름(참고·오탐 가능)' AS 패턴, COUNT(*) AS 건수 FROM search_logs WHERE term REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)'
UNION ALL
SELECT 'AI 상담 로그(고객 입력)' AS 영역, 'chat_logs.user_msg' AS 위치, '휴대폰번호' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE user_msg REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}'
UNION ALL
SELECT 'AI 상담 로그(고객 입력)' AS 영역, 'chat_logs.user_msg' AS 위치, '유선전화' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE user_msg REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}'
UNION ALL
SELECT 'AI 상담 로그(고객 입력)' AS 영역, 'chat_logs.user_msg' AS 위치, '이메일' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE user_msg REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}'
UNION ALL
SELECT 'AI 상담 로그(고객 입력)' AS 영역, 'chat_logs.user_msg' AS 위치, '주민등록번호 형식' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE user_msg REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}'
UNION ALL
SELECT 'AI 상담 로그(고객 입력)' AS 영역, 'chat_logs.user_msg' AS 위치, '도로명·지번 주소' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE user_msg REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+'
UNION ALL
SELECT 'AI 상담 로그(고객 입력)' AS 영역, 'chat_logs.user_msg' AS 위치, '담당자 직함+이름(참고·오탐 가능)' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE user_msg REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)'
UNION ALL
SELECT 'AI 상담 로그(AI 답변)' AS 영역, 'chat_logs.assistant_txt' AS 위치, '휴대폰번호' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE assistant_txt REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}'
UNION ALL
SELECT 'AI 상담 로그(AI 답변)' AS 영역, 'chat_logs.assistant_txt' AS 위치, '유선전화' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE assistant_txt REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}'
UNION ALL
SELECT 'AI 상담 로그(AI 답변)' AS 영역, 'chat_logs.assistant_txt' AS 위치, '이메일' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE assistant_txt REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}'
UNION ALL
SELECT 'AI 상담 로그(AI 답변)' AS 영역, 'chat_logs.assistant_txt' AS 위치, '주민등록번호 형식' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE assistant_txt REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}'
UNION ALL
SELECT 'AI 상담 로그(AI 답변)' AS 영역, 'chat_logs.assistant_txt' AS 위치, '도로명·지번 주소' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE assistant_txt REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+'
UNION ALL
SELECT 'AI 상담 로그(AI 답변)' AS 영역, 'chat_logs.assistant_txt' AS 위치, '담당자 직함+이름(참고·오탐 가능)' AS 패턴, COUNT(*) AS 건수 FROM chat_logs WHERE assistant_txt REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)'
UNION ALL
SELECT 'AI 대화 세션' AS 영역, 'ai_sessions.messages' AS 위치, '휴대폰번호' AS 패턴, COUNT(*) AS 건수 FROM ai_sessions WHERE messages REGEXP '01[016789][-. ]?[0-9]{3,4}[-. ]?[0-9]{4}'
UNION ALL
SELECT 'AI 대화 세션' AS 영역, 'ai_sessions.messages' AS 위치, '유선전화' AS 패턴, COUNT(*) AS 건수 FROM ai_sessions WHERE messages REGEXP '(^|[^0-9])0(2|[3-6][1-5]|70)[-. )][0-9]{3,4}[-. ][0-9]{4}'
UNION ALL
SELECT 'AI 대화 세션' AS 영역, 'ai_sessions.messages' AS 위치, '이메일' AS 패턴, COUNT(*) AS 건수 FROM ai_sessions WHERE messages REGEXP '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+[.][A-Za-z.]{2,}'
UNION ALL
SELECT 'AI 대화 세션' AS 영역, 'ai_sessions.messages' AS 위치, '주민등록번호 형식' AS 패턴, COUNT(*) AS 건수 FROM ai_sessions WHERE messages REGEXP '(^|[^0-9])[0-9]{2}(0[1-9]|1[0-2])(0[1-9]|[12][0-9]|3[01])-[1-4][0-9]{6}'
UNION ALL
SELECT 'AI 대화 세션' AS 영역, 'ai_sessions.messages' AS 위치, '도로명·지번 주소' AS 패턴, COUNT(*) AS 건수 FROM ai_sessions WHERE messages REGEXP '[가-힣]+(시|군|구) ([가-힣0-9]+ ){0,2}[가-힣0-9]+(로|길|리) ?[0-9]+'
UNION ALL
SELECT 'AI 대화 세션' AS 영역, 'ai_sessions.messages' AS 위치, '담당자 직함+이름(참고·오탐 가능)' AS 패턴, COUNT(*) AS 건수 FROM ai_sessions WHERE messages REGEXP '[가-힣]{2,3} ?(과장|실장|팀장)([^가-힣]|$)|(과장|실장|팀장) [가-힣]{2,3}([^가-힣]|$)';

-- [2] 상품 품질 (운영 products 기준 건수)
SELECT '필드 오염(운반비·설치비·상차수량·중량 칸에 설명 문장)' AS 검사, (SELECT COUNT(*) FROM products WHERE spec REGEXP '(운반비예상|설치비예상|상차수량|중량): [^|]*([*]|구성|주문|대표|주요품목|그레이팅:|파렛트)') AS 건수
UNION ALL
SELECT '규격 치수 m 표기(예: 0.8*0.8*2.0)' AS 검사, (SELECT COUNT(*) FROM products WHERE spec REGEXP '규격: [0-9]+[.][0-9]+[*][0-9]') AS 건수
UNION ALL
SELECT '단위 m 2(공백) 표기' AS 검사, (SELECT COUNT(*) FROM products WHERE spec LIKE '%단위: m 2%') AS 건수
UNION ALL
SELECT '활성 상품 중 판매가 0' AS 검사, (SELECT COUNT(*) FROM products WHERE is_active = 1 AND price_b2c = 0) AS 건수
UNION ALL
SELECT '같은 이름·규격 중복 묶음 수' AS 검사, (SELECT COUNT(*) FROM (SELECT name, spec FROM products WHERE is_active = 1 GROUP BY name, spec HAVING COUNT(*) > 1) d) AS 건수
UNION ALL
SELECT '외부 이미지 직접 참조(핫링크)' AS 검사, (SELECT COUNT(*) FROM products WHERE image_url LIKE 'http%' AND image_url NOT LIKE '%tomokmall%') AS 건수
UNION ALL
SELECT 'HTML 엔티티 잔존(&raquo; 등)' AS 검사, (SELECT COUNT(*) FROM products WHERE CONCAT_WS(' ', spec, description) REGEXP '&(raquo|amp|nbsp|lt|gt|quot);') AS 건수
UNION ALL
SELECT '경계석류 중 1개 길이(1000) 표기 없음' AS 검사, (SELECT COUNT(*) FROM products WHERE is_active = 1 AND name LIKE '%경계%' AND name NOT LIKE '%집게%' AND spec NOT REGEXP '[*]1000') AS 건수
UNION ALL
SELECT '활성 상품 전체' AS 검사, (SELECT COUNT(*) FROM products WHERE is_active = 1) AS 건수;

-- [3] 출처별 상품 수 (운영 데이터가 저장소 data/*/import.sql 과 같은지 판단용)
SELECT COALESCE(external_source, '(직접 등록)') AS 출처, COUNT(*) AS 전체, SUM(is_active = 1) AS 활성, MIN(id) AS 최소_id, MAX(id) AS 최대_id FROM products GROUP BY external_source;
