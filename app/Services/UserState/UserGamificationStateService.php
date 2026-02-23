<?php

namespace App\Services\UserState;

use App\Models\User;

class UserGamificationStateService
{
    public function buildPayload(User $user): array
    {
        $xpProgress = (float) ($user->xp_progress ?? 0.0);
        $rewardHistory = $this->normalizeRewardHistory($user->productivity_reward_history ?? []);

        return [
            'xp_progress' => $this->normalizeXpProgress($xpProgress),
            'productivity_score' => max(0, (int) ($user->productivity_score ?? 0)),
            'productivity_reward_history' => $rewardHistory,
            'xp_color_seed' => max(1, (int) ($user->xp_color_seed ?? 1)),
            'updated_at_ms' => $user->gamification_updated_at?->valueOf(),
        ];
    }

    public function applySyncPayload(User $user, array $payload): bool
    {
        $incomingUpdatedAtMs = $this->normalizePositiveInteger($payload['updated_at_ms'] ?? null);
        $currentUpdatedAtMs = $user->gamification_updated_at?->valueOf();

        if ($incomingUpdatedAtMs === null || ($currentUpdatedAtMs !== null && $incomingUpdatedAtMs < $currentUpdatedAtMs)) {
            return false;
        }

        $user->xp_progress = $this->normalizeXpProgress($payload['xp_progress'] ?? null);
        $user->productivity_score = max(0, (int) ($payload['productivity_score'] ?? 0));
        $user->productivity_reward_history = $this->normalizeRewardHistory($payload['productivity_reward_history'] ?? []);
        $user->xp_color_seed = max(1, (int) ($payload['xp_color_seed'] ?? 1));
        $user->gamification_updated_at = now();
        $user->save();

        return true;
    }

    private function normalizeXpProgress(mixed $value): float
    {
        $parsed = (float) $value;
        if (! is_finite($parsed)) {
            return 0.0;
        }

        return max(0.0, min(0.999999, $parsed));
    }

    private function normalizeRewardHistory(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = array_values(array_filter(
            array_map(static fn (mixed $entry): int => max(0, (int) $entry), $value),
            static fn (int $entry): bool => $entry > 0
        ));

        if (count($normalized) > 10000) {
            return array_slice($normalized, -10000);
        }

        return $normalized;
    }

    private function normalizePositiveInteger(mixed $value): ?int
    {
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }
}
