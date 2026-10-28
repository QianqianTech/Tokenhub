# Global Font Sizes Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the public admin and screen frontends use one title font size and one content font size globally.

**Architecture:** Define shared font variables in each frontend CSS entry point, then route all textual font-size declarations to `--font-size-title` or `--font-size-content`. Keep layout dimensions, spacing, radii, and responsive grids unchanged.

**Tech Stack:** Static HTML/CSS/JavaScript frontends served from `public/admin` and `public/screen`.

---

### Task 1: Collapse Frontend Text Sizes To Two Values

**Files:**
- Modify: `public/admin/assets/css/base.css`
- Modify: `public/admin/assets/css/app.css`
- Modify: `public/admin/assets/app.css`
- Modify: `public/screen/assets/screen.css`
- Test: `tests/Frontend/AdminFrontendSecurityTest.php`
- Test: `tests/Frontend/AdminLayoutOverflowTest.php`
- Test: `tests/Frontend/ScreenShareFrontendTest.php`

- [x] **Step 1: Add global font tokens**

Add these variables to each CSS root or shell scope:

```css
--font-family-global: Inter, Arial, "Microsoft YaHei", ui-sans-serif, system-ui, "Segoe UI", sans-serif;
--font-size-title: 18px;
--font-size-content: 14px;
```

- [x] **Step 2: Route normal page text to content size**

Set root or body text to the global family and content size:

```css
font-family: var(--font-family-global);
font-size: var(--font-size-content);
```

- [x] **Step 3: Route headings and title-like elements to title size**

Replace title-oriented font sizes with:

```css
font-size: var(--font-size-title);
```

- [x] **Step 4: Route all remaining textual font sizes to content size**

Replace label, badge, metric, nav, table, status, and helper text font sizes with:

```css
font-size: var(--font-size-content);
```

- [ ] **Step 5: Verify static frontend contracts**

Run:

```powershell
php tests/Frontend/AdminFrontendSecurityTest.php
php tests/Frontend/AdminLayoutOverflowTest.php
php tests/Frontend/ScreenShareFrontendTest.php
```

Expected: all three commands complete without assertion failures.

Environment note: `php` is not available in the current PATH, so these PHP test commands could not run here. Static CSS verification passed with no non-variable `font-size` or `font-family` declarations in the touched frontend CSS files.
