<?php

namespace App\Services\Sharing;

use App\Models\ListInvitation;
use App\Models\ListLink;
use App\Models\User;
use App\Services\ListSyncService;
use App\Services\Realtime\UserSyncStateBroadcaster;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ListSharingService
{
    public function __construct(
        private readonly ListSyncService $listSyncService,
        private readonly UserSyncStateBroadcaster $syncStateBroadcaster
    ) {
    }

    public function getState(User $user): array
    {
        return $this->listSyncService->getState($user);
    }

    public function setDefaultOwner(User $currentUser, int $ownerId): array
    {
        abort_unless(
            $this->listSyncService->canAccessOwner($currentUser, $ownerId),
            Response::HTTP_FORBIDDEN,
            'You do not have access to this list.'
        );

        $currentUser->preferred_owner_id = $ownerId;
        $currentUser->save();

        $this->syncStateBroadcaster->broadcastToUsers([(int) $currentUser->id], 'default_owner_changed', (int) $currentUser->id);

        return $this->listSyncService->getState($currentUser);
    }

    public function searchUsers(User $currentUser, string $query): array
    {
        $normalizedQuery = Str::lower(ltrim(trim($query), '@'));

        if ($normalizedQuery === '') {
            return ['users' => []];
        }

        $users = User::query()
            ->where('id', '!=', $currentUser->id)
            ->where('tag', 'like', '%'.$normalizedQuery.'%')
            ->orderBy('tag')
            ->limit(10)
            ->get(['id', 'name', 'tag', 'email'])
            ->map(static fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'tag' => $user->tag,
                'email' => $user->email,
            ]);

        return ['users' => $users];
    }

    /**
     * @return array{status: string, invitation_id: int}
     */
    public function sendInvitation(User $currentUser, int $targetUserId): array
    {
        if ((int) $currentUser->id === $targetUserId) {
            throw ValidationException::withMessages([
                'user_id' => 'Нельзя приглашать самого себя.',
            ]);
        }

        if ($this->hasActiveLinkBetween((int) $currentUser->id, $targetUserId)) {
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

        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $currentUser->id, $targetUserId],
            'invitation_sent',
            (int) $currentUser->id
        );

        return [
            'status' => 'ok',
            'invitation_id' => (int) $invitation->id,
        ];
    }

    public function acceptInvitation(User $currentUser, ListInvitation $invitation): array
    {
        abort_unless((int) $invitation->invitee_id === (int) $currentUser->id, Response::HTTP_FORBIDDEN);
        abort_unless($invitation->isPending(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Invitation is not active.');

        DB::transaction(function () use ($invitation): void {
            $invitation->status = ListInvitation::STATUS_ACCEPTED;
            $invitation->responded_at = now();
            $invitation->save();

            [$userOneId, $userTwoId] = $this->listSyncService->canonicalUserPair(
                (int) $invitation->inviter_id,
                (int) $invitation->invitee_id
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

        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $invitation->inviter_id, (int) $invitation->invitee_id],
            'invitation_accepted',
            (int) $currentUser->id
        );

        return $this->listSyncService->getState($currentUser);
    }

    public function declineInvitation(User $currentUser, ListInvitation $invitation): array
    {
        $isInvitee = (int) $invitation->invitee_id === (int) $currentUser->id;
        $isInviter = (int) $invitation->inviter_id === (int) $currentUser->id;

        abort_unless($isInvitee || $isInviter, Response::HTTP_FORBIDDEN);
        abort_unless($invitation->isPending(), Response::HTTP_UNPROCESSABLE_ENTITY, 'Invitation is not active.');

        $invitation->status = $isInvitee
            ? ListInvitation::STATUS_DECLINED
            : ListInvitation::STATUS_CANCELLED;
        $invitation->responded_at = now();
        $invitation->save();

        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $invitation->inviter_id, (int) $invitation->invitee_id],
            'invitation_declined',
            (int) $currentUser->id
        );

        return $this->listSyncService->getState($currentUser);
    }

    public function setListAsMine(User $currentUser, ListLink $link): array
    {
        $this->ensureLinkMember($link, (int) $currentUser->id);
        abort_unless($link->is_active, Response::HTTP_UNPROCESSABLE_ENTITY, 'Link is not active.');

        $targetOwnerId = $link->otherUserId((int) $currentUser->id);
        abort_if(! $targetOwnerId, Response::HTTP_UNPROCESSABLE_ENTITY, 'Invalid link.');
        abort_unless(
            $this->listSyncService->canAccessOwner($currentUser, (int) $targetOwnerId),
            Response::HTTP_UNPROCESSABLE_ENTITY,
            'This list is not available for you yet.'
        );

        $currentUser->preferred_owner_id = (int) $targetOwnerId;
        $currentUser->save();

        $this->syncStateBroadcaster->broadcastToUsers([(int) $currentUser->id], 'default_owner_changed', (int) $currentUser->id);

        return $this->listSyncService->getState($currentUser);
    }

    public function destroyLink(User $currentUser, ListLink $link): array
    {
        $this->ensureLinkMember($link, (int) $currentUser->id);

        $link->is_active = false;
        $link->sync_owner_id = null;
        $link->save();

        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $link->user_one_id, (int) $link->user_two_id],
            'link_removed',
            (int) $currentUser->id
        );

        return $this->listSyncService->getState($currentUser);
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
        abort_unless($link->involvesUser($userId), Response::HTTP_FORBIDDEN);
    }
}
