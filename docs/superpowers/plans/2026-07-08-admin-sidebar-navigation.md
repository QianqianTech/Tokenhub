# Admin Sidebar Navigation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build a searchable, collapsible, permission-filtered sidebar for the TokenHub admin console.

**Architecture:** `resources.js` exports static navigation sections and normalized navigation labels. `app.js` renders sections from that model, tracks search text and open groups, and persists only non-sensitive section keys in a same-site cookie. `app.css` adds compact section, search, count, and hidden-item styling.

**Tech Stack:** Native PHP static contract tests, browser ES modules, CSS.

---

### Task 1: Contract Tests

**Files:**
- Modify: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] Assert `resources.js` exports `navigationSections` and `navSectionsForRole`.
- [ ] Assert common items include `dashboard`, `usage-records`, and `logs`.
- [ ] Assert normalized labels include `智能大屏`, `用量明细`, and `日志中心`.
- [ ] Assert `app.js` contains `tokenhub_admin_nav_open`, `adminRenderNavSearch`, `nav-section-toggle`, and `nav-search`.
- [ ] Assert `app.css` contains `.nav-search`, `.nav-section`, `.nav-section-toggle`, `.nav-count`, and `.nav-button.is-hidden`.
- [ ] Run `docker compose exec -T php php bin/run-tests.php` and confirm the new test fails.

### Task 2: Navigation Model

**Files:**
- Modify: `public/admin/assets/js/resources.js`

- [ ] Add `navigationSections` in the order defined by the design.
- [ ] Add normalized label/group metadata for visible navigation resources.
- [ ] Add `navSectionsForRole(role)` that filters each section using the existing `roleAccess`.
- [ ] Keep `resourcesForRole(role)` behavior intact for existing page rendering.

### Task 3: Sidebar Rendering

**Files:**
- Modify: `public/admin/assets/js/app.js`

- [ ] Import `navSectionsForRole`.
- [ ] Add `NAV_OPEN_COOKIE`, `readNavOpenCookie`, `writeNavOpenCookie`, and open-section helpers.
- [ ] Add `adminRenderNavSearch(nav)` for the search box.
- [ ] Rewrite `adminRenderNav()` to render search, section headers, counts, buttons, active state, and filtered hidden state.
- [ ] Keep navigation actions calling `adminNavigate(page)`.

### Task 4: Sidebar Styling

**Files:**
- Modify: `public/admin/assets/css/app.css`

- [ ] Style `.sidebar` as a structured navigation rail.
- [ ] Add search input styling.
- [ ] Add section header, count, active, collapsed, hidden, and mobile states.
- [ ] Keep the existing A/B/C theme variables working.

### Task 5: Verification

**Files:**
- Verify: `public/admin/assets/js/resources.js`
- Verify: `public/admin/assets/js/app.js`
- Verify: `public/admin/assets/css/app.css`

- [ ] Run the full PHP test suite in Docker.
- [ ] Verify `/admin/` in the browser: sections render, counts appear, search filters items, toggles collapse/expand, and active page remains visible.
- [ ] Verify no frontend admin files reference external assets.
