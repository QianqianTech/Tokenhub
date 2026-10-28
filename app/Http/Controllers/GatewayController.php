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
        '/v1/completions',
        '/v1/responses',
        '/v1/embeddings',
        '/v1/rerank',
        '/v1/moderations',
        '/v1/messages',
        '/v1/messages/count_tokens',
        '/v1/audio/transcriptions',
        '/v1/audio/translations',
        '/v1/audio/speech',
        '/v1/images/generations',
        '/v1/images/edits',
        '/v1/videos/generations',
        '/v1/videos',
        '/v1/music/generations',
    ];

    public function proxy(Request $request): Response
    {
        return (new GatewayService())->handle($request);
    }

    /**
     * Vendor task callback (no API key: the URL carries a per-task HMAC).
     */
    public function taskCallback(Request $request): Response
    {
        $body = $request->json();
        $result = (new \TokenHub\Services\TaskCallbackService())->handle(
            (string) ($request->params['task_id'] ?? ''),
            (string) ($request->params['signature'] ?? ''),
            is_array($body) ? $body : []
        );

        return Response::json($result['body'], $result['status']);
    }

    public function responsesStateUnsupported(Request $request): Response
    {
        return Response::error('RESPONSES_STATE_UNSUPPORTED', 'Stored response retrieval, deletion and cancellation are not supported by this gateway; send the full conversation or use previous_response_id on the same channel', 501);
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

    public function cancelTask(Request $request): Response
    {
        return Response::ok((new GatewayService())->cancelTask($request->params['id']));
    }

    /** GET /v1/videos/{id} (OpenAI Videos API). */
    public function video(Request $request): Response
    {
        return Response::json((new \TokenHub\Services\Video\OpenAIVideoApi())->retrieve($request->params['id']));
    }

    /** GET /v1/videos/{id}/content?variant=video|thumbnail */
    public function videoContent(Request $request): Response
    {
        $asset = (new \TokenHub\Services\Video\OpenAIVideoApi())->contentAsset($request->params['id'], (string) ($request->query['variant'] ?? 'video'));

        return (new DownloadController())->serveAsset($asset);
    }

    /** DELETE /v1/videos/{id} */
    public function deleteVideo(Request $request): Response
    {
        return Response::json((new \TokenHub\Services\Video\OpenAIVideoApi())->delete($request->params['id']));
    }
}
