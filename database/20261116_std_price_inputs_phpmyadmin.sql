-- ─── 공통 분류 정리: 옛 대분류 8개 → '기타' 아래 · 인조화강블록(불투수) 상품 10개 → 71 (2026-11-16) ─────────────────────
-- 왜: 공통 분류(A안, S20) — 대분류 6개(기타 포함)로 맞추고, 인조화강블록을 한 품명(71)으로 합침(투수/불투수는 규격외정보).
-- 근거: 사용자 결정 2026-11-16 + CLAUDE.md P0-4-1 예외 2(이 두 작업에 한해 Claude 가 SQL 작성, 실행은 사용자 phpMyAdmin).
--       가격 계산 입력(categories.parent_id·products.category_id)이라 원격 관리 API 는 막혀 있음 → phpMyAdmin 에서 실행.
-- 가격 영향 없음: 마진율이 모두 개인 20%·기업 10%(옛 대분류 1~8 자기 값 20/10, 기타 16 = 20/10, 71·72 = 20/10 — 운영 조회 2026-11-16).
--
-- 실행 방법(phpMyAdmin → tomokmall DB → SQL 탭):
--   ① [확인 전] 두 SELECT 를 먼저 실행해 결과가 아래 '기대값'과 같은지 본다(다르면 멈추고 알려 주세요)
--   ② [실행] 두 UPDATE 를 실행 — 영향 행 8 · 10
--   ③ [확인 후] 두 SELECT 를 다시 실행해 기대값과 같은지 본다
--   ④ 문제가 있으면 [되돌리기]

-- ① [확인 전] 기대값: id 1~8 parent_id 가 모두 NULL, 마진율 20/10 (8행) / category_id 72 상품 10개, id 437~446
SELECT id, name, parent_id, discount_customer, discount_corporate FROM categories WHERE id IN (1,2,3,4,5,6,7,8) ORDER BY id;
SELECT COUNT(*) AS n, GROUP_CONCAT(id ORDER BY id) AS ids FROM products WHERE category_id = 72;

-- ② [실행]
UPDATE categories SET parent_id = 16 WHERE id IN (1,2,3,4,5,6,7,8) AND parent_id IS NULL;
UPDATE products SET category_id = 71 WHERE category_id = 72 AND id IN (437,438,439,440,441,442,443,444,445,446);

-- ③ [확인 후] 기대값: id 1~8 parent_id = 16 (8행) / category_id 72 상품 0개, 71 상품 20개
SELECT id, name, parent_id FROM categories WHERE id IN (1,2,3,4,5,6,7,8) ORDER BY id;
SELECT category_id, COUNT(*) AS n FROM products WHERE category_id IN (71, 72) GROUP BY category_id;

-- ④ [되돌리기]
-- UPDATE categories SET parent_id = NULL WHERE id IN (1,2,3,4,5,6,7,8) AND parent_id = 16;
-- UPDATE products SET category_id = 72 WHERE id IN (437,438,439,440,441,442,443,444,445,446) AND category_id = 71;

-- 72 '인조화강블록(불투수)' 분류는 지금 숨김 상태(전환 때). 20261116_std_categories.sql 이 숨긴 목록에서 빼 첫 공통 분류 반영 뒤에도 숨김 유지.
