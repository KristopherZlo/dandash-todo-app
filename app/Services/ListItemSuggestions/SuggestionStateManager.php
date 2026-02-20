<?php

namespace App\Services\ListItemSuggestions;

use App\Models\ListItemSuggestionState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

class SuggestionStateManager
{
    private const DAY_SECONDS = 86400;

    public function __construct(
        private readonly SuggestionTextNormalizer $textNormalizer
    ) {}

    /**
     * @return Collection<string, ListItemSuggestionState>
     */
    public function statesByKeyForOwner(int $ownerId, string $type): Collection
    {
        return ListItemSuggestionState::query()
            ->forOwner($ownerId)
            ->ofType($type)
            ->get()
            ->keyBy('suggestion_key');
    }

    public function resetSuggestionData(int $ownerId, string $type, string $suggestionKey): void
    {
        $normalizedKey = $this->textNormalizer->normalizeSuggestionKey($suggestionKey);

        if ($normalizedKey === '') {
            return;
        }

        $state = ListItemSuggestionState::query()->firstOrNew([
            'owner_id' => $ownerId,
            'type' => $type,
            'suggestion_key' => $normalizedKey,
        ]);

        $state->dismissed_count = 0;
        $state->hidden_until = null;
        $state->retired_at = null;
        $state->reset_at = now();
        $state->save();
    }

    public function dismissSuggestion(
        int $ownerId,
        string $type,
        string $suggestionKey,
        int $averageIntervalSeconds
    ): void {
        $normalizedKey = $this->textNormalizer->normalizeSuggestionKey($suggestionKey);

        if ($normalizedKey === '') {
            return;
        }

        $state = ListItemSuggestionState::query()->firstOrNew([
            'owner_id' => $ownerId,
            'type' => $type,
            'suggestion_key' => $normalizedKey,
        ]);

        $nextDismissCount = max(0, (int) $state->dismissed_count) + 1;
        $now = now()->toImmutable();

        if ($nextDismissCount >= 4) {
            $state->dismissed_count = 0;
            $state->hidden_until = null;
            $state->retired_at = $now;
            $state->save();

            return;
        }

        $averageIntervalSeconds = max(0, $averageIntervalSeconds);
        $hideSeconds = match ($nextDismissCount) {
            1 => self::DAY_SECONDS,
            2 => max(self::DAY_SECONDS, (int) floor($averageIntervalSeconds / 2)),
            3 => max(1, $averageIntervalSeconds),
            default => self::DAY_SECONDS,
        };

        $state->dismissed_count = $nextDismissCount;
        $state->hidden_until = $now->addSeconds($hideSeconds);
        $state->retired_at = null;
        $state->save();
    }

    /**
     * @param  array<int, array<string, mixed>>  $suggestions
     * @return array<int, array<string, mixed>>
     */
    public function filterSuppressedSuggestions(
        int $ownerId,
        string $type,
        array $suggestions,
        CarbonImmutable $now
    ): array {
        $keys = collect($suggestions)
            ->pluck('suggestion_key')
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        if ($keys === []) {
            return $suggestions;
        }

        $states = ListItemSuggestionState::query()
            ->forOwner($ownerId)
            ->ofType($type)
            ->whereIn('suggestion_key', $keys)
            ->get()
            ->keyBy('suggestion_key');

        $filtered = [];

        foreach ($suggestions as $suggestion) {
            $suggestionKey = is_string($suggestion['suggestion_key'] ?? null)
                ? $suggestion['suggestion_key']
                : '';

            if ($suggestionKey === '') {
                $filtered[] = $suggestion;

                continue;
            }

            $state = $states->get($suggestionKey);

            if (! $state) {
                $filtered[] = $suggestion;

                continue;
            }

            if ($state->retired_at !== null) {
                continue;
            }

            if ($state->hidden_until !== null && $state->hidden_until->greaterThan($now)) {
                continue;
            }

            $filtered[] = $suggestion;
        }

        return $filtered;
    }
}
