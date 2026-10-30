import { after, afterEach, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });
afterEach(() => env.mock.clearFaults());

const lastPost = (path) => {
  const entry = env.mock.requestLog.filter((item) => item.method === "POST" && item.path === path).at(-1);
  return entry && (typeof entry.body === "string" ? JSON.parse(entry.body) : entry.body);
};

test("screen share form picks scope objects and metrics instead of JSON", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/dashboard-share-links" });
  await page.getByRole("button", { name: /^新建/ }).click();
  const dialog = page.locator("dialog.modal-dialog");
  await dialog.waitFor();
  assert.equal(await dialog.locator('textarea[name="scope_ids"], textarea[name="allowed_metrics"]').count(), 0, "no JSON textareas");
  await dialog.locator('[name="name"]').fill("区委大屏");
  // Global scope hides the object picker.
  await dialog.locator('select[name="scope_type"]').selectOption("global");
  assert.equal(await dialog.locator('[data-field="scope_ids"]').isVisible(), false);
  // Department scope shows a department picker.
  await dialog.locator('select[name="scope_type"]').selectOption("department");
  const picker = dialog.locator('[data-remote-select="scope_ids"] input[role="combobox"]');
  await picker.click();
  await dialog.locator('[data-remote-select="scope_ids"] [role="option"]').first().waitFor();
  await picker.press("ArrowDown");
  await picker.press("Enter");
  await picker.press("Escape");
  await dialog.locator('input[name="allowed_metrics"][value="overview"]').check();
  await dialog.locator('input[name="allowed_metrics"][value="trends"]').check();
  await dialog.getByRole("button", { name: "保存" }).click();
  await settle(page);
  const body = lastPost("/admin-api/dashboard-share-links");
  assert.equal(body.scope_type, "department");
  assert.ok(Array.isArray(body.scope_ids) && body.scope_ids.length === 1 && Number.isFinite(body.scope_ids[0]), JSON.stringify(body));
  assert.deepEqual(body.allowed_metrics, ["overview", "trends"]);
  assert.deepEqual(errors, []);
  await context.close();
});

test("models can be filtered by name, provider and capability", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/models/models" });
  assert.equal(await page.locator('form.filters [data-field="provider_id"] .remote-select-input').count(), 1);
  await page.locator('form.filters [name="keyword"]').fill("model-1");
  env.mock.requestLog.length = 0;
  // Filters apply on change: the capability is a segmented control.
  await page.locator('form.filters [data-field="capability"]').getByRole("button", { name: /向量检索/ }).click();
  await settle(page);
  const request = env.mock.requestLog.filter((entry) => entry.method === "GET" && entry.path === "/admin-api/models" && /[?&]limit=20(&|$)/.test(entry.query)).at(-1);
  assert.ok(request.query.includes("capability=embedding"), request.query);
  assert.ok(request.query.includes("keyword=model-1"), request.query);
  assert.deepEqual(errors, []);
  await context.close();
});

test("rows with many actions keep the extras in a 更多 menu", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/organization/users" });
  const row = page.locator("tbody tr").nth(1);
  assert.equal(await row.getByRole("button", { name: "重置密码" }).isVisible(), false);
  await row.getByRole("button", { name: "更多" }).click();
  await row.getByRole("menuitem", { name: "重置密码" }).waitFor();
  await page.keyboard.press("Escape");
  assert.equal(await row.getByRole("menuitem", { name: "重置密码" }).isVisible(), false);
  assert.deepEqual(errors, []);
  await context.close();
});

test("page title shows the current page with its module as breadcrumb", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/api-keys" });
  assert.equal(await page.locator("#page-title").innerText(), "API 密钥");
  assert.equal(await page.locator("#page-crumb").innerText(), "API 接入");
  await context.close();
});
