// TokenHub APP mobile regression: every console page reachable from the APP
// menu opens on a phone (390 px) and a small tablet (768 px) without errors,
// renders content and never scrolls sideways.
import { after, before, beforeEach, test } from "node:test";
import assert from "node:assert/strict";
import { openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });
beforeEach(() => env.mock.reset());

async function signIn(page) {
  const code = env.mock.issueLinkCode();
  await page.goto(`${env.mock.baseUrl}/app/link#c=${code}`);
  await page.locator(".tha-tabbar, .tha-rail").first().waitFor({ state: "visible" });
  await settle(page);
}

async function sweep(viewport) {
  const { page, errors, context } = await openPage(env, { viewport });
  await signIn(page);
  await page.goto(`${env.mock.baseUrl}/app/#/manage`);
  await page.locator(".tha-menu-item").first().waitFor();
  const links = await page.locator(".tha-menu-item").evaluateAll((nodes) => nodes.map((node) => node.getAttribute("href")).filter(Boolean));
  assert.ok(links.length >= 20, `expected the console pages, got ${links.length}`);
  const failures = [];
  for (const href of [...new Set(links)]) {
    if (process.env.APP_MOBILE_TRACE) console.error(`${viewport.width} ${href} ${Date.now()}`);
    errors.length = 0;
    await page.goto(`${env.mock.baseUrl}/app/${href}`);
    try {
      await page.locator(".tha-page").waitFor({ timeout: 8000 });
    } catch {
      failures.push(`${href}: page did not open`);
      continue;
    }
    // The live-update stream stays open, so "network idle" never arrives: settle briefly.
    await settle(page, { timeout: 1500 });
    const text = (await page.locator(".tha-page").innerText()).trim();
    if (!text) failures.push(`${href}: empty`);
    if (await page.locator("[data-page-error]").count()) failures.push(`${href}: failed to load`);
    if (errors.length) failures.push(`${href}: ${errors.join(" | ")}`);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (overflow > 1) {
      const culprit = await page.evaluate(() => {
        const wide = [...document.querySelectorAll("#content *")].find((node) => node.getBoundingClientRect().right > window.innerWidth + 1 && getComputedStyle(node).position !== "fixed");
        return wide ? `${wide.tagName.toLowerCase()}.${[...wide.classList].join(".")}` : "?";
      });
      failures.push(`${href}: page scrolls sideways by ${overflow}px (${culprit})`);
    }
  }
  await context.close();
  return { failures, count: links.length };
}

test("every console page works on a phone (390 px)", async () => {
  const { failures } = await sweep({ width: 390, height: 844 });
  assert.deepEqual(failures, []);
});

test("every console page works on a small tablet (768 px)", async () => {
  const { failures } = await sweep({ width: 768, height: 1024 });
  assert.deepEqual(failures, []);
});
