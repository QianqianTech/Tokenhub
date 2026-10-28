<?php

declare(strict_types=1);

namespace TokenHub\Services\Gateway;

use TokenHub\Services\PriceService;

/**
 * Builds the reservation estimate for a request so hard limits hold under
 * concurrency.
 *
 * Reservation = final input estimate + the output the client is allowed to
 * generate. The output bound is the declared max_tokens /
 * max_completion_tokens / max_output_tokens; when the client declares none,
 * GATEWAY_DEFAULT_OUTPUT_RESERVE_TOKENS (default 1024) is reserved. After the
 * response, reservations are replaced by measured usage, so over-reservation
 * is released and only the declared bound can be exceeded (soft overrun is
 * reported as usage_overrun in the usage record).
 *
 * Cost: when a cost limit applies, the same token bound is priced with the
 * model's current price. Unknown price follows GATEWAY_UNKNOWN_PRICE_POLICY:
 * "allow" (default, reserve 0 cost and mark cost_unknown) or "deny".
 */
final class BudgetEstimator
{
    /** @var null|callable(int, array<string, mixed>): array{cost_amount: ?string} */
    private $pricer;

    /**
     * @param null|callable(int, array<string, mixed>): array{cost_amount: ?string} $pricer
     */
    public function __construct(?callable $pricer = null)
    {
        $this->pricer = $pricer;
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
    public function estimate(array $baseEstimate, array $payload, string $capability): array
    {
        $estimate = $baseEstimate;
        if (!in_array($capability, InputModalities::TEXT_FAMILY, true)) {
            return $estimate;
        }
        $input = max((int) ($baseEstimate['input_tokens'] ?? 0), $this->inputTokens($payload));
        $declared = $this->declaredMaxOutput($payload);
        $output = $declared ?? max(0, (int) env_value('GATEWAY_DEFAULT_OUTPUT_RESERVE_TOKENS', 1024));
        $estimate['input_tokens'] = $input;
        $estimate['output_tokens'] = $output;
        $estimate['total_tokens'] = $input + $output;
        $estimate['output_bound'] = $declared !== null ? 'declared' : 'default';

        return $estimate;
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
