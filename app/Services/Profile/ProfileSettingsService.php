<?php

namespace App\Services\Profile;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class ProfileSettingsService
{
    public function updateProfile(User $user, array $input): array
    {
        $payload = [
            'name' => (string) ($input['name'] ?? ''),
            'tag' => Str::lower(ltrim(trim((string) ($input['tag'] ?? '')), '@')),
            'email' => (string) ($input['email'] ?? ''),
        ];

        $validated = Validator::make($payload, [
            'name' => ['required', 'string', 'max:255', Rule::unique('users', 'name')->ignore($user->id)],
            'tag' => ['required', 'string', 'min:3', 'max:32', 'alpha_dash', Rule::unique('users', 'tag')->ignore($user->id)],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
        ])->validate();

        $emailChanged = $user->email !== $validated['email'];

        $user->name = $validated['name'];
        $user->tag = $validated['tag'];
        $user->email = $validated['email'];

        if ($emailChanged) {
            $user->email_verified_at = null;
        }

        $user->save();

        return [
            'status' => 'ok',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'tag' => $user->tag,
                'email' => $user->email,
            ],
        ];
    }

    public function updatePassword(User $user, array $input): array
    {
        $validated = Validator::make($input, [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ])->validate();

        $user->update([
            'password' => Hash::make($validated['password']),
        ]);

        return [
            'status' => 'ok',
        ];
    }
}
