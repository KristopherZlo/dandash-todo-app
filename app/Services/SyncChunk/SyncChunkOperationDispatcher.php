<?php

namespace App\Services\SyncChunk;

use App\Services\SyncChunk\Contracts\SyncChunkActionHandler;
use App\Services\SyncChunk\Handlers\ListItemSyncChunkActionHandler;
use App\Services\SyncChunk\Handlers\ProfileSyncChunkActionHandler;
use App\Services\SyncChunk\Handlers\SharingSyncChunkActionHandler;
use App\Services\SyncChunk\Handlers\UserStateSyncChunkActionHandler;
use Illuminate\Http\Request;

class SyncChunkOperationDispatcher
{
    /** @var array<int, SyncChunkActionHandler> */
    private array $handlers;

    public function __construct(
        ListItemSyncChunkActionHandler $listItemHandler,
        SharingSyncChunkActionHandler $sharingHandler,
        ProfileSyncChunkActionHandler $profileHandler,
        UserStateSyncChunkActionHandler $userStateHandler
    ) {
        $this->handlers = [
            $listItemHandler,
            $sharingHandler,
            $profileHandler,
            $userStateHandler,
        ];
    }

    public function dispatch(Request $request, array $operation): array
    {
        $action = (string) ($operation['action'] ?? '');

        foreach ($this->handlers as $handler) {
            if (! $handler->supports($action)) {
                continue;
            }

            return $handler->handle($request, $operation);
        }

        return [];
    }
}
