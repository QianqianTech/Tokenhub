import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup, visibleTabs } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

const shots = process.env.CONVERSATION_LOG_SHOTS || "";
const lastRequest = (method, path) => env.mock.requestLog.filter((entry) => entry.method === method && entry.path === path).at(-1);
const body = (entry) => (typeof entry.body === "string" ? JSON.parse(entry.body) : entry.body);

async function openKeyDialog(page, name) {
  const row = page.locator("tbody tr", { hasText: name });
  await row.getByRole("button", { name: "更多" }).click();
  await row.locator(".api-key-conversation-button").click();
  const dialog = page.locator(".api-key-conversation-dialog");
  await dialog.locator('input[name="enabled"]').waitFor();
  return dialog;
}

test("功能未启用时：会话记录页提示去启用，密钥开关被锁定", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/conversation-logs" });
  const banner = page.locator(".conversation-log-banner");
  await banner.waitFor();
  assert.match(await banner.innerText(), /会话记录功能未启用/);

  await page.goto(`${env.mock.baseUrl}/admin/#/access/api-keys`);
  await page.locator("tbody tr").first().waitFor();
  const dialog = await openKeyDialog(page, "密钥2");
  assert.match(await dialog.locator(".conversation-feature-gate").innerText(), /需要先在「系统设置 › 会话记录设置」中启用/);
  assert.equal(await dialog.locator('input[name="enabled"]').isDisabled(), true, "the key switch is locked while the feature is off");
  await dialog.getByRole("button", { name: "去启用" }).click();
  await page.waitForURL(/#\/system\/conversation-log-settings/);
  await page.locator(".conversation-settings-form").waitFor();
  assert.equal(await page.locator(".api-key-conversation-dialog").count(), 0, "the dialog closes when leaving");
  assert.deepEqual(errors, []);
  await context.close();
});

test("会话记录设置：启用功能、保存规则、手动清理（预览+执行）、清理记录、关闭时可清空", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/system/conversation-log-settings" });
  const form = page.locator(".conversation-settings-form");
  await form.waitFor();
  assert.equal(await form.locator('input[name="enabled"]').isChecked(), false, "the feature is off by default");
  await form.locator('input[name="enabled"]').check();
  await form.locator('input[name="retention_days"]').fill("14");
  await form.locator('input[name="max_records_per_key"]').fill("5000");
  await form.locator('input[name="cleanup_time"]').fill("02:30");
  await page.getByRole("button", { name: "保存设置" }).click();
  await page.getByRole("button", { name: "确认启用" }).click();
  await form.locator(".notice.success").waitFor();
  assert.deepEqual(body(lastRequest("PUT", "/admin-api/conversation-logs/settings")), {
    enabled: true, compress_daily: true, retention_days: 14, max_records_per_key: 5000, purge_deleted_keys: true, auto_cleanup: true, cleanup_time: "02:30", timezone: "Asia/Shanghai", storage_driver: "local"
  });
  const status = await page.locator(".conversation-status").innerText();
  assert.match(status, /已启用/);
  assert.match(status, /\/srv\/app\/storage\/conversation-logs/, "the settings page shows the directory");
  assert.match(status, /已压缩\s*178 KB/);
  assert.match(status, /独立数据库[\s\S]*SQLite 文件[\s\S]*index\.sqlite/, "the separate log database is shown");
  await page.getByRole("button", { name: "立即压缩" }).click();
  await page.getByText(/已压缩 3 个文件（891 KB → 128 KB）/).waitFor();
  await page.locator(".conversation-history-table tbody tr", { hasText: "压缩文件" }).waitFor();

  // Manual cleanup: preview, then delete with confirmation.
  const manual = page.locator(".conversation-manual");
  await manual.locator('select[name="mode"]').selectOption("before");
  await manual.locator('input[name="before"]').fill("2026-10-01");
  await manual.getByRole("button", { name: "预览" }).click();
  await manual.getByText(/共 7 条/).waitFor();
  assert.deepEqual(body(lastRequest("POST", "/admin-api/conversation-logs/cleanup/preview")), { mode: "before", before: "2026-10-01" });
  await manual.getByRole("button", { name: "执行清理" }).click();
  await page.getByRole("button", { name: "永久删除" }).click();
  await page.getByText("已删除 7 条会话记录").waitFor();
  assert.deepEqual(body(lastRequest("POST", "/admin-api/conversation-logs/cleanup")), { mode: "before", before: "2026-10-01" });
  await page.locator(".conversation-history-table tbody tr", { hasText: "按日期" }).waitFor();
  assert.match(await page.locator(".conversation-history-table").innerText(), /定时清理/);

  // Deleting everything needs the typed confirmation.
  await manual.locator('select[name="mode"]').selectOption("all");
  await manual.getByRole("button", { name: "执行清理" }).click();
  const confirm = page.locator(".modal-dialog--confirm");
  await confirm.waitFor();
  assert.equal(await confirm.getByRole("button", { name: "永久删除" }).isDisabled(), true);
  await confirm.locator(".confirm-input").fill("清空");
  await confirm.getByRole("button", { name: "永久删除" }).click();
  await page.getByText("已删除 42 条会话记录").waitFor();
  assert.equal(body(lastRequest("POST", "/admin-api/conversation-logs/cleanup")).confirm, "PURGE");
  if (shots) await page.screenshot({ path: `${shots}/conversation-log-settings.png`, fullPage: true });

  // Turning the feature off asks whether to delete the records too.
  await form.locator('input[name="enabled"]').uncheck();
  await page.getByRole("button", { name: "保存设置" }).click();
  const off = page.locator(".modal-dialog--confirm");
  await off.locator('input[name="purge_on_disable"]').check();
  await off.getByRole("button", { name: "确认关闭" }).click();
  await page.locator(".conversation-history-table tbody tr", { hasText: "关闭功能时清空" }).waitFor();
  assert.equal(body(lastRequest("PUT", "/admin-api/conversation-logs/settings")).purge, true);
  assert.match(await page.locator(".conversation-status").innerText(), /未启用/);

  // Back on for the next tests.
  await form.locator('input[name="enabled"]').check();
  await page.getByRole("button", { name: "保存设置" }).click();
  await page.getByRole("button", { name: "确认启用" }).click();
  await page.waitForFunction(() => document.querySelector(".conversation-status")?.innerText.includes("已启用"));
  assert.deepEqual(errors, []);
  await context.close();
});

test("会话记录设置：可选本地存储或腾讯云 COS，COS 先配置并测试", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/system/conversation-log-settings" });
  const form = page.locator(".conversation-settings-form").first();
  await form.waitFor();
  assert.equal(await form.locator('input[name="storage_driver"][value="local"]').isChecked(), true, "local storage is the default");
  // COS not configured yet: choosing it is refused with a clear message.
  await form.locator('input[name="storage_driver"][value="cos"]').check();
  await page.getByRole("button", { name: "保存设置" }).click();
  await page.getByRole("button", { name: "确认改用 COS" }).click();
  await form.locator(".notice.error").waitFor();
  assert.match(await form.locator(".notice.error").innerText(), /COS/, "choosing COS without a configuration is refused");

  const cos = page.locator(".conversation-cos");
  assert.match(await cos.innerText(), /未配置/);
  await cos.locator('input[name="secret_id"]').fill("AKIDexample12345678");
  await cos.locator('input[name="secret_key"]').fill("super-secret");
  await cos.locator('input[name="bucket"]').fill("tokenhub-logs-1250000000");
  await cos.locator('input[name="region"]').fill("ap-guangzhou");
  await cos.locator('select[name="storage_class"]').selectOption("STANDARD_IA");
  await cos.getByRole("button", { name: "保存 COS 配置" }).click();
  await cos.locator(".status-pill", { hasText: "已配置" }).waitFor();
  const saved = body(lastRequest("PUT", "/admin-api/conversation-logs/cos"));
  assert.equal(saved.secret_key, "super-secret");
  assert.equal(saved.storage_class, "STANDARD_IA");
  assert.equal(await cos.locator('input[name="secret_key"]').inputValue(), "", "the secret key is never shown again");
  assert.equal(await cos.locator('input[name="secret_key"]').getAttribute("placeholder"), "已保存，留空不修改");
  await cos.getByRole("button", { name: "测试连接" }).click();
  await cos.getByText(/连接成功：已在 tokenhub-logs-1250000000\.cos\.ap-guangzhou\.myqcloud\.com/).waitFor();

  // Now COS can be chosen.
  await form.locator('input[name="storage_driver"][value="cos"]').check();
  await page.getByRole("button", { name: "保存设置" }).click();
  await page.getByRole("button", { name: "确认改用 COS" }).click();
  await page.waitForFunction(() => document.querySelector(".conversation-cos")?.innerText.includes("当前存储方式：腾讯云 COS"));
  assert.equal(body(lastRequest("PUT", "/admin-api/conversation-logs/settings")).storage_driver, "cos");
  await cos.getByRole("button", { name: "立即同步" }).click();
  await page.getByText(/已上传 4 个文件/).waitFor();
  if (shots) await page.screenshot({ path: `${shots}/conversation-log-cos.png`, fullPage: true });

  // Back to local storage, then the files can be moved back.
  await form.locator('input[name="storage_driver"][value="local"]').check();
  await page.getByRole("button", { name: "保存设置" }).click();
  await page.getByRole("button", { name: "确认改回" }).click();
  await cos.getByRole("button", { name: "从 COS 迁回本地" }).click();
  await page.getByRole("button", { name: "开始迁回" }).click();
  await page.getByText(/已迁回 4 个文件/).waitFor();
  assert.deepEqual(errors, []);
  await context.close();
});

test("会话记录：按密钥逐条看问答和 Token，可删单条、整段会话、清空密钥", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/conversation-logs?api_key_id=1" });
  await page.locator(".conversation-call").first().waitFor();
  await settle(page);
  assert.equal(await page.locator(".conversation-log-banner").isHidden(), true, "no banner once the feature is on");
  const summary = await page.locator(".conversation-summary").innerText();
  assert.match(summary, /合计 Token\s*1,230/);
  const first = page.locator(".conversation-call").first();
  assert.match(await first.locator(".conversation-usage-chips").innerText(), /合计\s*730/);
  if (shots) await page.screenshot({ path: `${shots}/conversation-logs.png`, fullPage: true });

  await first.getByRole("button", { name: "删除" }).click();
  await page.locator(".modal-dialog--confirm").getByRole("button", { name: "删除" }).click();
  await page.getByText("已删除", { exact: true }).waitFor();
  assert.ok(lastRequest("DELETE", "/admin-api/conversation-logs/calls/2"), "one call can be deleted");

  await page.locator(".conversation-call").first().getByRole("button", { name: "查看整段会话" }).click();
  await page.getByRole("button", { name: "删除整段会话" }).click();
  await page.locator(".modal-dialog--confirm").getByRole("button", { name: "删除" }).click();
  await page.locator(".conversation-call").first().waitFor();
  assert.ok(lastRequest("DELETE", `/admin-api/conversation-logs/sessions/${"a".repeat(64)}`), "a whole conversation can be deleted");

  await page.getByRole("button", { name: "清空这个密钥的记录" }).click();
  const confirm = page.locator(".modal-dialog--confirm");
  await confirm.locator(".confirm-input").fill("清空");
  await confirm.getByRole("button", { name: "永久删除" }).click();
  await page.getByText("已删除 42 条记录").waitFor();
  assert.deepEqual(body(lastRequest("POST", "/admin-api/conversation-logs/cleanup")), { mode: "all", api_key_id: 1, confirm: "PURGE" });
  assert.deepEqual(errors, []);
  await context.close();
});

test("API 密钥：功能启用后，可为单个密钥开启会话记录", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/api-keys" });
  await page.locator("tbody tr").first().waitFor();
  assert.equal(await page.locator(".api-key-conversation-badge").count(), 1, "keys being recorded carry a badge");
  const dialog = await openKeyDialog(page, "密钥2");
  assert.equal(await dialog.locator(".conversation-feature-gate").count(), 0);
  await dialog.locator('input[name="enabled"]').check();
  await dialog.locator('input[name="retention_days"]').fill("90");
  await dialog.getByRole("button", { name: "保存" }).click();
  await page.getByRole("button", { name: "确认开启" }).click();
  await dialog.locator(".notice.success").waitFor();
  assert.deepEqual(body(lastRequest("PUT", "/admin-api/conversation-logs/api-keys/2")), { enabled: true, retention_days: 90 });
  await dialog.getByRole("button", { name: "查看对话" }).click();
  await page.waitForURL(/#\/access\/conversation-logs\?api_key_id=2/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("会话记录页面和按钮对非超级管理员隐藏", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { username: "rag", password: "rag-pass" });
  const keys = (await visibleTabs(page)).map(([, tab]) => tab);
  assert.ok(!keys.includes("conversation-logs") && !keys.includes("conversation-log-settings"));
  await context.close();
});
