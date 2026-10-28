# Screen Three Firewall Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a third independent screen view for the firewall security dashboard.

**Architecture:** Keep the existing static `/screen/*` app and front-end path routing. Add `firewall` to the screen view keys so `/screen/firewall` works for credential login and `/screen/{share_code}/firewall` works for share links. Reuse the existing `/screen-api/dashboard/security` data source and existing share-link auth.

**Tech Stack:** Static HTML/CSS/JavaScript, vanilla admin JavaScript, PHP source-string frontend contract tests, Docker PHP verification.

---

### Task 1: Frontend Contract

**Files:**
- Modify: `tests/Frontend/ScreenShareFrontendTest.php`

- [ ] Add assertions for `data-screen-view="firewall"`, firewall panel host IDs, `SCREEN_VIEW_ORDER = ["overview", "operations", "firewall"]`, and a `/firewall` share-link open action.
- [ ] Run `docker compose exec -T php php tests/run.php tests/Frontend/ScreenShareFrontendTest.php`.
- [ ] Confirm the test fails because the firewall view and share action are not implemented yet.

### Task 2: Screen View

**Files:**
- Modify: `public/screen/index.html`
- Modify: `public/screen/assets/screen.js`
- Modify: `public/screen/assets/screen.css`

- [ ] Add a `firewall` screen section with summary, rule ranking, source IP ranking, and event list hosts.
- [ ] Add `firewall` to `SCREEN_VIEW_ORDER` and reuse `screenViewFromPath()` / `shareCodeFromPath()`.
- [ ] Implement `renderFirewallScreen()` from the existing security payload without adding new API calls.
- [ ] Add firewall-specific layout styles that fit the current screen theme.

### Task 3: Admin Share Entry

**Files:**
- Modify: `public/admin/assets/js/table.js`

- [ ] Add a row action named `大屏3` that opens `/screen/{share_code}/firewall`.
- [ ] Keep screen 1 and screen 2 actions unchanged.

### Task 4: Verification

**Files:**
- Test: `tests/Frontend/ScreenShareFrontendTest.php`
- Check: `public/screen/assets/screen.js`
- Check: `public/admin/assets/js/table.js`

- [ ] Run `node --check public/screen/assets/screen.js`.
- [ ] Run `node --check public/admin/assets/js/table.js`.
- [ ] Run `docker compose exec -T php php tests/run.php tests/Frontend/ScreenShareFrontendTest.php`.
