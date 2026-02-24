<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProfileSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_update_profile_settings_via_api_and_tag_is_normalized(): void
    {
        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);

        $response = $this->actingAs($user)
            ->patchJson('/api/profile', [
                'name' => 'Dasha',
                'tag' => '@DaSha_Test',
                'email' => 'dasha@example.com',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok')
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.name', 'Dasha')
            ->assertJsonPath('user.tag', 'dasha_test')
            ->assertJsonPath('user.email', 'dasha@example.com');

        $user->refresh();

        $this->assertSame('Dasha', $user->name);
        $this->assertSame('dasha_test', $user->tag);
        $this->assertSame('dasha@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
        $this->assertSame('ok', $response->json('status'));
    }

    public function test_profile_settings_api_rejects_duplicate_tag(): void
    {
        $existing = User::factory()->create([
            'tag' => 'busy_tag',
        ]);
        $user = User::factory()->create();

        $this->actingAs($user)
            ->patchJson('/api/profile', [
                'name' => $user->name,
                'tag' => $existing->tag,
                'email' => $user->email,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['tag']);
    }

    public function test_user_can_update_password_via_api(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'password',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ])
            ->assertOk()
            ->assertJsonPath('status', 'ok');

        $user->refresh();
        $this->assertTrue(Hash::check('NewPassword123!', $user->password));
    }

    public function test_profile_password_api_rejects_invalid_current_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->putJson('/api/profile/password', [
                'current_password' => 'wrong-password',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['current_password']);
    }
}
