<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListItemSuggestionState extends Model
{
    protected $fillable = [
        'owner_id',
        'list_id',
        'type',
        'suggestion_key',
        'dismissed_count',
        'hidden_until',
        'retired_at',
        'reset_at',
        'custom_interval_seconds',
    ];

    protected function casts(): array
    {
        return [
            'dismissed_count' => 'integer',
            'list_id' => 'integer',
            'hidden_until' => 'datetime',
            'retired_at' => 'datetime',
            'reset_at' => 'datetime',
            'custom_interval_seconds' => 'integer',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(UserList::class, 'list_id');
    }

    public function scopeForOwner(Builder $query, int $ownerId): Builder
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopeForList(Builder $query, int $listId): Builder
    {
        return $query->where('list_id', $listId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}
