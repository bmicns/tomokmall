# 다업체 DB 설계 SQL 초안 생성(실행 안 함) — 매핑 대조표(M)·치수 칸 맞춤표(json)에서 만든다. 2026-11-12
import json, sys, re, collections
d, mapsrc, axjson, outdir = sys.argv[1:5]
src = open(mapsrc, encoding='utf-8').read()
M = eval(src[src.index('M = {') + 4: src.index('\n}\n', src.index('M = {')) + 2])
AX = json.load(open(axjson, encoding='utf-8'))
C = {c['id']: c for c in json.load(open(d + '/std_cats.json'))}
STDAX = collections.defaultdict(list)
for r in json.load(open(d + '/std_axes_full.json')): STDAX[r['cid']].append(r)
UNITS = json.load(open(d + '/syc_units.json'))
q = lambda v: 'NULL' if v is None else (str(v) if isinstance(v, int) else "'" + str(v).replace("'", "''") + "'")
HDR = lambda title, lines: '\n'.join([f'-- ─── {title} ─────────────────────────────', '-- 초안(2026-11-12) — 실행 안 함. 승인 뒤 database/ 마이그레이션으로 옮겨 실행. 설계: docs/02-design/multi-vendor-db.md'] + ['-- ' + l for l in lines] + [''])

# 새 표준 품목(19) — slug std-<번호>
NEW = collections.OrderedDict()
for slug, (how, tg, *_r) in M.items():
    for cond, t in tg:
        if isinstance(t, tuple):
            key = (t[1], t[2])
            if key not in NEW: NEW[key] = f"std-{len(NEW) + 1:03d}"
def std_ref(t):   # SQL 식: 표준 분류 id
    return f"(SELECT id FROM categories WHERE slug = {q(NEW[(t[1], t[2])])})" if isinstance(t, tuple) else str(t)
def cond_split(cond):
    if not cond: return ('all', '')
    m = re.match(r'(모델 코드|옵션|상품명) (.+)', cond)
    if m: return ({'모델 코드': 'code', '옵션': 'option', '상품명': 'name'}[m.group(1)], m.group(2))
    return ('name', cond)

# 01 DDL
open(outdir + '/01_ddl.sql', 'w', encoding='utf-8').write(HDR('① 구조(모두 추가형 — 기존 칸·행 변경 없음)', [
 '원격 관리 API: 가격 칸이 있는 표(products·categories)는 ADD COLUMN·ADD INDEX 만 허용 → FOREIGN KEY 는 넣지 않음(인덱스만, 코드가 존재 확인).',
 '되돌리기: 맨 아래 주석']) + """
-- 판매 업체 코드(업체 원본·매핑이 업체를 가리키는 이름). 기존 4곳(샘플)은 code NULL
ALTER TABLE vendors
    ADD COLUMN code VARCHAR(30) NULL COMMENT '업체 코드(예: ktomok, syccrt) — 원본 출처·매핑 기준' AFTER name,
    ADD UNIQUE KEY uq_vendors_code (code);

-- 고객용 상품(합친 표): 업체·목록 칸 3개(dim_t2 는 이미 있음)·상세 전용 칸(JSON)·목록용 작은 이미지
ALTER TABLE products
    ADD COLUMN vendor_id INT UNSIGNED NULL COMMENT '판매 업체(vendors.id) — NULL 은 업체 미지정(기존 일부 행)' AFTER category_id,
    ADD COLUMN dim_t_mid DECIMAL(8,1) NULL COMMENT '중간벽 두께 (mm) — 2련암거',
    ADD COLUMN dim_x1    DECIMAL(8,1) NULL COMMENT '기타 칸 1 (mm) — 뜻은 분류 이름표(예: 파형강관 파형 피치)',
    ADD COLUMN dim_x2    DECIMAL(8,1) NULL COMMENT '기타 칸 2 (mm) — 뜻은 분류 이름표(예: 파형강관 파형 깊이)',
    ADD COLUMN dim_extra JSON NULL COMMENT '상세 화면 전용 치수 {칸 이름: 값} — 이름표 in_list=0 칸(업체 고유 세부·부속 치수)',
    ADD COLUMN image_thumb_url VARCHAR(255) NULL COMMENT '목록용 작은 이미지(없으면 image_url)' AFTER image_url,
    ADD KEY idx_products_vendor (vendor_id);

-- 규격 이름표: 목록에 보일 칸(1) / 상세 화면에만(0). 기존 행은 모두 1(지금과 같음)
-- axis_key: ENUM(products 칸 목록) → VARCHAR(20). 업체 고유 칸(세부·부속 치수 — products.dim_extra)을 이름표에 넣기 위해 넓힘(기존 값 그대로).
--   허용 칸 검사는 코드(app/spec_axes.php SPEC_AXIS_COLUMNS + 상세 칸 규칙)가 한다. 새 업체 칸이 생겨도 구조 변경 없음.
ALTER TABLE category_spec_axes
    MODIFY COLUMN axis_key VARCHAR(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '칸 이름: 목록 칸 = products 칸(dim_<키>, wire_no 는 spec_wire_no), 상세 칸(in_list=0) = products.dim_extra 의 키. skip·none = 읽기용 자리',
    ADD COLUMN in_list TINYINT(1) NOT NULL DEFAULT 1 COMMENT '목록 표에 보임(1) / 상세 화면에만(0)' AFTER use_filter;

-- 업체 분류 → 표준 분류(categories) 매핑. match_type: all(품목 전체) / code(모델 코드) / option(옵션 첫 낱말) / name(상품명에 포함)
CREATE TABLE IF NOT EXISTS vendor_category_map (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vendor_id       INT UNSIGNED NOT NULL,
    vendor_category VARCHAR(40)  NOT NULL COMMENT '업체 분류 코드(예: syc-s1)',
    match_type      ENUM('all','code','option','name') NOT NULL DEFAULT 'all',
    match_value     VARCHAR(60)  NOT NULL DEFAULT '',
    std_category_id INT UNSIGNED NOT NULL COMMENT '표준 분류 categories.id',
    confidence      ENUM('상','중','하','새') NOT NULL DEFAULT '중',
    note            VARCHAR(200) NULL,
    is_active       TINYINT(1)   NOT NULL DEFAULT 1,
    updated_at      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_vcm (vendor_id, vendor_category, match_type, match_value),
    KEY idx_vcm_std (std_category_id),
    CONSTRAINT fk_vcm_vendor FOREIGN KEY (vendor_id) REFERENCES vendors(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='업체 분류 → 표준 분류';

-- 치수 칸 맞춤: 매핑 1줄마다 업체 칸 → 표준 칸(column) 또는 상세 전용(extra = products.dim_extra)
CREATE TABLE IF NOT EXISTS vendor_axis_map (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    map_id     INT UNSIGNED NOT NULL COMMENT 'vendor_category_map.id',
    src_key    VARCHAR(20)  NOT NULL COMMENT '업체 원본 칸(dim_ 뗀 이름)',
    dst_key    VARCHAR(20)  NOT NULL COMMENT '표준 칸(dim_ 뗀 이름)',
    target     ENUM('column','extra') NOT NULL DEFAULT 'column',
    confidence ENUM('상','중','하') NOT NULL DEFAULT '중',
    note       VARCHAR(200) NULL,
    UNIQUE KEY uq_vam (map_id, src_key),
    CONSTRAINT fk_vam_map FOREIGN KEY (map_id) REFERENCES vendor_category_map(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='업체 칸 → 표준 칸';

-- 되돌리기(데이터 없을 때): DROP TABLE vendor_axis_map, vendor_category_map;
--   ALTER TABLE category_spec_axes DROP COLUMN in_list;  (axis_key 를 ENUM 으로 되돌리려면 새로 넣은 칸 행을 먼저 지움)
--   ALTER TABLE products DROP KEY idx_products_vendor, DROP COLUMN image_thumb_url, DROP COLUMN dim_extra, DROP COLUMN dim_x2, DROP COLUMN dim_x1, DROP COLUMN dim_t_mid, DROP COLUMN vendor_id;
--   ALTER TABLE vendors DROP KEY uq_vendors_code, DROP COLUMN code;
""")

# 02 업체
open(outdir + '/02_dml_vendors.sql', 'w', encoding='utf-8').write(HDR('② 판매 업체: 케이토목·상용콘크리트 등록, 샘플 4곳 비활성, 케이토목 상품에 업체 표시', [
 '샘플 4곳(대성산업·한길콘크리트·동보건설자재·삼정산업)은 지우지 않고 비활성(사용자 확인: 샘플 데이터). product_vendors 샘플 연결 1,788건은 그대로(고객 화면은 products.vendor_id 로 업체 표시).',
 'products 는 가격 표 — vendor_id(가격 아님) 만 바꿈. category_id·가격 칸은 그대로. 외부 출처 dacity 10행·출처 없음 12행은 업체 미지정(NULL)으로 둠(결정 필요).']) + """
INSERT INTO vendors (name, code, is_active, memo)
SELECT x.name, x.code, 1, x.memo FROM (
    SELECT '케이토목' AS name, 'ktomok' AS code, '기존 상품 데이터(products external_source=ktomok)' AS memo
    UNION ALL SELECT '상용콘크리트', 'syccrt', '상용콘크리트(주) — 원본 syc_products'
) x WHERE NOT EXISTS (SELECT 1 FROM vendors v WHERE v.code = x.code);

UPDATE vendors SET is_active = 0 WHERE code IS NULL AND id IN (1, 2, 3, 4);

-- 케이토목 상품 1,319행에 업체 표시(원격 관리 API 규칙: 한 테이블 + WHERE … IN (SELECT …) — 값은 아래 확인 SELECT 로 id 를 읽어 숫자로 넣는다)
-- SELECT id FROM vendors WHERE code = 'ktomok';   -- 이 값을 :ktomok_id 자리에
UPDATE products SET vendor_id = :ktomok_id WHERE external_source = 'ktomok' AND vendor_id IS NULL;
""")

# 03 새 표준 품목
L = [HDR('③ 새 표준 품목 19개(매핑 대조표 확정 2026-11-12)', ['마진율 칸(discount_*)은 넣지 않음 → 0 = 상위 분류 값(20/10) 상속(app/pricing.php category_discount_map). slug std-001~.']),
     'INSERT INTO categories (parent_id, name, slug, sort_order, is_active)', 'SELECT x.parent_id, x.name, x.slug, x.sort_order, 1 FROM (']
sel = []
for (pid, name), slug in NEW.items():
    so = max([c['so'] for c in C.values() if c['pid'] == pid] or [0]) + 5 + int(slug[-3:])
    sel.append(f"SELECT {pid} AS parent_id, {q(name)} AS name, {q(slug)} AS slug, {so} AS sort_order")
L += ['    ' + '\n    UNION ALL '.join(sel), ') x WHERE NOT EXISTS (SELECT 1 FROM categories c WHERE c.slug = x.slug);', '']
open(outdir + '/03_dml_std_categories.sql', 'w', encoding='utf-8').write('\n'.join(L))

# 04 표준 이름표: 목록 칸(채택·추가) + 상세 칸(in_list=0). 표준 품목별로 합침(같은 칸은 처음 것)
per = collections.OrderedDict()   # 표준 ref → {key: (label, symbol, unit, in_list, use_filter)}
for r in AX:
    ref = std_ref(tuple([None] + r['new_std'])) if r['new_std'] else r['std']
    act = r['action']
    if act in ('같은 칸', '이름 바꿈', '상용 값 없음'): continue
    il = 1 if act in ('표준 이름표로 채택(목록)', '표준 이름표에 칸 추가(목록)') else 0
    key = r['dst_key']
    unit = UNITS.get(r['vendor_category'] + '|' + r['src_key'], 'mm')
    per.setdefault(ref, collections.OrderedDict()).setdefault(key, (r['label'], r['symbol'] or None, unit, il, 1 if il and key in ('w', 'h', 'd', 'd_i', 'depth', 't', 'v', 'l') else 0))
maxseq = {cid: max(a['seq'] for a in axs) for cid, axs in STDAX.items()}
L = [HDR('④ 표준 규격 이름표: 업체 칸 채택(목록)·상세 전용 칸(in_list=0)', ['기존 이름표 행은 바꾸지 않음(추가만). 같은 표준 품목에 같은 칸이 이미 있으면 건너뜀. 순번은 기존 다음부터.',
     '목록 칸(in_list=1)은 규격 칸이 없던 표준 품목·새 품목에 업체 칸을 채택한 것 + 수목보호판 직경(d). 나머지는 상세 전용(in_list=0).']),
     'INSERT INTO category_spec_axes (category_id, seq, axis_key, label, symbol, show_symbol, unit, use_filter, in_list)',
     'SELECT x.cid, x.seq, x.axis_key, x.label, x.symbol, 1, x.unit, x.use_filter, x.in_list FROM (']
sel = []
for ref, keys in per.items():
    base = maxseq.get(ref, 0) if isinstance(ref, int) else 0
    have = {a['k'] for a in STDAX.get(ref, [])} if isinstance(ref, int) else set()
    n = 0
    for k, (lab, sym, unit, il, uf) in keys.items():
        if k in have: continue
        n += 1
        sel.append(f"SELECT {ref} AS cid, {base + n} AS seq, {q(k)} AS axis_key, {q(lab[:20])} AS label, {q(sym)} AS symbol, {q(unit)} AS unit, {uf} AS use_filter, {il} AS in_list")
L += ['    ' + '\n    UNION ALL '.join(sel), ') x', 'WHERE x.cid IS NOT NULL AND NOT EXISTS (SELECT 1 FROM category_spec_axes a WHERE a.category_id = x.cid AND a.axis_key = x.axis_key);', '']
open(outdir + '/04_dml_std_axes.sql', 'w', encoding='utf-8').write('\n'.join(L))

# 05 매핑
L = [HDR('⑤ 상용콘크리트 매핑: 분류(vendor_category_map) + 치수 칸(vendor_axis_map)', ['매핑 대조표·치수 칸 맞춤표 확정본(2026-11-12). 상품 없는 카탈로그 품목도 분류 매핑은 넣음(칸 맞춤은 상품 있는 것만).',
     '인조화강블록 투수/불투수(syc-s54·s55)는 상품을 투수·불투수로 나눈 뒤에 맞음(지금 옵션 "투수/불투수" 는 어느 줄에도 안 맞음 → 반영 때 경고).']),
     'INSERT INTO vendor_category_map (vendor_id, vendor_category, match_type, match_value, std_category_id, confidence, note)',
     "SELECT v.id, x.vc, x.mt, x.mv, x.std, x.cf, x.note FROM (" ]
sel = []
for slug, (how, tg, conf, why, alt) in M.items():
    for cond, t in tg:
        mt, mv = cond_split(cond)
        cf = '새' if conf == '없음' else conf
        sel.append(f"SELECT {q(slug)} AS vc, {q(mt)} AS mt, {q(mv)} AS mv, {std_ref(t)} AS std, {q(cf)} AS cf, {q((why + (' / 후보: ' + alt if alt else ''))[:200])} AS note")
L += ['    ' + '\n    UNION ALL '.join(sel), ") x JOIN vendors v ON v.code = 'syccrt'", 'WHERE x.std IS NOT NULL AND NOT EXISTS (SELECT 1 FROM vendor_category_map m WHERE m.vendor_id = v.id AND m.vendor_category = x.vc AND m.match_type = x.mt AND m.match_value = x.mv);', '']
L += ['INSERT INTO vendor_axis_map (map_id, src_key, dst_key, target, confidence, note)', 'SELECT m.id, x.sk, x.dk, x.tg, x.cf, x.note FROM (']
sel = []
for r in AX:
    if r['action'] == '상용 값 없음': continue
    mt, mv = cond_split(r['cond'])
    tg = 'extra' if r['action'] in ('상세(추가)', '표준 이름표로 채택(상세)') else 'column'
    sel.append(f"SELECT {q(r['vendor_category'])} AS vc, {q(mt)} AS mt, {q(mv)} AS mv, {q(r['src_key'])} AS sk, {q(r['dst_key'])} AS dk, {q(tg)} AS tg, {q(r['confidence'])} AS cf, {q(r['action'])} AS note")
L += ['    ' + '\n    UNION ALL '.join(sel), ") x", "JOIN vendors v ON v.code = 'syccrt'",
      'JOIN vendor_category_map m ON m.vendor_id = v.id AND m.vendor_category = x.vc AND m.match_type = x.mt AND m.match_value = x.mv',
      'WHERE NOT EXISTS (SELECT 1 FROM vendor_axis_map a WHERE a.map_id = m.id AND a.src_key = x.sk);', '']
open(outdir + '/05_dml_maps.sql', 'w', encoding='utf-8').write('\n'.join(L))
print('new std', len(NEW), 'axes rows', sum(1 for s in open(outdir + '/04_dml_std_axes.sql', encoding='utf-8') if 'AS cid' in s), 'cat maps', sum(len(v[1]) for v in M.values()), 'axis maps', sum(1 for r in AX if r['action'] != '상용 값 없음'))
