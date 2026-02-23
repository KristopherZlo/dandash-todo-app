<?php

namespace App\Services\SyncChunk\Handlers;

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
        return in_array($action, ['sync_gamification', 'update_mood'], true);
    }

    public function handle(Request $request, array $operation): array
    {
        return match ((string) ($operation['action'] ?? '')) {
            'sync_gamification' => $this->handleSyncGamificationOperation($request, $operation),
            'update_mood' => $this->handleUpdateMoodOperation($request, $operation),
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
}
