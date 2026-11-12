# 치수 칸 맞춤표: (상용 품목 → 표준 품목) 쌍마다 상용 칸 → 표준 칸 / 상세(추가) / 새 품목 채택 — 2026-11-12
import json, sys, collections
from openpyxl import Workbook
from openpyxl.styles import Font, PatternFill, Alignment
from openpyxl.utils import get_column_letter
d, mapsrc, out_xlsx, out_md, out_json = sys.argv[1:6]
src = open(mapsrc, encoding='utf-8').read()
M = eval(src[src.index('M = {') + 4: src.index('\n}\n', src.index('M = {')) + 2])
C = {c['id']: c for c in json.load(open(d + '/std_cats.json'))}
def path(i):
    p = []
    while i: p.append(C[i]['name']); i = C[i]['pid']
    return ' › '.join(reversed(p))
STDAX = collections.defaultdict(list)
for r in json.load(open(d + '/std_axes_full.json')): STDAX[r['cid']].append(r)
SYCAX = collections.defaultdict(list)
for r in json.load(open(d + '/syc_axes_full.json')): SYCAX[r['slug']].append(r)
EV = json.load(open(d + '/axis_match.json'))
NAMES = {s['slug']: s['name'] for s in json.load(open(d + '/syc_items.json'))}
NPROD = {s['slug']: int(s['n']) for s in json.load(open(d + '/syc_items.json'))}
# 판단으로 정한 칸 맞춤(값 근거·이름표 근거). (상용 분류, 상용 칸) → (표준 칸, 신뢰도, 근거). 표준 칸 None = 상세(추가)
O = {
 ('syc-s1', 'h'): ('depth', '상', '값 같음 7/7(A형) — 상용 높이 H = 표준 깊이'),
 ('syc-s8', 'h'): ('depth', '중', '측구수로관과 같은 구조(높이 = 깊이)'),
 ('syc-s8', 'v'): (None, '중', '표준에 세로 없음'),
 ('syc-s10', 't2'): ('t', '중', '값 같음 8/13 — 표준 두께 = 상용 두께 e'), ('syc-s10', 't'): (None, '중', '상용 두께 d 는 표준에 없음(값 0/13)'),
 ('syc-s11', 't2'): ('t', '상', '값 같음 34/41 — 표준 두께 = 상용 두께 e'), ('syc-s11', 't'): (None, '상', '상용 두께 d 는 표준과 값이 한 번도 같지 않음(0/41)'),
 ('syc-s13', 't2'): ('t', '중', '값 같음 9/9(두께 d·e 같은 값)'), ('syc-s13', 't'): (None, '중', '벤치플륨과 같은 규칙'),
 ('syc-s12', 'h'): ('depth', '중', '값 같음 2/3 — 높이 = 깊이'),
 ('syc-s14', 'd'): ('d_i', '상', '값 같음 6/6 — 직경 = 내경'), ('syc-s14', 'w'): ('d_o', '상', '값 같음 6/6 — 폭 = 표준 외경'), ('syc-s14', 'h'): ('depth', '중', '값 같음 3/6'),
 ('syc-s15', 'w_o'): ('d_o', '중', '이름표: 외경폭 = 표준 외경'), ('syc-s15', 'h_o'): ('depth', '상', '값 같음 8/8 — 외경높이 = 깊이'),
 ('syc-s16', 't_wall'): ('t', '상', '값 같음 7/7 — 벽체두께 = 표준 두께'),
 ('syc-s79', 'w'): ('d_o', '하', '값 같음 2/5 — 폭 a = 표준 외경 추정'), ('syc-s79', 't2'): ('t', '중', '무개·유개 행에는 두께 t2 만 있음 → 표준 두께'), ('syc-s79', 't'): (None, '중', '무개·유개 행에 값 없음'),
 ('syc-s18', 'depth'): ('h', '상', '값 같음 4/4 — 깊이 = 표준 높이'),
 ('syc-s23', 't_top'): ('t_tb', '중', '값 같음 11/17 — 상판두께 = 표준 상하두께'), ('syc-s23', 't_bottom'): (None, '중', '표준은 상하두께 하나 — 하판두께는 상세'),
 ('syc-s24', 't_wall'): ('t', '상', '값 같음 5/5 — 벽체두께 = 표준 두께'),
 ('syc-s26', 't_wall'): ('t', '상', '값 같음 12/12'),
 ('syc-s28', 't_wall'): ('t', '상', '값 같음 20/20'), ('syc-s28', 'd'): (None, '중', '이 품목 행에는 값 없음(연결구는 따로)'),
 ('syc-s31', 'w'): ('w_t', '하', '값 같음 5/6 — 가로 = 상부폭 추정(모양 표기 다름)'), ('syc-s31', 'v'): (None, '하', '표준은 상부폭·하부폭 — 세로는 상세'),
 ('syc-s36', 'd'): ('d_i', '중', '이름표: 직경 = 내경'),
 ('syc-s45', 'd'): ('d_i', '상', '값 같음 1/1'),
 ('syc-s51', 'h1'): (None, '중', '높이2 — 표준에 없음'),
 ('syc-s53', 'd_i'): (None, '하', '사각 뚜껑 — 표준(원형) 칸과 다름'), ('syc-s53', 'd_o'): (None, '하', '위와 같음'), ('syc-s53', 'w'): (None, '하', '위와 같음'), ('syc-s53', 'v'): (None, '하', '위와 같음'),
 ('syc-s85', 'w'): (None, '중', '원형 뚜껑 행에 값 없음'), ('syc-s85', 'v'): (None, '중', '원형 뚜껑 행에 값 없음'),
 ('syc-s63', 't'): ('h', '하', '값 같음 1/1 — 두께 = 표준 높이'), ('syc-s63', 'v'): ('depth', '하', '세로 = 깊이 추정'),
 ('syc-s64', 't'): ('h', '하', '보강토 옹벽블록과 같은 규칙'), ('syc-s64', 'v'): ('depth', '하', '위와 같음'),
 ('syc-s66', 't'): ('h', '중', '값 같음 1/1'), ('syc-s68', 't'): ('h', '중', '값 같음 1/1'),
 ('syc-s67', 'v'): ('h', '중', '값 같음 1/1 — 세로 = 높이'), ('syc-s67', 't'): ('depth', '하', '두께 = 깊이 추정'),
 ('syc-s72', 'l'): (None, '중', '표준 경계석 칸에 길이 없음'), ('syc-s73', 'l'): (None, '중', '위와 같음'),
 ('syc-s74', 'l'): (None, '중', '표준 화강경계 칸에 길이 없음'), ('syc-s74', 'h1'): (None, '중', '낮은쪽높이 — 표준에 없음'),
 ('syc-s87', 'h'): ('h_high', '중', '이름표: 높이 = 상단 높이'), ('syc-s87', 'h1'): ('h_low', '상', '값 같음 4/4 — 낮은쪽높이 = 하단 높이'),
 ('syc-s75', 'd'): ('ADD:d', '중', '원형 보호틀 직경 — 표준 수목보호판에 직경 칸 추가(목록)'), ('syc-s88', 'd'): ('ADD:d', '중', '위와 같음'), ('syc-s89', 'd'): ('ADD:d', '중', '위와 같음'),
 ('syc-w2', 'w'): (None, '중', '표준 스텐볼라드 칸에 폭 없음'), ('syc-w2', 't'): (None, '중', '표준에 두께 없음'),
 ('syc-w5', 'w'): (None, '중', '위와 같음'), ('syc-w5', 't'): (None, '중', '위와 같음'),
}
# 표준 칸이 비어 있는 표준 품목(이름표 0개) → 상용 목록 칸을 표준 이름표로 채택
ROWS, OUT = [], []
for slug, (how, tg, conf, *_r) in M.items():
    if NPROD.get(slug, 0) == 0: continue
    for cond, t in tg:
        new = isinstance(t, tuple)
        tid = t[1] if new else t
        tname = (path(t[1]) + ' › [새] ' + t[2]) if new else path(t)
        std = {} if new else {a['k']: a for a in STDAX.get(t, []) if a['k'] != 'skip'}   # skip = 이름표 읽기용 자리표시
        adopt = new or not std
        ev = EV.get(f"{slug}|{cond}|{t}", {}).get('res', {}) if not new else {}
        used = set()
        for a in SYCAX[slug]:
            k, il = a['k'], int(a['il'])
            slab = a['label'] + (('/' + a['sym']) if a['sym'] else '')
            o = O.get((slug, k))
            if adopt:
                dst, act, cf, why = k, ('표준 이름표로 채택(목록)' if il else '표준 이름표로 채택(상세)'), '상', ('새 표준 품목 — 상용 칸 그대로' if new else '표준 품목에 규격 칸이 없음 — 상용 칸 그대로')
            elif o:
                if o[0] is None: dst, act = k, '상세(추가)'
                elif o[0].startswith('ADD:'): dst, act = o[0][4:], '표준 이름표에 칸 추가(목록)'
                else: dst, act = o[0], ('이름 바꿈' if o[0] != k else '같은 칸')
                cf, why = o[1], o[2]
            elif k in std:
                dst, act, cf, why = k, '같은 칸', '상', '같은 칸 이름' + (f" — 값 같음 {ev[k][1]}/{ev[k][2]}" if k in ev and ev[k][0] == k and ev[k][2] else '')
            else:
                dst, act, cf, why = k, '상세(추가)', '상' if not il else '중', '표준에 없는 칸' + (' (상용 목록 칸이었음)' if il else '')
            if act in ('같은 칸', '이름 바꿈'): used.add(dst)
            slab_std = (std.get(dst, {}).get('label', '') if not adopt else '')
            ROWS.append([NAMES[slug], cond, tname, f"{k} ({slab})", '목록' if il else '상세', act, dst, slab_std, cf, why])
            OUT.append({'vendor_category': slug, 'cond': cond, 'std': None if new else t, 'new_std': list(t[1:]) if new else None, 'src_key': k, 'dst_key': dst,
                        'action': act, 'src_in_list': il, 'label': a['label'], 'symbol': a['sym'], 'confidence': cf})
        if not adopt:
            for k, a in std.items():
                if k not in used and not any(r['dst_key'] == k and r['action'] in ('같은 칸', '이름 바꿈') for r in OUT if r['vendor_category'] == slug and r['cond'] == cond):
                    ROWS.append([NAMES[slug], cond, tname, '—', '', '상용 값 없음', k, a['label'], '', '표준 칸이지만 상용 데이터에 없음 → 상용 행은 빈칸'])
H = ['상용 품목', '조건', '표준 품목', '상용 칸(이름표)', '상용 보기', '처리', '표준 칸', '표준 칸 이름', '신뢰도', '근거']
wb = Workbook(); ws = wb.active; ws.title = '치수 칸 맞춤표'; ws.append(H)
FILL = {'같은 칸': 'E8F5E9', '이름 바꿈': 'FFF8E1', '상세(추가)': 'F3F4F6', '상용 값 없음': 'FDECEA'}
for r in ROWS:
    ws.append(r)
    for key, col in FILL.items():
        if r[5] == key: ws.cell(row=ws.max_row, column=6).fill = PatternFill('solid', fgColor=col)
for i, w in enumerate((26, 14, 46, 22, 7, 22, 10, 14, 7, 52), 1):
    ws.column_dimensions[get_column_letter(i)].width = w
    c = ws.cell(row=1, column=i); c.font = Font(bold=True); c.fill = PatternFill('solid', fgColor='E8F0FE')
ws.freeze_panes = 'D2'; ws.auto_filter.ref = f"A1:J{ws.max_row}"
wr = wb.create_sheet('읽는 법')
for l in ['원칙: 목록 = 표준 규격 칸(업체 공통) / 업체 고유 칸 = 상세 화면만. 같은 품목에서 업체가 달라도 한 표로 비교된다.',
          '처리: 같은 칸 = 이름 그대로 / 이름 바꿈 = 상용 칸 값을 표준 칸에 넣음(값 근거: 같은 크기 제품에서 값이 같았던 비율) / 상세(추가) = 표준 이름표에 상세 전용 칸으로 추가',
          '      표준 이름표로 채택 = 표준 품목에 규격 칸이 없거나 새 품목이라 상용 칸을 그대로 표준으로 / 표준 이름표에 칸 추가(목록) = 표준 목록 칸을 늘림 / 상용 값 없음 = 상용 행은 그 칸이 빈칸',
          '신뢰도: 상 = 값 근거 충분 또는 같은 이름 / 중 = 값 근거 일부·이름표 근거 / 하 = 추정(값 근거 1개 이하) — 하는 실제 데이터를 받으면 다시 확인']:
    wr.append([l])
wr.column_dimensions['A'].width = 150
wb.save(out_xlsx)
json.dump(OUT, open(out_json, 'w'), ensure_ascii=False, indent=1)
cnt = collections.Counter(r[5] for r in ROWS); cf = collections.Counter(r[8] for r in ROWS if r[5] in ('이름 바꿈',))
print(dict(cnt), '이름 바꿈 신뢰도', dict(cf), 'rows', len(ROWS))
md = ['# 치수 칸 맞춤표(초안, 2026-11-12)', '', '원칙: 목록 = 표준 규격 칸(업체 공통), 업체 고유 칸 = 상세만. 엑셀: `data/mapping/syc-std-axis-map.xlsx`. 기계 판독: `data/mapping/syc-std-axis-map.json`.', '',
      '처리별 줄 수: ' + ' · '.join(f"{k} {v}" for k, v in cnt.items()), '', '## 이름 바꿈(같은 뜻, 다른 칸 이름)', '', '| 상용 품목 | 조건 | 상용 칸 | → 표준 칸 | 신뢰도 | 근거 |', '|---|---|---|---|---|---|']
for r in ROWS:
    if r[5] in ('이름 바꿈', '표준 이름표에 칸 추가(목록)'): md.append(f"| {r[0]} | {r[1]} | {r[3]} | {r[6]} {r[7]} | {r[8]} | {r[9]} |")
open(out_md, 'w', encoding='utf-8').write('\n'.join(md) + '\n')
