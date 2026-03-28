<?php

use App\Models\User;
use App\Models\UserList;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('users.{userId}', function (User $user, int $userId): bool {
    return $user->id === $userId;
});

Broadcast::channel('lists.{listId}', function (User $user, int $listId): bool {
    return UserList::query()
        ->visibleTo((int) $user->id)
        ->whereKey($listId)
        ->exists();
});
