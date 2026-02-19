<?php

namespace Tests\Feature\Api;

use App\Models\ListItem;
use App\Models\ListItemSuggestionState;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ListItemSuggestionTest extends TestCase
{
    use RefreshDatabase;

    public function test_suggestions_are_built_from_average_intervals_and_fuzzy_matches(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-02-17 12:00:00'));

        $user = User::factory()->create();
        $now = CarbonImmutable::now();
        $history = [
            ['text' => 'Молоко', 'created_at' => $now->subDays(9)],
            ['text' => 'moloko', 'created_at' => $now->subDays(6)],
            ['text' => 'молокоо', 'created_at' => $now->subDays(3)],
        ];

        foreach ($history as $entry) {
            ListItem::query()->forceCreate([
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'text' => $entry['text'],
                'created_by_id' => $user->id,
                'updated_by_id' => $user->id,
                'created_at' => $entry['created_at'],
                'updated_at' => $entry['created_at'],
            ]);
        }

        $response = $this->actingAs($user)
            ->getJson('/api/items/suggestions?owner_id='.$user->id.'&type=product');

        $response
            ->assertOk()
            ->assertJsonPath('suggestions.0.type', ListItem::TYPE_PRODUCT)
            ->assertJsonPath('suggestions.0.occurrences', 3)
            ->assertJsonPath('suggestions.0.is_due', true);

        $suggestion = $response->json('suggestions.0');

        $this->assertIsArray($suggestion);
        $this->assertContains($suggestion['suggested_text'], ['Молоко', 'moloko', 'молокоо']);
        $this->assertGreaterThanOrEqual(2, count($suggestion['variants']));
        $this->assertGreaterThan(0, $suggestion['average_interval_seconds']);
        $this->assertSame(0, $suggestion['seconds_until_expected']);
    }

    public function test_user_cannot_read_suggestions_for_unavailable_owner(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();

        $this->actingAs($otherUser)
            ->getJson('/api/items/suggestions?owner_id='.$owner->id.'&type=product')
            ->assertForbidden();
    }

    public function test_dismiss_suggestion_follows_interval_steps_and_then_removes_it(): void
    {
        Carbon::setTestNow(CarbonImmutable::parse('2026-02-17 12:00:00'));

        $user = User::factory()->create();
        $now = CarbonImmutable::now();

        foreach ([
            $now->subDays(9),
            $now->subDays(6),
            $now->subDays(3),
        ] as $createdAt) {
            ListItem::query()->forceCreate([
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'text' => 'milk',
                'created_by_id' => $user->id,
                'updated_by_id' => $user->id,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ]);
        }

        $firstSuggestion = $this->actingAs($user)
            ->getJson('/api/items/suggestions?owner_id='.$user->id.'&type=product')
            ->assertOk()
            ->json('suggestions.0');

        $this->assertIsArray($firstSuggestion);
        $this->assertArrayHasKey('suggestion_key', $firstSuggestion);
        $this->assertArrayHasKey('average_interval_seconds', $firstSuggestion);

        $suggestionKey = (string) $firstSuggestion['suggestion_key'];
        $averageIntervalSeconds = (int) $firstSuggestion['average_interval_seconds'];

        $this->actingAs($user)
            ->postJson('/api/items/suggestions/dismiss', [
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'suggestion_key' => $suggestionKey,
                'average_interval_seconds' => $averageIntervalSeconds,
            ])
            ->assertOk();

        $state = ListItemSuggestionState::query()->firstWhere([
            'owner_id' => $user->id,
            'type' => ListItem::TYPE_PRODUCT,
            'suggestion_key' => $suggestionKey,
        ]);

        $this->assertNotNull($state);
        $this->assertSame(1, $state->dismissed_count);
        $this->assertNotNull($state->hidden_until);
        $this->assertTrue($state->hidden_until->equalTo(CarbonImmutable::now()->addDay()));

        $this->actingAs($user)
            ->getJson('/api/items/suggestions?owner_id='.$user->id.'&type=product')
            ->assertOk()
            ->assertJsonCount(0, 'suggestions');

        Carbon::setTestNow(CarbonImmutable::now()->addDay()->addSecond());

        $this->actingAs($user)
            ->getJson('/api/items/suggestions?owner_id='.$user->id.'&type=product')
            ->assertOk()
            ->assertJsonCount(1, 'suggestions');

        $this->actingAs($user)
            ->postJson('/api/items/suggestions/dismiss', [
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'suggestion_key' => $suggestionKey,
                'average_interval_seconds' => $averageIntervalSeconds,
            ])
            ->assertOk();

        $state = $state->fresh();
        $this->assertNotNull($state);
        $this->assertSame(2, $state->dismissed_count);
        $this->assertNotNull($state->hidden_until);
        $this->assertTrue(
            $state->hidden_until->equalTo(
                CarbonImmutable::now()->addSeconds(max(86400, (int) floor($averageIntervalSeconds / 2)))
            )
        );

        Carbon::setTestNow($state->hidden_until->addSecond());

        $this->actingAs($user)
            ->postJson('/api/items/suggestions/dismiss', [
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'suggestion_key' => $suggestionKey,
                'average_interval_seconds' => $averageIntervalSeconds,
            ])
            ->assertOk();

        $state = $state->fresh();
        $this->assertNotNull($state);
        $this->assertSame(3, $state->dismissed_count);
        $this->assertNotNull($state->hidden_until);
        $this->assertTrue($state->hidden_until->equalTo(CarbonImmutable::now()->addSeconds($averageIntervalSeconds)));

        Carbon::setTestNow($state->hidden_until->addSecond());

        $this->actingAs($user)
            ->postJson('/api/items/suggestions/dismiss', [
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'suggestion_key' => $suggestionKey,
                'average_interval_seconds' => $averageIntervalSeconds,
            ])
            ->assertOk();

        $state = $state->fresh();
        $this->assertNotNull($state);
        $this->assertSame(0, $state->dismissed_count);
        $this->assertNull($state->hidden_until);
        $this->assertNotNull($state->retired_at);

        Carbon::setTestNow(CarbonImmutable::now()->addDays(30));

        $this->actingAs($user)
            ->getJson('/api/items/suggestions?owner_id='.$user->id.'&type=product')
            ->assertOk()
            ->assertJsonCount(0, 'suggestions');
    }

    public function test_suggestions_are_not_reset_after_items_are_fully_deleted(): void
    {
        $user = User::factory()->create();
        $itemIds = [];

        foreach ([
            CarbonImmutable::parse('2026-02-01 10:00:00'),
            CarbonImmutable::parse('2026-02-04 10:00:00'),
            CarbonImmutable::parse('2026-02-07 10:00:00'),
        ] as $createdAt) {
            Carbon::setTestNow($createdAt);

            $itemIds[] = (int) $this->actingAs($user)
                ->postJson('/api/items', [
                    'owner_id' => $user->id,
                    'type' => ListItem::TYPE_PRODUCT,
                    'text' => 'milk',
                ])
                ->assertCreated()
                ->json('item.id');
        }

        foreach ($itemIds as $itemId) {
            $this->actingAs($user)
                ->deleteJson('/api/items/'.$itemId)
                ->assertOk();
        }

        $this->assertSame(0, ListItem::query()->where('owner_id', $user->id)->count());

        Carbon::setTestNow(CarbonImmutable::parse('2026-02-17 12:00:00'));

        $response = $this->actingAs($user)
            ->getJson('/api/items/suggestions?owner_id='.$user->id.'&type=product')
            ->assertOk();

        $this->assertGreaterThan(0, count($response->json('suggestions')));
        $this->assertSame('milk', $response->json('suggestions.0.suggested_text'));
        $this->assertSame(3, $response->json('suggestions.0.occurrences'));
    }

    public function test_product_stats_response_contains_user_summary(): void
    {
        $user = User::factory()->create();

        Carbon::setTestNow(CarbonImmutable::parse('2026-02-10 10:00:00'));
        $firstItemId = (int) $this->actingAs($user)
            ->postJson('/api/items', [
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'text' => 'Milk',
            ])
            ->assertCreated()
            ->json('item.id');

        Carbon::setTestNow(CarbonImmutable::parse('2026-02-12 10:00:00'));
        $this->actingAs($user)
            ->postJson('/api/items', [
                'owner_id' => $user->id,
                'type' => ListItem::TYPE_PRODUCT,
                'text' => 'Bread',
            ])
            ->assertCreated();

        Carbon::setTestNow(CarbonImmutable::parse('2026-02-13 10:00:00'));
        $this->actingAs($user)
            ->patchJson('/api/items/'.$firstItemId, [
                'is_completed' => true,
            ])
            ->assertOk();

        $response = $this->actingAs($user)
            ->getJson('/api/items/suggestions/stats?owner_id='.$user->id.'&limit=50')
            ->assertOk();

        $response
            ->assertJsonStructure([
                'stats',
                'summary' => [
                    'total_added',
                    'total_completed',
                    'unique_products',
                    'due_suggestions',
                    'upcoming_suggestions',
                    'last_activity_at',
                ],
            ])
            ->assertJsonPath('summary.total_added', 2)
            ->assertJsonPath('summary.total_completed', 1);
    }

    public function test_product_stats_summary_does_not_include_mock_suggestions(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/items/suggestions/stats?owner_id='.$user->id.'&limit=50')
            ->assertOk()
            ->assertJsonPath('summary.total_added', 0)
            ->assertJsonPath('summary.total_completed', 0)
            ->assertJsonPath('summary.unique_products', 0)
            ->assertJsonPath('summary.due_suggestions', 0)
            ->assertJsonPath('summary.upcoming_suggestions', 0);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }
}
