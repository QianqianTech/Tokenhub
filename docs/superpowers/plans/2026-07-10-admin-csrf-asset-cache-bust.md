# Admin CSRF Asset Cache Bust Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Ensure refreshed TokenHub admin pages load the JavaScript module that sends `X-CSRF-Token` on destructive requests.

**Architecture:** Keep server-side CSRF validation unchanged. Publish a new versioned URL for the admin entry module and, from that entry module, a new versioned URL for the API wrapper so browsers cannot reuse the stale API module that omitted the CSRF header.

**Tech Stack:** Static HTML, ES modules, PHP frontend contract tests, Docker PHP.

---

### Task 1: Lock the cache-busting contract with a focused test

**Files:**
- Modify: `tests/Frontend/AdminFrontendSecurityTest.php`
- Test: `tests/Frontend/AdminFrontendSecurityTest.php`

- [x] **Step 1: Write the failing test**

```php
$adminIndex = file_get_contents($root . '/public/admin/index.html') ?: '';
$appSource = file_get_contents($root . '/public/admin/assets/js/app.js') ?: '';

assert_true(
    str_contains($adminIndex, 'app.js?v=20260710-csrf-header-v1'),
    'Admin entry module should use the CSRF-header cache version.'
);
assert_true(
    str_contains($appSource, 'api.js?v=20260710-csrf-header-v1'),
    'Admin API module should use the CSRF-header cache version.'
);
```

- [x] **Step 2: Run the focused test to verify it fails**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: failure stating that the admin entry or API cache version is missing.

### Task 2: Publish fresh module URLs

**Files:**
- Modify: `public/admin/index.html:50`
- Modify: `public/admin/assets/js/app.js:1`
- Test: `tests/Frontend/AdminFrontendSecurityTest.php`

- [x] **Step 1: Update the HTML entry-module version**

```html
<script type="module" src="/admin/assets/js/app.js?v=20260710-csrf-header-v1"></script>
```

- [x] **Step 2: Update the API-module import version**

```js
import { adminErrorMessage, api, configureApi, login, setCsrf } from "./api.js?v=20260710-csrf-header-v1";
```

- [x] **Step 3: Run the focused test to verify it passes**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: `PASS tests/Frontend/AdminFrontendSecurityTest.php`.

- [x] **Step 4: Check JavaScript syntax**

Run: `node --check public/admin/assets/js/app.js; node --check public/admin/assets/js/api.js`

Expected: exit code 0 with no syntax errors.

### Task 3: Verify the security boundary and delivered asset chain

**Files:**
- Verify: `app/Middleware/CsrfMiddleware.php`
- Verify: `public/admin/index.html`
- Verify: `public/admin/assets/js/app.js`
- Verify: `public/admin/assets/js/api.js`

- [x] **Step 1: Run the CSRF security contract test**

Run: `docker compose exec -T php php tests/Support/AdminSecurityContractTest.php`

Expected: `PASS tests/Support/AdminSecurityContractTest.php`.

- [x] **Step 2: Confirm the source chain**

Run: `rg -n 'csrf-header-v1|X-CSRF-Token|ADMIN_CSRF_INVALID' public/admin/index.html public/admin/assets/js/app.js public/admin/assets/js/api.js app/Middleware/CsrfMiddleware.php`

Expected: the new cache version appears in the entry/import chain; the API wrapper still sends `X-CSRF-Token`; the middleware still returns `ADMIN_CSRF_INVALID` for invalid requests.
