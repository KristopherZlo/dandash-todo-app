<?php

namespace App\Services\ListItems;

use App\Models\ListItem;
use App\Models\ListMember;
use App\Models\User;
use App\Models\UserList;
use App\Services\Lists\ListCatalogService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ListAccessService
{
    public function __construct(
        private readonly ListCatalogService $listCatalogService,
    ) {
    }

    public function resolveRequestedListId(Request $request): int
    {
        $listId = (int) $request->input('list_id', 0);
        if ($listId > 0) {
            return $listId;
        }

        $ownerId = (int) $request->input('owner_id', 0);
        abort_if($ownerId <= 0, Response::HTTP_UNPROCESSABLE_ENTITY, 'The list id field is required.');

        return $this->resolveAccessibleListIdForOwner(
            (int) $request->user()->id,
            $ownerId,
            $request->user(),
        );
    }

    public function ensureCanAccess(Request $request, int $listId): UserList
    {
        $list = UserList::query()->findOrFail($listId);
        $hasAccess = ListMember::query()
            ->where('list_id', (int) $list->id)
            ->where('user_id', (int) $request->user()->id)
            ->exists();

        abort_unless($hasAccess, Response::HTTP_FORBIDDEN, 'You do not have access to this list.');

        return $list;
    }

    public function ensureCanAccessItem(Request $request, ListItem $item): void
    {
        $listId = (int) ($item->list_id ?? 0);
        if ($listId <= 0 && (int) ($item->owner_id ?? 0) > 0) {
            $listId = $this->resolveAccessibleListIdForOwner(
                (int) $request->user()->id,
                (int) $item->owner_id,
                $request->user(),
            );
        }

        $this->ensureCanAccess($request, $listId);
    }

    public function resolveReadContext(Request $request, int $listId): ListAccessContext
    {
        $list = $this->ensureCanAccess($request, $listId);

        return new ListAccessContext((int) $list->owner_user_id, (int) $list->id);
    }

    public function resolveCreateContext(Request $request, int $listId): ListAccessContext
    {
        return $this->resolveReadContext($request, $listId);
    }

    private function resolveAccessibleListIdForOwner(int $actingUserId, int $ownerId, ?User $actingUser = null): int
    {
        if ($actingUserId > 0 && $actingUserId === $ownerId && $actingUser) {
            return (int) $this->listCatalogService->ensurePersonalListExists($actingUser)->id;
        }

        $accessibleListId = UserList::query()
            ->regular()
            ->where('owner_user_id', $ownerId)
            ->whereHas('members', static function ($query) use ($actingUserId): void {
                $query->where('user_id', $actingUserId);
            })
            ->orderBy('id')
            ->value('id');

        if ($accessibleListId) {
            return (int) $accessibleListId;
        }

        $ownerExists = User::query()->whereKey($ownerId)->exists();

        abort(
            $ownerExists ? Response::HTTP_FORBIDDEN : Response::HTTP_UNPROCESSABLE_ENTITY,
            $ownerExists ? 'You do not have access to this list.' : 'The list id field is required.',
        );
    }
}
