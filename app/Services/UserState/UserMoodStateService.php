<?php

namespace App\Services\UserState;

use App\Models\User;

class UserMoodStateService
{
    private const COLOR_VALUES = ['red', 'yellow', 'green'];

    private const DEFAULT_FIRE_RECENT = ['🥰', '😝', '😈'];

    private const DEFAULT_BATTERY_RECENT = ['😊', '😄', '😴'];

    private const UNKNOWN_EMOJI = '❔';

    private const STALE_RESET_AFTER_SECONDS = 86400;

    private const MAX_RECENT_EMOJIS = 3;

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
                : $this->normalizeEmoji($user->mood_fire_emoji, self::DEFAULT_FIRE_RECENT[0]),
            'battery_level' => $isStale ? 50 : $this->normalizeLevel($user->mood_battery_level),
            'battery_emoji' => $isStale
                ? self::UNKNOWN_EMOJI
                : $this->normalizeEmoji($user->mood_battery_emoji, self::DEFAULT_BATTERY_RECENT[0]),
            'updated_at' => optional($updatedAt)->toISOString(),
            'updated_at_ms' => $updatedAtMs,
        ];
    }

    public function buildPreferencesPayload(User $user): array
    {
        return [
            'fire_recent_emojis' => $this->normalizeRecentEmojiList(
                $user->mood_fire_recent_emojis,
                self::DEFAULT_FIRE_RECENT,
                $user->mood_fire_emoji,
            ),
            'battery_recent_emojis' => $this->normalizeRecentEmojiList(
                $user->mood_battery_recent_emojis,
                self::DEFAULT_BATTERY_RECENT,
                $user->mood_battery_emoji,
            ),
        ];
    }

    public function applySyncPayload(User $user, array $payload): bool
    {
        $incomingUpdatedAtMs = $this->normalizeIncomingUpdatedAtMs($payload);
        $currentUpdatedAtMs = $user->mood_updated_at?->valueOf();

        if ($incomingUpdatedAtMs === null || ($currentUpdatedAtMs !== null && $incomingUpdatedAtMs < $currentUpdatedAtMs)) {
            return false;
        }

        $fireEmoji = $this->normalizeEmoji($payload['fire_emoji'] ?? null, self::DEFAULT_FIRE_RECENT[0]);
        $batteryEmoji = $this->normalizeEmoji($payload['battery_emoji'] ?? null, self::DEFAULT_BATTERY_RECENT[0]);

        $user->mood_color = $this->normalizeColor($payload['color'] ?? null);
        $user->mood_fire_level = $this->normalizeLevel($payload['fire_level'] ?? null);
        $user->mood_fire_emoji = $fireEmoji;
        $user->mood_fire_recent_emojis = $this->prependRecentEmoji(
            $user->mood_fire_recent_emojis,
            self::DEFAULT_FIRE_RECENT,
            $fireEmoji,
        );
        $user->mood_battery_level = $this->normalizeLevel($payload['battery_level'] ?? null);
        $user->mood_battery_emoji = $batteryEmoji;
        $user->mood_battery_recent_emojis = $this->prependRecentEmoji(
            $user->mood_battery_recent_emojis,
            self::DEFAULT_BATTERY_RECENT,
            $batteryEmoji,
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

    private function normalizeEmoji(mixed $value, string $fallback): string
    {
        $candidate = trim((string) ($value ?? ''));
        if ($candidate === self::UNKNOWN_EMOJI) {
            return $candidate;
        }

        if ($candidate === '' || mb_strlen($candidate, 'UTF-8') > 64) {
            return $fallback;
        }

        return $candidate;
    }

    /**
     * @param  array<int, string>|mixed  $source
     * @param  array<int, string>  $defaults
     * @return array<int, string>
     */
    private function normalizeRecentEmojiList(mixed $source, array $defaults, mixed $currentEmoji = null): array
    {
        $values = [];

        foreach (is_array($source) ? $source : [] as $emoji) {
            $normalized = $this->normalizeEmoji($emoji, '');
            if ($normalized === '' || $normalized === self::UNKNOWN_EMOJI || in_array($normalized, $values, true)) {
                continue;
            }

            $values[] = $normalized;
            if (count($values) >= self::MAX_RECENT_EMOJIS) {
                break;
            }
        }

        $current = $this->normalizeEmoji($currentEmoji, '');
        if ($current !== '' && $current !== self::UNKNOWN_EMOJI) {
            $values = array_values(array_filter(
                [$current, ...$values],
                static fn (string $emoji, int $index): bool => $emoji !== '' && array_search($emoji, [$current, ...$values], true) === $index,
                ARRAY_FILTER_USE_BOTH
            ));
        }

        foreach ($defaults as $fallbackEmoji) {
            if (count($values) >= self::MAX_RECENT_EMOJIS) {
                break;
            }

            if (! in_array($fallbackEmoji, $values, true)) {
                $values[] = $fallbackEmoji;
            }
        }

        return array_slice(array_values($values), 0, self::MAX_RECENT_EMOJIS);
    }

    /**
     * @param  array<int, string>|mixed  $source
     * @param  array<int, string>  $defaults
     * @return array<int, string>
     */
    private function prependRecentEmoji(mixed $source, array $defaults, string $selectedEmoji): array
    {
        $recents = $this->normalizeRecentEmojiList($source, $defaults);

        if ($selectedEmoji === '' || $selectedEmoji === self::UNKNOWN_EMOJI) {
            return $recents;
        }

        $next = [$selectedEmoji];
        foreach ($recents as $emoji) {
            if ($emoji !== $selectedEmoji) {
                $next[] = $emoji;
            }
        }

        return array_slice($next, 0, self::MAX_RECENT_EMOJIS);
    }

    private function isStale(mixed $updatedAt): bool
    {
        if (! $updatedAt || ! method_exists($updatedAt, 'lt')) {
            return false;
        }

        return $updatedAt->lt(now()->subSeconds(self::STALE_RESET_AFTER_SECONDS));
    }
}
