<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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
        'list_id',
        'type',
        'text',
        'client_request_id',
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
            'list_id' => 'integer',
            'quantity' => 'decimal:2',
            'due_at' => 'datetime',
            'priority' => 'string',
            'is_completed' => 'boolean',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (ListItem $item): void {
            if ((int) ($item->list_id ?? 0) > 0 || (int) ($item->owner_id ?? 0) <= 0) {
                return;
            }

            $item->list_id = static::ensureOwnerPersonalListId((int) $item->owner_id);
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function listLink(): BelongsTo
    {
        return $this->belongsTo(ListLink::class, 'list_link_id');
    }

    public function list(): BelongsTo
    {
        return $this->belongsTo(UserList::class, 'list_id');
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

    public function scopeForList(Builder $query, int $listId): Builder
    {
        return $query->where('list_id', $listId);
    }

    public function scopeOfType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type);
    }

    private static function ensureOwnerPersonalListId(int $ownerId): int
    {
        $existingListId = UserList::query()
            ->regular()
            ->where('owner_user_id', $ownerId)
            ->whereHas('members', static function (Builder $query) use ($ownerId): void {
                $query->where('user_id', $ownerId)
                    ->where('role', ListMember::ROLE_OWNER);
            })
            ->whereDoesntHave('members', static function (Builder $query) use ($ownerId): void {
                $query->where('user_id', '!=', $ownerId);
            })
            ->orderBy('id')
            ->value('id');

        if ($existingListId) {
            return (int) $existingListId;
        }

        return DB::transaction(static function () use ($ownerId): int {
            $list = UserList::query()->create([
                'owner_user_id' => $ownerId,
                'name' => 'Р›РёС‡РЅС‹Р№',
                'is_template' => false,
            ]);

            ListMember::query()->create([
                'list_id' => (int) $list->id,
                'user_id' => $ownerId,
                'role' => ListMember::ROLE_OWNER,
            ]);

            User::query()
                ->whereKey($ownerId)
                ->whereNull('preferred_list_id')
                ->update(['preferred_list_id' => (int) $list->id]);

            return (int) $list->id;
        });
    }
}
