<?php

declare(strict_types=1);

final class TokenHubClient
{
    public function __construct(
        private string $baseUrl = 'http://localhost:8080',
        private string $apiKey = 'replace-with-tokenhub-api-key',
    ) {
        $this->baseUrl = rtrim(getenv('TOKENHUB_BASE_URL') ?: $this->baseUrl, '/');
        $this->apiKey = getenv('TOKENHUB_API_KEY') ?: $this->apiKey;
    }

    public function request(string $method, string $path, array $body = []): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => "Authorization: Bearer {$this->apiKey}\r\nContent-Type: application/json\r\n",
                'content' => $body === [] && $method === 'GET' ? '' : json_encode($body, JSON_UNESCAPED_SLASHES),
            ],
        ]);
        $raw = file_get_contents($this->baseUrl . $path, false, $context);
        return json_decode($raw ?: '{}', true) ?: [];
    }

    public function generateSeedanceVideo(string $prompt, ?string $imageUrl = null): array
    {
        return $this->request('POST', '/v1/videos/generations', ['model' => 'seedance-video', 'prompt' => $prompt, 'image_url' => $imageUrl]);
    }

    public function pollTask(string $taskId): array
    {
        do {
            $result = $this->refreshTask($taskId);
            if (in_array($result['data']['status'] ?? '', ['succeeded', 'failed'], true)) {
                return $result;
            }
            sleep(2);
        } while (true);
    }

    public function refreshTask(string $taskId): array
    {
        return $this->request('POST', '/v1/tasks/' . $taskId . ':refresh');
    }

    public function modelHealth(): array
    {
        return $this->request('GET', '/v1/model-health');
    }

    public function listAssets(?string $mediaType = null): array
    {
        return $this->request('GET', '/v1/assets' . ($mediaType === null ? '' : '?type=' . rawurlencode($mediaType)));
    }

    public function syncBatch(array $records): array
    {
        return $this->request('POST', '/v1/sync/records:batch', ['records' => $records]);
    }

    public function createWebhook(string $url): array
    {
        return $this->request('POST', '/v1/webhooks', ['url' => $url, 'event_types' => ['request.completed']]);
    }

    public function testWebhook(string $webhookId): array
    {
        return $this->request('POST', '/v1/webhooks/' . $webhookId . ':test');
    }

    public function webhookDeliveries(string $webhookId): array
    {
        return $this->request('GET', '/v1/webhooks/' . $webhookId . '/deliveries');
    }

    public function retryWebhookDelivery(string $deliveryId): array
    {
        return $this->request('POST', '/v1/webhook-deliveries/' . $deliveryId . ':retry');
    }

    public function ragKnowledgeBases(): array
    {
        return $this->request('GET', '/v1/rag/knowledge-bases');
    }

    public function ragDatasets(string $kbId): array
    {
        return $this->request('GET', '/v1/rag/knowledge-bases/' . $kbId . '/datasets');
    }

    public function ragUpload(string $kbId, array $payload): array
    {
        return $this->request('POST', '/v1/rag/knowledge-bases/' . $kbId . '/uploads', $payload);
    }

    public function ragConfirmVectorization(string $uploadId): array
    {
        return $this->request('POST', '/v1/rag/uploads/' . $uploadId . '/confirm-vectorization');
    }

    public function ragRetrieval(string $retrievalId): array
    {
        return $this->request('GET', '/v1/rag/retrievals/' . $retrievalId);
    }
}
