// Knowledge-set access control (RAG-013) and index health (RAG-043) pages.
import { after, afterEach, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });
afterEach(() => env.mock.clearFaults());

const requests = (method, path) => env.mock.requestLog.filter((entry) => entry.method === method && (path instanceof RegExp ? path.test(entry.path) : entry.path === path));

test("knowledge-set access tab edits grants, keeps out-of-scope ones read-only and runs the access check", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/rag/rag-build?tab=rag-access" });
  const panel = page.locator(".rag-access");
  await panel.locator(".rag-access-grants").first().waitFor();
  const kbTable = panel.locator(".rag-access-grants").first();
  assert.match(await kbTable.innerText(), /超出管理范围/, "a grant outside the admin scope is shown read-only");
  const rows = kbTable.locator("tbody tr");
  assert.equal(await rows.count(), 2);

  await panel.locator('[name="visibility"]').selectOption("department");
  await panel.getByRole("button", { name: "添加授权" }).first().click();
  const added = rows.nth(2);
  await added.locator('[name="subject_type"]').selectOption("user");
  await added.locator('[name="subject_id"]').selectOption({ index: 0 });
  await added.locator('[name="permission"]').selectOption("admin");
  assert.equal(await added.locator('[name="include_descendants"]').isDisabled(), true, "include_descendants applies to departments only");
  await panel.getByRole("button", { name: "保存知识库授权" }).click();
  await settle(page);

  const put = requests("PUT", /^\/admin-api\/rag\/knowledge-bases\/\d+\/acl$/).at(-1);
  assert.ok(put, "the ACL should be saved");
  assert.equal(put.body.visibility, "department");
  assert.equal(put.body.grants.length, 2, "only editable grants are sent");
  assert.deepEqual(put.body.grants.map((g) => [g.subject_type, g.permission, g.include_descendants]), [["department", "write", true], ["user", "admin", false]]);
  await page.locator(".rag-access", { hasText: "知识库授权已保存" }).waitFor();

  const dataset = panel.locator(".rag-access-dataset", { hasText: "核心设计" });
  assert.equal(await dataset.evaluate((node) => node.open), true, "custom datasets start expanded");
  await dataset.getByRole("button", { name: "保存数据集授权" }).click();
  await settle(page);
  const dsPut = requests("PUT", "/admin-api/rag/datasets/12/acl").at(-1);
  assert.equal(dsPut.body.acl_mode, "custom");
  assert.equal(dsPut.body.grants[0].priority, 1);

  await panel.locator('[name="check_subject"]').selectOption({ index: 0 });
  await panel.getByRole("button", { name: "检查" }).click();
  await panel.locator("text=部门链：总部 › 研发部").waitFor();
  assert.match(await panel.innerText(), /研发库（kb_rd）[\s\S]*公开资料:read/);
  await panel.locator('[name="check_type"]').selectOption("api_key");
  await panel.getByRole("button", { name: "检查" }).click();
  await panel.locator("text=处于限制模式").waitFor();
  assert.ok(requests("GET", "/admin-api/rag/access-check").some((entry) => entry.query.includes("api_key_id=")));
  assert.deepEqual(errors, []);
  await context.close();
});

test("index health tab shows coverage and runs reconcile and lexical backfill", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/rag/rag-operations?tab=rag-index-health" });
  const panel = page.locator(".rag-index-health");
  await panel.locator(".rag-health-stats").waitFor();
  const text = await panel.innerText();
  assert.match(text, /97\.5%/);
  assert.match(text, /尚未核对/);
  assert.match(text, /92\.5%（37\/40）/);
  assert.match(text, /未构建/);

  await panel.getByRole("button", { name: "核对全部" }).click();
  await panel.locator("text=核对完成：孤儿点 2，缺失点 3").waitFor();
  assert.equal(requests("POST", "/admin-api/rag/index-reconcile").at(-1).body.fix, false);
  await panel.locator("text=上次核对").waitFor();

  await panel.getByRole("button", { name: "构建关键词索引" }).click();
  await panel.locator("text=完成：40 个分块，40 个索引点").waitFor();
  assert.equal(requests("POST", "/admin-api/rag/datasets/12/backfill-lexical").length, 1);
  assert.deepEqual(errors, []);
  await context.close();
});

test("retrieval evaluation shows ranking and answer-level metrics and can request the judge", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/rag/rag-quality?tab=rag-evaluation" });
  const panel = page.locator(".rag-evaluation");
  await panel.locator(".rag-answer-evaluation").waitFor();
  const text = await panel.innerText();
  assert.match(text, /nDCG@10\s*0\.54/);
  assert.match(text, /低置信率\s*33\.33%/);
  assert.match(text, /答案忠实度\s*0\.90/);
  assert.match(text, /引用命中率\s*100%/);
  await panel.locator('[name="judge"]').check();
  await panel.getByRole("button", { name: "运行评估" }).click();
  await settle(page);
  const posted = requests("POST", "/admin-api/rag/evaluation-runs").at(-1);
  assert.equal(posted.body.judge, true);
  assert.deepEqual(errors, []);
  await context.close();
});

test("upload workbench sends documents with the selected redaction types", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/rag/rag-build?tab=rag-import-task" });
  const form = page.locator(".rag-upload-workbench");
  await form.waitFor();
  await form.locator('[name="template"]').selectOption("document");
  await form.locator('input[name="redaction"][value="phone"]').check();
  await form.locator('input[name="redaction"][value="id_card"]').check();
  await form.locator('input[name="rag_file"]').setInputFiles({ name: "手册.md", mimeType: "text/markdown", buffer: Buffer.from("# 手册\n电话 13812345678\n") });
  await form.getByRole("button", { name: "上传并生成预览" }).click();
  await settle(page);
  const posted = requests("POST", /^\/admin-api\/rag\/knowledge-bases\/[^/]+\/uploads$/).at(-1);
  assert.ok(posted, "the upload should be posted");
  assert.equal(posted.body.content_type, "document");
  assert.deepEqual(posted.body.processing.redaction, ["phone", "id_card"]);
  assert.equal(posted.body.filename, "手册.md");
  assert.deepEqual(errors, []);
  await context.close();
});
