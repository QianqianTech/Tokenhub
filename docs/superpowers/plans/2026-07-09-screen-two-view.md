# Screen Two View Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Split the existing operations screen into an overview view and an operations view while preserving share-link login and existing dashboard APIs.

**Architecture:** Keep one static `/screen/*` app and use front-end view routing. `/screen/{shareCode}/overview` and `/screen/{shareCode}/operations` keep the same share code; `/screen/overview` and `/screen/operations` work for credential login. Existing `/screen-api/dashboard/*` endpoints remain the data source.

**Tech Stack:** Native PHP tests, static HTML/CSS/JavaScript, Docker PHP verification.

---

### Task 1: Frontend Contract

**Files:**
- Modify: `tests/Frontend/ScreenShareFrontendTest.php`

- [ ] Add assertions that `index.html` exposes two screen views: `data-screen-view="overview"` and `data-screen-view="operations"`.
- [ ] Add assertions that `screen.js` exposes `screenViewFromPath`, `screenPathForView`, `SCREEN_VIEW_ROTATE_INTERVAL_MS`, and requests `/screen-api/dashboard/rankings`.
- [ ] Add assertions that `screen.css` styles `.screen-view`, `.screen-view.active`, and `.screen-view-nav`.
- [ ] Run `docker compose exec -T php php tests/run.php tests/Frontend/ScreenShareFrontendTest.php` and confirm it fails on the missing two-view contract.

### Task 2: Screen Markup

**Files:**
- Modify: `public/screen/index.html`

- [ ] Add segmented view controls to the screen toolbar.
- [ ] Wrap existing realtime/token/model/live panels in `<section class="screen-view active" data-screen-view="overview">`.
- [ ] Wrap trends, rankings, performance, and security panels in `<section class="screen-view" data-screen-view="operations">`.
- [ ] Add `id="rankings"` with `class="rank-list"` for the operations ranking panel.

### Task 3: Screen Behavior

**Files:**
- Modify: `public/screen/assets/screen.js`

- [ ] Implement `screenViewFromPath()` to parse `overview` or `operations` from `/screen/{shareCode}/{view}` and `/screen/{view}`.
- [ ] Update `shareCodeFromPath()` so `overview` and `operations` are not mistaken for share codes.
- [ ] Implement view switching, URL updates, active button state, and timed rotation.
- [ ] Fetch rankings together with overview, trends, security, and performance.
- [ ] Render rankings in the operations view.

### Task 4: Screen Styling and Verification

**Files:**
- Modify: `public/screen/assets/screen.css`

- [ ] Add view visibility and segmented-control styling.
- [ ] Tune overview and operations grids so each view fills one screen without nesting cards.
- [ ] Run `node --check public/screen/assets/screen.js`.
- [ ] Run `docker compose exec -T php php bin/run-tests.php`.
