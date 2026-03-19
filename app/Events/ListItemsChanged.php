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
        public int $ownerId,
        public string $type,
        public ?int $listLinkId = null,
        public ?int $actorUserId = null,
        public int $listVersion = 0,
        public array $items = [],
        public string $mode = 'snapshot',
        public ?string $operation = null,
        public ?array $item = null,
        public ?int $removedItemId = null,
        public ?array $activeOrder = null,
        public ?array $completedOrder = null,
    ) {
    }

    public function broadcastOn(): PrivateChannel
    {
        if ($this->listLinkId) {
            return new PrivateChannel('lists.shared.'.$this->listLinkId);
        }

        return new PrivateChannel('lists.personal.'.$this->ownerId);
    }

    public function broadcastAs(): string
    {
        return 'list.items.changed';
    }

    public function broadcastWith(): array
    {
        return [
            'owner_id' => $this->ownerId,
            'list_link_id' => $this->listLinkId,
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
            'changed_at' => now()->toISOString(),
        ];
    }
}
