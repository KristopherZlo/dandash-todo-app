<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ListSyncVersion extends Model
{
    protected $fillable = [
        'scope_key',
        'owner_id',
        'list_link_id',
        'type',
        'version',
    ];

    protected function casts(): array
    {
        return [
            'owner_id' => 'integer',
            'list_link_id' => 'integer',
            'version' => 'integer',
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
}

