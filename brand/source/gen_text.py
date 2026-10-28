"""Outline a string with Inter Display, applying GPOS pair kerning. Prints JSON."""
import json, sys
from fontTools.ttLib import TTFont
from fontTools.pens.svgPathPen import SVGPathPen
from fontTools.pens.transformPen import TransformPen
from fontTools.pens.boundsPen import BoundsPen

FONT = sys.argv[1]
TEXT = sys.argv[2]
TRACK = float(sys.argv[3]) if len(sys.argv) > 3 else 0.0  # in font units

f = TTFont(FONT)
cmap = f.getBestCmap()
gs = f.getGlyphSet()
hmtx = f["hmtx"]
upm = f["head"].unitsPerEm


def kern(a, b):
    total = 0
    gpos = f["GPOS"].table
    for fi in gpos.FeatureList.FeatureRecord:
        if fi.FeatureTag != "kern":
            continue
        for li in fi.Feature.LookupListIndex:
            lk = gpos.LookupList.Lookup[li]
            subs = lk.SubTable
            for st in subs:
                if lk.LookupType == 9:
                    st = st.ExtSubTable
                if getattr(st, "LookupType", 2) != 2 and lk.LookupType not in (2, 9):
                    continue
                if not hasattr(st, "Format"):
                    continue
                cov = st.Coverage.glyphs
                if a not in cov:
                    continue
                if st.Format == 1:
                    idx = cov.index(a)
                    for pvr in st.PairSet[idx].PairValueRecord:
                        if pvr.SecondGlyph == b:
                            v = pvr.Value1
                            return (getattr(v, "XAdvance", 0) or 0)
                elif st.Format == 2:
                    c1 = st.ClassDef1.classDefs.get(a, 0)
                    c2 = st.ClassDef2.classDefs.get(b, 0)
                    rec = st.Class1Record[c1].Class2Record[c2]
                    v = rec.Value1
                    val = getattr(v, "XAdvance", 0) or 0
                    if val:
                        return val
    return total

names = [cmap[ord(c)] for c in TEXT]
x = 0
parts = []
for i, g in enumerate(names):
    pen = SVGPathPen(gs)
    tpen = TransformPen(pen, (1, 0, 0, -1, x, 0))
    gs[g].draw(tpen)
    parts.append({"char": TEXT[i], "d": pen.getCommands(), "x": x})
    adv = hmtx[g][0]
    k = kern(g, names[i + 1]) if i + 1 < len(names) else 0
    x += adv + k + (TRACK if i + 1 < len(names) else 0)

bp = BoundsPen(gs)
xmin = ymin = 1e9; xmax = ymax = -1e9
xx = 0
for p, g in zip(parts, names):
    b = BoundsPen(gs); gs[g].draw(b)
    if b.bounds:
        x0, y0, x1, y1 = b.bounds
        xmin = min(xmin, p["x"] + x0); xmax = max(xmax, p["x"] + x1)
        ymin = min(ymin, -y1); ymax = max(ymax, -y0)
os2 = f["OS/2"]
print(json.dumps({"upm": upm, "cap": os2.sCapHeight, "xh": os2.sxHeight, "advance": x,
                  "bbox": [xmin, ymin, xmax, ymax], "glyphs": parts}))
