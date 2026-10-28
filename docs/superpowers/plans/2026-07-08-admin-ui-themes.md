# Admin UI Themes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add fast local A/B/C visual themes to the TokenHub admin console and lightly polish the screen view.

**Architecture:** The admin console keeps static HTML/CSS/ES modules. Theme state lives as a class on `#app` and as a non-sensitive cookie. CSS variables provide most color and density changes so the implementation stays small.

**Tech Stack:** Native PHP tests, static HTML, CSS, browser ES modules.

---

### Task 1: Frontend Contract Tests

**Files:**
- Modify: `tests/Frontend/AdminFrontendSecurityTest.php`
- Modify: `tests/Frontend/ScreenShareFrontendTest.php`

- [ ] Add assertions that admin HTML exposes `id="theme-switcher"` and `data-theme-choice="a"`, `"b"`, and `"c"`.
- [ ] Add assertions that admin JS contains `tokenhub_admin_theme`, `applyAdminTheme`, and does not contain `localStorage` or `sessionStorage`.
- [ ] Add assertions that admin CSS contains `.theme-a`, `.theme-b`, `.theme-c`, `.theme-switcher`, and no `http://`, `https://`, or `@import`.
- [ ] Add assertions that screen CSS contains local polished selectors for `.screen-shell`, `.screen-topline`, and no `http://`, `https://`, or `@import`.
- [ ] Run `php bin/run-tests.php` and confirm these new assertions fail before implementation.

### Task 2: Admin Theme Selector

**Files:**
- Modify: `public/admin/index.html`
- Modify: `public/admin/assets/js/app.js`

- [ ] Add a compact theme selector in `.topbar-actions`.
- [ ] Add `theme-a` to the admin root element as the default theme class.
- [ ] Add JS helpers `readThemeCookie`, `writeThemeCookie`, `applyAdminTheme`, and `adminInitThemeSwitcher`.
- [ ] Call `adminInitThemeSwitcher()` during boot before auth rendering.
- [ ] Run the focused frontend tests and confirm the admin theme assertions pass.

### Task 3: Admin CSS Themes

**Files:**
- Modify: `public/admin/assets/css/app.css`

- [ ] Move repeated colors into CSS variables on `.app-shell`.
- [ ] Define `.theme-a`, `.theme-b`, and `.theme-c` variable sets.
- [ ] Add `.theme-switcher` styling and active state styling.
- [ ] Tighten table, card, sidebar, form, and mobile styles while keeping simple layout and no external assets.
- [ ] Run the focused frontend tests and confirm CSS assertions pass.

### Task 4: Screen Polish

**Files:**
- Modify: `public/screen/index.html`
- Modify: `public/screen/assets/screen.css`

- [ ] Add lightweight structural classes: `.screen-shell`, `.screen-topline`, `.screen-title`, and `.screen-subtitle`.
- [ ] Keep the screen dark and local-only.
- [ ] Improve metric, panel, table, login, and mobile spacing using CSS only.
- [ ] Run focused frontend tests and confirm screen assertions pass.

### Task 5: Verification

**Files:**
- Verify: `public/admin/*`
- Verify: `public/screen/*`

- [ ] Run `php bin/run-tests.php`.
- [ ] Serve the app locally if needed and inspect `/admin/` and `/screen/`.
- [ ] Confirm theme buttons switch classes without console errors.
- [ ] Confirm no frontend CSS/JS/HTML contains remote asset references.
