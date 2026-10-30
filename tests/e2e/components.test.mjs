import { after, afterEach, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });
afterEach(() => env.mock.clearFaults());

const requests = (method, path) => env.mock.requestLog.filter((entry) => entry.method === method && entry.path === path);

test("generic lists paginate with totals, page size and sort kept in the URL", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/security/logs" });
  const summary = page.locator(".pager-summary");
  assert.match(await summary.innerText(), /第 1–20 条，共 137 条/);
  env.mock.requestLog.length = 0;
  await page.locator(".pager").getByRole("button", { name: "下一页" }).click();
  await settle(page);
  assert.match(await summary.innerText(), /第 21–40 条，共 137 条/);
  assert.equal(await page.locator(".pager .pager-page.is-current").innerText(), "2");
  assert.ok(requests("GET", "/admin-api/logs").at(-1).query.includes("offset=20"));
  assert.match(page.url(), /offset=20/);
  await page.locator(".pager select").selectOption("50");
  await settle(page);
  assert.match(await summary.innerText(), /第 1–50 条，共 137 条/);
  await page.reload();
  await settle(page);
  assert.match(await page.locator(".pager-summary").innerText(), /第 1–50 条/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("sortable column headers request server-side sorting", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/usage/usage-records" });
  env.mock.requestLog.length = 0;
  await page.locator("th.table-cell--created_at .sort-button").click();
  await settle(page);
  assert.ok(requests("GET", "/admin-api/usage-records").at(-1).query.includes("sort=created_at_desc"));
  await page.locator("th.table-cell--created_at .sort-button").click();
  await settle(page);
  assert.ok(requests("GET", "/admin-api/usage-records").at(-1).query.includes("sort=created_at_asc"));
  assert.equal(await page.locator("th.table-cell--created_at").getAttribute("aria-sort"), "ascending");
  await context.close();
});

test("a failed list shows an error state with request id and a working retry", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl);
  env.mock.fault("/admin-api/quota-policies", { method: "GET", status: 500, times: 1 });
  await page.goto(`${env.mock.baseUrl}/admin/#/access/quota-policies`);
  await settle(page);
  const state = page.locator(".state--error");
  assert.match(await state.innerText(), /服务内部异常/);
  assert.match(await state.innerText(), /请求编号/);
  assert.equal(await page.locator("#content table").count(), 0, "an error must not be rendered as a data row");
  await state.getByRole("button", { name: "重试" }).click();
  await settle(page);
  assert.ok(await page.locator("#content table").count() > 0);
  await context.close();
});

test("a slow save cannot be submitted twice", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/quota-policies" });
  env.mock.fault("/admin-api/quota-policies", { method: "POST", delayMs: 600, times: 1 });
  await page.getByRole("button", { name: /^新建/ }).click();
  const dialog = page.locator("dialog.modal-dialog");
  await dialog.locator('[name="name"]').fill("防重复提交");
  const save = dialog.getByRole("button", { name: "保存" });
  await save.click();
  await save.click({ force: true }).catch(() => {});
  await save.click({ force: true }).catch(() => {});
  await page.locator("#content table", { hasText: "防重复提交" }).waitFor({ timeout: 5000 });
  assert.equal(requests("POST", "/admin-api/quota-policies").filter((entry) => entry.body?.name === "防重复提交").length, 1);
  assert.equal(await page.locator("dialog.modal-dialog").count(), 0, "dialog closes after a successful save");
  assert.match(await page.locator(".toast").first().innerText(), /已新增/);
  await context.close();
});

test("required fields are validated before submitting", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/quota-policies" });
  const before = requests("POST", "/admin-api/quota-policies").length;
  await page.getByRole("button", { name: /^新建/ }).click();
  const dialog = page.locator("dialog.modal-dialog");
  await dialog.locator('[name="name"]').press("Enter");
  await page.waitForTimeout(200);
  assert.equal(requests("POST", "/admin-api/quota-policies").length, before);
  assert.match(await dialog.locator(".field-error:not([hidden])").first().innerText(), /请填写名称/);
  assert.equal(await dialog.locator('[name="name"]').getAttribute("aria-invalid"), "true");
  await context.close();
});

test("dialogs close with Escape, guard unsaved edits and restore focus", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/quota-policies" });
  const opener = page.getByRole("button", { name: /^新建/ });
  await opener.click();
  const dialog = page.locator("dialog.modal-dialog").first();
  await dialog.waitFor();
  await page.keyboard.press("Escape");
  await page.waitForTimeout(100);
  assert.equal(await page.locator("dialog.modal-dialog").count(), 0);
  assert.match(await page.evaluate(() => document.activeElement?.textContent || ""), /^新建/);

  await opener.click();
  await page.locator('dialog.modal-dialog [name="name"]').fill("未保存");
  await page.keyboard.press("Escape");
  const confirm = page.locator("dialog.modal-dialog--confirm");
  await confirm.waitFor();
  assert.match(await confirm.innerText(), /放弃未保存的修改/);
  await confirm.getByRole("button", { name: "继续编辑" }).click();
  assert.equal(await page.locator('dialog.modal-dialog [name="name"]').inputValue(), "未保存");
  await page.keyboard.press("Escape");
  await page.locator("dialog.modal-dialog--confirm").getByRole("button", { name: "放弃修改" }).click();
  await page.waitForTimeout(100);
  assert.equal(await page.locator("dialog.modal-dialog").count(), 0);
  await context.close();
});

test("reference pickers search beyond the first 100 records", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/usage/usage-records" });
  const picker = page.locator('[data-remote-select="user_id"] input[role="combobox"]');
  await picker.click();
  await picker.fill("user140");
  const option = page.locator('[data-remote-select="user_id"] [role="option"]', { hasText: "user140" });
  await option.waitFor();
  env.mock.requestLog.length = 0;
  // Filters apply as soon as a value is picked.
  await option.click();
  await settle(page);
  assert.ok(requests("GET", "/admin-api/usage-records").at(-1).query.includes("user_id=140"));
  assert.match(page.url(), /user_id=140/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("reference pickers support keyboard selection in forms", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/api-key-model-quotas" });
  await page.getByRole("button", { name: /^新建/ }).click();
  const dialog = page.locator("dialog.modal-dialog");
  const apiKey = dialog.locator('[data-remote-select="api_key_id"] input[role="combobox"]');
  await apiKey.click();
  await apiKey.fill("密钥3");
  await dialog.locator('[role="option"]', { hasText: "密钥3" }).waitFor();
  await apiKey.press("ArrowDown");
  await apiKey.press("Enter");
  const model = dialog.locator('[data-remote-select="model_id"] input[role="combobox"]');
  await model.click();
  await dialog.locator('[data-remote-select="model_id"] [role="option"]').first().waitFor();
  await model.press("ArrowDown");
  await model.press("Enter");
  await dialog.getByRole("button", { name: "保存" }).click();
  await page.locator("dialog.modal-dialog").waitFor({ state: "detached" });
  const body = requests("POST", "/admin-api/api-key-model-quotas").at(-1).body;
  assert.equal(body.api_key_id, 3);
  assert.ok(Number.isFinite(body.model_id) && body.model_id > 0);
  await context.close();
});

test("irreversible deletes require typing the record name", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/models/providers" });
  const row = page.locator("tbody tr", { hasText: "厂商2" });
  // Provider rows carry more than three actions (拉取模型 / 目录同步 / 编辑 / 删除),
  // so the destructive one sits in the “更多” menu.
  const more = row.getByRole("button", { name: "更多" });
  if (await more.count()) {
    await more.click();
    await row.getByRole("menuitem", { name: "删除" }).click();
  } else {
    await row.getByRole("button", { name: "删除" }).click();
  }
  const confirm = page.locator("dialog.modal-dialog--confirm");
  const confirmButton = confirm.getByRole("button", { name: "删除" });
  assert.equal(await confirmButton.isDisabled(), true);
  await confirm.locator("input").fill("厂商2");
  assert.equal(await confirmButton.isDisabled(), false);
  await confirmButton.click();
  await settle(page);
  assert.equal(requests("DELETE", "/admin-api/providers/2").length, 1);
  await context.close();
});

test("column visibility is remembered", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/quota-policies" });
  await page.getByRole("button", { name: "显示列" }).click();
  const panel = page.locator(".column-menu-panel");
  await panel.locator("label", { hasText: "周期" }).locator("input").uncheck();
  assert.equal(await page.locator("th.table-cell--period").count(), 0);
  await page.reload();
  await settle(page);
  assert.equal(await page.locator("th.table-cell--period").count(), 0);
  await page.getByRole("button", { name: "显示列" }).click();
  await page.locator(".column-menu-panel label", { hasText: "周期" }).locator("input").check();
  assert.equal(await page.locator("th.table-cell--period").count(), 1);
  await context.close();
});
