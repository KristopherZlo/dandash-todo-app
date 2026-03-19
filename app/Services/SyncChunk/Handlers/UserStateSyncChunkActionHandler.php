<?php

namespace App\Services\SyncChunk\Handlers;

use App\Models\ListLink;
use App\Models\User;
use App\Services\ListSyncService;
use App\Services\Realtime\UserSyncStateBroadcaster;
use App\Services\SyncChunk\Contracts\SyncChunkActionHandler;
use App\Services\UserState\UserGamificationStateService;
use App\Services\UserState\UserMoodStateService;
use Illuminate\Http\Request;

class UserStateSyncChunkActionHandler implements SyncChunkActionHandler
{
    public function __construct(
        private readonly ListSyncService $listSyncService,
        private readonly UserGamificationStateService $gamificationStateService,
        private readonly UserMoodStateService $moodStateService,
        private readonly UserSyncStateBroadcaster $syncStateBroadcaster
    ) {
    }

    public function supports(string $action): bool
    {
        return in_array($action, ['sync_gamification', 'update_mood', 'apply_shared_gamification_delta'], true);
    }

    public function handle(Request $request, array $operation): array
    {
        return match ((string) ($operation['action'] ?? '')) {
            'sync_gamification' => $this->handleSyncGamificationOperation($request, $operation),
            'update_mood' => $this->handleUpdateMoodOperation($request, $operation),
            'apply_shared_gamification_delta' => $this->handleApplySharedGamificationDeltaOperation($request, $operation),
            default => [],
        };
    }

    private function handleSyncGamificationOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $user = $request->user();
        $applied = $this->gamificationStateService->applySyncPayload($user, $payload);

        if ($applied) {
            $this->syncStateBroadcaster->broadcastToUser((int) $user->id, 'gamification_changed', (int) $user->id);
        }

        /** @var User $freshUser */
        $freshUser = $user->fresh();

        return [
            'gamification' => $this->listSyncService->getGamificationState($freshUser),
            'applied' => $applied,
        ];
    }

    private function handleUpdateMoodOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $user = $request->user();
        $applied = $this->moodStateService->applySyncPayload($user, $payload);

        if ($applied) {
            $this->syncStateBroadcaster->broadcastToLinkedUsers((int) $user->id, 'mood_changed', (int) $user->id);
        }

        /** @var User $freshUser */
        $freshUser = $user->fresh();

        return [
            'mood' => $this->listSyncService->getMoodState($freshUser),
            'mood_cards' => $this->listSyncService->getMoodCards($freshUser)->values()->all(),
            'applied' => $applied,
        ];
    }

    private function handleApplySharedGamificationDeltaOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $linkId = (int) ($payload['link_id'] ?? 0);
        $delta = (float) ($payload['delta'] ?? 0.0);
        $user = $request->user();
        $link = $this->resolveAccessibleActiveLink((int) $user->id, $linkId);

        if (! $link) {
            return [
                'applied' => false,
                'partner_user_id' => null,
            ];
        }

        $partnerUserId = $link->otherUserId((int) $user->id);
        if (! $partnerUserId) {
            return [
                'applied' => false,
                'partner_user_id' => null,
            ];
        }

        $partnerUser = User::query()->find($partnerUserId);
        if (! $partnerUser) {
            return [
                'applied' => false,
                'partner_user_id' => (int) $partnerUserId,
            ];
        }

        $applied = $this->gamificationStateService->applyProgressDelta($partnerUser, $delta);

        if ($applied) {
            $this->syncStateBroadcaster->broadcastToUser((int) $partnerUserId, 'gamification_changed', (int) $user->id);
        }

        return [
            'applied' => $applied,
            'partner_user_id' => (int) $partnerUserId,
        ];
    }

    private function resolveAccessibleActiveLink(int $userId, int $linkId): ?ListLink
    {
        if ($userId <= 0 || $linkId <= 0) {
            return null;
        }

        return ListLink::query()
            ->whereKey($linkId)
            ->where('is_active', true)
            ->where(function ($query) use ($userId): void {
                $query->where('user_one_id', $userId)
                    ->orWhere('user_two_id', $userId);
            })
            ->first();
    }
}
