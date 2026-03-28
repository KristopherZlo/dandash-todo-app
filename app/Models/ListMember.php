<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListMember extends Model
{
    public const ROLE_OWNER = 'owner';
    public const ROLE_EDITOR = 'editor';

    protected $fillable = [
        'list_id',
        'user_id',
        'role',
    ];

    public function list(): BelongsTo
    {
        return $this->belongsTo(UserList::class, 'list_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function isOwner(): bool
    {
        return $this->role === self::ROLE_OWNER;
    }
}
