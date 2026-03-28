<?php

namespace App\Services\Realtime;

use App\Events\UserSyncStateChanged;
use App\Models\ListMember;
use App\Models\User;
use App\Services\ListSyncService;
use Illuminate\Support\Facades\Log;

class UserSyncStateBroadcaster
{
    public function __construct(
        private readonly ListSyncService $listSyncService
    ) {
    }

    public function broadcastToUsers(array $userIds, string $reason, ?int $actorUserId = null): void
    {
        foreach (array_values(array_unique(array_map(static fn (mixed $id): int => (int) $id, $userIds))) as $userId) {
            if ($userId <= 0) {
                continue;
            }

            $this->broadcastToUser($userId, $reason, $actorUserId);
        }
    }

    public function broadcastToUser(int $userId, string $reason, ?int $actorUserId = null): void
    {
        try {
            $targetUser = User::query()->find($userId);
            $statePayload = $targetUser
                ? $this->listSyncService->getState($targetUser)
                : null;

            broadcast(new UserSyncStateChanged($userId, $reason, $actorUserId, $statePayload))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Realtime user sync dispatch failed.', [
                'user_id' => $userId,
                'reason' => $reason,
                'actor_user_id' => $actorUserId,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function broadcastToRelatedListUsers(int $userId, string $reason, ?int $actorUserId = null): void
    {
        $targets = ListMember::query()
            ->join('lists', 'lists.id', '=', 'list_members.list_id')
            ->whereIn('list_members.list_id', function ($query) use ($userId): void {
                $query->from('list_members')
                    ->select('list_id')
                    ->where('user_id', $userId);
            })
            ->where('lists.is_template', false)
            ->pluck('list_members.user_id')
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();

        $this->broadcastToUsers($targets, $reason, $actorUserId);
    }
}
