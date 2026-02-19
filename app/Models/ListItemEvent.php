<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListItemEvent extends Model
{
    public const EVENT_ADDED = 'added';
    public const EVENT_COMPLETED = 'completed';

    protected $fillable = [
        'owner_id',
        'list_link_id',
        'type',
        'event_type',
        'text',
        'normalized_text',
        'occurred_at',
        'source_item_id',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'list_link_id' => 'integer',
            'source_item_id' => 'integer',
            'occurred_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function sourceItem(): BelongsTo
    {
        return $this->belongsTo(ListItem::class, 'source_item_id');
    }

    public function scopeForOwner(Builder $query, int $ownerId): Builder
    {
        return $query->where('owner_id', $ownerId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    public function scopeOfEventType(Builder $query, string $eventType): Builder
    {
        return $query->where('event_type', $eventType);
    }
}

