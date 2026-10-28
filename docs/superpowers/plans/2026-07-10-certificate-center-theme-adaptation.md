# Certificate Center Theme Adaptation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make every certificate-center surface inherit the selected admin light or dark theme without changing certificate behavior or APIs.

**Architecture:** Replace the certificate center's fixed light-only colors with the app shell's existing semantic theme variables. Keep the layout, DOM contracts, and interactions intact; add source-level regression assertions that require these variable-backed declarations.

**Tech Stack:** Vanilla JavaScript admin UI, CSS custom properties, PHP frontend contract tests.

---

### Task 1: Lock the certificate-center theme contract

**Files:**

- Modify: `tests/Frontend/AdminSettingsCenterFrontendTest.php:101-111`
- Test: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: Write the failing test**

  Add assertions that require the existing certificate-center CSS block to use `var(--surface)`, `var(--surface-soft)`, `var(--text)`, and `var(--border)` rather than a fixed light-only palette:

  ```php
  assert_true(str_contains($adminCss, '.certificate-summary-card,') && str_contains($adminCss, 'background: var(--surface);'), 'Certificate center surfaces should follow the active theme.');
  assert_true(str_contains($adminCss, '.certificate-type-card') && str_contains($adminCss, 'color: var(--text);'), 'Certificate type cards should inherit the active theme text color.');
  assert_true(str_contains($adminCss, '.internal-ip-choices') && str_contains($adminCss, 'background: var(--surface-soft);'), 'Internal IP choices should follow the active theme surface.');
  ```

- [ ] **Step 2: Run the focused test and verify it fails**

  Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

  Expected: a failure explaining that the certificate center still uses fixed colors.

- [ ] **Step 3: Commit the test-only change**

  Run: `git add tests/Frontend/AdminSettingsCenterFrontendTest.php && git commit -m "test: cover certificate center theme tokens"`

### Task 2: Make certificate-center surfaces theme-aware

**Files:**

- Modify: `public/admin/assets/css/app.css:792-868`
- Test: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: Replace the fixed surface and border colors**

  Update the certificate summary/request/progress/inventory/alert surfaces and the type/IP selector controls to use the existing semantic variables:

  ```css
  .certificate-summary-card,
  .certificate-request,
  .certificate-request-progress,
  .certificate-inventory-results,
  .certificate-center-alerts {
    border: 1px solid var(--border-soft);
    background: var(--surface);
  }

  .certificate-type-card {
    border-color: var(--border);
    background: var(--surface);
    color: var(--text);
  }

  .certificate-type-card.active,
  .internal-ip-choice.active {
    background: color-mix(in srgb, var(--primary) 12%, var(--surface));
  }

  .internal-ip-choices {
    background: var(--surface-soft);
  }
  ```

- [ ] **Step 2: Run the focused test and verify it passes**

  Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

  Expected: exit code 0 with `Admin settings center frontend tests passed.`

- [ ] **Step 3: Commit the implementation**

  Run: `git add public/admin/assets/css/app.css tests/Frontend/AdminSettingsCenterFrontendTest.php && git commit -m "fix: adapt certificate center to admin theme"`

### Task 3: Verify the rendered dark-theme state

**Files:**

- Verify: `public/admin/assets/css/app.css`
- Verify: `tests/Frontend/AdminSettingsCenterFrontendTest.php`

- [ ] **Step 1: Run the focused PHP frontend contract test**

  Run: `docker compose exec -T php php tests/Frontend/AdminSettingsCenterFrontendTest.php`

  Expected: exit code 0.

- [ ] **Step 2: Inspect the dark-theme computed styles in the local admin page**

  Open `http://localhost:8080/admin/#/system/settings-tls-certificates` with `.app-shell.theme-dark` active and confirm the certificate card, selected type card, and internal-IP choice container resolve to dark surface/text values rather than white backgrounds.

- [ ] **Step 3: Inspect the final diff**

  Run: `git diff --check && git diff -- public/admin/assets/css/app.css tests/Frontend/AdminSettingsCenterFrontendTest.php`

  Expected: no whitespace errors and only the planned CSS/test changes.
