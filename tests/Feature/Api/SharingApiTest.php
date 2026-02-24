<?php

namespace Tests\Feature\Api;

use App\Models\ListInvitation;
use App\Models\ListLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_state_returns_core_sharing_payload_for_user(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/sync/state')
            ->assertOk()
            ->assertJsonPath('default_owner_id', $user->id)
            ->assertJsonPath('pending_invitations_count', 0)
            ->assertJsonPath('list_options.0.owner_id', $user->id)
            ->assertJsonPath('list_options.0.is_personal', true)
            ->assertJsonPath('mood_cards.0.id', $user->id);
    }

    public function test_default_owner_can_be_changed_to_linked_user_and_persisted(): void
    {
        $user = User::factory()->create();
        $partner = User::factory()->create();
        [$userOneId, $userTwoId] = $user->id < $partner->id
            ? [$user->id, $partner->id]
            : [$partner->id, $user->id];

        $link = ListLink::query()->create([
            'user_one_id' => $userOneId,
            'user_two_id' => $userTwoId,
            'sync_owner_id' => $partner->id,
            'is_active' => true,
            'accepted_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->postJson('/api/sync/default-owner', [
                'owner_id' => $partner->id,
            ])
            ->assertOk()
            ->assertJsonPath('default_owner_id', $partner->id);

        $user->refresh();
        $link->refresh();

        $this->assertSame($partner->id, (int) $user->preferred_owner_id);
        $this->assertTrue((bool) $link->is_active);
        $this->assertGreaterThanOrEqual(1, count((array) $response->json('links')));
    }

    public function test_default_owner_change_is_forbidden_for_unlinked_user(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();

        $this->actingAs($user)
            ->postJson('/api/sync/default-owner', [
                'owner_id' => $stranger->id,
            ])
            ->assertForbidden();
    }

    public function test_user_search_by_tag_excludes_current_user_and_returns_matches(): void
    {
        $currentUser = User::factory()->create([
            'tag' => 'danek_owner',
        ]);
        $match = User::factory()->create([
            'tag' => 'danek_friend',
        ]);
        User::factory()->create([
            'tag' => 'another_person',
        ]);

        $this->actingAs($currentUser)
            ->getJson('/api/users/search?query=danek')
            ->assertOk()
            ->assertJsonMissing(['id' => $currentUser->id])
            ->assertJsonPath('users.0.id', $match->id)
            ->assertJsonPath('users.0.tag', 'danek_friend');
    }

    public function test_invitee_can_decline_pending_invitation(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $invitation = ListInvitation::query()->create([
            'inviter_id' => $inviter->id,
            'invitee_id' => $invitee->id,
            'status' => ListInvitation::STATUS_PENDING,
        ]);

        $this->actingAs($invitee)
            ->postJson("/api/invitations/{$invitation->id}/decline")
            ->assertOk()
            ->assertJsonPath('pending_invitations_count', 0);

        $invitation->refresh();
        $this->assertSame(ListInvitation::STATUS_DECLINED, $invitation->status);
        $this->assertNotNull($invitation->responded_at);
    }

    public function test_inviter_can_cancel_pending_invitation_via_decline_endpoint(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $invitation = ListInvitation::query()->create([
            'inviter_id' => $inviter->id,
            'invitee_id' => $invitee->id,
            'status' => ListInvitation::STATUS_PENDING,
        ]);

        $this->actingAs($inviter)
            ->postJson("/api/invitations/{$invitation->id}/decline")
            ->assertOk()
            ->assertJsonPath('outgoing_pending_invitations', []);

        $invitation->refresh();
        $this->assertSame(ListInvitation::STATUS_CANCELLED, $invitation->status);
        $this->assertNotNull($invitation->responded_at);
    }
}
