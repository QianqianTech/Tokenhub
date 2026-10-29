import { after, afterEach, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });
afterEach(() => env.mock.clearFaults());

const panel = (page, title) => page.locator(".dashboard-panel", { has: page.locator("h2", { hasText: title }) });

test("a failing dashboard endpoint only breaks its own panel and can be retried", async () => {
  env.mock.fault("/admin-api/dashboard/security", { method: "GET", status: 500, times: 1 });
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/dashboard" });
  await panel(page, "部门用量排行").locator(".ranking-chart").waitFor();
  const security = panel(page, "安全看板");
  await security.locator(".state--error").waitFor();
  assert.match(await page.locator(".dashboard-notice").innerText(), /部分面板加载失败/);
  await security.getByRole("button", { name: "重试" }).click();
  await security.locator(".security-chart-card").waitFor();
  assert.deepEqual(errors, []);
  await context.close();
});

test("overlapping dashboard loads never duplicate panels", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/dashboard" });
  await panel(page, "趋势").waitFor();
  env.mock.fault("/admin-api/dashboard/trends", { method: "GET", delayMs: 700, times: 1 });
  await page.getByRole("button", { name: "刷新" }).click();
  await page.locator('select[name="sort"]').selectOption("total_tokens");
  await page.waitForTimeout(900);
  await settle(page);
  assert.equal(await panel(page, "趋势").count(), 1);
  assert.equal(await page.locator(".dashboard-screen-grid .metric-grid").count(), 1);
  assert.deepEqual(errors, []);
  await context.close();
});

test("trend chart reads oldest to newest and supports keyboard inspection", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/dashboard" });
  const trend = panel(page, "趋势");
  await trend.locator("svg.trend-line-chart-svg").waitFor();
  const buckets = await trend.locator(".chart-data-table tbody tr td:first-child").allTextContents();
  assert.ok(buckets.length > 1);
  assert.deepEqual(buckets, [...buckets].sort(), "trend rows should be in ascending time order");
  await trend.locator("svg.trend-line-chart-svg").focus();
  await page.keyboard.press("ArrowRight");
  const tooltip = trend.locator(".chart-tooltip");
  await tooltip.waitFor({ state: "visible" });
  assert.match(await tooltip.innerText(), /请求量/);
  const toggle = trend.getByRole("button", { name: "失败请求" });
  await toggle.click();
  assert.equal(await toggle.getAttribute("aria-pressed"), "false");
  assert.equal(await trend.locator(".trend-line-series-failed").count(), 0);
  const download = page.waitForEvent("download");
  await trend.getByRole("button", { name: "导出 CSV" }).click();
  assert.match((await download).suggestedFilename(), /dashboard-trends-day\.csv/);
  assert.deepEqual(errors, []);
  await context.close();
});
