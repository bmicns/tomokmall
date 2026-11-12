-- ─── ④ 표준 규격 이름표: 업체 칸 채택(목록)·상세 전용 칸(in_list=0) ─────────────────────────────
-- 초안(2026-11-12) — 실행 안 함. 승인 뒤 database/ 마이그레이션으로 옮겨 실행. 설계: docs/02-design/multi-vendor-db.md
-- 기존 이름표 행은 바꾸지 않음(추가만). 같은 표준 품목에 같은 칸이 이미 있으면 건너뜀. 순번은 기존 다음부터.
-- 목록 칸(in_list=1)은 규격 칸이 없던 표준 품목·새 품목에 업체 칸을 채택한 것 + 수목보호판 직경(d). 나머지는 상세 전용(in_list=0).

INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, in_list)
SELECT x.cid, x.seq, x.axis_key, x.label, x.symbol, 1, x.unit, x.use_filter, x.in_list FROM (
    SELECT 33 AS cid, 5 AS seq, 't2' AS axis_key, '두께' AS label, 't2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 6 AS seq, 'w1' AS axis_key, '세부' AS label, 'w1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 7 AS seq, 'w2' AS axis_key, '세부' AS label, 'w2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 8 AS seq, 'h1' AS axis_key, '세부' AS label, 'h1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 9 AS seq, 'h2' AS axis_key, '세부' AS label, 'h2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 10 AS seq, 'h3' AS axis_key, '세부' AS label, 'h3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 11 AS seq, 't1' AS axis_key, '세부' AS label, 't1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 12 AS seq, 't3' AS axis_key, '세부' AS label, 't3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 13 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 14 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 15 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 16 AS seq, 'h4' AS axis_key, '세부' AS label, 'h4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 17 AS seq, 't4' AS axis_key, '세부' AS label, 't4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 18 AS seq, 'l1' AS axis_key, '세부' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 33 AS cid, 19 AS seq, 'l2' AS axis_key, '세부' AS label, 'l2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 5 AS seq, 't2' AS axis_key, '두께' AS label, 't2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 6 AS seq, 'w1' AS axis_key, '세부' AS label, 'w1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 7 AS seq, 'w2' AS axis_key, '세부' AS label, 'w2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 8 AS seq, 'h1' AS axis_key, '세부' AS label, 'h1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 9 AS seq, 'h2' AS axis_key, '세부' AS label, 'h2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 10 AS seq, 'h3' AS axis_key, '세부' AS label, 'h3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 11 AS seq, 't1' AS axis_key, '세부' AS label, 't1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 12 AS seq, 't3' AS axis_key, '세부' AS label, 't3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 13 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 14 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 15 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 16 AS seq, 'h4' AS axis_key, '세부' AS label, 'h4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 17 AS seq, 't4' AS axis_key, '세부' AS label, 't4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 18 AS seq, 'l1' AS axis_key, '세부' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 34 AS cid, 19 AS seq, 'l2' AS axis_key, '세부' AS label, 'l2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 5 AS seq, 't2' AS axis_key, '두께' AS label, 't2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 6 AS seq, 'w1' AS axis_key, '세부' AS label, 'w1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 7 AS seq, 'w2' AS axis_key, '세부' AS label, 'w2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 8 AS seq, 'h1' AS axis_key, '세부' AS label, 'h1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 9 AS seq, 'h2' AS axis_key, '세부' AS label, 'h2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 10 AS seq, 'h3' AS axis_key, '세부' AS label, 'h3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 11 AS seq, 't1' AS axis_key, '세부' AS label, 't1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 12 AS seq, 't3' AS axis_key, '세부' AS label, 't3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 13 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 14 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 15 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 16 AS seq, 'h4' AS axis_key, '세부' AS label, 'h4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 17 AS seq, 't4' AS axis_key, '세부' AS label, 't4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 18 AS seq, 'l1' AS axis_key, '세부' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 35 AS cid, 19 AS seq, 'l2' AS axis_key, '세부' AS label, 'l2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 5 AS seq, 't2' AS axis_key, '두께' AS label, 't2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 6 AS seq, 'w1' AS axis_key, '세부' AS label, 'w1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 7 AS seq, 'w2' AS axis_key, '세부' AS label, 'w2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 8 AS seq, 'h1' AS axis_key, '세부' AS label, 'h1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 9 AS seq, 'h2' AS axis_key, '세부' AS label, 'h2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 10 AS seq, 'h3' AS axis_key, '세부' AS label, 'h3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 11 AS seq, 't1' AS axis_key, '세부' AS label, 't1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 12 AS seq, 't3' AS axis_key, '세부' AS label, 't3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 13 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 14 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 15 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 16 AS seq, 'h4' AS axis_key, '세부' AS label, 'h4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 17 AS seq, 't4' AS axis_key, '세부' AS label, 't4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 18 AS seq, 'l1' AS axis_key, '세부' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 36 AS cid, 19 AS seq, 'l2' AS axis_key, '세부' AS label, 'l2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 38 AS cid, 5 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 63 AS cid, 5 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-001') AS cid, 1 AS seq, 'w' AS axis_key, '폭' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-001') AS cid, 2 AS seq, 'depth' AS axis_key, '깊이' AS label, 'D' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-001') AS cid, 3 AS seq, 't' AS axis_key, '두께' AS label, 'T' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-001') AS cid, 4 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-001') AS cid, 5 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-001') AS cid, 6 AS seq, 'a_t' AS axis_key, '그레이팅 높이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 23 AS cid, 5 AS seq, 'w_b' AS axis_key, '밑나비' AS label, 'c' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 23 AS cid, 6 AS seq, 'w1' AS axis_key, '안나비' AS label, 'b' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 23 AS cid, 7 AS seq, 'r1' AS axis_key, '반지름' AS label, 'r' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 24 AS cid, 5 AS seq, 'w_b' AS axis_key, '밑나비' AS label, 'c' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 24 AS cid, 6 AS seq, 'w1' AS axis_key, '안나비' AS label, 'b' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 24 AS cid, 7 AS seq, 'x1' AS axis_key, '밑나비' AS label, 'm' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 24 AS cid, 8 AS seq, 'x2' AS axis_key, '기타' AS label, 'i' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 22 AS cid, 5 AS seq, 'w1' AS axis_key, '폭' AS label, 'A''' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 22 AS cid, 6 AS seq, 'h1' AS axis_key, '높이' AS label, 'H''' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 22 AS cid, 7 AS seq, 'h2' AS axis_key, '높이' AS label, 'h' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 22 AS cid, 8 AS seq, 't1' AS axis_key, '두께' AS label, 'T''' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 22 AS cid, 9 AS seq, 'l1' AS axis_key, '길이' AS label, 'L''' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 22 AS cid, 10 AS seq, 'r1' AS axis_key, '반지름' AS label, 'R' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 22 AS cid, 11 AS seq, 'r2' AS axis_key, '반지름' AS label, 'R''' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 25 AS cid, 5 AS seq, 'w_b' AS axis_key, '밑나비' AS label, 'c' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 25 AS cid, 6 AS seq, 'w1' AS axis_key, '안나비' AS label, 'b' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 25 AS cid, 7 AS seq, 'x1' AS axis_key, '기타' AS label, 'f' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 25 AS cid, 8 AS seq, 'x2' AS axis_key, '기타' AS label, 'g' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 40 AS cid, 7 AS seq, 'h1' AS axis_key, '높이2' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 41 AS cid, 7 AS seq, 'h1' AS axis_key, '높이2' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-002') AS cid, 1 AS seq, 'd' AS axis_key, '직경' AS label, 'Ø' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-002') AS cid, 2 AS seq, 'w' AS axis_key, '폭' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-002') AS cid, 3 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-002') AS cid, 4 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-002') AS cid, 5 AS seq, 'h1' AS axis_key, '높이2' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 39 AS cid, 7 AS seq, 'l1' AS axis_key, '길이' AS label, 'L1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 39 AS cid, 8 AS seq, 'l2' AS axis_key, '길이' AS label, 'L2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 39 AS cid, 9 AS seq, 'l3' AS axis_key, '길이' AS label, 'L3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 39 AS cid, 10 AS seq, 'a_w' AS axis_key, '스틸그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 39 AS cid, 11 AS seq, 'a_t' AS axis_key, '스틸그레이팅 높이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 39 AS cid, 12 AS seq, 'a_l' AS axis_key, '스틸그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 43 AS cid, 5 AS seq, 'w_o' AS axis_key, '외경폭' AS label, 'A' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 43 AS cid, 6 AS seq, 'h_o' AS axis_key, '외경깊이' AS label, 'B' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 43 AS cid, 7 AS seq, 't_top' AS axis_key, '상부두께' AS label, 'c' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 43 AS cid, 8 AS seq, 't_bottom' AS axis_key, '바닥두께' AS label, 'd' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 44 AS cid, 5 AS seq, 'w_o' AS axis_key, '외경폭' AS label, 'A' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 44 AS cid, 6 AS seq, 'h_o' AS axis_key, '외경깊이' AS label, 'B' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 44 AS cid, 7 AS seq, 't_top' AS axis_key, '상부두께' AS label, 'c' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 44 AS cid, 8 AS seq, 't_bottom' AS axis_key, '바닥두께' AS label, 'd' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 1 AS seq, 'd_i' AS axis_key, '호칭' AS label, 'D' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 2 AS seq, 'w' AS axis_key, '폭' AS label, 'a' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 3 AS seq, 'h' AS axis_key, '높이' AS label, 'b' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 4 AS seq, 'l' AS axis_key, '길이' AS label, 'l' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 5 AS seq, 't' AS axis_key, '두께' AS label, 't1' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 6 AS seq, 't2' AS axis_key, '두께' AS label, 't2' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 7 AS seq, 'l1' AS axis_key, '길이' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 8 AS seq, 'x1' AS axis_key, '타공' AS label, 'm' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 9 AS seq, 'x2' AS axis_key, '타공' AS label, 'n' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 10 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 11 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 12 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 13 AS seq, 'a2_w' AS axis_key, '그레이팅2 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 14 AS seq, 'a2_l' AS axis_key, '그레이팅2 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-003') AS cid, 15 AS seq, 'a2_t' AS axis_key, '그레이팅2 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 48 AS cid, 6 AS seq, 'l1' AS axis_key, '길이' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 48 AS cid, 7 AS seq, 'x1' AS axis_key, '타공' AS label, 'm' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 48 AS cid, 8 AS seq, 'x2' AS axis_key, '타공' AS label, 'n' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 48 AS cid, 9 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 48 AS cid, 10 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 48 AS cid, 11 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 48 AS cid, 12 AS seq, 'a2_w' AS axis_key, '그레이팅2 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 48 AS cid, 13 AS seq, 'a2_l' AS axis_key, '그레이팅2 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 48 AS cid, 14 AS seq, 'a2_t' AS axis_key, '그레이팅2 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 49 AS cid, 6 AS seq, 'l1' AS axis_key, '길이' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 49 AS cid, 7 AS seq, 'x1' AS axis_key, '타공' AS label, 'm' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 49 AS cid, 8 AS seq, 'x2' AS axis_key, '타공' AS label, 'n' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 49 AS cid, 9 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 49 AS cid, 10 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 49 AS cid, 11 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 49 AS cid, 12 AS seq, 'a2_w' AS axis_key, '그레이팅2 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 49 AS cid, 13 AS seq, 'a2_l' AS axis_key, '그레이팅2 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 49 AS cid, 14 AS seq, 'a2_t' AS axis_key, '그레이팅2 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 115 AS cid, 4 AS seq, 'x1' AS axis_key, '개구부' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 115 AS cid, 5 AS seq, 'a_w' AS axis_key, '덮개 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 115 AS cid, 6 AS seq, 'a_l' AS axis_key, '덮개 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 115 AS cid, 7 AS seq, 'a_t' AS axis_key, '덮개 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-004') AS cid, 1 AS seq, 'w' AS axis_key, '폭' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-004') AS cid, 2 AS seq, 'depth' AS axis_key, '깊이' AS label, 'D' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-004') AS cid, 3 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-004') AS cid, 4 AS seq, 'x1' AS axis_key, '개구부' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-004') AS cid, 5 AS seq, 'a_w' AS axis_key, '덮개 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-004') AS cid, 6 AS seq, 'a_l' AS axis_key, '덮개 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-004') AS cid, 7 AS seq, 'a_t' AS axis_key, '덮개 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-005') AS cid, 1 AS seq, 'w' AS axis_key, '폭' AS label, 'a' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-005') AS cid, 2 AS seq, 'depth' AS axis_key, '깊이' AS label, 'h' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-005') AS cid, 3 AS seq, 'l' AS axis_key, '길이' AS label, 'l' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-005') AS cid, 4 AS seq, 't' AS axis_key, '최소두께' AS label, 'd' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 50 AS cid, 4 AS seq, 'l1' AS axis_key, '세부' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 50 AS cid, 5 AS seq, 'l2' AS axis_key, '세부' AS label, 'l2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 50 AS cid, 6 AS seq, 'l3' AS axis_key, '세부' AS label, 'l3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 50 AS cid, 7 AS seq, 'l4' AS axis_key, '세부' AS label, 'l4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 50 AS cid, 8 AS seq, 'l5' AS axis_key, '세부' AS label, 'l5' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 50 AS cid, 9 AS seq, 'd1' AS axis_key, '세부' AS label, 'd1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 50 AS cid, 10 AS seq, 'd2' AS axis_key, '세부' AS label, 'd2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 50 AS cid, 11 AS seq, 'd3' AS axis_key, '세부' AS label, 'd3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 50 AS cid, 12 AS seq, 'd4' AS axis_key, '세부' AS label, 'd4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 4 AS seq, 'l1' AS axis_key, '세부' AS label, 'l1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 5 AS seq, 'l2' AS axis_key, '세부' AS label, 'l2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 6 AS seq, 'l3' AS axis_key, '세부' AS label, 'l3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 7 AS seq, 'l4' AS axis_key, '세부' AS label, 'l4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 8 AS seq, 'l5' AS axis_key, '세부' AS label, 'l5' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 9 AS seq, 'l6' AS axis_key, '세부' AS label, 'l6' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 10 AS seq, 'd1' AS axis_key, '세부' AS label, 'd1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 11 AS seq, 'd2' AS axis_key, '세부' AS label, 'd2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 12 AS seq, 'd3' AS axis_key, '세부' AS label, 'd3' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 13 AS seq, 'd4' AS axis_key, '세부' AS label, 'd4' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 54 AS cid, 14 AS seq, 'd5' AS axis_key, '세부' AS label, 'd5' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 9 AS cid, 1 AS seq, 'w' AS axis_key, '내공폭' AS label, 'B' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 9 AS cid, 2 AS seq, 'w_o' AS axis_key, '바깥나비' AS label, 'Bo' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 9 AS cid, 3 AS seq, 'h' AS axis_key, '내공높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 9 AS cid, 4 AS seq, 'h_o' AS axis_key, '바깥높이' AS label, 'Ho' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 9 AS cid, 5 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 9 AS cid, 6 AS seq, 't_top' AS axis_key, '상판두께' AS label, 'T1' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT 9 AS cid, 7 AS seq, 't_wall' AS axis_key, '측벽두께' AS label, 'T3' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT 9 AS cid, 8 AS seq, 't_bottom' AS axis_key, '하판두께' AS label, 'T2' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT 9 AS cid, 9 AS seq, 'l1' AS axis_key, '삽입구' AS label, 'ℓ1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 9 AS cid, 10 AS seq, 'l2' AS axis_key, '수구' AS label, 'ℓ2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 9 AS cid, 11 AS seq, 'c' AS axis_key, '헌치높이' AS label, 'C' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 1 AS seq, 'w' AS axis_key, '내공폭' AS label, 'B' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 2 AS seq, 'w_o' AS axis_key, '바깥나비' AS label, 'Bo' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 3 AS seq, 'h' AS axis_key, '내공높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 4 AS seq, 'h_o' AS axis_key, '바깥높이' AS label, 'Ho' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 5 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 6 AS seq, 't_top' AS axis_key, '상판두께' AS label, 'T1' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 7 AS seq, 't_wall' AS axis_key, '측벽두께' AS label, 'T3' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 8 AS seq, 't_bottom' AS axis_key, '하판두께' AS label, 'T2' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 9 AS seq, 't_mid' AS axis_key, '중간벽두께' AS label, 'T4' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 10 AS seq, 'l1' AS axis_key, '삽입구' AS label, 'ℓ1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 11 AS seq, 'l2' AS axis_key, '수구' AS label, 'ℓ2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-006') AS cid, 12 AS seq, 'c' AS axis_key, '헌치높이' AS label, 'C' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 21 AS cid, 6 AS seq, 'w_o' AS axis_key, '바깥나비' AS label, 'Bo' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 21 AS cid, 7 AS seq, 'h_o' AS axis_key, '바깥높이' AS label, 'Ho' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 21 AS cid, 8 AS seq, 't_bottom' AS axis_key, '하판두께' AS label, 'T2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 21 AS cid, 9 AS seq, 'l1' AS axis_key, '삽입구' AS label, 'ℓ1' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 21 AS cid, 10 AS seq, 'l2' AS axis_key, '수구' AS label, 'ℓ2' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 21 AS cid, 11 AS seq, 'c' AS axis_key, '헌치높이' AS label, 'C' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 59 AS cid, 5 AS seq, 't_top' AS axis_key, '슬라브두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 59 AS cid, 6 AS seq, 't_bottom' AS axis_key, '기초두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 59 AS cid, 7 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 59 AS cid, 8 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 59 AS cid, 9 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-007') AS cid, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-007') AS cid, 2 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-007') AS cid, 3 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-007') AS cid, 4 AS seq, 't_wall' AS axis_key, '벽체두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-007') AS cid, 5 AS seq, 't_bottom' AS axis_key, '기초두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-007') AS cid, 6 AS seq, 'a_w' AS axis_key, '그레이팅 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-007') AS cid, 7 AS seq, 'a_l' AS axis_key, '그레이팅 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-007') AS cid, 8 AS seq, 'a_t' AS axis_key, '그레이팅 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 61 AS cid, 4 AS seq, 't_bottom' AS axis_key, '기초두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 61 AS cid, 5 AS seq, 'a_w' AS axis_key, '슬라브 지름' AS label, 'Ø' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 66 AS cid, 4 AS seq, 'd' AS axis_key, '관 직경' AS label, 'Ø' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-008') AS cid, 1 AS seq, 'd' AS axis_key, '관 직경' AS label, 'Ø' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-008') AS cid, 2 AS seq, 'd_i' AS axis_key, '내경' AS label, 'D' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-008') AS cid, 3 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-008') AS cid, 4 AS seq, 't_wall' AS axis_key, '벽두께' AS label, 'T' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT 103 AS cid, 4 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 177 AS cid, 4 AS seq, 'w1' AS axis_key, '치수' AS label, 'A' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 177 AS cid, 5 AS seq, 'w2' AS axis_key, '치수' AS label, 'B' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-011') AS cid, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-011') AS cid, 2 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-011') AS cid, 3 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-011') AS cid, 4 AS seq, 'a_w' AS axis_key, '그레이팅뚜껑 폭' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-011') AS cid, 5 AS seq, 'a_l' AS axis_key, '그레이팅뚜껑 길이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-011') AS cid, 6 AS seq, 'a_t' AS axis_key, '그레이팅뚜껑 두께' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-012') AS cid, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-012') AS cid, 2 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-012') AS cid, 3 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-013') AS cid, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-013') AS cid, 2 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-013') AS cid, 3 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 108 AS cid, 3 AS seq, 'h1' AS axis_key, '뚜껑높이' AS label, 'B' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 108 AS cid, 4 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 108 AS cid, 5 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 108 AS cid, 6 AS seq, 'd_i' AS axis_key, '내경' AS label, 'D' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 108 AS cid, 7 AS seq, 'd_o' AS axis_key, '외경' AS label, 'D' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 112 AS cid, 1 AS seq, 'd' AS axis_key, '직경' AS label, 'Ø' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 112 AS cid, 2 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 112 AS cid, 3 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 112 AS cid, 4 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-014') AS cid, 1 AS seq, 'd_i' AS axis_key, '내경' AS label, 'D' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-014') AS cid, 2 AS seq, 'd_o' AS axis_key, '외경' AS label, 'D' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-014') AS cid, 3 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-014') AS cid, 4 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-014') AS cid, 5 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 97 AS cid, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 97 AS cid, 2 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 97 AS cid, 3 AS seq, 't' AS axis_key, '두께' AS label, 'T' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-015') AS cid, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-015') AS cid, 2 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-015') AS cid, 3 AS seq, 't' AS axis_key, '두께' AS label, 'T' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-016') AS cid, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-016') AS cid, 2 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-016') AS cid, 3 AS seq, 't' AS axis_key, '두께' AS label, 'T' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-017') AS cid, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-017') AS cid, 2 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-017') AS cid, 3 AS seq, 't' AS axis_key, '두께' AS label, 'T' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-018') AS cid, 1 AS seq, 'w' AS axis_key, '가로' AS label, 'W' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-018') AS cid, 2 AS seq, 'v' AS axis_key, '세로' AS label, 'V' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-018') AS cid, 3 AS seq, 't' AS axis_key, '두께' AS label, 'T' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 90 AS cid, 1 AS seq, 'w_b' AS axis_key, '밑면' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT 90 AS cid, 2 AS seq, 'w_t' AS axis_key, '윗면' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT 90 AS cid, 3 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 90 AS cid, 4 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 93 AS cid, 4 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 150 AS cid, 3 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 150 AS cid, 4 AS seq, 'h1' AS axis_key, '낮은쪽높이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 152 AS cid, 3 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 152 AS cid, 4 AS seq, 'h1' AS axis_key, '낮은쪽높이' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 132 AS cid, 5 AS seq, 'd' AS axis_key, '직경' AS label, 'Ø' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 132 AS cid, 6 AS seq, 'a_w' AS axis_key, '받침틀 가로' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 132 AS cid, 7 AS seq, 'a_l' AS axis_key, '받침틀 세로' AS label, NULL AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 136 AS cid, 1 AS seq, 'd_i' AS axis_key, '관경' AS label, 'D' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 136 AS cid, 2 AS seq, 'l' AS axis_key, '길이' AS label, 'L' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 136 AS cid, 3 AS seq, 't' AS axis_key, '두께' AS label, 'T' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT 136 AS cid, 4 AS seq, 'x1' AS axis_key, '파형 피치' AS label, 'p' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT 136 AS cid, 5 AS seq, 'x2' AS axis_key, '파형 깊이' AS label, 'd' AS symbol, 'mm' AS unit, 0 AS use_filter, 1 AS in_list
    UNION ALL SELECT 148 AS cid, 5 AS seq, 'w' AS axis_key, '폭' AS label, 'W' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT 148 AS cid, 6 AS seq, 't' AS axis_key, '두께' AS label, 'T' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-019') AS cid, 1 AS seq, 'd' AS axis_key, '직경' AS label, 'Ø' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-019') AS cid, 2 AS seq, 'h' AS axis_key, '높이' AS label, 'H' AS symbol, 'mm' AS unit, 1 AS use_filter, 1 AS in_list
    UNION ALL SELECT (SELECT id FROM categories WHERE slug = 'std-019') AS cid, 3 AS seq, 'd1' AS axis_key, '기둥직경' AS label, 'Ø' AS symbol, 'mm' AS unit, 0 AS use_filter, 0 AS in_list
) x
WHERE x.cid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM category_spec_axes a WHERE a.category_id = x.cid AND a.axis_key = x.axis_key);
