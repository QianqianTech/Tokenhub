// 2026-10-30 UI fixes: sidebar accordion, chat window layout and the
// permission configuration page / editor.
import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

const requests = (method, path) => env.mock.requestLog.filter((entry) => entry.method === method && entry.path === path);

test("sidebar opens one group at a time and shows an expand chevron", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/dashboard" });
  assert.equal(await page.locator("#nav .nav-section.open").count(), 1);
  assert.equal(await page.locator('#nav [data-nav-section="models"] .nav-caret').isVisible(), true);
  await page.locator('#nav [data-nav-section="models"] .nav-section-toggle').click();
  const open = await page.locator("#nav .nav-section.open").evaluateAll((nodes) => nodes.map((node) => node.dataset.navSection));
  assert.deepEqual(open, ["models"], "opening a group collapses the others");
  await page.locator('#nav [data-nav-section="security"] .nav-section-toggle').click();
  await page.locator("#nav a.nav-button", { hasText: "告警事件" }).click();
  await page.waitForURL(/alert-events/);
  await settle(page);
  const afterNav = await page.locator("#nav .nav-section.open").evaluateAll((nodes) => nodes.map((node) => node.dataset.navSection));
  assert.deepEqual(afterNav, ["security"]);
  // second-level text lines up with the group label
  const [labelLeft, itemLeft] = await page.evaluate(() => {
    const section = document.querySelector('#nav [data-nav-section="security"]');
    const label = section.querySelector(".nav-section-label").getBoundingClientRect().left;
    const item = section.querySelector(".nav-button");
    const left = item.getBoundingClientRect().left + parseFloat(getComputedStyle(item).paddingLeft);
    return [Math.round(label), Math.round(left)];
  });
  assert.ok(Math.abs(labelLeft - itemLeft) <= 2, `menu items align with group label (${labelLeft} vs ${itemLeft})`);
  assert.deepEqual(errors, []);
  await context.close();
});

test("chat page is a chat window: Enter sends, composer holds model and mode", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/admin-chat-sessions" });
  await page.locator(".admin-chat-suggestion").first().waitFor();
  assert.equal(await page.locator(".admin-chat-input-footer select[name=model]").count(), 1);
  assert.equal(await page.locator(".admin-chat-input-footer select[name=api_key_id]").count(), 1);
  const box = await page.locator(".admin-chat-shell").boundingBox();
  assert.ok(box.y + box.height <= 900, "chat window fits the viewport");
  await page.locator("[name=prompt]").fill("你好");
  await page.locator("[name=prompt]").press("Enter");
  await settle(page);
  await page.locator(".admin-chat-message.assistant .admin-chat-bubble", { hasText: "你好" }).waitFor();
  assert.equal(await page.locator("[name=prompt]").inputValue(), "");
  await page.getByRole("button", { name: "清空对话" }).click();
  assert.equal(await page.locator(".admin-chat-message").count(), 0);
  assert.deepEqual(errors, []);
  await context.close();
});

test("permission page lists administrators as a table and the editor keeps preset mode", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/organization/permission-config" });
  await page.locator(".permission-admin-table tbody tr").first().waitFor();
  const text = await page.locator("#content").innerText();
  assert.doesNotMatch(text, /有效权限：/, "no raw comma-separated code dump");
  assert.equal(await page.locator(".perm-matrix tbody tr").count(), 14);
  const superRow = page.locator(".permission-admin-table tbody tr", { hasText: "admin" }).filter({ hasText: "超级管理员" });
  assert.equal(await superRow.getByRole("button", { name: "配置权限" }).count(), 0, "super admin is not editable");

  // preset administrator: saving does not send explicit permissions
  await page.locator(".permission-admin-table tbody tr", { hasText: "hr-admin" }).getByRole("button", { name: "配置权限" }).click();
  const dialog = page.locator("dialog.modal-dialog").first();
  await dialog.locator(".permission-edit-matrix").waitFor();
  assert.equal(await dialog.locator('input[value="users.manage"]').isDisabled(), true, "preset matrix is read-only");
  assert.equal(await dialog.locator('input[value="users.manage"]').isChecked(), true);
  await dialog.getByRole("button", { name: "保存权限配置" }).click();
  await settle(page);
  let put = requests("PUT", "/admin-api/admin-users/3").at(-1);
  assert.equal(put.body.admin_type, "user_admin");
  assert.equal("permissions" in put.body, false);
  assert.deepEqual(put.body.managed_department_ids, [2, 3]);

  // switch to custom: checkboxes start from the preset and are sent
  await page.locator(".permission-admin-table tbody tr", { hasText: "rag" }).getByRole("button", { name: "配置权限" }).click();
  const dialog2 = page.locator("dialog.modal-dialog").last();
  await dialog2.locator(".permission-edit-matrix").waitFor();
  await dialog2.locator('input[name="permission_mode"][value="custom"]').check();
  await dialog2.locator('input[value="logs.read"]').check();
  await dialog2.getByRole("button", { name: "保存权限配置" }).click();
  await settle(page);
  put = requests("PUT", "/admin-api/admin-users/4").at(-1);
  assert.deepEqual(put.body.permissions, ["dashboard.read", "usage.read", "rag.read", "rag.manage", "logs.read"]);
  assert.deepEqual(errors, []);
  await context.close();
});
