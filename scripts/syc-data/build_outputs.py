# products.json → import.sql(로컬·운영 적재용) + 일괄 등록 테스트 xlsx
import sys, re, json
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment
from openpyxl.utils import get_column_letter

pj, cat_sql, out_sql, out_xlsx = sys.argv[1:5]
D = json.load(open(pj, encoding='utf-8'))
R, NOTES, SKIP = D['rows'], D['notes'], D['skipped']

# 카테고리 slug → (대분류, 품목명): 카테고리 마이그레이션에서 읽음
cs = '\n'.join(open(f, encoding='utf-8').read() for f in cat_sql.split(','))   # 카테고리 마이그레이션 여러 개(쉼표) — 뒤 파일이 이름을 바꿈
GROUP = dict((m.group(2), m.group(1)) for m in re.finditer(r"SELECT '([^']+)'(?: AS name)?, '(syc-g\d+)'", cs))
CAT = {}
for m in re.finditer(r"SELECT '(syc-g\d+)'(?: AS parent_slug)?, '([^']+)'(?: AS name)?, '(syc-[sw]\d+)'", cs):
    CAT[m.group(3)] = (GROUP[m.group(1)], m.group(2))
for m in re.finditer(r"WHEN '(syc-[sw]\d+)' THEN '([^']+)'", cs):
    CAT[m.group(1)] = (CAT[m.group(1)][0], m.group(2))
for m in re.finditer(r"SET is_active = 0\s+WHERE slug IN \(([^)]*)\)", cs):   # 숨긴 카테고리는 '카테고리' 시트에서 뺌
    for x in re.findall(r"'(syc-[sw]\d+)'", m.group(1)): CAT.pop(x, None)
assert all(r['category_slug'] in CAT for r in R)

DB_COLS = [c for c in R[0].keys() if c != 'category_slug']

def sql_v(v):
    if v is None: return 'NULL'
    if isinstance(v, (int, float)) and not isinstance(v, bool):
        return str(int(v)) if float(v).is_integer() else repr(float(v))
    return "'" + str(v).replace('\\', '\\\\').replace("'", "''") + "'"

L = []
A = L.append
A('-- ─── 상용콘크리트 상품 데이터 적재: syc_products ← 카탈로그(2025) 판독본 + 웹사이트 캡처 ─────────────────')
A('-- 생성: 2026-11-11, 원천 data/syccrt/products.json (같은 내용의 일괄 등록 테스트 엑셀: data/syccrt/syc-products-bulk-test.xlsx)')
A(f'-- 행 수: {len(R)} (카테고리 {len({r["category_slug"] for r in R})}개). 가격(price_b2c·price_b2b·cost_price)은 원문에 없어 넣지 않음(기본값 0).')
A('-- 값 원칙(사용자 결정 2026-11-11): 실제 데이터는 나중에 다시 받음 — 원문 의심·빈 값은 다른 행·기존 DB(케이토목) 같은 품목 기준 임시값으로 채움.')
A('--   임시값 행은 description 이 "[임시값]" 으로 시작한다(엑셀 "임시값·해석" 시트에 목록). 실제 데이터로 바꾸기 전 운영 노출 금지.')
A('-- image_file·image_thumb_file 은 비움(서버에 이미지 파일이 없음 — 일괄 등록 기능이 ZIP 에서 저장할 때 채움).')
A('-- ⚠ 사용자 결정(2026-11-11): 운영 DB 에는 지금 넣지 않는다. 실제 데이터를 받은 뒤 실제 값으로 다시 만들어 넣는다(이 파일은 로컬 테스트용).')
A('-- 상태: is_active 기본값 0(비활성)으로 들어감 — 확인 뒤 활성화.')
A('-- 선행: database/20261110_syc_categories.sql → database/20261110_syc_products.sql')
A('-- 다시 실행해도 중복 없음: (external_source, external_id) 가 이미 있으면 그 행은 건너뜀(나중에 고친 값을 덮어쓰지 않음).')
A('-- ⚠ 운영 반영은 사용자 승인 후 phpMyAdmin 에서(가격 칸이 있는 표 — 원격 관리 API 대상 아님). 이 파일은 배포 대상(database/)이 아니다.')
A('-- 되돌리기: DELETE FROM syc_products WHERE external_source = \'syccrt\';  (승인 후에만)')
A('')
A('START TRANSACTION;')
cols_sql = ', '.join(['category_id'] + DB_COLS + ['spec_dims_src'])
for r in R:
    vals = ', '.join(sql_v(r[c]) for c in DB_COLS)
    A(f"INSERT INTO syc_products ({cols_sql})")
    A(f"SELECT c.id, {vals}, {sql_v(r['spec'])}")
    A(f"FROM syc_categories c WHERE c.slug = {sql_v(r['category_slug'])}")
    A(f"  AND NOT EXISTS (SELECT 1 FROM syc_products p WHERE p.external_source = 'syccrt' AND p.external_id = {sql_v(r['external_id'])});")
A('COMMIT;')
A('')
A('-- 확인(읽기 전용)')
A(f"-- SELECT COUNT(*) FROM syc_products WHERE external_source = 'syccrt';   -- {len(R)}")
A("-- SELECT c.slug, c.name, COUNT(p.id) FROM syc_categories c LEFT JOIN syc_products p ON p.category_id = c.id WHERE c.parent_id IS NOT NULL GROUP BY c.id ORDER BY c.id;")
open(out_sql, 'w', encoding='utf-8').write('\n'.join(L) + '\n')

# ── 엑셀 ──────────────────────────────────────────────────────────────
LABEL = {
 'category_slug': '분류코드', 'category_name': '분류명(참고)', 'name': '상품명', 'brand': '브랜드', 'spec': '규격', 'spec_code': '모델코드(호칭)',
 'dim_d': '직경 Ø', 'dim_d_i': '내경', 'dim_d_o': '외경', 'dim_w': '폭·가로 W', 'dim_w_o': '외폭', 'dim_w_b': '하부폭', 'dim_w_t': '상부폭',
 'dim_h': '높이 H', 'dim_h_o': '외높이', 'dim_depth': '깊이', 'dim_l': '길이 L', 'dim_v': '세로', 'dim_t': '두께 T', 'dim_t2': '두께 t2',
 'dim_t_top': '상판두께', 'dim_t_wall': '벽체두께', 'dim_t_bottom': '하판두께', 'dim_t_mid': '중간벽두께',
 'dim_w1': '세부 w1', 'dim_w2': '세부 w2', 'dim_h1': '세부 h1', 'dim_h2': '세부 h2', 'dim_h3': '세부 h3', 'dim_h4': '세부 h4',
 'dim_t1': '세부 t1', 'dim_t3': '세부 t3', 'dim_t4': '세부 t4', 'dim_l1': '세부 l1', 'dim_l2': '세부 l2', 'dim_l3': '세부 l3',
 'dim_l4': '세부 l4', 'dim_l5': '세부 l5', 'dim_l6': '세부 l6', 'dim_d1': '세부 D1', 'dim_d2': '세부 D2', 'dim_d3': '세부 D3',
 'dim_d4': '세부 D4', 'dim_d5': '세부 D5', 'dim_r1': '반지름 r1', 'dim_r2': '반지름 r2', 'dim_c': '헌치 C',
 'dim_x1': '기타 x1', 'dim_x2': '기타 x2', 'dim_x3': '기타 x3', 'dim_x4': '기타 x4',
 'dim_a_w': '부속 폭', 'dim_a_l': '부속 길이', 'dim_a_t': '부속 두께', 'dim_a2_w': '부속2 폭', 'dim_a2_l': '부속2 길이', 'dim_a2_t': '부속2 두께',
 'dim_mesh': '망눈간격', 'dim_wire_d': '선경', 'spec_wire_no': '철선품번', 'dim_d_inlet': '유입측 지름', 'dim_d_outlet': '유출측 지름',
 'spec_option': '옵션', 'unit': '단위', 'weight_kg': '중량(kg)', 'description': '설명',
 'price_b2c': '개인판매가', 'price_b2b': '기업판매가', 'cost_price': '원가', 'stock_qty': '재고',
 'external_source': '출처', 'external_id': '출처ID', 'source_url': '출처URL', 'image_file': '대표이미지(상세용, ZIP 안 파일명)',
 'image_thumb_file': '썸네일(목록용, ZIP 안 파일명)'}
XCOLS = ['category_slug', 'category_name', 'name', 'brand', 'spec', 'spec_code'] + \
        [c for c in DB_COLS if c.startswith('dim_') or c == 'spec_wire_no'] + \
        ['spec_option', 'unit', 'weight_kg', 'description', 'price_b2c', 'price_b2b', 'cost_price', 'stock_qty',
         'external_source', 'external_id', 'source_url', 'image_file', 'image_thumb_file']
assert set(XCOLS) - {'category_name', 'price_b2c', 'price_b2b', 'cost_price', 'stock_qty'} == set(R[0].keys()), set(R[0].keys()) ^ set(XCOLS)

HEAD_FILL = PatternFill('solid', fgColor='E8F0FE'); BOLD = Font(bold=True)
wb = Workbook()
ws = wb.active; ws.title = '상품'
ws.append([f"{LABEL[c]} [{c}]" for c in XCOLS])
for r in R:
    row = []
    for c in XCOLS:
        if c == 'category_name': v = CAT[r['category_slug']][1]
        elif c == 'image_file': v = r['external_id'] + '.jpg'
        elif c == 'image_thumb_file': v = r['external_id'] + '_thumb.jpg'
        elif c in ('price_b2c', 'price_b2b', 'cost_price', 'stock_qty'): v = None
        else: v = r[c]
        if isinstance(v, float) and v.is_integer(): v = int(v)
        row.append(v)
    ws.append(row)
for i, c in enumerate(XCOLS, 1):
    cell = ws.cell(row=1, column=i); cell.font = BOLD; cell.fill = HEAD_FILL; cell.alignment = Alignment(wrap_text=True, vertical='center')
    w = 34 if c in ('name', 'spec', 'spec_option', 'category_name') else (50 if c == 'description' else (14 if c in ('category_slug', 'spec_code', 'external_id') else 10))
    ws.column_dimensions[get_column_letter(i)].width = w
ws.row_dimensions[1].height = 36
ws.freeze_panes = 'D2'
ws.auto_filter.ref = f"A1:{get_column_letter(len(XCOLS))}{len(R) + 1}"

wd = wb.create_sheet('작성법')
for line in [
    ['상용콘크리트 상품 일괄 등록 엑셀(테스트용) — 2026-11-10, 2026-11-11 수정(부속 치수 칸·모델코드·옵션 정리)'],
    [''],
    ['첫 시트 "상품" 한 줄 = 상품 1개. 머리글 [ ] 안이 DB 칸 이름(syc_products)이다. 머리글을 바꾸지 않는다.'],
    ['필수: 분류코드 [category_slug], 상품명 [name]. 분류코드는 "카테고리" 시트의 slug(예: syc-s21). 분류명은 참고용(등록 때 쓰지 않음).'],
    ['치수는 모두 mm(중량은 kg). 칸별 뜻·품목별 도면 기호 대응: docs/02-design/syc-products.md 2장.'],
    ['옵션 [spec_option] 에는 치수를 넣지 않는다(글자만 — 예: 슬라브 없음, 그레이팅, 주문품). 그레이팅·덮개·받침틀·슬라브 같은 부속의 치수는 부속 칸 [dim_a_w]·[dim_a_l]·[dim_a_t](두 번째 부속은 [dim_a2_*])에 넣는다 — 상세 화면에만 보인다.'],
    ['모델코드 [spec_code] 에는 치수만으로 된 값(예: 0.6*0.6, 300*400)을 넣지 않는다 — 치수는 치수 칸에. 모델명·형식 기호(예: 300A, NS-1, KCI-S1)만 넣는다.'],
    ['같은 상품 다시 올리기: 출처 [external_source] + 출처ID [external_id] 가 같으면 같은 상품.'],
    ['이미지 2가지 크기: 대표이미지 칸 = 상세 화면용 큰 이미지(예: syc-s21-01.jpg), 썸네일 칸 = 목록용 작은 이미지(예: syc-s21-01_thumb.jpg) — 둘 다 이미지 ZIP 안 파일명. 썸네일 칸·파일이 없으면 비워 두고 화면은 빈 이미지(이미지 없음)로 보인다.'],
    ['테스트용 이미지 ZIP(syc-products-images-test.zip)은 임시 이미지(TEST IMAGE 표시)다. 실제 상품 사진 아님.'],
    ['가격(개인·기업 판매가, 원가)·재고: 원문에 없어 비어 있다(0). 등록 상품은 비활성으로 저장된다.'],
    ['데이터 원천: 상용콘크리트(주) 카탈로그(2025) 판독본 + 웹사이트 화면 캡처(건널목수로관·볼라드). 의심·빈 값은 다른 행·기존 DB 기준 임시값 — 설명이 [임시값] 으로 시작, "임시값·해석" 시트에 목록.'],
]:
    wd.append(line)
wd.column_dimensions['A'].width = 120; wd['A1'].font = Font(bold=True, size=13)

wc = wb.create_sheet('카테고리')
wc.append(['분류코드 [slug]', '대분류', '품목(카테고리)', '상품 수'])
cnt = {}
for r in R: cnt[r['category_slug']] = cnt.get(r['category_slug'], 0) + 1
def skey(s):
    m = re.fullmatch(r'syc-([sw])(\d+)', s); return (m.group(1) == 'w', int(m.group(2)))
for slug in sorted(CAT, key=skey):
    wc.append([slug, CAT[slug][0], CAT[slug][1], cnt.get(slug, 0)])
for i, w in enumerate((16, 22, 60, 10), 1): wc.column_dimensions[get_column_letter(i)].width = w

wi = wb.create_sheet('임시값·해석')
wi.append(['출처ID', '상품명', '규격', '구분', '내용'])
for e in NOTES: wi.append(list(e))
for i, w in enumerate((22, 30, 34, 8, 110), 1): wi.column_dimensions[get_column_letter(i)].width = w

wk = wb.create_sheet('상품 없음')
wk.append(['카테고리(판독본 구분자)', '이름', '이유'])
for sid, t in SKIP: wk.append([f'syc-{sid}', t, '카탈로그에 규격 수치 없음(설명·사진만) — 상품을 만들지 않음'])
for i, w in enumerate((24, 50, 60), 1): wk.column_dimensions[get_column_letter(i)].width = w

for s in wb.worksheets[1:]:
    for c in s[1]: c.font = BOLD
    s['A1'].font = Font(bold=True, size=13) if s.title == '작성법' else BOLD
wb.save(out_xlsx)
print('sql', len(R), 'xlsx cols', len(XCOLS), 'cats', len(CAT))
