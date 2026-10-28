# Advanced Settings Clarity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn the current advanced-settings fallback into an understandable custom-configuration page that hides managed and demo-only records.

**Architecture:** Keep `/admin-api/system-settings` and its CRUD behavior unchanged. Add a frontend-only classifier in `table.js`: settings owned by the typed Settings Center, the dedicated content-retention policy, and demo marker keys are excluded before the existing card renderer runs. The remaining user-created records retain the typed-field editor.

**Tech Stack:** PHP frontend contract tests, static ES modules, existing TokenHub admin DOM helpers.

---

## File Structure

- Modify `public/admin/assets/js/table.js`: classify custom settings, filter the fetched list, and replace the ambiguous UI copy.
- Modify `tests/Frontend/AdminSettingsCenterFrontendTest.php`: lock the custom-only behavior and user-facing page copy into the existing frontend contract suite.

### Task 1: Capture the regression in the frontend contract test

**Files:**

- Modify: `tests/Frontend/AdminSettingsCenterFrontendTest.php:42-53`
- Test: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: Add a failing source-contract assertion set after the current advanced-settings assertions**

```php
assert_true(str_contains($tableSource, 'MANAGED_SYSTEM_SETTING_KEYS'), 'Custom settings page should identify settings that are already managed in Settings Center.');
assert_true(str_contains($tableSource, 'content_retention.policy'), 'Custom settings page should hide the dedicated content-retention policy.');
assert_true(str_contains($tableSource, 'demo-content-retention.'), 'Custom settings page should hide demo seed markers.');
assert_true(str_contains($tableSource, 'filter(isCustomSystemSetting)'), 'Custom settings page should filter non-custom rows before cards render.');
assert_true(str_contains($tableSource, '自定义系统配置'), 'Custom settings page should explain its purpose in the title.');
assert_true(str_contains($tableSource, '设置中心'), 'Custom settings page should direct normal configuration to Settings Center.');
assert_true(str_contains($tableSource, '新增自定义配置'), 'Custom settings page should label the creation action as custom configuration.');
```

- [ ] **Step 2: Run the focused test to verify the new contract fails**

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: failure reporting the missing managed-key classification or the new page copy.

### Task 2: Filter managed and demo-only rows before rendering

**Files:**

- Modify: `public/admin/assets/js/table.js:155-160`
- Modify: `public/admin/assets/js/table.js:3264-3297`
- Test: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: Add the page-owned key set and classifier below `SETTINGS_SECTION_CATEGORIES`**

```js
const MANAGED_SYSTEM_SETTING_KEYS = new Set([
  ...Object.values(SETTINGS_SECTION_PAGES).map((page) => page.section),
  "content_retention.policy"
]);

function isCustomSystemSetting(row) {
  const key = String(row?.setting_key || "").trim();
  return key !== ""
    && !MANAGED_SYSTEM_SETTING_KEYS.has(key)
    && !key.startsWith("demo-content-retention.");
}
```

- [ ] **Step 2: Replace the system-settings heading, guidance, and row rendering**

```js
const customRows = rows.filter(isCustomSystemSetting);
if (customRows.length === 0) {
  list.append(statusMessage("暂无自定义配置。标题、证书、安全、RAG、留存和告警等常用功能请在“设置中心”中管理。"));
  return;
}
for (const row of customRows) {
  list.append(systemSettingCard(row, refresh));
}
```

```js
el("h2", { text: "自定义系统配置" }),
el("p", { className: "notice", text: "用于技术人员创建应用扩展配置。日常功能设置请前往“设置中心”；这里不会显示系统自动维护的配置和演示数据。" }),
button("新增自定义配置", () => showCreateSystemSettingModal(refresh))
```

- [ ] **Step 3: Make create and edit labels match the page purpose**

```js
{ name: "setting_key", label: "自定义配置键" },
showModal(`编辑自定义配置：${row.setting_key || "未命名"}`, form);
showModal("新增自定义配置", form);
```

- [ ] **Step 4: Run the focused test to verify it passes**

Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

Expected: exit code 0 and no assertion failures.

### Task 3: Run related frontend regression coverage

**Files:**

- Verify: `tests/Frontend/AdminFrontendSecurityTest.php`
- Verify: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: Run the existing security/frontend contract test**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: exit code 0 and no assertion failures.

- [ ] **Step 2: Review the changed source against the approved design**

Check that only custom records render, the page exposes a clear purpose, and all standard settings remain reachable through the existing `设置中心` resource without changing its API contract.

- [ ] **Step 3: Check patch hygiene**

Run: `git diff --check`

Expected: no whitespace errors. If the current checkout lacks Git metadata, record that constraint and rely on the two focused test commands plus a direct source review.
