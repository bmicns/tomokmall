-- ─── ③ 새 표준 품목 19개(매핑 대조표 확정 2026-11-12) ─────────────────────────────
-- 초안(2026-11-12) — 실행 안 함. 승인 뒤 database/ 마이그레이션으로 옮겨 실행. 설계: docs/02-design/multi-vendor-db.md
-- 마진율 칸(discount_*)은 넣지 않음 → 0 = 상위 분류 값(20/10) 상속(app/pricing.php category_discount_map). slug std-001~.

INSERT INTO categories (parent_id, name, slug, sort_order, is_active)
SELECT x.parent_id, x.name, x.slug, x.sort_order, 1 FROM (
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
