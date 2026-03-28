<?php

namespace Tests\Feature\Api;

use App\Models\ListItem;
use App\Models\ListMember;
use App\Models\User;
use App\Models\UserList;
use App\Services\Lists\ListCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SyncChunkTest extends TestCase
{
    use RefreshDatabase;

    public function test_chunk_sync_processes_operations_in_order(): void
    {
        $user = User::factory()->create();
        $list = $this->personalList($user);

        $item = ListItem::query()->create([
            'owner_id' => $user->id,
            'list_id' => $list->id,
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
                    'list_id' => $list->id,
                    'type' => ListItem::TYPE_TODO,
                    'item_id' => $item->id,
                    'payload' => [
                        'is_completed' => true,
                    ],
                ],
                [
                    'op_id' => 'op-2',
                    'action' => 'delete',
                    'list_id' => $list->id,
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
        $list = $this->personalList($user);

        $response = $this->actingAs($user)->postJson('/api/sync/chunk', [
            'operations' => [
                [
                    'op_id' => 'op-1',
                    'action' => 'delete',
                    'list_id' => $list->id,
                    'type' => ListItem::TYPE_PRODUCT,
                    'item_id' => 999999,
                    'payload' => [],
                ],
                [
                    'op_id' => 'op-2',
                    'action' => 'create',
                    'list_id' => $list->id,
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
            'list_id' => $list->id,
            'text' => 'Milk',
        ]);
    }

    public function test_chunk_sync_is_idempotent_by_operation_id_for_create_action(): void
    {
        $user = User::factory()->create();
        $list = $this->personalList($user);

        $payload = [
            'operations' => [
                [
                    'op_id' => 'op-create-idempotent-1',
                    'action' => 'create',
                    'list_id' => $list->id,
                    'type' => ListItem::TYPE_PRODUCT,
                    'item_id' => -10,
                    'payload' => [
                        'text' => 'Eggs',
                        'client_request_id' => 'req-eggs-1',
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
        $this->assertSame('req-eggs-1', $firstResponse['results'][0]['data']['item']['client_request_id'] ?? null);

        $secondResponse = $this->actingAs($user)
            ->postJson('/api/sync/chunk', $payload)
            ->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->json();

        $secondCreatedId = (int) ($secondResponse['results'][0]['data']['item']['id'] ?? 0);
        $this->assertSame($firstCreatedId, $secondCreatedId);
        $this->assertSame('req-eggs-1', $secondResponse['results'][0]['data']['item']['client_request_id'] ?? null);

        $this->assertSame(1, ListItem::query()
            ->where('list_id', $list->id)
            ->where('type', ListItem::TYPE_PRODUCT)
            ->where('text', 'Eggs')
            ->count());
    }

    public function test_chunk_sync_create_with_completed_flag_returns_completed_item_state(): void
    {
        $user = User::factory()->create();
        $list = $this->personalList($user);

        $response = $this->actingAs($user)->postJson('/api/sync/chunk', [
            'operations' => [
                [
                    'op_id' => 'op-create-completed-1',
                    'action' => 'create',
                    'list_id' => $list->id,
                    'type' => ListItem::TYPE_PRODUCT,
                    'item_id' => -200,
                    'payload' => [
                        'text' => 'Mayonnaise',
                        'client_request_id' => 'req-create-completed-1',
                        'is_completed' => true,
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.op_id', 'op-create-completed-1')
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.0.data.item.text', 'Mayonnaise')
            ->assertJsonPath('results.0.data.item.client_request_id', 'req-create-completed-1')
            ->assertJsonPath('results.0.data.item.is_completed', true);

        $resultData = $response->json('results.0.data');
        $createdItemId = (int) ($resultData['item']['id'] ?? 0);

        $this->assertGreaterThan(0, $createdItemId);
        $this->assertNotNull($resultData['item']['completed_at'] ?? null);
        $this->assertSame(1, (int) ($resultData['list_version'] ?? 0));

        $this->assertDatabaseHas('list_items', [
            'id' => $createdItemId,
            'list_id' => $list->id,
            'type' => ListItem::TYPE_PRODUCT,
            'text' => 'Mayonnaise',
            'is_completed' => true,
        ]);
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
    }

    public function test_chunk_sync_applies_shared_gamification_delta_to_partner(): void
    {
        $owner = User::factory()->create([
            'xp_progress' => 0.2,
            'productivity_score' => 10,
            'productivity_reward_history' => [10],
            'xp_color_seed' => 17,
        ]);
        $partner = User::factory()->create([
            'xp_progress' => 0.99,
            'productivity_score' => 0,
            'productivity_reward_history' => [],
            'xp_color_seed' => 23,
        ]);
        $sharedList = $this->sharedList($owner, [$partner]);

        $response = $this->actingAs($owner)->postJson('/api/sync/chunk', [
            'operations' => [
                [
                    'op_id' => 'op-shared-gamification-1',
                    'action' => 'apply_shared_gamification_delta',
                    'payload' => [
                        'list_id' => $sharedList->id,
                        'delta' => 0.02,
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.0.data.applied', true)
            ->assertJsonPath('results.0.data.partner_user_id', $partner->id);
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
                        'fire_emoji' => '😭',
                        'battery_level' => 27,
                        'battery_emoji' => '😭',
                        'updated_at_ms' => now()->addSecond()->valueOf(),
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.0.data.mood.fire_emoji', '😭')
            ->assertJsonPath('results.0.data.mood.battery_emoji', '😭')
            ->assertJsonPath('results.0.data.self_mood_preferences.fire_recent_emojis.0', '😭');
    }

    public function test_chunk_sync_can_update_suggestion_settings(): void
    {
        $user = User::factory()->create();
        $list = $this->personalList($user);

        $response = $this->actingAs($user)->postJson('/api/sync/chunk', [
            'operations' => [
                [
                    'op_id' => 'op-suggestion-settings-1',
                    'action' => 'update_suggestion_settings',
                    'list_id' => $list->id,
                    'type' => ListItem::TYPE_PRODUCT,
                    'payload' => [
                        'suggestion_key' => 'coffee',
                        'custom_interval_seconds' => 172800,
                        'ignored' => true,
                    ],
                ],
            ],
        ]);

        $response->assertOk()
            ->assertJsonPath('results.0.status', 'ok')
            ->assertJsonPath('results.0.data.state.suggestion_key', 'coffee')
            ->assertJsonPath('results.0.data.state.custom_interval_seconds', 172800);

        $this->assertDatabaseHas('list_item_suggestion_states', [
            'list_id' => $list->id,
            'type' => ListItem::TYPE_PRODUCT,
            'suggestion_key' => 'coffee',
            'custom_interval_seconds' => 172800,
        ]);
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
            ->assertJsonPath('results.0.data.mood.color', 'green');
    }

    public function test_sync_state_contains_gamification_payload(): void
    {
        $user = User::factory()->create([
            'xp_progress' => 0.33,
            'productivity_score' => 14,
            'productivity_reward_history' => [8, 6],
            'xp_color_seed' => 77,
        ]);

        $this->actingAs($user)
            ->getJson('/api/sync/state')
            ->assertOk()
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
        $this->sharedList($owner, [$friend]);

        $this->actingAs($owner)
            ->getJson('/api/sync/state')
            ->assertOk()
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

        $this->actingAs($user)
            ->getJson('/api/sync/state')
            ->assertOk()
            ->assertJsonPath('mood_cards.0.id', $user->id)
            ->assertJsonPath('mood_cards.0.mood.fire_level', 50)
            ->assertJsonPath('mood_cards.0.mood.battery_level', 50)
            ->assertJsonPath('mood_cards.0.mood.fire_emoji', '❔')
            ->assertJsonPath('mood_cards.0.mood.battery_emoji', '❔');
    }

    private function personalList(User $user): UserList
    {
        return app(ListCatalogService::class)->ensurePersonalListExists($user->fresh() ?? $user);
    }

    /**
     * @param  array<int, User>  $editors
     */
    private function sharedList(User $owner, array $editors): UserList
    {
        $list = UserList::query()->create([
            'owner_user_id' => $owner->id,
            'name' => 'Shared',
            'is_template' => false,
        ]);

        ListMember::query()->create([
            'list_id' => $list->id,
            'user_id' => $owner->id,
            'role' => ListMember::ROLE_OWNER,
        ]);

        foreach ($editors as $editor) {
            ListMember::query()->create([
                'list_id' => $list->id,
                'user_id' => $editor->id,
                'role' => ListMember::ROLE_EDITOR,
            ]);
        }

        return $list;
    }
}
