<?php

namespace App\Services\ListItems;

use App\Models\ListItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ListItemOrderingService
{
    public function nextSortOrder(int $listId, string $type, bool $isCompleted): int
    {
        $query = ListItem::query()
            ->ofType($type)
            ->where('is_completed', $isCompleted)
            ->forList($listId);

        $minSortOrder = $query->min('sort_order');

        if ($minSortOrder === null) {
            return 1000;
        }

        return ((int) $minSortOrder) - 1000;
    }

    /**
     * @param  array<int, int|string>  $requestedOrder
     * @return array{active_order: array<int, int>, completed_order: array<int, int>}
     */
    public function reorderItemsForScope(Builder $itemsQuery, array $requestedOrder, int $updatedById): array
    {
        $itemsById = $itemsQuery->get()->keyBy('id');

        if ($itemsById->isEmpty()) {
            return [
                'active_order' => [],
                'completed_order' => [],
            ];
        }

        $orderedIds = collect($requestedOrder)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->filter(fn (int $id): bool => $itemsById->has($id))
            ->values();

        if ($orderedIds->isEmpty()) {
            return [
                'active_order' => [],
                'completed_order' => [],
            ];
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

        return $this->persistOrders(
            $itemsById,
            $activeIds->concat($remainingActiveIds)->values(),
            $completedIds->concat($remainingCompletedIds)->values(),
            $updatedById
        );
    }

    private function persistOrders(
        Collection $itemsById,
        Collection $finalActiveIds,
        Collection $finalCompletedIds,
        int $updatedById
    ): array {
        return DB::transaction(function () use ($itemsById, $finalActiveIds, $finalCompletedIds, $updatedById): array {
            $updatedAt = now();
            $orderByItemId = [];
            $activeOrder = 1000;
            foreach ($finalActiveIds as $itemId) {
                /** @var ListItem|null $item */
                $item = $itemsById->get($itemId);
                if (! $item) {
                    continue;
                }

                $orderByItemId[(int) $item->id] = $activeOrder;
                $activeOrder += 1000;
            }

            $completedOrder = 1000;
            foreach ($finalCompletedIds as $itemId) {
                /** @var ListItem|null $item */
                $item = $itemsById->get($itemId);
                if (! $item) {
                    continue;
                }

                $orderByItemId[(int) $item->id] = $completedOrder;
                $completedOrder += 1000;
            }

            if ($orderByItemId === []) {
                return [
                    'active_order' => [],
                    'completed_order' => [],
                ];
            }

            $itemIds = array_keys($orderByItemId);
            $caseSql = collect($orderByItemId)
                ->map(fn (int $sortOrder, int $itemId): string => "WHEN {$itemId} THEN {$sortOrder}")
                ->implode(' ');

            $idPlaceholders = implode(', ', array_fill(0, count($itemIds), '?'));
            $updatedAtSql = $updatedAt->format('Y-m-d H:i:s');

            DB::update(
                "UPDATE list_items
                SET sort_order = CASE id {$caseSql} END,
                    updated_by_id = ?,
                    updated_at = ?
                WHERE id IN ({$idPlaceholders})",
                [
                    $updatedById,
                    $updatedAtSql,
                    ...$itemIds,
                ]
            );

            return [
                'active_order' => $finalActiveIds
                    ->map(static fn (mixed $itemId): int => (int) $itemId)
                    ->values()
                    ->all(),
                'completed_order' => $finalCompletedIds
                    ->map(static fn (mixed $itemId): int => (int) $itemId)
                    ->values()
                    ->all(),
            ];
        });
    }
}
