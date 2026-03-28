<?php

namespace App\Services\Sharing;

use App\Models\ListInvitation;
use App\Models\User;
use App\Models\UserList;
use App\Services\ListSyncService;
use App\Services\Lists\ListCatalogService;
use App\Services\Lists\ListMembershipService;
use App\Services\Realtime\UserSyncStateBroadcaster;

class ListSharingService
{
    public function __construct(
        private readonly ListSyncService $listSyncService,
        private readonly ListCatalogService $listCatalogService,
        private readonly ListMembershipService $listMembershipService,
        private readonly UserSyncStateBroadcaster $syncStateBroadcaster,
    ) {
    }

    public function getState(User $user): array
    {
        return $this->listSyncService->getState($user);
    }

    public function setDefaultList(User $currentUser, UserList $list): array
    {
        $this->listCatalogService->setDefaultList($currentUser, $list);
        $this->syncStateBroadcaster->broadcastToUsers([(int) $currentUser->id], 'default_list_changed', (int) $currentUser->id);

        return $this->listSyncService->getState($currentUser);
    }

    public function searchUsers(User $currentUser, string $query, ?UserList $list = null): array
    {
        return $this->listMembershipService->searchUsers($currentUser, $query, $list);
    }

    public function sendInvitation(User $currentUser, UserList $list, int $targetUserId): array
    {
        $invitation = $this->listMembershipService->sendInvitation($currentUser, $list, $targetUserId);

        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $currentUser->id, $targetUserId],
            'invitation_sent',
            (int) $currentUser->id,
        );

        return [
            'status' => 'ok',
            'invitation_id' => (int) $invitation->id,
            'state' => $this->listSyncService->getState($currentUser),
        ];
    }

    public function acceptInvitation(User $currentUser, ListInvitation $invitation): array
    {
        $list = $this->listMembershipService->acceptInvitation($currentUser, $invitation);

        $this->syncStateBroadcaster->broadcastToUsers(
            $list->members()->pluck('user_id')->map(static fn ($value): int => (int) $value)->all(),
            'invitation_accepted',
            (int) $currentUser->id,
        );

        return $this->listSyncService->getState($currentUser);
    }

    public function declineInvitation(User $currentUser, ListInvitation $invitation): array
    {
        $list = $this->listMembershipService->declineInvitation($currentUser, $invitation);

        $this->syncStateBroadcaster->broadcastToUsers(
            $list->members()->pluck('user_id')->map(static fn ($value): int => (int) $value)->all(),
            'invitation_declined',
            (int) $currentUser->id,
        );

        return $this->listSyncService->getState($currentUser);
    }

    public function removeMember(User $currentUser, UserList $list, int $memberUserId): array
    {
        $this->listMembershipService->removeMember($currentUser, $list, $memberUserId);

        $this->syncStateBroadcaster->broadcastToUsers(
            [(int) $currentUser->id, $memberUserId],
            'member_removed',
            (int) $currentUser->id,
        );

        return $this->listSyncService->getState($currentUser);
    }
}
