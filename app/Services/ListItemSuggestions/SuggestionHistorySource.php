<?php

namespace App\Services\ListItemSuggestions;

use App\Models\ListItem;
use App\Models\ListItemEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SuggestionHistorySource
{
    private ?bool $eventsTableAvailable = null;

    public function __construct(
        private readonly SuggestionTextNormalizer $textNormalizer
    ) {}

    /**
     * @return array<int, array{text: string, timestamp: CarbonImmutable}>
     */
    public function loadAdditionEntriesForOwner(int $ownerId, string $type, ?int $listLinkId = null): array
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
    public function loadCompletionEntriesForOwner(int $ownerId, string $type, ?int $listLinkId = null): array
    {
        if ($this->canUseEventsTable()) {
            try {
                $events = $this->eventQueryForOwner($ownerId, $type, $listLinkId)
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
            ->ofType($type)
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

    public function countEventsForOwner(int $ownerId, string $type, string $eventType, ?int $listLinkId = null): int
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

    public function uniqueEventKeysForOwner(int $ownerId, string $type, ?int $listLinkId = null): int
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

    public function lastEventActivityForOwner(int $ownerId, string $type, ?int $listLinkId = null): ?CarbonImmutable
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
}
