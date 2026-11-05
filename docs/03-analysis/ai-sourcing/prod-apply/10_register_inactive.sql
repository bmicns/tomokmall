-- 운영 rag_documents 법령 근거 등록 — 전부 is_active=0·review_status='unreviewed' (AI 상담에 쓰이지 않음)
-- ⚠️ 운영 실행은 사용자 승인 후 phpMyAdmin 에서. 먼저 database/20261102_rag_documents_legal_columns.sql 적용.
-- 생성: scripts/legal-sources/build_prod_sql.php (원본 candidates.json 39건 + verified_clauses.json 43건)
-- 재실행 안전: 같은 (author, title) 행이 있으면 넣지 않음(INSERT … SELECT … WHERE NOT EXISTS). 기존 사내 문서는 건드리지 않음.
-- 기대: 처음 실행 82행 추가, 두 번째 실행 0행. 확인은 30_post_check_readonly.sql

START TRANSACTION;

-- [1] 후보 39건 (author = 'AI 조사 초안(검색 기반)', 초안 02 와 같은 값)
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 주차장법 시행규칙', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
확인된 내용: 제3조 주차장의 주차구획 — 평행주차형식 외 일반형: 너비 2.5m 이상, 길이 5.0m 이상(면적 예시 12.5㎡).
AI 적용 범위: 조건부 안내 — 주차장법상 주차장(부설주차장 포함)일 때 적용, 지자체 조례로 달리 정할 수 있음, 단순 마당 포장은 관할 시·군·구 확인
문서명: 주차장법 시행규칙
번호: 제3조(주차장의 주차구획) · 국토교통부령 제1604호
발행: 국토교통부(국토교통부령)
관련 자재: 주차장 구획선(도료), 주차블록·카스토퍼, 주차장 포장재(인터로킹·투수블록)
적용 범위(검색 기준): 주차장법에 따른 주차장의 주차구획 기준. 개인 주택 마당에 같은 기준이 의무 적용되는지는 별도 검토 필요.
시행·개정일(검색 기준, 미확인): 시행 2026-07-27 (국토교통부령 제1604호) — 사용자 원문 확인
2-1 판정: 상세 원문 검증(공식 사이트 + 원문 미러 대조) — 사용자 공식 사이트 확인 수치와 원문 미러 표가 일치. 적용 범위(부설주차장 정의·조례 위임)도 법 제2조·제6조·제19조, 시행규칙 제11조로 확인
검색 근거 신뢰도: 높음
확인 수준: original_verified — 공식 조문 내용 확인(사용자). Claude는 작업 환경 차단으로 원문 미열람. 이전 링크(lsJoLnkSeq=1025436739)도 같은 조문으로 기록됨.
확인 URL: https://law.go.kr/lsLinkCommonInfo.do?lsJoLnkSeq=1011857635
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '주차장 구획선(도료), 주차블록·카스토퍼, 주차장 포장재(인터로킹·투수블록)', 0, 900, 'law', '주차장법 시행규칙', '제3조(주차장의 주차구획) · 국토교통부령 제1604호', 'https://law.go.kr/lsLinkCommonInfo.do?lsJoLnkSeq=1011857635', '주차장법에 따른 주차장의 주차구획 기준. 개인 주택 마당에 같은 기준이 의무 적용되는지는 별도 검토 필요.', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국가법령정보센터 원문 확인)', '2026-10-27', '2026-07-27', NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 주차장법 시행규칙');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙
번호: [별표 1] 편의시설의 구조·재질등에 관한 세부기준(제2조제1항 관련) — 점자블록 항목
발행: 보건복지부(보건복지부령)
관련 자재: 점자블록(점형·선형), 보도블록 접근로
적용 범위(검색 기준): 편의증진법 대상시설(공공건물·공중이용시설·공원 등)에 설치하는 편의시설. 검색 요약상 점자블록 0.3m×0.3m, 점형 36개 돌출점, 선형 4개 돌출선, 황색 원칙, 매립식 원칙 등이 언급됨(원문 미확인). 개인 주택 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 별표 1 <개정 2023. 12. 11.> 판본 확인(law.go.kr 파일명). 2022.7.26 판본도 존재. 이후 개정 여부 미확인
2-1 판정: 상세 원문 검증(별표 원문 PDF + 법제처 OPEN API 대조) — 별표 1(개정 2023-12-11) 전문 확인 — 점자블록 규격(제16호), 접근로(제1호), 장애인전용주차구역(제4호), 경사로(제12호)를 조항 단위 근거 V15~V18 로 등록·로컬 활성
검색 근거 신뢰도: 높음
확인 수준: original_verified — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점자블록(점형·선형), 보도블록 접근로', 0, 900, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙', '[별표 1] 편의시설의 구조·재질등에 관한 세부기준(제2조제1항 관련) — 점자블록 항목', 'https://www.law.go.kr/flDownload.do?flSeq=135226913', '편의증진법 대상시설(공공건물·공중이용시설·공원 등)에 설치하는 편의시설. 검색 요약상 점자블록 0.3m×0.3m, 점형 36개 돌출점, 선형 4개 돌출선, 황색 원칙, 매립식 원칙 등이 언급됨(원문 미확인). 개인 주택 마당은 적용 대상 다름', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국가법령정보센터 별표 원문 PDF) + Claude(원문 대조)', '2026-10-28', '2026-07-02', '2026-04-01' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령
번호: [별표 2] 대상시설별 편의시설의 종류 및 설치기준(제4조 관련)
발행: 보건복지부(대통령령)
관련 자재: 점자블록, 보도(공원 내 보도 등)
적용 범위(검색 기준): 어떤 대상시설에 점자블록 등 편의시설을 설치해야 하는지(설치 의무 대상) 규정. 공원 점자블록 설치범위 불명확하다는 법제처 해석례 존재. 포털에서 검색
시행·개정일(검색 기준, 미확인): 확인 안 됨
2-1 판정: 상세 원문 검증(별표 원문 PDF + 법제처 OPEN API 대조) — 별표 2(개정 2024-09-19) 확인 — 공원·공공건물·공동주택별 접근로·장애인전용주차구역(부설주차장 10대 미만 제외)·점자블록 의무/권장, 연립·다세대 10세대 이상만 → V27(설치 의무 판단) 로컬 활성
검색 근거 신뢰도: 중간
확인 수준: original_verified — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점자블록, 보도(공원 내 보도 등)', 0, 900, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령', '[별표 2] 대상시설별 편의시설의 종류 및 설치기준(제4조 관련)', 'https://www.law.go.kr', '어떤 대상시설에 점자블록 등 편의시설을 설치해야 하는지(설치 의무 대상) 규정. 공원 점자블록 설치범위 불명확하다는 법제처 해석례 존재. 포털에서 검색', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국가법령정보센터 원문 파일) + Claude(원문 대조)', '2026-10-28', '2026-01-02', '2025-12-30' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 교통약자의 이동편의 증진법 시행규칙', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 교통약자의 이동편의 증진법 시행규칙
번호: 제2조제1항, [별표 1] 이동편의시설의 구조·재질 등에 관한 세부기준
발행: 국토교통부(국토교통부령)
관련 자재: 점자블록, 보도 턱낮춤(경계석), 보행 접근로
적용 범위(검색 기준): 교통수단·여객시설·도로(보행 관련) 등 교통약자법 대상시설. 검색 요약상 점형블록 돌기 높이 0.6±0.1cm 언급(원문 미확인). 도로관리청·교통사업자 대상이며 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 별표 1 <개정 2024. 12. 26.> 판본 확인(law.go.kr). 검색 요약상 시행규칙 [시행 2026.02.01.][국토교통부령 제1558호] 판본 언급(nepla.ai, 2차)
2-1 판정: 상세 원문 검증(별표 원문 PDF + 법제처 OPEN API 대조) — 별표 1(개정 2024-12-26) 제3호 도로 보도(유효폭 2m, 덮개 틈 1cm, 기울기 1/18·좌우 1/25, 연석 25cm, 차량 진출입부 턱낮추기, 턱 2cm) → V25, 별표 2(개정 2021-08-27) 고원식 횡단보도·말뚝 → V26 로컬 활성
검색 근거 신뢰도: 높음
확인 수준: original_verified — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점자블록, 보도 턱낮춤(경계석), 보행 접근로', 0, 900, 'law', '교통약자의 이동편의 증진법 시행규칙', '제2조제1항, [별표 1] 이동편의시설의 구조·재질 등에 관한 세부기준', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=155311565&bylClsCd=110201', '교통수단·여객시설·도로(보행 관련) 등 교통약자법 대상시설. 검색 요약상 점형블록 돌기 높이 0.6±0.1cm 언급(원문 미확인). 도로관리청·교통사업자 대상이며 개인 마당은 적용 대상 다름', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국가법령정보센터 원문 파일) + Claude(원문 대조)', '2026-10-28', '2026-02-01', '2026-01-30' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 교통약자의 이동편의 증진법 시행규칙');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 보행안전 및 편의증진에 관한 법률 시행규칙', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 보행안전 및 편의증진에 관한 법률 시행규칙
번호: [별표 1] 보행안전 및 편의증진 시설의 구조 및 기준(제5조제2항 관련)
발행: 행정안전부(추정 — 검색에서 확인 안 됨)
관련 자재: 보도블록, 점자블록, 보행 안전시설
적용 범위(검색 기준): 보행환경 개선지구 등 보행안전 시설. 세부 내용은 검색에서 확인 안 됨. 공공 보행공간 대상으로 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 확인 안 됨
2-1 판정: 상세 원문 검증(별표 원문 PDF + 법제처 OPEN API 대조) — 별표 1 전문 확인 — 자동차 진입억제용 말뚝(제10호: 높이 80~100cm·지름 10~20cm·간격 1.5m), 과속방지턱(제1호다목: 길이 3.6m·높이 10cm), 점자블록 색상·용도(제11호) → V13·V14 등록·로컬 활성
검색 근거 신뢰도: 중간
확인 수준: original_verified — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도블록, 점자블록, 보행 안전시설', 0, 900, 'law', '보행안전 및 편의증진에 관한 법률 시행규칙', '[별표 1] 보행안전 및 편의증진 시설의 구조 및 기준(제5조제2항 관련)', 'https://www.law.go.kr/LSW/flDownload.do?gubun=&flSeq=116980749&bylClsCd=110201', '보행환경 개선지구 등 보행안전 시설. 세부 내용은 검색에서 확인 안 됨. 공공 보행공간 대상으로 개인 마당은 적용 대상 다름', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국가법령정보센터 별표 원문 PDF) + Claude(원문 대조)', '2026-10-28', '2022-07-12', '2022-07-12' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 보행안전 및 편의증진에 관한 법률 시행규칙');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 보도 설치 및 관리 지침', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 보도 설치 및 관리 지침
번호: 국토교통부예규 제321호(행정규칙)
발행: 국토교통부
관련 자재: 보도블록(블록포장), 경계석(연석), 점자블록, 보도 포장
적용 범위(검색 기준): 도로관리청이 설치하는 보도(공공 도로)의 유효폭·횡단경사·포장 기준. 전면개정안에 블록포장/아스팔트/콘크리트/기타포장 구분과 공법별 시공기준 제시(행정예고 자료). 개인 마당·사유지 진입로는 적용 대상 다름
시행·개정일(검색 기준, 미확인): [시행 2021. 7. 23.] 국토교통부예규 제321호, 일부개정(유효기간→재검토기한 변경) — law.go.kr 검색 요약. 2018년 전면개정안 행정예고 자료(molit.go.kr idx=15594)는 별도
2-1 판정: 상세 원문 검증(첨부 개정전문 PDF + 법제처 OPEN API 대조) — 개정전문(2021.7, 105쪽) 확인 — 적용 범위: 도로 구조 규칙 제16조 보도. 용도 구분 Ⅱ(최대 적재량 4t 이하 차량이 보도를 가로질러 통행) 인터로킹 표층 80mm 이상·안정층 20·기층 150mm, 구분 Ⅰ 60mm → V24(@scope:public_sidewalk, 공공 보도 차량 출입부 질문에서만) 로컬 활성. 사내 80T 문구의 출처로는 붙이지 않음
검색 근거 신뢰도: 높음
확인 수준: original_verified — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도블록(블록포장), 경계석(연석), 점자블록, 보도 포장', 0, 900, 'law', '보도 설치 및 관리 지침', '국토교통부예규 제321호(행정규칙)', 'https://www.law.go.kr/admRulLsInfoP.do?chrClsCd=010202&admRulSeq=2100000203352', '도로관리청이 설치하는 보도(공공 도로)의 유효폭·횡단경사·포장 기준. 전면개정안에 블록포장/아스팔트/콘크리트/기타포장 구분과 공법별 시공기준 제시(행정예고 자료). 개인 마당·사유지 진입로는 적용 대상 다름', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국가법령정보센터 첨부 개정전문 PDF) + Claude(원문 대조)', '2026-10-28', '2021-07-23', '2021-07-23' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 보도 설치 및 관리 지침');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 도로의 구조ㆍ시설 기준에 관한 규칙', '[원문 조문 확인(법제처 OPEN API 대조) — AI 상담 미사용(검토 전)]
AI 적용 범위: 조건부 안내 — 공공 도로 공사 기준. 사유지 의무로 안내하지 않음
문서명: 도로의 구조ㆍ시설 기준에 관한 규칙
번호: 제1조·제16조·제29조·제30조 · 국토교통부령 제1588호
발행: 국토교통부(국토교통부령)
관련 자재: 보도, 경계석(연석), 측구, 배수시설
적용 범위(검색 기준): 「도로법」상 도로의 구조·시설 기준. 연석 높이·측구 등 세부 수치는 검색에서 확인 안 됨. 공공 도로 대상이며 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 국토교통부령 제1360호, 2024. 7. 10. 일부개정(law.go.kr 검색 요약). 이후 개정 여부 미확인
2-1 판정: 원문 조문 확인(법제처 OPEN API 대조 일치) — 연석 높이 25cm 이하·보도 유효폭 2m(제16조), 배수시설(제30조) 조문 확인. 제29조 포장은 두께 수치 없음(80T 근거 아님). 적용 범위: 도로법상 도로 신설·개량(제1조)
검색 근거 신뢰도: 중간
확인 수준: api_text_verified — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도, 경계석(연석), 측구, 배수시설', 0, 900, 'law', '도로의 구조ㆍ시설 기준에 관한 규칙', '제1조·제16조·제29조·제30조 · 국토교통부령 제1588호', 'https://law.go.kr/LSW/lsRvsDocListP.do?chrClsCd=010102&lsId=007080', '「도로법」상 도로의 구조·시설 기준. 연석 높이·측구 등 세부 수치는 검색에서 확인 안 됨. 공공 도로 대상이며 개인 마당은 적용 대상 다름', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행 2026-10-28)', '2026-10-28', '2026-06-03', '2026-06-02' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 도로의 구조ㆍ시설 기준에 관한 규칙');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 도로와 다른 시설의 연결에 관한 규칙', '[원문 조문 확인(법제처 OPEN API 대조) — AI 상담 미사용(검토 전)]
AI 적용 범위: 조건부 안내 — 일반국도 연결허가 공사일 때만
문서명: 도로와 다른 시설의 연결에 관한 규칙
번호: 제2조제11호·제3조·제9조 · 국토교통부령 제882호
발행: 국토교통부(국토교통부령)
관련 자재: 경계석(연석), 진입로
적용 범위(검색 기준): ''연석이란 보도 등과 차도의 경계선에 연접하여 설치하는 경계석'' 정의(2차 사이트 요약). 도로에 진입로 등을 연결할 때 적용 — 사유지 진입로 연결 시 관련 가능. 포털에서 검색
시행·개정일(검색 기준, 미확인): 확인 안 됨
2-1 판정: 원문 조문 확인(법제처 OPEN API 대조 일치) — 연석 정의(제2조제11호), 적용 범위(일반국도 우측 연결, 제3조), U형 측구 유효폭 30cm·깊이 60cm 이상+격자형 철제 뚜껑·20m 이내 맨홀(제9조) 확인
검색 근거 신뢰도: 낮음
확인 수준: api_text_verified — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '경계석(연석), 진입로', 0, 900, 'law', '도로와 다른 시설의 연결에 관한 규칙', '제2조제11호·제3조·제9조 · 국토교통부령 제882호', 'https://www.law.go.kr', '''연석이란 보도 등과 차도의 경계선에 연접하여 설치하는 경계석'' 정의(2차 사이트 요약). 도로에 진입로 등을 연결할 때 적용 — 사유지 진입로 연결 시 관련 가능. 포털에서 검색', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행 2026-10-28)', '2026-10-28', '2021-08-27', '2021-08-27' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 도로와 다른 시설의 연결에 관한 규칙');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 하수도법 시행규칙', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 하수도법 시행규칙
번호: 제6조 관련 [별표 5] 공공하수도의 구조에 관한 기술적 기준
발행: 환경부(검색 결과상 최신 판본에 ''기후에너지환경부장관'' 표기)
관련 자재: 빗물받이, 맨홀(뚜껑 재질), 하수관로(흄관·PE·PVC관)
적용 범위(검색 기준): 공공하수도 구조 기준. 검색 요약상 빗물받이 간격 10m 이상 30m 이하(상습침수지역 10m 미만 가능), 맨홀은 방향·경사·관경 변화점 등에 설치, 뚜껑은 주철제·철근콘크리트제 등 충분한 강도. 공공하수도 대상이며 개인하수도·마당 배수와는 적용 대상 다름
시행·개정일(검색 기준, 미확인): 별표 5 <개정 2025. 10. 1.> 판본 확인(law.go.kr 파일 제목). 구판 <개정 2014.7.17>도 존재
2-1 판정: 상세 원문 검증(별표 원문 PDF + 법제처 OPEN API 대조) — 별표 5(개정 2025-10-01) 전문 확인 — 맨홀 KS 규격·인버트, 오수받이 안지름 30cm 이상, 빗물받이 간격 10~30m, 맨홀·물받이 뚜껑 재질 → V19 등록·로컬 활성
검색 근거 신뢰도: 높음
확인 수준: original_verified — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '빗물받이, 맨홀(뚜껑 재질), 하수관로(흄관·PE·PVC관)', 0, 900, 'law', '하수도법 시행규칙', '제6조 관련 [별표 5] 공공하수도의 구조에 관한 기술적 기준', 'https://www.law.go.kr/flDownload.do?gubun=&flSeq=160140833&bylClsCd=110201', '공공하수도 구조 기준. 검색 요약상 빗물받이 간격 10m 이상 30m 이하(상습침수지역 10m 미만 가능), 맨홀은 방향·경사·관경 변화점 등에 설치, 뚜껑은 주철제·철근콘크리트제 등 충분한 강도. 공공하수도 대상이며 개인하수도·마당 배수와는 적용 대상 다름', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국가법령정보센터 별표 원문 PDF) + Claude(원문 대조)', '2026-10-28', '2026-06-22', '2026-06-22' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 하수도법 시행규칙');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[법령] 하수관로 유지관리 기준', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
AI 적용 범위: 조건부 안내 — 공공 빗물받이·측구에 한함
문서명: 하수관로 유지관리 기준
번호: 제3조·제5조·제6조 · 기후에너지환경부고시 제2025-165호
발행: 환경부
관련 자재: 맨홀, 빗물받이, 측구, 하수관로
적용 범위(검색 기준): 공공하수도 관리자의 유지관리(점검·청소 주기 등) 기준. 제품 규격 기준 아님. 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 2023. 4. 10. 제정(law.go.kr 고시문 파일). 개정안 행정예고 보도(korea.kr) 있으나 최종 고시 여부 미확인
2-1 판정: 상세 원문 검증(별표 원문 PDF + 법제처 OPEN API 대조) — 별표 1 점검 주기(받이 월별·맨홀 분기·측구 연 1회, 받이 뚜껑 덮개 적치 점검) 확인 → V20 등록(관리청 업무라 상담 비활성). 조문 근거 V10 은 활성 유지
검색 근거 신뢰도: 높음
확인 수준: original_verified — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', '맨홀, 빗물받이, 측구, 하수관로', 0, 900, 'law', '하수관로 유지관리 기준', '제3조·제5조·제6조 · 기후에너지환경부고시 제2025-165호', 'https://www.law.go.kr/LSW/admRulLsInfoP.do?admRulSeq=2100000221674', '공공하수도 관리자의 유지관리(점검·청소 주기 등) 기준. 제품 규격 기준 아님. 개인 마당은 적용 대상 다름', 'current', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국가법령정보센터 별표 원문 PDF) + Claude(원문 대조)', '2026-10-28', '2025-10-01', '2025-10-01' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[법령] 하수관로 유지관리 기준');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KDS] 하수도설계기준 (KDS 61 00 00)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 하수도설계기준
번호: KDS 61 00 00
발행: 환경부(국가건설기준센터 게재)
관련 자재: 하수관로, 맨홀, 빗물받이, 집수정, 관종(흄관·PE·PVC)
적용 범위(검색 기준): 공공 하수도시설 설계기준. 관로·맨홀·빗물받이 세부 조항은 검색에서 확인 안 됨. 공공사업 대상 — 개인 마당 배수는 적용 대상 다름
시행·개정일(검색 기준, 미확인): 2022. 12. 28. 개정 표기(2차 PDF 표지). law.go.kr 행정규칙 연혁에 2017.10.27, 2019.4.10 일부개정본 노출. 최신본 미확인
2-1 판정: 공식 메타데이터 확인(법제처 OPEN API 대조) — 고시(제2025-165호, 2025-10-01 시행) 조문·번호는 API 일치. 별지 PDF 는 안내 1쪽뿐 — KDS 61 기준 본문은 KCSC 원문 필요
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '하수관로, 맨홀, 빗물받이, 집수정, 관종(흄관·PE·PVC)', 0, 900, 'kds', '하수도설계기준', 'KDS 61 00 00', 'https://www.law.go.kr/LSW/admRulInfoP.do?admRulSeq=2100000100709', '공공 하수도시설 설계기준. 관로·맨홀·빗물받이 세부 조항은 검색에서 확인 안 됨. 공공사업 대상 — 개인 마당 배수는 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(법제처 Open API 행정규칙 원문 미러(admrule-kr, 2026-10-27 기준) 열람) + 법제처 OPEN API 대조(사용자 실행 2026-10-28)', '2026-10-28', '2025-10-01', '2025-10-01' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KDS] 하수도설계기준 (KDS 61 00 00)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KDS] 관로시설 설계기준 (KDS 61 40 00)', '[검색 후보 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 관로시설 설계기준
번호: KDS 61 40 00
발행: 환경부
관련 자재: 하수관로, 맨홀(추락방지시설), 빗물받이, 관종
적용 범위(검색 기준): 공공 하수관로 설계. 개정안: 맨홀 뚜껑 이탈 위험 지점 추락방지시설 의무화 등. 하위 번호(KDS 61 40 10 등)는 검색에서 확인 안 됨. 공공사업 대상
시행·개정일(검색 기준, 미확인): 2022년 12월 개정안 행정예고(me.go.kr). 확정 시행일 미확인
2-1 판정: 검색 후보 — 원문 접근 불가 — 상위 하수도설계기준 고시(제2025-165호, 2025-10-01 시행) 현행은 원문 미러로 확인했으나, 이 코드 번호가 별지 안에 실제 있는지 확인 불가
검색 근거 신뢰도: 높음
확인 수준: search_candidate — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '하수관로, 맨홀(추락방지시설), 빗물받이, 관종', 0, 900, 'kds', '관로시설 설계기준', 'KDS 61 40 00', 'http://me.go.kr/home/web/board/read.do?boardMasterId=39&boardId=1566950&menuId=10524', '공공 하수관로 설계. 개정안: 맨홀 뚜껑 이탈 위험 지점 추락방지시설 의무화 등. 하위 번호(KDS 61 40 10 등)는 검색에서 확인 안 됨. 공공사업 대상', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'search_candidate', NULL, '2026-10-28', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KDS] 관로시설 설계기준 (KDS 61 40 00)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KDS] 도로설계기준 (KDS 44 00 00)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 미검토
문서명: 도로설계기준
번호: KDS 44 00 00 · 국토교통부고시 제2026-528호(2026-10-02 고시)
발행: 국토교통부
관련 자재: 보도 포장, 배수시설(측구·빗물받이), 경계석
적용 범위(검색 기준): 도로 설계기준. 포장·배수 관련 하위 코드 번호·내용은 검색에서 확인 안 됨. 공공 도로 대상 — 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 고시 2026-10-02(국토교통부고시 제2026-528호), 국가건설기준센터 게시 2026-10-07, 경과조치: 발간 시점부터 사용 — 사용자 확인
2-1 판정: 공식 메타데이터 확인(사용자) — 사용자 KCSC 공지 확인(고시 제2026-528호). 참고: 법제처 행정규칙 미러(2026-10-27)는 아직 제2023-907호를 현행으로 표시 — 2026 고시의 법제처 등록 지연 추정
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 고시 번호·고시일·게시일·경과조치 확인. 개정된 개별 조항 내용은 미검증.
확인 URL: https://kcsc.re.kr/board/notdetail/9891
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도 포장, 배수시설(측구·빗물받이), 경계석', 0, 900, 'kds', '도로설계기준', 'KDS 44 00 00 · 국토교통부고시 제2026-528호(2026-10-02 고시)', 'https://www.molit.go.kr/USR/I0204/m_45/dtl.jsp?idx=18977', '도로 설계기준. 포장·배수 관련 하위 코드 번호·내용은 검색에서 확인 안 됨. 공공 도로 대상 — 개인 마당은 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(국가건설기준센터 공지 확인)', '2026-10-27', NULL, '2026-10-02' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KDS] 도로설계기준 (KDS 44 00 00)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KCS] 도로공사 표준시방서 (KCS 44 00 00)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 미검토
문서명: 도로공사 표준시방서
번호: KCS 44 00 00 · 국토교통부고시 제2026-528호(2026-10-02 고시)
발행: 국토교통부
관련 자재: 보도블록 포장, 경계석, 측구, 배수시설
적용 범위(검색 기준): 공공 도로공사 시공 기준. ''KCS 44 50 05 보도포장''이라는 번호는 검색에서 확인되지 않음(행정예고 목록에 KCS 44 30 05, 44 50 25, 44 80 05만 노출, 제목 미확인). 공공공사 대상
시행·개정일(검색 기준, 미확인): 고시 2026-10-02(국토교통부고시 제2026-528호), 국가건설기준센터 게시 2026-10-07, 경과조치: 발간 시점부터 사용 — 사용자 확인
2-1 판정: 공식 메타데이터 확인(사용자) — 사용자 KCSC 공지 확인(고시 제2026-528호). 참고: 법제처 행정규칙 미러(2026-10-27)는 아직 제2023-907호를 현행으로 표시 — 2026 고시의 법제처 등록 지연 추정
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 고시 번호·고시일·게시일·경과조치 확인. 개정된 개별 조항 내용은 미검증.
확인 URL: https://kcsc.re.kr/board/notdetail/9891
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도블록 포장, 경계석, 측구, 배수시설', 0, 900, 'kcs', '도로공사 표준시방서', 'KCS 44 00 00 · 국토교통부고시 제2026-528호(2026-10-02 고시)', 'https://www.molit.go.kr/USR/I0204/m_45/dtl.jsp?idx=18977', '공공 도로공사 시공 기준. ''KCS 44 50 05 보도포장''이라는 번호는 검색에서 확인되지 않음(행정예고 목록에 KCS 44 30 05, 44 50 25, 44 80 05만 노출, 제목 미확인). 공공공사 대상', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(국가건설기준센터 공지 확인)', '2026-10-27', NULL, '2026-10-02' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KCS] 도로공사 표준시방서 (KCS 44 00 00)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KDS] 차도용 블록포장설계 (KDS 44 50 25)', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
확인된 내용: 기준명 "차도용 블록포장설계"(국가건설기준센터 기준 목록, 사용자 확인) · 2026-10-02 신설 고시(국토교통부고시 제2026-528호).
AI 적용 범위: 미검토 — 상세 기술 조항(두께·포장 구조) 검증 전 사용 금지
문서명: 차도용 블록포장설계
번호: KDS 44 50 25
발행: 국토교통부(국가건설기준센터 게시)
관련 자재: 차도·차량 진입부 블록포장(인터로킹·인조화강 등 블록), 포장 하부 구조(기층·모래 등 — 조항 미검증)
적용 범위(검색 기준): 차도용 블록포장 기준 — 공공 도로공사 기준으로 보이며, 개인 진입로·마당 적용 범위와 블록 두께 등 상세 조항은 미검증
시행·개정일(검색 기준, 미확인): 신설 고시 2026-10-02(국토교통부고시 제2026-528호), 게시 2026-10-07, 경과조치: 발간 시점부터 사용 — 사용자 확인
2-1 판정: 상세 원문 검증(국토교통부 고시 첨부 개정전문) — KDS 44 50 25:2026 원문 확인 — 적용 범위 도로법 제10조 도로, 블록 표층 최소두께 100mm, 표준단면 표층 100mm(AADT≤1,000)/120mm(≤4,000), 받침안정층 25mm, 줄눈 2~3mm → V21 등록(비활성: 사내 80T 문구와 수치가 달라 활성화는 사용자 결정)
검색 근거 신뢰도: 높음
확인 수준: original_verified — 공식 메타데이터 확인(고시·기준명). 상세 기술 조항 원문 미검증.
확인 URL: https://kcsc.re.kr/standardCode/viewer
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '차도·차량 진입부 블록포장(인터로킹·인조화강 등 블록), 포장 하부 구조(기층·모래 등 — 조항 미검증)', 0, 900, 'kds', '차도용 블록포장설계', 'KDS 44 50 25', 'https://kcsc.re.kr/board/notdetail/9891', '차도용 블록포장 기준 — 공공 도로공사 기준으로 보이며, 개인 진입로·마당 적용 범위와 블록 두께 등 상세 조항은 미검증', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국토교통부 고시 첨부 개정전문 zip) + Claude(HWPX 원문 대조)', '2026-10-28', NULL, '2026-10-02' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KDS] 차도용 블록포장설계 (KDS 44 50 25)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KCS] 차도용 블록포장공사 (KCS 44 50 25)', '[상세 원문 검증 — AI 상담 미사용(검토 전)]
확인된 내용: 차도용 블록포장 공사에 관한 시공 기준(신설)
AI 적용 범위: 미검토 — 상세 기술 조항(두께·포장 구조) 검증 전 사용 금지
문서명: 차도용 블록포장공사
번호: KCS 44 50 25
발행: 국토교통부(국가건설기준센터 게시)
관련 자재: 차도·차량 진입부 블록포장(인터로킹·인조화강 등 블록), 포장 하부 구조(기층·모래 등 — 조항 미검증)
적용 범위(검색 기준): 차도용 블록포장 기준 — 공공 도로공사 기준으로 보이며, 개인 진입로·마당 적용 범위와 블록 두께 등 상세 조항은 미검증
시행·개정일(검색 기준, 미확인): 신설 고시 2026-10-02(국토교통부고시 제2026-528호), 게시 2026-10-07, 경과조치: 발간 시점부터 사용 — 사용자 확인
2-1 판정: 상세 원문 검증(국토교통부 고시 첨부 개정전문) — KCS 44 50 25:2026 원문 확인(정식 명칭 "차도용 블록포장공사") — 차량이 통행하는 차도용 블록포장공사, 블록 최소 100mm(설계 결과에 따라 상향), KS F 4419 품질, 돌기 2~3mm, 받침모래 25±5mm, 단차 2mm 이내 → V22 등록(비활성, 사용자 결정)
검색 근거 신뢰도: 높음
확인 수준: original_verified — 공식 메타데이터 확인(고시). 기준 목록상 정식 명칭과 상세 기술 조항은 미검증.
확인 URL: https://kcsc.re.kr/board/notdetail/9891
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '차도·차량 진입부 블록포장(인터로킹·인조화강 등 블록), 포장 하부 구조(기층·모래 등 — 조항 미검증)', 0, 900, 'kcs', '차도용 블록포장공사', 'KCS 44 50 25', 'https://kcsc.re.kr/board/notdetail/9891', '차도용 블록포장 기준 — 공공 도로공사 기준으로 보이며, 개인 진입로·마당 적용 범위와 블록 두께 등 상세 조항은 미검증', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'original_verified', '사용자(국토교통부 고시 첨부 개정전문 zip) + Claude(HWPX 원문 대조)', '2026-10-28', NULL, '2026-10-02' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KCS] 차도용 블록포장공사 (KCS 44 50 25)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KDS] 보도포장 (2016 판 — 현행 여부 미확인) (KDS 34 60 10)', '[검색 후보 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 보도포장 (2016 판 — 현행 여부 미확인)
번호: KDS 34 60 10
발행: 국토교통부(국가건설기준)
관련 자재: 보도블록, 차도용 블록(콘크리트 조립 블록)
적용 범위(검색 기준): 2차 자료 요약에 "콘크리트 조립 블록을 보도용·차도용으로 나누고 보도용 두께 6cm, 차도용 8cm" 문구 — 원문·현행 여부 미확인. 80T 문구의 출처로 연결하지 않음(별도 조사 항목)
시행·개정일(검색 기준, 미확인): 2016 판(2차 자료 제목 기준). 현행·폐지·코드 재편 여부 미확인
2-1 판정: 검색 후보 — 원문 접근 불가 — 상위 조경설계기준(KDS 34 00 00) 고시 제2024-743호(2024-12-16 시행) 현행은 원문 미러로 확인. KDS 34 60 10 코드의 현행 존재·블록 두께 조항은 미확인 — 80T 출처로 연결하지 않음 / 개정전문 zip 에 KDS 34 계열 없음 — 차도용 블록포장은 KDS·KCS 44 50 25 로 신설(조경 하위 → 도로 기준). 34 60 10 현행 여부는 KCSC 확인 필요
검색 근거 신뢰도: 낮음
확인 수준: search_candidate — 검색 후보(2차 자료). 공식 원문으로 현행 여부·조항 확인 필요.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도블록, 차도용 블록(콘크리트 조립 블록)', 0, 900, 'kds', '보도포장 (2016 판 — 현행 여부 미확인)', 'KDS 34 60 10', 'https://kcsc.re.kr/standardCode/viewer', '2차 자료 요약에 "콘크리트 조립 블록을 보도용·차도용으로 나누고 보도용 두께 6cm, 차도용 8cm" 문구 — 원문·현행 여부 미확인. 80T 문구의 출처로 연결하지 않음(별도 조사 항목)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'search_candidate', NULL, '2026-10-28', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KDS] 보도포장 (2016 판 — 현행 여부 미확인) (KDS 34 60 10)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KCS] 하수관로공사 (KCS 61 10 00)', '[검색 후보 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 하수관로공사
번호: KCS 61 10 00
발행: 환경부(추정 — 검색에서 확인 안 됨)
관련 자재: 하수관(흄관·PE 이중벽관·PVC관), 맨홀
적용 범위(검색 기준): 공공 하수관로 시공 기준. 포털에서 검색. 공공공사 대상
시행·개정일(검색 기준, 미확인): 확인 안 됨
2-1 판정: 검색 후보 — 원문 접근 불가 — 상위 하수관로공사 표준시방서 고시(기후에너지환경부고시 제2025-165호, 2025-10-01 시행) 현행은 원문 미러로 확인했으나, 이 코드 번호가 별지 안에 실제 있는지 확인 불가
검색 근거 신뢰도: 중간
확인 수준: search_candidate — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '하수관(흄관·PE 이중벽관·PVC관), 맨홀', 0, 900, 'kcs', '하수관로공사', 'KCS 61 10 00', 'https://www.kcsc.re.kr', '공공 하수관로 시공 기준. 포털에서 검색. 공공공사 대상', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'search_candidate', NULL, '2026-10-28', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KCS] 하수관로공사 (KCS 61 10 00)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KCS] 하수도관부설 및 연결공사 (KCS 61 20 00)', '[검색 후보 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 하수도관부설 및 연결공사
번호: KCS 61 20 00
발행: 환경부(추정 — 검색에서 확인 안 됨)
관련 자재: 하수관 부설·연결(PE·PVC·흄관), 맨홀 접속
적용 범위(검색 기준): 공공 하수관 부설·연결 시공. 포털에서 검색
시행·개정일(검색 기준, 미확인): 확인 안 됨
2-1 판정: 검색 후보 — 원문 접근 불가 — 상위 하수관로공사 표준시방서 고시(기후에너지환경부고시 제2025-165호, 2025-10-01 시행) 현행은 원문 미러로 확인했으나, 이 코드 번호가 별지 안에 실제 있는지 확인 불가
검색 근거 신뢰도: 중간
확인 수준: search_candidate — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '하수관 부설·연결(PE·PVC·흄관), 맨홀 접속', 0, 900, 'kcs', '하수도관부설 및 연결공사', 'KCS 61 20 00', 'https://www.kcsc.re.kr', '공공 하수관 부설·연결 시공. 포털에서 검색', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'search_candidate', NULL, '2026-10-28', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KCS] 하수도관부설 및 연결공사 (KCS 61 20 00)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 보차도용 콘크리트 인터로킹 블록 (KS F 4419)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
확인된 내용: 표준명·최종 개정·확인일·적용 범위만 확인. 블록 종류·두께·강도 등 상세 조항은 미검증(KS 원문은 유료).
AI 적용 범위: 불가 — 제품 표준의 존재·적용 범위만 확인. 두께·강도 요건은 원문 미검증
문서명: 보차도용 콘크리트 인터로킹 블록
번호: KS F 4419
발행: 국가기술표준원
관련 자재: 인터로킹 블록, 투수블록(2016 개정에 투수성 시험장치 수정 포함), 주차장 포장 블록
적용 범위(검색 기준): 보도, 차도, 광장, 주차장 등의 포장에 사용하는 콘크리트 인터로킹 블록 (e나라표준인증 적용 범위, 사용자 확인)
시행·개정일(검색 기준, 미확인): 최종 개정 2025-12-16
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2025-12-16 · 적용 범위: 보도·차도·광장·주차장 포장용 콘크리트 인터로킹 블록(사용자 확인 메타데이터) (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 공식 표준 메타데이터 확인. 상세 기술 조항 원문 검증 아님.
확인 URL: https://www.standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSF4419&menuId=919&reformNo=15&tmprKsNo=KSF4419&topMenuId=502&upperMenuId=503
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '인터로킹 블록, 투수블록(2016 개정에 투수성 시험장치 수정 포함), 주차장 포장 블록', 0, 900, 'ks', '보차도용 콘크리트 인터로킹 블록', 'KS F 4419', 'https://www.standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?menuId=919&topMenuId=502&upperMenuId=503&ksNo=KSF4419&tmprKsNo=KSF4419&reformNo=15', '보도, 차도, 광장, 주차장 등의 포장에 사용하는 콘크리트 인터로킹 블록 (e나라표준인증 적용 범위, 사용자 확인)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(e나라표준인증 상세 페이지)', '2026-10-27', NULL, '2025-12-16' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 보차도용 콘크리트 인터로킹 블록 (KS F 4419)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 시각장애인용 점자블록 (KS F 4561)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 시각장애인용 점자블록
번호: KS F 4561
발행: 국가기술표준원
관련 자재: 점자블록(점형·선형)
적용 범위(검색 기준): 돌기를 표면에 양각해 보행 위치·방향 안내와 위험물 주의 표시를 하는 시각장애인용 점자블록 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2022-12-23
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2022-12-23 · 적용 범위: 돌기를 표면에 양각해 보행 위치·방향 안내와 위험물 주의 표시를 하는 시각장애인용 점자블록 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점자블록(점형·선형)', 0, 900, 'ks', '시각장애인용 점자블록', 'KS F 4561', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?menuId=919&topMenuId=502&upperMenuId=503&ksNo=KSF4561&tmprKsNo=KSF4561&reformNo=09', '돌기를 표면에 양각해 보행 위치·방향 안내와 위험물 주의 표시를 하는 시각장애인용 점자블록 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(e나라표준인증 상세 페이지)', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 시각장애인용 점자블록 (KS F 4561)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 포장용 콘크리트 평판 (KS F 4001)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 포장용 콘크리트 평판
번호: KS F 4001
발행: 국가기술표준원
관련 자재: 보도용 평판 블록
적용 범위(검색 기준): 주로 도로·광장 등의 포장에 쓰는 콘크리트 평판 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2024-12-31
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2024-12-31 · 적용 범위: 주로 도로·광장 등의 포장에 쓰는 콘크리트 평판 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도용 평판 블록', 0, 900, 'ks', '포장용 콘크리트 평판', 'KS F 4001', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010050193', '주로 도로·광장 등의 포장에 쓰는 콘크리트 평판 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(e나라표준인증 상세 페이지 직접 열람) · 사용자 인정 2026-11-05', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 포장용 콘크리트 평판 (KS F 4001)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 콘크리트 경계 블록 (KS F 4006)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
확인된 내용: 표준명·최종 개정·확인일·적용 범위만 확인. 종류(A·B·C형 등)·치수·1개 길이 등 상세 조항은 미검증.
AI 적용 범위: 불가 — 경계석 1개 길이·치수 등은 원문 미검증(상품 데이터의 길이 누락도 이 표준으로 채우지 않음)
문서명: 콘크리트 경계 블록
번호: KS F 4006
발행: 국가기술표준원
관련 자재: 경계석(콘크리트 경계블록), 인조석 표면층 경계석
적용 범위(검색 기준): 보도·차도 또는 도로 경계부에 사용하는 콘크리트 경계 블록 (e나라표준인증 적용 범위, 사용자 확인)
시행·개정일(검색 기준, 미확인): 최종 개정 2024-12-31
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2024-12-31 · 적용 범위: 보도·차도·도로 경계부 콘크리트 경계 블록(사용자 확인 메타데이터) (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 공식 표준 메타데이터 확인. 상세 기술 조항 원문 검증 아님.
확인 URL: https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSF4006&menuId=919&pageIndex=1&pageUnit=10&reformNo=19&tmprKsNo=KSF4006&topMenuId=502&upperMenuId=503
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '경계석(콘크리트 경계블록), 인조석 표면층 경계석', 0, 900, 'ks', '콘크리트 경계 블록', 'KS F 4006', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSF4006&tmprKsNo=KSF4006&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '보도·차도 또는 도로 경계부에 사용하는 콘크리트 경계 블록 (e나라표준인증 적용 범위, 사용자 확인)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(e나라표준인증 상세 페이지)', '2026-10-27', NULL, '2024-12-31' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 콘크리트 경계 블록 (KS F 4006)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 무근 및 철근 콘크리트 L형 측구 (KS F 4005)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 무근 및 철근 콘크리트 L형 측구
번호: KS F 4005
발행: 국가기술표준원
관련 자재: L형 측구, 노면 배수
적용 범위(검색 기준): 주로 노면 배수용 측구로 쓰는 콘크리트 및 철근 콘크리트 L형(공식 표준명: 콘크리트 및 철근 콘크리트 L 형) (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 확인 2024-11-15
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 확인 2024-11-15 · 적용 범위: 주로 노면 배수용 측구로 쓰는 콘크리트 및 철근 콘크리트 L형(공식 표준명: 콘크리트 및 철근 콘크리트 L 형) (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 검색 후보 — 공식 사이트 주소·요약은 찾았으나 사람이 페이지를 열어 확인하지 않음. 사용자가 해당 URL에서 확인하면 metadata_confirmed 로 승격.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', 'L형 측구, 노면 배수', 0, 900, 'ks', '무근 및 철근 콘크리트 L형 측구', 'KS F 4005', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010124877', '주로 노면 배수용 측구로 쓰는 콘크리트 및 철근 콘크리트 L형(공식 표준명: 콘크리트 및 철근 콘크리트 L 형) (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(e나라표준인증 상세 페이지 직접 열람) · 사용자 인정 2026-11-05', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 무근 및 철근 콘크리트 L형 측구 (KS F 4005)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 도로용 철근 콘크리트 측구 (KS F 4417)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 도로용 철근 콘크리트 측구
번호: KS F 4417
발행: 국가기술표준원
관련 자재: 측구(몸체·뚜껑), 도로 배수
적용 범위(검색 기준): 보도·차도에 평행하게 설치하는 철근 콘크리트 측구의 몸체 및 뚜껑 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2024-12-31
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2024-12-31 · 적용 범위: 보도·차도에 평행하게 설치하는 철근 콘크리트 측구의 몸체 및 뚜껑 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 검색 후보 — 공식 사이트 주소·요약은 찾았으나 사람이 페이지를 열어 확인하지 않음. 사용자가 해당 URL에서 확인하면 metadata_confirmed 로 승격.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', '측구(몸체·뚜껑), 도로 배수', 0, 900, 'ks', '도로용 철근 콘크리트 측구', 'KS F 4417', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSF4417&tmprKsNo=KSF4417&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '보도·차도에 평행하게 설치하는 철근 콘크리트 측구의 몸체 및 뚜껑 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(e나라표준인증 상세 페이지 직접 열람) · 사용자 인정 2026-11-05', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 도로용 철근 콘크리트 측구 (KS F 4417)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 철근 콘크리트 플룸 및 벤치 플룸 (KS F 4010)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 철근 콘크리트 플룸 및 벤치 플룸
번호: KS F 4010
발행: 국가기술표준원
관련 자재: 벤치플륨, 플륨형 수로관
적용 범위(검색 기준): 주로 수로로 쓰이는 철근 콘크리트 플룸 및 벤치 플룸 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2024-12-31
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2024-12-31 · 적용 범위: 주로 수로로 쓰이는 철근 콘크리트 플룸 및 벤치 플룸 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 검색 후보 — 공식 사이트 주소·요약은 찾았으나 사람이 페이지를 열어 확인하지 않음. 사용자가 해당 URL에서 확인하면 metadata_confirmed 로 승격.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', '벤치플륨, 플륨형 수로관', 0, 900, 'ks', '철근 콘크리트 플룸 및 벤치 플룸', 'KS F 4010', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSF4010&menuId=919&pageIndex=1&pageUnit=10&reformNo=19&tmprKsNo=KSF4010&topMenuId=502&upperMenuId=503', '주로 수로로 쓰이는 철근 콘크리트 플룸 및 벤치 플룸 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(e나라표준인증 상세 페이지)', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 철근 콘크리트 플룸 및 벤치 플룸 (KS F 4010)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 철근 콘크리트 U형 (KS F 4016)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 철근 콘크리트 U형
번호: KS F 4016
발행: 국가기술표준원
관련 자재: U형 수로관(U형 측구)
적용 범위(검색 기준): 주로 도로 측구에 쓰는 철근 콘크리트 U형의 몸체 및 뚜껑 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2024-12-31
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2024-12-31 · 적용 범위: 주로 도로 측구에 쓰는 철근 콘크리트 U형의 몸체 및 뚜껑 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 중간
확인 수준: metadata_confirmed — 검색 후보 — 공식 사이트 주소·요약은 찾았으나 사람이 페이지를 열어 확인하지 않음. 사용자가 해당 URL에서 확인하면 metadata_confirmed 로 승격.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', 'U형 수로관(U형 측구)', 0, 900, 'ks', '철근 콘크리트 U형', 'KS F 4016', 'https://standard.go.kr', '주로 도로 측구에 쓰는 철근 콘크리트 U형의 몸체 및 뚜껑 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(e나라표준인증 상세 페이지 직접 열람) · 사용자 인정 2026-11-05', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 철근 콘크리트 U형 (KS F 4016)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 하수도용 콘크리트 맨홀 블록 (KS F 4012)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 하수도용 콘크리트 맨홀 블록
번호: KS F 4012
발행: 국가기술표준원
관련 자재: 맨홀(콘크리트 맨홀 블록), 집수정
적용 범위(검색 기준): 주로 하수도에 쓰이는 철근 콘크리트 맨홀 블록 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2024-12-31
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2024-12-31 · 적용 범위: 주로 하수도에 쓰이는 철근 콘크리트 맨홀 블록 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '맨홀(콘크리트 맨홀 블록), 집수정', 0, 900, 'ks', '하수도용 콘크리트 맨홀 블록', 'KS F 4012', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010150139', '주로 하수도에 쓰이는 철근 콘크리트 맨홀 블록 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(e나라표준인증 상세 페이지)', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 하수도용 콘크리트 맨홀 블록 (KS F 4012)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항 (KS D 4040)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항
번호: KS D 4040
발행: 국가기술표준원
관련 자재: 맨홀 뚜껑·틀(주철·주강)
적용 범위(검색 기준): 상수도·하수도·전기용·통신용 주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항 (한국표준협회 KSSN 표준 상세, 사용자 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 확인 2024-09-13
2-1 판정: 공식 메타데이터 확인(한국표준협회 KSSN 표준 상세 — 확인일·적용 범위) — 표준명·최종 확인 2024-09-13 · 적용 범위: 상수도·하수도·전기용·통신용 주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항(KSSN 표준 상세 — 2024 확인, 고시 제2024-0349호) (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '맨홀 뚜껑·틀(주철·주강)', 0, 900, 'ks', '주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항', 'KS D 4040', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010099262', '상수도·하수도·전기용·통신용 주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항 (한국표준협회 KSSN 표준 상세, 사용자 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(한국표준협회 KSSN 표준 상세 페이지)', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항 (KS D 4040)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 맨홀 뚜껑 및 틀(상하수도·전기·통신용) (KS D 6021)', '[검색 후보 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 폐지 규격
문서명: 맨홀 뚜껑 및 틀(상하수도·전기·통신용)
번호: KS D 6021
발행: 국가기술표준원
관련 자재: 맨홀 뚜껑
적용 범위(검색 기준): 폐지됨. 대체: 단체표준 SPS-KFCAM201-1639(주철·주강재 맨홀 뚜껑 및 틀, 최종개정 2022-07-13) — 검색 요약. 상담 시 ''KS 인증 맨홀뚜껑'' 표현 주의
시행·개정일(검색 기준, 미확인): 확인 안 됨
2-1 판정: 제외 — 국가표준 목록 CSV 에서 최종고시상태 "폐지"(N) 확인 — 맨홀 뚜껑은 KS D 4040(확인)으로 대체 검토
검색 근거 신뢰도: 중간
확인 수준: search_candidate — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '맨홀 뚜껑', 0, 900, 'ks', '맨홀 뚜껑 및 틀(상하수도·전기·통신용)', 'KS D 6021', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSD6021&tmprKsNo=KSD6021&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '폐지됨. 대체: 단체표준 SPS-KFCAM201-1639(주철·주강재 맨홀 뚜껑 및 틀, 최종개정 2022-07-13) — 검색 요약. 상담 시 ''KS 인증 맨홀뚜껑'' 표현 주의', 'repealed', 'AI 조사 초안(검색 기반)', 'unreviewed', 'search_candidate', NULL, NULL, NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 맨홀 뚜껑 및 틀(상하수도·전기·통신용) (KS D 6021)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 원심력 철근 콘크리트관 (KS F 4403)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 원심력 철근 콘크리트관
번호: KS F 4403
발행: 국가기술표준원
관련 자재: 흄관(원심력 철근콘크리트관) 및 A형 이음 칼라
적용 범위(검색 기준): 원심력 또는 축 전압으로 만든 철근 콘크리트관과 직관 A형 이음용 칼라 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2024-11-15
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2024-11-15 · 적용 범위: 원심력 또는 축 전압으로 만든 철근 콘크리트관과 직관 A형 이음용 칼라(칼라는 부속서) (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', '흄관(원심력 철근콘크리트관) 및 A형 이음 칼라', 0, 900, 'ks', '원심력 철근 콘크리트관', 'KS F 4403', 'https://www.standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSF4403', '원심력 또는 축 전압으로 만든 철근 콘크리트관과 직관 A형 이음용 칼라 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(e나라표준인증 상세 페이지)', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 원심력 철근 콘크리트관 (KS F 4403)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 진동 및 전압 철근 콘크리트관(제목 원문 미확인) (KS F 4402)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 진동 및 전압 철근 콘크리트관(제목 원문 미확인)
번호: KS F 4402
발행: 국가기술표준원
관련 자재: 철근 콘크리트관(진동·롤 전압 성형)
적용 범위(검색 기준): 진동 코어·롤로 내면을 진동 또는 롤 전압해 만든 철근 콘크리트관(공식 표준명: 진동 및 전압 철근 콘크리트 관) (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 확인 2021-09-06
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 확인 2021-09-06 · 적용 범위: 진동 코어·롤로 내면을 진동 또는 롤 전압해 만든 철근 콘크리트관(공식 표준명: 진동 및 전압 철근 콘크리트 관) (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 중간
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', '철근 콘크리트관(진동·롤 전압 성형)', 0, 900, 'ks', '진동 및 전압 철근 콘크리트관(제목 원문 미확인)', 'KS F 4402', 'https://www.standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSF4402', '진동 코어·롤로 내면을 진동 또는 롤 전압해 만든 철근 콘크리트관(공식 표준명: 진동 및 전압 철근 콘크리트 관) (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(e나라표준인증 상세 페이지 직접 열람) · 사용자 인정 2026-11-05', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 진동 및 전압 철근 콘크리트관(제목 원문 미확인) (KS F 4402)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 배수 및 하수용 비압력 매설용 구조형 폴리에틸렌(PE)관 — 제1부: 이중벽관 (KS M 3500-1)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 배수 및 하수용 비압력 매설용 구조형 폴리에틸렌(PE)관 — 제1부: 이중벽관
번호: KS M 3500-1
발행: 국가기술표준원
관련 자재: PE 이중벽관(하수·배수용)
적용 범위(검색 기준): 비압력 조건에서 매설하는 배수·하수용 구조형 PE 이중벽관(관벽은 외압에 견디는 구조형, 관 내·외면 평활) (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2023-11-14
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2023-11-14 · 적용 범위: 비압력 조건 매설용 배수·하수용 구조형 PE 이중벽관 — 관벽은 외압에 견디는 구조형, 관 내·외면 평활. 원료 특성·치수·공칭 원강성 등급·매설 시 물성 규정 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PE 이중벽관(하수·배수용)', 0, 900, 'ks', '배수 및 하수용 비압력 매설용 구조형 폴리에틸렌(PE)관 — 제1부: 이중벽관', 'KS M 3500-1', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSM3500-1&tmprKsNo=KSM3500-1&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '비압력 조건에서 매설하는 배수·하수용 구조형 PE 이중벽관(관벽은 외압에 견디는 구조형, 관 내·외면 평활) (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(e나라표준인증 상세 페이지)', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 배수 및 하수용 비압력 매설용 구조형 폴리에틸렌(PE)관 — 제1부: 이중벽관 (KS M 3500-1)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 배수 및 하수용 비압력 매설용 구조형 폴리염화비닐(PVC)관 — 이중벽관 및 리브관 (KS M 3600)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 배수 및 하수용 비압력 매설용 구조형 폴리염화비닐(PVC)관 — 이중벽관 및 리브관
번호: KS M 3600
발행: 국가기술표준원
관련 자재: PVC 이중벽관·리브관(하수·배수 매설용)
적용 범위(검색 기준): 비압력 조건에서 매설하는 배수·하수용 구조형 PVC관 중 이중벽관·리브관(관벽은 외압에 견디는 구조형, 내면 평활) (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 확인 2021-09-24
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 확인 2021-09-24 · 적용 범위: 비압력 조건 매설용 배수·하수용 구조형 PVC관 중 이중벽관·리브관 — 관벽은 외압에 견디는 구조형, 내면 평활. 원료 특성·치수·공칭 원강성·매설 시 물성 규정, 색상 추천 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PVC 이중벽관·리브관(하수·배수 매설용)', 0, 900, 'ks', '배수 및 하수용 비압력 매설용 구조형 폴리염화비닐(PVC)관 — 이중벽관 및 리브관', 'KS M 3600', 'https://standard.go.kr', '비압력 조건에서 매설하는 배수·하수용 구조형 PVC관 중 이중벽관·리브관(관벽은 외압에 견디는 구조형, 내면 평활) (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(e나라표준인증 상세 페이지)', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 배수 및 하수용 비압력 매설용 구조형 폴리염화비닐(PVC)관 — 이중벽관 및 리브관 (KS M 3600)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 비압력용 경질 폴리염화비닐관(구 일반용 경질 폴리염화비닐관) (KS M 3404)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 비압력용 경질 폴리염화비닐관(구 일반용 경질 폴리염화비닐관)
번호: KS M 3404
발행: 국가기술표준원
관련 자재: PVC관(배수·통기·건물 외부 비압력)
적용 범위(검색 기준): 건물 안 비압력 환기·통기·배기·배수 배관과 건물 밖 비압력 용도의 경질 PVC관(일반 특성) (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2021-07-07
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2021-07-07 · 적용 범위: 건물 안 비압력 환기·통기·배기·배수 배관과 건물 밖 비압력 용도의 경질 PVC관(일반 특성) (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PVC관(배수·통기·건물 외부 비압력)', 0, 900, 'ks', '비압력용 경질 폴리염화비닐관(구 일반용 경질 폴리염화비닐관)', 'KS M 3404', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSM3404&menuId=919&pageIndex=1&pageUnit=10&reformNo=19&tmprKsNo=KSM3404&topMenuId=502&upperMenuId=503', '건물 안 비압력 환기·통기·배기·배수 배관과 건물 밖 비압력 용도의 경질 PVC관(일반 특성) (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(e나라표준인증 상세 페이지 직접 열람) · 사용자 인정 2026-11-05', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 비압력용 경질 폴리염화비닐관(구 일반용 경질 폴리염화비닐관) (KS M 3404)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 비압력용 경질 폴리염화비닐 이음관 (KS M 3410)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 비압력용 경질 폴리염화비닐 이음관
번호: KS M 3410
발행: 국가기술표준원
관련 자재: PVC 이음관
적용 범위(검색 기준): 비압력용 경질 PVC관을 접착 접합할 때 쓰는 이음관 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2021-07-07
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2021-07-07 · 적용 범위: 비압력용 경질 PVC관을 접착 접합할 때 쓰는 이음관 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PVC 이음관', 0, 900, 'ks', '비압력용 경질 폴리염화비닐 이음관', 'KS M 3410', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010132913', '비압력용 경질 PVC관을 접착 접합할 때 쓰는 이음관 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(e나라표준인증 상세 페이지 직접 열람) · 사용자 인정 2026-11-05', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 비압력용 경질 폴리염화비닐 이음관 (KS M 3410)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 수도용 경질 폴리염화비닐관 (KS M 3401)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 수도용 경질 폴리염화비닐관
번호: KS M 3401
발행: 국가기술표준원
관련 자재: PVC관(수도용)
적용 범위(검색 기준): 유체 수송용 수도·배수·하수·우수 용도의 압력용 경질 PVC관(공식 표준명: 압력용 경질 폴리염화비닐관 — 목록의 수도용은 이전 명칭) (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2021-07-07
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2021-07-07 · 적용 범위: 유체 수송용 수도·배수·하수·우수 용도의 압력용 경질 PVC관(공식 표준명: 압력용 경질 폴리염화비닐관 — 목록의 수도용은 이전 명칭) (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PVC관(수도용)', 0, 900, 'ks', '수도용 경질 폴리염화비닐관', 'KS M 3401', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010123794', '유체 수송용 수도·배수·하수·우수 용도의 압력용 경질 PVC관(공식 표준명: 압력용 경질 폴리염화비닐관 — 목록의 수도용은 이전 명칭) (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(e나라표준인증 상세 페이지 직접 열람) · 사용자 인정 2026-11-05', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 수도용 경질 폴리염화비닐관 (KS M 3401)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 파형 강관 및 파형 섹션 (KS D 3590)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 공식 확인 전(검색 후보)
문서명: 파형 강관 및 파형 섹션
번호: KS D 3590
발행: 국가기술표준원
관련 자재: 파형강관(스파이럴형), 파형섹션, 볼트·커플링밴드
적용 범위(검색 기준): 수로·통로·각종 구조물에 쓰는 파형강관·파형섹션과 연결용 볼트·너트·와셔·커플링 밴드 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2025-10-01
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2025-10-01 · 적용 범위: 수로·통로·각종 구조물에 쓰는 파형강관·파형섹션과 연결용 볼트·너트·와셔·커플링 밴드(공장 제작 스파이럴형 관, 현장 조립용 섹션 포함) (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 높음
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', '파형강관(스파이럴형), 파형섹션, 볼트·커플링밴드', 0, 900, 'ks', '파형 강관 및 파형 섹션', 'KS D 3590', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSD3590&tmprKsNo=KSD3590&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '수로·통로·각종 구조물에 쓰는 파형강관·파형섹션과 연결용 볼트·너트·와셔·커플링 밴드 (e나라표준인증 적용 범위, 사용자 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', '사용자(e나라표준인증 상세 페이지)', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 파형 강관 및 파형 섹션 (KS D 3590)');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at, effective_date, revised_date)
SELECT '[KS] 점토 벽돌 (KS L 4201)', '[공식 메타데이터 확인 — AI 상담 미사용(검토 전)]
AI 적용 범위: 불가 — 적용 대상 다름
문서명: 점토 벽돌
번호: KS L 4201
발행: 국가기술표준원
관련 자재: 점토블록(참고 — 포장용 전용 표준 아님)
적용 범위(검색 기준): 점토·고령토·황토 등을 원료로 반죽·성형·건조·소성해 만든 벽돌 — 포장용 점토블록에 그대로 적용되는지는 표준 본문 확인 필요 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)
시행·개정일(검색 기준, 미확인): 최종 개정 2022-12-09
2-1 판정: 공식 메타데이터 확인(e나라표준인증 상세 — 개정일·적용 범위) — 표준명·최종 개정 2022-12-09 · 적용 범위: 점토·고령토·황토 등을 원료로 반죽·성형·건조·소성해 만든 벽돌 — 포장용 점토블록에 그대로 적용되는지는 표준 본문 확인 필요 (본문 수치 미확인 — KS 원문은 유료)
검색 근거 신뢰도: 중간
확인 수준: metadata_confirmed — 웹 검색 결과(제목·요약)로만 존재 추정. 공식 원문 미열람.
확인 URL: 없음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점토블록(참고 — 포장용 전용 표준 아님)', 0, 900, 'ks', '점토 벽돌', 'KS L 4201', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSL4201&tmprKsNo=KSL4201&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '점토·고령토·황토 등을 원료로 반죽·성형·건조·소성해 만든 벽돌 — 포장용 점토블록에 그대로 적용되는지는 표준 본문 확인 필요 (e나라표준인증 적용 범위, 공식 상세 확인 2026-11-05)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed', 'metadata_confirmed', 'Claude(e나라표준인증 상세 페이지 직접 열람) · 사용자 인정 2026-11-05', '2026-11-05', NULL, NULL FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND title = '[KS] 점토 벽돌 (KS L 4201)');

-- [2] 검증 조문 43건 (author = '검증 조문(2-1단계)', 초안 06 과 같은 값 · V21·V22 포함 전부 비활성)
-- V01
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 주차장법 시행규칙 제3조제1항제2호 (주차장의 주차구획 — 평행주차형식 외)', '요지: 평행주차형식 외 주차단위구획: 경형 너비 2.0m·길이 3.6m 이상, 일반형 2.5m·5.0m 이상, 확장형 2.6m·5.2m 이상, 장애인전용 3.3m·5.0m 이상. (평행주차형식 일반형은 2.0m·6.0m 이상 — 제1호)
적용 조건: 주차장법상 주차장(노상·노외·부설주차장)의 주차단위구획 기준(법 제6조제1항) / 지방자치단체 조례로 달리 정할 수 있음(주차장법 제6조제2항) / 부설주차장 = 건축물 등에 부대하여 설치된 주차장(법 제2조제1호다목). 도시지역 등에서 건축물을 건축할 때 설치 의무(법 제19조제1항)
주의: 단순한 마당·진입로 포장 자체를 법적 의무로 단정하지 않음 — 관할 시·군·구 확인 필요
상담 안내 예: "주차장법 시행규칙 제3조에 따라 일반형 주차 1칸은 너비 2.5m·길이 5.0m 이상(약 12.5㎡)입니다. 집 마당이 법상 부설주차장에 해당하는지에 따라 의무 여부가 달라지니 공사 전 관할 시·군·구에 확인하세요."
확인: 사용자 국가법령정보센터 원문 확인(2026-10-27) + legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) 표 대조 일치 · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 주차장법 시행규칙 01604·시행 2026-07-27 조문 일치 · 2026-10-28', 'pavement', '주차,주차장,주차칸,주차공간,주차구획,차 한 대,차 두 대,주차면', 0, 800, 'law', '주차장법 시행규칙 (국토교통부령 제1604호)', '제3조제1항제2호 (주차장의 주차구획 — 평행주차형식 외)', 'https://www.law.go.kr/법령/주차장법시행규칙/제3조', '2026-07-27', '2026-07-27', '주차장법에 따른 주차장. 개인 주택 마당이 법상 부설주차장인지(건축 허가 시 설치한 주차장인지)에 따라 의무 여부가 달라짐', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 사이트) + Claude(미러 대조) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 주차장법 시행규칙 제3조제1항제2호 (주차장의 주차구획 — 평행주차형식 외)');
-- V02
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 주차장법 시행규칙 제11조제5항 (총주차대수 8대 이하 자주식 부설주차장)', '요지: 8대 이하 부설주차장: 차로 너비 2.5m 이상(주차단위구획에 접한 차로는 평행 3.0m·직각 6.0m·60도 4.0m·45도 3.5m·교차 3.5m 이상), 출입구 너비 3m 이상(막다른 도로로서 시장 등이 인정하면 2.5m 이상), 보행 통로 필요 시 시설물과 주차단위구획 사이 0.5m 이상.
적용 조건: 건축물 부설주차장 중 총주차대수 8대 이하 자주식주차장 / 지자체 조례로 달리 정할 수 있음(법 제6조제2항)
주의: 부설주차장에 해당하지 않는 마당 포장에는 의무로 안내하지 않음
상담 안내 예: "건축물에 딸린 8대 이하 부설주차장이라면 출입구 너비 3m 이상(주차장법 시행규칙 제11조제5항) 기준이 있습니다. 해당 여부는 관할 시·군·구에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 주차장법 시행규칙 01604·시행 2026-07-27 조문 일치 · 2026-10-28', 'pavement', '진입로,출입구,주차장 입구,차고,부설주차장,주차장 폭', 0, 800, 'law', '주차장법 시행규칙 (국토교통부령 제1604호)', '제11조제5항 (총주차대수 8대 이하 자주식 부설주차장)', 'https://www.law.go.kr/법령/주차장법시행규칙/제11조', '2026-07-27', '2026-07-27', '소규모 건축물 부설주차장(주택 포함 가능)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 주차장법 시행규칙 제11조제5항 (총주차대수 8대 이하 자주식 부설주차장)');
-- V03
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제16조제2항제1호·제3항 (보도)', '요지: 보도와 차도를 구분할 때 차도에 접하여 연석을 설치하는 경우 그 높이는 25cm 이하. 보도의 유효폭은 최소 2m 이상(지방지역 도로·도시지역 국지도로에서 불가피한 경우 1.5m 이상).
적용 조건: 「도로법」에 따른 도로를 신설·개량하는 경우의 최소 기준(제1조) / 연석 높이 측정 기준(노출 높이 등)은 원문·설계도로 확인
주의: 사유지 마당·화단 경계석에는 법적 의무로 안내하지 않음
상담 안내 예: "공공 도로에서 차도에 접한 연석(경계석)은 높이 25cm 이하로 설치하도록 정해져 있습니다(도로의 구조·시설 기준에 관한 규칙 제16조). 개인 부지 공사는 이 규칙의 직접 적용 대상이 아닙니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 도로의 구조ㆍ시설 기준에 관한 규칙 01588·시행 2026-06-03 조문 일치 · 2026-10-28', 'pavement', '연석,경계석,보도,인도,보도 폭,인도 폭,턱 높이', 0, 800, 'law', '도로의 구조ㆍ시설 기준에 관한 규칙 (국토교통부령 제1588호)', '제16조제2항제1호·제3항 (보도)', 'https://www.law.go.kr/법령/도로의구조ㆍ시설기준에관한규칙/제16조', '2026-06-03', '2026-06-02', '도로법상 도로(공공 도로) 공사', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제16조제2항제1호·제3항 (보도)');
-- V04
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제29조제1항 (포장)', '요지: 차로·측대·길어깨·보도·자전거도로는 안정성·시공성 등을 고려하여 적절한 재료와 두께로 포장. 블록 두께 수치는 규정하지 않음.
적용 조건: 도로법상 도로 공사
주의: 블록 두께(60T·80T 등)의 법적 근거로 인용 금지 — 수치 없음
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 도로의 구조ㆍ시설 기준에 관한 규칙 01588·시행 2026-06-03 조문 일치 · 2026-10-28', 'pavement', NULL, 0, 800, 'law', '도로의 구조ㆍ시설 기준에 관한 규칙 (국토교통부령 제1588호)', '제29조제1항 (포장)', 'https://www.law.go.kr/법령/도로의구조ㆍ시설기준에관한규칙/제29조', '2026-06-03', '2026-06-02', '공공 도로', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제29조제1항 (포장)');
-- V05
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제30조제1항·제3항 (배수시설)', '요지: 도로에는 측구·집수정·도수로 등 적절한 배수시설을 설치하고, 규격은 강우 지속시간·강도·지형에 따라 결정. 길어깨에 붙여 측구를 설치하는 경우 교통안전을 위해 윗면이 열린 측구를 설치하지 않음.
적용 조건: 도로법상 도로 공사 / "윗면이 열린 측구 금지"는 길어깨에 붙여 설치하는 경우
주의: 개인 부지 배수로에 의무로 안내하지 않음
상담 안내 예: "공공 도로의 길어깨에 붙여 설치하는 측구는 윗면이 열린 형태로 두지 않도록 정해져 있어(도로의 구조·시설 기준에 관한 규칙 제30조), 덮개나 그레이팅을 함께 씁니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 도로의 구조ㆍ시설 기준에 관한 규칙 01588·시행 2026-06-03 조문 일치 · 2026-10-28', 'culvert', '측구,배수로,도로 배수,길어깨,덮개,뚜껑,집수정', 0, 800, 'law', '도로의 구조ㆍ시설 기준에 관한 규칙 (국토교통부령 제1588호)', '제30조제1항·제3항 (배수시설)', 'https://www.law.go.kr/법령/도로의구조ㆍ시설기준에관한규칙/제30조', '2026-06-03', '2026-06-02', '공공 도로', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제30조제1항·제3항 (배수시설)');
-- V06
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 도로와 다른 시설의 연결에 관한 규칙 제2조제11호·제3조·제9조제3호·제4호 (연석 정의, 적용 범위, 배수시설)', '요지: 연석 = 보도 등과 차도의 경계선에 연접하여 설치하는 경계석. 일반국도에 다른 시설을 연결하는 경우 연결 시설의 오수·빗물이 도로로 흐르지 않도록 별도 배수시설을 두며, 격자형 철제 뚜껑이 있는 유효 폭 30cm 이상·유효 깊이 60cm 이상의 U형 콘크리트 측구로 함. 기존 배수시설이 매립되면 더 큰 규격(또는 같은 단면) 배수관을 두고 20m 이내 간격으로 뚜껑 있는 맨홀 설치.
적용 조건: 「도로법」상 일반국도에 차량 진행 방향 우측으로 다른 시설(진입로 등)을 연결하는 경우(제3조) / 그 밖의 연결은 「도로의 구조·시설 기준에 관한 규칙」에 따름
주의: 일반 사유지 내부 배수로에는 의무로 안내하지 않음
상담 안내 예: "일반국도에 진입로를 연결하는 공사라면 격자형 철제 뚜껑이 있는 U형 콘크리트 측구(유효 폭 30cm·깊이 60cm 이상)를 두도록 정해져 있습니다(도로와 다른 시설의 연결에 관한 규칙 제9조). 국도 연결이 아니면 해당하지 않습니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 도로와 다른 시설의 연결에 관한 규칙 00882·시행 2021-08-27 조문 일치 · 2026-10-28', 'culvert', '국도,진입로 연결,도로 연결,연결허가,U형 측구,측구,연석,가게 앞 진입로', 0, 800, 'law', '도로와 다른 시설의 연결에 관한 규칙 (국토교통부령 제882호)', '제2조제11호·제3조·제9조제3호·제4호 (연석 정의, 적용 범위, 배수시설)', 'https://www.law.go.kr/법령/도로와다른시설의연결에관한규칙/제9조', '2021-08-27', '2021-08-27', '일반국도 연결허가 공사(사업장·주유소 진입로 등)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 도로와 다른 시설의 연결에 관한 규칙 제2조제11호·제3조·제9조제3호·제4호 (연석 정의, 적용 범위, 배수시설)');
-- V07
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 하수도법 제27조제1항·제3항·제5항·제9항, 제2조제12호·제14호 (배수설비)', '요지: 공공하수도 사용이 개시되면 배수구역 안 토지(시설물)의 소유자·관리자는 하수를 공공하수도로 유입시키고 필요한 배수설비를 설치해야 함. 설치 시 공공하수도관리청에 신고, 완료 후 조례에 따른 준공검사, 유지·관리는 설치자(토지 경계~공공하수도 구간은 관리청이 할 수 있음).
적용 조건: 공공하수도 배수구역(법 제15조로 공고된 구역) 안의 토지·건물 / 세부 절차는 지방자치단체 조례
주의: 배수구역 밖(정화조 등 개인하수처리시설 지역)은 별도 기준
상담 안내 예: "공공하수도 배수구역 안이라면 건물·토지 소유자가 배수설비를 설치하고 관할 지자체에 신고·준공검사를 받아야 합니다(하수도법 제27조). 연결 방법은 관할 지자체 하수도 부서에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 하수도법 21065·시행 2025-10-01 조문 일치 · 2026-10-28', 'pe_pipe', '배수설비,하수관 연결,하수도 연결,오수관,우수관,집 하수관,배수 신고', 0, 800, 'law', '하수도법 (법률 제21065호)', '제27조제1항·제3항·제5항·제9항, 제2조제12호·제14호 (배수설비)', 'https://www.law.go.kr/법령/하수도법/제27조', '2025-10-01', '2025-10-01', '배수구역 안의 개인 주택·건물 포함', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 하수도법 제27조제1항·제3항·제5항·제9항, 제2조제12호·제14호 (배수설비)');
-- V08
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 하수도법 시행규칙 제23조제1항·제2항 (배수설비의 설치기준 및 구조기준)', '요지: 배수설비는 공공하수도 또는 다른 배수설비에 연결하고, 철근콘크리트·경질염화비닐·도기 그 밖에 내구성과 내부식성이 있는 자재를 사용해 수밀구조로 하며, 분류식 하수관로에 연결하는 배수설비는 오수와 우수가 분리되어 흐르는 구조로 함(제23조). 별표 7(개정 2019. 12. 20.) — ① 오수관 지름은(공공하수도관리청이 특별한 사유가 있다고 인정하는 경우 외, ②도 같음) 배수 인구 150명 이하 100mm 이상, 300명 이하 150mm 이상, 600명 이하 200mm 이상, 1,000명 이하 250mm 이상(오수 일부 배제용 총 길이 3m 미만 지관은 75mm 가능). ② 합류관·우수관 지름은 배수면적 200㎡ 미만 100mm 이상, 600㎡ 미만 150mm 이상, 1,200㎡ 미만 200mm 이상, 1,200㎡ 이상은 관의 지름 또는 개수를 늘림(우수 지관 총 길이 3m 미만은 75mm 가능). ③ 배수량이 특히 많은 장소는 배수량(㎥) 1,000 미만 150mm 이상, 2,000 미만 200mm 이상, 4,000 미만 250mm 이상, 6,000 미만 300mm 이상, 6,000 이상은 관의 지름 또는 개수를 늘림. ④ 배수관 경사는 관 안 유속이 초당 0.6~1.5m가 되도록. ⑤ 배수관·배수거의 기점·종점·합류점·굴곡점과 안지름·안 폭·관 종류가 달라지는 곳에 물받이, 직선 부분은 안지름(안 폭)의 120배 이하 간격으로 물받이(합류점·굴곡점에 설치가 곤란하면 청소구). ⑥ 고형물질 유입구에는 유효간격 10mm 이하 스크린, 유지류 유입구에는 유지차단장치, 토사가 많은 유입구에는 모래받이, 배수관·배수거의 필요한 부분에는 악취방지트랩.
적용 조건: 하수도법 제27조에 따른 배수설비 / 「건축법」 등 다른 법령에 규정이 있으면 그에 따름(법 제27조제10항) / 별표 7 의 관 지름은 공공하수도관리청이 특별한 사유를 인정하면 달리 정할 수 있음 — 관할 지자체 하수도 조례·관리청 확인 / 배수 인구·배수면적은 고객이 알려 준 값으로 표를 읽어 주는 것만(대신 산정하지 않음)
주의: 배수 인구·면적을 짐작해 관경을 단정하지 않음. 공공하수도 관로 최소관경(KDS 61 40 00: 오수 200mm)과 섞지 않음
상담 안내 예: "건물·마당의 배수설비는 하수도법 시행규칙 제23조·별표 7에 따라 철근콘크리트·PVC 등 물이 새지 않는 관으로 하고, 빗물·합류 배수관은 배수면적 200㎡ 미만이면 지름 100mm 이상, 600㎡ 미만이면 150mm 이상입니다. 관이 꺾이거나 합쳐지는 곳에는 물받이를 두고, 관할 지자체가 따로 정한 기준이 있으면 그걸 따르세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 하수도법 시행규칙 00042·시행 2026-06-22 조문 일치 · 2026-11-05 별표 7 공식 파일(law.go.kr flSeq=165912267 — 현행판 제42호 첨부, <개정 2019. 12. 20.>, 2026-11-05 공식 API 일치 판) 원문 확보·대조(Claude) · 2026-11-05', 'pe_pipe', '배수설비,배수관,하수관,PVC관,오수,우수,분류식,수밀,물받이 간격,물받이 위치,청소구,악취방지트랩,유지차단,배수관 경사,오수관 크기는,오수관 관경은,우수관 크기는,우수관 관경은,배수관 크기는,배수관 관경은,배수관 굵기는,관경은 얼마,관경을 얼마', 0, 800, 'law', '하수도법 시행규칙 (기후에너지환경부령 제42호)', '제23조제1항·제2항 (배수설비의 설치기준 및 구조기준)', 'https://www.law.go.kr/법령/하수도법시행규칙/제23조', '2026-06-22', '2026-06-22', '배수구역 안 건물·토지의 배수설비', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행) · 별표 7 공식 파일 대조(Claude, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 하수도법 시행규칙 제23조제1항·제2항 (배수설비의 설치기준 및 구조기준)');
-- V09
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 하수도법 시행령 하수도법 제12조제3항 + 시행령 제10조제2항 (하수도용 자재)', '요지: 하수도의 설치에 사용되는 하수도용 자재는 산업표준화법에 따른 KS 인증 제품, 우수 단체표준제품, 한국상하수도협회 인증 단체표준제품, 환경신기술·환경표지·신제품·신기술 지정 제품 등 시행령 제10조제2항 각 호 중 하나의 품질·성능을 가져야 함.
적용 조건: 하수도 설치 공사(주로 공공하수도 설치 기준 조항에 위치 — 개인 배수설비 적용 범위는 관할 지자체 확인)
주의: 특정 상품이 인증을 받았다고 단정 금지 — 상품별 인증서 확인 필요
상담 안내 예: "하수도 공사에 쓰는 관·자재는 KS 인증 등 하수도법 시행령 제10조제2항의 인증을 받은 제품이어야 합니다. 상품별 인증 여부는 견적 시 확인해 드립니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 하수도법 21065·시행 2025-10-01 조문 일치, 하수도법 시행령 36338·시행 2026-06-03 조문 일치 · 2026-10-28', 'pe_pipe', '하수도용,KS 인증,KS인증,인증 제품,하수관 자재,하수도 공사', 0, 800, 'law', '하수도법 시행령 (대통령령 제36338호)', '하수도법 제12조제3항 + 시행령 제10조제2항 (하수도용 자재)', 'https://www.law.go.kr/법령/하수도법시행령/제10조', '2026-06-03', '2026-05-19', '하수도 설치에 쓰는 관·자재', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 하수도법 시행령 하수도법 제12조제3항 + 시행령 제10조제2항 (하수도용 자재)');
-- V10
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 하수관로 유지관리 기준 제3조·제5조제1항·제6조제3항', '요지: 침수우려지역(하수도정비중점관리지역 등)의 하수관로 유지관리 기준. 공공하수도관리청은 빗물받이·측구 등이 정상 기능을 유지하도록 유입구 청소 및 불법덮개 등이 설치되지 않도록 상시 관리해야 함.
적용 조건: 적용 지역: 하수도정비중점관리지역, 하수 침수위험 자연재해위험개선지구, 침수 피해(우려) 지역(제3조) / 의무 주체는 공공하수도관리청(지자체)
주의: 개인 부지 안의 배수구·트렌치 덮개에는 해당 없음
상담 안내 예: "도로의 공공 빗물받이·측구에 임의로 덮개를 설치하면 관리청의 관리(제거) 대상이 될 수 있습니다(하수관로 유지관리 기준 제6조). 공공시설이면 설치 전 관할 지자체에 확인하세요. 집 안 배수구 덮개는 해당하지 않습니다."
확인: legalize-kr/admrule-kr (법제처 국가법령정보 Open API 행정규칙 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 하수관로 유지관리 기준 2025-165·시행 2025-10-01 조문 일치 · 2026-10-28', 'manhole', '빗물받이,빗물받이 덮개,배수구 덮개,악취,냄새,오물망,낙엽,하수구 덮개', 0, 800, 'law', '하수관로 유지관리 기준 (기후에너지환경부고시 제2025-165호)', '제3조·제5조제1항·제6조제3항', 'https://www.law.go.kr/행정규칙/하수관로유지관리기준', '2025-10-01', '2025-10-01', '공공 하수관로·빗물받이(지자체 관리 시설)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 하수관로 유지관리 기준 제3조·제5조제1항·제6조제3항');
-- V11
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 법 제7조·제8조 + 시행규칙 제2조제1항', '요지: 편의시설(점자블록 등) 설치 대상시설은 공원, 공공건물 및 공중이용시설, 공동주택, 통신시설, 그 밖에 필요한 건물·시설로서 대통령령으로 정하는 것. 시설 종류는 대통령령, 구조·재질 세부기준은 보건복지부령 별표 1(미확보).
적용 조건: 대상시설 해당 여부는 시행령 별표 1로 판단(미확보) / 점자블록 크기·색상 등 세부기준은 시행규칙 별표 1(미확보) — 수치 안내 금지 / 제7조·제8조 문구는 현재 시행판과 시행 예정판(2026-11-12·2027-05-27 시행)이 동일함을 대조
주의: 점자블록 규격 수치·설치 위치를 이 근거로 단정하지 않음
상담 안내 예: "점자블록 설치 의무는 장애인등편의법 제7조의 대상시설(공원·공공건물·공중이용시설·공동주택 등)에 적용됩니다. 세부 규격은 법령 별표를 확인 중이라 현장 설계도·발주처 기준을 따르세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) — 법률은 HEAD가 시행 예정판이라 현재 시행판(커밋 0d4040acd)과 조문 대조 · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 20594·시행 2025-12-21 조문 일치, 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 01166·시행 2026-07-02 조문 일치 · 2026-10-28', 'pavement', '점자블록,점자 블록,장애인 블록,유도블록,편의시설', 0, 800, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 (법률 (현재 시행판: 2024-12-20 공포, 2025-12-21 시행))', '법 제7조·제8조 + 시행규칙 제2조제1항', 'https://www.law.go.kr/법령/장애인ㆍ노인ㆍ임산부등의편의증진보장에관한법률/제7조', '2025-12-21', '2024-12-20', '법 제7조 대상시설', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 법 제7조·제8조 + 시행규칙 제2조제1항');
-- V12
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 보행안전 및 편의증진에 관한 법률 시행규칙 제5조제1항·제2항', '요지: 보행환경개선지구 안의 도로에 설치할 수 있는 시설: 보도용 방호울타리, 조명시설, 장애인용 음향안내시설, 영상정보처리기기, 자동차 진입억제용 말뚝, 점자블록. 구조·기준은 별표 1(미확보).
적용 조건: 보행환경개선지구 안의 도로(지자체 지정) / 말뚝(볼라드) 높이·간격 등 수치는 별표 1 미확보 — 안내 금지
주의: 사유지 볼라드 설치 의무로 안내하지 않음
상담 안내 예: "보행환경개선지구 도로에는 자동차 진입억제용 말뚝(볼라드)·점자블록 등을 설치할 수 있도록 정해져 있습니다(보행안전법 시행규칙 제5조). 규격 기준은 확인 중입니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 보행안전 및 편의증진에 관한 법률 시행규칙 00344·시행 2022-07-12 조문 일치 · 2026-10-28', NULL, '볼라드,말뚝,차량 진입 막기,진입 방지,차 못 들어오게', 0, 800, 'law', '보행안전 및 편의증진에 관한 법률 시행규칙 (행정안전부령 제344호)', '제5조제1항·제2항', 'https://www.law.go.kr/법령/보행안전및편의증진에관한법률시행규칙/제5조', '2022-07-12', '2022-07-12', '보행환경개선지구 도로', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 보행안전 및 편의증진에 관한 법률 시행규칙 제5조제1항·제2항');
-- V13
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 보행안전 및 편의증진에 관한 법률 시행규칙 [별표 1] 제10호 (자동차 진입억제용 말뚝)', '요지: 자동차 진입억제용 말뚝(볼라드): 높이 80~100cm 내외, 지름 10~20cm 내외, 간격 1.5m 내외. 밝은 색 반사도료 등으로 쉽게 식별, 보행자 충격을 흡수하는 재료로 하되 저속 차량 충격에 견디는 구조. 말뚝 0.3m 앞에 점형블록 설치. 보행자 통행에 방해되지 않는 범위에서 설치.
적용 조건: 시행규칙 제5조제2항에 따른 보행안전 및 편의증진 시설의 구조·기준(V12) — 보행자길에 지방자치단체 등이 설치하는 시설 / 수치는 "내외" 표현 — 현장 여건에 따른 범위
주의: 개인 사유지 볼라드 설치를 법적 의무처럼 말하지 않음
상담 안내 예: "보행안전법 시행규칙 별표 1 기준으로 보행자길의 차량 진입억제 말뚝(볼라드)은 높이 80~100cm, 지름 10~20cm, 간격 1.5m 내외이고, 앞쪽 0.3m에 점형블록을 둡니다. 개인 부지라면 의무는 아니지만 이 기준을 참고하시면 됩니다."
확인: 국가법령정보센터 별표 원문 PDF(사용자 내려받음, 2026-10-28) 전문 대조 — 공포번호·시행일은 법제처 OPEN API 대조(2026-10-28) 일치 · 2026-10-28', NULL, '볼라드,볼라드 간격,볼라드 높이,말뚝 간격,진입억제 말뚝,차막이,차량 진입 억제', 0, 800, 'law', '보행안전 및 편의증진에 관한 법률 시행규칙 (행정안전부령 제344호)', '[별표 1] 제10호 (자동차 진입억제용 말뚝)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=116981969', '2022-07-12', '2022-07-12', '보행안전법에 따라 보행자길에 설치하는 보행안전시설(공공). 사유지 마당·주차장에 설치하는 볼라드는 의무 대상이 아님 — 참고 기준으로 안내', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 보행안전 및 편의증진에 관한 법률 시행규칙 [별표 1] 제10호 (자동차 진입억제용 말뚝)');
-- V14
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 보행안전 및 편의증진에 관한 법률 시행규칙 [별표 1] 제1호다목 (과속방지턱)', '요지: 과속방지턱을 설치하는 경우 설치 길이 3.6m, 설치 높이 10cm 규격을 적용. 다만 폭 6m 미만의 좁은 도로 등 설치장소 특성에 따라 길이·높이를 다르게 할 수 있음. 낮은 주행속도가 요구되는 도로구간·생활공간·학교지역 등에 설치할 수 있음.
적용 조건: 보행안전법상 보행안전시설로 설치하는 경우(시행규칙 제5조제2항) / 폭 6m 미만 도로 등은 길이·높이 조정 가능
주의: 사유지 방지턱 규격을 법적 의무로 단정하지 않음. 고무 방지턱 제품 규격(조립식)과 혼동하지 않음
상담 안내 예: "보행안전법 시행규칙 별표 1은 과속방지턱을 길이 3.6m·높이 10cm로 정하고, 폭 6m 미만 좁은 도로 등은 달리 할 수 있게 합니다. 공공 도로 기준이라 단지·주차장 안이라면 참고 기준입니다."
확인: 국가법령정보센터 별표 원문 PDF(사용자 내려받음, 2026-10-28) 전문 대조 — 공포번호·시행일은 법제처 OPEN API 대조(2026-10-28) 일치 · 2026-10-28', NULL, '과속방지턱,방지턱,과속 방지,속도 저감,험프', 0, 800, 'law', '보행안전 및 편의증진에 관한 법률 시행규칙 (행정안전부령 제344호)', '[별표 1] 제1호다목 (과속방지턱)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=116981969', '2022-07-12', '2022-07-12', '보행안전법에 따른 차량 속도 저감시설(공공 도로). 사유지 주차장·단지 내 방지턱은 의무 대상 아님 — 참고 기준', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 보행안전 및 편의증진에 관한 법률 시행규칙 [별표 1] 제1호다목 (과속방지턱)');
-- V15
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 [별표 1] 제16호 (점자블록 — 규격·색상·설치방법)', '요지: 점자블록은 감지용 점형블록과 유도용 선형블록 사용. 크기 0.3m×0.3m 표준, 높이는 바닥재와 동일. 점형블록 돌출점 36개 표준·높이 0.6±0.1cm(반구형·원뿔절단형·혼합), 선형블록 돌출선 4개 표준·높이 0.5±0.1cm(상단부 평면형). 색상은 황색 원칙(바닥재와 구별 어려우면 다른 색). 실외는 반사되거나 미끄러운 재질 금지. 설치: 위험 장소·계단 등 0.3m 전면과 선형블록 시작·교차·굴절 지점에 점형블록, 주출입구로 연결된 접근로에 선형블록 연속 설치. 매립식 원칙(곤란하면 부착식).
적용 조건: 법 제7조 대상시설에 설치하는 편의시설의 세부기준(시행규칙 제2조제1항, V11) / 별표 1 최종 개정 2023-12-11(현행 시행규칙 제1166호에 포함)
주의: 모든 건물·사유지에 의무라고 단정하지 않음 — 대상시설 여부 확인
상담 안내 예: "장애인등편의법 시행규칙 별표 1 기준 점자블록은 30×30cm 표준, 점형은 돌출점 36개(높이 0.6±0.1cm), 선형은 돌출선 4개(0.5±0.1cm), 황색 원칙·매립식 원칙입니다. 대상시설이면 이 기준을 따라야 합니다."
확인: 국가법령정보센터 별표 원문 PDF(사용자 내려받음, 2026-10-28) 전문 대조 — 공포번호·시행일은 법제처 OPEN API 대조(2026-10-28) 일치 · 2026-10-28', 'pavement', '점자블록,점자 블록,점형블록,선형블록,유도블록,시각장애인 블록,점자블럭', 0, 800, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 (보건복지부령 제1166호)', '[별표 1] 제16호 (점자블록 — 규격·색상·설치방법)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=162983711', '2026-07-02', '2026-04-01', '장애인등편의법 대상시설(공공건물·공중이용시설·공동주택 등)의 점자블록. 일반 개인 주택은 대상이 아닐 수 있음', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 [별표 1] 제16호 (점자블록 — 규격·색상·설치방법)');
-- V16
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 [별표 1] 제1호 (장애인등의 통행이 가능한 접근로)', '요지: 접근로 유효폭 1.2m 이상(50m마다 1.5m×1.5m 교행구역 설치 가능). 기울기 1/18 이하(지형상 곤란하면 1/12까지). 대지 내 주접근로 단차 2cm 이하. 차도 경계에는 연석·울타리 등 설치(곤란하면 바닥 질감 차이), 연석 높이 6~15cm 가능. 바닥은 미끄럽지 않게 평탄 마감, 블록 포장은 이음새 틈이 벌어지지 않고 평탄하게 시공. 빠질 위험이 있는 곳의 덮개는 접근로와 같은 높이, 격자·틈새 간격 2cm 이하. 가로수는 2.1m까지 가지치기.
적용 조건: 법 제7조 대상시설의 접근로(대지 내) / 도로 보도의 연석 높이는 도로 구조 규칙(V03, 25cm 이하)이 별도
주의: 일반 개인 주택 마당에 의무라고 단정하지 않음
상담 안내 예: "장애인등편의법 대상시설이라면 접근로는 폭 1.2m 이상, 기울기 1/18 이하, 단차 2cm 이하로 하고, 배수구 덮개 틈은 2cm 이하여야 합니다(시행규칙 별표 1)."
확인: 국가법령정보센터 별표 원문 PDF(사용자 내려받음, 2026-10-28) 전문 대조 — 공포번호·시행일은 법제처 OPEN API 대조(2026-10-28) 일치 · 2026-10-28', 'pavement', '접근로,휠체어 통로,장애인 통로,무장애,배리어프리,휠체어 진입,그레이팅 틈,그레이팅 간격', 0, 800, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 (보건복지부령 제1166호)', '[별표 1] 제1호 (장애인등의 통행이 가능한 접근로)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=162983711', '2026-07-02', '2026-04-01', '장애인등편의법 대상시설의 대지 안 접근로(건물 출입구까지). 공공 도로 보도에는 도로 구조 규칙이 적용', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 [별표 1] 제1호 (장애인등의 통행이 가능한 접근로)');
-- V17
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 [별표 1] 제4호 (장애인전용주차구역)', '요지: 장애인전용주차구역 1대당 폭 3.3m 이상·길이 5m 이상(평행주차형식은 폭 2m 이상·길이 6m 이상). 바닥면은 승하차에 지장이 되는 높이차이가 없어야 하고 기울기 1/50 이하로 할 수 있음, 미끄럽지 않은 재질로 평탄 마감. 출입구까지 통로는 높이차이 없이 유효폭 1.2m 이상, 차로와 분리하고 교차 부분은 색상·질감을 다르게. 출입구·승강설비와 가장 가까운 곳에 설치.
적용 조건: 건축물 부설주차장 등 장애인등편의법 대상시설의 장애인전용주차구역 / 주차장법 시행규칙 제3조(V01) 장애인전용 3.3×5.0m 와 같은 값
주의: 개인 주택 주차 공간에 의무라고 단정하지 않음
상담 안내 예: "장애인전용주차구역은 1대에 폭 3.3m·길이 5m 이상, 바닥 기울기 1/50 이하로 평탄하게 시공합니다(장애인등편의법 시행규칙 별표 1). 일반 주차칸(2.5×5.0m)보다 넓게 잡아 포장 면적을 계산하세요."
확인: 국가법령정보센터 별표 원문 PDF(사용자 내려받음, 2026-10-28) 전문 대조 — 공포번호·시행일은 법제처 OPEN API 대조(2026-10-28) 일치 · 2026-10-28', 'pavement', '장애인주차,장애인 주차,장애인전용주차,장애인 주차구역,장애인주차구역', 0, 800, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 (보건복지부령 제1166호)', '[별표 1] 제4호 (장애인전용주차구역)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=162983711', '2026-07-02', '2026-04-01', '장애인등편의법 대상시설의 주차장(설치 의무 여부는 대상시설·주차대수에 따름)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 [별표 1] 제4호 (장애인전용주차구역)');
-- V18
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 [별표 1] 제12호 (경사로)', '요지: 경사로 유효폭 1.2m 이상(증축·개축 등으로 곤란하면 0.9m까지). 기울기 1/12 이하 — 기존시설·높이 1m 이하·상시 보조서비스 제공 요건을 모두 충족하면 1/8까지. 높이 0.75m 이내마다 수평 참, 시작·끝·굴절부·참에 1.5m×1.5m 이상 활동공간. 길이 1.8m 이상 또는 높이 0.15m 이상이면 양측 손잡이 연속 설치(끝부분 0.3m 이상 연장). 바닥은 미끄럽지 않게 평탄 마감, 양측 5cm 이상 추락방지턱 또는 측벽 설치 가능.
적용 조건: 장애인등편의법 대상시설의 경사로 / 1/8 완화는 세 요건 모두 충족 시에만
주의: 개인 주택 경사로에 의무라고 단정하지 않음
상담 안내 예: "장애인등편의법 대상시설의 경사로는 폭 1.2m 이상, 기울기 1/12 이하(높이 1m를 오르려면 길이 12m 이상)가 기준입니다(시행규칙 별표 1)."
확인: 국가법령정보센터 별표 원문 PDF(사용자 내려받음, 2026-10-28) 전문 대조 — 공포번호·시행일은 법제처 OPEN API 대조(2026-10-28) 일치 · 2026-10-28', 'pavement', '경사로,휠체어 경사로,슬로프,경사로 기울기,경사로 폭', 0, 800, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 (보건복지부령 제1166호)', '[별표 1] 제12호 (경사로)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=162983711', '2026-07-02', '2026-04-01', '장애인등편의법 대상시설의 건축물 경사로', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 [별표 1] 제12호 (경사로)');
-- V19
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 하수도법 시행규칙 [별표 5] 제6호·제7호·제8호 (공공하수도 맨홀·물받이·뚜껑)', '요지: 맨홀: 하수관로의 방향·경사·관경이 변하는 곳, 단차가 생기는 곳, 합류·분리되는 곳에 설치. 규격은 한국산업표준(KS)에 따르고, 현장 제작 맨홀은 기후에너지환경부장관 고시 표준도에 따름. 밑바닥에 인버트 설치. 오수받이: 안지름·안 폭 30cm 이상의 원형·각형, 벽돌·콘크리트·철근콘크리트 등 튼튼하고 물이 스며들지 않는 구조. 빗물받이: 간격 10m 이상 30m 이하(상습침수지역은 10m 미만 가능), 크기는 빗물이 원활히 유입되고 유지관리가 쉬운 크기. 맨홀·물받이 뚜껑은 주철제·철근콘크리트제 또는 유사 강도 재질.
적용 조건: 시행규칙 제6조에 따른 공공하수도의 구조 기술기준(별표 5 개정 2025-10-01) / 개인 배수설비 기준은 시행규칙 제23조(V08)
주의: 개인 배수설비에 공공하수도 기준을 그대로 의무로 적용하지 않음
상담 안내 예: "공공하수도 기준(하수도법 시행규칙 별표 5)으로 빗물받이는 10~30m 간격, 오수받이는 안지름 30cm 이상, 맨홀은 KS 규격이고 뚜껑은 주철·철근콘크리트 등 강도가 있는 재질입니다. 집 안 배수설비는 시행규칙 제23조 기준을 따릅니다."
확인: 국가법령정보센터 별표 원문 PDF(사용자 내려받음, 2026-10-28) 전문 대조 — 공포번호·시행일은 법제처 OPEN API 대조(2026-10-28) 일치 · 2026-10-28', 'manhole', '맨홀,맨홀뚜껑,맨홀 뚜껑,오수받이,물받이,빗물받이 간격,빗물받이 설치,인버트', 0, 800, 'law', '하수도법 시행규칙 (기후에너지환경부령 제42호)', '[별표 5] 제6호·제7호·제8호 (공공하수도 맨홀·물받이·뚜껑)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=165912229', '2026-06-22', '2026-06-22', '공공하수도(지방자치단체가 설치·관리). 집 안 배수설비(V08)와 구분', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 하수도법 시행규칙 [별표 5] 제6호·제7호·제8호 (공공하수도 맨홀·물받이·뚜껑)');
-- V20
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 하수관로 유지관리 기준 [별표 1] 하수관로 시설별 점검 주기 및 내용', '요지: 점검 주기: 암거·개거·측구·역사이펀 연 1회, 맨홀·우수토실 분기 1회(맨홀 추락방지시설 설치 여부 포함), 빗물·오수받이와 빗물·중계 펌프장 월별. 빗물·오수받이 점검 항목: 받이 안·유입부의 토사·쓰레기·낙엽 퇴적, 뚜껑 유입부 물건(덮개) 적치 여부 → 준설·청소.
적용 조건: 공공하수도관리청의 침수 대비 유지관리(V10 과 같은 고시)
주의: 고객의 의무가 아님 — 빗물받이 덮개 적치가 점검 대상이라는 참고 정보
확인: 국가법령정보센터 별표 원문 PDF(사용자 내려받음, 2026-10-28) 전문 대조 — 공포번호·시행일은 법제처 OPEN API 대조(2026-10-28) 일치 · 2026-10-28', 'manhole', NULL, 0, 800, 'law', '하수관로 유지관리 기준 (기후에너지환경부고시 제2025-165호)', '[별표 1] 하수관로 시설별 점검 주기 및 내용', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=158077383', '2025-10-01', '2025-10-01', '공공하수도관리청(지자체)의 하수관로 점검', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 하수관로 유지관리 기준 [별표 1] 하수관로 시설별 점검 주기 및 내용');
-- V21
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] KDS 44 50 25 차도용 블록포장설계 1.2 적용 범위 · 4.8 포장 층별 최소두께(표 4.8-1) · 4.9 표준단면두께(표 4.9-1) · 4.11 줄눈', '요지: 적용 범위: 차도용 블록포장을 활용한 차도의 신설·보수·확장 설계, 도로법 제10조 도로(일반국도·특별시도·광역시도·지방도·시도·군도·구도), 교통안전·속도 저감·경관 개선 구간. 층별 최소두께: 블록 표층 100mm, 받침안정층 25mm, 아스팔트·시멘트 기층 150mm 등. 표준단면(노상 CBR 5 기준): AADT 1,000대/일 이하 표층 100mm, 2,000~4,000대/일 120mm, 받침안정층 25mm. AADT 4,000 초과·중차량 15% 초과·설계속도 60km/h 초과는 역학적 설계·별도 검토. 줄눈 폭 2~3mm.
적용 조건: 도로법 제10조 도로의 차도 설계에 적용 — 사유지 마당·주차장·단지 내 진입로는 적용 범위 밖 / 국가법령정보센터 행정규칙에는 2026 고시 미등록(제2023-907호까지) — 고시 번호·시행일은 사용자 확인 값 / 적용 시점: 고시 2026-10-02(국토교통부고시 제2026-528호) · 적용 개시: 개정전문 경과조치 "발간 시점부터 사용"(발간일은 원문에 없음 — 국가건설기준센터 게시 2026-10-07 은 사용자 확인 값, 고시일과 구분)
주의: 개인 마당·사유지 진입로·단지 내 도로의 법적 의무로 말하지 않음. 사내 80T 안내 문구에는 출처로 붙이지 않음 — 공공도로 차도 질문(@scope:public_road)에서만 별도 안내
상담 안내 예: "도로법상 도로(국도·지방도·시도 등)의 차도에 블록을 시공한다면 KDS 44 50 25 에 따라 블록 표층은 최소 100mm이고, 교통량에 따라 100mm(연평균 일교통량 1,000대 이하)·120mm(4,000대 이하) 표준단면을 씁니다. 최종 두께는 발주처 설계도서를 따르세요."
확인: 국토교통부 고시 첨부 「도로분야 건설기준 개정전문」(전문_9개 코드, HWPX, 사용자 제공 2026-10-28) 원문 대조 — 국가법령정보센터 행정규칙에는 아직 제2023-907호만 등록(API 검색 2026-10-28) · 2026-11-05 국가건설기준센터 OpenAPI 목록에서 KDS 445025 판 2026·갱신 2026-10-07 확인(사용자 확인 값이던 게시일 2026-10-07 과 같음) · 2026-10-28', 'pavement', '@scope:public_road,두께,블록 두께,몇 t,몇 mm,100mm,차도용 블록,블록 포장,블록포장,인터로킹,차도', 0, 800, 'law', 'KDS 44 50 25 차도용 블록포장설계 (국가건설기준 설계기준 KDS 44 50 25:2026 (국토교통부고시 제2026-528호, 2026-10-02 고시 — 사용자 확인))', '1.2 적용 범위 · 4.8 포장 층별 최소두께(표 4.8-1) · 4.9 표준단면두께(표 4.9-1) · 4.11 줄눈', 'https://www.molit.go.kr/USR/I0204/m_45/dtl.jsp?idx=18977', NULL, '2026-10-02', '공공 도로(도로법 제10조)의 차도용 블록포장 설계', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] KDS 44 50 25 차도용 블록포장설계 1.2 적용 범위 · 4.8 포장 층별 최소두께(표 4.8-1) · 4.9 표준단면두께(표 4.9-1) · 4.11 줄눈');
-- V22
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] KCS 44 50 25 차도용 블록포장공사 1.1 적용 범위 · 2.1 차도용 블록 · 2.3 받침안정층 모래 · 3.5.7 시공허용 오차', '요지: 적용 범위: 차량이 통행하는 차도용 블록포장공사. 차도용 블록은 시멘트 콘크리트 블록(휨강도·흡수율·치수허용차 KS F 4419)과 점토바닥벽돌(SPS-KCBIC 0002-1569). 동결융해감량 1.0kg/㎡ 이하(BS EN 1338). 최소 100mm 두께를 사용하고 교통량·축하중·지지력·설계 결과에 따라 상향. 측면 돌기 2~3mm. 치수: 형상비(긴 변/두께) 2.2 이하, 측면적비 1.0 이상, 짧은 변 50mm 이상, 긴 변/짧은 변 2.1 이하. 받침안정층 모래 다짐 후 25mm(±5mm), 줄눈 2~3mm, 45°·90° 헤링본 패턴 원칙, 블록 간 단차 2mm 이내.
적용 조건: 도로공사 표준시방서(KCS 44 00 00)의 일부 — 공공 도로공사 시방 기준 / 국가법령정보센터 행정규칙에는 2026 고시 미등록 / 적용 시점: 고시 2026-10-02(국토교통부고시 제2026-528호) · 적용 개시: 개정전문 경과조치 "발간 시점부터 사용"(발간일은 원문에 없음 — 국가건설기준센터 게시 2026-10-07 은 사용자 확인 값, 고시일과 구분)
주의: 개인 마당·사유지 진입로·단지 내 도로의 법적 의무로 말하지 않음. 사내 80T 안내 문구에는 출처로 붙이지 않음 — 공공도로 차도 질문(@scope:public_road)에서만 별도 안내
상담 안내 예: "공공도로 차도 블록포장공사(KCS 44 50 25)는 차도용 블록을 최소 100mm 이상 쓰고, 교통량·축하중·설계 결과에 따라 두께를 올립니다. 콘크리트 블록 품질은 KS F 4419 를 따릅니다."
확인: 국토교통부 고시 첨부 「도로분야 건설기준 개정전문」(전문_9개 코드, HWPX, 사용자 제공 2026-10-28) 원문 대조 — 국가법령정보센터 행정규칙에는 아직 제2023-907호만 등록(API 검색 2026-10-28) · 2026-11-05 국가건설기준센터 OpenAPI 목록에서 KCS 445025 판 2026·갱신 2026-10-07 확인(사용자 확인 값이던 게시일 2026-10-07 과 같음) · 2026-10-28', 'pavement', '@scope:public_road,두께,블록 두께,몇 t,몇 mm,100mm,차도용 블록,블록 시공,블록포장,인터로킹,받침모래,줄눈', 0, 800, 'law', 'KCS 44 50 25 차도용 블록포장공사 (국가건설기준 표준시방서 KCS 44 50 25:2026 (국토교통부고시 제2026-528호, 2026-10-02 고시 — 사용자 확인))', '1.1 적용 범위 · 2.1 차도용 블록 · 2.3 받침안정층 모래 · 3.5.7 시공허용 오차', 'https://www.molit.go.kr/USR/I0204/m_45/dtl.jsp?idx=18977', NULL, '2026-10-02', '차량이 통행하는 차도용 블록포장공사(도로공사 표준시방서)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] KCS 44 50 25 차도용 블록포장공사 1.1 적용 범위 · 2.1 차도용 블록 · 2.3 받침안정층 모래 · 3.5.7 시공허용 오차');
-- V23
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] KDS 44 40 20 도시지역 도로배수 4.1 우수받이 · 4.2 우수관거 및 맨홀', '요지: 우수받이는 도로 옆 물이 모이기 쉬운 곳이나 L형 측구 유하방향 하단부에 설치하고, 보도·차도 구분이 있으면 그 경계, 없으면 도로와 사유지 경계에 설치. 간격은 수리계산으로 정하되 최소 5m·최대 30m, 가능한 일정 간격. 도로 배수관거 최소관경 450mm. 맨홀 세부설계는 KDS 61 00 00.
적용 조건: 도로 설계 단계의 도로배수 기준(공공 도로) / 공공하수도의 빗물받이 간격은 하수도법 시행규칙 별표 5(V19: 10~30m)로 별도 — 기준별 값이 다름 / 적용 시점: 고시 2026-10-02(국토교통부고시 제2026-528호) · 적용 개시: 개정전문 경과조치 "발간 시점부터 사용"(발간일은 원문에 없음 — 국가건설기준센터 게시 2026-10-07 은 사용자 확인 값, 고시일과 구분)
주의: 하수도법 기준(V19)과 섞어 말하지 않음
확인: 국토교통부 고시 첨부 「도로분야 건설기준 개정전문」(전문_9개 코드, HWPX, 사용자 제공 2026-10-28) 원문 대조 — 국가법령정보센터 행정규칙에는 아직 제2023-907호만 등록(API 검색 2026-10-28) · 2026-11-05 국가건설기준센터 OpenAPI 원문(KDS 444020 판 2026·갱신 2026-10-07) 4.1.1·4.1.2·4.2.2 대조 일치(우수받이 5~30m·배수관거 최소관경 450mm) — 비활성 사유(하수도법 별표 5 와 기준별 값 차이)는 그대로 · 2026-10-28', 'culvert', NULL, 0, 800, 'law', 'KDS 44 40 20 도시지역 도로배수 (국가건설기준 설계기준 KDS 44 40 20:2026 (국토교통부고시 제2026-528호 개정 — 사용자 확인))', '4.1 우수받이 · 4.2 우수관거 및 맨홀', 'https://www.molit.go.kr/USR/I0204/m_45/dtl.jsp?idx=18977', NULL, '2026-10-02', '도시지역 도로의 배수 설계(도로관리청)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] KDS 44 40 20 도시지역 도로배수 4.1 우수받이 · 4.2 우수관거 및 맨홀');
-- V24
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 보도 설치 및 관리 지침 1-2 적용 범위 · 4-1 나.2) 용도에 따른 구분 · 4-2 나. <표 4.1> 콘크리트 인터로킹 블록 포장의 표준단면', '요지: 적용 범위: 「도로의 구조·시설 기준에 관한 규칙」 제16조에 따라 설치되는 보도 등 보행자 통행시설(도로관리자용 기술 기준). 보도 포장 용도 구분은 최대 적재량 4t 이하 차량의 통행 여부 — 구분 Ⅰ: 보행자·자전거용 보도, 구분 Ⅱ: 보도횡단 차량출입시설 등 보도를 가로질러 인접 건물·시설을 이용하거나 관리용 차량 등 최대 적재량 4t 이하 차량이 통행하는 보도. 콘크리트 인터로킹 블록 포장 표준단면: 표층 60mm(구분 Ⅱ 80mm 이상), 안정층 30mm(구분 Ⅱ 20mm), 기층 100mm(구분 Ⅱ 150mm, 입도조정기층). 【설명】 최근 구분 Ⅱ 는 내구성을 위해 100~120mm 로 높이는 경향. 결합틈새 투수블록은 차량 통과 시 파손되기 쉬워 구분 Ⅰ 에만 적용. 점토바닥벽돌은 구분 Ⅰ 50mm·구분 Ⅱ 60mm(소형차 이하 차량이 가로지르는 보도에만 제한 적용).
적용 조건: 도로(도로법·도로 구조 규칙 제16조)의 보도에 적용 — 도로관리청이 설치·관리 / 구분 Ⅱ = 최대 적재량 4t 이하 차량이 보도를 가로질러 통행하는 보도. 4t 초과 차량·차도 부분은 대상 아님(차도는 KDS·KCS 44 50 25) / 예규 제321호(2021-07-23 발령·시행), 본문은 첨부 개정전문(HWP·PDF)
주의: 사유지 마당·주차장의 의무 기준으로 말하지 않음. 사내 80T 안내 문구의 출처로 붙이지 않음 — 공공 보도 차량 출입부 질문(@scope:public_sidewalk)에서만 별도 안내
상담 안내 예: "도로의 인도(보도) 중 건물 출입 차량이 가로지르는 구간이라면, 국토교통부 「보도 설치 및 관리 지침」 표준단면은 최대 적재량 4t 이하 차량 기준으로 인터로킹 블록 표층 80mm 이상(안정층 20mm·기층 150mm)입니다. 4t 넘는 차량이 다니거나 차도라면 별도 기준을 확인해야 합니다."
확인: 보도설치 및 관리지침 개정전문 PDF(국가법령정보센터 첨부, 사용자 내려받음 2026-10-28) 전문 대조 — 발령번호·시행일은 법제처 OPEN API 대조(2026-10-28) 일치 · 2026-10-28', 'pavement', '@scope:public_sidewalk,두께,블록 두께,몇 t,몇 mm,80mm,80t,인터로킹,보도 포장,인도 포장,차량 출입,진출입', 0, 800, 'law', '보도 설치 및 관리 지침 (국토교통부예규 제321호)', '1-2 적용 범위 · 4-1 나.2) 용도에 따른 구분 · 4-2 나. <표 4.1> 콘크리트 인터로킹 블록 포장의 표준단면', 'https://law.go.kr/flDownload.do?flSeq=169457241', '2021-07-23', '2021-07-23', '공공 도로의 보도(인도) — 특히 건물·시설 진출입 차량(최대 적재량 4t 이하)이 가로지르는 구간(구분 Ⅱ)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 보도 설치 및 관리 지침 1-2 적용 범위 · 4-1 나.2) 용도에 따른 구분 · 4-2 나. <표 4.1> 콘크리트 인터로킹 블록 포장의 표준단면');
-- V25
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 교통약자의 이동편의 증진법 시행규칙 [별표 1] 제3호가목 (교통약자가 통행할 수 있는 보도)', '요지: 도로의 보도 또는 접근로("보도등") 유효폭 2m 이상(지형상 불가능하거나 기존 도로 증·개축 시 1.2m까지 완화), 유효폭 1.5m 미만이면 50m마다 1.5×1.5m 교행구역. 바닥은 미끄럽지 않게 평탄 마감, 보도블록 이음새 틈이 벌어지지 않게 시공. 빠질 위험이 있는 곳 덮개는 보도와 같은 높이, 격자·틈새 간격 1cm 이하. 기울기 1/18 이하(완화 1/12), 좌우 기울기 1/25 이하. 연석(경계석) 높이 25cm 이하. 차량 진출입부는 보도 높이를 유지하고 차도 경계부는 턱 낮추기, 교행 구간 바닥 마감재 색상·질감 구분. 횡단보도 접속부 턱 낮추기(높이 차 2cm 이하, 연석만 낮추기 금지), 연석경사로 유효폭 0.9m 이상·기울기 1/12 이하. 횡단보도 진입부 점형블록, 보도 폭의 4/5 지점까지 선형블록.
적용 조건: 교통약자법상 대상시설인 도로의 보도(시행규칙 제2조제1항 별표 1, 개정 2024-12-26) / 대상시설(건물) 대지 안 접근로는 장애인등편의법(V16: 덮개 틈 2cm)이 별도 — 기준별 값이 다름
주의: 사유지 마당에 의무로 말하지 않음. 편의법 접근로 기준(2cm)과 섞지 않음
상담 안내 예: "도로 보도라면 교통약자법 시행규칙 별표 1 기준으로 유효폭 2m, 기울기 1/18, 경계석 25cm 이하이고, 보도 위 덮개 틈은 1cm 이하, 차량 진출입부는 보도 높이를 유지하고 차도 쪽을 턱 낮추기합니다."
확인: 국가법령정보센터 원문 파일(사용자 내려받음, 2026-10-28) 전문 대조 — 별표 1(개정 2024-12-26) · 2026-11-05 공식 파일 재대조(Claude, law.go.kr 직접 내려받음): API 현행 링크(flSeq=164257997)와 현행판(국토교통부령 제1558호, 2026-11-05 공식 API 일치) 미러 첨부 링크(flSeq=161303961)의 별표 1 PDF 가 같은 파일(MD5 동일)·<개정 2024. 12. 26.> 그대로 — 제3호가목 1)~7) 수치(유효폭 2m·완화 1.2m, 덮개 틈 1cm, 기울기 1/18·완화 1/12, 좌우 1/25, 연석 25cm, 턱 2cm, 연석경사로 0.9m·1/12, 선형블록 4/5)가 위 요약과 같음 · 2026-11-05 사용자 인정(위 공식 파일 대조 결과를 사용자가 확인·인정) · 2026-11-05', 'pavement', '보도 유효폭,인도 유효폭,턱낮추기,턱 낮추기,차량 진출입부,보도 기울기,연석경사로,횡단보도 점자블록,보도 덮개,인도 덮개', 0, 800, 'law', '교통약자의 이동편의 증진법 시행규칙 (국토교통부령 제1558호)', '[별표 1] 제3호가목 (교통약자가 통행할 수 있는 보도)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=164257997', '2026-02-01', '2026-01-30', '도로(공공)의 보도·접근로를 설치·관리하는 도로관리청 등', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조) · 공식 파일 재대조(Claude, 2026-11-05) · 사용자 인정 2026-11-05', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 교통약자의 이동편의 증진법 시행규칙 [별표 1] 제3호가목 (교통약자가 통행할 수 있는 보도)');
-- V26
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 교통약자의 이동편의 증진법 시행규칙 [별표 2] 제1호·제2호가목·제7호 (보행안전시설물 — 속도저감시설·고원식 횡단보도·자동차 진입억제용 말뚝)', '요지: 보행우선구역의 보행안전시설물(시행규칙 제9조): 신호기 없는 충돌 위험 교차로에는 고원식 교차로 설치(암적색 아스콘 또는 블록포장 가능, 보도 연결부 요철 없게). 과속방지턱은 통과 차량 안전에 지장이 없도록, 긴급자동차 통행을 위해 폭을 좁게 할 수 있음. 고원식 횡단보도: 사다리꼴 구조물 높이는 보도와 같게, 윗면 평탄부 250cm 이상, 경사부와 횡단부 색상·재질 구분, 배수 설비, 야간 표지·말뚝 설치. 자동차 진입억제용 말뚝: 높이 80~100cm, 지름 10~20cm, 간격 1.5m 안팎, 반사도료, 충격 흡수 재료·저속 차량 충격에 견디는 구조, 0.3m 전면 점형블록.
적용 조건: 교통약자법 제21조 보행우선구역의 보행안전시설물(별표 2, 개정 2021-08-27) / 보행안전법 시행규칙 별표 1(V13·V14)과 같은 취지 — 법령·적용 구역이 다름
주의: 사유지 시설에 의무로 말하지 않음
상담 안내 예: "교통약자법 시행규칙 별표 2 기준으로 고원식 횡단보도는 보도와 같은 높이로 하고 윗면 평탄부를 250cm 이상 둡니다(보행우선구역 공공시설 기준)."
확인: 국가법령정보센터 원문 파일(사용자 내려받음, 2026-10-28) 전문 대조 — 별표 2(개정 2021-08-27) · 2026-11-05 공식 파일 재대조(Claude, law.go.kr 직접 내려받음): 현행판(국토교통부령 제1558호, 2026-11-05 공식 API 일치) 첨부 별표 2 PDF(flSeq=161304041) <개정 2021. 8. 27.> 그대로 — 제1호가목·마목, 제2호가목 1)~5)(평탄부 250cm 이상), 제7호(말뚝 높이 80~100cm·지름 10~20cm·간격 1.5m 안팎·0.3m 전면 점형블록)가 위 요약과 같음 · 2026-11-05 사용자 인정(위 공식 파일 대조 결과를 사용자가 확인·인정) · 2026-11-05', 'pavement', '고원식 횡단보도,고원식 교차로,고원식,보행우선구역,험프식 횡단보도', 0, 800, 'law', '교통약자의 이동편의 증진법 시행규칙 (국토교통부령 제1558호)', '[별표 2] 제1호·제2호가목·제7호 (보행안전시설물 — 속도저감시설·고원식 횡단보도·자동차 진입억제용 말뚝)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=161304041', '2026-02-01', '2026-01-30', '보행우선구역에 시장·군수가 설치하는 보행안전시설물(공공)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조) · 공식 파일 재대조(Claude, 2026-11-05) · 사용자 인정 2026-11-05', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 교통약자의 이동편의 증진법 시행규칙 [별표 2] 제1호·제2호가목·제7호 (보행안전시설물 — 속도저감시설·고원식 횡단보도·자동차 진입억제용 말뚝)');
-- V27
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령 [별표 2] 제2호·제3호·제4호 (대상시설별 편의시설 — 접근로·장애인전용주차구역·점자블록)', '요지: 공원: 공원과 도로·교통시설을 연결하는 보도에 점자블록 설치 의무, 공원 주차장에는 장애인전용주차구역. 공공건물 및 공중이용시설: 외부에서 주출입구까지 접근로 설치, 부설주차장에 장애인전용주차구역(주차장법령 설치비율, 주차대수 10대 미만 부설주차장은 제외), 건축물 주출입구와 도로·교통시설을 연결하는 보도에 점자블록 설치. 공동주택: 아파트·연립주택·다세대주택(연립·다세대는 10세대 이상만)은 접근로·장애인전용주차구역 의무(부설주차장 10대 미만 제외), 장애인전용주택 출입구와 도로를 연결하는 보도의 점자블록은 설치할 수 있음(권장).
적용 조건: 법 제7조 대상시설의 종류는 시행령 별표 1 — 이 별표 2는 대상시설별 편의시설의 종류·설치기준(개정 2024-09-19) / 세부 규격(크기·기울기 등)은 시행규칙 별표 1(V15~V18)
주의: 단독주택·소규모(10세대 미만) 공동주택 등 대상이 아닌 곳에 의무로 말하지 않음
상담 안내 예: "아파트나 10세대 이상 연립·다세대는 부설주차장이 10대 이상이면 장애인전용주차구역을 두어야 하고(장애인등편의법 시행령 별표 2), 공공건물은 주출입구와 도로를 잇는 보도에 점자블록을 설치해야 합니다."
확인: 국가법령정보센터 원문 파일(사용자 내려받음, 2026-10-28) 전문 대조 — 별표 2(개정 2024-09-19) · 2026-10-28', 'pavement', '설치 의무,의무 대상,의무 설치,장애인 편의시설,편의시설 의무,장애인주차 의무,점자블록 의무,10세대,장애인 주차구역 설치,주차구역 설치 의무,점자블록 설치 의무,접근로 설치 의무', 0, 800, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령 (대통령령 제35947호)', '[별표 2] 제2호·제3호·제4호 (대상시설별 편의시설 — 접근로·장애인전용주차구역·점자블록)', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=159969651', '2026-01-02', NULL, '장애인등편의법 대상시설(공원·공공건물 및 공중이용시설·공동주택·통신시설)의 설치 의무 판단', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 원문 파일 확보) + Claude(원문 대조)', '2026-10-28' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령 [별표 2] 제2호·제3호·제4호 (대상시설별 편의시설 — 접근로·장애인전용주차구역·점자블록)');
-- V28
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 건축법 시행규칙 제25조 (대지의 조성 — 옹벽) + 건축법 제40조제4항', '요지: 손궤(무너져 내림)의 우려가 있는 토지에 대지를 조성하려면 옹벽을 설치하거나 필요한 조치를 해야 함(건축법 제40조제4항). 조치(시행규칙 제25조): ① 성토 또는 절토하는 부분의 경사도가 1:1.5 이상으로서 높이 1m 이상인 부분에는 옹벽 설치 ② 옹벽 높이가 2m 이상이면 콘크리트구조로 함(다만 별표 6 ''옹벽에 관한 기술적 기준''에 적합하면 예외) ③ 옹벽 외벽면에는 지지·배수 시설 외의 구조물이 튀어나오지 않게 함 ④ 옹벽 윗가장자리에서 안쪽 2m 이내에 묻는 배수관은 주철관·강관 또는 흄관으로 하고 이음부분은 물이 새지 않게 함 ⑤ 옹벽에는 3㎡마다 하나 이상의 배수구멍을 두고, 윗가장자리 안쪽 2m 이내의 지표수는 지상 또는 배수관으로 배수 ⑥ 성토 부분의 높이는 인접 대지 지표면보다 0.5m 이상 높게 하지 않음(지형상 부득이하다고 허가권자가 인정하면 예외). 건축사 또는 건축구조기술사가 해당 토지의 구조안전을 확인한 경우에는 위 조치를 하지 않아도 됨(제25조 단서). 별표 6 내용은 미확보.
적용 조건: 건축법 제40조제4항 — 손궤의 우려가 있는 토지에 대지(건축물을 짓는 땅)를 조성하는 경우 / 건축사 또는 「기술사법」에 따라 등록한 건축구조기술사가 해당 토지의 구조안전을 확인한 경우는 제외(제25조 단서) / 높이 2m 이상 옹벽을 콘크리트구조가 아닌 방식으로 할 수 있는 별표 6(옹벽에 관한 기술적 기준, 개정 2014.10.15)은 석축(돌쌓기) 옹벽 기준만 정함 — 높이별 경사도 이하(멧쌓기 1.5m까지 1:0.30·3m까지 1:0.35·5m까지 1:0.40, 찰쌓기 1:0.25·1:0.30·1:0.35), 석축용 돌 뒷길이 이상(30·40·50cm)·뒷채움돌 두께 이상(상부 30cm, 하부 40·50·50cm), 윗가장자리에서 건축물 외벽까지 띄우는 거리 이상(1층 1.5m·2층 2m·3층 이상 3m — 건축물 기초가 석축 기초 이하면 예외). 보강토블록 등 블록 옹벽의 기준은 별표에 없음
주의: 보강토블록·식생블록 옹벽이 별표 6 기준에 맞는지 판단하지 않음(구조 검토·설계 필요). 블록 제품의 강도·적합성 근거로 쓰지 않음
상담 안내 예: "건축물을 지을 땅을 만들면서 흙을 돋우거나 깎은 비탈의 경사도가 1:1.5 이상이고 높이가 1m 이상이면 옹벽을 설치해야 하고, 옹벽이 2m 이상이면 콘크리트구조로 해야 하고, 예외는 별표 6 의 석축(돌쌓기) 옹벽 기술 기준(높이별 경사도·돌 뒷길이 등)에 맞는 경우입니다(건축법 시행규칙 제25조·별표 6). 보강토블록으로 2m 넘게 쌓으실 계획이면 설계·구조 검토를 먼저 받으세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러) 2026-11-04 열람 — 현재 시행판(건축법 시행규칙 01567·시행 2026-02-27, 건축법 21065·시행 2025-10-01)과 시행 예정판(건축법 21880·21901) 조문 대조: 인용 조문 동일 · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 건축법 시행규칙 01567 제25조 일치, 건축법은 공식 현행판 21035·시행 2026-02-27(위 21065 와 다른 판 — 제40조 내용 동일). 별표 6 은 API 현행 링크 PDF(flSeq=161990051, 개정 2014.10.15) 열람 — 석축 옹벽 기준만(4~6호 삭제) (api-sync-report-2026-11-05-b.md) · 2026-11-05', NULL, '옹벽,축대,석축,보강토,식생블록,식생 블록,식생축조,흙막이,성토,절토,비탈,배수구멍,물빠짐 구멍', 0, 800, 'law', '건축법 시행규칙 (국토교통부령 제1567호)', '제25조 (대지의 조성 — 옹벽) + 건축법 제40조제4항', 'https://www.law.go.kr/법령/건축법시행규칙/제25조', '2026-02-27', '2026-02-27', '건축법에 따른 대지 조성(건축물 대지)의 성토·절토 부분. 단순 화단·조경용 낮은 쌓기나 농지·임야에 그대로 적용되는지는 관할 시·군·구 확인', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 건축법 시행규칙 제25조 (대지의 조성 — 옹벽) + 건축법 제40조제4항');
-- V29
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 건축법 시행령 제118조제1항제5호·제2항 + 건축법 제83조제1항 (옹벽·담장 공작물 축조신고)', '요지: 대지를 조성하기 위한 옹벽 등 대통령령으로 정하는 공작물을 축조하려는 자는 특별자치시장·특별자치도지사 또는 시장·군수·구청장에게 신고해야 함(건축법 제83조제1항). 신고 대상 공작물에 ''높이 2미터를 넘는 옹벽 또는 담장''이 포함됨(시행령 제118조제1항제5호 — 건축물과 분리하여 축조하는 경우). 축조하려는 자는 공작물 축조신고서와 국토교통부령으로 정하는 설계도서를 제출(제118조제2항). 높이 2m 이하 옹벽·담장은 이 조항의 신고 대상이 아님.
적용 조건: 건축물과 분리하여 축조하는 옹벽·담장(제118조제1항) / 높이 2m를 넘는 경우만 이 조항의 신고 대상 / 신고처: 특별자치시장·특별자치도지사 또는 시장·군수·구청장. 설계도서 제출(제118조제2항)
주의: 2m 이하는 어떤 신고·허가도 필요 없다고 단정하지 않음(이 조항 기준만 안내 — 지역 조례·다른 법령은 별도). 신고만으로 구조 안전이 확인된다고 말하지 않음
상담 안내 예: "건물과 따로 쌓는 옹벽이나 담장이 높이 2m를 넘으면 시·군·구청에 공작물 축조신고를 해야 합니다(건축법 제83조, 시행령 제118조). 2m 이하라면 이 신고 대상은 아니지만 지역 조례 등은 관할 구청에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러) 2026-11-04 열람 — 현재 시행판(건축법 시행령 36746·시행 2026-10-06, 제118조 2026.8.18 개정분은 2026-09-18 시행 / 건축법 21065·시행 2025-10-01)과 시행 예정판(건축법 21880·21901) 조문 대조: 인용 조문 동일 · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 건축법 시행령 36746 제118조 일치, 건축법 공식 현행판 21035·시행 2026-02-27(위 21065 와 다른 판) 제83조 내용 동일 (api-sync-report-2026-11-05-b.md) · 2026-11-05', NULL, '옹벽,담장,담벼락,담을 쌓,담 쌓,축대,석축,보강토,축조신고,공작물,높이 2m,2m 넘,2미터 넘', 0, 800, 'law', '건축법 시행령 (대통령령 제36746호)', '제118조제1항제5호·제2항 + 건축법 제83조제1항 (옹벽·담장 공작물 축조신고)', 'https://www.law.go.kr/법령/건축법시행령/제118조', '2026-10-06', '2026-10-06', '건물과 따로 쌓는 옹벽·담장(보강토블록 옹벽, 블록·벽돌 담장 등). 높이 산정·세부 절차는 관할 시·군·구 건축 부서 확인', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 건축법 시행령 제118조제1항제5호·제2항 + 건축법 제83조제1항 (옹벽·담장 공작물 축조신고)');
-- V30
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 국토의 계획 및 이용에 관한 법률 시행령 제53조제3호가목·나목·다목 + 법 제56조제1항제2호·제4항제3호 (개발행위허가 — 허가 없이 할 수 있는 경미한 토지 형질변경)', '요지: 토지의 형질 변경 등 개발행위를 하려면 특별시장·광역시장·특별자치시장·특별자치도지사·시장 또는 군수의 개발행위허가를 받아야 함(법 제56조제1항제2호, 경작을 위한 경우로서 대통령령으로 정하는 형질 변경은 제외). 다만 대통령령으로 정하는 경미한 행위는 허가 없이 할 수 있음(법 제56조제4항제3호). 경미한 토지 형질변경(시행령 제53조제3호): 가. 높이 50cm 이내 또는 깊이 50cm 이내의 절토·성토·정지 등(여러 차례에 걸치면 누적 산정, 포장 제외, 주거·상업·공업지역 외에서는 지목변경을 수반하지 않는 경우) 나. 도시지역·자연환경보전지역·지구단위계획구역 외의 지역에서 면적 660㎡ 이하 토지의 지목변경 없는 절토·성토·정지·포장 등 다. 조성이 끝난 기존 대지에 건축물·공작물을 설치하기 위한 형질변경(지하구조물 설치를 위한 터파기·되메우기 포함, 그 밖의 절토·성토 제외). 위 범위에서 시·군의 도시·군계획조례로 따로 정하면 그에 따름(제53조 단서).
적용 조건: 토지의 형질 변경(절토·성토·정지·포장 등) — 경작을 위한 형질 변경 중 대통령령으로 정하는 것은 제외(법 제56조제1항제2호) / 50cm 기준은 여러 차례 공사를 누적해 산정하고 포장은 제외(시행령 제53조제3호가목) / 시·군 도시·군계획조례가 이 범위 안에서 따로 정하면 조례에 따름(시행령 제53조 단서) / 도시·군계획사업에 의한 행위는 허가 대상 아님(법 제56조제1항 단서)
주의: 허가가 필요 없다고 단정하지 않음 — 용도지역·조례·지목에 따라 다름. 산지(산지관리법)·농지(농지법)의 별도 규정은 확인하지 않았음
상담 안내 예: "마당 흙을 50cm 넘게 돋우거나 깎으면(여러 번 나눠 해도 합산) 원칙적으로 시·군 개발행위허가 대상이 될 수 있습니다(국토계획법 제56조, 시행령 제53조). 용도지역과 조례에 따라 기준이 달라 공사 전 관할 시·군청에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러) 2026-11-04 열람 — 현재 시행판(시행령 36656·시행 2026-09-08, 법 21585·시행 2026-05-17)과 시행 예정판(법 21626·21796·21812) 조문 대조: 인용 조문(법 제56조제1항·제4항, 시행령 제53조) 동일, 법 제56조제3항만 2027-05-13 시행 예정 개정(인용 안 함) · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 시행령 공식 현행판 36587·공포 2026-08-18·시행 2026-09-18, 법 공식 현행판 21447·시행 2026-07-01(위 36656·21585 와 다른 판) — 시행령 제53조·법 제56조 내용 동일 (api-sync-report-2026-11-05-b.md) · 2026-11-05', NULL, '성토,절토,흙을 쌓,흙 쌓,흙을 돋,흙 돋,흙을 깎,흙 깎,땅을 돋,땅 돋,땅을 높,마당을 높,마당 높,평탄화,정지 작업,복토,개발행위,형질변경,형질 변경', 0, 800, 'law', '국토의 계획 및 이용에 관한 법률 시행령 (대통령령 제36587호)', '제53조제3호가목·나목·다목 + 법 제56조제1항제2호·제4항제3호 (개발행위허가 — 허가 없이 할 수 있는 경미한 토지 형질변경)', 'https://www.law.go.kr/법령/국토의계획및이용에관한법률시행령/제53조', '2026-09-18', '2026-08-18', '국토계획법이 적용되는 토지(개인 마당·전원주택 부지 포함)의 흙 돋우기·깎기·고르기. 실제 허가 필요 여부는 용도지역·지목·조례에 따라 달라 관할 시·군 확인', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 국토의 계획 및 이용에 관한 법률 시행령 제53조제3호가목·나목·다목 + 법 제56조제1항제2호·제4항제3호 (개발행위허가 — 허가 없이 할 수 있는 경미한 토지 형질변경)');
-- V31
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 하천법 제33조제1항·제3항 (하천의 점용허가 등)', '요지: 하천구역 안에서 ① 토지의 점용 ② 하천시설의 점용 ③ 공작물의 신축·개축·변경 ④ 토지의 굴착·성토·절토 그 밖의 토지의 형질변경 ⑤ 토석·모래·자갈의 채취 ⑥ 그 밖에 하천의 보전·관리에 장애가 될 수 있는 행위로서 대통령령으로 정하는 행위를 하려는 자는 하천관리청의 허가(하천점용허가)를 받아야 함(제1항). 하천관리청은 허가할 때 하천의 구조·시설 기준 적합 여부, 하천기본계획 적합 여부, 공작물 설치로 인근 지대가 침수되지 않도록 하는 배수시설 설치 여부 등을 고려(제3항).
적용 조건: 하천법상 하천(국가하천·지방하천)의 하천구역 안에서 하는 행위 / 허가 절차·변경 허가 대상은 대통령령으로 정함(제1항)
주의: 어떤 물길이 하천구역인지 판단하지 않음 — 관할 지자체 하천 부서 확인. 허가 가능 여부를 단정하지 않음
상담 안내 예: "하천구역 안에 호안블록을 쌓거나 땅을 파고 메우는 공사는 하천관리청의 점용허가 대상입니다(하천법 제33조). 해당 물길이 하천구역인지, 소하천인지 관할 시·군청 하천 부서에 먼저 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러) 2026-11-04 열람 — 현재 시행판(하천법 21471·시행 2026-09-18, 미러 최신판과 같음) 조문 확인 · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 하천법 21471·시행 2026-09-18 제33조 일치 (api-sync-report-2026-11-05-b.md) · 2026-11-05', NULL, '하천,냇가,강가,개울,호안,제방,하천변,천변', 0, 800, 'law', '하천법 (법률 제21471호)', '제33조제1항·제3항 (하천의 점용허가 등)', 'https://www.law.go.kr/법령/하천법/제33조', '2026-09-18', '2026-03-17', '국가하천·지방하천의 하천구역. 소하천(마을 개울 등 소하천으로 지정된 곳)은 소하천정비법 — 별도 근거', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 하천법 제33조제1항·제3항 (하천의 점용허가 등)');
-- V32
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 소하천정비법 제14조제1항·제6항 (소하천등의 점용 등)', '요지: 소하천등(소하천 예정지 제외)에서 ① 유수의 점용 ② 토지의 점용 ③ 소하천시설의 점용·신축·개축·변경 또는 제거 ④ 그 밖의 인공구조물의 신축·개축 또는 변경 ⑤ 토지의 굴착·성토·절토 그 밖의 토지의 형상 변경 ⑥ 토석·모래·자갈·죽목 등 소하천등 산출물의 채취 ⑦ 그 밖에 소하천등의 형상과 기능에 지장을 줄 수 있는 행위를 하려는 자는 관리청의 허가를 받아야 함(대통령령으로 정하는 경우는 제외 — 제1항). 관리청은 허가 신청을 받은 날부터 20일 이내에 허가 여부 또는 처리 지연 사유를 통지해야 함(제6항).
적용 조건: 소하천정비법에 따른 소하천등(소하천 예정지 제외) / 대통령령으로 정하는 경우는 허가 불필요(제1항 단서) — 해당 경우의 내용은 미확인
주의: 물길이 소하천으로 지정됐는지 판단하지 않음 — 관할 시·군·구 확인. 단서의 허가 면제 경우는 확인하지 않았음
상담 안내 예: "마을 개울처럼 소하천으로 지정된 곳에 호안블록·수로관을 놓거나 흙을 파고 메우려면 관리청(시·군·구) 허가가 필요합니다(소하천정비법 제14조). 소하천 지정 여부는 시·군·구청에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러) 2026-11-04 열람 — 현재 시행판(소하천정비법 21065·시행 2025-10-01)과 시행 예정판(21733·시행 2026-12-03) 조문 대조: 인용 조문 동일 · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 소하천정비법 21065·시행 2025-10-01 제14조 일치 (api-sync-report-2026-11-05-b.md) · 2026-11-05', NULL, '소하천,개울,실개천,냇가,하천,호안,마을 하천', 0, 800, 'law', '소하천정비법 (법률 제21065호)', '제14조제1항·제6항 (소하천등의 점용 등)', 'https://www.law.go.kr/법령/소하천정비법/제14조', '2025-10-01', '2025-10-01', '시·군·구가 관리하는 소하천(소하천으로 지정된 마을 개울·작은 하천 등)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 소하천정비법 제14조제1항·제6항 (소하천등의 점용 등)');
-- V33
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 도로법 제61조제1항 (도로의 점용 허가) · 제52조제1항·제5항 (도로와 다른 시설의 연결) + 시행령 제49조·제55조', '요지: 공작물·물건 그 밖의 시설을 신설·개축·변경 또는 제거하거나 그 밖의 사유로 도로(도로구역 포함)를 점용하려는 자는 도로관리청의 허가를 받아야 함(제61조제1항). 점용허가를 받을 수 있는 시설에는 수도관·하수도관·작업구(맨홀)·배수시설·암거 등(시행령 제55조제2호), 주차장 등과 이를 위한 진입로·출입로(제3호), 장애인등편의법상 높이차이 제거시설·주출입구 접근로(제10호) 등이 있음. 고속국도·자동차전용도로·일반국도·지방도·4차로 이상으로 도로구역이 결정된 도로에 다른 도로·통로·시설을 연결하려면 미리 도로관리청의 연결허가를 받아야 하며(제52조제1항, 시행령 제49조), 연결허가를 받아 연결하는 시설은 도로점용허가를 받은 것으로 봄(제52조제5항).
적용 조건: 도로법상 도로(도로구역 포함)를 점용하는 경우 — 사유지 안 공사는 해당 없음 / 연결허가 대상 도로: 고속국도·자동차전용도로·일반국도·지방도·4차로 이상으로 도로구역이 결정된 도로(제52조제1항, 시행령 제49조) / 허가 기준·점용료 등 세부는 도로관리청·조례
주의: 어느 땅이 도로구역인지 판단하지 않음 — 관할 도로관리청(시·군·구청 등) 확인. 허가 가능 여부·점용료를 안내하지 않음
상담 안내 예: "집 앞 인도나 도로(도로구역)에 진입로를 내거나 배수로·배수관을 묻는 공사는 도로관리청의 도로점용허가 대상입니다(도로법 제61조). 국도·지방도 등에 진입로를 연결하면 연결허가가 따로 필요합니다(제52조). 공사 전 관할 시·군·구청 도로 부서에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러) 2026-11-04 열람 — 현재 시행판(도로법 21738·시행 2026-06-02, 시행령 36689·시행 2026-09-15)과 시행 예정판(도로법 21965·시행 2027-03-23) 조문 대조: 인용 조문 동일 · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 도로법 공식 현행판 21480·공포 2026-03-17·시행 2026-09-18, 시행령 36587·시행 2026-09-18(위 21738·36689 와 다른 판) — 제52조·제61조·시행령 제49조·제55조 내용 동일 (api-sync-report-2026-11-05-b.md) · 2026-11-05', NULL, '도로점용,도로 점용,점용허가,인도에,보도에,집 앞 인도,집 앞 보도,가게 앞 인도,인도에 배수,보도에 배수,인도 위,보도 위,집 앞 도로,가게 앞 도로,도로에 배수,도로 횡단,도로를 파,도로 굴착,턱낮춤,인도 턱,보도 턱,도로 연결,도로에 연결,연결허가', 0, 800, 'law', '도로법 (법률 제21480호)', '제61조제1항 (도로의 점용 허가) · 제52조제1항·제5항 (도로와 다른 시설의 연결) + 시행령 제49조·제55조', 'https://www.law.go.kr/법령/도로법/제61조', '2026-09-18', '2026-03-17', '집·가게 앞 공공 도로·보도(인도) 등 도로구역에 진입로·배수로·배수관·맨홀 등을 설치하거나 도로를 파는 공사. 사유지 내부 공사는 대상 아님', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 도로법 제61조제1항 (도로의 점용 허가) · 제52조제1항·제5항 (도로와 다른 시설의 연결) + 시행령 제49조·제55조');
-- V34
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 산업표준화법 제15조제2항·제3항 (제품의 인증 — 제품인증표시) · 제24조 (한국산업표준의 준수)', '요지: 제품의 인증(KS 인증)을 받은 자는 그 제품·포장·용기·납품서 또는 보증서에 그 제품이 한국산업표준(KS)에 적합한 것임을 나타내는 표시(제품인증표시)를 하거나 홍보할 수 있고(제15조제2항), 인증을 받은 자가 아니면 제품·포장·용기·납품서·보증서·홍보물에 제품인증표시나 이와 유사한 표시를 할 수 없음(제3항). 국가·지방자치단체·공공기관·공공단체는 물자·용역의 조달, 생산관리, 시설공사 등을 할 때 한국산업표준을 준수해야 함(제24조).
적용 조건: 제품인증표시(KS 표시)는 인증기관에서 그 제품의 인증을 받은 자만 할 수 있음(제15조제3항) / 제24조 준수 의무는 국가·지방자치단체·공공기관·공공단체의 조달·생산관리·시설공사
주의: 개인 공사에서 KS 제품이 의무인지 여부는 이 조항으로 말하지 않음(공사 종류별 법령에 따라 다름 — 예: 하수도용 자재는 하수도법 근거). 특정 상품의 KS 인증 여부는 제품 표시·인증서로만 확인
상담 안내 예: "KS 표시는 인증기관에서 그 제품이 한국산업표준에 맞는다고 인증받은 경우에만 할 수 있습니다(산업표준화법 제15조). 관공서·공공기관이 발주하는 공사는 KS를 지켜야 합니다(제24조). 개인 공사의 의무 여부는 공사 종류에 따라 다릅니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러) 2026-11-04 열람 — 현재 시행판(산업표준화법 21438·시행 2026-03-10)과 시행 예정판(21925·시행 2027-03-16) 조문 대조: 제15조제2항·제3항·제24조 동일, 제15조제1항만 시행 예정 개정(인용 안 함) · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 산업표준화법 21438·시행 2026-03-10 제15조·제24조 일치 (api-sync-report-2026-11-05-b.md) · 2026-11-05', NULL, 'ks 인증,ks인증,ks 마크,ks마크,ks 제품,ks제품,ks 표시,ks표시,ks 규격,ks규격,케이에스,한국산업표준,인증 제품,인증제품', 0, 800, 'law', '산업표준화법 (법률 제21438호)', '제15조제2항·제3항 (제품의 인증 — 제품인증표시) · 제24조 (한국산업표준의 준수)', 'https://www.law.go.kr/법령/산업표준화법/제15조', '2026-03-10', '2026-03-10', 'KS 표시 제품의 의미 설명. 공공기관 발주 공사·조달에서는 KS 준수 의무(제24조)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 산업표준화법 제15조제2항·제3항 (제품의 인증 — 제품인증표시) · 제24조 (한국산업표준의 준수)');
-- V35
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 도로안전시설 설치 및 관리 지침 제4편 2. 과속방지턱 — 2.1.2 적용 범위 · 2.3 설치 장소 · 2.4.1 형상 및 제원 · 2.4.2 재료 · 2.5 설치', '요지: 「도로법」에 따라 설치되는 과속방지턱에 적용(도로법 제10조 도로 중 집산도로·국지도로 원칙, 그 밖의 도로에도 준용 가능 — 2.1.2). 형상은 원호형이 표준이며 제원은 설치 길이 3.6m·설치 높이 10cm(2.4.1). 재료는 도로 노면 포장 재료와 같은 재료로 노면과 일체가 되도록 설치하는 것이 원칙이고, 특수한 경우에 한하여 고무·플라스틱 등으로 제작해 설치할 수 있음(2.4.2). 반사성 도료로 백색·황색 도색 원칙(2.4.3). 차량 통행 속도를 30km/h 이하로 제한할 필요가 있는 구간 중 도로관리청이 필요하다고 판단하는 장소에 한하여 최소로 설치(2.3). 교차로 15m 이내·건널목 20m 이내·버스정류장 20m 이내·교량·지하도·터널·어두운 곳 등에는 설치 금지, 연속형은 20~90m 간격 원칙(2.5).
적용 조건: 「도로법」에 따라 도로관리청이 설치하는 과속방지턱(공공 도로) / 고무·플라스틱 제품은 ''특수한 경우에 한하여'' 사용 가능(2.4.2)
주의: 고무 방지턱 제품의 성능·규격 적합성을 판단하지 않음. 사유지 설치를 금지·의무로 말하지 않음. 도색 그림(그림 2.5)은 미확인
상담 안내 예: "공공 도로의 과속방지턱은 국토교통부 「도로안전시설 설치 및 관리 지침」에서 원호형 길이 3.6m·높이 10cm를 표준으로 하고, 노면 포장재와 같은 재료가 원칙이며 고무·플라스틱 제품은 특수한 경우에 쓸 수 있게 되어 있습니다. 단지·주차장 안이라면 참고 기준입니다."
확인: legalize-kr/admrule-kr (법제처 국가법령정보 Open API 행정규칙 원문 미러, 본문출처 api-text) 2026-11-04 열람 — 발령 제465호·시행 2026-08-14(현행여부 Y), 제4편 2. 과속방지턱 본문 확인 · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 발령 제465호·시행 2026-08-14 번호·시행일 일치(API 본문은 조 단위가 아니어서 조문 대조 없음) + law.go.kr 공식 첨부 PDF((개정전문) 일부개정 26.08.14, flSeq=169584827)의 제4편 기타안전시설 2. 과속방지턱 2.1.2·2.3·2.4.1~2.4.3·2.5.2 본문이 위 요약과 같음(Claude 열람) (api-sync-report-2026-11-05-b.md) · 2026-11-05', NULL, '과속방지턱,방지턱,고무 방지턱,고무방지턱,조립식 방지턱,속도 저감,험프', 0, 800, 'law', '도로안전시설 설치 및 관리 지침 (국토교통부예규 제465호)', '제4편 2. 과속방지턱 — 2.1.2 적용 범위 · 2.3 설치 장소 · 2.4.1 형상 및 제원 · 2.4.2 재료 · 2.5 설치', 'https://www.law.go.kr/행정규칙/도로안전시설설치및관리지침', '2026-08-14', '2026-08-14', '도로법상 도로의 과속방지턱(도로관리청 설치). 아파트 단지·사유지 주차장 안 방지턱은 이 지침의 직접 적용 대상 아님 — 참고 기준', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 도로안전시설 설치 및 관리 지침 제4편 2. 과속방지턱 — 2.1.2 적용 범위 · 2.3 설치 장소 · 2.4.1 형상 및 제원 · 2.4.2 재료 · 2.5 설치');
-- V36
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 하수도법 제2조제1호·제5호·제6호·제7호·제8호·제12호·제13호 (용어 정의 — 하수·오수, 개인하수도, 하수관로, 합류식·분류식, 배수설비, 개인하수처리시설)', '요지: 하수 = 사람의 생활이나 경제활동으로 오염된 물(오수)과 건물·도로 그 밖의 시설물 부지에서 하수도로 유입되는 빗물·지하수(농작물 경작으로 인한 것은 제외 — 제1호). 개인하수도 = 건물·시설 설치자·소유자가 그 건물·시설의 하수를 유출·처리하려고 설치하는 배수설비·개인하수처리시설과 부대시설(제5호). 하수관로 = 하수를 공공하수처리시설 등으로 이송하거나 하천·바다 등으로 유출시키려고 지방자치단체가 설치·관리하는 관로와 부속시설(제6호). 합류식하수관로 = 오수와 하수도로 유입되는 빗물·지하수가 함께 흐르도록 한 하수관로(제7호), 분류식하수관로 = 오수와 빗물·지하수가 각각 구분되어 흐르도록 한 하수관로(제8호). 배수설비 = 건물·시설 등의 하수를 공공하수도에 유입시키려고 설치하는 배수관과 그 밖의 배수시설(제12호). 개인하수처리시설 = 건물·시설 등의 오수를 침전·분해 등으로 처리하는 시설(제13호).
적용 조건: 하수도법에서 쓰는 용어의 뜻(법적 정의) — 공사 의무를 정하는 조항이 아님 / 배수설비 설치·신고 의무는 제27조(V07), 배수설비 구조는 시행규칙 제23조(V08)
주의: 용어 정의만으로 특정 배관을 설치해야 한다고 말하지 않음. 지역별 하수관로 방식(합류식·분류식)을 짐작해 말하지 않음
상담 안내 예: "하수도법에서 오수는 생활하면서 더러워진 물이고, 빗물·지하수와 함께 ''하수''로 정의됩니다. 분류식 하수관로는 오수와 빗물이 따로 흐르게, 합류식은 함께 흐르게 만든 관로입니다(하수도법 제2조). 우리 지역이 어느 방식인지는 관할 지자체 하수도 부서에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러) 2026-11-05 열람 — 현재 시행판(하수도법 21065·시행 2025-10-01, 미러 최신판과 같음, 시행 예정판 없음) 조문 확인. 법제처 OPEN API 대조 기준값(api_targets.json sewer_law 제2조)은 2026-10-28 사용자 PC 대조 일치 · 2026-11-05 사용자 PC 공식 API 재대조 일치(하수도법 21065·제2조, api-sync-report-2026-11-05.md) · 2026-11-05', 'pe_pipe', '오수,오수관,빗물관,우수와 오수,오수와 우수,합류식,분류식,개인하수처리,정화조,하수관로,하수란,하수가 뭐', 0, 800, 'law', '하수도법 (법률 제21065호)', '제2조제1호·제5호·제6호·제7호·제8호·제12호·제13호 (용어 정의 — 하수·오수, 개인하수도, 하수관로, 합류식·분류식, 배수설비, 개인하수처리시설)', 'https://www.law.go.kr/법령/하수도법/제2조', '2025-10-01', '2025-10-01', '오수·빗물(우수)·하수관로·합류식/분류식·배수설비 같은 용어를 설명할 때. 우리 지역이 합류식인지 분류식인지는 관할 지자체 하수도 부서 확인', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 하수도법 제2조제1호·제5호·제6호·제7호·제8호·제12호·제13호 (용어 정의 — 하수·오수, 개인하수도, 하수관로, 합류식·분류식, 배수설비, 개인하수처리시설)');
-- V37
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 주차장법 제2조제1호·제7호 (정의 — 주차장의 종류: 노상·노외·부설주차장, 주차단위구획)', '요지: 주차장 = 자동차 주차를 위한 시설로서 가. 노상주차장(도로의 노면 또는 교차점광장의 일정한 구역에 설치되어 일반의 이용에 제공되는 것) 나. 노외주차장(도로의 노면 및 교통광장 외의 장소에 설치되어 일반의 이용에 제공되는 것) 다. 부설주차장(법 제19조에 따라 건축물, 골프연습장, 그 밖에 주차수요를 유발하는 시설에 부대하여 설치된 주차장으로서 해당 건축물·시설의 이용자 또는 일반의 이용에 제공되는 것) 중 어느 하나(제1호). 주차단위구획 = 자동차 1대를 주차할 수 있는 구획(제7호).
적용 조건: 주차장법에서 쓰는 주차장 종류의 법적 정의 / 주차구획 크기(V01)·부설주차장 진입로(V02) 같은 기준은 이 종류에 해당하는 주차장에 적용
주의: 개인 마당·공터 주차 공간을 법상 주차장으로 단정하지 않음
상담 안내 예: "주차장법에서 부설주차장은 건축물 등에 딸려 설치된 주차장, 노외주차장은 도로 밖에 일반 이용으로 만든 주차장입니다(주차장법 제2조). 집 마당이 건축 때 설치한 부설주차장에 해당하는지는 관할 시·군·구에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러) 2026-11-05 열람 — 현재 시행판(주차장법 21413·시행 2026-08-28, 미러 최신판과 같음) 제2조 확인(개정 표시 2011·2012·2016) · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 주차장법 21413·시행 2026-08-28 제2조 일치 (api-sync-report-2026-11-05-b.md) · 2026-11-05', 'pavement', '부설주차장인,부설주차장에 해당,부설주차장이,부설주차장,노외주차장,노상주차장,주차장 종류,법상 주차장,주차장법상', 0, 800, 'law', '주차장법 (법률 제21413호)', '제2조제1호·제7호 (정의 — 주차장의 종류: 노상·노외·부설주차장, 주차단위구획)', 'https://www.law.go.kr/법령/주차장법/제2조', '2026-08-28', '2026-02-27', '고객의 주차 공간이 주차장법상 어떤 주차장인지(노상·노외·부설) 설명할 때. 집 마당 주차 공간이 법상 부설주차장인지는 관할 시·군·구 확인', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 주차장법 제2조제1호·제7호 (정의 — 주차장의 종류: 노상·노외·부설주차장, 주차단위구획)');
-- V38
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] 하천점용허가 세부기준 제15조 (수로) · 제2조 (적용범위)', '요지: 하천구역 및 홍수관리구역 안의 하천점용허가에 적용(제2조). 수로를 설치할 때 기준(제15조): 1. 제방에 설치하지 않을 것 2. 제방에서 하천 쪽 토지에 가로질러 설치하는 수로의 방향은 홍수 때 물 흐름 방향에 직각을 원칙으로 하고, 비탈면 경사는 완만하게 하고 그 주위에 보호공을 설치할 것 3. 배수를 위한 수로는 물 흐름 방향을 크게 바꾸지 않는 범위에서 연속성을 유지할 것 4. 비탈면 보호공은 하천환경 보전에 적합한 구조로 할 것 5. 수로에 수문 등을 설치하면 제14조(수문·통문) 준용.
적용 조건: 하천구역·홍수관리구역 안에서 하천법 제33조(V31) 점용허가를 받아 수로를 설치하는 경우 / 허가 여부는 하천관리청이 판단 — 이 기준은 허가 심사 기준
주의: 하천구역 밖 사유지 배수로에는 적용하지 않음. 허가 가능 여부를 단정하지 않음
상담 안내 예: "하천구역 안에 배수 수로를 낼 때는 제방에 설치하지 않고, 비탈면을 완만하게 하고 보호공을 두는 등 「하천점용허가 세부기준」 제15조 기준에 맞춰 하천관리청 허가를 받아야 합니다. 공사 전 관할 하천관리청에 먼저 확인하세요."
확인: legalize-kr/admrule-kr (법제처 국가법령정보 Open API 행정규칙 원문 미러, 본문출처 api-text) 2026-11-05 열람 — 발령 제2026-62호·시행 2026-03-09(현행여부 Y), 제2조·제15조 본문 확인 · 2026-11-05 법제처 OPEN API 공식 대조(사용자 PC): 하천점용허가 세부기준 제2026-62호·시행 2026-03-09 제2조·제15조 일치 (api-sync-report-2026-11-05-b.md) · 2026-11-05', 'culvert', '개울로,하천으로,냇가로,하천으로 배수,하천에 연결,하천으로 연결,개울로 배수,개울로 연결,하천 수로,제방', 0, 800, 'law', '하천점용허가 세부기준 (기후에너지환경부고시 제2026-62호)', '제15조 (수로) · 제2조 (적용범위)', 'https://www.law.go.kr/행정규칙/하천점용허가세부기준', '2026-03-09', '2026-03-09', '마당·농지의 배수로를 하천(하천구역)으로 내거나 하천 안에 수로를 설치하는 공사. 소하천은 소하천정비법(V32) 관리청 확인', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람·현행판 대조) · 공식 API 대조(사용자 PC, 2026-11-05)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] 하천점용허가 세부기준 제15조 (수로) · 제2조 (적용범위)');
-- V39
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] KDS 11 80 10 보강토옹벽 1.2 적용 범위 · 3.1.1 전면벽체 · 4.1.1 보강토옹벽 적용기준 · 4.8 배수시설 + KCS 11 80 10 2.1.1 전면벽체 · 2.1.4 블록 속채움재료 · 3.3.2 전면벽체의 기초공', '요지: 보강토옹벽은 금속 또는 지오신세틱스(지오그리드 등) 보강재를 흙 속에 깔아 시공하는 옹벽이고 전면벽체는 블록·패널 등을 쓴다(1.2, 3.1.1). 설계 최소 기준(4.1.1): 보강재 길이는 전면벽체 기초패드부터 벽체높이의 0.7배 이상이면서 최소 2.5m, 보강재 수직 설치간격 0.8m 이하, 최상단 보강재는 벽체 윗면에서 0.5m 이내, 전면벽체는 기초지반에 최소 0.6m 이상 근입(견고한 암반·구조물 위는 예외), 경사지반이면 벽체 앞에 폭 1.2m 이상 소단. 배수(4.8): 전면벽체 배면에 자갈필터층 두께 0.3m 이상, 배수시설 끝은 인근 배수시설에 연결. 시공(KCS 11 80 10): 콘크리트 블록은 KS F 4416 관련 규정 준용, 평균 압축강도 28MPa 이상, 평균 흡수율 7% 이내(각각 10% 이하), 블록 속채움에 재생골재 사용 금지, 전면벽체 기초패드 두께 최소 150mm(앞뒤 여유폭 각 75mm 이상, 높이 10m 미만 블록식은 잡석 기초패드 가능).
적용 조건: 국가건설기준(설계·시공 기술기준) — 실제 보강재 길이·간격은 상재하중·토질을 고려한 설계로 정하며 위 값은 최소 기준 / 보강토옹벽은 블록만 쌓는 구조가 아니라 뒤채움 흙 속 보강재(지오그리드 등)와 함께 시공하는 구조 / 건축물 대지 조성 옹벽은 건축법 시행규칙 제25조, 높이 2m를 넘는 옹벽은 건축법상 공작물 축조신고 대상(건축법 시행령 제118조) — 별도 확인
주의: 개별 상품이 KS F 4416·압축강도 28MPa 기준을 만족한다고 말하지 않음(제품 시험성적서로 확인). 보강재 길이·간격을 고객 대신 설계하지 않음 — 높이 있는 옹벽은 설계·구조 검토 권고
상담 안내 예: "보강토블록 옹벽은 블록만 쌓는 게 아니라 뒤채움 흙 속에 지오그리드 같은 보강재를 함께 까는 구조입니다. 국가건설기준(KDS 11 80 10)은 보강재 길이를 벽 높이의 0.7배 이상(최소 2.5m), 보강재 간격 0.8m 이하, 벽체 묻힘 0.6m 이상, 블록 뒤 자갈 배수층 30cm 이상을 최소로 둡니다. 높이가 있는 옹벽은 설계·구조 검토를 받으세요."
확인: 국가건설기준센터 OpenAPI 원문(CodeViewer KDS 118010 판 2025·갱신 2025-12-29, KCS 118010 판 2025·갱신 2025-12-30) 2026-11-05 조회 — 해당 절 원문 대조(Claude, 사용자 발급 인증키 KCSC_API_KEY) · 2026-11-05', NULL, '보강토블록,보강토 블록,보강토옹벽,보강토 옹벽,지오그리드,보강재,블록으로 옹벽,블록 옹벽,보강토', 0, 800, 'law', 'KDS 11 80 10 보강토옹벽 (국가건설기준 설계기준 KDS 11 80 10:2025 (국가건설기준센터 갱신 2025-12-29) + 표준시방서 KCS 11 80 10:2025 보강토옹벽공사(갱신 2025-12-30))', '1.2 적용 범위 · 3.1.1 전면벽체 · 4.1.1 보강토옹벽 적용기준 · 4.8 배수시설 + KCS 11 80 10 2.1.1 전면벽체 · 2.1.4 블록 속채움재료 · 3.', 'https://www.kcsc.re.kr/', NULL, NULL, '보강재(금속·지오신세틱스)를 쓰는 보강토옹벽의 설계·시공 기술기준. 마당·조경용 낮은 쌓기에 그대로 의무 적용되는지는 설계자·관할 확인', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', 'Claude(국가건설기준센터 OpenAPI 원문 대조)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] KDS 11 80 10 보강토옹벽 1.2 적용 범위 · 3.1.1 전면벽체 · 4.1.1 보강토옹벽 적용기준 · 4.8 배수시설 + KCS 11 80 10 2.1.1 전면벽체 · 2.1.4 블록 속채움재료 · 3.3.2 전면벽체의 기초공');
-- V40
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] KDS 11 80 25 돌(블록)쌓기옹벽 1.2 적용 범위 · 4.1 설계 일반사항 · 4.2 기초 · 4.7 배수시설', '요지: 자연석·다듬은 돌을 메쌓기·찰쌓기로 쌓는 돌쌓기옹벽과 콘크리트 블록을 쌓는 블록쌓기옹벽의 설계에 적용(뒤채움에 보강재를 넣는 옹벽은 KDS 11 80 10 보강토옹벽)(1.2). 전면벽체가 큰 토압에 충분히 저항하기 어려워 배면지반 침하·수평변위 문제가 없고 토압이 작은 소규모 옹벽에 적용(4.1(1)). 설계 시 안정해석으로 안정성 확보가 원칙이며, 벽체 높이가 한계높이(메쌓기 1.5m, 찰쌓기 3.0m) 미만인 돌쌓기옹벽이면서 벽체 기울기(수직:수평)가 1:0.3보다 완만하고, 지반 지지력이 평판재하시험 등으로 확인되고, 수압·부력이 작용하지 않게 배수 계획을 세운 경우에만 발주자와 협의해 안정해석 생략 여부를 정함(4.1(2)). 기초는 콘크리트 직접기초 원칙, 최소 두께 150mm, 기초폭은 전면벽체 폭보다 크게(4.2). 찰쌓기 돌쌓기옹벽은 하단에 약 2㎡마다 배수구멍 1개(4.7).
적용 조건: 국가건설기준(설계 기술기준) — 토압이 작은 소규모 옹벽 대상 / 한계높이는 안정해석 생략을 검토할 수 있는 조건 중 하나이며 그 높이까지 쌓아도 된다는 허용 높이가 아님 / 건축물 대지 조성 옹벽은 건축법 시행규칙 제25조(2m 이상은 콘크리트구조 또는 별표 6 석축 기준), 2m를 넘으면 공작물 축조신고 — 별도 확인
주의: 한계높이(메쌓기 1.5m·찰쌓기 3.0m)를 "그 높이까지 쌓아도 된다"는 뜻으로 말하지 않음. 보강토블록(보강재 사용)에는 KDS 11 80 10 을 씀
상담 안내 예: "돌이나 블록을 그냥 쌓는 옹벽은 국가건설기준(KDS 11 80 25)상 흙의 압력이 작은 소규모 옹벽에 쓰는 방식입니다. 콘크리트 기초는 최소 150mm 두께로 하고, 모르타르로 붙이는 찰쌓기는 아래쪽에 약 2㎡마다 배수구멍을 둡니다. 높아지면 안정 검토가 필요하니 설계를 확인하세요."
확인: 국가건설기준센터 OpenAPI 원문(CodeViewer KDS 118025 판 2025·갱신 2025-12-29) 2026-11-05 조회 — 해당 절 원문 대조(Claude, 사용자 발급 인증키 KCSC_API_KEY) · 2026-11-05', NULL, '돌쌓기,돌 쌓기,돌을 쌓,석축 쌓,메쌓기,찰쌓기,블록쌓기 옹벽,블록 쌓기 옹벽,블록으로 쌓은 옹벽,조경석 쌓,자연석 쌓', 0, 800, 'law', 'KDS 11 80 25 돌(블록)쌓기옹벽 (국가건설기준 설계기준 KDS 11 80 25:2025 (국가건설기준센터 갱신 2025-12-29))', '1.2 적용 범위 · 4.1 설계 일반사항 · 4.2 기초 · 4.7 배수시설', 'https://www.kcsc.re.kr/', NULL, NULL, '돌쌓기(메쌓기·찰쌓기)·콘크리트 블록쌓기 옹벽의 설계 기술기준. 조경용 낮은 쌓기에 그대로 의무 적용되는지는 설계자·관할 확인', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', 'Claude(국가건설기준센터 OpenAPI 원문 대조)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] KDS 11 80 25 돌(블록)쌓기옹벽 1.2 적용 범위 · 4.1 설계 일반사항 · 4.2 기초 · 4.7 배수시설');
-- V41
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] KDS 11 70 10 비탈면 보호공법 1.2.1 격자블록 및 돌(블록) 붙이기 · 4.1.2 적용기준 · 4.1.5 돌(블록)붙이기 공법의 배수시설', '요지: 비가 올 때 비탈면 유실·세굴을 막으려고 비탈면 표면에 설치하는 격자블록공법·돌(블록)붙이기 설계에 적용(1.2.1). 격자블록공법은 비탈면 표면 침식·유실을 막고 격자 안 채움재를 보호하는 것이 목적이며, 원칙적으로 토압에는 저항할 수 없는 것으로 보아 미끄러짐·붕괴 위험이 있는 비탈면에는 적합하지 않음. 프리캐스트 격자블록은 비탈면 경사가 1:1.0보다 완만한 구간(현장타설식은 1:0.8보다 완만), 연속 시공 높이 10m 미만(넘으면 폭 1m 이상 소단). 돌(블록)붙이기는 경사 1:1.0보다 완만한 비탈면에서 점착력 없는 사질토·무너지기 쉬운 점성토, 표면수로 유실·세굴되기 쉬운 구간에 적용(4.1.2). 배수(4.1.5): 배면을 일정 두께 자갈로 뒤채움(세립분 유출 우려 시 필터재), 하단 배수구멍 약 2㎡당 1개, 지하수위 저하용 수평배수공, 상부 표면수 유입 방지용 콘크리트 피복·배수로.
적용 조건: 국가건설기준(설계 기술기준) — 비탈면 표면 보호 공법 / 붙이기·격자블록은 흙의 압력(토압)을 막는 옹벽이 아님 — 흙을 막아야 하면 옹벽(보강토옹벽 KDS 11 80 10, 돌(블록)쌓기옹벽 KDS 11 80 25)
주의: 격자블록·블록붙이기를 흙막이·옹벽 대용으로 권하지 않음. 비탈면 안정(붕괴 위험)을 판단하지 않음
상담 안내 예: "비탈면에 깔아 흙이 쓸려 내려가는 걸 막는 격자블록·블록붙이기는 국가건설기준(KDS 11 70 10)상 표면 보호용이라 흙의 압력을 막는 옹벽 역할은 못 합니다. 경사가 1:1.0(45°)보다 완만한 비탈에 쓰고, 아래쪽에 약 2㎡마다 배수구멍을 둡니다."
확인: 국가건설기준센터 OpenAPI 원문(CodeViewer KDS 117010 판 2025·갱신 2025-12-29) 2026-11-05 조회 — 해당 절 원문 대조(Claude, 사용자 발급 인증키 KCSC_API_KEY) · 2026-11-05', NULL, '비탈면,법면,격자블록,블록 붙이,블록붙이,돌붙임,비탈에 블록,경사지에 블록,비탈 보호,경사면 보호,흙이 쓸려,흙이 흘러,경사면 흙', 0, 800, 'law', 'KDS 11 70 10 비탈면 보호공법 (국가건설기준 설계기준 KDS 11 70 10:2025 (국가건설기준센터 갱신 2025-12-29))', '1.2.1 격자블록 및 돌(블록) 붙이기 · 4.1.2 적용기준 · 4.1.5 돌(블록)붙이기 공법의 배수시설', 'https://www.kcsc.re.kr/', NULL, NULL, '깎기·쌓기 비탈면 표면 보호(격자블록·돌(블록)붙이기) 설계 기술기준. 마당 경사면 소규모 시공은 참고', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', 'Claude(국가건설기준센터 OpenAPI 원문 대조)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] KDS 11 70 10 비탈면 보호공법 1.2.1 격자블록 및 돌(블록) 붙이기 · 4.1.2 적용기준 · 4.1.5 돌(블록)붙이기 공법의 배수시설');
-- V42
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] KDS 61 40 00 관로시설 설계기준 2.1 관의 종류 · 2.3 최소관경 · 3.2 매설깊이 · 4.3 관로기초공', '요지: 하수관은 철근콘크리트관(원심력철근콘크리트관(흄관)·PC관·VR관 등), 도관, 합성수지관(경질염화비닐관·폴리에틸렌관), 덕타일주철관, 파형강관 등을 매설 특성에 따라 사용(2.1). 최소관경은 오수관로 200mm, 우수관로·합류관로 250mm를 표준(장래 하수량 증가 계획이 없는 초기 오수관로는 국지적으로 150mm 제한 사용)(2.3). 관로 최소 흙두께는 원칙 1m(연결관·노면하중·동결심도 등 고려, 얕아지는 곳에 진동 영향이 있으면 안전성 검토·고강도관·보강·방호공 검토)(3.2). 기초(4.3): 철근콘크리트관 등 강성관은 모래·쇄석·콘크리트 등 기초(지반 양호하면 생략 가능), 모래기초 두께는 관 하단 최소 100~200mm 또는 관 외경의 0.2~0.25배, 모래는 염화물 함유량 허용값 이하. 경질염화비닐관·폴리에틸렌관 등 연성관은 자유받침 모래기초가 원칙, 관 하단 모래 100~300mm, 관 상단 200mm 이상은 모래 등 양질 토사로 다짐, 기초받침각 360°가 바람직.
적용 조건: 공공하수도 관로시설의 설계 기술기준(지자체 등 공공 공사) / 건물·대지의 배수설비(개인하수도)는 하수도법 시행규칙 제23조·관할 조례와 이 기준 15장(배수설비)
주의: 개인 마당 배수관에 흙두께 1m·관경 200mm를 의무로 말하지 않음. 관 강도·하중을 계산해 주지 않음
상담 안내 예: "공공 하수관 설계기준(KDS 61 40 00)에서는 흄관 같은 단단한 관은 아래에 모래 기초를 100~200mm 이상, PE·PVC 같은 휘는 관은 아래 모래 100~300mm에 관 위 200mm 이상을 모래 등 좋은 흙으로 덮어 다지도록 하고, 흙 덮는 깊이는 원칙 1m입니다. 마당 배수관이면 참고로 보시고 관할 지자체 기준을 확인하세요."
확인: 국가건설기준센터 OpenAPI 원문(CodeViewer KDS 614000 판 2025·갱신 2026-08-03) 2026-11-05 조회 — 해당 절 원문 대조(Claude, 사용자 발급 인증키 KCSC_API_KEY) · 2026-11-05', 'pe_pipe', '하수관 매설,관 매설,관을 묻,관 묻,배관을 묻,흄관 묻,PE관 묻,PVC관 묻,관 기초,관로 기초,관 아래 모래,관 밑에 모래,흙두께,흙 두께,하수관로', 0, 800, 'law', 'KDS 61 40 00 관로시설 설계기준 (국가건설기준 설계기준 KDS 61 40 00:2025 (국가건설기준센터 갱신 2026-08-03))', '2.1 관의 종류 · 2.3 최소관경 · 3.2 매설깊이 · 4.3 관로기초공', 'https://www.kcsc.re.kr/', NULL, NULL, '공공하수도 관로(오수·우수·합류관로) 설계. 개인 마당·건물 배수관은 참고', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', 'Claude(국가건설기준센터 OpenAPI 원문 대조)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] KDS 61 40 00 관로시설 설계기준 2.1 관의 종류 · 2.3 최소관경 · 3.2 매설깊이 · 4.3 관로기초공');
-- V43
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
SELECT '[법령] KDS 61 40 00 관로시설 설계기준 15.2 배수관 · 15.3 물받이 (배수설비)', '요지: 배수설비의 배수관은 암거로 하되 우수만 배수하면 개거도 가능, 관은 도관·철근콘크리트관·경질염화비닐관 등 내구성 있는 것(15.2(1)). 오수관 크기(배수인구): 150명 이하 100mm 이상, 300명 이하 150mm 이상, 600명 이하 200mm 이상, 1,000명 이하 250mm 이상. 합류관·우수관 크기(배수면적): 200㎡ 미만 100mm 이상, 600㎡ 미만 150mm 이상, 1,200㎡ 미만 200mm 이상(1,200㎡ 이상은 관경 또는 개수를 늘림). 일부 배제를 위한 연장 3m 미만 지관은 75mm 가능(15.2(2)). 물받이(빗물받이·오수받이)는 관로의 기점·종점·합류점·굴곡점, 신설관과 기존관 연결지점, 관 내경·경사·관종이 바뀌는 곳에 설치(곤란하면 청소구), 크기는 내경 또는 내부치수 30cm 이상 원형·각형(벽돌·콘크리트·철근콘크리트제 등), 깊이별로 30~60cm 미만 30cm, 60~90cm 미만 40cm, 90~120cm 미만 50cm, 120~150cm 미만 60cm(15.3).
적용 조건: 같은 관경표·물받이 기준이 하수도법 시행규칙 별표 7(V08, 법령)에 있음 — 법령 근거를 우선하고 이 기준은 깊이별 물받이 크기 등 보충으로 / 건물·대지 배수설비 설계 기술기준 — 법정 기준은 하수도법 시행규칙 제23조(배수설비 설치·구조기준)와 관할 지자체 하수도 조례이며 다르면 그쪽을 따름 / 배수인구·배수면적은 고객이 알려 준 값으로 표를 읽어 주는 것만(대신 산정하지 않음)
주의: 조례·관할청 기준보다 우선한다고 말하지 않음. 공공 하수관 최소관경(오수 200mm)과 섞지 않음
상담 안내 예: "건물·마당 배수설비 설계기준(KDS 61 40 00 15장)으로 보면 빗물·합류 배수관은 배수면적 200㎡ 미만이면 100mm 이상, 600㎡ 미만이면 150mm 이상이고, 물받이는 관이 시작·끝나거나 꺾이고 합쳐지는 곳에 안지름 30cm 이상(깊이에 따라 더 크게) 둡니다. 관할 지자체 하수도 조례가 따로 있으면 그 기준을 따르세요."
확인: 국가건설기준센터 OpenAPI 원문(CodeViewer KDS 614000 판 2025·갱신 2026-08-03) 2026-11-05 조회 — 해당 절 원문 대조(Claude, 사용자 발급 인증키 KCSC_API_KEY) · 2026-11-05', 'pe_pipe', '배수관 관경은,배수관 크기는,배수관 굵기는,오수관 관경은,오수관 크기는,우수관 관경은,우수관 크기는,관경은 얼마,관경을 얼마,관 굵기는,몇 mm 관을,몇mm 관을,몇 mm짜리 관,물받이 크기,물받이 위치,오수받이 크기,오수받이 위치,빗물받이 크기,받이 크기,받이 위치', 0, 800, 'law', 'KDS 61 40 00 관로시설 설계기준 (국가건설기준 설계기준 KDS 61 40 00:2025 (국가건설기준센터 갱신 2026-08-03))', '15.2 배수관 · 15.3 물받이 (배수설비)', 'https://www.kcsc.re.kr/', NULL, NULL, '건물·대지의 배수설비(개인하수도) 배수관 관경·물받이 설치 위치·크기 참고', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', 'Claude(국가건설기준센터 OpenAPI 원문 대조)', '2026-11-05' FROM DUAL
 WHERE NOT EXISTS (SELECT 1 FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND title = '[법령] KDS 61 40 00 관로시설 설계기준 15.2 배수관 · 15.3 물받이 (배수설비)');

COMMIT;
