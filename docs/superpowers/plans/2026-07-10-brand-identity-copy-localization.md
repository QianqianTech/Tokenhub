# 标题与标识文案中文化实施计划

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将“标题与标识”设置的用户可见说明改为中文，同时保持配置键与保存接口不变。

**Architecture:** `SystemSettingsService::description()` 是设置中心返回该说明的唯一来源。通过既有前端设置中心契约测试读取服务源文件，锁定中文说明存在和旧英文说明不存在；不改动任何配置键或接口。

**Tech Stack:** PHP 8.5、现有 PHP 契约测试、Docker Compose。

---

### Task 1: 锁定并中文化品牌设置说明

**Files:**

- Modify: `D:\mysoft\tokenhub\tests\Frontend\AdminSettingsCenterFrontendTest.php`
- Modify: `D:\mysoft\tokenhub\app\Services\SystemSettingsService.php:313`

- [ ] **Step 1: 写入失败的前端契约测试**

在 `AdminSettingsCenterFrontendTest.php` 读取 `SystemSettingsService.php` 后添加：

```php
$settingsServiceSource = file_get_contents(__DIR__ . '/../../app/Services/SystemSettingsService.php') ?: '';
assert_true(
    str_contains($settingsServiceSource, "'brand_identity' => '管理后台和运营大屏的标题、Logo 与浏览器图标设置'"),
    'Brand identity settings should use a Chinese user-facing description.'
);
assert_true(
    !str_contains($settingsServiceSource, 'Admin and screen title/logo settings'),
    'Brand identity settings should not retain the English user-facing description.'
);
```

- [ ] **Step 2: 运行测试并确认失败**

运行 `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`。

预期：命令以非零退出，报出“Brand identity settings should use a Chinese user-facing description.”；失败原因是服务仍返回旧英文文案。

- [ ] **Step 3: 进行最小实现**

在 `SystemSettingsService::description()` 的 `brand_identity` 分支中替换一行：

```php
'brand_identity' => '管理后台和运营大屏的标题、Logo 与浏览器图标设置',
```

不得修改 `brand_identity`、`product_name`、`admin_title`、`screen_title`、`logo_url`、`favicon_url` 或任何接口路由。

- [ ] **Step 4: 运行聚焦测试并确认通过**

运行 `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`。

预期：退出码为 `0`，无未捕获异常。

- [ ] **Step 5: 检查语法与改动范围**

运行 `docker compose exec -T php php -l app/Services/SystemSettingsService.php` 与 `node --check public/admin/assets/js/table.js`。

预期：两项语法检查均成功；`git diff --check` 在 Git 可用时无空白错误。

- [ ] **Step 6: 提交（仅当 Git 元数据恢复可用且工作区没有无关改动时）**

执行 `git add app/Services/SystemSettingsService.php tests/Frontend/AdminSettingsCenterFrontendTest.php docs/superpowers/specs/2026-07-10-brand-identity-copy-localization-design.md docs/superpowers/plans/2026-07-10-brand-identity-copy-localization.md`，随后执行 `git commit -m "fix: localize brand identity settings copy"`。

当前 Git 无法识别该工作目录的 `.git` 元数据，因此不得强行提交或清理；仅在恢复正常后执行此步骤。
