import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";
import { fileURLToPath } from "node:url";
import { login, openPage, settle, setup, visibleTabs } from "./helpers.mjs";

const jsRoot = fileURLToPath(new URL("../../public/admin/assets/js/", import.meta.url));
function adminModules(dir = jsRoot) {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name);
    return statSync(path).isDirectory() ? adminModules(path) : path.endsWith(".js") ? [relative(jsRoot, path)] : [];
  });
}

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

test("super admin can open every menu page without runtime errors", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl);
  const tabs = await visibleTabs(page);
  assert.ok(tabs.length >= 30, `expected many tabs, got ${tabs.length}`);
  const failures = [];
  for (const [domain, tab] of tabs) {
    errors.length = 0;
    await page.goto(`${env.mock.baseUrl}/admin/#/${domain}/${tab}`);
    await page.locator("#content").waitFor({ state: "visible" });
    await settle(page);
    const text = (await page.locator("#content").innerText()).trim();
    if (text.length === 0) failures.push(`${domain}/${tab}: empty content`);
    if (text.includes("页面加载失败") || await page.locator("[data-page-error]").count()) failures.push(`${domain}/${tab}: page failed to render`);
    if (errors.length) failures.push(`${domain}/${tab}: ${errors.join(" | ")}`);
  }
  await context.close();
  assert.deepEqual(failures, []);
});

test("every admin module parses and loads as an ES module", async () => {
  const { page, context } = await openPage(env);
  await page.goto(`${env.mock.baseUrl}/admin/`);
  const failures = [];
  for (const module of adminModules()) {
    const result = await page.evaluate(async (path) => {
      try {
        await import(`/admin/assets/js/${path}`);
        return "";
      } catch (error) {
        return String(error);
      }
    }, module);
    if (result) failures.push(`${module}: ${result}`);
  }
  await context.close();
  assert.deepEqual(failures, []);
});

test("restricted administrator only sees permitted domains", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { username: "rag", password: "rag-pass" });
  const tabs = await visibleTabs(page);
  const domains = new Set(tabs.map(([domain]) => domain));
  assert.deepEqual([...domains].sort(), ["operations", "rag"]);
  const navText = await page.locator("#nav").innerText();
  assert.ok(!navText.includes("WAF"), "WAF must be hidden for a RAG administrator");
  await context.close();
});

test("department can be created from the department tree page", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/organization/departments" });
  await page.getByRole("button", { name: "新增部门", exact: true }).click();
  const form = page.locator("dialog.modal-dialog .department-create-form");
  await form.locator('[name="name"]').fill("E2E 测试部");
  await form.getByRole("button", { name: "创建" }).click();
  await page.locator(".department-tree-panel", { hasText: "E2E 测试部" }).waitFor({ timeout: 5000 });
  assert.deepEqual(errors, []);
  await context.close();
});

test("generic resource page creates a record through the modal form", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/quota-policies" });
  await page.getByRole("button", { name: "新增", exact: true }).click();
  const dialog = page.locator(".modal-dialog");
  await dialog.waitFor();
  await dialog.locator('[name="name"]').fill("E2E 配额策略");
  await dialog.getByRole("button", { name: "保存" }).click();
  await page.locator("#content table", { hasText: "E2E 配额策略" }).waitFor({ timeout: 5000 });
  assert.deepEqual(errors, []);
  await context.close();
});
