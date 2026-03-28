<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class UserList extends Model
{
    protected $table = 'lists';

    protected $fillable = [
        'owner_user_id',
        'name',
        'is_template',
        'last_activity_at',
    ];

    protected function casts(): array
    {
        return [
            'is_template' => 'boolean',
            'last_activity_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ListMember::class, 'list_id');
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ListInvitation::class, 'list_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ListItem::class, 'list_id');
    }

    public function scopeVisibleTo(Builder $query, int $userId): Builder
    {
        return $query->whereHas('members', static function (Builder $memberQuery) use ($userId): void {
            $memberQuery->where('user_id', $userId);
        });
    }

    public function scopeRegular(Builder $query): Builder
    {
        return $query->where('is_template', false);
    }

    public function scopeTemplates(Builder $query): Builder
    {
        return $query->where('is_template', true);
    }
}
