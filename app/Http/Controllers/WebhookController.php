<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\WebhookService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class WebhookController
{
    public function create(Request $request): Response
    {
        return Response::ok((new WebhookService())->create($this->apiKey(), $request->json()), 201);
    }

    public function index(Request $request): Response
    {
        return Response::ok((new WebhookService())->list($this->apiKey()));
    }

    public function deliveries(Request $request): Response
    {
        return Response::ok((new WebhookService())->deliveries($this->apiKey(), (string) $request->params['id'], $request->query));
    }

    public function test(Request $request): Response
    {
        return Response::ok((new WebhookService())->test($this->apiKey(), (string) $request->params['id']), 202);
    }

    public function retryDelivery(Request $request): Response
    {
        return Response::ok((new WebhookService())->retryDelivery($this->apiKey(), (string) $request->params['delivery_id']), 202);
    }

    public function rotateSecret(Request $request): Response
    {
        return Response::ok((new WebhookService())->rotateSecret($this->apiKey(), (string) $request->params['id']));
    }

    public function delete(Request $request): Response
    {
        return Response::ok((new WebhookService())->delete($this->apiKey(), (string) $request->params['id']));
    }

    /**
     * @return array<string, mixed>
     */
    private function apiKey(): array
    {
        return App::context()->apiKey() ?? [];
    }
}
