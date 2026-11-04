<?php

declare(strict_types=1);

/**
 * Usage reconciliation.
 *
 *   php bin/usage-reconcile.php --resume                 finish stuck usage rows
 *   php bin/usage-reconcile.php --check --from=... --to=...  report aggregate drift
 *   php bin/usage-reconcile.php --rebuild --from="2026-10-01 00:00:00" --to="2026-10-02 00:00:00"
 *   php bin/usage-reconcile.php --openrouter [--limit=50] reconcile OpenRouter generations
 *   php bin/usage-reconcile.php --drift-alert [--hours=24]   check cost/token drift, raise or resolve the usage_drift alert
 *   php bin/usage-reconcile.php --rebuild-quota [--policy=ID] [--dry-run]
 *                                    recompute the current period's Redis quota counters
 *                                    from usage_records (after a Redis data loss)
 *
 * Times are UTC. --rebuild replaces minute/hour/day aggregates in the range
 * with sums of usage_records (detail rows are the source of truth).
 */

require __DIR__ . '/_bootstrap.php';

use TokenHub\Services\OpenRouterGenerationService;
use TokenHub\Services\UsageReconciliationService;
use TokenHub\Services\UsageService;

$from = cli_option($argv, 'from', gmdate('Y-m-d 00:00:00'));
$to = cli_option($argv, 'to', gmdate('Y-m-d H:i:s'));
$result = [];
if (cli_option($argv, 'resume') !== null) {
    $result['resumed'] = (new UsageService())->resumePending((int) cli_option($argv, 'limit', '500'), 0);
}
if (cli_option($argv, 'check') !== null) {
    $result['drift'] = (new UsageReconciliationService())->drift((string) $from, (string) $to);
}
if (cli_option($argv, 'rebuild') !== null) {
    $result['rebuilt'] = (new UsageService())->rebuildAggregates((string) $from, (string) $to);
}
if (cli_option($argv, 'openrouter') !== null) {
    $result['openrouter_reconciled'] = (new OpenRouterGenerationService())->reconcilePending((int) cli_option($argv, 'limit', '50'));
}
if (cli_option($argv, 'drift-alert') !== null) {
    $result['drift_alert'] = (new UsageReconciliationService())->checkDriftAndAlert((int) cli_option($argv, 'hours', '24'));
}
if (cli_option($argv, 'rebuild-quota') !== null) {
    $policy = cli_option($argv, 'policy');
    $rebuilt = (new UsageReconciliationService())->rebuildQuotaCounters($policy !== null && $policy !== true && $policy !== '' ? (int) $policy : null, cli_option($argv, 'dry-run') !== null);
    unset($rebuilt['values']);
    $result['quota_rebuilt'] = $rebuilt;
}
if ($result === []) {
    fwrite(STDERR, "Nothing to do; see file header for options\n");
    exit(1);
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
