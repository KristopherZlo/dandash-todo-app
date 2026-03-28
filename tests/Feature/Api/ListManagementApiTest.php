<?php

namespace Tests\Feature\Api;

use App\Models\ListItem;
use App\Models\User;
use App\Models\UserList;
use App\Services\Lists\ListCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ListManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_rename_and_delete_owned_list(): void
    {
        $user = User::factory()->create();
        $personalList = $this->personalList($user);

        $createResponse = $this->actingAs($user)
            ->postJson('/api/lists', [
                'name' => 'Trip',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'ok');

        $listId = (int) $createResponse->json('list_id');
        $this->assertGreaterThan(0, $listId);
        $this->assertDatabaseHas('lists', [
            'id' => $listId,
            'owner_user_id' => $user->id,
            'name' => 'Trip',
            'is_template' => false,
        ]);

        $this->actingAs($user)
            ->patchJson("/api/lists/{$listId}", [
                'name' => 'Trip 2026',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseHas('lists', [
            'id' => $listId,
            'name' => 'Trip 2026',
        ]);

        $deleteResponse = $this->actingAs($user)
            ->deleteJson("/api/lists/{$listId}")
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $this->assertDatabaseMissing('lists', ['id' => $listId]);
        $this->assertTrue(
            collect($deleteResponse->json('state.lists'))
                ->contains(fn (array $list): bool => (int) ($list['id'] ?? 0) === (int) $personalList->id)
        );
    }

    public function test_user_can_save_template_and_create_new_list_from_it(): void
    {
        $user = User::factory()->create();
        $sourceList = $this->personalList($user);

        $this->actingAs($user)
            ->postJson('/api/items', [
                'list_id' => $sourceList->id,
                'type' => ListItem::TYPE_PRODUCT,
                'text' => 'Passport',
                'quantity' => 1,
                'unit' => 'pc',
            ])
            ->assertCreated();

        $this->actingAs($user)
            ->postJson('/api/items', [
                'list_id' => $sourceList->id,
                'type' => ListItem::TYPE_TODO,
                'text' => 'Charge camera',
                'priority' => ListItem::PRIORITY_TODAY,
                'is_completed' => true,
            ])
            ->assertCreated();

        $templateResponse = $this->actingAs($user)
            ->postJson('/api/templates', [
                'source_list_id' => $sourceList->id,
                'name' => 'Travel pack',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'ok');

        $templateId = (int) $templateResponse->json('template_id');
        $this->assertGreaterThan(0, $templateId);
        $this->assertDatabaseHas('lists', [
            'id' => $templateId,
            'owner_user_id' => $user->id,
            'name' => 'Travel pack',
            'is_template' => true,
        ]);

        $createFromTemplateResponse = $this->actingAs($user)
            ->postJson('/api/lists/from-template', [
                'template_id' => $templateId,
                'name' => 'April trip',
            ])
            ->assertCreated()
            ->assertJsonPath('status', 'ok');

        $newListId = (int) $createFromTemplateResponse->json('list_id');
        $this->assertGreaterThan(0, $newListId);
        $this->assertDatabaseHas('lists', [
            'id' => $newListId,
            'owner_user_id' => $user->id,
            'name' => 'April trip',
            'is_template' => false,
        ]);

        $copiedItems = ListItem::query()
            ->where('list_id', $newListId)
            ->orderBy('type')
            ->orderBy('id')
            ->get();

        $this->assertCount(2, $copiedItems);
        $this->assertNotNull($copiedItems->firstWhere('text', 'Passport'));

        $copiedTodo = $copiedItems->firstWhere('text', 'Charge camera');
        $this->assertNotNull($copiedTodo);
        $this->assertFalse((bool) $copiedTodo->is_completed);
        $this->assertNull($copiedTodo->completed_at);
    }

    private function personalList(User $user): UserList
    {
        return app(ListCatalogService::class)->ensurePersonalListExists($user->fresh() ?? $user);
    }
}
