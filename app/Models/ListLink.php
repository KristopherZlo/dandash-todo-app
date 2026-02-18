<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ListLink extends Model
{
    protected $fillable = [
        'user_one_id',
        'user_two_id',
        'sync_owner_id',
        'is_active',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'accepted_at' => 'datetime',
        ];
    }

    public function userOne(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_one_id');
    }

    public function userTwo(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_two_id');
    }

    public function syncOwner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sync_owner_id');
    }

    public function involvesUser(int $userId): bool
    {
        return $this->user_one_id === $userId || $this->user_two_id === $userId;
    }

    public function otherUserId(int $userId): ?int
    {
        if ($this->user_one_id === $userId) {
            return $this->user_two_id;
        }

        if ($this->user_two_id === $userId) {
            return $this->user_one_id;
        }

        return null;
    }
}
