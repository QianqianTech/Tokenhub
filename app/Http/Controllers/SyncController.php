<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\UserSyncService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class SyncController
{
    public function registerDevice(Request $request): Response
    {
        return Response::ok((new UserSyncService())->registerDevice($this->apiKey(), $request->json()), 201);
    }

    public function manifest(Request $request): Response
    {
        return Response::ok((new UserSyncService())->manifest($this->apiKey()));
    }

    public function devices(Request $request): Response
    {
        return Response::ok((new UserSyncService())->devices($this->apiKey()));
    }

    public function deleteDevice(Request $request): Response
    {
        return Response::ok((new UserSyncService())->disableDevice($this->apiKey(), (string) $request->params['device_id']));
    }

    public function namespaces(Request $request): Response
    {
        return Response::ok((new UserSyncService())->namespaces($this->apiKey()));
    }

    public function records(Request $request): Response
    {
        return Response::ok((new UserSyncService())->list($this->apiKey(), $request->query));
    }

    public function record(Request $request): Response
    {
        return Response::ok((new UserSyncService())->record(
            $this->apiKey(),
            (string) $request->params['namespace'],
            (string) $request->params['client_record_id']
        ));
    }

    public function batchRecords(Request $request): Response
    {
        return Response::ok((new UserSyncService())->batchUpsert($this->apiKey(), $request->json()));
    }

    public function upsertRecord(Request $request): Response
    {
        return Response::ok((new UserSyncService())->upsert(
            $this->apiKey(),
            (string) $request->params['namespace'],
            (string) $request->params['client_record_id'],
            $request->json()
        ));
    }

    public function deleteRecord(Request $request): Response
    {
        return Response::ok((new UserSyncService())->delete(
            $this->apiKey(),
            (string) $request->params['namespace'],
            (string) $request->params['client_record_id'],
            $request->json()
        ));
    }

    public function resolveConflict(Request $request): Response
    {
        return Response::ok((new UserSyncService())->resolveConflict(
            $this->apiKey(),
            (string) $request->params['namespace'],
            (string) $request->params['client_record_id'],
            $request->json()
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function apiKey(): array
    {
        return App::context()->apiKey() ?? [];
    }
}
