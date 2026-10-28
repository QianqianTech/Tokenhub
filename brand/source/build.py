"""TokenHub logo system generator.

The mark ("A!" on a dark rounded tile) is the user's own design, vectorised 1:1
from the supplied 250 x 250 master. All geometry is on that 250-unit grid.
"""
import json, os, shutil

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
OUT = BASE
os.makedirs(OUT + "/logo/svg", exist_ok=True)

# ---------- Brand colors (sampled from the master) ----------
C = {
    "ink": "#151A21",      # 曜石黑  tile / primary dark
    "green": "#34D399",    # 信号绿  "A" and "!" dot
    "deep": "#236D57",     # 深松绿  "!" bar (= signal green 45% on ink)
    "white": "#FFFFFF",
    "black": "#000000",
}

G = 250.0          # master grid
TILE_R = 56.0      # tile corner radius (22.4%)

# "A" — outer contour with crossbar notch, plus counter (evenodd)
A_OUTER = [(90.5, 78), (117.5, 78), (147.5, 172), (129, 172), (121.4, 147.5),
           (87.0, 147.5), (80, 172), (61.5, 172)]
A_COUNTER = [(103.5, 90), (116.4, 131.5), (91.6, 131.5)]
BAR = (156, 78, 32, 63, 7)     # x, y, w, h, r   "!" stem
DOT = (156, 149, 32, 23, 7)    # "!" dot
MARK_BBOX = (61.5, 78, 188, 172)


def poly(pts):
    return "M" + "L".join(f"{x:g} {y:g}" for x, y in pts) + "Z"


def rrect(x, y, w, h, r):
    return (f"M{x + r:g} {y:g}H{x + w - r:g}A{r:g} {r:g} 0 0 1 {x + w:g} {y + r:g}V{y + h - r:g}"
            f"A{r:g} {r:g} 0 0 1 {x + w - r:g} {y + h:g}H{x + r:g}A{r:g} {r:g} 0 0 1 {x:g} {y + h - r:g}"
            f"V{y + r:g}A{r:g} {r:g} 0 0 1 {x + r:g} {y:g}Z")


A_PATH = poly(A_OUTER) + poly(A_COUNTER)
BAR_PATH = rrect(*BAR)
DOT_PATH = rrect(*DOT)


def mark(a, bar, dot):
    return (f'<path fill="{a}" fill-rule="evenodd" d="{A_PATH}"/>'
            f'<path fill="{bar}" d="{BAR_PATH}"/><path fill="{dot}" d="{DOT_PATH}"/>')


def tile(bg, a, bar, dot, size=G, square=False):
    k = size / G
    r = 0 if square else TILE_R
    body = f'<rect width="{G:g}" height="{G:g}" rx="{r:g}" fill="{bg}"/>' + mark(a, bar, dot)
    return body if k == 1 else f'<g transform="scale({k:.6f})">{body}</g>'


def svg(w, h, body, title="TokenHub"):
    return (f'<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 {w:g} {h:g}" '
            f'width="{w:g}" height="{h:g}" role="img" aria-label="{title}">'
            f"<title>{title}</title>{body}</svg>\n")


# ---------- Wordmark ----------
T = json.load(open(BASE + "/source/text.json"))
CAP = T["cap"]
WM_X0, _, WM_X1, _ = T["bbox"]
WM_W_UNITS = WM_X1 - WM_X0


def wordmark_body(c, scale, tx, ty):
    paths = "".join(f'<path d="{g["d"]}"/>' for g in T["glyphs"])
    return f'<g transform="translate({tx:.3f} {ty:.3f}) scale({scale:.6f})" fill="{c}">{paths}</g>'


# Horizontal lockup: tile height = 1 unit (U); cap height = 0.42 U; gap = 0.28 U
U = 48.0
CAP_H = U * 0.42
WM_SCALE = CAP_H / CAP
GAP = U * 0.28


def horizontal(word, bg, a, bar, dot):
    wm_w = WM_W_UNITS * WM_SCALE
    w, h = U + GAP + wm_w, U
    body = tile(bg, a, bar, dot, size=U)
    body += wordmark_body(word, WM_SCALE, U + GAP - WM_X0 * WM_SCALE, h / 2 + CAP_H / 2)
    return w, h, body


def stacked(word, bg, a, bar, dot):
    t, cap, gap = 96.0, 26.0, 22.0
    sc = cap / CAP
    wm_w = WM_W_UNITS * sc
    w, h = max(t, wm_w), t + gap + cap
    body = f'<g transform="translate({(w - t) / 2:.3f} 0)">{tile(bg, a, bar, dot, size=t)}</g>'
    body += wordmark_body(word, sc, (w - wm_w) / 2 - WM_X0 * sc, h)
    return w, h, body


def write(name, w, h, body):
    with open(f"{OUT}/logo/svg/{name}.svg", "w") as fh:
        fh.write(svg(w, h, body))


STD = (C["ink"], C["green"], C["deep"], C["green"])          # tile, A, bar, dot
MONO_BLACK = (C["black"], C["white"], C["white"], C["white"])
MONO_WHITE = (C["white"], C["ink"], C["ink"], C["ink"])


def build():
    # App icon (the master logo)
    write("tokenhub-app-icon", G, G, tile(*STD))
    write("tokenhub-app-icon-black", G, G, tile(*MONO_BLACK))
    write("tokenhub-app-icon-white", G, G, tile(*MONO_WHITE))
    write("tokenhub-apple-touch-icon", G, G, tile(*STD, square=True))
    # maskable: full bleed, mark scaled into the 80% safe circle
    bx0, by0, bx1, by1 = MARK_BBOX
    s = 0.86
    cx, cy = (bx0 + bx1) / 2, (by0 + by1) / 2
    write("tokenhub-icon-maskable", G, G,
          f'<rect width="{G:g}" height="{G:g}" fill="{C["ink"]}"/>'
          f'<g transform="translate({G/2 - cx*s:.3f} {G/2 - cy*s:.3f}) scale({s})">{mark(C["green"], C["deep"], C["green"])}</g>')
    # Symbol without tile ("A!" only), trimmed to its bounding box
    mw, mh = bx1 - bx0, by1 - by0
    for name, cols in [("symbol", (C["green"], C["deep"], C["green"])),
                       ("symbol-ink", (C["ink"], C["ink"], C["ink"])),
                       ("symbol-white", (C["white"], C["white"], C["white"]))]:
        write(f"tokenhub-{name}", mw, mh, f'<g transform="translate({-bx0:g} {-by0:g})">{mark(*cols)}</g>')
    # Favicon: same as app icon; 16 px uses the hand-hinted file
    write("tokenhub-favicon", G, G, tile(*STD))
    shutil.copy(BASE + "/source/tokenhub-favicon-16.svg", f"{OUT}/logo/svg/tokenhub-favicon-16.svg")
    # Wordmark
    wm_w = WM_W_UNITS * WM_SCALE
    for name, c in [("wordmark", C["ink"]), ("wordmark-white", C["white"]), ("wordmark-black", C["black"])]:
        write(f"tokenhub-{name}", wm_w, CAP_H, wordmark_body(c, WM_SCALE, -WM_X0 * WM_SCALE, CAP_H))
    # Lockups
    write("tokenhub-logo-horizontal", *horizontal(C["ink"], *STD))
    write("tokenhub-logo-horizontal-reverse", *horizontal(C["white"], *STD))
    write("tokenhub-logo-horizontal-black", *horizontal(C["black"], *MONO_BLACK))
    write("tokenhub-logo-horizontal-white", *horizontal(C["white"], *MONO_WHITE))
    write("tokenhub-logo-stacked", *stacked(C["ink"], *STD))
    write("tokenhub-logo-stacked-reverse", *stacked(C["white"], *STD))


if __name__ == "__main__":
    for f in os.listdir(OUT + "/logo/svg"):
        os.remove(os.path.join(OUT + "/logo/svg", f))
    build()
    print("ok")
