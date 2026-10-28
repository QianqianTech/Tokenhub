# WAF Overview Attack List Layout Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the WAF attack records page lead with a readable WAF overview and treat the attack statistics area as the attack list surface.

**Architecture:** Keep the existing WAF admin APIs unchanged. Update only the vanilla admin frontend renderer and CSS so the overview appears before the attack list, and the overview panels render as a single-column stack instead of narrow dashboard grid cells.

**Tech Stack:** Vanilla JavaScript admin assets, existing admin CSS, PHP source-string frontend contract tests.

---

### Task 1: Lock The Frontend Structure

**Files:**
- Modify: `tests/Frontend/AdminLayoutOverflowTest.php`

- [ ] Add assertions that `table.js` renders a `waf-overview` section, a `waf-statistics-grid`, and a `waf-attack-list` section.
- [ ] Add assertions that `app.css` defines single-column rules for `.waf-statistics-grid`.
- [ ] Run `docker compose exec -T php php bin/run-tests.php tests/Frontend/AdminLayoutOverflowTest.php` or the repo's focused test equivalent and confirm the new assertions fail before implementation.

### Task 2: Implement The Layout

**Files:**
- Modify: `public/admin/assets/js/table.js`
- Modify: `public/admin/assets/css/app.css`

- [ ] Change the WAF statistics heading to `WAF 概述`.
- [ ] Render overview panels in a dedicated `waf-statistics-grid` instead of the generic `dashboard-grid`.
- [ ] Render attack records in a dedicated `waf-attack-list` table panel with heading `攻击列表`.
- [ ] Add CSS so `.waf-statistics-grid` is a one-column stack, and each `.waf-statistics-panel` has its own horizontal overflow boundary.

### Task 3: Verify

**Files:**
- Test: `tests/Frontend/AdminLayoutOverflowTest.php`
- Test: `tests/Frontend/AdminFrontendSecurityTest.php`

- [ ] Run focused frontend PHP tests.
- [ ] Run `node --check public/admin/assets/js/table.js`.
- [ ] Report exact command output and any environment limits.
