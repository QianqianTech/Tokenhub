"""Export PNG / ICO / manifest from the SVG masters (needs node + playwright, ImageMagick)."""
import json, os, re, subprocess, shutil

B = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
S, P, F = B + "/logo/svg", B + "/logo/png", B + "/favicon"
for d in (P, F):
    shutil.rmtree(d, ignore_errors=True)
    os.makedirs(d)


def dims(n):
    m = re.search(r'viewBox="0 0 ([\d.]+) ([\d.]+)"', open(f"{S}/{n}.svg").read())
    return float(m[1]), float(m[2])


jobs = []


def byh(n, h):
    w, hh = dims(n)
    jobs.append([f"{S}/{n}.svg", f"{P}/{n}-{h}h.png", round(w * h / hh), h])


def sq(n, s, out):
    jobs.append([f"{S}/{n}.svg", out, s, s])


for n in ["tokenhub-app-icon", "tokenhub-app-icon-black", "tokenhub-app-icon-white"]:
    for s in (64, 128, 256, 512, 1024):
        sq(n, s, f"{P}/{n}-{s}.png")
for n in ["tokenhub-symbol", "tokenhub-symbol-ink", "tokenhub-symbol-white"]:
    for h in (64, 256, 512):
        byh(n, h)
for n in ["tokenhub-logo-horizontal", "tokenhub-logo-horizontal-reverse", "tokenhub-logo-horizontal-black",
          "tokenhub-logo-horizontal-white", "tokenhub-logo-stacked", "tokenhub-logo-stacked-reverse",
          "tokenhub-wordmark", "tokenhub-wordmark-white"]:
    for h in (64, 128, 256):
        byh(n, h)
sq("tokenhub-favicon-16", 16, f"{F}/favicon-16.png")
sq("tokenhub-favicon", 32, f"{F}/favicon-32.png")
sq("tokenhub-favicon", 48, f"{F}/favicon-48.png")
sq("tokenhub-apple-touch-icon", 180, f"{F}/apple-touch-icon.png")
sq("tokenhub-app-icon", 192, f"{F}/icon-192.png")
sq("tokenhub-app-icon", 512, f"{F}/icon-512.png")
sq("tokenhub-icon-maskable", 512, f"{F}/icon-maskable-512.png")

jf = B + "/source/.jobs.json"
json.dump(jobs, open(jf, "w"))
subprocess.run(["node", B + "/source/render.js", jf], check=True)
os.remove(jf)
subprocess.run(["convert", f"{F}/favicon-16.png", f"{F}/favicon-32.png", f"{F}/favicon-48.png", f"{F}/favicon.ico"], check=True)
shutil.copy(f"{S}/tokenhub-favicon.svg", f"{F}/favicon.svg")
json.dump({"name": "TokenHub", "short_name": "TokenHub", "icons": [
    {"src": "icon-192.png", "sizes": "192x192", "type": "image/png"},
    {"src": "icon-512.png", "sizes": "512x512", "type": "image/png"},
    {"src": "icon-maskable-512.png", "sizes": "512x512", "type": "image/png", "purpose": "maskable"}],
    "theme_color": "#151A21", "background_color": "#151A21", "display": "standalone"},
    open(f"{F}/site.webmanifest", "w"), indent=2)
print(len(jobs), "png")
