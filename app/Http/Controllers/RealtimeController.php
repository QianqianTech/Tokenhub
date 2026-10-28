<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\RealtimeService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

/**
 * Realtime relay endpoints (MM-030). /internal/* is called only by the Go
 * realtime-gateway with X-TokenHub-Internal-Token (REALTIME_INTERNAL_TOKEN)
 * and must not be exposed publicly (see the Caddyfile).
 */
final class RealtimeController
{
    public function sessions(Request $request): Response
    {
        $service = new RealtimeService();
        if (!$service->internalTokenValid($request->header('x-tokenhub-internal-token'))) {
            return Response::error('FORBIDDEN', 'Internal endpoint', 403);
        }
        $body = $request->json();
        $result = $service->authorize((string) ($body['api_key'] ?? ''), (string) ($body['model'] ?? ''), (string) ($body['client_ip'] ?? '127.0.0.1'));

        return $result['ok'] ? Response::ok($result['data']) : Response::error((string) $result['code'], (string) $result['message'], (int) $result['status']);
    }

    public function usage(Request $request): Response
    {
        $service = new RealtimeService();
        if (!$service->internalTokenValid($request->header('x-tokenhub-internal-token'))) {
            return Response::error('FORBIDDEN', 'Internal endpoint', 403);
        }
        $body = $request->json();
        $result = $service->recordUsage(
            (string) ($body['session_id'] ?? ''),
            is_array($body['usage'] ?? null) ? $body['usage'] : [],
            (string) ($body['status'] ?? 'success'),
            (string) ($body['error_code'] ?? ''),
            (int) ($body['duration_ms'] ?? 0),
            ($body['final'] ?? false) === true,
        );

        return $result['ok'] ? Response::ok($result['data']) : Response::error((string) $result['code'], (string) $result['message'], (int) $result['status']);
    }

    /**
     * Short-lived client secret for browsers (API key auth).
     */
    public function clientSecret(Request $request): Response
    {
        $body = $request->json();
        $model = (string) ($body['model'] ?? $body['session']['model'] ?? '');
        if ($model === '') {
            return Response::error('MODEL_REQUIRED', 'model is required', 422);
        }

        return Response::ok((new RealtimeService())->issueClientSecret(App::context()->apiKey() ?? [], $model, (int) ($body['expires_in'] ?? 60)));
    }
}
