import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

// List requests only: the status counts next to the filters ask for one row.
function listRequests(env, resource) {
  return env.mock.requestLog.filter((entry) => entry.method === "GET" && entry.path === `/admin-api/${resource}` && !/[?&]limit=1(&|$)/.test(entry.query));
}

test("clicking a menu item renders the page once (one list request)", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl);
  env.mock.requestLog.length = 0;
  await page.locator("#nav").getByText("模型服务").click();
  await page.locator("#nav a.nav-button", { hasText: "厂商管理" }).first().click().catch(async () => {
    await page.locator("#nav a.nav-button").filter({ hasText: /厂商/ }).first().click();
  });
  await settle(page);
  assert.equal(listRequests(env, "providers").length, 1, JSON.stringify(env.mock.requestLog));
  assert.match(page.url(), /#\/models\/providers$/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("dashboard drill-down keeps its filter in the URL and in the request", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/dashboard" });
  env.mock.requestLog.length = 0;
  await page.locator(".chart-panel", { hasText: "员工用量排行" }).getByRole("button", { name: /用量明细/ }).first().click();
  await page.waitForURL(/#\/usage\/usage-records\?user_id=1/);
  await settle(page);
  const requests = listRequests(env, "usage-records");
  assert.ok(requests.length >= 1, "usage records should be requested");
  assert.ok(requests.every((entry) => entry.query.includes("user_id=1")), `filter lost: ${JSON.stringify(requests)}`);
  await page.reload();
  await settle(page);
  assert.match(page.url(), /user_id=1/);
  assert.ok(listRequests(env, "usage-records").at(-1).query.includes("user_id=1"));
  assert.deepEqual(errors, []);
  await context.close();
});

test("unknown or forbidden routes fall back to a permitted page", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { username: "rag", password: "rag-pass", hash: "#/waf/waf-rules" });
  assert.doesNotMatch(page.url(), /waf/);
  await context.close();
});

test("browser back returns to the previous page", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/quota-policies" });
  await page.goto(`${env.mock.baseUrl}/admin/#/access/api-key-model-quotas`);
  await settle(page);
  await page.goBack();
  await settle(page);
  assert.match(page.url(), /quota-policies/);
  assert.equal(await page.locator(".domain-tab.active").innerText(), await page.locator(".domain-tab", { hasText: /配额策略|策略/ }).first().innerText());
  assert.deepEqual(errors, []);
  await context.close();
});

test("domain tabs expose tab semantics", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/quota-policies" });
  const selected = page.locator('.domain-tabs [role="tab"][aria-selected="true"]');
  assert.equal(await selected.count(), 1);
  await context.close();
});

test("menu keeps keyboard focus when navigating and toggling sections", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/dashboard" });
  const link = page.locator("#nav a.nav-button", { hasText: "会话测试" });
  await link.focus();
  await page.keyboard.press("Enter");
  await page.waitForURL(/admin-chat-sessions/);
  await settle(page);
  assert.equal(await link.evaluate((node) => node === document.activeElement), true, "focused menu link should survive navigation");
  assert.equal(await link.getAttribute("aria-current"), "page");
  const toggle = page.locator('#nav [data-nav-section="models"] .nav-section-toggle');
  await toggle.focus();
  await page.keyboard.press("Enter");
  assert.equal(await toggle.evaluate((node) => node === document.activeElement), true, "section toggle should keep focus");
  assert.equal(await toggle.getAttribute("aria-expanded"), "true");
  assert.deepEqual(errors, []);
  await context.close();
});
