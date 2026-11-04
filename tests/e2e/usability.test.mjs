// 2026-10-31 console usability (UX-101 … UX-113): the provider → model →
// price → key → test chain, local time display, page-form dirty guard,
// navigation regrouping, structured retention policy, knowledge base
// profile fields and the SMTP-aware "忘记密码" link.
import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { login, openPage, settle, setup } from "./helpers.mjs";

let env;
before(async () => { env = await setup(); });
after(async () => { await env?.close(); });

const requests = (method, path) => env.mock.requestLog.filter((entry) => entry.method === method && entry.path === path);

async function pickRemote(scope, name, text) {
  const picker = scope.locator(`[data-remote-select="${name}"] input[role="combobox"]`);
  await picker.click();
  await picker.fill(text);
  const option = scope.locator(`[data-remote-select="${name}"] [role="option"]`, { hasText: text }).first();
  await option.waitFor();
  await option.click();
}

test("provider → model → price → API key → chat test works end to end without typing ids", async () => {
  const { page, errors, context } = await openPage(env);
  await context.grantPermissions(["clipboard-read", "clipboard-write"]);
  await login(page, env.mock.baseUrl, { hash: "#/models/providers" });

  // 1. Provider
  await page.getByRole("button", { name: /^新建/ }).click();
  let dialog = page.locator("dialog.modal-dialog");
  await dialog.waitFor();
  await dialog.locator('[name="name"]').fill("链路厂商");
  await dialog.locator('[name="base_url"]').fill("https://chain.example.com/v1");
  await dialog.getByRole("button", { name: "保存" }).click();
  await settle(page);
  const provider = requests("POST", "/admin-api/providers").at(-1).body;
  assert.equal(provider.name, "链路厂商");

  // 2. Model: the provider is picked by name, not typed as a number.
  await page.goto(`${env.mock.baseUrl}/admin/#/models/models`);
  await settle(page);
  await page.getByRole("button", { name: /^新建/ }).click();
  dialog = page.locator("dialog.modal-dialog");
  await dialog.waitFor();
  assert.equal(await dialog.locator('input[type="number"][name="provider_id"]').count(), 0, "no numeric provider id input");
  await pickRemote(dialog, "provider_id", "链路厂商");
  await dialog.locator('[name="name"]').fill("chain-model");
  await dialog.locator('[name="external_name"]').fill("chain-upstream");
  await dialog.getByRole("button", { name: "保存" }).click();
  await settle(page);
  const modelPost = requests("POST", "/admin-api/models").at(-1);
  assert.equal(typeof modelPost.body.provider_id, "number");
  const providerRow = (await page.evaluate(() => fetch("/admin-api/providers?limit=100").then((r) => r.json()))).data.find((row) => row.name === "链路厂商");
  assert.equal(modelPost.body.provider_id, providerRow.id);
  const models = (await page.evaluate(() => fetch("/admin-api/models?limit=100").then((r) => r.json()))).data;
  const model = models.find((row) => row.name === "chain-model");

  // 3. Price: "未配置" opens the price form prefilled with this model.
  const priceButton = page.locator(`.price-missing-button[data-model-id="${model.id}"]`);
  await priceButton.waitFor();
  await priceButton.click();
  dialog = page.locator("dialog.modal-dialog");
  await dialog.waitFor();
  assert.equal(await dialog.locator('input[name="model_id"]').inputValue(), String(model.id));
  await dialog.locator('[name="input_token_price"]').fill("0.000002");
  await dialog.locator('[name="output_token_price"]').fill("0.000006");
  await dialog.getByRole("button", { name: "保存" }).click();
  await settle(page);
  const price = requests("POST", "/admin-api/model-prices").at(-1).body;
  assert.equal(price.model_id, model.id);

  // 4. API key granted that model.
  await page.goto(`${env.mock.baseUrl}/admin/#/access/api-keys`);
  await settle(page);
  await page.getByRole("button", { name: /^新建/ }).click();
  dialog = page.locator("dialog.modal-dialog");
  await dialog.waitFor();
  await dialog.locator('select[name="department_id"]').selectOption({ index: 1 });
  const userPicker = dialog.locator('[data-remote-select="user_id"] input[role="combobox"]');
  await userPicker.click();
  await dialog.locator('[data-remote-select="user_id"] [role="option"]').first().waitFor();
  await userPicker.press("ArrowDown");
  await userPicker.press("Enter");
  await dialog.locator('[name="name"]').fill("链路密钥");
  await pickRemote(dialog, "model_ids", "chain-model");
  await dialog.getByRole("button", { name: /创建并显示一次明文/ }).click();
  await settle(page);
  const key = requests("POST", "/admin-api/api-keys").at(-1).body;
  assert.deepEqual(key.model_ids, [model.id]);
  await page.keyboard.press("Escape");
  await settle(page);

  // 5. "测试" on the key row opens 会话测试 with the key preselected.
  const row = page.locator("tbody tr", { hasText: "链路密钥" }).first();
  await row.getByRole("button", { name: "更多" }).click();
  await row.getByRole("menuitem", { name: "测试" }).click();
  await page.waitForURL(/admin-chat-sessions\?api_key_id=\d+/);
  await settle(page);
  const keyId = await page.locator('select[name="api_key_id"]').inputValue();
  assert.notEqual(keyId, "");
  assert.match(await page.locator(".admin-chat-mode-hint").innerText(), /API Key 试用模式/);
  assert.deepEqual(await page.locator('select[name="model"] option').allInnerTexts(), ["chain-model"]);

  // Ctrl+Enter sends; the reply is Markdown rendered as DOM, never as HTML.
  await page.locator('[name="prompt"]').fill("请用 markdown 回答");
  await page.locator('[name="prompt"]').press("Control+Enter");
  const reply = page.locator(".admin-chat-message.assistant .admin-chat-bubble").last();
  await reply.locator("strong", { hasText: "加粗" }).waitFor();
  assert.equal(await reply.locator("code.md-code-inline").innerText(), "code");
  assert.equal(await reply.locator("li").count(), 2);
  assert.match(await reply.locator("pre code").innerText(), /console\.log\(1\)/);
  assert.equal(await reply.locator("img").count(), 0, "markup in a reply is shown as text");
  assert.match(await reply.innerText(), /<img src=x onerror=alert\(1\)>/);
  const link = reply.locator("a.md-link");
  assert.equal(await link.getAttribute("href"), "https://example.com");
  assert.match(await link.getAttribute("rel"), /noopener/);

  // Per-reply metrics: time, tokens from the usage chunk, request id, channel.
  const metrics = page.locator(".admin-chat-message.assistant .admin-chat-metrics").last();
  await metrics.waitFor();
  const metricsText = await metrics.innerText();
  assert.match(metricsText, /耗时/);
  assert.match(metricsText, /首字/);
  assert.match(metricsText, /12 \/ 7/);
  assert.match(metricsText, /请求 ID/);
  await page.locator(".admin-chat-metrics", { hasText: "渠道" }).waitFor({ timeout: 5000 });
  assert.match(await page.locator(".admin-chat-metrics").last().innerText(), /厂商1 · gpt-x/);
  const sent = requests("POST", "/admin-api/chat-session/stream").at(-1).body;
  assert.equal(Number(sent.api_key_id), Number(keyId));

  // Copy as curl never embeds a key.
  await metrics.getByRole("button", { name: "复制 curl" }).click();
  const curl = await page.evaluate(() => navigator.clipboard.readText());
  assert.match(curl, /\/v1\/chat\/completions/);
  assert.match(curl, /\$TOKENHUB_API_KEY/);
  assert.match(curl, /"model":"chain-model"/);

  // Retry asks again with the same conversation.
  const before = requests("POST", "/admin-api/chat-session/stream").length;
  await page.locator(".admin-chat-retry").click();
  await settle(page);
  assert.equal(requests("POST", "/admin-api/chat-session/stream").length, before + 1);
  assert.equal(requests("POST", "/admin-api/chat-session/stream").at(-1).body.messages.length, 1);
  assert.equal(await page.locator(".admin-chat-message.assistant").count(), 1);

  // History survives switching pages within the session.
  await page.goto(`${env.mock.baseUrl}/admin/#/operations/dashboard`);
  await settle(page);
  await page.locator("#nav a.nav-button", { hasText: "会话测试" }).click();
  await settle(page);
  await page.locator(".admin-chat-message.user", { hasText: "请用 markdown 回答" }).waitFor();
  assert.deepEqual(errors, []);
  await context.close();
});

test("server timestamps (UTC) are shown in local time and range filters are sent as UTC", async () => {
  const context = await env.browser.newContext({ viewport: { width: 1440, height: 900 }, timezoneId: "Asia/Shanghai" });
  const page = await context.newPage();
  const errors = [];
  page.on("pageerror", (error) => errors.push(error.message));
  await login(page, env.mock.baseUrl, { hash: "#/usage/usage-records" });
  await page.locator("tbody tr").first().waitFor();
  // usage-records #1 is created at 2026-10-27 09:53:00 UTC = 17:53 in Shanghai.
  const titles = await page.locator("tbody td span[title]").evaluateAll((nodes) => nodes.map((node) => node.getAttribute("title")));
  assert.ok(titles.includes("2026-10-27 17:53:00"), JSON.stringify(titles.slice(0, 8)));
  const formatted = await page.evaluate(async () => {
    const format = await import("/admin/assets/js/lib/format.js");
    return {
      plain: format.formatDateTime("2026-10-27 16:30:00"),
      iso: format.formatDateTime("2026-10-27T16:30:00Z"),
      offset: format.formatDateTime("2026-10-28T00:30:00+08:00"),
      date: format.formatDateTime("2026-10-27"),
      toInput: format.toLocalInputValue("2026-10-27 16:30:00"),
      fromInput: format.fromLocalInputValue("2026-10-28T00:30"),
      stamp: (await import("/admin/assets/js/lib/list-filters.js")).serverStamp(new Date(2026, 9, 28, 0, 0, 0))
    };
  });
  assert.deepEqual(formatted, {
    plain: "2026-10-28 00:30:00",
    iso: "2026-10-28 00:30:00",
    offset: "2026-10-28 00:30:00",
    date: "2026-10-27",
    toInput: "2026-10-28T00:30",
    fromInput: "2026-10-27 16:30:00",
    stamp: "2026-10-27 16:00:00"
  });
  env.mock.requestLog.length = 0;
  await page.locator('select[name="range_preset"]').selectOption("custom");
  await page.locator('input[aria-label="开始时间"]').fill("2026-10-27T08:00");
  await page.locator('input[aria-label="开始时间"]').dispatchEvent("change");
  await page.waitForFunction(() => location.hash.includes("from="));
  const query = new URLSearchParams(requests("GET", "/admin-api/usage-records").at(-1).query);
  assert.equal(query.get("from"), "2026-10-27 00:00:00", "08:00 in Shanghai is 00:00 UTC");
  assert.deepEqual(errors, []);
  await context.close();
});

test("leaving a settings page with unsaved edits asks first", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/system/settings-smtp" });
  const host = page.locator('.smtp-settings-form [name="host"]');
  await host.waitFor();
  await host.fill("smtp.changed.example");
  await page.locator("#nav a.nav-button", { hasText: "高级设置" }).click();
  const confirm = page.locator("dialog.modal-dialog--confirm");
  await confirm.waitFor();
  await confirm.getByRole("button", { name: "继续编辑" }).click();
  await settle(page);
  assert.match(page.url(), /settings-smtp/);
  assert.equal(await host.inputValue(), "smtp.changed.example", "the edit is kept");
  await page.locator("#nav a.nav-button", { hasText: "高级设置" }).click();
  await page.locator("dialog.modal-dialog--confirm").getByRole("button", { name: "放弃修改并离开" }).click();
  await page.waitForURL(/system-settings/);
  await settle(page);
  // Saved forms do not ask.
  await page.goto(`${env.mock.baseUrl}/admin/#/system/settings-smtp`);
  await settle(page);
  await page.locator('.smtp-settings-form [name="host"]').fill("smtp.saved.example");
  await page.getByRole("button", { name: "保存 SMTP 配置" }).click();
  await settle(page);
  await page.locator("#nav a.nav-button", { hasText: "高级设置" }).click();
  await page.waitForURL(/system-settings/);
  assert.equal(await page.locator("dialog.modal-dialog--confirm").count(), 0);
  assert.deepEqual(errors, []);
  await context.close();
});

test("settings are grouped under 系统设置 and log archive/retention moved to 安全审计", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/system/log-archive-files" });
  await page.waitForURL(/#\/security\/log-archive-files/);
  const system = page.locator('#nav [data-nav-section="system"]');
  assert.equal(await system.locator(".nav-section-label").innerText(), "系统设置");
  await system.locator(".nav-section-toggle").click();
  assert.deepEqual(await system.locator(".nav-group-label").allInnerTexts(), ["运维", "平台与入口", "通知与告警", "数据与高级"]);
  const security = await page.evaluate(async () => (await import("/admin/assets/js/domains.js")).adminDomains.find((domain) => domain.key === "security").tabs);
  assert.ok(security.includes("log-archive-files") && security.includes("log-retention-policies"));
  assert.deepEqual(errors, []);
  await context.close();
});

test("content retention policy is a structured form; certificate type has one control", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/system/content-retention-policy" });
  const form = page.locator(".retention-policy-form");
  await form.waitFor();
  assert.equal(await form.locator("textarea").count(), 0, "no JSON textareas");
  await form.getByRole("button", { name: "添加部门策略" }).click();
  const row = form.locator('[data-scope-row="departments"]').first();
  await row.locator("select").first().selectOption({ index: 2 });
  const departmentId = await row.locator("select").first().inputValue();
  await row.locator('select[name^="departments_mode_"]').selectOption("raw");
  await row.locator('input[name^="departments_days_"]').fill("7");
  await form.getByRole("button", { name: "保存策略" }).click();
  await settle(page);
  const body = requests("PUT", "/admin-api/system/content-retention").at(-1).body;
  assert.deepEqual(body.departments, { [departmentId]: { mode: "raw", days: 7 } });
  assert.deepEqual(body.users, {});
  assert.deepEqual(body.raw_allowed_roles, ["super_admin"]);

  await page.goto(`${env.mock.baseUrl}/admin/#/system/settings-tls-certificates`);
  await settle(page);
  await page.locator(".certificate-type-card").first().waitFor();
  assert.equal(await page.locator('select[name="certificate_request_type"]').count(), 0, "the duplicate type select is gone");
  await page.locator('.certificate-type-card[data-certificate-type="public_ip"]').click();
  assert.equal(await page.locator('input[name="certificate_request_type"]').inputValue(), "public_ip");
  assert.equal(await page.locator('.certificate-type-card[data-certificate-type="public_ip"]').getAttribute("aria-checked"), "true");
  assert.deepEqual(errors, []);
  await context.close();
});

test("knowledge base form maps redaction and retrieval fields onto the stored profiles", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/operations/dashboard" });
  const result = await page.evaluate(async () => {
    const forms = await import("/admin/assets/js/lib/forms.js");
    const row = { id: 3, chunking_profile: JSON.stringify({ chunk_size: 800, redaction: { types: ["phone"] } }), retrieval_profile: { min_vector_score: 0.5, rerank: { model_id: 4, top_n: 20 } } };
    const values = forms.knowledgeBaseFormValues(row);
    return {
      values: { mode: values.redaction_mode, types: values.redaction_types, vector: values.min_vector_score, rerank: values.rerank_model_id, topN: values.rerank_top_n },
      edited: forms.resourcePayload("rag-knowledge-bases", { name: "kb", redaction_mode: "custom", redaction_types: ["email", "id_card"], min_lexical_score: 0.1 }, row),
      off: forms.resourcePayload("rag-knowledge-bases", { name: "kb", redaction_mode: "off" }, {}),
      plain: forms.resourcePayload("rag-knowledge-bases", { name: "kb" }, {})
    };
  });
  assert.deepEqual(result.values, { mode: "custom", types: ["phone"], vector: 0.5, rerank: 4, topN: 20 });
  assert.deepEqual(result.edited.chunking_profile, { chunk_size: 800, redaction: { types: ["email", "id_card"] } }, "other chunking keys are kept");
  assert.deepEqual(result.edited.retrieval_profile, { min_lexical_score: 0.1 }, "cleared fields are removed");
  assert.equal(result.edited.redaction_mode, undefined);
  assert.deepEqual(result.off, { name: "kb", chunking_profile: { redaction: false } });
  assert.deepEqual(result.plain, { name: "kb" }, "an untouched create sends no profiles");
  assert.deepEqual(errors, []);
  await context.close();
});

test("administrators can be filtered by role and department", async () => {
  const { page, errors, context } = await openPage(env);
  await login(page, env.mock.baseUrl, { hash: "#/organization/admin-users" });
  env.mock.requestLog.length = 0;
  await page.locator(".segmented-option", { hasText: "超级管理员" }).click();
  await settle(page);
  assert.match(requests("GET", "/admin-api/admin-users").at(-1).query, /role=super_admin/);
  assert.deepEqual(errors, []);
  await context.close();
});

test("忘记密码 is offered only when SMTP can send the reset mail", async () => {
  const { page, errors, context } = await openPage(env);
  await page.goto(`${env.mock.baseUrl}/admin/`);
  await settle(page);
  assert.equal(await page.getByRole("button", { name: "忘记密码？" }).isVisible(), true);
  assert.equal(await page.locator("#forgot-password-contact").isVisible(), false);
  await page.route("**/admin-api/auth/options", (route) => route.fulfill({ status: 200, contentType: "application/json", body: JSON.stringify({ ok: true, data: { recovery_enabled: false } }) }));
  await page.reload();
  await settle(page);
  await page.locator("#forgot-password-contact").waitFor({ state: "visible" });
  assert.equal(await page.getByRole("button", { name: "忘记密码？" }).count(), 0);
  assert.match(await page.locator("#forgot-password-contact").innerText(), /联系超级管理员/);
  assert.deepEqual(errors, []);
  await context.close();
});
