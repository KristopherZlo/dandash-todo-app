<?php

namespace App\Services\ListItems;

use App\Models\ListItem;
use App\Models\ListMember;
use App\Models\UserList;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class ListAccessService
{
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
        $this->ensureCanAccess($request, (int) ($item->list_id ?? 0));
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
}
