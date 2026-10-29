import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

test("login form submits with Enter and reports missing fields", async () => {
  const { page, errors, context } = await openPage(env);
  await page.goto(`${env.mock.baseUrl}/admin/`);
  await page.locator("#username").fill("admin");
  await page.locator("#username").press("Enter");
  assert.match(await page.locator("#login-error").innerText(), /请输入用户名和密码/);
  await page.locator("#password").fill("wrong");
  await page.locator("#password").press("Enter");
  await page.locator("#login-error", { hasText: "登录" }).waitFor();
  assert.equal(await page.locator("#password").inputValue(), "", "a rejected password is cleared");
  await page.locator("#password").fill("admin-pass");
  await page.locator("#password").press("Enter");
  await page.locator("#content").waitFor({ state: "visible" });
  assert.deepEqual(errors, []);
  await context.close();
});

test("an expired session asks for the password on top of the page and keeps unsaved input", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/quota-policies" });
  await page.getByRole("button", { name: "新增", exact: true }).click();
  const form = page.locator("dialog.modal-dialog").first();
  await form.locator('[name="name"]').fill("未提交的草稿");
  env.mock.expireSessions();
  await form.getByRole("button", { name: "保存" }).click();
  const relogin = page.locator("dialog.modal-dialog--relogin");
  await relogin.waitFor();
  await page.keyboard.press("Escape");
  assert.equal(await relogin.isVisible(), true, "the re-login dialog cannot be dismissed");
  await relogin.locator('[name="password"]').fill("admin-pass");
  await relogin.getByRole("button", { name: "重新登录" }).click();
  await relogin.waitFor({ state: "detached" });
  assert.equal(await form.locator('[name="name"]').inputValue(), "未提交的草稿");
  await form.getByRole("button", { name: "保存" }).click();
  await page.locator("#content table", { hasText: "未提交的草稿" }).waitFor({ timeout: 5000 });
  await context.close();
});

test("the user is warned before the session expires and can renew it", async () => {
  const { page, context } = await openPage(env);
  await page.clock.install();
  await login(page, env.mock.baseUrl);
  await page.clock.fastForward("01:56:00");
  const warning = page.locator("dialog.modal-dialog--confirm", { hasText: "登录即将过期" });
  await warning.waitFor();
  await warning.getByRole("button", { name: "继续使用" }).click();
  await page.locator(".toast", { hasText: "登录已续期" }).waitFor();
  assert.ok(env.mock.requestLog.some((entry) => entry.method === "POST" && entry.path === "/admin-api/session/renew"));
  await settle(page);
  await context.close();
});

test("command palette jumps to a page by name", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl);
  await page.keyboard.press("Control+k");
  const input = page.locator(".palette-input");
  await input.waitFor();
  await input.fill("审计日志");
  await input.press("Enter");
  await page.waitForURL(/#\/security\/audit-logs/);
  await settle(page);
  assert.equal(await page.locator(".palette-input").count(), 0);
  await page.locator("#palette-trigger").click();
  await page.locator(".palette-input").fill("zzzz-no-match");
  assert.match(await page.locator(".palette-list").innerText(), /没有匹配的页面/);
  await page.keyboard.press("Escape");
  assert.deepEqual(errors, []);
  await context.close();
});
