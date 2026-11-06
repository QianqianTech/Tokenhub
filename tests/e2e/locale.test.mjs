import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup, visibleTabs } from "./helpers.mjs";
import { GOOD_CODE, startScreenMock } from "./screen-mock.mjs";
import * as en from "../../public/admin/assets/js/i18n/en-US.js";
import * as zh from "../../public/admin/assets/js/i18n/zh-CN.js";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

function placeholders(text) {
  return Array.from(text.matchAll(/\{(\w+)\}/g), (match) => match[1]).sort();
}

function checkDictionary(english, chinese, path) {
  assert.deepEqual(Object.keys(english), Object.keys(chinese), path);
  for (const [key, source] of Object.entries(chinese)) {
    const target = english[key];
    if (typeof source === "object") {
      checkDictionary(target, source, `${path}.${key}`);
    } else {
      assert.ok(target.trim(), `${path}.${key}`);
      assert.deepEqual(placeholders(target), placeholders(source), `${path}.${key}`);
      if (/\p{Script=Han}/u.test(source)) assert.doesNotMatch(target, /\p{Script=Han}/u, `${path}.${key}`);
    }
  }
}

test("English dictionary covers every shared key and keeps all interpolation values", () => {
  for (const [section, chinese] of Object.entries(zh)) checkDictionary(en[section], chinese, section);
  for (const [source, target] of Object.entries(en.copy)) {
    assert.ok(target.trim(), source);
    assert.deepEqual(placeholders(target), placeholders(source), source);
  }
});

test("the console switches before login, renders every English menu page, and switches back on the same route", async () => {
  const { context, page, errors } = await openPage(env);
  const url = `${env.mock.baseUrl}/admin/`;
  try {
    await page.goto(url);
    assert.equal(await page.locator("html").getAttribute("lang"), "zh-CN");
    await page.locator("#login [data-locale-picker] select").selectOption("en-US");
    await page.locator('html[lang="en-US"] #login-title').waitFor();
    assert.equal(await page.locator("#login-title").innerText(), "Sign in to the admin console");
    await login(page, env.mock.baseUrl);
    const tabs = await visibleTabs(page);
    assert.ok(tabs.length >= 30);
    for (const [domain, tab] of tabs) {
      await page.goto(`${url}#/${domain}/${tab}`);
      await settle(page);
      assert.ok((await page.locator("#content").innerText()).trim(), tab);
      assert.doesNotMatch(await page.locator("#page-title").innerText(), /\p{Script=Han}/u, tab);
      assert.equal(await page.locator("[data-page-error]").count(), 0, tab);
    }
    await page.goto(`${url}#/organization/departments`);
    await page.getByRole("button", { name: "Add department", exact: true }).click();
    const form = page.locator(".department-create-form");
    await form.locator('[name="name"]').fill("双语测试部门");
    await form.getByRole("button", { name: /^Create$/i }).click();
    await page.locator(".department-tree-panel", { hasText: "双语测试部门" }).waitFor();
    const selectedDepartmentRoute = new URL(page.url()).hash;
    assert.match(selectedDepartmentRoute, /^#\/organization\/departments\?dept=\d+$/);
    await page.locator(".topbar [data-locale-picker] select").selectOption("zh-CN");
    await page.locator('html[lang="zh-CN"] #content').waitFor();
    assert.equal(new URL(page.url()).hash, selectedDepartmentRoute);
    await page.getByRole("button", { name: "新增部门", exact: true }).waitFor();
    assert.deepEqual(errors, []);
  } finally {
    await context.close();
  }
});

test("switching on the password reset page preserves its in-memory credential", async () => {
  const { context, page, errors } = await openPage(env, { viewport: { width: 390, height: 844 } });
  const token = `apr_${"a".repeat(64)}`;
  let sent;
  await page.route("**/admin-api/reset-password", async (route) => {
    sent = route.request().postDataJSON();
    assert.equal(route.request().headers()["x-tokenhub-locale"], "en-US");
    await route.fulfill({ json: { ok: true, data: { password_reset: true } } });
  });
  try {
    await page.goto(`${env.mock.baseUrl}/admin/#reset-password=${token}`);
    await page.locator("#reset-password [data-locale-picker] select").selectOption("en-US");
    await page.locator('html[lang="en-US"] #reset-password-title').waitFor();
    assert.equal(await page.locator("#reset-password-title").innerText(), "Set a new password");
    assert.doesNotMatch(page.url(), /apr_|reset-password=/);
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
    await page.locator("#reset-new-password").fill("Local-Test-Password123");
    await page.locator("#reset-confirm-password").fill("Local-Test-Password123");
    await page.locator("#recovery-reset").click();
    await page.locator("#login-status").waitFor({ state: "visible" });
    assert.equal(await page.locator("#login-status").innerText(), "Password reset. Sign in with your new password.");
    assert.equal(sent.token, token);
    assert.deepEqual(errors, []);
  } finally {
    await context.close();
  }
});

test("the app and screen load English from their own preferences and entry points", async () => {
  const { context, page, errors } = await openPage(env, { viewport: { width: 390, height: 844 } });
  const screen = await startScreenMock(0);
  try {
    await page.goto(`${env.mock.baseUrl}/app/`);
    await page.locator('.tha-welcome').waitFor();
    await page.locator(".tha-topbar [data-locale-picker] select").selectOption("en-US");
    await page.locator('html[lang="en-US"] .tha-welcome').waitFor();
    assert.match(await page.locator('link[rel="manifest"]').getAttribute("href"), /en-US/);
    const signInCode = env.mock.issueLinkCode();
    await page.goto(`${env.mock.baseUrl}/app/link#c=${signInCode}`);
    await page.locator(".tha-tabbar").waitFor({ state: "visible" });
    await page.goto(`${env.mock.baseUrl}/app/#/system/conversation-log-settings`);
    await page.locator(".conversation-status .conversation-stats").first().waitFor();
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth));
    await page.goto(`${env.mock.baseUrl}/admin/`);
    assert.equal(await page.locator("html").getAttribute("lang"), "zh-CN");
    await page.goto(`${screen.baseUrl}/screen/${GOOD_CODE}?lang=en-US`);
    await page.locator('html[lang="en-US"] #screen').waitFor({ state: "visible" });
    assert.equal(await page.title(), "TokenHub Operations Dashboard");
    assert.equal(await page.locator("#token-title").innerText(), "Token usage today");
    assert.deepEqual(errors, []);
  } finally {
    await context.close();
    await screen.close();
  }
});
