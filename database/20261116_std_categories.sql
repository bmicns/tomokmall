-- ─── 공통 분류(2026-11-16 사용자 결정 A안): 새 품명 19개 · 인조화강블록 이름 · 숨겨 둔 케이토목 분류 다시 보이기 ─────────────
-- 왜: 모든 업체 상품을 케이토목 분류 체계(재질 6개 대분류 › 중분류 › 품명)에 함께 보인다. 상용콘크리트·업체 상품은 app/catalog_map.php 로
--     공통 분류에 넣어 반영(app/syc_publish.php syc_publish_std_apply). 노출은 상품별 활성 여부.
-- 내용(categories 한 표, 가격 칸 쓰지 않음):
--   1) 새 품명 19개(std-001~019, 매핑 대조표 확정 2026-11-12) — 마진율 칸을 넣지 않아 0 = 상위 분류 값(20%/10%) 상속 → 가격 계산 같음.
--      **숨긴 채(is_active 0) 넣음** — 첫 공통 분류 반영(syc_publish_std_apply) 때 숨겨 둔 케이토목 분류와 함께 한 번에 보이게 함(그 전 메뉴 변화 없음)
--   2) 71 '인조화강블록(투수)' → '인조화강블록'(투수·불투수 합침, 투수/불투수는 규격외정보 — 72 의 상품은 관리자 화면에서 71 로 옮김)
-- 순서: 20261116_catalog_mode_std.sql(DDL) → 이 파일 → 코드 배포 → 관리자가 상용콘크리트 엑셀을 다시 일괄 등록(자동 반영 → 공통 분류 모드)
-- 되돌리기: DELETE FROM categories WHERE slug LIKE 'std-0%' AND NOT EXISTS (SELECT 1 FROM products p WHERE p.category_id = categories.id);
--          UPDATE categories SET name = '인조화강블록(투수)' WHERE id = 71;

INSERT INTO categories (parent_id, name, slug, sort_order, is_active)
SELECT x.parent_id, x.name, x.slug, x.sort_order, 0 FROM (
    SELECT 257 AS parent_id, '측구수로관(그레이팅 높이조절용)' AS name, 'std-001' AS slug, 2216 AS sort_order
    UNION ALL SELECT 258 AS parent_id, '돌무늬원형사각수로관NS-01' AS name, 'std-002' AS slug, 2307 AS sort_order
    UNION ALL SELECT 260 AS parent_id, '원심력수로관(W2·S2)' AS name, 'std-003' AS slug, 3828 AS sort_order
    UNION ALL SELECT 268 AS parent_id, '알루미늄트렌치 소켓' AS name, 'std-004' AS slug, 2989 AS sort_order
    UNION ALL SELECT 256 AS parent_id, '건널목수로관(3종)' AS name, 'std-005' AS slug, 2160 AS sort_order
    UNION ALL SELECT 11 AS parent_id, '2련암거' AS name, 'std-006' AS slug, 1011 AS sort_order
    UNION ALL SELECT 259 AS parent_id, '콘크리트빗물받이(집수정용)' AS name, 'std-007' AS slug, 2522 AS sort_order
    UNION ALL SELECT 259 AS parent_id, '조립식맨홀 이송관연결구' AS name, 'std-008' AS slug, 2523 AS sort_order
    UNION ALL SELECT 281 AS parent_id, '고압유공관' AS name, 'std-009' AS slug, 3944 AS sort_order
    UNION ALL SELECT 281 AS parent_id, 'ELP전선관' AS name, 'std-010' AS slug, 3945 AS sort_order
    UNION ALL SELECT 280 AS parent_id, 'PE집수정' AS name, 'std-011' AS slug, 3636 AS sort_order
    UNION ALL SELECT 280 AS parent_id, 'PE침사조' AS name, 'std-012' AS slug, 3637 AS sort_order
    UNION ALL SELECT 280 AS parent_id, 'PE인상링' AS name, 'std-013' AS slug, 3638 AS sort_order
    UNION ALL SELECT 267 AS parent_id, '맨홀뚜껑 높이조절링' AS name, 'std-014' AS slug, 2969 AS sort_order
    UNION ALL SELECT 261 AS parent_id, '사각보도블록' AS name, 'std-015' AS slug, 2640 AS sort_order
    UNION ALL SELECT 262 AS parent_id, '계단형블록' AS name, 'std-016' AS slug, 2721 AS sort_order
    UNION ALL SELECT 265 AS parent_id, '장대블록' AS name, 'std-017' AS slug, 2922 AS sort_order
    UNION ALL SELECT 265 AS parent_id, '게다블록' AS name, 'std-018' AS slug, 2923 AS sort_order
    UNION ALL SELECT 16 AS parent_id, '탄성볼라드' AS name, 'std-019' AS slug, 4144 AS sort_order
) x WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.slug = x.slug);

UPDATE categories SET name = '인조화강블록' WHERE id = 71 AND name = '인조화강블록(투수)';

