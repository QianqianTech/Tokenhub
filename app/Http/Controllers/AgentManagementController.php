<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\AgentManagementApiKeyService;
use TokenHub\Services\AgentManagementDepartmentService;
use TokenHub\Services\AgentManagementUsageService;
use TokenHub\Services\AgentManagementUserService;
use TokenHub\Services\AgentManagementRagService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

final class AgentManagementController
{
    public function department(Request $request): Response { return Response::ok((new AgentManagementDepartmentService())->current($this->actorKey())); }
    public function usageSummary(Request $request): Response { return Response::ok((new AgentManagementUsageService())->summary($this->actorKey(), $request->query)); }
    public function usageTrends(Request $request): Response { return Response::ok((new AgentManagementUsageService())->trends($this->actorKey(), $request->query)); }
    public function user(Request $request): Response { return Response::ok((new AgentManagementUserService())->show((int) $request->params['id'], $this->actorKey())); }
    public function showApiKey(Request $request): Response { return Response::ok((new AgentManagementApiKeyService())->show((int) $request->params['id'], $this->actorKey())); }
    public function updateApiKey(Request $request): Response { return Response::ok((new AgentManagementApiKeyService())->update((int) $request->params['id'], $this->actorKey(), $request->json())); }
    public function rotateApiKey(Request $request): Response { return Response::ok((new AgentManagementApiKeyService())->rotate((int) $request->params['id'], $this->actorKey(), $request->json()), 201); }
    public function apiKeyQuota(Request $request): Response { return Response::ok((new AgentManagementApiKeyService())->quota((int) $request->params['id'], $this->actorKey())); }
    public function users(Request $request): Response { return Response::ok((new AgentManagementUserService())->list($this->actorKey(), $request->query)); }
    public function createUser(Request $request): Response { return Response::ok((new AgentManagementUserService())->create($this->actorKey(), $request->json()), 201); }
    public function updateUser(Request $request): Response { return Response::ok((new AgentManagementUserService())->update((int) $request->params['id'], $this->actorKey(), $request->json())); }
    public function deleteUser(Request $request): Response { return Response::ok((new AgentManagementUserService())->disable((int) $request->params['id'], $this->actorKey())); }
    public function apiKeys(Request $request): Response { return Response::ok((new AgentManagementApiKeyService())->list($this->actorKey(), $request->query)); }
    public function createApiKey(Request $request): Response { return Response::ok((new AgentManagementApiKeyService())->create($this->actorKey(), $request->json()), 201); }
    public function replaceApiKeyModels(Request $request): Response
    {
        $modelIds = $request->json()['model_ids'] ?? null;
        if (!is_array($modelIds) || $modelIds === [] || array_filter($modelIds, static fn (mixed $id): bool => (!is_int($id) && !(is_string($id) && ctype_digit($id))) || ((int) $id) <= 0) !== []) {
            abort_json('MANAGEMENT_INVALID_PAYLOAD', 'model_ids must be a non-empty array of positive integers', 422);
        }
        return Response::ok((new AgentManagementApiKeyService())->replaceModels((int) $request->params['id'], $this->actorKey(), $modelIds));
    }
    public function deleteApiKey(Request $request): Response { return Response::ok((new AgentManagementApiKeyService())->delete((int) $request->params['id'], $this->actorKey())); }
    public function knowledgeBases(Request $request): Response { return Response::ok((new AgentManagementRagService())->knowledgeBases($this->actorKey(), $request->query)); }
    public function createKnowledgeBase(Request $request): Response { return Response::ok((new AgentManagementRagService())->createKnowledgeBase($this->actorKey(), $request->json()), 201); }
    public function updateKnowledgeBase(Request $request): Response { return Response::ok((new AgentManagementRagService())->updateKnowledgeBase((int) $request->params['id'], $this->actorKey(), $request->json())); }
    public function deleteKnowledgeBase(Request $request): Response { return Response::ok((new AgentManagementRagService())->deleteKnowledgeBase((int) $request->params['id'], $this->actorKey())); }
    public function datasets(Request $request): Response { return Response::ok((new AgentManagementRagService())->datasets($this->actorKey(), $request->query)); }
    public function createDataset(Request $request): Response { return Response::ok((new AgentManagementRagService())->createDataset($this->actorKey(), $request->json()), 201); }
    public function updateDataset(Request $request): Response { return Response::ok((new AgentManagementRagService())->updateDataset((int) $request->params['id'], $this->actorKey(), $request->json())); }
    public function deleteDataset(Request $request): Response { return Response::ok((new AgentManagementRagService())->deleteDataset((int) $request->params['id'], $this->actorKey())); }
    private function actorKey(): array { return App::context()->apiKey() ?? []; }
}
