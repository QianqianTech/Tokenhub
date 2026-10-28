<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\ExternalUsageService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class UsageController
{
    public function summary(Request $request): Response
    {
        return Response::ok((new ExternalUsageService())->summary($this->apiKey(), $request->query));
    }

    public function trends(Request $request): Response
    {
        return Response::ok((new ExternalUsageService())->trends($this->apiKey(), $request->query));
    }

    /**
     * @return array<string, mixed>
     */
    private function apiKey(): array
    {
        return App::context()->apiKey() ?? [];
    }
}

