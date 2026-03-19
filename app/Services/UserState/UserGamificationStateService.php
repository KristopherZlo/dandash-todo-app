<?php

namespace App\Services\UserState;

use App\Models\User;

class UserGamificationStateService
{
    private const PRODUCTIVITY_REWARD_BASE_PER_LEVEL = 8;

    private const PRODUCTIVITY_REWARD_RANDOM_MAX_BONUS = 5;

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

    public function applyProgressDelta(User $user, mixed $delta): bool
    {
        $normalizedDelta = $this->normalizeFiniteDelta($delta);
        if ($normalizedDelta === 0.0) {
            return false;
        }

        $xpProgress = $this->normalizeXpProgress($user->xp_progress ?? null);
        $productivityScore = max(0, (int) ($user->productivity_score ?? 0));
        $rewardHistory = $this->normalizeRewardHistory($user->productivity_reward_history ?? []);
        $xpColorSeed = max(1, (int) ($user->xp_color_seed ?? 1));

        $initialXpProgress = $xpProgress;
        $initialProductivityScore = $productivityScore;
        $initialRewardHistory = $rewardHistory;

        if ($normalizedDelta > 0) {
            $this->applyPositiveDelta(
                $xpProgress,
                $productivityScore,
                $rewardHistory,
                $xpColorSeed,
                $normalizedDelta
            );
        } else {
            $this->applyNegativeDelta(
                $xpProgress,
                $productivityScore,
                $rewardHistory,
                abs($normalizedDelta)
            );
        }

        if (
            abs($xpProgress - $initialXpProgress) < 0.000001
            && $productivityScore === $initialProductivityScore
            && $rewardHistory === $initialRewardHistory
        ) {
            return false;
        }

        $user->xp_progress = $this->normalizeXpProgress($xpProgress);
        $user->productivity_score = max(0, $productivityScore);
        $user->productivity_reward_history = $this->normalizeRewardHistory($rewardHistory);
        $user->xp_color_seed = $xpColorSeed;
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

    private function applyPositiveDelta(
        float &$xpProgress,
        int &$productivityScore,
        array &$rewardHistory,
        int $xpColorSeed,
        float $delta
    ): void {
        $remainingGain = max(0.0, $delta);
        $guard = 0;

        while ($remainingGain > 0.000001 && $guard < 512) {
            $guard += 1;
            $currentProgress = $this->normalizeXpProgress($xpProgress);
            $spaceToLevelUp = max(0.000001, 1.0 - $currentProgress);

            if ($remainingGain < $spaceToLevelUp) {
                $xpProgress = $this->normalizeXpProgress($currentProgress + $remainingGain);
                break;
            }

            $remainingGain -= $spaceToLevelUp;
            $xpProgress = 0.0;

            $reward = $this->rewardForLevel($xpColorSeed, count($rewardHistory) + 1);
            $productivityScore = max(0, $productivityScore + $reward);
            $rewardHistory[] = $reward;
            $rewardHistory = $this->normalizeRewardHistory($rewardHistory);
        }
    }

    private function applyNegativeDelta(
        float &$xpProgress,
        int &$productivityScore,
        array &$rewardHistory,
        float $delta
    ): void {
        $remainingLoss = max(0.0, $delta);
        $guard = 0;

        while ($remainingLoss > 0.000001 && $guard < 512) {
            $guard += 1;
            $currentProgress = $this->normalizeXpProgress($xpProgress);

            if ($currentProgress >= $remainingLoss) {
                $xpProgress = $this->normalizeXpProgress($currentProgress - $remainingLoss);
                break;
            }

            $remainingLoss -= $currentProgress;
            $xpProgress = 0.0;

            $revertedReward = max(0, (int) array_pop($rewardHistory));
            if ($revertedReward <= 0) {
                break;
            }

            $productivityScore = max(0, $productivityScore - $revertedReward);
            $xpProgress = 0.999999;
        }
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

    private function normalizeFiniteDelta(mixed $value): float
    {
        $parsed = (float) $value;
        if (! is_finite($parsed)) {
            return 0.0;
        }

        return round($parsed, 6);
    }

    private function rewardForLevel(int $xpColorSeed, int $level): int
    {
        $normalizedSeed = max(1, $xpColorSeed);
        $normalizedLevel = max(1, $level);
        $hashValue = (int) sprintf('%u', crc32(sprintf('%d:%d', $normalizedSeed, $normalizedLevel)));
        $bonus = $hashValue % (self::PRODUCTIVITY_REWARD_RANDOM_MAX_BONUS + 1);

        return self::PRODUCTIVITY_REWARD_BASE_PER_LEVEL + $bonus;
    }
}
