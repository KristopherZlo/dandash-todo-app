<?php

namespace Tests\Feature\Api;

use App\Models\ListItem;
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
}
