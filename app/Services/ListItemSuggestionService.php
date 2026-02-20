<?php

namespace App\Services;

use App\Models\ListItem;
use App\Models\ListItemEvent;
use App\Models\ListItemSuggestionState;
use App\Services\ListItemSuggestions\SuggestionTextNormalizer;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ListItemSuggestionService
{
    private const MIN_OCCURRENCES = 2;
    private const DUE_RATIO_THRESHOLD = 0.9;
    private const UPCOMING_RATIO_THRESHOLD = 0.75;
    private const DAY_SECONDS = 86400;
    private ?bool $eventsTableAvailable = null;

    public function __construct(
        private readonly SuggestionTextNormalizer $textNormalizer
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestForOwner(int $ownerId, string $type, int $limit = 8, ?int $listLinkId = null): array
    {
        $entries = $this->loadSuggestionEntriesForOwner($ownerId, $type, $listLinkId);

        $statesByKey = ListItemSuggestionState::query()
            ->forOwner($ownerId)
            ->ofType($type)
            ->get()
            ->keyBy('suggestion_key');

        $clusters = [];

        foreach ($entries as $entry) {
            $text = trim((string) ($entry['text'] ?? ''));
            $timestamp = $entry['timestamp'] ?? null;
            if ($text === '' || ! $timestamp instanceof CarbonImmutable) {
                continue;
            }

            $normalized = $this->textNormalizer->normalizeText($text);

            if ($normalized === '') {
                continue;
            }

            $clusterIndex = $this->resolveClusterIndex($clusters, $normalized);

            if ($clusterIndex === null) {
                $clusters[] = [
                    'normalized_samples' => [$normalized],
                    'variants' => [],
                    'latest_text' => '',
                    'timestamps' => [],
                ];

                $clusterIndex = array_key_last($clusters);
            }

            if (! in_array($normalized, $clusters[$clusterIndex]['normalized_samples'], true)) {
                $clusters[$clusterIndex]['normalized_samples'][] = $normalized;
            }

            $clusters[$clusterIndex]['variants'][$text] = ($clusters[$clusterIndex]['variants'][$text] ?? 0) + 1;
            $clusters[$clusterIndex]['latest_text'] = $text;
            $clusters[$clusterIndex]['timestamps'][] = $timestamp;
        }

        $now = now()->toImmutable();
        $suggestions = [];

        foreach ($clusters as $cluster) {
            $state = $this->resolveClusterStateForSamples($statesByKey, $cluster['normalized_samples']);
            $resetAt = $state?->reset_at?->toImmutable();

            $timestamps = $cluster['timestamps'];
            if ($resetAt !== null) {
                $timestamps = array_values(array_filter(
                    $timestamps,
                    static fn (CarbonImmutable $timestamp): bool => $timestamp->greaterThanOrEqualTo($resetAt),
                ));
            }

            usort($timestamps, static fn (CarbonImmutable $left, CarbonImmutable $right): int => $left->getTimestamp() <=> $right->getTimestamp());
            $occurrences = count($timestamps);

            if ($occurrences < self::MIN_OCCURRENCES) {
                continue;
            }

            $intervals = [];
            for ($index = 1; $index < $occurrences; $index++) {
                $delta = $timestamps[$index]->diffInSeconds($timestamps[$index - 1], true);
                if ($delta > 0) {
                    $intervals[] = $delta;
                }
            }

            if (count($intervals) === 0) {
                continue;
            }

            $averageIntervalSeconds = (int) round(array_sum($intervals) / count($intervals));
            $lastAddedAt = $timestamps[$occurrences - 1];
            $elapsedSeconds = max(0, $now->getTimestamp() - $lastAddedAt->getTimestamp());
            $dueRatio = $averageIntervalSeconds > 0
                ? $elapsedSeconds / $averageIntervalSeconds
                : 0.0;

            $isDue = $dueRatio >= self::DUE_RATIO_THRESHOLD;
            $isUpcoming = ! $isDue && $dueRatio >= self::UPCOMING_RATIO_THRESHOLD;

            if (! $isDue && ! $isUpcoming) {
                continue;
            }

            $nextExpectedAt = $lastAddedAt->addSeconds($averageIntervalSeconds);
            $secondsUntilExpected = max(0, $nextExpectedAt->getTimestamp() - $now->getTimestamp());
            $confidence = $this->estimateConfidence($intervals, $occurrences);
            $sortedVariants = $this->sortVariants($cluster['variants']);
            $displayText = $cluster['latest_text'] !== ''
                ? $cluster['latest_text']
                : (array_key_first($sortedVariants) ?? '');

            if ($displayText === '') {
                continue;
            }

            $suggestions[] = [
                'suggested_text' => $displayText,
                'suggestion_key' => (string) ($cluster['normalized_samples'][0] ?? $this->textNormalizer->normalizeText($displayText)),
                'type' => $type,
                'occurrences' => $occurrences,
                'average_interval_seconds' => $averageIntervalSeconds,
                'last_added_at' => $lastAddedAt->toISOString(),
                'next_expected_at' => $nextExpectedAt->toISOString(),
                'seconds_until_expected' => $secondsUntilExpected,
                'is_due' => $isDue,
                'due_ratio' => round($dueRatio, 2),
                'confidence' => $confidence,
                'variants' => array_slice(array_keys($sortedVariants), 0, 4),
            ];
        }

        usort($suggestions, function (array $left, array $right): int {
            $leftDue = $left['is_due'] ? 1 : 0;
            $rightDue = $right['is_due'] ? 1 : 0;

            if ($leftDue !== $rightDue) {
                return $rightDue <=> $leftDue;
            }

            if ($left['due_ratio'] !== $right['due_ratio']) {
                return $right['due_ratio'] <=> $left['due_ratio'];
            }

            if ($left['confidence'] !== $right['confidence']) {
                return $right['confidence'] <=> $left['confidence'];
            }

            return $right['occurrences'] <=> $left['occurrences'];
        });

        $filtered = $this->filterSuppressedSuggestions($ownerId, $type, $suggestions, $now);
        $deduplicated = $this->deduplicateSuggestions($filtered);

        return array_slice($deduplicated, 0, max(1, $limit));
    }


    /**
     * @return array<int, array<string, mixed>>
     */
    public function productStatsForOwner(int $ownerId, int $limit = 50, ?int $listLinkId = null): array
    {
        return $this->suggestionStatsForOwner($ownerId, ListItem::TYPE_PRODUCT, $limit, $listLinkId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestionStatsForOwner(int $ownerId, string $type, int $limit = 50, ?int $listLinkId = null): array
    {
        $normalizedType = $this->normalizeSuggestionType($type);
        $limit = max(1, min(200, $limit));
        $entries = $this->loadSuggestionStatsEntriesForOwner($ownerId, $normalizedType, $listLinkId);

        $statesByKey = ListItemSuggestionState::query()
            ->forOwner($ownerId)
            ->ofType($normalizedType)
            ->get()
            ->keyBy('suggestion_key');

        $clusters = [];

        foreach ($entries as $entry) {
            $text = trim((string) ($entry['text'] ?? ''));
            $timestamp = $entry['timestamp'] ?? null;
            if ($text === '' || ! $timestamp instanceof CarbonImmutable) {
                continue;
            }

            $normalized = $this->textNormalizer->normalizeText($text);
            if ($normalized === '') {
                continue;
            }

            $clusterIndex = $this->resolveClusterIndex($clusters, $normalized);

            if ($clusterIndex === null) {
                $clusters[] = [
                    'normalized_samples' => [$normalized],
                    'entries' => [],
                ];

                $clusterIndex = array_key_last($clusters);
            }

            if (! in_array($normalized, $clusters[$clusterIndex]['normalized_samples'], true)) {
                $clusters[$clusterIndex]['normalized_samples'][] = $normalized;
            }

            $clusters[$clusterIndex]['entries'][] = [
                'text' => $text,
                'timestamp' => $timestamp,
            ];
        }

        $stats = [];

        foreach ($clusters as $cluster) {
            $state = $this->resolveClusterStateForSamples($statesByKey, $cluster['normalized_samples']);
            $resetAt = $state?->reset_at?->toImmutable();

            $entries = array_values(array_filter(
                $cluster['entries'],
                static function (array $entry) use ($resetAt): bool {
                    $timestamp = $entry['timestamp'] ?? null;
                    if (! $timestamp instanceof CarbonImmutable) {
                        return false;
                    }

                    return $resetAt === null || $timestamp->greaterThanOrEqualTo($resetAt);
                }
            ));

            if ($entries === []) {
                continue;
            }

            usort(
                $entries,
                static fn (array $left, array $right): int => ($left['timestamp'])->getTimestamp() <=> ($right['timestamp'])->getTimestamp(),
            );

            $occurrences = count($entries);
            $intervals = [];

            for ($index = 1; $index < $occurrences; $index++) {
                /** @var CarbonImmutable $current */
                $current = $entries[$index]['timestamp'];
                /** @var CarbonImmutable $previous */
                $previous = $entries[$index - 1]['timestamp'];

                $delta = $current->diffInSeconds($previous, true);
                if ($delta > 0) {
                    $intervals[] = $delta;
                }
            }

            $averageIntervalSeconds = $intervals !== []
                ? (int) round(array_sum($intervals) / count($intervals))
                : null;

            $variantCounts = [];
            foreach ($entries as $entry) {
                $entryText = (string) ($entry['text'] ?? '');
                if ($entryText === '') {
                    continue;
                }

                $variantCounts[$entryText] = ($variantCounts[$entryText] ?? 0) + 1;
            }

            $sortedVariants = $this->sortVariants($variantCounts);
            $lastEntry = $entries[$occurrences - 1];
            /** @var CarbonImmutable $lastTimestamp */
            $lastTimestamp = $lastEntry['timestamp'];
            $displayText = (string) ($lastEntry['text'] ?? '');

            if ($displayText === '') {
                $displayText = (string) (array_key_first($sortedVariants) ?? '');
            }

            if ($displayText === '') {
                continue;
            }

            $stats[] = [
                'suggestion_key' => (string) ($cluster['normalized_samples'][0] ?? $this->textNormalizer->normalizeText($displayText)),
                'text' => $displayText,
                'occurrences' => $occurrences,
                'average_interval_seconds' => $averageIntervalSeconds,
                'last_completed_at' => $lastTimestamp->toISOString(),
                'variants' => array_slice(array_keys($sortedVariants), 0, 4),
                'dismissed_count' => (int) ($state?->dismissed_count ?? 0),
                'hidden_until' => $state?->hidden_until?->toISOString(),
                'retired_at' => $state?->retired_at?->toISOString(),
                'reset_at' => $state?->reset_at?->toISOString(),
            ];
        }

        usort($stats, static function (array $left, array $right): int {
            if (($left['occurrences'] ?? 0) !== ($right['occurrences'] ?? 0)) {
                return ($right['occurrences'] ?? 0) <=> ($left['occurrences'] ?? 0);
            }

            return strcmp(
                (string) ($right['last_completed_at'] ?? ''),
                (string) ($left['last_completed_at'] ?? ''),
            );
        });

        return array_slice($stats, 0, $limit);
    }

    /**
     * @return array{stats: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function productStatsPayloadForOwner(int $ownerId, int $limit = 50, ?int $listLinkId = null): array
    {
        return $this->suggestionStatsPayloadForOwner($ownerId, ListItem::TYPE_PRODUCT, $limit, $listLinkId);
    }

    /**
     * @return array{stats: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function suggestionStatsPayloadForOwner(int $ownerId, string $type, int $limit = 50, ?int $listLinkId = null): array
    {
        $normalizedType = $this->normalizeSuggestionType($type);
        $stats = $this->suggestionStatsForOwner($ownerId, $normalizedType, $limit, $listLinkId);

        $addedCount = $this->countEventsForOwner(
            $ownerId,
            $normalizedType,
            ListItemEvent::EVENT_ADDED,
            $listLinkId
        );
        $completedCount = $this->countEventsForOwner(
            $ownerId,
            $normalizedType,
            ListItemEvent::EVENT_COMPLETED,
            $listLinkId
        );
        $uniqueItems = $this->uniqueEventKeysForOwner($ownerId, $normalizedType, $listLinkId);
        $lastActivityAt = $this->lastEventActivityForOwner($ownerId, $normalizedType, $listLinkId);

        if ($addedCount === 0) {
            $itemsQuery = ListItem::query()
                ->forOwner($ownerId)
                ->ofType($normalizedType);
            $this->applyListLinkScope($itemsQuery, $listLinkId);
            $addedCount = (int) $itemsQuery->count();
        }

        if ($completedCount === 0) {
            $itemsQuery = ListItem::query()
                ->forOwner($ownerId)
                ->ofType($normalizedType)
                ->where('is_completed', true);
            $this->applyListLinkScope($itemsQuery, $listLinkId);
            $completedCount = (int) $itemsQuery->count();
        }

        if ($uniqueItems === 0) {
            $uniqueItems = count($stats);
        }

        $activeSuggestions = $this->suggestForOwner($ownerId, $normalizedType, 50, $listLinkId);
        $dueCount = count(array_filter(
            $activeSuggestions,
            static fn (array $entry): bool => (bool) ($entry['is_due'] ?? false),
        ));

        return [
            'stats' => $stats,
            'summary' => [
                'total_added' => $addedCount,
                'total_completed' => $completedCount,
                'unique_items' => $uniqueItems,
                'unique_products' => $normalizedType === ListItem::TYPE_PRODUCT ? $uniqueItems : 0,
                'unique_todos' => $normalizedType === ListItem::TYPE_TODO ? $uniqueItems : 0,
                'due_suggestions' => $dueCount,
                'upcoming_suggestions' => max(0, count($activeSuggestions) - $dueCount),
                'last_activity_at' => $lastActivityAt?->toISOString(),
            ],
        ];
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

    public function normalizeSuggestionKey(string $value): string
    {
        return $this->textNormalizer->normalizeSuggestionKey($value);
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

    public function recordAddedEvent(ListItem $item): void
    {
        $this->recordEvent(
            $item,
            ListItemEvent::EVENT_ADDED,
            $item->created_at?->toImmutable() ?? now()->toImmutable(),
        );
    }

    public function recordCompletedEvent(ListItem $item): void
    {
        $this->recordEvent(
            $item,
            ListItemEvent::EVENT_COMPLETED,
            $item->completed_at?->toImmutable() ?? now()->toImmutable(),
        );
    }

    /**
     * @return array<int, array{text: string, timestamp: CarbonImmutable}>
     */
    private function loadSuggestionEntriesForOwner(int $ownerId, string $type, ?int $listLinkId = null): array
    {
        if ($this->canUseEventsTable()) {
            try {
                $events = $this->eventQueryForOwner($ownerId, $type, $listLinkId)
                    ->ofEventType(ListItemEvent::EVENT_ADDED)
                    ->orderBy('occurred_at')
                    ->get(['text', 'occurred_at']);

                if ($events->isNotEmpty()) {
                    return $events
                        ->map(static function (ListItemEvent $event): ?array {
                            $text = trim((string) $event->text);
                            $timestamp = $event->occurred_at?->toImmutable();
                            if ($text === '' || ! $timestamp instanceof CarbonImmutable) {
                                return null;
                            }

                            return [
                                'text' => $text,
                                'timestamp' => $timestamp,
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all();
                }
            } catch (QueryException $exception) {
                $this->markEventsUnavailable($exception);
            }
        }

        $itemsQuery = ListItem::query()
            ->forOwner($ownerId)
            ->ofType($type)
            ->orderBy('created_at');
        $this->applyListLinkScope($itemsQuery, $listLinkId);

        return $itemsQuery
            ->get(['text', 'created_at'])
            ->map(static function (ListItem $item): ?array {
                $text = trim((string) $item->text);
                $timestamp = $item->created_at?->toImmutable();
                if ($text === '' || ! $timestamp instanceof CarbonImmutable) {
                    return null;
                }

                return [
                    'text' => $text,
                    'timestamp' => $timestamp,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array{text: string, timestamp: CarbonImmutable}>
     */
    private function loadProductStatsEntriesForOwner(int $ownerId, ?int $listLinkId = null): array
    {
        return $this->loadSuggestionStatsEntriesForOwner($ownerId, ListItem::TYPE_PRODUCT, $listLinkId);
    }

    /**
     * @return array<int, array{text: string, timestamp: CarbonImmutable}>
     */
    private function loadSuggestionStatsEntriesForOwner(int $ownerId, string $type, ?int $listLinkId = null): array
    {
        $normalizedType = $this->normalizeSuggestionType($type);

        if ($this->canUseEventsTable()) {
            try {
                $events = $this->eventQueryForOwner($ownerId, $normalizedType, $listLinkId)
                    ->ofEventType(ListItemEvent::EVENT_COMPLETED)
                    ->orderBy('occurred_at')
                    ->get(['text', 'occurred_at']);

                if ($events->isNotEmpty()) {
                    return $events
                        ->map(static function (ListItemEvent $event): ?array {
                            $text = trim((string) $event->text);
                            $timestamp = $event->occurred_at?->toImmutable();
                            if ($text === '' || ! $timestamp instanceof CarbonImmutable) {
                                return null;
                            }

                            return [
                                'text' => $text,
                                'timestamp' => $timestamp,
                            ];
                        })
                        ->filter()
                        ->values()
                        ->all();
                }
            } catch (QueryException $exception) {
                $this->markEventsUnavailable($exception);
            }
        }

        $itemsQuery = ListItem::query()
            ->forOwner($ownerId)
            ->ofType($normalizedType)
            ->where('is_completed', true)
            ->orderBy('completed_at')
            ->orderBy('created_at');
        $this->applyListLinkScope($itemsQuery, $listLinkId);

        return $itemsQuery
            ->get(['text', 'created_at', 'completed_at'])
            ->map(static function (ListItem $item): ?array {
                $text = trim((string) $item->text);
                $timestamp = $item->completed_at?->toImmutable() ?? $item->created_at?->toImmutable();
                if ($text === '' || ! $timestamp instanceof CarbonImmutable) {
                    return null;
                }

                return [
                    'text' => $text,
                    'timestamp' => $timestamp,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    private function countEventsForOwner(int $ownerId, string $type, string $eventType, ?int $listLinkId = null): int
    {
        if (! $this->canUseEventsTable()) {
            return 0;
        }

        try {
            return (int) $this->eventQueryForOwner($ownerId, $type, $listLinkId)
                ->ofEventType($eventType)
                ->count();
        } catch (QueryException $exception) {
            $this->markEventsUnavailable($exception);
            return 0;
        }
    }

    private function uniqueEventKeysForOwner(int $ownerId, string $type, ?int $listLinkId = null): int
    {
        if (! $this->canUseEventsTable()) {
            return 0;
        }

        try {
            return (int) $this->eventQueryForOwner($ownerId, $type, $listLinkId)
                ->where('normalized_text', '!=', '')
                ->distinct()
                ->count('normalized_text');
        } catch (QueryException $exception) {
            $this->markEventsUnavailable($exception);
            return 0;
        }
    }

    private function lastEventActivityForOwner(int $ownerId, string $type, ?int $listLinkId = null): ?CarbonImmutable
    {
        if (! $this->canUseEventsTable()) {
            return null;
        }

        try {
            $value = $this->eventQueryForOwner($ownerId, $type, $listLinkId)
                ->max('occurred_at');
        } catch (QueryException $exception) {
            $this->markEventsUnavailable($exception);
            return null;
        }

        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value);
    }

    private function recordEvent(ListItem $item, string $eventType, CarbonImmutable $occurredAt): void
    {
        if (! $this->canUseEventsTable()) {
            return;
        }

        $text = trim((string) $item->text);
        if ($text === '') {
            return;
        }

        $normalized = $this->textNormalizer->normalizeText($text);
        if ($normalized === '') {
            return;
        }

        try {
            ListItemEvent::query()->create([
                'owner_id' => (int) $item->owner_id,
                'list_link_id' => $item->list_link_id ? (int) $item->list_link_id : null,
                'type' => (string) $item->type,
                'event_type' => $eventType,
                'text' => $text,
                'normalized_text' => $normalized,
                'occurred_at' => $occurredAt,
                'source_item_id' => (int) $item->id,
                'meta' => [
                    'is_completed' => (bool) $item->is_completed,
                    'sort_order' => (int) ($item->sort_order ?? 0),
                ],
            ]);
        } catch (QueryException $exception) {
            $this->markEventsUnavailable($exception);
        }
    }

    private function eventQueryForOwner(int $ownerId, string $type, ?int $listLinkId = null): Builder
    {
        $query = ListItemEvent::query()
            ->forOwner($ownerId)
            ->ofType($type);

        $this->applyListLinkScope($query, $listLinkId);

        return $query;
    }

    private function applyListLinkScope(Builder $query, ?int $listLinkId = null): void
    {
        if ($listLinkId) {
            $query->where('list_link_id', $listLinkId);
            return;
        }

        $query->whereNull('list_link_id');
    }

    private function canUseEventsTable(): bool
    {
        if ($this->eventsTableAvailable !== null) {
            return $this->eventsTableAvailable;
        }

        try {
            $this->eventsTableAvailable = Schema::hasTable('list_item_events');
        } catch (\Throwable $exception) {
            $this->eventsTableAvailable = false;
            Log::warning('Failed to detect list_item_events table availability.', [
                'error' => $exception->getMessage(),
            ]);
        }

        return $this->eventsTableAvailable;
    }

    private function markEventsUnavailable(QueryException $exception): void
    {
        if ($this->eventsTableAvailable === false) {
            return;
        }

        $this->eventsTableAvailable = false;

        Log::warning('List item events storage is unavailable, using fallback suggestion source.', [
            'error' => $exception->getMessage(),
        ]);
    }


    private function resolveClusterStateForSamples(Collection $statesByKey, array $samples): ?ListItemSuggestionState
    {
        $resolvedState = null;

        foreach ($samples as $sample) {
            if (! is_string($sample) || $sample === '') {
                continue;
            }

            $candidate = $statesByKey->get($sample);
            if (! $candidate instanceof ListItemSuggestionState) {
                continue;
            }

            if (! $resolvedState instanceof ListItemSuggestionState) {
                $resolvedState = $candidate;
                continue;
            }

            $resolvedResetAt = $resolvedState->reset_at?->getTimestamp() ?? 0;
            $candidateResetAt = $candidate->reset_at?->getTimestamp() ?? 0;

            if ($candidateResetAt > $resolvedResetAt) {
                $resolvedState = $candidate;
            }
        }

        return $resolvedState;
    }

    /**
     * @param array<int, array<string, mixed>> $clusters
     */
    private function resolveClusterIndex(array $clusters, string $normalized): ?int
    {
        foreach ($clusters as $index => $cluster) {
            foreach ($cluster['normalized_samples'] as $sample) {
                if ($sample === $normalized || $this->textNormalizer->isFuzzyMatch($sample, $normalized)) {
                    return $index;
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, int> $intervals
     */
    private function estimateConfidence(array $intervals, int $occurrences): float
    {
        $average = array_sum($intervals) / count($intervals);
        if ($average <= 0) {
            return 0.0;
        }

        $variance = 0.0;
        foreach ($intervals as $interval) {
            $variance += ($interval - $average) ** 2;
        }

        $variance /= count($intervals);
        $standardDeviation = sqrt($variance);
        $consistency = max(0.0, min(1.0, 1 - ($standardDeviation / $average)));
        $frequency = max(0.0, min(1.0, $occurrences / 8));

        return round(($consistency * 0.55) + ($frequency * 0.45), 2);
    }

    /**
     * @param array<string, int> $variants
     * @return array<string, int>
     */
    private function sortVariants(array $variants): array
    {
        uksort($variants, static function (string $left, string $right) use ($variants): int {
            $leftCount = $variants[$left];
            $rightCount = $variants[$right];

            if ($leftCount !== $rightCount) {
                return $rightCount <=> $leftCount;
            }

            return strcmp($left, $right);
        });

        return $variants;
    }

    private function normalizeSuggestionType(string $type): string
    {
        return $type === ListItem::TYPE_TODO
            ? ListItem::TYPE_TODO
            : ListItem::TYPE_PRODUCT;
    }

    /**
     * @param array<int, array<string, mixed>> $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function filterSuppressedSuggestions(
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

    /**
     * @param array<int, array<string, mixed>> $suggestions
     * @return array<int, array<string, mixed>>
     */
    private function deduplicateSuggestions(array $suggestions): array
    {
        $deduplicated = [];
        $seen = [];

        foreach ($suggestions as $suggestion) {
            $suggestionKey = is_string($suggestion['suggestion_key'] ?? null)
                ? $this->textNormalizer->normalizeSuggestionKey((string) $suggestion['suggestion_key'])
                : '';

            if ($suggestionKey === '') {
                $suggestionKey = $this->textNormalizer->normalizeSuggestionKey((string) ($suggestion['suggested_text'] ?? ''));
            }

            if ($suggestionKey === '') {
                $suggestionKey = mb_strtolower(trim((string) ($suggestion['suggested_text'] ?? '')), 'UTF-8');
            }

            if ($suggestionKey !== '' && isset($seen[$suggestionKey])) {
                continue;
            }

            if ($suggestionKey !== '') {
                $seen[$suggestionKey] = true;
            }

            $deduplicated[] = $suggestion;
        }

        return $deduplicated;
    }
}





