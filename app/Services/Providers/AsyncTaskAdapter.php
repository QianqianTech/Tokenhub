<?php

declare(strict_types=1);

namespace TokenHub\Services\Providers;

/**
 * Adapters whose upstream works as "create task -> poll task" (MM-010).
 *
 * pollRequest() returns the upstream call that reads a task's state;
 * normalizePollResponse() turns its answer into the canonical task view:
 *   status            queued | processing | succeeded | failed
 *   assets            [{url|b64_json, mime_type}] or [{download: {endpoint}, mime_type}] (needs the channel credential)
 *   error             {code, message}
 *   usage             canonical usage measured by the upstream (video_seconds, upstream_cost, ...)
 *   follow_up         a second request to fetch results once the task finished (fal)
 */
interface AsyncTaskAdapter
{
    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $taskResult multimodal_tasks.result (decoded)
     * @return array{method: string, endpoint: string, headers?: array<string, string>, json?: array<string, mixed>}|null
     */
    public function pollRequest(array $route, string $capability, string $providerTaskId, array $taskResult = []): ?array;

    /**
     * @param array<string, mixed> $route
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    public function normalizePollResponse(string $capability, array $route, array $response, string $providerTaskId): array;
}
