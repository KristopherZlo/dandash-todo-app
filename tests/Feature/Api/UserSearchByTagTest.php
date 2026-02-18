<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserSearchByTagTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_search_by_tag(): void
    {
        $currentUser = User::factory()->create([
            'tag' => 'owner_tag',
        ]);

        $targetUser = User::factory()->create([
            'name' => 'Friend',
            'tag' => 'danek_love',
        ]);

        $response = $this
            ->actingAs($currentUser)
            ->getJson('/api/users/search?query=danek');

        $response
            ->assertOk()
            ->assertJsonPath('users.0.id', $targetUser->id)
            ->assertJsonPath('users.0.tag', 'danek_love');
    }
}
