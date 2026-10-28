# Admin Sidebar Search Removal Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove menu search from the TokenHub admin sidebar without changing the remaining navigation behavior.

**Architecture:** The admin shell will create only the recent-pages and navigation-list regions. `adminSidebarState` and the sidebar key handler will retain only state and behavior still used by compact mode, mobile navigation, section toggles, and page routing. The static frontend contract will invert its search assertions to prevent reintroduction.

**Tech Stack:** Vanilla ES modules, CSS, PHP static frontend contract tests, Docker Compose PHP runtime.

---

### Task 1: Lock the removal behavior in a frontend contract

**Files:**
- Modify: `D:\mysoft\tokenhub\tests\Frontend\AdminFrontendSecurityTest.php`
- Test: `D:\mysoft\tokenhub\tests\Frontend\AdminFrontendSecurityTest.php`

- [x] **Step 1: Replace search-presence assertions with absence assertions**

```php
assert_true(!str_contains($appJs, 'adminRenderNavSearch'), 'Admin sidebar must not render a navigation search control.');
assert_true(!str_contains($appJs, 'nav-search-input'), 'Admin sidebar must not expose a navigation search input.');
assert_true(!str_contains($appJs, 'compactSearchOpen'), 'Admin sidebar must not keep compact search state.');
assert_true(!str_contains($appJs, 'aria-keyshortcuts'), 'Admin sidebar must not register a search keyboard shortcut.');
assert_true(!str_contains($adminCss, '.nav-search'), 'Admin CSS must not style a removed sidebar search control.');
assert_true(str_contains($appJs, 'nav-section-toggle'), 'Admin sidebar should render collapsible section toggles.');
assert_true(str_contains($adminCss, '.nav-section'), 'Admin CSS should style sidebar sections.');
```

- [x] **Step 2: Run the focused contract to verify the new assertion fails against the existing search implementation**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: FAIL at the assertion that `adminRenderNavSearch` must be absent.

### Task 2: Remove search-specific client behavior and styling

**Files:**
- Modify: `D:\mysoft\tokenhub\public\admin\assets\js\app.js`
- Modify: `D:\mysoft\tokenhub\public\admin\assets\css\app.css`
- Test: `D:\mysoft\tokenhub\tests\Frontend\AdminFrontendSecurityTest.php`

- [x] **Step 1: Remove unused sidebar search state and renderer**

In `adminSidebarState`, remove `query` and `compactSearchOpen`. Delete `adminPreserveSearchFocus()` and `adminRenderNavSearch()`. In `adminEnsureSidebarShell()`, do not call a search renderer; append only the existing `nav-recent` and `nav-list-host` elements.

```js
clear(nav);
nav.append(
  el("div", { id: "nav-recent", className: "nav-recent" }),
  el("div", { id: "nav-list-host", className: "nav-list-host", "aria-live": "polite" })
);
```

- [x] **Step 2: Remove query filtering and search-only keyboard behavior**

Remove the query match/filter branch from `adminRenderNavList()`. Remove Ctrl/Cmd+K handling and the Escape branch that clears query or closes compact search. Preserve Escape closing the mobile drawer.

```js
function adminHandleSidebarKeydown(event) {
  if (event.key === "Escape" && adminSidebarState.mobileOpen) {
    adminCloseMobileNav();
  }
}
```

- [x] **Step 3: Remove sidebar-search CSS rules**

Delete the `.nav-search`, `.nav-search span`, `.nav-search input`, `.nav-search input::placeholder`, dark-theme `.nav-search input`, and compact/mobile `.nav-search` selector entries. Do not alter styles for `.nav-section`, `.nav-list-host`, or the sidebar mode controls.

- [x] **Step 4: Run JavaScript syntax validation**

Run: `node --check public/admin/assets/js/app.js`

Expected: exit code 0 with no output.

- [x] **Step 5: Run the focused frontend contract**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: PASS, including the new absence assertions and existing navigation assertions.

- [x] **Step 6: Review the diff before committing**

Run: `git diff --check -- public/admin/assets/js/app.js public/admin/assets/css/app.css tests/Frontend/AdminFrontendSecurityTest.php docs/superpowers/specs/2026-07-10-admin-sidebar-search-removal-design.md docs/superpowers/plans/2026-07-10-admin-sidebar-search-removal.md`

Expected: no whitespace errors. Do not commit unless the user explicitly asks, because the shared working tree may contain unrelated changes.

### Task 3: Synchronize dependent sidebar contracts discovered by the full suite

**Files:**
- Modify: `D:\mysoft\tokenhub\tests\Frontend\AdminDomainNavigationFrontendTest.php`
- Modify: `D:\mysoft\tokenhub\tests\Frontend\AdminSidebarDeepOptimizationFrontendTest.php`
- Test: `D:\mysoft\tokenhub\tests\Frontend\AdminDomainNavigationFrontendTest.php`
- Test: `D:\mysoft\tokenhub\tests\Frontend\AdminSidebarDeepOptimizationFrontendTest.php`

- [x] **Step 1: Update the domain-navigation contract**

Replace the former `adminSectionIsOpen(domain, query)` assertion with `adminSectionIsOpen(domain)` so the test preserves its collapsible-section guarantee without requiring removed filtering state.

- [x] **Step 2: Update the sidebar-optimization contract**

Remove the focus-preservation hook from its required stable hooks. Assert that `adminRenderNavSearch` and `nav-search-input` are absent, and require Escape handling while rejecting the removed Ctrl/Cmd+K search shortcut.

- [x] **Step 3: Verify the dependent contracts and full suite**

Run: `docker compose exec -T php php tests/Frontend/AdminDomainNavigationFrontendTest.php`

Run: `docker compose exec -T php php tests/Frontend/AdminSidebarDeepOptimizationFrontendTest.php`

Run: `docker compose exec -T php php bin/run-tests.php`

Expected: all commands exit with code 0.
