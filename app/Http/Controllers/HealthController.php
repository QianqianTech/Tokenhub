<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class HealthController
{
    public function public(Request $request): Response
    {
        return $this->status();
    }

    public function admin(Request $request): Response
    {
        return $this->status();
    }

    public function ready(Request $request): Response
    {
        $readiness = (new \TokenHub\Services\HealthCheckService(App::db(), App::redis()))->readiness();
        return Response::ok($readiness, $readiness['healthy'] ? 200 : 503);
    }

    private function status(): Response
    {
        $database = App::db()->healthy();
        $redis = App::redis()->healthy();
        $ok = $database['ok'] && $redis['ok'];

        return Response::ok([
            'status' => $ok ? 'healthy' : 'degraded',
            'php' => PHP_VERSION,
            'mysql' => $database,
            'redis' => $redis,
        ], $ok ? 200 : 503);
    }
}
