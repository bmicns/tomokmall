-- ============================================================================
-- 10. 운영 DB 정기 복제용 구조·지문 점검 (SELECT 전용, 결과 1개 표 → CSV 1개)
-- ----------------------------------------------------------------------------
-- 용도: 개발 DB 재복제(scripts/testdb/devdb.sh refresh)의 입력 ①. 01의 1부(A·B·C)와 2부 P1·P2·P3·P5를 SELECT 하나로 합쳤다.
--       (2026-10-23 점검으로 category_relations·vendors·product_vendors·category_gallery 가 운영에 있음을 확인.
--        운영에 없는 home_featured(P4)는 뺐다. 블록 내용·이름은 01과 같다 → compare.php 대조 기준 그대로)
-- 실행: 닷홈 phpMyAdmin → 사이트 DB 선택 → [SQL] 탭 → 이 파일 내용 전체를 붙여 넣고 [실행]
--       → 결과 표(blk, result) 약 21줄 → [쿼리 결과 작업 → 내보내기] → 형식 CSV → 파일 1개
-- 원칙: SELECT만(information_schema 와 업무 테이블 집계). INSERT/UPDATE/DELETE/CREATE/ALTER/DROP/SHOW/CALL/LOCK/FOR UPDATE 없음.
--       결과에는 구조·집계·카테고리명만 있고 개인정보 값은 없다.
-- 요구: MySQL 8.0 이상(JSON_ARRAYAGG·WITH RECURSIVE). 한 테이블이라도 없으면 전체가 실패 → 오류 문구를 그대로 전달.
-- ============================================================================

SELECT 'A1 버전·문자셋' AS blk,
       JSON_OBJECT('version', VERSION(), 'distro', @@version_comment, 'db_charset', @@character_set_database,
                   'db_collation', @@collation_database) AS result
UNION ALL
-- A2 테이블 목록·엔진·행 수(추정)·AUTO_INCREMENT
SELECT 'A2 테이블', (SELECT JSON_ARRAYAGG(JSON_OBJECT('t', table_name, 'engine', engine, 'collation', table_collation,
                                                       'rows', table_rows, 'ai', auto_increment))
                       FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE')
UNION ALL
-- A3 컬럼 정의(테스트 DB 스키마 대조용)
SELECT 'A3 컬럼', (SELECT JSON_ARRAYAGG(JSON_OBJECT('t', table_name, 'pos', ordinal_position, 'col', column_name,
                                                     'type', column_type, 'null', is_nullable, 'def', column_default,
                                                     'extra', extra, 'key', column_key, 'collation', collation_name,
                                                     'comment', column_comment))
                     FROM information_schema.columns WHERE table_schema = DATABASE())
UNION ALL
-- A4 인덱스(PRIMARY·UNIQUE·일반·FULLTEXT)
SELECT 'A4 인덱스', (SELECT JSON_ARRAYAGG(JSON_OBJECT('t', table_name, 'idx', index_name, 'non_unique', non_unique,
                                                       'seq', seq_in_index, 'col', column_name, 'sub', sub_part,
                                                       'type', index_type))
                       FROM information_schema.statistics WHERE table_schema = DATABASE())
UNION ALL
-- A5 외래키
SELECT 'A5 외래키', (SELECT JSON_ARRAYAGG(JSON_OBJECT('t', k.table_name, 'fk', k.constraint_name, 'col', k.column_name,
                                                       'ref_t', k.referenced_table_name, 'ref_col', k.referenced_column_name,
                                                       'on_update', r.update_rule, 'on_delete', r.delete_rule))
                       FROM information_schema.key_column_usage k
                       JOIN information_schema.referential_constraints r
                         ON r.constraint_schema = k.constraint_schema AND r.constraint_name = k.constraint_name
                      WHERE k.table_schema = DATABASE() AND k.referenced_table_name IS NOT NULL)
UNION ALL
-- A6 뷰·트리거·프로시저·함수 개수(있으면 복제 여부 별도 판단)
SELECT 'A6 뷰·트리거·루틴',
       JSON_OBJECT('views', (SELECT COUNT(*) FROM information_schema.views WHERE table_schema = DATABASE()),
                   'triggers', (SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = DATABASE()),
                   'routines', (SELECT COUNT(*) FROM information_schema.routines WHERE routine_schema = DATABASE()))
UNION ALL
-- B1 [F] 카테고리 지문(ID·이름·부모·활성·정렬)
SELECT 'B1 카테고리 지문',
       (SELECT JSON_OBJECT('cnt', COUNT(*), 'max_id', MAX(id), 'active', SUM(is_active), 'roots', SUM(parent_id IS NULL),
                           'fp', SUM(CRC32(CONCAT_WS('|', id, name, IFNULL(parent_id, '-'), is_active, sort_order))))
          FROM categories)
UNION ALL
-- B2 [F] 깊이별 개수(depth<10 = 순환 방지)
SELECT 'B2 깊이별',
       (WITH RECURSIVE t AS (
            SELECT id, 1 AS depth FROM categories WHERE parent_id IS NULL
            UNION ALL
            SELECT c.id, t.depth + 1 FROM categories c JOIN t ON c.parent_id = t.id WHERE t.depth < 10
        )
        SELECT JSON_ARRAYAGG(JSON_OBJECT('depth', depth, 'cnt', cnt))
          FROM (SELECT depth, COUNT(*) AS cnt FROM t GROUP BY depth) d)
UNION ALL
-- B3 [F] 루트 카테고리(코드 고정값: 토목 11~16, 건축 1~8)
SELECT 'B3 루트', (SELECT JSON_ARRAYAGG(JSON_OBJECT('id', id, 'name', name, 'active', is_active, 'sort', sort_order))
                     FROM categories WHERE parent_id IS NULL)
UNION ALL
-- B4 [F] 부모가 없는(고아) 카테고리 수
SELECT 'B4 고아 카테고리',
       (SELECT JSON_OBJECT('cnt', COUNT(*)) FROM categories c LEFT JOIN categories p ON p.id = c.parent_id
         WHERE c.parent_id IS NOT NULL AND p.id IS NULL)
UNION ALL
-- B5 [F] 작업 유형 대표 자재 카테고리 95개(app/work_types.php) 존재·활성·부모
SELECT 'B5 대표 자재',
       (SELECT JSON_ARRAYAGG(JSON_OBJECT('id', id, 'name', name, 'parent', parent_id, 'active', is_active))
          FROM categories
         WHERE id IN (9,23,24,27,28,32,34,38,39,50,52,57,58,59,61,63,64,65,69,70,71,72,73,75,76,77,79,80,81,84,86,87,88,
                      91,92,93,95,96,99,100,101,102,103,104,106,107,108,112,116,117,124,137,144,147,148,150,151,152,153,155,
                      156,158,159,160,161,162,163,164,167,168,173,174,176,177,178,179,180,181,182,184,185,186,187,192,196,198,
                      204,205,206,207,210,264,268,269,272))
UNION ALL
-- C1 [F] 상품 요약(매입가 cost_price 는 집계·지문에서도 제외)
SELECT 'C1 상품 요약',
       (SELECT JSON_OBJECT('products', COUNT(*), 'active', SUM(is_active), 'max_id', MAX(id),
                           'used_categories', COUNT(DISTINCT category_id), 'has_spec_label', SUM(spec LIKE '%규격:%'),
                           'in_stock', SUM(stock_qty > 0), 'negative_stock', SUM(stock_qty < 0), 'stock_sum', SUM(stock_qty),
                           'price_zero', SUM(price_b2c = 0), 'no_image', SUM(image_url IS NULL OR image_url = ''))
          FROM products)
UNION ALL
-- C2 [F] 상품 지문(복제 대상 컬럼만)
SELECT 'C2 상품 지문',
       (SELECT JSON_OBJECT('fp', SUM(CRC32(CONCAT_WS('|', id, category_id, name, IFNULL(brand, ''), IFNULL(spec, ''),
                                                     price_b2c, price_b2b, stock_qty, is_active))))
          FROM products)
UNION ALL
-- C3 [F] 카테고리가 없는(고아) 상품 수
SELECT 'C3 고아 상품',
       (SELECT JSON_OBJECT('cnt', COUNT(*)) FROM products p LEFT JOIN categories c ON c.id = p.category_id WHERE c.id IS NULL)
UNION ALL
-- C4 [F] 루트(재질 필터 기준)별 판매중 상품 수·재고 있는 수
SELECT 'C4 루트별 상품',
       (WITH RECURSIVE t AS (
            SELECT id, id AS root_id, 1 AS depth FROM categories WHERE parent_id IS NULL
            UNION ALL
            SELECT c.id, t.root_id, t.depth + 1 FROM categories c JOIN t ON c.parent_id = t.id WHERE t.depth < 10
        )
        SELECT JSON_ARRAYAGG(JSON_OBJECT('root', root_id, 'cnt', cnt, 'in_stock', in_stock))
          FROM (SELECT t.root_id, COUNT(p.id) AS cnt, SUM(p.stock_qty > 0) AS in_stock
                  FROM t JOIN products p ON p.category_id = t.id AND p.is_active = 1
                 GROUP BY t.root_id) r)
UNION ALL
-- C5 [F] 형상/옵션·하중 필터 근거(상품명 키워드) 분포 — 판매중
SELECT 'C5 형상·하중 키워드',
       (SELECT JSON_OBJECT('사각', SUM(name LIKE '%사각%'), '원형', SUM(name LIKE '%원형%'), 'U형', SUM(name LIKE '%U형%'),
                           '유공', SUM(name LIKE '%유공%'), '앵글부착', SUM(name LIKE '%앵글부착%'),
                           '인터로킹', SUM(name LIKE '%인터로킹%'), '안전중하중', SUM(name LIKE '%안전중하중%'),
                           '중하중', SUM(name LIKE '%중하중%'))
          FROM products WHERE is_active = 1)
UNION ALL
-- C6 [F] 대표 자재 카테고리별 판매중 상품 수·재고 있는 수·규격 종류 수(메인 → 목록 검증 기준값)
SELECT 'C6 대표 자재별 상품',
       (SELECT JSON_ARRAYAGG(JSON_OBJECT('cat', category_id, 'cnt', cnt, 'in_stock', in_stock, 'spec_values', spec_values))
          FROM (SELECT p.category_id, COUNT(*) AS cnt, SUM(p.stock_qty > 0) AS in_stock,
                       COUNT(DISTINCT CASE WHEN p.spec LIKE '%규격:%'
                                           THEN TRIM(SUBSTRING_INDEX(SUBSTRING_INDEX(p.spec, '규격:', -1), '|', 1)) END) AS spec_values
                  FROM products p
                 WHERE p.is_active = 1
                   AND p.category_id IN (9,23,24,27,28,32,34,38,39,50,52,57,58,59,61,63,64,65,69,70,71,72,73,75,76,77,79,80,81,84,86,87,88,
                                         91,92,93,95,96,99,100,101,102,103,104,106,107,108,112,116,117,124,137,144,147,148,150,151,152,153,155,
                                         156,158,159,160,161,162,163,164,167,168,173,174,176,177,178,179,180,181,182,184,185,186,187,192,196,198,
                                         204,205,206,207,210,264,268,269,272)
                 GROUP BY p.category_id) s)
UNION ALL
-- P1 [F] 유사품목·공정연결
SELECT 'P1 category_relations',
       (SELECT JSON_ARRAYAGG(JSON_OBJECT('type', relation_type, 'cnt', cnt, 'active', active, 'fp', fp))
          FROM (SELECT relation_type, COUNT(*) AS cnt, SUM(is_active) AS active,
                       SUM(CRC32(CONCAT_WS('|', source_category_id, target_category_id, relation_type, display_order, is_active))) AS fp
                  FROM category_relations GROUP BY relation_type) x)
UNION ALL
-- P2 [F] 업체 수(연락처 등 값은 조회하지 않음)
SELECT 'P2 vendors',
       (SELECT JSON_OBJECT('vendors', COUNT(*), 'active', SUM(is_active), 'fp', SUM(CRC32(CONCAT_WS('|', id, name, is_active))))
          FROM vendors)
UNION ALL
-- P3 [F] 상품-업체 연결(공급단가 supply_price 제외)
SELECT 'P3 product_vendors',
       (SELECT JSON_OBJECT('links', COUNT(*), 'products_with_vendor', COUNT(DISTINCT product_id), 'primaries', SUM(is_primary),
                           'fp', SUM(CRC32(CONCAT_WS('|', product_id, vendor_id, is_primary))))
          FROM product_vendors)
UNION ALL
-- P5 category_gallery — 행 수 + 민감정보 검사(값 자체는 조회하지 않음. 전화번호 형태는 caption만)
SELECT 'P5 category_gallery',
       (SELECT JSON_OBJECT('rows', COUNT(*), 'categories', COUNT(DISTINCT category_id),
                           'email_like', SUM(CONCAT_WS(' ', image_url, caption) REGEXP '[[:alnum:]._%+-]+@[[:alnum:].-]+[.][[:alpha:]]{2,}'),
                           'phone_like', SUM(IFNULL(caption, '') REGEXP '0[0-9]{1,2}[- .]?[0-9]{3,4}[- .]?[0-9]{4}'))
          FROM category_gallery);
