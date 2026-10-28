# API 密钥操作图标 Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** 将 API 密钥列表操作列的乱码替换为可访问的编辑和删除 SVG 图标按钮。

**Architecture:** 在 `table.js` 中增加一个只负责创建 SVG 的本地辅助函数；现有按钮、事件处理、确认弹窗、删除请求和刷新逻辑保持不变。前端契约测试只验证源码中的稳定结构，不依赖浏览器或网络。

**Tech Stack:** 原生 ES 模块 DOM API、内联 SVG、PHP 前端契约测试、Docker Compose PHP 运行时。

---

### Task 1: API 密钥操作图标

**Files:**
- Modify: `tests/Frontend/AdminApiKeyUsageFrontendTest.php`
- Modify: `public/admin/assets/js/table.js`
- Verify: `tests/Frontend/AdminApiKeyUsageFrontendTest.php`

- [ ] **Step 1: 写入失败的前端契约测试**

在 `tests/Frontend/AdminApiKeyUsageFrontendTest.php` 末尾添加：

```php
assert_true(
    str_contains($tableSource, 'function apiKeyActionIcon') &&
    str_contains($tableSource, 'const editButton = button("",') &&
    str_contains($tableSource, 'const deleteButton = button("",') &&
    str_contains($tableSource, 'editButton.append(apiKeyActionIcon') &&
    str_contains($tableSource, 'deleteButton.append(apiKeyActionIcon'),
    'API key row actions should use accessible SVG icon buttons instead of encoded text glyphs.'
);
```

- [ ] **Step 2: 运行测试并确认失败**

Run: `docker compose exec -T php php tests/Frontend/AdminApiKeyUsageFrontendTest.php`

Expected: non-zero exit with `API key row actions should use accessible SVG icon buttons instead of encoded text glyphs.`

- [ ] **Step 3: 实现最小 SVG 辅助函数与按钮内容**

在 `public/admin/assets/js/table.js` 的 API 密钥页面辅助函数附近加入：

```js
function apiKeyActionIcon(paths) {
  const svg = document.createElementNS("http://www.w3.org/2000/svg", "svg");
  svg.setAttribute("viewBox", "0 0 24 24");
  svg.setAttribute("fill", "none");
  svg.setAttribute("stroke", "currentColor");
  svg.setAttribute("stroke-width", "2");
  svg.setAttribute("stroke-linecap", "round");
  svg.setAttribute("stroke-linejoin", "round");
  svg.setAttribute("aria-hidden", "true");
  for (const d of paths) {
    const path = document.createElementNS("http://www.w3.org/2000/svg", "path");
    path.setAttribute("d", d);
    svg.append(path);
  }
  return svg;
}
```

将两处乱码按钮标签改为空字符串，并分别追加铅笔路径 `M12 20h9`、`M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z` 和垃圾桶路径 `M3 6h18`、`M8 6V4h8v2`、`M19 6l-1 14H6L5 6`、`M10 11v5M14 11v5`。保留既有 `title`、`ariaLabel`、删除确认和请求逻辑。

- [ ] **Step 4: 验证语法与聚焦测试通过**

Run: `C:\Users\leo\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe --check public/admin/assets/js/table.js`

Expected: exit code 0 without output.

Run: `docker compose exec -T php php tests/Frontend/AdminApiKeyUsageFrontendTest.php`

Expected: exit code 0.

- [ ] **Step 5: 运行关联验证**

Run: `docker compose exec -T php php tests/Frontend/AdminLayoutOverflowTest.php`

Expected: exit code 0.

Run: `docker compose exec -T php php bin/run-tests.php`

Expected: exit code 0 and `All tests passed`.

- [ ] **Step 6: 检查改动范围**

Run: `git status --short`

Expected: only this task's JavaScript, front-end test, specification and plan files are listed. If the directory is not a Git worktree, report that fact and inspect the named files directly instead; do not attempt a commit.
