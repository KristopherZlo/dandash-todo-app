<?php

namespace App\Services\ListItems;

use App\Events\ListItemsChanged;
use Illuminate\Support\Facades\Log;

class ListItemRealtimeNotifier
{
    public function dispatchListItemsChangedSafely(int $ownerId, string $type, ?int $listLinkId = null): void
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
