<?php

namespace App\Services\ListItems;

use App\Models\ListItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ListItemOrderingService
{
    public function nextSortOrder(int $ownerId, string $type, bool $isCompleted, ?int $listLinkId = null): int
    {
        $query = ListItem::query()
            ->ofType($type)
            ->where('is_completed', $isCompleted);

        $this->applyScope($query, $ownerId, $listLinkId);

        $minSortOrder = $query->min('sort_order');

        if ($minSortOrder === null) {
            return 1000;
        }

        return ((int) $minSortOrder) - 1000;
    }

    /**
     * @param  array<int, int|string>  $requestedOrder
     */
    public function reorderItemsForScope(Builder $itemsQuery, array $requestedOrder, int $updatedById): void
    {
        $itemsById = $itemsQuery->get()->keyBy('id');

        if ($itemsById->isEmpty()) {
            return;
        }

        $orderedIds = collect($requestedOrder)
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->filter(fn (int $id): bool => $itemsById->has($id))
            ->values();

        if ($orderedIds->isEmpty()) {
            return;
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

        $this->persistOrders(
            $itemsById,
            $activeIds->concat($remainingActiveIds)->values(),
            $completedIds->concat($remainingCompletedIds)->values(),
            $updatedById
        );
    }

    private function applyScope(Builder $query, int $ownerId, ?int $listLinkId = null): void
    {
        if ($listLinkId) {
            $query->where('list_link_id', $listLinkId);

            return;
        }

        $query->forOwner($ownerId)->whereNull('list_link_id');
    }

    private function persistOrders(
        Collection $itemsById,
        Collection $finalActiveIds,
        Collection $finalCompletedIds,
        int $updatedById
    ): void {
        DB::transaction(function () use ($itemsById, $finalActiveIds, $finalCompletedIds, $updatedById): void {
            $activeOrder = 1000;
            foreach ($finalActiveIds as $itemId) {
                /** @var ListItem|null $item */
                $item = $itemsById->get($itemId);
                if (! $item) {
                    continue;
                }

                $item->sort_order = $activeOrder;
                $item->updated_by_id = $updatedById;
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
                $item->updated_by_id = $updatedById;
                $item->save();
                $completedOrder += 1000;
            }
        });
    }
}
