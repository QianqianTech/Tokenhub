"""Build the TokenHub logo guideline page (Artifact fragment + standalone HTML)."""
import base64, os, re, sys

BASE = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
sys.path.insert(0, BASE + "/source")
import build as B

S = BASE + "/logo/svg"
F = BASE + "/favicon"
C = B.C


def raw(name, cls="", style=""):
    s = open(f"{S}/{name}.svg").read().strip()
    s = re.sub(r'\swidth="[\d.]+" height="[\d.]+"', "", s, count=1)
    s = re.sub(r"<title>.*?</title>", "", s)
    attrs = (f' class="{cls}"' if cls else "") + (f' style="{style}"' if style else "")
    return s.replace("<svg ", f"<svg{attrs} ", 1)


def png(path):
    return "data:image/png;base64," + base64.b64encode(open(path, "rb").read()).decode()


def construction():
    g = []
    for i in range(0, 251, 25):
        g.append(f'<line x1="{i}" y1="0" x2="{i}" y2="250" class="cg"/><line x1="0" y1="{i}" x2="250" y2="{i}" class="cg"/>')
    art = B.tile(*B.STD)
    o = []
    for y, lab in [(78, "顶线 78"), (172, "基线 172"), (141, "141"), (149, "149")]:
        o.append(f'<line x1="0" y1="{y}" x2="250" y2="{y}" class="ca"/>')
    for x in (61.5, 148, 156, 188):
        o.append(f'<line x1="{x}" y1="0" x2="{x}" y2="250" class="ca"/>')
    o.append('<line x1="125" y1="0" x2="125" y2="250" class="cc"/><line x1="0" y1="125" x2="250" y2="125" class="cc"/>')
    o.append(f'<circle cx="{B.TILE_R}" cy="{B.TILE_R}" r="{B.TILE_R}" class="cl"/>')
    o.append(f'<rect x="61.5" y="78" width="126.5" height="94" class="cb"/>')
    return f'<svg viewBox="-6 -6 262 262" class="construct" role="img" aria-label="标志构成网格">{"".join(g)}{art}{"".join(o)}</svg>'


def clearspace():
    w, h, body = B.horizontal(C["ink"], *B.STD)
    x = 12  # X = 1/4 icon height = height of the "!" stem
    marks = (f'<rect x="{-x}" y="{-x}" width="{w + 2 * x:g}" height="{h + 2 * x}" class="zone"/>'
             f'<rect x="0" y="0" width="{w:g}" height="{h}" class="edge"/>')
    for (rx, ry) in [(-x, 18), (w, 18), (18, -x), (18, h)]:
        marks += f'<rect x="{rx:g}" y="{ry}" width="{x}" height="{x}" class="xbox"/><text x="{rx + x / 2:g}" y="{ry + x / 2 + 1.7:g}" class="xt">X</text>'
    return f'<svg viewBox="{-x - 2} {-x - 2} {w + 2 * x + 4:g} {h + 2 * x + 4}" class="clear" role="img" aria-label="安全区示意">{marks}{body}</svg>'


COLORS = [
    ("Obsidian", "曜石黑 · 主色", C["ink"], "21 26 33", "36 21 0 87", "Black 6 C（近似）", "图标底板、浅色背景上的字标、深色界面底色"),
    ("Signal Green", "信号绿 · 主色", C["green"], "52 211 153", "75 0 27 17", "7479 C（近似）", "字母 A、感叹号圆点、深色背景上的强调"),
    ("Pine", "深松绿 · 辅助色", C["deep"], "35 109 87", "68 0 20 57", "7727 C（近似）", "感叹号竖条；等于信号绿以 45% 透明度叠在曜石黑上"),
    ("Paper", "纸白 · 中性", C["white"], "255 255 255", "0 0 0 0", "—", "浅色背景、深色背景上的字标"),
]

MISUSE = [
    ("stretch", "不得拉伸或压扁"),
    ("rotate", "不得旋转或倾斜"),
    ("recolor", "不得改用规范外颜色"),
    ("flatbar", "不得把竖条改为亮绿"),
    ("effects", "不得添加阴影、发光、渐变"),
    ("rearrange", "不得调换图标与字标位置"),
    ("lowcontrast", "不得在深色背景用深色字标"),
    ("corner", "不得改变底板圆角"),
]


def misuse_tile(kind):
    logo = raw("tokenhub-logo-horizontal", "mu-logo")
    bg = ""
    if kind == "recolor":
        logo = logo.replace(C["green"], "#F472B6").replace(C["deep"], "#7C3AED")
    if kind == "flatbar":
        logo = logo.replace(f'fill="{C["deep"]}"', f'fill="{C["green"]}"')
    if kind == "lowcontrast":
        bg = ' style="background:#20262F"'
    if kind == "corner":
        logo = logo.replace(f'rx="{B.TILE_R:g}"', 'rx="6"')
    if kind == "rearrange":
        logo = f'<span class="mu-row">{raw("tokenhub-wordmark", "mu-wm")}{raw("tokenhub-app-icon", "mu-icon")}</span>'
    return (f'<figure class="mu mu-{kind}"><div class="mu-stage"{bg}>{logo}</div>'
            f'<figcaption><span class="no" aria-hidden="true">✕</span>{dict(MISUSE)[kind]}</figcaption></figure>')


CSS = r"""
<style>
/* Layout: single specification column with full-width specimen plates, like a printed VI manual */
:root{
  --bg:#F3F5F4; --paper:#FFFFFF; --fg:#151A21; --muted:#5A6570; --line:#DCE2E0; --soft:#E9EEEC;
  --accent:#0F7A5A; --grid:#D3DCD8; --guide:#E0457B; --bad:#C2410C;
  --sans:"Inter","Noto Sans SC","PingFang SC","Microsoft YaHei",system-ui,sans-serif;
  --mono:"JetBrains Mono",ui-monospace,"SFMono-Regular",Menlo,monospace;
}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]){
  --bg:#0E1217; --paper:#151A21; --fg:#E6ECEA; --muted:#93A09B; --line:#26303A; --soft:#1B222B;
  --accent:#34D399; --grid:#26303A; --guide:#F472B6; --bad:#FB923C; color-scheme:dark}}
:root[data-theme="dark"]{
  --bg:#0E1217; --paper:#151A21; --fg:#E6ECEA; --muted:#93A09B; --line:#26303A; --soft:#1B222B;
  --accent:#34D399; --grid:#26303A; --guide:#F472B6; --bad:#FB923C; color-scheme:dark}
body{background:var(--bg);color:var(--fg);font-family:var(--sans);font-size:15px;line-height:1.65;
  padding-left:max(16px,env(safe-area-inset-left,0px));padding-right:max(16px,env(safe-area-inset-right,0px));padding-block:0 64px}
.wrap{max-width:1040px;margin:0 auto}
h1,h2,h3{text-wrap:balance;line-height:1.25;margin:0} p{margin:0}
.mono{font-family:var(--mono);font-size:12.5px;font-variant-numeric:tabular-nums}
header.top{display:flex;justify-content:space-between;align-items:center;gap:12px;padding-block:20px;border-bottom:1px solid var(--line);flex-wrap:wrap}
header.top .doc{color:var(--muted);font-size:13px}
header.top svg{height:30px;width:auto}
.logo-dark{display:none}
@media (prefers-color-scheme:dark){:root:not([data-theme="light"]) .logo-light{display:none}:root:not([data-theme="light"]) .logo-dark{display:block}}
:root[data-theme="dark"] .logo-light{display:none} :root[data-theme="dark"] .logo-dark{display:block}
.hero{display:grid;grid-template-columns:minmax(0,1.15fr) minmax(0,1fr);gap:40px;align-items:center;padding-block:56px 40px}
.hero h1{font-size:clamp(28px,4.4vw,44px);font-weight:800;letter-spacing:-.01em}
.hero p{color:var(--muted);margin-top:14px;max-width:34em}
.hero .plate{border-radius:16px;padding:40px;display:grid;place-items:center;background:var(--paper);border:1px solid var(--line)}
.hero .plate svg{width:100%;max-width:280px;height:auto}
.parts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:20px;margin-top:28px}
.parts div{border-top:2px solid var(--accent);padding-top:10px}
.parts b{display:block;font-size:14px} .parts span{color:var(--muted);font-size:13.5px}
section{padding-block:44px;border-top:1px solid var(--line)}
.sec-head{display:grid;grid-template-columns:120px minmax(0,1fr);gap:16px;margin-bottom:24px}
.sec-head .no{font-family:var(--mono);font-size:12px;color:var(--accent);letter-spacing:.08em;padding-top:6px}
.sec-head h2{font-size:24px;font-weight:750}
.sec-head p{color:var(--muted);margin-top:6px;max-width:44em}
.plate{background:var(--paper);border:1px solid var(--line);border-radius:12px}
.two{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:20px}
.construct{width:100%;max-width:420px;height:auto;display:block;margin:auto}
.construct .cg{stroke:var(--grid);stroke-width:.6}
.construct .ca{stroke:var(--guide);stroke-width:.7;stroke-dasharray:3 2}
.construct .cc{stroke:var(--fg);stroke-width:.5;opacity:.35}
.construct .cl{fill:none;stroke:var(--guide);stroke-width:.8;stroke-dasharray:3 2}
.construct .cb{fill:none;stroke:#FFFFFF;stroke-width:.7;stroke-dasharray:2 2;opacity:.6}
.specs div{display:flex;justify-content:space-between;gap:12px;padding:10px 0;border-bottom:1px dashed var(--line)}
.specs dt{color:var(--muted)} .specs dd{margin:0;font-family:var(--mono);font-size:13px;text-align:right}
.lockups{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
.lock{border-radius:12px;overflow:hidden;border:1px solid var(--line)}
.lock .stage{display:grid;place-items:center;padding:36px 20px;min-height:180px}
.lock .stage svg{max-width:100%;height:auto}
.lock .cap{padding:12px 16px;background:var(--paper);border-top:1px solid var(--line);display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
.lock .cap b{font-size:14px}.lock .cap span{color:var(--muted);font-size:13px}
.on-white{background:#FFFFFF}.on-ink{background:#151A21}.on-mist{background:#E9EEEC}.on-green{background:#34D399}.on-black{background:#000000}.on-slate{background:#2B3440}
.on-photo{background:linear-gradient(135deg,#0d2b24 0%,#123a30 45%,#0a1f1a 100%)}
.w-h svg{width:260px}.w-s svg{width:140px}.w-i svg{width:100px}.w-w svg{width:220px}.w-m svg{width:110px}
.clear{width:100%;max-width:620px;height:auto;display:block;margin:auto}
.clear .zone{fill:color-mix(in srgb,var(--guide) 8%,transparent);stroke:var(--guide);stroke-width:.4;stroke-dasharray:2 1.5}
.clear .edge{fill:none;stroke:#151A21;stroke-width:.25;opacity:.4}
.clear .xbox{fill:color-mix(in srgb,var(--guide) 18%,transparent);stroke:var(--guide);stroke-width:.3}
.clear .xt{fill:var(--guide);font:700 5px var(--mono);text-anchor:middle}
.clear-plate{padding:28px 20px;background:#FFFFFF}
.min{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}
.min .plate{padding:20px;display:grid;gap:12px;align-content:start}
.min .px{background:#FFFFFF;border-radius:8px;padding:10px;display:inline-flex;align-items:flex-end;gap:12px;border:1px solid var(--line);justify-self:start}
.min b{font-size:14px}.min p{color:var(--muted);font-size:13px}
.swatches{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.sw{border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--paper);display:grid;grid-template-rows:120px auto}
.sw .chip{display:flex;align-items:flex-end;padding:12px}
.sw .chip span{font:700 13px var(--sans)}
.sw .info{padding:12px;display:grid;gap:3px}
.sw .info b{font-size:14px}
.sw dl{margin:6px 0 0;display:grid;grid-template-columns:auto 1fr;gap:2px 10px}
.sw dt{color:var(--muted);font-size:11.5px;letter-spacing:.04em}.sw dd{margin:0;font-family:var(--mono);font-size:12px}
.sw .use{color:var(--muted);font-size:12.5px;margin-top:8px;line-height:1.5}
.ratio{display:flex;height:22px;border-radius:6px;overflow:hidden;margin-top:18px;border:1px solid var(--line)}
.ratio-l{display:flex;justify-content:space-between;color:var(--muted);font-size:12.5px;margin-top:6px;flex-wrap:wrap;gap:8px}
.type{display:grid;grid-template-columns:minmax(0,1.2fr) minmax(0,1fr);gap:16px}
.type .plate{padding:24px}
.type .big{font-size:56px;font-weight:700;letter-spacing:-.02em;line-height:1.05;font-family:"Inter",sans-serif}
.type .zh{font-size:34px;font-weight:700;font-family:"Noto Sans SC",sans-serif;line-height:1.2}
.type .glyphs{font-family:"Inter",sans-serif;font-weight:700;letter-spacing:.06em;color:var(--muted);margin-top:12px;word-break:break-all}
.type p.n{color:var(--muted);font-size:13.5px;margin-top:12px}
.bgs{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.bg{border-radius:12px;overflow:hidden;border:1px solid var(--line)}
.bg .stage{height:130px;display:grid;place-items:center;padding:16px}
.bg .stage svg{width:100%;max-width:190px;height:auto}
.bg .cap{padding:10px 12px;background:var(--paper);font-size:13px;border-top:1px solid var(--line)}
.bg .cap em{font-style:normal;color:var(--accent);font-weight:700;margin-right:6px}
.misuse{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
.mu{margin:0;border:1px solid var(--line);border-radius:12px;overflow:hidden;background:var(--paper)}
.mu-stage{height:120px;display:grid;place-items:center;background:#FFFFFF;position:relative;overflow:hidden;padding:12px}
.mu-stage::after{content:"";position:absolute;inset:0;background:linear-gradient(to top right,transparent calc(50% - 1px),color-mix(in srgb,var(--bad) 70%,transparent) 50%,transparent calc(50% + 1px))}
.mu-logo{width:100%;max-width:170px;height:auto}
.mu-stretch .mu-logo{transform:scaleX(1.4) scaleY(.7);max-width:120px}
.mu-rotate .mu-logo{transform:rotate(-14deg)}
.mu-effects .mu-logo{filter:drop-shadow(0 0 6px #34D399) drop-shadow(3px 4px 0 #A855F7)}
.mu-row{display:flex;align-items:center;gap:8px}
.mu-row .mu-wm{width:min(120px,22vw);height:auto}.mu-row .mu-icon{width:32px;height:32px;flex:0 0 auto}
.mu figcaption{padding:10px 12px;font-size:13px;display:flex;gap:8px;align-items:center;border-top:1px solid var(--line)}
.mu .no{color:var(--bad);font-weight:800}
.apps{display:grid;grid-template-columns:minmax(0,1.4fr) minmax(0,1fr);gap:16px;margin-bottom:20px}
.mock{border-radius:12px;overflow:hidden;border:1px solid var(--line);display:grid;grid-template-columns:180px 1fr;min-height:220px;background:#F4F6F9}
.mock .side{background:#152033;padding:16px 12px;display:grid;align-content:start;gap:8px}
.mock .side .b{display:flex;align-items:center;gap:8px;color:#EDF3FB;font-weight:700;font-size:15px;margin-bottom:10px}
.mock .side .b svg{width:28px;height:28px}
.mock .side i{display:block;height:9px;border-radius:4px;background:#24334D}
.mock .side i.on{background:#2563EB;width:80%}
.mock .main{padding:16px;display:grid;gap:10px;align-content:start}
.mock .main i{display:block;height:44px;border-radius:8px;background:#FFFFFF;border:1px solid #D8E0EB}
.tab{border-radius:12px;border:1px solid var(--line);background:var(--paper);padding:16px;display:grid;gap:14px;align-content:start}
.tab .bar{display:flex;align-items:center;gap:8px;background:var(--soft);border-radius:8px 8px 0 0;padding:8px 10px;font-size:12.5px;max-width:240px}
.tab .icons{display:flex;gap:14px;align-items:flex-end;flex-wrap:wrap}
.tab .icons figure{margin:0;display:grid;justify-items:center;gap:4px;font-size:11.5px;color:var(--muted)}
.scroll{overflow-x:auto;background:var(--paper);border:1px solid var(--line);border-radius:12px}
.files{width:100%;border-collapse:collapse;font-size:13.5px;min-width:640px}
.files th,.files td{text-align:left;padding:10px 12px;border-bottom:1px solid var(--line);vertical-align:top}
.files th{color:var(--muted);font-weight:600;font-size:12.5px;letter-spacing:.04em}
.files td:first-child{font-family:var(--mono);font-size:12.5px;white-space:nowrap}
footer{border-top:1px solid var(--line);padding-top:20px;color:var(--muted);font-size:13px;display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap}
@media (max-width:860px){
  .hero{grid-template-columns:1fr;padding-block:36px 28px}
  .two,.type,.apps{grid-template-columns:1fr}
  .swatches,.bgs,.misuse{grid-template-columns:repeat(2,minmax(0,1fr))}
  .min{grid-template-columns:1fr}
  .sec-head{grid-template-columns:1fr;gap:4px}
}
@media (max-width:520px){
  .lockups,.parts,.swatches{grid-template-columns:1fr}
  .mock{grid-template-columns:120px 1fr}
}
</style>
"""


def page():
    sec = []
    sec.append(f'''
<header class="top"><span>{raw("tokenhub-logo-horizontal", "logo-light")}{raw("tokenhub-logo-horizontal-reverse", "logo-dark")}</span>
<span class="doc mono">品牌标识规范 · v1.0 · 2026-10</span></header>
<div class="hero">
  <div>
    <h1>TokenHub 标识规范</h1>
    <p>标志由曜石黑圆角底板、信号绿字母 A 和一个感叹号组成。本规范给出它的构成数值、组合方式、色彩与使用边界，所有对外物料与产品界面都以此为准。</p>
    <div class="parts">
      <div><b>圆角底板</b><span>曜石黑，圆角为边长的 22.4%，是标志不可拆分的一部分</span></div>
      <div><b>字母 A</b><span>信号绿，平顶结构，笔画宽约 18.5 u</span></div>
      <div><b>感叹号</b><span>深松绿竖条与信号绿圆点，一暗一亮</span></div>
    </div>
  </div>
  <div class="plate">{raw("tokenhub-app-icon")}</div>
</div>''')

    sec.append(f'''
<section id="construction"><div class="sec-head"><span class="no">01 构成</span><div><h2>标志构成</h2>
<p>标志绘制在 250 × 250 单位（u）网格上，图形组合在底板中水平、垂直均居中。重绘或制作实体物料时以下数值为唯一依据。</p></div></div>
<div class="two"><div class="plate" style="padding:20px">{construction()}</div>
<dl class="specs plate" style="padding:8px 20px">
<div><dt>底板</dt><dd>250 × 250 u</dd></div>
<div><dt>底板圆角</dt><dd>56 u（22.4%）</dd></div>
<div><dt>图形区域</dt><dd>126.5 × 94 u，居中</dd></div>
<div><dt>顶线 / 基线</dt><dd>y = 78 / 172</dd></div>
<div><dt>字母 A 宽度</dt><dd>86 u（平顶 27 u）</dd></div>
<div><dt>A 笔画宽</dt><dd>≈ 18.5 u</dd></div>
<div><dt>A 横画</dt><dd>y 131.5 – 147.5（16 u）</dd></div>
<div><dt>A 与感叹号间距</dt><dd>8 u</dd></div>
<div><dt>感叹号宽</dt><dd>32 u</dd></div>
<div><dt>竖条 / 圆点高</dt><dd>63 u / 23 u，间隔 8 u</dd></div>
<div><dt>感叹号圆角</dt><dd>7 u</dd></div>
</dl></div></section>''')

    sec.append(f'''
<section id="lockups"><div class="sec-head"><span class="no">02 组合</span><div><h2>标志组合</h2>
<p>图标是主标志，可单独使用。需要出现产品名称时用横版组合；空间接近正方形时用竖版。字标只在同一版面已出现图标时单独使用。</p></div></div>
<div class="lockups">
<div class="lock"><div class="stage on-mist w-i">{raw("tokenhub-app-icon")}</div><div class="cap"><b>图标 · 主标志</b><span>favicon、侧栏、头像、应用图标</span></div></div>
<div class="lock"><div class="stage on-white w-h">{raw("tokenhub-logo-horizontal")}</div><div class="cap"><b>横版组合</b><span>页眉、登录页、文档封面</span></div></div>
<div class="lock"><div class="stage on-white w-s">{raw("tokenhub-logo-stacked")}</div><div class="cap"><b>竖版组合</b><span>启动页、海报、方形版面</span></div></div>
<div class="lock"><div class="stage on-ink w-m">{raw("tokenhub-symbol")}</div><div class="cap"><b>无底板图形</b><span>仅限曜石黑或接近的深色背景</span></div></div>
</div>
<p class="mono" style="color:var(--muted);margin-top:14px">横版比例：图标高 1 · 图标与字标间距 0.28 · 字标大写字高 0.42，与图标垂直居中</p>
</section>''')

    sec.append(f'''
<section id="clearspace"><div class="sec-head"><span class="no">03 安全区</span><div><h2>安全区</h2>
<p>X = 图标高度的 1/4，约等于感叹号竖条的高度。标志四周至少留出 X 的空白，文字、图片、页面边缘都不得进入。</p></div></div>
<div class="plate clear-plate">{clearspace()}</div></section>''')

    def ico(p, s):
        return f'<img src="{png(F + p)}" width="{s}" height="{s}" alt="">'
    sec.append(f'''
<section id="minimum"><div class="sec-head"><span class="no">04 最小尺寸</span><div><h2>最小使用尺寸</h2>
<p>16 px 时感叹号与 A 的间距不足一个像素，因此单独绘制了像素对齐版本：加大图形、拉开间距，配色不变。</p></div></div>
<div class="min">
<div class="plate"><div class="px">{raw("tokenhub-logo-horizontal", style="height:24px;width:auto")}</div><b>横版组合</b><p>屏幕 ≥ 24 px 高<br>印刷 ≥ 25 mm 宽</p></div>
<div class="plate"><div class="px">{raw("tokenhub-logo-stacked", style="height:52px;width:auto")}</div><b>竖版组合</b><p>屏幕 ≥ 52 px 高<br>印刷 ≥ 15 mm 宽</p></div>
<div class="plate"><div class="px">{ico("/favicon-32.png", 32)}{ico("/favicon-16.png", 16)}</div><b>图标</b><p>≥ 24 px 用标准图标<br>16 px 用 favicon-16 专用版</p></div>
</div></section>''')

    sw = ""
    for en, zh, hx, rgb, cmyk, pms, use in COLORS:
        tc = "#151A21" if hx in (C["green"], C["white"]) else "#FFFFFF"
        bd = "border-bottom:1px solid var(--line);" if hx == C["white"] else ""
        sw += (f'<div class="sw"><div class="chip" style="background:{hx};{bd}"><span style="color:{tc}">{en}</span></div>'
               f'<div class="info"><b>{zh}</b><dl><dt>HEX</dt><dd>{hx}</dd><dt>RGB</dt><dd>{rgb}</dd><dt>CMYK</dt><dd>{cmyk}</dd>'
               f'<dt>PMS</dt><dd>{pms}</dd></dl><p class="use">{use}</p></div></div>')
    sec.append(f'''
<section id="color"><div class="sec-head"><span class="no">05 色彩</span><div><h2>标准色</h2>
<p>色值取自原始设计稿。屏幕以 HEX/RGB 为准，CMYK 与 Pantone 为换算近似值，印刷前须打样校色。信号绿在曜石黑上的对比度为 9.1 : 1；在白底上只有 1.9 : 1，因此不得用信号绿在浅色背景上写字。</p></div></div>
<div class="swatches">{sw}</div>
<div class="ratio" aria-label="用色比例"><span style="flex:55;background:#151A21"></span><span style="flex:25;background:#FFFFFF"></span><span style="flex:14;background:#34D399"></span><span style="flex:6;background:#236D57"></span></div>
<div class="ratio-l"><span>建议用色比例：曜石黑 55%</span><span>纸白 25%</span><span>信号绿 14%</span><span>深松绿 6%</span></div>
</section>''')

    sec.append('''
<section id="type"><div class="sec-head"><span class="no">06 字体</span><div><h2>标准字体</h2>
<p>字标“TokenHub”使用 Inter Display Bold，已转为曲线，“To”字偶采用字体自带字距，整体收紧 10/2048 em。文件不依赖字体，任何设备显示一致。</p></div></div>
<div class="type">
<div class="plate"><div class="big">TokenHub</div><div class="glyphs">ABCDEFGHIJKLMNOPQRSTUVWXYZ 0123456789</div><p class="n">英文与数字：Inter / Inter Display（SIL OFL，可商用）。标题 700，正文 400，数据 500 并开启等宽数字。</p></div>
<div class="plate"><div class="zh">统一模型网关</div><div class="glyphs" style="font-family:'Noto Sans SC',sans-serif">鉴权 计量 配额 审计 大屏</div><p class="n">中文：思源黑体 / Noto Sans SC（SIL OFL）。回退顺序与管理后台一致：Inter → 微软雅黑 → 系统无衬线。</p></div>
</div></section>''')

    bgs = [
        ("on-white", "tokenhub-logo-horizontal", "推荐", "白色、浅灰背景：标准版，字标曜石黑"),
        ("on-mist", "tokenhub-logo-horizontal", "推荐", "雾绿浅底：标准版"),
        ("on-ink", "tokenhub-logo-horizontal-reverse", "推荐", "曜石黑背景：反白字标，底板与背景相融"),
        ("on-slate", "tokenhub-logo-horizontal-reverse", "推荐", "深灰蓝背景：反白字标"),
        ("on-photo", "tokenhub-logo-horizontal-reverse", "可用", "深色图片：局部背景平整、对比充足"),
        ("on-green", "tokenhub-logo-horizontal", "可用", "信号绿色块：标准版，限活动物料"),
        ("on-white", "tokenhub-logo-horizontal-black", "单色", "单色印刷：黑底板反白图形"),
        ("on-black", "tokenhub-logo-horizontal-white", "单色", "单色反白：白底板黑图形"),
    ]
    b = "".join(f'<div class="bg"><div class="stage {c}">{raw(n)}</div><div class="cap"><em>{t}</em>{d}</div></div>' for c, n, t, d in bgs)
    sec.append(f'''
<section id="backgrounds"><div class="sec-head"><span class="no">07 背景</span><div><h2>背景与色彩版本</h2>
<p>图标自带底板，可放在大多数背景上；需要调整的只有字标颜色。浅色背景用曜石黑字标，深色背景用白色字标。</p></div></div>
<div class="bgs">{b}</div></section>''')

    m = "".join(misuse_tile(k) for k, _ in MISUSE)
    sec.append(f'''
<section id="misuse"><div class="sec-head"><span class="no">08 禁用</span><div><h2>错误用法</h2>
<p>以下处理都会改变标志本身。需要新组合或特殊效果时，先提出需求，由规范维护人出图。</p></div></div>
<div class="misuse">{m}</div></section>''')

    sec.append(f'''
<section id="application"><div class="sec-head"><span class="no">09 应用</span><div><h2>产品内应用</h2>
<p>管理后台与运营大屏默认使用以下资源；在“系统设置 → 标题与标识”中填入新的 Logo、浏览器图标地址可以覆盖默认值。</p></div></div>
<div class="apps">
<div class="mock" aria-label="管理后台侧栏示意"><div class="side"><div class="b">{raw("tokenhub-app-icon")}TokenHub</div><i class="on"></i><i></i><i></i><i style="width:70%"></i><i></i></div><div class="main"><i style="height:28px;width:50%"></i><i></i><i></i><i></i></div></div>
<div class="tab"><div class="bar">{ico("/favicon-16.png", 16)}<span>TokenHub 管理后台</span></div>
<div class="icons">
<figure><img src="{png(F + '/apple-touch-icon.png')}" width="60" height="60" style="border-radius:13px" alt=""><figcaption>iOS 180</figcaption></figure>
<figure>{ico("/icon-192.png", 48)}<figcaption>PWA 192</figcaption></figure>
<figure>{ico("/favicon-32.png", 32)}<figcaption>32</figcaption></figure>
<figure>{ico("/favicon-16.png", 16)}<figcaption>16</figcaption></figure>
</div></div></div>
<div class="scroll"><table class="files"><thead><tr><th>文件</th><th>用途</th><th>说明</th></tr></thead><tbody>
<tr><td>logo/svg/tokenhub-app-icon.svg</td><td>图标 · 主标志</td><td>另有 -black / -white 单色版</td></tr>
<tr><td>logo/svg/tokenhub-logo-horizontal.svg</td><td>横版组合</td><td>浅色背景；另有 -reverse / -black / -white</td></tr>
<tr><td>logo/svg/tokenhub-logo-stacked.svg</td><td>竖版组合</td><td>另有 -reverse</td></tr>
<tr><td>logo/svg/tokenhub-symbol.svg</td><td>无底板图形</td><td>另有 -ink / -white</td></tr>
<tr><td>logo/svg/tokenhub-wordmark.svg</td><td>字标</td><td>另有 -white / -black</td></tr>
<tr><td>logo/png/</td><td>位图</td><td>图标 64–1024 方形；组合与字标按高度 64 / 128 / 256 导出</td></tr>
<tr><td>favicon/favicon.ico</td><td>浏览器图标</td><td>内含 16 / 32 / 48，16 为像素对齐专用版</td></tr>
<tr><td>favicon/favicon.svg</td><td>矢量浏览器图标</td><td>现代浏览器优先使用</td></tr>
<tr><td>favicon/apple-touch-icon.png</td><td>iOS 主屏</td><td>180 × 180，满版无圆角，由系统加遮罩</td></tr>
<tr><td>favicon/icon-192.png · icon-512.png</td><td>PWA</td><td>配合 site.webmanifest</td></tr>
<tr><td>favicon/icon-maskable-512.png</td><td>Android 自适应图标</td><td>图形位于中心 80% 安全区内</td></tr>
</tbody></table></div></section>''')

    sec.append('<footer><span>TokenHub 品牌标识规范 v1.0</span><span>源文件：仓库 brand/ 目录，可由 brand/source 中的脚本重新生成</span></footer>')

    head = ('<title>TokenHub 标识规范</title>\n<meta name="claude:viewport-fit-sides" content="cover">\n'
            '<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>\n'
            '<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800&family=JetBrains+Mono:wght@400;700&family=Noto+Sans+SC:wght@400;700&display=swap">\n')
    return head + CSS + '<div class="wrap">' + "".join(sec) + "</div>\n"


if __name__ == "__main__":
    frag = page()
    out = sys.argv[1] if len(sys.argv) > 1 else None
    if out:
        open(out, "w").write(frag)
    full = ('<!doctype html>\n<html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">\n'
            + frag.replace('<div class="wrap">', '</head><body><div class="wrap">', 1) + "</body></html>\n")
    open(BASE + "/tokenhub-brand-guidelines.html", "w").write(full)
    print(len(frag) // 1024, "KB")
