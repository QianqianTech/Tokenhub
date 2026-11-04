// TokenHub APP (/app/): sign-in by one-time link, staying signed in across
// restarts, silent token renewal, console pages inside the APP, PIN step-up,
// sign-out, and the desktop layout.
import { after, before, beforeEach, test } from "node:test";
import assert from "node:assert/strict";
import { openPage, settle, setup } from "./helpers.mjs";

const PHONE = { viewport: { width: 390, height: 844 } };
let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });
beforeEach(() => env.mock.reset());

async function signIn(page) {
  const code = env.mock.issueLinkCode();
  await page.goto(`${env.mock.baseUrl}/app/link#c=${code}`);
  await page.locator(".tha-tabbar").waitFor({ state: "visible" });
  await settle(page);
  return code;
}

test("without a device credential the APP asks for a sign-in link", async () => {
  const { page, errors, context } = await openPage(env, PHONE);
  await page.goto(`${env.mock.baseUrl}/app/`);
  await page.locator(".tha-welcome").waitFor();
  assert.equal(await page.locator(".tha-tabbar").isHidden(), true);
  await page.locator(".tha-link-input").fill("https://example.com/app/link#c=nope");
  await page.locator(".tha-welcome-form button[type=submit]").click();
  assert.match(await page.locator(".tha-error").first().innerText(), /不是有效的认证链接/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("opening the link signs in, removes the code from the URL and shows the overview", async () => {
  const { page, errors, context } = await openPage(env, PHONE);
  const code = await signIn(page);
  assert.equal(new URL(page.url()).hash, "#/overview");
  assert.ok(!page.url().includes(code), "the one-time code must not stay in the address bar");
  await page.locator(".tha-kpi").first().waitFor();
  const text = await page.locator("#content").innerText();
  assert.match(text, /今日用量/);
  assert.match(text, /服务健康/);
  assert.match(text, /知识库 Worker/);
  assert.equal(await page.locator(".tha-tab").count(), 5);
  assert.deepEqual(errors, []);
  // The code works once.
  const again = await page.evaluate(async (value) => (await fetch("/admin-api/app-auth/exchange", { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ code: value }) })).status, code);
  assert.equal(again, 401);
  await context.close();
});

test("after the APP is killed and reopened it is still signed in (no new link)", async () => {
  const { page, context } = await openPage(env, PHONE);
  await signIn(page);
  const state = await context.storageState();
  await context.close(); // the APP process is gone; only the credential cookie survives

  const reopened = await env.browser.newContext({ ...PHONE, storageState: state });
  const page2 = await reopened.newPage();
  await page2.goto(`${env.mock.baseUrl}/app/`);
  await page2.locator(".tha-tabbar").waitFor({ state: "visible" });
  assert.equal(await page2.locator(".tha-welcome").count(), 0);
  await reopened.close();
});

test("an expired access token is renewed silently and the request replayed", async () => {
  const { page, errors, context } = await openPage(env, PHONE);
  await signIn(page);
  env.mock.expireAppAccessTokens();
  await page.goto(`${env.mock.baseUrl}/app/#/me`);
  await page.locator(".tha-me").waitFor();
  await settle(page);
  assert.equal(await page.locator(".tha-welcome").count(), 0);
  assert.match(await page.locator(".tha-me").innerText(), /已登录的设备/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("console pages open inside the APP with their domain chips", async () => {
  const { page, errors, context } = await openPage(env, PHONE);
  await signIn(page);
  await page.goto(`${env.mock.baseUrl}/app/#/manage`);
  await page.locator(".tha-menu-item").first().waitFor();
  const links = await page.locator(".tha-menu-item").evaluateAll((nodes) => nodes.map((node) => node.getAttribute("href")));
  assert.ok(links.length >= 20, `expected the console pages, got ${links.length}`);
  const failures = [];
  for (const href of links.filter((item) => /api-keys|alert-events|departments|usage-records|providers|operations-status/.test(item))) {
    errors.length = 0;
    await page.goto(`${env.mock.baseUrl}/app/${href}`);
    await page.locator(".tha-page").waitFor();
    await settle(page, { timeout: 1500 }); // the live stream never lets the network go idle
    const text = (await page.locator(".tha-page").innerText()).trim();
    if (!text) failures.push(`${href}: empty`);
    if (await page.locator("[data-page-error]").count()) failures.push(`${href}: failed`);
    if (errors.length) failures.push(`${href}: ${errors.join(" | ")}`);
    const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (overflow > 1) failures.push(`${href}: page scrolls sideways by ${overflow}px`);
  }
  assert.deepEqual(failures, []);
  await context.close();
});

test("a sensitive operation asks for a PIN, then succeeds", async () => {
  const { page, context } = await openPage(env, PHONE);
  await signIn(page);
  const pending = page.evaluate(async () => {
    const { api } = await import("/admin/assets/js/api.js");
    const result = await api("/admin-api/api-keys/1", { method: "DELETE" });
    return result.data;
  });
  // First time: set a PIN (counts as verification).
  await page.locator(".tha-pin-form").waitFor();
  const inputs = page.locator(".tha-pin-input");
  assert.equal(await inputs.count(), 2);
  await inputs.nth(0).fill("135790");
  await inputs.nth(1).fill("135790");
  await page.locator(".tha-pin-form button[type=submit]").click();
  assert.deepEqual(await pending, { deleted: true });
  await context.close();
});

test("signing out ends the device; reopening shows the sign-in page", async () => {
  const { page, context } = await openPage(env, PHONE);
  await signIn(page);
  await page.goto(`${env.mock.baseUrl}/app/#/me`);
  await page.locator(".tha-me").waitFor();
  await page.getByRole("button", { name: "退出登录" }).click();
  await page.locator(".confirm-dialog").waitFor();
  await page.locator("dialog[open] button.danger").click();
  await page.locator(".tha-welcome").waitFor();
  await page.goto(`${env.mock.baseUrl}/app/`);
  await page.locator(".tha-welcome").waitFor();
  await context.close();
});

test("desktop width shows a left rail instead of the bottom bar", async () => {
  const { page, context } = await openPage(env, { viewport: { width: 1280, height: 800 } });
  await signIn(page);
  const box = await page.locator(".tha-tabbar").boundingBox();
  assert.ok(box && box.x === 0 && box.width < 120 && box.height > 300, `rail expected, got ${JSON.stringify(box)}`);
  await context.close();
});

test("the service worker never caches API responses", async () => {
  const { page, context } = await openPage(env, PHONE);
  await signIn(page);
  await page.evaluate(() => navigator.serviceWorker?.ready);
  await page.reload();
  await page.locator(".tha-tabbar").waitFor({ state: "visible" });
  await settle(page);
  const cached = await page.evaluate(async () => {
    const urls = [];
    for (const key of await caches.keys()) {
      const cache = await caches.open(key);
      urls.push(...(await cache.keys()).map((request) => new URL(request.url).pathname));
    }
    return urls;
  });
  assert.ok(cached.some((path) => path.startsWith("/app/")), "the shell should be cached for offline start");
  assert.deepEqual(cached.filter((path) => path.startsWith("/admin-api/")), []);
  await context.close();
});

test("first service worker claim preserves input and a later controller change reloads once", async () => {
  const { page, context } = await openPage(env, PHONE);
  await page.addInitScript(() => {
    const boots = Number(sessionStorage.getItem("service-worker-test-boots") || 0) + 1;
    sessionStorage.setItem("service-worker-test-boots", String(boots));
  });
  await page.goto(`${env.mock.baseUrl}/app/`);
  await page.locator(".tha-welcome").waitFor();
  const input = page.locator(".tha-link-input");
  await input.fill("keep this pending sign-in input");
  await page.evaluate(async () => {
    await navigator.serviceWorker.ready;
    if (!navigator.serviceWorker.controller) {
      await new Promise(resolve => navigator.serviceWorker.addEventListener("controllerchange", resolve, { once: true }));
    }
  });
  assert.equal(await page.evaluate(() => sessionStorage.getItem("service-worker-test-boots")), "1", "first activation must not reload the document");
  assert.equal(await input.inputValue(), "keep this pending sign-in input");
  await Promise.all([
    page.waitForEvent("framenavigated", { predicate: frame => frame === page.mainFrame() }),
    page.evaluate(() => navigator.serviceWorker.dispatchEvent(new Event("controllerchange")))
  ]);
  await page.locator(".tha-welcome").waitFor();
  assert.equal(await page.evaluate(() => sessionStorage.getItem("service-worker-test-boots")), "2", "a subsequent controller replacement reloads exactly once");
  await context.close();
});

test("on phones console tables become labelled cards", async () => {
  const { page, errors, context } = await openPage(env, PHONE);
  await signIn(page);
  await page.goto(`${env.mock.baseUrl}/app/#/access/api-keys`);
  await page.locator("table.tha-cards tbody tr").first().waitFor();
  await settle(page);
  const card = await page.evaluate(() => {
    const row = document.querySelector("table.tha-cards tbody tr");
    const cells = [...row.querySelectorAll("td")];
    const box = row.getBoundingClientRect();
    return { labels: cells.map((cell) => cell.getAttribute("data-label")), rowWidth: box.width, display: getComputedStyle(row).display, viewport: window.innerWidth };
  });
  assert.equal(card.display, "block");
  assert.ok(card.labels.includes("名称"), `labels: ${card.labels.join(",")}`);
  assert.ok(card.rowWidth <= card.viewport && card.rowWidth > card.viewport * 0.8, `card width ${card.rowWidth}`);
  assert.deepEqual(errors, []);
  await context.close();
});

test("the console lets a super admin open another administrator's APP devices", async () => {
  const { page, errors, context } = await openPage(env, { viewport: { width: 1440, height: 900 } });
  await page.goto(`${env.mock.baseUrl}/admin/`);
  await page.locator("#username").fill("admin");
  await page.locator("#password").fill("admin-pass");
  await page.locator("#login-button").click();
  await page.locator("#content").waitFor({ state: "visible" });
  await page.goto(`${env.mock.baseUrl}/admin/#/organization/admin-users`);
  await settle(page);
  await page.locator("#content .row-more-toggle").first().click();
  await page.locator("#content [role=menuitem]").filter({ hasText: "APP 设备" }).first().click();
  await page.locator(".app-devices-intro").waitFor();
  assert.match(await page.locator(".app-devices-intro").innerText(), /为管理员/);
  assert.deepEqual(errors.filter((line) => !/401|404/.test(line)), []);
  await context.close();
});

test("live updates: the overview fills from the stream and a new alert shows at once", async () => {
  const { page, errors, context } = await openPage(env, PHONE);
  await signIn(page);
  await page.locator("#tha-live:not([hidden])").waitFor({ timeout: 5000 });
  await page.waitForFunction(() => document.querySelector(".tha-kpis")?.textContent.includes("4,321"), null, { timeout: 5000 });
  assert.ok(env.mock.liveStreamCount() >= 1, "the APP keeps a live stream open");
  env.mock.pushLiveAlert({ severity: "critical", message: "上游厂商连续超时" });
  await page.locator(".toast", { hasText: "新告警" }).first().waitFor({ timeout: 3000 });
  await page.waitForFunction(() => document.querySelector(".tha-tab-badge:not([hidden])")?.textContent === "1", null, { timeout: 3000 });
  assert.match(await page.locator(".tha-alert-summary").innerText(), /1 条待处理/);
  // In the background the stream is closed (Web Push takes over).
  await page.evaluate(() => {
    Object.defineProperty(document, "visibilityState", { configurable: true, get: () => "hidden" });
    document.dispatchEvent(new Event("visibilitychange"));
  });
  await page.waitForFunction(() => document.getElementById("tha-live").hidden, null, { timeout: 3000 });
  assert.deepEqual(errors, []);
  await context.close();
});

test("new-device approval: the APP waits with a match code until the web console confirms it", async () => {
  env.mock.setAppApproval(true);
  const consolePage = await openPage(env, { viewport: { width: 1440, height: 900 } });
  await consolePage.page.goto(`${env.mock.baseUrl}/admin/`);
  await consolePage.page.locator("#username").fill("admin");
  await consolePage.page.locator("#password").fill("admin-pass");
  await consolePage.page.locator("#login-button").click();
  await consolePage.page.locator("#content").waitFor({ state: "visible" });

  const { page, errors, context } = await openPage(env, PHONE);
  const code = env.mock.issueLinkCode();
  await page.goto(`${env.mock.baseUrl}/app/link#c=${code}`);
  await page.locator(".tha-pending").waitFor();
  const matchCode = (await page.locator(".tha-match-code").innerText()).trim();
  assert.match(matchCode, /^\d{4}$/);
  assert.equal(await page.locator(".tha-tabbar").isHidden(), true, "no access before approval");

  // A restart while waiting keeps waiting (the credential stays).
  await page.reload();
  await page.locator(".tha-pending").waitFor();

  await consolePage.page.locator("#app-devices").click();
  await consolePage.page.locator(".app-devices-item.is-pending").waitFor();
  assert.equal((await consolePage.page.locator(".app-devices-match-code").innerText()).trim(), matchCode, "the console shows the same match code");
  await consolePage.page.locator(".app-devices-item.is-pending button").filter({ hasText: "确认" }).click();
  await consolePage.page.locator(".modal-dialog--confirm button").filter({ hasText: "确认登录" }).click();

  await page.locator(".tha-tabbar").waitFor({ state: "visible", timeout: 10000 });
  assert.equal(await page.locator(".tha-pending").count(), 0);
  assert.deepEqual(errors.filter((line) => !/409/.test(line)), []);
  await context.close();
  await consolePage.context.close();
});

test("new-device approval: a rejected device returns to the sign-in page with a reason", async () => {
  env.mock.setAppApproval(true);
  const { page, context } = await openPage(env, PHONE);
  const code = env.mock.issueLinkCode();
  await page.goto(`${env.mock.baseUrl}/app/link#c=${code}`);
  await page.locator(".tha-pending").waitFor();
  const device = [...env.mock.appState.devices.values()].find((item) => item.status === "pending");
  device.status = "rejected";
  await page.locator(".tha-welcome .tha-error").filter({ hasText: "被拒绝" }).waitFor({ timeout: 10000 });
  await context.close();
});

test("a recovered sign-in (lost refresh response) is announced once", async () => {
  const { page, errors, context } = await openPage(env, PHONE);
  await signIn(page);
  // The rotation response was lost: the browser still holds the previous credential.
  const device = [...env.mock.appState.devices.values()].find((item) => item.status === "active");
  device.prev = device.refresh;
  device.refresh = "thr_lost_rotation";
  env.mock.appState.recoverNext = true;
  await page.reload();
  await page.locator(".tha-tabbar").waitFor({ state: "visible" });
  await page.getByText("登录状态已自动恢复", { exact: false }).first().waitFor();
  // The next ordinary refresh is not announced again.
  await page.reload();
  await page.locator(".tha-tabbar").waitFor({ state: "visible" });
  await settle(page);
  assert.equal(await page.getByText("登录状态已自动恢复", { exact: false }).count(), 0);
  assert.deepEqual(errors, []);
  await context.close();
});

test("without an in-page QR scanner the sign-in page says to use the system camera or paste the link", async () => {
  const { page, errors, context } = await openPage(env, PHONE);
  await page.addInitScript(() => { delete window.BarcodeDetector; });
  await page.goto(`${env.mock.baseUrl}/app/`);
  await page.locator(".tha-welcome").waitFor();
  const note = page.locator(".tha-scan-fallback");
  await note.waitFor();
  assert.match(await note.innerText(), /系统相机/);
  assert.match(await note.innerText(), /粘贴/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("inside the Capacitor shell, links that leave the server open in the system browser", async () => {
  const { page, errors, context } = await openPage(env, PHONE);
  await page.addInitScript(() => {
    window.__opened = [];
    window.Capacitor = {
      isNativePlatform: () => true,
      getPlatform: () => "ios",
      Plugins: { Browser: { open: async ({ url }) => { window.__opened.push(url); } } }
    };
  });
  await page.goto(`${env.mock.baseUrl}/app/`);
  await page.locator(".tha-welcome").waitFor();
  await page.evaluate(() => {
    for (const [id, href, target] of [["ext", "https://docs.example.com/guide", ""], ["blank", "/admin/", "_blank"], ["inside", "#/ca", ""]]) {
      const a = document.createElement("a");
      a.id = id; a.href = href; a.textContent = id;
      if (target) a.target = target;
      document.body.append(a);
    }
  });
  await page.locator("#ext").click();
  await page.locator("#blank").click();
  await page.evaluate(() => window.open("https://other.example.com/x"));
  const opened = await page.evaluate(() => window.__opened);
  assert.deepEqual(opened, ["https://docs.example.com/guide", `${env.mock.baseUrl}/admin/`, "https://other.example.com/x"].map(String));
  assert.equal(new URL(page.url()).origin, env.mock.baseUrl, "the shell stays on the TokenHub server");
  await page.locator("#inside").click();
  assert.equal((await page.evaluate(() => window.__opened)).length, 3, "in-app links are not sent to the browser");
  assert.deepEqual(errors, []);
  await context.close();
});
