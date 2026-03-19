<?php

namespace Tests\Unit;

use App\Events\ListItemsChanged;
use Illuminate\Broadcasting\PrivateChannel;
use Tests\TestCase;

class ListItemsChangedEventTest extends TestCase
{
    public function test_event_broadcast_payload_contains_fields_required_by_frontend(): void
    {
        $items = [
            [
                'id' => 11,
                'type' => 'product',
                'text' => 'Milk',
            ],
        ];

        $event = new ListItemsChanged(
            ownerId: 7,
            type: 'product',
            listLinkId: 13,
            actorUserId: 3,
            listVersion: 42,
            items: $items,
        );

        $channel = $event->broadcastOn();
        $payload = $event->broadcastWith();

        $this->assertSame('list.items.changed', $event->broadcastAs());
        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-lists.shared.13', $channel->name);

        $this->assertSame(7, $payload['owner_id']);
        $this->assertSame(13, $payload['list_link_id']);
        $this->assertSame('product', $payload['type']);
        $this->assertSame(3, $payload['actor_user_id']);
        $this->assertSame(42, $payload['list_version']);
        $this->assertSame($items, $payload['items']);
        $this->assertSame('snapshot', $payload['mode']);
        $this->assertNull($payload['operation']);
        $this->assertNull($payload['item']);
        $this->assertNull($payload['removed_item_id']);
        $this->assertNull($payload['active_order']);
        $this->assertNull($payload['completed_order']);
        $this->assertArrayHasKey('changed_at', $payload);
        $this->assertNotSame('', (string) ($payload['changed_at'] ?? ''));
    }

    public function test_event_uses_personal_channel_when_shared_link_is_missing(): void
    {
        $event = new ListItemsChanged(
            ownerId: 9,
            type: 'todo',
            listLinkId: null,
            actorUserId: null,
            listVersion: 5,
            items: [],
        );

        $channel = $event->broadcastOn();
        $payload = $event->broadcastWith();

        $this->assertInstanceOf(PrivateChannel::class, $channel);
        $this->assertSame('private-lists.personal.9', $channel->name);
        $this->assertSame('todo', $payload['type']);
        $this->assertNull($payload['list_link_id']);
        $this->assertNull($payload['actor_user_id']);
        $this->assertSame(5, $payload['list_version']);
    }

    public function test_event_can_broadcast_delta_payload_without_snapshot_items(): void
    {
        $event = new ListItemsChanged(
            ownerId: 11,
            type: 'product',
            listLinkId: 17,
            actorUserId: 5,
            listVersion: 88,
            items: [],
            mode: 'delta',
            operation: 'deleted',
            item: null,
            removedItemId: 901,
            activeOrder: [10, 11],
            completedOrder: [12],
        );

        $payload = $event->broadcastWith();

        $this->assertSame('delta', $payload['mode']);
        $this->assertSame('deleted', $payload['operation']);
        $this->assertSame([], $payload['items']);
        $this->assertSame(901, $payload['removed_item_id']);
        $this->assertSame([10, 11], $payload['active_order']);
        $this->assertSame([12], $payload['completed_order']);
    }
}
