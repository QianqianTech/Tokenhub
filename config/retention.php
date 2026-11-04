<?php

declare(strict_types=1);

/*
 * BIZ-009: database retention. Each purge job deletes rows older than N days
 * in batches (DELETE ... LIMIT batch_size) so it never holds long locks.
 * 0 days disables a purge. usage_records / audit_logs are the billing and
 * compliance source of truth: their archive-then-delete job is OFF by default.
 */
return [
    'batch_size' => (int) env('RETENTION_BATCH_SIZE', 5000),
    'max_batches' => (int) env('RETENTION_MAX_BATCHES', 200),
    'usage_aggregates_minute_days' => (int) env('RETENTION_USAGE_MINUTE_DAYS', 14),
    'alert_events_days' => (int) env('RETENTION_ALERT_EVENTS_DAYS', 180),
    'alert_notifications_days' => (int) env('RETENTION_ALERT_NOTIFICATIONS_DAYS', 90),
    'webhook_deliveries_days' => (int) env('RETENTION_WEBHOOK_DELIVERIES_DAYS', 30),
    'dashboard_share_access_logs_days' => (int) env('RETENTION_SHARE_ACCESS_LOG_DAYS', env('SCREEN_ACCESS_LOG_RETENTION_DAYS', 90)),
    'rag_retrieval_logs_days' => (int) env('RETENTION_RAG_RETRIEVAL_LOG_DAYS', 90),
    // Archive (gzip JSONL under storage/archives) then delete. Disabled by default.
    'archive_enabled' => env('RETENTION_ARCHIVE_ENABLED', false),
    'usage_records_days' => (int) env('RETENTION_USAGE_RECORDS_DAYS', 400),
    'audit_logs_days' => (int) env('RETENTION_AUDIT_LOGS_DAYS', 400),
    'archive_path' => env('RETENTION_ARCHIVE_PATH', base_path('storage/archives')),
];
