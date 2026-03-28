<?php

namespace App\Services\ListItems;

use App\Events\ListItemsChanged;
use App\Models\ListItem;
use App\Models\ListMember;
use App\Models\UserList;
use Illuminate\Support\Facades\Log;

class ListItemRealtimeNotifier
{
    public function __construct(
        private readonly ListItemSerializer $itemSerializer,
        private readonly ListSyncVersionService $listSyncVersionService,
    ) {
    }

    public function dispatchListItemsChangedSafely(
        int $listId,
        int $ownerId,
        string $type,
        ?int $actorUserId = null,
        ?int $listVersion = null,
        ?array $changePayload = null,
    ): void {
        try {
            $resolvedListVersion = $listVersion ?? $this->listSyncVersionService->getVersion($listId, $type);
            $payload = $this->buildBroadcastPayload($listId, $type, $changePayload);
            broadcast(new ListItemsChanged(
                listId: $listId,
                ownerId: $ownerId,
                type: $type,
                actorUserId: $actorUserId,
                listVersion: $resolvedListVersion,
                items: $payload['items'],
                mode: $payload['mode'],
                operation: $payload['operation'],
                item: $payload['item'],
                removedItemId: $payload['removed_item_id'],
                activeOrder: $payload['active_order'],
                completedOrder: $payload['completed_order'],
                listSummary: $this->buildListSummaryPayload($listId),
            ))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Realtime list update dispatch failed.', [
                'list_id' => $listId,
                'owner_id' => $ownerId,
                'type' => $type,
                'actor_user_id' => $actorUserId,
                'list_version' => $listVersion,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function dispatchItemCreatedSafely(ListItem $item, ?int $actorUserId = null, ?int $listVersion = null): void
    {
        $this->dispatchListItemsChangedSafely(
            (int) $item->list_id,
            (int) $item->owner_id,
            (string) $item->type,
            $actorUserId,
            $listVersion,
            [
                'mode' => 'delta',
                'operation' => 'created',
                'item' => $this->itemSerializer->serialize($item),
            ]
        );
    }

    public function dispatchItemUpdatedSafely(ListItem $item, ?int $actorUserId = null, ?int $listVersion = null): void
    {
        $this->dispatchListItemsChangedSafely(
            (int) $item->list_id,
            (int) $item->owner_id,
            (string) $item->type,
            $actorUserId,
            $listVersion,
            [
                'mode' => 'delta',
                'operation' => 'updated',
                'item' => $this->itemSerializer->serialize($item),
            ]
        );
    }

    public function dispatchItemDeletedSafely(
        int $listId,
        int $ownerId,
        string $type,
        int $itemId,
        ?int $actorUserId = null,
        ?int $listVersion = null,
    ): void {
        $this->dispatchListItemsChangedSafely(
            $listId,
            $ownerId,
            $type,
            $actorUserId,
            $listVersion,
            [
                'mode' => 'delta',
                'operation' => 'deleted',
                'removed_item_id' => $itemId,
            ]
        );
    }

    /**
     * @param  array<int, int>  $activeOrder
     * @param  array<int, int>  $completedOrder
     */
    public function dispatchListReorderedSafely(
        int $listId,
        int $ownerId,
        string $type,
        array $activeOrder,
        array $completedOrder,
        ?int $actorUserId = null,
        ?int $listVersion = null,
    ): void {
        $this->dispatchListItemsChangedSafely(
            $listId,
            $ownerId,
            $type,
            $actorUserId,
            $listVersion,
            [
                'mode' => 'delta',
                'operation' => 'reordered',
                'active_order' => array_values(array_map('intval', $activeOrder)),
                'completed_order' => array_values(array_map('intval', $completedOrder)),
            ]
        );
    }

    private function buildBroadcastPayload(int $listId, string $type, ?array $changePayload): array
    {
        if (! is_array($changePayload) || $changePayload === []) {
            return [
                'mode' => 'snapshot',
                'operation' => null,
                'item' => null,
                'removed_item_id' => null,
                'active_order' => null,
                'completed_order' => null,
                'items' => $this->buildItemsPayload($listId, $type),
            ];
        }

        return [
            'mode' => (string) ($changePayload['mode'] ?? 'delta'),
            'operation' => isset($changePayload['operation']) ? (string) $changePayload['operation'] : null,
            'item' => is_array($changePayload['item'] ?? null) ? $changePayload['item'] : null,
            'removed_item_id' => isset($changePayload['removed_item_id'])
                ? (int) $changePayload['removed_item_id']
                : null,
            'active_order' => is_array($changePayload['active_order'] ?? null)
                ? array_values(array_map('intval', $changePayload['active_order']))
                : null,
            'completed_order' => is_array($changePayload['completed_order'] ?? null)
                ? array_values(array_map('intval', $changePayload['completed_order']))
                : null,
            'items' => is_array($changePayload['items'] ?? null)
                ? array_values($changePayload['items'])
                : [],
        ];
    }

    private function buildItemsPayload(int $listId, string $type): array
    {
        return ListItem::query()
            ->forList($listId)
            ->ofType($type)
            ->orderBy('is_completed')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (ListItem $item): array => $this->itemSerializer->serialize($item))
            ->values()
            ->all();
    }

    private function buildListSummaryPayload(int $listId): ?array
    {
        /** @var UserList|null $list */
        $list = UserList::query()->find($listId);
        if (! $list) {
            return null;
        }

        $counts = ListItem::query()
            ->selectRaw(
                'SUM(CASE WHEN type = ? AND is_completed = 0 THEN 1 ELSE 0 END) as open_products_count,
                SUM(CASE WHEN type = ? AND is_completed = 0 THEN 1 ELSE 0 END) as open_todos_count',
                [ListItem::TYPE_PRODUCT, ListItem::TYPE_TODO]
            )
            ->forList($listId)
            ->first();

        $openProductsCount = (int) ($counts?->open_products_count ?? 0);
        $openTodosCount = (int) ($counts?->open_todos_count ?? 0);

        return [
            'id' => (int) $list->id,
            'name' => (string) $list->name,
            'owner_user_id' => (int) $list->owner_user_id,
            'member_count' => (int) ListMember::query()->where('list_id', $listId)->count(),
            'open_products_count' => $openProductsCount,
            'open_todos_count' => $openTodosCount,
            'total_pending_count' => $openProductsCount + $openTodosCount,
            'last_activity_at' => optional($list->last_activity_at)->toISOString(),
        ];
    }
}
