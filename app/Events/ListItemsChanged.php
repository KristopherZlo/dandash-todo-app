<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ListItemsChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $listId,
        public int $ownerId,
        public string $type,
        public ?int $actorUserId = null,
        public int $listVersion = 0,
        public array $items = [],
        public string $mode = 'snapshot',
        public ?string $operation = null,
        public ?array $item = null,
        public ?int $removedItemId = null,
        public ?array $activeOrder = null,
        public ?array $completedOrder = null,
        public ?array $listSummary = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel('lists.'.$this->listId);
    }

    public function broadcastAs(): string
    {
        return 'list.items.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'list_id' => $this->listId,
            'owner_id' => $this->ownerId,
            'type' => $this->type,
            'actor_user_id' => $this->actorUserId,
            'list_version' => $this->listVersion,
            'items' => $this->items,
            'mode' => $this->mode,
            'operation' => $this->operation,
            'item' => $this->item,
            'removed_item_id' => $this->removedItemId,
            'active_order' => $this->activeOrder,
            'completed_order' => $this->completedOrder,
            'list_summary' => $this->listSummary,
            'changed_at' => now()->toISOString(),
        ];
    }
}
