<?php

namespace App\Http\Controllers\Api;

use App\Events\ListItemsChanged;
use App\Http\Controllers\Controller;
use App\Models\ListItem;
use App\Models\ListLink;
use App\Services\ListItemSuggestionService;
use App\Services\ListSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class ListItemController extends Controller
{
    public function __construct(
        private readonly ListSyncService $listSyncService,
        private readonly ListItemSuggestionService $listItemSuggestionService
    ) {
    }

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

        $query = ListItem::query()
            ->ofType($type)
            ->orderBy('is_completed')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($linkId) {
            $this->resolveAccessibleLink($request, $linkId);
            $query->where('list_link_id', $linkId);
        } else {
            abort_unless($request->user()->id === $ownerId, 403, 'You do not have access to this list.');
            $query->forOwner($ownerId)->whereNull('list_link_id');
        }

        $items = $query
            ->get()
            ->map(fn (ListItem $item): array => $this->serializeItem($item));

        return response()->json([
            'items' => $items,
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

        if ($linkId) {
            $link = $this->resolveAccessibleLink($request, $linkId);
            $ownerId = (int) $link->user_one_id;
        } else {
            if ($request->user()->id !== $ownerId) {
                $link = $this->resolveAccessibleLinkByOwner($request, $ownerId);
                $linkId = (int) $link->id;
                $ownerId = (int) $link->user_one_id;
            }
        }

        $item = ListItem::query()->create([
            'owner_id' => $ownerId,
            'list_link_id' => $linkId,
            'type' => $type,
            'text' => trim($validated['text']),
            'sort_order' => $this->nextSortOrder($ownerId, $type, false, $linkId),
            'quantity' => $type === ListItem::TYPE_PRODUCT
                ? $this->normalizeQuantity($validated['quantity'] ?? null)
                : null,
            'unit' => $type === ListItem::TYPE_PRODUCT
                ? $this->normalizeUnit(
                    $this->normalizeQuantity($validated['quantity'] ?? null) !== null
                        ? ($validated['unit'] ?? null)
                        : null
                )
                : null,
            'due_at' => $type === ListItem::TYPE_TODO ? ($validated['due_at'] ?? null) : null,
            'priority' => $type === ListItem::TYPE_TODO
                ? $this->normalizePriority($validated['priority'] ?? null)
                : null,
            'created_by_id' => $request->user()->id,
            'updated_by_id' => $request->user()->id,
        ]);
        $this->listItemSuggestionService->recordAddedEvent($item);

        $this->dispatchListItemsChangedSafely(
            $item->owner_id,
            $item->type,
            $item->list_link_id ? (int) $item->list_link_id : null
        );

        return response()->json([
            'item' => $this->serializeItem($item->fresh()),
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

        if ($linkId) {
            $link = $this->resolveAccessibleLink($request, $linkId);
            $ownerId = (int) $link->user_one_id;
        } else {
            $this->ensureCanAccess($request, $ownerId);
        }

        return response()->json([
            'suggestions' => $this->listItemSuggestionService->suggestForOwner($ownerId, $type, $limit, $linkId),
            'generated_at' => now()->toISOString(),
        ]);
    }

    public function productStats(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'owner_id' => ['required', 'integer', 'exists:users,id'],
            'link_id' => ['nullable', 'integer', 'exists:list_links,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        $ownerId = (int) $validated['owner_id'];
        $linkId = isset($validated['link_id']) ? (int) $validated['link_id'] : null;
        $limit = (int) ($validated['limit'] ?? 50);

        if ($linkId) {
            $link = $this->resolveAccessibleLink($request, $linkId);
            $ownerId = (int) $link->user_one_id;
        } else {
            $this->ensureCanAccess($request, $ownerId);
        }

        return response()->json(
            $this->listItemSuggestionService->productStatsPayloadForOwner($ownerId, $limit, $linkId)
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

        if ($linkId) {
            $link = $this->resolveAccessibleLink($request, $linkId);
            $ownerId = (int) $link->user_one_id;
        } else {
            $this->ensureCanAccess($request, $ownerId);
        }

        $this->listItemSuggestionService->resetSuggestionData(
            $ownerId,
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

        if ($linkId) {
            $link = $this->resolveAccessibleLink($request, $linkId);
            $ownerId = (int) $link->user_one_id;
        } else {
            $this->ensureCanAccess($request, $ownerId);
        }

        $this->listItemSuggestionService->dismissSuggestion(
            $ownerId,
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

        $this->ensureCanAccessItem($request, $item);
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
                ? $this->normalizePriority($validated['priority'] ?? null)
                : null;
        }

        if ($item->type === ListItem::TYPE_PRODUCT) {
            $quantityWasProvided = $request->has('quantity');
            $unitWasProvided = $request->has('unit');

            if ($quantityWasProvided) {
                $item->quantity = $this->normalizeQuantity($validated['quantity'] ?? null);
            }

            if ($unitWasProvided) {
                $item->unit = $this->normalizeUnit($validated['unit'] ?? null);
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
            $item->sort_order = $this->nextSortOrder(
                $item->owner_id,
                $item->type,
                (bool) $item->is_completed,
                $item->list_link_id ? (int) $item->list_link_id : null
            );
        }

        if (! $item->isDirty()) {
            return response()->json([
                'item' => $this->serializeItem($item),
            ]);
        }

        $item->updated_by_id = $request->user()->id;
        $item->save();

        if ($completionChanged && (bool) $item->is_completed) {
            $this->listItemSuggestionService->recordCompletedEvent($item);
        }

        $this->dispatchListItemsChangedSafely(
            $item->owner_id,
            $item->type,
            $item->list_link_id ? (int) $item->list_link_id : null
        );

        return response()->json([
            'item' => $this->serializeItem($item->fresh()),
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

        $itemsQuery = ListItem::query()->ofType($type);

        if ($linkId) {
            $link = $this->resolveAccessibleLink($request, $linkId);
            $ownerId = (int) $link->user_one_id;
            $itemsQuery->where('list_link_id', $linkId);
        } else {
            abort_unless($request->user()->id === $ownerId, 403, 'You do not have access to this list.');
            $itemsQuery->forOwner($ownerId)->whereNull('list_link_id');
        }

        $itemsById = $itemsQuery->get()
            ->keyBy('id');

        if ($itemsById->isEmpty()) {
            return response()->json(['status' => 'ok']);
        }

        $requestedOrder = collect($validated['order'])
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        $orderedIds = $requestedOrder
            ->filter(fn (int $id): bool => $itemsById->has($id))
            ->values();

        if ($orderedIds->isEmpty()) {
            return response()->json(['status' => 'ok']);
        }

        $activeIds = $orderedIds
            ->filter(fn (int $id): bool => ! (bool) $itemsById->get($id)?->is_completed)
            ->values();
        $completedIds = $orderedIds
            ->filter(fn (int $id): bool => (bool) $itemsById->get($id)?->is_completed)
            ->values();

        $remainingActiveIds = $itemsById
            ->filter(fn (ListItem $item): bool => ! (bool) $item->is_completed)
            ->keys()
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => ! $activeIds->contains($id))
            ->values();
        $remainingCompletedIds = $itemsById
            ->filter(fn (ListItem $item): bool => (bool) $item->is_completed)
            ->keys()
            ->map(static fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => ! $completedIds->contains($id))
            ->values();

        $finalActiveIds = $activeIds->concat($remainingActiveIds)->values();
        $finalCompletedIds = $completedIds->concat($remainingCompletedIds)->values();

        DB::transaction(function () use ($itemsById, $finalActiveIds, $finalCompletedIds, $request): void {
            $activeOrder = 1000;
            foreach ($finalActiveIds as $itemId) {
                /** @var ListItem|null $item */
                $item = $itemsById->get($itemId);
                if (! $item) {
                    continue;
                }

                $item->sort_order = $activeOrder;
                $item->updated_by_id = $request->user()->id;
                $item->save();
                $activeOrder += 1000;
            }

            $completedOrder = 1000;
            foreach ($finalCompletedIds as $itemId) {
                /** @var ListItem|null $item */
                $item = $itemsById->get($itemId);
                if (! $item) {
                    continue;
                }

                $item->sort_order = $completedOrder;
                $item->updated_by_id = $request->user()->id;
                $item->save();
                $completedOrder += 1000;
            }
        });

        $this->dispatchListItemsChangedSafely($ownerId, $type, $linkId);

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function destroy(Request $request, ListItem $item): JsonResponse
    {
        $this->ensureCanAccessItem($request, $item);

        $ownerId = $item->owner_id;
        $type = $item->type;
        $item->delete();

        $this->dispatchListItemsChangedSafely(
            $ownerId,
            $type,
            $item->list_link_id ? (int) $item->list_link_id : null
        );

        return response()->json([
            'status' => 'ok',
        ]);
    }

    private function ensureCanAccess(Request $request, int $ownerId): void
    {
        abort_unless(
            $request->user()->id === $ownerId,
            403,
            'You do not have access to this list.'
        );
    }

    private function ensureCanAccessItem(Request $request, ListItem $item): void
    {
        if ($item->list_link_id) {
            $this->resolveAccessibleLink($request, (int) $item->list_link_id);
            return;
        }

        abort_unless($request->user()->id === (int) $item->owner_id, 403, 'You do not have access to this list.');
    }

    private function resolveAccessibleLink(Request $request, int $linkId): ListLink
    {
        $link = ListLink::query()->findOrFail($linkId);

        abort_unless(
            $link->is_active && $link->involvesUser($request->user()->id),
            403,
            'You do not have access to this shared list.'
        );

        return $link;
    }

    private function resolveAccessibleLinkByOwner(Request $request, int $ownerId): ListLink
    {
        $currentUserId = (int) $request->user()->id;

        $link = ListLink::query()
            ->where('is_active', true)
            ->where('user_one_id', $ownerId)
            ->where(function ($query) use ($currentUserId): void {
                $query->where('user_one_id', $currentUserId)
                    ->orWhere('user_two_id', $currentUserId);
            })
            ->first();

        abort_unless($link, 403, 'You do not have access to this shared list.');

        return $link;
    }

    private function serializeItem(ListItem $item): array
    {
        return [
            'id' => $item->id,
            'owner_id' => $item->owner_id,
            'list_link_id' => $item->list_link_id ? (int) $item->list_link_id : null,
            'type' => $item->type,
            'text' => $item->text,
            'sort_order' => (int) ($item->sort_order ?? 0),
            'quantity' => $item->quantity !== null ? (float) $item->quantity : null,
            'unit' => $item->unit,
            'due_at' => optional($item->due_at)->toISOString(),
            'priority' => $item->type === ListItem::TYPE_TODO
                ? $this->normalizePriority($item->priority)
                : null,
            'is_completed' => (bool) $item->is_completed,
            'completed_at' => optional($item->completed_at)->toISOString(),
            'created_at' => optional($item->created_at)->toISOString(),
            'updated_at' => optional($item->updated_at)->toISOString(),
        ];
    }

    private function normalizeQuantity(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $quantity = (float) $value;
        if ($quantity <= 0) {
            return null;
        }

        return round($quantity, 2);
    }

    private function normalizePriority(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $priority = mb_strtolower(trim($value), 'UTF-8');

        return match ($priority) {
            ListItem::PRIORITY_URGENT, ListItem::PRIORITY_TODAY, ListItem::PRIORITY_LATER => $priority,
            default => null,
        };
    }

    private function normalizeUnit(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $unit = mb_strtolower(trim($value), 'UTF-8');
        $unit = str_replace('ё', 'е', $unit);
        $unit = preg_replace('/[.,;:]+$/u', '', $unit) ?? $unit;
        if ($unit === '') {
            return null;
        }

        $aliases = [
            'шт' => 'шт',
            'штука' => 'шт',
            'штуки' => 'шт',
            'штук' => 'шт',
            'штучка' => 'шт',
            'штучки' => 'шт',
            'штучек' => 'шт',
            'ед' => 'шт',
            'единица' => 'шт',
            'единицы' => 'шт',
            'единиц' => 'шт',
            'pc' => 'шт',
            'pcs' => 'шт',
            'piece' => 'шт',
            'pieces' => 'шт',
            'кг' => 'кг',
            'kg' => 'кг',
            'кило' => 'кг',
            'килограмм' => 'кг',
            'килограмма' => 'кг',
            'килограммов' => 'кг',
            'г' => 'г',
            'гр' => 'г',
            'gram' => 'г',
            'grams' => 'г',
            'грамм' => 'г',
            'грамма' => 'г',
            'граммов' => 'г',
            'л' => 'л',
            'l' => 'л',
            'литр' => 'л',
            'литра' => 'л',
            'литров' => 'л',
            'мл' => 'мл',
            'ml' => 'мл',
            'миллилитр' => 'мл',
            'миллилитра' => 'мл',
            'миллилитров' => 'мл',
            'уп' => 'уп',
            'упак' => 'уп',
            'упаковка' => 'уп',
            'упаковки' => 'уп',
            'упаковок' => 'уп',
            'pack' => 'уп',
            'packs' => 'уп',
            'package' => 'уп',
            'packages' => 'уп',
            'pkg' => 'уп',
            'пач' => 'пач',
            'пачка' => 'пач',
            'пачки' => 'пач',
            'пачек' => 'пач',
            'пак' => 'пак',
            'пакет' => 'пак',
            'пакета' => 'пак',
            'пакетов' => 'пак',
            'packet' => 'пак',
            'packets' => 'пак',
            'бут' => 'бут',
            'бутылка' => 'бут',
            'бутылки' => 'бут',
            'бутылок' => 'бут',
            'bottle' => 'бут',
            'bottles' => 'бут',
            'бан' => 'бан',
            'банка' => 'бан',
            'банки' => 'бан',
            'банок' => 'бан',
            'jar' => 'бан',
            'jars' => 'бан',
            'кор' => 'кор',
            'коробка' => 'кор',
            'коробки' => 'кор',
            'коробок' => 'кор',
            'box' => 'кор',
            'boxes' => 'кор',
            'рул' => 'рул',
            'рулон' => 'рул',
            'рулона' => 'рул',
            'рулонов' => 'рул',
            'roll' => 'рул',
            'rolls' => 'рул',
            'дюж' => 'дюж',
            'дюжина' => 'дюж',
            'дюжины' => 'дюж',
            'дюжин' => 'дюж',
            'dozen' => 'дюж',
            'dozens' => 'дюж',
            'dz' => 'дюж',
            'порц' => 'порц',
            'порция' => 'порц',
            'порции' => 'порц',
            'порций' => 'порц',
            'portion' => 'порц',
            'portions' => 'порц',
        ];

        $normalized = $aliases[$unit] ?? null;

        if ($normalized !== null) {
            return $normalized;
        }

        return mb_substr($unit, 0, 24);
    }

    private function nextSortOrder(int $ownerId, string $type, bool $isCompleted, ?int $listLinkId = null): int
    {
        $query = ListItem::query()
            ->ofType($type)
            ->where('is_completed', $isCompleted);

        if ($listLinkId) {
            $query->where('list_link_id', $listLinkId);
        } else {
            $query->forOwner($ownerId)->whereNull('list_link_id');
        }

        $minSortOrder = $query->min('sort_order');

        if ($minSortOrder === null) {
            return 1000;
        }

        return ((int) $minSortOrder) - 1000;
    }

    private function dispatchListItemsChangedSafely(int $ownerId, string $type, ?int $listLinkId = null): void
    {
        try {
            ListItemsChanged::dispatch($ownerId, $type, $listLinkId);
        } catch (\Throwable $exception) {
            Log::warning('Realtime list update dispatch failed.', [
                'owner_id' => $ownerId,
                'type' => $type,
                'list_link_id' => $listLinkId,
                'error' => $exception->getMessage(),
            ]);
        }
    }
}
