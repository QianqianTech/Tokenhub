<?php

declare(strict_types=1);

namespace TokenHub\Services;

use Throwable;
use TokenHub\Support\App;
use TokenHub\Support\SchemaInspector;

/**
 * OpenRouter generation reconciliation (queue job "openrouter.reconcile").
 *
 * The gateway stores the generation id (upstream_request_id), the actual
 * model/provider and usage.cost from the response. This job asks
 * GET {base}/generation?id=... for the authoritative native token counts and
 * total cost, stores them in usage_records.reconciliation and marks the row
 * reconciled. The UPDATE is guarded by "reconciled_at IS NULL", so the same
 * generation is never reconciled (or charged) twice.
 */
final class OpenRouterGenerationService
{
    public function __construct(private readonly ?ProviderHttpClient $client = null)
    {
    }

    public function reconcilePending(int $limit = 50): int
    {
        if (!SchemaInspector::hasColumn('usage_records', 'reconciled_at')) {
            return 0;
        }
        $rows = App::db()->select(
            'SELECT r.id, r.upstream_request_id, r.model_id, r.input_tokens, r.output_tokens, r.cost_amount, p.id AS provider_id, p.base_url, p.encrypted_secret, p.default_headers, p.protocol, p.timeout_ms, p.retry_count
             FROM usage_records r
             JOIN providers p ON p.id = r.provider_id
             WHERE r.reconciled_at IS NULL
               AND r.upstream_request_id LIKE "gen-%"
               AND LOWER(p.base_url) LIKE "%openrouter.ai%"
               AND r.created_at <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 60 SECOND)
             ORDER BY r.id ASC
             LIMIT ' . max(1, min(500, $limit))
        );
        $done = 0;
        foreach ($rows as $row) {
            try {
                if ($this->reconcileRow($row)) {
                    $done++;
                }
            } catch (Throwable $throwable) {
                App::logger()->warning('provider', 'openrouter reconciliation failed', ['usage_record_id' => $row['id'], 'error' => $throwable->getMessage()]);
            }
        }

        return $done;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function reconcileRow(array $row): bool
    {
        $response = ($this->client ?? new ProviderHttpClient())->send(
            '/v1/generation?id=' . rawurlencode((string) $row['upstream_request_id']),
            $row,
            ['method' => 'GET', 'headers' => ['Accept' => 'application/json']]
        );
        if ($response['status'] === 404) {
            // Generation metadata can lag behind the response; retry later.
            return false;
        }
        if ($response['status'] >= 400 || !is_array($response['json'])) {
            return false;
        }
        $summary = $this->summarize($response['json'], $row);

        return App::db()->execute(
            'UPDATE usage_records SET reconciled_at = UTC_TIMESTAMP(), reconciliation = ?, upstream_cost = COALESCE(?, upstream_cost), upstream_provider = COALESCE(?, upstream_provider) WHERE id = ? AND reconciled_at IS NULL',
            [json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $summary['upstream_cost'], $summary['provider_name'], (int) $row['id']]
        ) === 1;
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public function summarize(array $payload, array $row): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : $payload;
        $cost = isset($data['total_cost']) && is_numeric($data['total_cost']) ? (string) $data['total_cost'] : null;
        $nativePrompt = isset($data['native_tokens_prompt']) ? (int) $data['native_tokens_prompt'] : null;
        $nativeCompletion = isset($data['native_tokens_completion']) ? (int) $data['native_tokens_completion'] : null;

        return [
            'source' => 'openrouter_generation',
            'upstream_cost' => $cost,
            'provider_name' => isset($data['provider_name']) ? (string) $data['provider_name'] : null,
            'model' => isset($data['model']) ? (string) $data['model'] : null,
            'native_tokens_prompt' => $nativePrompt,
            'native_tokens_completion' => $nativeCompletion,
            'native_tokens_reasoning' => isset($data['native_tokens_reasoning']) ? (int) $data['native_tokens_reasoning'] : null,
            'recorded_input_tokens' => (int) ($row['input_tokens'] ?? 0),
            'recorded_output_tokens' => (int) ($row['output_tokens'] ?? 0),
            'recorded_cost_amount' => $row['cost_amount'] ?? null,
            'token_delta' => $nativePrompt === null || $nativeCompletion === null ? null : ($nativePrompt + $nativeCompletion) - ((int) ($row['input_tokens'] ?? 0) + (int) ($row['output_tokens'] ?? 0)),
            'checked_at' => gmdate('c'),
        ];
    }
}
