-- ⚠️ 초안 — 운영 실행 금지(승인 전). 01_rag_documents_source_columns_DRAFT.sql 적용 후에만 실행 가능.
DRAFT_STOP_승인_전_실행_금지;  -- 안전장치: 이 줄이 있으면 실행 즉시 문법 오류로 중단됩니다. 승인 후 이 줄만 지우고 실행.
-- 생성: scripts/legal-sources/build_legal_sql.php · 원본: candidates.json (36건, 웹 검색 기반 후보 · 원문 미확인)
-- 모든 행 is_active=0 → AI 상담에 쓰이지 않음. 원문 확인·검토 후 관리자 화면에서 칸을 채우고 활성화.
-- 기존 5건(id 1~5)은 건드리지 않음(INSERT 만).

START TRANSACTION;
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 주차장법 시행규칙', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 주차장법 시행규칙
번호: 제3조(주차장의 주차구획) 제1항 — 평행주차형식 외 일반형 주차단위구획 (입법예고 자료상 ''안 제3조제1항제2호'')
발행: 국토교통부(국토교통부령)
관련 자재: 주차장 구획선(도료), 주차블록·카스토퍼, 주차장 포장재(인터로킹·투수블록)
적용 범위(검색 기준): 주차장법상 노상·노외·부설주차장의 주차단위구획 최소 크기. 시행일 이후 신축·설치 시설에만 적용(기존 시설·허가 신청분은 종전 규정). 개인 마당의 비법정 주차공간에는 적용 대상 다름(법정 부설주차장인지 여부에 따라 달라짐). 지자체 조례 별도 확인 필요
시행·개정일(검색 기준, 미확인): 구획 확대 개정 시행: 2019년 3월 예정(국토부 보도자료 ''시행시기 1년간 조정'', molit.go.kr). 검색 요약에 ''2026.7.27 시행 일부개정본''이 언급됨(law.go.kr 결과, 원문 미확인)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '주차장 구획선(도료), 주차블록·카스토퍼, 주차장 포장재(인터로킹·투수블록)', 0, 900, 'law', '주차장법 시행규칙', '제3조(주차장의 주차구획) 제1항 — 평행주차형식 외 일반형 주차단위구획 (입법예고 자료상 ''안 제3조제1항제2호'')', 'https://www.law.go.kr/lsLinkCommonInfo.do?lspttninfSeq=81561&chrClsCd=010202', '주차장법상 노상·노외·부설주차장의 주차단위구획 최소 크기. 시행일 이후 신축·설치 시설에만 적용(기존 시설·허가 신청분은 종전 규정). 개인 마당의 비법정 주차공간에는 적용 대상 다름(법정 부설주차장인지 여부에 따라 달라짐). 지자체 조례 별도 확인 필요', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙
번호: [별표 1] 편의시설의 구조·재질등에 관한 세부기준(제2조제1항 관련) — 점자블록 항목
발행: 보건복지부(보건복지부령)
관련 자재: 점자블록(점형·선형), 보도블록 접근로
적용 범위(검색 기준): 편의증진법 대상시설(공공건물·공중이용시설·공원 등)에 설치하는 편의시설. 검색 요약상 점자블록 0.3m×0.3m, 점형 36개 돌출점, 선형 4개 돌출선, 황색 원칙, 매립식 원칙 등이 언급됨(원문 미확인). 개인 주택 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 별표 1 <개정 2023. 12. 11.> 판본 확인(law.go.kr 파일명). 2022.7.26 판본도 존재. 이후 개정 여부 미확인
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점자블록(점형·선형), 보도블록 접근로', 0, 900, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙', '[별표 1] 편의시설의 구조·재질등에 관한 세부기준(제2조제1항 관련) — 점자블록 항목', 'https://www.law.go.kr/flDownload.do?flSeq=135226913', '편의증진법 대상시설(공공건물·공중이용시설·공원 등)에 설치하는 편의시설. 검색 요약상 점자블록 0.3m×0.3m, 점형 36개 돌출점, 선형 4개 돌출선, 황색 원칙, 매립식 원칙 등이 언급됨(원문 미확인). 개인 주택 마당은 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령
번호: [별표 2] 대상시설별 편의시설의 종류 및 설치기준(제4조 관련)
발행: 보건복지부(대통령령)
관련 자재: 점자블록, 보도(공원 내 보도 등)
적용 범위(검색 기준): 어떤 대상시설에 점자블록 등 편의시설을 설치해야 하는지(설치 의무 대상) 규정. 공원 점자블록 설치범위 불명확하다는 법제처 해석례 존재. 포털에서 검색
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 중간
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점자블록, 보도(공원 내 보도 등)', 0, 900, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행령', '[별표 2] 대상시설별 편의시설의 종류 및 설치기준(제4조 관련)', 'https://www.law.go.kr', '어떤 대상시설에 점자블록 등 편의시설을 설치해야 하는지(설치 의무 대상) 규정. 공원 점자블록 설치범위 불명확하다는 법제처 해석례 존재. 포털에서 검색', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 교통약자의 이동편의 증진법 시행규칙', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 교통약자의 이동편의 증진법 시행규칙
번호: 제2조제1항, [별표 1] 이동편의시설의 구조·재질 등에 관한 세부기준
발행: 국토교통부(국토교통부령)
관련 자재: 점자블록, 보도 턱낮춤(경계석), 보행 접근로
적용 범위(검색 기준): 교통수단·여객시설·도로(보행 관련) 등 교통약자법 대상시설. 검색 요약상 점형블록 돌기 높이 0.6±0.1cm 언급(원문 미확인). 도로관리청·교통사업자 대상이며 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 별표 1 <개정 2024. 12. 26.> 판본 확인(law.go.kr). 검색 요약상 시행규칙 [시행 2026.02.01.][국토교통부령 제1558호] 판본 언급(nepla.ai, 2차)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점자블록, 보도 턱낮춤(경계석), 보행 접근로', 0, 900, 'law', '교통약자의 이동편의 증진법 시행규칙', '제2조제1항, [별표 1] 이동편의시설의 구조·재질 등에 관한 세부기준', 'https://www.law.go.kr/LSW/flDownload.do?flSeq=155311565&bylClsCd=110201', '교통수단·여객시설·도로(보행 관련) 등 교통약자법 대상시설. 검색 요약상 점형블록 돌기 높이 0.6±0.1cm 언급(원문 미확인). 도로관리청·교통사업자 대상이며 개인 마당은 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 보행안전 및 편의증진에 관한 법률 시행규칙', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 보행안전 및 편의증진에 관한 법률 시행규칙
번호: [별표 1] 보행안전 및 편의증진 시설의 구조 및 기준(제5조제2항 관련)
발행: 행정안전부(추정 — 검색에서 확인 안 됨)
관련 자재: 보도블록, 점자블록, 보행 안전시설
적용 범위(검색 기준): 보행환경 개선지구 등 보행안전 시설. 세부 내용은 검색에서 확인 안 됨. 공공 보행공간 대상으로 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 중간
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도블록, 점자블록, 보행 안전시설', 0, 900, 'law', '보행안전 및 편의증진에 관한 법률 시행규칙', '[별표 1] 보행안전 및 편의증진 시설의 구조 및 기준(제5조제2항 관련)', 'https://www.law.go.kr/LSW/flDownload.do?gubun=&flSeq=116980749&bylClsCd=110201', '보행환경 개선지구 등 보행안전 시설. 세부 내용은 검색에서 확인 안 됨. 공공 보행공간 대상으로 개인 마당은 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 보도 설치 및 관리 지침', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 보도 설치 및 관리 지침
번호: 국토교통부예규 제321호(행정규칙)
발행: 국토교통부
관련 자재: 보도블록(블록포장), 경계석(연석), 점자블록, 보도 포장
적용 범위(검색 기준): 도로관리청이 설치하는 보도(공공 도로)의 유효폭·횡단경사·포장 기준. 전면개정안에 블록포장/아스팔트/콘크리트/기타포장 구분과 공법별 시공기준 제시(행정예고 자료). 개인 마당·사유지 진입로는 적용 대상 다름
시행·개정일(검색 기준, 미확인): [시행 2021. 7. 23.] 국토교통부예규 제321호, 일부개정(유효기간→재검토기한 변경) — law.go.kr 검색 요약. 2018년 전면개정안 행정예고 자료(molit.go.kr idx=15594)는 별도
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도블록(블록포장), 경계석(연석), 점자블록, 보도 포장', 0, 900, 'law', '보도 설치 및 관리 지침', '국토교통부예규 제321호(행정규칙)', 'https://www.law.go.kr/admRulLsInfoP.do?chrClsCd=010202&admRulSeq=2100000203352', '도로관리청이 설치하는 보도(공공 도로)의 유효폭·횡단경사·포장 기준. 전면개정안에 블록포장/아스팔트/콘크리트/기타포장 구분과 공법별 시공기준 제시(행정예고 자료). 개인 마당·사유지 진입로는 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 도로의 구조ㆍ시설 기준에 관한 규칙', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 도로의 구조ㆍ시설 기준에 관한 규칙
번호: 조문 번호 미확인(보도·길어깨·배수 관련 조문 — 제12조 길어깨만 확인)
발행: 국토교통부(국토교통부령)
관련 자재: 보도, 경계석(연석), 측구, 배수시설
적용 범위(검색 기준): 「도로법」상 도로의 구조·시설 기준. 연석 높이·측구 등 세부 수치는 검색에서 확인 안 됨. 공공 도로 대상이며 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 국토교통부령 제1360호, 2024. 7. 10. 일부개정(law.go.kr 검색 요약). 이후 개정 여부 미확인
검색 근거 신뢰도: 중간
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도, 경계석(연석), 측구, 배수시설', 0, 900, 'law', '도로의 구조ㆍ시설 기준에 관한 규칙', '조문 번호 미확인(보도·길어깨·배수 관련 조문 — 제12조 길어깨만 확인)', 'https://law.go.kr/LSW/lsRvsDocListP.do?chrClsCd=010102&lsId=007080', '「도로법」상 도로의 구조·시설 기준. 연석 높이·측구 등 세부 수치는 검색에서 확인 안 됨. 공공 도로 대상이며 개인 마당은 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 도로와 다른 시설의 연결에 관한 규칙', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 도로와 다른 시설의 연결에 관한 규칙
번호: 정의 조항(연석 정의) — 조문 번호 검색에서 확인 안 됨
발행: 국토교통부(국토교통부령)
관련 자재: 경계석(연석), 진입로
적용 범위(검색 기준): ''연석이란 보도 등과 차도의 경계선에 연접하여 설치하는 경계석'' 정의(2차 사이트 요약). 도로에 진입로 등을 연결할 때 적용 — 사유지 진입로 연결 시 관련 가능. 포털에서 검색
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 낮음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '경계석(연석), 진입로', 0, 900, 'law', '도로와 다른 시설의 연결에 관한 규칙', '정의 조항(연석 정의) — 조문 번호 검색에서 확인 안 됨', 'https://www.law.go.kr', '''연석이란 보도 등과 차도의 경계선에 연접하여 설치하는 경계석'' 정의(2차 사이트 요약). 도로에 진입로 등을 연결할 때 적용 — 사유지 진입로 연결 시 관련 가능. 포털에서 검색', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 하수도법 시행규칙', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 하수도법 시행규칙
번호: 제6조 관련 [별표 5] 공공하수도의 구조에 관한 기술적 기준
발행: 환경부(검색 결과상 최신 판본에 ''기후에너지환경부장관'' 표기)
관련 자재: 빗물받이, 맨홀(뚜껑 재질), 하수관로(흄관·PE·PVC관)
적용 범위(검색 기준): 공공하수도 구조 기준. 검색 요약상 빗물받이 간격 10m 이상 30m 이하(상습침수지역 10m 미만 가능), 맨홀은 방향·경사·관경 변화점 등에 설치, 뚜껑은 주철제·철근콘크리트제 등 충분한 강도. 공공하수도 대상이며 개인하수도·마당 배수와는 적용 대상 다름
시행·개정일(검색 기준, 미확인): 별표 5 <개정 2025. 10. 1.> 판본 확인(law.go.kr 파일 제목). 구판 <개정 2014.7.17>도 존재
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '빗물받이, 맨홀(뚜껑 재질), 하수관로(흄관·PE·PVC관)', 0, 900, 'law', '하수도법 시행규칙', '제6조 관련 [별표 5] 공공하수도의 구조에 관한 기술적 기준', 'https://www.law.go.kr/flDownload.do?gubun=&flSeq=160140833&bylClsCd=110201', '공공하수도 구조 기준. 검색 요약상 빗물받이 간격 10m 이상 30m 이하(상습침수지역 10m 미만 가능), 맨홀은 방향·경사·관경 변화점 등에 설치, 뚜껑은 주철제·철근콘크리트제 등 충분한 강도. 공공하수도 대상이며 개인하수도·마당 배수와는 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[법령] 하수관로 유지관리 기준', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 하수관로 유지관리 기준
번호: 환경부고시 제2023-71호(「하수도법」 제4조의4제3항 근거)
발행: 환경부
관련 자재: 맨홀, 빗물받이, 측구, 하수관로
적용 범위(검색 기준): 공공하수도 관리자의 유지관리(점검·청소 주기 등) 기준. 제품 규격 기준 아님. 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 2023. 4. 10. 제정(law.go.kr 고시문 파일). 개정안 행정예고 보도(korea.kr) 있으나 최종 고시 여부 미확인
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', '맨홀, 빗물받이, 측구, 하수관로', 0, 900, 'law', '하수관로 유지관리 기준', '환경부고시 제2023-71호(「하수도법」 제4조의4제3항 근거)', 'https://www.law.go.kr/LSW/admRulLsInfoP.do?admRulSeq=2100000221674', '공공하수도 관리자의 유지관리(점검·청소 주기 등) 기준. 제품 규격 기준 아님. 개인 마당은 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KDS] 하수도설계기준 (KDS 61 00 00)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 하수도설계기준
번호: KDS 61 00 00
발행: 환경부(국가건설기준센터 게재)
관련 자재: 하수관로, 맨홀, 빗물받이, 집수정, 관종(흄관·PE·PVC)
적용 범위(검색 기준): 공공 하수도시설 설계기준. 관로·맨홀·빗물받이 세부 조항은 검색에서 확인 안 됨. 공공사업 대상 — 개인 마당 배수는 적용 대상 다름
시행·개정일(검색 기준, 미확인): 2022. 12. 28. 개정 표기(2차 PDF 표지). law.go.kr 행정규칙 연혁에 2017.10.27, 2019.4.10 일부개정본 노출. 최신본 미확인
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '하수관로, 맨홀, 빗물받이, 집수정, 관종(흄관·PE·PVC)', 0, 900, 'kds', '하수도설계기준', 'KDS 61 00 00', 'https://www.law.go.kr/LSW/admRulInfoP.do?admRulSeq=2100000100709', '공공 하수도시설 설계기준. 관로·맨홀·빗물받이 세부 조항은 검색에서 확인 안 됨. 공공사업 대상 — 개인 마당 배수는 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KDS] 관로시설 설계기준 (KDS 61 40 00)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 관로시설 설계기준
번호: KDS 61 40 00
발행: 환경부
관련 자재: 하수관로, 맨홀(추락방지시설), 빗물받이, 관종
적용 범위(검색 기준): 공공 하수관로 설계. 개정안: 맨홀 뚜껑 이탈 위험 지점 추락방지시설 의무화 등. 하위 번호(KDS 61 40 10 등)는 검색에서 확인 안 됨. 공공사업 대상
시행·개정일(검색 기준, 미확인): 2022년 12월 개정안 행정예고(me.go.kr). 확정 시행일 미확인
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '하수관로, 맨홀(추락방지시설), 빗물받이, 관종', 0, 900, 'kds', '관로시설 설계기준', 'KDS 61 40 00', 'http://me.go.kr/home/web/board/read.do?boardMasterId=39&boardId=1566950&menuId=10524', '공공 하수관로 설계. 개정안: 맨홀 뚜껑 이탈 위험 지점 추락방지시설 의무화 등. 하위 번호(KDS 61 40 10 등)는 검색에서 확인 안 됨. 공공사업 대상', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KDS] 도로설계기준 (KDS 44 00 00 (하위 코드 중 KDS 44 50 25 신설·개정 예고 확인, 제목 미확인))', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 도로설계기준
번호: KDS 44 00 00 (하위 코드 중 KDS 44 50 25 신설·개정 예고 확인, 제목 미확인)
발행: 국토교통부
관련 자재: 보도 포장, 배수시설(측구·빗물받이), 경계석
적용 범위(검색 기준): 도로 설계기준. 포장·배수 관련 하위 코드 번호·내용은 검색에서 확인 안 됨. 공공 도로 대상 — 개인 마당은 적용 대상 다름
시행·개정일(검색 기준, 미확인): 2026. 10. 2. 일부 개정 및 제정 고시(molit.go.kr). 2025.10.24~11.13 행정예고(공고 제2025-1246호)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도 포장, 배수시설(측구·빗물받이), 경계석', 0, 900, 'kds', '도로설계기준', 'KDS 44 00 00 (하위 코드 중 KDS 44 50 25 신설·개정 예고 확인, 제목 미확인)', 'https://www.molit.go.kr/USR/I0204/m_45/dtl.jsp?idx=18977', '도로 설계기준. 포장·배수 관련 하위 코드 번호·내용은 검색에서 확인 안 됨. 공공 도로 대상 — 개인 마당은 적용 대상 다름', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KCS] 도로공사 표준시방서 (KCS 44 00 00 (보도포장 하위 코드 번호는 검색에서 확인 안 됨))', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 도로공사 표준시방서
번호: KCS 44 00 00 (보도포장 하위 코드 번호는 검색에서 확인 안 됨)
발행: 국토교통부
관련 자재: 보도블록 포장, 경계석, 측구, 배수시설
적용 범위(검색 기준): 공공 도로공사 시공 기준. ''KCS 44 50 05 보도포장''이라는 번호는 검색에서 확인되지 않음(행정예고 목록에 KCS 44 30 05, 44 50 25, 44 80 05만 노출, 제목 미확인). 공공공사 대상
시행·개정일(검색 기준, 미확인): 2026. 10. 2. 일부 개정 및 제정 고시(molit.go.kr)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도블록 포장, 경계석, 측구, 배수시설', 0, 900, 'kcs', '도로공사 표준시방서', 'KCS 44 00 00 (보도포장 하위 코드 번호는 검색에서 확인 안 됨)', 'https://www.molit.go.kr/USR/I0204/m_45/dtl.jsp?idx=18977', '공공 도로공사 시공 기준. ''KCS 44 50 05 보도포장''이라는 번호는 검색에서 확인되지 않음(행정예고 목록에 KCS 44 30 05, 44 50 25, 44 80 05만 노출, 제목 미확인). 공공공사 대상', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KCS] 하수관로공사 (KCS 61 10 00)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 하수관로공사
번호: KCS 61 10 00
발행: 환경부(추정 — 검색에서 확인 안 됨)
관련 자재: 하수관(흄관·PE 이중벽관·PVC관), 맨홀
적용 범위(검색 기준): 공공 하수관로 시공 기준. 포털에서 검색. 공공공사 대상
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 중간
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '하수관(흄관·PE 이중벽관·PVC관), 맨홀', 0, 900, 'kcs', '하수관로공사', 'KCS 61 10 00', 'https://www.kcsc.re.kr', '공공 하수관로 시공 기준. 포털에서 검색. 공공공사 대상', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KCS] 하수도관부설 및 연결공사 (KCS 61 20 00)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 하수도관부설 및 연결공사
번호: KCS 61 20 00
발행: 환경부(추정 — 검색에서 확인 안 됨)
관련 자재: 하수관 부설·연결(PE·PVC·흄관), 맨홀 접속
적용 범위(검색 기준): 공공 하수관 부설·연결 시공. 포털에서 검색
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 중간
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '하수관 부설·연결(PE·PVC·흄관), 맨홀 접속', 0, 900, 'kcs', '하수도관부설 및 연결공사', 'KCS 61 20 00', 'https://www.kcsc.re.kr', '공공 하수관 부설·연결 시공. 포털에서 검색', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 보차도용 콘크리트 인터로킹 블록 (KS F 4419)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 보차도용 콘크리트 인터로킹 블록
번호: KS F 4419
발행: 국가기술표준원
관련 자재: 인터로킹 블록, 투수블록(2016 개정에 투수성 시험장치 수정 포함), 주차장 포장 블록
적용 범위(검색 기준): 주로 조립하여 보도·차도·광장·주차장 등의 포장에 사용하는 콘크리트 인터로킹 블록(제품 규격). 제품 품질 기준이므로 사유지 시공에도 제품 선택 근거로 활용 가능
시행·개정일(검색 기준, 미확인): 제정 1991-12-30, 최근 개정 2025-12-16(고시 2025-0646, 품질성능 확보·시험시료 개수 명확화) — standard.go.kr/kssn.net 검색 요약
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '인터로킹 블록, 투수블록(2016 개정에 투수성 시험장치 수정 포함), 주차장 포장 블록', 0, 900, 'ks', '보차도용 콘크리트 인터로킹 블록', 'KS F 4419', 'https://www.standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?menuId=919&topMenuId=502&upperMenuId=503&ksNo=KSF4419&tmprKsNo=KSF4419&reformNo=15', '주로 조립하여 보도·차도·광장·주차장 등의 포장에 사용하는 콘크리트 인터로킹 블록(제품 규격). 제품 품질 기준이므로 사유지 시공에도 제품 선택 근거로 활용 가능', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 시각장애인용 점자블록 (KS F 4561)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 시각장애인용 점자블록
번호: KS F 4561
발행: 국가기술표준원
관련 자재: 점자블록(점형·선형)
적용 범위(검색 기준): 돌기를 표면에 양각시켜 보행 위치·방향 안내 및 위험물 주의 표시를 하는 시각장애인용 점자블록(제품 규격). 설치 위치·의무는 편의증진법·교통약자법 별표에서 규정
시행·개정일(검색 기준, 미확인): 제정 2001-04-20, 최종개정 2022-12-23(standard.go.kr·kssn.net 검색 요약). 인증심사기준 2025-08-22 개정은 표준 본문 개정 아님
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점자블록(점형·선형)', 0, 900, 'ks', '시각장애인용 점자블록', 'KS F 4561', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?menuId=919&topMenuId=502&upperMenuId=503&ksNo=KSF4561&tmprKsNo=KSF4561&reformNo=09', '돌기를 표면에 양각시켜 보행 위치·방향 안내 및 위험물 주의 표시를 하는 시각장애인용 점자블록(제품 규격). 설치 위치·의무는 편의증진법·교통약자법 별표에서 규정', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 포장용 콘크리트 평판 (KS F 4001)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 포장용 콘크리트 평판
번호: KS F 4001
발행: 국가기술표준원
관련 자재: 보도용 평판 블록
적용 범위(검색 기준): 포장용 콘크리트 평판(제품 규격). 세부 범위 검색에서 확인 안 됨
시행·개정일(검색 기준, 미확인): 2021 확인 판본 표기(kssn.net 제목 ''KS F 4001(2021 확인)'')
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '보도용 평판 블록', 0, 900, 'ks', '포장용 콘크리트 평판', 'KS F 4001', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010050193', '포장용 콘크리트 평판(제품 규격). 세부 범위 검색에서 확인 안 됨', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 콘크리트 경계 블록 (KS F 4006)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 콘크리트 경계 블록
번호: KS F 4006
발행: 국가기술표준원
관련 자재: 경계석(콘크리트 경계블록), 인조석 표면층 경계석
적용 범위(검색 기준): 주로 보도 및 차도 또는 도로의 경계부에 사용하는 콘크리트 경계블록(제품 규격). 화강석 경계석은 이 표준 범위 밖일 수 있음(미확인)
시행·개정일(검색 기준, 미확인): 제정 1967-12-30, 최종 일자 2024-12-31(개정/확인 구분 미확인) — standard.go.kr 검색 요약
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '경계석(콘크리트 경계블록), 인조석 표면층 경계석', 0, 900, 'ks', '콘크리트 경계 블록', 'KS F 4006', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSF4006&tmprKsNo=KSF4006&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '주로 보도 및 차도 또는 도로의 경계부에 사용하는 콘크리트 경계블록(제품 규격). 화강석 경계석은 이 표준 범위 밖일 수 있음(미확인)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 무근 및 철근 콘크리트 L형 측구 (KS F 4005)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 무근 및 철근 콘크리트 L형 측구
번호: KS F 4005
발행: 국가기술표준원
관련 자재: L형 측구, 노면 배수
적용 범위(검색 기준): 주로 노면 배수용 측구로 사용되는 콘크리트 및 철근 콘크리트 L형(검색 요약)
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', 'L형 측구, 노면 배수', 0, 900, 'ks', '무근 및 철근 콘크리트 L형 측구', 'KS F 4005', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010124877', '주로 노면 배수용 측구로 사용되는 콘크리트 및 철근 콘크리트 L형(검색 요약)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 도로용 철근 콘크리트 측구 (KS F 4417)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 도로용 철근 콘크리트 측구
번호: KS F 4417
발행: 국가기술표준원
관련 자재: 측구(몸체·뚜껑), 도로 배수
적용 범위(검색 기준): 보도 및 차도에 평행하게 설치하는 철근 콘크리트제 측구의 몸체 및 뚜껑(검색 요약)
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', '측구(몸체·뚜껑), 도로 배수', 0, 900, 'ks', '도로용 철근 콘크리트 측구', 'KS F 4417', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSF4417&tmprKsNo=KSF4417&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '보도 및 차도에 평행하게 설치하는 철근 콘크리트제 측구의 몸체 및 뚜껑(검색 요약)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 철근 콘크리트 플룸 및 벤치 플룸 (KS F 4010)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 철근 콘크리트 플룸 및 벤치 플룸
번호: KS F 4010
발행: 국가기술표준원
관련 자재: 벤치플륨, 플륨형 수로관
적용 범위(검색 기준): 주로 수로로 쓰이는 철근 콘크리트 플룸과 벤치 플룸(제품 규격)
시행·개정일(검색 기준, 미확인): 발행 2024-12-31(kssn.net 검색 요약)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', '벤치플륨, 플륨형 수로관', 0, 900, 'ks', '철근 콘크리트 플룸 및 벤치 플룸', 'KS F 4010', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSF4010&menuId=919&pageIndex=1&pageUnit=10&reformNo=19&tmprKsNo=KSF4010&topMenuId=502&upperMenuId=503', '주로 수로로 쓰이는 철근 콘크리트 플룸과 벤치 플룸(제품 규격)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 철근 콘크리트 U형 (KS F 4016)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 철근 콘크리트 U형
번호: KS F 4016
발행: 국가기술표준원
관련 자재: U형 수로관(U형 측구)
적용 범위(검색 기준): 적용범위·치수 검색에서 확인 안 됨. 포털에서 검색
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 중간
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'culvert', 'U형 수로관(U형 측구)', 0, 900, 'ks', '철근 콘크리트 U형', 'KS F 4016', 'https://standard.go.kr', '적용범위·치수 검색에서 확인 안 됨. 포털에서 검색', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 하수도용 콘크리트 맨홀 블록 (KS F 4012)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 하수도용 콘크리트 맨홀 블록
번호: KS F 4012
발행: 국가기술표준원
관련 자재: 맨홀(콘크리트 맨홀 블록), 집수정
적용 범위(검색 기준): 하수도에 주로 사용하는 철근 콘크리트 맨홀 블록(제품 규격)
시행·개정일(검색 기준, 미확인): 2024-12-31 개정본(kssn.net 검색 요약)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '맨홀(콘크리트 맨홀 블록), 집수정', 0, 900, 'ks', '하수도용 콘크리트 맨홀 블록', 'KS F 4012', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010150139', '하수도에 주로 사용하는 철근 콘크리트 맨홀 블록(제품 규격)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항 (KS D 4040)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항
번호: KS D 4040
발행: 국가기술표준원
관련 자재: 맨홀 뚜껑·틀(주철·주강)
적용 범위(검색 기준): 상수도·하수도·전기·통신용 주철(강)재 맨홀 뚜껑과 틀의 일반 요구사항
시행·개정일(검색 기준, 미확인): 2024-09-13 확인(5년 도래 확인) — kssn.net 검색 요약
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '맨홀 뚜껑·틀(주철·주강)', 0, 900, 'ks', '주철(강)재 맨홀 뚜껑 및 틀의 일반 요구사항', 'KS D 4040', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010099262', '상수도·하수도·전기·통신용 주철(강)재 맨홀 뚜껑과 틀의 일반 요구사항', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 맨홀 뚜껑 및 틀(상하수도·전기·통신용) (KS D 6021)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 맨홀 뚜껑 및 틀(상하수도·전기·통신용)
번호: KS D 6021
발행: 국가기술표준원
관련 자재: 맨홀 뚜껑
적용 범위(검색 기준): 폐지됨. 대체: 단체표준 SPS-KFCAM201-1639(주철·주강재 맨홀 뚜껑 및 틀, 최종개정 2022-07-13) — 검색 요약. 상담 시 ''KS 인증 맨홀뚜껑'' 표현 주의
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 중간
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'manhole', '맨홀 뚜껑', 0, 900, 'ks', '맨홀 뚜껑 및 틀(상하수도·전기·통신용)', 'KS D 6021', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSD6021&tmprKsNo=KSD6021&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '폐지됨. 대체: 단체표준 SPS-KFCAM201-1639(주철·주강재 맨홀 뚜껑 및 틀, 최종개정 2022-07-13) — 검색 요약. 상담 시 ''KS 인증 맨홀뚜껑'' 표현 주의', 'repealed', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 원심력 철근 콘크리트관 (KS F 4403)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 원심력 철근 콘크리트관
번호: KS F 4403
발행: 국가기술표준원
관련 자재: 흄관(원심력 철근콘크리트관) 및 A형 이음 칼라
적용 범위(검색 기준): 원심력 또는 축 전압을 응용하여 만든 철근 콘크리트관 및 직관 A형 이음 칼라
시행·개정일(검색 기준, 미확인): 제정 1971-06-30, 최종개정확인 2024-11-15(standard.go.kr 검색 요약)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', '흄관(원심력 철근콘크리트관) 및 A형 이음 칼라', 0, 900, 'ks', '원심력 철근 콘크리트관', 'KS F 4403', 'https://www.standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSF4403', '원심력 또는 축 전압을 응용하여 만든 철근 콘크리트관 및 직관 A형 이음 칼라', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 진동 및 전압 철근 콘크리트관(제목 원문 미확인) (KS F 4402)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 진동 및 전압 철근 콘크리트관(제목 원문 미확인)
번호: KS F 4402
발행: 국가기술표준원
관련 자재: 철근 콘크리트관(진동·롤 전압 성형)
적용 범위(검색 기준): 진동 코어 및 롤을 사용해 내면을 진동 또는 롤 전압해서 만든 철근 콘크리트관(검색 요약). 정확한 표준명 미확인
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 중간
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', '철근 콘크리트관(진동·롤 전압 성형)', 0, 900, 'ks', '진동 및 전압 철근 콘크리트관(제목 원문 미확인)', 'KS F 4402', 'https://www.standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSF4402', '진동 코어 및 롤을 사용해 내면을 진동 또는 롤 전압해서 만든 철근 콘크리트관(검색 요약). 정확한 표준명 미확인', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 배수 및 하수용 비압력 매설용 구조형 폴리에틸렌(PE)관 — 제1부: 이중벽관 (KS M 3500-1)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 배수 및 하수용 비압력 매설용 구조형 폴리에틸렌(PE)관 — 제1부: 이중벽관
번호: KS M 3500-1
발행: 국가기술표준원
관련 자재: PE 이중벽관(하수·배수용)
적용 범위(검색 기준): 비압력 조건 매설용 배수·하수 구조형 PE 이중벽관(내·외면 평활). 같은 계열 제2부 다중벽관(KS M 3500-2) 등
시행·개정일(검색 기준, 미확인): 2009 제정, 2023-11-14 개정(kssn.net 이력 검색 요약)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PE 이중벽관(하수·배수용)', 0, 900, 'ks', '배수 및 하수용 비압력 매설용 구조형 폴리에틸렌(PE)관 — 제1부: 이중벽관', 'KS M 3500-1', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSM3500-1&tmprKsNo=KSM3500-1&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '비압력 조건 매설용 배수·하수 구조형 PE 이중벽관(내·외면 평활). 같은 계열 제2부 다중벽관(KS M 3500-2) 등', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 배수 및 하수용 비압력 매설용 구조형 폴리염화비닐(PVC)관 — 이중벽관 및 리브관 (KS M 3600)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 배수 및 하수용 비압력 매설용 구조형 폴리염화비닐(PVC)관 — 이중벽관 및 리브관
번호: KS M 3600
발행: 국가기술표준원
관련 자재: PVC 이중벽관·리브관(하수·배수 매설용)
적용 범위(검색 기준): 하수·배수 매설용 구조형 PVC관. 포털에서 검색
시행·개정일(검색 기준, 미확인): 2021 확인 판본 표기(kssn.net 제목)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PVC 이중벽관·리브관(하수·배수 매설용)', 0, 900, 'ks', '배수 및 하수용 비압력 매설용 구조형 폴리염화비닐(PVC)관 — 이중벽관 및 리브관', 'KS M 3600', 'https://standard.go.kr', '하수·배수 매설용 구조형 PVC관. 포털에서 검색', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 비압력용 경질 폴리염화비닐관(구 일반용 경질 폴리염화비닐관) (KS M 3404)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 비압력용 경질 폴리염화비닐관(구 일반용 경질 폴리염화비닐관)
번호: KS M 3404
발행: 국가기술표준원
관련 자재: PVC관(배수·통기·건물 외부 비압력)
적용 범위(검색 기준): 건물 내부 환기·통기·배기·배수 및 건물 외부 비압력 용도 PVC관. 하수관로 매설용은 KS M 3600 등이 더 적합(검색 요약 의견)
시행·개정일(검색 기준, 미확인): 2021-07-07 개정(검색 요약)
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PVC관(배수·통기·건물 외부 비압력)', 0, 900, 'ks', '비압력용 경질 폴리염화비닐관(구 일반용 경질 폴리염화비닐관)', 'KS M 3404', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?ksNo=KSM3404&menuId=919&pageIndex=1&pageUnit=10&reformNo=19&tmprKsNo=KSM3404&topMenuId=502&upperMenuId=503', '건물 내부 환기·통기·배기·배수 및 건물 외부 비압력 용도 PVC관. 하수관로 매설용은 KS M 3600 등이 더 적합(검색 요약 의견)', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 비압력용 경질 폴리염화비닐 이음관 (KS M 3410)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 비압력용 경질 폴리염화비닐 이음관
번호: KS M 3410
발행: 국가기술표준원
관련 자재: PVC 이음관
적용 범위(검색 기준): 비압력용 경질 PVC관 접착 접합용 이음관
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PVC 이음관', 0, 900, 'ks', '비압력용 경질 폴리염화비닐 이음관', 'KS M 3410', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010132913', '비압력용 경질 PVC관 접착 접합용 이음관', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 수도용 경질 폴리염화비닐관 (KS M 3401)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 수도용 경질 폴리염화비닐관
번호: KS M 3401
발행: 국가기술표준원
관련 자재: PVC관(수도용)
적용 범위(검색 기준): 수도용 — 하수·배수용과 구분 필요
시행·개정일(검색 기준, 미확인): 확인 안 됨
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', 'PVC관(수도용)', 0, 900, 'ks', '수도용 경질 폴리염화비닐관', 'KS M 3401', 'https://www.kssn.net/search/stddetail.do?itemNo=K001010123794', '수도용 — 하수·배수용과 구분 필요', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 파형 강관 및 파형 섹션 (KS D 3590)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 파형 강관 및 파형 섹션
번호: KS D 3590
발행: 국가기술표준원
관련 자재: 파형강관(스파이럴형), 파형섹션, 볼트·커플링밴드
적용 범위(검색 기준): 수로·통로·기타 구조물용 파형강관 및 파형섹션과 접합 부품
시행·개정일(검색 기준, 미확인): 제정 1981-11-30, 최종 개정확인 2025-10-01(standard.go.kr 검색 요약). kssn.net에는 2024-09-13 확인 표기 — 출처 간 차이
검색 근거 신뢰도: 높음
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pe_pipe', '파형강관(스파이럴형), 파형섹션, 볼트·커플링밴드', 0, 900, 'ks', '파형 강관 및 파형 섹션', 'KS D 3590', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSD3590&tmprKsNo=KSD3590&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '수로·통로·기타 구조물용 파형강관 및 파형섹션과 접합 부품', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, applies_to, legal_status, author, review_status)
VALUES ('[KS] 점토 벽돌 (KS L 4201)', '[원문 미확인 후보 — AI 상담 미사용]
문서명: 점토 벽돌
번호: KS L 4201
발행: 국가기술표준원
관련 자재: 점토블록(참고 — 포장용 전용 표준 아님)
적용 범위(검색 기준): 점토·고령토·황토 등을 소성한 벽돌. 포장용 점토블록 전용 KS는 검색에서 확인 안 됨 — 점토 바닥 블록에 그대로 적용된다고 단정하지 말 것
시행·개정일(검색 기준, 미확인): 최종 개정확인 2022-12-09(standard.go.kr 검색 요약). 제정일은 출처 간 불일치(1965-12-07 vs 1962-08-28)
검색 근거 신뢰도: 중간
※ 이 칸에는 원문 확인 후 ''상담에 필요한 요지''만 입력합니다(조문 전문 복사 금지, KS 원문 재게시 금지).', 'pavement', '점토블록(참고 — 포장용 전용 표준 아님)', 0, 900, 'ks', '점토 벽돌', 'KS L 4201', 'https://standard.go.kr/KSCI/standardIntro/getStandardSearchView.do?pageIndex=1&pageUnit=10&ksNo=KSL4201&tmprKsNo=KSL4201&reformNo=19&menuId=919&topMenuId=502&upperMenuId=503', '점토·고령토·황토 등을 소성한 벽돌. 포장용 점토블록 전용 KS는 검색에서 확인 안 됨 — 점토 바닥 블록에 그대로 적용된다고 단정하지 말 것', 'unknown', 'AI 조사 초안(검색 기반)', 'unreviewed');
COMMIT;

-- 롤백: DELETE FROM rag_documents WHERE author = 'AI 조사 초안(검색 기반)' AND review_status = 'unreviewed';
