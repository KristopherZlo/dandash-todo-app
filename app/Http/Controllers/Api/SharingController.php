<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListInvitation;
use App\Models\ListLink;
use App\Services\Sharing\ListSharingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SharingController extends Controller
{
    public function __construct(
        private readonly ListSharingService $listSharingService
    ) {
    }

    public function state(Request $request): JsonResponse
    {
        return response()->json($this->listSharingService->getState($request->user()));
    }

    public function setDefaultOwner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return response()->json(
            $this->listSharingService->setDefaultOwner($request->user(), (int) $validated['owner_id'])
        );
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:64'],
        ]);

        return response()->json(
            $this->listSharingService->searchUsers($request->user(), (string) $validated['query'])
        );
    }

    public function sendInvitation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        return response()->json(
            $this->listSharingService->sendInvitation($request->user(), (int) $validated['user_id']),
            201
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

    public function setListAsMine(Request $request, ListLink $link): JsonResponse
    {
        return response()->json($this->listSharingService->setListAsMine($request->user(), $link));
    }

    public function destroyLink(Request $request, ListLink $link): JsonResponse
    {
        return response()->json($this->listSharingService->destroyLink($request->user(), $link));
    }
}
