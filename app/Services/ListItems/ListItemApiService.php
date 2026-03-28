<?php

namespace App\Services\ListItems;

use App\Models\ListItem;
use App\Services\ListItemSuggestionService;
use App\Services\Lists\ListSummaryService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListItemApiService
{
    public function __construct(
        private readonly ListItemSuggestionService $listItemSuggestionService,
        private readonly ListAccessService $accessService,
        private readonly ListItemSerializer $itemSerializer,
        private readonly ListItemInputNormalizer $inputNormalizer,
        private readonly ListItemOrderingService $orderingService,
        private readonly ListItemRealtimeNotifier $realtimeNotifier,
        private readonly ListSyncVersionService $listSyncVersionService,
        private readonly ListSummaryService $listSummaryService,
    ) {
    }

    public function index(Request $request): array
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'exists:lists,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
        ]);

        $context = $this->accessService->resolveReadContext($request, (int) $validated['list_id']);
        $type = (string) $validated['type'];

        $items = ListItem::query()
            ->forList($context->listId)
            ->ofType($type)
            ->orderBy('is_completed')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ListItem $item): array => $this->itemSerializer->serialize($item))
            ->values()
            ->all();

        return [
            'items' => $items,
            'list_version' => $this->listSyncVersionService->getVersion($context->listId, $type),
            'list_summary' => $this->listSummaryService->summaryForUser($request->user(), $context->listId),
        ];
    }

    public function store(Request $request): array
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'exists:lists,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'text' => ['required', 'string', 'max:255'],
            'client_request_id' => ['nullable', 'string', 'max:120'],
            'is_completed' => ['sometimes', 'boolean'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'unit' => ['nullable', 'string', 'max:24'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in([ListItem::PRIORITY_URGENT, ListItem::PRIORITY_TODAY, ListItem::PRIORITY_LATER])],
        ]);

        $context = $this->accessService->resolveCreateContext($request, (int) $validated['list_id']);
        $type = (string) $validated['type'];
        $isCompleted = (bool) ($validated['is_completed'] ?? false);
        $quantity = $type === ListItem::TYPE_PRODUCT
            ? $this->inputNormalizer->normalizeQuantity($validated['quantity'] ?? null)
            : null;

        $item = ListItem::query()->create([
            'owner_id' => $context->ownerId,
            'list_id' => $context->listId,
            'list_link_id' => null,
            'type' => $type,
            'text' => trim((string) $validated['text']),
            'client_request_id' => isset($validated['client_request_id'])
                ? (trim((string) $validated['client_request_id']) !== ''
                    ? trim((string) $validated['client_request_id'])
                    : null)
                : null,
            'sort_order' => $this->orderingService->nextSortOrder($context->listId, $type, $isCompleted),
            'is_completed' => $isCompleted,
            'completed_at' => $isCompleted ? now() : null,
            'quantity' => $quantity,
            'unit' => $type === ListItem::TYPE_PRODUCT
                ? $this->inputNormalizer->normalizeUnit($quantity !== null ? ($validated['unit'] ?? null) : null)
                : null,
            'due_at' => $type === ListItem::TYPE_TODO ? ($validated['due_at'] ?? null) : null,
            'priority' => $type === ListItem::TYPE_TODO
                ? $this->inputNormalizer->normalizePriority($validated['priority'] ?? null)
                : null,
            'created_by_id' => (int) $request->user()->id,
            'updated_by_id' => (int) $request->user()->id,
        ]);

        $this->listItemSuggestionService->recordAddedEvent($item);
        if ($isCompleted) {
            $this->listItemSuggestionService->recordCompletedEvent($item);
        }

        $this->listSummaryService->touchList($context->listId);
        $listVersion = $this->listSyncVersionService->bumpVersion($context->listId, $type, $context->ownerId);
        $freshItem = $item->fresh();

        $this->realtimeNotifier->dispatchItemCreatedSafely(
            $freshItem,
            (int) $request->user()->id,
            $listVersion,
        );

        return [
            'item' => $this->itemSerializer->serialize($freshItem),
            'list_version' => $listVersion,
            'list_summary' => $this->listSummaryService->summaryForUser($request->user(), $context->listId),
        ];
    }

    public function suggestions(Request $request): array
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'exists:lists,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $context = $this->accessService->resolveReadContext($request, (int) $validated['list_id']);

        return [
            'suggestions' => $this->listItemSuggestionService->suggestForList(
                $context->listId,
                $context->ownerId,
                (string) $validated['type'],
                (int) ($validated['limit'] ?? 8),
            ),
            'generated_at' => now()->toISOString(),
        ];
    }

    public function productStats(Request $request): array
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'exists:lists,id'],
            'type' => ['nullable', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $context = $this->accessService->resolveReadContext($request, (int) $validated['list_id']);

        return $this->listItemSuggestionService->suggestionStatsPayloadForList(
            $context->listId,
            $context->ownerId,
            (string) ($validated['type'] ?? ListItem::TYPE_PRODUCT),
            (int) ($validated['limit'] ?? 50),
        );
    }

    public function resetSuggestionData(Request $request): array
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'exists:lists,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'suggestion_key' => ['required', 'string', 'max:190'],
        ]);

        $context = $this->accessService->resolveReadContext($request, (int) $validated['list_id']);

        $this->listItemSuggestionService->resetSuggestionData(
            $context->listId,
            $context->ownerId,
            (string) $validated['type'],
            (string) $validated['suggestion_key'],
        );

        return ['status' => 'ok'];
    }

    public function updateSuggestionSettings(Request $request): array
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'exists:lists,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'suggestion_key' => ['required', 'string', 'max:190'],
            'custom_interval_seconds' => ['nullable', 'integer', 'min:0', 'max:315360000'],
            'ignored' => ['nullable', 'boolean'],
        ]);

        $context = $this->accessService->resolveReadContext($request, (int) $validated['list_id']);
        $state = $this->listItemSuggestionService->updateSuggestionSettings(
            $context->listId,
            $context->ownerId,
            (string) $validated['type'],
            (string) $validated['suggestion_key'],
            array_key_exists('custom_interval_seconds', $validated)
                ? max(0, (int) ($validated['custom_interval_seconds'] ?? 0))
                : null,
            array_key_exists('ignored', $validated)
                ? (bool) $validated['ignored']
                : null,
        );

        return [
            'status' => 'ok',
            'state' => [
                'suggestion_key' => (string) ($state?->suggestion_key ?? ''),
                'dismissed_count' => (int) ($state?->dismissed_count ?? 0),
                'hidden_until' => $state?->hidden_until?->toISOString(),
                'retired_at' => $state?->retired_at?->toISOString(),
                'reset_at' => $state?->reset_at?->toISOString(),
                'custom_interval_seconds' => $state?->custom_interval_seconds !== null
                    ? (int) $state->custom_interval_seconds
                    : null,
            ],
        ];
    }

    public function dismissSuggestion(Request $request): array
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'exists:lists,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'suggestion_key' => ['required', 'string', 'max:190'],
            'average_interval_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $context = $this->accessService->resolveReadContext($request, (int) $validated['list_id']);

        $this->listItemSuggestionService->dismissSuggestion(
            $context->listId,
            $context->ownerId,
            (string) $validated['type'],
            (string) $validated['suggestion_key'],
            (int) ($validated['average_interval_seconds'] ?? 0),
        );

        return ['status' => 'ok'];
    }

    public function update(Request $request, ListItem $item): array
    {
        $validated = $request->validate([
            'text' => ['sometimes', 'required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in([ListItem::PRIORITY_URGENT, ListItem::PRIORITY_TODAY, ListItem::PRIORITY_LATER])],
            'is_completed' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'unit' => ['nullable', 'string', 'max:24'],
        ]);

        $this->accessService->ensureCanAccessItem($request, $item);
        $completionChanged = false;

        if ($request->has('text')) {
            $item->text = trim((string) $validated['text']);
        }

        if ($request->has('due_at')) {
            $item->due_at = $item->type === ListItem::TYPE_TODO
                ? ($validated['due_at'] ?? null)
                : null;
        }

        if ($request->has('priority')) {
            $item->priority = $item->type === ListItem::TYPE_TODO
                ? $this->inputNormalizer->normalizePriority($validated['priority'] ?? null)
                : null;
        }

        if ($item->type === ListItem::TYPE_PRODUCT) {
            $quantityWasProvided = $request->has('quantity');
            $unitWasProvided = $request->has('unit');

            if ($quantityWasProvided) {
                $item->quantity = $this->inputNormalizer->normalizeQuantity($validated['quantity'] ?? null);
            }

            if ($unitWasProvided) {
                $item->unit = $this->inputNormalizer->normalizeUnit($validated['unit'] ?? null);
            }

            if (($quantityWasProvided || $unitWasProvided) && $item->quantity === null) {
                $item->unit = null;
            }
        } elseif ($request->has('quantity') || $request->has('unit')) {
            $item->quantity = null;
            $item->unit = null;
        }

        if ($request->has('is_completed')) {
            $isCompleted = (bool) $validated['is_completed'];
            $wasCompleted = (bool) $item->is_completed;
            $completionChanged = $isCompleted !== $wasCompleted;
            $item->is_completed = $isCompleted;

            if ($isCompleted) {
                if ($completionChanged || $item->completed_at === null) {
                    $item->completed_at = now();
                }
            } else {
                $item->completed_at = null;
            }
        }

        if ($request->has('sort_order')) {
            $item->sort_order = (int) $validated['sort_order'];
        } elseif ($completionChanged) {
            $item->sort_order = $this->orderingService->nextSortOrder(
                (int) $item->list_id,
                (string) $item->type,
                (bool) $item->is_completed,
            );
        }

        if (! $item->isDirty()) {
            return [
                'item' => $this->itemSerializer->serialize($item),
                'list_version' => $this->listSyncVersionService->getVersion((int) $item->list_id, (string) $item->type),
                'list_summary' => $this->listSummaryService->summaryForUser($request->user(), (int) $item->list_id),
            ];
        }

        $item->updated_by_id = (int) $request->user()->id;
        $item->save();

        if ($completionChanged && (bool) $item->is_completed) {
            $this->listItemSuggestionService->recordCompletedEvent($item);
        }

        $this->listSummaryService->touchList((int) $item->list_id);
        $listVersion = $this->listSyncVersionService->bumpVersion((int) $item->list_id, (string) $item->type, (int) $item->owner_id);
        $freshItem = $item->fresh();

        $this->realtimeNotifier->dispatchItemUpdatedSafely(
            $freshItem,
            (int) $request->user()->id,
            $listVersion,
        );

        return [
            'item' => $this->itemSerializer->serialize($freshItem),
            'list_version' => $listVersion,
            'list_summary' => $this->listSummaryService->summaryForUser($request->user(), (int) $item->list_id),
        ];
    }

    public function reorder(Request $request): array
    {
        $validated = $request->validate([
            'list_id' => ['required', 'integer', 'exists:lists,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'distinct'],
        ]);

        $context = $this->accessService->resolveReadContext($request, (int) $validated['list_id']);
        $type = (string) $validated['type'];

        $orderPayload = $this->orderingService->reorderItemsForScope(
            ListItem::query()->forList($context->listId)->ofType($type),
            (array) $validated['order'],
            (int) $request->user()->id,
        );

        $this->listSummaryService->touchList($context->listId);
        $listVersion = $this->listSyncVersionService->bumpVersion($context->listId, $type, $context->ownerId);

        $this->realtimeNotifier->dispatchListReorderedSafely(
            $context->listId,
            $context->ownerId,
            $type,
            $orderPayload['active_order'] ?? [],
            $orderPayload['completed_order'] ?? [],
            (int) $request->user()->id,
            $listVersion,
        );

        return [
            'status' => 'ok',
            'list_version' => $listVersion,
            'list_summary' => $this->listSummaryService->summaryForUser($request->user(), $context->listId),
        ];
    }

    public function destroy(Request $request, ListItem $item): array
    {
        $this->accessService->ensureCanAccessItem($request, $item);

        $listId = (int) ($item->list_id ?? 0);
        $ownerId = (int) $item->owner_id;
        $type = (string) $item->type;
        $itemId = (int) $item->id;

        $item->delete();

        $this->listSummaryService->touchList($listId);
        $listVersion = $this->listSyncVersionService->bumpVersion($listId, $type, $ownerId);

        $this->realtimeNotifier->dispatchItemDeletedSafely(
            $listId,
            $ownerId,
            $type,
            $itemId,
            (int) $request->user()->id,
            $listVersion,
        );

        return [
            'status' => 'ok',
            'list_version' => $listVersion,
            'list_summary' => $this->listSummaryService->summaryForUser($request->user(), $listId),
        ];
    }
}
