<?php

namespace Tests\Feature\Api;

use App\Models\ListItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ListItemCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_repeated_completion_update_keeps_original_completed_at_and_updated_at(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow('2026-02-18 10:00:00');

        $item = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'Milk',
            'sort_order' => 1000,
            'is_completed' => false,
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->patchJson('/api/items/'.$item->id, [
                'is_completed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('item.is_completed', true);

        $item->refresh();
        $firstCompletedAt = $item->completed_at?->toISOString();
        $firstUpdatedAt = $item->updated_at?->toISOString();

        $this->assertNotNull($firstCompletedAt);
        $this->assertNotNull($firstUpdatedAt);

        Carbon::setTestNow('2026-02-18 12:30:00');

        $this->actingAs($user)
            ->patchJson('/api/items/'.$item->id, [
                'is_completed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('item.is_completed', true);

        $item->refresh();

        $this->assertSame($firstCompletedAt, $item->completed_at?->toISOString());
        $this->assertSame($firstUpdatedAt, $item->updated_at?->toISOString());

        Carbon::setTestNow();
    }

    public function test_uncompleting_item_clears_completed_at(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow('2026-02-18 09:00:00');

        $item = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_TODO,
            'text' => 'Pay bills',
            'sort_order' => 1000,
            'is_completed' => true,
            'completed_at' => now(),
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->patchJson('/api/items/'.$item->id, [
                'is_completed' => false,
            ])
            ->assertOk()
            ->assertJsonPath('item.is_completed', false)
            ->assertJsonPath('item.completed_at', null);

        $item->refresh();

        $this->assertFalse((bool) $item->is_completed);
        $this->assertNull($item->completed_at);

        Carbon::setTestNow();
    }

    public function test_items_index_places_completed_items_after_incomplete_items_after_toggle(): void
    {
        $user = User::factory()->create();

        $activeItem = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'Bread',
            'sort_order' => 1000,
            'is_completed' => false,
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);

        $itemToComplete = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'Milk',
            'sort_order' => 2000,
            'is_completed' => false,
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);

        $this->actingAs($user)
            ->patchJson('/api/items/'.$itemToComplete->id, [
                'is_completed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('item.is_completed', true);

        $response = $this->actingAs($user)
            ->getJson('/api/items?owner_id='.$user->id.'&type=product')
            ->assertOk();

        $items = $response->json('items');

        $this->assertIsArray($items);
        $this->assertCount(2, $items);
        $this->assertSame($activeItem->id, (int) ($items[0]['id'] ?? 0));
        $this->assertFalse((bool) ($items[0]['is_completed'] ?? true));
        $this->assertSame($itemToComplete->id, (int) ($items[1]['id'] ?? 0));
        $this->assertTrue((bool) ($items[1]['is_completed'] ?? false));
    }
}
