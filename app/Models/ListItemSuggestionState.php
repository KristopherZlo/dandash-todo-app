<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListItemSuggestionState extends Model
{
    protected $fillable = [
        'owner_id',
        'type',
        'suggestion_key',
        'dismissed_count',
        'hidden_until',
        'retired_at',
        'reset_at',
    ];

    protected function casts(): array
    {
        return [
            'dismissed_count' => 'integer',
            'hidden_until' => 'datetime',
            'retired_at' => 'datetime',
            'reset_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function scopeForOwner(Builder $query, int $ownerId): Builder
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }
}

