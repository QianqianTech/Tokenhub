<?php

declare(strict_types=1);

require __DIR__ . '/../bootstrap.php';

$source = file_get_contents(__DIR__ . '/../../app/Services/DashboardService.php');
$workerSource = file_get_contents(__DIR__ . '/../../app/Services/WorkerService.php');
$usageSource = file_get_contents(__DIR__ . '/../../app/Services/UsageService.php');
$schema = file_get_contents(__DIR__ . '/../../database/migrations/0001_create_tokenhub_schema.sql');
assert_true(is_string($source), 'DashboardService source should be readable.');
assert_true(is_string($workerSource), 'WorkerService source should be readable.');
assert_true(is_string($usageSource), 'UsageService source should be readable.');
assert_true(is_string($schema), 'Schema source should be readable.');

assert_true(str_contains($source, 'usage_aggregates_hour'), 'Dashboard trends should read hourly aggregate data.');
assert_true(str_contains($source, 'usage_aggregates_day'), 'Dashboard should support daily aggregate data.');
assert_true(!preg_match('/FROM\s+usage_records/i', $source), 'Dashboard service must not aggregate directly from usage_records.');
assert_true(!preg_match('/JOIN\s+usage_records/i', $source), 'Dashboard service must not join usage_records for dashboard metrics.');
assert_true(str_contains($source, 'function security('), 'Dashboard security metric should be implemented.');
assert_true(str_contains($source, 'function performance('), 'Dashboard performance metric should be implemented.');
assert_true(str_contains($source, 'function multimodal('), 'Dashboard multimodal metric should be implemented.');
assert_true(str_contains($source, 'PermissionService'), 'Dashboard aggregate queries should apply actor permission scope.');
assert_true(str_contains($source, 'p95_latency_ms'), 'Dashboard performance metric should expose P95 latency.');
assert_true(str_contains($source, 'p99_latency_ms'), 'Dashboard performance metric should expose P99 latency.');
assert_true(str_contains($source, 'blocked_ips'), 'Dashboard security metric should expose blocked IP data.');
assert_true(str_contains($source, 'client_ip'), 'Dashboard security metric should use client IP data.');
assert_true(str_contains($schema, 'latency_bucket_100'), 'Aggregate schema should store latency buckets.');
assert_true(str_contains($workerSource, 'new UsageService())->write'), 'Worker usage aggregation should delegate to UsageService.');
assert_true(str_contains($usageSource, 'latencyBuckets'), 'Usage service aggregation should maintain latency buckets.');
assert_true(str_contains($source, 'percentileFromBuckets'), 'Dashboard P95/P99 should be derived from aggregate latency buckets.');
