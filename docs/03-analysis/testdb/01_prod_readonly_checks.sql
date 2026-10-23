-- ============================================================================
-- 01. 운영 DB 읽기 전용 점검 SQL (SELECT 전용)
-- ----------------------------------------------------------------------------
-- 실행: 사용자 승인 후, 사용자가 닷홈 phpMyAdmin → tomokmall DB → [SQL] 탭에서 블록 단위로 실행
-- 공유: 각 결과를 [내보내기 → CSV]로 저장해 전달(또는 화면 값). 개인정보 값은 조회하지 않는다.
-- 금지: 이 파일에는 INSERT/UPDATE/DELETE/CREATE/ALTER/DROP/SHOW/CALL/LOCK/FOR UPDATE 가 없다.
--       information_schema 와 업무 테이블에 대한 SELECT(집계 위주)만 있다.
-- 표시 [F] = 테스트 DB 구축 후 같은 쿼리를 테스트 DB에서도 실행해 값이 같은지 비교하는 '지문' 쿼리
-- 테이블이 없으면 해당 블록만 "Table doesn't exist" 오류 → 그 블록은 건너뛰고 결과에 '없음'으로 기록
-- ============================================================================

-- [A1] 엔진·버전·문자셋 (MySQL 8.0 / MariaDB 여부 → 마이그레이션 문법 판단)
SELECT VERSION() AS version, @@version_comment AS distro,
       @@character_set_database AS db_charset, @@collation_database AS db_collation, DATABASE() AS db;

-- [A2] 테이블 목록·엔진·행 수(추정)·AUTO_INCREMENT
SELECT table_name, engine, table_collation, table_rows, auto_increment
  FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
 ORDER BY table_name;

-- [A3] 컬럼 정의 (테스트 DB의 CREATE TABLE을 이 결과로 재구성 → 운영과 같은 스키마)
SELECT table_name, ordinal_position, column_name, column_type, is_nullable, column_default,
       extra, column_key, character_set_name, collation_name, column_comment
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
 ORDER BY table_name, ordinal_position;

-- [A4] 인덱스 (PRIMARY·UNIQUE·일반·FULLTEXT)
SELECT table_name, index_name, non_unique, seq_in_index, column_name, sub_part, index_type
  FROM information_schema.statistics
 WHERE table_schema = DATABASE()
 ORDER BY table_name, index_name, seq_in_index;

-- [A5] 외래키
SELECT k.table_name, k.constraint_name, k.ordinal_position, k.column_name,
       k.referenced_table_name, k.referenced_column_name, r.update_rule, r.delete_rule
  FROM information_schema.key_column_usage k
  JOIN information_schema.referential_constraints r
    ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
 WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL
 ORDER BY k.table_name, k.constraint_name, k.ordinal_position;

-- [A6] 뷰·트리거·프로시저·함수 존재 여부 (있으면 복제 여부 별도 판단)
SELECT 'VIEW' AS kind, table_name AS name FROM information_schema.views WHERE table_schema = DATABASE()
UNION ALL SELECT 'TRIGGER', trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE()
UNION ALL SELECT routine_type, routine_name FROM information_schema.routines WHERE routine_schema = DATABASE();

-- [A7] 민감 정보 의심 컬럼(이름 기준) — 복제 제외·비식별 대상 확정용. 값은 조회하지 않음
SELECT table_name, column_name, column_type
  FROM information_schema.columns
 WHERE table_schema = DATABASE()
   AND column_name REGEXP 'name|email|phone|tel|fax|addr|zip|contact|memo|password|token|secret|ip|session|cost|supply|price|account|bank'
 ORDER BY table_name, column_name;

-- ── 카테고리: ID·계층 ─────────────────────────────────────────────────────────

-- [B1][F] 카테고리 지문 (ID·이름·부모·활성·정렬)
SELECT COUNT(*) AS cnt, MAX(id) AS max_id, SUM(is_active) AS active, SUM(parent_id IS NULL) AS roots,
       SUM(CRC32(CONCAT_WS('|', id, name, IFNULL(parent_id, '-'), is_active, sort_order))) AS fp
  FROM categories;

-- [B2][F] 깊이별 개수 (운영 스냅샷: 1단계 14 / 2단계 51 / 3단계 180 예상). depth<10 = 순환 방지
WITH RECURSIVE t AS (
  SELECT id, 1 AS depth FROM categories WHERE parent_id IS NULL
  UNION ALL
  SELECT c.id, t.depth + 1 FROM categories c JOIN t ON c.parent_id = t.id WHERE t.depth < 10
)
SELECT depth, COUNT(*) AS cnt FROM t GROUP BY depth ORDER BY depth;

-- [B3][F] 루트 카테고리 (코드 고정값: 토목 11~16, 건축 1~8)
SELECT id, name, is_active, sort_order FROM categories WHERE parent_id IS NULL ORDER BY id;

-- [B4][F] 부모가 없는(고아) 카테고리 수
SELECT COUNT(*) AS orphan_categories
  FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
 WHERE c.parent_id IS NOT NULL AND p.id IS NULL;

-- [B5] 작업 유형 대표 자재 카테고리 95개 존재·활성·부모 (app/work_types.php 설정 ID와 대조)
SELECT id, name, parent_id, is_active
  FROM categories
 WHERE id IN (9,23,24,27,28,32,34,38,39,50,52,57,58,59,61,63,64,65,69,70,71,72,73,75,76,77,79,80,81,84,86,87,88,
              91,92,93,95,96,99,100,101,102,103,104,106,107,108,112,116,117,124,137,144,147,148,150,151,152,153,155,
              156,158,159,160,161,162,163,164,167,168,173,174,176,177,178,179,180,181,182,184,185,186,187,192,196,198,
              204,205,206,207,210,264,268,269,272)
 ORDER BY id;

-- ── 상품: 연결 관계·규격·필터·재고 ──────────────────────────────────────────

-- [C1][F] 상품 요약 (매입가 cost_price 는 집계·지문에서도 제외)
SELECT COUNT(*) AS products, SUM(is_active) AS active, MAX(id) AS max_id,
       COUNT(DISTINCT category_id) AS used_categories,
       SUM(spec LIKE '%규격:%') AS has_spec_label,
       SUM(stock_qty > 0) AS in_stock, SUM(stock_qty < 0) AS negative_stock, SUM(stock_qty) AS stock_sum,
       SUM(price_b2c = 0) AS price_zero,
       SUM(image_url IS NULL OR image_url = '') AS no_image
  FROM products;

-- [C2][F] 상품 지문 (복제 대상 컬럼만)
SELECT SUM(CRC32(CONCAT_WS('|', id, category_id, name, IFNULL(brand, ''), IFNULL(spec, ''),
                           price_b2c, price_b2b, stock_qty, is_active))) AS fp
  FROM products;

-- [C3][F] 카테고리가 없는(고아) 상품 수
SELECT COUNT(*) AS orphan_products
  FROM products p LEFT JOIN categories c ON c.id = p.category_id
 WHERE c.id IS NULL;

-- [C4][F] 루트(재질 필터 기준)별 판매중 상품 수
WITH RECURSIVE t AS (
  SELECT id, id AS root_id, 1 AS depth FROM categories WHERE parent_id IS NULL
  UNION ALL
  SELECT c.id, t.root_id, t.depth + 1 FROM categories c JOIN t ON c.parent_id = t.id WHERE t.depth < 10
)
SELECT t.root_id, COUNT(p.id) AS cnt, SUM(p.stock_qty > 0) AS in_stock
  FROM t JOIN products p ON p.category_id = t.id AND p.is_active = 1
 GROUP BY t.root_id ORDER BY t.root_id;

-- [C5][F] 형상/옵션·하중 필터 근거(상품명 키워드) 분포 — 판매중
SELECT SUM(name LIKE '%사각%') AS `사각`, SUM(name LIKE '%원형%') AS `원형`, SUM(name LIKE '%U형%') AS `U형`,
       SUM(name LIKE '%유공%') AS `유공`, SUM(name LIKE '%앵글부착%') AS `앵글부착`, SUM(name LIKE '%인터로킹%') AS `인터로킹`,
       SUM(name LIKE '%안전중하중%') AS `안전중하중`, SUM(name LIKE '%중하중%') AS `중하중`
  FROM products WHERE is_active = 1;

-- [C6][F] 대표 자재 카테고리별 판매중 상품 수·재고 있는 수 (메인 → 목록 검증 기준값)
SELECT p.category_id, COUNT(*) AS cnt, SUM(p.stock_qty > 0) AS in_stock,
       COUNT(DISTINCT CASE WHEN p.spec LIKE '%규격:%'
                           THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(p.spec, '규격:', -1), '|', 1)) END) AS spec_values
  FROM products p
 WHERE p.is_active = 1
   AND p.category_id IN (9,23,24,27,28,32,34,38,39,50,52,57,58,59,61,63,64,65,69,70,71,72,73,75,76,77,79,80,81,84,86,87,88,
                         91,92,93,95,96,99,100,101,102,103,104,106,107,108,112,116,117,124,137,144,147,148,150,151,152,153,155,
                         156,158,159,160,161,162,163,164,167,168,173,174,176,177,178,179,180,181,182,184,185,186,187,192,196,198,
                         204,205,206,207,210,264,268,269,272)
 GROUP BY p.category_id ORDER BY p.category_id;

-- ── 관계·업체(이름만)·화면 보조 테이블 ──────────────────────────────────────

-- [D1][F] 유사품목·공정연결
SELECT relation_type, COUNT(*) AS cnt, SUM(is_active) AS active,
       SUM(CRC32(CONCAT_WS('|', source_category_id, target_category_id, relation_type, display_order, is_active))) AS fp
  FROM category_relations GROUP BY relation_type;

-- [D2][F] 업체 수 (연락처 등 값은 조회하지 않음)
SELECT COUNT(*) AS vendors, SUM(is_active) AS active, SUM(CRC32(CONCAT_WS('|', id, name, is_active))) AS fp FROM vendors;

-- [D3][F] 상품-업체 연결 (공급단가 supply_price 제외)
SELECT COUNT(*) AS links, COUNT(DISTINCT product_id) AS products_with_vendor, SUM(is_primary) AS primaries,
       SUM(CRC32(CONCAT_WS('|', product_id, vendor_id, is_primary))) AS fp
  FROM product_vendors;

-- [D4] 저장소에 CREATE 문이 없는 화면 보조 테이블 (없으면 오류 → '없음' 기록)
SELECT COUNT(*) AS home_featured_rows FROM home_featured;
SELECT COUNT(*) AS category_gallery_rows FROM category_gallery;
