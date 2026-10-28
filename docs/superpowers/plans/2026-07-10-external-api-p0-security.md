# External API P0 Security Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make TokenHub's public API safe against spoofed client IPs, abuse, duplicate mutation, unsafe webhooks, sync races, and non-atomic quota overruns.

**Architecture:** Preserve existing API Key authentication and add focused services for trusted ingress, rate limits, and idempotency. Use database state for durable idempotency/sync ordering and Redis atomic counters for the synchronous gateway path.

**Tech Stack:** Native PHP, MySQL migrations, Redis, Caddy/PHP-FPM deployment, existing PHP test runner.

---

### Task 1: Trusted client IP and public API rate limits

**Files:** `app/Support/Request.php`, `app/Services/ExternalRateLimitService.php`, `app/Middleware/ApiKeyAuthMiddleware.php`, `config/app.php`, focused tests.

- [ ] Write tests proving a direct client cannot spoof `X-Forwarded-For`, trusted proxies can forward it, and the fourth request above a configured window returns 429 plus `Retry-After`.
- [ ] Add trusted-peer CIDR resolution and Redis-backed limits for invalid auth, model routes, and other external routes.
- [ ] Run the focused tests and the API middleware contract tests.

### Task 2: Durable idempotency for mutations

**Files:** `database/migrations/*external_api_p0*.sql`, `app/Services/IdempotencyService.php`, `app/Middleware/IdempotencyMiddleware.php`, `app/Bootstrap/Kernel.php`, focused tests.

- [ ] Write tests proving same key/payload replays the stored response and changed payload returns 409.
- [ ] Add the durable record table and apply idempotency middleware to external POST/PUT/DELETE mutation routes.
- [ ] Run the focused tests and external API surface tests.

### Task 3: Webhook egress and due retry leases

**Files:** `app/Services/WebhookService.php`, migration, focused tests.

- [ ] Write tests for private-target rejection, no early retry, and single delivery claim.
- [ ] Validate resolved targets, disable redirects, claim due work atomically, and enqueue retries through the due scheduler path.
- [ ] Run webhook and worker-focused tests.

### Task 4: Sync ordering and device enforcement

**Files:** `database/migrations/*external_api_p0*.sql`, `app/Services/UserSyncService.php`, focused tests.

- [ ] Write tests for stale delete conflict, disabled-device rejection, and strictly increasing user sync versions.
- [ ] Add user sequence state and transactional write/delete paths with active-device validation.
- [ ] Run sync service and external API tests.

### Task 5: Atomic quota reservations

**Files:** `app/Services/QuotaService.php`, `app/Services/ApiKeyModelQuotaService.php`, `app/Services/GatewayService.php`, focused tests.

- [ ] Write tests for reservation denial and actual-usage reconciliation.
- [ ] Replace read-then-check token counters with atomic reserve/reconcile operations and release reservation on all failure paths.
- [ ] Run quota, gateway, and performance benchmark contract tests.

### Task 6: Documentation and verification

**Files:** `docs/external-api-complete.md`, `docs/external-api-sync.md`, `public/openapi.json`, relevant tests.

- [ ] Document `Idempotency-Key`, 429 retry behavior, trusted proxy deployment requirement, and webhook delivery semantics.
- [ ] Run focused tests, the full PHP test suite, migration verification, and Docker-based smoke checks.
