# Kimi Code URL Split Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Separate Kimi Code provider URLs from normal Kimi API provider URLs.

**Architecture:** Keep the existing provider/model schema and use provider presets plus route heuristics to distinguish Kimi API from Kimi Code. Kimi Code routes use `api.kimi.com/coding` and `kimi-for-coding`; normal Kimi API routes keep Moonshot URLs and regular Kimi model ids.

**Tech Stack:** PHP gateway services, static PHP contract tests, vanilla admin JavaScript, Markdown docs.

---

### Task 1: Failing Contract Tests

**Files:**
- Modify: `tests/Services/ProviderHttpClientTest.php`
- Modify: `tests/Services/KimiClaudeCodeBridgeTest.php`
- Modify: `tests/Frontend/AdminProviderUrlDefaultsFrontendTest.php`
- Modify: `tests/Support/KimiCodexCompatibilityContractTest.php`

- [ ] **Step 1: Add failing tests**

Assert Kimi Code OpenAI routes build `https://api.kimi.com/coding/v1/chat/completions`, normal Kimi API routes still build `https://api.moonshot.cn/v1/chat/completions`, Kimi Code routes are detected by `api.kimi.com/coding` or `kimi-for-coding`, and regular `kimi-k2.7-code-highspeed` Moonshot routes are not treated as Kimi Code routes.

- [ ] **Step 2: Run focused tests to verify failure**

Run: `docker compose exec -T php php tests/Services/KimiClaudeCodeBridgeTest.php`
Expected: failure before implementation because the bridge currently treats ordinary Kimi API model ids as Kimi Code routes.

### Task 2: Backend Route Heuristic Fix

**Files:**
- Modify: `app/Services/Gateway/KimiClaudeCodeBridge.php`
- Modify: `app/Services/Gateway/ModelRouter.php`

- [ ] **Step 1: Narrow Kimi Code detection**

Change `isKimiCodeRoute()` to detect Kimi Code through `api.kimi.com/coding`, `kimi-for-coding`, or explicit Kimi Code naming, not every Moonshot or `kimi-*` model.

- [ ] **Step 2: Keep Anthropic-to-Kimi Code routing available**

Update the router SQL so Anthropic text requests can still select an OpenAI-compatible Kimi Code provider when the provider URL is `api.kimi.com/coding` or the model is `kimi-for-coding`.

### Task 3: Admin Presets and Docs

**Files:**
- Modify: `public/admin/assets/js/table.js`
- Modify: `tests/Frontend/AdminProviderUrlDefaultsFrontendTest.php`
- Modify: `tests/Support/KimiCodexCompatibilityContractTest.php`
- Modify: `docs/external-api-sync.md`
- Modify: `docs/development-tasks/13-external-api-sync-agent-calls.md`

- [ ] **Step 1: Split presets**

Expose separate admin presets for `Kimi API / Moonshot` and `Kimi Code`.

- [ ] **Step 2: Correct docs**

Document normal Kimi API as `https://api.moonshot.cn/v1` / `https://api.moonshot.ai/v1` with Kimi model ids, and Kimi Code as `https://api.kimi.com/coding/v1` or `https://api.kimi.com/coding/` with `kimi-for-coding`.

### Task 4: Verification

**Files:**
- Run tests only.

- [ ] **Step 1: Run focused tests**

Run: `docker compose exec -T php php tests/Services/ProviderHttpClientTest.php`
Run: `docker compose exec -T php php tests/Services/KimiClaudeCodeBridgeTest.php`
Run: `docker compose exec -T php php tests/Services/ModelRouterTest.php`
Run: `docker compose exec -T php php tests/Frontend/AdminProviderUrlDefaultsFrontendTest.php`
Run: `docker compose exec -T php php tests/Support/KimiCodexCompatibilityContractTest.php`

- [ ] **Step 2: Run full suite**

Run: `docker compose exec -T php php bin/run-tests.php`
