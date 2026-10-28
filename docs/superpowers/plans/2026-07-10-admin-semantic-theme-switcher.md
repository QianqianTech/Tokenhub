# Admin Semantic Theme Switcher Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the A/B/C admin appearance selector with persisted Deep, Light, and Auto choices, with Auto following the operating-system color preference.

**Architecture:** The selector stores the semantic preference (`dark`, `light`, or `auto`) in the existing cookie. JavaScript resolves Auto through one `prefers-color-scheme: dark` media query and applies only a semantic `theme-dark` or `theme-light` class to the shell. CSS continues to provide all component colors through variables, so the layout remains unchanged.

**Tech Stack:** Static HTML, vanilla ES modules, CSS custom properties, PHP frontend contract tests, Docker PHP runner.

---

### Task 1: Lock the semantic theme contract in the focused frontend test

**Files:**
- Modify: `tests/Frontend/AdminFrontendSecurityTest.php:38-65`
- Test: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] **Step 1: Replace the legacy A/B/C assertions with the desired semantic contract**

```php
assert_true(str_contains($adminHtml, 'data-theme-choice="dark"'), 'Admin theme switcher should expose the deep theme.');
assert_true(str_contains($adminHtml, 'data-theme-choice="light"'), 'Admin theme switcher should expose the light theme.');
assert_true(str_contains($adminHtml, 'data-theme-choice="auto"'), 'Admin theme switcher should expose the adaptive theme.');
assert_true(str_contains($adminHtml, '深色'), 'Admin theme switcher should label the deep theme in Chinese.');
assert_true(str_contains($adminHtml, '浅色'), 'Admin theme switcher should label the light theme in Chinese.');
assert_true(str_contains($adminHtml, '自适应'), 'Admin theme switcher should label the adaptive theme in Chinese.');
assert_true(str_contains($appJs, 'prefers-color-scheme: dark'), 'Admin auto theme should follow the operating-system color preference.');
assert_true(str_contains($appJs, 'theme-dark'), 'Admin app should resolve the deep theme through a semantic class.');
assert_true(str_contains($appJs, 'theme-light'), 'Admin app should resolve the light theme through a semantic class.');
assert_true(str_contains($adminCss, '.theme-light'), 'Admin CSS should define the light theme.');
assert_true(str_contains($adminCss, '.theme-dark'), 'Admin CSS should define the deep theme.');
assert_true(!str_contains($adminCss, '.theme-a'), 'Admin CSS should not retain the legacy theme A selector.');
assert_true(!str_contains($adminCss, '.theme-b'), 'Admin CSS should not retain the legacy theme B selector.');
assert_true(!str_contains($adminCss, '.theme-c'), 'Admin CSS should not retain the legacy theme C selector.');
```

- [ ] **Step 2: Run the focused test and verify RED**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: FAIL with an assertion that the current admin theme switcher lacks one of the semantic values or semantic CSS selectors.

### Task 2: Implement the semantic selector and system-adaptive resolution

**Files:**
- Modify: `public/admin/index.html:7-10,27-30,43`
- Modify: `public/admin/assets/js/app.js:27-92`
- Modify: `public/admin/assets/css/app.css:1774-1825,1968-1980,2204-2212`
- Test: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] **Step 1: Replace the initial class and A/B/C buttons with semantic labels**

```html
<main id="app" class="app-shell theme-light is-login">
...
<div id="theme-switcher" class="theme-switcher" aria-label="界面主题">
  <button type="button" class="theme-choice" data-theme-choice="dark" aria-pressed="false" title="深色主题">深色</button>
  <button type="button" class="theme-choice" data-theme-choice="light" aria-pressed="false" title="浅色主题">浅色</button>
  <button type="button" class="theme-choice active" data-theme-choice="auto" aria-pressed="true" title="跟随系统主题">自适应</button>
</div>
```

Update the CSS and module query-string versions together to `20260710-semantic-themes-v1` so deployed browsers load the changed static assets.

- [ ] **Step 2: Resolve selections and legacy cookies through semantic classes**

```js
const ADMIN_THEMES = ["dark", "light", "auto"];
const ADMIN_THEME_MEDIA_QUERY = window.matchMedia("(prefers-color-scheme: dark)");

function normalizeAdminTheme(value) {
  if (value === "a" || value === "b") return "light";
  if (value === "c") return "dark";
  return ADMIN_THEMES.includes(value) ? value : "auto";
}

function resolvedAdminTheme(theme) {
  return theme === "auto" && ADMIN_THEME_MEDIA_QUERY.matches ? "dark" : theme === "auto" ? "light" : theme;
}

function applyAdminTheme(theme, persist = false) {
  const selected = normalizeAdminTheme(theme);
  const resolved = resolvedAdminTheme(selected);
  const shell = $("app");
  if (shell) {
    shell.classList.toggle("theme-dark", resolved === "dark");
    shell.classList.toggle("theme-light", resolved === "light");
  }
  document.querySelectorAll("[data-theme-choice]").forEach((control) => {
    const active = control.dataset.themeChoice === selected;
    control.classList.toggle("active", active);
    control.setAttribute("aria-pressed", active ? "true" : "false");
  });
  if (persist) writeThemeCookie(selected);
  return selected;
}
```

In `adminInitThemeSwitcher()`, keep the selected semantic preference in a local variable, apply it on boot and after each click, then register a `change` listener on `ADMIN_THEME_MEDIA_QUERY` that reapplies only when that variable is `auto`.

- [ ] **Step 3: Rename CSS selectors without changing token values**

```css
.app-shell.theme-light { /* existing theme-a token values */ }
.app-shell.theme-dark { /* existing theme-c token values */ }
.app-shell.theme-dark .login-panel,
.app-shell.theme-dark .metric,
.app-shell.theme-dark .panel,
.app-shell.theme-dark .filters,
.app-shell.theme-dark table,
.app-shell.theme-dark .modal-dialog { box-shadow: none; }
.app-shell.theme-dark .nav-search input { background: #0f172a; }
```

Remove the old compact-density `theme-b` token block and its `.nav-button` override. Preserve existing shared variables and component rules.

- [ ] **Step 4: Run the focused test and verify GREEN**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: PASS with no frontend contract assertion failures.

### Task 3: Validate the production assets

**Files:**
- Verify: `public/admin/index.html`
- Verify: `public/admin/assets/js/app.js`
- Verify: `public/admin/assets/css/app.css`
- Verify: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] **Step 1: Check JavaScript syntax**

Run: `node --check public/admin/assets/js/app.js`

Expected: exit code 0 with no output.

- [ ] **Step 2: Re-run the focused frontend contract**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: PASS.

- [ ] **Step 3: Review the changed surface**

Run: `rg -n "theme-a|theme-b|theme-c|data-theme-choice=\"[abc]\"" public/admin/index.html public/admin/assets/js/app.js public/admin/assets/css/app.css tests/Frontend/AdminFrontendSecurityTest.php`

Expected: no matches, confirming the old A/B/C implementation is fully replaced.

- [ ] **Step 4: Commit**

Run: `git add public/admin/index.html public/admin/assets/js/app.js public/admin/assets/css/app.css tests/Frontend/AdminFrontendSecurityTest.php docs/superpowers/specs/2026-07-10-admin-semantic-theme-switcher-design.md docs/superpowers/plans/2026-07-10-admin-semantic-theme-switcher.md && git commit -m "feat: add semantic admin themes"`

Expected: a commit containing only the semantic theme implementation and its documents. Skip the commit and report the exact repository-state blocker if Git remains unavailable.
