<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\GatewayService;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class GatewayController
{
    public const ENDPOINTS = [
        '/v1/chat/completions',
        '/v1/responses',
        '/v1/embeddings',
        '/v1/messages',
        '/v1/messages/count_tokens',
        '/v1/audio/transcriptions',
        '/v1/audio/speech',
        '/v1/images/generations',
        '/v1/videos/generations',
    ];

    public function proxy(Request $request): Response
    {
        return (new GatewayService())->handle($request);
    }

    public function task(Request $request): Response
    {
        return Response::ok((new GatewayService())->task($request->params['id']));
    }

    public function refreshTask(Request $request): Response
    {
        return Response::ok((new GatewayService())->refreshTask($request->params['id']));
    }

    public function assetDownloadUrl(Request $request): Response
    {
        return Response::ok((new GatewayService())->assetDownloadUrl($request->params['id']));
    }
}
