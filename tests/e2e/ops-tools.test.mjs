// Operations tools ported from the former single-file table.js into
// lib/ops-tools.js: quota status/adjustment, key rotation, request
// diagnostics, provider catalog sync, capability contract editor, agent
// management scopes and the API key trial mode of the chat page.
import { after, afterEach, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });
afterEach(() => env.mock.clearFaults());

const requests = (method, path) => env.mock.requestLog.filter((entry) => entry.method === method && entry.path === path);

test("API key quota view shows in-flight reservations and posts an audited adjustment", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/api-keys" });
  const row = page.locator("tbody tr", { hasText: "密钥1" }).first();
  await row.getByRole("button", { name: "额度" }).click();
  const dialog = page.locator("dialog.modal-dialog").last();
  await dialog.locator(".quota-status-card").first().waitFor();
  assert.match(await dialog.innerText(), /研发部月度额度/);
  assert.match(await dialog.innerText(), /进行中预占/);
  await dialog.locator('[name="amount"]').fill("-5000");
  await dialog.locator('[name="reason"]').fill("上游故障返还");
  await dialog.getByRole("button", { name: "提交调整" }).click();
  await settle(page);
  const posted = requests("POST", "/admin-api/quota-adjustments").at(-1);
  assert.ok(posted, "adjustment should be posted");
  assert.deepEqual({ policy_id: posted.body.policy_id, target_type: posted.body.target_type, target_id: posted.body.target_id, amount: posted.body.amount, reason: posted.body.reason },
    { policy_id: 3, target_type: "department", target_id: 2, amount: -5000, reason: "上游故障返还" });
  assert.match(await dialog.innerText(), /已调整/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("API key rotation validates the grace period and shows the new key once", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/api-keys" });
  const row = page.locator("tbody tr", { hasText: "密钥1" }).first();
  await row.getByRole("button", { name: "轮换" }).click();
  const dialog = page.locator("dialog.modal-dialog").last();
  await dialog.locator('[name="grace_minutes"]').fill("20000");
  await dialog.getByRole("button", { name: "确认轮换" }).click();
  assert.match(await dialog.innerText(), /0 到 10080/);
  assert.equal(requests("POST", "/admin-api/api-keys/1/rotate").length, 0);
  await dialog.locator('[name="grace_minutes"]').fill("30");
  await dialog.getByRole("button", { name: "确认轮换" }).click();
  await settle(page);
  assert.deepEqual(requests("POST", "/admin-api/api-keys/1/rotate").at(-1).body, { grace_minutes: 30 });
  const result = page.locator("dialog.modal-dialog").last();
  await result.locator(".api-key-rotate-secret").waitFor();
  assert.match(await result.innerText(), /sk-th-new-secret-value/);
  assert.match(await result.innerText(), /只显示这一次/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("API key list shows the management badge and who disabled a key", async () => {
  const { page, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/api-keys" });
  const managed = page.locator("tbody tr", { hasText: "密钥2" }).first();
  assert.equal(await managed.locator(".api-key-management-badge").innerText(), "管理");
  const disabled = page.locator("tbody tr", { hasText: "密钥5" }).first();
  assert.match(await disabled.innerText(), /已停用（智能体）/);
  await context.close();
});

test("API key create form sends selected management scopes", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/api-keys" });
  await page.locator(".api-key-create-button").click();
  const dialog = page.locator("dialog.modal-dialog").last();
  await dialog.locator(".api-key-management-scopes summary").click();
  await dialog.locator('input[name="management_scopes"][value="users:write"]').check();
  assert.equal(await dialog.locator('input[name="management_scopes"][value="users:read"]').isDisabled(), true);
  for (const name of ["department_id", "user_id"]) {
    const picker = dialog.locator(`[data-remote-select="${name}"] input[role="combobox"]`);
    if (await picker.count()) {
      await picker.click();
      await dialog.locator(`[data-remote-select="${name}"] [role="option"]`).first().waitFor();
      await picker.press("ArrowDown");
      await picker.press("Enter");
    } else {
      await dialog.locator(`select[name="${name}"]`).selectOption({ index: 1 });
    }
  }
  await dialog.locator('[name="name"]').fill("agent-key");
  env.mock.requestLog.length = 0;
  await dialog.getByRole("button", { name: /创建并显示一次明文|保存/ }).click();
  await settle(page);
  const posted = requests("POST", "/admin-api/api-keys").at(-1);
  assert.ok(posted, `API key should be created; form said: ${await dialog.locator(".notice").allInnerTexts()}`);
  assert.deepEqual(posted.body.management_scopes, ["users:write"]);
  assert.deepEqual(errors, []);
  await context.close();
});

test("usage rows link to request diagnostics with translated causes", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/usage/usage-records" });
  const lookup = page.locator(".request-diagnostics-lookup input");
  await lookup.fill("req_abc");
  await lookup.press("Enter");
  const dialog = page.locator("dialog.modal-dialog").last();
  await dialog.locator(".request-diagnostics-explanation").waitFor();
  assert.ok(requests("GET", "/admin-api/diagnostics/requests/req_abc").length >= 1);
  assert.match(await dialog.innerText(), /额度策略在当前窗口已用尽/);
  assert.match(await dialog.innerText(), /厂商1/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("provider catalog sync previews first and applies only after confirmation", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/models/providers" });
  const row = page.locator("tbody tr", { hasText: "厂商1" }).first();
  const more = row.getByRole("button", { name: "更多" });
  if (await more.count()) {
    await more.click();
    await row.getByRole("menuitem", { name: "目录同步" }).click();
  } else {
    await row.getByRole("button", { name: "目录同步" }).click();
  }
  const dialog = page.locator("dialog.modal-dialog").first();
  await dialog.locator(".catalog-sync-summary").waitFor();
  assert.deepEqual(requests("POST", "/admin-api/providers/1/catalog-sync").map((entry) => entry.body), [{ apply: false }]);
  assert.match(await dialog.innerText(), /上游已下线/);
  assert.match(await dialog.innerText(), /另有 1 个上游新模型 TokenHub 无法调用/);
  await dialog.getByRole("button", { name: "应用" }).click();
  const confirm = page.locator("dialog.modal-dialog--confirm");
  await confirm.getByRole("button", { name: "取消" }).click();
  assert.equal(requests("POST", "/admin-api/providers/1/catalog-sync").length, 1);
  await dialog.getByRole("button", { name: "应用" }).click();
  await page.locator("dialog.modal-dialog--confirm").getByRole("button", { name: "应用" }).click();
  await settle(page);
  assert.deepEqual(requests("POST", "/admin-api/providers/1/catalog-sync").at(-1).body, { apply: true });
  assert.match(await page.locator("dialog.modal-dialog").first().innerText(), /已应用/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("model capability editor writes only the admin layer with optimistic concurrency", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/models/models?support=all" });
  const row = page.locator("tbody tr", { hasText: "model-1" }).first();
  await row.getByRole("button", { name: "编辑" }).click();
  const dialog = page.locator("dialog.modal-dialog").last();
  await dialog.locator(".model-capability-editor").waitFor();
  await dialog.locator('select[name="capability_feature_json_schema"]').selectOption("true");
  env.mock.requestLog.length = 0;
  await dialog.getByRole("button", { name: "保存" }).last().click();
  await settle(page);
  const put = env.mock.requestLog.filter((entry) => entry.method === "PUT" && /^\/admin-api\/models\/\d+$/.test(entry.path)).at(-1);
  assert.ok(put, "model update should be sent");
  assert.equal(put.body.capabilities.admin.features.json_schema, true);
  assert.deepEqual(put.body.capabilities.upstream, { features: { tools: true, streaming: true } });
  assert.ok(put.body.expected_updated_at);
  assert.deepEqual(errors, []);
  await context.close();
});

test("chat page API key trial mode lists granted supported models and sends api_key_id", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/admin-chat-sessions" });
  const apiKeySelect = page.locator('select[name="api_key_id"]');
  await apiKeySelect.waitFor();
  assert.match(await page.locator(".admin-chat-mode-hint").innerText(), /直连诊断模式/);
  const keyOption = await apiKeySelect.locator("option", { hasText: "密钥1 /" }).getAttribute("value");
  await apiKeySelect.selectOption(keyOption);
  await settle(page);
  assert.match(await page.locator(".admin-chat-mode-hint").innerText(), /API Key 试用模式/);
  const modelOptions = await page.locator('select[name="model"] option').allInnerTexts();
  assert.deepEqual(modelOptions, ["model-1"], "only granted, gateway-supported text models are offered");
  await page.locator('[name="prompt"]').fill("你好");
  await page.getByRole("button", { name: "发送" }).click();
  await settle(page);
  const sent = requests("POST", "/admin-api/chat-session/stream").at(-1);
  assert.ok(Number(sent.body.api_key_id) > 0);
  assert.match(await page.locator(".admin-chat-trial-meta").innerText(), /请求 ID/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("API key MCP sign-ins can be listed and revoked", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/access/api-keys" });
  const row = page.locator("tbody tr", { hasText: "密钥1" }).first();
  await row.getByRole("button", { name: "MCP 授权" }).click();
  const dialog = page.locator("dialog.modal-dialog").first();
  await dialog.locator(".mcp-grants-table").waitFor();
  const text = await dialog.innerText();
  assert.match(text, /Claude/);
  assert.match(text, /claude\.ai/, "metadata-document clients are shown by host");
  assert.match(text, /已撤销（用户改了密码）/);
  await dialog.getByRole("button", { name: "撤销" }).click();
  await page.locator("dialog.modal-dialog--confirm").getByRole("button", { name: "撤销" }).click();
  await settle(page);
  assert.equal(requests("POST", "/admin-api/api-keys/mcp-oauth-grants/grt_claude/revoke").length, 1);
  assert.match(await page.locator("dialog.modal-dialog").first().innerText(), /已撤销授权/);
  assert.deepEqual(errors, []);
  await context.close();
});
