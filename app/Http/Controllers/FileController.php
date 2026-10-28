<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\ExternalFileService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class FileController
{
    public function upload(Request $request): Response
    {
        return Response::ok((new ExternalFileService())->upload($this->apiKey(), $request->json()), 201);
    }

    public function assets(Request $request): Response
    {
        return Response::ok((new ExternalFileService())->list($this->apiKey(), $request->query));
    }

    public function downloadUrl(Request $request): Response
    {
        return Response::ok((new ExternalFileService())->downloadUrl($this->apiKey(), (string) $request->params['file_id']));
    }

    public function delete(Request $request): Response
    {
        return Response::ok((new ExternalFileService())->delete($this->apiKey(), (string) $request->params['file_id']));
    }

    /**
     * @return array<string, mixed>
     */
    private function apiKey(): array
    {
        return App::context()->apiKey() ?? [];
    }
}
