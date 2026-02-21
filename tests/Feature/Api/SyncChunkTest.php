<?php

namespace Tests\Feature\Api;

use App\Models\ListItem;
use App\Models\ListLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncChunkTest extends TestCase
{
    use RefreshDatabase;

    public function test_chunk_sync_processes_operations_in_order(): void
    {
        $user = User::factory()->create();

        $item = ListItem::query()->create([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_TODO,
            'text' => 'Pay bills',
            'sort_order' => 1000,
            'is_completed' => false,
            'created_by_id' => $user->id,
            'updated_by_id' => $user->id,
        ]);

        $response = $this->actingAs($user)->postJson('/api/sync/chunk', [
            'operations' => [
                [
                    'op_id' => 'op-1',
                    'action' => 'update',
                    'owner_id' => $user->id,
                    'type' => ListItem::TYPE_TODO,
                    'item_id' => $item->id,
                    'payload' => [
                        'is_completed' => true,
                    ],
                ],
                [
                    'op_id' => 'op-2',
                    'action' => 'delete',
                    'owner_id' => $user->id,
                    'type' => ListItem::TYPE_TODO,
                    'item_id' => $item->id,
                    'payload' => [],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.op_id', 'op-1')
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.1.op_id', 'op-2')
            ->assertJsonPath('results.1.status', 'ok');

        $this->assertDatabaseMissing('list_items', [
            'id' => $item->id,
        ]);
    }

    public function test_chunk_sync_stops_after_first_operation_error(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/sync/chunk', [
            'operations' => [
                [
                    'op_id' => 'op-1',
                    'action' => 'delete',
                    'owner_id' => $user->id,
                    'type' => ListItem::TYPE_PRODUCT,
                    'item_id' => 999999,
                    'payload' => [],
                ],
                [
                    'op_id' => 'op-2',
                    'action' => 'create',
                    'owner_id' => $user->id,
                    'type' => ListItem::TYPE_PRODUCT,
                    'item_id' => -100,
                    'payload' => [
                        'text' => 'Milk',
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonCount(1, 'results')
            ->assertJsonPath('results.0.op_id', 'op-1')
            ->assertJsonPath('results.0.status', 'error')
            ->assertJsonPath('results.0.http_status', 404);

        $this->assertDatabaseMissing('list_items', [
            'owner_id' => $user->id,
            'text' => 'Milk',
        ]);
    }

    public function test_chunk_sync_is_idempotent_by_operation_id_for_create_action(): void
    {
        $user = User::factory()->create();

        $payload = [
            'operations' => [
                [
                    'op_id' => 'op-create-idempotent-1',
                    'action' => 'create',
                    'owner_id' => $user->id,
                    'type' => ListItem::TYPE_PRODUCT,
                    'item_id' => -10,
                    'payload' => [
                        'text' => 'Eggs',
                    ],
                ],
            ],
        ];

        $firstResponse = $this->actingAs($user)
            ->postJson('/api/sync/chunk', $payload)
            ->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->json();

        $firstCreatedId = (int) ($firstResponse['results'][0]['data']['item']['id'] ?? 0);
        $this->assertGreaterThan(0, $firstCreatedId);

        $secondResponse = $this->actingAs($user)
            ->postJson('/api/sync/chunk', $payload)
            ->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->json();

        $secondCreatedId = (int) ($secondResponse['results'][0]['data']['item']['id'] ?? 0);
        $this->assertSame($firstCreatedId, $secondCreatedId);

        $this->assertSame(1, ListItem::query()
            ->where('owner_id', $user->id)
            ->where('type', ListItem::TYPE_PRODUCT)
            ->where('text', 'Eggs')
            ->count());
    }

    public function test_chunk_sync_updates_user_gamification_state(): void
    {
        $user = User::factory()->create([
            'xp_progress' => 0.1,
            'productivity_score' => 5,
            'productivity_reward_history' => [5],
            'xp_color_seed' => 11,
        ]);

        $response = $this->actingAs($user)->postJson('/api/sync/chunk', [
            'operations' => [
                [
                    'op_id' => 'op-gamification-1',
                    'action' => 'sync_gamification',
                    'payload' => [
                        'xp_progress' => 0.42,
                        'productivity_score' => 17,
                        'productivity_reward_history' => [8, 4, 5],
                        'xp_color_seed' => 222,
                        'updated_at_ms' => now()->addSecond()->valueOf(),
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.0.data.gamification.productivity_score', 17)
            ->assertJsonPath('results.0.data.gamification.xp_color_seed', 222);

        $user->refresh();

        $this->assertEquals(0.42, (float) $user->xp_progress);
        $this->assertSame(17, (int) $user->productivity_score);
        $this->assertSame([8, 4, 5], array_values($user->productivity_reward_history ?? []));
        $this->assertSame(222, (int) $user->xp_color_seed);
        $this->assertNotNull($user->gamification_updated_at);
    }

    public function test_chunk_sync_updates_user_mood_state(): void
    {
        $user = User::factory()->create([
            'mood_color' => 'yellow',
            'mood_fire_level' => 40,
            'mood_battery_level' => 60,
        ]);

        $response = $this->actingAs($user)->postJson('/api/sync/chunk', [
            'operations' => [
                [
                    'op_id' => 'op-mood-1',
                    'action' => 'update_mood',
                    'payload' => [
                        'color' => 'red',
                        'fire_level' => 83,
                        'battery_level' => 27,
                        'updated_at_ms' => now()->addSecond()->valueOf(),
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.0.data.mood.color', 'red')
            ->assertJsonPath('results.0.data.mood.fire_level', 83)
            ->assertJsonPath('results.0.data.mood.battery_level', 27)
            ->assertJsonPath('results.0.data.mood_cards.0.id', $user->id)
            ->assertJsonPath('results.0.data.applied', true);

        $user->refresh();

        $this->assertSame('red', $user->mood_color);
        $this->assertSame(83, (int) $user->mood_fire_level);
        $this->assertSame(27, (int) $user->mood_battery_level);
        $this->assertNotNull($user->mood_updated_at);
    }

    public function test_chunk_sync_rejects_stale_mood_update_state(): void
    {
        $user = User::factory()->create([
            'mood_color' => 'green',
            'mood_fire_level' => 75,
            'mood_battery_level' => 64,
            'mood_updated_at' => now(),
        ]);

        $response = $this->actingAs($user)->postJson('/api/sync/chunk', [
            'operations' => [
                [
                    'op_id' => 'op-mood-stale-1',
                    'action' => 'update_mood',
                    'payload' => [
                        'color' => 'red',
                        'fire_level' => 10,
                        'battery_level' => 11,
                        'updated_at_ms' => now()->subSecond()->valueOf(),
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.0.data.applied', false)
            ->assertJsonPath('results.0.data.mood.color', 'green')
            ->assertJsonPath('results.0.data.mood.fire_level', 75)
            ->assertJsonPath('results.0.data.mood.battery_level', 64);

        $user->refresh();

        $this->assertSame('green', $user->mood_color);
        $this->assertSame(75, (int) $user->mood_fire_level);
        $this->assertSame(64, (int) $user->mood_battery_level);
    }

    public function test_sync_state_contains_gamification_payload(): void
    {
        $user = User::factory()->create([
            'xp_progress' => 0.33,
            'productivity_score' => 14,
            'productivity_reward_history' => [8, 6],
            'xp_color_seed' => 77,
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/sync/state')
            ->assertOk();

        $response
            ->assertJsonPath('gamification.productivity_score', 14)
            ->assertJsonPath('gamification.xp_color_seed', 77)
            ->assertJsonPath('gamification.productivity_reward_history.0', 8)
            ->assertJsonPath('gamification.productivity_reward_history.1', 6);
    }

    public function test_sync_state_contains_mood_cards_for_self_and_connected_users(): void
    {
        $owner = User::factory()->create([
            'name' => 'Owner',
            'mood_color' => 'green',
            'mood_fire_level' => 72,
            'mood_battery_level' => 63,
        ]);
        $friend = User::factory()->create([
            'name' => 'Friend',
            'mood_color' => 'red',
            'mood_fire_level' => 24,
            'mood_battery_level' => 31,
        ]);

        [$userOneId, $userTwoId] = $owner->id < $friend->id
            ? [$owner->id, $friend->id]
            : [$friend->id, $owner->id];

        ListLink::query()->create([
            'user_one_id' => $userOneId,
            'user_two_id' => $userTwoId,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($owner)
            ->getJson('/api/sync/state')
            ->assertOk();

        $response
            ->assertJsonPath('mood_cards.0.id', $owner->id)
            ->assertJsonPath('mood_cards.0.is_self', true)
            ->assertJsonPath('mood_cards.0.mood.color', 'green')
            ->assertJsonPath('mood_cards.1.id', $friend->id)
            ->assertJsonPath('mood_cards.1.is_self', false)
            ->assertJsonPath('mood_cards.1.mood.color', 'red');
    }

    public function test_sync_state_resets_stale_mood_after_24_hours(): void
    {
        $user = User::factory()->create([
            'mood_color' => 'yellow',
            'mood_fire_level' => 88,
            'mood_fire_emoji' => '🥰',
            'mood_battery_level' => 12,
            'mood_battery_emoji' => '😡',
            'mood_updated_at' => now()->subDay()->subMinute(),
        ]);

        $response = $this->actingAs($user)
            ->getJson('/api/sync/state')
            ->assertOk();

        $response
            ->assertJsonPath('mood_cards.0.id', $user->id)
            ->assertJsonPath('mood_cards.0.mood.color', 'yellow')
            ->assertJsonPath('mood_cards.0.mood.fire_level', 50)
            ->assertJsonPath('mood_cards.0.mood.battery_level', 50)
            ->assertJsonPath('mood_cards.0.mood.fire_emoji', '❔')
            ->assertJsonPath('mood_cards.0.mood.battery_emoji', '❔');
    }
}
