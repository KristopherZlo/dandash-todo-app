<?php

namespace Tests\Feature\Api;

use App\Models\ListInvitation;
use App\Models\ListMember;
use App\Models\User;
use App\Models\UserList;
use App\Services\Lists\ListCatalogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SharingApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_state_returns_core_sharing_payload_for_user(): void
    {
        $user = User::factory()->create();
        $personalList = $this->personalList($user);

        $this->actingAs($user)
            ->getJson('/api/sync/state')
            ->assertOk()
            ->assertJsonPath('default_list_id', $personalList->id)
            ->assertJsonPath('pending_invitations_count', 0)
            ->assertJsonPath('lists.0.id', $personalList->id)
            ->assertJsonPath('lists.0.member_count', 1)
            ->assertJsonPath('mood_cards.0.id', $user->id);
    }

    public function test_default_list_can_be_changed_to_accessible_shared_list_and_persisted(): void
    {
        $user = User::factory()->create();
        $partner = User::factory()->create();
        $this->personalList($user);
        $sharedList = $this->sharedList($user, [$partner]);

        $this->actingAs($user)
            ->postJson('/api/sync/default-list', [
                'list_id' => $sharedList->id,
            ])
            ->assertOk()
            ->assertJsonPath('default_list_id', $sharedList->id);

        $user->refresh();

        $this->assertSame($sharedList->id, (int) $user->preferred_list_id);
    }

    public function test_default_list_change_is_forbidden_for_inaccessible_list(): void
    {
        $user = User::factory()->create();
        $stranger = User::factory()->create();
        $strangerList = $this->personalList($stranger);

        $this->actingAs($user)
            ->postJson('/api/sync/default-list', [
                'list_id' => $strangerList->id,
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
        $personalList = $this->personalList($currentUser);

        $this->actingAs($currentUser)
            ->getJson('/api/users/search?query=danek&list_id='.$personalList->id)
            ->assertOk()
            ->assertJsonMissing(['id' => $currentUser->id])
            ->assertJsonPath('users.0.id', $match->id)
            ->assertJsonPath('users.0.tag', 'danek_friend');
    }

    public function test_invitee_can_decline_pending_invitation(): void
    {
        $inviter = User::factory()->create();
        $invitee = User::factory()->create();
        $list = $this->personalList($inviter);
        $invitation = ListInvitation::query()->create([
            'list_id' => $list->id,
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
        $list = $this->personalList($inviter);
        $invitation = ListInvitation::query()->create([
            'list_id' => $list->id,
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
            'name' => 'Trip',
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
