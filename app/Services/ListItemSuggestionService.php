<?php

namespace App\Services;

use App\Models\ListItem;
use App\Models\ListItemEvent;
use App\Models\ListItemSuggestionState;
use App\Services\ListItemSuggestions\SuggestionAnalytics;
use App\Services\ListItemSuggestions\SuggestionHistorySource;
use App\Services\ListItemSuggestions\SuggestionStateManager;
use App\Services\ListItemSuggestions\SuggestionTextNormalizer;
use Illuminate\Database\Eloquent\Builder;

class ListItemSuggestionService
{
    public function __construct(
        private readonly SuggestionTextNormalizer $textNormalizer,
        private readonly SuggestionHistorySource $historySource,
        private readonly SuggestionStateManager $stateManager,
        private readonly SuggestionAnalytics $analytics
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestForList(int $listId, int $ownerId, string $type, int $limit = 8): array
    {
        $normalizedType = $this->normalizeSuggestionType($type);
        $entries = $this->historySource->loadAdditionEntriesForList($listId, $normalizedType);
        $statesByKey = $this->stateManager->statesByKeyForList($listId, $normalizedType);
        $now = now()->toImmutable();

        $suggestions = $this->analytics->buildSuggestions($entries, $statesByKey, $normalizedType, $now);
        $filtered = $this->stateManager->filterSuppressedSuggestions($listId, $normalizedType, $suggestions, $now);
        $deduplicated = $this->analytics->deduplicateSuggestions($filtered);

        return array_slice($deduplicated, 0, max(1, $limit));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function productStatsForList(int $listId, int $ownerId, int $limit = 50): array
    {
        return $this->suggestionStatsForList($listId, $ownerId, ListItem::TYPE_PRODUCT, $limit);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function suggestionStatsForList(int $listId, int $ownerId, string $type, int $limit = 50): array
    {
        $normalizedType = $this->normalizeSuggestionType($type);
        $limit = max(1, min(200, $limit));
        $entries = $this->historySource->loadCompletionEntriesForList($listId, $normalizedType);
        $statesByKey = $this->stateManager->statesByKeyForList($listId, $normalizedType);

        return $this->analytics->buildStats($entries, $statesByKey, $limit);
    }

    /**
     * @return array{stats: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function productStatsPayloadForList(int $listId, int $ownerId, int $limit = 50): array
    {
        return $this->suggestionStatsPayloadForList($listId, $ownerId, ListItem::TYPE_PRODUCT, $limit);
    }

    /**
     * @return array{stats: array<int, array<string, mixed>>, summary: array<string, mixed>}
     */
    public function suggestionStatsPayloadForList(int $listId, int $ownerId, string $type, int $limit = 50): array
    {
        $normalizedType = $this->normalizeSuggestionType($type);
        $stats = $this->suggestionStatsForList($listId, $ownerId, $normalizedType, $limit);

        $addedCount = $this->historySource->countEventsForList(
            $listId,
            $normalizedType,
            ListItemEvent::EVENT_ADDED
        );
        $completedCount = $this->historySource->countEventsForList(
            $listId,
            $normalizedType,
            ListItemEvent::EVENT_COMPLETED
        );
        $uniqueItems = $this->historySource->uniqueEventKeysForList($listId, $normalizedType);
        $lastActivityAt = $this->historySource->lastEventActivityForList($listId, $normalizedType);

        if ($addedCount === 0) {
            $itemsQuery = ListItem::query()
                ->forList($listId)
                ->ofType($normalizedType);
            $addedCount = (int) $itemsQuery->count();
        }

        if ($completedCount === 0) {
            $itemsQuery = ListItem::query()
                ->forList($listId)
                ->ofType($normalizedType)
                ->where('is_completed', true);
            $completedCount = (int) $itemsQuery->count();
        }

        if ($uniqueItems === 0) {
            $uniqueItems = count($stats);
        }

        $activeSuggestions = $this->suggestForList($listId, $ownerId, $normalizedType, 50);
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

    public function resetSuggestionData(int $listId, int $ownerId, string $type, string $suggestionKey): void
    {
        $this->stateManager->resetSuggestionData($listId, $ownerId, $this->normalizeSuggestionType($type), $suggestionKey);
    }

    public function updateSuggestionSettings(
        int $listId,
        int $ownerId,
        string $type,
        string $suggestionKey,
        ?int $customIntervalSeconds = null,
        ?bool $ignored = null
    ): ?ListItemSuggestionState {
        return $this->stateManager->updateSuggestionSettings(
            $listId,
            $ownerId,
            $this->normalizeSuggestionType($type),
            $suggestionKey,
            $customIntervalSeconds,
            $ignored
        );
    }

    public function normalizeSuggestionKey(string $value): string
    {
        return $this->textNormalizer->normalizeSuggestionKey($value);
    }

    public function dismissSuggestion(
        int $listId,
        int $ownerId,
        string $type,
        string $suggestionKey,
        int $averageIntervalSeconds
    ): void {
        $this->stateManager->dismissSuggestion(
            $listId,
            $ownerId,
            $this->normalizeSuggestionType($type),
            $suggestionKey,
            $averageIntervalSeconds
        );
    }

    public function recordAddedEvent(ListItem $item): void
    {
        $this->historySource->recordAddedEvent($item);
    }

    public function recordCompletedEvent(ListItem $item): void
    {
        $this->historySource->recordCompletedEvent($item);
    }

    private function normalizeSuggestionType(string $type): string
    {
        return $type === ListItem::TYPE_TODO
            ? ListItem::TYPE_TODO
            : ListItem::TYPE_PRODUCT;
    }
}
