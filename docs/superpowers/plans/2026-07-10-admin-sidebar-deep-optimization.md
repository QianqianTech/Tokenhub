# Admin Sidebar Deep Optimization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a reliable expanded/compact admin sidebar with stable search, collapsible sections, preserved scroll, recent navigation, and a true mobile drawer.

**Architecture:** Keep `resources.js` as the permission-filtered navigation source and move sidebar UI state into explicit helpers in `app.js`. Render stable shell controls once, rerender only the section-list region, and drive expanded, compact, and mobile presentation through shell classes and existing theme variables. Preserve all backend routes and access lists.

**Tech Stack:** Browser ES modules, native DOM helpers, CSS, PHP static frontend contract tests, Docker PHP test runner, in-app browser verification.

---

## File Structure

- `public/admin/index.html`: adds stable mobile menu and drawer backdrop hooks plus accessible navigation labels.
- `public/admin/assets/js/resources.js`: adds local section symbols while preserving order, resources, and role filtering.
- `public/admin/assets/js/app.js`: owns sidebar state, cookie validation, stable shell rendering, partial list rendering, recent resources, keyboard controls, scroll restoration, and mobile focus handling.
- `public/admin/assets/css/app.css`: implements sticky expanded/compact layouts, independent navigation scrolling, focus styles, compact rail, responsive drawer, and reduced-motion behavior.
- `tests/Frontend/AdminSidebarCollapseFrontendTest.php`: retains the explicit-empty-cookie regression and adds active-section collapse contracts.
- `tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php`: new focused contract for stable search, dual mode, recent-resource filtering, scroll restoration, mobile drawer, accessibility, and local-only styling.
- `tests/Frontend/AdminFrontendSecurityTest.php`: remains the adjacent security and navigation-boundary gate; update only assertions invalidated by the new stable renderer.

The current checkout has an empty `.git` directory and is not recognized as a repository. Commit steps below are deferred until repository metadata is restored; execution must not initialize Git or create commits without user direction.

---

### Task 1: Pin the New Sidebar Contracts

**Files:**
- Create: `tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php`
- Modify: `tests/Frontend/AdminSidebarCollapseFrontendTest.php`

- [ ] **Step 1: Write the failing deep-optimization contract**

Create a test that reads the four frontend sources and asserts the agreed hooks:

```php
<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$index = file_get_contents(__DIR__ . '/../../public/admin/index.html') ?: '';
$appJs = file_get_contents(__DIR__ . '/../../public/admin/assets/js/app.js') ?: '';
$resources = file_get_contents(__DIR__ . '/../../public/admin/assets/js/resources.js') ?: '';
$css = file_get_contents(__DIR__ . '/../../public/admin/assets/css/app.css') ?: '';

foreach (['adminSidebarState', 'adminEnsureSidebarShell', 'adminRenderNavList', 'adminCaptureNavScroll', 'adminRestoreNavScroll'] as $hook) {
    assert_true(str_contains($appJs, $hook), 'Sidebar should expose stable state/render hook: ' . $hook);
}
assert_true(str_contains($appJs, 'ADMIN_SIDEBAR_MODE_COOKIE'), 'Sidebar mode should use a same-site cookie.');
assert_true(str_contains($appJs, 'ADMIN_NAV_RECENT_COOKIE'), 'Recent navigation should persist only resource keys.');
assert_true(str_contains($appJs, 'adminAllowedRecentPages'), 'Recent resources should be filtered by current role.');
assert_true(str_contains($appJs, 'adminOpenMobileNav') && str_contains($appJs, 'adminCloseMobileNav'), 'Mobile drawer should expose explicit open and close paths.');
assert_true(str_contains($appJs, 'event.key === "Escape"') && str_contains($appJs, 'event.key.toLowerCase() === "k"'), 'Sidebar should support Escape and Ctrl/Cmd+K.');
assert_true(str_contains($index, 'id="sidebar-toggle"') && str_contains($index, 'id="sidebar-backdrop"'), 'Admin shell should expose mobile drawer controls.');
assert_true(str_contains($index, 'aria-label="主导航"'), 'Navigation should have an accessible label.');
assert_true(str_contains($resources, 'symbol:'), 'Navigation sections should expose local compact symbols.');
foreach (['.app-shell.sidebar-compact', '.sidebar-backdrop', '.sidebar-mobile-open', ':focus-visible', 'prefers-reduced-motion'] as $selector) {
    assert_true(str_contains($css, $selector), 'Sidebar CSS should define: ' . $selector);
}
assert_true(!preg_match('/https?:\\/\\//', $index . $appJs . $resources . $css), 'Admin sidebar should not load external assets.');
```

- [ ] **Step 2: Extend the collapse regression contract**

Add assertions proving explicit user collapse can win after the navigation event:

```php
assert_true(str_contains($appJs, 'transientOpenKey'), 'Active navigation should use a one-event transient open section.');
assert_true(str_contains($appJs, 'adminToggleNavSection'), 'Section toggling should use one explicit state transition.');
assert_true(!str_contains($appJs, 'openKeys.has(section.key) || section.key === activeSectionKey'), 'Active sections must not be permanently forced open.');
```

- [ ] **Step 3: Run the tests and verify RED**

Run:

```powershell
docker compose exec -T php php tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php
docker compose exec -T php php tests/Frontend/AdminSidebarCollapseFrontendTest.php
```

Expected: the new test fails on the first missing stable-render hook; the collapse test fails on `transientOpenKey`.

---

### Task 2: Add Compact Navigation Metadata Without Changing Access

**Files:**
- Modify: `public/admin/assets/js/resources.js:248-257`
- Test: `tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php`
- Verify: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] **Step 1: Add a short local symbol to every navigation section**

Extend each section object while preserving its key, label, default-open flag, items, splice position, and role filtering. Use one visible Chinese character so no icon asset is required:

```js
export const navigationSections = [
  { key: "operations", label: "运营", symbol: "运", defaultOpen: true, items: [/* unchanged */] },
  { key: "organization", label: "组织", symbol: "组", items: [/* unchanged */] },
  { key: "models", label: "模型", symbol: "模", items: [/* unchanged */] },
  { key: "access", label: "访问", symbol: "钥", items: [/* unchanged */] },
  { key: "usage", label: "用量", symbol: "量", items: [/* unchanged */] },
  { key: "security", label: "安全", symbol: "安", items: [/* unchanged */] },
  { key: "logs", label: "日志", symbol: "志", items: [/* unchanged */] },
  { key: "system", label: "系统设置", symbol: "设", items: [/* unchanged */] }
];
```

Give the inserted RAG section `symbol: "知"` and keep its current index.

- [ ] **Step 2: Run metadata and security contracts**

Run:

```powershell
docker compose exec -T php php tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php
docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php
```

Expected: the symbol assertion passes; the deep test still fails on `app.js` hooks; the security test passes without role-access changes.

---

### Task 3: Introduce Validated Sidebar State

**Files:**
- Modify: `public/admin/assets/js/app.js:7-17,162-194`
- Test: `tests/Frontend/AdminSidebarCollapseFrontendTest.php`
- Test: `tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php`

- [ ] **Step 1: Define state and cookie names**

Add state that distinguishes persistent preferences from transient UI:

```js
const adminSidebarState = {
  mode: "expanded",
  mobileOpen: false,
  query: "",
  openKeys: null,
  transientOpenKey: "",
  scrollTop: 0,
  recentPages: [],
  shellReady: false,
  restoreFocusToToggle: false
};

const ADMIN_SIDEBAR_MODE_COOKIE = "tokenhub_admin_sidebar_mode";
const ADMIN_NAV_RECENT_COOKIE = "tokenhub_admin_nav_recent";
```

- [ ] **Step 2: Add safe generic cookie and key validators**

Implement `adminReadCookie`, `adminWriteCookie`, `adminValidSectionKeys`, `adminNormalizeOpenKeys`, `adminAllowedRecentPages`, and `adminReadSidebarPreferences`. Unknown values must be discarded against `navSectionsForRole(currentRole)` and `resourcesForRole(currentRole)`; cookie writes are wrapped in `try/catch` and never block navigation.

```js
function adminAllowedRecentPages(pages, sections = navSectionsForRole(adminState.user?.role || "user")) {
  const allowed = new Set(sections.flatMap((section) => section.items.map(([key]) => key)));
  return [...new Set(pages.map(String))].filter((key) => allowed.has(key)).slice(0, 5);
}
```

- [ ] **Step 3: Replace forced-active open logic**

Make the active section open only for the navigation transition:

```js
function adminSectionIsOpen(section, query) {
  if (query !== "") return true;
  if (adminSidebarState.transientOpenKey === section.key) return true;
  if (adminSidebarState.openKeys === null) return section.defaultOpen === true;
  return adminSidebarState.openKeys.has(section.key);
}

function adminToggleNavSection(sectionKey) {
  const openKeys = adminSidebarState.openKeys ?? new Set();
  if (openKeys.has(sectionKey) || adminSidebarState.transientOpenKey === sectionKey) {
    openKeys.delete(sectionKey);
    adminSidebarState.transientOpenKey = "";
  } else {
    openKeys.add(sectionKey);
  }
  adminSidebarState.openKeys = openKeys;
  writeNavOpenCookie(openKeys);
  adminRenderNavList();
}
```

- [ ] **Step 4: Run state tests and verify GREEN for collapse behavior**

Run the two sidebar tests. Expected: `AdminSidebarCollapseFrontendTest.php` passes; deep optimization advances to the next missing hook.

---

### Task 4: Render a Stable Sidebar Shell and Preserve Search Focus

**Files:**
- Modify: `public/admin/index.html:9-16,20-31`
- Modify: `public/admin/assets/js/app.js:196-272`
- Test: `tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php`

- [ ] **Step 1: Add stable shell hooks**

Change the sidebar markup to include a mode toggle, accessible nav, backdrop, and mobile top-bar control. Keep all labels local:

```html
<aside id="sidebar" class="sidebar" aria-label="管理菜单">
  <div class="sidebar-header">
    <div class="brand">TokenHub</div>
    <button id="sidebar-mode-toggle" type="button" class="sidebar-mode-toggle" aria-label="收起菜单">‹</button>
  </div>
  <nav id="nav" aria-label="主导航"></nav>
</aside>
<button id="sidebar-backdrop" class="sidebar-backdrop" type="button" aria-label="关闭菜单" hidden></button>
```

Add `#sidebar-toggle` to `.topbar-title` with `aria-controls="sidebar"` and `aria-expanded="false"`.

- [ ] **Step 2: Split shell creation from list rendering**

Implement `adminEnsureSidebarShell()` so the search input and list host are created once. Bind `input` to state and call only `adminRenderNavList()`:

```js
function adminEnsureSidebarShell() {
  if (adminSidebarState.shellReady) return;
  const nav = $("nav");
  clear(nav);
  const search = el("input", {
    id: "nav-search-input",
    type: "search",
    placeholder: "搜索菜单",
    ariaLabel: "搜索菜单",
    onInput: (event) => {
      adminSidebarState.query = event.target.value.trim().toLowerCase();
      adminRenderNavList();
    }
  });
  nav.append(adminCreateSearchControl(search), el("div", { id: "nav-recent" }), el("div", { id: "nav-list", ariaLive: "polite" }));
  adminSidebarState.shellReady = true;
}
```

- [ ] **Step 3: Render only recent and list regions**

Implement `adminRenderNavList()` using the existing section/item mapping. Add stable IDs, `aria-controls`, `aria-expanded`, and `aria-current="page"`. `adminRenderNav()` becomes:

```js
function adminRenderNav() {
  adminEnsureSidebarShell();
  adminRenderRecentNav();
  adminRenderNavList();
  adminRestoreNavScroll();
}
```

- [ ] **Step 4: Add scroll capture and restoration**

Read and restore only the list host:

```js
function adminCaptureNavScroll() {
  adminSidebarState.scrollTop = $("nav-list")?.scrollTop || 0;
}

function adminRestoreNavScroll() {
  const list = $("nav-list");
  if (list) list.scrollTop = adminSidebarState.scrollTop;
}
```

Call capture before list replacement and after scroll events; do not rebuild the search control.

- [ ] **Step 5: Run the focused test**

Expected: stable-render, search, and scroll hooks pass; failures move to dual-mode/mobile behavior.

---

### Task 5: Add Recent Navigation and Expanded/Compact Mode

**Files:**
- Modify: `public/admin/assets/js/app.js:274-358`
- Modify: `public/admin/assets/css/app.css:48-93,1916-2061`
- Test: `tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php`

- [ ] **Step 1: Record and render safe recent pages**

Before changing `adminState.page`, push the destination through `adminAllowedRecentPages`, persist only resource keys, and render the most recent allowed page other than the current page. Omit the block when empty.

- [ ] **Step 2: Apply persisted desktop mode**

Implement `adminApplySidebarMode(mode, persist)` and bind `#sidebar-mode-toggle`. Toggle `sidebar-expanded` and `sidebar-compact` on `#app`; update the button label and `aria-pressed` without changing route state.

- [ ] **Step 3: Add sticky and compact CSS**

Use these layout contracts:

```css
.app-shell { grid-template-columns: 240px minmax(0, 1fr); }
.app-shell.sidebar-compact { grid-template-columns: 56px minmax(0, 1fr); }
.sidebar { position: sticky; top: 0; height: 100vh; overflow: hidden; }
.sidebar nav { min-height: 0; display: flex; flex: 1; flex-direction: column; }
.nav-list-host { min-height: 0; overflow-y: auto; overscroll-behavior: contain; }
.app-shell.sidebar-compact .brand span,
.app-shell.sidebar-compact .nav-search,
.app-shell.sidebar-compact .nav-section-label,
.app-shell.sidebar-compact .nav-count,
.app-shell.sidebar-compact .nav-list { display: none; }
```

Compact section controls remain visible as 40-pixel square symbol buttons with `title` and accessible names.

- [ ] **Step 4: Run the focused tests**

Expected: mode, symbol, recent navigation, and local-only asset assertions pass.

---

### Task 6: Implement the Mobile Drawer and Keyboard Controls

**Files:**
- Modify: `public/admin/assets/js/app.js`
- Modify: `public/admin/assets/css/app.css:1579-1600,2345-2358`
- Test: `tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php`

- [ ] **Step 1: Add explicit drawer state transitions**

Implement `adminOpenMobileNav()` and `adminCloseMobileNav({ restoreFocus = true } = {})`. Toggle `sidebar-mobile-open` on the shell, `hidden` on the backdrop, `aria-expanded` on the top-bar toggle, and `document.body.classList.toggle("sidebar-lock-scroll", open)`.

- [ ] **Step 2: Bind all close paths**

Close the drawer after successful page selection, backdrop click, close/mode button activation on mobile, and `Escape`. Return focus to `#sidebar-toggle` when closing from keyboard or backdrop.

- [ ] **Step 3: Bind keyboard search**

On `Ctrl+K` or `Meta+K`, prevent default, open the compact search surface if needed, and focus `#nav-search-input`. On `Escape`, clear a non-empty query first; otherwise close compact search or the mobile drawer.

- [ ] **Step 4: Replace the old mobile flow CSS**

```css
@media (max-width: 820px) {
  .app-shell,
  .app-shell.sidebar-compact { grid-template-columns: minmax(0, 1fr); }
  .sidebar { position: fixed; inset: 0 auto 0 0; width: min(86vw, 300px); height: 100dvh; transform: translateX(-102%); z-index: 50; }
  .app-shell.sidebar-mobile-open .sidebar { transform: translateX(0); }
  .sidebar-backdrop { position: fixed; inset: 0; z-index: 40; background: rgb(15 23 42 / 58%); }
  .sidebar-toggle { display: inline-flex; }
}
.sidebar-lock-scroll { overflow: hidden; }
```

- [ ] **Step 5: Run the focused sidebar tests**

Expected: both sidebar tests pass with no output and exit code 0.

---

### Task 7: Complete Accessibility and Theme-Safe Styling

**Files:**
- Modify: `public/admin/assets/css/app.css`
- Modify: `public/admin/assets/js/app.js`
- Test: `tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php`
- Verify: `tests/Frontend/AdminFrontendSecurityTest.php`
- Verify: `tests/Frontend/AdminLayoutOverflowTest.php`

- [ ] **Step 1: Add focus, active, and reduced-motion rules**

```css
.sidebar button:focus-visible,
.sidebar input:focus-visible,
.sidebar-toggle:focus-visible {
  outline: 2px solid color-mix(in srgb, var(--primary) 76%, white);
  outline-offset: 2px;
}
.nav-button[aria-current="page"] { background: var(--primary); color: var(--primary-text); }
@media (prefers-reduced-motion: reduce) {
  .sidebar,
  .sidebar-backdrop,
  .nav-section { transition: none !important; }
}
```

- [ ] **Step 2: Verify ARIA synchronization**

Every section list has a stable `id`, every section button references it, mobile toggle state follows the drawer, and active resource buttons set `aria-current` only on the unique active destination.

- [ ] **Step 3: Run adjacent frontend gates**

Run:

```powershell
docker compose exec -T php php tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php
docker compose exec -T php php tests/Frontend/AdminSidebarCollapseFrontendTest.php
docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php
docker compose exec -T php php tests/Frontend/AdminLayoutOverflowTest.php
```

Expected: all commands exit 0.

---

### Task 8: Syntax, Suite, and Browser Verification

**Files:**
- Verify: `public/admin/index.html`
- Verify: `public/admin/assets/js/resources.js`
- Verify: `public/admin/assets/js/app.js`
- Verify: `public/admin/assets/css/app.css`

- [ ] **Step 1: Check JavaScript syntax**

Run:

```powershell
node --check public/admin/assets/js/app.js
node --check public/admin/assets/js/resources.js
```

Expected: both exit 0 with no syntax errors.

- [ ] **Step 2: Run the project test entrypoint in Docker**

Run:

```powershell
docker compose exec -T php php bin/run-tests.php
```

Expected: exit 0 and all listed tests report `PASS`.

- [ ] **Step 3: Verify desktop expanded mode around 1440 pixels**

In the in-app browser, sign in with an existing local administrator session. Confirm multi-character search retains focus, matching sections update, section toggles work, active page has `aria-current`, long navigation scrolls independently, and page changes preserve sidebar scroll.

- [ ] **Step 4: Verify narrow desktop around 900 pixels**

Confirm compact mode is usable, symbols have accessible names, the mode preference survives reload, `Ctrl+K` focuses search, and all role-permitted destinations remain reachable.

- [ ] **Step 5: Verify mobile around 390 pixels**

Confirm the menu overlays rather than pushes content, backdrop and `Escape` close it, route selection closes it, focus returns to the menu button, and body scrolling is restored.

- [ ] **Step 6: Verify all themes and console state**

Check themes A, B, and C in expanded, compact, and drawer states. Confirm readable contrast, no horizontal page overflow, no external asset request, and no new browser console error.

- [ ] **Step 7: Record the Git limitation**

Run `git status --short`. If the current empty `.git` directory still prevents repository detection, report that verification result without initializing Git or claiming a commit.

---

## Implementation Amendment: Domain Icons and Secondary Navigation

User review of the rendered sidebar replaced the earlier one-character section-symbol decision:

- Primary domains use locally rendered inline SVG paths, not text badges, icon fonts, downloaded SVG files, or remote assets.
- Expanded mode renders each domain as an accessible collapsible section with its permission-filtered second-level pages in the left sidebar.
- Compact mode hides the secondary list and retains one SVG icon per domain.
- Content-area domain tabs remain available but do not replace left-sidebar secondary navigation.
- `public/admin/index.html` cache-busts both `app.js` and `app.css` so the corrected renderer replaces cached flat-domain output.
- The authoritative regression gates are `AdminSidebarDeepOptimizationFrontendTest.php` and `AdminDomainNavigationFrontendTest.php`.
