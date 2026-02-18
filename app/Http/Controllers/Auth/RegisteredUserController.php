<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\RegistrationCode;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rules;
use Inertia\Inertia;
use Inertia\Response;

class RegisteredUserController extends Controller
{
    /**
     * Display the registration view.
     */
    public function create(): Response
    {
        return Inertia::render('Auth/Register');
    }

    /**
     * Handle an incoming registration request.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->merge([
            'tag' => Str::lower(ltrim(trim((string) $request->input('tag')), '@')),
        ]);

        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:'.User::class,
            'tag' => ['required', 'string', 'min:3', 'max:32', 'alpha_dash', Rule::unique(User::class, 'tag')],
            'email' => 'required|string|lowercase|email|max:255|unique:'.User::class,
            'registration_code' => 'required|string|max:32',
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $registrationCodeValue = Str::upper(trim($validated['registration_code']));

        $user = DB::transaction(function () use ($validated, $registrationCodeValue): User {
            $registrationCode = RegistrationCode::query()
                ->where('code', $registrationCodeValue)
                ->lockForUpdate()
                ->first();

            if (! $registrationCode || ! $registrationCode->isAvailable()) {
                throw ValidationException::withMessages([
                    'registration_code' => 'Недействительный или уже использованный регистрационный код.',
                ]);
            }

            $user = User::query()->create([
                'name' => trim($validated['name']),
                'tag' => $validated['tag'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);

            $registrationCode->update([
                'used_by_user_id' => $user->id,
                'used_at' => now(),
            ]);

            return $user;
        });

        event(new Registered($user));

        Auth::login($user);

        return redirect(route('dashboard', absolute: false));
    }
}
