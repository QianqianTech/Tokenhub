import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { mkdir } from "node:fs/promises";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });
const token = `apr_${"a".repeat(64)}`;
const genericMessage = "如果该邮箱已绑定启用的管理员账号，您将收到密码重置邮件，请检查收件箱和垃圾邮件。";
const respond = (route, data, status = 200, code = "") => route.fulfill({ status, contentType: "application/json", body: JSON.stringify(code ? { ok: false, error: { code }, request_id: "e2e-recovery" } : { ok: true, data, request_id: "e2e-recovery" }) });

test("forgot password submits a mailbox once and preserves the generic success state", async () => {
  const { page, context, errors } = await openPage(env);
  const received = [];
  await page.route("**/admin-api/forgot-password", async (route) => {
    received.push(route.request().postDataJSON());
    await new Promise((resolve) => setTimeout(resolve, 100));
    await respond(route, { message: genericMessage });
  });
  await page.goto(`${env.mock.baseUrl}/admin/`);
  await settle(page);
  await page.getByRole("button", { name: "忘记密码？" }).click();
  await page.locator("#recovery-email").fill("admin@example.test");
  await page.locator("#forgot-password").evaluate((form) => { form.requestSubmit(); form.requestSubmit(); });
  await page.locator("#recovery-status").waitFor({ state: "visible" });
  assert.deepEqual(received, [{ email: "admin@example.test" }], "a pending request cannot be double-submitted");
  assert.equal(await page.locator("#recovery-status").innerText(), genericMessage);
  assert.equal(await page.locator("#login").isVisible(), false);
  await page.locator("#forgot-password").getByRole("button", { name: "返回登录" }).click();
  assert.equal(await page.locator("#login").isVisible(), true);
  assert.deepEqual(errors, []);
  await context.close();
});

test("mail service errors and rate limits are actionable on a phone without overflow", async () => {
  const { page, context, errors } = await openPage(env, { viewport: { width: 390, height: 844 } });
  let attempts = 0;
  await page.route("**/admin-api/forgot-password", (route) => respond(route, null, attempts++ === 0 ? 503 : 429, attempts === 1 ? "SMTP_NOT_CONFIGURED" : "ADMIN_RECOVERY_RATE_LIMITED"));
  await page.goto(`${env.mock.baseUrl}/admin/`);
  await settle(page);
  await page.getByRole("button", { name: "忘记密码？" }).click();
  await page.locator("#recovery-email").fill("admin@example.test");
  await page.locator("#recovery-send").click();
  await page.locator("#recovery-error", { hasText: "邮件服务尚未配置" }).waitFor();
  await page.locator("#recovery-send").click();
  await page.locator("#recovery-error", { hasText: "操作过于频繁" }).waitFor();
  assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth), true);
  await mkdir("/private/tmp/tokenhub-recovery-verification", { recursive: true });
  await page.screenshot({ path: "/private/tmp/tokenhub-recovery-verification/forgot-mobile.png", fullPage: true });
  assert.deepEqual(errors, []);
  await context.close();
});

test("a reset link opens while signed out, clears the URL, validates confirmation and returns to login", async () => {
  const { page, context, errors } = await openPage(env);
  const received = [];
  await page.route("**/admin-api/reset-password", (route) => {
    received.push(route.request().postDataJSON());
    return respond(route, { password_reset: true });
  });
  await page.goto(`${env.mock.baseUrl}/admin/#reset-password=${token}`);
  await page.locator("#reset-password").waitFor({ state: "visible" });
  assert.doesNotMatch(page.url(), /apr_|reset-password=/, "the credential is removed from browser history");
  await page.locator("#reset-new-password").fill("Updated-Password-456");
  await page.locator("#reset-confirm-password").fill("Different-Password-789");
  await page.locator("#recovery-reset").click();
  assert.match(await page.locator("#reset-error").innerText(), /不一致/);
  assert.equal(received.length, 0, "a mismatch is handled before sending");
  await page.locator("#reset-confirm-password").fill("Updated-Password-456");
  await page.locator("#recovery-reset").click();
  await page.locator("#login-status").waitFor({ state: "visible" });
  assert.equal(await page.locator("#login").isVisible(), true);
  assert.equal(await page.locator("#reset-new-password").inputValue(), "");
  assert.deepEqual(received, [{ token, password: "Updated-Password-456", password_confirmation: "Updated-Password-456" }]);
  const persisted = await page.evaluate(() => document.cookie + JSON.stringify({ ...localStorage }) + JSON.stringify({ ...sessionStorage }));
  assert.equal(persisted.includes(token), false, "no reset credential is persisted");
  assert.deepEqual(errors, []);
  await context.close();
});

test("expired and malformed links offer a new request without losing the recovery screen", async () => {
  const { page, context, errors } = await openPage(env);
  await page.route("**/admin-api/reset-password", (route) => respond(route, null, 422, "ADMIN_RESET_LINK_INVALID"));
  await page.goto(`${env.mock.baseUrl}/admin/#reset-password=${token}`);
  await page.locator("#reset-new-password").fill("Updated-Password-456");
  await page.locator("#reset-confirm-password").fill("Updated-Password-456");
  await page.locator("#recovery-reset").click();
  await page.locator("#reset-error", { hasText: "无效或已过期" }).waitFor();
  assert.equal(await page.locator("#recovery-reset").isDisabled(), true);
  await page.getByRole("button", { name: "重新申请重置邮件" }).click();
  assert.equal(await page.locator("#forgot-password").isVisible(), true);
  await page.goto(`${env.mock.baseUrl}/admin/#reset-password=invalid`);
  assert.equal(await page.locator("#recovery-reset").isDisabled(), true);
  assert.match(await page.locator("#reset-error").innerText(), /无效或已过期/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("SMTP settings save typed values, keep stored passwords out of the form and test the saved configuration", async () => {
  const { page, context, errors } = await openPage(env);
  const settings = { enabled: true, host: "smtp.example.test", port: 587, encryption: "starttls", auth_enabled: true, username: "account@example.test", from_address: "noreply@example.test", from_name: "TokenHub", public_url: "https://hub.example.test", timeout_seconds: 10, reset_ttl_minutes: 30, password_set: true };
  const saved = [];
  const tests = [];
  await page.route("**/admin-api/system/settings-center", (route) => respond(route, { sections: { smtp: settings } }));
  await page.route("**/admin-api/system/settings-center/smtp", (route) => {
    saved.push(route.request().postDataJSON());
    return respond(route, settings);
  });
  await page.route("**/admin-api/system/settings-center/smtp/test", (route) => {
    tests.push(route.request().postDataJSON());
    return respond(route, { sent: true });
  });
  await login(page, env.mock.baseUrl, { hash: "#/system/settings-smtp" });
  const form = page.locator(".smtp-settings-form");
  await form.waitFor();
  assert.equal(await form.locator('[name="password"]').inputValue(), "");
  assert.match(await form.innerText(), /已保存 SMTP 密码/);
  assert.equal(await form.locator('[name="encryption"]').inputValue(), "starttls");
  await form.locator('[name="password"]').fill("Synthetic-New-SMTP-Secret");
  await form.locator('[name="port"]').fill("465");
  await form.locator('[name="encryption"]').selectOption("ssl");
  await form.getByRole("button", { name: "保存 SMTP 配置" }).click();
  await page.getByRole("status", { name: "" }).filter({ hasText: "SMTP 配置已保存" }).waitFor();
  assert.equal(saved[0].enabled, true);
  assert.equal(saved[0].auth_enabled, true);
  assert.equal(saved[0].port, 465);
  assert.equal(saved[0].password, "Synthetic-New-SMTP-Secret");
  assert.equal(saved[0].clear_password, false);
  assert.equal(await form.locator('[name="password"]').inputValue(), "", "the password field clears after saving");
  await page.locator('[name="test_recipient"]').fill("receiver@example.test");
  await page.getByRole("button", { name: "发送测试邮件" }).click();
  await page.getByText("测试邮件已提交给 SMTP 服务器，请检查收件箱和垃圾邮件。", { exact: true }).waitFor();
  assert.deepEqual(tests, [{ recipient: "receiver@example.test" }]);
  await form.getByRole("button", { name: "保存 SMTP 配置" }).click();
  assert.equal(saved.at(-1).password, "", "saving unrelated fields preserves the saved credential");
  await mkdir("/private/tmp/tokenhub-recovery-verification", { recursive: true });
  await page.screenshot({ path: "/private/tmp/tokenhub-recovery-verification/smtp-desktop.png", fullPage: true });
  assert.deepEqual(errors, []);
  await context.close();
});

test("administrator email can be edited and explicitly unbound in the account form", async () => {
  const { page, context, errors } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/organization/admin-users" });
  const row = page.locator("#content table tbody tr", { hasText: "hr-admin" });
  await row.getByRole("button", { name: "编辑", exact: true }).click();
  let dialog = page.locator("dialog.modal-dialog");
  await dialog.locator('[name="email"]').fill("recovery@example.test");
  await dialog.getByRole("button", { name: "保存", exact: true }).click();
  await dialog.waitFor({ state: "hidden" });
  await settle(page);
  let saved = env.mock.requestLog.filter((entry) => entry.method === "PUT" && entry.path === "/admin-api/admin-users/3").at(-1);
  assert.equal(saved.body.email, "recovery@example.test");
  await row.getByRole("button", { name: "编辑", exact: true }).click();
  dialog = page.locator("dialog.modal-dialog");
  assert.equal(await dialog.locator('[name="email"]').inputValue(), "recovery@example.test");
  await dialog.locator('[name="email"]').fill("");
  await dialog.getByRole("button", { name: "保存", exact: true }).click();
  await dialog.waitFor({ state: "hidden" });
  await settle(page);
  saved = env.mock.requestLog.filter((entry) => entry.method === "PUT" && entry.path === "/admin-api/admin-users/3").at(-1);
  assert.equal(saved.body.email, null, "clearing the form unbinds the mailbox instead of omitting the update");
  assert.deepEqual(errors, []);
  await context.close();
});
