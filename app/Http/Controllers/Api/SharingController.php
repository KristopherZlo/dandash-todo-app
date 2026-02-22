<?php

namespace App\Http\Controllers\Api;

use App\Events\UserSyncStateChanged;
use App\Http\Controllers\Controller;
use App\Models\ListInvitation;
use App\Models\ListLink;
use App\Models\User;
use App\Services\ListSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SharingController extends Controller
{
    public function __construct(
        private readonly ListSyncService $listSyncService
    ) {
    }

    public function state(Request $request): JsonResponse
    {
        return response()->json($this->listSyncService->getState($request->user()));
    }

    public function setDefaultOwner(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $currentUser = $request->user();
        $ownerId = (int) $validated['owner_id'];

        abort_unless(
            $this->listSyncService->canAccessOwner($currentUser, $ownerId),
            403,
            'You do not have access to this list.'
        );

        $currentUser->preferred_owner_id = $ownerId;
        $currentUser->save();

        $this->dispatchSyncUpdates([$currentUser->id], 'default_owner_changed', (int) $currentUser->id);

        return response()->json($this->listSyncService->getState($currentUser));
    }

    public function searchUsers(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:1', 'max:64'],
        ]);

        $query = Str::lower(ltrim(trim($validated['query']), '@'));

        if ($query === '') {
            return response()->json([
                'users' => [],
            ]);
        }

        $users = User::query()
            ->where('id', '!=', $request->user()->id)
            ->where('tag', 'like', '%'.$query.'%')
            ->orderBy('tag')
            ->limit(10)
            ->get(['id', 'name', 'tag', 'email'])
            ->map(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'tag' => $user->tag,
                'email' => $user->email,
            ]);

        return response()->json([
            'users' => $users,
        ]);
    }

    public function sendInvitation(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $currentUser = $request->user();
        $targetUserId = (int) $validated['user_id'];

        if ($currentUser->id === $targetUserId) {
            throw ValidationException::withMessages([
                'user_id' => 'Нельзя приглашать самого себя.',
            ]);
        }

        if ($this->hasActiveLinkBetween($currentUser->id, $targetUserId)) {
            throw ValidationException::withMessages([
                'user_id' => 'Этот пользователь уже синхронизирован с вами.',
            ]);
        }

        $pendingExists = ListInvitation::query()
            ->where('status', ListInvitation::STATUS_PENDING)
            ->where(function ($query) use ($currentUser, $targetUserId): void {
                $query->where(function ($pairQuery) use ($currentUser, $targetUserId): void {
                    $pairQuery->where('inviter_id', $currentUser->id)
                        ->where('invitee_id', $targetUserId);
                })->orWhere(function ($pairQuery) use ($currentUser, $targetUserId): void {
                    $pairQuery->where('inviter_id', $targetUserId)
                        ->where('invitee_id', $currentUser->id);
                });
            })
            ->exists();

        if ($pendingExists) {
            throw ValidationException::withMessages([
                'user_id' => 'Уже есть активное приглашение между вами.',
            ]);
        }

        $invitation = ListInvitation::query()->create([
            'inviter_id' => $currentUser->id,
            'invitee_id' => $targetUserId,
            'status' => ListInvitation::STATUS_PENDING,
        ]);

        $this->dispatchSyncUpdates([$currentUser->id, $targetUserId], 'invitation_sent', (int) $currentUser->id);

        return response()->json([
            'status' => 'ok',
            'invitation_id' => $invitation->id,
        ], 201);
    }

    public function acceptInvitation(Request $request, ListInvitation $invitation): JsonResponse
    {
        $currentUser = $request->user();

        abort_unless($invitation->invitee_id === $currentUser->id, 403);
        abort_unless($invitation->isPending(), 422, 'Invitation is not active.');

        DB::transaction(function () use ($invitation): void {
            $invitation->status = ListInvitation::STATUS_ACCEPTED;
            $invitation->responded_at = now();
            $invitation->save();

            [$userOneId, $userTwoId] = $this->listSyncService->canonicalUserPair(
                $invitation->inviter_id,
                $invitation->invitee_id
            );

            $link = ListLink::query()->firstOrNew([
                'user_one_id' => $userOneId,
                'user_two_id' => $userTwoId,
            ]);

            $link->is_active = true;
            $link->sync_owner_id = $invitation->inviter_id;
            $link->accepted_at = now();
            $link->save();

            User::query()
                ->whereKey($invitation->invitee_id)
                ->update([
                    'preferred_owner_id' => $invitation->inviter_id,
                ]);
        });

        $this->dispatchSyncUpdates(
            [$invitation->inviter_id, $invitation->invitee_id],
            'invitation_accepted',
            (int) $currentUser->id
        );

        return response()->json($this->listSyncService->getState($currentUser));
    }

    public function declineInvitation(Request $request, ListInvitation $invitation): JsonResponse
    {
        $currentUser = $request->user();

        $isInvitee = $invitation->invitee_id === $currentUser->id;
        $isInviter = $invitation->inviter_id === $currentUser->id;

        abort_unless($isInvitee || $isInviter, 403);
        abort_unless($invitation->isPending(), 422, 'Invitation is not active.');

        $invitation->status = $isInvitee
            ? ListInvitation::STATUS_DECLINED
            : ListInvitation::STATUS_CANCELLED;
        $invitation->responded_at = now();
        $invitation->save();

        $this->dispatchSyncUpdates(
            [$invitation->inviter_id, $invitation->invitee_id],
            'invitation_declined',
            (int) $currentUser->id
        );

        return response()->json($this->listSyncService->getState($currentUser));
    }

    public function setListAsMine(Request $request, ListLink $link): JsonResponse
    {
        $currentUser = $request->user();
        $this->ensureLinkMember($link, $currentUser->id);
        abort_unless($link->is_active, 422, 'Link is not active.');

        $targetOwnerId = $link->otherUserId($currentUser->id);
        abort_if(! $targetOwnerId, 422, 'Invalid link.');
        abort_unless(
            $this->listSyncService->canAccessOwner($currentUser, $targetOwnerId),
            422,
            'This list is not available for you yet.'
        );

        $currentUser->preferred_owner_id = $targetOwnerId;
        $currentUser->save();

        $this->dispatchSyncUpdates([$currentUser->id], 'default_owner_changed', (int) $currentUser->id);

        return response()->json($this->listSyncService->getState($currentUser));
    }

    public function destroyLink(Request $request, ListLink $link): JsonResponse
    {
        $currentUser = $request->user();
        $this->ensureLinkMember($link, $currentUser->id);

        $link->is_active = false;
        $link->sync_owner_id = null;
        $link->save();

        $this->dispatchSyncUpdates(
            [$link->user_one_id, $link->user_two_id],
            'link_removed',
            (int) $currentUser->id
        );

        return response()->json($this->listSyncService->getState($currentUser));
    }

    private function hasActiveLinkBetween(int $firstUserId, int $secondUserId): bool
    {
        [$userOneId, $userTwoId] = $this->listSyncService->canonicalUserPair($firstUserId, $secondUserId);

        return ListLink::query()
            ->where('user_one_id', $userOneId)
            ->where('user_two_id', $userTwoId)
            ->where('is_active', true)
            ->exists();
    }

    private function ensureLinkMember(ListLink $link, int $userId): void
    {
        abort_unless($link->involvesUser($userId), 403);
    }

    private function dispatchSyncUpdates(array $userIds, string $reason, ?int $actorUserId = null): void
    {
        foreach (array_values(array_unique($userIds)) as $userId) {
            try {
                $targetUser = User::query()->find((int) $userId);
                $statePayload = $targetUser
                    ? $this->listSyncService->getState($targetUser)
                    : null;

                broadcast(new UserSyncStateChanged((int) $userId, $reason, $actorUserId, $statePayload))->toOthers();
            } catch (\Throwable $exception) {
                Log::warning('Realtime sync state dispatch failed.', [
                    'user_id' => (int) $userId,
                    'reason' => $reason,
                    'actor_user_id' => $actorUserId,
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }
}
