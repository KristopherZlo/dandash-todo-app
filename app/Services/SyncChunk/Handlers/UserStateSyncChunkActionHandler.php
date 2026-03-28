<?php

namespace App\Services\SyncChunk\Handlers;

use App\Models\ListMember;
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
            $this->syncStateBroadcaster->broadcastToRelatedListUsers((int) $user->id, 'mood_changed', (int) $user->id);
        }

        /** @var User $freshUser */
        $freshUser = $user->fresh();

        return [
            'mood' => $this->listSyncService->getMoodState($freshUser),
            'mood_cards' => $this->listSyncService->getMoodCards($freshUser)->values()->all(),
            'self_mood_preferences' => $this->moodStateService->buildPreferencesPayload($freshUser),
            'applied' => $applied,
        ];
    }

    private function handleApplySharedGamificationDeltaOperation(Request $request, array $operation): array
    {
        $payload = is_array($operation['payload'] ?? null) ? $operation['payload'] : [];
        $listId = (int) ($payload['list_id'] ?? 0);
        $delta = (float) ($payload['delta'] ?? 0.0);
        $user = $request->user();
        $partnerUserIds = $this->resolveAccessiblePartnerUserIds((int) $user->id, $listId);

        if ($partnerUserIds === []) {
            return [
                'applied' => false,
                'partner_user_id' => null,
                'partner_user_ids' => [],
            ];
        }

        $appliedUserIds = [];
        $partnerUsers = User::query()->whereIn('id', $partnerUserIds)->get();

        foreach ($partnerUsers as $partnerUser) {
            $applied = $this->gamificationStateService->applyProgressDelta($partnerUser, $delta);
            if (! $applied) {
                continue;
            }

            $appliedUserIds[] = (int) $partnerUser->id;
            $this->syncStateBroadcaster->broadcastToUser((int) $partnerUser->id, 'gamification_changed', (int) $user->id);
        }

        return [
            'applied' => $appliedUserIds !== [],
            'partner_user_id' => count($partnerUserIds) === 1 ? $partnerUserIds[0] : null,
            'partner_user_ids' => $partnerUserIds,
            'applied_user_ids' => $appliedUserIds,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function resolveAccessiblePartnerUserIds(int $userId, int $listId): array
    {
        if ($userId <= 0 || $listId <= 0) {
            return [];
        }

        $hasAccess = ListMember::query()
            ->join('lists', 'lists.id', '=', 'list_members.list_id')
            ->where('list_members.list_id', $listId)
            ->where('list_members.user_id', $userId)
            ->where('lists.is_template', false)
            ->exists();

        if (! $hasAccess) {
            return [];
        }

        return ListMember::query()
            ->join('lists', 'lists.id', '=', 'list_members.list_id')
            ->where('list_members.list_id', $listId)
            ->where('list_members.user_id', '!=', $userId)
            ->where('lists.is_template', false)
            ->pluck('list_members.user_id')
            ->map(static fn ($value): int => (int) $value)
            ->unique()
            ->values()
            ->all();
    }
}
