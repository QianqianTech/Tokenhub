<?php

declare(strict_types=1);

namespace TokenHub\Http\Controllers;

use TokenHub\Services\RagAclService;
use TokenHub\Support\App;
use TokenHub\Support\Request;
use TokenHub\Support\Response;

/** Admin endpoints for knowledge-set access control (RAG-012). */
final class RagAclController
{
    public function knowledgeBaseAcl(Request $request): Response
    {
        return Response::ok((new RagAclService())->knowledgeBaseAcl($this->admin(), (int) $request->params['id']));
    }

    public function replaceKnowledgeBaseAcl(Request $request): Response
    {
        return Response::ok((new RagAclService())->replaceKnowledgeBaseAcl($this->admin(), (int) $request->params['id'], $request->json()));
    }

    public function replaceDatasetAcl(Request $request): Response
    {
        return Response::ok((new RagAclService())->replaceDatasetAcl($this->admin(), (int) $request->params['id'], $request->json()));
    }

    public function accessCheck(Request $request): Response
    {
        return Response::ok((new RagAclService())->accessCheck($this->admin(), $request->query));
    }

    /** @return array<string, mixed> */
    private function admin(): array
    {
        $admin = App::context()->adminUser();
        if ($admin === null) {
            abort_json('ADMIN_UNAUTHENTICATED', 'Admin login required', 401);
        }

        return $admin;
    }
}
