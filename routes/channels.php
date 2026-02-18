<?php

use App\Models\ListLink;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

Broadcast::channel('lists.personal.{ownerId}', function (User $user, int $ownerId): bool {
    if ($user->id === $ownerId) {
        return true;
    }

    return false;
});

Broadcast::channel('lists.shared.{linkId}', function (User $user, int $linkId): bool {
    return ListLink::query()
        ->whereKey($linkId)
        ->where('is_active', true)
        ->where(function (Builder $query) use ($user): void {
            $query->where('user_one_id', $user->id)
                ->orWhere('user_two_id', $user->id);
        })
        ->exists();
});
