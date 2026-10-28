-- ⚠️ 초안 — 운영 실행 금지(승인 전). 01_rag_documents_source_columns_DRAFT.sql 적용 후에만 실행 가능. MySQL 8.0.46 호환.
DRAFT_STOP_승인_전_실행_금지;  -- 안전장치: 이 줄이 있으면 실행 즉시 문법 오류로 중단됩니다. 승인 후 이 줄만 지우고 실행.
-- 생성: scripts/legal-sources/build_verified_sql.php · 원본: verified_clauses.json (12건, 원문 조문 확인된 법령 근거)
-- 전부 is_active=0, review_status='unreviewed' — 등록만으로는 AI 상담에 쓰이지 않음. 기존 5건·후보 38건은 건드리지 않음(INSERT 만).

START TRANSACTION;
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 주차장법 시행규칙 제3조제1항제2호 (주차장의 주차구획 — 평행주차형식 외)', '요지: 평행주차형식 외 주차단위구획: 경형 너비 2.0m·길이 3.6m 이상, 일반형 2.5m·5.0m 이상, 확장형 2.6m·5.2m 이상, 장애인전용 3.3m·5.0m 이상. (평행주차형식 일반형은 2.0m·6.0m 이상 — 제1호)
적용 조건: 주차장법상 주차장(노상·노외·부설주차장)의 주차단위구획 기준(법 제6조제1항) / 지방자치단체 조례로 달리 정할 수 있음(주차장법 제6조제2항) / 부설주차장 = 건축물 등에 부대하여 설치된 주차장(법 제2조제1호다목). 도시지역 등에서 건축물을 건축할 때 설치 의무(법 제19조제1항)
주의: 단순한 마당·진입로 포장 자체를 법적 의무로 단정하지 않음 — 관할 시·군·구 확인 필요
상담 안내 예: "주차장법 시행규칙 제3조에 따라 일반형 주차 1칸은 너비 2.5m·길이 5.0m 이상(약 12.5㎡)입니다. 집 마당이 법상 부설주차장에 해당하는지에 따라 의무 여부가 달라지니 공사 전 관할 시·군·구에 확인하세요."
확인: 사용자 국가법령정보센터 원문 확인(2026-10-27) + legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) 표 대조 일치 · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 주차장법 시행규칙 01604·시행 2026-07-27 조문 일치 · 2026-10-28', 'pavement', '주차,주차장,주차칸,주차공간,주차구획,차 한 대,차 두 대,주차면', 0, 800, 'law', '주차장법 시행규칙 (국토교통부령 제1604호)', '제3조제1항제2호 (주차장의 주차구획 — 평행주차형식 외)', 'https://www.law.go.kr/법령/주차장법시행규칙/제3조', '2026-07-27', '2026-07-27', '주차장법에 따른 주차장. 개인 주택 마당이 법상 부설주차장인지(건축 허가 시 설치한 주차장인지)에 따라 의무 여부가 달라짐', 'current', '검증 조문(2-1단계)', 'unreviewed', 'original_verified', '사용자(공식 사이트) + Claude(미러 대조) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 주차장법 시행규칙 제11조제5항 (총주차대수 8대 이하 자주식 부설주차장)', '요지: 8대 이하 부설주차장: 차로 너비 2.5m 이상(주차단위구획에 접한 차로는 평행 3.0m·직각 6.0m·60도 4.0m·45도 3.5m·교차 3.5m 이상), 출입구 너비 3m 이상(막다른 도로로서 시장 등이 인정하면 2.5m 이상), 보행 통로 필요 시 시설물과 주차단위구획 사이 0.5m 이상.
적용 조건: 건축물 부설주차장 중 총주차대수 8대 이하 자주식주차장 / 지자체 조례로 달리 정할 수 있음(법 제6조제2항)
주의: 부설주차장에 해당하지 않는 마당 포장에는 의무로 안내하지 않음
상담 안내 예: "건축물에 딸린 8대 이하 부설주차장이라면 출입구 너비 3m 이상(주차장법 시행규칙 제11조제5항) 기준이 있습니다. 해당 여부는 관할 시·군·구에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 주차장법 시행규칙 01604·시행 2026-07-27 조문 일치 · 2026-10-28', 'pavement', '진입로,출입구,주차장 입구,차고,부설주차장,주차장 폭', 0, 800, 'law', '주차장법 시행규칙 (국토교통부령 제1604호)', '제11조제5항 (총주차대수 8대 이하 자주식 부설주차장)', 'https://www.law.go.kr/법령/주차장법시행규칙/제11조', '2026-07-27', '2026-07-27', '소규모 건축물 부설주차장(주택 포함 가능)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제16조제2항제1호·제3항 (보도)', '요지: 보도와 차도를 구분할 때 차도에 접하여 연석을 설치하는 경우 그 높이는 25cm 이하. 보도의 유효폭은 최소 2m 이상(지방지역 도로·도시지역 국지도로에서 불가피한 경우 1.5m 이상).
적용 조건: 「도로법」에 따른 도로를 신설·개량하는 경우의 최소 기준(제1조) / 연석 높이 측정 기준(노출 높이 등)은 원문·설계도로 확인
주의: 사유지 마당·화단 경계석에는 법적 의무로 안내하지 않음
상담 안내 예: "공공 도로에서 차도에 접한 연석(경계석)은 높이 25cm 이하로 설치하도록 정해져 있습니다(도로의 구조·시설 기준에 관한 규칙 제16조). 개인 부지 공사는 이 규칙의 직접 적용 대상이 아닙니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 도로의 구조ㆍ시설 기준에 관한 규칙 01588·시행 2026-06-03 조문 일치 · 2026-10-28', 'pavement', '연석,경계석,보도,인도,보도 폭,인도 폭,턱 높이', 0, 800, 'law', '도로의 구조ㆍ시설 기준에 관한 규칙 (국토교통부령 제1588호)', '제16조제2항제1호·제3항 (보도)', 'https://www.law.go.kr/법령/도로의구조ㆍ시설기준에관한규칙/제16조', '2026-06-03', '2026-06-02', '도로법상 도로(공공 도로) 공사', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제29조제1항 (포장)', '요지: 차로·측대·길어깨·보도·자전거도로는 안정성·시공성 등을 고려하여 적절한 재료와 두께로 포장. 블록 두께 수치는 규정하지 않음.
적용 조건: 도로법상 도로 공사
주의: 블록 두께(60T·80T 등)의 법적 근거로 인용 금지 — 수치 없음
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 도로의 구조ㆍ시설 기준에 관한 규칙 01588·시행 2026-06-03 조문 일치 · 2026-10-28', 'pavement', NULL, 0, 800, 'law', '도로의 구조ㆍ시설 기준에 관한 규칙 (국토교통부령 제1588호)', '제29조제1항 (포장)', 'https://www.law.go.kr/법령/도로의구조ㆍ시설기준에관한규칙/제29조', '2026-06-03', '2026-06-02', '공공 도로', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 도로의 구조ㆍ시설 기준에 관한 규칙 제30조제1항·제3항 (배수시설)', '요지: 도로에는 측구·집수정·도수로 등 적절한 배수시설을 설치하고, 규격은 강우 지속시간·강도·지형에 따라 결정. 길어깨에 붙여 측구를 설치하는 경우 교통안전을 위해 윗면이 열린 측구를 설치하지 않음.
적용 조건: 도로법상 도로 공사 / "윗면이 열린 측구 금지"는 길어깨에 붙여 설치하는 경우
주의: 개인 부지 배수로에 의무로 안내하지 않음
상담 안내 예: "공공 도로의 길어깨에 붙여 설치하는 측구는 윗면이 열린 형태로 두지 않도록 정해져 있어(도로의 구조·시설 기준에 관한 규칙 제30조), 덮개나 그레이팅을 함께 씁니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 도로의 구조ㆍ시설 기준에 관한 규칙 01588·시행 2026-06-03 조문 일치 · 2026-10-28', 'culvert', '측구,배수로,도로 배수,길어깨,덮개,뚜껑,집수정', 0, 800, 'law', '도로의 구조ㆍ시설 기준에 관한 규칙 (국토교통부령 제1588호)', '제30조제1항·제3항 (배수시설)', 'https://www.law.go.kr/법령/도로의구조ㆍ시설기준에관한규칙/제30조', '2026-06-03', '2026-06-02', '공공 도로', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 도로와 다른 시설의 연결에 관한 규칙 제2조제11호·제3조·제9조제3호·제4호 (연석 정의, 적용 범위, 배수시설)', '요지: 연석 = 보도 등과 차도의 경계선에 연접하여 설치하는 경계석. 일반국도에 다른 시설을 연결하는 경우 연결 시설의 오수·빗물이 도로로 흐르지 않도록 별도 배수시설을 두며, 격자형 철제 뚜껑이 있는 유효 폭 30cm 이상·유효 깊이 60cm 이상의 U형 콘크리트 측구로 함. 기존 배수시설이 매립되면 더 큰 규격(또는 같은 단면) 배수관을 두고 20m 이내 간격으로 뚜껑 있는 맨홀 설치.
적용 조건: 「도로법」상 일반국도에 차량 진행 방향 우측으로 다른 시설(진입로 등)을 연결하는 경우(제3조) / 그 밖의 연결은 「도로의 구조·시설 기준에 관한 규칙」에 따름
주의: 일반 사유지 내부 배수로에는 의무로 안내하지 않음
상담 안내 예: "일반국도에 진입로를 연결하는 공사라면 격자형 철제 뚜껑이 있는 U형 콘크리트 측구(유효 폭 30cm·깊이 60cm 이상)를 두도록 정해져 있습니다(도로와 다른 시설의 연결에 관한 규칙 제9조). 국도 연결이 아니면 해당하지 않습니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 도로와 다른 시설의 연결에 관한 규칙 00882·시행 2021-08-27 조문 일치 · 2026-10-28', 'culvert', '국도,진입로 연결,도로 연결,연결허가,U형 측구,측구,연석,가게 앞 진입로', 0, 800, 'law', '도로와 다른 시설의 연결에 관한 규칙 (국토교통부령 제882호)', '제2조제11호·제3조·제9조제3호·제4호 (연석 정의, 적용 범위, 배수시설)', 'https://www.law.go.kr/법령/도로와다른시설의연결에관한규칙/제9조', '2021-08-27', '2021-08-27', '일반국도 연결허가 공사(사업장·주유소 진입로 등)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 하수도법 제27조제1항·제3항·제5항·제9항, 제2조제12호·제14호 (배수설비)', '요지: 공공하수도 사용이 개시되면 배수구역 안 토지(시설물)의 소유자·관리자는 하수를 공공하수도로 유입시키고 필요한 배수설비를 설치해야 함. 설치 시 공공하수도관리청에 신고, 완료 후 조례에 따른 준공검사, 유지·관리는 설치자(토지 경계~공공하수도 구간은 관리청이 할 수 있음).
적용 조건: 공공하수도 배수구역(법 제15조로 공고된 구역) 안의 토지·건물 / 세부 절차는 지방자치단체 조례
주의: 배수구역 밖(정화조 등 개인하수처리시설 지역)은 별도 기준
상담 안내 예: "공공하수도 배수구역 안이라면 건물·토지 소유자가 배수설비를 설치하고 관할 지자체에 신고·준공검사를 받아야 합니다(하수도법 제27조). 연결 방법은 관할 지자체 하수도 부서에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 하수도법 21065·시행 2025-10-01 조문 일치 · 2026-10-28', 'pe_pipe', '배수설비,하수관 연결,하수도 연결,오수관,우수관,집 하수관,배수 신고', 0, 800, 'law', '하수도법 (법률 제21065호)', '제27조제1항·제3항·제5항·제9항, 제2조제12호·제14호 (배수설비)', 'https://www.law.go.kr/법령/하수도법/제27조', '2025-10-01', '2025-10-01', '배수구역 안의 개인 주택·건물 포함', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 하수도법 시행규칙 제23조제1항·제2항 (배수설비의 설치기준 및 구조기준)', '요지: 배수설비는 공공하수도 또는 다른 배수설비에 연결하고, 철근콘크리트·경질염화비닐·도기 그 밖에 내구성과 내부식성이 있는 자재를 사용해 수밀구조로 하며, 분류식 하수관로에 연결하는 배수설비는 오수와 우수가 분리되어 흐르는 구조로 함. 이 외 별표 7 기준에 따름(별표 7 미확보).
적용 조건: 하수도법 제27조에 따른 배수설비 / 「건축법」 등 다른 법령에 규정이 있으면 그에 따름(법 제27조제10항) / 별표 7 세부기준은 미확인
주의: 관경·경사 등 수치 안내 금지(별표 7 미확인)
상담 안내 예: "배수설비는 철근콘크리트·경질염화비닐(PVC) 등 내구성·내부식성 자재로 물이 새지 않게(수밀) 설치하고, 분류식 지역은 오수와 빗물을 나눠야 합니다(하수도법 시행규칙 제23조). 관경 등 세부 기준은 관할 지자체에 확인하세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 하수도법 시행규칙 00042·시행 2026-06-22 조문 일치 · 2026-10-28', 'pe_pipe', '배수설비,배수관,하수관,PVC관,오수,우수,분류식,수밀', 0, 800, 'law', '하수도법 시행규칙 (기후에너지환경부령 제42호)', '제23조제1항·제2항 (배수설비의 설치기준 및 구조기준)', 'https://www.law.go.kr/법령/하수도법시행규칙/제23조', '2026-06-22', '2026-06-22', '배수구역 안 건물·토지의 배수설비', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 하수도법 시행령 하수도법 제12조제3항 + 시행령 제10조제2항 (하수도용 자재)', '요지: 하수도의 설치에 사용되는 하수도용 자재는 산업표준화법에 따른 KS 인증 제품, 우수 단체표준제품, 한국상하수도협회 인증 단체표준제품, 환경신기술·환경표지·신제품·신기술 지정 제품 등 시행령 제10조제2항 각 호 중 하나의 품질·성능을 가져야 함.
적용 조건: 하수도 설치 공사(주로 공공하수도 설치 기준 조항에 위치 — 개인 배수설비 적용 범위는 관할 지자체 확인)
주의: 특정 상품이 인증을 받았다고 단정 금지 — 상품별 인증서 확인 필요
상담 안내 예: "하수도 공사에 쓰는 관·자재는 KS 인증 등 하수도법 시행령 제10조제2항의 인증을 받은 제품이어야 합니다. 상품별 인증 여부는 견적 시 확인해 드립니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 하수도법 21065·시행 2025-10-01 조문 일치, 하수도법 시행령 36338·시행 2026-06-03 조문 일치 · 2026-10-28', 'pe_pipe', '하수도용,KS 인증,KS인증,인증 제품,하수관 자재,하수도 공사', 0, 800, 'law', '하수도법 시행령 (대통령령 제36338호)', '하수도법 제12조제3항 + 시행령 제10조제2항 (하수도용 자재)', 'https://www.law.go.kr/법령/하수도법시행령/제10조', '2026-06-03', '2026-05-19', '하수도 설치에 쓰는 관·자재', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 하수관로 유지관리 기준 제3조·제5조제1항·제6조제3항', '요지: 침수우려지역(하수도정비중점관리지역 등)의 하수관로 유지관리 기준. 공공하수도관리청은 빗물받이·측구 등이 정상 기능을 유지하도록 유입구 청소 및 불법덮개 등이 설치되지 않도록 상시 관리해야 함.
적용 조건: 적용 지역: 하수도정비중점관리지역, 하수 침수위험 자연재해위험개선지구, 침수 피해(우려) 지역(제3조) / 의무 주체는 공공하수도관리청(지자체)
주의: 개인 부지 안의 배수구·트렌치 덮개에는 해당 없음
상담 안내 예: "도로의 공공 빗물받이·측구에 임의로 덮개를 설치하면 관리청의 관리(제거) 대상이 될 수 있습니다(하수관로 유지관리 기준 제6조). 공공시설이면 설치 전 관할 지자체에 확인하세요. 집 안 배수구 덮개는 해당하지 않습니다."
확인: legalize-kr/admrule-kr (법제처 국가법령정보 Open API 행정규칙 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 하수관로 유지관리 기준 2025-165·시행 2025-10-01 조문 일치 · 2026-10-28', 'manhole', '빗물받이,빗물받이 덮개,배수구 덮개,악취,냄새,오물망,낙엽,하수구 덮개', 0, 800, 'law', '하수관로 유지관리 기준 (기후에너지환경부고시 제2025-165호)', '제3조·제5조제1항·제6조제3항', 'https://www.law.go.kr/행정규칙/하수관로유지관리기준', '2025-10-01', '2025-10-01', '공공 하수관로·빗물받이(지자체 관리 시설)', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 법 제7조·제8조 + 시행규칙 제2조제1항', '요지: 편의시설(점자블록 등) 설치 대상시설은 공원, 공공건물 및 공중이용시설, 공동주택, 통신시설, 그 밖에 필요한 건물·시설로서 대통령령으로 정하는 것. 시설 종류는 대통령령, 구조·재질 세부기준은 보건복지부령 별표 1(미확보).
적용 조건: 대상시설 해당 여부는 시행령 별표 1로 판단(미확보) / 점자블록 크기·색상 등 세부기준은 시행규칙 별표 1(미확보) — 수치 안내 금지 / 제7조·제8조 문구는 현재 시행판과 시행 예정판(2026-11-12·2027-05-27 시행)이 동일함을 대조
주의: 점자블록 규격 수치·설치 위치를 이 근거로 단정하지 않음
상담 안내 예: "점자블록 설치 의무는 장애인등편의법 제7조의 대상시설(공원·공공건물·공중이용시설·공동주택 등)에 적용됩니다. 세부 규격은 법령 별표를 확인 중이라 현장 설계도·발주처 기준을 따르세요."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) — 법률은 HEAD가 시행 예정판이라 현재 시행판(커밋 0d4040acd)과 조문 대조 · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 20594·시행 2025-12-21 조문 일치, 장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 시행규칙 01166·시행 2026-07-02 조문 일치 · 2026-10-28', 'pavement', '점자블록,점자 블록,장애인 블록,유도블록,편의시설', 0, 800, 'law', '장애인ㆍ노인ㆍ임산부 등의 편의증진 보장에 관한 법률 (법률 (현재 시행판: 2024-12-20 공포, 2025-12-21 시행))', '법 제7조·제8조 + 시행규칙 제2조제1항', 'https://www.law.go.kr/법령/장애인ㆍ노인ㆍ임산부등의편의증진보장에관한법률/제7조', '2025-12-21', '2024-12-20', '법 제7조 대상시설', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
INSERT INTO rag_documents (title, content, category_key, tags, is_active, sort_order, source_type, source_name, source_ref, source_url, effective_date, revised_date, applies_to, legal_status, author, review_status, verify_level, verified_by, verified_at)
VALUES ('[법령] 보행안전 및 편의증진에 관한 법률 시행규칙 제5조제1항·제2항', '요지: 보행환경개선지구 안의 도로에 설치할 수 있는 시설: 보도용 방호울타리, 조명시설, 장애인용 음향안내시설, 영상정보처리기기, 자동차 진입억제용 말뚝, 점자블록. 구조·기준은 별표 1(미확보).
적용 조건: 보행환경개선지구 안의 도로(지자체 지정) / 말뚝(볼라드) 높이·간격 등 수치는 별표 1 미확보 — 안내 금지
주의: 사유지 볼라드 설치 의무로 안내하지 않음
상담 안내 예: "보행환경개선지구 도로에는 자동차 진입억제용 말뚝(볼라드)·점자블록 등을 설치할 수 있도록 정해져 있습니다(보행안전법 시행규칙 제5조). 규격 기준은 확인 중입니다."
확인: legalize-kr/legalize-kr (법제처 국가법령정보 Open API 원문 미러, HEAD 2026-10-27) · 법제처 국가법령정보 OPEN API 직접 대조(2026-10-28, 사용자 PC 실행): 보행안전 및 편의증진에 관한 법률 시행규칙 00344·시행 2022-07-12 조문 일치 · 2026-10-28', NULL, '볼라드,말뚝,차량 진입 막기,진입 방지,차 못 들어오게', 0, 800, 'law', '보행안전 및 편의증진에 관한 법률 시행규칙 (행정안전부령 제344호)', '제5조제1항·제2항', 'https://www.law.go.kr/법령/보행안전및편의증진에관한법률시행규칙/제5조', '2022-07-12', '2022-07-12', '보행환경개선지구 도로', 'current', '검증 조문(2-1단계)', 'unreviewed', 'api_text_verified', 'Claude(법제처 원문 미러 열람) + 법제처 OPEN API 대조(사용자 실행)', '2026-10-28');
COMMIT;

-- 롤백: DELETE FROM rag_documents WHERE author = '검증 조문(2-1단계)' AND review_status = 'unreviewed';
