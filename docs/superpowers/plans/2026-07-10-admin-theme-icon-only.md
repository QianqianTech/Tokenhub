# Admin Theme Icon-Only Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the admin theme selector's visible labels with three accessible inline icon controls.

**Architecture:** The existing static HTML controls keep their `data-theme-choice`, `title`, and `aria-label` contracts, while their visible content becomes local inline SVG. CSS changes the existing shared theme-choice component from label-sized segments to compact square icon controls. The current JavaScript remains the owner of selection, persistence, theme resolution, and system preference updates.

**Tech Stack:** Static HTML, CSS, ES modules, PHP frontend contract tests, Docker PHP runtime.

---

### Task 1: Lock the icon-only contract

**Files:**
- Modify: `D:/mysoft/tokenhub/tests/Frontend/AdminFrontendSecurityTest.php:46-92`
- Test: `D:/mysoft/tokenhub/tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] **Step 1: Replace the label-oriented assertions with the failing icon-only contract**

```php
foreach (['dark', 'light', 'auto'] as $themeChoice) {
    assert_true(
        preg_match('/data-theme-choice="' . $themeChoice . '"[^>]*>\\s*<svg\\b[\\s\\S]*?<\\/svg>\\s*<\\/button>/', $adminHtml) === 1,
        "Admin {$themeChoice} theme choice should render only an inline SVG icon."
    );
}
assert_true(str_contains($adminHtml, 'aria-label='), 'Theme icons should retain accessible names.');
assert_true(str_contains($adminHtml, 'title='), 'Theme icons should retain hover labels.');

preg_match('/\\.theme-choice\\s*\\{([^}]*)\\}/', $adminCss, $themeChoiceCssMatch);
assert_true(str_contains($themeChoiceCssMatch[1], 'width: 28px'), 'Theme choices should use compact icon width.');
assert_true(str_contains($themeChoiceCssMatch[1], 'padding: 0'), 'Theme choices should not reserve text-label padding.');
```

- [ ] **Step 2: Run the focused contract test and verify red**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: FAIL because the current buttons contain label text and the CSS retains `min-width: 54px` plus `padding: 0 10px`.

### Task 2: Render three local theme icons

**Files:**
- Modify: `D:/mysoft/tokenhub/public/admin/index.html:9-31`

- [ ] **Step 1: Replace each visible label with exactly one local inline SVG**

```html
<button type="button" class="theme-choice" data-theme-choice="dark" aria-pressed="false" aria-label="深色主题" title="切换为深色主题">
  <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M20.5 14.2A8.5 8.5 0 0 1 9.8 3.5 8.5 8.5 0 1 0 20.5 14.2Z"/></svg>
</button>
```

Use the same structure for `light` with a sun path and `auto` with a split-display path. Set `aria-hidden="true"` on each SVG; the button `aria-label` remains the accessible name. Do not place visible text inside any of the three buttons.

- [ ] **Step 2: Update the stylesheet cache version**

```html
<link rel="stylesheet" href="/admin/assets/css/app.css?v=20260710-theme-icons-v1">
```

### Task 3: Size and color the icon controls

**Files:**
- Modify: `D:/mysoft/tokenhub/public/admin/assets/css/app.css:1904-1929`

- [ ] **Step 1: Make the buttons compact squares and define local SVG sizing**

```css
.theme-choice {
  width: 28px;
  min-width: 28px;
  min-height: 28px;
  padding: 0;
  border: 0;
  border-radius: calc(var(--control-radius) - 2px);
  background: transparent;
  color: var(--muted);
}

.theme-choice svg {
  width: 16px;
  height: 16px;
  fill: none;
  stroke: currentColor;
  stroke-width: 1.9;
  stroke-linecap: round;
  stroke-linejoin: round;
}
```

Keep the existing `.theme-choice.active, .theme-choice:hover` rule so active and hover colors inherit through `currentColor`.

- [ ] **Step 2: Run the focused contract test and verify green**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: PASS.

### Task 4: Verify static syntax and presentation

**Files:**
- Verify: `D:/mysoft/tokenhub/public/admin/index.html`
- Verify: `D:/mysoft/tokenhub/public/admin/assets/css/app.css`
- Verify: `D:/mysoft/tokenhub/public/admin/assets/js/app.js`
- Verify: `D:/mysoft/tokenhub/tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] **Step 1: Check unchanged JavaScript syntax**

Run: `node --check public/admin/assets/js/app.js`

Expected: exit code 0 with no output.

- [ ] **Step 2: Re-run the focused frontend contract test**

Run: `docker compose exec -T php php tests/Frontend/AdminFrontendSecurityTest.php`

Expected: PASS.

- [ ] **Step 3: Inspect the final contract**

Run: `rg -n -C 2 'data-theme-choice|theme-choice svg|width: 28px|padding: 0' public/admin/index.html public/admin/assets/css/app.css tests/Frontend/AdminFrontendSecurityTest.php`

Expected: Three semantic theme-choice controls contain inline SVG, CSS has icon dimensions and no label padding, and the test asserts this contract.

- [ ] **Step 4: Commit the focused change if Git metadata is available**

Run: `git add public/admin/index.html public/admin/assets/css/app.css tests/Frontend/AdminFrontendSecurityTest.php docs/superpowers/specs/2026-07-10-admin-theme-icon-only-design.md docs/superpowers/plans/2026-07-10-admin-theme-icon-only.md && git commit -m "feat: use icons for admin themes"`

Expected: A focused commit. If the worktree still lacks valid Git metadata, skip the commit and report that blocker without altering repository state.

## Self-review

- The plan preserves all existing values and JavaScript behavior; Task 2 changes only presentation markup and Task 3 changes only control geometry.
- Every confirmed requirement maps to a task: exactly three icons (Task 2), no visible text (Task 1 and 2), accessible labels and hover text (Task 1 and 2), active visual state and responsive behavior (Task 3), retained persistence and Auto behavior (Task 1 through unchanged JS assertions), and focused verification (Task 4).
- No incomplete placeholders or undefined helper APIs are present.
