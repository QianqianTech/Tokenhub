<?php

declare(strict_types=1);

namespace TokenHub\Services;

use TokenHub\Http\Controllers\GatewayController;
use Throwable;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class IdempotencyService
{
    /**
     * MT-004: task-type gateway endpoints (video, image, music generation)
     * create billable upstream jobs, so a retried submission with the same
     * Idempotency-Key replays the stored answer instead of running twice.
     * Streaming requests are never stored.
     */
    public const TASK_ENDPOINTS = [
        '/v1/videos/generations',
        '/v1/videos',
        '/v1/images/generations',
        '/v1/images/edits',
        '/v1/music/generations',
    ];

    /** Task responses larger than this are not stored (the record is dropped). */
    private const MAX_STORED_BYTES = 8 * 1024 * 1024;

    public function isEligible(Request $request): bool
    {
        if (!in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true) || !str_starts_with($request->path, '/v1/')) {
            return false;
        }
        if ($this->isTaskEndpoint($request)) {
            return ($request->body['stream'] ?? false) !== true;
        }

        return !in_array($request->path, GatewayController::ENDPOINTS, true);
    }

    public function isTaskEndpoint(Request $request): bool
    {
        return $request->method === 'POST' && in_array($request->path, self::TASK_ENDPOINTS, true);
    }

    public function requestHash(Request $request): string
    {
        return hash('sha256', $request->method . "\n" . $request->path . "\n" . $request->rawBody);
    }

    /**
     * @param array<string, mixed> $apiKey
     */
    public function handle(Request $request, array $apiKey, callable $next): Response
    {
        $key = trim((string) $request->header('idempotency-key', ''));
        if ($key === '' || !$this->isEligible($request)) {
            return $next($request);
        }
        if (strlen($key) > 120 || preg_match('/^[A-Za-z0-9_.:-]+$/', $key) !== 1) {
            return Response::error('IDEMPOTENCY_KEY_INVALID', 'Idempotency-Key is invalid', 422);
        }

        $apiKeyId = (int) ($apiKey['id'] ?? 0);
        $hash = $this->requestHash($request);
        App::db()->execute('DELETE FROM api_idempotency_records WHERE expires_at < UTC_TIMESTAMP()');
        $row = App::db()->first('SELECT * FROM api_idempotency_records WHERE api_key_id = ? AND idempotency_key = ?', [$apiKeyId, $key]);
        if ($row !== null) {
            if (!hash_equals((string) $row['request_hash'], $hash)) {
                return Response::error('IDEMPOTENCY_CONFLICT', 'Idempotency-Key was already used for a different request', 409);
            }
            if ((string) $row['status'] !== 'completed') {
                return Response::error('IDEMPOTENCY_IN_PROGRESS', 'The original request is still processing', 409);
            }
            $headers = json_decode((string) ($row['response_headers'] ?? '{}'), true);
            return new Response((string) $row['response_body'], (int) $row['response_status'], is_array($headers) ? $headers : []);
        }

        try {
            App::db()->execute(
                'INSERT INTO api_idempotency_records (api_key_id, idempotency_key, request_method, request_path, request_hash, status, expires_at) VALUES (?, ?, ?, ?, ?, "processing", DATE_ADD(UTC_TIMESTAMP(), INTERVAL 24 HOUR))',
                [$apiKeyId, $key, $request->method, $request->path, $hash]
            );
        } catch (\PDOException $exception) {
            // A concurrent request with the same key won the unique index.
            if ($this->isTaskEndpoint($request) && (string) $exception->getCode() === '23000') {
                return Response::error('IDEMPOTENCY_IN_PROGRESS', 'The original request is still processing', 409);
            }
            throw $exception;
        }

        try {
            $response = $next($request);
            if ($this->isTaskEndpoint($request) && ($response->status >= 500 || $response->status === 429 || $response->body === '' || strlen($response->body) > self::MAX_STORED_BYTES)) {
                // Transient failures (and streamed or oversized answers) are
                // not replayed: the client may retry with the same key.
                App::db()->execute('DELETE FROM api_idempotency_records WHERE api_key_id = ? AND idempotency_key = ? AND status = "processing"', [$apiKeyId, $key]);
                return $response;
            }
            App::db()->execute(
                'UPDATE api_idempotency_records SET status = "completed", response_status = ?, response_body = ?, response_headers = ? WHERE api_key_id = ? AND idempotency_key = ?',
                [$response->status, $response->body, json_encode($response->headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $apiKeyId, $key]
            );
            return $response;
        } catch (Throwable $throwable) {
            App::db()->execute('DELETE FROM api_idempotency_records WHERE api_key_id = ? AND idempotency_key = ? AND status = "processing"', [$apiKeyId, $key]);
            throw $throwable;
        }
    }
}
