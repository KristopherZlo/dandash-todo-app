<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\UserList;
use App\Services\ListSyncService;
use App\Services\Lists\ListCatalogService;
use App\Services\Realtime\UserSyncStateBroadcaster;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ListController extends Controller
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
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $list = $this->listCatalogService->createList($request->user(), (string) ($validated['name'] ?? ''));
        $this->syncStateBroadcaster->broadcastToUsers([(int) $request->user()->id], 'list_created', (int) $request->user()->id);

        return response()->json([
            'status' => 'ok',
            'list_id' => (int) $list->id,
            'state' => $this->listSyncService->getState($request->user()),
        ], 201);
    }

    public function update(Request $request, UserList $list): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
        ]);

        $this->listCatalogService->renameList($request->user(), $list, (string) $validated['name']);
        $memberUserIds = $list->members()->pluck('user_id')->map(static fn ($value): int => (int) $value)->all();
        $this->syncStateBroadcaster->broadcastToUsers($memberUserIds, 'list_renamed', (int) $request->user()->id);

        return response()->json([
            'status' => 'ok',
            'state' => $this->listSyncService->getState($request->user()),
        ]);
    }

    public function destroy(Request $request, UserList $list): JsonResponse
    {
        $memberUserIds = $list->members()->pluck('user_id')->map(static fn ($value): int => (int) $value)->all();
        $this->listCatalogService->deleteList($request->user(), $list);
        $this->syncStateBroadcaster->broadcastToUsers($memberUserIds, 'list_deleted', (int) $request->user()->id);

        return response()->json([
            'status' => 'ok',
            'state' => $this->listSyncService->getState($request->user()),
        ]);
    }

    public function createFromTemplate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'template_id' => ['required', 'integer', 'exists:lists,id'],
            'name' => ['nullable', 'string', 'max:120'],
        ]);

        $list = $this->listCatalogService->createFromTemplate(
            $request->user(),
            UserList::query()->findOrFail((int) $validated['template_id']),
            isset($validated['name']) ? (string) $validated['name'] : null,
        );
        $this->syncStateBroadcaster->broadcastToUsers([(int) $request->user()->id], 'list_created_from_template', (int) $request->user()->id);

        return response()->json([
            'status' => 'ok',
            'list_id' => (int) $list->id,
            'state' => $this->listSyncService->getState($request->user()),
        ], 201);
    }
}
