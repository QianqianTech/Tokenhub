import { after, afterEach, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });
afterEach(() => env.mock.clearFaults());

const archiveRequests = () => env.mock.requestLog.filter((entry) => entry.method === "GET" && entry.path === "/admin-api/content-archive");

test("content archive filters by picker, keeps filters in the URL and resolves names", async () => {
  const { page, errors, context } = await openPage(env);
  env.mock.requestLog.length = 0;
  await login(page, env.mock.baseUrl, { hash: "#/usage/content-archive?user_id=2" });
  const table = page.locator("#content table");
  await table.waitFor();
  assert.ok(archiveRequests().at(-1).query.includes("user_id=2"), "initial URL filter should reach the API");
  assert.equal(await table.locator("tbody tr").count(), 1);
  const picker = page.locator('[data-field="user_id"] .remote-select-input');
  assert.equal(await picker.count(), 1, "user filter should be a searchable picker, not an id input");
  assert.equal(await page.locator('input[name="user_id"][type="number"]').count(), 0);
  // Session ids are free text; submitting keeps the URL in sync.
  await page.locator('[name="session_id"]').fill("sess-2");
  await page.getByRole("button", { name: "查询" }).click();
  await settle(page);
  assert.match(page.url(), /session_id=sess-2/);
  assert.match(page.url(), /user_id=2/);
  await page.getByRole("button", { name: "重置" }).click();
  await settle(page);
  assert.equal(await table.locator("tbody tr").count(), 3);
  assert.doesNotMatch(page.url(), /user_id=/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("content archive shows a retryable error state instead of a fake row", async () => {
  env.mock.fault("/admin-api/content-archive", { method: "GET", status: 500, times: 1 });
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/usage/content-archive" });
  const error = page.locator("#content .state--error");
  await error.waitFor();
  await error.getByRole("button", { name: "重试" }).click();
  await page.locator("#content table tbody tr").first().waitFor();
  assert.deepEqual(errors, []);
  await context.close();
});

test("departments use a parent picker, confirm before disabling and can be re-enabled", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/organization/departments" });
  // Create a child of 研发部 from the page-level action.
  await page.getByRole("button", { name: "新增部门", exact: true }).click();
  const form = page.locator("dialog.modal-dialog .department-create-form");
  const parent = form.locator('select[name="parent_id"]');
  assert.ok((await parent.locator("option").allTextContents()).some((text) => text.includes("研发部")), "parent picker should list departments by name");
  await form.locator('[name="name"]').fill("E2E 子部门");
  await parent.selectOption({ label: "　研发部" });
  await form.getByRole("button", { name: "创建" }).click();
  const tree = page.locator(".department-tree-panel");
  const created = tree.locator(".department-children .department-node-name", { hasText: "E2E 子部门" });
  await created.waitFor();
  const created_post = env.mock.requestLog.filter((entry) => entry.method === "POST" && entry.path === "/admin-api/departments").at(-1);
  const body = typeof created_post.body === "string" ? JSON.parse(created_post.body) : created_post.body;
  assert.equal(body.parent_id, 2);
  // The new department is selected and its path is shown.
  const detail = page.locator(".department-detail");
  await detail.locator("h3", { hasText: "E2E 子部门" }).waitFor();
  assert.match(await detail.locator(".department-path").innerText(), /研发部/);

  // Editing offers neither the department itself nor its descendants as parent.
  await tree.locator('.department-node[data-department-id="2"] > .department-node-row').click();
  await detail.getByRole("button", { name: /编辑部门 研发部/ }).click();
  const dialog = page.locator(".modal-dialog");
  const editParents = await dialog.locator('select[name="parent_id"] option').allTextContents();
  assert.ok(!editParents.some((text) => text.includes("研发部") || text.includes("E2E 子部门")), editParents.join(","));
  await dialog.getByRole("button", { name: "关闭" }).click().catch(() => page.keyboard.press("Escape"));

  // Select the new department from the children list, then disable / enable it.
  await detail.locator(".department-child-row", { hasText: "E2E 子部门" }).click();
  await detail.getByRole("button", { name: /停用部门 E2E 子部门/ }).click();
  const confirm = page.locator(".modal-dialog", { hasText: "停用后该部门下的 API 密钥将立即失效" });
  await confirm.waitFor();
  await confirm.getByRole("button", { name: "取消" }).click();
  assert.equal(env.mock.requestLog.filter((entry) => entry.method === "DELETE").length, 0, "cancel must not disable");
  await detail.getByRole("button", { name: /停用部门 E2E 子部门/ }).click();
  await page.locator(".modal-dialog").getByRole("button", { name: "停用", exact: true }).click();
  await detail.getByRole("button", { name: /启用部门 E2E 子部门/ }).click();
  await detail.getByRole("button", { name: /停用部门 E2E 子部门/ }).waitFor();
  assert.deepEqual(errors, []);
  await context.close();
});

test("department tree supports search, keyboard navigation and deep links", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/organization/departments" });
  const tree = page.locator(".department-tree-panel");
  await tree.locator(".department-node").first().waitFor();
  await tree.locator(".department-search").fill("研发");
  const visible = await tree.locator('.department-node:visible .department-node-name').allTextContents();
  assert.ok(visible.some((text) => text.includes("研发部")), visible.join(","));
  assert.ok(!visible.some((text) => text.includes("运营部")), "non-matching departments are filtered out");
  assert.equal(await tree.locator(".department-node-name mark").first().innerText(), "研发");
  await tree.locator(".department-search").fill("");
  // Keyboard: focus the selected item, move down and select with Enter.
  await tree.locator('.department-node[tabindex="0"]').focus();
  await page.keyboard.press("ArrowDown");
  await page.keyboard.press("Enter");
  await settle(page);
  assert.match(page.url(), /dept=\d+/);
  const selectedName = await tree.locator('.department-node[aria-selected="true"] > .department-node-row .department-node-name').innerText();
  assert.equal(await page.locator(".department-detail h3").innerText(), selectedName);
  // Reload keeps the selection.
  await page.reload();
  await settle(page);
  assert.equal(await page.locator(".department-detail h3").innerText(), selectedName);
  assert.deepEqual(errors, []);
  await context.close();
});

test("department tree shows a retryable error state", async () => {
  env.mock.fault("/admin-api/departments/tree", { method: "GET", status: 500, times: 1 });
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/organization/departments" });
  const error = page.locator(".department-tree-panel .state--error");
  await error.waitFor();
  await error.getByRole("button", { name: "重试" }).click();
  await page.locator(".department-tree-panel .department-node").first().waitFor();
  assert.deepEqual(errors, []);
  await context.close();
});

test("a page whose initial load fails shows a retryable page error", async () => {
  env.mock.fault("/admin-api/operations/status", { method: "GET", status: 500, times: 1 });
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/system/operations-status" });
  const failure = page.locator("[data-page-error]");
  await failure.waitFor();
  await failure.getByRole("button", { name: "重试" }).click();
  await page.locator("#content .ops-service").first().waitFor();
  assert.equal(await page.locator("[data-page-error]").count(), 0);
  assert.deepEqual(errors, []);
  await context.close();
});

test("RAG import preview is readable and paged", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/rag/rag-build?tab=rag-import-task&upload_id=up-1" });
  const status = page.locator(".rag-upload-status-card");
  await status.waitFor();
  const statusText = await status.innerText();
  assert.match(statusText, /待审核/);
  assert.doesNotMatch(statusText, /waiting_review/);
  const preview = page.locator(".rag-preview-page");
  await preview.waitFor();
  assert.match(await preview.innerText(), /校验未通过[\s\S]*缺少标题/);
  await preview.getByRole("button", { name: "下一页" }).click();
  await page.locator(".rag-preview-item", { hasText: "条目 21" }).waitFor();
  await page.getByRole("button", { name: "图片", exact: true }).click();
  const image = page.locator(".rag-image-preview-item").first();
  await image.waitFor();
  const imageText = await image.innerText();
  assert.match(imageText, /AI 标注[\s\S]*标签[\s\S]*设备，铭牌/);
  assert.doesNotMatch(imageText, /[{}"]/, "annotations must not be shown as raw JSON");
  assert.match(imageText, /分辨率偏低/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("RAG preview type buttons reflect the active type", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/rag/rag-build?tab=rag-import-task&upload_id=up-1" });
  const imageTab = page.getByRole("button", { name: "图片", exact: true });
  await imageTab.click();
  await settle(page);
  assert.equal(await imageTab.getAttribute("aria-pressed"), "true");
  assert.equal(await page.getByRole("button", { name: "文本", exact: true }).getAttribute("aria-pressed"), "false");
  await context.close();
});
