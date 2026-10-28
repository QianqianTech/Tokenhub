# External OpenAI Production Hardening Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Remove the production blockers in TokenHub's external OpenAI gateway without changing the public API contract.

**Architecture:** Webhook producers and the worker will share the configured Redis queue. Non-streaming upstream calls receive one stable `Idempotency-Key`; streams never retry. Development Compose becomes loopback-only and password-protected, while the production Caddy/Ubuntu assets state the HTTPS, proxy, logging, and readiness requirements.

**Tech Stack:** PHP 8.2+, Redis, Caddy 2, Docker Compose, native PHP test runner.

---

### Task 1: Make Webhook delivery use the configured queue

**Files:**
- Modify: `app/Services/WebhookService.php:129,159,189,267`
- Modify: `tests/Services/WebhookRetryLeaseTest.php`

- [ ] **Step 1: Write the failing regression test**

Add a queue publisher closure to `WebhookService`, invoke `test()` with it, and assert the exact configured queue is used:

```php
$published = [];
$service = new WebhookService(static function (string $queue, array $payload) use (&$published): void {
    $published[] = [$queue, $payload];
});
// create an authorized webhook fixture, then:
$service->test($apiKey, $webhookId);
assert_same((string) App::config()->get('queue.name'), $published[0][0] ?? null, 'Webhook jobs must use the configured worker queue.');
assert_same('webhook.deliver', $published[0][1]['type'] ?? null, 'Webhook job type should remain webhook.deliver.');
```

- [ ] **Step 2: Run the regression test and verify RED**

Run: `docker compose exec -T php php tests/Services/WebhookRetryLeaseTest.php`

Expected: failure because `WebhookService` has no injectable publisher and writes literal `jobs`.

- [ ] **Step 3: Implement the single queue publisher boundary**

Add an optional constructor publisher and a private method, then replace all literal queue writes:

```php
public function __construct(private readonly mixed $queuePublisher = null) {}

private function enqueueDelivery(string $deliveryId): void
{
    $payload = ['type' => 'webhook.deliver', 'delivery_id' => $deliveryId];
    $queue = (string) App::config()->get('queue.name', 'tokenhub:jobs');
    if (is_callable($this->queuePublisher)) {
        ($this->queuePublisher)($queue, $payload);
        return;
    }
    App::redis()->push($queue, $payload);
}
```

Call `enqueueDelivery()` from `test()`, `retryDelivery()`, `emit()`, and `enqueueDue()`.

- [ ] **Step 4: Run the regression test and worker-related tests**

Run: `docker compose exec -T php php tests/Services/WebhookRetryLeaseTest.php && docker compose exec -T php php tests/Services/WorkerServiceTest.php`

Expected: both commands exit 0.

### Task 2: Stabilize non-streaming upstream retries

**Files:**
- Modify: `app/Services/GatewayService.php:116-123`
- Modify: `app/Services/ProviderHttpClient.php:145-235`
- Modify: `tests/Services/ProviderHttpClientTest.php`

- [ ] **Step 1: Write failing idempotency and stream retry contract tests**

Add source/behavior assertions for the new client contract:

```php
$source = file_get_contents(__DIR__ . '/../../app/Services/ProviderHttpClient.php') ?: '';
assert_true(str_contains($source, "'Idempotency-Key' => $idempotencyKey"), 'Non-streaming upstream requests must forward a stable idempotency key.');
assert_true(str_contains($source, '$attempts = 1;'), 'Streaming upstream requests must use one attempt.');
```

Add a request-preparation assertion using a known key:

```php
$prepared = (new ProviderHttpClient())->prepareRequest('/v1/chat/completions', $route, [
    'headers' => ['Idempotency-Key' => 'req_stable_key'],
    'json' => ['model' => 'demo'],
]);
assert_same('req_stable_key', $prepared['headers']['Idempotency-Key'] ?? null, 'Prepared provider request should retain the idempotency key.');
```

- [ ] **Step 2: Run the test and verify RED**

Run: `docker compose exec -T php php tests/Services/ProviderHttpClientTest.php`

Expected: failure because `sendJson()` has no idempotency-key parameter and `stream()` reads provider retry count.

- [ ] **Step 3: Add the minimum stable-key plumbing**

In `GatewayService`, derive the key once for a non-streaming request:

```php
private function upstreamIdempotencyKey(Request $request): string
{
    $provided = trim((string) $request->header('idempotency-key', ''));
    return preg_match('/^[A-Za-z0-9._-]{1,120}$/', $provided) === 1
        ? $provided
        : App::context()->requestId();
}
```

Pass it to `sendJson()`. Extend `ProviderHttpClient::sendJson()` with `?string $idempotencyKey = null`; after `buildRequest()`, merge `Idempotency-Key` only when non-empty. Set `stream()` attempts to `1`, while preserving non-streaming retry behavior and response mapping.

- [ ] **Step 4: Run gateway parser/client tests and verify GREEN**

Run: `docker compose exec -T php php tests/Services/ProviderHttpClientTest.php && docker compose exec -T php php tests/Services/GatewayRequestParserTest.php && docker compose exec -T php php tests/Support/ResponseStreamTest.php`

Expected: all commands exit 0.

### Task 3: Add queue-aware readiness without changing public health

**Files:**
- Modify: `app/Services/HealthCheckService.php`
- Modify: `app/Http/Controllers/HealthController.php`
- Modify: `app/Bootstrap/Kernel.php`
- Modify: `docker/caddy/Caddyfile`
- Modify: `Caddyfile.example`
- Modify: `tests/Services/AdminOperationsStatusHealthTest.php`

- [ ] **Step 1: Write a failing readiness contract test**

Add assertions that the readiness path exists and includes the queue worker heartbeat:

```php
$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$health = file_get_contents(__DIR__ . '/../../app/Services/HealthCheckService.php') ?: '';
assert_true(str_contains($kernel, "get('/ready'"), 'Kernel should expose a readiness endpoint.');
assert_true(str_contains($health, 'operations:worker:queue-worker'), 'Readiness must require the queue worker heartbeat.');
```

- [ ] **Step 2: Run it and verify RED**

Run: `docker compose exec -T php php tests/Services/AdminOperationsStatusHealthTest.php`

Expected: failure because no `/ready` route or worker-aware health check exists.

- [ ] **Step 3: Implement readiness**

Add `HealthCheckService::readiness()` that returns the existing PHP/MySQL/Redis checks plus `queue-worker`, sourced from `App::redis()->get('operations:worker:queue-worker')`. Add `HealthController::ready()` returning `200` only when all readiness checks are healthy, register `GET /ready`, and route it through both Caddy files. Leave `/health` unchanged for backward compatibility.

- [ ] **Step 4: Run readiness and routing tests**

Run: `docker compose exec -T php php tests/Services/AdminOperationsStatusHealthTest.php && docker compose exec -T php php tests/Support/RouterTest.php`

Expected: both commands exit 0.

### Task 4: Harden development and production deployment surfaces

**Files:**
- Modify: `docker-compose.yml`
- Modify: `docker/caddy/Caddyfile`
- Modify: `Caddyfile.example`
- Modify: `.env.example`
- Create: `deploy/ubuntu/.env.production.example`
- Modify: `deploy/ubuntu/README.md`
- Modify: `tests/Console/DeploymentArtifactsTest.php`

- [ ] **Step 1: Write failing configuration contract tests**

Add assertions for all required production/development controls:

```php
$compose = file_get_contents($root . '/docker-compose.yml') ?: '';
$developmentCaddy = file_get_contents($root . '/docker/caddy/Caddyfile') ?: '';
$productionEnv = file_get_contents($root . '/deploy/ubuntu/.env.production.example') ?: '';
assert_true(str_contains($compose, '127.0.0.1:${CADDY_HTTP_PORT:-8080}:80'), 'Development HTTP must bind to loopback.');
assert_true(str_contains($compose, 'redis-server --requirepass'), 'Development Redis must require a password.');
assert_true(str_contains($developmentCaddy, '-X-Powered-By'), 'Development Caddy must hide PHP version headers.');
assert_true(str_contains($productionEnv, 'SESSION_SECURE=true'), 'Production sessions must require Secure cookies.');
assert_true(str_contains($productionEnv, 'TRUSTED_PROXY_CIDRS='), 'Production must configure explicit trusted proxies.');
```

- [ ] **Step 2: Run deployment contract test and verify RED**

Run: `docker compose exec -T php php tests/Console/DeploymentArtifactsTest.php`

Expected: failure because loopback bindings, Redis authentication, response-header removal, and production env template are absent.

- [ ] **Step 3: Implement the minimum secure configuration**

Use these Compose bindings for all published development ports:

```yaml
ports:
  - "127.0.0.1:${CADDY_HTTP_PORT:-8080}:80"
  - "127.0.0.1:${CADDY_HTTPS_PORT:-8443}:443"
```

Apply the same loopback prefix to MySQL, Redis, and Qdrant. Configure Redis with `redis-server --requirepass ${REDIS_PASSWORD:?REDIS_PASSWORD is required}` and supply `REDIS_PASSWORD` to every PHP/worker service. Add `-X-Powered-By`, `request_body { max_size 32MB }`, and query removal to the development Caddy logs; add `-X-Powered-By` to the production Caddy header block.

Create `deploy/ubuntu/.env.production.example` with non-empty placeholder secrets, `APP_URL=https://api.example.com`, `SESSION_SECURE=true`, empty-but-required operator-provided `TRUSTED_PROXY_CIDRS`, and instructions not to publish data-service ports. Update the Ubuntu README to copy the template to `/etc/tokenhub/tokenhub.env`, protect it with mode `0600`, and run Caddy/security/acceptance checks.

- [ ] **Step 4: Run configuration verification**

Run: `docker compose exec -T php php tests/Console/DeploymentArtifactsTest.php && docker compose config`

Expected: test exits 0 and Compose renders successfully when a development `REDIS_PASSWORD` is supplied.

### Task 5: Execute release verification

**Files:**
- Modify: `docs/development-tasks/14-external-api-p0-p1-hardening-summary.md`

- [ ] **Step 1: Record verified remediation and residual operational test**

Append the exact automated evidence and a release-gate note requiring a real API-key run for Chat, Responses, Embeddings, SSE, quota exhaustion, upstream timeout, and webhook receipt.

- [ ] **Step 2: Run focused suite**

Run: `docker compose exec -T php php tests/run.php`

Expected: exit 0 with no `FAIL` lines. Investigate and resolve any existing failure before release.

- [ ] **Step 3: Validate running deployment**

Run: `docker compose ps && curl -fsS http://127.0.0.1:${CADDY_HTTP_PORT:-8080}/health && curl -fsS http://127.0.0.1:${CADDY_HTTP_PORT:-8080}/ready`

Expected: services healthy, health `200`, and readiness `200` after the queue worker records a heartbeat.
