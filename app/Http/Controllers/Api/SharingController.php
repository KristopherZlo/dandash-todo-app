<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListInvitation;
use App\Models\UserList;
use App\Services\Sharing\ListSharingService;
use App\Services\Lists\ListCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharingController extends Controller
{
    public function __construct(
        private readonly ListSharingService $listSharingService,
        private readonly ListCatalogService $listCatalogService,
    ) {
    }

    public function state(Request $request): JsonResponse
    {
        return response()->json($this->listSharingService->getState($request->user()));
    }

    public function setDefaultList(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'exists:lists,id'],
        ]);

        return response()->json(
            $this->listSharingService->setDefaultList(
                $request->user(),
                UserList::query()->findOrFail((int) $validated['list_id']),
            )
        );
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:64'],
            'list_id' => ['nullable', 'integer', 'exists:lists,id'],
        ]);

        return response()->json(
            $this->listSharingService->searchUsers(
                $request->user(),
                (string) $validated['query'],
                isset($validated['list_id'])
                    ? UserList::query()->findOrFail((int) $validated['list_id'])
                    : null,
            )
        );
    }

    public function sendInvitation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'list_id' => ['nullable', 'integer', 'exists:lists,id'],
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return response()->json(
            $this->listSharingService->sendInvitation(
                $request->user(),
                isset($validated['list_id'])
                    ? UserList::query()->findOrFail((int) $validated['list_id'])
                    : $this->listCatalogService->ensurePersonalListExists($request->user()),
                (int) $validated['user_id'],
            ),
            201,
        );
    }

    public function acceptInvitation(Request $request, ListInvitation $invitation): JsonResponse
    {
        return response()->json($this->listSharingService->acceptInvitation($request->user(), $invitation));
    }

    public function declineInvitation(Request $request, ListInvitation $invitation): JsonResponse
    {
        return response()->json($this->listSharingService->declineInvitation($request->user(), $invitation));
    }

    public function removeMember(Request $request, UserList $list, int $userId): JsonResponse
    {
        return response()->json(
            $this->listSharingService->removeMember($request->user(), $list, $userId)
        );
    }
}
