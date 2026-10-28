# WAF Web Control Plane Completion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 完成现有 WAF 后端能力在管理端 Web 的规则、版本、事件处置与总览闭环。

**Architecture:** 保持现有 PHP 路由和服务契约不变，在 `table.js` 中为 WAF 规则、发布版本和事件处置增加专用渲染器；`app.css` 仅添加 WAF 命名空间样式。所有行为由现有 Docker PHP 契约测试和 JavaScript 语法检查验证。

**Tech Stack:** 原生 ES modules、TokenHub DOM helpers、CSS Grid、PHP source-contract tests、Docker PHP runner。

---

### Task 1: 锁定待补全的 WAF Web 契约

**Files:**
- Modify: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`
- Test: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`

- [ ] **Step 1: 添加失败的前端契约断言**

```php
assert_true(str_contains($table, 'renderWafVersionsPage'), 'WAF versions should use a dedicated page.');
assert_true(str_contains($table, '/admin-api/waf/versions'), 'WAF versions page should use the dedicated endpoint.');
assert_true(str_contains($table, 'renderWafVersionDiff'), 'WAF version differences should be structured.');
assert_true(str_contains($table, 'confirmWafRollback'), 'WAF rollback should require a dedicated confirmation flow.');
assert_true(str_contains($table, 'showWafRuleEditor'), 'WAF rules should use a guided editor.');
assert_true(str_contains($table, 'wafMatcherFromEditor'), 'WAF rule editor should build matcher payloads.');
assert_true(str_contains($table, '预检当前规则'), 'WAF rules should support candidate preflight.');
assert_true(str_contains($table, 'showWafDispositionDialog'), 'WAF event handling should collect a note.');
assert_true(str_contains($table, 'showWafAutoDispositionDialog'), 'WAF automatic disposition should be configurable.');
assert_true(str_contains($table, 'event.disposition_status === "open"'), 'WAF overview should count open events as pending.');
```

- [ ] **Step 2: 运行测试并确认按预期失败**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`

Expected: FAIL at `renderWafVersionsPage` or the first newly added contract; no PHP parse error.

### Task 2: 修正总览待处置状态并补齐事件操作

**Files:**
- Modify: `public/admin/assets/js/table.js:873-980`
- Modify: `public/admin/assets/js/table.js:2440-2590`
- Test: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`

- [ ] **Step 1: 将 open 状态计为待处置**

```js
const unhandledBlocks = recentEvents.filter((event) => event.action === "block" && (!event.disposition_status || event.disposition_status === "open")).length;
```

- [ ] **Step 2: 用处置弹窗替换固定备注动作**

新增 `showWafDispositionDialog(row, action, onRefresh)`：构造事件摘要、`textarea[name="note"]`、动作说明和提交按钮。提交时空备注显示“请填写处置说明”；`ban_ip`、`unban_ip` 先执行 `window.confirm`，再调用：

```js
await api(`/admin-api/waf/events/${row.id}/handle`, {
  method: "POST",
  body: JSON.stringify({ action, note })
});
```

四个行操作全部调用该弹窗，不再调用固定备注版 `handleWafEvent`。

- [ ] **Step 3: 添加参数化自动处置弹窗**

新增 `showWafAutoDispositionDialog(onRefresh)`。打开时读取 `/admin-api/system/settings-center`，用 `alert_thresholds.waf_spike_count`、`waf_spike_window_minutes` 预填两个数字字段；提交时调用：

```js
await api("/admin-api/waf/auto-disposition", {
  method: "POST",
  body: JSON.stringify({ threshold: Number(threshold), window_minutes: Number(windowMinutes) })
});
```

阈值必须大于 0，执行前必须确认系统级封禁影响。结果显示阈值、窗口、封禁数量和 IP 列表。

- [ ] **Step 4: 运行聚焦测试**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`

Expected: 后续任务相关断言仍失败，但总览和事件相关断言通过。

### Task 3: 建立专用发布版本控制面

**Files:**
- Modify: `public/admin/assets/js/table.js:310-370`
- Modify: `public/admin/assets/js/table.js:980-1140`
- Test: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`

- [ ] **Step 1: 注册版本专用渲染器**

```js
if (resourceKey === "waf-rule-versions") {
  await renderWafVersionsPage(content);
  return;
}
```

- [ ] **Step 2: 使用专用版本端点渲染版本列表**

`renderWafVersionsPage(content)` 请求 `/admin-api/waf/versions`，识别 `status === "published"` 的当前版本，渲染版本码、中文状态、发布人、时间以及“查看差异”“回滚到此版本”操作。加载失败显示错误和“重新加载版本”按钮。

- [ ] **Step 3: 结构化渲染版本差异**

`renderWafVersionDiff(data)` 分别渲染 `data.rules` 与 `data.caddyfile_fragment`。每一行以 `+ `、`- ` 前缀映射到 `is-added`、`is-removed`，其余映射到 `is-context`：

```js
function wafDiffLine(line) {
  const tone = line.startsWith("+ ") ? "is-added" : line.startsWith("- ") ? "is-removed" : "is-context";
  return el("code", { className: `waf-diff-line ${tone}`, text: line });
}
```

- [ ] **Step 4: 添加回滚确认与结构化结果**

`confirmWafRollback(version, currentVersion, reload)` 显示当前/目标版本和影响说明。管理员点击确认按钮后再调用 `/admin-api/waf/rollback`；复用发布结果组件展示 `status`、`validated`、`reloaded` 和错误信息，并刷新版本列表。

- [ ] **Step 5: 运行聚焦测试**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`

Expected: 仅规则编辑器相关的新断言仍失败。

### Task 4: 增加引导式 WAF 规则编辑与候选预检

**Files:**
- Modify: `public/admin/assets/js/table.js:1040-1080`
- Modify: `public/admin/assets/js/table.js:1396-1560`
- Modify: `public/admin/assets/js/table.js:1635-1660`
- Test: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`

- [ ] **Step 1: 将新增、编辑和模板预填统一到专用编辑器**

规则页新增按钮调用 `showWafRuleEditor(null, onSaved)`；规则行编辑按钮在 `resourceKey === "waf-rules"` 时调用 `showWafRuleEditor(row, onRefresh)`；模板调用 `showWafRuleEditor(templateRuleValues(template), onSaved)`。

- [ ] **Step 2: 实现 Matcher 字段与双向转换**

`wafMatcherEditorValues(rule)` 解码现有 matcher；`wafMatcherFromEditor(form)` 根据 `matcher_type` 生成以下对象之一：

```js
{ type: "ip", paths, allow, deny }
{ type: "path", values }
{ type: "method", deny }
{ type: "query", keys, patterns }
{ type: "header", headers }
{ type: "body_size", max_bytes }
{ type: "security_headers", headers }
```

列表字段按换行拆分并去空；请求头和安全响应头按每行第一个冒号拆为键值，只有名称的行保留为数组项。

- [ ] **Step 3: 实现候选预检和保存门禁**

编辑器维护 `preflightPassed`。任何输入变化后将其重置为 false；“预检当前规则”调用：

```js
const candidate = wafRulePayloadFromEditor(form);
const result = await api("/admin-api/waf/preview", {
  method: "POST",
  body: JSON.stringify({ rules: [candidate] })
});
preflightPassed = result.data?.status === "valid";
```

保存按钮在预检未通过时显示“请先完成规则预检”；通过后根据是否存在 `row.id` 使用 POST 或 PUT 保存。高级 JSON 预览使用只读 textarea 展示最终 matcher。

- [ ] **Step 4: 运行聚焦测试和语法检查**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`

Expected: PASS.

Run: `node --check public/admin/assets/js/table.js`

Expected: exit 0.

### Task 5: 补充 WAF 控制面样式并执行回归验证

**Files:**
- Modify: `public/admin/assets/css/app.css`
- Modify: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`
- Test: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`

- [ ] **Step 1: 先添加失败的样式契约**

```php
assert_true(str_contains($css, '.waf-rule-editor'), 'WAF guided rule editor should have dedicated styles.');
assert_true(str_contains($css, '.waf-version-list'), 'WAF version control plane should have dedicated styles.');
assert_true(str_contains($css, '.waf-diff-line.is-added'), 'WAF diff should distinguish added lines.');
assert_true(str_contains($css, '.waf-disposition-dialog'), 'WAF disposition dialog should have dedicated styles.');
```

- [ ] **Step 2: 添加命名空间样式**

为 `.waf-rule-editor`、`.waf-version-list`、`.waf-version-card`、`.waf-diff-view`、`.waf-diff-line`、`.waf-disposition-dialog`、`.waf-auto-disposition-dialog` 添加布局、状态和窄屏单列样式。新增行使用绿色、删除行使用红色，同时保留 `+`/`-` 文本，不仅依赖颜色表达含义。

- [ ] **Step 3: 运行 WAF 聚焦回归**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`

Expected: PASS.

Run: `docker compose exec -T php php tests/Services/WafServiceTest.php`

Expected: PASS.

Run: `docker compose exec -T php php tests/Services/LoginIpBanServiceTest.php`

Expected: PASS.

Run: `docker compose exec -T php php tests/Frontend/AdminDomainNavigationFrontendTest.php`

Expected: PASS.

- [ ] **Step 4: 浏览器验收**

在已登录的 `http://localhost:8080/admin/#/waf/waf-overview` 中检查总览、规则、版本、攻击记录四个页面；确认窄屏布局、预检门禁、回滚确认和处置备注。若管理端未登录，记录为浏览器验收阻塞，不以此覆盖自动化测试结果。

## 提交说明

当前工作区 Git 元数据无法识别；若执行阶段恢复可用，则在每个 Task 通过后分别提交。否则保留未提交改动并在交付说明中明确列出。
