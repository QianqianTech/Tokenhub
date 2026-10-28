<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$operations = file_get_contents(__DIR__ . '/../../app/Services/AdminOperationsService.php') ?: '';
$queueWorker = file_get_contents(__DIR__ . '/../../app/Console/QueueWorker.php') ?: '';
$ragWorker = file_get_contents(__DIR__ . '/../../python-rag-worker/tokenhub_rag_worker/worker.py') ?: '';
$healthController = file_get_contents(__DIR__ . '/../../app/Http/Controllers/HealthController.php') ?: '';
$healthChecks = file_get_contents(__DIR__ . '/../../app/Services/HealthCheckService.php') ?: '';
$kernel = file_get_contents(__DIR__ . '/../../app/Bootstrap/Kernel.php') ?: '';
$developmentCaddy = file_get_contents(__DIR__ . '/../../docker/caddy/Caddyfile') ?: '';
$productionCaddy = file_get_contents(__DIR__ . '/../../Caddyfile.example') ?: '';

assert_true(str_contains($operations, "'qdrant'"), 'Operations status should include the real Qdrant health probe.');
assert_true(str_contains($operations, "'queue-worker'"), 'Operations status should include queue-worker heartbeat health.');
assert_true(str_contains($operations, "'rag-worker'"), 'Operations status should include rag-worker heartbeat health.');
assert_true(str_contains($queueWorker, 'operations:worker:queue-worker'), 'Queue worker should publish its runtime heartbeat.');
assert_true(str_contains($ragWorker, 'operations:worker:rag-worker'), 'RAG worker should publish its runtime heartbeat.');
assert_true(str_contains($healthController, 'function ready'), 'Health controller should expose readiness separately from public health.');
assert_true(str_contains($healthChecks, 'function readiness'), 'Health checks should evaluate readiness separately from basic liveness.');
assert_true(str_contains($healthChecks, 'operations:worker:queue-worker'), 'Readiness must require the queue worker heartbeat.');
assert_true(str_contains($kernel, "get('/ready'"), 'Kernel should register the readiness endpoint.');
assert_true(str_contains($developmentCaddy, '/ready'), 'Development Caddy should route readiness to PHP.');
assert_true(str_contains($productionCaddy, 'handle /ready'), 'Production Caddy should route readiness to PHP.');
