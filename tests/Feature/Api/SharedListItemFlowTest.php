<?php

namespace Tests\Feature\Api;

use App\Models\ListInvitation;
use App\Models\ListMember;
use App\Models\User;
use App\Models\UserList;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedListItemFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_invited_user_can_add_items_to_shared_list(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();

        $this->actingAs($inviter)
            ->postJson('/api/invitations', [
                'user_id' => $invitee->id,
            ])
            ->assertCreated();

        $invitation = ListInvitation::query()->firstOrFail();
        $list = UserList::query()->findOrFail((int) $invitation->list_id);

        $this->actingAs($invitee)
            ->postJson("/api/invitations/{$invitation->id}/accept")
            ->assertOk();

        $this->assertDatabaseHas('list_members', [
            'list_id' => $list->id,
            'user_id' => $invitee->id,
            'role' => ListMember::ROLE_EDITOR,
        ]);
        $this->assertDatabaseHas('users', [
            'id' => $invitee->id,
            'preferred_list_id' => $list->id,
        ]);

        $this->actingAs($invitee)
            ->postJson('/api/items', [
                'list_id' => $list->id,
                'type' => 'product',
                'text' => 'Milk',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('list_items', [
            'list_id' => $list->id,
            'owner_id' => $inviter->id,
            'type' => 'product',
            'text' => 'Milk',
            'created_by_id' => $invitee->id,
        ]);
    }

    public function test_setting_default_list_affects_current_user_only(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/api/invitations', [
                'user_id' => $editor->id,
            ])
            ->assertCreated();

        $invitation = ListInvitation::query()->firstOrFail();
        $list = UserList::query()->findOrFail((int) $invitation->list_id);

        $this->actingAs($editor)
            ->postJson("/api/invitations/{$invitation->id}/accept")
            ->assertOk();

        $response = $this->actingAs($editor)
            ->postJson('/api/sync/default-list', [
                'list_id' => $list->id,
            ]);

        $response
            ->assertOk()
            ->assertJsonPath('default_list_id', $list->id);

        $this->assertDatabaseHas('users', [
            'id' => $editor->id,
            'preferred_list_id' => $list->id,
        ]);

        $owner->refresh();
        $this->assertNotSame($list->id, (int) ($owner->preferred_list_id ?? 0));
    }

    public function test_removed_member_cannot_add_items_to_list(): void
    {
        $owner = User::factory()->create();
        $editor = User::factory()->create();

        $this->actingAs($owner)
            ->postJson('/api/invitations', [
                'user_id' => $editor->id,
            ])
            ->assertCreated();

        $invitation = ListInvitation::query()->firstOrFail();
        $list = UserList::query()->findOrFail((int) $invitation->list_id);

        $this->actingAs($editor)
            ->postJson("/api/invitations/{$invitation->id}/accept")
            ->assertOk();

        $this->actingAs($owner)
            ->deleteJson("/api/lists/{$list->id}/members/{$editor->id}")
            ->assertOk();

        $this->actingAs($editor)
            ->postJson('/api/items', [
                'list_id' => $list->id,
                'type' => 'product',
                'text' => 'Bread',
            ])
            ->assertForbidden();
    }
}
