# 일괄 등록 테스트용 임시 이미지 ZIP: 상품마다 대표 {external_id}.jpg(800px) + 썸네일 {external_id}_thumb.jpg(200px)
# 실제 상품 사진이 아님 — 화면에 'TEST IMAGE' 와 출처ID·규격을 크게 표시해 운영 사진과 헷갈리지 않게 한다.
import sys, json, re, io, zipfile
from PIL import Image, ImageDraw, ImageFont

pj, out_zip = sys.argv[1], sys.argv[2]
VENDOR = sys.argv[4] if len(sys.argv) > 4 else ''          # 두 번째 업체 등: 띠 글자·색으로 구분
BAND = '#1F5F8B' if VENDOR else '#C62828'
R = json.load(open(pj, encoding='utf-8'))['rows']
FONT = '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'
BOLD = '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf'
# 대분류별 배경색(연한 색) — 그룹 구분용
CAT_GROUP = {}
cs = open(sys.argv[3], encoding='utf-8').read()
for m in re.finditer(r"SELECT '(syc-g\d+)'(?: AS parent_slug)?, '[^']+'(?: AS name)?, '(syc-[sw]\d+)'", cs):
    CAT_GROUP[m.group(2)] = int(m.group(1)[5:])
PALETTE = ['#DCE8F5', '#E3F1E1', '#F6EBD9', '#EDE3F3', '#F9E0E0', '#DFF1F1', '#F2F2D9', '#E6E6E6', '#F7E4EE', '#E0E7F7']

def ascii_only(s):
    s = re.sub(r'[가-힣ㄱ-ㆎ]+', '', s or '')     # 한글 글꼴 없음 → 한글 제거
    return re.sub(r'\s{2,}', ' ', s).strip(' _')

def fit(draw, text, font_path, max_w, start):
    size = start
    while size > 12:
        ft = ImageFont.truetype(font_path, size)
        if draw.textlength(text, font=ft) <= max_w: return ft
        size -= 2
    return ImageFont.truetype(font_path, 12)

def make(r):
    W = 800
    bg = PALETTE[(CAT_GROUP[r['category_slug']] - 1) % len(PALETTE)]
    im = Image.new('RGB', (W, W), bg)
    d = ImageDraw.Draw(im)
    d.rectangle([24, 24, W - 25, W - 25], outline='#5F6A70', width=4)
    d.line([24, 24, W - 25, W - 25], fill='#C5CAD3', width=2); d.line([W - 25, 24, 24, W - 25], fill='#C5CAD3', width=2)
    band = ImageFont.truetype(BOLD, 44)
    d.rectangle([24, 24, W - 25, 110], fill=BAND)
    label = 'TEST IMAGE' + (' · ' + VENDOR if VENDOR else '')
    d.text((W / 2, 67), label, font=fit(d, label, BOLD, W - 80, 44), fill='white', anchor='mm')
    eid = r['external_id']
    d.text((W / 2, 300), eid, font=fit(d, eid, BOLD, W - 120, 96), fill='#1A2035', anchor='mm')
    spec = ascii_only(r['spec']) or '-'
    d.text((W / 2, 440), spec, font=fit(d, spec, FONT, W - 120, 52), fill='#1A2035', anchor='mm')
    code = ascii_only(r['spec_code'] or '')
    if code: d.text((W / 2, 530), code, font=fit(d, code, FONT, W - 120, 44), fill='#444444', anchor='mm')
    d.text((W / 2, 700), f"{r['category_slug']} · {r['external_source']}", font=ImageFont.truetype(FONT, 30), fill='#555555', anchor='mm')
    return im

def jpg(im, q):
    b = io.BytesIO(); im.save(b, 'JPEG', quality=q, optimize=True); return b.getvalue()

with zipfile.ZipFile(out_zip, 'w', zipfile.ZIP_STORED) as z:     # JPEG 는 이미 압축됨
    for r in R:
        im = make(r)
        z.writestr(f"{r['external_id']}.jpg", jpg(im, 72))
        z.writestr(f"{r['external_id']}_thumb.jpg", jpg(im.resize((200, 200), Image.LANCZOS), 80))
print('images', len(R) * 2)
