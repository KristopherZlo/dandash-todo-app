<?php

namespace Tests\Feature\Api;

use App\Models\ListInvitation;
use App\Models\ListLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharedListItemFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_invited_user_can_add_items_to_current_shared_owner_list(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();

        $this->actingAs($inviter)
            ->postJson('/api/invitations', [
                'user_id' => $invitee->id,
            ])
            ->assertCreated();

        $invitation = ListInvitation::query()->firstOrFail();

        $this->actingAs($invitee)
            ->postJson("/api/invitations/{$invitation->id}/accept")
            ->assertOk();

        $link = ListLink::query()->firstOrFail();
        $this->assertTrue($link->is_active);
        $this->assertSame($inviter->id, $link->sync_owner_id);
        $this->assertDatabaseHas('users', [
            'id' => $invitee->id,
            'preferred_owner_id' => $inviter->id,
        ]);

        $this->actingAs($invitee)
            ->postJson('/api/items', [
                'owner_id' => $inviter->id,
                'type' => 'product',
                'text' => 'Milk',
            ])
            ->assertCreated();

        $this->assertDatabaseHas('list_items', [
            'owner_id' => $inviter->id,
            'type' => 'product',
            'text' => 'Milk',
            'created_by_id' => $invitee->id,
        ]);
    }

    public function test_set_mine_sets_other_user_list_as_default_for_clicking_user_only(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->actingAs($firstUser)
            ->postJson('/api/invitations', [
                'user_id' => $secondUser->id,
            ])
            ->assertCreated();

        $invitation = ListInvitation::query()->firstOrFail();

        $this->actingAs($secondUser)
            ->postJson("/api/invitations/{$invitation->id}/accept")
            ->assertOk();

        $link = ListLink::query()->firstOrFail();

        $response = $this->actingAs($secondUser)
            ->postJson("/api/links/{$link->id}/set-mine");

        $response
            ->assertOk()
            ->assertJsonPath('default_owner_id', $firstUser->id);

        $link->refresh();
        $this->assertSame($firstUser->id, $link->sync_owner_id);

        $this->assertDatabaseHas('users', [
            'id' => $secondUser->id,
            'preferred_owner_id' => $firstUser->id,
        ]);

        $this->assertDatabaseHas('users', [
            'id' => $firstUser->id,
            'preferred_owner_id' => null,
        ]);
    }

    public function test_user_cannot_add_items_to_unlinked_owner_list(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();

        $this->actingAs($firstUser)
            ->postJson('/api/invitations', [
                'user_id' => $secondUser->id,
            ])
            ->assertCreated();

        $invitation = ListInvitation::query()->firstOrFail();

        $this->actingAs($secondUser)
            ->postJson("/api/invitations/{$invitation->id}/accept")
            ->assertOk();

        $link = ListLink::query()->firstOrFail();

        $this->actingAs($firstUser)
            ->deleteJson("/api/links/{$link->id}")
            ->assertOk();

        $this->actingAs($secondUser)
            ->postJson('/api/items', [
                'owner_id' => $firstUser->id,
                'type' => 'product',
                'text' => 'Bread',
            ])
            ->assertForbidden();
    }
}
