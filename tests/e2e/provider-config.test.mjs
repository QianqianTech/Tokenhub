import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

const tokenPlanUrl = "https://token-plan.maas.qianwenaiapi.com/compatible-mode/v1";
const providerRequests = (method, id = "") => env.mock.requestLog.filter((entry) => entry.method === method && entry.path === `/admin-api/providers${id ? `/${id}` : ""}`);
const providerRow = (page, name) => page.locator("tbody tr", { has: page.locator("td.table-cell--name", { hasText: name }) }).first();

test("existing provider Key has a direct edit action and only the new secret is sent", async () => {
  const { page, context, errors } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/models/providers" });
  const row = providerRow(page, "厂商1");
  assert.equal(await row.locator(".table-cell--secret_status").innerText(), "已配置");
  await row.getByRole("button", { name: "修改 Key", exact: true }).click();
  const dialog = page.locator("dialog.modal-dialog");
  const key = dialog.getByLabel("新 API Key", { exact: true });
  assert.equal(await key.inputValue(), "");
  const before = providerRequests("PUT", 1).length;
  await dialog.getByRole("button", { name: "保存 Key", exact: true }).click();
  assert.equal(providerRequests("PUT", 1).length, before, "empty Key is rejected before calling the API");
  assert.equal(await key.getAttribute("aria-invalid"), "true");
  await key.fill("sk-synthetic-replacement");
  await dialog.getByRole("button", { name: "显示 API Key", exact: true }).click();
  assert.equal(await key.getAttribute("type"), "text");
  await dialog.getByRole("button", { name: "隐藏 API Key", exact: true }).click();
  assert.equal(await key.getAttribute("type"), "password");
  await key.press("Enter");
  await dialog.waitFor({ state: "detached" });
  await settle(page);
  assert.deepEqual(providerRequests("PUT", 1).at(-1).body, { secret: "sk-synthetic-replacement" });
  assert.doesNotMatch(await page.locator("#content").innerText(), /sk-synthetic/);
  const saved = await page.evaluate(() => fetch("/admin-api/providers?ids=1").then((response) => response.json()));
  assert.equal(saved.data[0].base_url, "https://api.example.com/v1");
  assert.equal(saved.data[0].secret_status, "configured");
  assert.equal("secret" in saved.data[0], false);
  assert.deepEqual(errors, []);
  await context.close();
});

test("full provider editing keeps an existing Key when left blank and can replace it", async () => {
  const { page, context, errors } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/models/providers" });
  await providerRow(page, "厂商2").getByRole("button", { name: "编辑", exact: true }).click();
  let dialog = page.locator("dialog.modal-dialog");
  assert.equal(await dialog.locator(".provider-preset-picker").getAttribute("open"), null);
  assert.equal(await dialog.locator(".provider-form-advanced").getAttribute("open"), null);
  assert.equal(await dialog.locator('[name="secret"]').inputValue(), "");
  assert.match(await dialog.locator('[name="secret"]').getAttribute("placeholder"), /留空保留/);
  await dialog.locator('[name="name"]').fill("厂商2更新");
  await dialog.getByRole("button", { name: "保存", exact: true }).click();
  await dialog.waitFor({ state: "detached" });
  await settle(page);
  const update = providerRequests("PUT", 2).at(-1).body;
  assert.equal("secret" in update, false);
  assert.equal(update.base_url, "https://api.example.com/v1");
  assert.equal(update.timeout_ms, 30000);
  assert.equal(update.retry_count, 1);
  await providerRow(page, "厂商2更新").getByRole("button", { name: "编辑", exact: true }).click();
  dialog = page.locator("dialog.modal-dialog");
  await dialog.locator('[name="secret"]').fill("sk-synthetic-full-edit");
  await dialog.getByRole("button", { name: "保存", exact: true }).click();
  await dialog.waitFor({ state: "detached" });
  await settle(page);
  assert.equal(providerRequests("PUT", 2).at(-1).body.secret, "sk-synthetic-full-edit");
  assert.deepEqual(errors, []);
  await context.close();
});

test("Qwen presets separate Token Plan and pay-as-you-go and use the new URL", async () => {
  const { page, context, errors } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/models/providers" });
  await page.getByRole("button", { name: /^新建/ }).click();
  const dialog = page.locator("dialog.modal-dialog");
  const tokenPlanPreset = dialog.getByRole("button", { name: "千问 Token Plan", exact: true });
  await tokenPlanPreset.click();
  assert.equal(await tokenPlanPreset.getAttribute("aria-pressed"), "true");
  const selectedColor = await tokenPlanPreset.evaluate((node) => getComputedStyle(node).backgroundColor);
  const otherColor = await dialog.getByRole("button", { name: "DeepSeek", exact: true }).evaluate((node) => getComputedStyle(node).backgroundColor);
  assert.notEqual(selectedColor, otherColor, "the selected provider preset must be visually distinct");
  assert.equal(await dialog.locator('[name="base_url"]').inputValue(), tokenPlanUrl);
  assert.equal(await dialog.locator('[name="protocol"]').inputValue(), "openai");
  assert.match(await dialog.locator(".provider-endpoint-guide").innerText(), /sk-sp-/);
  await dialog.locator('[name="protocol"]').selectOption("anthropic");
  assert.equal(await dialog.locator('[name="base_url"]').inputValue(), "https://token-plan.maas.qianwenaiapi.com/apps/anthropic");
  await dialog.getByRole("button", { name: "千问（按量计费）", exact: true }).click();
  assert.equal(await dialog.locator('[name="base_url"]').inputValue(), "https://maas.qianwenaiapi.com/compatible-mode/v1");
  assert.match(await dialog.locator(".provider-endpoint-guide").innerText(), /按量计费/);
  await dialog.getByRole("button", { name: "千问 Token Plan", exact: true }).click();
  await dialog.locator('[name="secret"]').fill("sk-sp-synthetic-create");
  await dialog.getByRole("button", { name: "保存", exact: true }).click();
  await dialog.waitFor({ state: "detached" });
  await settle(page);
  const created = providerRequests("POST").at(-1).body;
  assert.equal(created.base_url, tokenPlanUrl);
  assert.equal(created.protocol, "openai");
  assert.equal(created.secret, "sk-sp-synthetic-create");
  assert.equal(await providerRow(page, "千问 Token Plan").locator(".table-cell--secret_status").innerText(), "已配置");
  assert.deepEqual(errors, []);
  await context.close();
});

test("provider name suggestions recognize 千问 and preserve a manually entered URL", async () => {
  const { page, context, errors } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/models/providers" });
  await page.getByRole("button", { name: /^新建/ }).click();
  const dialog = page.locator("dialog.modal-dialog");
  const name = dialog.locator('[name="name"]');
  const url = dialog.locator('[name="base_url"]');
  for (const label of ["DeepSeek", "千问", "Qwen", "通义千问"]) {
    await name.fill(label);
    assert.equal(await url.inputValue(), label === "DeepSeek" ? "https://api.deepseek.com" : tokenPlanUrl);
  }
  await url.fill("https://custom.example.com/compatible-mode/v1");
  await name.fill("千问自建代理");
  await dialog.locator('[name="protocol"]').selectOption("anthropic");
  assert.equal(await url.inputValue(), "https://custom.example.com/compatible-mode/v1");
  // An explicit preset is allowed to change the endpoint; old Bailian remains selectable.
  await dialog.getByText("更多厂商", { exact: true }).click();
  await dialog.getByRole("button", { name: "阿里云百炼兼容", exact: true }).click();
  assert.equal(await url.inputValue(), "https://dashscope.aliyuncs.com/compatible-mode/v1");
  assert.equal(await dialog.locator('[name="protocol"]').inputValue(), "openai");
  assert.deepEqual(errors, []);
  await context.close();
});

test("a Key save error keeps the form and allows retry; an empty provider can be configured", async () => {
  const { page, context, errors } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/models/providers" });
  const row = providerRow(page, "厂商6");
  assert.equal(await row.locator(".table-cell--secret_status").innerText(), "未配置");
  await row.getByRole("button", { name: "配置 Key", exact: true }).click();
  const dialog = page.locator("dialog.modal-dialog");
  const key = dialog.locator('[name="secret"]');
  await key.fill("sk-synthetic-retry");
  await page.route("**/admin-api/providers/6", async (route) => {
    if (route.request().method() !== "PUT") return route.continue();
    return route.fulfill({ status: 422, contentType: "application/json", body: JSON.stringify({ ok: false, error: { code: "ADMIN_VALIDATION_FAILED", message: "Key 保存失败，请重试" } }) });
  });
  await dialog.getByRole("button", { name: "保存 Key", exact: true }).click();
  await dialog.locator(".notice.error").waitFor();
  assert.equal(await key.inputValue(), "sk-synthetic-retry");
  assert.equal(await dialog.isVisible(), true);
  await page.unroute("**/admin-api/providers/6");
  await dialog.getByRole("button", { name: "保存 Key", exact: true }).click();
  await dialog.waitFor({ state: "detached" });
  await settle(page);
  assert.equal(await providerRow(page, "厂商6").locator(".table-cell--secret_status").innerText(), "已配置");
  assert.deepEqual(errors, []);
  await context.close();
});

test("provider configuration fits a narrow mobile dialog with advanced settings collapsed", async () => {
  const { page, context, errors } = await openPage(env, { viewport: { width: 390, height: 844 } });
  await login(page, env.mock.baseUrl, { hash: "#/models/providers" });
  await page.getByRole("button", { name: /^新建/ }).click();
  const dialog = page.locator("dialog.modal-dialog");
  await dialog.getByRole("button", { name: "千问 Token Plan", exact: true }).click();
  const overflow = await dialog.evaluate((node) => node.scrollWidth > node.clientWidth);
  assert.equal(overflow, false, "configuration dialog must not scroll horizontally");
  await dialog.locator(".provider-form-advanced > summary").click();
  assert.equal(await dialog.locator('[name="timeout_ms"]').isVisible(), true);
  await dialog.locator('[name="timeout_ms"]').fill("60000");
  await dialog.locator('[name="retry_count"]').fill("2");
  await dialog.getByRole("button", { name: "保存", exact: true }).click();
  await dialog.waitFor({ state: "detached" });
  await settle(page);
  assert.equal(providerRequests("POST").at(-1).body.timeout_ms, 60000);
  assert.deepEqual(errors, []);
  await context.close();
});
