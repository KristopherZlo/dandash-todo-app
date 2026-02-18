<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;

class ListItem extends Model
{
    public const TYPE_PRODUCT = 'product';
    public const TYPE_TODO = 'todo';

    public const PRIORITY_URGENT = 'urgent';
    public const PRIORITY_TODAY = 'today';
    public const PRIORITY_LATER = 'later';

    protected $fillable = [
        'owner_id',
        'list_link_id',
        'type',
        'text',
        'sort_order',
        'quantity',
        'unit',
        'due_at',
        'priority',
        'is_completed',
        'completed_at',
        'created_by_id',
        'updated_by_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'list_link_id' => 'integer',
            'quantity' => 'decimal:2',
            'due_at' => 'datetime',
            'priority' => 'string',
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function listLink(): BelongsTo
    {
        return $this->belongsTo(ListLink::class, 'list_link_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by_id');
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
