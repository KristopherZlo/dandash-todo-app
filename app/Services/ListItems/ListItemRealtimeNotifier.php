<?php

namespace App\Services\ListItems;

use App\Events\ListItemsChanged;
use App\Models\ListItem;
use Illuminate\Support\Facades\Log;

class ListItemRealtimeNotifier
{
    public function __construct(
        private readonly ListItemSerializer $itemSerializer,
        private readonly ListSyncVersionService $listSyncVersionService
    ) {
    }

    public function dispatchListItemsChangedSafely(
        int $ownerId,
        string $type,
        ?int $listLinkId = null,
        ?int $actorUserId = null,
        ?int $listVersion = null,
        ?array $changePayload = null
    ): void
    {
        try {
            $resolvedListVersion = $listVersion ?? $this->listSyncVersionService->getVersion($ownerId, $type, $listLinkId);
            $payload = $this->buildBroadcastPayload($ownerId, $type, $listLinkId, $changePayload);
            broadcast(new ListItemsChanged(
                ownerId: $ownerId,
                type: $type,
                listLinkId: $listLinkId,
                actorUserId: $actorUserId,
                listVersion: $resolvedListVersion,
                items: $payload['items'],
                mode: $payload['mode'],
                operation: $payload['operation'],
                item: $payload['item'],
                removedItemId: $payload['removed_item_id'],
                activeOrder: $payload['active_order'],
                completedOrder: $payload['completed_order'],
            ))->toOthers();
        } catch (\Throwable $exception) {
            Log::warning('Realtime list update dispatch failed.', [
                'owner_id' => $ownerId,
                'type' => $type,
                'list_link_id' => $listLinkId,
                'actor_user_id' => $actorUserId,
                'list_version' => $listVersion,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function dispatchItemCreatedSafely(ListItem $item, ?int $actorUserId = null, ?int $listVersion = null): void
    {
        $this->dispatchListItemsChangedSafely(
            (int) $item->owner_id,
            (string) $item->type,
            $item->list_link_id ? (int) $item->list_link_id : null,
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
            (int) $item->owner_id,
            (string) $item->type,
            $item->list_link_id ? (int) $item->list_link_id : null,
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
        int $ownerId,
        string $type,
        int $itemId,
        ?int $listLinkId = null,
        ?int $actorUserId = null,
        ?int $listVersion = null
    ): void {
        $this->dispatchListItemsChangedSafely(
            $ownerId,
            $type,
            $listLinkId,
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
        int $ownerId,
        string $type,
        array $activeOrder,
        array $completedOrder,
        ?int $listLinkId = null,
        ?int $actorUserId = null,
        ?int $listVersion = null
    ): void {
        $this->dispatchListItemsChangedSafely(
            $ownerId,
            $type,
            $listLinkId,
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

    private function buildBroadcastPayload(int $ownerId, string $type, ?int $listLinkId, ?array $changePayload): array
    {
        if (! is_array($changePayload) || $changePayload === []) {
            return [
                'mode' => 'snapshot',
                'operation' => null,
                'item' => null,
                'removed_item_id' => null,
                'active_order' => null,
                'completed_order' => null,
                'items' => $this->buildItemsPayload($ownerId, $type, $listLinkId),
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

    private function buildItemsPayload(int $ownerId, string $type, ?int $listLinkId): array
    {
        $query = ListItem::query()
            ->ofType($type)
            ->orderBy('is_completed')
            ->orderBy('sort_order')
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($listLinkId) {
            $query->where('list_link_id', $listLinkId);
        } else {
            $query->forOwner($ownerId)->whereNull('list_link_id');
        }

        return $query
            ->get()
            ->map(fn (ListItem $item): array => $this->itemSerializer->serialize($item))
            ->values()
            ->all();
    }
}
