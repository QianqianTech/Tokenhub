<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Support\App;

final class ExternalEventService
{
    /**
     * @param array<string, mixed> $apiKey
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function events(array $apiKey, array $query): array
    {
        $limit = min(500, max(1, (int) ($query['limit'] ?? 100)));
        $cursor = isset($query['cursor']) && preg_match('/^evt_(\d+)$/', (string) $query['cursor'], $m) === 1 ? (int) $m[1] : PHP_INT_MAX;
        $page = App::db()->select('SELECT * FROM external_api_events WHERE api_key_id = ? AND id < ? ORDER BY id DESC LIMIT ' . ($limit + 1), [$apiKey['id'] ?? 0, $cursor]);
        $hasMore = count($page) > $limit;
        $page = array_slice($page, 0, $limit);
        $last = end($page);

        return [
            'events' => array_map(fn (array $row): array => $this->event((string) $row['event_type'], (string) $row['source'], $row['resource_id'] ?? null, (string) $row['status'], $row['summary'] ?? null, (string) $row['created_at']), $page),
            'next_cursor' => $hasMore && is_array($last) ? 'evt_' . (int) $last['id'] : null,
            'has_more' => $hasMore,
        ];
    }

    private function event(string $type, string $source, mixed $id, string $status, mixed $summary, string $createdAt): array
    {
        $decoded = is_string($summary) ? json_decode($summary, true) : $summary;
        return [
            'event_id' => $source . '_' . md5((string) $id . $type . $createdAt),
            'event_type' => $type,
            'source' => $source,
            'status' => $status,
            'resource_id' => $id,
            'summary' => is_array($decoded) ? $decoded : null,
            'created_at' => $createdAt,
        ];
    }
}
