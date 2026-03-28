<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserList;
use App\Services\ListSyncService;
use App\Services\Lists\ListCatalogService;
use App\Services\Realtime\UserSyncStateBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemplateController extends Controller
{
    public function __construct(
        private readonly ListCatalogService $listCatalogService,
        private readonly ListSyncService $listSyncService,
        private readonly UserSyncStateBroadcaster $syncStateBroadcaster,
    ) {
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'source_list_id' => ['required', 'integer', 'exists:lists,id'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $template = $this->listCatalogService->saveTemplate(
            $request->user(),
            UserList::query()->findOrFail((int) $validated['source_list_id']),
            (string) ($validated['name'] ?? ''),
        );
        $this->syncStateBroadcaster->broadcastToUsers([(int) $request->user()->id], 'template_created', (int) $request->user()->id);

        return response()->json([
            'status' => 'ok',
            'template_id' => (int) $template->id,
            'state' => $this->listSyncService->getState($request->user()),
        ], 201);
    }

    public function destroy(Request $request, UserList $template): JsonResponse
    {
        $this->listCatalogService->deleteList($request->user(), $template);
        $this->syncStateBroadcaster->broadcastToUsers([(int) $request->user()->id], 'template_deleted', (int) $request->user()->id);

        return response()->json([
            'status' => 'ok',
            'state' => $this->listSyncService->getState($request->user()),
        ]);
    }
}
