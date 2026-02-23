<?php

namespace App\Services\UserState;

use App\Models\User;

class UserMoodStateService
{
    private const COLOR_VALUES = ['red', 'yellow', 'green'];
    private const FIRE_EMOJIS = ['🥰', '😝', '😀'];
    private const BATTERY_EMOJIS = ['😴', '😡', '😄', '😊'];
    private const UNKNOWN_EMOJI = '❔';
    private const STALE_RESET_AFTER_SECONDS = 86400;

    public function buildPayload(User $user): array
    {
        $updatedAt = $user->mood_updated_at;
        $updatedAtMs = $updatedAt?->valueOf();
        $isStale = $this->isStale($updatedAt);

        return [
            'color' => $this->normalizeColor($user->mood_color),
            'fire_level' => $isStale ? 50 : $this->normalizeLevel($user->mood_fire_level),
            'fire_emoji' => $isStale
                ? self::UNKNOWN_EMOJI
                : $this->normalizeEmoji($user->mood_fire_emoji, self::FIRE_EMOJIS, self::FIRE_EMOJIS[0]),
            'battery_level' => $isStale ? 50 : $this->normalizeLevel($user->mood_battery_level),
            'battery_emoji' => $isStale
                ? self::UNKNOWN_EMOJI
                : $this->normalizeEmoji($user->mood_battery_emoji, self::BATTERY_EMOJIS, self::BATTERY_EMOJIS[3]),
            'updated_at' => optional($updatedAt)->toISOString(),
            'updated_at_ms' => $updatedAtMs,
        ];
    }

    public function applySyncPayload(User $user, array $payload): bool
    {
        $incomingUpdatedAtMs = $this->normalizeIncomingUpdatedAtMs($payload);
        $currentUpdatedAtMs = $user->mood_updated_at?->valueOf();

        if ($incomingUpdatedAtMs === null || ($currentUpdatedAtMs !== null && $incomingUpdatedAtMs < $currentUpdatedAtMs)) {
            return false;
        }

        $user->mood_color = $this->normalizeColor($payload['color'] ?? null);
        $user->mood_fire_level = $this->normalizeLevel($payload['fire_level'] ?? null);
        $user->mood_fire_emoji = $this->normalizeEmoji(
            $payload['fire_emoji'] ?? null,
            self::FIRE_EMOJIS,
            self::FIRE_EMOJIS[0]
        );
        $user->mood_battery_level = $this->normalizeLevel($payload['battery_level'] ?? null);
        $user->mood_battery_emoji = $this->normalizeEmoji(
            $payload['battery_emoji'] ?? null,
            self::BATTERY_EMOJIS,
            self::BATTERY_EMOJIS[3]
        );
        $user->mood_updated_at = now();
        $user->save();

        return true;
    }

    private function normalizeIncomingUpdatedAtMs(array $payload): ?int
    {
        $updatedAtMs = $this->normalizePositiveInteger($payload['updated_at_ms'] ?? null);
        if ($updatedAtMs !== null) {
            return $updatedAtMs;
        }

        $updatedAt = trim((string) ($payload['updated_at'] ?? ''));
        if ($updatedAt === '') {
            return null;
        }

        $timestamp = strtotime($updatedAt);
        if ($timestamp === false || $timestamp <= 0) {
            return null;
        }

        return $timestamp * 1000;
    }

    private function normalizePositiveInteger(mixed $value): ?int
    {
        $parsed = (int) $value;

        return $parsed > 0 ? $parsed : null;
    }

    private function normalizeColor(mixed $value): string
    {
        $candidate = strtolower(trim((string) $value));

        return in_array($candidate, self::COLOR_VALUES, true)
            ? $candidate
            : self::COLOR_VALUES[1];
    }

    private function normalizeLevel(mixed $value): int
    {
        return max(0, min(100, (int) $value));
    }

    private function normalizeEmoji(mixed $value, array $allowed, string $fallback): string
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === self::UNKNOWN_EMOJI) {
            return $candidate;
        }

        return in_array($candidate, $allowed, true)
            ? $candidate
            : $fallback;
    }

    private function isStale(mixed $updatedAt): bool
    {
        if (! $updatedAt || ! method_exists($updatedAt, 'lt')) {
            return false;
        }

        return $updatedAt->lt(now()->subSeconds(self::STALE_RESET_AFTER_SECONDS));
    }
}
