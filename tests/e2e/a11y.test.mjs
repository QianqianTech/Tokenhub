import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup, visibleTabs } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

// Lightweight checks that need no external tooling: every interactive control
// has an accessible name, images have alt text, ids are unique, and focus can
// reach the main content through the skip link.
function audit() {
  const text = (node) => (node?.textContent || "").replace(/\s+/g, " ").trim();
  const visible = (node) => !!(node.offsetWidth || node.offsetHeight || node.getClientRects().length) && getComputedStyle(node).visibility !== "hidden";
  const name = (node) => {
    if (node.getAttribute("aria-label")?.trim()) return true;
    const labelledBy = node.getAttribute("aria-labelledby");
    if (labelledBy && labelledBy.split(/\s+/).some((id) => text(document.getElementById(id)))) return true;
    if (node.closest("label") && text(node.closest("label"))) return true;
    if (node.id && [...document.querySelectorAll("label")].some((label) => label.htmlFor === node.id && text(label))) return true;
    if (node.title?.trim()) return true;
    if (["BUTTON", "A", "SUMMARY"].includes(node.tagName) && (text(node) || node.querySelector("img[alt]:not([alt=''])"))) return true;
    if (node.tagName === "INPUT" && ["submit", "button", "reset"].includes(node.type) && node.value) return true;
    return false;
  };
  const problems = [];
  const describe = (node) => `${node.tagName.toLowerCase()}${node.name ? `[name=${node.name}]` : ""}${node.className && typeof node.className === "string" ? `.${node.className.split(" ")[0]}` : ""}`;
  for (const node of document.querySelectorAll("#app button, #app a[href], #app input:not([type=hidden]), #app select, #app textarea, #app [role=combobox], #app summary")) {
    if (visible(node) && !name(node)) problems.push(`unnamed ${describe(node)}`);
  }
  for (const image of document.querySelectorAll("#app img")) {
    if (!image.hasAttribute("alt")) problems.push(`img without alt: ${image.getAttribute("src")}`);
  }
  const ids = new Map();
  for (const node of document.querySelectorAll("[id]")) ids.set(node.id, (ids.get(node.id) || 0) + 1);
  for (const [id, count] of ids) if (count > 1) problems.push(`duplicate id #${id}`);
  return problems;
}

test("every page passes the basic accessibility audit", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl);
  const failures = [];
  let controls = 0;
  for (const [domain, tab] of await visibleTabs(page)) {
    await page.goto(`${env.mock.baseUrl}/admin/#/${domain}/${tab}`);
    await settle(page);
    for (const problem of await page.evaluate(audit)) failures.push(`${domain}/${tab}: ${problem}`);
    controls += await page.locator("#content button, #content input, #content select").count();
  }
  await context.close();
  assert.ok(controls > 200, `audit should see the page controls (saw ${controls})`);
  assert.deepEqual([...new Set(failures)], []);
});

test("login form and skip link are keyboard accessible", async () => {
  const { page, context } = await openPage(env);
  await page.goto(`${env.mock.baseUrl}/admin/`);
  assert.deepEqual(await page.evaluate(audit), []);
  await login(page, env.mock.baseUrl);
  await page.reload();
  await settle(page);
  await page.keyboard.press("Tab");
  const skip = page.locator(".skip-link");
  assert.equal(await skip.evaluate((node) => node === document.activeElement), true, "skip link should be the first tab stop");
  const box = await skip.boundingBox();
  assert.ok(box && box.y >= 0, "focused skip link should be on screen");
  await page.keyboard.press("Enter");
  assert.equal(await page.evaluate(() => document.activeElement?.id), "content");
  await context.close();
});
