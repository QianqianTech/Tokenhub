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
// screenSecurity() is the one exception: a scoped screen maps today's desensitization
// events to its own requests by request id (an id lookup, not an aggregate).
$securityStart = strpos($source, 'public function screenSecurity(');
$securityEnd = $securityStart === false ? false : strpos($source, "\n    }\n", $securityStart);
assert_true($securityStart !== false && $securityEnd !== false, 'Dashboard should implement a scoped screen security panel.');
$securitySource = substr($source, $securityStart, $securityEnd - $securityStart);
assert_true(str_contains($securitySource, 'BusinessTime::periodStartUtc(\'day\')'), 'Screen security panel should be limited to today in the business time zone.');
assert_true(str_contains($securitySource, 'r.created_at >= ?'), 'Scoped screen request lookup should be bounded to today.');
$aggregateSource = substr($source, 0, $securityStart) . substr($source, $securityEnd);
assert_true(!preg_match('/FROM\s+usage_records/i', $aggregateSource), 'Dashboard service must not aggregate directly from usage_records.');
assert_true(!preg_match('/JOIN\s+usage_records/i', $aggregateSource), 'Dashboard service must not join usage_records for dashboard metrics.');
assert_true(str_contains($source, 'BusinessTime::periodStartUtc'), 'Dashboard periods should follow the business time zone.');
assert_true(!str_contains($source, "gmdate('Y-m-01 00:00:00'"), 'Dashboard periods must not be computed in UTC.');
assert_true(str_contains($source, 'function security('), 'Dashboard security metric should be implemented.');
assert_true(str_contains($source, 'function performance('), 'Dashboard performance metric should be implemented.');
assert_true(str_contains($source, 'function multimodal('), 'Dashboard multimodal metric should be implemented.');
assert_true(str_contains($source, 'PermissionService'), 'Dashboard aggregate queries should apply actor permission scope.');
assert_true(str_contains($source, 'p95_latency_ms'), 'Dashboard performance metric should expose P95 latency.');
assert_true(str_contains($source, 'p99_latency_ms'), 'Dashboard performance metric should expose P99 latency.');
assert_true(!str_contains($source, 'waf_events'), 'Dashboard security metric should not query removed WAF events.');
assert_true(str_contains($schema, 'latency_bucket_100'), 'Aggregate schema should store latency buckets.');
assert_true(str_contains($workerSource, 'new UsageService())->write'), 'Worker usage aggregation should delegate to UsageService.');
assert_true(str_contains($usageSource, 'latencyBuckets'), 'Usage service aggregation should maintain latency buckets.');
assert_true(str_contains($source, 'percentileFromBuckets'), 'Dashboard P95/P99 should be derived from aggregate latency buckets.');
