# 高级设置字段中文化实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将高级设置中已知系统配置的英文字段路径改为中文展示，且不改变任何保存数据或接口。

**Architecture:** 前端在 `SYSTEM_SETTING_FIELD_LABELS` 中维护按“设置键 + 字段路径”索引的中文文案。卡片摘要与弹窗字段配置都调用同一个标签解析函数；未知路径继续回退到原始路径。

**Tech Stack:** 原生 JavaScript 管理端、PHP 前端契约测试、Docker PHP 测试运行时。

---

### Task 1: 用契约测试定义中文标签行为

**Files:**
- Modify: `tests/Frontend/AdminSettingsCenterFrontendTest.php`
- Test: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: 写入失败的契约断言**

在现有高级设置断言附近加入：

```php
assert_true(str_contains($tableSource, 'days: "保存天数"'), 'Content retention user policies should show a Chinese retention-days label.');
assert_true(str_contains($tableSource, 'systemSettingSummaryLabel'), 'Advanced settings summary should use the shared field-label resolver.');
assert_true(str_contains($tableSource, 'systemSettingFieldLabel'), 'Advanced settings editor should use the shared Chinese field-label resolver.');
```

- [ ] **Step 2: 运行测试，确认当前实现缺少中文标签解析**

运行：`docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

预期：失败，原因是 `systemSettingFieldLabel` 尚不存在，且内容留存嵌套字段没有中文标签映射。

### Task 2: 最小化实现共享字段标签解析

**Files:**
- Modify: `public/admin/assets/js/table.js`
- Test: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: 扩充内置内容留存字段映射**

为 `content_retention.policy` 增加嵌套字段标签，包含 `days: "保存天数"`，并保留现有顶层字段标签。

- [ ] **Step 2: 新增共享标签解析函数**

实现 `systemSettingFieldLabel(row, path)`：从配置键对应的映射读取路径中第一个匹配的字段名；没有匹配时返回 `path.join(" / ")`。对数组索引跳过纯数字路径段，以便 `users / 2 / days` 显示为“用户策略 / 保存天数”。

- [ ] **Step 3: 让摘要和编辑表单复用解析结果**

将 `systemSettingSummaryLabel()` 委托给共享解析函数；将 `systemSettingFieldConfig()` 接收当前配置行或预先解析的标签，确保弹窗字段与摘要一致。不要改 `systemSettingEditableFields()`、`setSystemSettingFieldValue()` 或 API 请求载荷。

- [ ] **Step 4: 运行测试与语法检查**

运行：

```powershell
node --check public/admin/assets/js/table.js
docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php
```

预期：两项命令退出码均为 0。

### Task 3: 复核回退与回归范围

**Files:**
- Verify: `public/admin/assets/js/table.js`
- Verify: `tests/Frontend/AdminFrontendSecurityTest.php`
- Verify: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: 检查差异范围**

运行：`git diff -- public/admin/assets/js/table.js tests/Frontend/AdminSettingsCenterFrontendTest.php docs/development-tasks/2026-07-10-advanced-settings-chinese-labels-design.md docs/development-tasks/2026-07-10-advanced-settings-chinese-labels-plan.md`

预期：仅包含字段展示映射、契约测试与本次说明文档；不存在接口或保存逻辑改动。

- [ ] **Step 2: 运行相邻前端安全契约测试**

运行：`docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

预期：退出码为 0，确认高级设置入口与其现有展示契约未回退。
