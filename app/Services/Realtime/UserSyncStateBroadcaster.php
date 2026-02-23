<?php

namespace App\Services\Realtime;

use App\Events\UserSyncStateChanged;
use App\Models\ListLink;
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

    public function broadcastToLinkedUsers(int $userId, string $reason, ?int $actorUserId = null): void
    {
        $targets = ListLink::query()
            ->where('is_active', true)
            ->where(function ($query) use ($userId): void {
                $query->where('user_one_id', $userId)
                    ->orWhere('user_two_id', $userId);
            })
            ->get(['user_one_id', 'user_two_id'])
            ->flatMap(static fn (ListLink $link): array => [
                (int) $link->user_one_id,
                (int) $link->user_two_id,
            ])
            ->unique()
            ->values()
            ->all();

        $this->broadcastToUsers($targets, $reason, $actorUserId);
    }
}
