<?php

namespace Tests\Feature\Api;

use App\Models\ListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListItemCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_store_product_item_and_fetch_it_via_index(): void
    {
        $user = User::factory()->create();

        $storeResponse = $this->actingAs($user)
            ->postJson('/api/items', [
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'text' => 'Milk',
                'quantity' => 2,
                'unit' => 'l',
            ])
            ->assertCreated()
            ->assertJsonPath('item.type', ListItem::TYPE_PRODUCT)
            ->assertJsonPath('item.text', 'Milk')
            ->assertJsonPath('item.is_completed', false);

        $storePayload = $storeResponse->json();
        $createdItemId = (int) ($storePayload['item']['id'] ?? 0);
        $this->assertGreaterThan(0, $createdItemId);
        $this->assertGreaterThan(0, (int) ($storePayload['list_version'] ?? 0));

        $indexResponse = $this->actingAs($user)
            ->getJson('/api/items?owner_id='.$user->id.'&type=product')
            ->assertOk()
            ->assertJsonCount(1, 'items')
            ->assertJsonPath('items.0.id', $createdItemId)
            ->assertJsonPath('items.0.text', 'Milk')
            ->assertJsonPath('items.0.quantity', 2);

        $this->assertNotSame('', (string) ($indexResponse->json('items.0.unit') ?? ''));

        $this->assertGreaterThan(0, (int) ($indexResponse->json('list_version') ?? 0));
    }

    public function test_user_can_update_todo_fields_via_api(): void
    {
        $user = User::factory()->create();

        $item = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_TODO,
            'text' => 'Buy gifts',
            'sort_order' => 1000,
            'priority' => ListItem::PRIORITY_LATER,
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);

        $dueAt = now()->addDay()->startOfHour()->toISOString();

        $response = $this->actingAs($user)
            ->patchJson('/api/items/'.$item->id, [
                'text' => 'Buy gifts and wrap',
                'priority' => ListItem::PRIORITY_TODAY,
                'due_at' => $dueAt,
            ])
            ->assertOk()
            ->assertJsonPath('item.id', $item->id)
            ->assertJsonPath('item.text', 'Buy gifts and wrap')
            ->assertJsonPath('item.priority', ListItem::PRIORITY_TODAY);

        $item->refresh();

        $this->assertSame('Buy gifts and wrap', $item->text);
        $this->assertSame(ListItem::PRIORITY_TODAY, $item->priority);
        $this->assertNotNull($item->due_at);
        $this->assertSame($response->json('item.due_at'), $item->due_at?->toISOString());
    }

    public function test_reorder_endpoint_reorders_active_and_completed_groups_and_updates_sort_order(): void
    {
        $user = User::factory()->create();

        $activeA = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'A',
            'sort_order' => 1000,
            'is_completed' => false,
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);
        $activeB = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'B',
            'sort_order' => 2000,
            'is_completed' => false,
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);
        $completedA = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'C',
            'sort_order' => 1000,
            'is_completed' => true,
            'completed_at' => now(),
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);
        $completedB = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'D',
            'sort_order' => 2000,
            'is_completed' => true,
            'completed_at' => now(),
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/items/reorder', [
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'order' => [
                    $completedB->id,
                    $activeB->id,
                    $completedA->id,
                    $activeA->id,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertGreaterThan(0, (int) ($response->json('list_version') ?? 0));

        $activeA->refresh();
        $activeB->refresh();
        $completedA->refresh();
        $completedB->refresh();

        $this->assertSame(1000, (int) $activeB->sort_order);
        $this->assertSame(2000, (int) $activeA->sort_order);
        $this->assertSame(1000, (int) $completedB->sort_order);
        $this->assertSame(2000, (int) $completedA->sort_order);

        $index = $this->actingAs($user)
            ->getJson('/api/items?owner_id='.$user->id.'&type=product')
            ->assertOk()
            ->json('items');

        $this->assertSame([$activeB->id, $activeA->id, $completedB->id, $completedA->id], array_map(
            static fn (array $item): int => (int) ($item['id'] ?? 0),
            $index
        ));
    }

    public function test_user_can_delete_item_via_api(): void
    {
        $user = User::factory()->create();

        $item = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'To delete',
            'sort_order' => 1000,
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);

        $response = $this->actingAs($user)
            ->deleteJson('/api/items/'.$item->id)
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertGreaterThan(0, (int) ($response->json('list_version') ?? 0));
        $this->assertDatabaseMissing('list_items', ['id' => $item->id]);
    }

    public function test_user_cannot_read_unlinked_other_user_list_items(): void
    {
        $owner = User::factory()->create();
        $intruder = User::factory()->create();

        ListItem::query()->create([
            'owner_id' => $owner->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'Secret item',
            'sort_order' => 1000,
            'created_by_id' => $owner->id,
            'updated_by_id' => $owner->id,
        ]);

        $this->actingAs($intruder)
            ->getJson('/api/items?owner_id='.$owner->id.'&type=product')
            ->assertForbidden();
    }
}
