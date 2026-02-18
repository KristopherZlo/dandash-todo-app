<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'tag',
        'preferred_owner_id',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function registrationCodesCreated(): HasMany
    {
        return $this->hasMany(RegistrationCode::class, 'created_by_user_id');
    }

    public function registrationCodesUsed(): HasMany
    {
        return $this->hasMany(RegistrationCode::class, 'used_by_user_id');
    }

    public function invitationsSent(): HasMany
    {
        return $this->hasMany(ListInvitation::class, 'inviter_id');
    }

    public function invitationsReceived(): HasMany
    {
        return $this->hasMany(ListInvitation::class, 'invitee_id');
    }

    public function listLinksAsUserOne(): HasMany
    {
        return $this->hasMany(ListLink::class, 'user_one_id');
    }

    public function listLinksAsUserTwo(): HasMany
    {
        return $this->hasMany(ListLink::class, 'user_two_id');
    }

    public function listItems(): HasMany
    {
        return $this->hasMany(ListItem::class, 'owner_id');
    }
}
