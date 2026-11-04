import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

async function showLogin(page) {
  await page.goto(`${env.mock.baseUrl}/admin/`);
  await settle(page);
  await page.locator("#username").fill("admin");
  await page.locator("#password").fill("admin-pass");
}

test("a stalled login shows progress, times out and can be retried", async () => {
  const { page, context, errors } = await openPage(env);
  try {
    let requests = 0;
    await page.route("**/admin-api/login", () => { requests += 1; });
    await showLogin(page);
    await page.locator("#login-button").click();
    assert.equal(await page.locator("#login-button").innerText(), "登录中…");
    assert.equal(await page.locator("#login-button").isDisabled(), true);
    await page.locator("#login").evaluate(form => form.requestSubmit());
    await page.locator("#login-error", { hasText: "登录请求超时" }).waitFor({ timeout: 15000 });
    assert.equal(requests, 1, "a pending login cannot be submitted again");
    assert.equal(await page.locator("#login-button").isEnabled(), true);
    assert.equal(await page.locator("#login-button").innerText(), "登录");
    assert.equal(await page.locator("#password").inputValue(), "");
    await page.unroute("**/admin-api/login");
    await page.locator("#password").fill("admin-pass");
    await page.locator("#login-button").click();
    await page.locator("#content").waitFor({ state: "visible" });
    assert.deepEqual(errors, []);
  } finally {
    await context.close();
  }
});

test("a refused login reports credentials clearly and unlocks the form", async () => {
  const { page, context, errors } = await openPage(env);
  try {
    await page.route("**/admin-api/login", route => route.fulfill({
      status: 401,
      contentType: "application/json",
      body: JSON.stringify({ ok: false, error: { code: "ADMIN_UNAUTHENTICATED", message: "Invalid username or password" } })
    }));
    await showLogin(page);
    await page.locator("#login-button").click();
    await page.locator("#login-error", { hasText: "用户名或密码错误" }).waitFor();
    assert.equal(await page.locator("#login-button").isEnabled(), true);
    assert.equal(await page.locator("#password").inputValue(), "");
    assert.deepEqual(errors, []);
  } finally {
    await context.close();
  }
});

test("a failed connection reports a network error instead of staying busy", async () => {
  const { page, context, errors } = await openPage(env);
  try {
    await page.route("**/admin-api/login", route => route.abort("failed"));
    await showLogin(page);
    await page.locator("#login-button").click();
    await page.locator("#login-error", { hasText: "网络连接失败" }).waitFor();
    assert.equal(await page.locator("#login-button").isEnabled(), true);
    assert.equal(await page.locator("#login-button").innerText(), "登录");
    assert.deepEqual(errors, []);
  } finally {
    await context.close();
  }
});
