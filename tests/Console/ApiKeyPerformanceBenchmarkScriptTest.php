<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$script = __DIR__ . '/../../scripts/api-key-performance-benchmark.php';
$source = is_file($script) ? file_get_contents($script) : false;

assert_true(is_string($source), 'API key performance benchmark script should exist.');
assert_true(str_contains($source, 'TOKENHUB_API_KEY_BENCHMARK_ITERATIONS'), 'Benchmark should allow iteration count through an environment variable.');
assert_true(str_contains($source, '--iterations'), 'Benchmark should allow iteration count through a CLI option.');
assert_true(str_contains($source, 'ApiKeyService'), 'Benchmark should measure API key authentication.');
assert_true(str_contains($source, 'ModelRouter'), 'Benchmark should measure model routing.');
assert_true(str_contains($source, 'QuotaService'), 'Benchmark should measure quota preflight.');
assert_true(str_contains($source, 'ApiKeyModelQuotaService'), 'Benchmark should measure per-model API key quotas.');
assert_true(str_contains($source, 'DesensitizationService'), 'Benchmark should measure desensitization policy lookup.');
assert_true(str_contains($source, 'p50_ms'), 'Benchmark output should include p50 latency.');
assert_true(str_contains($source, 'p95_ms'), 'Benchmark output should include p95 latency.');
assert_true(str_contains($source, 'cache_keys_warmed'), 'Benchmark output should report warmed cache keys.');
assert_true(str_contains($source, 'register_shutdown_function($cleanup)'), 'Benchmark should clean up synthetic records after execution.');
assert_true(str_contains($source, 'JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE'), 'Benchmark should emit readable JSON.');
