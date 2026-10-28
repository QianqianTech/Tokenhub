<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;
use TokenHub\Support\Crypto;

final class ExternalEventOutboxService
{
    /** @param array<string, mixed> $summary */
    public function append(int $apiKeyId, string $type, string $source, ?string $resourceId, string $status, array $summary = []): void
    {
        if ($apiKeyId <= 0) {
            return;
        }
        App::db()->execute('INSERT INTO external_api_events (event_id, api_key_id, event_type, source, resource_id, status, summary) VALUES (?, ?, ?, ?, ?, ?, ?)', [Crypto::token('evt_'), $apiKeyId, $type, $source, $resourceId, $status, json_encode($summary, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)]);
    }
}
