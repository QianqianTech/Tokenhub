<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Services\PriceService;

/**
 * Builds the reservation estimate for a request so hard limits hold under
 * concurrency.
 *
 * Reservation = final input estimate + the output the request is expected
 * to generate (MT-001):
 *   min(declared bound, reserve limit)
 * The declared bound is max_tokens / max_completion_tokens /
 * max_output_tokens, or GATEWAY_DEFAULT_OUTPUT_RESERVE_TOKENS (default 1024)
 * when the client declares none. The reserve limit is the channel's recent
 * p95 output (OutputTokenStats, x1.2, at least
 * GATEWAY_OUTPUT_RESERVE_MIN_TOKENS) capped by
 * GATEWAY_OUTPUT_RESERVE_CAP_TOKENS (default 8192; 0 = no cap). Clients that
 * send max_tokens 32k-64k on every call therefore no longer hold that whole
 * budget while running. Settlement replaces the reservation with measured
 * usage and charges any difference (RequestLifecycle::settle), so the quota
 * only becomes soft by at most one request's overrun.
 * GATEWAY_OUTPUT_RESERVE_P95=false uses the cap alone; a cap of 0 with p95
 * off restores reserving the full declared bound.
 *
 * Cost: when a cost limit applies, the same token bound is priced with the
 * model's current price. Unknown price follows GATEWAY_UNKNOWN_PRICE_POLICY:
 * "allow" (default, reserve 0 cost and mark cost_unknown) or "deny".
 */
final class BudgetEstimator
{
    /** @var null|callable(int, array<string, mixed>): array{cost_amount: ?string} */
    private $pricer;

    /** @var null|callable(int): ?int */
    private $p95;

    /**
     * @param null|callable(int, array<string, mixed>): array{cost_amount: ?string} $pricer
     * @param null|callable(int): ?int $p95 recent p95 output tokens per model id
     */
    public function __construct(?callable $pricer = null, ?callable $p95 = null)
    {
        $this->pricer = $pricer;
        $this->p95 = $p95;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function declaredMaxOutput(array $payload): ?int
    {
        foreach (['max_completion_tokens', 'max_output_tokens', 'max_tokens'] as $key) {
            if (isset($payload[$key]) && is_numeric($payload[$key]) && (int) $payload[$key] > 0) {
                return (int) $payload[$key];
            }
        }

        return null;
    }

    public function inputTokens(array $payload): int
    {
        unset($payload['rag']);

        return MediaPayload::estimate($payload)['tokens'];
    }

    /**
     * @param array<string, mixed> $baseEstimate parser estimate (keeps media metrics)
     * @param array<string, mixed> $payload final payload sent upstream
     * @return array<string, int|float|string|null>
     */
    public function estimate(array $baseEstimate, array $payload, string $capability, ?array $route = null): array
    {
        $estimate = $baseEstimate;
        if (!in_array($capability, InputModalities::TEXT_FAMILY, true)) {
            return $estimate;
        }
        $input = max((int) ($baseEstimate['input_tokens'] ?? 0), $this->inputTokens($payload));
        $declared = $this->declaredMaxOutput($payload);
        $bound = $declared ?? max(0, (int) env_value('GATEWAY_DEFAULT_OUTPUT_RESERVE_TOKENS', 1024));
        [$limit, $limitSource] = $this->reserveLimit((int) ($route['id'] ?? 0));
        $output = $limit !== null ? min($bound, $limit) : $bound;
        $estimate['input_tokens'] = $input;
        $estimate['output_tokens'] = $output;
        $estimate['total_tokens'] = $input + $output;
        $estimate['output_bound'] = $output < $bound ? $limitSource : ($declared !== null ? 'declared' : 'default');

        return $estimate;
    }

    /**
     * @return array{0: ?int, 1: string} limit (null = none) and its source
     */
    public function reserveLimit(int $modelId): array
    {
        $cap = max(0, (int) env_value('GATEWAY_OUTPUT_RESERVE_CAP_TOKENS', 8192));
        $p95 = null;
        if ($modelId > 0 && filter_var(env_value('GATEWAY_OUTPUT_RESERVE_P95', true), FILTER_VALIDATE_BOOL)) {
            $p95 = is_callable($this->p95) ? ($this->p95)($modelId) : (new OutputTokenStats())->p95($modelId);
        }
        if ($p95 !== null) {
            $floor = max(1, (int) env_value('GATEWAY_OUTPUT_RESERVE_MIN_TOKENS', 256));
            $limit = max($floor, (int) ceil($p95 * 1.2));
            return $cap > 0 && $cap < $limit ? [$cap, 'capped'] : [$limit, 'p95'];
        }

        return $cap > 0 ? [$cap, 'capped'] : [null, 'declared'];
    }

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $estimate
     * @return array{ok: bool, estimate: array<string, mixed>, code?: string}
     */
    public function withCost(array $route, array $estimate): array
    {
        if (empty($route['id'])) {
            $estimate['cost_unknown'] = true;
            return $this->unknownPrice($estimate);
        }
        $priced = is_callable($this->pricer)
            ? ($this->pricer)((int) $route['id'], $estimate)
            : (new PriceService())->priceUsage((int) $route['id'], $estimate);
        if ($priced['cost_amount'] === null) {
            $estimate['cost_unknown'] = true;
            return $this->unknownPrice($estimate);
        }
        $estimate['cost_amount'] = (float) $priced['cost_amount'];

        return ['ok' => true, 'estimate' => $estimate];
    }

    /**
     * Positive differences between a new and an already reserved estimate.
     *
     * @param array<string, mixed> $reserved
     * @param array<string, mixed> $next
     * @return array<string, int|float>
     */
    public function delta(array $reserved, array $next): array
    {
        $delta = [];
        foreach (['input_tokens', 'output_tokens', 'total_tokens'] as $metric) {
            $diff = (int) ($next[$metric] ?? 0) - (int) ($reserved[$metric] ?? 0);
            if ($diff > 0) {
                $delta[$metric] = $diff;
            }
        }
        $cost = (float) ($next['cost_amount'] ?? 0) - (float) ($reserved['cost_amount'] ?? 0);
        if ($cost > 0) {
            $delta['cost_amount'] = $cost;
        }

        return $delta;
    }

    /**
     * @param array<string, mixed> $estimate
     * @return array{ok: bool, estimate: array<string, mixed>, code?: string}
     */
    private function unknownPrice(array $estimate): array
    {
        if (strtolower((string) env_value('GATEWAY_UNKNOWN_PRICE_POLICY', 'allow')) === 'deny') {
            return ['ok' => false, 'estimate' => $estimate, 'code' => 'PRICE_UNKNOWN'];
        }

        return ['ok' => true, 'estimate' => $estimate];
    }
}
