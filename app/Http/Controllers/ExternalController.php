<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\ExternalEventService;
use TokenHub\Services\ExternalFeedbackService;
use TokenHub\Services\ExternalIdentityService;
use TokenHub\Services\ExternalModelService;
use TokenHub\Services\ExternalQuotaService;
use TokenHub\Services\ExternalRequestService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class ExternalController
{
    public function me(Request $request): Response
    {
        return Response::ok((new ExternalIdentityService())->me($this->apiKey()));
    }

    public function models(Request $request): Response
    {
        return Response::json((new ExternalModelService())->authorized($this->apiKey()));
    }

    public function modelDetail(Request $request): Response
    {
        return Response::ok((new ExternalModelService())->detail($this->apiKey(), (string) $request->params['model']));
    }

    public function modelHealth(Request $request): Response
    {
        return Response::ok((new ExternalModelService())->health($this->apiKey()));
    }

    public function quota(Request $request): Response
    {
        return Response::ok((new ExternalQuotaService())->current($this->apiKey()));
    }

    public function usageRecords(Request $request): Response
    {
        return Response::ok((new ExternalRequestService())->records($this->apiKey(), $request->query));
    }

    public function requestDetail(Request $request): Response
    {
        return Response::ok((new ExternalRequestService())->detail($this->apiKey(), (string) $request->params['request_id']));
    }

    public function capabilities(Request $request): Response
    {
        return Response::ok((new ExternalIdentityService())->capabilities());
    }

    public function openapi(Request $request): Response
    {
        $path = base_path('public' . DIRECTORY_SEPARATOR . 'openapi.json');
        if (!is_file($path)) {
            abort_json('OPENAPI_NOT_FOUND', 'OpenAPI document not found', 404);
        }

        return Response::raw((string) file_get_contents($path), 200, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public function events(Request $request): Response
    {
        return Response::ok((new ExternalEventService())->events($this->apiKey(), $request->query));
    }

    public function feedback(Request $request): Response
    {
        return Response::ok((new ExternalFeedbackService())->create($this->apiKey(), $request->json()), 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function apiKey(): array
    {
        return App::context()->apiKey() ?? [];
    }
}
