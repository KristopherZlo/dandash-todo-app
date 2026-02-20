<?php

namespace App\Services\ListItems;

use App\Models\ListItem;
use App\Models\ListLink;
use Illuminate\Http\Request;

class ListAccessService
{
    public function ensureCanAccess(Request $request, int $ownerId): void
    {
        abort_unless(
            (int) $request->user()->id === $ownerId,
            403,
            'You do not have access to this list.'
        );
    }

    public function ensureCanAccessItem(Request $request, ListItem $item): void
    {
        if ($item->list_link_id) {
            $this->resolveAccessibleLink($request, (int) $item->list_link_id);

            return;
        }

        abort_unless(
            (int) $request->user()->id === (int) $item->owner_id,
            403,
            'You do not have access to this list.'
        );
    }

    public function resolveReadContext(Request $request, int $ownerId, ?int $linkId = null): ListAccessContext
    {
        if ($linkId) {
            $link = $this->resolveAccessibleLink($request, $linkId);

            return new ListAccessContext((int) $link->user_one_id, (int) $link->id);
        }

        $this->ensureCanAccess($request, $ownerId);

        return new ListAccessContext($ownerId, null);
    }

    public function resolveCreateContext(Request $request, int $ownerId, ?int $linkId = null): ListAccessContext
    {
        if ($linkId) {
            $link = $this->resolveAccessibleLink($request, $linkId);

            return new ListAccessContext((int) $link->user_one_id, (int) $link->id);
        }

        $currentUserId = (int) $request->user()->id;
        if ($currentUserId === $ownerId) {
            return new ListAccessContext($ownerId, null);
        }

        $link = $this->resolveAccessibleLinkByOwner($request, $ownerId);

        return new ListAccessContext((int) $link->user_one_id, (int) $link->id);
    }

    public function resolveAccessibleLink(Request $request, int $linkId): ListLink
    {
        $link = ListLink::query()->findOrFail($linkId);

        abort_unless(
            $link->is_active && $link->involvesUser((int) $request->user()->id),
            403,
            'You do not have access to this shared list.'
        );

        return $link;
    }

    public function resolveAccessibleLinkByOwner(Request $request, int $ownerId): ListLink
    {
        $currentUserId = (int) $request->user()->id;

        $link = ListLink::query()
            ->where('is_active', true)
            ->where('user_one_id', $ownerId)
            ->where(function ($query) use ($currentUserId): void {
                $query->where('user_one_id', $currentUserId)
                    ->orWhere('user_two_id', $currentUserId);
            })
            ->first();

        abort_unless($link, 403, 'You do not have access to this shared list.');

        return $link;
    }
}
