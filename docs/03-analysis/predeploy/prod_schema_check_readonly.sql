-- 배포 전 운영 DB 구조 점검 (읽기 전용 · information_schema SELECT 1문장 · 데이터 값은 나오지 않음)
-- 목적: 최신 코드(main)가 쓰는 테이블·컬럼·인덱스가 운영 DB에 있는지 확인 — '필수'가 '없음'이면 코드 배포 전에 원인 확인(배포 보류)
-- 실행: 닷홈 phpMyAdmin → 운영 DB(tomokmall) 선택 → SQL 탭에 아래 전체 붙여넣기 → 실행 → 결과 표 전체를 전달
-- 결과 열: kind(필수/선택) · target(대상) · sql_file(만든 SQL 파일) · status(있음/없음) · impact(없으면 영향)
-- 2026-10-29 로컬 MySQL 8.0.46 에서 검증(전체 반영 DB: 필수 모두 있음 / 외부 자재·법령 칸 없는 DB: '선택'만 없음)
SELECT r.kind, r.target, r.sql_file,
       CASE WHEN r.col = '(table)' THEN IF(t.TABLE_NAME IS NULL, '없음', '있음')
            WHEN r.col = '(fulltext)' THEN IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS s WHERE s.TABLE_SCHEMA = DATABASE() AND s.TABLE_NAME = r.tbl AND s.INDEX_TYPE = 'FULLTEXT'), '있음', '없음')
            ELSE IF(c.COLUMN_NAME IS NULL, '없음', '있음') END AS status,
       r.impact
FROM (
            SELECT '필수' AS kind, 'users' AS tbl, '(table)' AS col, 'users' AS target, 'add_users_auth.sql' AS sql_file, '로그인·회원 전체' AS impact
  UNION ALL SELECT '필수','categories','(table)','categories','schema.sql','카테고리·상품 목록'
  UNION ALL SELECT '필수','products','(table)','products','schema.sql','상품 전체'
  UNION ALL SELECT '필수','category_relations','(table)','category_relations','schema.sql','카테고리 연결'
  UNION ALL SELECT '필수','estimates','(table)','estimates','schema.sql','견적'
  UNION ALL SELECT '필수','estimate_items','(table)','estimate_items','schema.sql','견적 품목'
  UNION ALL SELECT '필수','search_logs','(table)','search_logs','schema.sql','검색 기록'
  UNION ALL SELECT '필수','ai_sessions','(table)','ai_sessions','add_ai_sessions.sql','AI 상담 대화 저장 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','chat_logs','(table)','chat_logs','add_chat_logs.sql','AI 상담 로그·이전 상담 복원 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','rag_documents','(table)','rag_documents','add_rag_documents.sql','AI 상담 사내 문서 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','category_consult_guide','(table)','category_consult_guide','add_category_consult_guide.sql','AI 상담 카테고리 가이드 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','favorite_products','(table)','favorite_products','20261023_add_favorite_products.sql','기업회원 즐겨찾기 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','orders','(table)','orders','add_ai_orders.sql','관리자 메인·주문·통계'
  UNION ALL SELECT '필수','order_items','(table)','order_items','add_ai_orders.sql','주문 품목·통계'
  UNION ALL SELECT '필수','sites','(table)','sites','add_sites.sql','현장 관리'
  UNION ALL SELECT '필수','site_orders','(table)','site_orders','add_sites.sql','현장 주문'
  UNION ALL SELECT '필수','vendors','(table)','vendors','add_vendors.sql','공급업체(직원)'
  UNION ALL SELECT '필수','product_vendors','(table)','product_vendors','add_vendors.sql','상품-공급업체'
  UNION ALL SELECT '필수','reorder_alerts','(table)','reorder_alerts','add_reorder.sql','재주문 알림'
  UNION ALL SELECT '필수','products','(fulltext)','products 전문검색 인덱스(name,spec,description)','schema.sql','상품 검색·AI 상품 추천 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','categories','discount_customer','categories.discount_customer','add_pricing.sql','개인 마진율(없으면 0%로 계산)'
  UNION ALL SELECT '필수','categories','discount_corporate','categories.discount_corporate','(저장소 SQL 없음 — 운영에서 추가된 것으로 추정)','기업 마진율(없으면 0% = 매입가로 계산)'
  UNION ALL SELECT '필수','products','cost_price','products.cost_price','add_pricing.sql','매입가 기준 가격'
  UNION ALL SELECT '필수','products','unit','products.unit','add_ai_sessions.sql','단위·수량 계산 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','products','weight_kg','products.weight_kg','add_ai_sessions.sql','운반비'
  UNION ALL SELECT '필수','products','image_url','products.image_url','20260604_dacity_columns.sql','상품 이미지 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','users','address_zip','users.address_zip','add_user_address.sql','마이페이지 주소'
  UNION ALL SELECT '필수','ai_sessions','tool_state','ai_sessions.tool_state','add_ai_orders.sql','AI 상담 상태'
  UNION ALL SELECT '필수','chat_logs','events_json','chat_logs.events_json','add_chat_logs.sql','이전 상담 추천표 복원 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','chat_logs','tools_json','chat_logs.tools_json','add_chat_logs.sql','상담 로그 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','chat_logs','category_key','chat_logs.category_key','add_chat_logs.sql','상담 로그 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','estimates','user_id','estimates.user_id','alter_estimates_v2.sql','내 견적 [이번 배포 파일 사용]'
  UNION ALL SELECT '필수','estimates','staff_id','estimates.staff_id','add_vendors.sql','직원 견적'
  UNION ALL SELECT '필수','estimate_items','adjusted_price','estimate_items.adjusted_price','add_vendors.sql','직원 견적 조정가'
  UNION ALL SELECT '선택','external_products','(table)','external_products','20261029_external_products.sql','없으면 자재 참고 자료(material_info) = 자료 없음(정상)'
  UNION ALL SELECT '선택','external_product_matches','(table)','external_product_matches','20261029_external_products.sql','없으면 상품 카드의 외부 참고 정보 없음(정상)'
  UNION ALL SELECT '선택','rag_documents','source_type','rag_documents.source_type','sql-drafts/01 (운영 미적용)','없으면 법령 근거 검색·출처 카드 없음(정상)'
  UNION ALL SELECT '선택','rag_documents','verify_level','rag_documents.verify_level','sql-drafts/01 (운영 미적용)','없으면 법령 근거 검색 없음(정상)'
  UNION ALL SELECT '선택','category_gallery','(table)','category_gallery','(저장소 SQL 없음)','없으면 상품 상세의 카테고리 갤러리 숨김(오류 아님)'
  UNION ALL SELECT '선택','home_featured','(table)','home_featured','(저장소 SQL 없음)','없으면 메인 추천 영역 기본 표시(오류 아님)'
) r
LEFT JOIN information_schema.TABLES t ON t.TABLE_SCHEMA = DATABASE() AND t.TABLE_NAME = r.tbl
LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = r.tbl AND c.COLUMN_NAME = r.col
ORDER BY (r.kind = '선택'), (CASE WHEN r.col = '(table)' THEN t.TABLE_NAME IS NULL ELSE 0 END) DESC, r.target;
