# 매핑 쌍마다 실제 값으로 치수 칸 대응 찾기: 상용 행 ↔ 같은 품목 케이토목 행(가장 많은 값이 같은 행), 같은 값이 놓인 칸끼리 집계
import json, re, sys, collections
d = sys.argv[1]
src = open(sys.argv[2], encoding='utf-8').read()
M = eval(src[src.index('M = {') + 4: src.index('\n}\n', src.index('M = {')) + 2])
STD = collections.defaultdict(list)
for r in json.load(open(d + '/std_prods.json')): STD[r['cid']].append(r)
AXS = {r['cid']: [x.split(':')[0] for x in r['ax'].split(',')] for r in json.load(open(d + '/std_axes.json'))}
SYC = collections.defaultdict(list)
for r in json.load(open('data/syccrt/products.json', encoding='utf-8'))['rows']: SYC[r['category_slug']].append(r)
SAX = {s['slug']: (s['ax'] or '').split(',') for s in json.load(open(d + '/syc_items.json'))}
def cond_ok(r, cond):
    if not cond: return True
    m = re.match(r'(모델 코드|옵션|상품명) (.+)', cond)
    if m:
        k, v = m.groups()
        if k == '모델 코드': return r['spec_code'] == v
        if k == '옵션': return (r['spec_option'] or '').split(', ')[0] == v
        return v in r['name']
    return cond in (r['name'] or '') or cond in (r['spec_option'] or '')
col = lambda k: 'spec_wire_no' if k == 'wire_no' else 'dim_' + k
out = {}
for slug, (how, tg, conf, *_rest) in M.items():
    for cond, t in tg:
        if isinstance(t, tuple) or not SYC.get(slug): continue
        srows = [r for r in SYC[slug] if cond_ok(r, cond)]
        stds = STD.get(t, [])
        skeys = [k for k in SAX.get(slug, []) if k]
        tkeys = AXS.get(t, [])
        pair = collections.Counter(); seen = collections.Counter(); matched = 0
        for r in srows:
            sv = {k: float(r[col(k)]) for k in skeys if r.get(col(k)) is not None}
            best, bs = None, 0
            for q in stds:
                tv = {k: float(q[col(k)]) for k in tkeys if q.get(col(k)) not in (None, '')}
                sc = len(set(sv.values()) & set(tv.values()))
                if sc > bs: best, bs = tv, sc
            if not best or bs < 2: continue
            matched += 1
            for k, v in sv.items():
                seen[k] += 1
                for tk, tvv in best.items():
                    if tvv == v: pair[(k, tk)] += 1
        res = {}
        for k in skeys:
            cands = sorted(((n, tk) for (sk, tk), n in pair.items() if sk == k), reverse=True)
            res[k] = (cands[0][1], cands[0][0], seen[k]) if cands else (None, 0, seen[k])
        out[f"{slug}|{cond}|{t}"] = {'n': len(srows), 'matched': matched, 'std_rows': len(stds), 'res': res, 'tkeys': tkeys}
json.dump(out, open(d + '/axis_match.json', 'w'), ensure_ascii=False, indent=1)
for k, v in out.items():
    s = ' '.join(f"{a}→{b or '?'}({n}/{m})" for a, (b, n, m) in v['res'].items())
    print(f"{k}: 매칭 {v['matched']}/{v['n']} | {s} | 표준칸 {','.join(v['tkeys'])}")
