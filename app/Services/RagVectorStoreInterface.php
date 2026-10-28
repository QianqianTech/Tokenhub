<?php

declare(strict_types=1);

namespace TokenHub\Services;

interface RagVectorStoreInterface
{
    /**
     * @param list<float> $vector
     * @param array<string, mixed> $payload
     */
    public function upsert(string $collection, string $vectorId, array $vector, array $payload): void;

    /**
     * @param list<float> $vector
     * @param array<string, mixed> $filter
     * @return list<array{vector_id: string, score: float, payload: array<string, mixed>}>
     */
    public function search(string $collection, array $vector, int $topK, array $filter = []): array;

    /**
     * @param list<string> $vectorIds
     */
    public function delete(string $collection, array $vectorIds): void;

    public function healthy(): bool;
}

