# WAF 总览安全驾驶舱 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将 WAF 总览改造成能说明防护状态、能力、威胁热点和处置入口的安全驾驶舱。

**Architecture:** 保持 `GET /admin-api/waf/overview` 的现有响应契约。`table.js` 将该响应转换为页面级组件，`app.css` 提供命名空间样式；页面通过既有 `tokenhub-admin-navigate` 自定义事件跳转至规则、攻击记录和 IP 封禁模块。

**Tech Stack:** 原生 ES modules、既有 DOM 工具函数、CSS Grid、PHP 前端契约测试、Docker PHP 测试运行器。

---

### Task 1: 锁定总览驾驶舱的前端契约

**Files:**
- Modify: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`
- Test: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`

- [x] **Step 1: 写入失败的驾驶舱契约测试**

在现有 WAF 总览断言之后，加入下列断言，锁定页面结构、空态、失败重试和既有跳转入口：

```php
assert_true(str_contains($table, 'renderWafOverviewCockpit'), 'WAF overview should render a dedicated security cockpit.');
assert_true(str_contains($table, '边缘防护已发布'), 'WAF overview should explain published edge protection.');
assert_true(str_contains($table, '启用规则'), 'WAF overview should present enabled rule coverage.');
assert_true(str_contains($table, '防护能力'), 'WAF overview should explain its protection capabilities.');
assert_true(str_contains($table, '威胁热点'), 'WAF overview should render rule threat rankings.');
assert_true(str_contains($table, '近期攻击'), 'WAF overview should render recent attack events.');
assert_true(str_contains($table, '当前统计窗口内未发现攻击命中'), 'WAF overview should provide a safe empty state.');
assert_true(str_contains($table, '重新加载 WAF 总览'), 'WAF overview should offer a retry action on load failure.');
assert_true(str_contains($table, 'ragNavigate("waf-rules")'), 'WAF overview should link to WAF rules.');
assert_true(str_contains($table, 'ragNavigate("waf-events")'), 'WAF overview should link to WAF attack records.');
assert_true(str_contains($table, 'ragNavigate("waf-ip-bans")'), 'WAF overview should link to IP ban management.');
assert_true(!str_contains($table, 'el("h2", { text: "WAF 总览" }),\n    renderDataDetails(result.data || {})'), 'WAF overview should not expose the raw overview object.');
```

- [x] **Step 2: 在 Docker 中确认测试按预期失败**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`

Expected: 断言因 `renderWafOverviewCockpit` 或驾驶舱文案缺失而失败；不得因 PHP 语法或容器启动错误失败。

### Task 2: 实现 WAF 安全驾驶舱渲染

**Files:**
- Modify: `public/admin/assets/js/table.js:857-864`
- Test: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`

- [x] **Step 1: 替换原始对象渲染，保留单一 API 请求与失败重试**

将 `renderWafOverviewPage` 改为先建立 `section.waf-overview-cockpit` 容器，再调用内部 `load`。`load` 清空容器、请求 `/admin-api/waf/overview`、更新 request id，并调用 `renderWafOverviewCockpit`；捕获异常后只渲染错误状态和 `button("重新加载 WAF 总览", load, "secondary")`。

```js
async function renderWafOverviewPage(content) {
  clear(content);
  const cockpit = el("section", { className: "waf-overview-cockpit stack" });
  content.append(cockpit);
  const load = async () => {
    clear(cockpit);
    try {
      const result = await api("/admin-api/waf/overview");
      document.getElementById("request-id").textContent = result.requestId || "";
      cockpit.append(renderWafOverviewCockpit(result.data || {}));
    } catch (error) {
      cockpit.append(statusMessage(adminErrorMessage(error, "WAF 总览加载失败"), "error"));
      cockpit.append(button("重新加载 WAF 总览", load, "secondary"));
    }
  };
  await load();
}
```

- [x] **Step 2: 实现状态、指标、能力和快捷入口组件**

新增 `renderWafOverviewCockpit(data)`、`wafPublicationCopy(status)`、`wafMetricCard(label, value, description)` 与 `wafNavigateButton(label, page, variant)`。状态映射必须至少包含 `published -> 边缘防护已发布`、`unpublished -> 边缘防护待发布` 和未知状态的“边缘防护状态异常”；四项指标应使用 `active_rule_count`、`active_ban_count`、`total_events`、`blocked_events`。

能力区固定展示“规则匹配拦截”“敏感路径保护”“异常来源 IP 封禁”“攻击留痕与审计”，每项使用简短说明。快捷入口的按钮回调必须是 `ragNavigate("waf-rules")`、`ragNavigate("waf-events")` 与 `ragNavigate("waf-ip-bans")`，不得发起新的后端请求或直接执行高风险动作。

- [x] **Step 3: 实现热点与近期攻击的可读视图**

在 `renderWafOverviewCockpit` 内根据 `top_rules` 渲染最多五项规则热点，使用最大命中数计算进度条宽度；没有规则时渲染“当前统计窗口内未发现攻击命中”。根据 `recent_events` 渲染最多五项攻击事件，显示时间、来源 IP、路径、规则、动作和处置状态，并提供“查看全部攻击记录”按钮。动作文字至少映射 `block -> 已拦截`、`log -> 观察中`，未知值回退为“已记录”。

- [x] **Step 4: 在 Docker 中确认契约测试通过**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`

Expected: 退出码为 0，且没有 assertion failure。

### Task 3: 添加驾驶舱响应式样式与回归验证

**Files:**
- Modify: `public/admin/assets/css/app.css`
- Modify: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`
- Test: `tests/Frontend/AdminWafControlPlaneFrontendTest.php`

- [x] **Step 1: 为驾驶舱添加命名空间样式**

在 `app.css` 末尾添加 `waf-overview-cockpit` 命名空间。桌面端使用 `waf-cockpit-hero`、`waf-cockpit-metrics`、`waf-cockpit-grid` 和 `waf-cockpit-events` Grid；热点进度条使用 `.waf-threat-bar` / `.waf-threat-bar-fill`；状态使用 `.is-published`、`.is-pending`、`.is-problem`，同时保留文字标签。窄屏 media query 将所有驾驶舱网格切换为单列，并允许近期攻击横向滚动而不截断路径文本。

- [x] **Step 2: 扩展 CSS 存在性断言**

在测试中读取 CSS：

```php
$css = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';
assert_true(str_contains($css, '.waf-overview-cockpit'), 'WAF overview should have dedicated cockpit styles.');
assert_true(str_contains($css, '.waf-cockpit-metrics'), 'WAF overview should have responsive metric layout styles.');
assert_true(str_contains($css, '.waf-threat-bar-fill'), 'WAF overview should style threat ranking bars.');
```

- [ ] **Step 3: 运行聚焦回归验证**

Run: `docker compose exec -T php php tests/Frontend/AdminWafControlPlaneFrontendTest.php`

Expected: 退出码为 0。

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: 退出码为 0，确认 WAF 导航与安全前端契约未回归。

- [ ] **Step 4: 运行语法与页面验证**

Run: `node --check public/admin/assets/js/table.js`

Expected: 退出码为 0。

在已登录管理端访问 `http://localhost:8080/admin/#/waf/waf-overview`，确认桌面端驾驶舱、无攻击空态和窄屏单列布局均不展示原始 JSON 对象。

## 计划自检

- 规格中的防护态势、指标、能力、威胁热点、近期攻击、处置入口、空态、失败重试和响应式布局均有对应任务。
- 未引入后端接口、环境配置或高风险直接操作。
- 所有实现步骤均先有可观察的测试断言与明确验证命令。
