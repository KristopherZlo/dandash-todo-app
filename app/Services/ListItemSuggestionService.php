<?php

namespace App\Services;

use App\Models\ListItem;
use App\Models\ListItemEvent;
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
    public function suggestForOwner(int $ownerId, string $type, int $limit = 8, ?int $listLinkId = null): array
    {
        $normalizedType = $this->normalizeSuggestionType($type);
        $entries = $this->historySource->loadAdditionEntriesForOwner($ownerId, $normalizedType, $listLinkId);
        $statesByKey = $this->stateManager->statesByKeyForOwner($ownerId, $normalizedType);
        $now = now()->toImmutable();

        $suggestions = $this->analytics->buildSuggestions($entries, $statesByKey, $normalizedType, $now);
        $filtered = $this->stateManager->filterSuppressedSuggestions($ownerId, $normalizedType, $suggestions, $now);
        $deduplicated = $this->analytics->deduplicateSuggestions($filtered);

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
        $entries = $this->historySource->loadCompletionEntriesForOwner($ownerId, $normalizedType, $listLinkId);
        $statesByKey = $this->stateManager->statesByKeyForOwner($ownerId, $normalizedType);

        return $this->analytics->buildStats($entries, $statesByKey, $limit);
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

        $addedCount = $this->historySource->countEventsForOwner(
            $ownerId,
            $normalizedType,
            ListItemEvent::EVENT_ADDED,
            $listLinkId
        );
        $completedCount = $this->historySource->countEventsForOwner(
            $ownerId,
            $normalizedType,
            ListItemEvent::EVENT_COMPLETED,
            $listLinkId
        );
        $uniqueItems = $this->historySource->uniqueEventKeysForOwner($ownerId, $normalizedType, $listLinkId);
        $lastActivityAt = $this->historySource->lastEventActivityForOwner($ownerId, $normalizedType, $listLinkId);

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
        $this->stateManager->resetSuggestionData($ownerId, $this->normalizeSuggestionType($type), $suggestionKey);
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
        $this->stateManager->dismissSuggestion(
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

    private function applyListLinkScope(Builder $query, ?int $listLinkId = null): void
    {
        if ($listLinkId) {
            $query->where('list_link_id', $listLinkId);

            return;
        }

        $query->whereNull('list_link_id');
    }
}
