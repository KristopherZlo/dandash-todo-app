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
        ?int $listVersion = null
    ): void
    {
        try {
            $resolvedListVersion = $listVersion ?? $this->listSyncVersionService->getVersion($ownerId, $type, $listLinkId);
            $items = $this->buildItemsPayload($ownerId, $type, $listLinkId);
            broadcast(new ListItemsChanged(
                $ownerId,
                $type,
                $listLinkId,
                $actorUserId,
                $resolvedListVersion,
                $items
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
