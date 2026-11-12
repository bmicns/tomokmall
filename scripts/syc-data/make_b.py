# A 엑셀 → B 엑셀([TEST] 테스트콘크리트): 업체·출처·출처ID·이미지 파일명만 바꿈(같은 제품·규격)
import sys, re
from openpyxl import load_workbook
a, b = sys.argv[1:3]
wb = load_workbook(a)
ws = wb['상품']
h = {c.value: i for i, c in enumerate(ws[1])}
def col(key): return next(i for t, i in h.items() if t.endswith('[' + key + ']'))
cb, cs, ci, cf, ct = map(col, ['brand', 'external_source', 'external_id', 'image_file', 'image_thumb_file'])
for row in ws.iter_rows(min_row=2):
    row[cb].value = '[TEST] 테스트콘크리트'
    row[cs].value = 'testcon'
    for c in (ci, cf, ct):
        row[c].value = re.sub(r'^syc-', 'tc-', row[c].value)
wi = wb['임시값·해석']
for row in wi.iter_rows(min_row=2):
    if row[0].value: row[0].value = re.sub(r'^syc-', 'tc-', row[0].value)
wb.save(b)
