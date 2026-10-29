// Audit capture: screenshots + diagnostics for every admin page.
// usage: node capture.mjs <outDir> [--only=dashboard,api-keys]
import { mkdir, writeFile } from "node:fs/promises";
import { setup, openPage, login, settle, visibleTabs } from "./helpers.mjs";

const out = process.argv[2] || "/home/claude/audit/before";
const only = (process.argv.find((a) => a.startsWith("--only=")) || "").slice(7).split(",").filter(Boolean);
await mkdir(out, { recursive: true });

const DIAG = () => {
  const lum = (c) => {
    const m = c.match(/rgba?\(([^)]+)\)/); if (!m) return null;
    const [r, g, b, a = 1] = m[1].split(",").map(Number);
    if (a === 0) return null;
    const f = (v) => { v /= 255; return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4; };
    return 0.2126 * f(r) + 0.7152 * f(g) + 0.0722 * f(b);
  };
  const bgOf = (el) => { while (el) { const c = getComputedStyle(el).backgroundColor; if (lum(c) !== null) return c; el = el.parentElement; } return "rgb(255,255,255)"; };
  const visible = (el) => { const r = el.getBoundingClientRect(); const s = getComputedStyle(el); return r.width > 0 && r.height > 0 && s.visibility !== "hidden" && s.display !== "none" && Number(s.opacity) > 0.05; };
  const lowContrast = [];
  const rawKeys = new Set();
  const english = new Set();
  const walker = document.createTreeWalker(document.body, NodeFilter.SHOW_TEXT);
  let n;
  while ((n = walker.nextNode())) {
    const text = n.textContent.trim();
    const el = n.parentElement;
    if (!text || !el || !visible(el) || el.closest("script,style,code,pre,.mono,kbd")) continue;
    const fg = getComputedStyle(el).color;
    const l1 = lum(fg), l2 = lum(bgOf(el));
    if (l1 !== null && l2 !== null) {
      const ratio = (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
      const size = parseFloat(getComputedStyle(el).fontSize);
      if (ratio < (size >= 24 ? 3 : 4.5) && lowContrast.length < 12) lowContrast.push(`${ratio.toFixed(2)} "${text.slice(0, 30)}" <${el.tagName.toLowerCase()}.${String(el.className).slice(0, 30)}>`);
    }
    for (const m of text.matchAll(/\b[a-z]+(?:_[a-z0-9]+)+\b/g)) rawKeys.add(m[0]);
    if (/^[A-Za-z][A-Za-z \-/()]{2,}$/.test(text) && el.closest("th,button,label,h1,h2,h3,legend,.nav-button,a,dt,option")) english.add(text);
  }
  const doc = document.documentElement;
  const content = document.querySelector("#content");
  const tables = [...document.querySelectorAll("#content table")].map((t) => ({ cols: t.querySelectorAll("thead th").length, rows: t.querySelectorAll("tbody tr").length, wide: t.scrollWidth > t.parentElement.clientWidth + 2 }));
  const buttons = [...document.querySelectorAll("#content button")].filter(visible).map((b) => (b.textContent || b.getAttribute("aria-label") || "").trim()).filter(Boolean);
  const headings = [...document.querySelectorAll("#content h1,#content h2,#content h3")].filter(visible).map((h) => h.textContent.trim()).slice(0, 12);
  const overflowing = [...document.querySelectorAll("#content *")].filter((el) => visible(el) && el.getBoundingClientRect().right > window.innerWidth + 1).slice(0, 5).map((el) => `${el.tagName.toLowerCase()}.${String(el.className).slice(0, 40)}`);
  return {
    title: document.querySelector("#page-title")?.textContent?.trim(),
    hOverflow: doc.scrollWidth > doc.clientWidth + 1,
    contentHeight: content ? Math.round(content.scrollHeight) : 0,
    headings, buttons: buttons.slice(0, 30), buttonCount: buttons.length, tables,
    lowContrast, rawKeys: [...rawKeys].slice(0, 20), english: [...english].slice(0, 20), overflowing
  };
};

const env = await setup();
const report = {};
try {
  for (const scheme of ["light", "dark"]) {
    // login page
    for (const vp of [{ width: 1440, height: 900, tag: "desk" }, { width: 390, height: 844, tag: "phone" }]) {
      const { page, context } = await openPage(env, { viewport: vp, colorScheme: scheme });
      await page.goto(`${env.mock.baseUrl}/admin/`);
      await page.waitForTimeout(500);
      await page.screenshot({ path: `${out}/login-${scheme}-${vp.tag}.png`, fullPage: true });
      await context.close();
    }
    const { page, context, errors } = await openPage(env, { viewport: { width: 1440, height: 900 }, colorScheme: scheme });
    await login(page, env.mock.baseUrl);
    const tabs = await visibleTabs(page);
    for (const [domain, tab] of tabs) {
      if (only.length && !only.includes(tab)) continue;
      errors.length = 0;
      await page.evaluate((h) => { location.hash = h; }, `#/${domain}/${tab}`);
      await settle(page);
      await page.waitForTimeout(150);
      await page.screenshot({ path: `${out}/${domain}--${tab}-${scheme}.png`, fullPage: true });
      const diag = await page.evaluate(DIAG);
      report[`${domain}/${tab}`] ??= {};
      report[`${domain}/${tab}`][scheme] = { ...diag, errors: [...errors] };
    }
    await context.close();
  }
  // phone width for every page, light only
  const { page, context } = await openPage(env, { viewport: { width: 390, height: 844 }, colorScheme: "light" });
  await login(page, env.mock.baseUrl);
  for (const [domain, tab] of await visibleTabs(page)) {
    if (only.length && !only.includes(tab)) continue;
    await page.evaluate((h) => { location.hash = h; }, `#/${domain}/${tab}`);
    await settle(page);
    await page.screenshot({ path: `${out}/${domain}--${tab}-phone.png`, fullPage: false });
    const diag = await page.evaluate(DIAG);
    report[`${domain}/${tab}`].phone = { hOverflow: diag.hOverflow, overflowing: diag.overflowing, tables: diag.tables };
  }
  await context.close();
} finally {
  await writeFile(`${out}/report.json`, JSON.stringify(report, null, 1));
  await env.close();
}
console.log("pages", Object.keys(report).length);
