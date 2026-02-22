<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ListItem;
use App\Services\ListItems\ListAccessService;
use App\Services\ListItems\ListItemInputNormalizer;
use App\Services\ListItems\ListItemOrderingService;
use App\Services\ListItems\ListItemRealtimeNotifier;
use App\Services\ListItems\ListItemSerializer;
use App\Services\ListItems\ListSyncVersionService;
use App\Services\ListItemSuggestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ListItemController extends Controller
{
    public function __construct(
        private readonly ListItemSuggestionService $listItemSuggestionService,
        private readonly ListAccessService $accessService,
        private readonly ListItemSerializer $itemSerializer,
        private readonly ListItemInputNormalizer $inputNormalizer,
        private readonly ListItemOrderingService $orderingService,
        private readonly ListItemRealtimeNotifier $realtimeNotifier,
        private readonly ListSyncVersionService $listSyncVersionService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'link_id' => ['nullable', 'integer', 'exists:list_links,id'],
        ]);

        $ownerId = (int) $validated['owner_id'];
        $type = (string) $validated['type'];
        $linkId = isset($validated['link_id']) ? (int) $validated['link_id'] : null;

        $context = $this->accessService->resolveReadContext($request, $ownerId, $linkId);

        $query = ListItem::query()
            ->ofType($type)
            ->orderBy('is_completed')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($context->linkId) {
            $query->where('list_link_id', $context->linkId);
        } else {
            $query->forOwner($context->ownerId)->whereNull('list_link_id');
        }

        $items = $query
            ->get()
            ->map(fn (ListItem $item): array => $this->itemSerializer->serialize($item));

        return response()->json([
            'items' => $items,
            'list_version' => $this->listSyncVersionService->getVersion(
                $context->ownerId,
                $type,
                $context->linkId
            ),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'link_id' => ['nullable', 'integer', 'exists:list_links,id'],
            'text' => ['required', 'string', 'max:255'],
            'quantity' => ['nullable', 'numeric', 'min:0.01', 'max:99999999.99'],
            'unit' => ['nullable', 'string', 'max:24'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['nullable', Rule::in([ListItem::PRIORITY_URGENT, ListItem::PRIORITY_TODAY, ListItem::PRIORITY_LATER])],
        ]);

        $ownerId = (int) $validated['owner_id'];
        $type = (string) $validated['type'];
        $linkId = isset($validated['link_id']) ? (int) $validated['link_id'] : null;

        $context = $this->accessService->resolveCreateContext($request, $ownerId, $linkId);
        $quantity = $type === ListItem::TYPE_PRODUCT
            ? $this->inputNormalizer->normalizeQuantity($validated['quantity'] ?? null)
            : null;

        $item = ListItem::query()->create([
            'owner_id' => $context->ownerId,
            'list_link_id' => $context->linkId,
            'type' => $type,
            'text' => trim($validated['text']),
            'sort_order' => $this->orderingService->nextSortOrder($context->ownerId, $type, false, $context->linkId),
            'quantity' => $quantity,
            'unit' => $type === ListItem::TYPE_PRODUCT
                ? $this->inputNormalizer->normalizeUnit($quantity !== null ? ($validated['unit'] ?? null) : null)
                : null,
            'due_at' => $type === ListItem::TYPE_TODO ? ($validated['due_at'] ?? null) : null,
            'priority' => $type === ListItem::TYPE_TODO
                ? $this->inputNormalizer->normalizePriority($validated['priority'] ?? null)
                : null,
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
        ]);
        $this->listItemSuggestionService->recordAddedEvent($item);
        $listVersion = $this->listSyncVersionService->bumpVersion(
            (int) $item->owner_id,
            (string) $item->type,
            $item->list_link_id ? (int) $item->list_link_id : null
        );

        $this->realtimeNotifier->dispatchListItemsChangedSafely(
            $item->owner_id,
            $item->type,
            $item->list_link_id ? (int) $item->list_link_id : null,
            (int) $request->user()->id,
            $listVersion
        );

        return response()->json([
            'item' => $this->itemSerializer->serialize($item->fresh()),
            'list_version' => $listVersion,
        ], 201);
    }

    public function suggestions(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'link_id' => ['nullable', 'integer', 'exists:list_links,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:20'],
        ]);

        $ownerId = (int) $validated['owner_id'];
        $type = (string) $validated['type'];
        $linkId = isset($validated['link_id']) ? (int) $validated['link_id'] : null;
        $limit = (int) ($validated['limit'] ?? 8);

        $context = $this->accessService->resolveReadContext($request, $ownerId, $linkId);

        return response()->json([
            'suggestions' => $this->listItemSuggestionService->suggestForOwner($context->ownerId, $type, $limit, $context->linkId),
            'generated_at' => now()->toISOString(),
        ]);
    }

    public function productStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['nullable', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'link_id' => ['nullable', 'integer', 'exists:list_links,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $ownerId = (int) $validated['owner_id'];
        $type = (string) ($validated['type'] ?? ListItem::TYPE_PRODUCT);
        $linkId = isset($validated['link_id']) ? (int) $validated['link_id'] : null;
        $limit = (int) ($validated['limit'] ?? 50);

        $context = $this->accessService->resolveReadContext($request, $ownerId, $linkId);

        return response()->json(
            $this->listItemSuggestionService->suggestionStatsPayloadForOwner(
                $context->ownerId,
                $type,
                $limit,
                $context->linkId
            )
        );
    }

    public function resetSuggestionData(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'link_id' => ['nullable', 'integer', 'exists:list_links,id'],
            'suggestion_key' => ['required', 'string', 'max:190'],
        ]);

        $ownerId = (int) $validated['owner_id'];
        $type = (string) $validated['type'];
        $linkId = isset($validated['link_id']) ? (int) $validated['link_id'] : null;

        $context = $this->accessService->resolveReadContext($request, $ownerId, $linkId);

        $this->listItemSuggestionService->resetSuggestionData(
            $context->ownerId,
            $type,
            (string) $validated['suggestion_key']
        );

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function dismissSuggestion(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'link_id' => ['nullable', 'integer', 'exists:list_links,id'],
            'suggestion_key' => ['required', 'string', 'max:190'],
            'average_interval_seconds' => ['nullable', 'integer', 'min:0'],
        ]);

        $ownerId = (int) $validated['owner_id'];
        $type = (string) $validated['type'];
        $linkId = isset($validated['link_id']) ? (int) $validated['link_id'] : null;
        $averageIntervalSeconds = (int) ($validated['average_interval_seconds'] ?? 0);

        $context = $this->accessService->resolveReadContext($request, $ownerId, $linkId);

        $this->listItemSuggestionService->dismissSuggestion(
            $context->ownerId,
            $type,
            (string) $validated['suggestion_key'],
            $averageIntervalSeconds
        );

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function update(Request $request, ListItem $item): JsonResponse
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
                $item->owner_id,
                $item->type,
                (bool) $item->is_completed,
                $item->list_link_id ? (int) $item->list_link_id : null
            );
        }

        if (! $item->isDirty()) {
            return response()->json([
                'item' => $this->itemSerializer->serialize($item),
                'list_version' => $this->listSyncVersionService->getVersion(
                    (int) $item->owner_id,
                    (string) $item->type,
                    $item->list_link_id ? (int) $item->list_link_id : null
                ),
            ]);
        }

        $item->updated_by_id = $request->user()->id;
        $item->save();

        if ($completionChanged && (bool) $item->is_completed) {
            $this->listItemSuggestionService->recordCompletedEvent($item);
        }
        $listVersion = $this->listSyncVersionService->bumpVersion(
            (int) $item->owner_id,
            (string) $item->type,
            $item->list_link_id ? (int) $item->list_link_id : null
        );

        $this->realtimeNotifier->dispatchListItemsChangedSafely(
            $item->owner_id,
            $item->type,
            $item->list_link_id ? (int) $item->list_link_id : null,
            (int) $request->user()->id,
            $listVersion
        );

        return response()->json([
            'item' => $this->itemSerializer->serialize($item->fresh()),
            'list_version' => $listVersion,
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', Rule::in([ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO])],
            'link_id' => ['nullable', 'integer', 'exists:list_links,id'],
            'order' => ['required', 'array', 'min:1'],
            'order.*' => ['integer', 'distinct'],
        ]);

        $ownerId = (int) $validated['owner_id'];
        $type = (string) $validated['type'];
        $linkId = isset($validated['link_id']) ? (int) $validated['link_id'] : null;
        $context = $this->accessService->resolveReadContext($request, $ownerId, $linkId);

        $itemsQuery = ListItem::query()->ofType($type);

        if ($context->linkId) {
            $itemsQuery->where('list_link_id', $context->linkId);
        } else {
            $itemsQuery->forOwner($context->ownerId)->whereNull('list_link_id');
        }

        $this->orderingService->reorderItemsForScope(
            $itemsQuery,
            (array) $validated['order'],
            (int) $request->user()->id
        );
        $listVersion = $this->listSyncVersionService->bumpVersion(
            (int) $context->ownerId,
            (string) $type,
            $context->linkId
        );

        $this->realtimeNotifier->dispatchListItemsChangedSafely(
            $context->ownerId,
            $type,
            $context->linkId,
            (int) $request->user()->id,
            $listVersion
        );

        return response()->json([
            'status' => 'ok',
            'list_version' => $listVersion,
        ]);
    }

    public function destroy(Request $request, ListItem $item): JsonResponse
    {
        $this->accessService->ensureCanAccessItem($request, $item);

        $ownerId = $item->owner_id;
        $type = $item->type;
        $linkId = $item->list_link_id ? (int) $item->list_link_id : null;
        $item->delete();
        $listVersion = $this->listSyncVersionService->bumpVersion(
            (int) $ownerId,
            (string) $type,
            $linkId
        );

        $this->realtimeNotifier->dispatchListItemsChangedSafely(
            $ownerId,
            $type,
            $linkId,
            (int) $request->user()->id,
            $listVersion
        );

        return response()->json([
            'status' => 'ok',
            'list_version' => $listVersion,
        ]);
    }
}
