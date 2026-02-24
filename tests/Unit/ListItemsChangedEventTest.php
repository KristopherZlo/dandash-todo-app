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
}
